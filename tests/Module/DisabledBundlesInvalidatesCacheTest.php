<?php

declare(strict_types=1);

namespace CoolMS\Core\Bundle\Tests\Module;

use CoolMS\Core\Bundle\Cache\CacheSeed;
use CoolMS\Core\Bundle\Module\DisabledBundles;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Disabling a module must invalidate every cache pool.
 *
 * A pool must not survive holding objects whose class has stopped loading. That
 * happens today for free: the disabled list is written into `config/`, and
 * CacheSeed hashes every recognised file there, so the seed moves and every pool
 * is namespaced afresh.
 *
 * !! It is a property of WHERE THE FILE IS, not of anything DisabledBundles
 * does. Move it to `var/`, or give it an extension CacheSeed does not hash, and
 * the invalidation silently stops -- nothing else would fail. So the second test
 * writes the same bytes OUTSIDE config/ and asserts the seed does NOT move: the
 * pair together says the location is the mechanism, and the failure messages say
 * which half broke.
 */
#[CoversClass(DisabledBundles::class)]
final class DisabledBundlesInvalidatesCacheTest extends TestCase
{
    private string $project;

    protected function setUp(): void
    {
        $this->project = sys_get_temp_dir() . '/coolms-seed-' . bin2hex(random_bytes(6));
        mkdir($this->project . '/config', 0o777, true);
        mkdir($this->project . '/var', 0o777, true);
        file_put_contents($this->project . '/config/services.yaml', "services:\n");
    }

    protected function tearDown(): void
    {
        // Deepest first: removing a parent before its children is a warning,
        // and this package fails on warnings.
        foreach (['/config/coolms_disabled_bundles.php', '/config/services.yaml',
                  '/var/coolms_disabled_bundles.php'] as $f) {
            if (is_file($this->project . $f)) {
                unlink($this->project . $f);
            }
        }
        foreach (['/config', '/var'] as $d) {
            if (is_dir($this->project . $d)) {
                rmdir($this->project . $d);
            }
        }
        if (is_dir($this->project)) {
            rmdir($this->project);
        }
    }

    #[Test]
    public function disablingAModuleMovesThePrefixSeed(): void
    {
        $before = CacheSeed::compute($this->project);

        (new DisabledBundles($this->project))->disable('Acme\\Probe\\ProbeBundle');

        self::assertNotSame(
            $before,
            CacheSeed::compute($this->project),
            'Disabling a module did not move framework.cache.prefix_seed, so every '
            . 'pool keeps its namespace and may still hold objects whose class has '
            . 'stopped loading. This works only because the disabled list is written '
            . 'into config/ with an extension CacheSeed hashes. If this test just '
            . 'started failing, check whether that file was moved out of config/ or '
            . 'renamed to an extension CacheSeed does not read.',
        );
    }

    #[Test]
    public function theSameBytesOutsideConfigDoNotMoveIt(): void
    {
        $before = CacheSeed::compute($this->project);

        // Byte-identical content, one directory across. If THIS moves the seed
        // the pairing above proves nothing, because the location would not be
        // what is doing the work.
        file_put_contents(
            $this->project . '/var/coolms_disabled_bundles.php',
            "<?php" . PHP_EOL . PHP_EOL . "return ['Acme\\Probe\\ProbeBundle'];" . PHP_EOL,
        );

        self::assertSame(
            $before,
            CacheSeed::compute($this->project),
            'A file outside config/ moved the cache seed, which means the sibling '
            . 'test above is not evidence that living in config/ is what invalidates '
            . 'the pools. Find what else is being hashed before trusting either.',
        );
    }
}
