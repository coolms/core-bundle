<?php

declare(strict_types=1);

namespace CoolMS\CoreBundle\Tests\DependencyInjection\Compiler;

use CoolMS\CoreBundle\Cache\CacheSeed;
use CoolMS\CoreBundle\DependencyInjection\Compiler\CachePrefixSeedPass;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;

#[CoversClass(CachePrefixSeedPass::class)]
final class CachePrefixSeedPassTest extends TestCase
{
    #[Test]
    public function itSeedsTheFrameworkCachePrefix(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.project_dir', __DIR__);

        new CachePrefixSeedPass()->prepend($container);

        $configs = $container->getExtensionConfig('framework');

        self::assertSame(CacheSeed::compute(__DIR__), $configs[0]['cache']['prefix_seed']);
    }

    #[Test]
    public function theSeedIsPrependedSoApplicationConfigStillWins(): void
    {
        // Symfony merges an extension's configs in array order, later winning.
        // Prepending is what makes this a DEFAULT rather than an override: an
        // application that names its own prefix_seed keeps it.
        $container = new ContainerBuilder();
        $container->setParameter('kernel.project_dir', __DIR__);
        $container->registerExtension($this->frameworkExtension());
        $container->loadFromExtension('framework', ['cache' => ['prefix_seed' => 'chosen/by/the/app']]);

        new CachePrefixSeedPass()->prepend($container);

        $configs = $container->getExtensionConfig('framework');

        self::assertCount(2, $configs);
        self::assertSame(CacheSeed::compute(__DIR__), $configs[0]['cache']['prefix_seed']);
        self::assertSame('chosen/by/the/app', $configs[1]['cache']['prefix_seed']);
    }

    #[Test]
    public function itStillSeedsWhenTheProjectDirIsUnknown(): void
    {
        // No kernel.project_dir -- the config component drops out, the package
        // component still carries the seed. Must not throw: a bundle that
        // cannot compile is worse than a cache that is namespaced by packages
        // alone.
        $container = new ContainerBuilder();

        new CachePrefixSeedPass()->prepend($container);

        $configs = $container->getExtensionConfig('framework');

        self::assertSame(CacheSeed::compute(''), $configs[0]['cache']['prefix_seed']);
    }

    #[Test]
    public function itCarriesTheEnvironmentAndDebugFlagFromTheContainer(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.project_dir', __DIR__);
        $container->setParameter('kernel.environment', 'prod');
        $container->setParameter('kernel.debug', false);

        new CachePrefixSeedPass()->prepend($container);

        self::assertSame(
            CacheSeed::compute(__DIR__, 'prod', false),
            $container->getExtensionConfig('framework')[0]['cache']['prefix_seed'],
        );
    }

    /**
     * ⚠️ The point of the whole parameter: two environments must not land on
     * the same namespace. A pass that read neither would produce one seed here.
     */
    #[Test]
    public function twoEnvironmentsProduceDifferentSeeds(): void
    {
        self::assertNotSame($this->seedFor('dev', true), $this->seedFor('prod', false));
    }

    #[Test]
    public function theBuildIdentityIsReadFromTheEnvironment(): void
    {
        $before = $_SERVER[CachePrefixSeedPass::BUILD_ID_ENV] ?? null;

        try {
            $_SERVER[CachePrefixSeedPass::BUILD_ID_ENV] = 'sha256:from-the-environment';
            $withId = $this->seedFor('prod', false);

            unset($_SERVER[CachePrefixSeedPass::BUILD_ID_ENV]);
            $withoutId = $this->seedFor('prod', false);

            self::assertNotSame($withoutId, $withId);
            self::assertSame(
                CacheSeed::compute(__DIR__, 'prod', false, 'sha256:from-the-environment'),
                $withId,
            );
            // Absent, the pass produces exactly the un-parameterised seed.
            self::assertSame(CacheSeed::compute(__DIR__, 'prod', false), $withoutId);
        } finally {
            if (null === $before) {
                unset($_SERVER[CachePrefixSeedPass::BUILD_ID_ENV]);
            } else {
                $_SERVER[CachePrefixSeedPass::BUILD_ID_ENV] = $before;
            }
        }
    }

    /** A blank or whitespace value is "not supplied", not "supplied as empty". */
    #[Test]
    public function aBlankBuildIdentityIsTreatedAsAbsent(): void
    {
        $before = $_SERVER[CachePrefixSeedPass::BUILD_ID_ENV] ?? null;

        try {
            $_SERVER[CachePrefixSeedPass::BUILD_ID_ENV] = '   ';

            self::assertSame(CacheSeed::compute(__DIR__, 'prod', false), $this->seedFor('prod', false));
        } finally {
            if (null === $before) {
                unset($_SERVER[CachePrefixSeedPass::BUILD_ID_ENV]);
            } else {
                $_SERVER[CachePrefixSeedPass::BUILD_ID_ENV] = $before;
            }
        }
    }

    private function seedFor(string $env, bool $debug): string
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.project_dir', __DIR__);
        $container->setParameter('kernel.environment', $env);
        $container->setParameter('kernel.debug', $debug);

        new CachePrefixSeedPass()->prepend($container);

        /** @var string $seed */
        $seed = $container->getExtensionConfig('framework')[0]['cache']['prefix_seed'];

        return $seed;
    }

    /** A stand-in for FrameworkExtension -- loadFromExtension needs one registered. */
    private function frameworkExtension(): ExtensionInterface
    {
        return new class implements ExtensionInterface {
            public function load(array $configs, ContainerBuilder $container): void
            {
            }

            public function getNamespace(): string
            {
                return '';
            }

            public function getXsdValidationBasePath(): false
            {
                return false;
            }

            public function getAlias(): string
            {
                return 'framework';
            }
        };
    }
}
