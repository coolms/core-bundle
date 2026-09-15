<?php

declare(strict_types=1);

namespace CoolMS\Core\Bundle\Secret;

use CoolMS\Core\Secret\RotationTally;
use CoolMS\Core\Secret\SealedKindInterface;

/**
 * The secrets file as a kind of sealed value: one blob, so the tally is one
 * of `current`, `previous` or `unreadable` -- or all zero when there is no
 * file, which is not a value and not a problem.
 */
final readonly class SecretsFileKind implements SealedKindInterface
{
    public function __construct(
        private EncryptedSecretsFile $file,
        private SealedValueSweep $sweep,
    ) {
    }

    public function name(): string
    {
        return 'secrets file (' . $this->file->path() . ')';
    }

    public function sweep(bool $apply): RotationTally
    {
        $raw = $this->file->raw();
        if (null === $raw) {
            return new RotationTally();
        }

        return $this->sweep->run(
            [[$this->file->path(), $raw]],
            fn (mixed $id, string $resealed) => $this->file->replaceRaw($resealed),
            $apply,
        );
    }
}
