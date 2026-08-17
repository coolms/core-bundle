<?php

declare(strict_types=1);

namespace CoolMS\CoreBundle\Event\Behaviour;

use CoolMS\Core\Lifecycle\OnCreateEvent;
use CoolMS\Core\Lifecycle\OnUpdateEvent;
use CoolMS\Core\Timestampable\AccessedAtProviderInterface;
use CoolMS\Core\Timestampable\CreatedAtProviderInterface;
use CoolMS\Core\Timestampable\UpdatedAtProviderInterface;
use DateTimeImmutable;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener(event: OnCreateEvent::class, method: 'onCreate', priority: 100)]
#[AsEventListener(event: OnUpdateEvent::class, method: 'onUpdate', priority: 100)]
class TimestampableEntityEventListener
{
    public function onCreate(OnCreateEvent $event): void
    {
        $entity = $event->subject;
        $timestamp = new DateTimeImmutable();
        if ($entity instanceof AccessedAtProviderInterface && null === $entity->accessedAtAsString) {
            $entity->accessedAt = $timestamp;
        }
        if ($entity instanceof CreatedAtProviderInterface && null === $entity->createdAtAsString) {
            $entity->createdAt = $timestamp;
        }
        if ($entity instanceof UpdatedAtProviderInterface && null === $entity->updatedAtAsString) {
            $entity->updatedAt = $timestamp;
        }
    }

    public function onUpdate(OnUpdateEvent $event): void
    {
        $entity = $event->subject;
        if ($entity instanceof UpdatedAtProviderInterface) {
            $entity->updatedAt = new DateTimeImmutable();
        }
    }
}
