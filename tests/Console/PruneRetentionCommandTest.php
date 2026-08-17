<?php

declare(strict_types=1);

namespace CoolMS\CoreBundle\Tests\Console;

use CoolMS\Core\Retention\RetentionPrunerInterface;
use CoolMS\CoreBundle\Console\PruneRetentionCommand;
use CoolMS\CoreModule\Retention\RetentionPruneRunner;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `coolms:retention:prune` reports the per-pruner sweep outcome across every
 * registered pruner + a total, previews on `--dry-run` without deleting, and
 * warns when nothing is registered.
 */
final class PruneRetentionCommandTest extends TestCase
{
    #[Test]
    public function itReportsThePerPrunerAndTotalRemovedCounts(): void
    {
        $tester = $this->tester([
            $this->pruner('analytics.events', 'Analytics events', 7, 7),
            $this->pruner('comment.spam', 'Spam comments', 3, 3),
        ]);

        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        $display = $tester->getDisplay();
        self::assertStringContainsString('Pruned 7 rows from Analytics events (analytics.events).', $display);
        self::assertStringContainsString('Pruned 3 rows from Spam comments (comment.spam).', $display);
        self::assertStringContainsString('10 rows removed across 2 pruners', $display);
    }

    #[Test]
    public function dryRunPreviewsWithoutDeleting(): void
    {
        $tester = $this->tester([$this->pruner('comment.spam', 'Spam comments', 3, 5)]);

        $tester->execute(['--dry-run' => true]);

        $tester->assertCommandIsSuccessful();
        $display = $tester->getDisplay();
        self::assertStringContainsString('Spam comments (comment.spam) would prune 5 rows', $display);
        self::assertStringContainsString('Nothing was deleted', $display);
        self::assertStringNotContainsString('Pruned ', $display);
    }

    #[Test]
    public function itWarnsWhenNoPrunersAreRegistered(): void
    {
        $tester = $this->tester([]);

        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('No retention pruners are registered', $tester->getDisplay());
    }

    /**
     * @param list<RetentionPrunerInterface> $pruners
     */
    private function tester(array $pruners): CommandTester
    {
        return new CommandTester(new PruneRetentionCommand(new RetentionPruneRunner($pruners)));
    }

    private function pruner(string $key, string $label, int $removed, int $prunable): RetentionPrunerInterface
    {
        return new class($key, $label, $removed, $prunable) implements RetentionPrunerInterface {
            public function __construct(
                private readonly string $key,
                private readonly string $label,
                private readonly int $removed,
                private readonly int $prunable,
            ) {
            }

            public function retentionKey(): string
            {
                return $this->key;
            }

            public function retentionLabel(): string
            {
                return $this->label;
            }

            public function pruneExpired(): int
            {
                return $this->removed;
            }

            public function countExpired(): int
            {
                return $this->prunable;
            }
        };
    }
}
