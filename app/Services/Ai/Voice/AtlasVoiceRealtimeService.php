<?php

namespace App\Services\Ai\Voice;

use App\Models\AtlasLedgerEvent;
use App\Services\Ai\Kernel\Decision\DecisionReceipt;
use App\Services\Ai\Kernel\Decision\DecisionReceiptIssuer;
use App\Services\Ai\Kernel\Envelope\EffectiveProfile;
use App\Services\Ai\Kernel\Envelope\IntentClassification;
use App\Services\Ai\Kernel\Envelope\OperationEnvelope;
use App\Services\Ai\Kernel\Envelope\OperationEnvelopeFactory;
use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use App\Services\Ai\Kernel\Slo\KernelSloTargets;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

final class AtlasVoiceRealtimeService
{
    public const SCHEMA_VERSION = 'atlas.voice_realtime.scaffold.v1';

    private const ALLOWED_CLIENT_SURFACES = ['mobile', 'mac_edge'];

    private const ALLOWED_TRANSPORTS = ['mobile_push_to_talk', 'livekit_webrtc'];

    private const ALLOWED_RUNTIMES = ['livekit_agents_sdk'];

    private const ALLOWED_PRIVACY_CLASSES = ['p1_public', 'p2_internal', 'p3_audio', 'p4_secret'];

    private const CALLBACK_PAYLOAD_SCHEMAS = [
        'participant_joined' => [
            'required' => ['session_id', 'participant_identity', 'room_name'],
            'optional' => ['client_surface', 'transport', 'privacy_class', 'rivals_arm'],
            'prohibited' => ['access_token', 'token', 'livekit_token', 'api_key', 'api_secret', 'raw_audio', 'audio_bytes'],
        ],
        'transcript_final' => [
            'required' => ['session_id', 'turn_id', 'transcript'],
            'optional' => ['language', 'domain_hint', 'flow_hint'],
            'prohibited' => ['raw_audio', 'audio_bytes', 'tool_call', 'tool_args', 'llm_provider', 'provider_api_key'],
        ],
        'wake_word_detected' => [
            'required' => ['session_id'],
            'optional' => ['wake_word_engine', 'confidence', 'latency_ms'],
            'prohibited' => ['raw_audio', 'audio_bytes', 'pcm', 'wav'],
        ],
        'tts_synthesized' => [
            'required' => ['session_id', 'turn_id'],
            'optional' => ['response_text_hash', 'audio_hash', 'audio_duration_ms', 'tts_provider', 'provider', 'model', 'latency_ms'],
            'prohibited' => ['response_text', 'raw_response_text', 'tts_text', 'raw_audio', 'audio_bytes'],
        ],
        'audio_played' => [
            'required' => ['session_id', 'turn_id'],
            'optional' => ['played_duration_ms', 'latency_ms'],
            'prohibited' => ['raw_audio', 'audio_bytes', 'pcm', 'wav'],
        ],
        'barge_in' => [
            'required' => ['session_id', 'turn_id'],
            'optional' => ['reason', 'interrupted_stage', 'latency_ms'],
            'prohibited' => ['raw_audio', 'audio_bytes', 'pcm', 'wav'],
        ],
        'runtime_failed' => [
            'required' => ['session_id', 'turn_id'],
            'optional' => ['failure_code', 'error_class', 'latency_ms'],
            'prohibited' => ['raw_audio', 'audio_bytes', 'response_text', 'tool_call', 'tool_args'],
        ],
        'provider_health_degraded' => [
            'required' => ['session_id', 'turn_id', 'provider'],
            'optional' => ['reason', 'latency_ms'],
            'prohibited' => ['provider_api_key', 'api_key', 'api_secret', 'token'],
        ],
        'participant_left' => [
            'required' => ['session_id'],
            'optional' => ['reason'],
            'prohibited' => ['access_token', 'token', 'livekit_token', 'api_key', 'api_secret'],
        ],
    ];

