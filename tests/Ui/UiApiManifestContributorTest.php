<?php

declare(strict_types=1);

namespace CoolMS\Core\Bundle\Tests\Ui;

use CoolMS\Core\Bundle\Ui\UiApiManifest;
use CoolMS\Core\Bundle\Ui\UiApiManifestContributor;
use CoolMS\Core\Ui\HostContracts;
use CoolMS\Core\Ui\InstalledThemeContractsInterface;
use CoolMS\Core\Ui\ThemeContracts;
use CoolMS\Core\Ui\UiContractMatcher;
use CoolMS\Core\Ui\UiEntry;
use CoolMS\Core\Ui\UiEntryCatalogInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The `ui` section a host reads to mount its modules. Contracts are named canonically; while `console` and `desk`
 * are deprecated aliases of `admin` and `workspace`, each old name is emitted beside its new one, so a host built
 * before the rename -- core-angular asking for `console` -- still finds every module it found before.
 */
final class UiApiManifestContributorTest extends TestCase
{
    #[Test]
    public function aModuleOnEitherNameIsListedUnderTheNewNameAndTheOldOneBesideIt(): void
    {
        $manifest = self::manifest(
            [new ThemeContracts('coolms-admin', 'angular', ['console' => '1.0'])],
            [
                new UiEntry('email', 'console', '^1.0', 'angular', 'ui/angular/entries/console.ts'),
                new UiEntry('call', 'admin', '^1.0', 'angular', 'ui/angular/entries/admin.ts'),
                new UiEntry('inbox', 'desk', '^1.0', 'angular', 'ui/angular/entries/desk.ts'),
            ],
        );

        self::assertSame(['admin' => '1.0', 'console' => '1.0'], $manifest->contracts);
        self::assertSame(['admin' => 'coolms-admin', 'console' => 'coolms-admin'], $manifest->hosts);
        self::assertSame([
            ['module' => 'email', 'contract' => 'admin', 'range' => '^1.0', 'framework' => 'angular'],
            ['contract' => 'console', 'module' => 'email', 'range' => '^1.0', 'framework' => 'angular'],
            ['module' => 'call', 'contract' => 'admin', 'range' => '^1.0', 'framework' => 'angular'],
            ['contract' => 'console', 'module' => 'call', 'range' => '^1.0', 'framework' => 'angular'],
        ], $manifest->modules, 'the workspace entry has no host here, so it is not listed under either name');
    }

    #[Test]
    public function aHostReadingEitherNameFindsTheSameModules(): void
    {
        $manifest = self::manifest(
            [new ThemeContracts('coolms-admin', 'angular', ['admin' => '1.0'])],
            [
                new UiEntry('email', 'console', '^1.0', 'angular', 'x'),
                new UiEntry('call', 'admin', '^1.0', 'angular', 'x'),
            ],
        );
        $under = static fn (string $name): array => array_values(array_map(
            static fn (array $row): string => $row['module'],
            array_filter($manifest->modules, static fn (array $row): bool => $row['contract'] === $name),
        ));

        self::assertSame(['email', 'call'], $under('admin'), 'a host on the new name');
        self::assertSame(['email', 'call'], $under('console'), 'a host built before the rename');
    }

    #[Test]
    public function aContractWithNoOldNameIsListedOnce(): void
    {
        $manifest = self::manifest(
            [new ThemeContracts('coolms-site', 'ssr', ['site' => '1.0'])],
            [new UiEntry('page', 'site', '^1.0', 'ssr', 'x')],
        );

        self::assertSame(['site' => '1.0'], $manifest->contracts);
        self::assertSame(['site' => 'coolms-site'], $manifest->hosts);
        self::assertSame([['module' => 'page', 'contract' => 'site', 'range' => '^1.0', 'framework' => 'ssr']], $manifest->modules);
    }

    #[Test]
    public function noInstalledThemeMountsNothingUnderAnyName(): void
    {
        $manifest = self::manifest([], [new UiEntry('email', 'console', '^1.0', 'angular', 'x')]);

        self::assertSame([], $manifest->contracts);
        self::assertSame([], $manifest->hosts);
        self::assertSame([], $manifest->modules);
    }

    /**
     * @param list<ThemeContracts> $themes
     * @param list<UiEntry>        $entries
     */
    private static function manifest(array $themes, array $entries): UiApiManifest
    {
        $catalog = new readonly class($entries) implements UiEntryCatalogInterface {
            /** @param list<UiEntry> $entries */
            public function __construct(private array $entries)
            {
            }

            public function entries(): array
            {
                return $this->entries;
            }
        };
        $installed = new readonly class(HostContracts::fromThemes($themes)) implements InstalledThemeContractsInterface {
            public function __construct(private HostContracts $hosts)
            {
            }

            public function installed(): HostContracts
            {
                return $this->hosts;
            }
        };

        [$key, $manifest] = new UiApiManifestContributor($catalog, new UiContractMatcher(), $installed)->contribute();
        self::assertSame('ui', $key);

        return $manifest;
    }
}
