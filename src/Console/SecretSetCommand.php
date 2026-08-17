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

use function is_string;
use function sprintf;

/**
 * F1.b -- set (create or update) a secret in the libsodium encrypted
 * file: `coolms:secret:set <key> [value]`. With the value omitted it is
 * read from a hidden prompt (keeps it out of shell history). Empty values
 * are rejected (an empty secret is absent; use `coolms:secret:remove`).
 */
#[AsCommand(
    name: 'coolms:secret:set',
    description: 'Set a secret in the encrypted secrets file.',
)]
final class SecretSetCommand extends Command
{
    public function __construct(
        private readonly EncryptedSecretsFile $file,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('key', InputArgument::REQUIRED, 'Logical secret key, e.g. stripe_api_key')
            ->addArgument('value', InputArgument::OPTIONAL, 'Secret value (omit for a hidden prompt)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var string $key */
        $key = $input->getArgument('key');

        $value = $input->getArgument('value');
        if (!is_string($value)) {
            $value = (string) $io->askHidden('Secret value');
        }

        if ('' === $value) {
            $io->error('Empty values are not stored. Use coolms:secret:remove to delete a key.');

            return Command::FAILURE;
        }

        $secrets = $this->file->load();
        $existed = isset($secrets[$key]);
        $secrets[$key] = $value;
        $this->file->save($secrets);

        $io->success(sprintf('%s secret "%s".', $existed ? 'Updated' : 'Set', $key));

        return Command::SUCCESS;
    }
}
