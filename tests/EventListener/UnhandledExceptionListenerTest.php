<?php

declare(strict_types=1);

namespace CoolMS\Core\Bundle\Tests\EventListener;

use CoolMS\Core\Bundle\EventListener\UnhandledExceptionListener;
use CoolMS\Core\Config\PlatformDefaults;
use CoolMS\Core\Exception\InvalidInputExceptionInterface;
use CoolMS\Core\Exception\TranslatableExceptionInterface;
use DomainException;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\NullLogger;
use RuntimeException;
use Stringable;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Throwable;

/**
 * The unhandled-exception renderer: a server error says only its status; a client
 * error says why only when its exception says the message is meant for the client
 * (an invalid input, or a translatable one), localized when it can be.
 *
 * The behaviours pinned here:
 *   1. a server error (500)    -> `detail` is "Internal Server Error", never its message
 *   2. translatable client error -> `detail` is the translated string
 *   3. plain client error       -> `detail` is its status text; the message is logged
 *   3b. an invalid input        -> `detail` is its message
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

        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame(500, $response->getStatusCode());
        self::assertSame('Internal Server Error', $this->detail($event));
        self::assertStringNotContainsString('/var/www', (string) $response->getContent());
    }

    #[Test]
    public function aPlainPhpClientErrorSaysOnlyItsStatusAndTheLogKeepsItsMessage(): void
    {
        $logger = new FixtureLogger();
        $listener = new UnhandledExceptionListener(
            $logger,
            $this->translatorThatMustNotBeCalled(),
            new PlatformDefaults('en', 'UTC', 'yyyy-MM-dd', '24h', 'monday'),
        );
        $domain = $this->event(new DomainException('row 42 of coolms_ledger breaks rule R7'));
        $argument = $this->event(new InvalidArgumentException('expected a uuid, got "/var/www/x"'));

        $listener($domain);
        $listener($argument);

        self::assertSame(422, $domain->getResponse()?->getStatusCode());
        self::assertSame('Unprocessable Content', $this->detail($domain));
        $response = $argument->getResponse();
        self::assertNotNull($response);
        self::assertSame(400, $response->getStatusCode());
        self::assertSame('Bad Request', $this->detail($argument));
        self::assertStringNotContainsString('/var/www', (string) $response->getContent());
        self::assertSame(
            ['row 42 of coolms_ledger breaks rule R7', 'expected a uuid, got "/var/www/x"'],
            $logger->messages,
            'each hidden message is logged',
        );
    }

    #[Test]
    public function anInvalidInputSaysWhy(): void
    {
        $listener = $this->listener($this->translatorThatMustNotBeCalled());
        $event = $this->event(new FixtureInvalidInputException('the path may not contain ".."'));

        $listener($event);

        self::assertSame(400, $event->getResponse()?->getStatusCode());
        self::assertSame('the path may not contain ".."', $this->detail($event));
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

/**
 * Test-local invalid input: always the caller's fault, so its message is meant for the client.
 */
final class FixtureInvalidInputException extends RuntimeException implements InvalidInputExceptionInterface
{
}

/**
 * Test-local logger: keeps the raw message each log line carries in its context.
 */
final class FixtureLogger extends AbstractLogger
{
    /** @var list<string> */
    public array $messages = [];

    /** @param array<string, mixed> $context */
    public function log(mixed $level, string|Stringable $message, array $context = []): void
    {
        $this->messages[] = (string) ($context['message'] ?? $message);
    }
}
