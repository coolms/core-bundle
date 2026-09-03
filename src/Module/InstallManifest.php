<?php

declare(strict_types=1);

namespace CoolMS\CoreBundle\Module;

use Composer\InstalledVersions;
use CoolMS\Core\Install\DeclaresVfsPathsInterface;
use CoolMS\Core\Install\ModuleInstallerInterface;
use JsonException;
use Throwable;

use function array_values;
use function class_exists;
use function dirname;
use function file_get_contents;
use function file_put_contents;
use function is_array;
use function is_dir;
use function is_file;
use function json_decode;
use function json_encode;
use function ksort;
use function spl_object_id;
use function mkdir;

use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;

/**
 * What installation did, per module.
 *
 * NOT a module registry -- Composer and `bundles.php` already answer what is
 * installed. This answers the different question of what installing it PUT
 * THERE, and it serves three things at once: the guard against removing in the
 * wrong order, the inventory purge will need, and the marketplace's question of
 * whether a module is installed and in what state.
 *
 * ⚠️ DERIVED STATE, AND THEREFORE NEVER AUTHORITATIVE. It lives in `var/`, which
 * is gitignored in full and absent on a fresh checkout, may be missing from a
 * restored backup, and can be cleared by hand. So its absence means NOTHING IS
 * KNOWN -- it must never mean "nothing was installed", and removal must not
 * refuse because of it.
 *
 * `kinds` is DECLARED, never exhaustive. It reads the interfaces a service
 * implements -- vfs when it declares VFS structure, data when it installs
 * -- so a service that does both says both. It never says WHICH rows,
 * because nothing at install time knows. Reading it as a complete
 * inventory is the mistake this line exists to prevent.
 *
 * The artefacts are the source of truth; this only accelerates finding them.
 * Navi rows carry their owner, so a module's navigation can always be
 * identified with or without this file. If absence were treated as authority,
 * clearing `var/` would turn every installed module into an unremovable one.
 *
 * Not in `config/`: that directory is hashed into `framework.cache.prefix_seed`,
 * so writing there on every install would invalidate every cache pool for a
 * record that changes nothing about how the application runs.
 */
final readonly class InstallManifest
{
    public function __construct(private string $projectDir)
    {
    }

    public function path(): string
    {
        return $this->projectDir . '/var/coolms/installed.json';
    }

    public function exists(): bool
    {
        return is_file($this->path());
    }

    /**
     * @return array<string, array{paths: list<string>, installers: list<string>, at: string}>
     */
    public function all(): array
    {
        if (!$this->exists()) {
            // Absent is not empty. The caller must say "unknown", not "none".
            return [];
        }

        try {
            /** @var mixed $data */
            $data = json_decode(
                (string) file_get_contents($this->path()),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );
        } catch (JsonException) {
            // A corrupt manifest is the same as a missing one: derived state
            // that cannot be read is simply not known, and nothing depends on
            // it being readable.
            return [];
        }

        return is_array($data) ? $data : [];
    }

    /**
     * @param iterable<object> $installers every installer that just ran
     */
    public function record(string $module, iterable $installers): void
    {
        $paths = [];
        $names = [];
        $kinds = [];
        $seen = [];
        foreach ($installers as $installer) {
            // One service can be BOTH a structure installer and a module
            // installer, and it is then handed to us once from each tagged
            // iterator. Recording it twice inflates the inventory and would
            // have a purge do its work twice, so identity decides -- not the
            // class name, because two instances of one class are legitimate.
            $id = spl_object_id($installer);
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;

            $names[] = $installer::class;

            // Kinds come from the interfaces the service implements, not from
            // which iterator handed it over: a service that does both declares
            // both, and nothing here has to know how it was collected.
            if ($installer instanceof DeclaresVfsPathsInterface) {
                foreach ($installer->declaredVfsPaths() as $path) {
                    $paths[$path] = true;
                }
                $kinds['vfs'] = true;
            }
            if ($installer instanceof ModuleInstallerInterface) {
                // It installs something and cannot say which rows. That is the
                // whole of what is honestly knowable here.
                $kinds['data'] = true;
            }
        }

        ksort($kinds);

        $all = $this->all();
        $all[$module] = [
            'kinds' => array_keys($kinds),
            'paths' => array_keys($paths),
            'installers' => $names,
            'version' => self::platformVersion(),
            'at' => date('c'),
        ];
        ksort($all);

        $this->write($all);
    }

    /**
     * The platform version that did the installing.
     *
     * Recorded so a later purge knows which layout it is looking at rather
     * than assuming today's. Null when Composer's runtime API is absent --
     * unknown is a fine answer here and a fabricated version is not.
     */
    private static function platformVersion(): ?string
    {
        if (!class_exists(InstalledVersions::class)) {
            return null;
        }

        try {
            return InstalledVersions::getPrettyVersion('coolms/core-bundle');
        } catch (Throwable) {
            return null;
        }
    }

    public function forget(string $module): void
    {
        $all = $this->all();
        if (!isset($all[$module])) {
            return;
        }
        unset($all[$module]);
        $this->write($all);
    }

    /**
     * @param array<string, mixed> $all
     */
    private function write(array $all): void
    {
        $dir = dirname($this->path());
        if (!is_dir($dir) && !@mkdir($dir, 0o775, true) && !is_dir($dir)) {
            // Best effort throughout: this record is an accelerator, and
            // failing to write it must never fail an install that worked.
            return;
        }

        try {
            @file_put_contents(
                $this->path(),
                json_encode($all, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL,
            );
        } catch (Throwable) {
            // Same reason.
        }
    }
}
