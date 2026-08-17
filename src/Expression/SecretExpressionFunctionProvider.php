<?php

declare(strict_types=1);

namespace CoolMS\CoreBundle\Expression;

use CoolMS\Core\Secret\SecretStoreInterface;
use Symfony\Component\ExpressionLanguage\ExpressionFunction;
use Symfony\Component\ExpressionLanguage\ExpressionFunctionProviderInterface;

/**
 * F1.a -- exposes the secret store to the Expression Language.
 *
 * Adds one function, usable from any EL expression the platform
 * evaluates (workflow guards, service-task inputs, connector configs):
 *
 *  - `secret(key)` -- resolves a required secret via
 *    {@see SecretStoreInterface::getRequired()}. FAILS LOUD
 *    ({@see \CoolMS\Core\Secret\SecretNotFoundException}) when the
 *    key is unset, so a half-formed credential is never sent. The
 *    headline consumer is the HTTP connector:
 *
 *        "headers": { "Authorization": "concat('Bearer ', secret('api_token')) }"
 *
 * Picked up by Expression's `coolms.expression.function_provider`
 * autoconfigure tag (the `ExpressionFunctionProviderInterface`
 * implementation marker) -- no extra DI wiring. Lives in Core (not the
 * leaf Expression module) because it pulls in `SecretStoreInterface`, a
 * Core dep the Expression module deliberately doesn't carry -- the same
 * placement rationale as Identity's and Calendar's providers.
 */
final readonly class SecretExpressionFunctionProvider implements ExpressionFunctionProviderInterface
{
    public function __construct(
        private SecretStoreInterface $secretStore,
    ) {
    }

    /**
     * @return list<ExpressionFunction>
     */
    public function getFunctions(): array
    {
        return [
            new ExpressionFunction(
                'secret',
                // Compiled form is intentionally not supported: secrets
                // must never be baked into a cached, on-disk compiled
                // expression. Everything goes through evaluate(); the
                // throw makes the compile-then-cache misuse loud.
                static fn (string $key): string => 'throw new \RuntimeException("secret() is not compilable; use ExpressionService::evaluate() instead.")',
                fn (array $variables, string $key): string => $this->secretStore->getRequired($key),
            ),
        ];
    }
}
