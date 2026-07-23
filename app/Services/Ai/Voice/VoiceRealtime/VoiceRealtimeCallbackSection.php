<?php

namespace App\Services\Ai\Voice\VoiceRealtime;

use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use App\Services\Ai\Voice\AtlasVoiceRealtimeService;
use Illuminate\Support\Str;

/**
 * Voice runtime callback recording (synthesis/playback/failure/provider-health), split from {@see AtlasVoiceRealtimeService} (GOD-DEBULK).
 */
final class VoiceRealtimeCallbackSection
{
    public function __construct(
        private readonly AtlasEvidenceLedger $ledger,
        private readonly VoiceRealtimeSupport $support,
    ) {}

    /**
     * @param  array<string,mixed>  $payload
     * @param  array<string,string>  $options
     * @return array<string,mixed>
     */
    public function recordRuntimeCallback(LedgerEventType $type, array $payload, array $options): array
    {
        $session = $this->support->baseSession($payload);
        $turnId = $this->support->string($payload['turn_id'] ?? (string) Str::ulid(), 80);
        $contractViolations = $this->support->runtimeCallbackContractViolations($type, $payload);

        if ($contractViolations !== []) {
            $failure = $this->ledger->recordVoiceEvent(LedgerEventType::VoiceRuntimeFailed, [
                ...$session,
                'turn_id' => $turnId,
                'runtime' => $this->support->string($payload['runtime'] ?? $session['runtime'], 120),
                'failure_code' => 'runtime_callback_payload_contract_violation',
                'rejected_callback_event_type' => $type->value,
                'violations' => $contractViolations,
                'error_message_hash' => $this->support->runtimeFailureMessageHash('runtime_callback_payload_contract_violation', $type->value, $contractViolations),
                'raw_audio_persisted' => false,
                'raw_text_persisted' => false,
            ], $this->support->ledgerContext($session));

            return [
                'schema_version' => AtlasVoiceRealtimeService::SCHEMA_VERSION,
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
                'contract' => $this->support->contract(),
                'evidence_ledger' => $this->support->ledgerEventPayload($failure),
            ];
        }

        if ($this->support->callbackRequiresAcceptedTurn($type) && ! $this->support->hasAcceptedKernelTurn($session, $turnId)) {
            $failure = $this->ledger->recordVoiceEvent(LedgerEventType::VoiceRuntimeFailed, [
                ...$session,
                'turn_id' => $turnId,
                'runtime' => $this->support->string($payload['runtime'] ?? $session['runtime'], 120),
                'failure_code' => 'kernel_turn_not_accepted',
                'rejected_callback_event_type' => $type->value,
                'requires_voice_turn_decided' => true,
                'error_message_hash' => $this->support->runtimeFailureMessageHash('kernel_turn_not_accepted', $type->value),
                'raw_audio_persisted' => false,
                'raw_text_persisted' => false,
            ], $this->support->ledgerContext($session));

            return [
                'schema_version' => AtlasVoiceRealtimeService::SCHEMA_VERSION,
                'status' => 'callback_rejected_missing_kernel_turn',
                'session' => $session,
                'turn' => [
                    'turn_id' => $turnId,
                    'event_type' => $type->value,
                    'accepted_kernel_turn_required' => true,
                    'raw_audio_persisted' => false,
                    'raw_text_persisted' => false,
                ],
                'contract' => $this->support->contract(),
                'evidence_ledger' => $this->support->ledgerEventPayload($failure),
            ];
        }

        $voicePayload = [
            ...$session,
            'turn_id' => $turnId,
            'runtime' => $this->support->string($payload['runtime'] ?? $session['runtime'], 120),
            'provider' => $this->support->string($payload[$options['provider_key'] ?? 'provider'] ?? $payload['provider'] ?? '', 120),
            'model' => $this->support->string($payload['model'] ?? '', 120),
            'audio_hash' => $this->support->sha256Hex($payload['audio_hash'] ?? null),
            'audio_duration_ms' => $payload[$options['duration_key'] ?? 'audio_duration_ms'] ?? $payload['audio_duration_ms'] ?? null,
            'latency_ms' => isset($payload['latency_ms']) ? (int) $payload['latency_ms'] : null,
            'failure_code' => $this->support->string($payload[$options['failure_key'] ?? 'failure_code'] ?? $payload['failure_code'] ?? '', 160),
            'error_class' => $this->support->string($payload['error_class'] ?? '', 160),
            'error_message_hash' => $this->support->sha256Hex($payload['error_message_hash'] ?? null) ?? '',
            'response_text_hash' => $this->support->sha256Hex($payload['response_text_hash'] ?? null) ?? '',
        ];

        $event = $this->ledger->recordVoiceEvent($type, $voicePayload, $this->support->ledgerContext($session));

        return [
            'schema_version' => AtlasVoiceRealtimeService::SCHEMA_VERSION,
            'status' => $options['status'],
            'session' => $session,
            'turn' => [
                'turn_id' => $turnId,
                'event_type' => $type->value,
                'raw_audio_persisted' => false,
                'raw_text_persisted' => false,
            ],
            'contract' => $this->support->contract(),
            'evidence_ledger' => $this->support->ledgerEventPayload($event),
        ];
    }
}
