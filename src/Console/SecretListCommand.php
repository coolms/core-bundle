<?php

declare(strict_types=1);

namespace CoolMS\CoreBundle\Console;

use CoolMS\CoreBundle\Secret\EncryptedSecretsFile;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function array_keys;
use function count;
use function sort;
use function sprintf;

/**
 * F1.b -- list the KEY NAMES held in the libsodium encrypted file:
 * `coolms:secret:list`. Never prints values.
 */
#[AsCommand(
    name: 'coolms:secret:list',
    description: 'List the names of secrets in the encrypted secrets file (values never shown).',
)]
final class SecretListCommand extends Command
{
    public function __construct(
        private readonly EncryptedSecretsFile $file,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $keys = array_keys($this->file->load());
        sort($keys);

        if ([] === $keys) {
            $io->note('No secrets are stored.');

            return Command::SUCCESS;
        }

        $io->listing($keys);
        $io->writeln(sprintf('<info>%d secret(s).</info>', count($keys)));

        return Command::SUCCESS;
    }
}
