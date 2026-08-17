<?php

declare(strict_types=1);

namespace CoolMS\CoreBundle\ApiPlatform\Resource\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use CoolMS\Core\Option\Exception\UnknownOptionSourceException;
use CoolMS\CoreBundle\ApiPlatform\Resource\OptionResource;
use CoolMS\CoreModule\Option\OptionSourceRegistry;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Anonymous collection provider for `GET /api/v1/public-options/{source}`.
 *
 * The firewall-public sibling of {@see ListOptionsProvider}: it serves the
 * SAME wire shape but resolves the source with `publicOnly: true`, so only a
 * {@see \CoolMS\Core\Option\PublicOptionSourceInterface} (countries,
 * timezones — explicitly opted-in static lists) is reachable. A private,
 * admin-only source returns 404 here exactly like a typo would, so the
 * public surface never reveals that a private source exists.
 *
 * Honours the same lazy-select conventions as the authenticated endpoint:
 *  - `?q=<term>` — case-insensitive substring narrowing
 *  - `?limit=<n>` — caps the response (registry enforces the cap)
 *
 * @implements ProviderInterface<OptionResource>
 */
final readonly class ListPublicOptionsProvider implements ProviderInterface
{
    public function __construct(
        private OptionSourceRegistry $registry,
        private RequestStack $requestStack,
    ) {
    }

    /**
     * @return list<OptionResource>
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $source = isset($uriVariables['source']) && is_string($uriVariables['source'])
            ? $uriVariables['source']
            : null;
        if (null === $source) {
            return [];
        }

        $request = $this->requestStack->getCurrentRequest();
        $q = null === $request ? null : trim((string) $request->query->get('q', ''));
        $limit = (int) ($request?->query->get('limit') ?? 0);

        try {
            $rows = $this->registry->provide(
                $source,
                '' === $q ? null : $q,
                $limit > 0 ? $limit : null,
                publicOnly: true,
            );
        } catch (UnknownOptionSourceException $e) {
            throw new NotFoundHttpException($e->getMessage(), $e);
        }

        return array_map(
            static fn ($option): OptionResource => OptionResource::fromOption($source, $option),
            $rows,
        );
    }
}
