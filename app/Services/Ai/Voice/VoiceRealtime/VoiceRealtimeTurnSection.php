<?php

namespace App\Services\Ai\Voice\VoiceRealtime;

use App\Services\Ai\Kernel\Decision\DecisionReceipt;
use App\Services\Ai\Kernel\Decision\DecisionReceiptIssuer;
use App\Services\Ai\Kernel\Envelope\EffectiveProfile;
use App\Services\Ai\Kernel\Envelope\IntentClassification;
use App\Services\Ai\Kernel\Envelope\OperationEnvelope;
use App\Services\Ai\Kernel\Envelope\OperationEnvelopeFactory;
use App\Services\Ai\Voice\AtlasVoiceRealtimeService;

/**
 * Voice turn OperationEnvelope + DecisionReceipt construction, split from {@see AtlasVoiceRealtimeService} (GOD-DEBULK).
 */
final class VoiceRealtimeTurnSection
{
    public function __construct(
        private readonly OperationEnvelopeFactory $envelopes,
        private readonly DecisionReceiptIssuer $receipts,
        private readonly VoiceRealtimeSupport $support,
    ) {}

    /**
     * @param  array<string,mixed>  $session
     * @param  array<string,mixed>  $payload
     */
    public function createTurnEnvelope(array $session, array $payload, string $turnId): OperationEnvelope
    {
        $domain = $this->support->string($payload['domain_hint'] ?? 'general', 120) ?: 'general';
        $flow = $this->support->string($payload['flow_hint'] ?? 'general.answer', 120) ?: 'general.answer';
        $transcript = is_string($payload['transcript'] ?? null) ? (string) $payload['transcript'] : '';
        $audioHash = $this->support->sha256Hex($payload['audio_hash'] ?? null);

        $envelope = $this->envelopes->create([
            'operator' => [
                'tenant_id' => data_get($session, 'operator.tenant_id', 'default'),
                'operator_id' => data_get($session, 'operator.operator_id', 'voice_operator'),
                'default_privacy' => $session['privacy_class'] === 'p3_audio' ? 'sensitive' : 'private',
            ],
            'origin' => [
                'surface_id' => 'voice_realtime',
                'surface_version' => AtlasVoiceRealtimeService::SCHEMA_VERSION,
                'session_id' => $session['session_id'],
                'upstream_envelope_id' => $session['envelope_id'],
            ],
            'input' => [
                'primary_type' => 'voice',
                'text' => $transcript,
                'attachments' => array_values(array_filter([
                    $audioHash ? [
                        'kind' => 'audio',
                        'audio_hash' => $audioHash,
                        'duration_ms' => $payload['audio_duration_ms'] ?? null,
                    ] : null,
                ])),
                'hints' => [
                    'domain' => $domain,
                    'flow' => $flow,
                    'surface_id' => 'voice_realtime',
                    'turn_id' => $turnId,
                    'privacy_class' => $session['privacy_class'],
                ],
                'locale' => $payload['language'] ?? 'pt-BR',
            ],
            'parent_envelope_id' => $session['envelope_id'],
            'trace_id' => 'voice:'.$session['session_id'].':'.$turnId,
        ]);
        $envelope->routing->intent = IntentClassification::fromArray([
            'intent' => 'voice.turn',
            'confidence' => 0.7,
            'surface_id' => 'voice_realtime',
        ]);
        $envelope->routing->domain = $domain;
        $envelope->routing->flow = $flow;
        $envelope->routing->profile = EffectiveProfile::fromArray([
            'profile_id' => 'voice_realtime.default',
            'policy_profile_id' => 'voice_realtime.p3_audio',
            'surface_id' => 'voice_realtime',
        ]);

        return $envelope;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    public function issueTurnReceipt(OperationEnvelope $envelope, array $payload): DecisionReceipt
    {
        return $this->receipts->issue($envelope, [
            'dry_run' => true,
            'signed_by' => 'atlas.voice_realtime.decide.scaffold',
            'domain' => $envelope->routing->domain ?? 'general',
            'flow' => $envelope->routing->flow ?? 'general.answer',
            'risk' => $this->support->voiceRisk($payload),
            'provider_selection' => [
                'primary' => 'auto',
                'model' => 'selected-by-decide',
                'selection_mode' => 'auto_best_allowed',
                'selection_reason' => 'voice_turn_requires_atlas_decide_before_runtime',
            ],
            'budgets' => [
                'max_latency_ms' => 1200,
                'max_cost_microusd' => null,
            ],
            'required_gates' => ['voice_privacy', 'eclipse_policy', 'decision_receipt_runtime_guard'],
            'required_evidence' => ['voice_turn_hashes', 'transcript_hash', 'latency_slo'],
            'repair_policy' => ['enabled' => false, 'max_attempts' => 0],
            'metadata' => [
                'surface_id' => 'voice_realtime',
                'session_id' => data_get($envelope->origin, 'sessionId'),
                'runtime_execution_enabled' => false,
                'livekit_agents_call_kernel_webhook_only' => true,
                'raw_audio_persistence_allowed' => false,
            ],
        ]);
    }
}
