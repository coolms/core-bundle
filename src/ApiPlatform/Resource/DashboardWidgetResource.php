<?php

declare(strict_types=1);

namespace CoolMS\CoreBundle\ApiPlatform\Resource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use CoolMS\CoreBundle\ApiPlatform\Provider\DashboardWidgetProvider;

/**
 * `GET /api/v1/dashboard/widgets` — what this viewer's dashboard may show,
 * assembled from every installed module.
 *
 * Core contributes nothing to it and does not know what any entry means. Each
 * one carries the endpoint the client GETs for that widget's own data, so the
 * dashboard stays generic and a module's figures keep their own security,
 * caching and refresh.
 *
 * ## Why ONE Get wrapping a list, rather than a GetCollection
 *
 * Same shape as `/vfs/file-kinds` and `/document/format-info`, for the same
 * reason: a collection needs a per-item IRI and these have no item route to
 * point at — that first cut returns 500 with "Unable to generate an IRI". They
 * are catalogue entries, not addressable things.
 *
 * ⚠️ Unlike the file-kind catalogue this one IS filtered per viewer, and the
 * difference is deliberate. That one describes CAPABILITY, where hiding an
 * entry would make an unwritable folder look like a platform with fewer
 * features. A widget is a piece of CONTENT: one the viewer can never load only
 * ever renders an error, so it is not offered. The filter is cosmetic even so —
 * each widget's own endpoint remains the authority on its data.
 */
#[ApiResource(
    shortName: 'DashboardWidget',
    description: "The widgets installed modules offer for the current user's dashboard.",
    operations: [
        new Get(
            uriTemplate: '/dashboard/widgets',
            security: "is_granted('IS_AUTHENTICATED_FULLY')",
            provider: DashboardWidgetProvider::class,
        ),
    ],
)]
final class DashboardWidgetResource
{
    /**
     * @param list<DashboardWidgetInfo> $widgets  in the order the providers offered them
     * @param list<string>              $sections every section this viewer has
     *                                            widgets for, whichever
     *                                            dashboard was asked for.
     *                                            Carried here rather than on a
     *                                            route of its own: a client
     *                                            needs it to draw the switcher,
     *                                            and a second endpoint could
     *                                            disagree with this one. A
     *                                            section with no widgets never
     *                                            appears — an empty tab
     *                                            promises something and then
     *                                            does not have it
     */
    public function __construct(
        public array $widgets = [],
        public array $sections = [],
    ) {
    }
}
