<?php

declare(strict_types=1);

namespace CoolMS\Core\Bundle\Ui;

/**
 * The `ui` section of the API manifest: the host contracts in force and the
 * module entries the matcher accepted against them (the platform rule: hosts
 * implement contracts, modules offer entries). Serialized as-is;
 * core-angular's `UiApiManifest` is its mirror.
 *
 * @phpstan-type Entry array{module: string, contract: string, range: string, framework: string}
 */
final readonly class UiApiManifest
{
    /**
     * @param array<string, string> $contracts contract name -> MAJOR.MINOR; empty when no installed theme declares one
     * @param array<string, string> $hosts     contract name -> the installed theme implementing it
     * @param list<Entry>           $modules   the matched entries, what a host mounts
     */
    public function __construct(
        public array $contracts,
        public array $hosts,
        public array $modules,
    ) {
    }
}
