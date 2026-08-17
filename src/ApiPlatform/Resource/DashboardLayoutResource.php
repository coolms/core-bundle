<?php

declare(strict_types=1);

namespace CoolMS\CoreBundle\ApiPlatform\Resource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Put;
use CoolMS\CoreBundle\ApiPlatform\Resource\DTO\DashboardLayoutRequest;
use CoolMS\CoreBundle\ApiPlatform\Resource\Processor\ResetDashboardLayoutProcessor;
use CoolMS\CoreBundle\ApiPlatform\Resource\Processor\SaveDashboardLayoutProcessor;

/**
 * `PUT|DELETE /api/v1/dashboard/layout` — arranging the dashboard.
 *
 * ## Why `ROLE_ADMIN` when reading the catalogue only needs a login
 *
 * Because a layout is SHARED. It is written to `config/modules` (git-tracked,
 * reviewed, deployed) or to the config-override table, and either way the next
 * person to open the dashboard sees what this request decided. A per-user
 * arrangement would be a preference and would belong in the profile store; this
 * is not that, and the role is what says so.
 *
 * ## PUT, not PATCH
 *
 * A layout is an ORDER. Merge-patching a list has no meaning — there is no key
 * to merge on and no way to express "this one moved" — so the whole arrangement
 * is submitted each time and replaces what was there. It also sidesteps
 * API-Platform's merge-patch content type, which a PATCH here would require.
 *
 * ## No GET
 *
 * `GET /dashboard/widgets` already returns the catalogue in its saved order at
 * its saved widths, which is what an editor needs to draw. A second endpoint
 * returning the raw placements would let the two disagree, and the raw form is
 * the less useful of the two: it says nothing about widgets the layout has not
 * mentioned yet.
 */
#[ApiResource(
    shortName: 'DashboardLayout',
    description: 'The saved arrangement of the dashboard: order, widths, and which cards are hidden.',
    operations: [
        new Put(
            uriTemplate: '/dashboard/layout',
            security: "is_granted('ROLE_ADMIN')",
            // No provider: there is nothing to read first. The processor resolves
            // the dashboard from the route and the viewer from the token, never
            // from the body.
            read: false,
            input: DashboardLayoutRequest::class,
            output: self::class,
            name: 'dashboard_layout_save',
            processor: SaveDashboardLayoutProcessor::class,
        ),
        new Delete(
            uriTemplate: '/dashboard/layout',
            status: 204,
            security: "is_granted('ROLE_ADMIN')",
            read: false,
            output: false,
            name: 'dashboard_layout_reset',
            processor: ResetDashboardLayoutProcessor::class,
        ),
    ],
)]
final class DashboardLayoutResource
{
    public function __construct(
        /**
         * Where the layout was stored — an absolute path, or `db://dashboard/main`.
         *
         * Returned because the answer is not obvious and someone will ask: a
         * developer expects to find it in their working copy and commit it, and
         * on a host with a read-only `config/` it is a database row instead.
         * Saying which turns "why is it not in my diff" into information the
         * response already gave.
         */
        public string $storedAt = '',
    ) {
    }
}
