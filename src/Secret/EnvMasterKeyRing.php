<?php

declare(strict_types=1);

namespace CoolMS\Core\Bundle\Secret;

use CoolMS\Core\Secret\MasterKey;
use CoolMS\Core\Secret\MasterKeyException;
use CoolMS\Core\Secret\MasterKeyRingInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

use function getenv;
use function is_string;

/**
 * The ring every reader shares, read from the environment: the current key
 * from one variable, the previous key -- set only during a rotation window --
 * from another. Both are base64 of 32 bytes; a value that is set and broken
 * is refused by name rather than ignored, because a mistyped previous key
 * would otherwise turn every old value into "unreadable" without a word.
 *
 * Read lazily on each call, the way the readers it replaces read the
 * variable: the provisioner publishes a freshly generated key into the same
 * process, and a ring that cached an absence at construction would not see it.
 */
final readonly class EnvMasterKeyRing implements MasterKeyRingInterface
{
    public function __construct(
        #[Autowire('%coolms.secret_store.key_env%')]
        private string $currentEnvVar = 'COOLMS_SECRET_MASTER_KEY',
        #[Autowire('%coolms.secret_store.previous_key_env%')]
        private string $previousEnvVar = 'COOLMS_SECRET_MASTER_KEY_PREVIOUS',
    ) {
    }

    public function isConfigured(): bool
    {
        try {
            $this->current();

            return true;
        } catch (MasterKeyException) {
            return false;
        }
    }

    public function current(): MasterKey
    {
        $key = $this->read($this->currentEnvVar);
        if (null === $key) {
            throw MasterKeyException::notConfigured($this->currentEnvVar);
        }

        return $key;
    }

    public function keys(): array
    {
        $keys = [];
        $current = $this->read($this->currentEnvVar);
        if (null !== $current) {
            $keys[] = $current;
        }
        $previous = $this->read($this->previousEnvVar);
        if (null !== $previous && (null === $current || !$previous->is($current))) {
            $keys[] = $previous;
        }

        return $keys;
    }

    public function byId(string $id): ?MasterKey
    {
        foreach ($this->keys() as $key) {
            if ($key->id === $id) {
                return $key;
            }
        }

        return null;
    }

    public function currentEnvVar(): string
    {
        return $this->currentEnvVar;
    }

    public function previousEnvVar(): string
    {
        return $this->previousEnvVar;
    }

    /** Null when the variable is unset or empty; a set-but-broken value throws. */
    private function read(string $envVar): ?MasterKey
    {
        $b64 = $_ENV[$envVar] ?? getenv($envVar);
        if (!is_string($b64) || '' === $b64) {
            return null;
        }
        try {
            return MasterKey::fromBase64($b64);
        } catch (MasterKeyException $e) {
            throw MasterKeyException::invalid($envVar, $e);
        }
    }
}
