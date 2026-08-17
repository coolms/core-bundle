<?php

declare(strict_types=1);

namespace CoolMS\CoreBundle\Tests\Secret;

use CoolMS\CoreBundle\Console\SecretGenerateKeyCommand;
use CoolMS\CoreBundle\Console\SecretListCommand;
use CoolMS\CoreBundle\Console\SecretRemoveCommand;
use CoolMS\CoreBundle\Console\SecretSetCommand;
use CoolMS\CoreBundle\Secret\EncryptedSecretsFile;
use CoolMS\CoreBundle\Secret\FilesystemEncryptedStore;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * F1.b -- the coolms:secret:* management CLI, exercised end-to-end against
 * a temp encrypted file: generate-key, then set → list → (read back via the
 * store) → remove.
 */
final class SecretCommandsTest extends TestCase
{
    private const string KEY_ENV = 'COOLMS_SECRET_MASTER_KEY';

    private string $path;
    private EncryptedSecretsFile $file;

    #[Test]
    public function generateKeyEmitsAValid32ByteBase64Key(): void
    {
        $tester = new CommandTester(new SecretGenerateKeyCommand());
        $exit = $tester->execute([], ['capture_stderr_separately' => true]);

        self::assertSame(Command::SUCCESS, $exit);
        $key = trim($tester->getDisplay()); // stdout only = the raw key
        self::assertSame(
            SODIUM_CRYPTO_SECRETBOX_KEYBYTES,
            strlen(sodium_base642bin($key, SODIUM_BASE64_VARIANT_ORIGINAL)),
        );
    }

    #[Test]
    public function setListReadRemoveRoundTrip(): void
    {
        // set (create)
        $set = new CommandTester(new SecretSetCommand($this->file));
        self::assertSame(Command::SUCCESS, $set->execute(['key' => 'stripe_api_key', 'value' => 'sk_live_1']));
        self::assertStringContainsString('Set', $set->getDisplay());

        // set again (update)
        $set = new CommandTester(new SecretSetCommand($this->file));
        self::assertSame(Command::SUCCESS, $set->execute(['key' => 'stripe_api_key', 'value' => 'sk_live_2']));
        self::assertStringContainsString('Updated', $set->getDisplay());

        // list shows the name (never the value)
        $list = new CommandTester(new SecretListCommand($this->file));
        $list->execute([]);
        self::assertStringContainsString('stripe_api_key', $list->getDisplay());
        self::assertStringNotContainsString('sk_live_2', $list->getDisplay());

        // the store reads the latest value through the same encrypted file
        $store = new FilesystemEncryptedStore(new EncryptedSecretsFile($this->path));
        self::assertSame('sk_live_2', $store->get('stripe_api_key'));

        // remove
        $remove = new CommandTester(new SecretRemoveCommand($this->file));
        self::assertSame(Command::SUCCESS, $remove->execute(['key' => 'stripe_api_key']));

        $list = new CommandTester(new SecretListCommand($this->file));
        $list->execute([]);
        self::assertStringContainsString('No secrets', $list->getDisplay());
    }

    #[Test]
    public function setRejectsAnEmptyValue(): void
    {
        $set = new CommandTester(new SecretSetCommand($this->file));
        $exit = $set->execute(['key' => 'k', 'value' => '']);

        self::assertSame(Command::FAILURE, $exit);
        self::assertStringContainsString('Empty values are not stored', $set->getDisplay());
    }

    #[Test]
    public function removeIsIdempotent(): void
    {
        $remove = new CommandTester(new SecretRemoveCommand($this->file));
        $exit = $remove->execute(['key' => 'never-existed']);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertStringContainsString('nothing to remove', $remove->getDisplay());
    }

    protected function setUp(): void
    {
        $_ENV[self::KEY_ENV] = sodium_bin2base64(sodium_crypto_secretbox_keygen(), SODIUM_BASE64_VARIANT_ORIGINAL);
        $this->path = sys_get_temp_dir() . '/coolms-secrets-' . bin2hex(random_bytes(6)) . '.enc';
        $this->file = new EncryptedSecretsFile($this->path);
    }

    protected function tearDown(): void
    {
        unset($_ENV[self::KEY_ENV]);
        @unlink($this->path);
        @unlink($this->path . '.tmp');
    }
}
