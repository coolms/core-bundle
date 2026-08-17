<?php

declare(strict_types=1);

namespace CoolMS\CoreBundle\Tests\Analytics;

use CoolMS\CoreBundle\Analytics\RequestConsent;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * The consent read-side seam (tiered consent): maps the public
 * `coolms_consent` cookie to the granted category vector — the granular comma-list
 * AND the legacy binary accept/decline — and stays "unknown" (`[]`) off the
 * request edge so the enriching sink leaves an event's own declared consent
 * untouched.
 */
final class RequestConsentTest extends TestCase
{
    /** @return iterable<string, array{0: ?string, 1: list<string>}> */
    public static function cookies(): iterable
    {
        // Legacy binary banner: blanket accept covers analytics + personalization
        // (derived from the same first-party profile), but NOT marketing.
        yield 'legacy accepted grants analytics + personalization' => ['accepted', ['necessary', 'analytics', 'personalization']];
        yield 'legacy declined grants only necessary' => ['declined', ['necessary']];
        yield 'no cookie grants only necessary' => [null, ['necessary']];

        // Tiered banner: a comma-list of granted category slugs.
        yield 'granular analytics only' => ['necessary,analytics', ['necessary', 'analytics']];
        yield 'granular analytics + personalization' => ['necessary,analytics,personalization', ['necessary', 'analytics', 'personalization']];
        yield 'granular all four' => ['necessary,analytics,personalization,marketing', ['necessary', 'analytics', 'personalization', 'marketing']];
        yield 'granular implies necessary' => ['analytics', ['necessary', 'analytics']];
        yield 'granular emitted in canonical order' => ['marketing,analytics', ['necessary', 'analytics', 'marketing']];
        yield 'granular is case-insensitive' => ['Necessary,Analytics', ['necessary', 'analytics']];
        yield 'granular drops unknown tokens' => ['necessary,bogus,marketing', ['necessary', 'marketing']];
        yield 'a single unknown token grants only necessary' => ['garbage', ['necessary']];
    }

    #[Test]
    public function itReturnsEmptyOffTheRequestEdge(): void
    {
        self::assertSame([], new RequestConsent(new RequestStack())->current());
    }

    /**
     * @param list<string> $expected
     */
    #[Test]
    #[DataProvider('cookies')]
    public function itMapsTheConsentCookie(?string $cookie, array $expected): void
    {
        $requests = new RequestStack();
        $requests->push(Request::create(
            '/',
            'GET',
            cookies: null === $cookie ? [] : [RequestConsent::COOKIE => $cookie],
        ));

        self::assertSame($expected, new RequestConsent($requests)->current());
    }
}
