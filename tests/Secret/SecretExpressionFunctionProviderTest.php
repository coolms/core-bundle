<?php

declare(strict_types=1);

namespace CoolMS\CoreBundle\Tests\Secret;

use CoolMS\Core\Secret\SecretNotFoundException;
use CoolMS\Core\Secret\SecretStoreInterface;
use CoolMS\CoreBundle\Expression\SecretExpressionFunctionProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\ExpressionLanguage\ExpressionLanguage;

/**
 * F1.a -- the `secret()` EL function, exercised through a real
 * ExpressionLanguage with the provider registered (the same way
 * ExpressionService wires it), so the function name + arity + the
 * service-bound evaluator are all proven end-to-end.
 */
final class SecretExpressionFunctionProviderTest extends TestCase
{
    #[Test]
    public function secretFunctionResolvesAPresentSecret(): void
    {
        $el = new ExpressionLanguage();
        $el->registerProvider(new SecretExpressionFunctionProvider($this->store(['stripe_api_key' => 'sk_live_xyz'])));

        self::assertSame('sk_live_xyz', $el->evaluate("secret('stripe_api_key')"));
    }

    #[Test]
    public function secretFunctionComposesWithOtherExpressions(): void
    {
        $el = new ExpressionLanguage();
        $el->registerProvider(new SecretExpressionFunctionProvider($this->store(['api_token' => 'abc123'])));

        // The headline connector use: build an Authorization header value.
        self::assertSame('Bearer abc123', $el->evaluate("'Bearer ' ~ secret('api_token')"));
    }

    #[Test]
    public function secretFunctionFailsLoudOnAMissingSecret(): void
    {
        $el = new ExpressionLanguage();
        $el->registerProvider(new SecretExpressionFunctionProvider($this->store([])));

        $this->expectException(SecretNotFoundException::class);
        $el->evaluate("secret('absent')");
    }

    /**
     * @param array<string, string> $secrets
     */
    private function store(array $secrets): SecretStoreInterface
    {
        return new class($secrets) implements SecretStoreInterface {
            /** @param array<string, string> $secrets */
            public function __construct(private readonly array $secrets)
            {
            }

            public function get(string $key): ?string
            {
                return $this->secrets[$key] ?? null;
            }

            public function has(string $key): bool
            {
                return isset($this->secrets[$key]);
            }

            public function getRequired(string $key): string
            {
                return $this->secrets[$key] ?? throw new SecretNotFoundException($key);
            }
        };
    }
}
