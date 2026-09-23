<?php

declare(strict_types=1);

namespace CoolMS\Core\Bundle\Tests\Console;

use CoolMS\Core\Bundle\Console\SecretRotateCommand;
use CoolMS\Core\Bundle\Tests\Secret\Support\StaticRing;
use CoolMS\Core\Secret\ResidueTally;
use CoolMS\Core\Secret\RotationTally;
use CoolMS\Core\Secret\SealedKindInterface;
use CoolMS\Core\Secret\SealedResidueInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

use function preg_replace;

/**
 * The command over fake kinds: the window's state is what it measures, the
 * exit status says it, and a kind that cannot be swept stops the run rather
 * than being reported as swept.
 */
final class SecretRotateCommandTest extends TestCase
{
    #[Test]
    public function reportsPerKindAndClosesTheWindowAtZeroPrevious(): void
    {
        $kinds = [
            self::kind(
                'users.secret',
                apply: new RotationTally(current: 3, previous: 2, resealed: 2),
                dry: new RotationTally(current: 3, previous: 2),
            ),
            self::kind(
                'mailboxes.password_cipher',
                apply: new RotationTally(current: 1, unreadable: 1),
                dry: new RotationTally(current: 1, unreadable: 1),
            ),
        ];
        $tester = new CommandTester(new SecretRotateCommand(self::twoKeys(), $kinds));

        $status = $tester->execute([]);
        $out = self::unwrapped($tester->getDisplay());

        self::assertSame(Command::SUCCESS, $status, $out);
        self::assertStringContainsString('users.secret', $out);
        self::assertStringContainsString('Window CLOSED', $out);
        self::assertStringContainsString('2 re-sealed this run', $out);
        self::assertStringContainsString('1 value(s) open under neither key', $out);
    }

    #[Test]
    public function aDryRunReportsWhatItWouldDoAndLeavesTheWindowOpen(): void
    {
        $kinds = [self::kind(
            'users.secret',
            apply: new RotationTally(),
            dry: new RotationTally(current: 3, previous: 2),
        )];
        $tester = new CommandTester(new SecretRotateCommand(self::twoKeys(), $kinds));

        $status = $tester->execute(['--dry-run' => true]);
        $out = self::unwrapped($tester->getDisplay());

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('(would: 2)', $out);
        self::assertStringContainsString('Window OPEN: 2 value(s) still under the previous key (dry run', $out);
    }

    #[Test]
    public function aRunThatCannotResealEverythingLeavesTheWindowOpen(): void
    {
        // a kind that re-sealed one of two -- interrupted, or refused a write
        $kinds = [self::kind(
            'users.secret',
            apply: new RotationTally(previous: 2, resealed: 1),
            dry: new RotationTally(previous: 2),
        )];
        $tester = new CommandTester(new SecretRotateCommand(self::twoKeys(), $kinds));

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('Window OPEN: 1 value(s)', self::unwrapped($tester->getDisplay()));
    }

    #[Test]
    public function oneKeyIsAnInventoryNotARotationAndSaysSo(): void
    {
        $kinds = [self::kind('users.secret', apply: new RotationTally(current: 1), dry: new RotationTally(current: 1))];
        $tester = new CommandTester(new SecretRotateCommand(new StaticRing(StaticRing::fresh()), $kinds));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('no previous key', self::unwrapped($tester->getDisplay()));
    }

    #[Test]
    public function noCurrentKeyRefusesBeforeSweepingAnything(): void
    {
        $kind = new class implements SealedKindInterface {
            public bool $swept = false;

            public function name(): string
            {
                return 'k';
            }

            public function sweep(bool $apply): RotationTally
            {
                $this->swept = true;

                return new RotationTally();
            }
        };
        $tester = new CommandTester(new SecretRotateCommand(new StaticRing(), [$kind]));

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertFalse($kind->swept);
        self::assertStringContainsString('No current master key', $tester->getDisplay());
    }

    #[Test]
    public function aKindThatThrowsStopsTheRunWithItsName(): void
    {
        $broken = new class implements SealedKindInterface {
            public function name(): string
            {
                return 'sip_credentials.secret_cipher';
            }

            public function sweep(bool $apply): RotationTally
            {
                throw new RuntimeException('connection refused');
            }
        };
        $tester = new CommandTester(new SecretRotateCommand(self::twoKeys(), [$broken]));

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString(
            'sip_credentials.secret_cipher: the sweep stopped: connection refused',
            self::unwrapped($tester->getDisplay()),
        );
    }

