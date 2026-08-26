<?php

declare(strict_types=1);

namespace CoolMS\CoreBundle\Cache;

use Composer\InstalledVersions;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

use function array_keys;
use function class_exists;
use function hash;
use function hash_file;
use function implode;
use function in_array;
use function is_dir;
use function ksort;
use function str_replace;
use function strlen;
use function substr;

/**
 * The value every Symfony cache pool is namespaced with.
 *
 * A cache key describes its INPUT -- a template's source, a font catalogue's
 * name, a page's host and path. It says nothing about the code that produced
 * the stored value, so an upgrade that changes a payload class leaves
 * yesterday's serialized objects readable by today's classes: a fatal on the
 * first property the new class expects and the old graph does not carry.
 *
 * Symfony already has one place that namespaces every pool at once --
 * `framework.cache.prefix_seed`. Its default is derived from the project
 * directory and the container class, both of which are byte-identical before
 * and after an upgrade, so by default it protects nothing. This seeds it from
 * what actually moves when the code moves:
 *
 *  1. **The installed package set** -- every package's version AND resolved
 *     reference, read from Composer's own runtime data. Not a build number and
 *     not an application commit: a commit does not move when a module version
 *     does, and an application that is a near-empty skeleton over vendor has no
 *     commit worth keying on. Reading it here means an installation invalidates
 *     its caches on `composer update` while knowing nothing about how it is
 *     deployed.
 *  2. **The application's own configuration** -- so an override that is not a
 *     package bump (an edited yaml, a new module config) still invalidates.
 *
 * Both components are pure functions of files on disk: one installation
 * computes one seed, on every machine and in every worker process. Nothing
 * here may read a clock or a random source -- a seed that differs between two
 * FPM workers gives them disjoint caches and neither would know.
 *
 * **It is a floor, not a substitute for versioning a payload.** It keys on the
 * installed release, which is exactly what does not move in development, where
 * classes change constantly and nothing is installed. A payload whose class is
 * still evolving carries its own schema version in its own key as well.
 *
 * An application that sets `framework.cache.prefix_seed` itself keeps its own
 * value -- {@see \CoolMS\CoreBundle\DependencyInjection\Compiler\CachePrefixSeedPass}
 * PREPENDS this one, so application config wins.
 */
final class CacheSeed
{
    /** Application config file types that participate in the seed. */
    private const array CONFIG_EXTENSIONS = ['yaml', 'yml', 'xml', 'php', 'json'];

    /**
     * Non-cryptographic on purpose -- this is a namespace, not a signature, and
     * it is recomputed on every container build.
     */
    private const string ALGO = 'xxh128';

    /** Prefix so a pool directory is recognisable on disk. */
    private const string PREFIX = 'coolms.';

    public static function compute(string $projectDir): string
    {
        return self::PREFIX . hash(
            self::ALGO,
            self::installedPackages() . "\n--\n" . self::applicationConfig($projectDir),
        );
    }

    /**
     * Every installed package as `name version@reference`, sorted by name.
     *
     * `pretty_version` alone is not enough: a dev-branch or path install keeps
     * one version string across many different states of the code. `reference`
     * is the resolved commit/dist hash and moves with them. Entries that only
     * replace or provide another package carry neither and contribute an empty
     * pair -- their presence in the list is still the fact worth capturing.
     */
    private static function installedPackages(): string
    {
        if (!class_exists(InstalledVersions::class)) {
            return ''; // not installed through Composer -- component contributes nothing
        }

        $packages = [];
        foreach (InstalledVersions::getAllRawData() as $dataset) {
            foreach ($dataset['versions'] as $name => $info) {
                // `?? ''` covers both a missing key and an explicit null
                // reference (a path install with no VCS state behind it).
                $packages[$name] = ($info['pretty_version'] ?? '') . '@' . ($info['reference'] ?? '');
            }
        }

        ksort($packages);

        $lines = [];
        foreach ($packages as $name => $state) {
            $lines[] = $name . ' ' . $state;
        }

        return implode("\n", $lines);
    }

    /**
     * Every config file under the project's `config/` directory as
     * `relative/path hash`, sorted by path.
     *
     * Paths are stored relative to the project dir and normalised to forward
     * slashes so the same tree seeds identically wherever it is checked out.
     */
    private static function applicationConfig(string $projectDir): string
    {
        $dir = $projectDir . '/config';
        if ('' === $projectDir || !is_dir($dir)) {
            return '';
        }

        $strip = strlen($projectDir);
        $files = [];

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        ) as $file) {
            if (!$file->isFile() || !in_array($file->getExtension(), self::CONFIG_EXTENSIONS, true)) {
                continue;
            }

            $relative = str_replace('\\', '/', substr($file->getPathname(), $strip));
            $hash = hash_file(self::ALGO, $file->getPathname());
            $files[$relative] = false === $hash ? '' : $hash;
        }

        ksort($files);

        $lines = [];
        foreach (array_keys($files) as $path) {
            $lines[] = $path . ' ' . $files[$path];
        }

        return implode("\n", $lines);
    }
}
