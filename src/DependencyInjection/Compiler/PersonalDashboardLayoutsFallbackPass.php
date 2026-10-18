<?php

declare(strict_types=1);

namespace CoolMS\Core\Bundle\DependencyInjection\Compiler;

use CoolMS\Core\Application\Dashboard\NoPersonalDashboardLayouts;
use CoolMS\Core\Application\Dashboard\PersonalDashboardLayoutsInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Makes the per-person dashboard store optional.
 *
 * Where each person's arrangement is kept is the host's business -- in a full
 * CoolMS install the Identity module keeps it with the person's other settings
 * and aliases {@see PersonalDashboardLayoutsInterface} in its own wiring. The
 * platform stores nothing of its own (a core package writes bookkeeping only),
 * so when no module answers, this binds {@see NoPersonalDashboardLayouts}:
 * every dashboard is the module default and arranging one answers 501.
 *
 * A PASS rather than an `if (!hasAlias)` in the Extension for the same reason as
 * {@see ConfigStoreFallbackPass}: Core's bundle loads before the modules', so an
 * Extension-time check would always fire first and shadow the real store.
 */
final class PersonalDashboardLayoutsFallbackPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if ($container->hasAlias(PersonalDashboardLayoutsInterface::class)
            || $container->hasDefinition(PersonalDashboardLayoutsInterface::class)) {
            return;
        }

        if (!$container->hasDefinition(NoPersonalDashboardLayouts::class)) {
            $container->register(NoPersonalDashboardLayouts::class, NoPersonalDashboardLayouts::class)
                ->setAutowired(true)
                ->setAutoconfigured(false)
                ->setPublic(false);
        }
        $container->setAlias(PersonalDashboardLayoutsInterface::class, NoPersonalDashboardLayouts::class)
            ->setPublic(false);
    }
}
