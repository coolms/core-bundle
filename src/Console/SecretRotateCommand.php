<?php

declare(strict_types=1);

namespace CoolMS\Core\Bundle\Console;

use CoolMS\Core\Secret\MasterKeyRingInterface;
use CoolMS\Core\Secret\ResidueTally;
use CoolMS\Core\Secret\RotationTally;
use CoolMS\Core\Secret\SealedKindInterface;
use CoolMS\Core\Secret\SealedResidueInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Throwable;

use function count;
use function hrtime;
use function implode;
use function memory_get_peak_usage;
use function memory_reset_peak_usage;
use function round;
use function sprintf;

/**
 * Re-seal every kind of sealed value still under the previous master key,
 * and say per kind what is left.
 *
 * The window of a rotation is open while the ring holds two keys: new values
 * are sealed under the current key, old ones still open under the previous
 * one. This command walks every kind a module registered, opens every value,
 * re-seals the ones under the previous key, and reports three numbers per
 * kind: under the current key, under the previous key, and under neither.
 *
 * HOW THE NUMBER THAT GATES THE KEY IS OBTAINED. The `previous` column is
 * the count of values the PREVIOUS key opened and the current key did not,
 * arrived at by opening every value the kind holds -- not by reading its
 * marker. The marker would be cheaper and is not trusted on purpose: the
 * form `KeyRingSealer` writes today, `enc:v2:<key id>:...`, does carry the
 * id of the key it was sealed under, but {@see KeyRingSealer::candidates()}
 * treats that id as a HINT and the box as the PROOF -- it tries the other
 * keys when the named one fails. A value whose marker names the current key
 * but does not open under it is exactly the value a rotation must not miss,
 * and a marker-only count would report it as done. The two older forms
 * (`enc:v1:` and bare base64) carry no id at all, so for them opening is the
 * only answer there has ever been.
 *
 * That number is what the operator waits on: the previous key may leave the
 * ring when it is ZERO IN EVERY KIND, and not before. `--dry-run` is how it
 * is obtained without writing anything -- it reports the same per-kind
 * column and exits 1 while any of it is non-zero. The exit status says the
 * same thing: 0 closed, 1 open.
 *
 * Idempotent and resumable: a value already under the current key is skipped
 * whatever form it is in, so a second run re-seals nothing, and a run killed
 * half way continues by being run again -- it re-reads what it already did
 * and finds it done. The sweeps page by key rather than by offset, which is
 * what keeps that true while rows are being rewritten underneath.
 *
 * Each kind reports its wall time and its own peak memory, because this is
 * the emergency procedure -- it is run when a key has leaked -- and "it died"
 * is a thing the operator must be able to see coming rather than discover.
 *
 * What it does not do, on purpose: it never removes the previous key. The
 * command's job ends at zero; retiring the key from the runtime is an
 * operator's act, and retiring it from the vault waits on which backups must
 * stay restorable. A value under neither key is reported and left -- a
 * rotation cannot repair what no held key opens.
 */
