<?php

declare(strict_types=1);

namespace CoolMS\Core\Bundle\Console;

use CoolMS\Core\Application\Health\LivenessRunner;
use CoolMS\Core\Health\DependencyState;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function array_map;
use function count;
use function json_encode;
use function sprintf;

use const DATE_ATOM;
use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;

/**
 * The number that should be zero: required dependencies not answering.
 *
 * Every long-running thing an installation needs is ASKED something that fails when
 * it is down -- a query, a publish, a socket, a cursor's age -- and the answer is
 * printed beside the question that produced it. Written because Centrifugo was dead
 * for nine hours while every gate stayed green: gates read the code, and nothing read
 * the machine.
 *
 * Exit code is the alert, the same contract as `coolms:email:mailbox:health`: SUCCESS
 * when the number is zero, FAILURE otherwise. `--json` is the machine surface.
 *
 * Scripting it: the exit code is trustworthy through `docker compose exec` (measured:
 * `exit(3)` arrives as 3, and an `if` takes the failure branch). A reader that wants
 * more than pass/fail should still take `failingRequired` from `--json`, because a
 * count says how bad and the code only says whether.
 */
#[AsCommand(
    name: 'coolms:doctor',
    description: 'The number that should be zero: long-running dependencies that do not answer when asked',
)]
final class DoctorCommand extends Command
{
    public function __construct(private readonly LivenessRunner $runner)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Machine-readable report on stdout');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $states = $this->runner->run();
        $failing = $this->runner->failingRequired($states);

        if (true === $input->getOption('json')) {
            $output->writeln($this->json($states, $failing));

            return $failing > 0 ? Command::FAILURE : Command::SUCCESS;
        }

        $io = new SymfonyStyle($input, $output);
        $io->title('Liveness');

        if ([] === $states) {
            // Not a pass. A doctor with no probes answers nothing, and "0 failing"
            // here would be the same empty reassurance the outage hid behind.
            $io->warning('No probes are registered -- this reports nothing about anything.');

            return Command::FAILURE;
        }

        $io->table(
            ['Dependency', 'State', 'Asked', 'Answer', 'Last activity'],
            array_map(static fn (DependencyState $s): array => [
                $s->name,
                $s->status(),
                $s->ask,
                $s->detail,
                $s->lastActivityAt?->format('Y-m-d H:i:s') ?? '--',
            ], $states),
        );

        if ($failing > 0) {
            $io->error(sprintf('%d of %d required dependencies are not answering.', $failing, count($states)));

            return Command::FAILURE;
        }

        $io->success(sprintf('0 required dependencies not answering (%d asked).', count($states)));

        return Command::SUCCESS;
    }

    /**
     * @param list<DependencyState> $states
     */
    private function json(array $states, int $failing): string
    {
        return json_encode([
            'failingRequired' => $failing,
            'dependencies' => array_map(static fn (DependencyState $s): array => [
                'name' => $s->name,
                'status' => $s->status(),
                'required' => $s->required,
                'configured' => $s->configured,
                'answered' => $s->answered,
                'ask' => $s->ask,
                'detail' => $s->detail,
                'lastActivityAt' => $s->lastActivityAt?->format(DATE_ATOM),
            ], $states),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
