<?php

declare(strict_types=1);

namespace CoolMS\CoreBundle\Secret;

use CoolMS\Core\Secret\SecretNotFoundException;
use CoolMS\Core\Secret\SecretStoreInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

use function array_key_exists;
use function getenv;
use function is_string;
use function preg_replace;
use function strtoupper;
use function trim;

/**
 * F1.a -- the dev-default secret backend: reads secrets from environment
 * variables (12-factor style).
 *
 * A logical key is mapped to an env-var name by upper-casing it and
 * prefixing it with the configured prefix (default `COOLMS_SECRET_`):
 *
 *     get('stripe_api_key')   -> $_ENV['COOLMS_SECRET_STRIPE_API_KEY']
 *     get('telegram.bot')     -> $_ENV['COOLMS_SECRET_TELEGRAM_BOT']
 *
 * Non-alphanumeric runs in the key collapse to a single underscore, so
 * dot/dash/space variants all normalise to the same env name. `$_ENV`
 * is consulted first (Symfony's Dotenv populates it), with `getenv()` as
 * a fallback for real process-environment values. An unset OR empty
 * value is reported as absent.
 *
 * Production deployments select the libsodium filesystem store or Vault
 * via `coolms_core.secret_store.driver` (later F1 slices); this store is
 * the floor that keeps dev + single-tenant bare-metal installs running
 * without any secret infrastructure.
 */
final readonly class EnvSecretStore implements SecretStoreInterface
{
    public function __construct(
        #[Autowire('%coolms.secret_store.env_prefix%')]
        private string $prefix = 'COOLMS_SECRET_',
    ) {
    }

    public function get(string $key): ?string
    {
        $name = $this->envName($key);

        // $_ENV first (Dotenv-populated), then the real process env.
        // Treat empty string as absent -- a blank secret is a
        // misconfiguration, never a usable credential.
        if (array_key_exists($name, $_ENV) && is_string($_ENV[$name]) && '' !== $_ENV[$name]) {
            return $_ENV[$name];
        }

        $raw = getenv($name);

        return is_string($raw) && '' !== $raw ? $raw : null;
    }

    public function has(string $key): bool
    {
        return null !== $this->get($key);
    }

    public function getRequired(string $key): string
    {
        return $this->get($key) ?? throw new SecretNotFoundException($key);
    }

    private function envName(string $key): string
    {
        $normalised = strtoupper((string) preg_replace('/[^A-Za-z0-9]+/', '_', $key));

        return $this->prefix . trim($normalised, '_');
    }
}
