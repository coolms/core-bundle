<?php

declare(strict_types=1);

namespace CoolMS\Core\Bundle\Tests\Transaction;

use CoolMS\Core\Bundle\Transaction\HoldAsyncDispatchMiddleware;
use CoolMS\Core\Bundle\Transaction\MessageUnitOfWorkMiddleware;
use CoolMS\Core\Bundle\Transaction\ReleasedAfterCommitStamp;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Middleware\StackMiddleware;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Transport\Sender\SenderInterface;
use Symfony\Component\Messenger\Transport\Sender\SendersLocatorInterface;

#[CoversClass(MessageUnitOfWorkMiddleware::class)]
#[CoversClass(HoldAsyncDispatchMiddleware::class)]
final class MessengerUnitOfWorkTest extends TestCase
{
    private RecordingUnitOfWork $unit;

    #[Test]
    public function aMessageReceivedFromAnAsyncTransportIsOneUnitThatCommits(): void
    {
        $this->handle(new MessageUnitOfWorkMiddleware(true, $this->unit), new Envelope(new stdClass(), [new ReceivedStamp('async')]));

        self::assertSame(['begin', 'commit'], $this->unit->calls);
    }

    #[Test]
    public function aFailingHandlerDiscardsAndTheFailureGoesOn(): void
    {
        $failing = new class implements MiddlewareInterface {
            public function handle(Envelope $envelope, StackInterface $stack): Envelope
            {
                throw new RuntimeException('the handler failed');
            }
        };

        try {
            new MessageUnitOfWorkMiddleware(true, $this->unit)->handle(
                new Envelope(new stdClass(), [new ReceivedStamp('async')]),
                new StackMiddleware($failing),
            );
            self::fail('the failure was swallowed');
        } catch (RuntimeException) {
        }
        self::assertSame(['begin', 'discard'], $this->unit->calls);
    }

    #[Test]
    public function aSyncTransportsMessageIsItsCallersWork(): void
    {
        $this->handle(new MessageUnitOfWorkMiddleware(true, $this->unit), new Envelope(new stdClass(), [new ReceivedStamp('sync')]));
        $this->handle(new MessageUnitOfWorkMiddleware(true, $this->unit), new Envelope(new stdClass()));

        self::assertSame([], $this->unit->calls, 'a sync message, and one not received at all, open nothing');
    }

    #[Test]
    public function anAsyncDispatchInsideAUnitIsSentOnlyAfterTheCommit(): void
    {
        $sent = [];
        $hold = $this->hold(['async'], $sent);
        $this->unit->begin();

        $next = $this->handle($hold, new Envelope(new stdClass()));

        self::assertFalse($next, 'held: the rest of the bus did not see it');
        self::assertSame([], $sent, 'not before the commit');
        $this->unit->commit();
        self::assertCount(1, $sent, 'sent after it');
        self::assertNotNull($sent[0]->last(ReleasedAfterCommitStamp::class), 'marked, so the hold lets it pass');
    }

    #[Test]
    public function anAsyncDispatchInsideADiscardedUnitIsNeverSent(): void
    {
        $sent = [];
        $hold = $this->hold(['async'], $sent);
        $this->unit->begin();
        $this->handle($hold, new Envelope(new stdClass()));
        $this->unit->discard();

        self::assertSame([], $sent);
    }

    #[Test]
    public function aSyncRoutedMessageAReleasedOneAndAnyOutsideAUnitPass(): void
    {
        $sent = [];
        $this->unit->begin();
        self::assertTrue($this->handle($this->hold(['sync'], $sent), new Envelope(new stdClass())), 'sync-routed: handled now');
        self::assertTrue($this->handle($this->hold(['async'], $sent), new Envelope(new stdClass(), [new ReleasedAfterCommitStamp()])), 'released');
        $this->unit->discard();
        self::assertTrue($this->handle($this->hold(['async'], $sent), new Envelope(new stdClass())), 'no unit open');
    }

    protected function setUp(): void
    {
        $this->unit = new RecordingUnitOfWork();
    }

    /**
     * @param list<string>   $aliases the transports the message is routed to
     * @param list<Envelope> $sent
     */
    private function hold(array $aliases, array &$sent): HoldAsyncDispatchMiddleware
    {
        $senders = new class($aliases) implements SendersLocatorInterface {
            /** @param list<string> $aliases */
            public function __construct(private readonly array $aliases)
            {
            }

            public function getSenders(Envelope $envelope): iterable
            {
                foreach ($this->aliases as $alias) {
                    yield $alias => new class implements SenderInterface {
                        public function send(Envelope $envelope): Envelope
                        {
                            return $envelope;
                        }
                    };
                }
            }
        };
        $bus = new class($sent) implements MessageBusInterface {
            /** @param list<Envelope> $sent */
            public function __construct(private array &$sent)
            {
            }

            public function dispatch(object $message, array $stamps = []): Envelope
            {
                $envelope = Envelope::wrap($message, $stamps);
                $this->sent[] = $envelope;

                return $envelope;
            }
        };

        return new HoldAsyncDispatchMiddleware(true, $this->unit, $this->unit, $senders, static fn (): MessageBusInterface => $bus);
    }

    /** @return bool whether the rest of the bus was reached */
    private function handle(MiddlewareInterface $middleware, Envelope $envelope): bool
    {
        $reached = false;
        $rest = new class($reached) implements MiddlewareInterface {
            public function __construct(private bool &$reached)
            {
            }

            public function handle(Envelope $envelope, StackInterface $stack): Envelope
            {
                $this->reached = true;

                return $envelope;
            }
        };
        $middleware->handle($envelope, new StackMiddleware($rest));

        return $reached;
    }
}
