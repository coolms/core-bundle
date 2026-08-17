<?php

declare(strict_types=1);

namespace CoolMS\CoreBundle\ApiPlatform\Resource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use CoolMS\Core\Option\Option;
use CoolMS\CoreBundle\ApiPlatform\Resource\Provider\GetOptionProvider;
use CoolMS\CoreBundle\ApiPlatform\Resource\Provider\ListOptionsProvider;
use CoolMS\CoreBundle\ApiPlatform\Resource\Provider\ListPublicOptionsProvider;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * Generic options endpoint that feeds platform-wide selects from any
 * {@see \CoolMS\Core\Option\OptionSourceProviderInterface} tagged
 * `coolms.option.source`. The `{source}` URI variable is the
 * provider's `key()` — e.g. `calendar.timezones`, `scheduler.handlers`.
 *
 * **Wire shape.** A list of `{value, label, group?, description?}`
 * rows. The FE picker:
 *  - stores `value` in the bound model;
 *  - shows `label`;
 *  - bins by `group` when present (renders headings);
 *  - shows `description` as a hint under the option.
 *
 * Auth: IS_AUTHENTICATED_FULLY. Individual sources can self-gate by
 * returning an empty list / throwing inside `provide()` — the registry
 * doesn't intervene.
 *
 * **Public surface.** A source that additionally implements
 * {@see \CoolMS\Core\Option\PublicOptionSourceInterface} (static,
 * non-sensitive reference lists — ISO countries, IANA timezones) is ALSO
 * reachable anonymously at `GET /public-options/{source}` so a public SSR
 * form's api-data-source select can populate itself without a Bearer token.
 * Non-public keys 404 on that surface (never confirming they exist); the
 * authenticated `/options/{source}` continues to serve every source.
 *
 * The `Get` op exists primarily so API Platform's Hydra serializer
 * can mint each collection row's `@id` IRI; consumers typically only
 * call the collection.
 */
#[ApiResource(
    shortName: 'Option',
    operations: [
        new GetCollection(
            uriTemplate: '/options/{source}',
            uriVariables: ['source'],
            requirements: ['source' => '[a-z][a-z0-9._-]*'],
            security: "is_granted('IS_AUTHENTICATED_FULLY')",
            name: 'options_list',
            provider: ListOptionsProvider::class,
        ),
        new GetCollection(
            uriTemplate: '/public-options/{source}',
            uriVariables: ['source'],
            requirements: ['source' => '[a-z][a-z0-9._-]*'],
            security: "is_granted('PUBLIC_ACCESS')",
            name: 'options_public_list',
            provider: ListPublicOptionsProvider::class,
        ),
        new Get(
            uriTemplate: '/options/{source}/{value}',
            uriVariables: ['source', 'value'],
            requirements: [
                'source' => '[a-z][a-z0-9._-]*',
                // `.*`, not `.+`: a source may legitimately offer an EMPTY value
                // (a default list can legitimately be stored as `''`), and
                // this op exists to mint each COLLECTION row's `@id`. With `.+`
                // the Hydra serializer cannot generate an IRI for that row and
                // the whole collection 500s, which is a hard failure caused
                // entirely by a route nobody calls.
                'value' => '.*',
            ],
            security: "is_granted('IS_AUTHENTICATED_FULLY')",
            name: 'options_get',
            provider: GetOptionProvider::class,
        ),
    ],
    normalizationContext: ['groups' => ['core:option:read']],
)]
final class OptionResource
{
    public function __construct(
        /**
         * Source key (provider's `key()`, e.g. `calendar.timezones`).
         * Required as a resource property so API Platform can fill the
         * `{source}` slot when minting each collection row's `@id` IRI
         * from the `Get /options/{source}/{value}` URI template — without
         * it the Hydra serializer throws "Unable to generate an IRI".
         * Not part of the serialization groups (the FE doesn't display
         * it; it only consumes value/label/group/description).
         */
        #[ApiProperty(identifier: true)]
        public readonly string $source,
        /**
         * Per-row identifier within `$source`. The `Get` op's
         * `{value}` URI variable resolves to this property; this is
         * also the token the FE picker stores in the bound model.
         */
        #[ApiProperty(identifier: true)]
        #[Groups(['core:option:read'])]
        public readonly string $value,
        #[Groups(['core:option:read'])]
        public readonly string $label,
        #[Groups(['core:option:read'])]
        public readonly ?string $group = null,
        #[Groups(['core:option:read'])]
        public readonly ?string $description = null,
    ) {
    }

    public static function fromOption(string $source, Option $option): self
    {
        return new self(
            source: $source,
            value: $option->value,
            label: $option->label,
            group: $option->group,
            description: $option->description,
        );
    }
}
