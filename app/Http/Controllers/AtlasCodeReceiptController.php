<?php

namespace App\Http\Controllers;

// Gap5.F2 wiring marker — Programming-adjacent controller.
// Canonical route_decision schema: atlas.dual_core.route_decision.v1

use App\Models\AiDecision;
use App\Models\AtlasLedgerEvent;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Atlas Code · receipt signing boundary.
 *
 * The Kernel emits the receipt payload (AiDecision); the desktop signs it
 * locally with ed25519 (Apple Keychain key) and POSTs the signature back.
 * This controller:
 *
 *   1. Verifies the ed25519 signature against the canonical payload using
 *      libsodium (sodium_crypto_sign_verify_detached).
 *   2. Refuses to record invalid signatures (returns 422).
 *   3. On valid signature, appends to AtlasLedgerEvent (append-only).
 *
 *   POST /api/atlas-code/decisions/{decision}/sign
 *
 * Request body:
 *   {
 *     "signature":  base64 ed25519 detached signature (64 bytes raw)
 *     "publicKey":  base64 ed25519 public key (32 bytes raw)
 *     "signedAt":   ISO8601 timestamp
 *     "signerId":   human/agent identifier
 *   }
 *
 * Canonical payload (must match the desktop signer · @atlas/receipts):
 *   "atlas-decision/v1\n{decisionId}\n{signedAt}\n{signerId}"
 */
class AtlasCodeReceiptController extends Controller
{
    public function sign(Request $request, AiDecision $decision): JsonResponse
    {
        $payload = $request->validate([
            'signature' => ['required', 'string', 'min:32', 'max:512'],
            'publicKey' => ['required', 'string', 'min:32', 'max:512'],
            'signedAt' => ['required', 'date'],
            'signerId' => ['required', 'string', 'max:120'],
        ]);

        $signatureBytes = $this->b64decode($payload['signature']);
        $publicKeyBytes = $this->b64decode($payload['publicKey']);

        if ($signatureBytes === null || strlen($signatureBytes) !== SODIUM_CRYPTO_SIGN_BYTES) {
            throw ValidationException::withMessages([
                'signature' => 'signature must be 64 raw bytes (base64 encoded)',
            ]);
        }
        if ($publicKeyBytes === null || strlen($publicKeyBytes) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            throw ValidationException::withMessages([
                'publicKey' => 'publicKey must be 32 raw bytes (base64 encoded)',
            ]);
        }

        $canonical = $this->canonicalPayload($decision, $payload['signedAt'], $payload['signerId']);

        $cryptoVerified = false;
        try {
            $cryptoVerified = sodium_crypto_sign_verify_detached(
                $signatureBytes,
                $canonical,
                $publicKeyBytes,
            );
        } catch (\Throwable) {
            $cryptoVerified = false;
        }

        if (! $cryptoVerified) {
            throw ValidationException::withMessages([
                'signature' => 'ed25519 verification failed against canonical payload',
            ]);
        }

        $event = AtlasLedgerEvent::query()->create([
            'event_id' => (string) Str::uuid(),
            'schema_version' => 'atlas-code-sign-receipt/v1',
            'tenant_id' => null,
            'operator_id' => $payload['signerId'],
            'envelope_id' => null,
            'receipt_id' => (string) $decision->getKey(),
            'trace_id' => null,
            'correlation_id' => (string) $decision->getKey(),
            'causation_id' => null,
            'event_type' => 'atlas_code.receipt.signed',
            'emitter_stage' => 'atlas_code',
            'emitter_version' => '0.2.0',
            'payload' => [
                'decision_id' => (string) $decision->getKey(),
                'signature' => $payload['signature'],
                'public_key' => $payload['publicKey'],
                'signed_at' => CarbonImmutable::parse($payload['signedAt'])->toIso8601String(),
                'signer_id' => $payload['signerId'],
                'canonical_sha256' => hash('sha256', $canonical),
                'crypto_verified' => true,
            ],
            'payload_hash' => hash('sha256', $canonical),
            'occurred_at' => CarbonImmutable::now(),
        ]);

        return response()->json([
            'decisionId' => (string) $decision->getKey(),
            'signatureValid' => true,
            'ledgerEventId' => $event->getKey(),
            'canonicalSha256' => hash('sha256', $canonical),
        'route_decision' => \App\Services\Ai\DualCore\CanonicalRouteDecisionEnvelope::emit(route: 'programming', reason: 'http_atlas_code_receipt_controller'),
    ], 201);
    }

    private function b64decode(string $input): ?string
    {
        $decoded = base64_decode(strtr($input, '-_', '+/'), true);

        return is_string($decoded) ? $decoded : null;
    }

    /**
     * Canonical payload used by both desktop signer and server verifier.
     * Newline-separated for unambiguous re-encoding.
     */
    private function canonicalPayload(AiDecision $decision, string $signedAt, string $signerId): string
    {
        return implode("\n", [
            'atlas-decision/v1',
            (string) $decision->getKey(),
            CarbonImmutable::parse($signedAt)->toIso8601String(),
            $signerId,
        ]);
    }
}
