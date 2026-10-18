<?php

declare(strict_types=1);

namespace CoolMS\Core\Bundle\Tests\EventListener;

use CoolMS\Core\Bundle\EventListener\DashboardVersionAnswer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * The version of a person's arrangements on the wire: the strong ETag a save
 * names back, and an answer no shared cache may keep.
 */
#[CoversClass(DashboardVersionAnswer::class)]
final class DashboardVersionAnswerTest extends TestCase
{
    #[Test]
    public function aSuccessfulAnswerCarriesTheVersionAsAStrongEtagAndIsNeverStored(): void
    {
        $response = $this->answer('v7', new Response('{}', 200));

        self::assertSame('"v7"', $response->headers->get('ETag'));
        self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
        self::assertTrue($response->headers->hasCacheControlDirective('private'));
        self::assertFalse($response->headers->hasCacheControlDirective('public'));
    }

    /** A refused save names no version -- nothing was written -- but is still one person's answer. */
    #[Test]
    public function aRefusalCarriesNoEtag(): void
    {
        $response = $this->answer('v7', new Response('{}', 412));

        self::assertNull($response->headers->get('ETag'));
        self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
    }

    #[Test]
    public function aRequestThatIsNotADashboardOneIsLeftAlone(): void
    {
        $response = $this->answer(null, new Response('{}', 200));

        self::assertNull($response->headers->get('ETag'));
        self::assertFalse($response->headers->hasCacheControlDirective('no-store'));
    }

    private function answer(?string $version, Response $response): Response
    {
        $request = new Request();
        if (null !== $version) {
            $request->attributes->set(DashboardVersionAnswer::VERSION, $version);
        }

        new DashboardVersionAnswer()(new ResponseEvent(
            $this->createStub(HttpKernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
            $response,
        ));

        return $response;
    }
}
