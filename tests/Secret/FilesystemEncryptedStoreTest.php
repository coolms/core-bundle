<?php

declare(strict_types=1);

namespace CoolMS\CoreBundle\Tests\Secret;

use CoolMS\Core\Secret\SecretNotFoundException;
use CoolMS\CoreBundle\Secret\EncryptedSecretsFile;
use CoolMS\CoreBundle\Secret\FilesystemEncryptedStore;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * F1.b -- the read store over the encrypted file. Keys are literal logical
 * names (no env-style normalisation); empty stored values are absent.
 */
final class FilesystemEncryptedStoreTest extends TestCase
{
    private const string KEY_ENV = 'COOLMS_SECRET_MASTER_KEY';

    private string $path;

    #[Test]
    public function resolvesAPresentSecretByLiteralKey(): void
    {
        $store = $this->store();

        self::assertSame('sk_live_xyz', $store->get('stripe_api_key'));
        self::assertTrue($store->has('stripe_api_key'));
        self::assertSame('sk_live_xyz', $store->getRequired('stripe_api_key'));
    }

    #[Test]
    public function reportsAnUnknownKeyAsAbsent(): void
    {
        self::assertNull($this->store()->get('nope'));
        self::assertFalse($this->store()->has('nope'));
    }

    #[Test]
    public function treatsAnEmptyStoredValueAsAbsent(): void
    {
        self::assertNull($this->store()->get('blank'));
        self::assertFalse($this->store()->has('blank'));
    }

    #[Test]
    public function getRequiredThrowsWithTheKeyWhenAbsent(): void
    {
        try {
            $this->store()->getRequired('missing');
            self::fail('Expected SecretNotFoundException.');
        } catch (SecretNotFoundException $e) {
            self::assertSame('missing', $e->key);
        }
    }

    protected function setUp(): void
    {
        $_ENV[self::KEY_ENV] = sodium_bin2base64(sodium_crypto_secretbox_keygen(), SODIUM_BASE64_VARIANT_ORIGINAL);
        $this->path = sys_get_temp_dir() . '/coolms-secrets-' . bin2hex(random_bytes(6)) . '.enc';

        // Seed the encrypted file with a present, an empty, and (implicitly) absent key.
        new EncryptedSecretsFile($this->path)->save([
            'stripe_api_key' => 'sk_live_xyz',
            'blank' => '',
        ]);
    }

    protected function tearDown(): void
    {
        unset($_ENV[self::KEY_ENV]);
        @unlink($this->path);
        @unlink($this->path . '.tmp');
    }

    private function store(): FilesystemEncryptedStore
    {
        return new FilesystemEncryptedStore(new EncryptedSecretsFile($this->path));
    }
}
