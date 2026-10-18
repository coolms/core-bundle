<?php

declare(strict_types=1);

namespace CoolMS\Core\Bundle\Tests\ApiPlatform\Resource\Processor;

use Closure;
use CoolMS\Core\Application\Dashboard\DashboardArrangingUnavailableException;
use CoolMS\Core\Application\Dashboard\StaleDashboardLayoutsException;
use CoolMS\Core\Bundle\ApiPlatform\Resource\Processor\DashboardLayoutWrite;
use CoolMS\Core\Bundle\EventListener\DashboardVersionAnswer;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\PreconditionFailedHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Throwable;

/**
 * What a layout save and reset read from the request and how their outcomes are
 * answered: the dashboard, the version named, 412 / 501 / 422, and the version
 * written handed to the ETag.
 */
#[CoversClass(DashboardLayoutWrite::class)]
final class DashboardLayoutWriteTest extends TestCase
{
    #[Test]
    public function theSectionAndTheVersionAreReadFromTheRequest(): void
    {
        $write = $this->write(Request::create('/api/v1/dashboard/layout?section=content', 'PUT', server: [
            'HTTP_IF_MATCH' => '"v3"',
        ]));

        self::assertSame('content', $write->section());
        self::assertSame('v3', $write->expectedVersion());
    }

    #[Test]
    public function noSectionIsTheMainDashboardAndNoIfMatchNamesNoVersion(): void
    {
        $write = $this->write(Request::create('/api/v1/dashboard/layout?section=', 'PUT'));

        self::assertNull($write->section());
        self::assertNull($write->expectedVersion());
    }

    #[Test]
    public function theVersionWrittenIsLeftForTheEtag(): void
    {
        $request = Request::create('/api/v1/dashboard/layout', 'PUT');

        $version = $this->write($request)->answer(static fn (): string => 'v4');

        self::assertSame('v4', $version);
        self::assertSame('v4', $request->attributes->get(DashboardVersionAnswer::VERSION));
    }

    #[Test]
    public function aStaleVersionIs412(): void
    {
        $thrown = $this->refusal(static fn (): string => throw new StaleDashboardLayoutsException());

        self::assertInstanceOf(PreconditionFailedHttpException::class, $thrown);
        self::assertStringContainsString('changed elsewhere', $thrown->getMessage());
    }

    #[Test]
    public function anInstallationThatKeepsNoArrangementsIs501(): void
    {
        $thrown = $this->refusal(static fn (): string => throw new DashboardArrangingUnavailableException());

        self::assertInstanceOf(HttpException::class, $thrown);
        self::assertSame(501, $thrown->getStatusCode());
    }

    #[Test]
    public function aWidgetNotOfferedIs422(): void
    {
        $unknown = new InvalidArgumentException('No installed module offers x.');

        $thrown = $this->refusal(static fn (): string => throw $unknown);

        self::assertInstanceOf(UnprocessableEntityHttpException::class, $thrown);
    }

    /** A refused write leaves no version behind: nothing was written, so no ETag may say otherwise. */
    #[Test]
    public function aRefusedWriteLeavesNoVersion(): void
    {
        $request = Request::create('/api/v1/dashboard/layout', 'PUT');

        try {
            $this->write($request)->answer(static fn (): string => throw new StaleDashboardLayoutsException());
        } catch (PreconditionFailedHttpException) {
        }

        self::assertFalse($request->attributes->has(DashboardVersionAnswer::VERSION));
    }

    /** @param Closure(): string $write */
    private function refusal(Closure $write): HttpException
    {
        try {
            $this->write(Request::create('/api/v1/dashboard/layout', 'PUT'))->answer($write);
        } catch (HttpException $e) {
            return $e;
        } catch (Throwable $e) {
            self::fail('Expected an HTTP answer, got ' . $e::class);
        }

        self::fail('The write was not refused.');
    }

    private function write(Request $request): DashboardLayoutWrite
    {
        $stack = new RequestStack();
        $stack->push($request);

        return new DashboardLayoutWrite($stack);
    }
}