#[AsCommand(
    name: 'coolms:secret:rotate',
    description: 'Re-seal every sealed value still under the previous master key and report what remains per kind.',
)]
final class SecretRotateCommand extends Command
{
    /** @param iterable<SealedKindInterface> $kinds */
    public function __construct(
        private readonly MasterKeyRingInterface $ring,
        #[AutowireIterator('coolms.secret.sealed_kind')]
        private readonly iterable $kinds,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'dry-run',
            null,
            InputOption::VALUE_NONE,
            'Count per kind without re-sealing anything: the number that must be zero before the previous key is dropped',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $apply = !(bool) $input->getOption('dry-run');

        if (!$this->ring->isConfigured()) {
            $io->error('No current master key is configured; nothing can be sealed, so nothing is swept.');

            return Command::FAILURE;
        }
        $keys = $this->ring->keys();
        $current = $this->ring->current();
        if (1 === count($keys)) {
            $io->warning(sprintf(
                'The ring holds one key (%s) and no previous key: every value is either under it or unreadable.'
                . ' Set the previous key to rotate.',
                $current->id,
            ));
        } else {
            $io->text(sprintf('Ring: current %s, previous %s.', $current->id, $keys[1]->id));
        }

        $rows = [];
        $sum = new RotationTally();
        $kindCount = 0;
        /** @var list<string> $notAsked kinds that do not answer the residue question at all */
        $notAsked = [];
        /** @var array<string, ResidueTally> $holding kinds still holding copies the ring cannot open */
        $holding = [];
        $residueTotal = 0;
        foreach ($this->kinds as $kind) {
            ++$kindCount;
            memory_reset_peak_usage();
            $started = hrtime(true);
            try {
                $tally = $kind->sweep($apply);
            } catch (Throwable $e) {
                $io->error(sprintf('%s: the sweep stopped: %s', $kind->name(), $e->getMessage()));

                return Command::FAILURE;
            }
            // The second question, and the one the `previous` column above
            // cannot answer: what copies of this kind's ciphertext does the
            // estate still hold that the ring cannot open? A kind that does not
            // implement the port reports NOT ASKED -- never zero.
            $residue = $kind instanceof SealedResidueInterface ? $kind->residue() : null;
            if (null === $residue) {
                $notAsked[] = $kind->name();
            } else {
                $residueTotal += $residue->retained;
                if (!$residue->closed()) {
                    $holding[$kind->name()] = $residue;
                }
            }
            $rows[] = [
                $kind->name(),
                $tally->current,
                $tally->previous,
                $tally->unreadable,
                $tally->plaintext,
                $apply ? $tally->resealed : sprintf('(would: %d)', $tally->previous),
                self::residueCell($residue),
                sprintf('%.1f', (hrtime(true) - $started) / 1_000_000_000),
                sprintf('%d MB', (int) round(memory_get_peak_usage(true) / 1_048_576)),
            ];
            $sum = $sum->add($tally);
        }

        if (0 === $kindCount) {
            $io->warning('No sealed kinds are registered; nothing was swept.');
        }
        $io->table(
            ['kind', 'current', 'previous', 'unreadable', 'plaintext', 're-sealed', 'residue', 's', 'peak'],
            $rows,
        );

        $left = $apply ? $sum->previous - $sum->resealed : $sum->previous;
        if ($sum->unreadable > 0) {
            $io->caution(sprintf(
                '%d value(s) open under neither key this host holds. A rotation cannot repair them;'
                . ' each one needs the key it was sealed under, or re-entry.',
                $sum->unreadable,
            ));
        }
        if ($sum->plaintext > 0) {
            $io->note(sprintf(
                '%d value(s) are not sealed at all; the kind\'s own backfill seals them, a rotation does not.',
                $sum->plaintext,
            ));
        }
        if (0 !== $left) {
            $io->warning(sprintf(
                'Window OPEN: %d value(s) still under the previous key%s. Keep the previous key on the ring.',
                $left,
                $apply ? '' : ' (dry run: nothing was re-sealed)',
            ));

            return Command::FAILURE;
        }

        // Values are done. Copies are a different number, and the word CLOSED
        // belongs to neither of them alone.
        if ([] !== $holding) {
            foreach ($holding as $name => $residue) {
                $io->text(sprintf(
                    '  %s: %d of %d copies the ring cannot open. Not seen by this count: %s',
                    $name,
                    $residue->retained,
                    $residue->examined,
                    $residue->blindSpot,
                ));
            }
            $io->error(sprintf(
                'Window OPEN: zero values are under the previous key, and %d cop%s of that ciphertext'
                . ' remain that the ring cannot open. A rotation that leaves what the retired key opens'
                . ' has closed nothing; collect them, then run this again.',
                $residueTotal,
                1 === $residueTotal ? 'y' : 'ies',
            ));

            return Command::FAILURE;
        }

        if ([] !== $notAsked) {
            $io->warning(sprintf(
                'Window NOT ESTABLISHED: zero values are under the previous key, but %d kind(s) do not answer'
                . ' the residue question at all -- %s. Not asked is not zero, so whether the retired key still'
                . ' opens something cannot be said from here. The previous key stays on the ring.',
                count($notAsked),
                implode(', ', $notAsked),
            ));

            return Command::FAILURE;
        }

        $io->success(sprintf(
            'Window CLOSED: zero values under the previous key in every kind (%d read%s),'
            . ' and every kind was asked for its residue and reported none.'
            . ' The previous key can leave the ring.',
            $sum->total(),
            $apply && $sum->resealed > 0 ? sprintf(', %d re-sealed this run', $sum->resealed) : '',
        ));

        return Command::SUCCESS;
    }

    /** What the residue column says for one kind: a counted number, a structural none, or NOT ASKED. */
    private static function residueCell(?ResidueTally $residue): string
    {
        return match (true) {
            null === $residue => 'NOT ASKED',
            !$residue->walked => 'none kept',
            default => sprintf('%d of %d', $residue->retained, $residue->examined),
        };
    }
}
