<?php

declare(strict_types=1);

namespace CoolMS\Core\Bundle\Console;

use CoolMS\Core\Bundle\Install\InstallQuestionInterface;
use CoolMS\Core\Bundle\Module\InstallManifest;
use CoolMS\Core\Bundle\Module\ModuleCatalog;
use CoolMS\Core\Bundle\Secret\MasterKeyProvisioner;
use CoolMS\Core\Bundle\Secret\MasterKeyStatus;
use CoolMS\Core\Install\InstallOrder;
use CoolMS\Core\Install\ModuleInstallerInterface;
use CoolMS\Core\Install\StructureInstallerInterface;
use CoolMS\Core\Install\UnorderableInstallersException;
use CoolMS\Core\Install\VfsPathClaims;
use CoolMS\Core\Ui\HostContracts;
use CoolMS\Core\Ui\InstalledThemeContractsInterface;
use CoolMS\Core\Ui\UiContractMatcher;
use CoolMS\Core\Ui\UiEntryCatalogInterface;
use LogicException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'coolms:install',
    description: 'Install CoolMS2 VFS structure (runs all module VFS installers)',
)]
final class InstallCommand extends Command
{
    /** Options every console command already has: a question may not take one of these names. */
    private const array RESERVED_OPTIONS = [
        'help', 'quiet', 'silent', 'verbose', 'version', 'ansi', 'no-ansi', 'no-interaction', 'env', 'no-debug', 'profile',
    ];

    /**
     * @param iterable<StructureInstallerInterface> $installers
     * @param iterable<ModuleInstallerInterface>    $moduleInstallers
     * @param iterable<InstallQuestionInterface>    $questions        what the installation is asked, after the installers
     */
    public function __construct(
        private readonly iterable $installers,
        private readonly iterable $moduleInstallers,
        private readonly ModuleCatalog $catalog,
        private readonly InstallManifest $manifest,
        private readonly MasterKeyProvisioner $masterKey,
        /**
         * The host-contract check (the platform rule: hosts implement
         * contracts, modules offer entries): a module installed after the
         * theme meets the theme's declared contracts here. Appended
         * and nullable, as the trailing parameters of InstallThemeCommand are:
         * CoreServicesPass sets the two iterables BY NAME, so these autowire.
         */
        private readonly ?UiEntryCatalogInterface $uiEntries = null,
        private readonly ?UiContractMatcher $uiMatcher = null,
        private readonly ?InstalledThemeContractsInterface $themes = null,
        /**
         * The installation's questions (the application's base URL, for one). LAST and defaulted, so the optional
         * parameters before it stay optional; CoreServicesPass sets it BY NAME, and its test fails if it does not.
         */
        private readonly iterable $questions = [],
    ) {
        parent::__construct();
    }

