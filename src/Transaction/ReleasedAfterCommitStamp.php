<?php

declare(strict_types=1);

namespace CoolMS\Core\Bundle\Transaction;

use Symfony\Component\Messenger\Stamp\StampInterface;

/** On a message sent after its unit of work committed: it passes the hold, which already held it once. */
final class ReleasedAfterCommitStamp implements StampInterface
{
}
