<?php

declare(strict_types=1);

namespace CoolMS\Core\Bundle\Health;

use CoolMS\Core\Health\DependencyState;
use CoolMS\Core\Health\LivenessProbeInterface;
use CoolMS\Core\Secret\SealedKindInterface;
use CoolMS\Core\Secret\SealedResidueInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Throwable;

use function count;
use function implode;
use function sprintf;

/**
 * Does anything on this installation still open under a key the ring does not
 * hold?
 *
 * **Why a probe and not just the rotation's own output.** `coolms:secret:rotate`
 * prints the number, and a printed number is gone the moment the terminal
 * scrolls. The question it answers -- is the window actually closed -- is asked
 * days later, by somebody who did not run the rotation, usually while deciding
 * whether a leaked key still matters. That is a STATE, and a state belongs
 * where states are read.
 *
 * ## The three answers, and why none of them is a silent zero
 *
 * - **Every kind answered and none holds anything** -> `ok`. Earned: each kind
 *   reported its own denominator.
 * - **Some kind holds copies the ring cannot open** -> the residue state,
 *   carrying the COUNT. It is configured, it was asked, and it answered; the
 *   answer is the fault. A state that says "open" would be a second
 *   implementation of the check and could disagree with the tally it came from.
 *   A state that says 5,686 cannot.
 * - **Some kind does not implement the port at all** -> `unknown`, naming them.
 *   NOT ASKED IS NOT ZERO. A green assembled from silence is the one outcome
 *   that would make this seam worse than nothing, because it would answer the
 *   leaked-key question with a confidence nobody measured.
 *
 * With no sealed kinds registered at all there is nothing to ask, and the row
 * reports `absent` rather than `ok` -- an installation that seals nothing is
 * not a healthy one, it is a different one.
 *
 * ## Cost
 *
 * A residue count opens every copy it walks, so this is the most expensive row
 * in the report: on the dev estate 2026-09-23, 6,821 blobs in 7.8 s. It is
 * bounded by construction -- each kind streams and holds one copy at a time --
 * and it is the number an operator is told to watch, which is the trade the
 * ruling made when it kept `coolms:vfs:gc` manual.
 */
final readonly class SealedResidueProbe implements LivenessProbeInterface
{
    private const string ASK = 'every sealed kind asked how many copies of its ciphertext the ring cannot open';

    /** @param iterable<SealedKindInterface> $kinds */
    public function __construct(
        #[AutowireIterator('coolms.secret.sealed_kind')]
        private iterable $kinds,
    ) {
    }

    public function check(): DependencyState
    {
        $name = 'Retired-key ciphertext';
        $notAsked = [];
        $holding = [];
        $examined = 0;
        $retained = 0;
        $kinds = 0;

        foreach ($this->kinds as $kind) {
            ++$kinds;
            if (!$kind instanceof SealedResidueInterface) {
                $notAsked[] = $kind->name();
                continue;
            }
            try {
                $tally = $kind->residue();
            } catch (Throwable $e) {
                // A kind that cannot answer has not answered zero.
                $notAsked[] = sprintf('%s (asked and threw: %s)', $kind->name(), $e->getMessage());
                continue;
            }
            $examined += $tally->examined;
            $retained += $tally->retained;
            if (!$tally->closed()) {
                $holding[] = sprintf('%s %d of %d', $kind->name(), $tally->retained, $tally->examined);
            }
        }

        if (0 === $kinds) {
            return DependencyState::notConfigured(
                $name,
                self::ASK,
                'no sealed kind is registered -- this installation seals nothing',
            );
        }

        if ([] !== $holding) {
            return DependencyState::residue(
                $name,
                self::ASK,
                sprintf(
                    '%d cop(ies) remain that the ring cannot open: %s. A retired key still opens them.',
                    $retained,
                    implode('; ', $holding),
                ),
                $retained,
            );
        }

        if ([] !== $notAsked) {
            return DependencyState::inconclusive(
                $name,
                self::ASK,
                sprintf(
                    '%d of %d kind(s) do not answer this question -- %s. Not asked is not zero,'
                    . ' so whether a retired key still opens something cannot be said from here.',
                    count($notAsked),
                    $kinds,
                    implode(', ', $notAsked),
                ),
            );
        }

        return DependencyState::answered(
            $name,
            self::ASK,
            sprintf('every one of %d kind(s) answered; %d copies examined, none the ring could not open', $kinds, $examined),
        );
    }
}
