<?php

declare(strict_types=1);

namespace CoolMS\CoreBundle\Tests\Http;

use CoolMS\CoreBundle\Http\PageCacheDirectives;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * The request-scoped page-cache directive: a render can raise "do not cache this
 * page" (time-sensitive content), read back later by the response listener. State
 * lives on the request attributes, so off a request every call is a safe no-op.
 */
#[CoversClass(PageCacheDirectives::class)]
final class PageCacheDirectivesTest extends TestCase
{
    #[Test]
    public function aFreshRequestIsCacheable(): void
    {
        $stack = new RequestStack();
        $stack->push(new Request());

        self::assertFalse(new PageCacheDirectives($stack)->isUncacheable());
    }

    #[Test]
    public function markUncacheableFlagsTheCurrentRequest(): void
    {
        $stack = new RequestStack();
        $stack->push($request = new Request());
        $directives = new PageCacheDirectives($stack);

        $directives->markUncacheable();

        self::assertTrue($directives->isUncacheable());
        // The flag rides on the request itself (so a shared service stays stateless).
        self::assertTrue($request->attributes->get('_coolms_page_uncacheable'));
    }

    #[Test]
    public function offARequestEveryCallIsASafeNoOp(): void
    {
        $directives = new PageCacheDirectives(new RequestStack()); // nothing pushed

        $directives->markUncacheable(); // must not throw

        self::assertFalse($directives->isUncacheable());
    }
}
