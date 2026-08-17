<?php

declare(strict_types=1);

namespace CoolMS\CoreBundle\ApiPlatform;

use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * Resolves a UUID from API Platform uriVariables.
 *
 * Two variants:
 *   extractUuid() -- for processors; throws 400 when the key is absent
 *   tryExtractUuid() -- for providers; returns null when the key is absent
 *                       (API Platform interprets null as "item not found")
 *
 * Both throw 404 when the value is present but is not a valid UUID string.
 */
trait UriVariableUuidExtractorTrait
{
    /**
     * Extract a required UUID from uriVariables.
     * Use in processors and delete handlers where a missing ID is a client error.
     *
     * @param array<string, mixed> $uriVariables
     */
    private function extractUuid(array $uriVariables, string $key = 'id'): Uuid
    {
        $id = $uriVariables[$key] ?? null;
        if (null === $id) {
            throw new BadRequestHttpException(sprintf('Missing %s.', $key));
        }
        try {
            return Uuid::fromString((string) $id);
        } catch (InvalidArgumentException) {
            throw new NotFoundHttpException('Invalid identifier.');
        }
    }

    /**
     * Extract an optional UUID from uriVariables.
     * Use in providers where a missing ID means "return null" (GetCollection path).
     *
     * @param array<string, mixed> $uriVariables
     */
    private function tryExtractUuid(array $uriVariables, string $key = 'id'): ?Uuid
    {
        $id = $uriVariables[$key] ?? null;
        if (null === $id) {
            return null;
        }
        try {
            return Uuid::fromString((string) $id);
        } catch (InvalidArgumentException) {
            throw new NotFoundHttpException('Invalid identifier.');
        }
    }
}
