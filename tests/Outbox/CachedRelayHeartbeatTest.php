<?php

declare(strict_types=1);

namespace CoolMS\Core\Bundle\Tests\Outbox;

use CoolMS\Core\Bundle\Outbox\CachedRelayHeartbeat;
use CoolMS\Core\Outbox\RelayHeartbeat;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * One key, the latest beat, with a lifetime: what the relay writes is what
 * the doctor reads back, the newest pass replaces the older, and a pool that
 * holds nothing (or holds something that is not a beat) reads as none.
 */
final class CachedRelayHeartbeatTest extends TestCase
{
    #[Test]
    public function theLatestBeatIsReadBackWithItsNumbers(): void
    {
        $store = new CachedRelayHeartbeat(new ArrayAdapter());

        self::assertNull($store->last(), 'nothing beat yet');

        $store->beat(new RelayHeartbeat(new DateTimeImmutable('2026-09-21 09:00:00+00:00'), 100, 3));
        $store->beat(new RelayHeartbeat(new DateTimeImmutable('2026-09-21 09:00:05+00:00'), 100, 0));

        $last = $store->last();
        self::assertNotNull($last);
        self::assertSame('2026-09-21T09:00:05+00:00', $last->at->format(DATE_ATOM), 'the newest pass, not the first');
        self::assertSame(100, $last->batch);
        self::assertSame(0, $last->published, 'an empty pass is recorded as a pass');
    }

    #[Test]
    public function aBeatLapsesWithItsKey(): void
    {
        $pool = new ArrayAdapter();
        $store = new CachedRelayHeartbeat($pool);
        $store->beat(new RelayHeartbeat(new DateTimeImmutable('2026-09-21 09:00:00+00:00'), 100, 1));

        $item = $pool->getItem(CachedRelayHeartbeat::KEY);
        self::assertTrue($item->isHit());
        $pool->deleteItem(CachedRelayHeartbeat::KEY);

        self::assertNull($store->last(), 'a key that lapsed is no beat, not a beat from last week');
    }

    #[Test]
    public function aValueThatIsNotABeatReadsAsNone(): void
    {
        $pool = new ArrayAdapter();
        $item = $pool->getItem(CachedRelayHeartbeat::KEY);
        $item->set(['at' => '2026-09-21T09:00:00+00:00', 'batch' => 'a hundred']);
        $pool->save($item);

        self::assertNull(new CachedRelayHeartbeat($pool)->last());
    }
}
