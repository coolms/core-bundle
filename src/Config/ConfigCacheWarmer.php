<?php

declare(strict_types=1);

namespace CoolMS\CoreBundle\Config;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\CacheWarmer\CacheWarmerInterface;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Validates all YAML config files under config/modules/ on cache:warmup.
 *
 * Does NOT fail on invalid configs -- logs warnings only, so production
 * cache:warmup remains non-blocking.
 *
 * Known types: layout, dialog, navigraph, form, datagrid
 */
final readonly class ConfigCacheWarmer implements CacheWarmerInterface
{
    private const array KNOWN_TYPES = ['layout', 'dialog', 'navigraph', 'form', 'form_type', 'datagrid', 'editor_profile', 'dashboard'];

    public function __construct(
        #[Autowire('%kernel.project_dir%/config')]
        private string $configDir,
        private LoggerInterface $logger,
    ) {
    }

    public function isOptional(): bool
    {
        return true;
    }

    /**
     * @return list<string>
     */
    public function warmUp(string $cacheDir, ?string $buildDir = null): array
    {
        $pattern = $this->configDir . '/modules/*/*/*.yaml';
        $scanned = 0;
        $warnings = 0;
        foreach (glob($pattern) ?: [] as $file) {
            ++$scanned;
            try {
                $data = Yaml::parseFile($file);
            } catch (ParseException $e) {
                ++$warnings;
                $this->logger->warning('ConfigCacheWarmer: YAML parse error in "{file}": {error}', [
                    'file' => $file,
                    'error' => $e->getMessage(),
                ]);
                continue;
            }
            if (!is_array($data)) {
                ++$warnings;
                $this->logger->warning('ConfigCacheWarmer: not a YAML map in "{file}"', ['file' => $file]);
                continue;
            }
            $type = $data['type'] ?? null;
            if (!is_string($type)) {
                ++$warnings;
                $this->logger->warning('ConfigCacheWarmer: missing "type" key in "{file}"', ['file' => $file]);
                continue;
            }
            if (!in_array($type, self::KNOWN_TYPES, strict: true)) {
                ++$warnings;
                $this->logger->warning(
                    'ConfigCacheWarmer: unknown config type "{type}" in "{file}" (known: {known})',
                    [
                        'type' => $type,
                        'file' => $file,
                        'known' => implode(', ', self::KNOWN_TYPES),
                    ],
                );
            }
        }
        $this->logger->info('ConfigCacheWarmer: scanned {count} files, {warnings} warning(s)', [
            'count' => $scanned,
            'warnings' => $warnings,
        ]);

        return [];
    }
}
