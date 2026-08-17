<?php

declare(strict_types=1);

namespace CoolMS\CoreBundle\Secret;

use CoolMS\Core\Secret\SecretNotFoundException;
use CoolMS\Core\Secret\SecretStoreException;
use CoolMS\Core\Secret\SecretStoreInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

use function getenv;
use function is_array;
use function is_string;
use function ltrim;
use function rtrim;
use function sprintf;

/**
 * F1.c -- the full-prod secret backend: reads secrets from HashiCorp
 * Vault's KV v2 secrets engine over the HTTP API.
 *
 * One configurable Vault path (`coolms_core.secret_store.vault.path`,
 * e.g. `secret/data/coolms`) holds the whole platform secret map; a
 * single authenticated `GET {addr}/v1/{path}` returns it and the result is
 * cached for the instance lifetime (one round-trip per request). Logical
 * keys are LITERAL Vault data keys (no env-style normalisation), so
 * `secret('stripe_api_key')` reads `data.data.stripe_api_key` -- consistent
 * with {@see FilesystemEncryptedStore}.
 *
 * The Vault token is read from an env var (`vault.token_env`, default
 * `VAULT_TOKEN`) -- the one bootstrap credential, kept out of config the
 * same way the filesystem store's master key is. A 404 (path not yet
 * created) reads as an empty map; any other non-2xx, a transport failure,
 * or a missing token is a hard {@see SecretStoreException}.
 *
 * Selected via `coolms_core.secret_store.driver: vault`. Vault itself owns
 * encryption-at-rest, rotation and audit; this store only fetches.
 */
final class VaultSecretStore implements SecretStoreInterface
{
    /** @var array<string, string>|null */
    private ?array $cache = null;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        #[Autowire('%coolms.secret_store.vault_addr%')]
        private readonly string $addr,
        #[Autowire('%coolms.secret_store.vault_token_env%')]
        private readonly string $tokenEnvVar,
        #[Autowire('%coolms.secret_store.vault_path%')]
        private readonly string $path,
    ) {
    }

    public function get(string $key): ?string
    {
        $this->cache ??= $this->fetch();

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

    /**
     * @return array<string, string>
     */
    private function fetch(): array
    {
        $url = rtrim($this->addr, '/') . '/v1/' . ltrim($this->path, '/');

        try {
            $response = $this->httpClient->request('GET', $url, [
                'headers' => ['X-Vault-Token' => $this->token()],
            ]);
            $status = $response->getStatusCode();

            // Path not created yet -> behave like an absent file.
            if (404 === $status) {
                return [];
            }
            if ($status < 200 || $status >= 300) {
                throw new SecretStoreException(sprintf('Vault returned HTTP %d for "%s".', $status, $this->path));
            }

            /** @var array<mixed> $json */
            $json = $response->toArray();
        } catch (HttpExceptionInterface $e) {
            throw new SecretStoreException(sprintf('Vault request to "%s" failed: %s', $url, $e->getMessage()), 0, $e);
        }

        // KV v2 shape: { "data": { "data": { key: value, ... }, "metadata": {…} } }.
        $data = $json['data']['data'] ?? null;
        if (!is_array($data)) {
            throw new SecretStoreException('Vault response did not contain a KV v2 data map.');
        }

        $out = [];
        foreach ($data as $k => $v) {
            if (is_string($k) && is_string($v)) {
                $out[$k] = $v;
            }
        }

        return $out;
    }

    private function token(): string
    {
        $token = $_ENV[$this->tokenEnvVar] ?? getenv($this->tokenEnvVar);
        if (!is_string($token) || '' === $token) {
            throw new SecretStoreException(sprintf('Vault token env var "%s" is not set.', $this->tokenEnvVar));
        }

        return $token;
    }
}
