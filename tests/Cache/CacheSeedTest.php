<?php

declare(strict_types=1);

namespace CoolMS\CoreBundle\Tests\Cache;

use CoolMS\CoreBundle\Cache\CacheSeed;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

use function array_reverse;
use function hash;
use function sys_get_temp_dir;
use function uniqid;

/**
 * The seed that namespaces every cache pool.
 *
 * Two properties matter and both are tested here rather than assumed:
 *
 *  - it MOVES when the installation changes (a changed or added config file),
 *    or it protects nothing;
 *  - it is DETERMINISTIC -- two identical trees seed identically regardless of
 *    where they live or what order their files were written in. A seed that
 *    varied per process would give each FPM worker its own private cache.
 */
#[CoversClass(CacheSeed::class)]
final class CacheSeedTest extends TestCase
{
    private Filesystem $fs;

    /** @var list<string> */
    private array $dirs = [];

    #[Test]
    public function theSameTreeSeedsTheSameValueTwice(): void
    {
        $project = $this->project(['config/packages/a.yaml' => 'framework: ~']);

        self::assertSame(CacheSeed::compute($project), CacheSeed::compute($project));
    }

    #[Test]
    public function aChangedConfigFileMovesTheSeed(): void
    {
        $project = $this->project(['config/packages/a.yaml' => 'framework: ~']);
        $before = CacheSeed::compute($project);

        $this->fs->dumpFile($project . '/config/packages/a.yaml', 'framework: {http_method_override: true}');

        self::assertNotSame($before, CacheSeed::compute($project));
    }

    #[Test]
    public function anAddedConfigFileMovesTheSeed(): void
    {
        $project = $this->project(['config/packages/a.yaml' => 'framework: ~']);
        $before = CacheSeed::compute($project);

        $this->fs->dumpFile($project . '/config/packages/b.yaml', 'framework: ~');

        self::assertNotSame($before, CacheSeed::compute($project));
    }

    #[Test]
    public function aRenamedConfigFileMovesTheSeed(): void
    {
        // Same bytes, different path -- the path is part of the material, so a
        // module config moved between directories is a different installation.
        $project = $this->project(['config/packages/a.yaml' => 'framework: ~']);
        $before = CacheSeed::compute($project);

        $this->fs->rename($project . '/config/packages/a.yaml', $project . '/config/packages/z.yaml');

        self::assertNotSame($before, CacheSeed::compute($project));
    }

    #[Test]
    public function aFileThatIsNotConfigDoesNotMoveTheSeed(): void
    {
        $project = $this->project(['config/packages/a.yaml' => 'framework: ~']);
        $before = CacheSeed::compute($project);

        $this->fs->dumpFile($project . '/config/README.md', 'not configuration');

        self::assertSame($before, CacheSeed::compute($project));
    }

    #[Test]
    public function twoIdenticalTreesInDifferentPlacesSeedIdentically(): void
    {
        // Paths go into the material relative to the project dir, and the file
        // list is sorted -- so neither the checkout location nor the order the
        // files were written in can reach the seed.
        $files = [
            'config/packages/a.yaml' => 'framework: ~',
            'config/modules/x/forms/f.yaml' => 'form: ~',
            'config/services.php' => '<?php return [];',
        ];

        $first = $this->project($files);
        $second = $this->project(array_reverse($files, preserve_keys: true));

        self::assertSame(CacheSeed::compute($first), CacheSeed::compute($second));
    }

    #[Test]
    public function aProjectWithNoConfigDirectoryStillSeedsFromThePackageSet(): void
    {
        $project = $this->project([]);

        // What the seed would be if BOTH components contributed nothing: the
        // hash of the bare joiner between them. Asserting the seed is merely
        // non-empty would pass on that, and an installation whose seed never
        // moves is the whole failure this class exists to prevent.
        $empty = 'coolms.' . hash('xxh128', "\n--\n");

        self::assertNotSame($empty, CacheSeed::compute($project));
    }

    #[Test]
    public function theSeedIsPrefixedSoAPoolDirectoryIsRecognisable(): void
    {
        self::assertStringStartsWith('coolms.', CacheSeed::compute($this->project([])));
    }

    protected function setUp(): void
    {
        $this->fs = new Filesystem();
    }

    protected function tearDown(): void
    {
        $this->fs->remove($this->dirs);
    }

    /**
     * @param array<string, string> $files relative path => contents
     */
    private function project(array $files): string
    {
        $dir = sys_get_temp_dir() . '/coolms-cache-seed-' . uniqid('', true);
        $this->fs->mkdir($dir);
        $this->dirs[] = $dir;

        foreach ($files as $relative => $contents) {
            $this->fs->dumpFile($dir . '/' . $relative, $contents);
        }

        return $dir;
    }
}
