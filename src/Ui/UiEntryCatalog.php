<?php

declare(strict_types=1);

namespace CoolMS\Core\Bundle\Ui;

use CoolMS\Core\Ui\UiEntry;
use CoolMS\Core\Ui\UiEntryCatalogInterface;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

use function basename;
use function dirname;
use function glob;
use function is_array;
use function is_string;
use function sprintf;

/**
 * The modules' `ui.yaml` files, read from the same roots the navigation
 * loader walks: every registered bundle's `config/modules/<id>/ui.yaml`, then
 * the application's. A module declares its UI entries beside its navigation,
 * layouts and datagrids (a module is one repository, UI included; the ruling of
 * 2026-09-22 that this is the place, not composer `extra`).
 *
 *   module: email
 *   entries:
 *     - contract: console
 *       range: "^1.0"
 *       framework: angular
 *       entry: ui/angular/entries/console.ts
 *
 * A file that cannot be read refuses the whole catalogue by name: a module
 * whose declaration is malformed must not install as if it declared nothing.
 */
final readonly class UiEntryCatalog implements UiEntryCatalogInterface
{
    /**
     * @param list<string> $moduleConfigDirs every registered bundle's config/
     */
    public function __construct(
        #[Autowire('%kernel.project_dir%/config')]
        private string $configDir,
        #[Autowire('%coolms.module_config_dirs%')]
        private array $moduleConfigDirs = [],
    ) {
    }

    /**
     * @return list<UiEntry>
     */
    public function entries(): array
    {
        $roots = $this->moduleConfigDirs;
        $roots[] = $this->configDir;

        $entries = [];
        foreach ($roots as $root) {
            foreach (glob($root . '/modules/*/ui.yaml') ?: [] as $file) {
                foreach ($this->read($file) as $entry) {
                    $entries[] = $entry;
                }
            }
        }

        return $entries;
    }

    /**
     * @return list<UiEntry>
     */
    private function read(string $file): array
    {
        try {
            $data = Yaml::parseFile($file);
        } catch (ParseException $e) {
            throw new RuntimeException(sprintf('%s: %s', $file, $e->getMessage()), 0, $e);
        }
        if (!is_array($data)) {
            throw new RuntimeException(sprintf('%s: must be a YAML mapping', $file));
        }

        $module = $data['module'] ?? basename(dirname($file));
        if (!is_string($module) || '' === $module) {
            throw new RuntimeException(sprintf('%s: module must be a string', $file));
        }
        if ($module !== basename(dirname($file))) {
            throw new RuntimeException(sprintf("%s: declares module '%s' but sits in config/modules/%s", $file, $module, basename(dirname($file))));
        }

        $list = $data['entries'] ?? [];
        if (!is_array($list)) {
            throw new RuntimeException(sprintf('%s: entries must be a list', $file));
        }

        $entries = [];
        foreach ($list as $i => $item) {
            if (!is_array($item)) {
                throw new RuntimeException(sprintf('%s: entries[%s] must be a mapping', $file, (string) $i));
            }
            foreach (['contract', 'range', 'framework', 'entry'] as $key) {
                if (!isset($item[$key]) || !is_string($item[$key]) || '' === $item[$key]) {
                    throw new RuntimeException(sprintf("%s: entries[%s] needs a string '%s'", $file, (string) $i, $key));
                }
            }
            try {
                $entries[] = new UiEntry($module, $item['contract'], $item['range'], $item['framework'], $item['entry']);
            } catch (InvalidArgumentException $e) {
                throw new RuntimeException(sprintf('%s: %s', $file, $e->getMessage()), 0, $e);
            }
        }

        return $entries;
    }
}
