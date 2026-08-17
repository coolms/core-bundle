<?php

declare(strict_types=1);

namespace CoolMS\CoreBundle\Tests\Webhook;

use CoolMS\Core\Secret\SecretNotFoundException;
use CoolMS\Core\Secret\SecretStoreInterface;
use CoolMS\Core\Webhook\WebhookSignatureException;
use CoolMS\CoreBundle\Webhook\HmacWebhookVerifier;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function hash_hmac;

/**
 * The shared HMAC inbound-webhook gate behind the Email + Call
 * webhooks. Pins the signature contract (sha256 over the raw body, optional
 * `sha256=` prefix), the per-channel `$secretKey`, and fail-closed-when-no-secret.
 */
#[AllowMockObjectsWithoutExpectations]
final class HmacWebhookVerifierTest extends TestCase
{
    private const string KEY = 'some_channel_webhook';

    private const string SECRET = 'super-secret-shared-key';

    private const string BODY = '{"from":"jane@example.com"}';

    #[Test]
    public function itAcceptsACorrectBareSignature(): void
    {
        $this->configuredVerifier()->verify(self::BODY, hash_hmac('sha256', self::BODY, self::SECRET), self::KEY);

        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function itAcceptsACorrectSha256PrefixedSignature(): void
    {
        $this->configuredVerifier()->verify(self::BODY, 'sha256=' . hash_hmac('sha256', self::BODY, self::SECRET), self::KEY);

        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function itRejectsAMissingSignature(): void
    {
        $this->expectException(WebhookSignatureException::class);

        $this->configuredVerifier()->verify(self::BODY, null, self::KEY);
    }

    #[Test]
    public function itRejectsABlankSignature(): void
    {
        $this->expectException(WebhookSignatureException::class);

        $this->configuredVerifier()->verify(self::BODY, '', self::KEY);
    }

    #[Test]
    public function itRejectsAWrongSignature(): void
    {
        $this->expectException(WebhookSignatureException::class);

        $this->configuredVerifier()->verify(self::BODY, hash_hmac('sha256', self::BODY, 'wrong-key'), self::KEY);
    }

    #[Test]
    public function itResolvesTheSecretByTheGivenKey(): void
    {
        $store = $this->createMock(SecretStoreInterface::class);
        $store->expects(self::once())->method('getRequired')->with(self::KEY)->willReturn(self::SECRET);

        new HmacWebhookVerifier($store)->verify(self::BODY, hash_hmac('sha256', self::BODY, self::SECRET), self::KEY);
    }

    #[Test]
    public function itPropagatesWhenNoSigningSecretIsConfigured(): void
    {
        $store = $this->createMock(SecretStoreInterface::class);
        $store->method('getRequired')->willThrowException(new SecretNotFoundException(self::KEY));

        $this->expectException(SecretNotFoundException::class);

        new HmacWebhookVerifier($store)->verify(self::BODY, hash_hmac('sha256', self::BODY, self::SECRET), self::KEY);
    }

    private function configuredVerifier(): HmacWebhookVerifier
    {
        $store = $this->createMock(SecretStoreInterface::class);
        $store->method('getRequired')->willReturn(self::SECRET);

        return new HmacWebhookVerifier($store);
    }
}
