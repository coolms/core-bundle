<?php

declare(strict_types=1);

namespace CoolMS\Core\Bundle\Tests\DependencyInjection\Compiler;

use CoolMS\Core\Application\Config\ConfigWriterInterface;
use CoolMS\Core\Application\Config\NoConfigOverrides;
use CoolMS\Core\Application\Config\ReadOnlyConfigWriter;
use CoolMS\Core\Bundle\DependencyInjection\Compiler\ConfigStoreFallbackPass;
use CoolMS\Core\Config\ConfigOverrideReaderInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * The pass that makes the config store optional. Two branches matter: bind the
 * platform's own answers when both ports are unbound (no store module), and
 * stand aside when a module already owns one -- its Extension aliased the port
 * before this pass runs, and shadowing it would send every operator's save to a
 * writer that refuses.
 */
final class ConfigStoreFallbackPassTest extends TestCase
{
    #[Test]
    public function bindsPlatformAnswersWhenBothPortsAreUnbound(): void
    {
        $container = new ContainerBuilder();

        new ConfigStoreFallbackPass()->process($container);

        self::assertSame(
            ReadOnlyConfigWriter::class,
            (string) $container->getAlias(ConfigWriterInterface::class),
        );
        self::assertSame(
            NoConfigOverrides::class,
            (string) $container->getAlias(ConfigOverrideReaderInterface::class),
        );
    }

    #[Test]
    public function leavesAStoreModulesAliasUntouched(): void
    {
        $container = new ContainerBuilder();
        // Simulate Settings having aliased the writer to its chained store.
        $container->register('coolms.settings.config_writer', stdClass::class);
        $container->setAlias(ConfigWriterInterface::class, 'coolms.settings.config_writer');

        new ConfigStoreFallbackPass()->process($container);

        // The module's writer survives (NOT shadowed by the read-only one)...
        self::assertSame(
            'coolms.settings.config_writer',
            (string) $container->getAlias(ConfigWriterInterface::class),
        );
        // ...while the still-unbound reader gets the platform's answer.
        self::assertSame(
            NoConfigOverrides::class,
            (string) $container->getAlias(ConfigOverrideReaderInterface::class),
        );
    }

    #[Test]
    public function respectsAConcreteServiceRegisteredUnderThePortId(): void
    {
        $container = new ContainerBuilder();
        // A module could register the port id as a concrete service (no alias).
        $container->register(ConfigOverrideReaderInterface::class, stdClass::class);

        new ConfigStoreFallbackPass()->process($container);

        // The pass must defer to it -- no fallback alias minted over a real definition.
        self::assertFalse($container->hasAlias(ConfigOverrideReaderInterface::class));
    }
}
