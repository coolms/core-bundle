<?php

declare(strict_types=1);

namespace CoolMS\Core\Bundle\Ui;

use CoolMS\Core\Application\ApiManifest\ApiManifestContributorInterface;
use CoolMS\Core\Ui\ContractName;
use CoolMS\Core\Ui\HostContracts;
use CoolMS\Core\Ui\InstalledThemeContractsInterface;
use CoolMS\Core\Ui\UiContractMatcher;
use CoolMS\Core\Ui\UiEntryCatalogInterface;
use CoolMS\Core\Ui\UiVerdict;

/**
 * The `ui` section of `GET /app-config`: the host contracts in force -- for
 * each contract, the installed theme that implements it and at which version
 * -- and the module entries the matcher accepted against them. It is what
 * ACTIVATES a module in a host: a host mounts only the modules listed here,
 * so the themes' declarations are read on every serve, not only at install.
 *
 * With no theme module, or no installed theme declaring anything, `contracts`
 * is empty and so is `modules`: nothing is mounted, and the host says which
 * modules it has and cannot mount. A refused entry is not listed either; the
 * installer already said why, and serving it would mount what it refused.
 *
 * Contracts are named canonically (`admin`, `workspace`, `site`). While
 * `console` and `desk` are deprecated aliases, each is ALSO emitted beside its
 * new name -- a key in `contracts` and `hosts`, a row in `modules` -- so a host
 * built before the rename, which asks for `console`, still finds its modules.
 * The additions go with the aliases ({@see ContractName}).
 */
final readonly class UiApiManifestContributor implements ApiManifestContributorInterface
{
    public function __construct(
        private UiEntryCatalogInterface $catalog,
        private UiContractMatcher $matcher,
        private ?InstalledThemeContractsInterface $themes = null,
    ) {
    }

    /**
     * @return array{string, UiApiManifest}
     */
    public function contribute(): array
    {
        $hosts = $this->themes?->installed() ?? HostContracts::none();
        $modules = [];
        foreach ($this->matcher->matchAll($hosts, $this->catalog->entries()) as $verdict) {
            if (UiVerdict::Matched !== $verdict->verdict) {
                continue;
            }
            $row = [
                'module' => $verdict->entry->module,
                'contract' => $verdict->entry->contract,
                'range' => $verdict->entry->range,
                'framework' => $verdict->entry->framework,
            ];
            $modules[] = $row;
            foreach (ContractName::aliasesOf($row['contract']) as $alias) {
                $modules[] = ['contract' => $alias] + $row;
            }
        }

        return ['ui', new UiApiManifest(self::withAliases($hosts->versions()), self::withAliases($hosts->hosts()), $modules)];
    }

    /**
     * A map keyed by canonical contract name, with each deprecated name added beside its new one.
     *
     * @param array<string, string> $byContract
     *
     * @return array<string, string>
     */
    private static function withAliases(array $byContract): array
    {
        $out = $byContract;
        foreach ($byContract as $name => $value) {
            foreach (ContractName::aliasesOf($name) as $alias) {
                $out[$alias] = $value;
            }
        }

        return $out;
    }
}
