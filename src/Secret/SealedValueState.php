<?php

declare(strict_types=1);

namespace CoolMS\Core\Bundle\Secret;

/**
 * Which key a stored value opens under, found by opening it -- the only way
 * to know, since a marker names a format and not a key.
 */
enum SealedValueState: string
{
    /** Opens under the current key: a rotation leaves it alone. */
    case Current = 'current';

    /** Opens under the previous key only: a rotation re-seals it. */
    case Previous = 'previous';

    /** Opens under neither key this host holds: reported, never touched. */
    case Unreadable = 'unreadable';
}
