<?php

declare(strict_types=1);

namespace CoolMS\CoreBundle\Analytics;

use CoolMS\Core\Analytics\CurrentVisitorReferenceInterface;
use CoolMS\Core\Analytics\VisitorReferenceGeneratorInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * The default {@see CurrentVisitorReferenceInterface}: reads the current HTTP
 * request's client IP + user-agent and mints the anonymous `visitorRef` through
 * the Core {@see VisitorReferenceGeneratorInterface} (whose impl hashes + drops
 * the raw IP/UA — derive-and-drop). The raw values never leave this method.
 *
 * Off the request edge — no current request (CLI / worker) or no resolvable
 * client IP — it returns null, so producers stamp a null ref and carry on.
 */
#[AsAlias(CurrentVisitorReferenceInterface::class)]
final readonly class RequestVisitorReference implements CurrentVisitorReferenceInterface
{
    public function __construct(
        private RequestStack $requests,
        private VisitorReferenceGeneratorInterface $generator,
        private ClockInterface $clock,
    ) {
    }

    public function current(): ?string
    {
        $request = $this->requests->getCurrentRequest();
        if (null === $request) {
            return null;
        }

        $ip = $request->getClientIp();
        if (null === $ip || '' === $ip) {
            return null;
        }

        return $this->generator->forVisitor(
            $ip,
            $request->headers->get('User-Agent') ?? '',
            $this->clock->now(),
        );
    }
}