    /**
     * The whole point of the second question. Every value is under the current
     * key -- the old verdict said CLOSED and exited 0 on exactly this input --
     * and copies the ring cannot open are still on disk.
     */
    #[Test]
    public function copiesTheRingCannotOpenKeepTheWindowOpenEvenAtZeroValues(): void
    {
        $kinds = [self::kind(
            'email bodies (VFS /mail)',
            apply: new RotationTally(current: 5687),
            dry: new RotationTally(current: 5687),
            residue: ResidueTally::counted(6821, 5686, 'database dumps and backup bundles'),
        )];
        $tester = new CommandTester(new SecretRotateCommand(self::twoKeys(), $kinds));

        $status = $tester->execute([]);
        $out = self::unwrapped($tester->getDisplay());

        self::assertSame(Command::FAILURE, $status, $out);
        self::assertStringNotContainsString('Window CLOSED', $out);
        self::assertStringContainsString('5686 copies of that ciphertext remain', $out);
        self::assertStringContainsString('5686 of 6821', $out);
        // It names where it did not look, so the zero it will eventually report
        // is not mistaken for a statement about the whole estate.
        self::assertStringContainsString('database dumps and backup bundles', $out);
    }

    /**
     * NOT ASKED IS NOT ZERO. A kind with no residue implementation must not be
     * counted as reporting none, or the verdict is a green assembled from
     * silence.
     */
    #[Test]
    public function aKindThatDoesNotAnswerTheResidueQuestionBlocksTheVerdict(): void
    {
        $kinds = [
            self::kind('users.secret', apply: new RotationTally(current: 1), dry: new RotationTally(current: 1)),
            self::kindWithoutResidue('sip_credentials.secret_cipher'),
        ];
        $tester = new CommandTester(new SecretRotateCommand(self::twoKeys(), $kinds));

        $status = $tester->execute([]);
        $out = self::unwrapped($tester->getDisplay());

        self::assertSame(Command::FAILURE, $status, $out);
        self::assertStringNotContainsString('Window CLOSED', $out);
        self::assertStringContainsString('NOT ESTABLISHED', $out);
        self::assertStringContainsString('sip_credentials.secret_cipher', $out);
        self::assertStringContainsString('NOT ASKED', $out);
    }

    /**
     * The control for both of the above: with every kind answering and none
     * holding anything, the window closes and the verdict says which two
     * questions it is answering.
     */
    #[Test]
    public function theWindowClosesOnlyWhenBothNumbersAreZeroAndBothWereAsked(): void
    {
        $kinds = [self::kind(
            'email bodies (VFS /mail)',
            apply: new RotationTally(current: 5687),
            dry: new RotationTally(current: 5687),
            residue: ResidueTally::counted(6821, 0, 'database dumps and backup bundles'),
        )];
        $tester = new CommandTester(new SecretRotateCommand(self::twoKeys(), $kinds));

        $status = $tester->execute([]);
        $out = self::unwrapped($tester->getDisplay());

        self::assertSame(Command::SUCCESS, $status, $out);
        self::assertStringContainsString('Window CLOSED', $out);
        self::assertStringContainsString('every kind was asked for its residue', $out);
        // The denominator reaches the operator: a zero out of 6,821 is a
        // different statement from a zero out of nothing.
        self::assertStringContainsString('0 of 6821', $out);
    }

    private static function twoKeys(): StaticRing
    {
        return new StaticRing(StaticRing::fresh(), StaticRing::fresh());
    }

    /** SymfonyStyle wraps at the terminal width; the assertions are about words, not line breaks. */
    private static function unwrapped(string $display): string
    {
        return (string) preg_replace('/\s+/', ' ', $display);
    }

    /**
     * A kind that answers BOTH questions. The default residue is a structural
     * none, because these tests are about the VALUE count and a kind that does
     * not answer the second question now blocks the verdict on purpose -- which
     * {@see aKindThatDoesNotAnswerTheResidueQuestionBlocksTheVerdict()} is the
     * test for.
     */
    private static function kind(
        string $name,
        RotationTally $apply,
        RotationTally $dry,
        ?ResidueTally $residue = null,
    ): SealedKindInterface {
        $residue ??= ResidueTally::noCopyKept('nothing this fake keeps');

        return new class($name, $apply, $dry, $residue) implements SealedKindInterface, SealedResidueInterface {
            public function __construct(
                private string $name,
                private RotationTally $apply,
                private RotationTally $dry,
                private ResidueTally $residue,
            ) {
            }

            public function name(): string
            {
                return $this->name;
            }

            public function sweep(bool $apply): RotationTally
            {
                return $apply ? $this->apply : $this->dry;
            }

            public function residue(): ResidueTally
            {
                return $this->residue;
            }
        };
    }

    /** A kind from before the residue port existed: it answers the first question only. */
    private static function kindWithoutResidue(string $name): SealedKindInterface
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
                return new RotationTally(current: 1);
            }
        };
    }
}
