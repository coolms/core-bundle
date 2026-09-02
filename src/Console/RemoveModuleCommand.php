<?php

declare(strict_types=1);

namespace CoolMS\CoreBundle\Console;

use CoolMS\CoreBundle\Module\DisabledBundles;
use CoolMS\CoreBundle\Module\InstallManifest;
use CoolMS\CoreBundle\Module\ModuleArtifactRemover;
use CoolMS\CoreBundle\Module\ModuleCatalog;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function count;
use function implode;
use function sprintf;

/**
 * Remove a module: stop it loading, and undo what its installation put in place.
 *
 * REMOVE IS NOT PURGE. Nothing here deletes data. Navigation entries go back
 * because navi records their owner; settings rows, VFS content, seeded rows and
 * tables are left exactly as they are, so reinstalling finds its data intact.
 * That is the whole promise, and it is why this is safe to offer as a button.
 *
 * Two refusals, both of which exist because the alternative is worse than a
 * stopped command:
 *
 *  - artefacts belong to a module whose code is NOT loaded. The remover ships
 *    WITH the module, so taking the package out first strands them with nobody
 *    able to identify them. Nothing records that a module was installed, so
 *    this is detected the only way available: navigation still holds rows
 *    naming it.
 *
 *  - another bundle hard-requires this one. `getRequiredBundles()` is validated
 *    at boot and throws, so disabling underneath a dependent stops the
 *    application from starting. Naming the dependents is strictly better.
 */
#[AsCommand(
    name: 'coolms:module:remove',
    description: 'Stop a module loading and undo what its installation created (deletes no data)',
)]
final class RemoveModuleCommand extends Command
{
    public function __construct(
        private readonly ModuleCatalog $catalog,
        private readonly DisabledBundles $disabled,
        private readonly ModuleArtifactRemover $remover,
        private readonly InstallManifest $manifest,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('module', InputArgument::REQUIRED,
                'Module name, as declared by its bundle')
            ->addOption('dry-run', null, InputOption::VALUE_NONE,
                'Report what would be undone and change nothing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        /** @var string $typed */
        $typed = $input->getArgument('module');
        $dryRun = (bool) $input->getOption('dry-run');

        $class = $this->catalog->resolve($typed);

        if (null === $class) {
            // Nothing records that a module was installed, so the only evidence
            // that it ever was is an artefact still naming it. Navigation is the
            // one that records its owner, so it is the one that can answer.
            $orphans = $this->remover->removeBestEffort([$typed], dryRun: true);
            if ([] !== $orphans) {
                $io->error(sprintf(
                    'Artefacts exist for "%s" but its code is not loaded.',
                    $typed,
                ));
                $io->writeln('  ' . implode(PHP_EOL . '  ', $orphans));
                $io->writeln('');
                $io->writeln('Reinstall the package, remove, then take it out.');
                $io->writeln('The remover ships with the module, so it has to be here to run.');

                return Command::FAILURE;
            }

            $io->error(sprintf('No module named "%s".', $typed));
            $io->writeln('Known modules: ' . implode(', ', $this->catalog->modules()));

            return Command::FAILURE;
        }

        $module = $this->catalog->moduleNameOf($class);
        $already = $this->disabled->isDisabled($class);


        $dependents = $this->catalog->dependentsOf($class, $this->disabled->all());
        if ([] !== $dependents) {
            $io->error(sprintf(
                '%d bundle(s) hard-require %s, and would stop the application booting:',
                count($dependents),
                $class,
            ));
            foreach ($dependents as $dependent) {
                $io->writeln('  - ' . $dependent);
            }
            $io->writeln('');
            $io->writeln('Remove those first, or leave this module enabled.');

            return Command::FAILURE;
        }

        // Strict: the module is still here, so a teardown fault has to be
        // reported rather than logged past.
        if (!$this->manifest->exists()) {
            // Derived state, and absent means NOT KNOWN -- never "nothing was
            // installed". Removal proceeds on the artefacts themselves, which
            // is where the truth is: navi records its own owner. Refusing here
            // would make clearing var/ turn installed modules into unremovable
            // ones.
            $io->note('No install manifest, so the inventory below is what the '
                . 'artefacts themselves report, not a complete record of what '
                . 'installation created.');
        }

        $done = $this->remover->removeStrict(
            $this->catalog->candidateNames($class),
            $dryRun,
        );

        if ($dryRun) {
            $io->section(sprintf('Dry run: %s (%s)', $module, $class));
            $io->writeln([] === $done
                ? 'Nothing to undo.'
                : '  ' . implode(PHP_EOL . '  ', $done));
            $io->writeln('');
            $io->writeln('Would disable: ' . $class);
            $io->writeln('Would NOT touch: settings, VFS content, seeded rows, tables.');

            return Command::SUCCESS;
        }

        $this->disabled->disable($class);
        $this->manifest->forget($module);

        if ($already) {
            // Idempotent, like install. Say so plainly rather than reporting a
            // removal that had already happened.
            $io->success(sprintf('%s was already removed.', $module));
            if ([] !== $done) {
                $io->writeln('  Also undone now:');
                $io->writeln('  ' . implode(PHP_EOL . '  ', $done));
            }

            return Command::SUCCESS;
        }

        $io->success(sprintf('%s removed.', $module));
        if ([] !== $done) {
            $io->writeln('  ' . implode(PHP_EOL . '  ', $done));
        }
        $io->writeln('');
        $io->writeln('Disabled in ' . $this->disabled->path());
        $io->writeln('Its data was not touched. Delete the entry to bring it back.');
        $io->note('Run `cache:clear` if a worker is already running: the pools are '
            . 'namespaced by a seed that has just changed.');

        return Command::SUCCESS;
    }
}
