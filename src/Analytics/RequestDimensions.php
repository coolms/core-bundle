<?php

declare(strict_types=1);

namespace CoolMS\CoreBundle\Analytics;

use CoolMS\Core\Analytics\CurrentRequestDimensionsInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * The default {@see CurrentRequestDimensionsInterface}: classifies the current
 * request's user-agent into coarse `device` / `os` / `browser` families and its
 * `Referer` into a `referrer` type — dep-free heuristics, no UA-parser library
 * (the codebase prefers dep-free derivers; the families are intentionally low-
 * cardinality, so a precise parser would be over-engineering). The raw UA +
 * referrer are read and dropped here — only the families reach the event store.
 *
 * **Geo:** the visitor's country, derived from the country header
 * a CDN/edge proxy injects (see GEO_HEADERS) — no MaxMind DB, no raw IP->geo
 * (the IP is already dropped). Country-level only (not PII, like the other dims);
 * absent off a geo-providing proxy (e.g. localhost). A configurable header name
 * is a trivial future extension.
 *
 * **UTM (campaign attribution):** the standard `utm_*` tags
 * (source/medium/campaign/term/content), read from the request query or the
 * `Referer` query, added as their own low-cardinality dims + classifying the
 * `referrer` as `'campaign'`. The design doc calls these "high-value and NOT
 * PII" (they are author-authored campaign labels, never identifying), so they
 * ride the same derive-and-drop seam as the other dims.
 */
