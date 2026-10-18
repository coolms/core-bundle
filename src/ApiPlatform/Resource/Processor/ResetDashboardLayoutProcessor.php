<?php

declare(strict_types=1);

namespace CoolMS\Core\Bundle\ApiPlatform\Resource\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use CoolMS\Core\Application\Dashboard\DashboardLayoutWriter;

/**
 * `DELETE /dashboard/layout` -- the person's arrangement dropped, so the module default applies again.
 *
 * Against the version the client read (If-Match), like a save: a reset from a stale screen would otherwise throw away
 * an arrangement made since on another device. The new version is answered as the ETag.
 *
 * ## 204 whether or not anything was there
 *
 * The caller asked for the dashboard to be unarranged, and afterwards it is.
 * A 404 for "there was no layout" would describe the storage rather than the
 * outcome, and would make an editor's Reset button fail in the one case where
 * the user is most likely to press it twice.
 *
 * @implements ProcessorInterface<mixed, null>
 */
final readonly class ResetDashboardLayoutProcessor implements ProcessorInterface
{
    public function __construct(
        private DashboardLayoutWriter $layouts,
        private DashboardLayoutWrite $write,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): null
    {
        $section = $this->write->section();
        $expected = $this->write->expectedVersion();

        $this->write->answer(fn (): string => $this->layouts->reset($section, $expected));

        return null;
    }
}
