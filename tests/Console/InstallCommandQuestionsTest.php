<?php

declare(strict_types=1);

namespace CoolMS\Core\Bundle\Tests\Console;

use ArrayObject;
use Closure;
use CoolMS\Core\Bundle\Console\InstallCommand;
use CoolMS\Core\Bundle\Install\InstallQuestionInterface;
use CoolMS\Core\Bundle\Module\DisabledBundles;
use CoolMS\Core\Bundle\Module\InstallManifest;
use CoolMS\Core\Bundle\Module\ModuleCatalog;
use CoolMS\Core\Bundle\Secret\MasterKeyProvisioner;
use CoolMS\Core\Install\StructureInstallerInterface;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

use function base64_encode;
use function bin2hex;
use function preg_replace;
use function random_bytes;
use function str_starts_with;
use function sys_get_temp_dir;

/**
 * coolms:install asks the installation's questions: a given answer's shape is checked before anything is installed,
 * and the answer applied after the installers, replacing one already set; one already answered and not given is kept;
 * an interactive install asks the rest (again after a refusal, empty skipping), an unattended one leaves them unset;
 * a given answer refused after the install says the install completed and how to set it later, and fails. Two
 * questions on one option, or one on an option the console has, are refused naming both.
 */
final class InstallCommandQuestionsTest extends TestCase
{
    /** @var ArrayObject<int, string> what ran, in order, shared with the installer and the question */
    private ArrayObject $log;

    #[Test]
    public function aGivenAnswerOfTheWrongShapeRefusesWithNothingInstalled(): void
    {
        $tester = $this->tester([$this->question()]);

        self::assertSame(Command::FAILURE, $tester->execute(['--base-url' => 'not an origin'], ['interactive' => false]));

        self::assertStringContainsString('--base-url: Not an origin. Nothing was installed.', $tester->getDisplay());
        self::assertSame([], $this->log->getArrayCopy(), 'no installer ran, nothing was applied');
    }

    #[Test]
    public function aGivenAnswerIsAppliedAfterTheInstallers(): void
    {
        $tester = $this->tester([$this->question()]);

        self::assertSame(Command::SUCCESS, $tester->execute(['--base-url' => 'https://a.example'], ['interactive' => false]));

        self::assertSame(['installed', 'applied https://a.example'], $this->log->getArrayCopy());
    }

    #[Test]
    public function aGivenAnswerRefusedAfterTheInstallSaysItCompletedAndHowToSetItLater(): void
    {
        $question = $this->question(refuse: static fn (string $a): string => 'No section serves it.');
        $tester = $this->tester([$question]);

        self::assertSame(Command::FAILURE, $tester->execute(['--base-url' => 'https://a.example'], ['interactive' => false]));

        // The error block wraps at the terminal width: compared with its whitespace collapsed.
        $display = (string) preg_replace('~\s+~', ' ', $tester->getDisplay());
        self::assertStringContainsString('Installation complete', $display);
        self::assertStringContainsString('--base-url was not kept: No section serves it.', $display);
        self::assertStringContainsString('Set it later: coolms:application:base-url https://host', $display);
        self::assertSame(['installed', 'applied https://a.example'], $this->log->getArrayCopy());
    }

    #[Test]
    public function anAnswerAlreadySetIsKeptWhenNoneIsGivenAndReplacedWhenOneIs(): void
    {
        $tester = $this->tester([$this->question(current: 'https://old.example')]);

        self::assertSame(Command::SUCCESS, $tester->execute([], ['interactive' => false]));
        self::assertStringContainsString('https://old.example (already set, kept)', $tester->getDisplay());
        self::assertSame(['installed'], $this->log->getArrayCopy());

        $this->log = new ArrayObject();
        $tester = $this->tester([$this->question(current: 'https://old.example')]);
        self::assertSame(Command::SUCCESS, $tester->execute(['--base-url' => 'https://new.example'], ['interactive' => false]));
        self::assertSame(['installed', 'applied https://new.example'], $this->log->getArrayCopy(), 'the operator stated it');
    }

