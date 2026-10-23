<?php

declare(strict_types=1);

namespace CoolMS\Core\Bundle\Console;

use CoolMS\Core\Application\Retention\RetentionPruneRunner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function count;
use function sprintf;

/**
 * `bin/console coolms:retention:prune` -- run EVERY module's retention sweep in one
 * pass (analytics events + page views, spam comments, expired auth tokens +
 * verification codes, and any future {@see \CoolMS\Core\Retention\RetentionPrunerInterface}).
 * Each pruner uses its own baked-in grace window. The per-module
 * `coolms:*:prune-*` commands still exist for targeted runs.
 *
 * A destructive command in the platform's shape (2026-09-24): a dry run unless
 * `--execute`; under `--no-interaction` it refuses to act without `--force`; and
 * every report says "N of M". M is a pruner's population when it implements
 * `RetentionPopulationInterface`, and "unknown" when it does not -- a dry run
 * with an unknown population exits 2, UNEVALUABLE, never a clean 0.
 *
 * The scheduled `retention.prune` handler calls the runner, not this command, so
 * scheduled retention is unchanged.
 */
#[AsCommand(
    name: 'coolms:retention:prune',
    description: 'Run every registered retention sweep (analytics, comments, credentials, ...). A dry run unless --execute.',
)]
final class PruneRetentionCommand extends Command
{
    private const int UNEVALUABLE = 2;

    public function __construct(private readonly RetentionPruneRunner $runner)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('execute', null, InputOption::VALUE_NONE, 'Delete. Without it the command reports and changes nothing.')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Do not ask. Required with --execute under --no-interaction.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'The default now; accepted, and it wins over --execute.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $execute = (bool) $input->getOption('execute') && !$input->getOption('dry-run');
        $force = (bool) $input->getOption('force');
        if ($execute && !$input->isInteractive() && !$force) {
            $io->error('Refusing to delete unattended: --execute under --no-interaction needs --force.');

            return Command::INVALID;
        }

        $preview = $this->runner->preview();
        if ([] === $preview) {
            $io->warning('No retention pruners are registered.');
            $io->writeln('Would delete 0 of 0 rows: no pruner is registered.');

            return Command::SUCCESS;
        }

        $expired = 0;
        $known = 0;
        $unknown = 0;
        foreach ($preview as $row) {
            $expired += $row['prunable'];
            if (null === $row['population']) {
                ++$unknown;
            } else {
                $known += $row['population'];
            }
            $io->writeln(sprintf(
                '%s (%s): %s %d of %s row(s).',
                $row['label'],
                $row['key'],
                $execute ? 'deleting' : 'would delete',
                $row['prunable'],
                null === $row['population'] ? 'unknown' : (string) $row['population'],
            ));
        }
        $io->writeln(sprintf(
            '%s %d of %s row(s) across %d pruner(s).',
            $execute ? 'Deleting' : 'Would delete',
            $expired,
            0 === $unknown ? (string) $known : sprintf('%d + unknown', $known),
            count($preview),
        ));

        if (!$execute) {
            $io->note('Dry run: nothing was changed. Pass --execute to delete.');
            if (0 !== $unknown) {
                $io->warning(sprintf(
                    '%d pruner(s) cannot count their population, so their "of" is unknown: UNEVALUABLE, not zero.',
                    $unknown,
                ));

                return self::UNEVALUABLE;
            }

            return Command::SUCCESS;
        }
        if (!$force && !$io->confirm(sprintf('Delete these %d row(s)?', $expired), false)) {
            $io->comment('Nothing was changed.');

            return Command::SUCCESS;
        }

        $removed = 0;
        foreach ($this->runner->prune() as $row) {
            $removed += $row['removed'];
            $io->writeln(sprintf('Pruned %d row(s) from %s (%s).', $row['removed'], $row['label'], $row['key']));
        }
        $io->success(sprintf('Retention prune complete: %d row(s) removed across %d pruner(s).', $removed, count($preview)));

        return Command::SUCCESS;
    }
}
