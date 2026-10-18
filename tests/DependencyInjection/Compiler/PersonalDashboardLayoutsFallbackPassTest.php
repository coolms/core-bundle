<?php

declare(strict_types=1);

namespace CoolMS\Core\Bundle\Tests\DependencyInjection\Compiler;

use CoolMS\Core\Application\Dashboard\NoPersonalDashboardLayouts;
use CoolMS\Core\Application\Dashboard\PersonalDashboardLayoutsInterface;
use CoolMS\Core\Bundle\DependencyInjection\Compiler\PersonalDashboardLayoutsFallbackPass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * The pass that makes the per-person dashboard store optional: the platform's
 * "nothing is kept" answer when no module keeps arrangements, and out of the
 * way when one does -- shadowing it would turn every person's Save into a 501.
 */
final class PersonalDashboardLayoutsFallbackPassTest extends TestCase
{
    #[Test]
    public function bindsNothingIsKeptWhenNoModuleAnswers(): void
    {
        $container = new ContainerBuilder();

        new PersonalDashboardLayoutsFallbackPass()->process($container);

        self::assertSame(
            NoPersonalDashboardLayouts::class,
            (string) $container->getAlias(PersonalDashboardLayoutsInterface::class),
        );
        self::assertTrue($container->hasDefinition(NoPersonalDashboardLayouts::class));
    }

    #[Test]
    public function leavesAModulesAliasUntouched(): void
    {
        $container = new ContainerBuilder();
        // Simulate Identity having aliased the port to its profile-backed store.
        $container->register('identity.dashboard_layouts', stdClass::class);
        $container->setAlias(PersonalDashboardLayoutsInterface::class, 'identity.dashboard_layouts');

        new PersonalDashboardLayoutsFallbackPass()->process($container);

        self::assertSame(
            'identity.dashboard_layouts',
            (string) $container->getAlias(PersonalDashboardLayoutsInterface::class),
        );
        self::assertFalse($container->hasDefinition(NoPersonalDashboardLayouts::class));
    }

    #[Test]
    public function respectsAConcreteServiceRegisteredUnderThePortId(): void
    {
        $container = new ContainerBuilder();
        $container->register(PersonalDashboardLayoutsInterface::class, stdClass::class);

        new PersonalDashboardLayoutsFallbackPass()->process($container);

        self::assertFalse($container->hasAlias(PersonalDashboardLayoutsInterface::class));
        $definition = $container->getDefinition(PersonalDashboardLayoutsInterface::class);
        self::assertSame(stdClass::class, $definition->getClass());
    }
}
