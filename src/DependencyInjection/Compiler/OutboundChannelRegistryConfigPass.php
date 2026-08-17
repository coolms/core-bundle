<?php

declare(strict_types=1);

namespace CoolMS\CoreBundle\DependencyInjection\Compiler;

use CoolMS\CoreModule\Channel\OutboundChannelRegistry;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Binds `coolms_core.outbound_channels` onto {@see OutboundChannelRegistry}'s
 * `$channelConfig` argument — the per-channel enable map that decides
 * which channels the admin picker offers and the distribution write accepts.
 *
 * **Why a compiler pass and not `#[Autowire(param:)]`.** Two reasons, and the
 * second is the load-bearing one:
 *
 *  1. Modules are heading for `CoolMS\` + `vendor/`, so configurable wiring
 *     must be discoverable from configuration rather than hidden in an
 *     attribute on a class an operator may never open.
 *  2. `config/services.yaml`'s `App\:` prototype scan re-registers this class
 *     with `autowire: true` BEFORE compiler passes run. An argument asserted
 *     from the Extension would be silently dropped; asserted here it wins.
 *     Same reason the realtime module's channel-policy binding pass
 *     exists, and the same reason the registry pins its tagged iterator with
 *     `#[AutowireIterator]` on the constructor instead.
 *
 * Absent config binds an empty map, which the registry reads as "every
 * installed channel is enabled" — the default that keeps a fresh install and
 * any site that never touches this config working exactly as before.
 */
final class OutboundChannelRegistryConfigPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->has(OutboundChannelRegistry::class)) {
            return;
        }

        $config = $container->hasParameter('coolms_core.outbound_channels')
            ? $container->getParameter('coolms_core.outbound_channels')
            : [];

        $container->findDefinition(OutboundChannelRegistry::class)
            ->setArgument('$channelConfig', is_array($config) ? $config : []);
    }
}
