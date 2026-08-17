<?php

declare(strict_types=1);

namespace CoolMS\CoreBundle\Event\Behaviour;

use CoolMS\Core\Blameable\AccessedByProviderInterface;
use CoolMS\Core\Blameable\BlameableActorInterface;
use CoolMS\Core\Blameable\CreatedByProviderInterface;
use CoolMS\Core\Blameable\UpdatedByProviderInterface;
use CoolMS\Core\Lifecycle\OnCreateEvent;
use CoolMS\Core\Lifecycle\OnUpdateEvent;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Uid\Uuid;

/**
 * Automatically populates blameable UUID fields from the current security context.
 *
 * Mirrors TimestampableEntityEventListener in structure and placement.
 * Checks for BlameableActorInterface (not Identity\UserInterface) -- no cross-module dependency.
 *
 * onCreate: fills createdBy / updatedBy / accessedBy if null (first write)
 * onUpdate: refreshes updatedBy on every write event
 *
 * Fields are skipped when no authenticated user is present (anonymous/CLI writes remain null).
 */
#[AsEventListener(event: OnCreateEvent::class, method: 'onCreate', priority: 100)]
#[AsEventListener(event: OnUpdateEvent::class, method: 'onUpdate', priority: 100)]
final class BlameableEntityEventListener
{
    public function __construct(
        private readonly Security $security,
    ) {
    }

    public function onCreate(OnCreateEvent $event): void
    {
        $uuid = $this->resolveActorUuid();
        if (null === $uuid) {
            return;
        }

        $entity = $event->subject;
        if ($entity instanceof CreatedByProviderInterface && null === $entity->createdByAsString) {
            $entity->createdBy = $uuid;
        }
        if ($entity instanceof UpdatedByProviderInterface && null === $entity->updatedByAsString) {
            $entity->updatedBy = $uuid;
        }
        if ($entity instanceof AccessedByProviderInterface && null === $entity->accessedByAsString) {
            $entity->accessedBy = $uuid;
        }
    }

    public function onUpdate(OnUpdateEvent $event): void
    {
        $uuid = $this->resolveActorUuid();
        if (null === $uuid) {
            return;
        }

        $entity = $event->subject;
        if ($entity instanceof UpdatedByProviderInterface) {
            $entity->updatedBy = $uuid;
        }
    }

    private function resolveActorUuid(): ?Uuid
    {
        $user = $this->security->getUser();

        return $user instanceof BlameableActorInterface ? $user->id : null;
    }
}
