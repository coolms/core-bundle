<?php

declare(strict_types=1);

namespace CoolMS\CoreBundle\EventListener;

use CoolMS\Core\Config\PlatformDefaults;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Contracts\Translation\LocaleAwareInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Pins the translator locale to the platform default for CLI runs. F6
 * Phase 3.
 *
 * Console commands, Messenger consumers, and scheduled jobs have no HTTP
 * request, so the F5 per-request locale chain never fires and the
 * translator falls back to `kernel.default_locale` -- a framework scalar
 * disconnected from the deployment's actual locale. This listener sets
 * the translator's current locale to `PlatformDefaults::$locale` at
 * command start, so any `trans()` a command emits (logs, notifications,
 * generated documents) speaks the platform language.
 *
 * Only the current-locale default is changed; an explicit `locale`
 * argument to `trans()` still wins. The guard tolerates a translator
 * that isn't `LocaleAwareInterface` (no-op) rather than hard-depending
 * on the concrete decorator.
 */
#[AsEventListener(event: ConsoleEvents::COMMAND)]
final class ConsoleLocaleListener
{
    public function __construct(
        private readonly TranslatorInterface $translator,
        private readonly PlatformDefaults $platformDefaults,
    ) {
    }

    public function __invoke(ConsoleCommandEvent $event): void
    {
        if ($this->translator instanceof LocaleAwareInterface) {
            $this->translator->setLocale($this->platformDefaults->locale);
        }
    }
}
