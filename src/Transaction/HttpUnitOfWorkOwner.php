<?php

declare(strict_types=1);

namespace CoolMS\Core\Bundle\Transaction;

use CoolMS\Core\Transaction\UnitOfWorkOwnerInterface;
use LogicException;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Throwable;

/**
 * The main request's unit of work. Opened first thing on kernel.request (before the firewall); decided on
 * kernel.response at -1020 -- after every listener that writes for the response, before it is sent: an exception for
 * the main request, or a status of 400 or above, discards; anything else commits. A commit that fails, or a unit that
 * is no longer usable, turns the response into a 500 -- never a 2xx over work that was not kept.
 *
 * Sub-requests never open, commit or discard: their work is the main request's. A unit still open at terminate (no
 * kernel.response came) is discarded and logged. A streamed response's callback runs after the decision: it writes
 * nothing, or writes in a short unit of its own (UnitOfWorkInterface::transactional()).
 */
final class HttpUnitOfWorkOwner implements EventSubscriberInterface
{
    private bool $mine = false;
    private bool $failed = false;

    public function __construct(
        private readonly bool $enabled,
        private readonly ?UnitOfWorkOwnerInterface $unit,
        private readonly LoggerInterface $logger,
    ) {
        if ($enabled && null === $unit) {
            throw new LogicException('coolms_core.transaction.mode is on, and no CoolMS\Core\Transaction\UnitOfWorkOwnerInterface service exists.');
        }
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['open', 4096],
            KernelEvents::EXCEPTION => ['failed', 4096],
            KernelEvents::RESPONSE => ['decide', -1020],
            KernelEvents::TERMINATE => ['leftOpen', 4096],
        ];
    }

    public function open(RequestEvent $event): void
    {
        if (!$this->enabled || null === $this->unit || !$event->isMainRequest()) {
            return;
        }
        $this->unit->begin();
        $this->mine = true;
        $this->failed = false;
    }

    public function failed(ExceptionEvent $event): void
    {
        if ($event->isMainRequest()) {
            $this->failed = true;
        }
    }

    public function decide(ResponseEvent $event): void
    {
        if (!$this->mine || null === $this->unit || !$event->isMainRequest()) {
            return;
        }
        $this->mine = false;
        if ($this->failed || $event->getResponse()->getStatusCode() >= 400) {
            $this->unit->discard();

            return;
        }
        try {
            $this->unit->commit();
        } catch (Throwable $e) {
            $this->logger->error('The request could not commit its unit of work; answered 500', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
                'path' => $event->getRequest()->getPathInfo(),
            ]);
            $event->setResponse(new JsonResponse(['title' => 'The change could not be saved.', 'status' => 500], 500));
        }
    }

    public function leftOpen(TerminateEvent $event): void
    {
        if (!$this->mine || null === $this->unit) {
            return;
        }
        $this->mine = false;
        $this->unit->discard();
        $this->logger->error('A request ended with its unit of work open; discarded', [
            'path' => $event->getRequest()->getPathInfo(),
        ]);
    }
}
