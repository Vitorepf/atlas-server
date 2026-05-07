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
        $this->assertSame(['mobile', 'mac_edge'], data_get($payload, 'allowlists.client_surfaces'));
        $this->assertSame(['mobile_push_to_talk', 'livekit_webrtc'], data_get($payload, 'allowlists.transports'));
        $this->assertSame(['livekit_agents_sdk'], data_get($payload, 'allowlists.runtimes'));
        $this->assertContains('p3_audio', data_get($payload, 'allowlists.privacy_classes'));
        $this->assertTrue($payload['kernel_is_decision_authority']);
        $this->assertSame('atlas.voice.session_lease.v1', data_get($payload, 'session_lease.schema_version'));
        $this->assertSame('mobile_push_to_talk', data_get($payload, 'session_lease.default_mode'));
        $this->assertSame('not_issued_scaffold', data_get($payload, 'session_lease.token_status'));
        $this->assertSame('/ai/voice/session/start', $payload['session_start_endpoint']);
        $this->assertSame('/ai/voice/session/end', $payload['session_end_endpoint']);
        $this->assertSame('/ai/voice/readiness', $payload['readiness_endpoint']);
        $this->assertSame('/ai/voice/rivals', $payload['rivals_endpoint']);
        $this->assertSame('/v1/mobile/ai/voice/readiness', $payload['mobile_readiness_endpoint']);
        $this->assertSame('/v1/mobile/ai/voice/rivals', $payload['mobile_rivals_endpoint']);
        $this->assertSame('/ai/voice/wake-word', $payload['wake_word_endpoint']);
        $this->assertSame('/v1/mobile/ai/voice/wake-word', $payload['mobile_wake_word_endpoint']);
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
        $this->assertSame(['session_id', 'turn_id', 'transcript'], data_get($payload, 'callback_payload_schemas.transcript_final.required'));
        $this->assertContains('raw_audio', data_get($payload, 'callback_payload_schemas.tts_synthesized.prohibited'));
        $this->assertContains('provider_api_key', data_get($payload, 'callback_payload_schemas.provider_health_degraded.prohibited'));
        $this->assertContains('audio_bytes', $payload['prohibited_fields']);
        $this->assertContains('voice.wake_word_detect', $payload['slo_stages']);
        $this->assertContains('voice.turn_to_first_audio', $payload['slo_stages']);
        $this->assertContains('voice.interruption_stop_audio', $payload['slo_stages']);
        $this->assertTrue(data_get($payload, 'contract.runtime_requires_decision_receipt'));
    }

    public function test_command_rejects_unknown_runtime_before_generating_contract(): void
    {
        $exit = Artisan::call('atlas:ai:voice', [
            'action' => 'contract',
            '--runtime' => 'rogue_runtime',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, $exit);
        $this->assertSame('invalid_runtime', $payload['status']);
        $this->assertSame('rogue_runtime', $payload['received_runtime']);
        $this->assertSame(['livekit_agents_sdk'], $payload['allowed_runtimes']);
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
        $this->assertSame('http://atlas.test/ai/voice/session/start', data_get($payload, 'kernel.session_start_url'));
        $this->assertSame('http://atlas.test/ai/voice/session/end', data_get($payload, 'kernel.session_end_url'));
        $this->assertSame('http://atlas.test/ai/voice/readiness', data_get($payload, 'kernel.readiness_url'));
        $this->assertSame('http://atlas.test/ai/voice/rivals', data_get($payload, 'kernel.rivals_url'));
        $this->assertSame('http://atlas.test/v1/mobile/ai/voice/readiness', data_get($payload, 'kernel.mobile_readiness_url'));
        $this->assertSame('http://atlas.test/v1/mobile/ai/voice/rivals', data_get($payload, 'kernel.mobile_rivals_url'));
        $this->assertSame('http://atlas.test/ai/voice/wake-word', data_get($payload, 'kernel.wake_word_url'));
        $this->assertSame('http://atlas.test/v1/mobile/ai/voice/wake-word', data_get($payload, 'kernel.mobile_wake_word_url'));
        $this->assertSame('http://atlas.test/ai/voice/turn', data_get($payload, 'kernel.turn_url'));
        $this->assertSame('http://atlas.test/v1/mobile/ai/voice/turn', data_get($payload, 'kernel.mobile_turn_url'));
        $this->assertContains('ATLAS_TOKEN', $payload['required_env']);
        $this->assertContains('ATLAS_VOICE_BOOTSTRAP', $payload['required_env']);
        $this->assertContains('direct_tool_execution', $payload['forbidden_capabilities']);
        $this->assertSame('atlas_kernel_only', data_get($payload, 'default_providers.llm'));
        $this->assertSame('atlas.voice.session_lease.v1', data_get($payload, 'session_lease.schema_version'));
        $this->assertSame('atlas-voice', data_get($payload, 'session_lease.room_prefix'));
        $this->assertSame(['livekit_agents_sdk'], data_get($payload, 'allowlists.runtimes'));
        $this->assertSame(['mobile', 'mac_edge'], data_get($payload, 'allowlists.client_surfaces'));
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
            $this->assertSame('http://atlas.test/ai/voice/session/start', $payload['session_start_url']);
            $this->assertSame('http://atlas.test/ai/voice/session/end', $payload['session_end_url']);
            $this->assertSame('http://atlas.test/ai/voice/readiness', $payload['readiness_url']);
            $this->assertSame('http://atlas.test/ai/voice/rivals', $payload['rivals_url']);
            $this->assertSame('http://atlas.test/ai/voice/wake-word', $payload['wake_word_url']);
            $this->assertSame('http://atlas.test/ai/voice/turn', $payload['turn_url']);
            $this->assertSame('atlas-voice', $payload['room_prefix']);
            $this->assertNull($payload['livekit_url']);
            $this->assertTrue($payload['kernel_only']);
            $this->assertFalse($payload['settings_loaded']);
            $this->assertFalse($payload['boundary_created']);
        } finally {
            @unlink($bootstrapPath);
        }
    }

    public function test_generated_bootstrap_manifest_with_livekit_token_issuer_is_accepted_by_python_runtime_entrypoint(): void
    {
        config()->set('atlas.voice.livekit.token_issuer_enabled', true);
        config()->set('atlas.voice.livekit.url', 'http://livekit.test');
        config()->set('atlas.voice.livekit.api_key', 'livekit-test-key');
        config()->set('atlas.voice.livekit.api_secret', 'livekit-test-secret');

        $exit = Artisan::call('atlas:ai:voice', [
            'action' => 'bootstrap',
            '--runtime' => 'livekit_agents_sdk',
            '--base-url' => 'http://atlas.test',
            '--json' => true,
        ]);
        $this->assertSame(0, $exit);
        $bootstrapJson = Artisan::output();
        $bootstrap = json_decode($bootstrapJson, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('issued_when_session_starts', data_get($bootstrap, 'session_lease.token_status'));
        $this->assertArrayNotHasKey('access_token', $bootstrap['session_lease']);

        $bootstrapPath = tempnam(sys_get_temp_dir(), 'atlas-voice-bootstrap-');
        $this->assertIsString($bootstrapPath);
        file_put_contents($bootstrapPath, $bootstrapJson);

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
            $this->assertSame('http://livekit.test', $payload['livekit_url']);
            $this->assertTrue($payload['kernel_only']);
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

    public function test_command_exposes_runtime_dependency_plan_as_json(): void
    {
        $exit = Artisan::call('atlas:ai:voice', [
            'action' => 'dependencies',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.voice_realtime.runtime_dependency_plan.v1', $payload['schema_version']);
        $this->assertSame('ready', $payload['status']);
        $this->assertSame('runtimes/python/voice_realtime/runtime-dependencies.json', $payload['manifest_path']);
        $this->assertSame([], $payload['core_dependencies']);
        $this->assertSame('python3 -m pip install livekit-agents', $payload['install_command']);
        $this->assertTrue(data_get($payload, 'guardrails.kernel_decides'));
        $this->assertFalse(data_get($payload, 'guardrails.raw_audio_persistence_allowed'));
    }

    public function test_command_human_output_lists_runtime_dependency_plan(): void
    {
        $exit = Artisan::call('atlas:ai:voice', [
            'action' => 'dependencies',
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('runtime_dependency_plan', $output);
        $this->assertStringContainsString('runtime-dependencies.json', $output);
        $this->assertStringContainsString('atlas ai voice sdk-check --json', $output);
    }

    public function test_command_runs_runtime_preflight_as_json(): void
    {
        $exit = Artisan::call('atlas:ai:voice', [
            'action' => 'preflight',
            '--json' => true,
        ]);
        $output = Artisan::output();
        $payload = json_decode($output, true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.voice_realtime.preflight_command.v1', $payload['schema_version']);
        $this->assertContains($payload['status'], ['ready', 'blocked']);
        $this->assertSame('atlas.voice_realtime.runtime_preflight.v1', data_get($payload, 'preflight.schema_version'));
        $this->assertTrue(data_get($payload, 'preflight.kernel_only'));
        $this->assertTrue(data_get($payload, 'preflight.settings_loaded'));
        $this->assertTrue(data_get($payload, 'preflight.contract_loaded'));
        $this->assertFalse(data_get($payload, 'preflight.guardrails.direct_provider_call_allowed'));
        $this->assertStringContainsString('--preflight', $payload['command']);
        $this->assertStringNotContainsString('preflight-secret', $output);
        $this->assertStringNotContainsString('preflight-token', $output);
    }

    public function test_command_human_output_lists_runtime_preflight(): void
    {
        $exit = Artisan::call('atlas:ai:voice', [
            'action' => 'preflight',
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('preflight_command', $output);
        $this->assertStringContainsString('Runtime preflight', $output);
    }

    public function test_command_exposes_activation_contract_as_json(): void
    {
        $exit = Artisan::call('atlas:ai:voice', [
            'action' => 'activation-contract',
            '--json' => true,
        ]);
        $output = Artisan::output();
        $payload = json_decode($output, true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.voice_realtime.activation_command.v1', $payload['schema_version']);
        $this->assertContains($payload['status'], ['blocked', 'ready_to_start_worker']);
        $this->assertSame('atlas.voice_realtime.activation_contract.v1', data_get($payload, 'activation.schema_version'));
        $this->assertTrue(data_get($payload, 'activation.kernel_only'));
        $this->assertTrue(data_get($payload, 'activation.mobile_first'));
        $this->assertContains('sdk_callback_direct_to_provider', data_get($payload, 'activation.forbidden_shortcuts'));
        $this->assertContains('atlas:ai:voice preflight --require-sdk --json', data_get($payload, 'activation.required_sequence'));
        $this->assertStringContainsString('--activation-contract', $payload['command']);
        $this->assertStringNotContainsString('activation-secret', $output);
        $this->assertStringNotContainsString('activation-token', $output);
    }

    public function test_command_human_output_lists_activation_contract(): void
    {
        $exit = Artisan::call('atlas:ai:voice', [
            'action' => 'activation-contract',
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('activation_command', $output);
        $this->assertStringContainsString('Activation contract', $output);
        $this->assertStringContainsString('Worker allowed', $output);
    }

    public function test_command_exposes_scripted_worker_example_as_json(): void
    {
        $exit = Artisan::call('atlas:ai:voice', [
            'action' => 'scripted-example',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.voice_realtime.scripted_worker_example.v1', $payload['schema_version']);
        $this->assertSame('ready', $payload['status']);
        $this->assertSame('runtimes/python/voice_realtime/scripted-events.example.json', $payload['example_path']);
        $this->assertSame('AtlasLiveKitWorker', data_get($payload, 'entrypoint.worker_adapter'));
        $this->assertSame('LiveKitSdkAdapter', data_get($payload, 'entrypoint.sdk_adapter'));
        $this->assertSame('start_session', $payload['required_first_event_kind']);
        $this->assertContains('wake_word_detected', $payload['allowed_event_kinds']);
        $this->assertContains('transcribed_turn', $payload['allowed_event_kinds']);
        $this->assertContains('access_token', $payload['forbidden_payloads']);
        $this->assertStringContainsString('--scripted-events runtimes/python/voice_realtime/scripted-events.example.json', $payload['command']);
        $this->assertTrue(data_get($payload, 'contract.runtime_requires_decision_receipt'));
    }

    public function test_command_human_output_lists_scripted_worker_example(): void
    {
        $exit = Artisan::call('atlas:ai:voice', [
            'action' => 'scripted-example',
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('scripted_worker_example', $output);
        $this->assertStringContainsString('runtimes/python/voice_realtime/scripted-events.example.json', $output);
        $this->assertStringContainsString('AtlasLiveKitWorker', $output);
        $this->assertStringContainsString('LiveKitSdkAdapter', $output);
    }

    public function test_command_runs_scripted_worker_smoke_as_json(): void
    {
        $exit = Artisan::call('atlas:ai:voice', [
            'action' => 'scripted-smoke',
            '--json' => true,
        ]);
        $output = Artisan::output();
        $payload = json_decode($output, true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.voice_realtime.scripted_smoke.v1', $payload['schema_version']);
        $this->assertSame('passed', $payload['status']);
        $this->assertTrue($payload['mock_kernel']);
        $this->assertSame(0, $payload['exit_code']);
        $this->assertSame('scripted_worker_completed', data_get($payload, 'scripted_worker.status'));
        $this->assertTrue(data_get($payload, 'scripted_worker.mock_kernel'));
        $this->assertSame(6, data_get($payload, 'scripted_worker.mock_call_count'));
        $this->assertSame(6, data_get($payload, 'scripted_worker.result_count'));
        $this->assertSame(0, data_get($payload, 'scripted_worker.active_session_count'));
        $this->assertStringContainsString('--mock-kernel --scripted-events', $payload['command']);
        $this->assertStringNotContainsString('"access_token"', $output);
        $this->assertStringNotContainsString('"livekit_token"', $output);
        $this->assertStringNotContainsString('"api_secret"', $output);
    }

    public function test_command_human_output_lists_scripted_worker_smoke(): void
    {
        $exit = Artisan::call('atlas:ai:voice', [
            'action' => 'scripted-smoke',
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('scripted_smoke', $output);
        $this->assertStringContainsString('Mock Kernel', $output);
        $this->assertStringContainsString('runtimes/python/voice_realtime/scripted-events.example.json', $output);
    }

    public function test_command_runs_callback_smoke_as_json(): void
    {
        $exit = Artisan::call('atlas:ai:voice', [
            'action' => 'callback-smoke',
            '--json' => true,
        ]);
        $output = Artisan::output();
        $payload = json_decode($output, true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.voice_realtime.callback_smoke.v1', $payload['schema_version']);
        $this->assertSame('passed', $payload['status']);
        $this->assertTrue($payload['mock_kernel']);
        $this->assertSame('callback_routed', data_get($payload, 'callback.status'));
        $this->assertSame(1, data_get($payload, 'callback.mock_call_count'));
        $this->assertSame(1, data_get($payload, 'callback.active_session_count'));
        $this->assertSame('session_started', data_get($payload, 'callback.result.event_kind'));
        $this->assertStringContainsString('--mock-kernel --callback-event', $payload['command']);
        $this->assertStringNotContainsString('"access_token"', $output);
        $this->assertStringNotContainsString('"livekit_token"', $output);
        $this->assertStringNotContainsString('"api_secret"', $output);
    }

    public function test_command_human_output_lists_callback_smoke(): void
    {
        $exit = Artisan::call('atlas:ai:voice', [
            'action' => 'callback-smoke',
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('callback_smoke', $output);
        $this->assertStringContainsString('Callback status', $output);
        $this->assertStringContainsString('runtimes/python/voice_realtime/callback-event.example.json', $output);
    }

    public function test_command_runs_callback_sequence_smoke_as_json(): void
    {
        $exit = Artisan::call('atlas:ai:voice', [
            'action' => 'callback-sequence-smoke',
            '--json' => true,
        ]);
        $output = Artisan::output();
        $payload = json_decode($output, true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.voice_realtime.callback_sequence_smoke.v1', $payload['schema_version']);
        $this->assertSame('passed', $payload['status']);
        $this->assertTrue($payload['mock_kernel']);
        $this->assertSame('callback_sequence_routed', data_get($payload, 'callback_sequence.status'));
        $this->assertSame(5, data_get($payload, 'callback_sequence.mock_call_count'));
        $this->assertSame(5, data_get($payload, 'callback_sequence.result_count'));
        $this->assertSame(0, data_get($payload, 'callback_sequence.active_session_count'));
        $this->assertStringContainsString('--mock-kernel --callback-events', $payload['command']);
        $this->assertStringNotContainsString('"access_token"', $output);
        $this->assertStringNotContainsString('"livekit_token"', $output);
        $this->assertStringNotContainsString('"api_secret"', $output);
    }

    public function test_command_human_output_lists_callback_sequence_smoke(): void
    {
        $exit = Artisan::call('atlas:ai:voice', [
            'action' => 'callback-sequence-smoke',
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('callback_sequence_smoke', $output);
        $this->assertStringContainsString('Sequence status', $output);
        $this->assertStringContainsString('runtimes/python/voice_realtime/callback-events.example.json', $output);
    }

    public function test_command_exposes_callback_loop_check_as_json(): void
    {
        $exit = Artisan::call('atlas:ai:voice', [
            'action' => 'callback-loop-check',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.voice_realtime.callback_loop_contract.v1', $payload['schema_version']);
        $this->assertSame('ready', $payload['status']);
        $this->assertTrue($payload['translation_layer_ready']);
        $this->assertFalse($payload['production_sdk_loop_wired']);
        $this->assertFalse($payload['worker_start_callback_loop_wired']);
        $this->assertSame('wire_real_livekit_agents_sdk_loop', $payload['next_action']);
        $this->assertContains('participant_joined', $payload['required_callbacks']);
        $this->assertFalse(data_get($payload, 'guardrails.direct_provider_call_allowed'));
    }

    public function test_command_human_output_lists_callback_loop_check(): void
    {
        $exit = Artisan::call('atlas:ai:voice', [
            'action' => 'callback-loop-check',
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('callback_loop_contract', $output);
        $this->assertStringContainsString('Translation layer', $output);
        $this->assertStringContainsString('Production SDK loop', $output);
    }

    public function test_command_exposes_livekit_sdk_check_as_json(): void
    {
        $exit = Artisan::call('atlas:ai:voice', [
            'action' => 'sdk-check',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.voice_realtime.sdk_check.v1', $payload['schema_version']);
        $this->assertContains($payload['status'], ['ready', 'missing_optional_dependency']);
        $this->assertSame('voice_realtime', $payload['surface_id']);
        $this->assertSame('livekit_agents_sdk', $payload['runtime_id']);
        $this->assertTrue($payload['kernel_only']);
        $this->assertArrayHasKey('livekit', $payload['packages']);
        $this->assertFalse(data_get($payload, 'contract.raw_audio_persistence_allowed'));
    }

    public function test_command_human_output_lists_livekit_sdk_check(): void
    {
        $exit = Artisan::call('atlas:ai:voice', [
            'action' => 'sdk-check',
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('sdk_check', $output);
        $this->assertStringContainsString('LiveKit Agents', $output);
    }

    public function test_command_exposes_livekit_worker_plan_as_json(): void
    {
        $exit = Artisan::call('atlas:ai:voice', [
            'action' => 'worker-plan',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.voice_realtime.worker_plan.v1', $payload['schema_version']);
        $this->assertContains($payload['status'], ['ready_to_wire_callbacks', 'blocked_missing_sdk']);
        $this->assertSame('voice_realtime', $payload['surface_id']);
        $this->assertSame('livekit_agents_sdk', $payload['runtime_id']);
        $this->assertTrue($payload['kernel_only']);
        $this->assertTrue($payload['mobile_first']);
        $this->assertFalse(data_get($payload, 'activation.can_start_long_running_worker'));
        $this->assertTrue(data_get($payload, 'activation.requires_callback_loop'));
        $this->assertFalse(data_get($payload, 'callback_loop_wired'));
        $this->assertSame('AtlasLiveKitWorker', data_get($payload, 'entrypoint.worker_adapter'));
        $this->assertSame('LiveKitSdkAdapter', data_get($payload, 'entrypoint.sdk_adapter'));
        $this->assertTrue(data_get($payload, 'guardrails.kernel_decides'));
        $this->assertFalse(data_get($payload, 'guardrails.direct_provider_call_allowed'));
        $this->assertFalse(data_get($payload, 'guardrails.raw_audio_persistence_allowed'));
        $this->assertArrayHasKey('sdk_status', $payload);
    }

    public function test_command_human_output_lists_livekit_worker_plan(): void
    {
        $exit = Artisan::call('atlas:ai:voice', [
            'action' => 'worker-plan',
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('worker_plan', $output);
        $this->assertStringContainsString('AtlasLiveKitWorker', $output);
        $this->assertStringContainsString('LiveKitSdkAdapter', $output);
    }

    public function test_command_exposes_production_loop_plan_as_json(): void
    {
        $exit = Artisan::call('atlas:ai:voice', [
            'action' => 'production-loop-plan',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.voice_realtime.production_loop_plan.v1', $payload['schema_version']);
        $this->assertSame('voice_realtime', $payload['surface_id']);
        $this->assertSame('livekit_agents_sdk', $payload['runtime_id']);
        $this->assertTrue($payload['kernel_only']);
        $this->assertTrue($payload['mobile_first']);
        $this->assertFalse($payload['production_sdk_loop_wired']);
        $this->assertFalse($payload['worker_start_callback_loop_wired']);
        $this->assertFalse(data_get($payload, 'guardrails.worker_start_allowed_by_this_plan'));
        $this->assertSame('atlas.voice_realtime.sdk_event_bridge.v1', data_get($payload, 'sdk_event_bridge_contract.schema_version'));
        $this->assertSame('atlas.voice_realtime.sdk_wiring_contract.v1', data_get($payload, 'sdk_wiring_contract.schema_version'));
        $this->assertTrue(data_get($payload, 'sdk_wiring_contract.complete_callback_coverage'));
        $this->assertContains('access_token', data_get($payload, 'sdk_wiring_contract.forbidden_keys'));
        $this->assertContains('route_all_sdk_callbacks_through_LiveKitCallbackRouter', $payload['implementation_sequence']);
        $this->assertContains('normalize_raw_sdk_objects_through_LiveKitSdkEventBridge', $payload['implementation_sequence']);
        $this->assertContains('transcript_final', $payload['required_loop_hooks']);
    }

    public function test_command_human_output_lists_production_loop_plan(): void
    {
        $exit = Artisan::call('atlas:ai:voice', [
            'action' => 'production-loop-plan',
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('production_loop_plan', $output);
        $this->assertStringContainsString('Production SDK loop', $output);
        $this->assertStringContainsString('Worker start gate', $output);
        $this->assertStringContainsString('SDK wiring', $output);
    }

    public function test_command_exposes_production_loop_smoke_as_json(): void
    {
        $exit = Artisan::call('atlas:ai:voice', [
            'action' => 'production-loop-smoke',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.voice_realtime.production_loop_smoke.v1', $payload['schema_version']);
        $this->assertSame('production_loop_smoke_completed', $payload['status']);
        $this->assertTrue($payload['kernel_only']);
        $this->assertTrue($payload['mobile_first']);
        $this->assertTrue($payload['mock_kernel']);
        $this->assertFalse($payload['daemon_started']);
        $this->assertFalse($payload['sdk_imported']);
        $this->assertSame(5, $payload['event_count']);
        $this->assertSame(5, $payload['result_count']);
        $this->assertSame(0, $payload['active_session_count']);
        $this->assertSame('atlas.voice_realtime.sdk_event_bridge.v1', data_get($payload, 'bridge_contract.schema_version'));
        $this->assertFalse(data_get($payload, 'guardrails.direct_provider_call_allowed'));
        $this->assertFalse(data_get($payload, 'guardrails.raw_audio_persistence_allowed'));
        $this->assertStringNotContainsString('header.payload.signature', Artisan::output());
    }

    public function test_command_human_output_lists_production_loop_smoke(): void
    {
        $exit = Artisan::call('atlas:ai:voice', [
            'action' => 'production-loop-smoke',
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('production_loop_smoke', $output);
        $this->assertStringContainsString('Production loop smoke', $output);
        $this->assertStringContainsString('Daemon started', $output);
        $this->assertStringContainsString('SDK imported', $output);
    }

    public function test_command_exposes_livekit_worker_start_check_as_json(): void
    {
        $exit = Artisan::call('atlas:ai:voice', [
            'action' => 'worker-start-check',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.voice_realtime.worker_start.v1', $payload['schema_version']);
        $this->assertContains($payload['status'], [
            'blocked_missing_sdk',
            'blocked_missing_runtime_settings',
            'blocked_by_activation_gate',
            'blocked_by_activation_contract',
            'blocked_unwired_sdk_callbacks',
            'blocked_unwired_production_loop',
            'blocked_unimplemented_start',
        ]);
        $this->assertFalse($payload['started']);
        $this->assertFalse($payload['callback_loop_wired']);
        $this->assertFalse($payload['production_sdk_loop_wired']);
        $this->assertTrue($payload['kernel_only']);
        $this->assertFalse(data_get($payload, 'guardrails.direct_provider_call_allowed'));
        $this->assertFalse(data_get($payload, 'guardrails.raw_audio_persistence_allowed'));
        $this->assertSame('atlas.voice_realtime.worker_plan.v1', data_get($payload, 'worker_plan.schema_version'));
        $this->assertSame('atlas.voice_realtime.activation_contract.v1', data_get($payload, 'activation_contract.schema_version'));
        $this->assertSame('atlas.voice_realtime.production_loop_plan.v1', data_get($payload, 'production_loop_plan.schema_version'));
        $this->assertSame('atlas.voice_realtime.sdk_wiring_contract.v1', data_get($payload, 'sdk_wiring_contract.schema_version'));
        $this->assertSame(data_get($payload, 'activation_contract.next_action'), data_get($payload, 'activation_next_action'));
        $this->assertTrue(data_get($payload, 'activation_contract.gates.settings_loaded'));
        $this->assertTrue(data_get($payload, 'activation_contract.gates.boundary_created'));
        $this->assertFalse(data_get($payload, 'activation_contract.gates.callback_loop_wired'));
        $this->assertStringContainsString('--env-file <generated> --start-worker', $payload['command']);
        $this->assertStringNotContainsString('worker-start-secret', Artisan::output());
        $this->assertStringNotContainsString('worker-start-token', Artisan::output());
    }

    public function test_command_human_output_lists_livekit_worker_start_check(): void
    {
        $exit = Artisan::call('atlas:ai:voice', [
            'action' => 'worker-start-check',
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('worker_start', $output);
        $this->assertStringContainsString('Started', $output);
        $this->assertStringContainsString('Plan status', $output);
        $this->assertStringContainsString('Production loop status', $output);
        $this->assertStringContainsString('Activation status', $output);
        $this->assertStringContainsString('Activation next action', $output);
    }

    public function test_command_exposes_runtime_certification_as_json(): void
    {
        $exit = Artisan::call('atlas:ai:voice', [
            'action' => 'runtime-certify',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.voice_realtime.runtime_certification.v1', $payload['schema_version']);
        $this->assertSame('certified_scaffold', $payload['status']);
        $this->assertTrue($payload['kernel_only']);
        $this->assertTrue($payload['mobile_first']);
        $this->assertFalse($payload['daemon_started']);
        $this->assertFalse($payload['sdk_required_for_certification']);
        $this->assertSame(4, data_get($payload, 'summary.gate_count'));
        $this->assertSame(4, data_get($payload, 'summary.passed_gates'));
        $this->assertSame(0, data_get($payload, 'summary.failed_gates'));
        $this->assertSame([], data_get($payload, 'summary.failed_keys'));
        $this->assertTrue(data_get($payload, 'gates.preflight_ready.passed'));
        $this->assertTrue(data_get($payload, 'gates.callback_sequence_passed.passed'));
        $this->assertTrue(data_get($payload, 'gates.production_loop_smoke_passed.passed'));
        $this->assertTrue(data_get($payload, 'gates.worker_start_blocked_safely.passed'));
        $this->assertSame(0, data_get($payload, 'gates.production_loop_smoke_passed.active_session_count'));
        $this->assertFalse(data_get($payload, 'gates.production_loop_smoke_passed.daemon_started'));
        $this->assertSame('wire_real_livekit_agents_sdk_loop_when_optional_dependency_is_ready', $payload['next_action']);
        $this->assertArrayNotHasKey('results', data_get($payload, 'artifacts.production_loop_smoke'));
        $this->assertArrayNotHasKey('worker_plan', data_get($payload, 'artifacts.worker_start_check'));
        $this->assertStringNotContainsString('preflight-secret', Artisan::output());
        $this->assertStringNotContainsString('worker-start-secret', Artisan::output());
    }

    public function test_command_human_output_lists_runtime_certification(): void
    {
        $exit = Artisan::call('atlas:ai:voice', [
            'action' => 'runtime-certify',
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('runtime_certification', $output);
        $this->assertStringContainsString('Certification', $output);
        $this->assertStringContainsString('Passed gates', $output);
        $this->assertStringContainsString('Daemon start blocked safely', $output);
        $this->assertStringContainsString('Next action', $output);
    }

    public function test_command_exposes_readiness_as_json(): void
    {
        $exit = Artisan::call('atlas:ai:voice', [
            'action' => 'readiness',
            '--hours' => 48,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.voice.readiness.v1', $payload['schema_version']);
        $this->assertSame(48, $payload['hours']);
        $this->assertContains($payload['status'], ['ledger_unavailable', 'attention', 'ready']);
    }

    public function test_command_exposes_rivals_voice_report_as_json(): void
    {
        $exit = Artisan::call('atlas:ai:voice', [
            'action' => 'rivals',
            '--hours' => 48,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.voice.rivals.v1', $payload['schema_version']);
        $this->assertSame(48, $payload['hours']);
        $this->assertSame('certified_scaffold', data_get($payload, 'runtime_certification.status'));
        $this->assertSame(0, data_get($payload, 'runtime_certification.summary.failed_gates'));
        $this->assertContains($payload['status'], ['ledger_unavailable', 'not_ready', 'ready']);
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
        $this->assertSame(['contract', 'bootstrap', 'dependencies', 'preflight', 'activation-contract', 'scripted-example', 'scripted-smoke', 'callback-smoke', 'callback-sequence-smoke', 'callback-loop-check', 'sdk-check', 'worker-plan', 'production-loop-plan', 'production-loop-smoke', 'worker-start-check', 'runtime-certify', 'health', 'readiness', 'rivals'], $payload['allowed_actions']);
    }
}
