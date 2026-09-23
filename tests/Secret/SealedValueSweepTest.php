<?php

declare(strict_types=1);

namespace CoolMS\Core\Bundle\Tests\Secret;

use CoolMS\Core\Bundle\Secret\KeyRingSealer;
use CoolMS\Core\Bundle\Secret\SealedValueSweep;
use CoolMS\Core\Bundle\Tests\Secret\Support\StaticRing;
use CoolMS\Core\Secret\RotationTally;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Service\ResetInterface;

use function array_filter;
use function array_slice;
use function array_values;
use function iterator_to_array;
use function str_starts_with;
use function strlen;
use function substr;

/**
 * The sweep as every kind runs it: reads every value, re-seals the ones under
 * the previous key, leaves the rest. The property the command relies on --
 * a second run re-seals nothing -- is asserted by running it twice.
 */
final class SealedValueSweepTest extends TestCase
{
    #[Test]
    public function readsEveryValueResealsThePreviousOnesAndLeavesTheRest(): void
    {
        $old = StaticRing::fresh();
        $new = StaticRing::fresh();
        $stranger = StaticRing::fresh();
        $underOld = new KeyRingSealer(new StaticRing($old));
        $underNew = new KeyRingSealer(new StaticRing($new));
        $underStranger = new KeyRingSealer(new StaticRing($stranger));
        $both = new KeyRingSealer(new StaticRing($new, $old));

        $store = [
            'a' => $underOld->seal('A'),
            'b' => $underOld->seal('B'),
            'c' => $underNew->seal('C'),
            'd' => $underStranger->seal('D'),
            'e' => null,
        ];
        // a plain closure: an arrow function would capture $store by value and
        // the second run would read the first run's input
        $rows = function () use (&$store): iterable {
            foreach ($store as $id => $v) {
                yield [$id, $v];
            }
        };
        $rewrite = function (mixed $id, string $v) use (&$store): void {
            $store[$id] = $v;
        };
        $sweep = new SealedValueSweep($both);
        $strangerValue = $store['d'];

        $dry = $sweep->run($rows(), $rewrite, false);
        self::assertSame([1, 2, 1, 0, 0], self::counts($dry));
        self::assertTrue(str_starts_with((string) $store['a'], 'enc:v2:' . $old->id), 'dry run writes nothing');

        $first = $sweep->run($rows(), $rewrite, true);
        self::assertSame([1, 2, 1, 0, 2], self::counts($first));
        self::assertTrue(str_starts_with((string) $store['a'], 'enc:v2:' . $new->id), 're-sealed under the current key');
        self::assertSame('A', $both->open((string) $store['a'])->plaintext);
        self::assertSame($strangerValue, $store['d'], 'the unreadable one is untouched');
        self::assertSame('D', $underStranger->open((string) $store['d'])->plaintext);

        $second = $sweep->run($rows(), $rewrite, true);
        self::assertSame([3, 0, 1, 0, 0], self::counts($second));
        self::assertTrue($second->closed());
    }

    #[Test]
    public function aKindWithItsOwnOuterMarkerUnwrapsBeforeReadingAndWrapsAfterResealing(): void
    {
        $old = StaticRing::fresh();
        $new = StaticRing::fresh();
        $marker = ':outer:v1:';
        $store = ['x' => $marker . new KeyRingSealer(new StaticRing($old))->seal('body'), 'plain' => 'From: a@b'];
        $sweep = new SealedValueSweep(new KeyRingSealer(new StaticRing($new, $old)));

        $tally = $sweep->run(
            [['x', $store['x']], ['plain', $store['plain']]],
            function (mixed $id, string $v) use (&$store): void {
                $store[$id] = $v;
            },
            true,
            isSealed: fn (string $s) => str_starts_with($s, $marker),
            unwrap: fn (string $s) => substr($s, strlen($marker)),
            wrap: fn (string $s) => $marker . $s,
        );

        self::assertSame([0, 1, 0, 1, 1], self::counts($tally));
        self::assertTrue(str_starts_with($store['x'], $marker . 'enc:v2:' . $new->id));
        self::assertSame('From: a@b', $store['plain'], 'plaintext is counted and left for the kind\'s own backfill');
    }

    #[Test]
    public function batchedWalksByKeySoARowAlreadyPassedNeverComesBack(): void
    {
        // The property this buys: the sweep REWRITES the rows it reads, so a
        // page taken by offset would slide under the walk and skip values. A
        // key that is already behind cannot come back, whatever the rewrite did.
        $all = [];
        for ($i = 1; $i <= 12; ++$i) {
            $all[] = [$i, 'v' . $i];
        }
        $asked = [];
        $fetch = static function (mixed $after, int $limit) use ($all, &$asked): array {
            $asked[] = $after;
            $rest = array_values(array_filter($all, static fn (array $row): bool => null === $after || $row[0] > $after));

            return array_slice($rest, 0, $limit);
        };

        $walked = iterator_to_array($this->sweep()->batched($fetch, null, 5), false);

        self::assertSame($all, $walked, 'every row once, in key order');
        self::assertSame([null, 5, 10], $asked, 'three pages, each asked for what comes AFTER the last key');
    }

