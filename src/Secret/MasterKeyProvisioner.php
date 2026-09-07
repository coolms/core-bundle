<?php

declare(strict_types=1);

namespace CoolMS\CoreBundle\Secret;

use SodiumException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

use function chgrp;
use function chmod;
use function chown;
use function end;
use function file_get_contents;
use function file_put_contents;
use function function_exists;
use function getenv;
use function is_array;
use function is_file;
use function is_string;
use function posix_geteuid;
use function posix_getpwnam;
use function preg_match_all;
use function preg_quote;
use function putenv;
use function sodium_base642bin;
use function sodium_bin2base64;
use function sodium_crypto_secretbox_keygen;
use function str_ends_with;
use function strlen;
use function trim;

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
 *  - absent, env dev/test         → generate + append to the env file THIS
 *                                   environment reads ({@see envFilePath()}),
 *                                   set in-process
 *                                   → {@see MasterKeyStatus::Generated};
 *  - absent, env prod             → {@see MasterKeyStatus::MissingInProd} (refuse:
 *                                   never auto-generate an unbacked-up key whose loss
 *                                   makes all sealed data undecryptable);
 *  - absent, running as root with
 *    no reader configured        -> {@see MasterKeyStatus::OwnerUndetermined} (refuse
 *                                   BEFORE writing anything: a 0600 file owned by a
 *                                   user the web server is not would break every
 *                                   request, which is what this refuses to cause);
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
        #[Autowire('%coolms.secret_store.key_file_owner%')]
        private readonly ?string $keyFileOwner = null,
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

        // ⚠️ ADOPT BEFORE GENERATING. A key already assigned in the file this
        // run is about to append to is the key that file's environment will
        // use, whether or not the current process loaded it -- appending a
        // second one would make the first unreachable and orphan anything
        // sealed with it. Reachable when a script boots without Dotenv, and the
        // guard is what makes "one assignment per file" an invariant.
        $fromFile = $this->keyInEnvFile();
        if (null !== $fromFile) {
            $this->publish($fromFile);

            return $this->isValidKey($fromFile) ? MasterKeyStatus::AlreadyValid : MasterKeyStatus::Invalid;
        }

        // !! DECIDED BEFORE THE FILE IS WRITTEN, not after. A refusal that has
        // already created a 0600 root-owned env file has caused the exact
        // breakage it is refusing to cause.
        if ($this->writingForAnotherUser() && null === $this->resolvedOwner()) {
            return MasterKeyStatus::OwnerUndetermined;
        }

        $key = sodium_bin2base64(sodium_crypto_secretbox_keygen(), SODIUM_BASE64_VARIANT_ORIGINAL);
        $this->appendToEnvFile($key);

        $this->publish($key);

        return MasterKeyStatus::Generated;
    }

    /**
     * The env file THIS environment actually reads.
     *
     * ⚠️ `.env.local` IS NOT READ UNDER `APP_ENV=test`. Symfony skips it there
     * so a test run does not depend on one developer's machine -- which makes it
     * the one file a test-environment install must never write to, because dev
     * does read it and the last assignment in it wins. Every non-dev environment
     * therefore gets `.env.{env}.local`, which it does read and which the
     * `/.env.*.local` gitignore rule already covers.
     *
     * Public so callers can report the path they were actually given rather than
     * naming `.env.local` in a message that is only sometimes true.
     */
    public function envFilePath(): string
    {
        return 'dev' === $this->environment
            ? $this->projectDir . '/.env.local'
            : $this->projectDir . '/.env.' . $this->environment . '.local';
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

    /**
     * Make a key visible to the rest of THIS run (later install steps + any
     * same-process request) -- mirrors the cipher's read.
     */
    private function publish(string $key): void
    {
        $_ENV[$this->keyEnvVar] = $key;
        putenv($this->keyEnvVar . '=' . $key);
    }

    /**
     * The key already assigned in {@see envFilePath()}, or null.
     *
     * Takes the LAST assignment, because that is the one Dotenv would end up
     * with -- reading the first would adopt a value the environment does not
     * actually use.
     */
    private function keyInEnvFile(): ?string
    {
        $path = $this->envFilePath();
        if (!is_file($path)) {
            return null;
        }
        $matched = preg_match_all(
            '/^[ \t]*(?:export[ \t]+)?' . preg_quote($this->keyEnvVar, '/') . '=(.*)$/m',
            (string) file_get_contents($path),
            $matches,
        );
        if (0 === $matched || false === $matched) {
            return null;
        }
        $value = trim((string) end($matches[1]), " \t\"'");

        return '' === $value ? null : $value;
    }

    private function appendToEnvFile(string $key): void
    {
        $path = $this->envFilePath();

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

        // The file now holds the master key, so it drops from the usual 0644 to
        // 0600 -- which makes OWNERSHIP the only thing standing between the key
        // and the process that has to read it back.
        chmod($path, 0o600);

        $owner = $this->writingForAnotherUser() ? $this->resolvedOwner() : null;
        if (null !== $owner) {
            chown($path, $owner['uid']);
            chgrp($path, $owner['gid']);
        }
    }

    /**
     * True when this process will NOT be the one that reads the file back.
     *
     * php-fpm workers drop to their pool user (`www-data` in the official
     * images) while a console command under `docker compose exec` runs as root.
     * Root is therefore never the reader, and a 0600 root-owned env file makes
     * Dotenv throw on EVERY request, before the kernel boots -- which php-fpm
     * then serves as a plain HTTP 200 error page. Measured on a clean clone of
     * the skeleton, 2026-09-07: it is what the documented install produced.
     *
     * !! WITHOUT EXT-POSIX THIS RETURNS FALSE AND THE CHECK DOES NOT RUN, which
     * is a real limit and not a safe default. The extension is optional, so a
     * Linux host serving through php-fpm can lack it and still have the
     * writer/reader split this exists to catch -- and {@see resolvedOwner()}
     * needs `posix_getpwnam` too, so a configured reader is not applied there
     * either. Measured: the package's own CI has no ext-posix.
     */
    private function writingForAnotherUser(): bool
    {
        return function_exists('posix_geteuid') && 0 === posix_geteuid();
    }

    /**
     * The configured reader as uid/gid, or null when it cannot be determined --
     * unset, or naming a user this system does not have.
     *
     * @return array{uid: int, gid: int}|null
     */
    private function resolvedOwner(): ?array
    {
        $name = trim((string) $this->keyFileOwner);
        if ('' === $name || !function_exists('posix_getpwnam')) {
            return null;
        }
        $entry = posix_getpwnam($name);
        if (!is_array($entry)) {
            return null;
        }

        return ['uid' => $entry['uid'], 'gid' => $entry['gid']];
    }
}
