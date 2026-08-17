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
 * Collection provider for `GET /api/v1/options/{source}`.
 *
 * Routes to whichever tagged service has registered `source` as its
 * key. Honours the lazy-select convention:
 *  - `?q=<term>` — case-insensitive substring narrowing
 *  - `?limit=<n>` — caps the response (registry enforces the cap as a
 *    backstop even if the provider ignores it)
 *
 * An unknown source returns 404 — defensive against typos in the FE
 * column YAML referencing a source the host hasn't registered.
 *
 * @implements ProviderInterface<OptionResource>
 */
final readonly class ListOptionsProvider implements ProviderInterface
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
            $rows = $this->registry->provide($source, '' === $q ? null : $q, $limit > 0 ? $limit : null);
        } catch (UnknownOptionSourceException $e) {
            throw new NotFoundHttpException($e->getMessage(), $e);
        }

        return array_map(
            static fn ($option): OptionResource => OptionResource::fromOption($source, $option),
            $rows,
        );
    }
}
