<?php

declare(strict_types=1);

namespace CoolMS\Core\Bundle\Tests\Transaction;

use CoolMS\Core\Bundle\Transaction\ConsoleUnitOfWorkOwner;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Event\ConsoleErrorEvent;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

#[CoversClass(ConsoleUnitOfWorkOwner::class)]
final class ConsoleUnitOfWorkOwnerTest extends TestCase
{
    private RecordingUnitOfWork $unit;

    #[Test]
    public function exitZeroCommits(): void
    {
        self::assertSame(0, $this->runCommand('app:work', 0));
        self::assertSame(['begin', 'commit'], $this->unit->calls);
    }

    #[Test]
    public function anyOtherExitCodeDiscards(): void
    {
        self::assertSame(113, $this->runCommand('app:work', 113));
        self::assertSame(['begin', 'discard'], $this->unit->calls);
    }

    #[Test]
    public function anErrorDiscards(): void
    {
        $this->runCommand('app:work', 0, new RuntimeException('failed'));

        self::assertSame(['begin', 'discard'], $this->unit->calls);
    }

    #[Test]
    public function aFailedCommitExitsNonZero(): void
    {
        $this->unit->failCommit = true;

        self::assertSame(1, $this->runCommand('app:work', 0));
    }

    #[Test]
    public function anUnscopedCommandIsNotOneUnit(): void
    {
        $this->runCommand('messenger:consume', 0);

        self::assertSame([], $this->unit->calls);
    }

    protected function setUp(): void
    {
        $this->unit = new RecordingUnitOfWork();
    }

    private function runCommand(string $name, int $exitCode, ?RuntimeException $error = null): int
    {
        $owner = new ConsoleUnitOfWorkOwner(true, $this->unit, ['messenger:consume'], new NullLogger());
        $command = new Command($name);
        $input = new ArrayInput([]);
        $output = new NullOutput();
        $owner->open(new ConsoleCommandEvent($command, $input, $output));
        if (null !== $error) {
            $owner->failed(new ConsoleErrorEvent($input, $output, $error, $command));
        }
        $terminate = new ConsoleTerminateEvent($command, $input, $output, $exitCode);
        $owner->decide($terminate);

        return $terminate->getExitCode();
    }
}
