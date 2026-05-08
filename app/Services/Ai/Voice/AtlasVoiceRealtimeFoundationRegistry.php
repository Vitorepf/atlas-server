<?php

namespace App\Services\Ai\Voice;

final class AtlasVoiceRealtimeFoundationRegistry
{
    private const SCHEMA_VERSION = 'atlas.voice_realtime.foundation_registry.v1';

    public function __construct(
        private readonly AtlasVoiceCallbackPayloadContract $payloads,
        private readonly AtlasVoiceCallbackSequenceContract $sequences,
        private readonly AtlasVoiceRuntimeEventNormalizer $normalizer,
        private readonly AtlasVoiceKernelHandoffContract $handoffs,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function summary(): array
    {
        $payloadContract = $this->payloads->schemas();
        $sequenceContract = $this->sequences->contract();
        $normalizerContract = $this->normalizer->contract();
        $handoffContract = $this->handoffs->contract();
        $components = [
            $this->component('ap181_callback_payload_contract', 'AP-181', $payloadContract, [
                'validates_callback_payload_shape',
                'rejects_raw_audio_raw_response_text_secrets_and_tool_calls',
                'fails_closed_on_unknown_fields',
            ]),
            $this->component('ap182_callback_sequence_contract', 'AP-182', $sequenceContract, [
                'validates_session_order',
                'requires_join_before_turn',
                'requires_transcript_before_tts_and_playback',
            ]),
            $this->component('ap183_runtime_event_normalizer', 'AP-183', $normalizerContract, [
                'maps_runtime_aliases_to_canonical_callbacks',
                'drops_non_contract_fields',
                'validates_payload_and_sequence',
            ]),
            $this->component('ap184_kernel_handoff_contract', 'AP-184', $handoffContract, [
                'maps_callbacks_to_kernel_handoff_kinds',
                'declares_receipt_and_envelope_requirements',
                'emits_stable_hash_and_dedupe_key',
            ]),
        ];

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => 'foundation_ready_for_adapter_integration',
            'surface_id' => 'voice_realtime',
            'mode' => 'read_only_registry',
            'authority' => 'foundation_status_only_no_runtime_execution',
            'component_count' => count($components),
            'components' => $components,
            'pipeline' => [
                'runtime_or_mobile_event',
                'ap183_runtime_event_normalizer',
                'ap181_callback_payload_contract',
                'ap182_callback_sequence_contract',
                'ap184_kernel_handoff_contract',
                'future_authorized_adapter',
                'atlas_voice_realtime_service',
                'operation_envelope_and_decision_receipt_when_applicable',
                'evidence_ledger_by_authorized_service_only',
            ],
            'readiness_gates' => $this->readinessGates(),
            'integration_boundaries' => [
                'may_connect_next' => [
                    'authorized_voice_adapter',
                    'existing_atlas_voice_realtime_service_methods',
                    'existing_python_livekit_bridge_after_contract_parity_review',
                ],
                'must_not_connect_here' => [
                    'provider_direct_execution',
                    'tool_direct_execution',
                    'memory_write',
                    'evidence_ledger_write',
                    'policy_override',
                    'route_or_command_side_effect',
                ],
            ],
            'guardrails' => [
                'runtime_execution_enabled' => false,
                'kernel_execution_enabled' => false,
                'provider_execution_enabled' => false,
                'ledger_write_enabled' => false,
                'operation_envelope_creation_enabled' => false,
                'decision_receipt_issuance_enabled' => false,
                'raw_audio_persistence_allowed' => false,
                'raw_response_text_persistence_allowed' => false,
                'secret_persistence_allowed' => false,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function readiness(): array
    {
        $summary = $this->summary();
        $gates = $summary['readiness_gates'];
        $failed = array_values(array_filter(
            $gates,
            fn (array $gate): bool => ($gate['passed'] ?? false) !== true,
        ));

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $failed === [] ? 'ready' : 'not_ready',
            'surface_id' => 'voice_realtime',
            'ready_for' => $failed === [] ? 'adapter_integration_review' : 'foundation_repair',
            'failed_gate_count' => count($failed),
            'failed_gates' => $failed,
            'gates' => $gates,
            'next_allowed_step' => $failed === []
                ? 'connect_through_authorized_adapter_with_existing_kernel_methods'
                : 'repair_failed_foundation_gate_before_integration',
            'summary' => $summary,
        ];
    }

    /**
     * @param  array<string,mixed>  $contract
     * @param  array<int,string>  $capabilities
     * @return array<string,mixed>
     */
    private function component(string $id, string $ap, array $contract, array $capabilities): array
    {
        return [
            'id' => $id,
            'ap' => $ap,
            'status' => $contract['status'] ?? 'unknown',
            'schema_version' => $contract['schema_version'] ?? null,
            'mode' => $contract['mode'] ?? null,
            'authority' => $contract['authority'] ?? null,
            'capabilities' => $capabilities,
            'runtime_execution_enabled' => (bool) data_get($contract, 'guardrails.runtime_execution_enabled', false),
            'provider_execution_enabled' => (bool) data_get($contract, 'guardrails.provider_execution_enabled', false),
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function readinessGates(): array
    {
        $payloadContract = $this->payloads->schemas();
        $sequenceContract = $this->sequences->contract();
        $normalizerContract = $this->normalizer->contract();
        $handoffContract = $this->handoffs->contract();

        return [
            $this->gate(
                'payload_contract_blocks_raw_audio',
                in_array('raw_audio', data_get($payloadContract, 'callback_schemas.transcript_final.prohibited', []), true)
                    && data_get($payloadContract, 'guardrails.raw_audio_persistence_allowed') === false,
            ),
            $this->gate(
                'payload_contract_blocks_raw_response_text',
                in_array('response_text', data_get($payloadContract, 'callback_schemas.tts_synthesized.prohibited', []), true)
                    && data_get($payloadContract, 'guardrails.raw_response_text_persistence_allowed') === false,
            ),
            $this->gate(
                'sequence_contract_requires_join_before_turn',
                data_get($sequenceContract, 'sequence_rules.participant_joined_required_before_turn_events') === true,
            ),
            $this->gate(
                'normalizer_maps_runtime_aliases',
                data_get($normalizerContract, 'event_to_callback.room_connected') === 'participant_joined'
                    && data_get($normalizerContract, 'event_to_callback.transcribed_turn') === 'transcript_final'
                    && data_get($normalizerContract, 'event_to_callback.synthesized') === 'tts_synthesized',
            ),
            $this->gate(
                'handoff_requires_decision_for_turn',
                data_get($handoffContract, 'handoff_requirements.turn_decision_request.operation_envelope_required') === true
                    && data_get($handoffContract, 'handoff_requirements.turn_decision_request.decision_receipt_required') === true,
            ),
            $this->gate(
                'handoff_requires_existing_receipt_for_runtime_callbacks',
                data_get($handoffContract, 'handoff_requirements.runtime_callback_report.existing_decision_receipt_required') === true,
            ),
            $this->gate(
                'foundation_has_no_execution_authority',
                data_get($normalizerContract, 'guardrails.runtime_execution_enabled') === false
                    && data_get($handoffContract, 'guardrails.kernel_execution_enabled') === false
                    && data_get($handoffContract, 'guardrails.ledger_write_enabled') === false,
            ),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function gate(string $id, bool $passed): array
    {
        return [
            'id' => $id,
            'passed' => $passed,
            'severity_if_failed' => 'integration_blocker',
        ];
    }
}
