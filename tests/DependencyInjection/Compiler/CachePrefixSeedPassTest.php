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
