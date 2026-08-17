<?php

declare(strict_types=1);

namespace CoolMS\CoreBundle\Console;

use CoolMS\Core\Install\ModuleInstallerInterface;
use CoolMS\Core\Install\StructureInstallerInterface;
use CoolMS\CoreBundle\Secret\MasterKeyProvisioner;
use CoolMS\CoreBundle\Secret\MasterKeyStatus;
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
        private readonly MasterKeyProvisioner $masterKey,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('CoolMS2 Install');

        // Seed/validate the at-rest master key FIRST: without it, sealing mailbox
        // credentials (M8) and the encrypted secret store (F1) fail closed — and
        // mailbox creation 503s with no hint. Refuse rather than proceed on a bad
        // or (in prod) missing key.
        $io->section('Secrets');
        switch ($this->masterKey->ensure()) {
            case MasterKeyStatus::AlreadyValid:
                $io->writeln('  ✓ At-rest master key (' . $this->masterKey->keyEnvVar() . ') present');
                break;
            case MasterKeyStatus::Generated:
                $io->writeln('  ✓ Generated ' . $this->masterKey->keyEnvVar() . ' → .env.local');
                if ($this->masterKey->compiledDumpExists()) {
                    $io->warning('A compiled .env.local.php exists — the generated key was written to .env.local but will not take effect until you re-run `composer dump-env`.');
                }
                $io->warning('Back up ' . $this->masterKey->keyEnvVar() . ' (.env.local): losing it makes all sealed mailbox passwords and the encrypted secret store permanently undecryptable.');
                break;
            case MasterKeyStatus::Invalid:
                $io->error($this->masterKey->keyEnvVar() . ' is set but is not a valid base64 32-byte key. Refusing to overwrite it (that would orphan already-sealed data). Fix or unset it, then re-run coolms:install.');

                return Command::FAILURE;
            case MasterKeyStatus::MissingInProd:
                $io->error($this->masterKey->keyEnvVar() . ' is not set and is not auto-generated in prod. Generate one with `coolms:secret:generate-key`, set it in your environment / secret mount, then re-run coolms:install.');

                return Command::FAILURE;
        }

        $io->section('VFS structure');
        foreach ($this->installers as $installer) {
            $installer->installStructure();
            $io->writeln('  ✓ ' . basename(str_replace('\\', '/', $installer::class)));
        }
        $moduleInstallers = [...$this->moduleInstallers];
        $io->section('Module data');
        foreach ($moduleInstallers as $installer) {
            $installer->install();
            $io->writeln('  ✓ ' . basename(str_replace('\\', '/', $installer::class)));
        }
        $io->section('Running post-install steps...');
        foreach ($moduleInstallers as $installer) {
            $installer->postInstall();
            $io->writeln('  ✓ ' . basename(str_replace('\\', '/', $installer::class)));
        }
        $io->success('Installation complete.');

        return Command::SUCCESS;
    }
}
