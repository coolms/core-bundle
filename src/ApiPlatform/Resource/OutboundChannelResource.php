<?php

declare(strict_types=1);

namespace CoolMS\CoreBundle\ApiPlatform\Resource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use CoolMS\CoreBundle\ApiPlatform\Resource\Provider\ListOutboundChannelsProvider;

/**
 * The ENABLED outbound channels and the settings each one needs.
 *
 * ## Why this exists next to `core.outbound_channels`
 *
 * The option source answers "what may I pick" — `{value, label}`, which is all
 * a dropdown needs. This answers "and what must I then fill in", which a
 * dropdown cannot express. Overloading the option wire shape with a field list
 * would have made every other picker in the platform carry a field it never
 * uses, so the richer question gets its own endpoint and the option source
 * stays what it is.
 *
 * ## The gap it closes
 *
 * The section-properties dialog used to hard-code one "Webhook URL" input, so a
 * channel needing anything else -- a bot token and a chat id, say -- could be
 * selected but never configured, and skipped silently at publish time. The
 * dialog now renders whatever each channel declares, which means a channel from
 * a module the front end has never heard of gets a working editor.
 *
 * Only ENABLED channels are listed: the provider reads the same registry the
 * picker and the distribution write use, so a channel switched off in
 * configuration is absent here too rather than offering fields nobody can use.
 *
 * **Secrets are declared, never returned.** A field marked `secret` tells the
 * admin to render a credential input; its VALUE is resolved through the secret
 * store and no read endpoint echoes it back.
 */
#[ApiResource(
    shortName: 'OutboundChannel',
    description: 'Enabled outbound distribution channels and their per-section configuration fields.',
    operations: [
        new GetCollection(
            uriTemplate: '/outbound-channels',
            name: 'core_outbound_channels_list',
            provider: ListOutboundChannelsProvider::class,
            security: "is_granted('IS_AUTHENTICATED_FULLY')",
        ),
    ],
)]
final class OutboundChannelResource
{
    /**
     * @param list<array<string, mixed>> $fields declared config fields, in presentation order
     */
    public function __construct(
        /** The channel id — what a section stores in `enabledChannels`. */
        #[ApiProperty(identifier: true)]
        public string $id = '',
        public string $label = '',
        public array $fields = [],
    ) {
    }
}
