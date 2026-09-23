<?php

declare(strict_types=1);

namespace CoolMS\Core\Bundle\Tests\Health;

use CoolMS\Core\Bundle\Health\SealedResidueProbe;
use CoolMS\Core\Secret\ResidueTally;
use CoolMS\Core\Secret\RotationTally;
use CoolMS\Core\Secret\SealedKindInterface;
use CoolMS\Core\Secret\SealedResidueInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The doctor row for "does a retired key still open anything here?".
 *
 * The test that matters is {@see aKindThatDoesNotAnswerIsNotCountedAsZero()}.
 * Every other state in this file is ordinary; that one is the property the
 * whole seam rests on, because the failure it prevents is silent and looks
 * exactly like success: a green row assembled out of kinds nobody asked.
 */
final class SealedResidueProbeTest extends TestCase
{
    #[Test]
    public function everyKindAnsweredAndNoneHoldsAnything(): void
    {
        $probe = new SealedResidueProbe([
            self::answering('email bodies', ResidueTally::counted(6821, 0, 'dumps')),
            self::answering('mailboxes.password_cipher', ResidueTally::noCopyKept('row versions')),
        ]);

        $state = $probe->check();

        self::assertSame('ok', $state->status());
        self::assertFalse($state->isFailing());
        // The denominator reaches the row: 0 of 6,821 is a different claim from 0 of nothing.
        self::assertStringContainsString('6821 copies examined', $state->detail);
    }

    #[Test]
    public function copiesTheRingCannotOpenAreTheFaultAndTheRowCarriesTheCount(): void
    {
        $probe = new SealedResidueProbe([
            self::answering('email bodies', ResidueTally::counted(6821, 5686, 'dumps and bundles')),
        ]);

        $state = $probe->check();

        self::assertSame('DOWN', $state->status());
        self::assertTrue($state->isFailing());
        self::assertSame(5686, $state->residue, 'the state carries the number, not a verdict about it');
        self::assertStringContainsString('email bodies 5686 of 6821', $state->detail);
    }

    /**
     * NOT ASKED IS NOT ZERO.
     *
     * A kind that does not implement the residue port has established nothing.
     * Counting it as none would let one unimplemented kind turn the whole row
     * green -- and this row is read to decide whether a leaked key still
     * matters, which is the worst possible place for a confident guess.
     */
    #[Test]
    public function aKindThatDoesNotAnswerIsNotCountedAsZero(): void
    {
        $answered = self::answering('email bodies', ResidueTally::counted(6821, 0, 'dumps'));
        $silent = self::notAnswering('sip_credentials.secret_cipher');

        // The control: with the answering kind alone the row is green, so the
        // red below is caused by the kind that does not answer and by nothing else.
        self::assertSame('ok', new SealedResidueProbe([$answered])->check()->status());

        $state = new SealedResidueProbe([$answered, $silent])->check();

        self::assertSame('unknown', $state->status());
        self::assertSame(0, $state->residue);
        self::assertStringContainsString('sip_credentials.secret_cipher', $state->detail);
        self::assertStringContainsString('Not asked is not zero', $state->detail);
    }

    /** A kind that throws has not answered either, and says which one it was. */
    #[Test]
    public function aKindThatThrowsIsReportedAsUnaskedNotAsClean(): void
    {
        $throwing = new class implements SealedKindInterface, SealedResidueInterface {
            public function name(): string
            {
                return 'secrets file';
            }

            public function sweep(bool $apply): RotationTally
            {
                return new RotationTally();
            }

            public function residue(): ResidueTally
            {
                throw new RuntimeException('the file is unreadable');
            }
        };

        $state = new SealedResidueProbe([$throwing])->check();

        self::assertSame('unknown', $state->status());
        self::assertStringContainsString('the file is unreadable', $state->detail);
    }

    /** An installation that seals nothing is a different installation, not a healthy one. */
    #[Test]
    public function noSealedKindsAtAllReportsAbsentRatherThanOk(): void
    {
        $state = new SealedResidueProbe([])->check();

        self::assertSame('absent', $state->status());
        self::assertFalse($state->isFailing());
    }

    private static function answering(string $name, ResidueTally $tally): SealedKindInterface
    {
        return new class($name, $tally) implements SealedKindInterface, SealedResidueInterface {
            public function __construct(private string $name, private ResidueTally $tally)
            {
            }

            public function name(): string
            {
                return $this->name;
            }

            public function sweep(bool $apply): RotationTally
            {
                return new RotationTally();
            }

            public function residue(): ResidueTally
            {
                return $this->tally;
            }
        };
    }

    private static function notAnswering(string $name): SealedKindInterface
    {
        return new class($name) implements SealedKindInterface {
            public function __construct(private string $name)
            {
            }

            public function name(): string
            {
                return $this->name;
            }

            public function sweep(bool $apply): RotationTally
            {
                return new RotationTally();
            }
        };
    }
}
