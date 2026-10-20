<?php

declare(strict_types=1);

namespace CoolMS\Core\Bundle\Transaction;

use CoolMS\Core\Transaction\UnitOfWorkOwnerInterface;
use LogicException;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Event\ConsoleErrorEvent;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Throwable;

use function in_array;

/**
 * A console command's unit of work, decided by its exit code: 0 commits; anything else, or an error, discards. The
 * transaction begins lazily, so a command that refuses before it writes exits with its own code -- never a connection
 * error. A long-running command (a worker, a listener, a loop: `coolms_core.transaction.unscoped_commands`) is not one
 * unit; its messages and events are.
 */
final class ConsoleUnitOfWorkOwner implements EventSubscriberInterface
{
    private bool $mine = false;
    private bool $failed = false;

    /** @param list<string> $unscoped */
    public function __construct(
        private readonly bool $enabled,
        private readonly ?UnitOfWorkOwnerInterface $unit,
        private readonly array $unscoped,
        private readonly LoggerInterface $logger,
    ) {
        if ($enabled && null === $unit) {
            throw new LogicException('coolms_core.transaction.mode is on, and no CoolMS\Core\Transaction\UnitOfWorkOwnerInterface service exists.');
        }
    }

    public static function getSubscribedEvents(): array
    {
        return [
            ConsoleEvents::COMMAND => ['open', 4096],
            ConsoleEvents::ERROR => ['failed', 4096],
            ConsoleEvents::TERMINATE => ['decide', -1020],
        ];
    }

    public function open(ConsoleCommandEvent $event): void
    {
        if (!$this->enabled || null === $this->unit || in_array($event->getCommand()?->getName(), $this->unscoped, true)) {
            return;
        }
        $this->unit->begin();
        $this->mine = true;
        $this->failed = false;
    }

    public function failed(ConsoleErrorEvent $event): void
    {
        $this->failed = true;
    }

    public function decide(ConsoleTerminateEvent $event): void
    {
        if (!$this->mine || null === $this->unit) {
            return;
        }
        $this->mine = false;
        if ($this->failed || 0 !== $event->getExitCode()) {
            $this->unit->discard();

            return;
        }
        try {
            $this->unit->commit();
        } catch (Throwable $e) {
            $this->logger->error('The command could not commit its unit of work', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
                'command' => $event->getCommand()?->getName(),
            ]);
            $event->setExitCode(1);
        }
    }
}
