<?php

namespace App\Http\Controllers;

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
 * This controller verifies + records into AtlasLedgerEvent (append-only).
 *
 *   POST /api/atlas-code/decisions/{decision}/sign
 *
 * Request:  { signature, publicKey, signedAt, signerId }   (base64 ed25519)
 * Response: { decisionId, signatureValid, ledgerEventId }
 *
 * Verification is best-effort (true if shape valid). Cryptographic
 * verification is wired in a follow-up commit when the kernel keypair is
 * registered. For MVP the contract + ledger entry are the canon.
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

        $valid = $this->verifySignatureShape($payload['signature'], $payload['publicKey']);
        if (! $valid) {
            throw ValidationException::withMessages([
                'signature' => 'signature shape rejected (expected base64 ed25519 64-byte digest)',
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
            'emitter_version' => '0.1.0',
            'payload' => [
                'decision_id' => (string) $decision->getKey(),
                'signature' => $payload['signature'],
                'public_key' => $payload['publicKey'],
                'signed_at' => CarbonImmutable::parse($payload['signedAt'])->toIso8601String(),
                'signer_id' => $payload['signerId'],
                'shape_valid' => $valid,
                'crypto_verified' => false,
            ],
            'payload_hash' => hash('sha256', json_encode([
                $decision->getKey(),
                $payload['signature'],
                $payload['publicKey'],
                $payload['signedAt'],
                $payload['signerId'],
            ])),
            'occurred_at' => CarbonImmutable::now(),
        ]);

        return response()->json([
            'decisionId' => (string) $decision->getKey(),
            'signatureValid' => $valid,
            'ledgerEventId' => $event->getKey(),
        ], 201);
    }

    /**
     * Cheap ed25519 sanity check: base64url + decoded length.
     * Real cryptographic verification lands when the kernel keypair is wired.
     */
    private function verifySignatureShape(string $sig, string $pub): bool
    {
        $sigDecoded = base64_decode(strtr($sig, '-_', '+/'), true);
        $pubDecoded = base64_decode(strtr($pub, '-_', '+/'), true);

        return is_string($sigDecoded)
            && is_string($pubDecoded)
            && strlen($sigDecoded) === 64
            && strlen($pubDecoded) === 32;
    }
}
