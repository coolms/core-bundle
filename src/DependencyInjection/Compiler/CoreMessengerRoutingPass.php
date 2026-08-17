<?php

declare(strict_types=1);

namespace CoolMS\CoreBundle\DependencyInjection\Compiler;

use CoolMS\Core\Event\DomainEventInterface;

/**
 * Default Messenger routing for Core-module messages.
 *
 * Routes all DomainEvents to the synchronous transport so domain
 * events are always dispatched in-process, within the same transaction.
 *
 * App-level messenger.yaml takes precedence -- if a project needs async
 * domain events, it can override DomainEventInterface routing there.
 */
final class CoreMessengerRoutingPass extends AbstractMessengerRoutingPass
{
    protected function getRouting(): array
    {
        return [
            DomainEventInterface::class => 'sync',
        ];
    }
}
