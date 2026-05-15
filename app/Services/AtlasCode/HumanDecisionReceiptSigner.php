<?php

declare(strict_types=1);

namespace App\Services\AtlasCode;

use RuntimeException;
use Throwable;

/**
 * Atlas Code · Ed25519 signer for human decision receipts.
 *
 * Canon:
 *   - docs/engineering-knowledge-base/atlas-code-obra-command-center-v1.md
 *     ("Toda decisão humana gera receipt")
 *   - docs/engineering-knowledge-base/atlas-code-interactive-observed-provider-workflow-v1.md
 *
 * Signs the canonical JSON projection of a decision so that even if the
 * filesystem/DB row is tampered, the signature won't verify. Appends a
 * one-line JSON record to the audit log (`storage/app/atlas-code/decision-
 * receipts.jsonl`) for offline review.
 *
 * Hard rules:
 *   - When no keypair is configured, returns signing_status='unavailable'
 *     honestly. NEVER signs with a synthetic key.
 *   - Signature is deterministic for a given payload (canonical JSON).
 *   - Audit log is append-only; service NEVER truncates or rewrites it.
 *   - Uses libsodium `sodium_crypto_sign_detached` (PHP 7.2+, always available).
 */
final class HumanDecisionReceiptSigner
{
    public const SCHEMA_VERSION = 'atlas.code.human_decision_receipt.v1';

    /**
     * @param  array<string, mixed>  $payload   Canonical fields to sign (session_id, packet_id, action, …).
     * @return array{
     *   schema_version: string,
     *   signed_at: string,
     *   signer_id: string,
     *   signing_status: 'signed'|'unavailable'|'error',
     *   signature: ?string,
     *   public_key: ?string,
     *   canonical_payload_hash: string,
     *   error: ?string
     * }
     */
    public function signDecision(array $payload): array
    {
        $signerId = (string) config('atlas_code_signing.signer_id', 'atlas-code-operator');
        $signedAt = now()->toJSON();
        $canonical = $this->canonicalJson($payload);
        $payloadHash = hash('sha256', $canonical);

        $base = [
            'schema_version' => self::SCHEMA_VERSION,
            'signed_at' => $signedAt,
            'signer_id' => $signerId,
            'canonical_payload_hash' => $payloadHash,
            'signature' => null,
            'public_key' => null,
            'error' => null,
        ];

        $keypairBase64 = (string) config('atlas_code_signing.keypair_base64', '');
        if ($keypairBase64 === '') {
            $receipt = array_merge($base, ['signing_status' => 'unavailable']);
            $this->appendAuditLog($receipt, $canonical);
            return $receipt;
        }

        try {
            $keypair = base64_decode($keypairBase64, true);
            if ($keypair === false || strlen($keypair) !== SODIUM_CRYPTO_SIGN_KEYPAIRBYTES) {
                return array_merge($base, [
                    'signing_status' => 'error',
                    'error' => 'invalid_keypair_length',
                ]);
            }
            $secret = sodium_crypto_sign_secretkey($keypair);
            $public = sodium_crypto_sign_publickey($keypair);
            $signature = sodium_crypto_sign_detached($canonical, $secret);
            sodium_memzero($secret);
        } catch (Throwable $e) {
            return array_merge($base, [
                'signing_status' => 'error',
                'error' => 'sign_failed:'.substr($e->getMessage(), 0, 200),
            ]);
        }

        $receipt = array_merge($base, [
            'signing_status' => 'signed',
            'signature' => base64_encode($signature),
            'public_key' => base64_encode($public),
        ]);
        $this->appendAuditLog($receipt, $canonical);
        return $receipt;
    }

    /**
     * Verify a signature against the original canonical payload.
     */
    public function verify(string $canonicalPayload, string $signatureBase64, string $publicKeyBase64): bool
    {
        try {
            $sig = base64_decode($signatureBase64, true);
            $pub = base64_decode($publicKeyBase64, true);
            if ($sig === false || $pub === false) {
                return false;
            }
            return sodium_crypto_sign_verify_detached($sig, $canonicalPayload, $pub);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Canonical JSON: keys sorted recursively, no spaces. Stable hash basis.
     *
     * @param  array<mixed>  $value
     */
    public function canonicalJson(array $value): string
    {
        $sorted = $this->sortKeys($value);
        $encoded = json_encode($sorted, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            throw new RuntimeException('canonical_json_encode_failed');
        }
        return $encoded;
    }

    /**
     * @param  mixed  $value
     * @return mixed
     */
    private function sortKeys(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        $isList = array_is_list($value);
        $mapped = array_map(fn ($v) => $this->sortKeys($v), $value);
        if ($isList) {
            return $mapped;
        }
        ksort($mapped);
        return $mapped;
    }

    /**
     * @param  array<string, mixed>  $receipt
     */
    private function appendAuditLog(array $receipt, string $canonicalPayload): void
    {
        $path = (string) config('atlas_code_signing.audit_log_path', storage_path('app/atlas-code/decision-receipts.jsonl'));
        try {
            $dir = dirname($path);
            if (! is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
            $line = json_encode([
                'receipt' => $receipt,
                'canonical_payload' => $canonicalPayload,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (! is_string($line)) {
                return;
            }
            @file_put_contents($path, $line.PHP_EOL, FILE_APPEND | LOCK_EX);
        } catch (Throwable) {
            // Audit log is best-effort. Signing decision still proceeds.
        }
    }
}
