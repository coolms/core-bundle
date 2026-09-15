<?php

declare(strict_types=1);

namespace CoolMS\Core\Bundle\Secret;

use CoolMS\Core\Secret\RotationTally;

/**
 * The sweep every kind runs, written once: read each stored value, say which
 * key opens it, and re-seal the ones under the previous key -- through a
 * callback the kind supplies, because the kind knows where its values live
 * and the sweep does not need to.
 *
 * Idempotent by construction: a value that opens under the current key is
 * counted and left, whatever form it is in, so a second run rewrites nothing
 * and a run that stops halfway resumes by being run again. A value that
 * opens under neither key is counted and left too; it is not this sweep's to
 * repair, only to report.
 */
final readonly class SealedValueSweep
{
    public function __construct(private KeyRingSealer $sealer)
    {
    }

    /**
     * @param iterable<mixed, array{mixed, ?string}> $rows     [identifier, stored value]; a null or empty value is not a value and is not counted
     * @param callable(mixed, string): void          $rewrite  receives the identifier and the re-sealed value; only called when applying
     * @param callable(string): bool|null            $isSealed for a kind that also holds PLAINTEXT values: true when the stored value is sealed at all
     * @param callable(string): string|null          $unwrap   strips a kind's own outer marker before the sealed value is opened
     * @param callable(string): string|null          $wrap     puts that marker back around the re-sealed value
     */
    public function run(
        iterable $rows,
        callable $rewrite,
        bool $apply,
        ?callable $isSealed = null,
        ?callable $unwrap = null,
        ?callable $wrap = null,
    ): RotationTally {
        $current = $previous = $unreadable = $plaintext = $resealed = 0;
        foreach ($rows as [$id, $stored]) {
            if (null === $stored || '' === $stored) {
                continue;
            }
            if (null !== $isSealed && !$isSealed($stored)) {
                ++$plaintext;
                continue;
            }
            $inner = null !== $unwrap ? $unwrap($stored) : $stored;
            $state = $this->sealer->classify($inner);
            match ($state) {
                SealedValueState::Current => ++$current,
                SealedValueState::Unreadable => ++$unreadable,
                SealedValueState::Previous => ++$previous,
            };
            if (SealedValueState::Previous === $state && $apply) {
                $resealedValue = $this->sealer->seal($this->sealer->open($inner)->plaintext);
                $rewrite($id, null !== $wrap ? $wrap($resealedValue) : $resealedValue);
                ++$resealed;
            }
        }

        return new RotationTally($current, $previous, $unreadable, $plaintext, $resealed);
    }
}
