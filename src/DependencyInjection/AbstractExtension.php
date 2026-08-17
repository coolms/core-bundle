<?php

declare(strict_types=1);

namespace CoolMS\CoreBundle\DependencyInjection;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension as BaseExtension;

abstract class AbstractExtension extends BaseExtension
{
    /**
     * @param array<class-string, class-string> $resolveTargetEntities
     */
    protected function setResolveTargetEntities(ContainerBuilder $container, array $resolveTargetEntities): void
    {
        $container->setParameter('coolms.mapping.resolve_target_entities', [
            ...($container->hasParameter('coolms.mapping.resolve_target_entities')
                ? (array) $container->getParameter('coolms.mapping.resolve_target_entities')
                : []),
            ...$resolveTargetEntities,
        ]);
    }
}
