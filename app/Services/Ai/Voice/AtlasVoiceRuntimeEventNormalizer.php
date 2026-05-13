<?php

namespace App\Services\Ai\Voice;

final class AtlasVoiceRuntimeEventNormalizer
{
    private const SCHEMA_VERSION = 'atlas.voice_realtime.runtime_event_normalizer.v1';

    private const EVENT_TO_CALLBACK = [
        'participant_joined' => 'participant_joined',
        'room_connected' => 'participant_joined',
        'start_session' => 'participant_joined',
        'transcript_final' => 'transcript_final',
        'transcribed_turn' => 'transcript_final',
        'wake_word_detected' => 'wake_word_detected',
        'tts_synthesized' => 'tts_synthesized',
        'synthesized' => 'tts_synthesized',
        'audio_played' => 'audio_played',
        'played' => 'audio_played',
        'barge_in' => 'barge_in',
        'turn_interrupted' => 'barge_in',
        'runtime_failed' => 'runtime_failed',
        'provider_health_degraded' => 'provider_health_degraded',
        'participant_left' => 'participant_left',
        'room_disconnected' => 'participant_left',
        'session_ended' => 'participant_left',
    ];

    private const ALLOWED_PAYLOAD_KEYS = [
        'participant_joined' => ['session_id', 'participant_identity', 'room_name', 'client_surface', 'transport', 'privacy_class', 'rivals_arm'],
        'transcript_final' => ['session_id', 'turn_id', 'transcript', 'language', 'domain_hint', 'flow_hint'],
        'wake_word_detected' => ['session_id', 'wake_word_engine', 'confidence', 'latency_ms'],
        'tts_synthesized' => ['session_id', 'turn_id', 'response_text_hash', 'audio_hash', 'audio_duration_ms', 'tts_provider', 'provider', 'model', 'latency_ms'],
        'audio_played' => ['session_id', 'turn_id', 'played_duration_ms', 'latency_ms'],
        'barge_in' => ['session_id', 'turn_id', 'reason', 'interrupted_stage', 'played_duration_ms', 'latency_ms'],
        'runtime_failed' => ['session_id', 'turn_id', 'failure_code', 'error_class', 'error_message_hash', 'latency_ms'],
        'provider_health_degraded' => ['session_id', 'turn_id', 'provider', 'reason', 'latency_ms'],
        'participant_left' => ['session_id', 'reason'],
    ];

