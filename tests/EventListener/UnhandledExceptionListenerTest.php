<?php

declare(strict_types=1);

namespace CoolMS\Core\Bundle\Tests\EventListener;

use CoolMS\Core\Bundle\EventListener\UnhandledExceptionListener;
use CoolMS\Core\Config\PlatformDefaults;
use CoolMS\Core\Exception\TranslatableExceptionInterface;
use DomainException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Throwable;

/**
 * The unhandled-exception renderer: a server error says only its status, and a
 * client error says why, localized when it can be.
 *
 * The behaviours pinned here:
 *   1. a server error (500)    -> `detail` is "Internal Server Error", never its message
 *   2. translatable client error -> `detail` is the translated string
 *   3. plain client error       -> `detail` is its message (unchanged)
 *   4. missing catalogue entry  -> its message (no key leaks to the wire)
 *   5. translator throws        -> its message (no mask-the-original)
 * plus the status-code mapping stays intact.
 */
final class UnhandledExceptionListenerTest extends TestCase
{
    #[Test]
    public function translatableExceptionGetsLocalizedDetail(): void
    {
        $listener = $this->listener($this->translatorReturning('Локалізовано'));
        $event = $this->event(new FixtureTranslatableException('raw dev message'), locale: 'uk');

        $listener($event);

        self::assertSame('Локалізовано', $this->detail($event));
    }

    #[Test]
    public function aServerErrorSaysOnlyItsStatus(): void
    {
        $listener = $this->listener($this->translatorThatMustNotBeCalled());
        $event = $this->event(new RuntimeException('Failed to open /var/www/var/storage/secret: permission denied'));

        $listener($event);

        self::assertSame(500, $event->getResponse()?->getStatusCode());
        self::assertSame('Internal Server Error', $this->detail($event));
        self::assertStringNotContainsString('/var/www', (string) $event->getResponse()?->getContent());
    }

    #[Test]
    public function aPlainClientErrorSaysWhy(): void
    {
        $listener = $this->listener($this->translatorThatMustNotBeCalled());
        $event = $this->event(new DomainException('nope'));

        $listener($event);

        self::assertSame('nope', $this->detail($event));
    }

    #[Test]
    public function missingTranslationFallsBackToRawMessage(): void
    {
        // Translator echoes the key (Symfony's "no entry" behaviour).
        $listener = $this->listener($this->translatorEchoingKey());
        $event = $this->event(new FixtureTranslatableException('raw dev message'));

        $listener($event);

        self::assertSame('raw dev message', $this->detail($event));
    }

    #[Test]
    public function translatorThrowingFallsBackToRawMessage(): void
    {
        // An exception raised WHILE rendering an exception must never
        // mask the original -- the raw message still reaches the client.
        $listener = $this->listener($this->translatorThrowing());
        $event = $this->event(new FixtureTranslatableException('raw dev message'));

        $listener($event);

        self::assertSame('raw dev message', $this->detail($event));
    }

    #[Test]
    public function passesLocaleAndDomainToTranslator(): void
    {
        $capturedLocale = null;
        $capturedDomain = null;
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(
            static function (?string $id, array $p, ?string $domain, ?string $locale) use (&$capturedLocale, &$capturedDomain): string {
                $capturedLocale = $locale;
                $capturedDomain = $domain;

                return 'x';
            },
        );

        $listener = $this->listener($translator);
        $listener($this->event(new FixtureTranslatableException('m'), locale: 'de'));

        self::assertSame('de', $capturedLocale);
        self::assertSame('exceptions', $capturedDomain);
    }

    #[Test]
    public function domainExceptionStillMapsTo422(): void
    {
        $listener = $this->listener($this->translatorThatMustNotBeCalled());
        $event = $this->event(new DomainException('nope'));

        $listener($event);

        self::assertSame(422, $event->getResponse()?->getStatusCode());
    }

    // -- Helpers ----------------------------------------------------------------

    private function listener(TranslatorInterface $translator): UnhandledExceptionListener
    {
        return new UnhandledExceptionListener(
            new NullLogger(),
            $translator,
            new PlatformDefaults('en', 'UTC', 'yyyy-MM-dd', '24h', 'monday'),
        );
    }

    private function event(Throwable $throwable, string $locale = 'en'): ExceptionEvent
    {
        $request = Request::create('/');
        $request->setLocale($locale);

        return new ExceptionEvent(
            $this->createStub(HttpKernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
            $throwable,
        );
    }

    private function detail(ExceptionEvent $event): string
    {
        $response = $event->getResponse();
        self::assertNotNull($response);
        /** @var array{detail: string} $data */
        $data = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        return $data['detail'];
    }

    private function translatorReturning(string $value): TranslatorInterface
    {
        $stub = $this->createStub(TranslatorInterface::class);
        $stub->method('trans')->willReturn($value);

        return $stub;
    }

    private function translatorEchoingKey(): TranslatorInterface
    {
        $stub = $this->createStub(TranslatorInterface::class);
        $stub->method('trans')->willReturnCallback(static fn (?string $id): string => (string) $id);

        return $stub;
    }

    private function translatorThrowing(): TranslatorInterface
    {
        $stub = $this->createStub(TranslatorInterface::class);
        $stub->method('trans')->willThrowException(new RuntimeException('translator down'));

        return $stub;
    }

    private function translatorThatMustNotBeCalled(): TranslatorInterface
    {
        // For non-translatable exceptions the listener must not consult
        // the translator at all; a throwing stub proves it.
        return $this->translatorThrowing();
    }
}

/**
 * Test-local translatable exception. Carries a stable key + the
 * `exceptions` domain; the raw constructor message is the fallback.
 */
final class FixtureTranslatableException extends DomainException implements TranslatableExceptionInterface
{
    public function getTranslationKey(): string
    {
        return 'errors.fixture';
    }

    public function getTranslationParameters(): array
    {
        return ['%detail%' => 'x'];
    }

    public function getTranslationDomain(): string
    {
        return 'exceptions';
    }
}
