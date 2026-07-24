<?php

namespace App\Services\Ai\Kernel\Decision;

use App\Services\Ai\EngineeringKernel\Adapters\ReceiptHashTrait;

final class DecisionReceiptHash
{
    use ReceiptHashTrait {
        canonicalize as private traitCanonicalize;
    }

    private const V3_CANARY_SELECTION_DOMAIN = 'atlas.decide.v3.canary-selection.v1';

    private const V3_LIVE_AUTHORITY_DOMAIN = 'atlas.decide.v3.live-authority.v1';

    /**
     * @param  array<string,mixed>  $payload
     */
    public static function hash(array $payload): string
    {
        $canonical = json_encode(
            self::canonicalize($payload),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );

        return hash('sha256', (string) $canonical);
    }

    /**
     * Explicit façade keeps the runtime contract discoverable by static gates
     * while the shared trait remains the single canonicalization implementation.
     *
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    public static function canonicalize(array $payload): array
    {
        return self::traitCanonicalize($payload);
    }

    /**
     * V3 signs the complete transport envelope except its self-referential
     * receipt_hash. Unlike V2's historical hash payloads, no authority field
     * is selected piecemeal: tenant/principal audience, scope, effect, budget,
     * nonce, revocation head and separation-of-duties remain in one basis.
     *
     * @param  array<string,mixed>  $receipt
     */
    public static function v3FullEnvelopeHash(array $receipt): string
    {
        unset($receipt['receipt_hash']);

        return hash('sha256', json_encode(
            self::canonicalizeV3($receipt),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ));
    }

    /**
     * @param  array<string,mixed>  $receipt
     */
    public static function v3FullEnvelopeHashMatches(array $receipt): bool
    {
        $provided = $receipt['receipt_hash'] ?? null;

        return is_string($provided)
            && preg_match('/^[a-f0-9]{64}$/', $provided) === 1
            && hash_equals(self::v3FullEnvelopeHash($receipt), $provided);
    }

    /**
     * Selects a canary bucket from a server-minted envelope id only. The HMAC
     * makes the deterministic selection unavailable to caller-controlled
     * receipt ids while preserving replay stability for a given envelope.
     */
    public static function v3CanaryBucketForEnvelope(string $envelopeId): ?int
    {
        $key = self::domainKey(self::V3_CANARY_SELECTION_DOMAIN);
        $envelopeId = trim($envelopeId);
        if ($key === null || $envelopeId === '') {
            return null;
        }

        $digest = hash_hmac('sha256', $envelopeId, $key);

        return hexdec(substr($digest, 0, 8)) % 100;
    }

    /**
     * A cutover-live V3 receipt carries a server-keyed authority signature in
     * addition to its unkeyed transport checksum. The checksum detects drift;
     * only this HMAC may authorize a live effect after cutover.
     *
     * @param  array<string,mixed>  $receipt
     */
    public static function v3LiveAuthoritySignature(array $receipt): ?string
    {
        $key = self::domainKey(self::V3_LIVE_AUTHORITY_DOMAIN);
        if ($key === null) {
            return null;
        }

        unset($receipt['receipt_hash'], $receipt['authority_signature']);

        try {
            $canonical = json_encode(
                self::canonicalizeV3($receipt),
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            );
        } catch (\Throwable) {
            return null;
        }

        return hash_hmac('sha256', $canonical, $key);
    }

    /**
     * @param  array<string,mixed>  $receipt
     */
    public static function v3LiveAuthoritySignatureMatches(array $receipt): bool
    {
        $provided = $receipt['authority_signature'] ?? null;
        $expected = self::v3LiveAuthoritySignature($receipt);

        return is_string($provided)
            && preg_match('/^[a-f0-9]{64}$/', $provided) === 1
            && is_string($expected)
            && hash_equals($expected, $provided);
    }

    private static function decodedApplicationKey(): ?string
    {
        $configured = config('app.key');
        if (! is_string($configured)) {
            return null;
        }

        $configured = trim($configured);
        if (str_starts_with($configured, 'base64:')) {
            $decoded = base64_decode(substr($configured, strlen('base64:')), true);

            return is_string($decoded) && strlen($decoded) >= 32 ? $decoded : null;
        }

        // Laravel permits an already-raw APP_KEY in addition to base64: form.
        // It is valid server key material only at the same minimum strength.
        return strlen($configured) >= 32 ? $configured : null;
    }

    private static function domainKey(string $domain): ?string
    {
        $appKey = self::decodedApplicationKey();

        return $appKey === null ? null : hash_hmac('sha256', $domain, $appKey, true);
    }

    private static function canonicalizeV3(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $isList = array_is_list($value);
        if (! $isList) {
            ksort($value);
        }

        foreach ($value as $key => $item) {
            $value[$key] = self::canonicalizeV3($item);
        }

        return $isList ? array_values($value) : $value;
    }
}
