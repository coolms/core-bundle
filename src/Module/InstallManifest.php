<?php

declare(strict_types=1);

namespace CoolMS\CoreBundle\Module;

use CoolMS\Core\Install\DeclaresVfsPathsInterface;
use JsonException;
use Throwable;

use function array_values;
use function dirname;
use function file_get_contents;
use function file_put_contents;
use function is_array;
use function is_dir;
use function is_file;
use function json_decode;
use function json_encode;
use function ksort;
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
        foreach ($installers as $installer) {
            $names[] = $installer::class;
            if ($installer instanceof DeclaresVfsPathsInterface) {
                foreach ($installer->declaredVfsPaths() as $path) {
                    $paths[] = $path;
                }
            }
        }

        $all = $this->all();
        $all[$module] = [
            'paths' => array_values($paths),
            'installers' => array_values($names),
            'at' => date('c'),
        ];
        ksort($all);

        $this->write($all);
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
