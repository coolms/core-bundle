<?php

declare(strict_types=1);

namespace CoolMS\Core\Bundle\Secret;

use CoolMS\Core\Secret\MasterKey;

/**
 * What {@see KeyRingSealer::open()} returns: the plaintext, the key that
 * opened it, and whether the stored form already named that key -- a value
 * in the current form under the current key needs nothing from a rotation.
 */
final readonly class OpenedValue
{
    public function __construct(
        public string $plaintext,
        public MasterKey $key,
        public bool $currentForm,
    ) {
    }
}
