<?php

declare(strict_types=1);

namespace CoolMS\CoreBundle\ApiPlatform\Resource\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use CoolMS\Core\Channel\ChannelConfigField;
use CoolMS\Core\Channel\ConfigurableChannelInterface;
use CoolMS\Core\Channel\OutboundChannelRegistryInterface;
use CoolMS\CoreBundle\ApiPlatform\Resource\OutboundChannelResource;

use function array_map;
use function usort;

/**
 * Serves `GET /api/v1/outbound-channels`: every ENABLED channel with
 * the settings it declares.
 *
 * Reads `channelIds()`, which is the gated list — so a channel switched
 * off in configuration is absent here exactly as it is absent from the picker
 * and rejected by the distribution write. One source of truth for "which
 * channels exist for this install", three consumers.
 *
 * A channel that does not implement {@see ConfigurableChannelInterface} needs
 * nothing configured and reports an empty field list; `rss` is the example —
 * it derives everything from the section it announces.
 *
 * @implements ProviderInterface<OutboundChannelResource>
 */
final readonly class ListOutboundChannelsProvider implements ProviderInterface
{
    public function __construct(
        private OutboundChannelRegistryInterface $channels,
    ) {
    }

    /**
     * @return list<OutboundChannelResource>
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $rows = [];
        foreach ($this->channels->channelIds() as $id) {
            $channel = $this->channels->get($id);
            if (null === $channel) {
                continue;
            }

            $fields = $channel instanceof ConfigurableChannelInterface
                ? array_map(static fn (ChannelConfigField $f): array => $f->toArray(), $channel->configFields())
                : [];

            $rows[] = new OutboundChannelResource(id: $id, label: $channel->label(), fields: $fields);
        }

        // Label order, matching the picker's — the two lists are read side by
        // side in the section dialog, and disagreeing orders read as a bug.
        usort($rows, static fn (OutboundChannelResource $a, OutboundChannelResource $b): int => strcmp($a->label, $b->label));

        return $rows;
    }
}
