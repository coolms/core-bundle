<?php

declare(strict_types=1);

namespace CoolMS\Core\Bundle\ApiPlatform\Resource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Put;
use CoolMS\Core\Bundle\ApiPlatform\Resource\DTO\DashboardLayoutRequest;
use CoolMS\Core\Bundle\ApiPlatform\Resource\Processor\ResetDashboardLayoutProcessor;
use CoolMS\Core\Bundle\ApiPlatform\Resource\Processor\SaveDashboardLayoutProcessor;

/**
 * `PUT|DELETE /api/v1/dashboard/layout` -- arranging one's own dashboard.
 *
 * ## The person's own, so a sign-in is all it takes
 *
 * An arrangement is the signed-in person's runtime state, kept by the host with
 * their other settings ({@see \CoolMS\Core\Application\Dashboard\PersonalDashboardLayoutsInterface}).
 * Nobody else sees it, so it needs no role. It is never written into
 * `config/modules`: the module's YAML is the default every person starts from,
 * changed in a commit, not by a drag. (Until 2026-10-18 a save rewrote that
 * file, shared by everyone -- and a person without `ROLE_ADMIN` was offered an
 * Arrange whose Save was refused.)
 *
 * ## Versioned
 *
 * A save and a reset name the version the client read (If-Match, the ETag of
 * `GET /dashboard/widgets`). One version covers all of a person's dashboards:
 * an arrangement changed since -- on another device, or another section --
 * answers 412 and nothing is written. The new version comes back as the ETag.
 * An installation that keeps no personal arrangements answers 501, and its
 * catalogue says `canArrange: false` so no client offers the action.
 *
 * ## PUT, not PATCH
 *
 * A layout is an ORDER. Merge-patching a list has no meaning -- there is no key
 * to merge on and no way to express "this one moved" -- so the whole arrangement
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
    description: "The signed-in person's own arrangement of a dashboard: order, widths, and which cards are hidden.",
    operations: [
        new Put(
            uriTemplate: '/dashboard/layout',
            security: "is_granted('IS_AUTHENTICATED_FULLY')",
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
            security: "is_granted('IS_AUTHENTICATED_FULLY')",
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
        /** The version written -- also the response's ETag, and what the next save names as If-Match. */
        public string $version = '',
    ) {
    }
}
