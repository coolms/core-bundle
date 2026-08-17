<?php

declare(strict_types=1);

namespace CoolMS\CoreBundle\Tests\Secret;

use CoolMS\Core\Secret\SecretStoreException;
use CoolMS\CoreBundle\Secret\EncryptedSecretsFile;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * F1.b -- the libsodium at-rest codec: round-trip, and the failure modes
 * that authenticated encryption is supposed to catch (wrong key, tampered
 * file, missing/invalid master key).
 */
final class EncryptedSecretsFileTest extends TestCase
{
    private const string KEY_ENV = 'COOLMS_SECRET_MASTER_KEY';

    private string $path;

    #[Test]
    public function roundTripsTheSecretMap(): void
    {
        $file = new EncryptedSecretsFile($this->path);
        $file->save(['stripe_api_key' => 'sk_live_1', 'telegram.bot' => 't0k']);

        // A fresh instance proves it read from disk, not in-memory state.
        $loaded = new EncryptedSecretsFile($this->path)->load();

        self::assertSame(['stripe_api_key' => 'sk_live_1', 'telegram.bot' => 't0k'], $loaded);
    }

    #[Test]
    public function aMissingFileLoadsEmpty(): void
    {
        self::assertSame([], new EncryptedSecretsFile($this->path)->load());
    }

    #[Test]
    public function theFileIsNotPlaintext(): void
    {
        new EncryptedSecretsFile($this->path)->save(['k' => 'super-secret-value']);

        $raw = (string) file_get_contents($this->path);
        self::assertStringNotContainsString('super-secret-value', $raw);
    }

    #[Test]
    public function aWrongMasterKeyFailsToDecrypt(): void
    {
        new EncryptedSecretsFile($this->path)->save(['k' => 'v']);

        // Swap to a different (valid) key, then try to read.
        $_ENV[self::KEY_ENV] = $this->freshKey();

        $this->expectException(SecretStoreException::class);
        new EncryptedSecretsFile($this->path)->load();
    }

    #[Test]
    public function aTamperedFileFailsToDecrypt(): void
    {
        $file = new EncryptedSecretsFile($this->path);
        $file->save(['k' => 'v']);

        $raw = (string) file_get_contents($this->path);
        // Flip a character in the middle of the base64 body.
        $mid = intdiv(strlen($raw), 2);
        $raw[$mid] = 'A' === $raw[$mid] ? 'B' : 'A';
        file_put_contents($this->path, $raw);

        $this->expectException(SecretStoreException::class);
        $file->load();
    }

    #[Test]
    public function aMissingMasterKeyThrows(): void
    {
        new EncryptedSecretsFile($this->path)->save(['k' => 'v']);
        unset($_ENV[self::KEY_ENV]);

        $this->expectException(SecretStoreException::class);
        new EncryptedSecretsFile($this->path)->load();
    }

    #[Test]
    public function aWrongLengthMasterKeyThrows(): void
    {
        $_ENV[self::KEY_ENV] = sodium_bin2base64(random_bytes(10), SODIUM_BASE64_VARIANT_ORIGINAL);

        $this->expectException(SecretStoreException::class);
        // save() also needs the key, so it trips on the bad length too.
        new EncryptedSecretsFile($this->path)->save(['k' => 'v']);
    }

    protected function setUp(): void
    {
        $_ENV[self::KEY_ENV] = $this->freshKey();
        $this->path = sys_get_temp_dir() . '/coolms-secrets-' . bin2hex(random_bytes(6)) . '.enc';
    }

    protected function tearDown(): void
    {
        unset($_ENV[self::KEY_ENV]);
        @unlink($this->path);
        @unlink($this->path . '.tmp');
    }

    private function freshKey(): string
    {
        return sodium_bin2base64(sodium_crypto_secretbox_keygen(), SODIUM_BASE64_VARIANT_ORIGINAL);
    }
}
