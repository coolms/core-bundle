<?php

declare(strict_types=1);

namespace CoolMS\CoreBundle\Console;

use CoolMS\CoreBundle\Module\DisabledBundles;
use CoolMS\CoreBundle\Module\ModuleCatalog;
use CoolMS\CoreBundle\Module\ModuleConfigFiles;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function implode;
use function sprintf;

/**
 * Bring a removed module back: load it again, and put its configuration back.
 *
 * The counterpart to `coolms:module:remove`, and until now the missing half --
 * {@see DisabledBundles} has always named this command in the header it writes
 * into `coolms_disabled_bundles.php`, while the only way back was editing that
 * file by hand.
 *
 * This restores CODE, not data. Nothing was deleted, so nothing is recreated
 * here: navigation comes back from `coolms:install` followed by
 * `coolms:navi:seed`, which is a separate step and easy to forget -- so the
 * command says so rather than leaving a half-restored module looking broken.
 *
 * ORDER. The bundle is re-enabled BEFORE its configuration file is put back.
 * A config file whose extension is not registered stops the container
 * building, so the reverse order would brick the application in the window
 * between the two writes. In this order the intermediate state is a module
 * running on its defaults, which boots.
 */
#[AsCommand(
    name: 'coolms:module:restore',
    description: 'Load a removed module again and put its configuration back',
)]
final class RestoreModuleCommand extends Command
{
    public function __construct(
        private readonly ModuleCatalog $catalog,
        private readonly DisabledBundles $disabled,
        private readonly ModuleConfigFiles $configFiles,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('module', InputArgument::REQUIRED,
                'Module name, as declared by its bundle')
            ->addOption('dry-run', null, InputOption::VALUE_NONE,
                'Report what would be restored and change nothing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        /** @var string $typed */
        $typed = $input->getArgument('module');
        $dryRun = (bool) $input->getOption('dry-run');

        // The catalogue reads the disabled list as well as bundles.php, so a
        // module that is currently switched off is still resolvable -- which is
        // the whole point here.
        $class = $this->catalog->resolve($typed);
        if (null === $class) {
            $io->error(sprintf('No module named "%s".', $typed));
            $io->writeln('Known modules: ' . implode(', ', $this->catalog->modules()));

            return Command::FAILURE;
        }

        $module = $this->catalog->moduleNameOf($class);

        if (!$this->disabled->isDisabled($class)) {
            // Idempotent, the same way install and remove are.
            $io->success(sprintf('%s is already loaded.', $module));

            return Command::SUCCESS;
        }

        $alias = $this->configFiles->aliasOf($class);

        if ($dryRun) {
            $io->section(sprintf('Dry run: %s (%s)', $module, $class));
            $io->writeln('Would enable: ' . $class);
            foreach (null === $alias ? [] : $this->configFiles->restore($alias, true) as $line) {
                $io->writeln('  ' . $line);
            }

            return Command::SUCCESS;
        }

        $this->disabled->enable($class);

        $done = null === $alias ? [] : $this->configFiles->restore($alias);

        $io->success(sprintf('%s restored.', $module));
        foreach ($done as $line) {
            $io->writeln('  ' . $line);
        }
        $io->writeln('');
        $io->writeln('Its data was never deleted, so nothing had to be recreated.');
        $io->note('Run `coolms:install` and then `coolms:navi:seed` to put the '
            . 'module back in the navigation: seeding is a separate command, and '
            . 'skipping it leaves the module loaded but missing from the menu.');

        return Command::SUCCESS;
    }
}
