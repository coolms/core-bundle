<?php

declare(strict_types=1);

namespace CoolMS\Core\Bundle\Tests\Http;

use CoolMS\Core\Bundle\Http\IfMatchVersion;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

/** One version named, or none: what a write based its change on. */
#[CoversClass(IfMatchVersion::class)]
final class IfMatchVersionTest extends TestCase
{
    #[Test]
    #[TestWith(['"abc"', 'abc'])]
    #[TestWith(['W/"abc"', 'abc'])]
    #[TestWith(['abc', 'abc'])]
    #[TestWith([' "abc" ', 'abc'])]
    #[TestWith([null, null])]
    #[TestWith(['', null])]
    #[TestWith(['*', null])]
    #[TestWith(['"a", "b"', null])]
    #[TestWith(['""', ''])]
    #[TestWith(['W/""', ''])]
    public function readsTheOneVersionNamed(?string $header, ?string $expected): void
    {
        self::assertSame($expected, IfMatchVersion::parse($header));
    }

    #[Test]
    #[TestWith(['"a", "b"', true])]
    #[TestWith(['"a",W/"b"', true])]
    #[TestWith(['"a"', false])]
    #[TestWith(['*', false])]
    #[TestWith([null, false])]
    public function tellsAListFromOneVersion(?string $header, bool $several): void
    {
        self::assertSame($several, IfMatchVersion::namesSeveral($header));
    }
}