    /**
     * One option per question. Two questions on one option, or a question on an option the command already has, would
     * leave one answer nowhere: refused here, naming both.
     */
    protected function configure(): void
    {
        $owners = [];
        foreach ($this->questions as $question) {
            $option = $question->option();
            $taken = $owners[$option] ?? (in_array($option, self::RESERVED_OPTIONS, true) ? 'the console itself' : null);
            if (null !== $taken) {
                $message = sprintf(
                    'coolms:install: the option --%s of %s is already taken by %s; each install question needs its own.',
                    $option,
                    $question::class,
                    $taken,
                );

                throw new LogicException($message);
            }
            $owners[$option] = $question::class;
            $this->addOption(
                $option,
                null,
                InputOption::VALUE_REQUIRED,
                $question->description() . ' Given where it is already set, it replaces it.',
            );
        }
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('CoolMS2 Install');

        // The order is DERIVED from what each installer declares it requires and
        // provides -- not registration order, which was alphabetical, and not a
        // priority, which nothing ever read. A prerequisite nothing provides, or a
        // cycle, refuses HERE.
        //
        // !! BEFORE THE SECRETS STEP, ON PURPOSE. `MasterKeyProvisioner::ensure()`
        // below WRITES a key to .env.local when none exists. The failure this
        // replaces was DecisionVfsInstaller discovering at line 76, mid-install,
        // that no system user existed -- after that key had been written to disk.
        // A refusal that still leaves a secret behind is the old failure with a
        // better message; this one leaves nothing.
        //
        // The module phase is sorted with the structure phase's provisions
        // already satisfied: it runs AFTER that phase completes, and four VFS
        // installers sit in both phases requiring what a structure installer
        // provides. Sorting the second set alone refused on a fact.
        try {
            $structureInstallers = InstallOrder::sort($this->installers);
            $moduleInstallers = InstallOrder::sort(
                $this->moduleInstallers,
                InstallOrder::provisionsOf($structureInstallers),
            );
        } catch (UnorderableInstallersException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        // Every answer given as an option is checked on its shape BEFORE anything is installed: a malformed one
        // refuses with nothing done. Whether it is acceptable beyond its shape is known only after the installers.
        foreach ($this->questions as $question) {
            $given = $this->given($input, $question);
            $refusal = null === $given ? null : $question->shapeRefusal($given);
            if (null !== $refusal) {
                $io->error(sprintf('--%s: %s Nothing was installed.', $question->option(), $refusal));

                return Command::FAILURE;
            }
        }

        // Host contracts, before anything is written: every module's UI entry
        // against the installed themes' declarations. A module whose range the
        // implementing theme's version does not include is refused BY NAME here
        // rather than installed with an entry the host cannot mount. No theme
        // declaring anything, or no theme module: every entry is unused,
        // nothing refuses.
        if (null !== $this->uiEntries && null !== $this->uiMatcher) {
            $hosts = $this->themes?->installed() ?? HostContracts::none();
            $refusals = $this->uiMatcher->refusalsAll($hosts, $this->uiEntries->entries());
            if ([] !== $refusals) {
                $io->section('UI contracts');
                foreach ($refusals as $refusal) {
                    $io->error($refusal->reason);
                }

                return Command::FAILURE;
            }
        }

        // Seed/validate the at-rest master key FIRST: without it, sealing mailbox
        // credentials (M8) and the encrypted secret store (F1) fail closed -- and
        // mailbox creation 503s with no hint. Refuse rather than proceed on a bad
        // or (in prod) missing key.
        $io->section('Secrets');
        switch ($this->masterKey->ensure()) {
            case MasterKeyStatus::AlreadyValid:
                $io->writeln('  ✓ At-rest master key (' . $this->masterKey->keyEnvVar() . ') present');
                break;
            case MasterKeyStatus::Generated:
                $io->writeln('  ✓ Generated ' . $this->masterKey->keyEnvVar() . ' → ' . basename($this->masterKey->envFilePath()));
                if ($this->masterKey->compiledDumpExists()) {
                    $io->warning(sprintf('A compiled .env.local.php exists — the generated key was written to %s but will not take effect until you re-run `composer dump-env`.', basename($this->masterKey->envFilePath())));
                }
                $io->warning(sprintf('Back up %s (%s): losing it makes all sealed mailbox passwords and the encrypted secret store permanently undecryptable.', $this->masterKey->keyEnvVar(), basename($this->masterKey->envFilePath())));
                break;
            case MasterKeyStatus::Invalid:
                $io->error($this->masterKey->keyEnvVar() . ' is set but is not a valid base64 32-byte key. Refusing to overwrite it (that would orphan already-sealed data). Fix or unset it, then re-run coolms:install.');

                return Command::FAILURE;
            case MasterKeyStatus::OwnerUndetermined:
                $io->error(sprintf(
                    'Refusing to write %s: this install is running as root, so the key file (%s) would be owned by root at 0600 and the web server -- which serves as a different user -- could not read it. Every request would then fail before the kernel boots. Set core.secret_store.key_file_owner (COOLMS_FILE_OWNER in the skeleton) to the user your web server runs as, commonly www-data, then re-run coolms:install.',
                    $this->masterKey->keyEnvVar(),
                    basename($this->masterKey->envFilePath()),
                ));

                return Command::FAILURE;
            case MasterKeyStatus::MissingInProd:
                $io->error($this->masterKey->keyEnvVar() . ' is not set and is not auto-generated in prod. Generate one with `coolms:secret:generate-key`, set it in your environment / secret mount, then re-run coolms:install.');

                return Command::FAILURE;
        }

        // Reported BEFORE anything is created, because after the fact the
        // second module has already written into the first one's directory and
        // the evidence is gone. Advisory: two modules may legitimately share a
        // root, so this says what it found and installs anyway.
        // !! Report the DENOMINATOR, and refuse to claim success over an empty
        // set. An install that ran no installers printed the identical green to
        // one that ran every installer -- two empty sections and
        // "Installation complete" -- so a distribution with no modules
        // registered looked like a working installation. The symptom is the
        // ABSENCE of an error, which nobody investigates.
        if ([] === $structureInstallers && [] === $moduleInstallers) {
            $io->error([
                'Nothing to install: this application registers no structure installers and no module installers.',
                'coolms:install runs what the installed modules contribute. Zero of both means no CoolMS module is registered in config/bundles.php, or the bundles are registered but their services are not (the coolms/* packages do not register their own -- the consuming application must).',
                'Refusing to report success over an empty set.',
            ]);

            return Command::FAILURE;
        }

        $collisions = VfsPathClaims::collisions([...$structureInstallers, ...$moduleInstallers]);
        if ([] !== $collisions) {
            $io->section('Declared VFS paths');
            foreach ($collisions as $path => $owners) {
                $io->warning(sprintf(
                    '%s is claimed by %d installers: %s',
                    $path,
                    count($owners),
                    implode(', ', array_map(
                        static fn (string $c): string => basename(str_replace('\\', '/', $c)),
                        $owners,
                    )),
                ));
            }
        }

        $io->section(sprintf('VFS structure -- %d installer(s)', count($structureInstallers)));
        foreach ($structureInstallers as $installer) {
            $installer->installStructure();
            $io->writeln('  ✓ ' . basename(str_replace('\\', '/', $installer::class)));
        }
        $io->section(sprintf('Module data -- %d installer(s)', count($moduleInstallers)));
        foreach ($moduleInstallers as $installer) {
            $installer->install();
            $io->writeln('  ✓ ' . basename(str_replace('\\', '/', $installer::class)));
        }
        $io->section(sprintf('Post-install steps -- %d installer(s)', count($moduleInstallers)));
        foreach ($moduleInstallers as $installer) {
            $installer->postInstall();
            $io->writeln('  ✓ ' . basename(str_replace('\\', '/', $installer::class)));
        }

        // Record what installation did. Derived state, best effort: a manifest
        // that cannot be written must never fail an install that worked, and
        // its absence later means "not known" rather than "nothing installed".
        $byModule = [];
        foreach ([...$structureInstallers, ...$moduleInstallers] as $installer) {
            $module = $this->catalog->moduleOf($installer::class);
            $byModule['' === $module ? '_unattributed' : $module][] = $installer;
        }
        foreach ($byModule as $module => $ran) {
            $this->manifest->record($module, $ran);
        }

        $refused = $this->answerQuestions($input, $io);
        if ([] !== $refused) {
            $io->error([
                sprintf(
                    'Installation complete -- %d structure installer(s), %d module installer(s) -- but not every answer was kept:',
                    count($structureInstallers),
                    count($moduleInstallers),
                ),
                ...$refused,
            ]);

            return Command::FAILURE;
        }

        $io->success(sprintf(
            'Installation complete -- %d structure installer(s), %d module installer(s).',
            count($structureInstallers),
            count($moduleInstallers),
        ));

        return Command::SUCCESS;
    }

    /**
     * The questions, after the installers. A given answer is applied, replacing one already set: the operator stated
     * it. Otherwise one already answered is kept; an interactive install asks the rest (again after a refusal, and
     * empty skips); an unattended one leaves them unset, saying how to set them later.
     *
     * @return list<string> each answer that was given and refused, in a sentence with how to set it later
     */
    private function answerQuestions(InputInterface $input, SymfonyStyle $io): array
    {
        $refused = [];
        $first = true;
        foreach ($this->questions as $question) {
            if ($first) {
                $io->section('Questions');
                $first = false;
            }
            $option = $question->option();
            $given = $this->given($input, $question);
            if (null !== $given) {
                $refusal = $question->apply($given);
                if (null === $refusal) {
                    $io->writeln(sprintf('  ✓ --%s: %s', $option, $given));
                } else {
                    $refused[] = sprintf('--%s was not kept: %s Set it later: %s', $option, $refusal, $question->setLater());
                }
                continue;
            }
            $current = $question->current();
            if (null !== $current) {
                $io->writeln(sprintf('  ✓ --%s: %s (already set, kept)', $option, $current));
                continue;
            }
            if (!$input->isInteractive()) {
                $io->writeln(sprintf('  - --%s: not given, left unset. Set it later: %s', $option, $question->setLater()));
                continue;
            }
            $this->ask($io, $question);
        }

        return $refused;
    }

    /** Asks until an answer is kept or the answer is empty, which skips it. */
    private function ask(SymfonyStyle $io, InstallQuestionInterface $question): void
    {
        while (true) {
            $answer = trim((string) $io->ask($question->question() . ' (empty to skip)', ''));
            if ('' === $answer) {
                $io->writeln(sprintf('  - --%s: skipped, left unset. Set it later: %s', $question->option(), $question->setLater()));

                return;
            }
            $refusal = $question->shapeRefusal($answer) ?? $question->apply($answer);
            if (null === $refusal) {
                $io->writeln(sprintf('  ✓ --%s: %s', $question->option(), $answer));

                return;
            }
            $io->error($refusal);
        }
    }

    /** The answer given as an option, trimmed; null when the option was not given or is empty. */
    private function given(InputInterface $input, InstallQuestionInterface $question): ?string
    {
        if (!$input->hasOption($question->option())) {
            return null;
        }
        $value = $input->getOption($question->option());

        return is_string($value) && '' !== trim($value) ? trim($value) : null;
    }
}
