<?php

declare(strict_types=1);

namespace CoolMS\CoreBundle\Tests\Secret;

use CoolMS\Core\Secret\SecretNotFoundException;
use CoolMS\CoreBundle\Secret\EnvSecretStore;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * F1.a -- the env-var secret backend.
 *
 * Drives `$_ENV` directly (Dotenv's destination) and restores it in
 * tearDown so the cases never leak into sibling tests.
 */
final class EnvSecretStoreTest extends TestCase
{
    /** @var array<string, string> */
    private array $touched = [];

    #[Test]
    public function resolvesAPresentSecretFromEnv(): void
    {
        $this->setEnv('COOLMS_SECRET_STRIPE_API_KEY', 'sk_live_abc');

        self::assertSame('sk_live_abc', $this->store()->get('stripe_api_key'));
        self::assertTrue($this->store()->has('stripe_api_key'));
        self::assertSame('sk_live_abc', $this->store()->getRequired('stripe_api_key'));
    }

    #[Test]
    public function normalisesDotDashAndCaseVariantsToOneEnvName(): void
    {
        $this->setEnv('COOLMS_SECRET_CHAT_BOT_TOKEN', 't0ken');

        // dot, dash, mixed-case and already-snake all map to the same name.
        self::assertSame('t0ken', $this->store()->get('chat.bot.token'));
        self::assertSame('t0ken', $this->store()->get('chat-bot-token'));
        self::assertSame('t0ken', $this->store()->get('Chat Bot Token'));
        self::assertSame('t0ken', $this->store()->get('chat_bot_token'));
    }

    #[Test]
    public function reportsAnUnsetSecretAsAbsent(): void
    {
        self::assertNull($this->store()->get('does_not_exist'));
        self::assertFalse($this->store()->has('does_not_exist'));
    }

    #[Test]
    public function treatsAnEmptyValueAsAbsent(): void
    {
        $this->setEnv('COOLMS_SECRET_BLANK', '');

        self::assertNull($this->store()->get('blank'));
        self::assertFalse($this->store()->has('blank'));
    }

    #[Test]
    public function getRequiredThrowsWithTheKeyWhenAbsent(): void
    {
        try {
            $this->store()->getRequired('missing_key');
            self::fail('Expected SecretNotFoundException.');
        } catch (SecretNotFoundException $e) {
            self::assertSame('missing_key', $e->key);
        }
    }

    #[Test]
    public function honoursACustomPrefix(): void
    {
        $this->setEnv('APP_SECRET__MY_KEY', 'v');

        // Prefix 'APP_SECRET__' + normalised 'MY_KEY'.
        self::assertSame('v', new EnvSecretStore('APP_SECRET__')->get('my.key'));
        // Default-prefixed store does NOT see it.
        self::assertNull($this->store()->get('my.key'));
    }

    protected function tearDown(): void
    {
        foreach (array_keys($this->touched) as $name) {
            unset($_ENV[$name]);
        }
        $this->touched = [];
        parent::tearDown();
    }

    private function store(): EnvSecretStore
    {
        return new EnvSecretStore();
    }

    private function setEnv(string $name, string $value): void
    {
        $_ENV[$name] = $value;
        $this->touched[$name] = $value;
    }
}
