<?php

declare(strict_types=1);

namespace CoolMS\Core\Bundle\Tests\Secret\Support;

use CoolMS\Core\Secret\MasterKey;
use CoolMS\Core\Secret\MasterKeyException;
use CoolMS\Core\Secret\MasterKeyRingInterface;

use function sodium_crypto_secretbox_keygen;

/** A ring holding exactly the keys a test hands it, current first. */
final readonly class StaticRing implements MasterKeyRingInterface
{
    /** @var list<MasterKey> */
    private array $keys;

    public function __construct(MasterKey ...$keys)
    {
        $this->keys = $keys;
    }

    public static function fresh(): MasterKey
    {
        return new MasterKey(sodium_crypto_secretbox_keygen());
    }

    public function isConfigured(): bool
    {
        return [] !== $this->keys;
    }

    public function current(): MasterKey
    {
        return $this->keys[0] ?? throw MasterKeyException::notConfigured('test ring');
    }

    public function keys(): array
    {
        return $this->keys;
    }

    public function byId(string $id): ?MasterKey
    {
        foreach ($this->keys as $key) {
            if ($key->id === $id) {
                return $key;
            }
        }

        return null;
    }
}
