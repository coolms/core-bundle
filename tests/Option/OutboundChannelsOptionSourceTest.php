<?php

declare(strict_types=1);

namespace CoolMS\CoreBundle\Tests\Option;

use CoolMS\Core\Channel\OutboundChannelInterface;
use CoolMS\Core\Channel\OutboundChannelRegistryInterface;
use CoolMS\Core\Option\Option;
use CoolMS\CoreBundle\Option\OutboundChannelsOptionSource;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_keys;
use function array_map;
use function is_int;

/**
 * F3 — the outbound-channel select datasource: every registered channel becomes
 * an {@see Option} (value = `channelId()`, label = `label()`), sorted by label,
 * with substring query narrowing + a limit. Adding a channel needs no change here.
 */
#[CoversClass(OutboundChannelsOptionSource::class)]
final class OutboundChannelsOptionSourceTest extends TestCase
{
    #[Test]
    public function keyIsTheDotNamespacedIdentifier(): void
    {
        self::assertSame('core.outbound_channels', $this->source(['rss'])->key());
    }

    #[Test]
    public function eachRegisteredChannelBecomesAnOptionSortedByLabel(): void
    {
        // Registered in a non-alphabetical order; output must be label-sorted.
        $options = $this->source([
            'webhook' => 'Webhook',
            'rss' => 'RSS feed',
        ])->provide();

        self::assertSame(['rss', 'webhook'], array_map(static fn (Option $o): string => $o->value, $options));
        self::assertSame(['RSS feed', 'Webhook'], array_map(static fn (Option $o): string => $o->label, $options));
    }

    #[Test]
    public function theQueryNarrowsBySubstringOnIdAndLabel(): void
    {
        $options = $this->source(['rss' => 'RSS feed', 'webhook' => 'Webhook'])->provide('hook');

        self::assertCount(1, $options);
        self::assertSame('webhook', $options[0]->value);
    }

    #[Test]
    public function theLimitCapsTheList(): void
    {
        $options = $this->source(['rss' => 'RSS feed', 'webhook' => 'Webhook'])->provide(null, 1);

        self::assertCount(1, $options);
    }

    /**
     * @param array<string, string>|list<string> $channels id=>label, or a list of ids (label = id)
     */
    private function source(array $channels): OutboundChannelsOptionSource
    {
        $labels = [];
        foreach ($channels as $key => $value) {
            if (is_int($key)) {
                $labels[$value] = $value;
            } else {
                $labels[$key] = $value;
            }
        }

        $registry = $this->createStub(OutboundChannelRegistryInterface::class);
        $registry->method('channelIds')->willReturn(array_keys($labels));
        $registry->method('get')->willReturnCallback(
            function (string $id) use ($labels): ?OutboundChannelInterface {
                if (!isset($labels[$id])) {
                    return null;
                }
                $channel = $this->createStub(OutboundChannelInterface::class);
                $channel->method('channelId')->willReturn($id);
                $channel->method('label')->willReturn($labels[$id]);

                return $channel;
            },
        );

        return new OutboundChannelsOptionSource($registry);
    }
}
