<?php

declare(strict_types=1);

namespace CoolMS\Core\Bundle\Ui;

/**
 * The `ui` section of the API manifest: the active theme's declared host
 * contracts and the module entries the matcher accepted against them
 * (the platform rule: hosts implement contracts, modules offer entries). Serialized as-is; core-angular's `UiApiManifest`
 * is its mirror.
 */
final readonly class UiApiManifest
{
    /**
     * @param array<string, string>                                                           $contracts contract name -> MAJOR.MINOR; empty when no theme is active
     * @param list<array{module: string, contract: string, range: string, framework: string}> $modules   the matched entries, what a host mounts
     */
    public function __construct(
        public array $contracts,
        public array $modules,
    ) {
    }
}
