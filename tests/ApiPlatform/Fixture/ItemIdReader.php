<?php

declare(strict_types=1);

namespace CoolMS\Core\Bundle\Tests\ApiPlatform\Fixture;

use CoolMS\Core\Bundle\ApiPlatform\UriVariableUuidExtractorTrait;
use Symfony\Component\Uid\Uuid;

/**
 * Reads an item's UUID from API Platform URI variables, written the way a
 * consumer writes it: a state processor calls the required variant and a state
 * provider the optional one. With no key named, both read the trait's default.
 */
final readonly class ItemIdReader
{
    use UriVariableUuidExtractorTrait;

    /**
     * @param array<string, mixed> $uriVariables
     */
    public function forProcessor(array $uriVariables, ?string $key = null): Uuid
    {
        return null === $key ? $this->extractUuid($uriVariables) : $this->extractUuid($uriVariables, $key);
    }

    /**
     * @param array<string, mixed> $uriVariables
     */
    public function forProvider(array $uriVariables, ?string $key = null): ?Uuid
    {
        return null === $key ? $this->tryExtractUuid($uriVariables) : $this->tryExtractUuid($uriVariables, $key);
    }
}
