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
    #[TestWith(['""', null])]
    public function readsTheOneVersionNamed(?string $header, ?string $expected): void
    {
        self::assertSame($expected, IfMatchVersion::parse($header));
    }
}
