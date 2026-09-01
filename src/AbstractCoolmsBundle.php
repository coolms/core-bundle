<?php

declare(strict_types=1);

namespace CoolMS\CoreBundle;

use LogicException;
use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * Base bundle for all CoolMS modules.
 *
 * Provides:
 *  - COMPONENT_NAME, the kebab-case identifier used for DI alias keys
 *  - getRequiredBundles(), validated at boot()
 *  - boot()-time validation that all required bundles are registered
 */
abstract class AbstractCoolmsBundle extends Bundle
{
    /** Kebab-case component identifier used for DI alias keys. Override in each bundle. */
    public const string COMPONENT_NAME = '';

    /**
     * Hard dependencies -- throws \LogicException at boot if any are missing.
     *
     * @return array<class-string<Bundle>>
     */
    public static function getRequiredBundles(): array
    {
        return [];
    }

    public function boot(): void
    {
        parent::boot();

        if (null === $this->container) {
            return;
        }

        /** @var array<string, class-string> $registered  bundle-name -- FQCN */
        $registered = $this->container->getParameter('kernel.bundles');
        $registeredClasses = array_values($registered);

        foreach (static::getRequiredBundles() as $required) {
            if (!in_array($required, $registeredClasses, true)) {
                throw new LogicException(sprintf('Bundle "%s" requires "%s" to be registered in the application.', static::class, $required));
            }
        }
    }
}
