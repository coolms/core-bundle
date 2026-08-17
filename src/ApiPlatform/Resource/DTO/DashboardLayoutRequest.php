<?php

declare(strict_types=1);

namespace CoolMS\CoreBundle\ApiPlatform\Resource\DTO;

/**
 * The body of `PUT /dashboard/layout`.
 *
 * ## A dedicated input DTO, so the payload CANNOT say anything else
 *
 * The alternative — denormalizing the widget resource and saving what comes
 * back — would put `endpoint` and `requiredRole` on the wire of a write
 * operation. Nothing would read them today, and the first thing that did would
 * be a config file overruling a module's security. An id and two numbers is the
 * entire vocabulary a layout needs, so it is the entire vocabulary it gets.
 *
 * ## Why the entries stay raw arrays
 *
 * A typed `DashboardPlacementRequest[]` was the first cut and the serializer
 * would not hydrate it: not from a promoted constructor `@param`, not from
 * `@var list<…>`, not from `@var …[]` on a plain property. Every entry arrived
 * as a bare array and the failure surfaced as a 500 deep inside the processor —
 * the shape of bug where a docblock is load-bearing and nothing says so.
 *
 * So the processor maps them itself. It reads exactly three keys, which is the
 * same guarantee the typed DTO was there to give, and it can say *which* entry
 * is wrong and *why* — better than a serializer type error was ever going to.
 */
final class DashboardLayoutRequest
{
    /**
     * The cards, in the order they should appear.
     *
     * An empty list is a legitimate save — "hide nothing, order nothing" — and
     * is NOT the same as a reset, which removes the stored layout entirely.
     *
     * @var array<int, mixed> each `{widget: string, columns?: int|null, hidden?: bool}`
     */
    public array $widgets = [];
}
