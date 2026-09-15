<?php

declare(strict_types=1);

namespace CoolMS\Core\Bundle\Console;

use CoolMS\Core\Secret\MasterKeyRingInterface;
use CoolMS\Core\Secret\RotationTally;
use CoolMS\Core\Secret\SealedKindInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Throwable;

use function count;
use function sprintf;

/**
 * Re-seal every kind of sealed value still under the previous master key,
 * and say per kind what is left.
 *
 * The window of a rotation is open while the ring holds two keys: new values
 * are sealed under the current key, old ones still open under the previous
 * one. This command walks every kind a module registered, opens every value
 * (a marker names a form, not a key, so only a read can say), re-seals the
 * ones under the previous key, and reports three numbers per kind: under the
 * current key, under the previous key, and under neither. The window is
 * CLOSED when every kind reports zero under the previous key -- a measurement
 * this command makes, not a judgement the operator makes -- and the exit
 * status says so: 0 closed, 1 open.
 *
 * Idempotent and resumable: a value already under the current key is skipped
 * whatever form it is in, so a second run re-seals nothing and an interrupted
 * run continues by being run again. `--dry-run` counts and writes nothing.
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
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Count per kind without re-sealing anything');
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
                'The ring holds one key (%s) and no previous key: every value is either under it or unreadable. Set the previous key to rotate.',
                $current->id,
            ));
        } else {
            $io->text(sprintf('Ring: current %s, previous %s.', $current->id, $keys[1]->id));
        }

        $rows = [];
        $sum = new RotationTally();
        $kindCount = 0;
        foreach ($this->kinds as $kind) {
            ++$kindCount;
            try {
                $tally = $kind->sweep($apply);
            } catch (Throwable $e) {
                $io->error(sprintf('%s: the sweep stopped: %s', $kind->name(), $e->getMessage()));

                return Command::FAILURE;
            }
            $rows[] = [
                $kind->name(),
                $tally->current,
                $tally->previous,
                $tally->unreadable,
                $tally->plaintext,
                $apply ? $tally->resealed : sprintf('(would: %d)', $tally->previous),
            ];
            $sum = $sum->add($tally);
        }

        if (0 === $kindCount) {
            $io->warning('No sealed kinds are registered; nothing was swept.');
        }
        $io->table(['kind', 'current', 'previous', 'unreadable', 'plaintext', 're-sealed'], $rows);

        $left = $apply ? $sum->previous - $sum->resealed : $sum->previous;
        if ($sum->unreadable > 0) {
            $io->caution(sprintf(
                '%d value(s) open under neither key this host holds. A rotation cannot repair them; each one needs the key it was sealed under, or re-entry.',
                $sum->unreadable,
            ));
        }
        if ($sum->plaintext > 0) {
            $io->note(sprintf('%d value(s) are not sealed at all; the kind\'s own backfill seals them, a rotation does not.', $sum->plaintext));
        }
        if (0 === $left) {
            $io->success(sprintf(
                'Window CLOSED: zero values under the previous key in every kind (%d read%s).',
                $sum->total(),
                $apply && $sum->resealed > 0 ? sprintf(', %d re-sealed this run', $sum->resealed) : '',
            ));

            return Command::SUCCESS;
        }
        $io->warning(sprintf(
            'Window OPEN: %d value(s) still under the previous key%s.',
            $left,
            $apply ? '' : ' (dry run: nothing was re-sealed)',
        ));

        return Command::FAILURE;
    }
}
