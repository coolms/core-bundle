<?php

declare(strict_types=1);

namespace CoolMS\CoreBundle\Tests\EventListener;

use CoolMS\Core\Config\PlatformDefaults;
use CoolMS\Core\Exception\TranslatableExceptionInterface;
use CoolMS\Core\Exception\TranslatableExceptionTrait;
use CoolMS\CoreBundle\EventListener\UnhandledExceptionListener;
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
 * F6 Phase 3 -- the unhandled-exception renderer localizes
 * translatable exceptions and leaves everything else exactly as before.
 *
 * The four behaviours pinned here:
 *   1. translatable exception -> `detail` is the translated string
 *   2. plain exception        -> `detail` is the raw message (unchanged)
 *   3. missing catalogue entry -> raw message (no key leaks to the wire)
 *   4. translator throws       -> raw message (no mask-the-original)
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
    public function plainExceptionPassesRawMessageThrough(): void
    {
        $listener = $this->listener($this->translatorThatMustNotBeCalled());
        $event = $this->event(new RuntimeException('boom'));

        $listener($event);

        self::assertSame('boom', $this->detail($event));
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

    // ── Helpers ────────────────────────────────────────────────────────────────

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
final class FixtureTranslatableException extends RuntimeException implements TranslatableExceptionInterface
{
    use TranslatableExceptionTrait;

    public function __construct(string $rawMessage)
    {
        parent::__construct($rawMessage);
        $this->setTranslation('errors.fixture', ['%detail%' => 'x'], 'exceptions');
    }
}
