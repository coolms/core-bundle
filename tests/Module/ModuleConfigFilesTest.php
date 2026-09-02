<?php

declare(strict_types=1);

namespace CoolMS\CoreBundle\Tests\Module;

use CoolMS\CoreBundle\Module\ModuleConfigFiles;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * A module's config file has to leave with its bundle, and only its own.
 *
 * Disabling a bundle takes away the extension that reads its configuration
 * file, and Symfony then refuses to build the container at all -- so this is
 * not a tidy-up, it is the difference between a removed module and an
 * application that will not start.
 *
 * The refusal is the other half. A file that also configured `framework` would
 * take unrelated configuration down with it, silently, so setting it aside has
 * to fail loudly instead.
 */
final class ModuleConfigFilesTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/coolms-cfg-' . uniqid('', true);
        mkdir($this->dir . '/config/packages', 0o777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/config/packages/*') as $f) {
            unlink($f);
        }
        rmdir($this->dir . '/config/packages');
        rmdir($this->dir . '/config');
        rmdir($this->dir);
    }

    private function write(string $name, string $body): void
    {
        file_put_contents($this->dir . '/config/packages/' . $name, $body);
    }

    #[Test]
    public function aSinglePurposeFileIsSetAsideAndComesBack(): void
    {
        $this->write('coolms_thing.yaml', "thing:
    enabled: true
");
        $files = new ModuleConfigFiles($this->dir);

        $done = $files->setAside('thing');

        self::assertSame(['set aside coolms_thing.yaml'], $done);
        self::assertFileDoesNotExist($this->dir . '/config/packages/coolms_thing.yaml');
        self::assertFileExists($this->dir . '/config/packages/coolms_thing.yaml.disabled');

        // The glob Symfony uses is `*.yaml`, so the renamed file is invisible
        // to it -- that is the whole mechanism, and it is worth asserting
        // rather than assuming.
        self::assertSame([], glob($this->dir . '/config/packages/*.yaml'));

        self::assertSame(['restored coolms_thing.yaml'], $files->restore('thing'));
        self::assertFileExists($this->dir . '/config/packages/coolms_thing.yaml');
    }

    #[Test]
    public function aFileConfiguringSomethingElseTooIsRefused(): void
    {
        $this->write('coolms_thing.yaml',
            "thing:
    enabled: true
framework:
    secret: x
");
        $files = new ModuleConfigFiles($this->dir);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/configures more than "thing"/');

        $files->setAside('thing');
    }

    #[Test]
    public function aRefusalLeavesTheFileWhereItWas(): void
    {
        $this->write('coolms_thing.yaml',
            "thing:
    enabled: true
framework:
    secret: x
");
        $files = new ModuleConfigFiles($this->dir);

        try {
            $files->setAside('thing');
            self::fail('setAside() should have refused.');
        } catch (RuntimeException) {
            // The refusal only helps if nothing moved: the caller goes on to
            // leave the bundle enabled, and a half-applied change would strand
            // the application between the two states.
            self::assertFileExists($this->dir . '/config/packages/coolms_thing.yaml');
            self::assertFileDoesNotExist(
                $this->dir . '/config/packages/coolms_thing.yaml.disabled');
        }
    }

    #[Test]
    public function aModuleWithNoConfigFileIsNotAnError(): void
    {
        $files = new ModuleConfigFiles($this->dir);

        self::assertSame([], $files->setAside('nothing_here'));
        self::assertSame([], $files->restore('nothing_here'));
    }
}
