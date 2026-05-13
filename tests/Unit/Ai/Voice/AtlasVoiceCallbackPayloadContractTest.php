<?php

namespace Tests\Unit\Ai\Voice;

use App\Services\Ai\Voice\AtlasVoiceCallbackPayloadContract;
use Tests\TestCase;

final class AtlasVoiceCallbackPayloadContractTest extends TestCase
{
    public function test_schemas_expose_voice_callback_guardrails_without_runtime_execution(): void
    {
        $payload = app(AtlasVoiceCallbackPayloadContract::class)->schemas();

        $this->assertSame('atlas.voice_realtime.callback_payload_contract.v1', $payload['schema_version']);
        $this->assertSame('validation_only', $payload['mode']);
        $this->assertSame('callback_payload_contract_no_runtime_execution', $payload['authority']);
        $this->assertFalse(data_get($payload, 'guardrails.runtime_execution_enabled'));
        $this->assertFalse(data_get($payload, 'guardrails.provider_execution_enabled'));
        $this->assertFalse(data_get($payload, 'guardrails.raw_audio_persistence_allowed'));
        $this->assertFalse(data_get($payload, 'guardrails.raw_response_text_persistence_allowed'));
        $this->assertTrue(data_get($payload, 'guardrails.kernel_decision_required_per_turn'));
        $this->assertSame(['session_id', 'turn_id', 'transcript'], data_get($payload, 'callback_schemas.transcript_final.required'));
        $this->assertSame(['session_id', 'turn_id', 'error_message_hash'], data_get($payload, 'callback_schemas.runtime_failed.required'));
        $this->assertContains('response_text', data_get($payload, 'callback_schemas.tts_synthesized.prohibited'));
        $this->assertContains('error_message', data_get($payload, 'callback_schemas.runtime_failed.prohibited'));
        $this->assertContains('provider_api_key', data_get($payload, 'callback_schemas.provider_health_degraded.prohibited'));
    }

    public function test_valid_fixture_payloads_pass_contract_validation(): void
    {
        $contract = app(AtlasVoiceCallbackPayloadContract::class);

        foreach ($this->fixtures()['valid'] as $fixture) {
            $result = $contract->validate($fixture['callback'], $fixture['payload']);

            $this->assertTrue($result['valid'], 'Expected '.$fixture['callback'].' fixture to be valid: '.json_encode($result['errors']));
            $this->assertSame('valid', $result['status']);
            $this->assertSame([], $result['errors']);
            $this->assertSame([], $result['missing_required']);
            $this->assertSame([], $result['unknown_fields']);
            $this->assertSame([], $result['prohibited_fields_found']);
        }
    }

    public function test_invalid_fixture_payloads_fail_closed_with_expected_errors(): void
    {
        $contract = app(AtlasVoiceCallbackPayloadContract::class);

        foreach ($this->fixtures()['invalid'] as $fixture) {
            $result = $contract->validate($fixture['callback'], $fixture['payload']);

            $this->assertFalse($result['valid'], 'Expected '.$fixture['name'].' fixture to be invalid');
            $this->assertSame('invalid_payload', $result['status']);

            foreach ($fixture['expected_errors'] as $expectedError) {
                $this->assertContains($expectedError, $result['errors'], 'Missing '.$expectedError.' for '.$fixture['name']);
            }
        }
    }

    public function test_unknown_callback_is_rejected_with_allowed_callback_list(): void
    {
        $result = app(AtlasVoiceCallbackPayloadContract::class)->validate('direct_provider_callback', [
            'session_id' => 'voice_session_01HYSAFE000000000000000001',
        ]);

        $this->assertFalse($result['valid']);
        $this->assertSame('invalid_callback', $result['status']);
        $this->assertSame(['unknown_callback'], $result['errors']);
        $this->assertContains('transcript_final', $result['allowed_callbacks']);
        $this->assertContains('provider_health_degraded', $result['allowed_callbacks']);
    }

    public function test_unknown_top_level_fields_are_rejected_even_without_sensitive_data(): void
    {
        $result = app(AtlasVoiceCallbackPayloadContract::class)->validate('audio_played', [
            'session_id' => 'voice_session_01HYSAFE000000000000000001',
            'turn_id' => 'turn_01HYSAFE000000000000000001',
            'debug_payload' => ['safe' => true],
        ]);

        $this->assertFalse($result['valid']);
        $this->assertSame('invalid_payload', $result['status']);
        $this->assertContains('unknown_field:debug_payload', $result['errors']);
        $this->assertSame(['debug_payload'], $result['unknown_fields']);
    }

    public function test_nested_sensitive_fields_are_detected_before_any_future_persistence(): void
    {
        $result = app(AtlasVoiceCallbackPayloadContract::class)->validate('runtime_failed', [
            'session_id' => 'voice_session_01HYSAFE000000000000000001',
            'turn_id' => 'turn_01HYSAFE000000000000000001',
            'failure_code' => 'bad_runtime_payload',
            'error_class' => 'fatal',
            'latency_ms' => 8,
            'diagnostics' => [
                'nested' => [
                    'raw_audio' => 'base64-redacted',
                    'provider_api_key' => 'sk-redacted',
                ],
            ],
        ]);

        $this->assertFalse($result['valid']);
        $this->assertContains('missing_required:error_message_hash', $result['errors']);
        $this->assertContains('unknown_field:diagnostics', $result['errors']);
        $this->assertContains('prohibited_field:diagnostics.nested.raw_audio', $result['errors']);
        $this->assertContains('prohibited_field:diagnostics.nested.provider_api_key', $result['errors']);
    }

    public function test_sensitive_fields_nested_inside_allowed_optional_fields_are_rejected(): void
    {
        $result = app(AtlasVoiceCallbackPayloadContract::class)->validate('transcript_final', [
            'session_id' => 'voice_session_01HYSAFE000000000000000001',
            'turn_id' => 'turn_01HYSAFE000000000000000001',
            'transcript' => 'agenda retorno com o cliente',
            'domain_hint' => [
                'name' => 'sales',
                'provider_api_key' => 'sk-redacted',
            ],
            'flow_hint' => [
                'token' => 'livekit-redacted',
            ],
        ]);

        $this->assertFalse($result['valid']);
        $this->assertSame('invalid_payload', $result['status']);
        $this->assertSame([], $result['unknown_fields']);
        $this->assertContains('prohibited_field:domain_hint.provider_api_key', $result['errors']);
        $this->assertContains('prohibited_field:flow_hint.token', $result['errors']);
    }

    /**
     * @return array<string,mixed>
     */
    private function fixtures(): array
    {
        $contents = file_get_contents(base_path('tests/Fixtures/Ai/voice-callback-payloads.json'));

        $this->assertIsString($contents);

        $fixtures = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('atlas.voice_realtime.callback_payload_fixtures.v1', $fixtures['schema_version']);

        return $fixtures;
    }
}
