<?php

declare(strict_types=1);

namespace CoolMS\Core\Bundle\Ui;

use CoolMS\Core\Application\ApiManifest\ApiManifestContributorInterface;
use CoolMS\Core\Ui\ActiveThemeContractsInterface;
use CoolMS\Core\Ui\UiContractMatcher;
use CoolMS\Core\Ui\UiEntryCatalogInterface;
use CoolMS\Core\Ui\UiVerdict;

/**
 * The `ui` section of `GET /app-config`: the active theme's declared
 * contracts and the modules whose entries the matcher accepted against
 * them. It is what ACTIVATES a module in the host -- a host mounts only the
 * modules listed here -- so the theme's declaration is read on every serve,
 * not only at install.
 *
 * With no theme module, or no active theme, `contracts` is empty and so is
 * `modules`: nothing is mounted, and the host says which modules it has and
 * cannot mount. A refused entry is not listed either; the installer already
 * said why, and serving it would mount what it refused.
 */
final readonly class UiApiManifestContributor implements ApiManifestContributorInterface
{
    public function __construct(
        private UiEntryCatalogInterface $catalog,
        private UiContractMatcher $matcher,
        private ?ActiveThemeContractsInterface $activeTheme = null,
    ) {
    }

    /**
     * @return array{string, UiApiManifest}
     */
    public function contribute(): array
    {
        $theme = $this->activeTheme?->active();
        $modules = [];
        foreach ($this->matcher->match($theme, $this->catalog->entries()) as $verdict) {
            if (UiVerdict::Matched !== $verdict->verdict) {
                continue;
            }
            $modules[] = [
                'module' => $verdict->entry->module,
                'contract' => $verdict->entry->contract,
                'range' => $verdict->entry->range,
                'framework' => $verdict->entry->framework,
            ];
        }

        return ['ui', new UiApiManifest(null === $theme ? [] : $theme->versions, $modules)];
    }
}
