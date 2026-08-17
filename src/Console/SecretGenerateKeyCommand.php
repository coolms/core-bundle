<?php

declare(strict_types=1);

namespace CoolMS\CoreBundle\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function sodium_bin2base64;
use function sodium_crypto_secretbox_keygen;

use const SODIUM_BASE64_VARIANT_ORIGINAL;

/**
 * F1.b -- generate a fresh master key for the libsodium filesystem secret
 * store. Prints a base64 32-byte key; the operator places it in the env
 * var named by `coolms_core.secret_store.filesystem.key_env` (default
 * `COOLMS_SECRET_MASTER_KEY`). Never touches the secrets file.
 */
#[AsCommand(
    name: 'coolms:secret:generate-key',
    description: 'Generate a base64 master key for the filesystem secret store.',
)]
final class SecretGenerateKeyCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $key = sodium_bin2base64(sodium_crypto_secretbox_keygen(), SODIUM_BASE64_VARIANT_ORIGINAL);

        // The raw key on its own line so it is easy to pipe/copy; the
        // guidance goes to stderr so it doesn't pollute a capture.
        $output->writeln($key);
        $io->getErrorStyle()->note([
            'Set this as the master key env var (default COOLMS_SECRET_MASTER_KEY), e.g.:',
            '  COOLMS_SECRET_MASTER_KEY=' . $key,
            'Keep it OUT of version control. Losing it makes the encrypted secrets unrecoverable.',
        ]);

        return Command::SUCCESS;
    }
}
