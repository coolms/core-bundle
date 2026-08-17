<?php

declare(strict_types=1);

namespace CoolMS\CoreBundle\Outbox;

use CoolMS\Core\Outbox\OutboxMessagePublished;
use CoolMS\Core\Outbox\OutboxPublisherInterface;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * Default {@see OutboxPublisherInterface}: dispatches the
 * {@see OutboxMessagePublished} event in-process, so any
 * `#[AsEventListener(OutboxMessagePublished::class)]` consumer in the monolith
 * receives it. The DB-backed relay IS the async boundary here; once a module is
 * extracted this is the one class that swaps to a broker publisher.
 */
final readonly class DispatchingOutboxPublisher implements OutboxPublisherInterface
{
    public function __construct(
        private EventDispatcherInterface $events,
    ) {
    }

    public function publish(OutboxMessagePublished $message): void
    {
        $this->events->dispatch($message);
    }
}
