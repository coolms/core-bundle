<?php

declare(strict_types=1);

namespace CoolMS\CoreBundle\Console;

use CoolMS\CoreModule\Outbox\OutboxMaintenanceService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function sprintf;

/**
 * `bin/console coolms:outbox:prune` — keep the F7 rails' tables bounded:
 * delete delivered outbox rows + old idempotency-inbox rows past their retention
 * windows (configured via `coolms_core.outbox.published_retention_days` /
 * `coolms_core.inbox.processed_retention_days`). Intended to run daily; idempotent
 * + safe on demand. A non-positive window disables that table's prune.
 */
#[AsCommand(
    name: 'coolms:outbox:prune',
    description: 'Prune delivered outbox rows and old idempotency-inbox rows past their retention windows.',
)]
final class PruneOutboxCommand extends Command
{
    public function __construct(private readonly OutboxMaintenanceService $maintenance)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'dry-run',
            null,
            InputOption::VALUE_NONE,
            'Report how many rows WOULD be pruned from each table, without deleting anything.',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ($this->maintenance->outboxRetentionDays() < 1) {
            $io->warning('Outbox retention pruning is disabled (published_retention_days < 1).');
        }
        if ($this->maintenance->inboxRetentionDays() < 1) {
            $io->warning('Inbox retention pruning is disabled (processed_retention_days < 1).');
        }

        if (true === $input->getOption('dry-run')) {
            $prunable = $this->maintenance->countPrunable();

            // writeln (not a SymfonyStyle block) so the single line is emitted
            // verbatim — block styles wrap at the terminal width and split the
            // sentence, which the machine-readable output must not do.
            $io->writeln(sprintf(
                'Dry run: %d delivered outbox row%s and %d idempotency-inbox row%s would be pruned. Nothing was deleted.',
                $prunable['outbox'],
                1 === $prunable['outbox'] ? '' : 's',
                $prunable['inbox'],
                1 === $prunable['inbox'] ? '' : 's',
            ));

            return Command::SUCCESS;
        }

        $pruned = $this->maintenance->prune();

        $io->success(sprintf(
            'Pruned %d delivered outbox row%s and %d idempotency-inbox row%s.',
            $pruned['outbox'],
            1 === $pruned['outbox'] ? '' : 's',
            $pruned['inbox'],
            1 === $pruned['inbox'] ? '' : 's',
        ));

        return Command::SUCCESS;
    }
}