#[AsAlias(CurrentRequestDimensionsInterface::class)]
final readonly class RequestDimensions implements CurrentRequestDimensionsInterface
{
    /** @var list<string> hosts classified as search engines (substring match on the referrer host) */
    private const array SEARCH_HOSTS = ['google.', 'bing.', 'duckduckgo.', 'yahoo.', 'yandex.', 'baidu.', 'ecosia.'];

    /** @var list<string> hosts classified as social networks */
    private const array SOCIAL_HOSTS = ['facebook.', 'fb.', 'twitter.', 't.co', 'x.com', 'linkedin.', 'lnkd.in', 'instagram.', 'reddit.', 'youtube.', 'youtu.be', 'pinterest.', 't.me', 'tiktok.'];

    /** @var list<string> country headers injected by common edge CDNs, in precedence order */
    private const array GEO_HEADERS = ['CF-IPCountry', 'CloudFront-Viewer-Country', 'X-Geo-Country'];

    /** @var list<string> the standard UTM campaign-attribution params (design §Acquisition) */
    private const array UTM_KEYS = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'];

    /** Length cap per UTM value — bounds cardinality + row size (a real campaign tag is short). */
    private const int UTM_MAX_LEN = 100;

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

        $ua = $request->headers->get('User-Agent') ?? '';

        // UTM campaign tags (if any) classify the referrer as 'campaign' AND add
        // their own utm_* dims — an explicit campaign tag wins over the host-based
        // referrer class (a utm-tagged same-site or search click is still campaign).
        $utm = $this->utm($request);

        $dims = [
            'device' => $this->device($ua),
            'os' => $this->os($ua),
            'browser' => $this->browser($ua),
            'referrer' => [] !== $utm ? 'campaign' : $this->referrerType($request),
        ];

        // Merge the present utm_* dims (no collision with the coarse dims above).
        foreach ($utm as $key => $value) {
            $dims[$key] = $value;
        }

        // Geo only when an edge proxy supplied a country header (absent on localhost).
        $geo = $this->geo($request);
        if (null !== $geo) {
            $dims['geo'] = $geo;
        }

        return $dims;
    }

    /**
     * UTM campaign-attribution params (the design classes them
     * "high-value and NOT PII"). Read from the current request's query string,
     * falling back to the `Referer` URL's query — a same-page conversion (the
     * events that actually reach the store are server-side conversion events, not
     * the tagged landing hit) carries the landing query only in `Referer`. Each
     * value is trimmed, length-capped + lowercased to stay low-cardinality;
     * absent/blank keys are dropped, so a non-campaign visit adds no `utm_*` dims.
     *
     * @return array<string, string> the present, normalised utm_* dims (empty when none)
     */
    private function utm(Request $request): array
    {
        $dims = $this->extractUtm($request->query->all());
        if ([] === $dims) {
            $dims = $this->extractUtm($this->refererQueryParams($request));
        }

        return $dims;
    }

    /**
     * @param array<array-key, mixed> $params
     *
     * @return array<string, string>
     */
    private function extractUtm(array $params): array
    {
        $dims = [];
        foreach (self::UTM_KEYS as $key) {
            $raw = $params[$key] ?? null;
            if (!is_string($raw)) {
                continue;
            }
            $value = trim($raw);
            if ('' === $value) {
                continue;
            }
            $dims[$key] = mb_strtolower(mb_substr($value, 0, self::UTM_MAX_LEN));
        }

        return $dims;
    }

    /**
     * The `Referer` URL's decoded query params (empty when there is no Referer or
     * it carries no query string).
     *
     * @return array<array-key, mixed>
     */
    private function refererQueryParams(Request $request): array
    {
        $referer = $request->headers->get('Referer');
        if (null === $referer || '' === $referer) {
            return [];
        }

        $query = parse_url($referer, PHP_URL_QUERY);
        if (!is_string($query) || '' === $query) {
            return [];
        }

        $params = [];
        parse_str($query, $params);

        return $params;
    }

    /**
     * The visitor's country from the first edge-CDN country header present, as an
     * uppercase 2-letter code; null when none is set or the value isn't a plain
     * 2-letter code (so a CDN's non-country marker like `T1` is dropped).
     */
    private function geo(Request $request): ?string
    {
        foreach (self::GEO_HEADERS as $header) {
            $value = $request->headers->get($header);
            if (null !== $value && 1 === preg_match('/^[a-zA-Z]{2}$/', $value)) {
                return strtoupper($value);
            }
        }

        return null;
    }

    private function device(string $ua): string
    {
        return match (true) {
            '' === $ua => 'unknown',
            (bool) preg_match('/bot|crawl|spider|slurp|headless/i', $ua) => 'bot',
            (bool) preg_match('/ipad|tablet|playbook|silk|(android(?!.*mobile))/i', $ua) => 'tablet',
            (bool) preg_match('/mobile|iphone|ipod|android.*mobile|windows phone/i', $ua) => 'mobile',
            default => 'desktop',
        };
    }

    private function os(string $ua): string
    {
        return match (true) {
            '' === $ua => 'unknown',
            (bool) preg_match('/windows/i', $ua) => 'windows',
            (bool) preg_match('/iphone|ipad|ipod|ios /i', $ua) => 'ios',
            (bool) preg_match('/mac os x|macintosh/i', $ua) => 'macos',
            (bool) preg_match('/android/i', $ua) => 'android',
            (bool) preg_match('/linux|x11/i', $ua) => 'linux',
            default => 'other',
        };
    }

    private function browser(string $ua): string
    {
        // Order matters: Edge/Opera/Chromium-forks identify as Chrome too.
        return match (true) {
            '' === $ua => 'unknown',
            (bool) preg_match('/edg(e|a|ios)?\//i', $ua) => 'edge',
            (bool) preg_match('/opr\/|opera/i', $ua) => 'opera',
            (bool) preg_match('/chrome|crios|chromium/i', $ua) => 'chrome',
            (bool) preg_match('/firefox|fxios/i', $ua) => 'firefox',
            (bool) preg_match('/safari/i', $ua) => 'safari',
            default => 'other',
        };
    }

    private function referrerType(Request $request): string
    {
        $referer = $request->headers->get('Referer');
        if (null === $referer || '' === $referer) {
            return 'direct';
        }

        $host = strtolower((string) parse_url($referer, PHP_URL_HOST));
        if ('' === $host) {
            return 'direct';
        }

        if ($host === strtolower($request->getHost())) {
            return 'internal';
        }
        foreach (self::SEARCH_HOSTS as $needle) {
            if (str_contains($host, $needle)) {
                return 'search';
            }
        }
        foreach (self::SOCIAL_HOSTS as $needle) {
            if (str_contains($host, $needle)) {
                return 'social';
            }
        }

        return 'external';
    }
}