    public function __construct(
        private readonly AtlasEvidenceLedger $ledger,
        private readonly AtlasVoiceEclipseGuard $eclipseGuard,
        private readonly KernelSloTargets $sloTargets,
        private readonly OperationEnvelopeFactory $envelopes,
        private readonly DecisionReceiptIssuer $receipts,
        private readonly AtlasVoiceLiveKitTokenIssuer $liveKitTokens,
    ) {}

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    public function startSession(array $payload): array
    {
        $session = $this->baseSession($payload);
        $eclipse = $this->eclipseGuard->evaluate($payload);
        $lease = $this->sessionLease($session, $payload);
        $event = $this->ledger->recordVoiceEvent(LedgerEventType::VoiceSessionStarted, [
            ...$session,
            'session_lease' => $lease,
            'eclipse' => $eclipse,
            'transport' => $session['transport'],
            'runtime' => $session['runtime'],
        ], $this->ledgerContext($session));

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => 'session_started_scaffold',
            'session' => $session,
            'session_lease' => $lease,
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
                'runtime' => 'livekit_agents_sdk',
                'rivals_arm' => $session['rivals_arm'],
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
    public function recordWakeWord(array $payload): array
    {
        $session = $this->baseSession($payload);
        $event = $this->ledger->recordVoiceEvent(LedgerEventType::VoiceWakeWordDetected, [
            ...$session,
            'wake_word_engine' => $this->string($payload['wake_word_engine'] ?? 'local_edge', 120),
            'confidence' => isset($payload['confidence']) ? (float) $payload['confidence'] : null,
            'latency_ms' => isset($payload['latency_ms']) ? (int) $payload['latency_ms'] : null,
            'local_only' => true,
            'raw_audio_persisted' => false,
        ], $this->ledgerContext($session));

        $slo = null;
        if (isset($payload['latency_ms'])) {
            $assessment = $this->sloTargets->assess('voice.wake_word_detect', (int) $payload['latency_ms'], true);
            $slo = $this->ledger->recordSloObservation($assessment, [
                ...$this->ledgerContext($session),
                'surface_id' => 'voice_realtime',
                'runtime' => $session['runtime'],
                'rivals_arm' => $session['rivals_arm'],
            ]);
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => 'wake_word_detected_recorded',
            'session' => $session,
            'wake_word' => [
                'local_only' => true,
                'raw_audio_persisted' => false,
                'requires_explicit_turn_after_detection' => true,
            ],
            'contract' => $this->contract(),
            'evidence_ledger' => [
                'wake_word_detected' => $this->ledgerEventPayload($event),
                'slo' => $this->ledgerEventPayload($slo),
            ],
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
                'rivals_arm' => $session['rivals_arm'],
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
            'livekit_token_issuer' => $this->liveKitTokens->enabled() ? 'configured' : 'disabled',
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
                'production_loop_smoke',
            ],
            'eclipse' => $this->eclipseGuard->evaluate($payload),
            'contract' => $this->contract(),
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    public function readiness(array $payload = []): array
    {
        $hours = max(1, min(8760, (int) ($payload['hours'] ?? 24)));
        $since = now()->subHours($hours);
        $until = now();

        if (! Schema::hasTable('atlas_ledger_events')) {
            return [
                'schema_version' => 'atlas.voice.readiness.v1',
                'available' => false,
                'status' => 'ledger_unavailable',
                'hours' => $hours,
                'window' => ['since' => $since->toJSON(), 'until' => $until->toJSON()],
                'review_signal' => [
                    'status' => 'blocked',
                    'severity' => 'high',
                    'recommended_action' => 'run_ledger_migrations_before_voice_readiness',
                ],
            ];
        }

        $voiceTypes = collect(LedgerEventType::cases())
            ->map(fn (LedgerEventType $type): string => $type->value)
            ->filter(fn (string $type): bool => str_starts_with($type, 'VOICE_'))
            ->values()
            ->all();
        $events = AtlasLedgerEvent::query()
            ->where('occurred_at', '>=', $since)
            ->whereIn('event_type', $voiceTypes)
            ->get();
        $sloEvents = AtlasLedgerEvent::query()
            ->where('occurred_at', '>=', $since)
            ->where('event_type', LedgerEventType::SloObserved->value)
            ->get()
            ->filter(fn (AtlasLedgerEvent $event): bool => str_starts_with((string) data_get($event->payload, 'stage'), 'voice.'));
        $eventCounts = $events->pluck('event_type')->countBy()->all();
        $required = [
            LedgerEventType::VoiceSessionStarted->value,
            LedgerEventType::VoiceTurnDecided->value,
            LedgerEventType::VoiceTurnSynthesized->value,
            LedgerEventType::VoiceTurnPlayed->value,
        ];
        $missing = collect($required)
            ->filter(fn (string $type): bool => (int) ($eventCounts[$type] ?? 0) === 0)
            ->values()
            ->all();
        $sloBreaches = $sloEvents
            ->filter(fn (AtlasLedgerEvent $event): bool => (string) data_get($event->payload, 'status') !== 'ok')
            ->count();
        $score = max(0, 100 - (count($missing) * 15) - ($sloBreaches * 10));

        return [
            'schema_version' => 'atlas.voice.readiness.v1',
            'available' => true,
            'status' => $missing === [] && $sloBreaches === 0 ? 'ready' : 'attention',
            'hours' => $hours,
            'window' => ['since' => $since->toJSON(), 'until' => $until->toJSON()],
            'mobile_first' => true,
            'score' => $score,
            'event_counts' => $eventCounts,
            'session_count' => $events->pluck('correlation_id')->filter()->unique()->count(),
            'turn_count' => $events->map(fn (AtlasLedgerEvent $event): ?string => data_get($event->payload, 'voice.turn_id'))->filter()->unique()->count(),
            'slo' => [
                'observation_count' => $sloEvents->count(),
                'breach_count' => $sloBreaches,
                'stages' => $sloEvents->pluck('payload.stage')->filter()->countBy()->all(),
            ],
            'gates' => [
                'required_events_present' => $missing === [],
                'latency_slo_clean' => $sloBreaches === 0,
                'raw_audio_forbidden' => true,
                'kernel_decision_per_turn' => true,
                'rivals_voice_ready' => $missing === [] && $sloEvents->isNotEmpty(),
            ],
            'missing_events' => $missing,
            'review_signal' => [
                'status' => $missing === [] && $sloBreaches === 0 ? 'ok' : 'warning',
                'severity' => $missing === [] ? ($sloBreaches === 0 ? 'none' : 'medium') : 'medium',
                'recommended_action' => $missing === []
                    ? ($sloBreaches === 0 ? 'voice_readiness_can_enter_rivals_voice' : 'investigate_voice_latency_slo_breaches')
                    : 'complete_voice_required_events_before_rivals_voice',
            ],
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
            'runtime_id' => $this->allowedValue($payload['runtime'] ?? 'livekit_agents_sdk', self::ALLOWED_RUNTIMES, 'runtime'),
            'kernel_is_decision_authority' => true,
            'allowlists' => [
                'client_surfaces' => self::ALLOWED_CLIENT_SURFACES,
                'transports' => self::ALLOWED_TRANSPORTS,
                'runtimes' => self::ALLOWED_RUNTIMES,
                'privacy_classes' => self::ALLOWED_PRIVACY_CLASSES,
            ],
            'session_lease' => [
                'schema_version' => 'atlas.voice.session_lease.v1',
                'default_mode' => 'mobile_push_to_talk',
                'ttl_seconds' => 900,
                'token_status' => $this->liveKitTokens->enabled() ? 'issued_when_session_starts' : 'not_issued_scaffold',
                'room_prefix' => 'atlas-voice',
                'livekit_url' => $this->liveKitTokens->liveKitUrl(),
                'kernel_decision_required_per_turn' => true,
            ],
            'session_start_endpoint' => '/ai/voice/session/start',
            'session_end_endpoint' => '/ai/voice/session/end',
            'readiness_endpoint' => '/ai/voice/readiness',
            'rivals_endpoint' => '/ai/voice/rivals',
            'runtime_dependencies_endpoint' => '/ai/voice/runtime/dependencies',
            'runtime_certification_endpoint' => '/ai/voice/runtime/certification',
            'mobile_session_start_endpoint' => '/v1/mobile/ai/voice/session/start',
            'mobile_session_end_endpoint' => '/v1/mobile/ai/voice/session/end',
            'mobile_readiness_endpoint' => '/v1/mobile/ai/voice/readiness',
            'mobile_rivals_endpoint' => '/v1/mobile/ai/voice/rivals',
            'mobile_runtime_dependencies_endpoint' => '/v1/mobile/ai/voice/runtime/dependencies',
            'mobile_runtime_certification_endpoint' => '/v1/mobile/ai/voice/runtime/certification',
            'wake_word_endpoint' => '/ai/voice/wake-word',
            'mobile_wake_word_endpoint' => '/v1/mobile/ai/voice/wake-word',
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
                'runtime_callbacks_require_kernel_accepted_turn' => ['turn_synthesized', 'turn_played', 'turn_interrupted', 'provider_health_degraded'],
            ],
            'production_loop_smoke' => [
                'schema_version' => 'atlas.voice_realtime.production_loop_smoke_contract.v1',
                'status' => 'available_without_daemon',
                'sdk_events_example_path' => 'runtimes/python/voice_realtime/sdk-events.example.json',
                'cli_command' => 'php artisan atlas:ai:voice production-loop-smoke --json',
                'python_command' => 'PYTHONPATH=runtimes/python/voice_realtime python3 -m atlas_voice_agent.main --mock-kernel --sdk-events runtimes/python/voice_realtime/sdk-events.example.json',
                'daemon_started' => false,
                'sdk_imported' => false,
                'kernel_only' => true,
                'mobile_first' => true,
            ],
            'callback_payload_schemas' => self::CALLBACK_PAYLOAD_SCHEMAS,
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
                'response_text',
                'raw_response_text',
                'tts_text',
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
                'session_start_url' => $baseUrl.$contract['session_start_endpoint'],
                'session_end_url' => $baseUrl.$contract['session_end_endpoint'],
                'readiness_url' => $baseUrl.$contract['readiness_endpoint'],
                'rivals_url' => $baseUrl.$contract['rivals_endpoint'],
                'runtime_dependencies_url' => $baseUrl.$contract['runtime_dependencies_endpoint'],
                'runtime_certification_url' => $baseUrl.$contract['runtime_certification_endpoint'],
                'mobile_session_start_url' => $baseUrl.$contract['mobile_session_start_endpoint'],
                'mobile_session_end_url' => $baseUrl.$contract['mobile_session_end_endpoint'],
                'mobile_readiness_url' => $baseUrl.$contract['mobile_readiness_endpoint'],
                'mobile_rivals_url' => $baseUrl.$contract['mobile_rivals_endpoint'],
                'mobile_runtime_dependencies_url' => $baseUrl.$contract['mobile_runtime_dependencies_endpoint'],
                'mobile_runtime_certification_url' => $baseUrl.$contract['mobile_runtime_certification_endpoint'],
                'wake_word_url' => $baseUrl.$contract['wake_word_endpoint'],
                'mobile_wake_word_url' => $baseUrl.$contract['mobile_wake_word_endpoint'],
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
                'raw_response_text_persistence',
            ],
            'required_runtime_behaviors' => [
                'fetch_contract_on_startup',
                'fail_closed_when_kernel_unreachable',
                'send_turn_to_kernel_before_tts',
                'report_synthesis_playback_failure_and_provider_health',
                'respect_eclipse_block_responses',
            ],
            'persistence_contract' => $contract['persistence_contract'],
            'callback_payload_schemas' => $contract['callback_payload_schemas'],
            'production_loop_smoke' => $contract['production_loop_smoke'],
            'auth_contract' => $contract['auth_contract'],
            'session_lease' => $contract['session_lease'],
            'allowlists' => $contract['allowlists'],
            'slo_stages' => $contract['slo_stages'],
            'contract_hash' => hash('sha256', json_encode($contract, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)),
            'generated_at' => now()->toJSON(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function scriptedWorkerExample(): array
    {
        $examplePath = 'runtimes/python/voice_realtime/scripted-events.example.json';

        return [
            'schema_version' => 'atlas.voice_realtime.scripted_worker_example.v1',
            'status' => 'ready',
            'surface_id' => 'voice_realtime',
            'runtime_id' => 'livekit_agents_sdk',
            'example_path' => $examplePath,
            'runtime_family' => 'python_ai_data',
            'entrypoint' => [
                'module' => 'atlas_voice_agent.main',
                'worker_adapter' => 'AtlasLiveKitWorker',
                'sdk_adapter' => 'LiveKitSdkAdapter',
            ],
            'command' => 'PYTHONPATH=runtimes/python/voice_realtime python3 -m atlas_voice_agent.main --env-file runtimes/python/voice_realtime/.env.local --scripted-events '.$examplePath,
            'preflight_commands' => [
                'php artisan atlas:ai:voice bootstrap --base-url=http://localhost --json > runtimes/python/voice_realtime/bootstrap.local.json',
                'cp runtimes/python/voice_realtime/.env.example runtimes/python/voice_realtime/.env.local',
                'PYTHONPATH=runtimes/python/voice_realtime python3 -m atlas_voice_agent.main --env-file runtimes/python/voice_realtime/.env.local --check',
            ],
            'required_first_event_kind' => 'start_session',
            'allowed_event_kinds' => [
                'start_session',
                'wake_word_detected',
                'transcribed_turn',
                'synthesized',
                'played',
                'interrupted',
                'failed',
                'provider_health_degraded',
                'session_ended',
            ],
            'forbidden_payloads' => [
                'raw_audio',
                'audio_bytes',
                'access_token',
                'token',
                'livekit_token',
                'api_key',
                'api_secret',
                'response_text',
                'raw_response_text',
                'tts_text',
                'direct_llm_provider_call',
                'direct_tool_execution',
            ],
            'contract' => $this->contract(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function runtimeDependencyPlan(): array
    {
        $manifestPath = base_path('runtimes/python/voice_realtime/runtime-dependencies.json');
        $manifest = is_file($manifestPath)
            ? json_decode((string) file_get_contents($manifestPath), true)
            : null;

        return [
            'schema_version' => 'atlas.voice_realtime.runtime_dependency_plan.v1',
            'status' => is_array($manifest) ? 'ready' : 'missing_manifest',
            'surface_id' => 'voice_realtime',
            'runtime_id' => 'livekit_agents_sdk',
            'runtime_family' => 'python_ai_data',
            'manifest_path' => 'runtimes/python/voice_realtime/runtime-dependencies.json',
            'manifest_hash' => is_array($manifest) ? hash('sha256', json_encode($manifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)) : null,
            'core_dependencies' => data_get($manifest, 'core.third_party_dependencies', []),
            'optional_livekit_packages' => data_get($manifest, 'optional_livekit.packages', []),
            'install_command' => data_get($manifest, 'optional_livekit.install_command'),
            'activation_gate' => data_get($manifest, 'optional_livekit.activation_gate'),
            'guardrails' => [
                'kernel_decides' => true,
                'runtime_executes_only_after_decision_receipt' => true,
                'raw_audio_persistence_allowed' => false,
                'sdk_dependency_is_optional_until_sdk_check_ready' => true,
            ],
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
            'client_surface' => $this->allowedValue($payload['client_surface'] ?? 'mobile', self::ALLOWED_CLIENT_SURFACES, 'client_surface'),
            'transport' => $this->allowedValue($payload['transport'] ?? 'mobile_push_to_talk', self::ALLOWED_TRANSPORTS, 'transport'),
            'runtime' => $this->allowedValue($payload['runtime'] ?? 'livekit_agents_sdk', self::ALLOWED_RUNTIMES, 'runtime'),
            'rivals_arm' => $this->rivalsArm($payload['rivals_arm'] ?? null),
            'privacy_class' => $this->allowedValue($payload['privacy_class'] ?? data_get($payload, 'privacy.class', 'p3_audio'), self::ALLOWED_PRIVACY_CLASSES, 'privacy_class'),
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
     * @param  array<string,mixed>  $session
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function sessionLease(array $session, array $payload): array
    {
        $roomName = $this->string($payload['room_name'] ?? '', 120);
        if ($roomName === '') {
            $roomName = 'atlas-voice-'.$this->roomSlug($session['session_id']);
        }

        $participant = $this->string($payload['participant_identity'] ?? '', 120);
        if ($participant === '') {
            $participant = $session['client_surface'].':'.data_get($session, 'operator.operator_id', 'voice_operator');
        }

        $expiresAt = now()->addMinutes(15)->toJSON();
        $lease = [
            'schema_version' => 'atlas.voice.session_lease.v1',
            'mode' => 'mobile_push_to_talk',
            'room_name' => $roomName,
            'participant_identity' => $participant,
            'runtime_id' => 'livekit_agents_sdk',
            'transport' => $session['transport'],
            'livekit_url' => $this->liveKitTokens->liveKitUrl(),
            'token_status' => 'not_issued_scaffold',
            'token_issuer' => 'livekit_pending',
            'expires_at' => $expiresAt,
            'kernel_decision_required_per_turn' => true,
            'raw_audio_persistence_allowed' => false,
            'turn_endpoint' => '/ai/voice/turn',
            'mobile_turn_endpoint' => '/v1/mobile/ai/voice/turn',
        ];
        $token = $this->liveKitTokens->issue($session, $lease);
        $lease['token_status'] = $token['status'];
        $lease['token_issuer'] = $token['issuer'];
        $lease['token_reason'] = $token['reason'] ?? null;
        $lease['expires_at'] = $token['expires_at'] ?? $expiresAt;
        $lease['ttl_seconds'] = $token['ttl_seconds'] ?? 900;
        $lease['grant'] = $token['grant'] ?? [
            'room_join' => false,
            'room' => $roomName,
        ];
        if (($token['issued'] ?? false) === true) {
            $lease['access_token'] = $token['access_token'];
        }

        return $lease;
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
            'response_text_hash' => $this->string($payload['response_text_hash'] ?? '', 160),
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

    /**
     * @param  array<int,string>  $allowed
     */
    private function allowedValue(mixed $value, array $allowed, string $field): string
    {
        $value = $this->string($value, 120);
        if (in_array($value, $allowed, true)) {
            return $value;
        }

        throw new \InvalidArgumentException("Invalid Atlas Voice {$field}: {$value}");
    }

    private function rivalsArm(mixed $value): string
    {
        return $value === 'direct_provider_baseline' ? 'direct_provider_baseline' : 'atlas_voice';
    }

    private function roomSlug(mixed $value): string
    {
        $slug = Str::slug((string) $value);

        return $slug !== '' ? Str::limit($slug, 80, '') : strtolower((string) Str::ulid());
    }
}
