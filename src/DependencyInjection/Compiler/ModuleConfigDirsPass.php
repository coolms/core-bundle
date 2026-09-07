<?php

declare(strict_types=1);

namespace CoolMS\CoreBundle\DependencyInjection\Compiler;

use LogicException;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Every registered bundle's `config/` directory that actually carries module
 * YAML, as `coolms.module_config_dirs`.
 *
 * The file-driven loaders read `config/modules/{module}/{type}/*.yaml` from the
 * application only, so a package could not ship a definition and have it found:
 * the module had to be installed into the application's own config. This
 * publishes the list once, at build time, and the loaders read it.
 *
 * Runs from `build()` rather than as a compiler pass because the loaders resolve
 * the parameter through `#[Autowire]`, and the kernel adds
 * `kernel.bundles_metadata` before any bundle's `build()` is called.
 *
 * !! A bundle's path is NOT a reliable base on its own. `Bundle::getPath()`
 * returns the directory of the bundle class, so a class in `src/` yields
 * `<package>/src` -- but a bundle carrying templates or assets overrides it to
 * return the package root, and both conventions are live in this tree
 * (`core-bundle/src` vs `theme-default`). So both places are examined rather
 * than one being assumed.
 *
 * Only directories that already contain `modules/` are listed: the parameter
 * then means "roots with something to load", and the loaders spend no time
 * globbing paths that will never match.
 */
final readonly class ModuleConfigDirsPass
{
    public function prepend(ContainerBuilder $container): void
    {
        $projectDir = $container->getParameter('kernel.project_dir');
        if (!is_string($projectDir)) {
            throw new LogicException('kernel.project_dir must be a string.');
        }

        $metadata = $container->getParameter('kernel.bundles_metadata');
        if (!is_array($metadata)) {
            throw new LogicException('kernel.bundles_metadata must be an array.');
        }

        $appConfig = rtrim($projectDir, '/') . '/config';
        $dirs = [];

        foreach ($metadata as $entry) {
            if (!is_array($entry) || !is_string($entry['path'] ?? null)) {
                continue;
            }

            $path = rtrim($entry['path'], '/');

            // !! The parent is examined ONLY when the path ends in `src`.
            // Climbing unconditionally leaves the package and lands in the
            // vendor namespace directory, where a sibling package can share the
            // name being looked for.
            foreach (self::candidates($path, 'config') as $candidate) {
                // The application's own config is read directly by the loaders,
                // and listing it here too would have it scanned twice -- which
                // for a last-write-wins loader silently changes precedence.
                if ($candidate === $appConfig) {
                    continue;
                }

                if (is_dir($candidate . '/modules')) {
                    $dirs[$candidate] = true;
                }
            }
        }

        $dirs = array_keys($dirs);
        // Deterministic: the container is compared byte-for-byte across builds,
        // and for the first-match loader the order decides which file wins.
        sort($dirs);

        $container->setParameter('coolms.module_config_dirs', $dirs);
    }

    /**
     * `<path>/<name>`, plus `<package>/<name>` when the path is a `src`
     * directory -- never a climb out of the package.
     *
     * @return list<string>
     */
    private static function candidates(string $path, string $name): array
    {
        $out = [$path . '/' . $name];

        if ('src' === basename($path)) {
            $out[] = dirname($path) . '/' . $name;
        }

        return $out;
    }
}
