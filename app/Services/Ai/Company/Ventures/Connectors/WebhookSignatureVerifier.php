<?php

namespace App\Services\Ai\Company\Ventures\Connectors;

/**
 * K6 (core) — webhook signature verification.
 *
 * The reconciled-cash `source` tag is authenticated, not asserted: it is set
 * server-side ONLY after the inbound webhook's HMAC signature verifies against
 * the connector secret. A string tag is not authentication — an externally
 * POSTable webhook can never mint a verified source. Fail-closed.
 */
class WebhookSignatureVerifier
{
    public function verify(string $payload, string $signatureHex, string $secret): bool
    {
        if ($secret === '' || $signatureHex === '') {
            return false; // fail-closed: no secret/signature => not verified
        }
        $expected = hash_hmac('sha256', $payload, $secret);

        return hash_equals($expected, $signatureHex);
    }

    /**
     * Returns the reconciled `source` to stamp ONLY if the signature verifies;
     * otherwise null — the caller must NOT credit anything.
     */
    public function verifiedSource(string $payload, string $signatureHex, string $secret, string $source): ?string
    {
        return $this->verify($payload, $signatureHex, $secret) ? $source : null;
    }
}
