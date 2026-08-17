<?php

declare(strict_types=1);

namespace CoolMS\CoreBundle\Secret;

/**
 * Outcome of {@see MasterKeyProvisioner::ensure()} — kept as a status enum so the
 * `coolms:install` command owns the user-facing messaging (and the pass/fail exit
 * code) while the provisioner stays pure + unit-testable.
 */
enum MasterKeyStatus
{
    /** A valid key was already configured — nothing to do. */
    case AlreadyValid;

    /** No key was set; one was generated and appended to .env.local (dev/test). */
    case Generated;

    /** A key is set but is not a valid base64 32-byte key — refused (never overwritten). */
    case Invalid;

    /** No key was set and the env is prod — refused (never auto-generated). */
    case MissingInProd;
}
