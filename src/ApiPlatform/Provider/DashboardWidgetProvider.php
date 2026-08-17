<?php

declare(strict_types=1);

namespace CoolMS\CoreBundle\ApiPlatform\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use CoolMS\Core\Dashboard\PlacedWidget;
use CoolMS\CoreBundle\ApiPlatform\Resource\DashboardWidgetInfo;
use CoolMS\CoreBundle\ApiPlatform\Resource\DashboardWidgetResource;
use CoolMS\CoreModule\Dashboard\DashboardCatalogue;

use function array_map;
use function is_string;

/**
 * Projects the {@see DashboardCatalogue} onto the API resource.
 *
 * The ORDER and the WIDTHS on the wire are already the saved arrangement
 *: the client draws what it is handed and holds no opinion about where
 * a card goes, which is what keeps a section dashboard the same page with a
 * different catalogue.
 *
 * @implements ProviderInterface<DashboardWidgetResource>
 */
final readonly class DashboardWidgetProvider implements ProviderInterface
{
    public function __construct(
        private DashboardCatalogue $widgets,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): DashboardWidgetResource
    {
        // `?section=content` picks a section dashboard; absent or empty is the
        // main one. A query parameter rather than a path variable because this
        // is one catalogue seen from one angle, not a collection of dashboards
        // with identities of their own.
        $section = $context['filters']['section'] ?? null;
        $section = is_string($section) && '' !== $section ? $section : null;

        return new DashboardWidgetResource(array_map(
            static fn (PlacedWidget $placed): DashboardWidgetInfo => new DashboardWidgetInfo(
                id: $placed->widget->id,
                label: $placed->widget->label,
                icon: $placed->widget->icon,
                endpoint: $placed->widget->endpoint,
                valuePath: $placed->widget->valuePath,
                displayPath: $placed->widget->displayPath,
                kind: $placed->widget->kind,
                columns: $placed->widget->columns,
                group: $placed->widget->group,
                hidden: $placed->hidden,
                explicitColumns: $placed->explicitColumns,
            ),
            $this->widgets->forCurrentUser($section),
        ), $this->widgets->sectionsForCurrentUser());
    }
}
