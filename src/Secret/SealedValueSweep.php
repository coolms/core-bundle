<?php

declare(strict_types=1);

namespace CoolMS\Core\Bundle\Secret;

use CoolMS\Core\Secret\RotationTally;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Service\ResetInterface;

use function count;
use function is_string;

/**
 * The sweep every kind runs, written once: read each stored value, say which
 * key opens it, and re-seal the ones under the previous key -- through a
 * callback the kind supplies, because the kind knows where its values live
 * and the sweep does not need to.
 *
 * Idempotent by construction: a value that opens under the current key is
 * counted and left, whatever form it is in, so a second run rewrites nothing
 * and a run that stops halfway resumes by being run again. A value that
 * opens under neither key is counted and left too; it is not this sweep's to
 * repair, only to report.
 *
 * BOUNDED, because a rotation is the emergency procedure and an emergency
 * procedure that dies on a real estate does not exist. {@see batched()} pages
 * a kind's values by key instead of loading the kind, this loop never holds
 * more than the row it is on, and between pages the process is asked to let
 * go of what reading the last page accumulated.
 *
 * Measured 2026-09-23 on the dev estate (5,686 values, 5,679 of them email
 * bodies) at the container's default 512M: unchanged, `coolms:secret:rotate
 * --dry-run` died of exhausted memory after 273.7 s. The holder was the ORM
 * identity map, which grows by one managed entity per file the VFS reads --
 * clearing it alone finished the same run in 40.1 s at a 406.8 MB peak. It
 * was NOT the debug query stack, which is the thing that looks guilty: the
 * same run with `--no-debug` still died, after 206.9 s. Letting the container
 * reset its resettable services between pages held the working set at
 * 38.7-60.0 MB across the whole estate, which is why the release is
 * `services_resetter` rather than a hand-picked list -- it reaches the
 * collectors and caches nobody thought to name, and it is the same seam a
 * Messenger worker uses between messages.
 *
 * The release is typed on the CONTRACT and wired to that service by id, not
 * typed on the class: CI's lowest-dependency job resolves
 * symfony/dependency-injection v8.0.0, where
 * `Symfony\Component\DependencyInjection\ServicesResetter` does not exist
 * yet -- it arrives in 8.1 -- while `ResetInterface` has been in
 * symfony/service-contracts throughout. Naming the interface is also the
 * truer statement: what this needs is something that lets go, and a host that
 * wants to hand it something narrower may.
 */
final readonly class SealedValueSweep
{
    /**
     * Rows per page, and therefore how often the process is asked to let go.
     */
    public const int BATCH = 500;

    public function __construct(
        private KeyRingSealer $sealer,
        #[Autowire(service: 'services_resetter')]
        private ?ResetInterface $release = null,
    ) {
    }

    /**
     * A kind's values, paged by KEY rather than by offset.
     *
     * `$fetch` receives the last key yielded (null on the first page) and a
     * limit, and returns that page as `[key, value]` rows in key order. Keyed
     * paging is what makes the sweep safe to re-run: a rotation REWRITES the
     * rows it reads, so an offset would shift under it, while a key that is
     * already past never comes back. It is also what makes a killed run
     * resumable at no cost -- the next run starts from the beginning and the
     * values it already re-sealed are skipped as "current" by the read.
     *
     * !! THE RELEASE HAPPENS BETWEEN PAGES, AND NOWHERE ELSE. The first
     * version of this released every N ROWS from inside {@see run()}, and
     * the apply run died where the dry run could not: rows are pulled from a
     * generator, so by the time a loop body runs, the row it is about to
     * write has ALREADY been read -- a release between those two moments
     * detaches the entity the write is holding, and `coolms:secret:rotate`
     * ended in a terminal flush complaining about an unpersisted
     * association. A page boundary is the only point in a pull-based walk
     * where the previous row is fully written and the next one is not yet
     * read.
     *
     * `$betweenPages` is the kind's chance to land what the last page wrote
     * before the process lets go of it -- a kind whose writes are deferred
     * to the end of the command MUST flush here, because the release clears
     * what has not been flushed. A kind that writes through immediately (the
     * DBAL columns) passes nothing.
     *
     * A page shorter than the limit is the last one. A `$fetch` that returns
     * a page whose last key did not move ends the walk rather than looping
     * for ever; that is a broken key column, not a reason to hang.
     *
     * @param callable(mixed, int): list<array<int, mixed>> $fetch
     * @param callable(): void|null                         $betweenPages
     *
     * @return iterable<int, array<int, mixed>>
     */
    public function batched(callable $fetch, ?callable $betweenPages = null, int $batch = self::BATCH): iterable
    {
        $after = null;
        $first = true;
        while (true) {
            if (!$first) {
                if (null !== $betweenPages) {
                    $betweenPages();
                }
                $this->release?->reset();
            }
            $first = false;
            $page = $fetch($after, $batch);
            if ([] === $page) {
                return;
            }
            $last = $after;
            foreach ($page as $row) {
                $after = $row[0] ?? null;
                yield $row;
            }
            if (count($page) < $batch || $after === $last) {
                return;
            }
        }
    }

    /**
     * Rows are [identifier, stored value]; a value that is not a non-empty string
     * (a NULL column) is not a value and is not counted. `$rewrite` receives the identifier and the re-sealed
     * value, and is only called when applying. A kind that also holds PLAINTEXT
     * values passes `$isSealed`; a kind with its own outer marker passes
     * `$unwrap` (strip it before opening) and `$wrap` (put it back after
     * re-sealing).
     *
     * @param iterable<mixed, array<int, mixed>> $rows
     * @param callable(mixed, string): mixed     $rewrite
     * @param callable(string): bool|null        $isSealed
     * @param callable(string): string|null      $unwrap
     * @param callable(string): string|null      $wrap
     */
    public function run(
        iterable $rows,
        callable $rewrite,
        bool $apply,
        ?callable $isSealed = null,
        ?callable $unwrap = null,
        ?callable $wrap = null,
    ): RotationTally {
        $current = $previous = $unreadable = $plaintext = $resealed = 0;
        foreach ($rows as $row) {
            $id = $row[0] ?? null;
            $stored = $row[1] ?? null;
            if (!is_string($stored) || '' === $stored) {
                continue;
            }
            if (null !== $isSealed && !$isSealed($stored)) {
                ++$plaintext;
                continue;
            }
            $inner = null !== $unwrap ? $unwrap($stored) : $stored;
            $state = $this->sealer->classify($inner);
            match ($state) {
                SealedValueState::Current => ++$current,
                SealedValueState::Unreadable => ++$unreadable,
                SealedValueState::Previous => ++$previous,
            };
            if (SealedValueState::Previous === $state && $apply) {
                $resealedValue = $this->sealer->seal($this->sealer->open($inner)->plaintext);
                $rewrite($id, null !== $wrap ? $wrap($resealedValue) : $resealedValue);
                ++$resealed;
            }
        }

        return new RotationTally($current, $previous, $unreadable, $plaintext, $resealed);
    }
}
