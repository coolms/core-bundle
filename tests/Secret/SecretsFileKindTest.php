<?php

declare(strict_types=1);

namespace CoolMS\Core\Bundle\Tests\Secret;

use CoolMS\Core\Bundle\Secret\EncryptedSecretsFile;
use CoolMS\Core\Bundle\Secret\KeyRingSealer;
use CoolMS\Core\Bundle\Secret\SealedValueSweep;
use CoolMS\Core\Bundle\Secret\SecretsFileKind;
use CoolMS\Core\Bundle\Tests\Secret\Support\StaticRing;
use CoolMS\Core\Secret\SecretStoreException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function file_exists;
use function file_get_contents;
use function random_bytes;
use function sodium_bin2base64;
use function sodium_crypto_secretbox;
use function str_starts_with;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

use const SODIUM_BASE64_VARIANT_ORIGINAL;
use const SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;

/**
 * The secrets file under a rotation -- the kind found last and known least,
 * so the two-key property is asserted on it directly: a file written under
 * the old key loads for a ring holding both keys and fails BY NAME for a ring
 * holding only the new one. Then the sweep: one blob, re-sealed once, and
 * never again.
 */
final class SecretsFileKindTest extends TestCase
{
    private string $path;

    #[Test]
    public function aFileWrittenUnderTheOldKeyLoadsWithBothKeysAndFailsByNameWithTheNewOnly(): void
    {
        $old = StaticRing::fresh();
        $new = StaticRing::fresh();
        new EncryptedSecretsFile($this->path, new KeyRingSealer(new StaticRing($old)))->save(['k' => 'v']);

        $both = new EncryptedSecretsFile($this->path, new KeyRingSealer(new StaticRing($new, $old)));
        self::assertSame(['k' => 'v'], $both->load());

        $newOnly = new EncryptedSecretsFile($this->path, new KeyRingSealer(new StaticRing($new)));
        $this->expectException(SecretStoreException::class);
        $this->expectExceptionMessage('Cannot decrypt secrets file');
        $newOnly->load();
    }

    #[Test]
    public function theBareFormWrittenBeforeKeyIdsStillLoads(): void
    {
        $old = StaticRing::fresh();
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $blob = sodium_bin2base64($nonce . sodium_crypto_secretbox('{"k":"v"}', $nonce, $old->bytes()), SODIUM_BASE64_VARIANT_ORIGINAL);
        new EncryptedSecretsFile($this->path)->replaceRaw($blob);

        $file = new EncryptedSecretsFile($this->path, new KeyRingSealer(new StaticRing(StaticRing::fresh(), $old)));

        self::assertSame(['k' => 'v'], $file->load());
    }

    #[Test]
    public function theSweepResealsTheFileOnceAndTheSecondRunResealsNothing(): void
    {
        $old = StaticRing::fresh();
        $new = StaticRing::fresh();
        new EncryptedSecretsFile($this->path, new KeyRingSealer(new StaticRing($old)))->save(['k' => 'v']);
        $sealer = new KeyRingSealer(new StaticRing($new, $old));
        $file = new EncryptedSecretsFile($this->path, $sealer);
        $kind = new SecretsFileKind($file, new SealedValueSweep($sealer));

        $dry = $kind->sweep(false);
        self::assertSame([0, 1, 0, 0], [$dry->current, $dry->previous, $dry->unreadable, $dry->resealed]);
        self::assertFalse(str_starts_with((string) file_get_contents($this->path), 'enc:v2:' . $new->id), 'dry run writes nothing');

        $first = $kind->sweep(true);
        self::assertSame([0, 1, 0, 1], [$first->current, $first->previous, $first->unreadable, $first->resealed]);
        self::assertTrue(str_starts_with((string) file_get_contents($this->path), 'enc:v2:' . $new->id));
        self::assertSame(['k' => 'v'], new EncryptedSecretsFile($this->path, new KeyRingSealer(new StaticRing($new)))->load(), 'readable with the new key alone now');

        $second = $kind->sweep(true);
        self::assertSame([1, 0, 0, 0], [$second->current, $second->previous, $second->unreadable, $second->resealed]);
        self::assertTrue($second->closed());
    }

    #[Test]
    public function noFileIsNoValue(): void
    {
        $sealer = new KeyRingSealer(new StaticRing(StaticRing::fresh()));
        $kind = new SecretsFileKind(new EncryptedSecretsFile($this->path, $sealer), new SealedValueSweep($sealer));

        self::assertSame(0, $kind->sweep(true)->total());
        self::assertStringContainsString($this->path, $kind->name());
    }

    protected function setUp(): void
    {
        $this->path = (string) tempnam(sys_get_temp_dir(), 'secrets-');
        unlink($this->path);
    }

    protected function tearDown(): void
    {
        if (file_exists($this->path)) {
            unlink($this->path);
        }
    }
}
