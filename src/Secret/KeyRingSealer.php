<?php

declare(strict_types=1);

namespace CoolMS\Core\Bundle\Secret;

use CoolMS\Core\Secret\MasterKey;
use CoolMS\Core\Secret\MasterKeyException;
use CoolMS\Core\Secret\MasterKeyRingInterface;
use CoolMS\Core\Secret\SealedValueException;
use SodiumException;

use function count;
use function explode;
use function random_bytes;
use function sodium_base642bin;
use function sodium_bin2base64;
use function sodium_crypto_secretbox;
use function sodium_crypto_secretbox_open;
use function str_starts_with;
use function strlen;
use function substr;

use const SODIUM_BASE64_VARIANT_ORIGINAL;
use const SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;

/**
 * The one at-rest codec behind every sealed value, over the key ring.
 *
 * Seals with `sodium_crypto_secretbox` (XSalsa20-Poly1305, a fresh nonce per
 * value) under the ring's CURRENT key, and writes the key's id into the stored
 * form: `enc:v2:<key id>:base64(nonce || box)`. Opens by trying the key the
 * form names first and then every other key the ring holds, so a value sealed
 * under the previous key reads for as long as that key is held, and the caller
 * learns which key it was.
 *
 * Two older forms are read and never written: `enc:v1:` + base64 (a second
 * factor sealed before key ids existed) and bare base64 (a mailbox password,
 * an OAuth grant, a SIP secret, the secrets file). They carry no key id, so
 * every key is tried in ring order; a sweep re-seals them into the current form
 * when it finds them under the previous key, and leaves them when it finds
 * them under the current one -- the form is not what a rotation is about.
 *
 * The box authenticates: a value that opens under no key fails by name, and
 * the failure does not say whether the key is wrong or the bytes are, which is
 * as it should be.
 */
final readonly class KeyRingSealer
{
    public const string PREFIX = 'enc:v2:';

    private const string LEGACY_PREFIX = 'enc:v1:';

    public function __construct(private MasterKeyRingInterface $ring)
    {
    }

    /** True for a value written by {@see seal()}: the form that names its key. */
    public static function isCurrentForm(string $stored): bool
    {
        return str_starts_with($stored, self::PREFIX);
    }

    /** True for either marked form; a bare base64 value is indistinguishable from any other string. */
    public static function isMarked(string $stored): bool
    {
        return str_starts_with($stored, self::PREFIX) || str_starts_with($stored, self::LEGACY_PREFIX);
    }

    public function ring(): MasterKeyRingInterface
    {
        return $this->ring;
    }

    /** True when a value CAN be sealed: the ring has a current key. */
    public function isConfigured(): bool
    {
        return $this->ring->isConfigured();
    }

    /** @throws MasterKeyException when no current key is configured */
    public function seal(string $plaintext): string
    {
        $key = $this->ring->current();
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $box = sodium_crypto_secretbox($plaintext, $nonce, $key->bytes());

        return self::PREFIX . $key->id . ':' . sodium_bin2base64($nonce . $box, SODIUM_BASE64_VARIANT_ORIGINAL);
    }

    /**
     * @throws SealedValueException when the value is malformed or opens under no held key
     * @throws MasterKeyException   when a configured key is itself broken
     */
    public function open(string $stored): OpenedValue
    {
        [$namedId, $payload] = $this->split($stored);
        try {
            $bin = sodium_base642bin($payload, SODIUM_BASE64_VARIANT_ORIGINAL);
        } catch (SodiumException) {
            throw SealedValueException::malformed();
        }
        if (strlen($bin) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw SealedValueException::malformed();
        }
        $nonce = substr($bin, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $box = substr($bin, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        foreach ($this->candidates($namedId) as $key) {
            $plain = sodium_crypto_secretbox_open($box, $nonce, $key->bytes());
            if (false !== $plain) {
                return new OpenedValue($plain, $key, null !== $namedId && $namedId === $key->id);
            }
        }

        throw SealedValueException::undecryptable();
    }

    /** Which key a stored value opens under -- by opening it. */
    public function classify(string $stored): SealedValueState
    {
        try {
            $opened = $this->open($stored);
        } catch (SealedValueException) {
            return SealedValueState::Unreadable;
        }

        return $opened->key->is($this->ring->current()) ? SealedValueState::Current : SealedValueState::Previous;
    }

    /** @return array{?string, string} the key id the form names (if any) and the base64 payload */
    private function split(string $stored): array
    {
        if (str_starts_with($stored, self::PREFIX)) {
            $parts = explode(':', substr($stored, strlen(self::PREFIX)), 2);
            if (2 !== count($parts) || '' === $parts[0] || '' === $parts[1]) {
                throw SealedValueException::malformed();
            }

            return [$parts[0], $parts[1]];
        }
        if (str_starts_with($stored, self::LEGACY_PREFIX)) {
            return [null, substr($stored, strlen(self::LEGACY_PREFIX))];
        }

        return [null, $stored];
    }

    /**
     * The named key first, then the rest in ring order. A named key the ring
     * does not hold is not a refusal by itself: the other keys are still tried,
     * because the id is a hint about the key, and the box is the proof.
     *
     * @return list<MasterKey>
     */
    private function candidates(?string $namedId): array
    {
        $keys = $this->ring->keys();
        if (null === $namedId) {
            return $keys;
        }
        $ordered = [];
        foreach ($keys as $key) {
            if ($key->id === $namedId) {
                $ordered[] = $key;
            }
        }
        foreach ($keys as $key) {
            if ($key->id !== $namedId) {
                $ordered[] = $key;
            }
        }

        return $ordered;
    }
}
