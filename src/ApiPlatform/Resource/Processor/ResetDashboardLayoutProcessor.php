<?php

declare(strict_types=1);

namespace CoolMS\CoreBundle\ApiPlatform\Resource\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use CoolMS\CoreModule\Dashboard\DashboardLayoutWriter;
use Symfony\Component\HttpFoundation\RequestStack;

use function is_string;

/**
 * `DELETE /dashboard/layout` — reset to what the modules themselves offer.
 *
 * ## Why a reset has to exist, and why it clears BOTH stores
 *
 * A layout can be saved to a YAML file or to the override table depending on
 * what the host allows, and the reader lets the row win. Without a reset,
 * "put it back how it was" means hunting for whichever copy is currently
 * winning — and an operator who deletes the file they can see would be left
 * with a dashboard the row still decides. The writer clears every store; this
 * just exposes it.
 *
 * ## 204 whether or not anything was there
 *
 * The caller asked for the dashboard to be unarranged, and afterwards it is.
 * A 404 for "there was no layout" would describe the storage rather than the
 * outcome, and would make an editor's Reset button fail in the one case where
 * the user is most likely to press it twice.
 *
 * @implements ProcessorInterface<mixed, null>
 */
final readonly class ResetDashboardLayoutProcessor implements ProcessorInterface
{
    public function __construct(
        private DashboardLayoutWriter $layouts,
        private RequestStack $requests,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): null
    {
        // From the REQUEST, not $context['filters'] — API-Platform does not
        // fill those for a Delete, so resetting a section would have cleared
        // the MAIN dashboard's layout instead of its own. Same trap as the
        // save, and silent in the same way.
        $section = $this->requests->getCurrentRequest()?->query->get('section');

        $this->layouts->reset(is_string($section) && '' !== $section ? $section : null);

        return null;
    }
}
