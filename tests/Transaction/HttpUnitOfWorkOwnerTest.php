<?php

declare(strict_types=1);

namespace CoolMS\Core\Bundle\Tests\Transaction;

use CoolMS\Core\Bundle\Transaction\HttpUnitOfWorkOwner;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

#[CoversClass(HttpUnitOfWorkOwner::class)]
final class HttpUnitOfWorkOwnerTest extends TestCase
{
    private RecordingUnitOfWork $unit;

    #[Test]
    public function aSuccessfulRequestCommits(): void
    {
        $response = $this->serve(new Response('', 201));

        self::assertSame(['begin', 'commit'], $this->unit->calls);
        self::assertSame(201, $response->getStatusCode());
    }

    #[Test]
    public function aRefusalDiscards(): void
    {
        $this->serve(new Response('', 404));

        self::assertSame(['begin', 'discard'], $this->unit->calls);
    }

    #[Test]
    public function anExceptionDiscardsWhateverStatusTheResponseCarries(): void
    {
        $this->serve(new Response('', 200), new RuntimeException('failed'));

        self::assertSame(['begin', 'discard'], $this->unit->calls);
    }

    #[Test]
    public function aFailedCommitIsAnswered500NeverA2xx(): void
    {
        $this->unit->failCommit = true;

        $response = $this->serve(new Response('', 200));

        self::assertSame(['begin', 'commit'], $this->unit->calls);
        self::assertSame(500, $response->getStatusCode());
    }

    #[Test]
    public function aSubRequestNeverOpensCommitsOrDiscards(): void
    {
        $owner = $this->owner();
        $owner->open(new RequestEvent($this->kernel(), new Request(), HttpKernelInterface::SUB_REQUEST));
        $owner->decide(new ResponseEvent($this->kernel(), new Request(), HttpKernelInterface::SUB_REQUEST, new Response()));

        self::assertSame([], $this->unit->calls);
    }

    #[Test]
    public function aUnitLeftOpenAtTerminateIsDiscarded(): void
    {
        $owner = $this->owner();
        $owner->open(new RequestEvent($this->kernel(), new Request(), HttpKernelInterface::MAIN_REQUEST));
        $owner->leftOpen(new TerminateEvent($this->kernel(), new Request(), new Response()));

        self::assertSame(['begin', 'discard'], $this->unit->calls);
    }

    #[Test]
    public function withTheModeOffItDoesNothing(): void
    {
        $this->serve(new Response('', 200), null, false);

        self::assertSame([], $this->unit->calls);
    }

    #[Test]
    public function theModeOnWithNoUnitOfWorkIsRefusedAtOnce(): void
    {
        $this->expectException(LogicException::class);
        new HttpUnitOfWorkOwner(true, null, new NullLogger());
    }

    protected function setUp(): void
    {
        $this->unit = new RecordingUnitOfWork();
    }

    private function serve(Response $response, ?RuntimeException $failure = null, bool $enabled = true): Response
    {
        $owner = $this->owner($enabled);
        $request = new Request();
        $owner->open(new RequestEvent($this->kernel(), $request, HttpKernelInterface::MAIN_REQUEST));
        if (null !== $failure) {
            $owner->failed(new ExceptionEvent($this->kernel(), $request, HttpKernelInterface::MAIN_REQUEST, $failure));
        }
        $event = new ResponseEvent($this->kernel(), $request, HttpKernelInterface::MAIN_REQUEST, $response);
        $owner->decide($event);

        return $event->getResponse();
    }

    private function owner(bool $enabled = true): HttpUnitOfWorkOwner
    {
        return new HttpUnitOfWorkOwner($enabled, $this->unit, new NullLogger());
    }

    private function kernel(): HttpKernelInterface
    {
        return $this->createStub(HttpKernelInterface::class);
    }
}
