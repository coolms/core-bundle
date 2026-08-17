<?php

declare(strict_types=1);

namespace CoolMS\CoreBundle\Console;

use CoolMS\Core\Transaction\ConnectionTransactionRunnerInterface;
use CoolMS\CoreModule\Outbox\OutboxRelay;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function is_numeric;
use function max;
use function sprintf;

/**
 * `bin/console coolms:outbox:relay [--batch=N]` — publish committed
 * transactional-outbox rows. Intended to run continuously / on a
 * tight cron (the DB-backed relay; a `LISTEN/NOTIFY` driver is a later option);
 * idempotent + safe to run on demand. The whole batch runs in ONE CONNECTION-level
 * transaction so the `FOR UPDATE SKIP LOCKED` claim holds until every row is marked
 * — connection-level (not EntityManager-level) so a consumer that closes the EM
 * mid-batch (see {@see OutboxRelay}) can't abort the commit; the relay resets the
 * EM per failed row to keep later rows healthy.
 */
#[AsCommand(
    name: 'coolms:outbox:relay',
    description: 'Publish committed transactional-outbox rows to their consumers.',
)]
final class RelayOutboxCommand extends Command
{
    private const int DEFAULT_BATCH = 100;

    public function __construct(
        private readonly OutboxRelay $relay,
        private readonly ConnectionTransactionRunnerInterface $transactionRunner,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'batch',
            null,
            InputOption::VALUE_REQUIRED,
            'Maximum number of messages to publish in this run.',
            (string) self::DEFAULT_BATCH,
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $raw = $input->getOption('batch');
        $batch = is_numeric($raw) ? max(1, (int) $raw) : self::DEFAULT_BATCH;

        // Connection-level transaction (NOT the EntityManager's): the relay's
        // claim + mark bookkeeping is raw DBAL, and a consumer that throws closes
        // the EM, so an EM-bound batch tx would abort on its final flush.
        $published = (int) $this->transactionRunner->run(fn (): int => $this->relay->relay($batch));

        $io->success(sprintf('Relayed %d outbox message%s.', $published, 1 === $published ? '' : 's'));

        return Command::SUCCESS;
    }
}
