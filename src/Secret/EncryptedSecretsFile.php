<?php

declare(strict_types=1);

namespace CoolMS\Core\Bundle\Secret;

use CoolMS\Core\Secret\SealedValueException;
use CoolMS\Core\Secret\SecretStoreException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

use function chmod;
use function dirname;
use function file_get_contents;
use function file_put_contents;
use function is_array;
use function is_dir;
use function is_file;
use function is_string;
use function json_decode;
use function json_encode;
use function mkdir;
use function rename;
use function sodium_memzero;
use function sprintf;
use function trim;
use function unlink;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;
use const LOCK_EX;

/**
 * The at-rest codec behind the libsodium filesystem secret store.
 *
 * Encrypts the whole `{key: value}` secret map as a single authenticated
 * blob through the shared {@see KeyRingSealer}, and stores it in one file.
 * Authenticated encryption means a tampered or truncated file fails to
 * decrypt rather than yielding garbage.
 *
 * The master key is the ONE bootstrap secret that is NOT in the file -- it
 * comes from the key ring (an environment variable, base64-encoded), so the
 * encrypted file can live on disk / in a backup while the key stays in the
 * orchestrator's secret mount. Generate one with `coolms:secret:generate-key`.
 * During a rotation the ring holds the previous key too, so a file written
 * before the rotation still loads; `coolms:secret:rotate` re-seals it.
 *
 * Shared by {@see FilesystemEncryptedStore} (read), {@see SecretsFileKind}
 * (the rotation sweep) and the `coolms:secret:*` console commands (write);
 * writes are atomic (tmp-file + rename) and the file is chmod 0600.
 */
final readonly class EncryptedSecretsFile
{
    private KeyRingSealer $sealer;

    public function __construct(
        #[Autowire('%coolms.secret_store.fs_path%')]
        private string $path,
        ?KeyRingSealer $sealer = null,
    ) {
        $this->sealer = $sealer ?? new KeyRingSealer(new EnvMasterKeyRing());
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
        $raw = $this->raw();
        if (null === $raw) {
            return [];
        }
        try {
            $opened = $this->sealer->open($raw);
        } catch (SealedValueException $e) {
            $message = sprintf('Cannot decrypt secrets file "%s": %s', $this->path, $e->getMessage());

            throw new SecretStoreException($message, 0, $e);
        }

        return $this->decode($opened->plaintext);
    }

    /**
     * Encrypt and atomically write the secret map (0600), always under the
     * current key.
     *
     * @param array<string, string> $map
     *
     * @throws SecretStoreException on a missing/invalid key or a write failure
     */
    public function save(array $map): void
    {
        $json = json_encode($map, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $this->write($this->sealer->seal($json));
    }

    public function path(): string
    {
        return $this->path;
    }

    /**
     * The stored blob as it is on disk, for a sweep that needs to ask which
     * key it opens under; null when there is no file or it is empty.
     */
    public function raw(): ?string
    {
        if (!is_file($this->path)) {
            return null;
        }
        $raw = file_get_contents($this->path);
        if (false === $raw || '' === trim($raw)) {
            return null;
        }

        return trim($raw);
    }

    /** Replace the stored blob with one the sweep re-sealed; same atomic write as {@see save()}. */
    public function replaceRaw(string $sealed): void
    {
        $this->write($sealed);
    }

    /** @return array<string, string> */
    private function decode(string $plain): array
    {
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

    private function write(string $encoded): void
    {
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
}
