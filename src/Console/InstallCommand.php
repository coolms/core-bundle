<?php

declare(strict_types=1);

namespace CoolMS\Core\Bundle\Console;

use CoolMS\Core\Install\ModuleInstallerInterface;
use CoolMS\Core\Install\StructureInstallerInterface;
use CoolMS\Core\Install\VfsPathClaims;
use CoolMS\Core\Bundle\Module\InstallManifest;
use CoolMS\Core\Bundle\Module\ModuleCatalog;
use CoolMS\Core\Bundle\Secret\MasterKeyProvisioner;
use CoolMS\Core\Bundle\Secret\MasterKeyStatus;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'coolms:install',
    description: 'Install CoolMS2 VFS structure (runs all module VFS installers)',
)]
final class InstallCommand extends Command
{
    /**
     * @param iterable<StructureInstallerInterface> $installers
     * @param iterable<ModuleInstallerInterface>    $moduleInstallers
     */
    public function __construct(
        private readonly iterable $installers,
        private readonly iterable $moduleInstallers,
        private readonly ModuleCatalog $catalog,
        private readonly InstallManifest $manifest,
        private readonly MasterKeyProvisioner $masterKey,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('CoolMS2 Install');

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
        $structureInstallers = [...$this->installers];
        $moduleInstallers = [...$this->moduleInstallers];

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

        $io->success(sprintf(
            'Installation complete -- %d structure installer(s), %d module installer(s).',
            count($structureInstallers),
            count($moduleInstallers),
        ));

        return Command::SUCCESS;
    }
}
