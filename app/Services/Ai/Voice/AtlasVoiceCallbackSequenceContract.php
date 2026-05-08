<?php

namespace App\Services\Ai\Voice;

use Illuminate\Support\Arr;

final class AtlasVoiceCallbackSequenceContract
{
    private const SCHEMA_VERSION = 'atlas.voice_realtime.callback_sequence_contract.v1';

    private const TURN_CALLBACKS = [
        'transcript_final',
        'tts_synthesized',
        'audio_played',
        'barge_in',
        'runtime_failed',
        'provider_health_degraded',
    ];

    public function __construct(
        private readonly AtlasVoiceCallbackPayloadContract $payloads,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function contract(): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => 'ready',
            'mode' => 'validation_only',
            'authority' => 'callback_sequence_contract_no_runtime_execution',
            'sequence_rules' => [
                'single_session_per_sequence' => true,
                'participant_joined_required_before_turn_events' => true,
                'participant_left_terminal' => true,
                'tts_requires_transcript_final_for_same_turn' => true,
                'audio_played_requires_tts_synthesized_for_same_turn' => true,
                'barge_in_requires_transcript_final_for_same_turn' => true,
                'provider_health_degraded_requires_transcript_final_for_same_turn' => true,
                'runtime_failed_requires_transcript_final_for_same_turn' => true,
            ],
            'guardrails' => [
                'runtime_execution_enabled' => false,
                'provider_execution_enabled' => false,
                'payload_contract_required_per_event' => true,
                'kernel_decision_required_per_turn' => true,
            ],
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $events
     * @return array<string,mixed>
     */
    public function validate(array $events): array
    {
        $errors = [];
        $eventResults = [];
        $joined = false;
        $left = false;
        $sessionId = null;
        $turnStates = [];

        if ($events === []) {
            $errors[] = 'empty_sequence';
        }

        foreach ($events as $index => $event) {
            $callback = $this->callbackName($event);
            $payload = $this->payload($event);
            $eventErrors = [];

            if ($left) {
                $eventErrors[] = 'event_after_participant_left';
            }

            $payloadResult = $this->payloads->validate($callback, $payload);
            if (! ($payloadResult['valid'] ?? false)) {
                foreach ((array) ($payloadResult['errors'] ?? []) as $payloadError) {
                    $eventErrors[] = 'payload_'.$payloadError;
                }
            }

            $payloadSessionId = Arr::get($payload, 'session_id');
            if (is_string($payloadSessionId) && $payloadSessionId !== '') {
                $sessionId ??= $payloadSessionId;
                if ($sessionId !== $payloadSessionId) {
                    $eventErrors[] = 'session_id_changed';
                }
            }

            if ($callback !== 'participant_joined' && ! $joined) {
                $eventErrors[] = 'participant_joined_required_before_'.$callback;
            }

            if ($callback === 'participant_joined') {
                if ($joined) {
                    $eventErrors[] = 'duplicate_participant_joined';
                }
                $joined = true;
            }

            if (in_array($callback, self::TURN_CALLBACKS, true)) {
                $turnId = Arr::get($payload, 'turn_id');
                if (is_string($turnId) && $turnId !== '') {
                    $turnStates[$turnId] ??= [
                        'transcript_final' => false,
                        'tts_synthesized' => false,
                        'audio_played' => false,
                        'barge_in' => false,
                    ];

                    if ($callback === 'transcript_final') {
                        $turnStates[$turnId]['transcript_final'] = true;
                    }

                    if ($callback === 'tts_synthesized' && ! $turnStates[$turnId]['transcript_final']) {
                        $eventErrors[] = 'tts_synthesized_requires_transcript_final_for_same_turn';
                    }
                    if ($callback === 'audio_played' && ! $turnStates[$turnId]['tts_synthesized']) {
                        $eventErrors[] = 'audio_played_requires_tts_synthesized_for_same_turn';
                    }
                    if ($callback === 'barge_in' && ! $turnStates[$turnId]['transcript_final']) {
                        $eventErrors[] = 'barge_in_requires_transcript_final_for_same_turn';
                    }
                    if (in_array($callback, ['provider_health_degraded', 'runtime_failed'], true) && ! $turnStates[$turnId]['transcript_final']) {
                        $eventErrors[] = $callback.'_requires_transcript_final_for_same_turn';
                    }

                    if ($callback === 'tts_synthesized') {
                        $turnStates[$turnId]['tts_synthesized'] = true;
                    }
                    if ($callback === 'audio_played') {
                        $turnStates[$turnId]['audio_played'] = true;
                    }
                    if ($callback === 'barge_in') {
                        $turnStates[$turnId]['barge_in'] = true;
                    }
                }
            }

            if ($callback === 'participant_left') {
                $left = true;
            }

            foreach ($eventErrors as $eventError) {
                $errors[] = 'event_'.$index.':'.$eventError;
            }

            $eventResults[] = [
                'index' => $index,
                'callback' => $callback,
                'valid' => $eventErrors === [],
                'errors' => $eventErrors,
                'payload_status' => $payloadResult['status'] ?? 'unknown',
            ];
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $errors === [] ? 'valid' : 'invalid_sequence',
            'valid' => $errors === [],
            'errors' => $errors,
            'event_count' => count($events),
            'session_id' => $sessionId,
            'joined' => $joined,
            'left' => $left,
            'turn_ids' => array_keys($turnStates),
            'events' => $eventResults,
            'contract' => $this->contract(),
        ];
    }

    /**
     * @param  array<string,mixed>  $event
     */
    private function callbackName(array $event): string
    {
        return trim((string) ($event['callback'] ?? $event['callback_kind'] ?? ''));
    }

    /**
     * @param  array<string,mixed>  $event
     * @return array<string,mixed>
     */
    private function payload(array $event): array
    {
        $payload = $event['payload'] ?? [];

        return is_array($payload) ? $payload : [];
    }
}
