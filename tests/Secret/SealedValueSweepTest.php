<?php

declare(strict_types=1);

namespace CoolMS\Core\Bundle\Tests\Secret;

use CoolMS\Core\Bundle\Secret\KeyRingSealer;
use CoolMS\Core\Bundle\Secret\SealedValueSweep;
use CoolMS\Core\Bundle\Tests\Secret\Support\StaticRing;
use CoolMS\Core\Secret\RotationTally;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

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
        self::assertTrue(str_starts_with($store['a'], 'enc:v2:' . $old->id), 'dry run writes nothing');

        $first = $sweep->run($rows(), $rewrite, true);
        self::assertSame([1, 2, 1, 0, 2], self::counts($first));
        self::assertTrue(str_starts_with($store['a'], 'enc:v2:' . $new->id), 're-sealed under the current key');
        self::assertSame('A', $both->open($store['a'])->plaintext);
        self::assertSame($strangerValue, $store['d'], 'the unreadable one is untouched');
        self::assertSame('D', $underStranger->open($store['d'])->plaintext);

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

    /** @return list<int> current, previous, unreadable, plaintext, resealed */
    private static function counts(RotationTally $t): array
    {
        return [$t->current, $t->previous, $t->unreadable, $t->plaintext, $t->resealed];
    }
}
