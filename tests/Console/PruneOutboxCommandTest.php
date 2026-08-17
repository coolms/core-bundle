<?php

declare(strict_types=1);

namespace CoolMS\CoreBundle\Tests\Console;

use CoolMS\Core\Inbox\ProcessedMessageStoreInterface;
use CoolMS\Core\Outbox\OutboxRelayRepositoryInterface;
use CoolMS\CoreBundle\Console\PruneOutboxCommand;
use CoolMS\CoreModule\Outbox\OutboxMaintenanceService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `coolms:outbox:prune --dry-run` previews the per-table prune counts (F7
 * retention rails) without deleting anything -- the read-only sibling of
 * the destructive default run.
 */
final class PruneOutboxCommandTest extends TestCase
{
    #[Test]
    public function dryRunReportsThePreviewForBothTablesWithoutDeleting(): void
    {
        $outbox = $this->createStub(OutboxRelayRepositoryInterface::class);
        $outbox->method('countPublishedOlderThan')->willReturn(4);
        $inbox = $this->createStub(ProcessedMessageStoreInterface::class);
        $inbox->method('countProcessedOlderThan')->willReturn(2);

        $service = new OutboxMaintenanceService($outbox, $inbox, new MockClock(), 7, 30);
        $tester = new CommandTester(new PruneOutboxCommand($service));

        $tester->execute(['--dry-run' => true]);

        $tester->assertCommandIsSuccessful();
        $display = $tester->getDisplay();
        self::assertStringContainsString('4 delivered outbox rows and 2 idempotency-inbox rows', $display);
        self::assertStringContainsString('would be pruned', $display);
        self::assertStringContainsString('Nothing was deleted', $display);
        // The delete-path success line ("Pruned N …") must NOT appear on a dry run.
        self::assertStringNotContainsString('Pruned ', $display);
    }
}
