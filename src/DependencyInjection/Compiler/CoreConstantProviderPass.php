<?php

declare(strict_types=1);

namespace CoolMS\CoreBundle\DependencyInjection\Compiler;

use CoolMS\CoreBundle\Dtmpl\CoreConstantProvider;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Re-asserts the explicit scalar arguments for CoreConstantProvider after the
 * services.yaml auto-scan has overridden the registration made in the Extension.
 *
 * Extension pre-registers the service -- auto-scan sets autowire:true -- this
 * pass runs last, disables autowiring, and injects the resolved container parameters.
 */
final class CoreConstantProviderPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->has(CoreConstantProvider::class)) {
            return;
        }
        $container->findDefinition(CoreConstantProvider::class)
            ->setAutowired(false)
            ->setArgument('$siteName', '%coolms.site_name%')
            ->setArgument('$appVersion', '%coolms.app_version%');
    }
}
