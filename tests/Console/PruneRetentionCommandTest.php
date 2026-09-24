<?php

declare(strict_types=1);

namespace CoolMS\Core\Bundle\Tests\Console;

use CoolMS\Core\Application\Retention\RetentionPruneRunner;
use CoolMS\Core\Bundle\Console\PruneRetentionCommand;
use CoolMS\Core\Retention\RetentionPopulationInterface;
use CoolMS\Core\Retention\RetentionPrunerInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `coolms:retention:prune` in the destructive-command shape: a dry run unless
 * --execute, "N of M" with M unknown (and the dry run UNEVALUABLE) for a pruner
 * that cannot count, a refusal unattended without --force, and the per-pruner
 * outcome when it does delete.
 */
final class PruneRetentionCommandTest extends TestCase
{
    #[Test]
    public function withoutExecuteItOnlyReportsNOfM(): void
    {
        $tester = $this->tester([$this->pruner('comment.spam', 'Spam comments', 3, 5, population: 40)]);

        $exit = $tester->execute([], ['interactive' => false]);

        self::assertSame(0, $exit);
        $display = $tester->getDisplay();
        self::assertStringContainsString('Spam comments (comment.spam): would delete 5 of 40 row(s).', $display);
        self::assertStringContainsString('Would delete 5 of 40 row(s) across 1 pruner(s).', $display);
        self::assertStringNotContainsString('Pruned ', $display);
    }

    #[Test]
    public function aPopulationNobodyCountedIsUnknownAndTheDryRunUnevaluable(): void
    {
        $tester = $this->tester([
            $this->pruner('comment.spam', 'Spam comments', 3, 5, population: 40),
            $this->pruner('analytics.events', 'Analytics events', 7, 7),
        ]);

        $exit = $tester->execute([], ['interactive' => false]);

        self::assertSame(2, $exit, 'unknown is not zero');
        self::assertStringContainsString('would delete 7 of unknown row(s)', $tester->getDisplay());
        self::assertStringContainsString('Would delete 12 of 40 + unknown row(s)', $tester->getDisplay());
    }

    #[Test]
    public function unattendedExecuteWithoutForceRefuses(): void
    {
        $tester = $this->tester([$this->pruner('comment.spam', 'Spam comments', 3, 5, population: 40)]);

        $exit = $tester->execute(['--execute' => true], ['interactive' => false]);

        self::assertSame(2, $exit);
        self::assertStringContainsString('--force', $tester->getDisplay());
        self::assertStringNotContainsString('Pruned ', $tester->getDisplay());
    }

    #[Test]
    public function itReportsThePerPrunerAndTotalRemovedCounts(): void
    {
        $tester = $this->tester([
            $this->pruner('analytics.events', 'Analytics events', 7, 7, population: 70),
            $this->pruner('comment.spam', 'Spam comments', 3, 3, population: 30),
        ]);

        $tester->execute(['--execute' => true, '--force' => true], ['interactive' => false]);

        $tester->assertCommandIsSuccessful();
        $display = $tester->getDisplay();
        self::assertStringContainsString('Pruned 7 row(s) from Analytics events (analytics.events).', $display);
        self::assertStringContainsString('Pruned 3 row(s) from Spam comments (comment.spam).', $display);
        self::assertStringContainsString('10 row(s) removed across 2 pruner(s)', $display);
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

    private function pruner(
        string $key,
        string $label,
        int $removed,
        int $prunable,
        ?int $population = null,
    ): RetentionPrunerInterface {
        $plain = new class($key, $label, $removed, $prunable) implements RetentionPrunerInterface {
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
        if (null === $population) {
            return $plain;
        }

        return new class($plain, $population) implements RetentionPrunerInterface, RetentionPopulationInterface {
            public function __construct(
                private readonly RetentionPrunerInterface $inner,
                private readonly int $population,
            ) {
            }

            public function retentionKey(): string
            {
                return $this->inner->retentionKey();
            }

            public function retentionLabel(): string
            {
                return $this->inner->retentionLabel();
            }

            public function pruneExpired(): int
            {
                return $this->inner->pruneExpired();
            }

            public function countExpired(): int
            {
                return $this->inner->countExpired();
            }

            public function countPopulation(): int
            {
                return $this->population;
            }
        };
    }
}
