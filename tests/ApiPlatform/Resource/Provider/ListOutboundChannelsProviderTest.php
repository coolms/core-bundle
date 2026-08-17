<?php

declare(strict_types=1);

namespace CoolMS\CoreBundle\Tests\ApiPlatform\Resource\Provider;

use ApiPlatform\Metadata\GetCollection;
use CoolMS\Core\Channel\ChannelConfigField;
use CoolMS\Core\Channel\ConfigurableChannelInterface;
use CoolMS\Core\Channel\DeliveryResult;
use CoolMS\Core\Channel\OutboundChannelInterface;
use CoolMS\Core\Channel\OutboundMessage;
use CoolMS\CoreBundle\ApiPlatform\Resource\OutboundChannelResource;
use CoolMS\CoreBundle\ApiPlatform\Resource\Provider\ListOutboundChannelsProvider;
use CoolMS\CoreModule\Channel\OutboundChannelRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_column;
use function array_map;

/**
 * Pins what `GET /outbound-channels` promises the section-properties
 * dialog: the declared fields, an empty list for a channel that needs nothing,
 * and — the one that matters — that a DISABLED channel contributes nothing.
 *
 * The last case is the point of the endpoint existing at all. The dialog renders
 * a config box per listed channel, so a disabled channel leaking in would put an
 * editable form on screen for a transport the publish trigger will never call.
 */
#[CoversClass(ListOutboundChannelsProvider::class)]
#[CoversClass(OutboundChannelResource::class)]
final class ListOutboundChannelsProviderTest extends TestCase
{
    #[Test]
    public function declaresTheFieldsEachChannelAsksFor(): void
    {
        $provider = $this->provider([
            $this->configurable('chat', 'Chat', [
                new ChannelConfigField(key: 'botToken', label: 'Bot token secret', type: ChannelConfigField::TYPE_SECRET_REF, required: true),
                new ChannelConfigField(key: 'roomId', label: 'Room', required: true, placeholder: '@myroom'),
            ]),
        ]);

        $rows = $provider->provide(new GetCollection());

        self::assertCount(1, $rows);
        self::assertSame('chat', $rows[0]->id);
        self::assertSame('Chat', $rows[0]->label);
        self::assertSame(['botToken', 'roomId'], array_column($rows[0]->fields, 'key'));
        // The admin needs the TYPE to render a reference differently from a
        // value; nothing in the wire shape can carry a credential.
        self::assertSame(ChannelConfigField::TYPE_SECRET_REF, $rows[0]->fields[0]['type']);
        self::assertSame('text', $rows[0]->fields[1]['type']);
        self::assertSame('@myroom', $rows[0]->fields[1]['placeholder']);
    }

    /**
     * `rss` is the real case: it derives everything from the section it
     * announces, so it implements no config interface. The dialog must still
     * list it (it IS selectable) with nothing to fill in.
     */
    #[Test]
    public function aChannelNeedingNoConfigIsListedWithNoFields(): void
    {
        $provider = $this->provider([$this->plain('rss', 'RSS feed')]);

        $rows = $provider->provide(new GetCollection());

        self::assertCount(1, $rows);
        self::assertSame('rss', $rows[0]->id);
        self::assertSame([], $rows[0]->fields);
    }

    #[Test]
    public function aDisabledChannelIsNotListed(): void
    {
        $provider = $this->provider(
            [
                $this->plain('rss', 'RSS feed'),
                $this->configurable('chat', 'Chat', [new ChannelConfigField(key: 'roomId', label: 'Room')]),
            ],
            ['chat' => ['enabled' => false]],
        );

        $rows = $provider->provide(new GetCollection());

        self::assertSame(['rss'], array_map(static fn (OutboundChannelResource $r): string => $r->id, $rows));
    }

    #[Test]
    public function rowsAreOrderedByLabel(): void
    {
        $provider = $this->provider([
            $this->plain('webhook', 'Webhook'),
            $this->plain('rss', 'RSS feed'),
            $this->plain('email', 'Email'),
        ]);

        $rows = $provider->provide(new GetCollection());

        self::assertSame(
            ['Email', 'RSS feed', 'Webhook'],
            array_map(static fn (OutboundChannelResource $r): string => $r->label, $rows),
        );
    }

    /**
     * @param list<OutboundChannelInterface>     $channels
     * @param array<string, array<string, bool>> $config
     */
    private function provider(array $channels, array $config = []): ListOutboundChannelsProvider
    {
        return new ListOutboundChannelsProvider(new OutboundChannelRegistry($channels, $config));
    }

    private function plain(string $id, string $label): OutboundChannelInterface
    {
        return new class($id, $label) implements OutboundChannelInterface {
            public function __construct(private readonly string $id, private readonly string $label)
            {
            }

            public function channelId(): string
            {
                return $this->id;
            }

            public function label(): string
            {
                return $this->label;
            }

            public function deliver(OutboundMessage $message, array $config): DeliveryResult
            {
                return DeliveryResult::delivered($this->id);
            }
        };
    }

    /**
     * @param list<ChannelConfigField> $fields
     */
    private function configurable(string $id, string $label, array $fields): ConfigurableChannelInterface
    {
        return new class($id, $label, $fields) implements ConfigurableChannelInterface {
            /**
             * @param list<ChannelConfigField> $fields
             */
            public function __construct(
                private readonly string $id,
                private readonly string $label,
                private readonly array $fields,
            ) {
            }

            public function channelId(): string
            {
                return $this->id;
            }

            public function label(): string
            {
                return $this->label;
            }

            public function configFields(): array
            {
                return $this->fields;
            }

            public function deliver(OutboundMessage $message, array $config): DeliveryResult
            {
                return DeliveryResult::delivered($this->id);
            }
        };
    }
}
