<?php

namespace Tests\Feature\Ai;

use App\Models\AtlasLedgerEvent;
use App\Models\AtlasMobileDevice;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use App\Services\Ai\Mobile\MobilePairingService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class AtlasAiVoiceRealtimeApiTest extends TestCase
{
    private array $headers = ['X-Atlas-Token' => 'test-token-with-enough-length-123'];

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('atlas.token', 'test-token-with-enough-length-123');
        Schema::dropIfExists('mobile_pairing_codes');
        Schema::dropIfExists('atlas_mobile_devices');
        Schema::dropIfExists('atlas_ledger_events');
        (require database_path('migrations/2026_05_05_020000_create_atlas_ledger_events_table.php'))->up();
        $this->createMobileDeviceTable();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('atlas_ledger_events');
        Schema::dropIfExists('mobile_pairing_codes');
        Schema::dropIfExists('atlas_mobile_devices');

        parent::tearDown();
    }

    public function test_voice_health_exposes_mobile_first_scaffold_contract(): void
    {
        $this->getJson('/ai/voice/health', $this->headers)
            ->assertOk()
            ->assertJsonPath('status', 'scaffold_ready')
            ->assertJsonPath('surface_id', 'voice_realtime')
            ->assertJsonPath('mobile_first', true)
            ->assertJsonPath('livekit_agents_sdk', 'planned')
            ->assertJsonPath('swift_native_mac', 'future_edge')
            ->assertJsonPath('contract.runtime_requires_decision_receipt', true)
            ->assertJsonPath('contract.raw_audio_persistence_allowed', false);
    }

    public function test_voice_session_start_records_session_event(): void
    {
        $this->postJson('/ai/voice/session/start', [
            'session_id' => 'voice_session_api',
            'envelope_id' => 'env_voice_api',
            'receipt_id' => 'receipt_voice_api',
            'tenant_id' => 'tenant-voice',
            'operator_id' => 'operator-voice',
            'client_surface' => 'mobile',
        ], $this->headers)
            ->assertOk()
            ->assertJsonPath('status', 'session_started_scaffold')
            ->assertJsonPath('session.session_id', 'voice_session_api')
            ->assertJsonPath('session.surface_id', 'voice_realtime')
            ->assertJsonPath('session.client_surface', 'mobile')
            ->assertJsonPath('eclipse.active', false)
            ->assertJsonPath('evidence_ledger.recorded', true)
            ->assertJsonPath('evidence_ledger.event_type', LedgerEventType::VoiceSessionStarted->value);

        $this->assertDatabaseHas('atlas_ledger_events', [
            'event_type' => LedgerEventType::VoiceSessionStarted->value,
            'envelope_id' => 'env_voice_api',
            'receipt_id' => 'receipt_voice_api',
            'tenant_id' => 'tenant-voice',
            'operator_id' => 'operator-voice',
            'emitter_stage' => 'atlas.voice_realtime',
        ]);
    }

    public function test_voice_runtime_contract_is_machine_readable_for_agents_sdk(): void
    {
        $this->getJson('/ai/voice/runtime/contract?runtime=livekit_agents_sdk', $this->headers)
            ->assertOk()
            ->assertJsonPath('schema_version', 'atlas.voice_realtime.runtime_contract.v1')
            ->assertJsonPath('status', 'ready')
            ->assertJsonPath('surface_id', 'voice_realtime')
            ->assertJsonPath('runtime_id', 'livekit_agents_sdk')
            ->assertJsonPath('kernel_is_decision_authority', true)
            ->assertJsonPath('turn_endpoint', '/ai/voice/turn')
            ->assertJsonPath('mobile_turn_endpoint', '/v1/mobile/ai/voice/turn')
            ->assertJsonPath('required_callbacks.turn_synthesized', '/ai/voice/turn/synthesized')
            ->assertJsonPath('required_callbacks.runtime_failed', '/ai/voice/runtime/failed')
            ->assertJsonPath('mobile_required_callbacks.turn_synthesized', '/v1/mobile/ai/voice/turn/synthesized')
            ->assertJsonPath('mobile_required_callbacks.provider_health_degraded', '/v1/mobile/ai/voice/provider/health-degraded')
            ->assertJsonPath('auth_contract.internal_api.middleware', 'atlas.token')
            ->assertJsonPath('auth_contract.mobile_api.middleware', 'atlas.mobile.bearer')
            ->assertJsonPath('auth_contract.rule', 'runtime_must_call_kernel_endpoint_before_provider_or_tool_execution')
            ->assertJsonPath('callback_order.normal_turn.0', 'turn')
            ->assertJsonPath('persistence_contract.raw_audio', false)
            ->assertJsonPath('persistence_contract.raw_transcript', false)
            ->assertJsonPath('persistence_contract.raw_response_text', false)
            ->assertJsonPath('contract.runtime_requires_decision_receipt', true)
            ->assertJsonPath('contract.livekit_agents_call_kernel_webhook_only', true)
            ->assertJson(fn ($json) => $json
                ->where('mobile_first', true)
                ->has('required_callbacks.turn_played')
                ->has('required_callbacks.turn_interrupted')
                ->has('required_callbacks.provider_health_degraded')
                ->has('mobile_required_callbacks.turn_played')
                ->has('mobile_required_callbacks.turn_interrupted')
                ->where('prohibited_fields.0', 'audio_bytes')
                ->where('slo_stages.0', 'voice.wake_word_detect')
                ->where('slo_stages.1', 'voice.turn_to_first_audio')
                ->etc()
            );
    }

    public function test_voice_mobile_gateway_exposes_runtime_contract_with_mobile_bearer(): void
    {
        $token = $this->mobileDeviceToken();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/v1/mobile/ai/voice/runtime/contract?runtime=livekit_agents_sdk')
            ->assertOk()
            ->assertJsonPath('schema_version', 'atlas.voice_realtime.runtime_contract.v1')
            ->assertJsonPath('surface_id', 'voice_realtime')
            ->assertJsonPath('mobile_first', true)
            ->assertJsonPath('mobile_turn_endpoint', '/v1/mobile/ai/voice/turn')
            ->assertJsonPath('mobile_required_callbacks.turn_played', '/v1/mobile/ai/voice/turn/played')
            ->assertJsonPath('auth_contract.mobile_api.middleware', 'atlas.mobile.bearer')
            ->assertJsonPath('persistence_contract.raw_audio', false);
    }

    public function test_voice_runtime_bootstrap_manifest_prepares_livekit_agents_without_authority_bypass(): void
    {
        $this->getJson('/ai/voice/runtime/bootstrap?runtime=livekit_agents_sdk&base_url=http://atlas.test', $this->headers)
            ->assertOk()
            ->assertJsonPath('schema_version', 'atlas.voice_realtime.runtime_bootstrap.v1')
            ->assertJsonPath('status', 'ready')
            ->assertJsonPath('runtime_family', 'python_ai_data')
            ->assertJsonPath('entrypoint.kind', 'livekit_agents_sdk')
            ->assertJsonPath('entrypoint.module', 'atlas_voice_agent.main')
            ->assertJsonPath('kernel.base_url', 'http://atlas.test')
            ->assertJsonPath('kernel.turn_url', 'http://atlas.test/ai/voice/turn')
            ->assertJsonPath('kernel.mobile_turn_url', 'http://atlas.test/v1/mobile/ai/voice/turn')
            ->assertJsonPath('kernel.callbacks.turn_synthesized', 'http://atlas.test/ai/voice/turn/synthesized')
            ->assertJsonPath('kernel.mobile_callbacks.turn_synthesized', 'http://atlas.test/v1/mobile/ai/voice/turn/synthesized')
            ->assertJsonPath('default_providers.llm', 'atlas_kernel_only')
            ->assertJsonPath('persistence_contract.raw_audio', false)
            ->assertJsonPath('auth_contract.internal_api.middleware', 'atlas.token')
            ->assertJson(fn ($json) => $json
                ->has('contract_hash')
                ->where('required_env.0', 'ATLAS_BASE_URL')
                ->where('required_env.5', 'ATLAS_VOICE_BOOTSTRAP')
                ->where('forbidden_capabilities.0', 'direct_llm_provider_call')
                ->has('required_runtime_behaviors')
                ->etc()
            );
    }

    public function test_voice_turn_records_audio_transcript_decision_and_slo_without_raw_audio(): void
    {
        $response = $this->postJson('/ai/voice/turn', [
            'session_id' => 'voice_session_turn',
            'envelope_id' => 'env_voice_turn',
            'receipt_id' => 'receipt_voice_turn',
            'turn_id' => 'voice_turn_01',
            'audio_hash' => hash('sha256', 'voice-audio'),
            'transcript' => 'corrija o teste quebrado',
            'domain_hint' => 'programming',
            'flow_hint' => 'programming.repair',
            'turn_to_first_audio_ms' => 480,
        ], $this->headers)
            ->assertOk()
            ->assertJsonPath('status', 'turn_accepted_scaffold')
            ->assertJsonPath('turn.requires_decision_receipt', true)
            ->assertJsonPath('turn.provider_execution_enabled', false)
            ->assertJsonPath('turn.raw_audio_persisted', false)
            ->assertJsonPath('turn.operation_envelope.parent_envelope_id', 'env_voice_turn')
            ->assertJsonPath('turn.operation_envelope.schema_version', 'atlas.envelope.v1')
            ->assertJsonPath('turn.decision_receipt.schema_version', 'atlas.decide.v2')
            ->assertJsonPath('turn.decision_receipt.dry_run', true)
            ->assertJsonPath('turn.decision_receipt.domain', 'programming')
            ->assertJsonPath('turn.decision_receipt.flow', 'programming.repair')
            ->assertJsonPath('turn.decision_receipt.provider_selection.selection_mode', 'auto_best_allowed')
            ->assertJsonPath('evidence_ledger.decision_issued.event_type', LedgerEventType::DecisionIssued->value)
            ->assertJsonPath('evidence_ledger.audio_received.event_type', LedgerEventType::VoiceTurnAudioReceived->value)
            ->assertJsonPath('evidence_ledger.transcribed.event_type', LedgerEventType::VoiceTurnTranscribed->value)
            ->assertJsonPath('evidence_ledger.decided.event_type', LedgerEventType::VoiceTurnDecided->value)
            ->assertJsonPath('evidence_ledger.slo.event_type', LedgerEventType::SloObserved->value);

        $envelopeId = (string) $response->json('turn.operation_envelope.envelope_id');

        $this->assertNotSame('', $envelopeId);
        $this->assertNotSame('env_voice_turn', $envelopeId);
        $this->assertDatabaseHas('atlas_ledger_events', [
            'event_type' => LedgerEventType::EnvelopeCreated->value,
            'envelope_id' => $envelopeId,
        ]);
        $this->assertDatabaseHas('atlas_ledger_events', [
            'event_type' => LedgerEventType::DecisionIssued->value,
            'envelope_id' => $envelopeId,
        ]);
        $this->assertDatabaseHas('atlas_ledger_events', [
            'event_type' => LedgerEventType::VoiceTurnTranscribed->value,
            'envelope_id' => $envelopeId,
        ]);

        $transcribed = AtlasLedgerEvent::query()
            ->where('event_type', LedgerEventType::VoiceTurnTranscribed->value)
            ->where('envelope_id', $envelopeId)
            ->firstOrFail();

        $this->assertArrayNotHasKey('transcript', data_get($transcribed->payload, 'voice'));
        $this->assertSame(hash('sha256', 'corrija o teste quebrado'), data_get($transcribed->payload, 'voice.transcript_hash'));
    }

    public function test_voice_turn_is_blocked_by_eclipse_policy(): void
    {
        $this->postJson('/ai/voice/turn', [
            'session_id' => 'voice_session_eclipse',
            'envelope_id' => 'env_voice_eclipse',
            'turn_id' => 'voice_turn_eclipse',
            'eclipse_active' => true,
            'transcript' => 'isso nao deve executar',
        ], $this->headers)
            ->assertOk()
            ->assertJsonPath('status', 'blocked_by_eclipse')
            ->assertJsonPath('eclipse.active', true)
            ->assertJsonPath('eclipse.reasons.0', 'explicit_eclipse_active')
            ->assertJsonPath('evidence_ledger.blocked.event_type', LedgerEventType::VoiceEclipseTriggered->value);

        $this->assertDatabaseHas('atlas_ledger_events', [
            'event_type' => LedgerEventType::VoiceEclipseTriggered->value,
            'envelope_id' => 'env_voice_eclipse',
        ]);
        $this->assertDatabaseMissing('atlas_ledger_events', [
            'event_type' => LedgerEventType::VoiceTurnDecided->value,
            'envelope_id' => 'env_voice_eclipse',
        ]);
    }

    public function test_voice_turn_interruption_is_recorded_without_raw_audio(): void
    {
        $this->postJson('/ai/voice/turn/interrupted', [
            'session_id' => 'voice_session_interrupt',
            'envelope_id' => 'env_voice_interrupt',
            'receipt_id' => 'receipt_voice_interrupt',
            'turn_id' => 'voice_turn_interrupt',
            'reason' => 'operator_started_speaking',
            'interrupted_stage' => 'tts_streaming',
            'latency_ms' => 92,
        ], $this->headers)
            ->assertOk()
            ->assertJsonPath('status', 'turn_interrupted_recorded')
            ->assertJsonPath('turn.turn_id', 'voice_turn_interrupt')
            ->assertJsonPath('turn.interruption_recorded', true)
            ->assertJsonPath('turn.raw_audio_persisted', false)
            ->assertJsonPath('evidence_ledger.interrupted.event_type', LedgerEventType::VoiceTurnInterrupted->value)
            ->assertJsonPath('evidence_ledger.slo.event_type', LedgerEventType::SloObserved->value);

        $this->assertDatabaseHas('atlas_ledger_events', [
            'event_type' => LedgerEventType::VoiceTurnInterrupted->value,
            'envelope_id' => 'env_voice_interrupt',
            'receipt_id' => 'receipt_voice_interrupt',
        ]);
        $this->assertDatabaseHas('atlas_ledger_events', [
            'event_type' => LedgerEventType::SloObserved->value,
            'envelope_id' => 'env_voice_interrupt',
            'receipt_id' => 'receipt_voice_interrupt',
        ]);
    }

    public function test_voice_runtime_callbacks_record_synthesis_playback_failure_and_provider_health(): void
    {
        $base = [
            'session_id' => 'voice_session_runtime',
            'envelope_id' => 'env_voice_runtime',
            'receipt_id' => 'receipt_voice_runtime',
            'turn_id' => 'voice_turn_runtime',
            'runtime' => 'livekit_agents_sdk',
        ];

        $this->postJson('/ai/voice/turn/synthesized', $base + [
            'response_text' => 'resposta falada sensivel',
            'tts_provider' => 'elevenlabs',
            'audio_hash' => hash('sha256', 'tts-audio'),
            'audio_duration_ms' => 1300,
        ], $this->headers)
            ->assertOk()
            ->assertJsonPath('status', 'turn_synthesis_recorded')
            ->assertJsonPath('turn.raw_audio_persisted', false)
            ->assertJsonPath('turn.raw_text_persisted', false)
            ->assertJsonPath('evidence_ledger.event_type', LedgerEventType::VoiceTurnSynthesized->value);

        $this->postJson('/ai/voice/turn/played', $base + [
            'played_duration_ms' => 1250,
        ], $this->headers)
            ->assertOk()
            ->assertJsonPath('status', 'turn_playback_recorded')
            ->assertJsonPath('evidence_ledger.event_type', LedgerEventType::VoiceTurnPlayed->value);

        $this->postJson('/ai/voice/runtime/failed', $base + [
            'failure_code' => 'tts_timeout',
            'latency_ms' => 2400,
        ], $this->headers)
            ->assertOk()
            ->assertJsonPath('status', 'runtime_failure_recorded')
            ->assertJsonPath('evidence_ledger.event_type', LedgerEventType::VoiceRuntimeFailed->value);

        $this->postJson('/ai/voice/provider/health-degraded', $base + [
            'provider' => 'deepgram',
            'reason' => 'latency_p95_breach',
        ], $this->headers)
            ->assertOk()
            ->assertJsonPath('status', 'provider_health_degraded_recorded')
            ->assertJsonPath('evidence_ledger.event_type', LedgerEventType::VoiceProviderHealthDegraded->value);

        foreach ([
            LedgerEventType::VoiceTurnSynthesized,
            LedgerEventType::VoiceTurnPlayed,
            LedgerEventType::VoiceRuntimeFailed,
            LedgerEventType::VoiceProviderHealthDegraded,
        ] as $type) {
            $this->assertDatabaseHas('atlas_ledger_events', [
                'event_type' => $type->value,
                'envelope_id' => 'env_voice_runtime',
                'receipt_id' => 'receipt_voice_runtime',
            ]);
        }

        $synthesized = AtlasLedgerEvent::query()
            ->where('event_type', LedgerEventType::VoiceTurnSynthesized->value)
            ->where('envelope_id', 'env_voice_runtime')
            ->firstOrFail();

        $this->assertArrayNotHasKey('response_text', data_get($synthesized->payload, 'voice'));
        $this->assertSame(hash('sha256', 'resposta falada sensivel'), data_get($synthesized->payload, 'voice.response_text_hash'));
    }

    public function test_voice_turn_rejects_raw_audio_payloads(): void
    {
        $this->postJson('/ai/voice/turn', [
            'session_id' => 'voice_session_raw',
            'raw_audio' => 'do-not-accept',
        ], $this->headers)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['raw_audio']);
    }

    public function test_voice_api_requires_atlas_token(): void
    {
        $this->getJson('/ai/voice/health')->assertUnauthorized();
    }

    private function mobileDeviceToken(): string
    {
        $token = 'atlas_mobile_voice_test_token';

        AtlasMobileDevice::query()->create([
            'user_id' => 'vitor',
            'device_label' => 'Voice Test iPhone',
            'platform' => 'ios',
            'device_token_hash' => app(MobilePairingService::class)->hashSecret($token),
            'notification_permissions' => 'granted',
            'last_seen_at' => now(),
            'paired_at' => now(),
            'metadata' => [],
        ]);

        return $token;
    }

    private function createMobileDeviceTable(): void
    {
        Schema::create('atlas_mobile_devices', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('user_id')->default('vitor')->index();
            $table->string('device_label', 80);
            $table->string('platform', 16);
            $table->string('app_version', 32)->nullable();
            $table->string('os_version', 64)->nullable();
            $table->text('expo_push_token')->nullable();
            $table->string('push_token_hash', 128)->nullable()->index();
            $table->string('device_token_hash', 128)->unique();
            $table->string('notification_permissions', 32)->default('unknown');
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('paired_at')->nullable();
            $table->timestamp('revoked_at')->nullable()->index();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }
}
