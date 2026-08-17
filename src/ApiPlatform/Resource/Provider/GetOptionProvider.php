<?php

declare(strict_types=1);

namespace CoolMS\CoreBundle\ApiPlatform\Resource\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use CoolMS\Core\Option\Exception\UnknownOptionSourceException;
use CoolMS\CoreBundle\ApiPlatform\Resource\OptionResource;
use CoolMS\CoreModule\Option\OptionSourceRegistry;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Single-row provider for `GET /api/v1/options/{source}/{value}`.
 *
 * Exists primarily so API Platform's Hydra serializer can mint each
 * collection row's `@id` IRI without 500-ing. We delegate to the
 * registry's `provide()` call (no `?q=` so we get the unfiltered
 * list) and pick out the matching `value`. Could be a lot more
 * efficient if the OptionSourceProviderInterface gained a `find($value)`
 * method, but for the small bounded lists we serve today (timezones,
 * handlers, etc.) the linear scan is fine.
 *
 * @implements ProviderInterface<OptionResource>
 */
final readonly class GetOptionProvider implements ProviderInterface
{
    public function __construct(
        private OptionSourceRegistry $registry,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ?OptionResource
    {
        $source = isset($uriVariables['source']) && is_string($uriVariables['source'])
            ? $uriVariables['source']
            : null;
        $value = isset($uriVariables['value']) && is_string($uriVariables['value'])
            ? $uriVariables['value']
            : null;
        if (null === $source || null === $value) {
            return null;
        }

        try {
            $rows = $this->registry->provide($source);
        } catch (UnknownOptionSourceException $e) {
            throw new NotFoundHttpException($e->getMessage(), $e);
        }

        foreach ($rows as $option) {
            if ($option->value === $value) {
                return OptionResource::fromOption($source, $option);
            }
        }

        return null;
    }
}
