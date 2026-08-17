<?php

declare(strict_types=1);

namespace CoolMS\CoreBundle\Tests\DependencyInjection;

use CoolMS\CoreBundle\DependencyInjection\Configuration;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;

use function sprintf;
use function var_export;

/**
 * F6 Phase 1 -- the coolms_core config tree, focused on the
 * new `platform_defaults` node + the existing `default_locale`.
 *
 * `platform_defaults` uses `addDefaultsIfNotSet()` so an operator who
 * omits the block (or any single key) still gets the bundled platform
 * defaults; an operator who sets one key keeps defaults for the rest.
 */
final class ConfigurationTest extends TestCase
{
    #[Test]
    public function platformDefaultsApplyWhenBlockOmittedEntirely(): void
    {
        $config = $this->process([]);

        self::assertSame('UTC', $config['platform_defaults']['timezone']);
        self::assertSame('yyyy-MM-dd', $config['platform_defaults']['date_format']);
        self::assertSame('24h', $config['platform_defaults']['time_format']);
        self::assertSame('monday', $config['platform_defaults']['week_start']);
    }

    #[Test]
    public function accentColorDefaultsToNoDeploymentBrand(): void
    {
        // null, NOT today's amber. A concrete default here would freeze every
        // deployment to whatever the stylesheet happened to ship, and the
        // stylesheet could then never move it.
        self::assertNull($this->process([])['platform_defaults']['accent_color']);
    }

    #[Test]
    public function accentColorAcceptsASixDigitHex(): void
    {
        $config = $this->process([['platform_defaults' => ['accent_color' => '#0A7D2B']]]);

        self::assertSame('#0A7D2B', $config['platform_defaults']['accent_color']);
    }

    #[Test]
    public function accentColorRejectsAnythingElseAtContainerBuild(): void
    {
        // This value is substituted into a CSS custom property, so a mistyped
        // brand colour must stop the container building rather than ship a
        // half-broken palette to every user of the deployment.
        foreach (['rebeccapurple', '#ABC', '#12345g', 'red; background: url(//evil)', ''] as $bad) {
            try {
                $this->process([['platform_defaults' => ['accent_color' => $bad]]]);
                self::fail(sprintf('Expected %s to be rejected.', var_export($bad, true)));
            } catch (InvalidConfigurationException $e) {
                self::assertStringContainsString('six-digit hex', $e->getMessage());
            }
        }
    }

    #[Test]
    public function defaultLocaleDefaultsToEn(): void
    {
        $config = $this->process([]);
        self::assertSame('en', $config['default_locale']);
    }

    #[Test]
    public function operatorOverridesMergePerKeyKeepingDefaultsForTheRest(): void
    {
        $config = $this->process([
            ['platform_defaults' => ['timezone' => 'Europe/Kyiv', 'week_start' => 'sunday']],
        ]);

        // Overridden keys win...
        self::assertSame('Europe/Kyiv', $config['platform_defaults']['timezone']);
        self::assertSame('sunday', $config['platform_defaults']['week_start']);
        // ...unset keys keep their bundled defaults.
        self::assertSame('yyyy-MM-dd', $config['platform_defaults']['date_format']);
        self::assertSame('24h', $config['platform_defaults']['time_format']);
    }

    #[Test]
    public function emptyPlatformDefaultScalarIsRejected(): void
    {
        // `cannotBeEmpty()` on each leaf: a blank timezone is a config
        // error, not a "fall through" -- there is no lower tier under the
        // platform default.
        $this->expectException(InvalidConfigurationException::class);
        $this->process([['platform_defaults' => ['timezone' => '']]]);
    }

    /**
     * @param list<array<string, mixed>> $configs
     *
     * @return array<string, mixed>
     */
    private function process(array $configs): array
    {
        return new Processor()->processConfiguration(new Configuration(), $configs);
    }
}
