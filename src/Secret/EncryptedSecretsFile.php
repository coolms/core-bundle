<?php

declare(strict_types=1);

namespace CoolMS\CoreBundle\Secret;

use CoolMS\Core\Secret\SecretStoreException;
use SodiumException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

use function chmod;
use function dirname;
use function file_get_contents;
use function file_put_contents;
use function getenv;
use function is_array;
use function is_dir;
use function is_file;
use function is_string;
use function json_decode;
use function json_encode;
use function mkdir;
use function random_bytes;
use function rename;
use function sodium_base642bin;
use function sodium_bin2base64;
use function sodium_crypto_secretbox;
use function sodium_crypto_secretbox_open;
use function sodium_memzero;
use function sprintf;
use function strlen;
use function substr;
use function trim;
use function unlink;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;
use const LOCK_EX;
use const SODIUM_BASE64_VARIANT_ORIGINAL;
use const SODIUM_CRYPTO_SECRETBOX_KEYBYTES;
use const SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;

/**
 * F1.b -- the at-rest codec behind the libsodium filesystem secret store.
 *
 * Encrypts the whole `{key: value}` secret map as a single authenticated
 * blob (XSalsa20-Poly1305 via `sodium_crypto_secretbox`) and stores it
 * base64-encoded as `base64(nonce || ciphertext)` in one file. Authenticated
 * encryption means a tampered or truncated file fails to decrypt rather than
 * yielding garbage.
 *
 * The 32-byte master key is the ONE bootstrap secret that is NOT in the
 * file -- it is read from an environment variable (base64-encoded), so the
 * encrypted file can live on disk / in a backup while the key stays in the
 * orchestrator's secret mount. Generate one with `coolms:secret:generate-key`.
 *
 * Shared by {@see FilesystemEncryptedStore} (read) and the
 * `coolms:secret:*` console commands (write); writes are atomic
 * (tmp-file + rename) and the file is chmod 0600.
 */
final readonly class EncryptedSecretsFile
{
    public function __construct(
        #[Autowire('%coolms.secret_store.fs_path%')]
        private string $path,
        #[Autowire('%coolms.secret_store.fs_key_env%')]
        private string $keyEnvVar = 'COOLMS_SECRET_MASTER_KEY',
    ) {
    }

    /**
     * Decrypt and return the secret map; an absent/empty file yields [].
     *
     * @throws SecretStoreException on a missing/invalid key or a
     *                              corrupt/tampered/undecryptable file
     *
     * @return array<string, string>
     */
    public function load(): array
    {
        if (!is_file($this->path)) {
            return [];
        }

        $raw = file_get_contents($this->path);
        if (false === $raw || '' === trim($raw)) {
            return [];
        }

        try {
            $bin = sodium_base642bin(trim($raw), SODIUM_BASE64_VARIANT_ORIGINAL);
        } catch (SodiumException) {
            throw new SecretStoreException(sprintf('Secrets file "%s" is not valid base64.', $this->path));
        }

        if (strlen($bin) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new SecretStoreException(sprintf('Secrets file "%s" is truncated.', $this->path));
        }

        $nonce = substr($bin, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = substr($bin, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        $plain = sodium_crypto_secretbox_open($cipher, $nonce, $this->masterKey());
        if (false === $plain) {
            throw new SecretStoreException(sprintf('Cannot decrypt secrets file "%s" -- wrong master key or tampered file.', $this->path));
        }

        $map = json_decode($plain, true);
        sodium_memzero($plain);

        if (!is_array($map)) {
            throw new SecretStoreException('Decrypted secrets payload is not a key/value map.');
        }

        $out = [];
        foreach ($map as $k => $v) {
            if (is_string($k) && is_string($v)) {
                $out[$k] = $v;
            }
        }

        return $out;
    }

    /**
     * Encrypt and atomically write the secret map (0600).
     *
     * @param array<string, string> $map
     *
     * @throws SecretStoreException on a missing/invalid key or a write failure
     */
    public function save(array $map): void
    {
        $json = json_encode($map, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = sodium_crypto_secretbox($json, $nonce, $this->masterKey());
        $encoded = sodium_bin2base64($nonce . $cipher, SODIUM_BASE64_VARIANT_ORIGINAL);

        $dir = dirname($this->path);
        if (!is_dir($dir) && !mkdir($dir, 0o700, true) && !is_dir($dir)) {
            throw new SecretStoreException(sprintf('Cannot create secrets directory "%s".', $dir));
        }

        $tmp = $this->path . '.tmp';
        if (false === file_put_contents($tmp, $encoded, LOCK_EX)) {
            throw new SecretStoreException(sprintf('Cannot write secrets file "%s".', $tmp));
        }
        @chmod($tmp, 0o600);

        if (!rename($tmp, $this->path)) {
            @unlink($tmp);

            throw new SecretStoreException(sprintf('Cannot finalize secrets file "%s".', $this->path));
        }
        @chmod($this->path, 0o600);
    }

    public function path(): string
    {
        return $this->path;
    }

    private function masterKey(): string
    {
        $b64 = $_ENV[$this->keyEnvVar] ?? getenv($this->keyEnvVar);
        if (!is_string($b64) || '' === $b64) {
            throw new SecretStoreException(sprintf('Master key env var "%s" is not set.', $this->keyEnvVar));
        }

        try {
            $key = sodium_base642bin($b64, SODIUM_BASE64_VARIANT_ORIGINAL);
        } catch (SodiumException) {
            throw new SecretStoreException(sprintf('Master key in "%s" is not valid base64.', $this->keyEnvVar));
        }

        if (SODIUM_CRYPTO_SECRETBOX_KEYBYTES !== strlen($key)) {
            throw new SecretStoreException(sprintf('Master key in "%s" must decode to %d bytes.', $this->keyEnvVar, SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
        }

        return $key;
    }
}
