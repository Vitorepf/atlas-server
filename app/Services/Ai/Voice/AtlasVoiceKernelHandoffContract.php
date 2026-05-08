<?php

namespace App\Services\Ai\Voice;

use Illuminate\Support\Arr;

final class AtlasVoiceKernelHandoffContract
{
    private const SCHEMA_VERSION = 'atlas.voice_realtime.kernel_handoff_contract.v1';

    private const CALLBACK_TO_HANDOFF = [
        'participant_joined' => 'session_start',
        'wake_word_detected' => 'wake_word_observation',
        'transcript_final' => 'turn_decision_request',
        'tts_synthesized' => 'runtime_callback_report',
        'audio_played' => 'runtime_callback_report',
        'barge_in' => 'runtime_callback_report',
        'runtime_failed' => 'runtime_callback_report',
        'provider_health_degraded' => 'runtime_callback_report',
        'participant_left' => 'session_end',
    ];

    private const HANDOFF_REQUIREMENTS = [
        'session_start' => [
            'operation_envelope_required' => false,
            'decision_receipt_required' => false,
            'existing_decision_receipt_required' => false,
            'ledger_write_allowed_by_contract' => false,
            'kernel_method' => 'startSession',
        ],
        'wake_word_observation' => [
            'operation_envelope_required' => false,
            'decision_receipt_required' => false,
            'existing_decision_receipt_required' => false,
            'ledger_write_allowed_by_contract' => false,
            'kernel_method' => 'recordWakeWord',
        ],
        'turn_decision_request' => [
            'operation_envelope_required' => true,
            'decision_receipt_required' => true,
            'existing_decision_receipt_required' => false,
            'ledger_write_allowed_by_contract' => false,
            'kernel_method' => 'handleTurn',
        ],
        'runtime_callback_report' => [
            'operation_envelope_required' => false,
            'decision_receipt_required' => false,
            'existing_decision_receipt_required' => true,
            'ledger_write_allowed_by_contract' => false,
            'kernel_method' => 'runtimeCallback',
        ],
        'session_end' => [
            'operation_envelope_required' => false,
            'decision_receipt_required' => false,
            'existing_decision_receipt_required' => false,
            'ledger_write_allowed_by_contract' => false,
            'kernel_method' => 'endSession',
        ],
    ];

    public function __construct(
        private readonly AtlasVoiceRuntimeEventNormalizer $normalizer,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function contract(): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => 'ready',
            'mode' => 'handoff_planning_only',
            'authority' => 'kernel_handoff_contract_no_kernel_execution',
            'callback_to_handoff' => self::CALLBACK_TO_HANDOFF,
            'handoff_requirements' => self::HANDOFF_REQUIREMENTS,
            'guardrails' => [
                'kernel_execution_enabled' => false,
                'provider_execution_enabled' => false,
                'ledger_write_enabled' => false,
                'operation_envelope_creation_enabled' => false,
                'decision_receipt_issuance_enabled' => false,
                'normalized_event_required' => true,
                'payload_contract_required' => true,
                'sequence_contract_required_for_batches' => true,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $event
     * @return array<string,mixed>
     */
    public function prepareEvent(array $event): array
    {
        $normalized = $this->normalizer->normalize($event);

        if (! ($normalized['valid'] ?? false)) {
            return [
                'schema_version' => self::SCHEMA_VERSION,
                'status' => 'invalid_event',
                'valid' => false,
                'errors' => $normalized['errors'] ?? ['invalid_normalized_event'],
                'normalized_event' => $normalized,
                'contract' => $this->contract(),
            ];
        }

        $callback = (string) ($normalized['callback'] ?? '');
        $payload = Arr::get($normalized, 'callback_event.payload', []);
        $payload = is_array($payload) ? $payload : [];
        $handoffKind = self::CALLBACK_TO_HANDOFF[$callback] ?? null;

        if ($handoffKind === null) {
            return [
                'schema_version' => self::SCHEMA_VERSION,
                'status' => 'unsupported_callback',
                'valid' => false,
                'errors' => ['unsupported_callback'],
                'callback' => $callback,
                'normalized_event' => $normalized,
                'contract' => $this->contract(),
            ];
        }

        $canonicalHash = $this->canonicalHash($callback, $payload);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => 'ready_for_kernel_handoff',
            'valid' => true,
            'errors' => [],
            'handoff' => [
                'handoff_kind' => $handoffKind,
                'callback' => $callback,
                'kernel_method' => self::HANDOFF_REQUIREMENTS[$handoffKind]['kernel_method'],
                'session_id' => $payload['session_id'] ?? null,
                'turn_id' => $payload['turn_id'] ?? null,
                'domain_hint' => $payload['domain_hint'] ?? null,
                'flow_hint' => $payload['flow_hint'] ?? null,
                'canonical_event_hash' => $canonicalHash,
                'dedupe_key' => $this->dedupeKey($handoffKind, $callback, $payload, $canonicalHash),
                'payload' => $payload,
                'requirements' => self::HANDOFF_REQUIREMENTS[$handoffKind],
                'privacy' => [
                    'raw_audio_present' => false,
                    'raw_response_text_present' => false,
                    'secret_present' => false,
                ],
            ],
            'normalized_event' => $normalized,
            'contract' => $this->contract(),
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $events
     * @return array<string,mixed>
     */
    public function prepareSequence(array $events): array
    {
        $normalized = $this->normalizer->normalizeSequence($events);
        $handoffs = [];
        $errors = [];

        if (! ($normalized['valid'] ?? false)) {
            foreach ((array) ($normalized['errors'] ?? []) as $error) {
                $errors[] = 'normalization_'.$error;
            }
        }

        foreach ((array) ($normalized['normalized_events'] ?? []) as $index => $event) {
            if (! is_array($event)) {
                continue;
            }

            $prepared = $this->prepareEvent($event);
            if ($prepared['valid'] ?? false) {
                $handoffs[] = $prepared['handoff'];
            } else {
                foreach ((array) ($prepared['errors'] ?? []) as $error) {
                    $errors[] = 'event_'.$index.':'.$error;
                }
            }
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $errors === [] ? 'ready_for_kernel_handoff_sequence' : 'invalid_sequence',
            'valid' => $errors === [],
            'errors' => array_values(array_unique($errors)),
            'event_count' => count($events),
            'handoff_count' => count($handoffs),
            'handoffs' => $handoffs,
            'normalization' => $normalized,
            'contract' => $this->contract(),
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function canonicalHash(string $callback, array $payload): string
    {
        ksort($payload);

        return hash('sha256', json_encode([
            'callback' => $callback,
            'payload' => $payload,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function dedupeKey(string $handoffKind, string $callback, array $payload, string $hash): string
    {
        return implode(':', array_filter([
            'voice_realtime',
            $handoffKind,
            $callback,
            (string) ($payload['session_id'] ?? 'session_unknown'),
            (string) ($payload['turn_id'] ?? 'turn_none'),
            substr($hash, 0, 16),
        ], fn (string $part): bool => $part !== ''));
    }
}
