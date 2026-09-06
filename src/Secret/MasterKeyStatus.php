<?php

declare(strict_types=1);

namespace CoolMS\CoreBundle\Secret;

/**
 * Outcome of {@see MasterKeyProvisioner::ensure()} -- kept as a status enum so the
 * `coolms:install` command owns the user-facing messaging (and the pass/fail exit
 * code) while the provisioner stays pure + unit-testable.
 */
enum MasterKeyStatus
{
    /** A valid key was already configured -- nothing to do. */
    case AlreadyValid;

    /** No key was set; one was generated and appended to .env.local (dev/test). */
    case Generated;

    /** A key is set but is not a valid base64 32-byte key -- refused (never overwritten). */
    case Invalid;

    /** No key was set and the env is prod -- refused (never auto-generated). */
    case MissingInProd;

    /**
     * A key would have to be handed to a DIFFERENT user and this process cannot
     * say which -- refused before anything is written.
     *
     * Reached when the install runs as root, which is normal under
     * `docker compose exec`: root is never the process that reads the file
     * back, so writing a 0600 root-owned env file would leave the key
     * unreadable by the web server.
     */
    case OwnerUndetermined;
}
