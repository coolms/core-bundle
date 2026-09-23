<?php

declare(strict_types=1);

namespace CoolMS\Core\Bundle\Secret;

/**
 * Which key a stored value opens under, found by opening it.
 *
 * The current form names its key id, so a cheaper guess is available; it is
 * not used, because {@see KeyRingSealer::candidates()} treats the id as a
 * hint and the box as the proof. See {@see \CoolMS\Core\Secret\RotationTally}
 * for why the count a rotation is gated on has to be the proof.
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
