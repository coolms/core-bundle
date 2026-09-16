<?php

declare(strict_types=1);

namespace CoolMS\Core\Bundle\Tests\Console;

use CoolMS\Core\Application\Outbox\OutboxRelay;
use CoolMS\Core\Bundle\Console\RelayOutboxCommand;
use CoolMS\Core\Outbox\OutboxBacklog;
use CoolMS\Core\Outbox\OutboxBacklogInterface;
use CoolMS\Core\Outbox\OutboxPublisherInterface;
use CoolMS\Core\Outbox\OutboxRelayRepositoryInterface;
use CoolMS\Core\Persistence\ManagerResetterInterface;
use CoolMS\Core\Transaction\ConnectionTransactionRunnerInterface;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `--status` reads the backlog port and turns the number that should be zero
 * into an exit code: 0 while every unpublished row is younger than a minute,
 * 1 the moment one is older -- a relay that stopped, made visible.
 */
final class RelayOutboxStatusCommandTest extends TestCase
{
    #[Test]
    public function aHealthyBacklogExitsZeroAndSaysSo(): void
    {
        $tester = $this->tester(new OutboxBacklog(3, 0, new DateTimeImmutable('-20 seconds')));

        $exit = $tester->execute(['--status' => true]);

        self::assertSame(Command::SUCCESS, $exit);
        $display = $tester->getDisplay();
        self::assertStringContainsString('Unpublished rows', $display);
        self::assertStringContainsString('keeping up', $display);
    }

    #[Test]
    public function aStaleRowExitsOneAndNamesTheCount(): void
    {
        $tester = $this->tester(new OutboxBacklog(5, 2, new DateTimeImmutable('-300 seconds')));

        $exit = $tester->execute(['--status' => true]);

        self::assertSame(Command::FAILURE, $exit);
        self::assertStringContainsString('2 outbox row(s) have waited longer than 60s', $tester->getDisplay());
    }

    #[Test]
    public function withoutABacklogReaderStatusFailsRatherThanGuessing(): void
    {
        $tester = new CommandTester(new RelayOutboxCommand($this->relay(), $this->runner(), null));

        $exit = $tester->execute(['--status' => true]);

        self::assertSame(Command::FAILURE, $exit);
        self::assertStringContainsString('No outbox backlog reader is wired', $tester->getDisplay());
    }

    private function tester(OutboxBacklog $backlog): CommandTester
    {
        $reader = new class($backlog) implements OutboxBacklogInterface {
            public function __construct(private readonly OutboxBacklog $backlog)
            {
            }

            public function unpublishedBacklog(DateTimeImmutable $olderThan): OutboxBacklog
            {
                return $this->backlog;
            }
        };

        return new CommandTester(new RelayOutboxCommand($this->relay(), $this->runner(), $reader));
    }

    /** A real relay over stubs; `--status` must never reach it, and a stub repository would claim nothing anyway. */
    private function relay(): OutboxRelay
    {
        return new OutboxRelay(
            $this->createStub(OutboxRelayRepositoryInterface::class),
            $this->createStub(OutboxPublisherInterface::class),
            $this->createStub(ManagerResetterInterface::class),
            new NullLogger(),
        );
    }

    private function runner(): ConnectionTransactionRunnerInterface
    {
        return new class implements ConnectionTransactionRunnerInterface {
            public function run(callable $work): mixed
            {
                return $work();
            }
        };
    }
}
