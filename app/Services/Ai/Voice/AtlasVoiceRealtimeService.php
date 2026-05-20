<?php

namespace App\Services\Ai\Voice;

use App\Models\AtlasLedgerEvent;
use App\Services\Ai\AiGatewayService;
use App\Services\Ai\Kernel\Architecture\AtlasRuntimeLanguageBoundaryReportService;
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
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Throwable;

final class AtlasVoiceRealtimeService
{
    public const SCHEMA_VERSION = 'atlas.voice_realtime.v1';

    private const PYTHON_COMMAND_TIMEOUT_SECONDS = 30;

    private const ALLOWED_CLIENT_SURFACES = ['mobile', 'mac_edge'];

    private const ALLOWED_TRANSPORTS = ['mobile_push_to_talk', 'livekit_webrtc'];

    private const ALLOWED_RUNTIMES = ['livekit_agents_sdk'];

    private const ALLOWED_PRIVACY_CLASSES = ['p1_public', 'p2_internal', 'p3_audio', 'p4_secret'];

    private const CALLBACKS_REQUIRING_ACCEPTED_TURN = [
        'VOICE_TURN_SYNTHESIZED',
        'VOICE_TURN_PLAYED',
        'VOICE_TURN_INTERRUPTED',
        'VOICE_RUNTIME_FAILED',
        'VOICE_PROVIDER_HEALTH_DEGRADED',
    ];

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
            'optional' => ['reason', 'interrupted_stage', 'played_duration_ms', 'latency_ms'],
            'prohibited' => ['raw_audio', 'audio_bytes', 'pcm', 'wav'],
        ],
        'runtime_failed' => [
            'required' => ['session_id', 'turn_id', 'error_message_hash'],
            'optional' => ['failure_code', 'error_class', 'latency_ms'],
            'prohibited' => ['raw_audio', 'audio_bytes', 'response_text', 'raw_response_text', 'error_message', 'message', 'tool_call', 'tool_args'],
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
        private readonly AtlasRuntimeLanguageBoundaryReportService $runtimeBoundary,
        private readonly AiGatewayService $gateway,
    ) {}

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    public function startSession(array $payload): array
    {
        $session = $this->baseSession($payload);
        $eclipse = $this->eclipseGuard->evaluate($payload);
        $lease = $this->sessionLease($session, $payload, $eclipse);
        $event = $this->ledger->recordVoiceEvent(LedgerEventType::VoiceSessionStarted, [
            ...$session,
            'session_lease' => $lease,
            'eclipse' => $eclipse,
            'transport' => $session['transport'],
            'runtime' => $session['runtime'],
        ], $this->ledgerContext($session));

        $ready = $this->sessionLeaseReady($lease);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $ready ? 'session_ready' : 'session_blocked',
            'session' => $session,
            'session_lease' => $lease,
            'livekit_url' => $lease['livekit_url'] ?? null,
            'room_name' => $lease['room_name'] ?? null,
            'participant_identity' => $lease['participant_identity'] ?? null,
            'participant_token' => $lease['access_token'] ?? null,
            'agent_identity' => $lease['agent_identity'] ?? null,
            'eclipse' => $eclipse,
            'contract' => $this->contract(),
            'evidence_ledger' => $this->ledgerEventPayload($event),
        ];
    }

    /**
     * @param  array<string,mixed>  $session
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function maybeEnqueueTranscriptInteraction(
        array $session,
        array $payload,
        string $turnId,
        string $transcript,
        DecisionReceipt $receipt,
    ): array {
        if ($transcript === '') {
            return [
                'dispatched' => false,
                'status' => 'skipped_no_transcript',
                'reason' => 'voice_turn_has_no_transcript_final',
            ];
        }

        if (! (bool) ($payload['dispatch_to_ai'] ?? false)) {
            return [
                'dispatched' => false,
                'status' => 'skipped_not_requested',
                'reason' => 'dispatch_to_ai_not_requested',
                'transcript_hash' => hash('sha256', $transcript),
            ];
        }

        if (! (bool) ($payload['allow_transcript_persistence'] ?? false)) {
            return [
                'dispatched' => false,
                'status' => 'blocked_transcript_persistence_not_allowed',
                'reason' => 'voice_contract_requires_explicit_transcript_persistence_for_ai_interaction',
                'transcript_hash' => hash('sha256', $transcript),
            ];
        }

        if ($this->looksLikeSttGhostTranscript($transcript)) {
            return [
                'dispatched' => false,
                'status' => 'skipped_suspect_stt_ghost_transcript',
                'reason' => 'voice_transcript_looked_like_short_url_or_common_whisper_hallucination',
                'transcript_hash' => hash('sha256', $transcript),
            ];
        }

        try {
            $trace = $this->gateway->enqueueInteraction($transcript, [
                'client_id' => 'voice:'.$session['session_id'].':'.$turnId,
                'source_type' => 'voice_realtime',
                'source_id' => $turnId,
                'kind' => 'interaction',
                'thread_id' => is_string($payload['ai_thread_id'] ?? null) ? (string) $payload['ai_thread_id'] : null,
                'new_thread' => ! is_string($payload['ai_thread_id'] ?? null),
                'agent_slug' => config('atlas.ai.default_agent', 'orquestrador'),
                'include_semantic_context' => true,
                'context_note_limit' => 5,
                'payload' => [
                    'app_surface' => 'voice_realtime',
                    'atlas_focus' => $this->string($payload['domain_hint'] ?? 'general', 120) ?: 'general',
                    'routing_task' => 'voice_turn',
                    'routing_domain' => $this->string($payload['domain_hint'] ?? 'general', 120) ?: 'general',
                    'response_style' => 'conversational',
                    'privacy' => [
                        'source' => 'voice_realtime_transcript',
                        'privacy_class' => $session['privacy_class'],
                        'raw_audio_persisted' => false,
                        'transcript_persistence_explicitly_allowed' => true,
                    ],
                    'voice_realtime' => [
                        'schema_version' => self::SCHEMA_VERSION,
                        'session_id' => $session['session_id'],
                        'turn_id' => $turnId,
                        'transport' => $session['transport'],
                        'runtime' => $session['runtime'],
                        'audio_hash' => $this->sha256Hex($payload['audio_hash'] ?? null),
                        'audio_duration_ms' => $payload['audio_duration_ms'] ?? null,
                        'transcript_hash' => hash('sha256', $transcript),
                        'decision_receipt_id' => $receipt->receiptId,
                        'decision_receipt_hash' => $receipt->receiptHash,
                        'decision_domain' => $receipt->domain,
                        'decision_flow' => $receipt->flow,
                    ],
                ],
            ]);

            return [
                'dispatched' => true,
                'status' => 'ai_interaction_enqueued',
                'trace_id' => $trace->id,
                'thread_id' => $trace->thread_id,
                'trace_status' => $trace->status,
                'transcript_hash' => hash('sha256', $transcript),
            ];
        } catch (Throwable $error) {
            return [
                'dispatched' => false,
                'status' => 'ai_interaction_enqueue_failed',
                'error_class' => $error::class,
                'error_message_hash' => hash('sha256', $error->getMessage()),
                'transcript_hash' => hash('sha256', $transcript),
            ];
        }
    }

    private function looksLikeSttGhostTranscript(string $transcript): bool
    {
        $normalized = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $transcript) ?? $transcript));
        $stripped = trim($normalized, " \t\n\r\0\x0B.!?;,");
        $ascii = $this->asciiFold($stripped);

        if ($stripped === '') {
            return true;
        }

        $commonGhosts = [
            'www.tinyurl.com.br',
            'tinyurl.com.br',
            'www tinyurl com br',
            'tinyurl com br',
            'acesse o site www.tinyurl.com.br para mais informações',
            'acesse o site tinyurl.com.br para mais informações',
            'acesse o site www.tinyurl.com.br para mais informacoes',
            'acesse o site tinyurl.com.br para mais informacoes',
        ];

        if (in_array($stripped, $commonGhosts, true) || in_array($ascii, $commonGhosts, true)) {
            return true;
        }

        $wordCount = str_word_count(str_replace(['://', '/', '.', '-'], ' ', $stripped), 0, 'áàâãéêíóôõúç');
        $isUrlOnly = preg_match('/^(?:acesse\s+(?:o\s+site\s+)?)?(?:https?:\/\/)?(?:www\.)?[a-z0-9][a-z0-9.-]*\.[a-z]{2,}(?:\/\S*)?(?:\s+para\s+mais\s+informações)?$/u', $stripped) === 1;
        $isAsciiUrlOnly = preg_match('/^(?:acesse\s+(?:o\s+site\s+)?)?(?:https?:\/\/)?(?:www\.)?[a-z0-9][a-z0-9.-]*\.[a-z]{2,}(?:\/\S*)?(?:\s+para\s+mais\s+informacoes)?$/u', $ascii) === 1;
        $isGenericMarketingUrlGhost = preg_match('/^acesse\s+(?:o\s+site\s+)?(?:https?:\/\/)?(?:www\.)?[a-z0-9][a-z0-9.-]*\.[a-z]{2,}(?:\/\S*)?\s+para\s+mais\s+informacoes$/u', $ascii) === 1;

        return $isGenericMarketingUrlGhost || (($isUrlOnly || $isAsciiUrlOnly) && $wordCount <= 8);
    }

    private function asciiFold(string $value): string
    {
        return strtr($value, [
            'á' => 'a',
            'à' => 'a',
            'â' => 'a',
            'ã' => 'a',
            'ä' => 'a',
            'é' => 'e',
            'ê' => 'e',
            'ë' => 'e',
            'í' => 'i',
            'ï' => 'i',
            'ó' => 'o',
            'ô' => 'o',
            'õ' => 'o',
            'ö' => 'o',
            'ú' => 'u',
            'ü' => 'u',
            'ç' => 'c',
        ]);
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
                'audio_hash' => $this->sha256Hex($payload['audio_hash'] ?? null),
                'audio_duration_ms' => $payload['audio_duration_ms'] ?? null,
            ], $this->ledgerContext($session));
        }

        $transcript = is_string($payload['transcript'] ?? null) ? trim((string) $payload['transcript']) : '';
        if ($transcript !== '') {
            $events['transcribed'] = $this->ledger->recordVoiceEvent(LedgerEventType::VoiceTurnTranscribed, [
                ...$session,
                'turn_id' => $turnId,
                'transcript_hash' => hash('sha256', $transcript),
                'transcript_length' => mb_strlen($transcript),
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

        $aiInteraction = $this->maybeEnqueueTranscriptInteraction($session, $payload, $turnId, $transcript, $receipt);

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
                'provider_execution_enabled' => (bool) ($aiInteraction['dispatched'] ?? false),
                'runtime_execution_enabled' => false,
                'requires_decision_receipt' => true,
                'raw_audio_persisted' => false,
                'ai_interaction' => $aiInteraction,
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
        $interruptionSource = $this->string($payload['interruption_source'] ?? 'operator', 80) ?: 'operator';

        if ($interruptionSource === 'runtime_callback') {
            $contractViolations = $this->runtimeCallbackContractViolations(LedgerEventType::VoiceTurnInterrupted, $payload);
            if ($contractViolations !== []) {
                $failure = $this->ledger->recordVoiceEvent(LedgerEventType::VoiceRuntimeFailed, [
                    ...$session,
                    'turn_id' => $turnId,
                    'runtime' => $this->string($payload['runtime'] ?? $session['runtime'], 120),
                    'failure_code' => 'runtime_interruption_payload_contract_violation',
                    'rejected_callback_event_type' => LedgerEventType::VoiceTurnInterrupted->value,
                    'violations' => $contractViolations,
                    'raw_audio_persisted' => false,
                    'raw_text_persisted' => false,
                ], $this->ledgerContext($session));

                return [
                    'schema_version' => self::SCHEMA_VERSION,
                    'status' => 'callback_rejected_payload_contract',
                    'session' => $session,
                    'turn' => [
                        'turn_id' => $turnId,
                        'event_type' => LedgerEventType::VoiceTurnInterrupted->value,
                        'interruption_source' => $interruptionSource,
                        'payload_contract_valid' => false,
                        'violations' => $contractViolations,
                        'raw_audio_persisted' => false,
                        'raw_text_persisted' => false,
                    ],
                    'contract' => $this->contract(),
                    'evidence_ledger' => $this->ledgerEventPayload($failure),
                ];
            }

            if (! $this->hasAcceptedKernelTurn($session, $turnId)) {
                $failure = $this->ledger->recordVoiceEvent(LedgerEventType::VoiceRuntimeFailed, [
                    ...$session,
                    'turn_id' => $turnId,
                    'runtime' => $this->string($payload['runtime'] ?? $session['runtime'], 120),
                    'failure_code' => 'kernel_turn_not_accepted',
                    'rejected_callback_event_type' => LedgerEventType::VoiceTurnInterrupted->value,
                    'requires_voice_turn_decided' => true,
                    'error_message_hash' => $this->runtimeFailureMessageHash('kernel_turn_not_accepted', LedgerEventType::VoiceTurnInterrupted->value),
                    'raw_audio_persisted' => false,
                    'raw_text_persisted' => false,
                ], $this->ledgerContext($session));

                return [
                    'schema_version' => self::SCHEMA_VERSION,
                    'status' => 'callback_rejected_missing_kernel_turn',
                    'session' => $session,
                    'turn' => [
                        'turn_id' => $turnId,
                        'event_type' => LedgerEventType::VoiceTurnInterrupted->value,
                        'interruption_source' => $interruptionSource,
                        'accepted_kernel_turn_required' => true,
                        'raw_audio_persisted' => false,
                        'raw_text_persisted' => false,
                    ],
                    'contract' => $this->contract(),
                    'evidence_ledger' => $this->ledgerEventPayload($failure),
                ];
            }
        }

        $reason = $this->string($payload['reason'] ?? 'operator_interrupted', 120);
        $interruptedStage = $this->string($payload['interrupted_stage'] ?? 'runtime_or_tts', 120);
        $playedDurationMs = isset($payload['played_duration_ms']) ? (int) $payload['played_duration_ms'] : null;
        $latencyMs = isset($payload['latency_ms']) ? (int) $payload['latency_ms'] : null;

        $event = $this->ledger->recordVoiceEvent(LedgerEventType::VoiceTurnInterrupted, [
            ...$session,
            'turn_id' => $turnId,
            'interruption_source' => $interruptionSource,
            'reason' => $reason,
            'interrupted_stage' => $interruptedStage,
            'played_duration_ms' => $playedDurationMs,
            'latency_ms' => $latencyMs,
        ], $this->ledgerContext($session));
        $slo = null;
        if ($latencyMs !== null) {
            $assessment = $this->sloTargets->assess('voice.interruption_stop_audio', $latencyMs, true);
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
                'interruption_source' => $interruptionSource,
                'reason' => $reason,
                'interrupted_stage' => $interruptedStage,
                'played_duration_ms' => $playedDurationMs,
                'latency_ms' => $latencyMs,
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
            'activation_governance' => $this->activationGovernance(),
            'livekit_token_issuer' => $this->liveKitTokens->enabled() ? 'configured' : 'disabled',
            'runtime_invocation_contract' => $this->runtimeInvocationContract('python_ai_data', 'livekit_agents_sdk'),
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
        $phase0Hardening = $this->phase0HardeningGate();
        $productLoopCheck = $this->productLoopCheckReference();
        $enterpriseMobileLoop = $this->enterpriseMobileLoopContract();
        $runtimeDependencies = $this->runtimeDependencyPlan();
        $runtimeDependencySummary = $this->runtimeDependencySummary($runtimeDependencies);
        $required = [
            LedgerEventType::VoiceSessionStarted->value,
            LedgerEventType::VoiceTurnDecided->value,
            LedgerEventType::VoiceTurnSynthesized->value,
            LedgerEventType::VoiceTurnPlayed->value,
        ];

        if (! Schema::hasTable('atlas_ledger_events')) {
            return [
                'schema_version' => 'atlas.voice.readiness.v1',
                'available' => false,
                'status' => 'ledger_unavailable',
                'hours' => $hours,
                'window' => ['since' => $since->toJSON(), 'until' => $until->toJSON()],
                'mobile_first' => true,
                'score' => null,
                'event_counts' => [],
                'required_events' => $required,
                'missing_events' => $required,
                'gates' => [
                    'ledger_available' => false,
                    'required_events_present' => false,
                    'latency_slo_clean' => false,
                    'raw_audio_forbidden' => true,
                    'kernel_decision_per_turn' => true,
                    'rivals_voice_ready' => false,
                ],
                'review_signal' => [
                    'status' => 'blocked',
                    'severity' => 'high',
                    'recommended_action' => 'run_ledger_migrations_before_voice_readiness',
                ],
                'next_action' => 'run_ledger_migrations_before_voice_readiness',
                'phase0_hardening' => $phase0Hardening,
                'product_loop_check' => $productLoopCheck,
                'enterprise_mobile_loop' => [
                    ...$enterpriseMobileLoop,
                    'status' => 'blocked',
                    'missing_promotion_events' => $enterpriseMobileLoop['promotion_required_events'],
                ],
                'runtime_dependency_summary' => $runtimeDependencySummary,
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
        $redactedRuntimeFailureCount = $events
            ->filter(fn (AtlasLedgerEvent $event): bool => $event->event_type === LedgerEventType::VoiceRuntimeFailed->value)
            ->filter(fn (AtlasLedgerEvent $event): bool => is_string(data_get($event->payload, 'voice.error_message_hash'))
                && preg_match('/^[a-f0-9]{64}$/', (string) data_get($event->payload, 'voice.error_message_hash')) === 1)
            ->count();
        $missing = collect($required)
            ->filter(fn (string $type): bool => (int) ($eventCounts[$type] ?? 0) === 0)
            ->values()
            ->all();
        $missingPromotionEvents = collect($enterpriseMobileLoop['promotion_required_events'])
            ->filter(fn (string $type): bool => $type === LedgerEventType::VoiceRuntimeFailed->value
                ? $redactedRuntimeFailureCount === 0
                : (int) ($eventCounts[$type] ?? 0) === 0)
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
            'phase0_hardening' => $phase0Hardening,
            'product_loop_check' => $productLoopCheck,
            'enterprise_mobile_loop' => [
                ...$enterpriseMobileLoop,
                'status' => $missingPromotionEvents === [] && $sloBreaches === 0 ? 'promotion_evidence_ready' : 'needs_promotion_evidence',
                'missing_promotion_events' => $missingPromotionEvents,
                'gates' => [
                    'healthy_loop_ready' => $missing === [] && $sloBreaches === 0,
                    'interruption_drill_recorded' => (int) ($eventCounts[LedgerEventType::VoiceTurnInterrupted->value] ?? 0) > 0,
                    'redacted_failure_drill_recorded' => $redactedRuntimeFailureCount > 0,
                    'latency_slo_clean' => $sloBreaches === 0,
                    'raw_payload_persistence_forbidden' => true,
                ],
            ],
            'runtime_dependency_summary' => $runtimeDependencySummary,
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
                    ? ($sloBreaches === 0
                        ? ($missingPromotionEvents === [] ? 'voice_enterprise_loop_ready_for_promotion_review' : 'voice_readiness_can_enter_rivals_voice')
                        : 'investigate_voice_latency_slo_breaches')
                    : 'complete_voice_required_events_before_rivals_voice',
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $dependencies
     * @return array<string,mixed>
     */
    private function runtimeDependencySummary(array $dependencies): array
    {
        return [
            'schema_version' => 'atlas.voice_realtime.runtime_dependency_summary.v1',
            'status' => $dependencies['status'] ?? 'unknown',
            'python_runtime_status' => data_get($dependencies, 'python_runtime.status'),
            'configured_python_binary' => data_get($dependencies, 'python_runtime.configured_binary'),
            'configured_python_version' => data_get($dependencies, 'python_runtime.configured_version'),
            'python_minimum_version' => data_get($dependencies, 'python_runtime.minimum_version'),
            'python_satisfies_minimum' => data_get($dependencies, 'python_runtime.configured_satisfies_minimum'),
            'operator_managed' => data_get($dependencies, 'python_runtime.operator_managed'),
            'auto_install_allowed' => data_get($dependencies, 'python_runtime.auto_install_allowed'),
            'next_action' => data_get($dependencies, 'python_runtime.next_action', $dependencies['next_action'] ?? null),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function phase0HardeningGate(): array
    {
        $runtimeBoundary = $this->runtimeBoundary->report();
        $gates = [
            'mobile_first_surface_contract' => [
                'passed' => true,
                'evidence' => 'client_surfaces_include_mobile_and_default_transport_mobile_push_to_talk',
            ],
            'kernel_decision_receipt_required' => [
                'passed' => true,
                'evidence' => 'runtime_invocation_contract_requires_decision_receipt_hash',
            ],
            'callback_loop_fail_closed' => [
                'passed' => true,
                'evidence' => 'runtime_callbacks_require_accepted_turn_before_synthesis_playback_failure_or_provider_health',
            ],
            'privacy_audio_not_persisted' => [
                'passed' => true,
                'evidence' => 'raw_audio_raw_transcript_and_raw_response_text_are_forbidden',
            ],
            'runtime_boundary_green' => [
                'passed' => (bool) data_get($runtimeBoundary, 'boundary.valid'),
                'evidence' => 'ap201_runtime_language_boundary_contract',
                'violation_count' => data_get($runtimeBoundary, 'boundary.violation_count', 0),
            ],
            'production_promotion_review_locked' => [
                'passed' => true,
                'evidence' => 'production_promotion_requires_certification_human_review_decision_receipt_and_rollback',
            ],
        ];

        $failed = collect($gates)
            ->filter(fn (array $gate): bool => ! (bool) ($gate['passed'] ?? false))
            ->keys()
            ->values()
            ->all();

        return [
            'schema_version' => 'atlas.voice_realtime.phase0_hardening_gate.v1',
            'status' => $failed === [] ? 'ready' : 'blocked',
            'surface_id' => 'voice_realtime',
            'runtime_id' => 'livekit_agents_sdk',
            'mobile_first' => true,
            'kernel_only' => true,
            'promotion_allowed' => false,
            'auto_promotion_allowed' => false,
            'human_review_required_for_production' => true,
            'safe_next_block' => 'Voice Realtime phase 0 hardening',
            'required_validation' => [
                'php artisan atlas:ai:voice runtime-certify --json',
                'php artisan atlas:ai:voice callback-loop-check --json',
                'php artisan atlas:ai:voice product-loop-check --json',
                'php artisan atlas:ai:voice readiness --json',
                'php artisan atlas:ai:runtime-boundary --json',
                'php artisan atlas:ai:architecture-validate --json',
            ],
            'gates' => $gates,
            'summary' => [
                'gate_count' => count($gates),
                'passed_gates' => count($gates) - count($failed),
                'failed_gates' => count($failed),
                'failed_keys' => $failed,
            ],
            'next_action' => $failed === []
                ? 'collect_real_mobile_voice_usage_before_livekit_production_promotion'
                : 'fix_voice_phase0_hardening_gates_before_product_runtime',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function productLoopCheckReference(): array
    {
        return [
            'schema_version' => 'atlas.voice_realtime.product_loop_check_reference.v1',
            'status' => 'available_as_runtime_contract',
            'surface_id' => 'voice_realtime',
            'runtime_id' => 'livekit_agents_sdk',
            'command' => 'php artisan atlas:ai:voice product-loop-check --json',
            'machine_contract' => 'atlas.voice_realtime.product_loop_check.v1',
            'required_gates' => [
                'callback_loop_wired',
                'production_sdk_loop_wired',
                'worker_start_still_blocked',
                'production_promotion_blocked',
                'sdk_probe_import_safe',
                'sdk_kernel_normalizer_required',
                'direct_provider_forbidden',
                'raw_audio_forbidden',
            ],
            'promotion_allowed' => false,
            'auto_promotion_allowed' => false,
            'daemon_started' => false,
            'next_action' => 'run_product_loop_check_before_livekit_daemon_work',
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    public function runtimeContract(array $payload = []): array
    {
        $runtimeId = $this->allowedValue($payload['runtime'] ?? 'livekit_agents_sdk', self::ALLOWED_RUNTIMES, 'runtime');

        return [
            'schema_version' => 'atlas.voice_realtime.runtime_contract.v1',
            'status' => 'ready',
            'surface_id' => 'voice_realtime',
            'mobile_first' => true,
            'runtime_family' => 'python_ai_data',
            'runtime_id' => $runtimeId,
            'kernel_is_decision_authority' => true,
            'activation_governance' => $this->activationGovernance(),
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
                'room_prefix' => 'atlas-voice-',
                'required_room_prefix' => 'atlas-voice-',
                'participant_namespace_source' => 'client_surface',
                'livekit_url' => $this->liveKitTokens->liveKitUrl(),
                'kernel_decision_required_per_turn' => true,
            ],
            'session_start_endpoint' => '/ai/voice/session/start',
            'session_end_endpoint' => '/ai/voice/session/end',
            'readiness_endpoint' => '/ai/voice/readiness',
            'rivals_endpoint' => '/ai/voice/rivals',
            'runtime_dependencies_endpoint' => '/ai/voice/runtime/dependencies',
            'runtime_dependency_install_plan_endpoint' => '/ai/voice/runtime/dependency-install-plan',
            'runtime_token_issuer_plan_endpoint' => '/ai/voice/runtime/token-issuer-plan',
            'runtime_token_issuer_smoke_endpoint' => '/ai/voice/runtime/token-issuer-smoke',
            'runtime_livekit_server_probe_endpoint' => '/ai/voice/runtime/livekit-server-probe',
            'runtime_pre_start_health_checks_smoke_endpoint' => '/ai/voice/runtime/pre-start-health-checks-smoke',
            'runtime_certification_endpoint' => '/ai/voice/runtime/certification',
            'runtime_product_loop_check_endpoint' => '/ai/voice/runtime/product-loop-check',
            'runtime_promotion_review_packet_endpoint' => '/ai/voice/runtime/promotion-review-packet',
            'runtime_event_normalizer_endpoint' => '/ai/voice/runtime/events/normalize',
            'runtime_event_sequence_normalizer_endpoint' => '/ai/voice/runtime/events/normalize-sequence',
            'mobile_session_start_endpoint' => '/v1/mobile/ai/voice/session/start',
            'mobile_session_end_endpoint' => '/v1/mobile/ai/voice/session/end',
            'mobile_readiness_endpoint' => '/v1/mobile/ai/voice/readiness',
            'mobile_rivals_endpoint' => '/v1/mobile/ai/voice/rivals',
            'mobile_runtime_dependencies_endpoint' => '/v1/mobile/ai/voice/runtime/dependencies',
            'mobile_runtime_dependency_install_plan_endpoint' => '/v1/mobile/ai/voice/runtime/dependency-install-plan',
            'mobile_runtime_token_issuer_plan_endpoint' => '/v1/mobile/ai/voice/runtime/token-issuer-plan',
            'mobile_runtime_token_issuer_smoke_endpoint' => '/v1/mobile/ai/voice/runtime/token-issuer-smoke',
            'mobile_runtime_livekit_server_probe_endpoint' => '/v1/mobile/ai/voice/runtime/livekit-server-probe',
            'mobile_runtime_pre_start_health_checks_smoke_endpoint' => '/v1/mobile/ai/voice/runtime/pre-start-health-checks-smoke',
            'mobile_runtime_certification_endpoint' => '/v1/mobile/ai/voice/runtime/certification',
            'mobile_runtime_product_loop_check_endpoint' => '/v1/mobile/ai/voice/runtime/product-loop-check',
            'mobile_runtime_promotion_review_packet_endpoint' => '/v1/mobile/ai/voice/runtime/promotion-review-packet',
            'mobile_runtime_event_normalizer_endpoint' => '/v1/mobile/ai/voice/runtime/events/normalize',
            'mobile_runtime_event_sequence_normalizer_endpoint' => '/v1/mobile/ai/voice/runtime/events/normalize-sequence',
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
            'runtime_invocation_contract' => $this->runtimeInvocationContract('python_ai_data', $runtimeId),
            'callback_order' => [
                'normal_turn' => ['turn', 'turn_synthesized', 'turn_played'],
                'operator_interruptible_anytime' => ['turn_interrupted'],
                'runtime_health_after_accepted_turn' => ['runtime_failed', 'provider_health_degraded'],
                'runtime_callbacks_require_kernel_accepted_turn' => ['turn_synthesized', 'turn_played', 'turn_interrupted', 'runtime_failed', 'provider_health_degraded'],
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
            'enterprise_mobile_loop' => $this->enterpriseMobileLoopContract(),
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
        $baseUrl = $this->kernelBaseUrl($payload);
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
                'runtime_dependency_install_plan_url' => $baseUrl.$contract['runtime_dependency_install_plan_endpoint'],
                'runtime_token_issuer_plan_url' => $baseUrl.$contract['runtime_token_issuer_plan_endpoint'],
                'runtime_token_issuer_smoke_url' => $baseUrl.$contract['runtime_token_issuer_smoke_endpoint'],
                'runtime_livekit_server_probe_url' => $baseUrl.$contract['runtime_livekit_server_probe_endpoint'],
                'runtime_pre_start_health_checks_smoke_url' => $baseUrl.$contract['runtime_pre_start_health_checks_smoke_endpoint'],
                'runtime_certification_url' => $baseUrl.$contract['runtime_certification_endpoint'],
                'runtime_product_loop_check_url' => $baseUrl.$contract['runtime_product_loop_check_endpoint'],
                'runtime_promotion_review_packet_url' => $baseUrl.$contract['runtime_promotion_review_packet_endpoint'],
                'mobile_session_start_url' => $baseUrl.$contract['mobile_session_start_endpoint'],
                'mobile_session_end_url' => $baseUrl.$contract['mobile_session_end_endpoint'],
                'mobile_readiness_url' => $baseUrl.$contract['mobile_readiness_endpoint'],
                'mobile_rivals_url' => $baseUrl.$contract['mobile_rivals_endpoint'],
                'mobile_runtime_dependencies_url' => $baseUrl.$contract['mobile_runtime_dependencies_endpoint'],
                'mobile_runtime_dependency_install_plan_url' => $baseUrl.$contract['mobile_runtime_dependency_install_plan_endpoint'],
                'mobile_runtime_token_issuer_plan_url' => $baseUrl.$contract['mobile_runtime_token_issuer_plan_endpoint'],
                'mobile_runtime_token_issuer_smoke_url' => $baseUrl.$contract['mobile_runtime_token_issuer_smoke_endpoint'],
                'mobile_runtime_livekit_server_probe_url' => $baseUrl.$contract['mobile_runtime_livekit_server_probe_endpoint'],
                'mobile_runtime_pre_start_health_checks_smoke_url' => $baseUrl.$contract['mobile_runtime_pre_start_health_checks_smoke_endpoint'],
                'mobile_runtime_certification_url' => $baseUrl.$contract['mobile_runtime_certification_endpoint'],
                'mobile_runtime_product_loop_check_url' => $baseUrl.$contract['mobile_runtime_product_loop_check_endpoint'],
                'mobile_runtime_promotion_review_packet_url' => $baseUrl.$contract['mobile_runtime_promotion_review_packet_endpoint'],
                'runtime_event_normalizer_url' => $baseUrl.$contract['runtime_event_normalizer_endpoint'],
                'runtime_event_sequence_normalizer_url' => $baseUrl.$contract['runtime_event_sequence_normalizer_endpoint'],
                'mobile_runtime_event_normalizer_url' => $baseUrl.$contract['mobile_runtime_event_normalizer_endpoint'],
                'mobile_runtime_event_sequence_normalizer_url' => $baseUrl.$contract['mobile_runtime_event_sequence_normalizer_endpoint'],
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
            'activation_governance' => $contract['activation_governance'],
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
            'runtime_invocation_contract' => $contract['runtime_invocation_contract'],
            'session_lease' => $contract['session_lease'],
            'allowlists' => $contract['allowlists'],
            'slo_stages' => $contract['slo_stages'],
            'contract_hash' => hash('sha256', json_encode($contract, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)),
            'generated_at' => now()->toJSON(),
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function kernelBaseUrl(array $payload): string
    {
        $fallback = rtrim($this->string(config('app.url', 'http://localhost'), 240), '/') ?: 'http://localhost';
        $baseUrl = rtrim($this->string($payload['base_url'] ?? $fallback, 240), '/');

        if ($baseUrl === '' || preg_match('/[\x00-\x1F\x7F]/', $baseUrl) === 1) {
            return $fallback;
        }

        $parts = parse_url($baseUrl);
        $scheme = is_array($parts) ? strtolower((string) ($parts['scheme'] ?? '')) : '';
        $host = is_array($parts) ? (string) ($parts['host'] ?? '') : '';

        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            return $fallback;
        }

        return $baseUrl;
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
        $pythonRuntime = $this->voicePythonRuntimePlan(is_array($manifest) ? $manifest : []);

        return [
            'schema_version' => 'atlas.voice_realtime.runtime_dependency_plan.v1',
            'status' => is_array($manifest) ? 'ready' : 'missing_manifest',
            'surface_id' => 'voice_realtime',
            'runtime_id' => 'livekit_agents_sdk',
            'runtime_family' => 'python_ai_data',
            'manifest_path' => 'runtimes/python/voice_realtime/runtime-dependencies.json',
            'manifest_hash' => is_array($manifest) ? hash('sha256', json_encode($manifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)) : null,
            'python_runtime' => $pythonRuntime,
            'core_dependencies' => data_get($manifest, 'core.third_party_dependencies', []),
            'optional_livekit_packages' => data_get($manifest, 'optional_livekit.packages', []),
            'requirements_file' => data_get($manifest, 'optional_livekit.requirements_file'),
            'install_command' => data_get($manifest, 'optional_livekit.install_command'),
            'verify_command' => data_get($manifest, 'optional_livekit.verify_command'),
            'activation_gate' => data_get($manifest, 'optional_livekit.activation_gate'),
            'install_policy' => data_get($manifest, 'optional_livekit.install_policy'),
            'guardrails' => [
                'kernel_decides' => true,
                'runtime_executes_only_after_decision_receipt' => true,
                'raw_audio_persistence_allowed' => false,
                'sdk_dependency_is_optional_until_sdk_check_ready' => true,
                'dependency_install_is_operator_managed' => true,
            ],
            'next_action' => data_get($pythonRuntime, 'configured_satisfies_minimum')
                ? 'run_voice_sdk_check'
                : 'configure_python_3_10_plus_for_voice_runtime',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function runtimeDependencyInstallPlan(): array
    {
        $manifestPath = base_path('runtimes/python/voice_realtime/runtime-dependencies.json');
        $manifest = is_file($manifestPath)
            ? json_decode((string) file_get_contents($manifestPath), true)
            : null;
        $optional = is_array($manifest) ? (array) data_get($manifest, 'optional_livekit', []) : [];
        $packages = collect((array) ($optional['packages'] ?? []))
            ->filter(fn (mixed $package): bool => is_array($package))
            ->values();
        $requirementsRef = trim((string) ($optional['requirements_file'] ?? ''));
        $requirementsPath = $this->requirementsFilePath($requirementsRef);
        $requirementsLines = $this->requirementsLines($requirementsPath);
        $expectedRequirements = $packages
            ->map(fn (array $package): string => $this->expectedRequirement($package))
            ->filter(fn (string $requirement): bool => $requirement !== '')
            ->values()
            ->all();
        $expectedPackages = $packages
            ->map(fn (array $package): string => trim((string) ($package['pip'] ?? '')))
            ->filter(fn (string $package): bool => $package !== '')
            ->values()
            ->all();
        $missingRequirements = collect($expectedRequirements)
            ->reject(fn (string $requirement): bool => in_array($requirement, $requirementsLines, true))
            ->values()
            ->all();
        $unsafeRequirements = collect($requirementsLines)
            ->filter(fn (string $line): bool => $this->unsafeRequirementLine($line))
            ->values()
            ->all();
        $requirementsReady = $requirementsRef !== ''
            && $requirementsPath !== null
            && is_file($requirementsPath)
            && $missingRequirements === []
            && $unsafeRequirements === [];

        return [
            'schema_version' => 'atlas.voice_realtime.dependency_install_plan.v1',
            'status' => $requirementsReady ? 'ready_to_install_optional_dependency' : 'blocked',
            'runtime_id' => 'livekit_agents_sdk',
            'runtime_family' => 'python_ai_data',
            'surface_id' => 'voice_realtime',
            'operator_managed' => true,
            'pip_execution_attempted' => false,
            'sdk_imported' => false,
            'daemon_started' => false,
            'kernel_only' => true,
            'mobile_first' => true,
            'requirements_file' => $requirementsRef,
            'requirements_path' => $requirementsPath,
            'requirements_sha256' => $requirementsReady ? hash_file('sha256', (string) $requirementsPath) : null,
            'expected_packages' => $expectedPackages,
            'expected_requirements' => $expectedRequirements,
            'requirements_packages' => $requirementsLines,
            'missing_requirements' => $missingRequirements,
            'unsafe_requirements' => $unsafeRequirements,
            'install_command' => $optional['install_command'] ?? null,
            'verify_command' => $optional['verify_command'] ?? null,
            'activation_gate' => $optional['activation_gate'] ?? null,
            'install_policy' => $optional['install_policy'] ?? null,
            'gates' => [
                'manifest_available' => is_array($manifest) && ($manifest['schema_version'] ?? null) === 'atlas.voice_realtime.runtime_dependencies.v1',
                'requirements_file_declared' => $requirementsRef !== '',
                'requirements_file_exists' => $requirementsPath !== null && is_file($requirementsPath),
                'requirements_match_manifest' => $missingRequirements === [],
                'requirements_safe' => $unsafeRequirements === [],
                'pip_not_executed' => true,
                'sdk_not_imported' => true,
                'daemon_not_started' => true,
            ],
            'forbidden_shortcuts' => [
                'run_pip_from_sdk_check',
                'install_dependency_without_operator_review',
                'import_livekit_during_install_plan',
                'start_daemon_after_dependency_install',
                'change_kernel_policy_from_dependency_install',
            ],
            'next_action' => $requirementsReady
                ? 'run_install_command_then_sdk_check'
                : 'fix_dependency_install_plan',
        ];
    }

    /**
     * @param  array<string,mixed>  $manifest
     * @return array<string,mixed>
     */
    private function voicePythonRuntimePlan(array $manifest): array
    {
        $minimum = (string) data_get($manifest, 'python.minimum_version', '3.10');
        $recommended = (string) data_get($manifest, 'python.recommended_version', '3.11');
        $configured = $this->configuredVoicePythonBinary();
        $candidates = collect([
            $configured,
            'python3.13',
            'python3.12',
            'python3.11',
            'python3.10',
            'python3',
            '/opt/homebrew/bin/python3.13',
            '/opt/homebrew/bin/python3.12',
            '/opt/homebrew/bin/python3.11',
            '/opt/homebrew/bin/python3.10',
            '/usr/local/bin/python3.13',
            '/usr/local/bin/python3.12',
            '/usr/local/bin/python3.11',
            '/usr/local/bin/python3.10',
            '/usr/bin/python3',
        ])
            ->filter(fn (string $binary): bool => $binary !== '')
            ->unique()
            ->map(fn (string $binary): array => $this->inspectPythonBinary($binary, $minimum))
            ->values()
            ->all();
        $configuredReport = collect($candidates)
            ->first(fn (array $candidate): bool => $candidate['binary'] === $configured);
        $readyCandidate = collect($candidates)
            ->first(fn (array $candidate): bool => (bool) ($candidate['satisfies_minimum'] ?? false));

        return [
            'schema_version' => 'atlas.voice_realtime.python_runtime_plan.v1',
            'status' => (bool) data_get($configuredReport, 'satisfies_minimum')
                ? 'ready'
                : 'blocked',
            'binary_config' => data_get($manifest, 'python.binary_config', 'ATLAS_VOICE_PYTHON_BIN or config atlas_ai.voice_realtime.python_binary'),
            'configured_binary' => $configured,
            'configured_available' => (bool) data_get($configuredReport, 'available'),
            'configured_version' => data_get($configuredReport, 'version'),
            'minimum_version' => $minimum,
            'recommended_version' => $recommended,
            'configured_satisfies_minimum' => (bool) data_get($configuredReport, 'satisfies_minimum'),
            'best_available_binary' => data_get($readyCandidate, 'binary'),
            'best_available_version' => data_get($readyCandidate, 'version'),
            'candidates' => $candidates,
            'operator_managed' => true,
            'auto_install_allowed' => false,
            'configuration_examples' => [
                'env' => 'export ATLAS_VOICE_PYTHON_BIN=/opt/homebrew/bin/python3.11',
                'cli_override' => 'php artisan atlas:ai:voice sdk-check --python-bin=/opt/homebrew/bin/python3.11 --json',
                'install_hint_macos' => 'brew install python@3.11',
            ],
            'next_action' => (bool) data_get($configuredReport, 'satisfies_minimum')
                ? 'run_voice_sdk_check'
                : ((bool) data_get($readyCandidate, 'available')
                    ? 'set_ATLAS_VOICE_PYTHON_BIN_to_best_available_binary'
                    : 'install_python_3_11_then_set_ATLAS_VOICE_PYTHON_BIN'),
        ];
    }

    private function configuredVoicePythonBinary(): string
    {
        $configured = trim((string) config('atlas_ai.voice_realtime.python_binary', 'python3'));

        if ($configured === '' || str_contains($configured, "\0") || str_contains($configured, "\n") || str_contains($configured, "\r")) {
            return 'python3';
        }

        return $configured;
    }

    /**
     * @return array<string,mixed>
     */
    private function inspectPythonBinary(string $binary, string $minimum): array
    {
        $process = new Process([$binary, '--version'], base_path());
        $process->setTimeout(5);
        $process->run();

        $versionOutput = trim($process->getOutput().' '.$process->getErrorOutput());
        preg_match('/Python\s+([0-9]+(?:\.[0-9]+){1,2})/', $versionOutput, $matches);
        $version = $matches[1] ?? null;

        return [
            'binary' => $binary,
            'available' => $process->isSuccessful() && $version !== null,
            'version' => $version,
            'satisfies_minimum' => $version !== null && version_compare($version, $minimum, '>='),
            'exit_code' => $process->getExitCode(),
        ];
    }

    private function requirementsFilePath(string $requirementsRef): ?string
    {
        if ($requirementsRef === '') {
            return null;
        }

        return str_starts_with($requirementsRef, '/')
            ? $requirementsRef
            : base_path($requirementsRef);
    }

    /**
     * @return array<int,string>
     */
    private function requirementsLines(?string $requirementsPath): array
    {
        if ($requirementsPath === null || ! is_file($requirementsPath)) {
            return [];
        }

        return collect(explode("\n", (string) file_get_contents($requirementsPath)))
            ->map(fn (string $line): string => trim($line))
            ->filter(fn (string $line): bool => $line !== '' && ! str_starts_with($line, '#'))
            ->values()
            ->all();
    }

    /**
     * @param  array<string,mixed>  $package
     */
    private function expectedRequirement(array $package): string
    {
        $pip = trim((string) ($package['pip'] ?? ''));
        if ($pip === '') {
            return '';
        }

        return $pip.trim((string) ($package['version_specifier'] ?? ''));
    }

    private function unsafeRequirementLine(string $line): bool
    {
        return str_starts_with($line, '-')
            || str_contains($line, '://')
            || str_starts_with($line, 'git+')
            || str_contains($line, ';');
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    public function preStartHealthChecksSmoke(array $payload = []): array
    {
        $bootstrapPath = tempnam(sys_get_temp_dir(), 'atlas-voice-bootstrap-');
        if ($bootstrapPath === false) {
            return [
                'schema_version' => 'atlas.voice_realtime.pre_start_health_checks_smoke.v1',
                'status' => 'failed',
                'surface_id' => 'voice_realtime',
                'runtime_id' => 'livekit_agents_sdk',
                'failure' => 'could_not_create_temp_bootstrap',
            ];
        }

        $bootstrap = $this->runtimeBootstrapManifest([
            'runtime' => $payload['runtime'] ?? 'livekit_agents_sdk',
            'base_url' => $payload['base_url'] ?? 'http://atlas.test',
        ]);
        file_put_contents($bootstrapPath, json_encode($bootstrap, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $process = new Process([
            $this->configuredVoicePythonBinary(),
            '-m',
            'atlas_voice_agent.main',
            '--bootstrap',
            $bootstrapPath,
            '--pre-start-health-checks-smoke',
        ], base_path(), [
            'PYTHONPATH' => base_path('runtimes/python/voice_realtime'),
        ]);
        $process->setTimeout(self::PYTHON_COMMAND_TIMEOUT_SECONDS);

        try {
            $process->run();
            $decoded = json_decode($process->getOutput(), true);
            if (! is_array($decoded)) {
                return [
                    'schema_version' => 'atlas.voice_realtime.pre_start_health_checks_smoke.v1',
                    'status' => 'failed',
                    'surface_id' => 'voice_realtime',
                    'runtime_id' => 'livekit_agents_sdk',
                    'failure' => 'voice_runtime_command_invalid_json',
                    'timeout_seconds' => self::PYTHON_COMMAND_TIMEOUT_SECONDS,
                    'exit_code' => $process->getExitCode(),
                    'stderr_hash' => $process->getErrorOutput() !== '' ? hash('sha256', $process->getErrorOutput()) : null,
                ];
            }

            $decoded = $this->sanitizeRuntimeSmokePayload($decoded);
            $decoded['timeout_seconds'] = self::PYTHON_COMMAND_TIMEOUT_SECONDS;
            $decoded['exit_code'] = $process->getExitCode();
            $decoded['stderr_hash'] = $process->getErrorOutput() !== '' ? hash('sha256', $process->getErrorOutput()) : null;

            return $decoded;
        } catch (ProcessTimedOutException) {
            return [
                'schema_version' => 'atlas.voice_realtime.pre_start_health_checks_smoke.v1',
                'status' => 'failed',
                'surface_id' => 'voice_realtime',
                'runtime_id' => 'livekit_agents_sdk',
                'failure' => 'voice_runtime_command_timeout',
                'timeout_seconds' => self::PYTHON_COMMAND_TIMEOUT_SECONDS,
                'exit_code' => null,
                'stderr_hash' => null,
            ];
        } finally {
            @unlink($bootstrapPath);
        }
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    public function productLoopCheck(array $payload = []): array
    {
        $args = ['--product-loop-check'];
        if ((bool) ($payload['callback_loop_wired'] ?? false)) {
            $args[] = '--callback-loop-wired';
        }
        if ((bool) ($payload['production_sdk_loop_wired'] ?? false)) {
            $args[] = '--production-sdk-loop-wired';
        }

        return $this->runPythonRuntimeEnvCommand(
            payload: $payload,
            args: $args,
            schemaVersion: 'atlas.voice_realtime.product_loop_check.v1',
            token: 'product-loop-token',
            livekitKey: 'product-loop-key',
            livekitSecret: 'product-loop-secret',
        );
    }

    /**
     * @param  array<string,mixed>  $payload
     * @param  array<int,string>  $args
     * @return array<string,mixed>
     */
    private function runPythonRuntimeEnvCommand(
        array $payload,
        array $args,
        string $schemaVersion,
        string $token,
        string $livekitKey,
        string $livekitSecret,
    ): array {
        $runtime = $this->allowedValue($payload['runtime'] ?? 'livekit_agents_sdk', self::ALLOWED_RUNTIMES, 'runtime');
        $baseUrl = $this->kernelBaseUrl($payload);
        $bootstrapPath = tempnam(sys_get_temp_dir(), 'atlas-voice-bootstrap-');
        $envPath = tempnam(sys_get_temp_dir(), 'atlas-voice-env-');
        if ($bootstrapPath === false || $envPath === false) {
            return [
                'schema_version' => $schemaVersion,
                'status' => 'failed',
                'surface_id' => 'voice_realtime',
                'runtime_id' => $runtime,
                'failure' => 'could_not_create_temp_runtime_files',
            ];
        }

        $bootstrap = $this->runtimeBootstrapManifest([
            'runtime' => $runtime,
            'base_url' => $baseUrl,
        ]);
        file_put_contents($bootstrapPath, json_encode($bootstrap, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        file_put_contents($envPath, implode("\n", [
            'ATLAS_BASE_URL='.$baseUrl,
            'ATLAS_TOKEN='.$token,
            'ATLAS_VOICE_BOOTSTRAP='.$bootstrapPath,
            'LIVEKIT_URL=http://livekit.test',
            'LIVEKIT_API_KEY='.$livekitKey,
            'LIVEKIT_API_SECRET='.$livekitSecret,
            'ATLAS_VOICE_STT_PROVIDER=configurable',
            'ATLAS_VOICE_TTS_PROVIDER=configurable',
        ]));

        $process = new Process([
            $this->configuredVoicePythonBinary(),
            '-m',
            'atlas_voice_agent.main',
            '--env-file',
            $envPath,
            ...$args,
        ], base_path(), [
            'PYTHONPATH' => base_path('runtimes/python/voice_realtime'),
        ]);
        $process->setTimeout(self::PYTHON_COMMAND_TIMEOUT_SECONDS);

        try {
            $process->run();
            $decoded = json_decode($process->getOutput(), true);
            if (! is_array($decoded)) {
                return [
                    'schema_version' => $schemaVersion,
                    'status' => 'failed',
                    'surface_id' => 'voice_realtime',
                    'runtime_id' => $runtime,
                    'failure' => 'voice_runtime_command_invalid_json',
                    'timeout_seconds' => self::PYTHON_COMMAND_TIMEOUT_SECONDS,
                    'exit_code' => $process->getExitCode(),
                    'stderr_hash' => $process->getErrorOutput() !== '' ? hash('sha256', $process->getErrorOutput()) : null,
                ];
            }

            $decoded = $this->sanitizeRuntimeSmokePayload($decoded);
            $decoded['timeout_seconds'] = self::PYTHON_COMMAND_TIMEOUT_SECONDS;
            $decoded['exit_code'] = $process->getExitCode();
            $decoded['stderr_hash'] = $process->getErrorOutput() !== '' ? hash('sha256', $process->getErrorOutput()) : null;
            $decoded['command'] = 'PYTHONPATH=runtimes/python/voice_realtime '.$this->configuredVoicePythonBinary().' -m atlas_voice_agent.main --env-file <generated> '.implode(' ', $args);

            return $decoded;
        } catch (ProcessTimedOutException) {
            return [
                'schema_version' => $schemaVersion,
                'status' => 'failed',
                'surface_id' => 'voice_realtime',
                'runtime_id' => $runtime,
                'failure' => 'voice_runtime_command_timeout',
                'timeout_seconds' => self::PYTHON_COMMAND_TIMEOUT_SECONDS,
                'exit_code' => null,
                'stderr_hash' => null,
            ];
        } finally {
            @unlink($bootstrapPath);
            @unlink($envPath);
        }
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
     * @param  array<string,mixed>|null  $payload
     * @return array<string,mixed>|null
     */
    private function sanitizeRuntimeSmokePayload(?array $payload): ?array
    {
        if ($payload === null) {
            return null;
        }

        $forbidden = [
            'access_token',
            'token',
            'livekit_token',
            'api_key',
            'api_secret',
            'raw_audio',
            'audio_bytes',
            'pcm',
            'wav',
            'response_text',
            'raw_response_text',
            'tts_text',
            'tool_call',
            'tool_args',
            'provider_api_key',
        ];

        return collect($payload)
            ->reject(fn (mixed $_, string|int $key): bool => in_array((string) $key, $forbidden, true))
            ->map(fn (mixed $value): mixed => is_array($value) ? $this->sanitizeRuntimeSmokePayload($value) : $value)
            ->all();
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
            'transport' => $this->allowedValue($payload['transport'] ?? 'livekit_webrtc', self::ALLOWED_TRANSPORTS, 'transport'),
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
        $audioHash = $this->sha256Hex($payload['audio_hash'] ?? null);

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
    private function sessionLease(array $session, array $payload, array $eclipse = []): array
    {
        $roomName = $this->voiceRoomName($payload['room_name'] ?? null, $session['session_id']);
        $participant = $this->voiceParticipantIdentity(
            $payload['participant_identity'] ?? null,
            $session['client_surface'],
            data_get($session, 'operator.operator_id', 'voice_operator'),
        );

        $expiresAt = now()->addMinutes(15)->toJSON();
        $lease = [
            'schema_version' => 'atlas.voice.session_lease.v1',
            'mode' => 'livekit_webrtc',
            'room_name' => $roomName,
            'participant_identity' => $participant,
            'agent_identity' => 'atlas-agent:'.$roomName,
            'agent_name' => (string) config('atlas.voice.livekit.agent_name', 'atlas-voice-agent'),
            'runtime_id' => 'livekit_agents_sdk',
            'transport' => 'livekit_webrtc',
            'livekit_url' => $this->liveKitTokens->publicLiveKitUrl(),
            'token_status' => 'not_issued_scaffold',
            'token_issuer' => 'livekit_pending',
            'expires_at' => $expiresAt,
            'kernel_decision_required_per_turn' => true,
            'raw_audio_persistence_allowed' => false,
            'activation_governance' => $this->activationGovernance(),
            'turn_endpoint' => '/ai/voice/turn',
            'mobile_turn_endpoint' => '/v1/mobile/ai/voice/turn',
        ];

        if ((bool) ($eclipse['active'] ?? false)) {
            $lease['token_status'] = 'blocked_by_eclipse';
            $lease['token_issuer'] = 'atlas_voice_eclipse_guard';
            $lease['token_reason'] = 'voice_session_token_blocked_by_active_eclipse';
            $lease['ttl_seconds'] = 0;
            $lease['grant'] = [
                'room_join' => false,
                'room' => $roomName,
            ];

            return $lease;
        }

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
     * @param  array<string,mixed>  $lease
     */
    private function sessionLeaseReady(array $lease): bool
    {
        return ($lease['token_status'] ?? null) === 'issued'
            && is_string($lease['access_token'] ?? null)
            && trim((string) $lease['access_token']) !== ''
            && is_string($lease['livekit_url'] ?? null)
            && trim((string) $lease['livekit_url']) !== ''
            && is_string($lease['room_name'] ?? null)
            && trim((string) $lease['room_name']) !== ''
            && is_string($lease['participant_identity'] ?? null)
            && trim((string) $lease['participant_identity']) !== '';
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
        $contractViolations = $this->runtimeCallbackContractViolations($type, $payload);

        if ($contractViolations !== []) {
            $failure = $this->ledger->recordVoiceEvent(LedgerEventType::VoiceRuntimeFailed, [
                ...$session,
                'turn_id' => $turnId,
                'runtime' => $this->string($payload['runtime'] ?? $session['runtime'], 120),
                'failure_code' => 'runtime_callback_payload_contract_violation',
                'rejected_callback_event_type' => $type->value,
                'violations' => $contractViolations,
                'error_message_hash' => $this->runtimeFailureMessageHash('runtime_callback_payload_contract_violation', $type->value, $contractViolations),
                'raw_audio_persisted' => false,
                'raw_text_persisted' => false,
            ], $this->ledgerContext($session));

            return [
                'schema_version' => self::SCHEMA_VERSION,
                'status' => 'callback_rejected_payload_contract',
                'session' => $session,
                'turn' => [
                    'turn_id' => $turnId,
                    'event_type' => $type->value,
                    'payload_contract_valid' => false,
                    'violations' => $contractViolations,
                    'raw_audio_persisted' => false,
                    'raw_text_persisted' => false,
                ],
                'contract' => $this->contract(),
                'evidence_ledger' => $this->ledgerEventPayload($failure),
            ];
        }

        if ($this->callbackRequiresAcceptedTurn($type) && ! $this->hasAcceptedKernelTurn($session, $turnId)) {
            $failure = $this->ledger->recordVoiceEvent(LedgerEventType::VoiceRuntimeFailed, [
                ...$session,
                'turn_id' => $turnId,
                'runtime' => $this->string($payload['runtime'] ?? $session['runtime'], 120),
                'failure_code' => 'kernel_turn_not_accepted',
                'rejected_callback_event_type' => $type->value,
                'requires_voice_turn_decided' => true,
                'error_message_hash' => $this->runtimeFailureMessageHash('kernel_turn_not_accepted', $type->value),
                'raw_audio_persisted' => false,
                'raw_text_persisted' => false,
            ], $this->ledgerContext($session));

            return [
                'schema_version' => self::SCHEMA_VERSION,
                'status' => 'callback_rejected_missing_kernel_turn',
                'session' => $session,
                'turn' => [
                    'turn_id' => $turnId,
                    'event_type' => $type->value,
                    'accepted_kernel_turn_required' => true,
                    'raw_audio_persisted' => false,
                    'raw_text_persisted' => false,
                ],
                'contract' => $this->contract(),
                'evidence_ledger' => $this->ledgerEventPayload($failure),
            ];
        }

        $voicePayload = [
            ...$session,
            'turn_id' => $turnId,
            'runtime' => $this->string($payload['runtime'] ?? $session['runtime'], 120),
            'provider' => $this->string($payload[$options['provider_key'] ?? 'provider'] ?? $payload['provider'] ?? '', 120),
            'model' => $this->string($payload['model'] ?? '', 120),
            'audio_hash' => $this->sha256Hex($payload['audio_hash'] ?? null),
            'audio_duration_ms' => $payload[$options['duration_key'] ?? 'audio_duration_ms'] ?? $payload['audio_duration_ms'] ?? null,
            'latency_ms' => isset($payload['latency_ms']) ? (int) $payload['latency_ms'] : null,
            'failure_code' => $this->string($payload[$options['failure_key'] ?? 'failure_code'] ?? $payload['failure_code'] ?? '', 160),
            'error_class' => $this->string($payload['error_class'] ?? '', 160),
            'error_message_hash' => $this->sha256Hex($payload['error_message_hash'] ?? null) ?? '',
            'response_text_hash' => $this->sha256Hex($payload['response_text_hash'] ?? null) ?? '',
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

    private function callbackRequiresAcceptedTurn(LedgerEventType $type): bool
    {
        return in_array($type->value, self::CALLBACKS_REQUIRING_ACCEPTED_TURN, true);
    }

    /**
     * @return array<string,mixed>
     */
    private function enterpriseMobileLoopContract(): array
    {
        return [
            'schema_version' => 'atlas.voice_realtime.enterprise_mobile_loop.v1',
            'status' => 'contract_ready',
            'promotion_required_events' => [
                LedgerEventType::VoiceSessionStarted->value,
                LedgerEventType::VoiceTurnDecided->value,
                LedgerEventType::VoiceTurnSynthesized->value,
                LedgerEventType::VoiceTurnPlayed->value,
                LedgerEventType::VoiceTurnInterrupted->value,
                LedgerEventType::VoiceRuntimeFailed->value,
            ],
            'healthy_loop_events' => [
                LedgerEventType::VoiceSessionStarted->value,
                LedgerEventType::VoiceTurnDecided->value,
                LedgerEventType::VoiceTurnSynthesized->value,
                LedgerEventType::VoiceTurnPlayed->value,
            ],
            'required_drills_before_promotion' => [
                'mobile_push_to_talk_success_path',
                'barge_in_interruption_path',
                'runtime_failure_redaction_path',
                'app_background_session_end_path',
                'network_retry_without_raw_payload_path',
            ],
            'mobile_callbacks' => [
                'turn_synthesized' => '/v1/mobile/ai/voice/turn/synthesized',
                'turn_played' => '/v1/mobile/ai/voice/turn/played',
                'turn_interrupted' => '/v1/mobile/ai/voice/turn/interrupted',
                'runtime_failed' => '/v1/mobile/ai/voice/runtime/failed',
            ],
            'privacy_invariants' => [
                'raw_audio_persisted' => false,
                'raw_transcript_persisted' => false,
                'raw_response_text_persisted' => false,
                'runtime_errors_require_error_message_hash' => true,
                'hashes_normalized_lowercase' => true,
            ],
            'latency_slo_stages' => [
                'voice.wake_word_detect',
                'voice.turn_to_first_audio',
                'voice.interruption_stop_audio',
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<int,string>
     */
    private function runtimeCallbackContractViolations(LedgerEventType $type, array $payload): array
    {
        $schemaKey = match ($type) {
            LedgerEventType::VoiceTurnSynthesized => 'tts_synthesized',
            LedgerEventType::VoiceTurnPlayed => 'audio_played',
            LedgerEventType::VoiceTurnInterrupted => 'barge_in',
            LedgerEventType::VoiceRuntimeFailed => 'runtime_failed',
            LedgerEventType::VoiceProviderHealthDegraded => 'provider_health_degraded',
            default => null,
        };

        if ($schemaKey === null) {
            return [];
        }

        $schema = self::CALLBACK_PAYLOAD_SCHEMAS[$schemaKey] ?? [];
        $violations = [];

        foreach ((array) ($schema['required'] ?? []) as $field) {
            if (! array_key_exists($field, $payload) || trim((string) $payload[$field]) === '') {
                $violations[] = 'missing_required_field:'.$field;
            }
        }

        $prohibited = array_values(array_unique([
            ...$this->globalRuntimeCallbackProhibitedFields(),
            ...(array) ($schema['prohibited'] ?? []),
        ]));

        foreach ($this->findProhibitedRuntimeCallbackFields($payload, $prohibited) as $path) {
            $violations[] = 'prohibited_field_present:'.$path;
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function globalRuntimeCallbackProhibitedFields(): array
    {
        return [
            'access_token',
            'api_key',
            'api_secret',
            'audio',
            'audio_bytes',
            'audio_raw',
            'livekit_token',
            'pcm',
            'provider_api_key',
            'raw_audio',
            'raw_audio_bytes',
            'raw_response_text',
            'response_text',
            'token',
            'tool_args',
            'tool_call',
            'tts_text',
            'wav',
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @param  array<int,string>  $prohibited
     * @return array<int,string>
     */
    private function findProhibitedRuntimeCallbackFields(array $payload, array $prohibited, string $prefix = ''): array
    {
        $found = [];

        foreach ($payload as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;
            if (in_array((string) $key, $prohibited, true)) {
                $found[] = $path;
            }

            if (is_array($value)) {
                array_push($found, ...$this->findProhibitedRuntimeCallbackFields($value, $prohibited, $path));
            }
        }

        return array_values(array_unique($found));
    }

    /**
     * @param  array<string,mixed>  $session
     */
    private function hasAcceptedKernelTurn(array $session, string $turnId): bool
    {
        if (! Schema::hasTable('atlas_ledger_events')) {
            return false;
        }

        return AtlasLedgerEvent::query()
            ->where('event_type', LedgerEventType::VoiceTurnDecided->value)
            ->where('correlation_id', $session['session_id'])
            ->get()
            ->contains(fn (AtlasLedgerEvent $event): bool => data_get($event->payload, 'voice.turn_id') === $turnId);
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
    private function runtimeInvocationContract(string $runtimeFamily, string $runtimeId): array
    {
        return [
            ...$this->runtimeBoundary->invocationContract(),
            'selected_runtime_family' => $runtimeFamily,
            'runtime_id' => $runtimeId,
            'surface_id' => 'voice_realtime',
            'evidence_rule' => 'livekit_agents_sdk_must_return_voice_events_and_evidence_refs_to_kernel',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function activationGovernance(): array
    {
        return [
            'schema_version' => 'atlas.voice_realtime.activation_governance.v1',
            'status' => 'livekit_realtime_ready',
            'first_product_surface' => 'mobile',
            'mobile_push_to_talk_required' => false,
            'livekit_agents_sdk_allowed' => true,
            'livekit_agents_direct_provider_allowed' => false,
            'kernel_webhook_required' => true,
            'decision_receipt_required_per_turn' => true,
            'runtime_daemon_start_allowed_now' => false,
            'production_audio_streaming_allowed_now' => false,
            'always_on_listening_allowed_now' => false,
            'mac_edge_first_product_allowed' => false,
            'swift_native_mac_phase' => 'future_after_mobile_voice',
            'promotion_requires' => [
                'livekit_mobile_room_connected',
                'mobile_microphone_published',
                'voice_agent_joined_room',
                'audible_agent_response',
                'barge_in_verified',
                'eclipse_guard_active',
                'runtime_certification_green',
                'callback_loop_green',
                'raw_audio_persistence_forbidden',
                'mobile_operator_controls',
                'rivals_voice_baseline',
                'human_review',
            ],
            'blocked_shortcuts' => [
                'swift_mac_before_mobile',
                'always_on_without_eclipse',
                'livekit_agents_to_provider_direct',
                'daemon_start_without_certification',
                'raw_audio_or_transcript_persistence',
                'voice_domain_creation',
            ],
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

    private function sha256Hex(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $hash = strtolower(trim((string) $value));

        return preg_match('/^[a-f0-9]{64}$/', $hash) === 1 ? $hash : null;
    }

    /**
     * @param  array<int,string>  $violations
     */
    private function runtimeFailureMessageHash(string $failureCode, string $eventType, array $violations = []): string
    {
        return hash('sha256', implode(':', [
            'atlas_voice_runtime_failed',
            $failureCode,
            $eventType,
            implode(',', $violations),
        ]));
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

    private function voiceRoomName(mixed $requestedRoom, mixed $fallback): string
    {
        $slug = $this->roomSlug($requestedRoom ?: $fallback);
        $slug = str_starts_with($slug, 'atlas-voice-')
            ? substr($slug, strlen('atlas-voice-'))
            : $slug;

        return 'atlas-voice-'.Str::limit($slug, 80, '');
    }

    private function voiceParticipantIdentity(mixed $requestedIdentity, mixed $clientSurface, mixed $fallbackOperator): string
    {
        $surface = $this->roomSlug($clientSurface ?: 'mobile');
        $requested = $this->string($requestedIdentity ?? '', 120);

        if ($requested !== '' && str_starts_with($requested, $surface.':')) {
            $requested = substr($requested, strlen($surface) + 1);
        }

        $identity = $this->roomSlug($requested !== '' ? $requested : $fallbackOperator);

        return $surface.':'.Str::limit($identity, 80, '');
    }
}
