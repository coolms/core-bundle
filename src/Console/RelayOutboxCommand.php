<?php

declare(strict_types=1);

namespace CoolMS\Core\Bundle\Console;

use CoolMS\Core\Application\Outbox\OutboxRelay;
use CoolMS\Core\Outbox\OutboxBacklogInterface;
use CoolMS\Core\Transaction\ConnectionTransactionRunnerInterface;
use DateTimeImmutable;
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
 * `bin/console coolms:outbox:relay [--batch=N]` -- publish committed
 * transactional-outbox rows. Intended to run continuously / on a
 * tight cron (the DB-backed relay; a `LISTEN/NOTIFY` driver is a later option);
 * idempotent + safe to run on demand. The whole batch runs in ONE CONNECTION-level
 * transaction so the `FOR UPDATE SKIP LOCKED` claim holds until every row is marked
 * -- connection-level (not EntityManager-level) so a consumer that closes the EM
 * mid-batch (see {@see OutboxRelay}) can't abort the commit; the relay resets the
 * EM per failed row to keep later rows healthy.
 */
#[AsCommand(
    name: 'coolms:outbox:relay',
    description: 'Publish committed transactional-outbox rows to their consumers.',
)]
final class RelayOutboxCommand extends Command
{
    /** Unpublished rows older than this are the relay's failure, not its queue. */
    public const int STALE_AFTER_SECONDS = 60;

    private const int DEFAULT_BATCH = 100;

    public function __construct(
        private readonly OutboxRelay $relay,
        private readonly ConnectionTransactionRunnerInterface $transactionRunner,
        private readonly ?OutboxBacklogInterface $backlog = null,
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
        $this->addOption(
            'status',
            null,
            InputOption::VALUE_NONE,
            'Publish nothing; print the undelivered backlog and exit 1 when any row has waited longer than a minute (the number that should be zero while a relay runs).',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ((bool) $input->getOption('status')) {
            return $this->status($io);
        }

        $raw = $input->getOption('batch');
        $batch = is_numeric($raw) ? max(1, (int) $raw) : self::DEFAULT_BATCH;

        // Connection-level transaction (NOT the EntityManager's): the relay's
        // claim + mark bookkeeping is raw DBAL, and a consumer that throws closes
        // the EM, so an EM-bound batch tx would abort on its final flush.
        $published = (int) $this->transactionRunner->run(fn (): int => $this->relay->relay($batch));

        $io->success(sprintf('Relayed %d outbox message%s.', $published, 1 === $published ? '' : 's'));

        return Command::SUCCESS;
    }

    /**
     * A relay that stopped is as silent as one never started. This is the
     * number an operator or a monitor reads instead of assuming: unpublished
     * rows older than {@see STALE_AFTER_SECONDS}. Zero while a relay runs.
     */
    private function status(SymfonyStyle $io): int
    {
        if (null === $this->backlog) {
            $io->error('No outbox backlog reader is wired (the persistence adapter does not provide OutboxBacklogInterface).');

            return Command::FAILURE;
        }

        $now = new DateTimeImmutable();
        $backlog = $this->backlog->unpublishedBacklog($now->modify(sprintf('-%d seconds', self::STALE_AFTER_SECONDS)));
        $oldest = $backlog->oldestUnpublishedAt;
        $oldestLine = null === $oldest
            ? 'none'
            : sprintf('%ds ago (%s)', $now->getTimestamp() - $oldest->getTimestamp(), $oldest->format(DateTimeImmutable::ATOM));

        $io->definitionList(
            ['Unpublished rows' => (string) $backlog->unpublished],
            [sprintf('Unpublished for more than %ds (should be 0)', self::STALE_AFTER_SECONDS) => (string) $backlog->staleUnpublished],
            ['Oldest unpublished row' => $oldestLine],
        );

        if ($backlog->isHealthy()) {
            $io->success('The relay is keeping up: no row has waited longer than a minute.');

            return Command::SUCCESS;
        }

        $io->error(sprintf('%d outbox row(s) have waited longer than %ds: the relay is not running, or not keeping up.', $backlog->staleUnpublished, self::STALE_AFTER_SECONDS));

        return Command::FAILURE;
    }
}