    #[Test]
    public function aFetchWhoseKeyDoesNotMoveEndsTheWalkInsteadOfLoopingForEver(): void
    {
        $stuck = static fn (): array => [[7, 'a'], [7, 'b']];

        $walked = iterator_to_array($this->sweep()->batched($stuck, null, 2), false);

        self::assertCount(4, $walked, 'the page is taken twice and the walk stops: a key column that does not'
            . ' advance is a broken column, not a reason to hang');
    }

    #[Test]
    public function theProcessIsAskedToLetGoOnEveryPageBoundaryAndNotBefore(): void
    {
        // Two boundaries and a remainder, expressed in terms of BATCH so the
        // expectation follows the constant instead of restating it. Nothing is
        // sealed here on purpose: what the release is counted against is ROWS
        // READ, which is what the memory is proportional to, not values found.
        $total = SealedValueSweep::BATCH * 2 + 200;
        $all = [];
        for ($i = 0; $i < $total; ++$i) {
            $all[] = [$i, 'not sealed at all'];
        }
        $recorder = $this->createMock(ResetInterface::class);
        $recorder->expects(self::exactly(2))->method('reset');
        $sweep = new SealedValueSweep(new KeyRingSealer(new StaticRing(StaticRing::fresh())), $recorder);

        $tally = $sweep->run(
            $sweep->batched(static fn (mixed $after, int $limit): array => self::page($all, $after, $limit)),
            static fn (): null => null,
            true,
            isSealed: static fn (): bool => false,
        );

        // The subject before the claim: the sweep actually walked those rows. A
        // release count on its own would be satisfied by a loop that ran twice
        // over nothing.
        self::assertSame($total, $tally->total(), 'the sweep saw every row');
        self::assertSame($total, $tally->plaintext);
    }

    #[Test]
    public function theKindLandsItsWritesBeforeTheProcessLetsGoOfThem(): void
    {
        // The ORDER is the subject, not the counts. A kind whose writes are
        // deferred to the end of the command loses them if the release clears
        // the manager first, and the rotation ended in exactly that: a terminal
        // flush over an association nobody had persisted.
        $log = new class implements ResetInterface {
            /** @var list<string> */
            public array $calls = [];

            public function reset(): void
            {
                $this->calls[] = 'release';
            }
        };
        $sweep = new SealedValueSweep(new KeyRingSealer(new StaticRing(StaticRing::fresh())), $log);
        $all = [];
        for ($i = 0; $i < 7; ++$i) {
            $all[] = [$i, 'v'];
        }

        $walked = iterator_to_array($sweep->batched(
            static fn (mixed $after, int $limit): array => self::page($all, $after, $limit),
            static function () use ($log): void {
                $log->calls[] = 'land';
            },
            3,
        ), false);

        self::assertCount(7, $walked, 'three pages were walked: the subject exists');
        self::assertSame(['land', 'release', 'land', 'release'], $log->calls, 'landed first, let go second, never'
            . ' before the first page');
    }

    #[Test]
    public function aSweepWithNoReleaserStillRuns(): void
    {
        // The control for the test above: the release is an optional collaborator,
        // and a sweep constructed without one (every existing caller, and every
        // test in this file) must behave exactly as it did before.
        $sweep = new SealedValueSweep(new KeyRingSealer(new StaticRing(StaticRing::fresh())));

        $tally = $sweep->run(
            [[1, 'not sealed at all']],
            static fn (): null => null,
            true,
            isSealed: static fn (): bool => false,
        );

        self::assertSame(1, $tally->plaintext);
    }

    /**
     * One page of `$all` after `$after`, as a keyed pager asks for it.
     *
     * @param list<array<int, mixed>> $all
     *
     * @return list<array<int, mixed>>
     */
    private static function page(array $all, mixed $after, int $limit): array
    {
        $rest = array_values(array_filter($all, static fn (array $row): bool => null === $after || $row[0] > $after));

        return array_slice($rest, 0, $limit);
    }

    /** @return list<int> current, previous, unreadable, plaintext, resealed */
    private static function counts(RotationTally $t): array
    {
        return [$t->current, $t->previous, $t->unreadable, $t->plaintext, $t->resealed];
    }

    private function sweep(): SealedValueSweep
    {
        return new SealedValueSweep(new KeyRingSealer(new StaticRing(StaticRing::fresh())));
    }
}
