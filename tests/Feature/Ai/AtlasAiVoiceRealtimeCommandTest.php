<?php

namespace Tests\Feature\Ai;

use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class AtlasAiVoiceRealtimeCommandTest extends TestCase
{
    public function test_command_exposes_voice_runtime_contract_as_json(): void
    {
        $exit = Artisan::call('atlas:ai:voice', [
            'action' => 'contract',
            '--runtime' => 'livekit_agents_sdk',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.voice_realtime.runtime_contract.v1', $payload['schema_version']);
        $this->assertSame('ready', $payload['status']);
        $this->assertSame('voice_realtime', $payload['surface_id']);
        $this->assertSame('livekit_agents_sdk', $payload['runtime_id']);
        $this->assertTrue($payload['kernel_is_decision_authority']);
        $this->assertSame('/ai/voice/turn', $payload['turn_endpoint']);
        $this->assertSame('/v1/mobile/ai/voice/turn', $payload['mobile_turn_endpoint']);
        $this->assertSame('/ai/voice/turn/synthesized', data_get($payload, 'required_callbacks.turn_synthesized'));
        $this->assertSame('/ai/voice/provider/health-degraded', data_get($payload, 'required_callbacks.provider_health_degraded'));
        $this->assertSame('/v1/mobile/ai/voice/turn/synthesized', data_get($payload, 'mobile_required_callbacks.turn_synthesized'));
        $this->assertSame('/v1/mobile/ai/voice/provider/health-degraded', data_get($payload, 'mobile_required_callbacks.provider_health_degraded'));
        $this->assertSame('atlas.token', data_get($payload, 'auth_contract.internal_api.middleware'));
        $this->assertSame('atlas.mobile.bearer', data_get($payload, 'auth_contract.mobile_api.middleware'));
        $this->assertFalse(data_get($payload, 'persistence_contract.raw_audio'));
        $this->assertFalse(data_get($payload, 'persistence_contract.raw_transcript'));
        $this->assertFalse(data_get($payload, 'persistence_contract.raw_response_text'));
        $this->assertContains('audio_bytes', $payload['prohibited_fields']);
        $this->assertContains('voice.wake_word_detect', $payload['slo_stages']);
        $this->assertContains('voice.turn_to_first_audio', $payload['slo_stages']);
        $this->assertContains('voice.interruption_stop_audio', $payload['slo_stages']);
        $this->assertTrue(data_get($payload, 'contract.runtime_requires_decision_receipt'));
    }

    public function test_command_human_output_lists_required_callbacks(): void
    {
        $exit = Artisan::call('atlas:ai:voice', [
            'action' => 'contract',
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Atlas AI Voice Realtime', $output);
        $this->assertStringContainsString('turn_synthesized', $output);
        $this->assertStringContainsString('/ai/voice/turn/synthesized', $output);
        $this->assertStringContainsString('/v1/mobile/ai/voice/turn/synthesized', $output);
        $this->assertStringContainsString('provider_health_degraded', $output);
        $this->assertStringContainsString('atlas.mobile.bearer', $output);
    }

    public function test_command_exposes_runtime_bootstrap_manifest_as_json(): void
    {
        $exit = Artisan::call('atlas:ai:voice', [
            'action' => 'bootstrap',
            '--runtime' => 'livekit_agents_sdk',
            '--base-url' => 'http://atlas.test',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.voice_realtime.runtime_bootstrap.v1', $payload['schema_version']);
        $this->assertSame('ready', $payload['status']);
        $this->assertSame('livekit_agents_sdk', $payload['runtime_id']);
        $this->assertSame('atlas_voice_agent.main', data_get($payload, 'entrypoint.module'));
        $this->assertSame('http://atlas.test/ai/voice/turn', data_get($payload, 'kernel.turn_url'));
        $this->assertSame('http://atlas.test/v1/mobile/ai/voice/turn', data_get($payload, 'kernel.mobile_turn_url'));
        $this->assertContains('ATLAS_TOKEN', $payload['required_env']);
        $this->assertContains('ATLAS_VOICE_BOOTSTRAP', $payload['required_env']);
        $this->assertContains('direct_tool_execution', $payload['forbidden_capabilities']);
        $this->assertSame('atlas_kernel_only', data_get($payload, 'default_providers.llm'));
        $this->assertIsString($payload['contract_hash']);
    }

    public function test_generated_bootstrap_manifest_is_accepted_by_python_runtime_entrypoint(): void
    {
        $exit = Artisan::call('atlas:ai:voice', [
            'action' => 'bootstrap',
            '--runtime' => 'livekit_agents_sdk',
            '--base-url' => 'http://atlas.test',
            '--json' => true,
        ]);
        $this->assertSame(0, $exit);

        $bootstrapPath = tempnam(sys_get_temp_dir(), 'atlas-voice-bootstrap-');
        $this->assertIsString($bootstrapPath);
        file_put_contents($bootstrapPath, Artisan::output());

        try {
            $process = new Process([
                'python3',
                '-m',
                'atlas_voice_agent.main',
                '--bootstrap',
                $bootstrapPath,
                '--check',
            ], base_path(), [
                'PYTHONPATH' => base_path('runtimes/python/voice_realtime'),
            ]);
            $process->mustRun();
            $payload = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

            $this->assertSame('ready', $payload['status']);
            $this->assertSame('atlas.voice_realtime.runtime_check.v1', $payload['schema_version']);
            $this->assertSame('http://atlas.test/ai/voice/turn', $payload['turn_url']);
            $this->assertTrue($payload['kernel_only']);
            $this->assertFalse($payload['settings_loaded']);
            $this->assertFalse($payload['boundary_created']);
        } finally {
            @unlink($bootstrapPath);
        }
    }

    public function test_command_human_output_lists_bootstrap_entrypoint(): void
    {
        $exit = Artisan::call('atlas:ai:voice', [
            'action' => 'bootstrap',
            '--base-url' => 'http://atlas.test',
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('runtime_bootstrap', $output);
        $this->assertStringContainsString('atlas_voice_agent.main', $output);
        $this->assertStringContainsString('http://atlas.test', $output);
    }

    public function test_command_rejects_unknown_action(): void
    {
        $exit = Artisan::call('atlas:ai:voice', [
            'action' => 'random',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, $exit);
        $this->assertSame('invalid_action', $payload['status']);
        $this->assertSame(['contract', 'bootstrap', 'health'], $payload['allowed_actions']);
    }
}
