<?php

declare(strict_types=1);

namespace CoolMS\CoreBundle\ApiPlatform\Resource\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use CoolMS\Core\Dashboard\DashboardPlacement;
use CoolMS\CoreBundle\ApiPlatform\Resource\DashboardLayoutResource;
use CoolMS\CoreBundle\ApiPlatform\Resource\DTO\DashboardLayoutRequest;
use CoolMS\CoreModule\Dashboard\DashboardLayoutWriter;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

use function is_array;
use function is_bool;
use function is_int;
use function is_string;
use function sprintf;

/**
 * `PUT /dashboard/layout`.
 *
 * Reads three keys per entry and refuses anything else it is handed. A caller
 * is present, so every problem is answered rather than survived — the opposite
 * of {@see \CoolMS\CoreModule\Dashboard\DashboardLayoutProvider}, which reads
 * the same shape out of a config file and DROPS bad entries because there is
 * nobody to tell and a whole dashboard riding on the rest of the file.
 *
 * @implements ProcessorInterface<DashboardLayoutRequest, DashboardLayoutResource>
 */
final readonly class SaveDashboardLayoutProcessor implements ProcessorInterface
{
    public function __construct(
        private DashboardLayoutWriter $layouts,
        private RequestStack $requests,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): DashboardLayoutResource
    {
        $placements = [];
        foreach ($data->widgets as $index => $entry) {
            $placements[] = $this->placement($entry, (int) $index);
        }

        try {
            return new DashboardLayoutResource($this->layouts->save($placements, $this->section()));
        } catch (InvalidArgumentException $e) {
            // An id no installed module offers. 422 rather than 400 — the
            // request is well-formed JSON asking for something the dashboard
            // cannot do.
            throw new UnprocessableEntityHttpException($e->getMessage(), $e);
        }
    }

    private function placement(mixed $entry, int $index): DashboardPlacement
    {
        if (!is_array($entry) || !is_string($entry['widget'] ?? null) || '' === $entry['widget']) {
            throw $this->refuse($index, 'needs a "widget" id');
        }

        $columns = $entry['columns'] ?? null;
        if (null !== $columns && !is_int($columns)) {
            throw $this->refuse($index, '"columns" must be a whole number of twelfths, or omitted to keep the module\'s own width');
        }

        $hidden = $entry['hidden'] ?? false;
        if (!is_bool($hidden)) {
            throw $this->refuse($index, '"hidden" must be true or false');
        }

        try {
            return new DashboardPlacement($entry['widget'], $columns, $hidden);
        } catch (InvalidArgumentException $e) {
            // A width outside the grid — the value object's own guard.
            throw $this->refuse($index, $e->getMessage());
        }
    }

    /**
     * `?section=content` arranges that section; absent is the main dashboard.
     *
     * ⚠️ Read from the REQUEST, not from `$context['filters']`. API-Platform
     * fills `filters` for the read side — the provider uses it and works — but
     * not for a Put or a Delete, so the first cut silently arranged the MAIN
     * dashboard whichever tab you were on. It surfaced only because the writer
     * refuses a widget the target dashboard does not offer: "No installed
     * module offers vfs.storage-used", on a screen that was showing it.
     *
     * ⚠️ Not sanitised here, deliberately. The value becomes a config key and
     * therefore a FILE NAME, and the store enforces
     * {@see \CoolMS\CoreModule\Config\ConfigWriterInterface::KEY_PATTERN}
     * itself — a check in this processor would be one of several places that
     * have to agree, and the one that got forgotten would be the hole.
     */
    private function section(): ?string
    {
        $section = $this->requests->getCurrentRequest()?->query->get('section');

        return is_string($section) && '' !== $section ? $section : null;
    }

    private function refuse(int $index, string $why): UnprocessableEntityHttpException
    {
        // The index, because a rejected layout is a list and "one of these is
        // wrong" is not an answer anyone can act on.
        return new UnprocessableEntityHttpException(sprintf('widgets[%d] %s', $index, $why));
    }
}
