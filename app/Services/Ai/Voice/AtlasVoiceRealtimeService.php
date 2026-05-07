<?php

namespace App\Services\Ai\Voice;

use App\Services\Ai\Kernel\Decision\DecisionReceipt;
use App\Services\Ai\Kernel\Decision\DecisionReceiptIssuer;
use App\Services\Ai\Kernel\Envelope\EffectiveProfile;
use App\Services\Ai\Kernel\Envelope\IntentClassification;
use App\Services\Ai\Kernel\Envelope\OperationEnvelope;
use App\Services\Ai\Kernel\Envelope\OperationEnvelopeFactory;
use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use App\Services\Ai\Kernel\Slo\KernelSloTargets;
use Illuminate\Support\Str;

final class AtlasVoiceRealtimeService
{
    public const SCHEMA_VERSION = 'atlas.voice_realtime.scaffold.v1';

    public function __construct(
        private readonly AtlasEvidenceLedger $ledger,
        private readonly AtlasVoiceEclipseGuard $eclipseGuard,
        private readonly KernelSloTargets $sloTargets,
        private readonly OperationEnvelopeFactory $envelopes,
        private readonly DecisionReceiptIssuer $receipts,
    ) {}

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    public function startSession(array $payload): array
    {
        $session = $this->baseSession($payload);
        $eclipse = $this->eclipseGuard->evaluate($payload);
        $event = $this->ledger->recordVoiceEvent(LedgerEventType::VoiceSessionStarted, [
            ...$session,
            'eclipse' => $eclipse,
            'transport' => $session['transport'],
            'runtime' => $session['runtime'],
        ], $this->ledgerContext($session));

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => 'session_started_scaffold',
            'session' => $session,
            'eclipse' => $eclipse,
            'contract' => $this->contract(),
            'evidence_ledger' => $this->ledgerEventPayload($event),
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    public function handleTurn(array $payload): array
    {
        $session = $this->baseSession($payload);
        $turnId = $this->string($payload['turn_id'] ?? (string) Str::ulid(), 80);
        $eclipse = $this->eclipseGuard->evaluate($payload);

        if ((bool) $eclipse['active']) {
            $event = $this->ledger->recordVoiceEvent(LedgerEventType::VoiceEclipseTriggered, [
                ...$session,
                'turn_id' => $turnId,
                'eclipse' => $eclipse,
            ], $this->ledgerContext($session));

            return [
                'schema_version' => self::SCHEMA_VERSION,
                'status' => 'blocked_by_eclipse',
                'session' => $session,
                'turn' => ['turn_id' => $turnId],
                'eclipse' => $eclipse,
                'contract' => $this->contract(),
                'evidence_ledger' => [
                    'blocked' => $this->ledgerEventPayload($event),
                ],
            ];
        }

        $envelope = $this->createTurnEnvelope($session, $payload, $turnId);
        $session['parent_envelope_id'] = $session['envelope_id'];
        $session['envelope_id'] = $envelope->envelopeId;
        $session['trace_id'] = $envelope->audit->traceId;
        $receipt = $this->issueTurnReceipt($envelope, $payload);
        $envelope->decision = $receipt;
        $decisionEvent = $this->ledger->recordDecisionIssued($receipt->toArray(), $this->ledgerContext($session));

        $events = [];
        if (isset($payload['audio_hash'])) {
            $events['audio_received'] = $this->ledger->recordVoiceEvent(LedgerEventType::VoiceTurnAudioReceived, [
                ...$session,
                'turn_id' => $turnId,
                'audio_hash' => $payload['audio_hash'],
                'audio_duration_ms' => $payload['audio_duration_ms'] ?? null,
            ], $this->ledgerContext($session));
        }

        if (isset($payload['transcript'])) {
            $events['transcribed'] = $this->ledger->recordVoiceEvent(LedgerEventType::VoiceTurnTranscribed, [
                ...$session,
                'turn_id' => $turnId,
                'transcript' => $payload['transcript'],
                'language' => $payload['language'] ?? 'pt-BR',
            ], $this->ledgerContext($session));
        }

        $events['decided'] = $this->ledger->recordVoiceEvent(LedgerEventType::VoiceTurnDecided, [
            ...$session,
            'turn_id' => $turnId,
            'decision_status' => 'decision_receipt_issued_dry_run',
            'receipt_id' => $receipt->receiptId,
            'receipt_hash' => $receipt->receiptHash,
            'domain' => $receipt->domain,
            'flow' => $receipt->flow,
        ], $this->ledgerContext($session));

        $slo = null;
        if (isset($payload['turn_to_first_audio_ms'])) {
            $assessment = $this->sloTargets->assess('voice.turn_to_first_audio', (int) $payload['turn_to_first_audio_ms'], true);
            $slo = $this->ledger->recordSloObservation($assessment, [
                ...$this->ledgerContext($session),
                'domain' => $payload['domain_hint'] ?? 'general',
                'flow' => $payload['flow_hint'] ?? 'general.answer',
                'surface_id' => 'voice_realtime',
                'runtime' => 'livekit_agents_scaffold',
            ]);
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => 'turn_accepted_scaffold',
            'session' => $session,
            'turn' => [
                'turn_id' => $turnId,
                'operation_envelope' => [
                    'envelope_id' => $envelope->envelopeId,
                    'parent_envelope_id' => $envelope->parentEnvelopeId,
                    'schema_version' => $envelope->schemaVersion,
                    'trace_id' => $envelope->audit->traceId,
                    'input_hash' => $envelope->input->inputHash,
                    'chain_hash' => $envelope->audit->chainHash,
                ],
                'decision_receipt' => [
                    'receipt_id' => $receipt->receiptId,
                    'schema_version' => $receipt->schemaVersion,
                    'dry_run' => $receipt->dryRun,
                    'domain' => $receipt->domain,
                    'flow' => $receipt->flow,
                    'provider_selection' => $receipt->providerSelection->toArray(),
                    'receipt_hash' => $receipt->receiptHash,
                    'chain_hash' => $receipt->chainHash,
                ],
                'provider_execution_enabled' => false,
                'runtime_execution_enabled' => false,
                'requires_decision_receipt' => true,
                'raw_audio_persisted' => false,
            ],
            'eclipse' => $eclipse,
            'contract' => $this->contract(),
            'evidence_ledger' => collect($events)
                ->map(fn (mixed $event): array => $this->ledgerEventPayload($event))
                ->put('decision_issued', $this->ledgerEventPayload($decisionEvent['decision_issued'] ?? null))
                ->put('slo', $this->ledgerEventPayload($slo))
                ->all(),
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    public function endSession(array $payload): array
    {
        $session = $this->baseSession($payload);
        $event = $this->ledger->recordVoiceEvent(LedgerEventType::VoiceSessionEnded, [
            ...$session,
            'reason' => $payload['reason'] ?? 'operator_ended',
        ], $this->ledgerContext($session));

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => 'session_ended_scaffold',
            'session' => $session,
            'contract' => $this->contract(),
            'evidence_ledger' => $this->ledgerEventPayload($event),
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    public function interruptTurn(array $payload): array
    {
        $session = $this->baseSession($payload);
        $turnId = $this->string($payload['turn_id'] ?? (string) Str::ulid(), 80);
        $event = $this->ledger->recordVoiceEvent(LedgerEventType::VoiceTurnInterrupted, [
            ...$session,
            'turn_id' => $turnId,
            'reason' => $this->string($payload['reason'] ?? 'operator_interrupted', 120),
            'interrupted_stage' => $this->string($payload['interrupted_stage'] ?? 'runtime_or_tts', 120),
            'latency_ms' => isset($payload['latency_ms']) ? (int) $payload['latency_ms'] : null,
        ], $this->ledgerContext($session));
        $slo = null;
        if (isset($payload['latency_ms'])) {
            $assessment = $this->sloTargets->assess('voice.interruption_stop_audio', (int) $payload['latency_ms'], true);
            $slo = $this->ledger->recordSloObservation($assessment, [
                ...$this->ledgerContext($session),
                'surface_id' => 'voice_realtime',
                'runtime' => $session['runtime'],
            ]);
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => 'turn_interrupted_recorded',
            'session' => $session,
            'turn' => [
                'turn_id' => $turnId,
                'interruption_recorded' => true,
                'raw_audio_persisted' => false,
            ],
            'contract' => $this->contract(),
            'evidence_ledger' => [
                'interrupted' => $this->ledgerEventPayload($event),
                'slo' => $this->ledgerEventPayload($slo),
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    public function recordSynthesis(array $payload): array
    {
        $event = $this->recordRuntimeCallback(LedgerEventType::VoiceTurnSynthesized, $payload, [
            'status' => 'turn_synthesis_recorded',
            'text_key' => 'response_text',
            'duration_key' => 'audio_duration_ms',
            'provider_key' => 'tts_provider',
        ]);

        return $event;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    public function recordPlayback(array $payload): array
    {
        return $this->recordRuntimeCallback(LedgerEventType::VoiceTurnPlayed, $payload, [
            'status' => 'turn_playback_recorded',
            'duration_key' => 'played_duration_ms',
        ]);
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    public function recordRuntimeFailure(array $payload): array
    {
        return $this->recordRuntimeCallback(LedgerEventType::VoiceRuntimeFailed, $payload, [
            'status' => 'runtime_failure_recorded',
            'failure_key' => 'failure_code',
        ]);
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    public function recordProviderHealth(array $payload): array
    {
        return $this->recordRuntimeCallback(LedgerEventType::VoiceProviderHealthDegraded, $payload, [
            'status' => 'provider_health_degraded_recorded',
            'provider_key' => 'provider',
            'failure_key' => 'reason',
        ]);
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    public function health(array $payload = []): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => 'scaffold_ready',
            'surface_id' => 'voice_realtime',
            'mobile_first' => true,
            'livekit_agents_sdk' => 'planned',
            'swift_native_mac' => 'future_edge',
            'capabilities' => [
                'session_contract',
                'turn_contract',
                'eclipse_guard',
                'voice_ledger_events',
                'voice_slo_targets',
                'turn_operation_envelope',
                'turn_decision_receipt',
                'turn_interruption_event',
                'runtime_callback_events',
            ],
            'eclipse' => $this->eclipseGuard->evaluate($payload),
            'contract' => $this->contract(),
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    public function runtimeContract(array $payload = []): array
    {
        return [
            'schema_version' => 'atlas.voice_realtime.runtime_contract.v1',
            'status' => 'ready',
            'surface_id' => 'voice_realtime',
            'mobile_first' => true,
            'runtime_family' => 'python_ai_data',
            'runtime_id' => $this->string($payload['runtime'] ?? 'livekit_agents_sdk', 120),
            'kernel_is_decision_authority' => true,
            'turn_endpoint' => '/ai/voice/turn',
            'mobile_turn_endpoint' => '/v1/mobile/ai/voice/turn',
            'required_callbacks' => [
                'turn_synthesized' => '/ai/voice/turn/synthesized',
                'turn_played' => '/ai/voice/turn/played',
                'turn_interrupted' => '/ai/voice/turn/interrupted',
                'runtime_failed' => '/ai/voice/runtime/failed',
                'provider_health_degraded' => '/ai/voice/provider/health-degraded',
            ],
            'mobile_required_callbacks' => [
                'turn_synthesized' => '/v1/mobile/ai/voice/turn/synthesized',
                'turn_played' => '/v1/mobile/ai/voice/turn/played',
                'turn_interrupted' => '/v1/mobile/ai/voice/turn/interrupted',
                'runtime_failed' => '/v1/mobile/ai/voice/runtime/failed',
                'provider_health_degraded' => '/v1/mobile/ai/voice/provider/health-degraded',
            ],
            'auth_contract' => [
                'internal_api' => [
                    'middleware' => 'atlas.token',
                    'header' => 'X-Atlas-Token',
                    'intended_callers' => ['livekit_agents_sdk', 'local_runtime_worker'],
                ],
                'mobile_api' => [
                    'middleware' => 'atlas.mobile.bearer',
                    'header' => 'Authorization: Bearer <mobile_token>',
                    'intended_callers' => ['atlas_mobile_app'],
                ],
                'rule' => 'runtime_must_call_kernel_endpoint_before_provider_or_tool_execution',
            ],
            'callback_order' => [
                'normal_turn' => ['turn', 'turn_synthesized', 'turn_played'],
                'interruptible_anytime' => ['turn_interrupted'],
                'runtime_health_anytime' => ['runtime_failed', 'provider_health_degraded'],
            ],
            'persistence_contract' => [
                'raw_audio' => false,
                'raw_transcript' => false,
                'raw_response_text' => false,
                'allowed' => ['audio_hash', 'transcript_hash', 'response_text_hash', 'duration_ms', 'latency_ms', 'provider', 'model'],
            ],
            'required_turn_fields' => [
                'session_id',
                'turn_id',
                'audio_hash_or_transcript_hash',
                'transcript_when_available',
            ],
            'prohibited_fields' => [
                'audio_bytes',
                'raw_audio',
                'audio_raw',
                'pcm',
                'wav',
            ],
            'required_evidence' => [
                'ENVELOPE_CREATED',
                'DECISION_ISSUED',
                'VOICE_TURN_DECIDED',
                'VOICE_TURN_SYNTHESIZED',
                'VOICE_TURN_PLAYED',
            ],
            'slo_stages' => [
                'voice.wake_word_detect',
                'voice.turn_to_first_audio',
                'voice.interruption_stop_audio',
            ],
            'contract' => $this->contract(),
            'eclipse' => $this->eclipseGuard->evaluate($payload),
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    public function runtimeBootstrapManifest(array $payload = []): array
    {
        $contract = $this->runtimeContract($payload);
        $baseUrl = rtrim($this->string($payload['base_url'] ?? config('app.url', 'http://localhost'), 240), '/');
        $runtimeId = (string) $contract['runtime_id'];

        return [
            'schema_version' => 'atlas.voice_realtime.runtime_bootstrap.v1',
            'status' => 'ready',
            'surface_id' => 'voice_realtime',
            'runtime_id' => $runtimeId,
            'runtime_family' => 'python_ai_data',
            'entrypoint' => [
                'kind' => 'livekit_agents_sdk',
                'module' => 'atlas_voice_agent.main',
                'factory' => 'create_atlas_voice_agent',
            ],
            'kernel' => [
                'base_url' => $baseUrl,
                'contract_url' => $baseUrl.'/ai/voice/runtime/contract?runtime='.$runtimeId,
                'turn_url' => $baseUrl.$contract['turn_endpoint'],
                'mobile_turn_url' => $baseUrl.$contract['mobile_turn_endpoint'],
                'callbacks' => collect($contract['required_callbacks'])
                    ->map(fn (string $path): string => $baseUrl.$path)
                    ->all(),
                'mobile_callbacks' => collect($contract['mobile_required_callbacks'])
                    ->map(fn (string $path): string => $baseUrl.$path)
                    ->all(),
            ],
            'required_env' => [
                'ATLAS_BASE_URL',
                'ATLAS_TOKEN',
                'LIVEKIT_URL',
                'LIVEKIT_API_KEY',
                'LIVEKIT_API_SECRET',
                'ATLAS_VOICE_BOOTSTRAP',
            ],
            'optional_env' => [
                'ATLAS_VOICE_STT_PROVIDER',
                'ATLAS_VOICE_TTS_PROVIDER',
                'ATLAS_VOICE_LOCAL_FALLBACK',
                'ATLAS_VOICE_ROOM_PREFIX',
            ],
            'default_providers' => [
                'stt' => 'configurable',
                'tts' => 'configurable',
                'llm' => 'atlas_kernel_only',
            ],
            'forbidden_capabilities' => [
                'direct_llm_provider_call',
                'direct_tool_execution',
                'memory_write',
                'policy_override',
                'raw_audio_persistence',
                'raw_transcript_persistence',
            ],
            'required_runtime_behaviors' => [
                'fetch_contract_on_startup',
                'fail_closed_when_kernel_unreachable',
                'send_turn_to_kernel_before_tts',
                'report_synthesis_playback_failure_and_provider_health',
                'respect_eclipse_block_responses',
            ],
            'persistence_contract' => $contract['persistence_contract'],
            'auth_contract' => $contract['auth_contract'],
            'slo_stages' => $contract['slo_stages'],
            'contract_hash' => hash('sha256', json_encode($contract, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)),
            'generated_at' => now()->toJSON(),
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    public function eclipseStatus(array $payload): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => 'ok',
            'eclipse' => $this->eclipseGuard->evaluate($payload),
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function baseSession(array $payload): array
    {
        return [
            'session_id' => $this->string($payload['session_id'] ?? (string) Str::ulid(), 80),
            'surface_id' => 'voice_realtime',
            'client_surface' => $this->string($payload['client_surface'] ?? 'mobile', 80),
            'transport' => $this->string($payload['transport'] ?? 'mobile_push_to_talk', 80),
            'runtime' => $this->string($payload['runtime'] ?? 'livekit_agents_scaffold', 80),
            'privacy_class' => $this->string($payload['privacy_class'] ?? 'p3_audio', 80),
            'envelope_id' => $this->string($payload['envelope_id'] ?? 'voice_realtime:'.(string) Str::ulid(), 120),
            'receipt_id' => isset($payload['receipt_id']) ? $this->string($payload['receipt_id'], 120) : null,
            'operator' => [
                'tenant_id' => $this->string(data_get($payload, 'operator.tenant_id', $payload['tenant_id'] ?? 'default'), 120),
                'operator_id' => $this->string(data_get($payload, 'operator.operator_id', $payload['operator_id'] ?? 'voice_operator'), 120),
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $session
     * @param  array<string,mixed>  $payload
     */
    private function createTurnEnvelope(array $session, array $payload, string $turnId): OperationEnvelope
    {
        $domain = $this->string($payload['domain_hint'] ?? 'general', 120) ?: 'general';
        $flow = $this->string($payload['flow_hint'] ?? 'general.answer', 120) ?: 'general.answer';
        $transcript = is_string($payload['transcript'] ?? null) ? (string) $payload['transcript'] : '';
        $audioHash = is_scalar($payload['audio_hash'] ?? null) ? (string) $payload['audio_hash'] : null;

        $envelope = $this->envelopes->create([
            'operator' => [
                'tenant_id' => data_get($session, 'operator.tenant_id', 'default'),
                'operator_id' => data_get($session, 'operator.operator_id', 'voice_operator'),
                'default_privacy' => $session['privacy_class'] === 'p3_audio' ? 'sensitive' : 'private',
            ],
            'origin' => [
                'surface_id' => 'voice_realtime',
                'surface_version' => self::SCHEMA_VERSION,
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
    private function issueTurnReceipt(OperationEnvelope $envelope, array $payload): DecisionReceipt
    {
        return $this->receipts->issue($envelope, [
            'dry_run' => true,
            'signed_by' => 'atlas.voice_realtime.decide.scaffold',
            'domain' => $envelope->routing->domain ?? 'general',
            'flow' => $envelope->routing->flow ?? 'general.answer',
            'risk' => $this->voiceRisk($payload),
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

    /**
     * @param  array<string,mixed>  $payload
     */
    private function voiceRisk(array $payload): string
    {
        $domain = (string) ($payload['domain_hint'] ?? 'general');
        $privacy = (string) ($payload['privacy_class'] ?? data_get($payload, 'privacy.class', 'p3_audio'));

        if (in_array($privacy, ['p4_secret', 'secret'], true)) {
            return 'critical';
        }

        if (in_array($domain, ['finance', 'health', 'operations', 'security'], true)) {
            return 'high';
        }

        return 'medium';
    }

    /**
     * @param  array<string,mixed>  $payload
     * @param  array<string,string>  $options
     * @return array<string,mixed>
     */
    private function recordRuntimeCallback(LedgerEventType $type, array $payload, array $options): array
    {
        $session = $this->baseSession($payload);
        $turnId = $this->string($payload['turn_id'] ?? (string) Str::ulid(), 80);
        $voicePayload = [
            ...$session,
            'turn_id' => $turnId,
            'runtime' => $this->string($payload['runtime'] ?? $session['runtime'], 120),
            'provider' => $this->string($payload[$options['provider_key'] ?? 'provider'] ?? $payload['provider'] ?? '', 120),
            'model' => $this->string($payload['model'] ?? '', 120),
            'audio_hash' => $payload['audio_hash'] ?? null,
            'audio_duration_ms' => $payload[$options['duration_key'] ?? 'audio_duration_ms'] ?? $payload['audio_duration_ms'] ?? null,
            'latency_ms' => isset($payload['latency_ms']) ? (int) $payload['latency_ms'] : null,
            'failure_code' => $this->string($payload[$options['failure_key'] ?? 'failure_code'] ?? $payload['failure_code'] ?? '', 160),
            'response_text' => $payload[$options['text_key'] ?? 'response_text'] ?? $payload['response_text'] ?? null,
        ];

        $event = $this->ledger->recordVoiceEvent($type, $voicePayload, $this->ledgerContext($session));

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $options['status'],
            'session' => $session,
            'turn' => [
                'turn_id' => $turnId,
                'event_type' => $type->value,
                'raw_audio_persisted' => false,
                'raw_text_persisted' => false,
            ],
            'contract' => $this->contract(),
            'evidence_ledger' => $this->ledgerEventPayload($event),
        ];
    }

    /**
     * @param  array<string,mixed>  $session
     * @return array<string,mixed>
     */
    private function ledgerContext(array $session): array
    {
        return [
            'tenant_id' => data_get($session, 'operator.tenant_id', 'default'),
            'operator_id' => data_get($session, 'operator.operator_id', 'voice_operator'),
            'envelope_id' => $session['envelope_id'],
            'receipt_id' => $session['receipt_id'],
            'correlation_id' => $session['session_id'],
            'emitter_stage' => 'atlas.voice_realtime',
            'emitter_version' => self::SCHEMA_VERSION,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function contract(): array
    {
        return [
            'surface_must_not_decide' => true,
            'provider_must_not_decide' => true,
            'runtime_requires_decision_receipt' => true,
            'raw_audio_persistence_allowed' => false,
            'livekit_agents_call_kernel_webhook_only' => true,
            'critical_behavior_changes_require_human_review' => true,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function ledgerEventPayload(mixed $event): array
    {
        return [
            'recorded' => $event !== null,
            'event_id' => data_get($event, 'event_id'),
            'event_type' => data_get($event, 'event_type'),
            'emitter_stage' => data_get($event, 'emitter_stage'),
            'payload_hash' => data_get($event, 'payload_hash'),
        ];
    }

    private function string(mixed $value, int $limit): string
    {
        return Str::limit(trim((string) $value), $limit, '');
    }
}
