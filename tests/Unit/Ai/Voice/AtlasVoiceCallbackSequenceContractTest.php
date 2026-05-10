<?php

namespace Tests\Unit\Ai\Voice;

use App\Services\Ai\Voice\AtlasVoiceCallbackSequenceContract;
use Tests\TestCase;

final class AtlasVoiceCallbackSequenceContractTest extends TestCase
{
    public function test_contract_documents_sequence_rules_without_runtime_execution(): void
    {
        $payload = app(AtlasVoiceCallbackSequenceContract::class)->contract();

        $this->assertSame('atlas.voice_realtime.callback_sequence_contract.v1', $payload['schema_version']);
        $this->assertSame('validation_only', $payload['mode']);
        $this->assertSame('callback_sequence_contract_no_runtime_execution', $payload['authority']);
        $this->assertFalse(data_get($payload, 'guardrails.runtime_execution_enabled'));
        $this->assertFalse(data_get($payload, 'guardrails.provider_execution_enabled'));
        $this->assertTrue(data_get($payload, 'guardrails.payload_contract_required_per_event'));
        $this->assertTrue(data_get($payload, 'sequence_rules.participant_joined_required_before_turn_events'));
        $this->assertTrue(data_get($payload, 'sequence_rules.participant_left_terminal'));
    }

    public function test_valid_sequence_fixtures_pass(): void
    {
        $contract = app(AtlasVoiceCallbackSequenceContract::class);

        foreach ($this->fixtures()['valid'] as $fixture) {
            $result = $contract->validate($fixture['events']);

            $this->assertTrue($result['valid'], 'Expected '.$fixture['name'].' to be valid: '.json_encode($result['errors']));
            $this->assertSame('valid', $result['status']);
            $this->assertSame('voice_session_sequence_001', $result['session_id']);
            $this->assertTrue($result['joined']);
            $this->assertTrue($result['left']);
            $this->assertContains('turn_sequence_001', $result['turn_ids']);
        }
    }

    public function test_invalid_sequence_fixtures_fail_with_expected_errors(): void
    {
        $contract = app(AtlasVoiceCallbackSequenceContract::class);

        foreach ($this->fixtures()['invalid'] as $fixture) {
            $result = $contract->validate($fixture['events']);

            $this->assertFalse($result['valid'], 'Expected '.$fixture['name'].' to be invalid');
            $this->assertSame('invalid_sequence', $result['status']);

            foreach ($fixture['expected_errors'] as $expectedError) {
                $this->assertContains($expectedError, $result['errors'], 'Missing '.$expectedError.' for '.$fixture['name']);
            }
        }
    }

    public function test_payload_contract_errors_are_lifted_into_sequence_errors(): void
    {
        $result = app(AtlasVoiceCallbackSequenceContract::class)->validate([
            [
                'callback' => 'participant_joined',
                'payload' => [
                    'session_id' => 'voice_session_sequence_006',
                    'participant_identity' => 'mobile:vitor',
                    'room_name' => 'atlas-voice-sequence',
                ],
            ],
            [
                'callback' => 'tts_synthesized',
                'payload' => [
                    'session_id' => 'voice_session_sequence_006',
                    'turn_id' => 'turn_sequence_006',
                    'response_text' => 'texto cru proibido',
                ],
            ],
        ]);

        $this->assertFalse($result['valid']);
        $this->assertContains('event_1:payload_prohibited_field:response_text', $result['errors']);
        $this->assertContains('event_1:tts_synthesized_requires_transcript_final_for_same_turn', $result['errors']);
        $this->assertSame('invalid_payload', data_get($result, 'events.1.payload_status'));
    }

    public function test_participant_left_is_terminal_even_when_later_payload_is_valid(): void
    {
        $result = app(AtlasVoiceCallbackSequenceContract::class)->validate([
            [
                'callback' => 'participant_joined',
                'payload' => [
                    'session_id' => 'voice_session_sequence_terminal',
                    'participant_identity' => 'mobile:vitor',
                    'room_name' => 'atlas-voice-sequence',
                ],
            ],
            [
                'callback' => 'participant_left',
                'payload' => [
                    'session_id' => 'voice_session_sequence_terminal',
                    'reason' => 'client_disconnected',
                ],
            ],
            [
                'callback' => 'transcript_final',
                'payload' => [
                    'session_id' => 'voice_session_sequence_terminal',
                    'turn_id' => 'turn_after_left',
                    'transcript' => 'depois do encerramento',
                ],
            ],
        ]);

        $this->assertFalse($result['valid']);
        $this->assertSame('invalid_sequence', $result['status']);
        $this->assertContains('event_2:event_after_participant_left', $result['errors']);
        $this->assertSame('valid', data_get($result, 'events.2.payload_status'));
    }

    public function test_empty_sequence_fails_closed(): void
    {
        $result = app(AtlasVoiceCallbackSequenceContract::class)->validate([]);

        $this->assertFalse($result['valid']);
        $this->assertSame('invalid_sequence', $result['status']);
        $this->assertSame(['empty_sequence'], $result['errors']);
    }

    /**
     * @return array<string,mixed>
     */
    private function fixtures(): array
    {
        $contents = file_get_contents(base_path('tests/Fixtures/Ai/voice-callback-sequences.json'));

        $this->assertIsString($contents);

        $fixtures = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('atlas.voice_realtime.callback_sequence_fixtures.v1', $fixtures['schema_version']);

        return $fixtures;
    }
}
