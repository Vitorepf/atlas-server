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
     * Emitted only in mode=prompt_polish, AFTER intentCompiled and BEFORE
     * policyEvaluated, to surface the polished prompt's metadata (not the
     * prompt body — that lives on the IntentPacket, referenced by id).
     *
     * @param  array<string,mixed>  $intentPacket
     * @return array<string,mixed>
     */
    public function promptCompiled(array $intentPacket): array
    {
        $compiledPrompt = (string) ($intentPacket['compiled_prompt'] ?? '');
        $transformations = (array) data_get($intentPacket, 'compiler_telemetry.transformations_applied', []);

        return $this->emit(
            type: LedgerEventType::VoxPromptCompiled,
            kind: 'VOX_PROMPT_COMPILED',
            payload: [
                'session_id' => (string) ($intentPacket['session_id'] ?? ''),
                'intent_id' => (string) ($intentPacket['intent_id'] ?? ''),
                'mode' => (string) ($intentPacket['mode'] ?? ''),
                'compiled_prompt_template' => (string) ($intentPacket['compiled_prompt_template'] ?? ''),
                'compiled_prompt_length' => mb_strlen($compiledPrompt),
                'compiled_prompt_sha256' => $compiledPrompt === ''
                    ? null
                    : hash('sha256', $compiledPrompt),
                'provider_hint' => (string) ($intentPacket['provider_hint'] ?? ''),
                'goal' => (string) ($intentPacket['goal'] ?? ''),
                'constraints_count' => count((array) ($intentPacket['constraints'] ?? [])),
                'transformations_applied' => array_values(array_filter($transformations, 'is_string')),
                'compiler_version' => (string) ($intentPacket['compiler_version'] ?? ''),
            ],
            envelopeId: (string) ($intentPacket['session_id'] ?? 'vox-unknown'),
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
     * Emitted alongside a VoxConfirmationRequest. Records that the Kernel
     * asked the operator to confirm a governed action. The confirmation
     * token is NEVER included in the ledger payload — only its presence
     * and shape are recorded for audit. (Lei 0.75 + security: tokens are
     * single-use HMAC; ledger leakage would allow replay.)
     *
     * @param  array<string,mixed>  $confirmationRequest  The full request
     *         packet (must contain request_id, receipt_id, intent_id,
     *         risk_class, expires_at). `confirmation_token`, if present,
     *         is dropped before recording.
     * @return array<string,mixed>
     */
    public function confirmationRequested(array $confirmationRequest): array
    {
        return $this->emit(
            type: LedgerEventType::VoxConfirmationRequested,
            kind: 'VOX_CONFIRMATION_REQUESTED',
            payload: [
                'request_id' => (string) ($confirmationRequest['request_id'] ?? ''),
                'session_id' => (string) ($confirmationRequest['session_id'] ?? ''),
                'intent_id' => (string) ($confirmationRequest['intent_id'] ?? ''),
                'receipt_id' => (string) ($confirmationRequest['receipt_id'] ?? ''),
                'risk_class' => (string) ($confirmationRequest['risk_class'] ?? ''),
                'requires_literal_confirmation' => (bool) ($confirmationRequest['requires_literal_confirmation'] ?? false),
                'actions_available' => (array) ($confirmationRequest['actions_available'] ?? []),
                'expires_at' => (string) ($confirmationRequest['expires_at'] ?? ''),
                'ttl_seconds' => (int) ($confirmationRequest['ttl_seconds'] ?? 0),
                'executor_hint' => (string) data_get($confirmationRequest, 'preview.executor_hint', ''),
                'provider_hint' => (string) data_get($confirmationRequest, 'preview.provider_hint', ''),
                // explicit "no token in ledger" flag so auditors can grep for
                // accidental leaks down the line
                'confirmation_token_in_ledger' => false,
            ],
            envelopeId: (string) ($confirmationRequest['session_id'] ?? 'vox-unknown'),
            receiptId: (string) ($confirmationRequest['receipt_id'] ?? ''),
        );
    }

    /**
     * Emitted after VoxExecutionGate allows execution AND the router has
     * chosen an executor. The token is NEVER logged — only the decision,
     * executor, risk and target identifiers.
     *
     * @param  array<string,mixed>  $payload  { request_id, session_id,
     *         intent_id, receipt_id, executor, risk_class, decision,
     *         provider_hint? }
     * @return array<string,mixed>
     */
    public function actionDispatched(array $payload): array
    {
        return $this->emit(
            type: LedgerEventType::VoxActionDispatched,
            kind: 'VOX_ACTION_DISPATCHED',
            payload: [
                'request_id' => (string) ($payload['request_id'] ?? ''),
                'session_id' => (string) ($payload['session_id'] ?? ''),
                'intent_id' => (string) ($payload['intent_id'] ?? ''),
                'receipt_id' => (string) ($payload['receipt_id'] ?? ''),
                'executor' => (string) ($payload['executor'] ?? ''),
                'risk_class' => (string) ($payload['risk_class'] ?? ''),
                'decision' => (string) ($payload['decision'] ?? ''),
                'provider_hint' => (string) ($payload['provider_hint'] ?? ''),
                'confirmation_token_in_ledger' => false,
            ],
            envelopeId: (string) ($payload['session_id'] ?? 'vox-unknown'),
            receiptId: (string) ($payload['receipt_id'] ?? ''),
        );
    }

    /**
     * Wave 7 (Claude O): emitted when Vitor records a rivals comparison
     * (Wispr / provider-direct / manual baseline vs Vox). Payload is the
     * full case metadata sans audio, sans transcript text. Used by
     * `VoxMetricsService` and `VoxV3PromotionGateService` to evaluate
     * promotion readiness.
     *
     * @param  array<string,mixed>  $case
     * @return array<string,mixed>
     */
    public function rivalsCaseRecorded(array $case): array
    {
        return $this->emit(
            type: LedgerEventType::VoxRivalsCaseRecorded,
            kind: 'VOX_RIVALS_CASE_RECORDED',
            payload: [
                'case_id' => (string) ($case['case_id'] ?? ''),
                'kind' => (string) ($case['kind'] ?? ''),
                'mode' => (string) ($case['mode'] ?? ''),
                'vox_session_id' => $case['vox_session_id'] ?? null,
                'vox_intent_id' => $case['vox_intent_id'] ?? null,
                'baseline_label' => (string) ($case['baseline_label'] ?? ''),
                'baseline_duration_ms' => $case['baseline_duration_ms'] ?? null,
                'vox_duration_ms' => $case['vox_duration_ms'] ?? null,
                'baseline_score' => $case['baseline_score'] ?? null,
                'vox_score' => $case['vox_score'] ?? null,
                'preference' => (string) ($case['preference'] ?? ''),
                'prompt_quality_vote' => $case['prompt_quality_vote'] ?? null,
                'regret_flag' => (bool) ($case['regret_flag'] ?? false),
            ],
            envelopeId: (string) ($case['vox_session_id'] ?? 'vox-rivals'),
        );
    }

    /**
     * V3 certification pack snapshot. The pack body is NOT ledgered (it
     * contains the full metrics/gate/rivals snapshot, which already lives
     * in the ledger via its own events). We record the canonical hash + a
     * minimal summary so auditors can later prove "the pack with this
     * hash existed at this time", without duplicating the payload.
     *
     * @param  array{
     *     certification_hash: string,
     *     gate_status: string,
     *     readiness_summary: string,
     *     hard_gate_violations: int,
     *     v4_unlock_allowed: bool,
     * } $packMeta
     * @return array<string,mixed>
     */
    public function v3CertificationPackCreated(array $packMeta): array
    {
        return $this->emit(
            type: LedgerEventType::VoxV3CertificationPackCreated,
            kind: 'VOX_V3_CERTIFICATION_PACK_CREATED',
            payload: [
                'certification_hash' => (string) ($packMeta['certification_hash'] ?? ''),
                'gate_status' => (string) ($packMeta['gate_status'] ?? ''),
                'readiness_summary' => (string) ($packMeta['readiness_summary'] ?? ''),
                'hard_gate_violations' => (int) ($packMeta['hard_gate_violations'] ?? 0),
                // explicit non-promotion flag · auditors can grep for accidental flips
                'v4_unlock_allowed' => false,
            ],
            envelopeId: 'vox-v3-cert',
        );
    }

    /**
     * V3 human promotion review. Records WHO reviewed, WHICH pack hash was
     * reviewed, and WHAT decision was taken. The free-form `notes` field
     * is intentionally NOT ledgered (it can contain personal context) —
     * only the structured decision + hash are persisted for audit.
     *
     * Even an `approved_for_v4_planning` decision NEVER flips a feature
     * flag in this wave: V4 unlock requires an explicit follow-up wave.
     *
     * @param  array{
     *     certification_hash: string,
     *     reviewed_by: string,
     *     decision: string,
     *     gate_status: string,
     * } $reviewMeta
     * @return array<string,mixed>
     */
    public function v3PromotionReviewRecorded(array $reviewMeta): array
    {
        return $this->emit(
            type: LedgerEventType::VoxV3PromotionReviewRecorded,
            kind: 'VOX_V3_PROMOTION_REVIEW_RECORDED',
            payload: [
                'certification_hash' => (string) ($reviewMeta['certification_hash'] ?? ''),
                'reviewed_by' => (string) ($reviewMeta['reviewed_by'] ?? ''),
                'decision' => (string) ($reviewMeta['decision'] ?? ''),
                'gate_status' => (string) ($reviewMeta['gate_status'] ?? ''),
                'v4_unlocked_by_review' => false,
            ],
            envelopeId: 'vox-v3-cert',
        );
    }

    /**
     * Wave 7.9 (Claude Z): emitted when Vitor records one dogfood session
     * (real Vox usage diary entry). Distinct from `rivalsCaseRecorded` —
     * a dogfood entry is unilateral ("eu usei e foi assim"), not a
     * head-to-head comparison.
     *
     * Payload is the structured session metadata sans audio, transcript
     * text, prompt body. `notes` and `metadata` are intentionally NOT
     * ledgered here — they may contain personal context. Only the
     * structured signals (mode/outcome/flags/duration) are emitted so the
     * ledger remains audit-safe.
     *
     * @param  array<string,mixed>  $session
     * @return array<string,mixed>
     */
    public function dogfoodSessionRecorded(array $session): array
    {
        return $this->emit(
            type: LedgerEventType::VoxDogfoodSessionRecorded,
            kind: 'VOX_DOGFOOD_SESSION_RECORDED',
            payload: [
                'dogfood_session_id' => (string) ($session['dogfood_session_id'] ?? ''),
                'vox_session_id' => $session['vox_session_id'] ?? null,
                'mode' => (string) ($session['mode'] ?? ''),
                'outcome' => (string) ($session['outcome'] ?? ''),
                'used_hotkey' => (bool) ($session['used_hotkey'] ?? false),
                'used_real_stt' => (bool) ($session['used_real_stt'] ?? false),
                'used_governed_execute' => (bool) ($session['used_governed_execute'] ?? false),
                'regret_flag' => (bool) ($session['regret_flag'] ?? false),
                'eclipse_used' => (bool) ($session['eclipse_used'] ?? false),
                'duration_ms' => $session['duration_ms'] ?? null,
            ],
            envelopeId: (string) ($session['vox_session_id'] ?? 'vox-dogfood'),
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
