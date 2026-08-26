<?php

declare(strict_types=1);

namespace CoolMS\CoreBundle\DependencyInjection\Compiler;

use CoolMS\CoreBundle\Cache\CacheSeed;
use Symfony\Component\DependencyInjection\ContainerBuilder;

use function is_string;

/**
 * Seeds `framework.cache.prefix_seed` from the installed package set, so every
 * cache pool is namespaced by the release that filled it. See {@see CacheSeed}
 * for why the default seed protects nothing.
 *
 * Prepended, not set: `prependExtensionConfig` puts this at the FRONT of
 * FrameworkExtension's config queue, and the merge tree lets later config win.
 * An application that names its own `prefix_seed` keeps it.
 *
 * Like {@see AbstractMessengerRoutingPass} this is not a compiler pass despite
 * the name: `prependExtensionConfig` has to run during `Bundle::build()`,
 * before extensions load. Calling it from `process()` is too late.
 */
final class CachePrefixSeedPass
{
    public function prepend(ContainerBuilder $container): void
    {
        $projectDir = $container->hasParameter('kernel.project_dir')
            ? $container->getParameter('kernel.project_dir')
            : '';

        $container->prependExtensionConfig('framework', [
            'cache' => [
                'prefix_seed' => CacheSeed::compute(is_string($projectDir) ? $projectDir : ''),
            ],
        ]);
    }
}