    private const FORBIDDEN_KEYS = [
        'access_token',
        'api_key',
        'api_secret',
        'audio',
        'audio_bytes',
        'audio_raw',
        'livekit_token',
        'llm_provider',
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

    public function __construct(
        private readonly AtlasVoiceCallbackPayloadContract $payloads,
        private readonly AtlasVoiceCallbackSequenceContract $sequences,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function contract(): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => 'ready',
            'mode' => 'normalization_and_validation_only',
            'authority' => 'runtime_event_normalizer_no_runtime_execution',
            'event_to_callback' => self::EVENT_TO_CALLBACK,
            'allowed_payload_keys' => self::ALLOWED_PAYLOAD_KEYS,
            'forbidden_keys' => self::FORBIDDEN_KEYS,
            'guardrails' => [
                'runtime_execution_enabled' => false,
                'provider_execution_enabled' => false,
                'raw_audio_persistence_allowed' => false,
                'secret_persistence_allowed' => false,
                'payload_contract_required' => true,
                'sequence_contract_available' => true,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $event
     * @return array<string,mixed>
     */
    public function normalize(array $event): array
    {
        $eventKind = $this->eventKind($event);
        $callback = self::EVENT_TO_CALLBACK[$eventKind] ?? null;

        if ($callback === null) {
            return [
                'schema_version' => self::SCHEMA_VERSION,
                'status' => 'unsupported_event_kind',
                'valid' => false,
                'event_kind' => $eventKind,
                'errors' => ['unsupported_event_kind'],
                'supported_event_kinds' => array_keys(self::EVENT_TO_CALLBACK),
                'contract' => $this->contract(),
            ];
        }

        $forbidden = $this->findForbiddenFields($event);
        $payload = $this->canonicalPayload($callback, $event);
        $payloadResult = $this->payloads->validate($callback, $payload);
        $errors = [];

        foreach ($forbidden as $path) {
            $errors[] = 'forbidden_runtime_field:'.$path;
        }
        if (! ($payloadResult['valid'] ?? false)) {
            foreach ((array) ($payloadResult['errors'] ?? []) as $payloadError) {
                $errors[] = 'payload_'.$payloadError;
            }
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $errors === [] ? 'normalized' : 'invalid_event',
            'valid' => $errors === [],
            'event_kind' => $eventKind,
            'callback' => $callback,
            'callback_event' => [
                'callback' => $callback,
                'payload' => $payload,
            ],
            'errors' => $errors,
            'dropped_fields' => $this->droppedFields($callback, $event),
            'forbidden_fields_found' => $forbidden,
            'payload_validation' => $payloadResult,
            'contract' => $this->contract(),
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $events
     * @return array<string,mixed>
     */
    public function normalizeSequence(array $events): array
    {
        $normalized = [];
        $errors = [];
        $results = [];

        foreach ($events as $index => $event) {
            $result = $this->normalize($event);
            $results[] = [
                'index' => $index,
                'event_kind' => $result['event_kind'] ?? '',
                'callback' => $result['callback'] ?? null,
                'valid' => (bool) ($result['valid'] ?? false),
                'errors' => $result['errors'] ?? [],
            ];

            if (! ($result['valid'] ?? false)) {
                foreach ((array) ($result['errors'] ?? []) as $error) {
                    $errors[] = 'event_'.$index.':'.$error;
                }
            }

            if (isset($result['callback_event']) && is_array($result['callback_event'])) {
                $normalized[] = $result['callback_event'];
            }
        }

        $sequence = $this->sequences->validate($normalized);
        if (! ($sequence['valid'] ?? false)) {
            foreach ((array) ($sequence['errors'] ?? []) as $error) {
                $errors[] = 'sequence_'.$error;
            }
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $errors === [] ? 'normalized_sequence' : 'invalid_sequence',
            'valid' => $errors === [],
            'errors' => $errors,
            'event_count' => count($events),
            'normalized_events' => $normalized,
            'event_results' => $results,
            'sequence_validation' => $sequence,
            'contract' => $this->contract(),
        ];
    }

    /**
     * @param  array<string,mixed>  $event
     */
    private function eventKind(array $event): string
    {
        return trim((string) ($event['event_kind'] ?? $event['callback_kind'] ?? $event['callback'] ?? ''));
    }

    /**
     * @param  array<string,mixed>  $event
     * @return array<string,mixed>
     */
    private function canonicalPayload(string $callback, array $event): array
    {
        $source = isset($event['payload']) && is_array($event['payload'])
            ? $event['payload']
            : $event;

        $payload = [];
        foreach (self::ALLOWED_PAYLOAD_KEYS[$callback] as $key) {
            if (array_key_exists($key, $source) && $source[$key] !== null) {
                $payload[$key] = $source[$key];
            }
        }

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $event
     * @return array<int,string>
     */
    private function droppedFields(string $callback, array $event): array
    {
        $source = isset($event['payload']) && is_array($event['payload'])
            ? $event['payload']
            : $event;
        $allowed = [...self::ALLOWED_PAYLOAD_KEYS[$callback], 'event_kind', 'callback_kind', 'callback', 'payload'];

        return array_values(array_diff(array_keys($source), $allowed));
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<int,string>
     */
    private function findForbiddenFields(array $payload, string $prefix = ''): array
    {
        $found = [];

        foreach ($payload as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;
            if (in_array((string) $key, self::FORBIDDEN_KEYS, true)) {
                $found[] = $path;
            }

            if (is_array($value)) {
                array_push($found, ...$this->findForbiddenFields($value, $path));
            }
        }

        return array_values(array_unique($found));
    }
}
