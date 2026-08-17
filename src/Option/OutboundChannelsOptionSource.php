<?php

declare(strict_types=1);

namespace CoolMS\CoreBundle\Option;

use CoolMS\Core\Channel\OutboundChannelRegistryInterface;
use CoolMS\Core\Option\Option;
use CoolMS\Core\Option\OptionSourceProviderInterface;

use function array_slice;
use function count;
use function mb_strtolower;
use function str_contains;
use function strcmp;
use function trim;
use function usort;

/**
 * F3 — advertises every registered outbound channel (`rss`, `webhook`, …) as a
 * select datasource (key `core.outbound_channels`), so admin UIs — e.g. the
 * per-section content-distribution picker — list the AVAILABLE channels
 * dynamically instead of hard-coding ids. Adding a channel (a new
 * `coolms.outbound_channel`-tagged class) automatically makes it selectable
 * here, with no FE change.
 *
 * The persisted `value` is the channel's `channelId()` (what the section stores
 * in `ContentCollection.enabledChannels`); the `label` is the channel's own
 * `label()`. Authenticated-only (channel config is an admin concern), so NOT a
 * {@see \CoolMS\Core\Option\PublicOptionSourceInterface} — reachable at
 * `GET /api/v1/options/core.outbound_channels`.
 */
final readonly class OutboundChannelsOptionSource implements OptionSourceProviderInterface
{
    public const string KEY = 'core.outbound_channels';

    public function __construct(
        private OutboundChannelRegistryInterface $channels,
    ) {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function provide(?string $query = null, ?int $limit = null): array
    {
        $needle = null === $query ? '' : mb_strtolower(trim($query));

        $rows = [];
        foreach ($this->channels->channelIds() as $id) {
            $channel = $this->channels->get($id);
            $label = null !== $channel ? $channel->label() : $id;
            if ('' !== $needle
                && !str_contains(mb_strtolower($id), $needle)
                && !str_contains(mb_strtolower($label), $needle)
            ) {
                continue;
            }
            $rows[] = new Option(value: $id, label: $label);
        }

        usort($rows, static fn (Option $a, Option $b): int => strcmp($a->label, $b->label));

        if (null !== $limit && $limit > 0 && count($rows) > $limit) {
            $rows = array_slice($rows, 0, $limit);
        }

        return $rows;
    }
}
