<?php

declare(strict_types=1);

namespace CoolMS\Core\Bundle\Tests\Secret;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function random_bytes;
use function sodium_crypto_secretbox;
use function sodium_crypto_secretbox_keygen;
use function sodium_crypto_secretbox_open;
use function str_repeat;
use function strlen;

use const SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;

/**
 * The property the whole rotation design rests on, tested before anything is
 * built on it.
 *
 * Every kind of value this platform seals -- a second factor, a mailbox
 * password, an OAuth grant, a SIP secret, an email body, the secrets file --
 * goes through `sodium_crypto_secretbox`. That box AUTHENTICATES: opening it
 * under a key other than the one that sealed it fails deterministically (the
 * function returns false) rather than yielding wrong bytes. So a reader that
 * holds two keys can try the current key and then the previous one and always
 * knows which opened it; and a reader that holds only the new key fails BY NAME
 * on a value still sealed under the old one, never silently.
 *
 * If this did not hold for the primitive, a half-finished rotation would not
 * be readable, and the incremental design would not apply. It does not hold
 * because anyone said so; it holds because this file runs.
 */
final class SecretBoxTwoKeyPropertyTest extends TestCase
{
    /** @return iterable<string, array{string}> the shapes of plaintext the six kinds seal */
    public static function plaintexts(): iterable
    {
        yield 'a base32 factor secret' => ['JBSWY3DPEHPK3PXP'];
        yield 'a mailbox password' => ['hunter2-imap-password'];
        yield 'an OAuth grant, json' => ['{"access_token":"ya29.a0","refresh_token":"1//0g","expires_at":1760000000}'];
        yield 'a SIP secret' => ['s1p-s3cr3t'];
        yield 'an email body, multi-line' => ["From: a@example.test\r\nSubject: hi\r\n\r\nbody\r\n"];
        yield 'the secrets map, json' => ['{"stripe_api_key":"sk_live_1","telegram.bot":"t0k"}'];
        yield 'a large value' => [str_repeat('x', 1_000_000)];
    }

    #[Test]
    #[DataProvider('plaintexts')]
    public function aValueSealedUnderTheOldKeyOpensUnderTheOldKeyAndFailsUnderTheNewOne(string $plain): void
    {
        $old = sodium_crypto_secretbox_keygen();
        $new = sodium_crypto_secretbox_keygen();
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $box = sodium_crypto_secretbox($plain, $nonce, $old);

        self::assertFalse(sodium_crypto_secretbox_open($box, $nonce, $new), 'the wrong key fails, never garbage');
        self::assertSame($plain, sodium_crypto_secretbox_open($box, $nonce, $old));
    }

    #[Test]
    public function aReaderHoldingBothKeysAnswersCorrectlyWhicheverKeySealedTheValue(): void
    {
        $old = sodium_crypto_secretbox_keygen();
        $new = sodium_crypto_secretbox_keygen();
        $ring = [$new, $old]; // new first, old second: the order a rotation reads in

        foreach ([$old, $new] as $sealedUnder) {
            $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $box = sodium_crypto_secretbox('the value', $nonce, $sealedUnder);

            $opened = null;
            $openedUnder = null;
            foreach ($ring as $i => $key) {
                $plain = sodium_crypto_secretbox_open($box, $nonce, $key);
                if (false !== $plain) {
                    $opened = $plain;
                    $openedUnder = $i;
                    break;
                }
            }

            self::assertSame('the value', $opened);
            self::assertSame($sealedUnder === $new ? 0 : 1, $openedUnder, 'the reader knows which key opened it');
        }
    }

    #[Test]
    public function aReaderHoldingOnlyTheNewKeyFailsDeterministicallyOnAnOldValue(): void
    {
        $old = sodium_crypto_secretbox_keygen();
        $new = sodium_crypto_secretbox_keygen();
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $box = sodium_crypto_secretbox('the value', $nonce, $old);

        // Ten attempts, ten refusals: no key-dependent luck in the answer.
        for ($i = 0; $i < 10; ++$i) {
            self::assertFalse(sodium_crypto_secretbox_open($box, $nonce, $new));
        }
    }

    #[Test]
    public function aTamperedBoxFailsUnderTheRightKeyToo(): void
    {
        $key = sodium_crypto_secretbox_keygen();
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $box = sodium_crypto_secretbox('the value', $nonce, $key);
        $box[strlen($box) - 1] = 'a' === $box[strlen($box) - 1] ? 'b' : 'a';

        self::assertFalse(sodium_crypto_secretbox_open($box, $nonce, $key), 'authentication, not just secrecy');
    }
}
