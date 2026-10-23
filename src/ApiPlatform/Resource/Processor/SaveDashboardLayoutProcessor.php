<?php

declare(strict_types=1);

namespace CoolMS\Core\Bundle\ApiPlatform\Resource\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use CoolMS\Core\Application\Dashboard\DashboardLayoutWriter;
use CoolMS\Core\Bundle\ApiPlatform\Resource\DashboardLayoutResource;
use CoolMS\Core\Bundle\ApiPlatform\Resource\DTO\DashboardLayoutRequest;
use CoolMS\Core\Dashboard\DashboardPlacement;
use InvalidArgumentException;
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
 * is present, so every problem is answered rather than survived -- the opposite
 * of {@see \CoolMS\Core\Application\Dashboard\DashboardLayoutProvider}, which reads
 * the same shape out of a config file and DROPS bad entries because there is
 * nobody to tell and a whole dashboard riding on the rest of the file.
 *
 * The arrangement is the signed-in person's own, saved against the version they
 * read (If-Match): 412 when it changed since, and the new version answered as
 * the ETag ({@see DashboardLayoutWrite}).
 *
 * @implements ProcessorInterface<DashboardLayoutRequest, DashboardLayoutResource>
 */
final readonly class SaveDashboardLayoutProcessor implements ProcessorInterface
{
    public function __construct(
        private DashboardLayoutWriter $layouts,
        private DashboardLayoutWrite $write,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): DashboardLayoutResource
    {
        $placements = [];
        foreach ($data->widgets as $index => $entry) {
            $placements[] = $this->placement($entry, (int) $index);
        }
        $section = $this->write->section();
        $expected = $this->write->expectedVersion();

        return new DashboardLayoutResource(
            $this->write->answer(fn (): string => $this->layouts->save($placements, $section, $expected)),
        );
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
            // A width outside the grid -- the value object's own guard.
            throw $this->refuse($index, $e->getMessage());
        }
    }

    private function refuse(int $index, string $why): UnprocessableEntityHttpException
    {
        // The index, because a rejected layout is a list and "one of these is
        // wrong" is not an answer anyone can act on.
        return new UnprocessableEntityHttpException(sprintf('widgets[%d] %s', $index, $why));
    }
}
