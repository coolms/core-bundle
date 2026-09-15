<?php

declare(strict_types=1);

namespace CoolMS\Core\Bundle\Tests\Secret;

use CoolMS\Core\Bundle\Secret\KeyRingSealer;
use CoolMS\Core\Bundle\Secret\SealedValueState;
use CoolMS\Core\Bundle\Tests\Secret\Support\StaticRing;
use CoolMS\Core\Secret\MasterKeyException;
use CoolMS\Core\Secret\SealedValueException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function random_bytes;
use function sodium_bin2base64;
use function sodium_crypto_secretbox;
use function str_starts_with;
use function strlen;
use function substr;

use const SODIUM_BASE64_VARIANT_ORIGINAL;
use const SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;

/**
 * The shared codec over the ring: what it writes, what it reads, and -- the
 * part a rotation rests on -- which key it says opened a value, for every
 * form the estate has ever written.
 */
final class KeyRingSealerTest extends TestCase
{
    #[Test]
    public function sealsUnderTheCurrentKeyAndNamesItInTheStoredForm(): void
    {
        $current = StaticRing::fresh();
        $sealer = new KeyRingSealer(new StaticRing($current, StaticRing::fresh()));

        $stored = $sealer->seal('the value');

        self::assertTrue(str_starts_with($stored, 'enc:v2:' . $current->id . ':'));
        self::assertTrue(KeyRingSealer::isCurrentForm($stored));
        $opened = $sealer->open($stored);
        self::assertSame('the value', $opened->plaintext);
        self::assertTrue($opened->key->is($current));
        self::assertTrue($opened->currentForm);
        self::assertSame(SealedValueState::Current, $sealer->classify($stored));
    }

    #[Test]
    public function aValueSealedUnderTheOldKeyOpensForAReaderHoldingBothAndSaysWhichKey(): void
    {
        $old = StaticRing::fresh();
        $new = StaticRing::fresh();
        $stored = new KeyRingSealer(new StaticRing($old))->seal('sealed before the rotation');

        $both = new KeyRingSealer(new StaticRing($new, $old));
        $opened = $both->open($stored);

        self::assertSame('sealed before the rotation', $opened->plaintext);
        self::assertTrue($opened->key->is($old));
        self::assertTrue($opened->currentForm, 'the form names the key that sealed it, and that key opened it');
        self::assertSame(SealedValueState::Previous, $both->classify($stored));
    }

    #[Test]
    public function aReaderHoldingOnlyTheNewKeyFailsByNameOnAnOldValue(): void
    {
        $old = StaticRing::fresh();
        $stored = new KeyRingSealer(new StaticRing($old))->seal('sealed before the rotation');
        $newOnly = new KeyRingSealer(new StaticRing(StaticRing::fresh()));

        self::assertSame(SealedValueState::Unreadable, $newOnly->classify($stored));
        $this->expectException(SealedValueException::class);
        $this->expectExceptionMessage('does not open under any key this host holds');
        $newOnly->open($stored);
    }

    #[Test]
    public function theTwoOlderFormsAreReadByTrialAndReportedAsNotTheCurrentForm(): void
    {
        $old = StaticRing::fresh();
        $new = StaticRing::fresh();
        $both = new KeyRingSealer(new StaticRing($new, $old));

        // bare base64(nonce || box): a mailbox password, a SIP secret, the secrets file
        $bare = self::bareBox('a mailbox password', $old->bytes());
        $opened = $both->open($bare);
        self::assertSame('a mailbox password', $opened->plaintext);
        self::assertTrue($opened->key->is($old));
        self::assertFalse($opened->currentForm);
        self::assertSame(SealedValueState::Previous, $both->classify($bare));

        // enc:v1: + the same: a second factor
        $v1 = 'enc:v1:' . self::bareBox('JBSWY3DPEHPK3PXP', $new->bytes());
        $opened = $both->open($v1);
        self::assertSame('JBSWY3DPEHPK3PXP', $opened->plaintext);
        self::assertTrue($opened->key->is($new));
        self::assertFalse($opened->currentForm, 'the old form does not name its key');
        self::assertSame(
            SealedValueState::Current,
            $both->classify($v1),
            'under the current key: a rotation leaves it, whatever the form',
        );
        self::assertTrue(KeyRingSealer::isMarked($v1));
        self::assertFalse(KeyRingSealer::isMarked($bare));
    }

    #[Test]
    public function aNamedKeyTheRingDoesNotHoldIsAHintNotARefusal(): void
    {
        // Sealed under key A, but the stored id says some other key: the box is the proof.
        $a = StaticRing::fresh();
        $stored = new KeyRingSealer(new StaticRing($a))->seal('x');
        $misnamed = 'enc:v2:0000000000000000:' . substr($stored, strlen('enc:v2:' . $a->id . ':'));

        $opened = new KeyRingSealer(new StaticRing($a))->open($misnamed);

        self::assertSame('x', $opened->plaintext);
        self::assertFalse($opened->currentForm);
    }

    #[Test]
    public function malformedInputIsRefusedAsMalformedNotAsAWrongKey(): void
    {
        $sealer = new KeyRingSealer(new StaticRing(StaticRing::fresh()));

        foreach (['enc:v2:', 'enc:v2:abc:', 'enc:v2::payload', 'not base64!', 'enc:v1:short'] as $bad) {
            try {
                $sealer->open($bad);
                self::fail($bad . ' was accepted');
            } catch (SealedValueException $e) {
                self::assertStringContainsString('not in a recognised form', $e->getMessage(), $bad);
            }
        }
    }

    #[Test]
    public function aTamperedValueIsUndecryptableUnderTheRightKey(): void
    {
        $key = StaticRing::fresh();
        $sealer = new KeyRingSealer(new StaticRing($key));
        $stored = $sealer->seal('x');
        $last = $stored[strlen($stored) - 2];
        $tampered = substr($stored, 0, -2) . ('A' === $last ? 'B' : 'A') . substr($stored, -1);

        self::assertSame(SealedValueState::Unreadable, $sealer->classify($tampered));
    }

    #[Test]
    public function withoutACurrentKeyNothingCanBeSealedAndThatIsSaidByName(): void
    {
        $sealer = new KeyRingSealer(new StaticRing());

        self::assertFalse($sealer->isConfigured());
        $this->expectException(MasterKeyException::class);
        $sealer->seal('x');
    }

    private static function bareBox(string $plain, string $key): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        $box = sodium_crypto_secretbox($plain, $nonce, $key);

        return sodium_bin2base64($nonce . $box, SODIUM_BASE64_VARIANT_ORIGINAL);
    }
}
