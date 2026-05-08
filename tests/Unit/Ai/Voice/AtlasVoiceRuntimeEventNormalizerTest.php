<?php

namespace Tests\Unit\Ai\Voice;

use App\Services\Ai\Voice\AtlasVoiceRuntimeEventNormalizer;
use Tests\TestCase;

final class AtlasVoiceRuntimeEventNormalizerTest extends TestCase
{
    public function test_contract_mirrors_runtime_event_bridge_without_execution(): void
    {
        $payload = app(AtlasVoiceRuntimeEventNormalizer::class)->contract();

        $this->assertSame('atlas.voice_realtime.runtime_event_normalizer.v1', $payload['schema_version']);
        $this->assertSame('normalization_and_validation_only', $payload['mode']);
        $this->assertSame('runtime_event_normalizer_no_runtime_execution', $payload['authority']);
        $this->assertSame('participant_joined', data_get($payload, 'event_to_callback.room_connected'));
        $this->assertSame('transcript_final', data_get($payload, 'event_to_callback.transcribed_turn'));
        $this->assertSame('tts_synthesized', data_get($payload, 'event_to_callback.synthesized'));
        $this->assertSame('participant_left', data_get($payload, 'event_to_callback.room_disconnected'));
        $this->assertFalse(data_get($payload, 'guardrails.runtime_execution_enabled'));
        $this->assertFalse(data_get($payload, 'guardrails.provider_execution_enabled'));
        $this->assertContains('raw_audio', $payload['forbidden_keys']);
        $this->assertContains('provider_api_key', $payload['forbidden_keys']);
    }

    public function test_wrapped_callback_event_is_normalized_and_drops_non_contract_fields(): void
    {
        $result = app(AtlasVoiceRuntimeEventNormalizer::class)->normalize($this->fixtures()['valid_payload_wrapped_event']);

        $this->assertTrue($result['valid']);
        $this->assertSame('normalized', $result['status']);
        $this->assertSame('participant_joined', $result['callback']);
        $this->assertSame('voice_session_runtime_002', data_get($result, 'callback_event.payload.session_id'));
        $this->assertContains('ignored_nested_runtime_object', $result['dropped_fields']);
        $this->assertArrayNotHasKey('ignored_nested_runtime_object', data_get($result, 'callback_event.payload'));
    }

    public function test_runtime_event_sequence_normalizes_then_passes_sequence_contract(): void
    {
        $result = app(AtlasVoiceRuntimeEventNormalizer::class)->normalizeSequence($this->fixtures()['valid_sequence']);

        $this->assertTrue($result['valid'], json_encode($result['errors']));
        $this->assertSame('normalized_sequence', $result['status']);
        $this->assertCount(5, $result['normalized_events']);
        $this->assertSame('participant_joined', data_get($result, 'normalized_events.0.callback'));
        $this->assertSame('transcript_final', data_get($result, 'normalized_events.1.callback'));
        $this->assertSame('tts_synthesized', data_get($result, 'normalized_events.2.callback'));
        $this->assertSame('audio_played', data_get($result, 'normalized_events.3.callback'));
        $this->assertSame('participant_left', data_get($result, 'normalized_events.4.callback'));
        $this->assertSame('valid', data_get($result, 'sequence_validation.status'));
    }

    public function test_invalid_runtime_events_fail_closed_with_expected_errors(): void
    {
        $normalizer = app(AtlasVoiceRuntimeEventNormalizer::class);

        foreach ($this->fixtures()['invalid_events'] as $fixture) {
            $result = $normalizer->normalize($fixture['event']);

            $this->assertFalse($result['valid'], 'Expected '.$fixture['name'].' to be invalid');
            foreach ($fixture['expected_errors'] as $expectedError) {
                $this->assertContains($expectedError, $result['errors'], 'Missing '.$expectedError.' for '.$fixture['name']);
            }
        }
    }

    public function test_sequence_errors_are_preserved_after_normalization(): void
    {
        $events = $this->fixtures()['valid_sequence'];
        $events[2] = $events[1];
        $events[1] = [
            'event_kind' => 'synthesized',
            'session_id' => 'voice_session_runtime_001',
            'turn_id' => 'turn_runtime_001',
            'response_text_hash' => 'sha256:2dc22a58640459efd97ef7d5fee1abfb32c8189dd1f5e4cfe4d58ce3fc498c33',
        ];

        $result = app(AtlasVoiceRuntimeEventNormalizer::class)->normalizeSequence($events);

        $this->assertFalse($result['valid']);
        $this->assertSame('invalid_sequence', $result['status']);
        $this->assertContains('sequence_event_1:tts_synthesized_requires_transcript_final_for_same_turn', $result['errors']);
    }

    /**
     * @return array<string,mixed>
     */
    private function fixtures(): array
    {
        $contents = file_get_contents(base_path('tests/Fixtures/Ai/voice-runtime-events.json'));

        $this->assertIsString($contents);

        $fixtures = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('atlas.voice_realtime.runtime_event_fixtures.v1', $fixtures['schema_version']);

        return $fixtures;
    }
}
