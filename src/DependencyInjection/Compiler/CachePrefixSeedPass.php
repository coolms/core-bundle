<?php

declare(strict_types=1);

namespace CoolMS\Core\Bundle\DependencyInjection\Compiler;

use CoolMS\Core\Bundle\Cache\CacheSeed;
use Symfony\Component\DependencyInjection\ContainerBuilder;

use function getenv;
use function is_bool;
use function is_string;
use function trim;

/**
 * Seeds `framework.cache.prefix_seed` from the installed package set, so every
 * cache pool is namespaced by the release that filled it. See {@see CacheSeed}
 * for why the default seed protects nothing, and for the item-by-item account
 * of what the default covered and what this preserves or drops.
 *
 * Prepended, not set: `prependExtensionConfig` puts this at the FRONT of
 * FrameworkExtension's config queue, and the merge tree lets later config win.
 * An application that names its own `prefix_seed` keeps it.
 *
 * Like {@see AbstractMessengerRoutingPass} this is not a compiler pass despite
 * the name: `prependExtensionConfig` has to run during `Bundle::build()`,
 * before extensions load. Calling it from `process()` is too late.
 *
 * ## `COOLMS_BUILD_ID` -- closing the `src/` gap without paying for it
 *
 * The seed covers installed packages and `config/`. It does NOT cover `src/`,
 * so an application whose code lives there can change without the seed moving.
 * Hashing `src/` would close it and cost far more than the ~23 ms `config/`
 * costs, on every container build, forever.
 *
 * So instead: **if `COOLMS_BUILD_ID` is set, it participates in the seed; if it
 * is absent, the seed is computed exactly as it would be without this.** Default
 * behaviour is unchanged and nobody pays for a feature they do not use. The
 * deploy script sets it, so anything deployed through the script has the gap
 * closed -- at the point where a build identity actually comes into existence.
 *
 * The seam survives the skeleton-application transition without changing: the
 * image digest fills the variable today, `composer.lock` fills it once `src/`
 * is nearly empty, and this bundle does not care which.
 *
 * !! Read at COMPILE time, not through `%env()%`. An env placeholder is resolved
 * at runtime, but pool namespaces are computed from the seed when the container
 * is compiled -- a placeholder would be hashed as its own literal text. The
 * consequence is that changing `COOLMS_BUILD_ID` only takes effect on a
 * container rebuild, which is exactly when a build identity changes anyway.
 *
 * !! Development is deliberately left uncovered. It is covered by what already
 * covers it: a per-payload schema version, and clearing by hand.
 */
final class CachePrefixSeedPass
{
    /** Optional build identity. Absent means "compute the seed as before". */
    public const string BUILD_ID_ENV = 'COOLMS_BUILD_ID';

    public function prepend(ContainerBuilder $container): void
    {
        $container->prependExtensionConfig('framework', [
            'cache' => [
                'prefix_seed' => CacheSeed::compute(
                    projectDir: self::stringParam($container, 'kernel.project_dir'),
                    environment: self::stringParam($container, 'kernel.environment'),
                    debug: self::boolParam($container, 'kernel.debug'),
                    buildId: self::buildId(),
                ),
            ],
        ]);
    }

    private static function stringParam(ContainerBuilder $container, string $name): string
    {
        if (!$container->hasParameter($name)) {
            return '';
        }
        $value = $container->getParameter($name);

        return is_string($value) ? $value : '';
    }

    private static function boolParam(ContainerBuilder $container, string $name): bool
    {
        if (!$container->hasParameter($name)) {
            return false;
        }
        $value = $container->getParameter($name);

        return is_bool($value) ? $value : (bool) $value;
    }

    /**
     * !! `$_SERVER` and `$_ENV` first, because Symfony's Dotenv populates those
     * and does NOT always reach `getenv()` -- reading only `getenv()` would miss
     * a value set in `.env`, and the failure would be a seed that silently did
     * not move.
     */
    private static function buildId(): string
    {
        foreach ([$_SERVER, $_ENV] as $bag) {
            $value = $bag[self::BUILD_ID_ENV] ?? null;
            if (is_string($value) && '' !== trim($value)) {
                return trim($value);
            }
        }

        $value = getenv(self::BUILD_ID_ENV);

        return is_string($value) ? trim($value) : '';
    }
}
