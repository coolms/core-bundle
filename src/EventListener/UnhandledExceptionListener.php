<?php

declare(strict_types=1);

namespace CoolMS\CoreBundle\EventListener;

use CoolMS\Core\Config\PlatformDefaults;
use CoolMS\Core\Exception\InvalidInputExceptionInterface;
use CoolMS\Core\Exception\TranslatableExceptionInterface;
use DomainException;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Contracts\Translation\TranslatorInterface;
use Throwable;

/**
 * Catches unhandled Throwables and returns RFC 7807 Problem Details JSON
 * instead of a raw 500 HTML page.
 *
 * Priority -100 runs after API Platform's own exception normalizer (priority 0),
 * so only exceptions that slipped through all earlier listeners reach here.
 *
 * F6 Phase 3: a {@see TranslatableExceptionInterface} has its
 * `detail` rendered through the translator against the request locale,
 * so error messages are localized like the rest of the platform.
 * Plain exceptions pass their raw message through unchanged
 * (behaviour-preserving). The log line always uses the raw message --
 * operators read logs in one language, not the requester's.
 */
#[AsEventListener(event: KernelEvents::EXCEPTION, priority: -100)]
final class UnhandledExceptionListener
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly TranslatorInterface $translator,
        private readonly PlatformDefaults $platformDefaults,
    ) {
    }

    public function __invoke(ExceptionEvent $event): void
    {
        $throwable = $event->getThrowable();

        // HttpExceptionInterface already produces the correct response via Symfony/API Platform.
        if ($throwable instanceof HttpExceptionInterface) {
            return;
        }

        $status = match (true) {
            $throwable instanceof InvalidInputExceptionInterface,
            $throwable instanceof InvalidArgumentException => 400,
            $throwable instanceof DomainException => 422,
            default => 500,
        };

        if (500 === $status) {
            // Raw message: developer-facing, source language, not the
            // requester's locale.
            $this->logger->critical('Unhandled exception', [
                'exception' => $throwable,
                'message' => $throwable->getMessage(),
            ]);
        }

        $event->setResponse(new JsonResponse([
            'type' => '/errors/' . $status,
            'title' => 500 === $status ? 'Internal Server Error' : 'An error occurred',
            'status' => $status,
            'detail' => $this->resolveDetail($throwable, $event),
        ], $status, ['Content-Type' => 'application/problem+json']));
    }

    /**
     * Localized `detail` for translatable exceptions; raw message
     * otherwise. Falls back to the raw message when the catalogue has no
     * entry (translator echoes the key) and -- defensively -- when the
     * translator itself throws: an exception raised while rendering an
     * exception must never mask the original.
     */
    private function resolveDetail(Throwable $throwable, ExceptionEvent $event): string
    {
        if (!$throwable instanceof TranslatableExceptionInterface) {
            return $throwable->getMessage();
        }

        // Request locale (stamped by F5's LocaleRequestListener); the
        // platform default is the floor when the request carries none.
        $locale = $event->getRequest()->getLocale() ?: $this->platformDefaults->locale;
        $key = $throwable->getTranslationKey();

        try {
            $translated = $this->translator->trans(
                $key,
                $throwable->getTranslationParameters(),
                $throwable->getTranslationDomain(),
                $locale,
            );
        } catch (Throwable) {
            return $throwable->getMessage();
        }

        // Symfony returns the key unchanged when no translation exists;
        // an empty/un-set key echoes to '' -- both fall back to the raw
        // developer message rather than surfacing a key in the response.
        return ($translated === $key || '' === $key)
            ? $throwable->getMessage()
            : $translated;
    }
}
