<?php

declare(strict_types=1);

namespace CoolMS\CoreBundle\Analytics;

use CoolMS\Core\Analytics\ConsentCategory;
use CoolMS\Core\Analytics\CurrentConsentInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\HttpFoundation\RequestStack;

use function array_map;
use function explode;
use function in_array;
use function strtolower;
use function trim;

/**
 * The default {@see CurrentConsentInterface}: reads the public site's consent
 * cookie off the current request and maps it to the granted category vector
 * (tiered consent).
 *
 * Two cookie shapes, both handled here so producers/pipelines stay decoupled
 * from the wire format ({@see mapCookie()}):
 *  - **Tiered** (the `Customize` banner): a comma-list of granted category slugs,
 *    e.g. `necessary,analytics,personalization`. `necessary` is always implied;
 *    unknown tokens are ignored; emitted in {@see ConsentCategory} canonical order.
 *  - **Legacy binary** (the current accept/decline banner): `accepted` is a
 *    blanket first-party accept → `necessary + analytics + personalization` (NOT
 *    `marketing`, a distinct more-sensitive purpose that stays an explicit opt-in
 *    ); `declined` / malformed / absent -> `necessary` only.
 *
 * Off the request edge (CLI / worker / no request) it returns `[]` — "unknown"
 * — so the enriching sink leaves an event's own declared consent untouched.
 */
#[AsAlias(CurrentConsentInterface::class)]
final readonly class RequestConsent implements CurrentConsentInterface
{
    public const string COOKIE = 'coolms_consent';

    /** Legacy blanket-accept covers first-party analytics + the personalization derived from it. */
    private const array LEGACY_ACCEPTED = [
        ConsentCategory::Necessary->value,
        ConsentCategory::Analytics->value,
        ConsentCategory::Personalization->value,
    ];

    public function __construct(
        private RequestStack $requests,
    ) {
    }

    public function current(): array
    {
        $request = $this->requests->getCurrentRequest();
        if (null === $request) {
            return [];
        }

        return $this->mapCookie((string) $request->cookies->get(self::COOKIE, ''));
    }

    /** @return list<string> */
    private function mapCookie(string $value): array
    {
        $value = strtolower(trim($value));

        if ('accepted' === $value) {
            return self::LEGACY_ACCEPTED;
        }
        if ('' === $value || 'declined' === $value) {
            return [ConsentCategory::Necessary->value];
        }

        return $this->parseGranular($value);
    }

    /**
     * A tiered cookie: keep `necessary` (always) plus any requested known
     * category, in canonical order. Unknown tokens are dropped, so a malformed
     * value degrades to `necessary` only.
     *
     * @return list<string>
     */
    private function parseGranular(string $value): array
    {
        $requested = array_map(trim(...), explode(',', $value));

        $granted = [];
        foreach (ConsentCategory::cases() as $category) {
            if (ConsentCategory::Necessary === $category || in_array($category->value, $requested, true)) {
                $granted[] = $category->value;
            }
        }

        return $granted;
    }
}
