<?php

declare(strict_types=1);

namespace CoolMS\Core\Bundle\Transaction;

use Closure;
use CoolMS\Core\Transaction\UnitOfWorkInterface;
use CoolMS\Core\Transaction\UnitOfWorkOwnerInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Transport\Sender\SendersLocatorInterface;

/**
 * A message dispatched to an ASYNCHRONOUS transport while a unit of work is open is held, and sent after the unit's
 * outermost commit -- or dropped if it is discarded: a worker never acts on work that was rolled back. One middleware,
 * for every dispatch. Held in memory: for best-effort messages; a message that must go is written to the outbox in
 * the unit instead.
 *
 * Passes through: a message routed only to `sync` (its work is the unit's, handled now), a received message, a
 * released one, and every message while no unit is open.
 */
final class HoldAsyncDispatchMiddleware implements MiddlewareInterface
{
    /** @param Closure(): MessageBusInterface $bus the bus, lazily: it holds this middleware */
    public function __construct(
        private readonly bool $enabled,
        private readonly ?UnitOfWorkOwnerInterface $owner,
        private readonly ?UnitOfWorkInterface $unit,
        private readonly SendersLocatorInterface $senders,
        private readonly Closure $bus,
    ) {
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        if (!$this->enabled || null === $this->owner || null === $this->unit || !$this->owner->ownerActive()
            || null !== $envelope->last(ReceivedStamp::class)
            || null !== $envelope->last(ReleasedAfterCommitStamp::class)
            || !$this->goesAsync($envelope)) {
            return $stack->next()->handle($envelope, $stack);
        }
        $bus = $this->bus;
        $this->unit->afterCommit(static function () use ($bus, $envelope): void {
            $bus()->dispatch($envelope->with(new ReleasedAfterCommitStamp()));
        });

        return $envelope;
    }

    private function goesAsync(Envelope $envelope): bool
    {
        foreach ($this->senders->getSenders($envelope) as $alias => $sender) {
            if ('sync' !== $alias) {
                return true;
            }
        }

        return false;
    }
}
