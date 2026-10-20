<?php

declare(strict_types=1);

namespace CoolMS\Core\Bundle\Http;

use function str_contains;
use function str_starts_with;
use function substr;
use function trim;

/**
 * The version an If-Match header names: the one version a write was based on.
 *
 * One version is named; `*` and a list name none, because a version the stored
 * state must have is what a client sends. A weak `W/` prefix is read as the
 * same version.
 */
final class IfMatchVersion
{
    /**
     * The version named, unquoted; '' for a present, EMPTY tag (`""`, `W/""`) -- compared like any version, it
     * matches none, so the write is refused rather than applied unchecked; null when the header names none (absent,
     * blank, `*`, or a list).
     */
    public static function parse(?string $header): ?string
    {
        $raw = trim((string) $header);
        if ('' === $raw || '*' === $raw || str_contains($raw, ',')) {
            return null;
        }
        if (str_starts_with($raw, 'W/')) {
            $raw = substr($raw, 2);
        }

        return trim($raw, '"');
    }

    /**
     * Whether the header names several versions. {@see parse()} reads such a header as naming none, so a writer that
     * must not ignore a precondition the client sent asks this first and refuses.
     */
    public static function namesSeveral(?string $header): bool
    {
        return str_contains((string) $header, ',');
    }
}
