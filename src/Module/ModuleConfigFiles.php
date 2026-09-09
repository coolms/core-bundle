<?php

declare(strict_types=1);

namespace CoolMS\Core\Bundle\Module;

use RuntimeException;
use Symfony\Component\HttpKernel\Bundle\BundleInterface;
use Throwable;

use function array_keys;
use function basename;
use function file;
use function glob;
use function in_array;
use function is_file;
use function preg_match;
use function rename;
use function sprintf;
use function str_ends_with;
use function substr;

/**
 * A module's own file under `config/packages/`, set aside while it is disabled.
 *
 * WHY THIS EXISTS. Disabling a bundle takes away its DI extension, but its
 * configuration file stays where it is -- and Symfony refuses to load
 * configuration for an extension nobody provides:
 *
 *     There is no extension able to load the configuration for "document"
 *     (in ".../config/packages/coolms_document.yaml")
 *
 * That is not a degraded module. It is a container that will not build, so the
 * whole application stops booting. Measured on this codebase: 15 of the 63
 * module extension aliases are configured by such a file, so disabling any one
 * of those 15 bricked the application until this class existed.
 *
 * WHY RENAME RATHER THAN EDIT. The file is the operator's, and removal deletes
 * nothing -- the same promise the rest of the ship makes. `x.yaml` becomes
 * `x.yaml.disabled`, which Symfony's `*.yaml` glob does not pick up and which
 * restores by renaming back. It also drops out of {@see
 * \CoolMS\Core\Bundle\Cache\CacheSeed}, whose extension list does not
 * include `disabled`, so the cache prefix seed moves for free -- the same
 * mechanism {@see DisabledBundles} relies on, for the same reason.
 *
 * WHY IT REFUSES RATHER THAN GUESSES. A file is set aside only when the module's
 * alias is the ONLY top-level key in it. Today all 15 are single-purpose, but a
 * file that also configured `framework` would take unrelated configuration down
 * with it, and silently. Such a file makes {@see setAside} THROW, which stops
 * the removal -- leaving the bundle enabled and the application working. A
 * message in a list would not have stopped it.
 */
final readonly class ModuleConfigFiles
{
    public function __construct(private string $projectDir)
    {
    }

    public function directory(): string
    {
        return $this->projectDir . '/config/packages';
    }

    /**
     * The extension alias a bundle configures, or null when it has none.
     *
     * @param class-string $bundleClass
     */
    public function aliasOf(string $bundleClass): ?string
    {
        try {
            $bundle = new $bundleClass();
            if (!$bundle instanceof BundleInterface) {
                return null;
            }
            $extension = $bundle->getContainerExtension();
        } catch (Throwable) {
            // A bundle we cannot construct standalone tells us nothing here,
            // and guessing an alias from its name would be a rename by
            // coincidence.
            return null;
        }

        return null === $extension ? null : $extension->getAlias();
    }

    /**
     * Set aside the file configuring $alias.
     *
     * @return list<string> what happened, in the caller's words
     */
    public function setAside(string $alias, bool $dryRun = false): array
    {
        $found = $this->fileFor($alias, '.yaml');
        if (null === $found) {
            return [];
        }
        [$path, $sole] = $found;

        if (!$sole) {
            // Throwing rather than reporting, because the caller must not go on
            // to disable the bundle: the config file would stay, and a config
            // file whose extension is gone stops the container building. A
            // refusal leaves a working application; a message in a list does
            // not.
            throw new RuntimeException(sprintf(
                '%s configures more than "%s", so setting it aside would '
                . 'disable unrelated configuration. Move the "%s" block into '
                . 'its own file, or disable it by hand.',
                basename($path), $alias, $alias,
            ));
        }

        if ($dryRun) {
            return [sprintf('would set aside %s', basename($path))];
        }

        if (!rename($path, $path . '.disabled')) {
            throw new RuntimeException(sprintf(
                'Could not set aside %s. The module was NOT disabled: the '
                . 'container will not build while that file is there.',
                basename($path),
            ));
        }

        return [sprintf('set aside %s', basename($path))];
    }

    /**
     * Put back the file configuring $alias.
     *
     * @return list<string>
     */
    public function restore(string $alias, bool $dryRun = false): array
    {
        $found = $this->fileFor($alias, '.yaml.disabled');
        if (null === $found) {
            return [];
        }
        [$path] = $found;

        $back = substr($path, 0, -9);

        if ($dryRun) {
            return [sprintf('would restore %s', basename($back))];
        }

        if (!rename($path, $back)) {
            return [sprintf('COULD NOT restore %s', basename($back))];
        }

        return [sprintf('restored %s', basename($back))];
    }

    /**
     * @return array{0: string, 1: bool}|null path, and whether the alias is the
     *                                       only top-level key in it
     */
    private function fileFor(string $alias, string $suffix): ?array
    {
        $files = glob($this->directory() . '/*' . $suffix);
        foreach ($files === false ? [] : $files as $path) {
            if (!is_file($path) || !str_ends_with($path, $suffix)) {
                continue;
            }

            $keys = $this->topLevelKeys($path);
            if (!in_array($alias, $keys, true)) {
                continue;
            }

            return [$path, [$alias] === $keys];
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function topLevelKeys(string $path): array
    {
        $lines = file($path);
        $keys = [];
        foreach ($lines === false ? [] : $lines as $line) {
            // Top level only: an indented key belongs to the block above it,
            // and a commented one is not configuration at all.
            if (preg_match('/^([A-Za-z_][A-Za-z0-9_@.\-]*)\s*:/', $line, $m)) {
                $keys[$m[1]] = true;
            }
        }

        return array_keys($keys);
    }
}
