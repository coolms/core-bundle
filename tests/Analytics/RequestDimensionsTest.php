<?php

declare(strict_types=1);

namespace CoolMS\CoreBundle\Tests\Analytics;

use CoolMS\CoreBundle\Analytics\RequestDimensions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * The derive-and-drop request-dimensions seam (Track E, Phase 1): coarse
 * device/os/browser families + referrer type + geo country + `utm_*` campaign
 * tags from the current request.
 */
final class RequestDimensionsTest extends TestCase
{
    /** @return iterable<string, array{0: ?string, 1: string}> */
    public static function referrers(): iterable
    {
        yield 'none' => [null, 'direct'];
        yield 'same host' => ['https://mysite.test/other', 'internal'];
        yield 'search engine' => ['https://www.google.com/search?q=widgets', 'search'];
        yield 'social' => ['https://t.co/abc123', 'social'];
        yield 'external' => ['https://example.org/landing', 'external'];
    }

    #[Test]
    public function itReturnsEmptyOffTheRequestEdge(): void
    {
        self::assertSame([], new RequestDimensions(new RequestStack())->current());
    }

    #[Test]
    public function itDerivesMobileSafariOnIos(): void
    {
        $ua = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1';
        $dims = $this->dimsFor('/', ['HTTP_USER_AGENT' => $ua]);

        self::assertSame('mobile', $dims['device']);
        self::assertSame('ios', $dims['os']);
        self::assertSame('safari', $dims['browser']);
        self::assertSame('direct', $dims['referrer']);
    }

    #[Test]
    public function itPrefersEdgeOverChromeOnWindowsDesktop(): void
    {
        $ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36 Edg/120.0.0.0';
        $dims = $this->dimsFor('/', ['HTTP_USER_AGENT' => $ua]);

        self::assertSame('desktop', $dims['device']);
        self::assertSame('windows', $dims['os']);
        self::assertSame('edge', $dims['browser']);
    }

    #[Test]
    public function itClassifiesABot(): void
    {
        $dims = $this->dimsFor('/', ['HTTP_USER_AGENT' => 'Googlebot/2.1 (+http://www.google.com/bot.html)']);

        self::assertSame('bot', $dims['device']);
    }

    #[Test]
    public function aMissingUserAgentIsUnknown(): void
    {
        // A bare Request has no User-Agent header (unlike Request::create, which injects a default).
        $requests = new RequestStack();
        $requests->push(new Request());

        $dims = new RequestDimensions($requests)->current();

        self::assertSame('unknown', $dims['device']);
        self::assertSame('unknown', $dims['browser']);
    }

    #[Test]
    #[DataProvider('referrers')]
    public function itClassifiesTheReferrerType(?string $referer, string $expected): void
    {
        $server = ['HTTP_USER_AGENT' => 'UA'];
        if (null !== $referer) {
            $server['HTTP_REFERER'] = $referer;
        }

        self::assertSame($expected, $this->dimsFor('https://mysite.test/', $server)['referrer']);
    }

    #[Test]
    public function itDerivesTheCountryFromAnEdgeGeoHeader(): void
    {
        $dims = $this->dimsFor('/', ['HTTP_CF_IPCOUNTRY' => 'us']);

        self::assertSame('US', $dims['geo']);
    }

    #[Test]
    public function itOmitsGeoWithoutACountryHeader(): void
    {
        self::assertArrayNotHasKey('geo', $this->dimsFor('/', ['HTTP_USER_AGENT' => 'UA']));
    }

    #[Test]
    public function itOmitsGeoForANonCountryMarker(): void
    {
        // A CDN's "unknown/Tor" marker like `T1` isn't a plain 2-letter code.
        self::assertArrayNotHasKey('geo', $this->dimsFor('/', ['HTTP_CF_IPCOUNTRY' => 'T1']));
    }

    #[Test]
    public function itPrefersTheCloudflareCountryHeader(): void
    {
        $dims = $this->dimsFor('/', ['HTTP_CF_IPCOUNTRY' => 'gb', 'HTTP_X_GEO_COUNTRY' => 'us']);

        self::assertSame('GB', $dims['geo']);
    }

    #[Test]
    public function itDerivesUtmFromTheQueryStringAndClassesTheReferrerAsCampaign(): void
    {
        $dims = $this->dimsFor('/landing?utm_source=Google&utm_medium=CPC&utm_campaign=Summer_Sale', ['HTTP_USER_AGENT' => 'UA']);

        // Normalised: trimmed + lowercased (low-cardinality).
        self::assertSame('google', $dims['utm_source']);
        self::assertSame('cpc', $dims['utm_medium']);
        self::assertSame('summer_sale', $dims['utm_campaign']);
        // A UTM tag classifies the visit as a campaign.
        self::assertSame('campaign', $dims['referrer']);
    }

    #[Test]
    public function itFallsBackToTheRefererQueryAndCampaignBeatsTheHostBasedClass(): void
    {
        // Same-host referer would be 'internal' — but the UTM tag makes it 'campaign'.
        $dims = $this->dimsFor('https://mysite.test/submit', [
            'HTTP_USER_AGENT' => 'UA',
            'HTTP_REFERER' => 'https://mysite.test/landing?utm_source=newsletter&utm_campaign=spring',
        ]);

        self::assertSame('newsletter', $dims['utm_source']);
        self::assertSame('spring', $dims['utm_campaign']);
        self::assertSame('campaign', $dims['referrer']);
    }

    #[Test]
    public function theCurrentQueryUtmWinsOverTheRefererUtm(): void
    {
        $dims = $this->dimsFor('https://mysite.test/x?utm_source=query', [
            'HTTP_USER_AGENT' => 'UA',
            'HTTP_REFERER' => 'https://mysite.test/landing?utm_source=referer',
        ]);

        self::assertSame('query', $dims['utm_source']);
    }

    #[Test]
    public function aNonCampaignVisitCarriesNoUtmDimsAndKeepsItsReferrerClass(): void
    {
        $dims = $this->dimsFor('https://mysite.test/', [
            'HTTP_USER_AGENT' => 'UA',
            'HTTP_REFERER' => 'https://example.org/landing',
        ]);

        self::assertArrayNotHasKey('utm_source', $dims);
        self::assertArrayNotHasKey('utm_campaign', $dims);
        self::assertSame('external', $dims['referrer'], 'no UTM → the host-based referrer class stands');
    }

    #[Test]
    public function itDropsBlankAndCapsOverlongUtmValues(): void
    {
        $dims = $this->dimsFor('/l?utm_source=&utm_medium=email&utm_campaign=' . str_repeat('x', 200), ['HTTP_USER_AGENT' => 'UA']);

        self::assertArrayNotHasKey('utm_source', $dims, 'a blank UTM value is dropped');
        self::assertSame('email', $dims['utm_medium']);
        self::assertSame(100, mb_strlen((string) $dims['utm_campaign']), 'an overlong UTM value is length-capped');
    }

    /**
     * @param array<string, string> $server
     *
     * @return array<string, scalar>
     */
    private function dimsFor(string $uri, array $server): array
    {
        $requests = new RequestStack();
        $requests->push(Request::create($uri, 'GET', server: $server));

        return new RequestDimensions($requests)->current();
    }
}
