<?php

declare(strict_types=1);

namespace CoolMS\Core\Bundle\DependencyInjection\Compiler;

use CoolMS\Core\Bundle\Outbox\CachedRelayHeartbeat;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

use function is_string;

/**
 * Binds the configured cache pool (`core.outbox.heartbeat_pool`) onto
 * {@see CachedRelayHeartbeat}'s `$pool` argument.
 *
 * A compiler pass for the reason OutboundChannelRegistryConfigPass gives:
 * the application's services scan re-registers the class autowired after
 * the extension has run, so an argument asserted in the extension is dropped
 * on the floor, and an argument asserted here wins. Without this pass the
 * autowired `CacheItemPoolInterface` resolves to `cache.app` -- a filesystem
 * pool in the default install, which the relay's container and the doctor's
 * do not share, so the doctor would read "no heartbeat" from a relay that is
 * beating every five seconds.
 */
final class RelayHeartbeatPoolPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->has(CachedRelayHeartbeat::class) || !$container->hasParameter('coolms_core.outbox.heartbeat_pool')) {
            return;
        }
        $pool = $container->getParameter('coolms_core.outbox.heartbeat_pool');
        if (!is_string($pool) || '' === $pool) {
            return;
        }
        $container->findDefinition(CachedRelayHeartbeat::class)
            ->setArgument('$pool', new Reference($pool));
    }
}
