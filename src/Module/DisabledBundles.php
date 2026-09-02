<?php

declare(strict_types=1);

namespace CoolMS\CoreBundle\Module;

use RuntimeException;

use function array_values;
use function file_put_contents;
use function in_array;
use function is_array;
use function is_file;
use function sort;
use function unlink;
use function sprintf;
use function var_export;

/**
 * The list of bundles the operator has disabled, as a file `bundles.php` reads.
 *
 * WHY A FILE AND NOT THE DATABASE. This list is consulted at kernel boot, and
 * the database is not always available -- a module list that depends on it
 * fails in exactly the situations where the application most needs to start.
 *
 * WHY IN `config/`. Two reasons, and the second is the one worth stating.
 * First, it is configuration: an operator can read it, diff it and commit it.
 * Second, {@see \CoolMS\CoreBundle\Cache\CacheSeed} hashes every file under
 * `config/` whose extension is one it recognises, so writing this file MOVES
 * `framework.cache.prefix_seed` and invalidates every pool. Disabling a module
 * must not leave a pool holding objects whose class has stopped loading, and
 * putting the file here gets that for free.
 *
 * That invalidation is a consequence of the LOCATION, not of anything this
 * class does, so it is pinned by a test -- move the file out of `config/` and
 * the test says why it broke.
 *
 * The file is written sorted and re-readable: it is a plain PHP array of
 * bundle class names, and nothing else may live in it.
 */
final readonly class DisabledBundles
{
    public function __construct(private string $projectDir)
    {
    }

    public function path(): string
    {
        return $this->projectDir . '/config' . '/coolms_disabled_bundles.php';
    }

    /**
     * @return list<class-string>
     */
    public function all(): array
    {
        $path = $this->path();
        if (!is_file($path)) {
            // Absent is the normal state: nothing has ever been disabled. It is
            // not an error, and `bundles.php` must boot without it.
            return [];
        }

        /** @var mixed $data */
        $data = require $path;

        return is_array($data) ? array_values($data) : [];
    }

    public function isDisabled(string $bundleClass): bool
    {
        return in_array($bundleClass, $this->all(), true);
    }

    /**
     * @param list<class-string> $bundleClasses
     */
    public function write(array $bundleClasses): void
    {
        sort($bundleClasses);

        if ([] === $bundleClasses) {
            // Absence is the normal state, as the header above says, so an
            // empty list leaves no file rather than a file saying nothing.
            // Re-enabling the last disabled module returns the checkout to
            // exactly the shape a fresh one has.
            if (is_file($this->path())) {
                unlink($this->path());
            }

            return;
        }

        $body = "<?php" . PHP_EOL . PHP_EOL
            . "// Written by `coolms:module:remove` and `coolms:module:restore`." . PHP_EOL
            . "// Bundles listed here are skipped by config/bundles.php." . PHP_EOL
            . "// Removing an entry re-enables the module; its data was never touched." . PHP_EOL
            . PHP_EOL
            . 'return ' . var_export(array_values($bundleClasses), true) . ';' . PHP_EOL;

        if (false === file_put_contents($this->path(), $body)) {
            throw new RuntimeException(sprintf(
                'Could not write %s. The module was NOT disabled.',
                $this->path(),
            ));
        }
    }

    public function disable(string $bundleClass): void
    {
        $all = $this->all();
        if (in_array($bundleClass, $all, true)) {
            return;   // idempotent, like install
        }
        $all[] = $bundleClass;
        $this->write($all);
    }

    public function enable(string $bundleClass): void
    {
        $all = $this->all();
        $kept = [];
        foreach ($all as $one) {
            if ($one !== $bundleClass) {
                $kept[] = $one;
            }
        }
        if ($kept !== $all) {
            $this->write($kept);
        }
    }
}
