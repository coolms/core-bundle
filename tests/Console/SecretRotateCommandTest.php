<?php

declare(strict_types=1);

namespace CoolMS\Core\Bundle\Tests\Console;

use CoolMS\Core\Bundle\Console\SecretRotateCommand;
use CoolMS\Core\Bundle\Tests\Secret\Support\StaticRing;
use CoolMS\Core\Secret\RotationTally;
use CoolMS\Core\Secret\SealedKindInterface;
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
        $swept = false;
        $kind = new class($swept) implements SealedKindInterface {
            public function __construct(private bool &$swept)
            {
            }

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
        self::assertFalse($swept);
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

    private static function twoKeys(): StaticRing
    {
        return new StaticRing(StaticRing::fresh(), StaticRing::fresh());
    }

    /** SymfonyStyle wraps at the terminal width; the assertions are about words, not line breaks. */
    private static function unwrapped(string $display): string
    {
        return (string) preg_replace('/\s+/', ' ', $display);
    }

    private static function kind(string $name, RotationTally $apply, RotationTally $dry): SealedKindInterface
    {
        return new class($name, $apply, $dry) implements SealedKindInterface {
            public function __construct(private string $name, private RotationTally $apply, private RotationTally $dry)
            {
            }

            public function name(): string
            {
                return $this->name;
            }

            public function sweep(bool $apply): RotationTally
            {
                return $apply ? $this->apply : $this->dry;
            }
        };
    }
}
