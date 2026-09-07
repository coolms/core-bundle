<?php

declare(strict_types=1);

namespace CoolMS\CoreBundle\Tests\Secret;

use CoolMS\CoreBundle\Secret\MasterKeyProvisioner;
use CoolMS\CoreBundle\Secret\MasterKeyStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function fileperms;
use function is_dir;
use function is_file;
use function mkdir;
use function posix_geteuid;
use function putenv;
use function rmdir;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

/**
 * The provisioner had no test of its own, and it decides whether the platform
 * can seal anything at all. These pin the four outcomes plus the one added when
 * a clean-clone install wrote a key the web server could not read.
 */
#[CoversClass(MasterKeyProvisioner::class)]
final class MasterKeyProvisionerTest extends TestCase
{
    private const ENV_VAR = 'COOLMS_TEST_MASTER_KEY';

    private string $projectDir;

    public function testGeneratesAndWritesTheKeyReadOnlyToItsOwner(): void
    {
        $status = $this->provisioner('dev')->ensure();

        self::assertSame(MasterKeyStatus::Generated, $status);
        self::assertFileExists($this->projectDir . '/.env.local');
        self::assertSame(0o600, fileperms($this->projectDir . '/.env.local') & 0o777);
    }

    /**
     * !! THE REGRESSION GUARD. Refusing when the reader is unknown must not
     * refuse when there is nothing to determine: a non-root process writes a
     * file it already owns, so no configured owner is needed and an install
     * that used to work must keep working.
     */
    public function testDoesNotRefuseWhenNotRootEvenWithNoOwnerConfigured(): void
    {
        // !! GUARDED THE WAY THE PRODUCTION CODE IS. CI has no ext-posix, and an
        // unguarded call here failed the whole suite while the code under test
        // handled its absence correctly. Without the extension the provisioner
        // cannot detect root and takes the non-root path, which is what this
        // asserts, so the test still runs there rather than skipping.
        if (function_exists('posix_geteuid') && 0 === posix_geteuid()) {
            self::markTestSkipped('running as root -- this asserts the non-root path');
        }

        self::assertSame(MasterKeyStatus::Generated, $this->provisioner('dev', null)->ensure());
    }

    public function testRefusesToGenerateInProd(): void
    {
        self::assertSame(MasterKeyStatus::MissingInProd, $this->provisioner('prod')->ensure());
        self::assertFileDoesNotExist($this->projectDir . '/.env.prod.local');
    }

    public function testKeepsAnAlreadyValidKey(): void
    {
        $this->provisioner('dev')->ensure();
        $this->clearKey();

        // Second run reads the key back out of the file it just wrote.
        self::assertSame(MasterKeyStatus::AlreadyValid, $this->provisioner('dev')->ensure());
    }

    public function testRefusesToOverwriteAnInvalidKey(): void
    {
        putenv(self::ENV_VAR . '=not-a-key');
        $_ENV[self::ENV_VAR] = 'not-a-key';

        self::assertSame(MasterKeyStatus::Invalid, $this->provisioner('dev')->ensure());
    }

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir() . '/coolms-mkp-' . uniqid();
        if (!is_dir($this->projectDir)) {
            mkdir($this->projectDir, 0o777, true);
        }
        $this->clearKey();
    }

    protected function tearDown(): void
    {
        $this->clearKey();
        foreach (['/.env.local', '/.env.prod.local', '/.env.test.local'] as $f) {
            if (is_file($this->projectDir . $f)) {
                unlink($this->projectDir . $f);
            }
        }
        if (is_dir($this->projectDir)) {
            rmdir($this->projectDir);
        }
    }

    private function provisioner(string $env, ?string $owner = 'root'): MasterKeyProvisioner
    {
        return new MasterKeyProvisioner(self::ENV_VAR, $this->projectDir, $env, $owner);
    }

    private function clearKey(): void
    {
        putenv(self::ENV_VAR);
        unset($_ENV[self::ENV_VAR]);
    }
}
