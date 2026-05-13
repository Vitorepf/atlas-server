<?php

namespace App\Services\Ai\Voice;

use Illuminate\Support\Arr;

final class AtlasVoiceCallbackPayloadContract
{
    private const SCHEMA_VERSION = 'atlas.voice_realtime.callback_payload_contract.v1';

    private const CALLBACK_SCHEMAS = [
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

    /**
     * @return array<string,mixed>
     */
    public function schemas(): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => 'ready',
            'mode' => 'validation_only',
            'authority' => 'callback_payload_contract_no_runtime_execution',
            'callback_schemas' => self::CALLBACK_SCHEMAS,
            'guardrails' => $this->guardrails(),
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    public function validate(string $callback, array $payload): array
    {
        $callback = trim($callback);
        $schema = self::CALLBACK_SCHEMAS[$callback] ?? null;

        if ($schema === null) {
            return [
                'schema_version' => self::SCHEMA_VERSION,
                'status' => 'invalid_callback',
                'callback' => $callback,
                'valid' => false,
                'errors' => ['unknown_callback'],
                'allowed_callbacks' => array_keys(self::CALLBACK_SCHEMAS),
                'guardrails' => $this->guardrails(),
            ];
        }

        $required = (array) $schema['required'];
        $optional = (array) $schema['optional'];
        $prohibited = array_values(array_unique([
            ...$this->globalProhibitedFields(),
            ...(array) $schema['prohibited'],
        ]));

        $missing = array_values(array_filter(
            $required,
            fn (string $key): bool => ! Arr::has($payload, $key) || $payload[$key] === null || $payload[$key] === '',
        ));
        $unknown = array_values(array_diff(array_keys($payload), [...$required, ...$optional]));
        $prohibitedFound = $this->findProhibitedFields($payload, $prohibited);
        $errors = [];

        foreach ($missing as $key) {
            $errors[] = "missing_required:{$key}";
        }
        foreach ($unknown as $key) {
            $errors[] = "unknown_field:{$key}";
        }
        foreach ($prohibitedFound as $path) {
            $errors[] = "prohibited_field:{$path}";
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $errors === [] ? 'valid' : 'invalid_payload',
            'callback' => $callback,
            'valid' => $errors === [],
            'errors' => $errors,
            'required' => $required,
            'optional' => $optional,
            'prohibited' => $prohibited,
            'missing_required' => $missing,
            'unknown_fields' => $unknown,
            'prohibited_fields_found' => $prohibitedFound,
            'guardrails' => $this->guardrails(),
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @param  array<int,string>  $prohibited
     * @return array<int,string>
     */
    private function findProhibitedFields(array $payload, array $prohibited, string $prefix = ''): array
    {
        $found = [];

        foreach ($payload as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;
            if (in_array((string) $key, $prohibited, true)) {
                $found[] = $path;
            }

            if (is_array($value)) {
                array_push($found, ...$this->findProhibitedFields($value, $prohibited, $path));
            }
        }

        return array_values(array_unique($found));
    }

    /**
     * @return array<int,string>
     */
    private function globalProhibitedFields(): array
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
     * @return array<string,mixed>
     */
    private function guardrails(): array
    {
        return [
            'runtime_execution_enabled' => false,
            'provider_execution_enabled' => false,
            'raw_audio_persistence_allowed' => false,
            'raw_transcript_persistence_allowed' => false,
            'raw_response_text_persistence_allowed' => false,
            'secret_persistence_allowed' => false,
            'kernel_decision_required_per_turn' => true,
        ];
    }
}
