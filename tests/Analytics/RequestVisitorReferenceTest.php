<?php

declare(strict_types=1);

namespace CoolMS\CoreBundle\Tests\Analytics;

use CoolMS\Core\Analytics\VisitorReferenceGeneratorInterface;
use CoolMS\CoreBundle\Analytics\RequestVisitorReference;
use DateTimeImmutable;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * The request-edge `visitorRef` seam (Track E, Phase 1): resolves the current
 * request's client IP + user-agent through the Core generator, and degrades to
 * null off the request edge.
 */
final class RequestVisitorReferenceTest extends TestCase
{
    #[Test]
    public function itMintsARefFromTheCurrentRequestIpAndUserAgent(): void
    {
        $requests = new RequestStack();
        $requests->push(Request::create('/', 'GET', server: [
            'REMOTE_ADDR' => '203.0.113.7',
            'HTTP_USER_AGENT' => 'TestAgent/1.0',
        ]));
        $generator = new class implements VisitorReferenceGeneratorInterface {
            public function forVisitor(string $ipAddress, string $userAgent, DateTimeImmutable $at): string
            {
                return "ref:{$ipAddress}|{$userAgent}";
            }
        };

        $ref = new RequestVisitorReference($requests, $generator, new MockClock())->current();

        self::assertSame('ref:203.0.113.7|TestAgent/1.0', $ref);
    }

    #[Test]
    public function itReturnsNullOffTheRequestEdge(): void
    {
        $ref = new RequestVisitorReference(new RequestStack(), $this->neverGenerator(), new MockClock())->current();

        self::assertNull($ref);
    }

    #[Test]
    public function itReturnsNullWhenTheRequestHasNoClientIp(): void
    {
        $requests = new RequestStack();
        $requests->push(new Request()); // empty server bag → getClientIp() === null

        $ref = new RequestVisitorReference($requests, $this->neverGenerator(), new MockClock())->current();

        self::assertNull($ref);
    }

    /** A generator that must never be reached when there is no resolvable client IP. */
    private function neverGenerator(): VisitorReferenceGeneratorInterface
    {
        return new class implements VisitorReferenceGeneratorInterface {
            public function forVisitor(string $ipAddress, string $userAgent, DateTimeImmutable $at): string
            {
                throw new LogicException('generator must not be called without a client IP');
            }
        };
    }
}
