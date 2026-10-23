<?php

declare(strict_types=1);

namespace CoolMS\Core\Bundle\Transaction;

use CoolMS\Core\Transaction\UnitOfWorkOwnerInterface;
use LogicException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Throwable;

/**
 * A received message's unit of work: commit when its handlers succeed, discard on any exception (rethrown).
 *
 * Only a message RECEIVED from an asynchronous transport opens one. A message on the `sync` transport is handled in
 * its caller's process and its work is the caller's: one that opened a unit of its own would commit re-entrantly
 * inside the caller's flush. A unit already open (any other caller) is left to its owner.
 *
 * A message of an unscoped class (`coolms_core.transaction.unscoped_messages`) opens none either: its handler talks to
 * another system between its writes (an IMAP sync fetching batch after batch), and one unit would hold a transaction
 * open across that whole conversation. Each transactional() call inside it is a unit of its own.
 */
final class MessageUnitOfWorkMiddleware implements MiddlewareInterface
{
    /** @param list<string> $unscoped */
    public function __construct(
        private readonly bool $enabled,
        private readonly ?UnitOfWorkOwnerInterface $unit,
        private readonly array $unscoped = [],
    ) {
        if ($enabled && null === $unit) {
            throw new LogicException('coolms_core.transaction.mode is on, and no CoolMS\Core\Transaction\UnitOfWorkOwnerInterface service exists.');
        }
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        $received = $envelope->last(ReceivedStamp::class);
        if (!$this->enabled || null === $this->unit || null === $received || 'sync' === $received->getTransportName()
            || $this->unit->ownerActive() || $this->isUnscoped($envelope->getMessage())) {
            return $stack->next()->handle($envelope, $stack);
        }
        $this->unit->begin();
        try {
            $envelope = $stack->next()->handle($envelope, $stack);
        } catch (Throwable $e) {
            $this->unit->discard();

            throw $e;
        }
        $this->unit->commit();

        return $envelope;
    }

    private function isUnscoped(object $message): bool
    {
        foreach ($this->unscoped as $class) {
            if ($message instanceof $class) {
                return true;
            }
        }

        return false;
    }
}
