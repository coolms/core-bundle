<?php

declare(strict_types=1);

namespace CoolMS\CoreBundle\Secret;

use SodiumException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

use function chmod;
use function file_get_contents;
use function file_put_contents;
use function getenv;
use function is_file;
use function is_string;
use function putenv;
use function sodium_base642bin;
use function sodium_bin2base64;
use function sodium_crypto_secretbox_keygen;
use function str_ends_with;
use function strlen;

use const FILE_APPEND;
use const LOCK_EX;
use const SODIUM_BASE64_VARIANT_ORIGINAL;
use const SODIUM_CRYPTO_SECRETBOX_KEYBYTES;

/**
 * Ensures the platform at-rest master key ({@see $keyEnvVar}, default
 * `COOLMS_SECRET_MASTER_KEY`) exists so a FRESH install can seal mailbox
 * credentials (M8) and the encrypted secret store (F1). Both fail-close without
 * it, and the mailbox-create path returns a bare 503 — an easy-to-miss
 * onboarding trap this closes by wiring it into `coolms:install`.
 *
 * This is the ONE bootstrap secret that is deliberately NOT kept in the encrypted
 * secret store (it is the key that decrypts that store — see
 * {@see EncryptedSecretsFile}), so it stays a raw
 * env var, read exactly the way the mailbox credential cipher
 * reads it (`$_ENV` then `getenv()`) — keeping detection and format in lock-step.
 *
 * Behaviour (idempotent):
 *  - valid key present            → {@see MasterKeyStatus::AlreadyValid} (no-op);
 *  - absent, env dev/test         → generate + append to `.env.local`, set in-process
 *                                   → {@see MasterKeyStatus::Generated};
 *  - absent, env prod             → {@see MasterKeyStatus::MissingInProd} (refuse:
 *                                   never auto-generate an unbacked-up key whose loss
 *                                   makes all sealed data undecryptable);
 *  - present but invalid          → {@see MasterKeyStatus::Invalid} (refuse: never
 *                                   overwrite — that would orphan already-sealed data).
 */
final class MasterKeyProvisioner
{
    public function __construct(
        #[Autowire('%coolms.secret_store.fs_key_env%')]
        private readonly string $keyEnvVar,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
        #[Autowire('%kernel.environment%')]
        private readonly string $environment,
    ) {
    }

    public function ensure(): MasterKeyStatus
    {
        $current = $_ENV[$this->keyEnvVar] ?? getenv($this->keyEnvVar);
        if (is_string($current) && '' !== $current) {
            return $this->isValidKey($current) ? MasterKeyStatus::AlreadyValid : MasterKeyStatus::Invalid;
        }

        // Never mint an unbacked-up secret on an ephemeral prod host: if the key
        // is later lost/rotated, every sealed mailbox password + the secret store
        // become permanently undecryptable. Force conscious provisioning.
        if ('prod' === $this->environment) {
            return MasterKeyStatus::MissingInProd;
        }

        $key = sodium_bin2base64(sodium_crypto_secretbox_keygen(), SODIUM_BASE64_VARIANT_ORIGINAL);
        $this->appendToEnvLocal($key);

        // Make the freshly-generated key visible to the rest of THIS run (later
        // install steps + any same-process request) — mirrors the cipher's read.
        $_ENV[$this->keyEnvVar] = $key;
        putenv($this->keyEnvVar . '=' . $key);

        return MasterKeyStatus::Generated;
    }

    /**
     * True when a compiled `.env.local.php` (from `composer dump-env`) shadows
     * `.env.local` at runtime — an appended key would then be silently ignored.
     */
    public function compiledDumpExists(): bool
    {
        return is_file($this->projectDir . '/.env.local.php');
    }

    /** The env var whose presence this provisioner guarantees. */
    public function keyEnvVar(): string
    {
        return $this->keyEnvVar;
    }

    private function isValidKey(string $b64): bool
    {
        try {
            $raw = sodium_base642bin($b64, SODIUM_BASE64_VARIANT_ORIGINAL);
        } catch (SodiumException) {
            return false;
        }

        return SODIUM_CRYPTO_SECRETBOX_KEYBYTES === strlen($raw);
    }

    private function appendToEnvLocal(string $key): void
    {
        $path = $this->projectDir . '/.env.local';

        // Guard a leading newline so the key can't be concatenated onto a
        // trailing line that has no terminator.
        $prefix = '';
        if (is_file($path)) {
            $existing = (string) file_get_contents($path);
            if ('' !== $existing && !str_ends_with($existing, "\n")) {
                $prefix = "\n";
            }
        }

        file_put_contents(
            $path,
            $prefix . $this->keyEnvVar . '=' . $key . "\n",
            FILE_APPEND | LOCK_EX,
        );

        // Best-effort: the file now holds a secret; tighten from the usual 0644.
        chmod($path, 0o600);
    }
}
