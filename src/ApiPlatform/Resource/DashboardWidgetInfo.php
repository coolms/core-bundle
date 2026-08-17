<?php

declare(strict_types=1);

namespace CoolMS\CoreBundle\ApiPlatform\Resource;

/**
 * One catalogue entry on the wire.
 *
 * A plain projection of {@see \CoolMS\Core\Dashboard\DashboardWidget} —
 * separate so the domain object can grow fields the API is not ready to
 * promise, which is the same split the file-kind catalogue uses.
 *
 * `requiredRole` is deliberately NOT carried: the registry has already applied
 * it, and telling a client which role it lacks describes the permission model
 * to someone who failed it.
 *
 * ⚠️ `columns` is in TWELFTHS of the dashboard grid, 1-12 — the one field here
 * a client must interpret rather than merely display. The domain object's
 * {@see \CoolMS\Core\Dashboard\DashboardWidget::COLUMNS_MAX} is the whole
 * contract, and a client that renders a different number of columns draws every
 * card the wrong width.
 */
final readonly class DashboardWidgetInfo
{
    public function __construct(
        public string $id,
        public string $label,
        public string $icon,
        public string $endpoint,
        public string $valuePath,
        public ?string $displayPath,
        public string $kind,
        public int $columns,
        public ?string $group,
        /**
         * Hidden by the saved layout: keeps its position in this list, and the
         * dashboard does not draw it.
         *
         * Sent rather than filtered out because the ARRANGER needs exactly what
         * the renderer does not — the only cards anyone wants to add back are
         * the ones that are not there. One route answering "everything, in
         * order, hidden ones marked" beats two routes that must agree about the
         * same list.
         */
        public bool $hidden = false,
        /**
         * What the saved layout said about this card's width, or null when it
         * said nothing — as opposed to `$columns`, which is the width in force.
         *
         * ⚠️ Only an editor should read this, and it must: re-submitting
         * `$columns` for a card nobody touched turns every module default into
         * a stored decision. Null arrives as an ABSENT key, not as null
         * (API-Platform omits null properties), so a client comparing against
         * `null` alone will get it wrong.
         */
        public ?int $explicitColumns = null,
    ) {
    }
}
