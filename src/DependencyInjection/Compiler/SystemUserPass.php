<?php

declare(strict_types=1);

namespace CoolMS\CoreBundle\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Collects every service tagged `coolms.system_user` and injects them as the
 * `$providers` argument of whatever service claims the seeder tag.
 *
 * The seeder is found by TAG, not by class name. It previously named
 * the Identity module's seeder class in a constant, which meant this package
 * could not be published without the application it was extracted from -- and
 * an installation without the Identity module would still carry the reference.
 * Whichever module owns system users tags its seeder and this pass fills it.
 */
final class SystemUserPass implements CompilerPassInterface
{
    public const string SEEDER_TAG = 'coolms.system_user.seeder';

    private const string PROVIDER_TAG = 'coolms.system_user';

    public function process(ContainerBuilder $container): void
    {
        $seeders = $container->findTaggedServiceIds(self::SEEDER_TAG);
        if ([] === $seeders) {
            // No module owns system users in this installation. The providers,
            // if any, simply go uncollected -- not an error.
            return;
        }

        $refs = array_map(
            static fn (string $id) => new Reference($id),
            array_keys($container->findTaggedServiceIds(self::PROVIDER_TAG)),
        );

        foreach (array_keys($seeders) as $id) {
            $container->getDefinition($id)->setArgument('$providers', $refs);
        }
    }
}
