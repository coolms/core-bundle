<?php

declare(strict_types=1);

namespace CoolMS\CoreBundle\DependencyInjection\Compiler;

use CoolMS\CoreModule\Option\OptionSourceRegistry;
use Symfony\Component\DependencyInjection\Argument\TaggedIteratorArgument;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Runs after the host's `App\:` resource scan in `services.yaml`, so
 * we can override the autowired definition of {@see OptionSourceRegistry}.
 *
 * Autowiring leaves `$providers` empty because `iterable` is
 * unresolvable. We replace it with a {@see TaggedIteratorArgument}
 * keyed on `coolms.option.source` so every
 * {@see \CoolMS\Core\Option\OptionSourceProviderInterface}
 * implementation gets collected automatically. Same pattern as
 * the scheduler module's own service pass.
 */
final class OptionSourceRegistryPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(OptionSourceRegistry::class)) {
            return;
        }

        $container->findDefinition(OptionSourceRegistry::class)
            ->setArgument('$providers', new TaggedIteratorArgument('coolms.option.source'))
            ->setAutowired(true);
    }
}
