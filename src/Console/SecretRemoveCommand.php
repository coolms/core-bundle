<?php

declare(strict_types=1);

namespace CoolMS\CoreBundle\Console;

use CoolMS\CoreBundle\Secret\EncryptedSecretsFile;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function sprintf;

/**
 * F1.b -- remove a secret from the libsodium encrypted file:
 * `coolms:secret:remove <key>`. Idempotent (removing an absent key is a
 * no-op success).
 */
#[AsCommand(
    name: 'coolms:secret:remove',
    description: 'Remove a secret from the encrypted secrets file.',
)]
final class SecretRemoveCommand extends Command
{
    public function __construct(
        private readonly EncryptedSecretsFile $file,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('key', InputArgument::REQUIRED, 'Logical secret key to remove');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var string $key */
        $key = $input->getArgument('key');

        $secrets = $this->file->load();
        if (!isset($secrets[$key])) {
            $io->note(sprintf('Secret "%s" is not set; nothing to remove.', $key));

            return Command::SUCCESS;
        }

        unset($secrets[$key]);
        $this->file->save($secrets);

        $io->success(sprintf('Removed secret "%s".', $key));

        return Command::SUCCESS;
    }
}
