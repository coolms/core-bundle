<?php

declare(strict_types=1);

namespace CoolMS\CoreBundle\Console;

use CoolMS\CoreModule\Retention\RetentionPruneRunner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function array_map;
use function array_sum;
use function count;
use function sprintf;

/**
 * `bin/console coolms:retention:prune` — run EVERY module's retention sweep in one
 * pass (analytics events + page views, spam comments, expired auth tokens +
 * verification codes, and any future {@see \CoolMS\Core\Retention\RetentionPrunerInterface}).
 * Each pruner uses its own baked-in grace window. Intended for cron / the M1.3
 * Scheduler (see the `retention.prune` scheduled handler); safe + idempotent on
 * demand. The per-module `coolms:*:prune-*` commands still exist for targeted runs.
 */
#[AsCommand(
    name: 'coolms:retention:prune',
    description: 'Run every registered retention sweep (analytics, comments, credentials, ...) in one pass.',
)]
final class PruneRetentionCommand extends Command
{
    public function __construct(private readonly RetentionPruneRunner $runner)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'dry-run',
            null,
            InputOption::VALUE_NONE,
            'Report how many rows WOULD be pruned by each registered pruner, without deleting anything.',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (true === $input->getOption('dry-run')) {
            $preview = $this->runner->preview();
            if ([] === $preview) {
                $io->warning('No retention pruners are registered.');

                return Command::SUCCESS;
            }

            foreach ($preview as $row) {
                $io->writeln(sprintf(
                    'Dry run: %s (%s) would prune %d row%s. Nothing was deleted.',
                    $row['label'],
                    $row['key'],
                    $row['prunable'],
                    1 === $row['prunable'] ? '' : 's',
                ));
            }
            $total = array_sum(array_map(static fn (array $r): int => $r['prunable'], $preview));
            $count = count($preview);
            $io->writeln(sprintf('Dry run total: %d row%s across %d pruner%s.', $total, 1 === $total ? '' : 's', $count, 1 === $count ? '' : 's'));

            return Command::SUCCESS;
        }

        $results = $this->runner->prune();
        if ([] === $results) {
            $io->warning('No retention pruners are registered.');

            return Command::SUCCESS;
        }

        foreach ($results as $row) {
            $io->writeln(sprintf('Pruned %d row%s from %s (%s).', $row['removed'], 1 === $row['removed'] ? '' : 's', $row['label'], $row['key']));
        }
        $total = array_sum(array_map(static fn (array $r): int => $r['removed'], $results));
        $count = count($results);
        $io->success(sprintf('Retention prune complete: %d row%s removed across %d pruner%s.', $total, 1 === $total ? '' : 's', $count, 1 === $count ? '' : 's'));

        return Command::SUCCESS;
    }
}
