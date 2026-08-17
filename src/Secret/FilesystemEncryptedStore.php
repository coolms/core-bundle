<?php

declare(strict_types=1);

namespace CoolMS\CoreBundle\Secret;

use CoolMS\Core\Secret\SecretNotFoundException;
use CoolMS\Core\Secret\SecretStoreInterface;

use function is_string;

/**
 * F1.b -- the small-prod secret backend: reads secrets from a libsodium
 * encrypted file ({@see EncryptedSecretsFile}) at rest.
 *
 * Keys are LITERAL logical names (`secret('stripe_api_key')` looks up map
 * key `stripe_api_key`) -- unlike {@see EnvSecretStore}, which maps the
 * same logical key onto an UPPER_SNAKE env-var name because env vars have
 * naming constraints a file does not. Both backends resolve the same
 * `secret('x')` call as long as the operator stored `x` (via
 * `coolms:secret:set x …`).
 *
 * The decrypted map is cached for the lifetime of the instance (one decrypt
 * per request); selected via `coolms_core.secret_store.driver: filesystem`.
 * An empty stored value is reported as absent (see SecretStoreInterface).
 */
final class FilesystemEncryptedStore implements SecretStoreInterface
{
    /** @var array<string, string>|null */
    private ?array $cache = null;

    public function __construct(
        private readonly EncryptedSecretsFile $file,
    ) {
    }

    public function get(string $key): ?string
    {
        $this->cache ??= $this->file->load();

        $value = $this->cache[$key] ?? null;

        return is_string($value) && '' !== $value ? $value : null;
    }

    public function has(string $key): bool
    {
        return null !== $this->get($key);
    }

    public function getRequired(string $key): string
    {
        return $this->get($key) ?? throw new SecretNotFoundException($key);
    }
}