    #[Test]
    public function anUnattendedInstallLeavesAnUnansweredQuestionUnsetAndSaysHow(): void
    {
        $tester = $this->tester([$this->question()]);

        self::assertSame(Command::SUCCESS, $tester->execute([], ['interactive' => false]));

        self::assertStringContainsString('--base-url: not given, left unset. Set it later:', $tester->getDisplay());
        self::assertSame(['installed'], $this->log->getArrayCopy());
    }

    #[Test]
    public function anInteractiveInstallAsksAgainAfterARefusalAndAnEmptyAnswerSkips(): void
    {
        $tester = $this->tester([$this->question()]);
        $tester->setInputs(['not an origin', 'https://a.example']);

        self::assertSame(Command::SUCCESS, $tester->execute([], ['interactive' => true]));
        self::assertStringContainsString('Not an origin.', $tester->getDisplay());
        self::assertSame(['installed', 'applied https://a.example'], $this->log->getArrayCopy());

        $this->log = new ArrayObject();
        $tester = $this->tester([$this->question()]);
        $tester->setInputs(['']);
        self::assertSame(Command::SUCCESS, $tester->execute([], ['interactive' => true]));
        self::assertStringContainsString('--base-url: skipped, left unset.', $tester->getDisplay());
        self::assertSame(['installed'], $this->log->getArrayCopy());
    }

    #[Test]
    public function twoQuestionsOnOneOptionAreRefusedNamingBoth(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('~--base-url of .+ is already taken by .+; each install question needs its own~');

        $this->tester([$this->question(), $this->question()]);
    }

    #[Test]
    public function aQuestionOnAConsoleOptionIsRefused(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('is already taken by the console itself');

        $this->tester([$this->question(option: 'env')]);
    }

    protected function setUp(): void
    {
        $this->log = new ArrayObject();
    }

    /**
     * @param list<InstallQuestionInterface> $questions
     */
    private function tester(array $questions): CommandTester
    {
        $dir = sys_get_temp_dir() . '/coolms-install-questions-' . bin2hex(random_bytes(4));
        $_ENV['COOLMS_TEST_INSTALL_KEY'] = base64_encode(random_bytes(32));
        $installer = new class($this->log) implements StructureInstallerInterface {
            /** @param ArrayObject<int, string> $log */
            public function __construct(private ArrayObject $log)
            {
            }

            public function installStructure(): void
            {
                $this->log[] = 'installed';
            }
        };

        $command = new InstallCommand(
            [$installer],
            [],
            new ModuleCatalog($dir, new DisabledBundles($dir)),
            new InstallManifest($dir),
            new MasterKeyProvisioner('COOLMS_TEST_INSTALL_KEY', $dir, 'test'),
            null,
            null,
            null,
            $questions,
        );

        return new CommandTester($command);
    }

    /**
     * @param Closure(string): ?string|null $refuse
     */
    private function question(
        ?string $current = null,
        ?Closure $refuse = null,
        string $option = 'base-url',
    ): InstallQuestionInterface {
        return new class($option, $current, $refuse, $this->log) implements InstallQuestionInterface {
            /** @param ArrayObject<int, string> $log */
            public function __construct(
                private string $option,
                private ?string $current,
                private ?Closure $refuse,
                private ArrayObject $log,
            ) {
            }

            public function option(): string
            {
                return $this->option;
            }

            public function question(): string
            {
                return 'The application base URL?';
            }

            public function description(): string
            {
                return 'The application base URL; empty leaves it unset.';
            }

            public function setLater(): string
            {
                return 'coolms:application:base-url https://host';
            }

            public function current(): ?string
            {
                return $this->current;
            }

            public function shapeRefusal(string $answer): ?string
            {
                return str_starts_with($answer, 'https://') ? null : 'Not an origin.';
            }

            public function apply(string $answer): ?string
            {
                $this->log[] = 'applied ' . $answer;

                return null === $this->refuse ? null : ($this->refuse)($answer);
            }
        };
    }
}
