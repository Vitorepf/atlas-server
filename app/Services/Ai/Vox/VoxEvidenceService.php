<?php

declare(strict_types=1);

namespace App\Services\Ai\Vox;

use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Throwable;

/**
 * Emits VOX_* events. Always returns the structured event packets so the
 * HTTP response can show the operator (and tests can assert) exactly what
 * was recorded. Additionally writes to the AtlasEvidenceLedger when its
 * underlying table exists; the ledger silently no-ops otherwise (V0 must
 * not depend on migrations to function as response-first).
 *
 * Hard rule (Lei 0 / Lei 0.75): audio bytes never appear in event
 * payloads. Only references and the `raw_pcm_persisted` invariant flag.
 */
final class VoxEvidenceService
{
    public function __construct(
        private readonly AtlasEvidenceLedger $ledger,
    ) {}

    /**
     * @param  array<string,mixed>  $transcript
     * @return array<string,mixed>
     */
    public function transcriptReady(array $transcript): array
    {
        return $this->emit(
            type: LedgerEventType::VoxTranscriptReady,
            kind: 'VOX_TRANSCRIPT_READY',
            payload: [
                'session_id' => (string) ($transcript['session_id'] ?? ''),
                'transcript_id' => (string) ($transcript['transcript_id'] ?? ''),
                'audio_handle' => (string) ($transcript['audio_handle'] ?? ''),
                'engine' => (string) ($transcript['engine'] ?? ''),
                'language' => (string) ($transcript['language'] ?? ''),
                'confidence' => (float) ($transcript['confidence'] ?? 0.0),
                'personal_dictionary_applied' => $transcript['personal_dictionary_applied'] ?? [],
                'raw_pcm_persisted' => (bool) ($transcript['raw_pcm_persisted'] ?? false),
            ],
            envelopeId: (string) ($transcript['session_id'] ?? 'vox-unknown'),
        );
    }

    /**
     * @param  array<string,mixed>  $intentPacket
     * @return array<string,mixed>
     */
    public function intentCompiled(array $intentPacket): array
    {
        return $this->emit(
            type: LedgerEventType::VoxIntentCompiled,
            kind: 'VOX_INTENT_COMPILED',
            payload: [
                'session_id' => (string) ($intentPacket['session_id'] ?? ''),
                'intent_id' => (string) ($intentPacket['intent_id'] ?? ''),
                'mode' => (string) ($intentPacket['mode'] ?? ''),
                'goal' => (string) ($intentPacket['goal'] ?? ''),
                'risk_class' => (string) ($intentPacket['risk_class'] ?? ''),
                'provider_hint' => (string) ($intentPacket['provider_hint'] ?? ''),
                'executor_hint' => (string) ($intentPacket['executor_hint'] ?? ''),
                'compiler_version' => (string) ($intentPacket['compiler_version'] ?? ''),
            ],
            envelopeId: (string) ($intentPacket['session_id'] ?? 'vox-unknown'),
        );
    }

    /**
     * @param  array<string,mixed>  $intentPacket
     * @param  array<string,mixed>  $receipt
     * @return array<string,mixed>
     */
    public function policyEvaluated(array $intentPacket, array $receipt): array
    {
        return $this->emit(
            type: LedgerEventType::VoxPolicyEvaluated,
            kind: 'VOX_POLICY_EVALUATED',
            payload: [
                'session_id' => (string) ($intentPacket['session_id'] ?? ''),
                'intent_id' => (string) ($intentPacket['intent_id'] ?? ''),
                'receipt_id' => (string) ($receipt['receipt_id'] ?? ''),
                'risk_class' => (string) ($intentPacket['risk_class'] ?? ''),
                'provider_call_allowed' => (bool) ($receipt['provider_call_allowed'] ?? false),
                'tool_call_allowed' => (bool) ($receipt['tool_call_allowed'] ?? false),
                'raw_audio_allowed' => (bool) ($receipt['raw_audio_allowed'] ?? false),
                'external_side_effect_allowed' => (bool) ($receipt['external_side_effect_allowed'] ?? false),
                'action_authorized' => (string) ($receipt['action_authorized'] ?? ''),
            ],
            envelopeId: (string) ($intentPacket['session_id'] ?? 'vox-unknown'),
            receiptId: (string) ($receipt['receipt_id'] ?? ''),
        );
    }

    /**
     * @param  array<string,mixed>  $actionOutcome
     * @return array<string,mixed>
     */
    public function evidenceRecorded(array $actionOutcome): array
    {
        return $this->emit(
            type: LedgerEventType::VoxEvidenceRecorded,
            kind: 'VOX_EVIDENCE_RECORDED',
            payload: [
                'outcome_id' => (string) ($actionOutcome['outcome_id'] ?? ''),
                'session_id' => (string) ($actionOutcome['session_id'] ?? ''),
                'intent_id' => (string) ($actionOutcome['intent_id'] ?? ''),
                'receipt_id' => (string) ($actionOutcome['receipt_id'] ?? ''),
                'executor' => (string) ($actionOutcome['executor'] ?? ''),
                'status' => (string) ($actionOutcome['status'] ?? ''),
                'duration_ms' => (int) ($actionOutcome['duration_ms'] ?? 0),
                'follow_up_required' => (bool) ($actionOutcome['follow_up_required'] ?? false),
                'executor_violations' => $actionOutcome['executor_violations'] ?? [],
            ],
            envelopeId: (string) ($actionOutcome['session_id'] ?? 'vox-unknown'),
            receiptId: (string) ($actionOutcome['receipt_id'] ?? ''),
        );
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    public function actionBlocked(string $reasonCode, string $message, array $payload = []): array
    {
        return $this->emit(
            type: LedgerEventType::VoxActionBlocked,
            kind: 'VOX_ACTION_BLOCKED',
            payload: array_merge([
                'reason_code' => $reasonCode,
                'message' => $message,
            ], $payload),
            envelopeId: (string) ($payload['session_id'] ?? 'vox-unknown'),
        );
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function emit(
        LedgerEventType $type,
        string $kind,
        array $payload,
        string $envelopeId,
        ?string $receiptId = null,
    ): array {
        $eventId = (string) Str::ulid();
        $occurredAt = Carbon::now('UTC')->toIso8601String();

        $packet = [
            'event_id' => $eventId,
            'event_kind' => $kind,
            'event_type' => $type->value,
            'occurred_at' => $occurredAt,
            'emitter' => 'atlas.kernel.vox.v0',
            'envelope_id' => $envelopeId,
            'receipt_id' => $receiptId,
            'payload' => $payload,
        ];

        // Best-effort ledger write. If the table doesn't exist (V0
        // response-first deployments) the ledger no-ops; if it throws we
        // swallow because the response packet is the authoritative
        // audit surface here.
        try {
            $this->ledger->record($type, $payload, [
                'event_id' => $eventId,
                'envelope_id' => $envelopeId,
                'receipt_id' => $receiptId,
                'emitter_stage' => 'atlas.kernel.vox.v0',
                'emitter_version' => 'v1',
                'occurred_at' => $occurredAt,
            ]);
        } catch (Throwable $e) {
            $packet['ledger_persist_error'] = $e->getMessage();
        }

        return $packet;
    }
}
