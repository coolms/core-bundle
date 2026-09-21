<?php

declare(strict_types=1);

namespace CoolMS\Core\Bundle\Outbox;

use CoolMS\Core\Outbox\RelayHeartbeat;
use CoolMS\Core\Outbox\RelayHeartbeatInterface;
use DateTimeImmutable;
use Psr\Cache\CacheItemPoolInterface;
use Throwable;

use function is_array;
use function is_int;
use function is_string;

/**
 * The relay's heartbeat in a cache pool: one key, the latest beat, overwritten
 * each pass, with a lifetime a few multiples of any freshness window a
 * monitor would apply -- so nothing accumulates and a relay that stopped is
 * read as "none" once the key lapses, not as a beat from last week.
 *
 * The pool is chosen by configuration (`core.outbox.heartbeat_pool`) and MUST
 * be one that the relay's container and the monitor's container both reach:
 * the relay beats in its own long-running container and `coolms:doctor`
 * reads in the application's, so a filesystem pool answers "none" to every
 * doctor while the relay is perfectly alive. A shared store (the same rule
 * the async worker's heartbeat follows) is the only kind that can carry it.
 */
final readonly class CachedRelayHeartbeat implements RelayHeartbeatInterface
{
    public const string KEY = 'coolms.outbox.relay_heartbeat';

    /** Long enough that a paused relay is still dated, short enough to lapse. */
    public const int TTL_SECONDS = 3600;

    public function __construct(private CacheItemPoolInterface $pool)
    {
    }

    public function beat(RelayHeartbeat $heartbeat): void
    {
        $item = $this->pool->getItem(self::KEY);
        $item->set([
            'at' => $heartbeat->at->format(DATE_ATOM),
            'batch' => $heartbeat->batch,
            'published' => $heartbeat->published,
        ]);
        $item->expiresAfter(self::TTL_SECONDS);
        $this->pool->save($item);
    }

    public function last(): ?RelayHeartbeat
    {
        try {
            $item = $this->pool->getItem(self::KEY);
        } catch (Throwable) {
            // A pool that cannot be asked (the shared store is down) reads as
            // no beat: the monitor will say the relay is not answering, which
            // is the truthful reading of a heartbeat nobody can reach.
            return null;
        }
        if (!$item->isHit()) {
            return null;
        }
        $value = $item->get();
        if (!is_array($value)) {
            return null;
        }
        $at = $value['at'] ?? null;
        $batch = $value['batch'] ?? null;
        $published = $value['published'] ?? null;
        if (!is_string($at) || !is_int($batch) || !is_int($published)) {
            return null;
        }

        return new RelayHeartbeat(new DateTimeImmutable($at), $batch, $published);
    }
}
