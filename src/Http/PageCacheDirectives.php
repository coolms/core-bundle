<?php

declare(strict_types=1);

namespace CoolMS\CoreBundle\Http;

use Symfony\Component\HttpFoundation\RequestStack;

/**
 * A request-scoped seam for a renderer to influence the anonymous full-page
 * cache (W8, the page-cache subscriber) from deep
 * inside a render — where only a string is produced, not the {@see \Symfony\Component\HttpFoundation\Response}.
 *
 * The one directive so far: {@see markUncacheable} — "this page's content is
 * TIME-SENSITIVE, do not freeze it in the shared cache". A widget rendering
 * live status (e.g. a "busy right NOW" overlay) raises it; the web
 * the volatile-response listener translates it to
 * `Cache-Control: no-store` on the response, which the page cache already
 * honours as its explicit opt-out.
 *
 * Lives in Core INFRASTRUCTURE (HTTP is fenced out of Domain/Application by
 * `CoolmsArchitectureRule`) so ANY module can raise it (an overlay, or a future
 * dynamic widget) and the Web module can read it — both are up-edges to Core, no
 * cross-module coupling. State rides on the main {@see \Symfony\Component\HttpFoundation\Request}
 * attributes (naturally request-scoped, so the shared service stays stateless);
 * off a request (CLI / tests) every call is a safe no-op.
 */
final readonly class PageCacheDirectives
{
    /** Request attribute set when the current page must NOT be stored in the anonymous page cache. */
    private const string ATTR_UNCACHEABLE = '_coolms_page_uncacheable';

    public function __construct(
        private RequestStack $requestStack,
    ) {
    }

    /**
     * Signal — DURING render — that the current page is time-sensitive and must
     * not be cached. Idempotent; a no-op when there is no active request.
     */
    public function markUncacheable(): void
    {
        $this->requestStack->getMainRequest()?->attributes->set(self::ATTR_UNCACHEABLE, true);
    }

    /** Whether {@see markUncacheable} was raised for the current request. */
    public function isUncacheable(): bool
    {
        return true === $this->requestStack->getMainRequest()?->attributes->get(self::ATTR_UNCACHEABLE);
    }
}
