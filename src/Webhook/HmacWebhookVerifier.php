<?php

declare(strict_types=1);

namespace CoolMS\CoreBundle\Webhook;

use CoolMS\Core\Secret\SecretStoreInterface;
use CoolMS\Core\Webhook\WebhookSignatureException;

use function hash_equals;
use function hash_hmac;
use function str_starts_with;
use function substr;

/**
 * Verifies the HMAC-SHA256 signature on a public inbound-webhook POST whose
 * shared signing secret lives in the F1 SECRET STORE under a per-channel key.
 * The reusable primitive behind the Email + Call inbound
 * webhooks (and any future secret-store-keyed webhook) — each caller passes its
 * own `$secretKey`.
 *
 * Fail-closed: the secret is resolved FIRST, so an UNCONFIGURED endpoint rejects
 * every request uniformly — the missing secret throws
 * {@see \CoolMS\Core\Secret\SecretNotFoundException}, which the controller
 * maps to 503. Past that, a missing/wrong signature is a
 * {@see WebhookSignatureException} → 401. Accepts an optional `sha256=` prefix
 * (GitHub/Stripe-style) so existing provider tooling can sign as-is.
 *
 * NB: this is for SECRET-STORE-keyed webhooks; the M5.d Connector inbound
 * webhooks carry a per-trigger DB-column secret (a different custody model) and
 * are intentionally not folded in here.
 */
final readonly class HmacWebhookVerifier
{
    public function __construct(
        private SecretStoreInterface $secrets,
    ) {
    }

    /**
     * @throws WebhookSignatureException                   when the signature is absent or wrong
     * @throws \CoolMS\Core\Secret\SecretNotFoundException when no signing secret is configured
     */
    public function verify(string $rawBody, ?string $providedSignature, string $secretKey): void
    {
        // Resolve the secret first so an unconfigured endpoint fails the same
        // way for everyone (controller → 503), never leaking config state.
        $secret = $this->secrets->getRequired($secretKey);

        if (null === $providedSignature || '' === $providedSignature) {
            throw WebhookSignatureException::missingSignature();
        }

        $provided = str_starts_with($providedSignature, 'sha256=')
            ? substr($providedSignature, 7)
            : $providedSignature;

        if (!hash_equals(hash_hmac('sha256', $rawBody, $secret), $provided)) {
            throw WebhookSignatureException::invalidSignature();
        }
    }
}
