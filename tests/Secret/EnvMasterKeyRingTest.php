<?php

declare(strict_types=1);

namespace CoolMS\Core\Bundle\Tests\Secret;

use CoolMS\Core\Bundle\Secret\EnvMasterKeyRing;
use CoolMS\Core\Secret\MasterKey;
use CoolMS\Core\Secret\MasterKeyException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function base64_encode;
use function random_bytes;

/**
 * The ring read from the environment: the current key, the previous key set
 * only during a window, and the refusals -- a set-but-broken key is named,
 * never skipped, because a mistyped previous key would otherwise make every
 * old value "unreadable" without a word.
 */
final class EnvMasterKeyRingTest extends TestCase
{
    private const string CURRENT = 'COOLMS_TEST_RING_CURRENT';

    private const string PREVIOUS = 'COOLMS_TEST_RING_PREVIOUS';

    #[Test]
    public function oneKeyIsARingOfOne(): void
    {
        $_ENV[self::CURRENT] = base64_encode($bytes = random_bytes(32));
        $ring = new EnvMasterKeyRing(self::CURRENT, self::PREVIOUS);

        self::assertTrue($ring->isConfigured());
        self::assertSame($bytes, $ring->current()->bytes());
        self::assertCount(1, $ring->keys());
        self::assertNotNull($ring->byId(new MasterKey($bytes)->id));
    }

    #[Test]
    public function thePreviousKeyComesSecondAndOnlyDuringAWindow(): void
    {
        $_ENV[self::CURRENT] = base64_encode($new = random_bytes(32));
        $_ENV[self::PREVIOUS] = base64_encode($old = random_bytes(32));
        $ring = new EnvMasterKeyRing(self::CURRENT, self::PREVIOUS);

        $keys = $ring->keys();
        self::assertCount(2, $keys);
        self::assertSame($new, $keys[0]->bytes());
        self::assertSame($old, $keys[1]->bytes());
        self::assertSame($keys[1]->id, $ring->byId($keys[1]->id)?->id);

        unset($_ENV[self::PREVIOUS]);
        self::assertCount(1, $ring->keys(), 'read on each call: the window closes the moment the variable goes');
    }

    #[Test]
    public function theSameKeyInBothVariablesIsOneKey(): void
    {
        $_ENV[self::CURRENT] = $_ENV[self::PREVIOUS] = base64_encode(random_bytes(32));

        self::assertCount(1, new EnvMasterKeyRing(self::CURRENT, self::PREVIOUS)->keys());
    }

    #[Test]
    public function noCurrentKeyIsNotConfiguredAndSaysWhichVariable(): void
    {
        $ring = new EnvMasterKeyRing(self::CURRENT, self::PREVIOUS);

        self::assertFalse($ring->isConfigured());
        self::assertSame([], $ring->keys());
        $this->expectException(MasterKeyException::class);
        $this->expectExceptionMessage(self::CURRENT);
        $ring->current();
    }

    #[Test]
    public function aBrokenPreviousKeyIsRefusedByNameRatherThanIgnored(): void
    {
        $_ENV[self::CURRENT] = base64_encode(random_bytes(32));
        $_ENV[self::PREVIOUS] = 'this is not a key';

        $this->expectException(MasterKeyException::class);
        $this->expectExceptionMessage(self::PREVIOUS);
        new EnvMasterKeyRing(self::CURRENT, self::PREVIOUS)->keys();
    }

    #[Test]
    public function aKeyOfTheWrongLengthIsRefusedByName(): void
    {
        $_ENV[self::CURRENT] = base64_encode(random_bytes(16));

        $ring = new EnvMasterKeyRing(self::CURRENT, self::PREVIOUS);
        self::assertFalse($ring->isConfigured());
        $this->expectException(MasterKeyException::class);
        $this->expectExceptionMessage('32 bytes');
        $ring->current();
    }

    protected function tearDown(): void
    {
        unset($_ENV[self::CURRENT], $_ENV[self::PREVIOUS]);
    }
}
