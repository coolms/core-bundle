<?php

declare(strict_types=1);

namespace CoolMS\Core\Bundle\Tests\Transaction;

use CoolMS\Core\Transaction\UnitOfWorkException;
use CoolMS\Core\Transaction\UnitOfWorkInterface;
use CoolMS\Core\Transaction\UnitOfWorkOwnerInterface;

/** A unit of work that records what its owners asked of it, and runs after-commit effects as the contract says. */
final class RecordingUnitOfWork implements UnitOfWorkInterface, UnitOfWorkOwnerInterface
{
    /** @var list<string> */
    public array $calls = [];

    public bool $failCommit = false;
    private bool $open = false;

    /** @var list<callable(): void> */
    private array $effects = [];

    public function begin(): void
    {
        $this->calls[] = 'begin';
        $this->open = true;
    }

    public function commit(): void
    {
        $this->calls[] = 'commit';
        $effects = $this->effects;
        $this->open = false;
        $this->effects = [];
        if ($this->failCommit) {
            throw new UnitOfWorkException('the commit failed');
        }
        foreach ($effects as $effect) {
            $effect();
        }
    }

    public function discard(): void
    {
        $this->calls[] = 'discard';
        $this->open = false;
        $this->effects = [];
    }

    public function isUsable(): bool
    {
        return true;
    }

    public function ownerActive(): bool
    {
        return $this->open;
    }

    public function transactional(callable $work): mixed
    {
        return $work();
    }

    public function afterCommit(callable $effect): void
    {
        if ($this->open) {
            $this->effects[] = $effect;

            return;
        }
        $effect();
    }
}
