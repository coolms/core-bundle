<?php

declare(strict_types=1);

namespace CoolMS\CoreBundle\Dtmpl;

use CoolMS\Dtmpl\Runtime\ConstantProviderInterface;

/**
 * Core Constant Provider.
 *
 * Supplies site-level platform constants available in every DTMPL template:
 *   {const:siteName}    resolves to configured site name
 *   {const:appVersion}  resolves to application version
 *   {const:currentYear} resolves to current year (integer cast to string)
 *   {const:currentDate} resolves to current date in Y-m-d format
 */
final readonly class CoreConstantProvider implements ConstantProviderInterface
{
    public function __construct(
        private string $siteName,
        private string $appVersion,
    ) {
    }

    public function getConstants(): array
    {
        return [
            'siteName' => $this->siteName,
            'appVersion' => $this->appVersion,
            'currentYear' => (int) date('Y'),
            'currentDate' => date('Y-m-d'),
        ];
    }
}
