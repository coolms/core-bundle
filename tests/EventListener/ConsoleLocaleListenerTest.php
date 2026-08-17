<?php

declare(strict_types=1);

namespace CoolMS\CoreBundle\Tests\EventListener;

use CoolMS\Core\Config\PlatformDefaults;
use CoolMS\CoreBundle\EventListener\ConsoleLocaleListener;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Contracts\Translation\LocaleAwareInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * F6 Phase 3 -- CLI runs pin the translator locale to the
 * platform default so non-request contexts speak the platform language.
 */
final class ConsoleLocaleListenerTest extends TestCase
{
    #[Test]
    public function setsTranslatorLocaleToPlatformDefault(): void
    {
        $translator = new SpyLocaleAwareTranslator();
        $listener = new ConsoleLocaleListener(
            $translator,
            new PlatformDefaults('uk', 'Europe/Kyiv', 'dd/MM/yyyy', '24h', 'monday'),
        );

        $listener($this->event());

        self::assertSame('uk', $translator->locale);
    }

    #[Test]
    public function isNoopWhenTranslatorIsNotLocaleAware(): void
    {
        // A translator that doesn't implement LocaleAwareInterface must
        // not blow up the command -- the listener simply does nothing.
        $translator = $this->createStub(TranslatorInterface::class);
        $listener = new ConsoleLocaleListener(
            $translator,
            new PlatformDefaults('en', 'UTC', 'yyyy-MM-dd', '24h', 'monday'),
        );

        $listener($this->event());

        $this->expectNotToPerformAssertions();
    }

    private function event(): ConsoleCommandEvent
    {
        return new ConsoleCommandEvent(null, new ArrayInput([]), new NullOutput());
    }
}

/**
 * Minimal translator that records the last setLocale() call.
 */
final class SpyLocaleAwareTranslator implements TranslatorInterface, LocaleAwareInterface
{
    public string $locale = 'en';

    /**
     * @param array<array-key, mixed> $parameters
     */
    public function trans(?string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string
    {
        return (string) $id;
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    public function setLocale(string $locale): void
    {
        $this->locale = $locale;
    }
}
