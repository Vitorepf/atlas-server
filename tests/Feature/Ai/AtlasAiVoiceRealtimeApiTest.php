<?php

namespace Tests\Feature\Ai;

use App\Models\AtlasLedgerEvent;
use App\Models\AtlasMobileDevice;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use App\Services\Ai\Mobile\MobilePairingService;
use App\Services\Ai\Voice\AtlasVoiceRealtimeService;
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
            ->assertJsonPath('activation_governance.schema_version', 'atlas.voice_realtime.activation_governance.v1')
            ->assertJsonPath('activation_governance.first_product_surface', 'mobile')
            ->assertJsonPath('activation_governance.livekit_agents_direct_provider_allowed', false)
            ->assertJsonPath('activation_governance.always_on_listening_allowed_now', false)
            ->assertJsonPath('activation_governance.mac_edge_first_product_allowed', false)
            ->assertJsonPath('runtime_invocation_contract.schema_version', 'atlas.runtime_invocation_contract.v1')
            ->assertJsonPath('runtime_invocation_contract.kernel_first', true)
            ->assertJsonPath('runtime_invocation_contract.selected_runtime_family', 'python_ai_data')
            ->assertJsonPath('runtime_invocation_contract.runtime_id', 'livekit_agents_sdk')
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
            'participant_identity' => 'mobile:vitor',
        ], $this->headers)
            ->assertOk()
            ->assertJsonPath('status', 'session_started_scaffold')
            ->assertJsonPath('session.session_id', 'voice_session_api')
            ->assertJsonPath('session.surface_id', 'voice_realtime')
            ->assertJsonPath('session.client_surface', 'mobile')
            ->assertJsonPath('session_lease.schema_version', 'atlas.voice.session_lease.v1')
            ->assertJsonPath('session_lease.mode', 'mobile_push_to_talk')
            ->assertJsonPath('session_lease.room_name', 'atlas-voice-voice-session-api')
            ->assertJsonPath('session_lease.participant_identity', 'mobile:vitor')
            ->assertJsonPath('session_lease.token_status', 'not_issued_scaffold')
            ->assertJsonPath('session_lease.kernel_decision_required_per_turn', true)
            ->assertJsonPath('session_lease.raw_audio_persistence_allowed', false)
            ->assertJsonPath('session_lease.activation_governance.first_product_surface', 'mobile')
            ->assertJsonPath('session_lease.activation_governance.runtime_daemon_start_allowed_now', false)
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

        $event = AtlasLedgerEvent::query()
            ->where('event_type', LedgerEventType::VoiceSessionStarted->value)
            ->where('envelope_id', 'env_voice_api')
            ->firstOrFail();

        $this->assertSame('atlas-voice-voice-session-api', data_get($event->payload, 'voice.session_lease.room_name'));
        $this->assertSame('not_issued_scaffold', data_get($event->payload, 'voice.session_lease.token_status'));
        $this->assertArrayNotHasKey('token', data_get($event->payload, 'voice.session_lease'));
    }

    public function test_voice_session_start_can_issue_livekit_token_without_persisting_it_to_ledger(): void
    {
        config()->set('atlas.voice.livekit.token_issuer_enabled', true);
        config()->set('atlas.voice.livekit.url', 'http://livekit.test');
        config()->set('atlas.voice.livekit.api_key', 'livekit-test-key');
        config()->set('atlas.voice.livekit.api_secret', 'livekit-test-secret');
        config()->set('atlas.voice.livekit.token_ttl_seconds', 600);

        $response = $this->postJson('/ai/voice/session/start', [
            'session_id' => 'voice_session_livekit',
            'envelope_id' => 'env_voice_livekit',
            'receipt_id' => 'receipt_voice_livekit',
            'tenant_id' => 'tenant-voice',
            'operator_id' => 'operator-voice',
            'client_surface' => 'mobile',
            'participant_identity' => 'mobile:vitor',
        ], $this->headers)
            ->assertOk()
            ->assertJsonPath('session_lease.token_status', 'issued')
            ->assertJsonPath('session_lease.token_issuer', 'atlas_voice_livekit_token_issuer')
            ->assertJsonPath('session_lease.ttl_seconds', 600)
            ->assertJsonPath('session_lease.livekit_url', 'http://livekit.test')
            ->assertJsonPath('session_lease.grant.room_join', true)
            ->assertJsonPath('session_lease.grant.room', 'atlas-voice-voice-session-livekit');

        $token = (string) $response->json('session_lease.access_token');
        $this->assertNotSame('', $token);
        $this->assertCount(3, explode('.', $token));

        $claims = $this->decodeJwtClaims($token);
        $this->assertSame('livekit-test-key', $claims['iss']);
        $this->assertSame('mobile:vitor', $claims['sub']);
        $this->assertSame('atlas-voice-voice-session-livekit', data_get($claims, 'video.room'));
        $this->assertTrue(data_get($claims, 'video.roomJoin'));

        $event = AtlasLedgerEvent::query()
            ->where('event_type', LedgerEventType::VoiceSessionStarted->value)
            ->where('envelope_id', 'env_voice_livekit')
            ->firstOrFail();

        $lease = data_get($event->payload, 'voice.session_lease');
        $this->assertSame('issued', data_get($lease, 'token_status'));
        $this->assertSame('atlas_voice_livekit_token_issuer', data_get($lease, 'token_issuer'));
        $this->assertArrayNotHasKey('access_token', $lease);
        $this->assertArrayNotHasKey('token', $lease);
        $this->assertArrayNotHasKey('livekit_token', $lease);
    }

    public function test_voice_session_start_does_not_issue_livekit_token_without_livekit_url(): void
    {
        config()->set('atlas.voice.livekit.token_issuer_enabled', true);
        config()->set('atlas.voice.livekit.url', '');
        config()->set('atlas.voice.livekit.api_key', 'livekit-test-key');
        config()->set('atlas.voice.livekit.api_secret', 'livekit-test-secret');

        $this->postJson('/ai/voice/session/start', [
            'session_id' => 'voice_session_missing_livekit_url',
            'envelope_id' => 'env_voice_missing_livekit_url',
            'receipt_id' => 'receipt_voice_missing_livekit_url',
            'client_surface' => 'mobile',
            'participant_identity' => 'mobile:vitor',
        ], $this->headers)
            ->assertOk()
            ->assertJsonPath('session_lease.livekit_url', null)
            ->assertJsonPath('session_lease.token_status', 'not_issued_missing_config')
            ->assertJsonPath('session_lease.token_reason', 'livekit_url_missing')
            ->assertJsonPath('session_lease.grant.room_join', false)
            ->assertJsonMissingPath('session_lease.access_token');

        $event = AtlasLedgerEvent::query()
            ->where('event_type', LedgerEventType::VoiceSessionStarted->value)
            ->where('envelope_id', 'env_voice_missing_livekit_url')
            ->firstOrFail();

        $lease = data_get($event->payload, 'voice.session_lease');
        $this->assertSame('not_issued_missing_config', data_get($lease, 'token_status'));
        $this->assertSame('livekit_url_missing', data_get($lease, 'token_reason'));
        $this->assertArrayNotHasKey('access_token', $lease);
    }

    public function test_voice_session_start_scopes_requested_livekit_room_to_atlas_voice_prefix(): void
    {
        config()->set('atlas.voice.livekit.token_issuer_enabled', true);
        config()->set('atlas.voice.livekit.url', 'http://livekit.test');
        config()->set('atlas.voice.livekit.api_key', 'livekit-test-key');
        config()->set('atlas.voice.livekit.api_secret', 'livekit-test-secret');

        $response = $this->postJson('/ai/voice/session/start', [
            'session_id' => 'voice_session_external_room',
            'envelope_id' => 'env_voice_external_room',
            'receipt_id' => 'receipt_voice_external_room',
            'client_surface' => 'mobile',
            'participant_identity' => 'mobile:vitor',
            'room_name' => '../../prod-room',
        ], $this->headers)
            ->assertOk()
            ->assertJsonPath('session_lease.room_name', 'atlas-voice-prod-room')
            ->assertJsonPath('session_lease.grant.room', 'atlas-voice-prod-room');

        $claims = $this->decodeJwtClaims((string) $response->json('session_lease.access_token'));
        $this->assertSame('atlas-voice-prod-room', data_get($claims, 'video.room'));

        $event = AtlasLedgerEvent::query()
            ->where('event_type', LedgerEventType::VoiceSessionStarted->value)
            ->where('envelope_id', 'env_voice_external_room')
            ->firstOrFail();

        $this->assertSame('atlas-voice-prod-room', data_get($event->payload, 'voice.session_lease.room_name'));
        $this->assertArrayNotHasKey('access_token', data_get($event->payload, 'voice.session_lease'));
    }

    public function test_voice_session_start_scopes_requested_livekit_participant_to_client_surface(): void
    {
        config()->set('atlas.voice.livekit.token_issuer_enabled', true);
        config()->set('atlas.voice.livekit.url', 'http://livekit.test');
        config()->set('atlas.voice.livekit.api_key', 'livekit-test-key');
        config()->set('atlas.voice.livekit.api_secret', 'livekit-test-secret');

        $response = $this->postJson('/ai/voice/session/start', [
            'session_id' => 'voice_session_external_participant',
            'envelope_id' => 'env_voice_external_participant',
            'receipt_id' => 'receipt_voice_external_participant',
            'client_surface' => 'mobile',
            'participant_identity' => 'admin:../../root',
        ], $this->headers)
            ->assertOk()
            ->assertJsonPath('session_lease.participant_identity', 'mobile:adminroot');

        $claims = $this->decodeJwtClaims((string) $response->json('session_lease.access_token'));
        $this->assertSame('mobile:adminroot', $claims['sub']);

        $event = AtlasLedgerEvent::query()
            ->where('event_type', LedgerEventType::VoiceSessionStarted->value)
            ->where('envelope_id', 'env_voice_external_participant')
            ->firstOrFail();

        $this->assertSame('mobile:adminroot', data_get($event->payload, 'voice.session_lease.participant_identity'));
        $this->assertArrayNotHasKey('access_token', data_get($event->payload, 'voice.session_lease'));
    }

    public function test_voice_session_start_blocks_livekit_token_when_eclipse_is_active(): void
    {
        config()->set('atlas.voice.livekit.token_issuer_enabled', true);
        config()->set('atlas.voice.livekit.url', 'http://livekit.test');
        config()->set('atlas.voice.livekit.api_key', 'livekit-test-key');
        config()->set('atlas.voice.livekit.api_secret', 'livekit-test-secret');

        $this->postJson('/ai/voice/session/start', [
            'session_id' => 'voice_session_eclipse_token',
            'envelope_id' => 'env_voice_eclipse_token',
            'receipt_id' => 'receipt_voice_eclipse_token',
            'tenant_id' => 'tenant-voice',
            'operator_id' => 'operator-voice',
            'client_surface' => 'mobile',
            'participant_identity' => 'mobile:vitor',
            'privacy_class' => 'p4_secret',
        ], $this->headers)
            ->assertOk()
            ->assertJsonPath('eclipse.active', true)
            ->assertJsonPath('session_lease.token_status', 'blocked_by_eclipse')
            ->assertJsonPath('session_lease.token_issuer', 'atlas_voice_eclipse_guard')
            ->assertJsonPath('session_lease.token_reason', 'voice_session_token_blocked_by_active_eclipse')
            ->assertJsonPath('session_lease.grant.room_join', false)
            ->assertJsonMissingPath('session_lease.access_token');

        $event = AtlasLedgerEvent::query()
            ->where('event_type', LedgerEventType::VoiceSessionStarted->value)
            ->where('envelope_id', 'env_voice_eclipse_token')
            ->firstOrFail();

        $lease = data_get($event->payload, 'voice.session_lease');
        $this->assertSame('blocked_by_eclipse', data_get($lease, 'token_status'));
        $this->assertFalse(data_get($lease, 'grant.room_join'));
        $this->assertArrayNotHasKey('access_token', $lease);
        $this->assertArrayNotHasKey('token', $lease);
        $this->assertArrayNotHasKey('livekit_token', $lease);
    }

    public function test_voice_session_end_records_session_end_event(): void
    {
        $this->postJson('/ai/voice/session/end', [
            'session_id' => 'voice_session_end',
            'envelope_id' => 'env_voice_end',
            'receipt_id' => 'receipt_voice_end',
            'tenant_id' => 'tenant-voice',
            'operator_id' => 'operator-voice',
            'reason' => 'operator_finished',
        ], $this->headers)
            ->assertOk()
            ->assertJsonPath('status', 'session_ended_scaffold')
            ->assertJsonPath('session.session_id', 'voice_session_end')
            ->assertJsonPath('evidence_ledger.recorded', true)
            ->assertJsonPath('evidence_ledger.event_type', LedgerEventType::VoiceSessionEnded->value);

        $this->assertDatabaseHas('atlas_ledger_events', [
            'event_type' => LedgerEventType::VoiceSessionEnded->value,
            'envelope_id' => 'env_voice_end',
            'receipt_id' => 'receipt_voice_end',
            'tenant_id' => 'tenant-voice',
            'operator_id' => 'operator-voice',
            'emitter_stage' => 'atlas.voice_realtime',
        ]);
    }

    public function test_voice_eclipse_endpoint_reports_policy_reasons_without_recording_evidence(): void
    {
        $this->getJson('/ai/voice/eclipse/active?privacy_class=p4_secret&privacy[private_meeting]=1', $this->headers)
            ->assertOk()
            ->assertJsonPath('schema_version', 'atlas.voice_realtime.scaffold.v1')
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('eclipse.schema_version', 'atlas.voice.eclipse.v1')
            ->assertJsonPath('eclipse.active', true)
            ->assertJsonPath('eclipse.privacy_class', 'p4_secret')
            ->assertJsonPath('eclipse.reasons.0', 'private_meeting')
            ->assertJsonPath('eclipse.reasons.1', 'secret_audio_requires_explicit_consent')
            ->assertJsonPath('eclipse.requires_wake_word_local', true)
            ->assertJsonPath('eclipse.raw_audio_persistence_allowed', false);

        $this->assertDatabaseCount('atlas_ledger_events', 0);
    }

    public function test_voice_wake_word_records_local_detection_and_slo_without_audio(): void
    {
        $this->postJson('/ai/voice/wake-word', [
            'session_id' => 'voice_session_wake',
            'envelope_id' => 'env_voice_wake',
            'receipt_id' => 'receipt_voice_wake',
            'tenant_id' => 'tenant-voice',
            'operator_id' => 'operator-voice',
            'wake_word_engine' => 'swift_local_edge',
            'confidence' => 0.93,
            'latency_ms' => 42,
        ], $this->headers)
            ->assertOk()
            ->assertJsonPath('status', 'wake_word_detected_recorded')
            ->assertJsonPath('wake_word.local_only', true)
            ->assertJsonPath('wake_word.raw_audio_persisted', false)
            ->assertJsonPath('wake_word.requires_explicit_turn_after_detection', true)
            ->assertJsonPath('evidence_ledger.wake_word_detected.event_type', LedgerEventType::VoiceWakeWordDetected->value)
            ->assertJsonPath('evidence_ledger.slo.event_type', LedgerEventType::SloObserved->value);

        $this->assertDatabaseHas('atlas_ledger_events', [
            'event_type' => LedgerEventType::VoiceWakeWordDetected->value,
            'envelope_id' => 'env_voice_wake',
            'receipt_id' => 'receipt_voice_wake',
            'tenant_id' => 'tenant-voice',
            'operator_id' => 'operator-voice',
            'emitter_stage' => 'atlas.voice_realtime',
        ]);

        $event = AtlasLedgerEvent::query()
            ->where('event_type', LedgerEventType::VoiceWakeWordDetected->value)
            ->where('envelope_id', 'env_voice_wake')
            ->firstOrFail();

        $this->assertSame('swift_local_edge', data_get($event->payload, 'voice.wake_word_engine'));
        $this->assertTrue(data_get($event->payload, 'voice.local_only'));
        $this->assertFalse(data_get($event->payload, 'voice.raw_audio_persisted'));
        $this->assertArrayNotHasKey('audio', data_get($event->payload, 'voice'));
        $this->assertArrayNotHasKey('raw_audio', data_get($event->payload, 'voice'));
    }

    public function test_voice_runtime_contract_is_machine_readable_for_agents_sdk(): void
    {
        $this->getJson('/ai/voice/runtime/contract?runtime=livekit_agents_sdk', $this->headers)
            ->assertOk()
            ->assertJsonPath('schema_version', 'atlas.voice_realtime.runtime_contract.v1')
            ->assertJsonPath('status', 'ready')
            ->assertJsonPath('surface_id', 'voice_realtime')
            ->assertJsonPath('runtime_id', 'livekit_agents_sdk')
            ->assertJsonPath('allowlists.client_surfaces.0', 'mobile')
            ->assertJsonPath('allowlists.client_surfaces.1', 'mac_edge')
            ->assertJsonPath('allowlists.transports.0', 'mobile_push_to_talk')
            ->assertJsonPath('allowlists.transports.1', 'livekit_webrtc')
            ->assertJsonPath('allowlists.runtimes.0', 'livekit_agents_sdk')
            ->assertJsonPath('allowlists.privacy_classes.2', 'p3_audio')
            ->assertJsonPath('kernel_is_decision_authority', true)
            ->assertJsonPath('activation_governance.schema_version', 'atlas.voice_realtime.activation_governance.v1')
            ->assertJsonPath('activation_governance.status', 'scaffold_fail_closed')
            ->assertJsonPath('activation_governance.first_product_surface', 'mobile')
            ->assertJsonPath('activation_governance.mobile_push_to_talk_required', true)
            ->assertJsonPath('activation_governance.livekit_agents_direct_provider_allowed', false)
            ->assertJsonPath('activation_governance.kernel_webhook_required', true)
            ->assertJsonPath('activation_governance.decision_receipt_required_per_turn', true)
            ->assertJsonPath('activation_governance.runtime_daemon_start_allowed_now', false)
            ->assertJsonPath('activation_governance.production_audio_streaming_allowed_now', false)
            ->assertJsonPath('activation_governance.always_on_listening_allowed_now', false)
            ->assertJsonPath('activation_governance.swift_native_mac_phase', 'future_after_mobile_voice')
            ->assertJsonPath('activation_governance.promotion_requires.0', 'mobile_push_to_talk_operational')
            ->assertJsonPath('activation_governance.blocked_shortcuts.0', 'swift_mac_before_mobile')
            ->assertJsonPath('session_lease.schema_version', 'atlas.voice.session_lease.v1')
            ->assertJsonPath('session_lease.default_mode', 'mobile_push_to_talk')
            ->assertJsonPath('session_lease.token_status', 'not_issued_scaffold')
            ->assertJsonPath('session_lease.kernel_decision_required_per_turn', true)
            ->assertJsonPath('session_start_endpoint', '/ai/voice/session/start')
            ->assertJsonPath('session_end_endpoint', '/ai/voice/session/end')
            ->assertJsonPath('readiness_endpoint', '/ai/voice/readiness')
            ->assertJsonPath('rivals_endpoint', '/ai/voice/rivals')
            ->assertJsonPath('runtime_dependencies_endpoint', '/ai/voice/runtime/dependencies')
            ->assertJsonPath('runtime_dependency_install_plan_endpoint', '/ai/voice/runtime/dependency-install-plan')
            ->assertJsonPath('runtime_token_issuer_plan_endpoint', '/ai/voice/runtime/token-issuer-plan')
            ->assertJsonPath('runtime_token_issuer_smoke_endpoint', '/ai/voice/runtime/token-issuer-smoke')
            ->assertJsonPath('runtime_pre_start_health_checks_smoke_endpoint', '/ai/voice/runtime/pre-start-health-checks-smoke')
            ->assertJsonPath('runtime_certification_endpoint', '/ai/voice/runtime/certification')
            ->assertJsonPath('runtime_product_loop_check_endpoint', '/ai/voice/runtime/product-loop-check')
            ->assertJsonPath('runtime_promotion_review_packet_endpoint', '/ai/voice/runtime/promotion-review-packet')
            ->assertJsonPath('runtime_event_normalizer_endpoint', '/ai/voice/runtime/events/normalize')
            ->assertJsonPath('runtime_event_sequence_normalizer_endpoint', '/ai/voice/runtime/events/normalize-sequence')
            ->assertJsonPath('mobile_session_start_endpoint', '/v1/mobile/ai/voice/session/start')
            ->assertJsonPath('mobile_session_end_endpoint', '/v1/mobile/ai/voice/session/end')
            ->assertJsonPath('mobile_readiness_endpoint', '/v1/mobile/ai/voice/readiness')
            ->assertJsonPath('mobile_rivals_endpoint', '/v1/mobile/ai/voice/rivals')
            ->assertJsonPath('mobile_runtime_dependencies_endpoint', '/v1/mobile/ai/voice/runtime/dependencies')
            ->assertJsonPath('mobile_runtime_dependency_install_plan_endpoint', '/v1/mobile/ai/voice/runtime/dependency-install-plan')
            ->assertJsonPath('mobile_runtime_token_issuer_plan_endpoint', '/v1/mobile/ai/voice/runtime/token-issuer-plan')
            ->assertJsonPath('mobile_runtime_token_issuer_smoke_endpoint', '/v1/mobile/ai/voice/runtime/token-issuer-smoke')
            ->assertJsonPath('mobile_runtime_pre_start_health_checks_smoke_endpoint', '/v1/mobile/ai/voice/runtime/pre-start-health-checks-smoke')
            ->assertJsonPath('mobile_runtime_certification_endpoint', '/v1/mobile/ai/voice/runtime/certification')
            ->assertJsonPath('mobile_runtime_product_loop_check_endpoint', '/v1/mobile/ai/voice/runtime/product-loop-check')
            ->assertJsonPath('mobile_runtime_promotion_review_packet_endpoint', '/v1/mobile/ai/voice/runtime/promotion-review-packet')
            ->assertJsonPath('mobile_runtime_event_normalizer_endpoint', '/v1/mobile/ai/voice/runtime/events/normalize')
            ->assertJsonPath('mobile_runtime_event_sequence_normalizer_endpoint', '/v1/mobile/ai/voice/runtime/events/normalize-sequence')
            ->assertJsonPath('wake_word_endpoint', '/ai/voice/wake-word')
            ->assertJsonPath('mobile_wake_word_endpoint', '/v1/mobile/ai/voice/wake-word')
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
            ->assertJsonPath('callback_order.runtime_callbacks_require_kernel_accepted_turn.0', 'turn_synthesized')
            ->assertJsonPath('callback_order.runtime_callbacks_require_kernel_accepted_turn.3', 'runtime_failed')
            ->assertJsonPath('callback_order.runtime_callbacks_require_kernel_accepted_turn.4', 'provider_health_degraded')
            ->assertJsonPath('callback_order.runtime_health_after_accepted_turn.0', 'runtime_failed')
            ->assertJsonPath('persistence_contract.raw_audio', false)
            ->assertJsonPath('persistence_contract.raw_transcript', false)
            ->assertJsonPath('persistence_contract.raw_response_text', false)
            ->assertJsonPath('callback_payload_schemas.transcript_final.required.2', 'transcript')
            ->assertJsonPath('callback_payload_schemas.tts_synthesized.prohibited.0', 'response_text')
            ->assertJsonPath('callback_payload_schemas.provider_health_degraded.required.2', 'provider')
            ->assertJsonPath('production_loop_smoke.schema_version', 'atlas.voice_realtime.production_loop_smoke_contract.v1')
            ->assertJsonPath('production_loop_smoke.status', 'available_without_daemon')
            ->assertJsonPath('production_loop_smoke.sdk_events_example_path', 'runtimes/python/voice_realtime/sdk-events.example.json')
            ->assertJsonPath('production_loop_smoke.cli_command', 'php artisan atlas:ai:voice production-loop-smoke --json')
            ->assertJsonPath('production_loop_smoke.daemon_started', false)
            ->assertJsonPath('production_loop_smoke.sdk_imported', false)
            ->assertJsonPath('production_loop_smoke.kernel_only', true)
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

    public function test_voice_runtime_event_normalizer_is_available_without_execution(): void
    {
        $this->postJson('/ai/voice/runtime/events/normalize', [
            'event_kind' => 'transcribed_turn',
            'session_id' => 'voice_session_normalize',
            'turn_id' => 'voice_turn_normalize',
            'transcript' => 'continue a implementacao',
            'debug_blob' => ['raw_audio' => 'base64-forbidden'],
        ], $this->headers)
            ->assertOk()
            ->assertJsonPath('schema_version', 'atlas.voice_realtime.runtime_event_normalizer.v1')
            ->assertJsonPath('status', 'invalid_event')
            ->assertJsonPath('valid', false)
            ->assertJsonPath('callback', 'transcript_final')
            ->assertJsonPath('callback_event.payload.session_id', 'voice_session_normalize')
            ->assertJsonPath('callback_event.payload.turn_id', 'voice_turn_normalize')
            ->assertJsonPath('callback_event.payload.transcript', 'continue a implementacao')
            ->assertJsonPath('contract.guardrails.runtime_execution_enabled', false)
            ->assertJsonPath('contract.guardrails.provider_execution_enabled', false)
            ->assertJsonPath('contract.guardrails.raw_audio_persistence_allowed', false)
            ->assertJson(fn ($json) => $json
                ->where('errors.0', 'forbidden_runtime_field:debug_blob.raw_audio')
                ->where('dropped_fields.0', 'debug_blob')
                ->etc()
            );
    }

    public function test_voice_runtime_event_sequence_normalizer_is_available_on_mobile_gateway(): void
    {
        $token = $this->mobileDeviceToken();

        $this->postJson('/v1/mobile/ai/voice/runtime/events/normalize-sequence', [
            'events' => [
                [
                    'event_kind' => 'room_connected',
                    'session_id' => 'voice_session_sequence',
                    'participant_identity' => 'mobile:vitor',
                    'room_name' => 'atlas-voice-sequence',
                ],
                [
                    'event_kind' => 'transcribed_turn',
                    'session_id' => 'voice_session_sequence',
                    'turn_id' => 'voice_turn_sequence',
                    'transcript' => 'continue',
                ],
                [
                    'event_kind' => 'synthesized',
                    'session_id' => 'voice_session_sequence',
                    'turn_id' => 'voice_turn_sequence',
                    'response_text_hash' => hash('sha256', 'ok'),
                ],
                [
                    'event_kind' => 'played',
                    'session_id' => 'voice_session_sequence',
                    'turn_id' => 'voice_turn_sequence',
                    'played_duration_ms' => 250,
                ],
                [
                    'event_kind' => 'room_disconnected',
                    'session_id' => 'voice_session_sequence',
                ],
            ],
        ], [
            'Authorization' => 'Bearer '.$token,
        ])
            ->assertOk()
            ->assertJsonPath('schema_version', 'atlas.voice_realtime.runtime_event_normalizer.v1')
            ->assertJsonPath('status', 'normalized_sequence')
            ->assertJsonPath('valid', true)
            ->assertJsonPath('normalized_events.0.callback', 'participant_joined')
            ->assertJsonPath('normalized_events.1.callback', 'transcript_final')
            ->assertJsonPath('normalized_events.2.callback', 'tts_synthesized')
            ->assertJsonPath('normalized_events.3.callback', 'audio_played')
            ->assertJsonPath('normalized_events.4.callback', 'participant_left')
            ->assertJsonPath('sequence_validation.status', 'valid')
            ->assertJsonPath('contract.guardrails.runtime_execution_enabled', false);
    }

    public function test_voice_runtime_contract_and_bootstrap_reject_unknown_runtime(): void
    {
        $this->getJson('/ai/voice/runtime/contract?runtime=rogue_runtime', $this->headers)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['runtime']);

        $this->getJson('/ai/voice/runtime/bootstrap?runtime=rogue_runtime', $this->headers)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['runtime']);
    }

    public function test_voice_runtime_contract_advertises_livekit_token_issued_at_session_start_when_enabled(): void
    {
        config()->set('atlas.voice.livekit.token_issuer_enabled', true);
        config()->set('atlas.voice.livekit.url', 'http://livekit.test');
        config()->set('atlas.voice.livekit.api_key', 'livekit-test-key');
        config()->set('atlas.voice.livekit.api_secret', 'livekit-test-secret');

        $this->getJson('/ai/voice/runtime/contract?runtime=livekit_agents_sdk', $this->headers)
            ->assertOk()
            ->assertJsonPath('session_lease.token_status', 'issued_when_session_starts')
            ->assertJsonPath('session_lease.livekit_url', 'http://livekit.test')
            ->assertJsonMissingPath('session_lease.access_token');
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

    public function test_voice_runtime_dependencies_are_available_over_internal_api(): void
    {
        $this->getJson('/ai/voice/runtime/dependencies?runtime=livekit_agents_sdk', $this->headers)
            ->assertOk()
            ->assertJsonPath('schema_version', 'atlas.voice_realtime.runtime_dependency_plan.v1')
            ->assertJsonPath('status', 'ready')
            ->assertJsonPath('surface_id', 'voice_realtime')
            ->assertJsonPath('runtime_id', 'livekit_agents_sdk')
            ->assertJsonPath('runtime_family', 'python_ai_data')
            ->assertJsonPath('manifest_path', 'runtimes/python/voice_realtime/runtime-dependencies.json')
            ->assertJsonPath('python_runtime.schema_version', 'atlas.voice_realtime.python_runtime_plan.v1')
            ->assertJsonPath('python_runtime.minimum_version', '3.10')
            ->assertJsonPath('python_runtime.operator_managed', true)
            ->assertJsonPath('python_runtime.auto_install_allowed', false)
            ->assertJsonPath('guardrails.kernel_decides', true)
            ->assertJsonPath('guardrails.raw_audio_persistence_allowed', false);
    }

    public function test_voice_runtime_dependencies_are_available_over_mobile_gateway(): void
    {
        $token = $this->mobileDeviceToken();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/v1/mobile/ai/voice/runtime/dependencies?runtime=livekit_agents_sdk')
            ->assertOk()
            ->assertJsonPath('schema_version', 'atlas.voice_realtime.runtime_dependency_plan.v1')
            ->assertJsonPath('status', 'ready')
            ->assertJsonPath('python_runtime.schema_version', 'atlas.voice_realtime.python_runtime_plan.v1')
            ->assertJsonPath('guardrails.kernel_decides', true);
    }

    public function test_voice_runtime_dependency_install_plan_is_available_over_internal_api(): void
    {
        $this->getJson('/ai/voice/runtime/dependency-install-plan?runtime=livekit_agents_sdk', $this->headers)
            ->assertOk()
            ->assertJsonPath('schema_version', 'atlas.voice_realtime.dependency_install_plan.v1')
            ->assertJsonPath('status', 'ready_to_install_optional_dependency')
            ->assertJsonPath('surface_id', 'voice_realtime')
            ->assertJsonPath('runtime_id', 'livekit_agents_sdk')
            ->assertJsonPath('runtime_family', 'python_ai_data')
            ->assertJsonPath('operator_managed', true)
            ->assertJsonPath('pip_execution_attempted', false)
            ->assertJsonPath('sdk_imported', false)
            ->assertJsonPath('daemon_started', false)
            ->assertJsonPath('kernel_only', true)
            ->assertJsonPath('mobile_first', true)
            ->assertJsonPath('requirements_file', 'runtimes/python/voice_realtime/requirements-livekit.txt')
            ->assertJsonPath('expected_packages.0', 'livekit-agents')
            ->assertJsonPath('expected_requirements.0', 'livekit-agents>=1.3.12,<2.0.0')
            ->assertJsonPath('requirements_packages.0', 'livekit-agents>=1.3.12,<2.0.0')
            ->assertJsonPath('missing_requirements', [])
            ->assertJsonPath('unsafe_requirements', [])
            ->assertJsonPath('gates.requirements_file_exists', true)
            ->assertJsonPath('gates.requirements_match_manifest', true)
            ->assertJsonPath('gates.pip_not_executed', true)
            ->assertJsonPath('next_action', 'run_install_command_then_sdk_check');
    }

    public function test_voice_runtime_dependency_install_plan_is_available_over_mobile_gateway(): void
    {
        $token = $this->mobileDeviceToken();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/v1/mobile/ai/voice/runtime/dependency-install-plan?runtime=livekit_agents_sdk')
            ->assertOk()
            ->assertJsonPath('schema_version', 'atlas.voice_realtime.dependency_install_plan.v1')
            ->assertJsonPath('status', 'ready_to_install_optional_dependency')
            ->assertJsonPath('operator_managed', true)
            ->assertJsonPath('pip_execution_attempted', false)
            ->assertJsonPath('sdk_imported', false)
            ->assertJsonPath('daemon_started', false)
            ->assertJsonPath('kernel_only', true)
            ->assertJsonPath('mobile_first', true);
    }

    public function test_voice_runtime_token_issuer_plan_is_available_over_internal_api_without_secret_leak(): void
    {
        config()->set('atlas.voice.livekit.api_secret', 'api-secret-should-not-leak');

        $response = $this->getJson('/ai/voice/runtime/token-issuer-plan?runtime=livekit_agents_sdk', $this->headers)
            ->assertOk()
            ->assertJsonPath('schema_version', 'atlas.voice_realtime.livekit_token_issuer_config_plan.v1')
            ->assertJsonPath('surface_id', 'voice_realtime')
            ->assertJsonPath('runtime_id', 'livekit_agents_sdk')
            ->assertJsonPath('security_contract.secrets_exposed', false)
            ->assertJsonPath('security_contract.writes_env_file', false)
            ->assertJsonPath('security_contract.starts_daemon', false);

        $this->assertStringNotContainsString('api-secret-should-not-leak', $response->getContent());
    }

    public function test_voice_runtime_token_issuer_plan_is_available_over_mobile_gateway(): void
    {
        $token = $this->mobileDeviceToken();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/v1/mobile/ai/voice/runtime/token-issuer-plan?runtime=livekit_agents_sdk')
            ->assertOk()
            ->assertJsonPath('schema_version', 'atlas.voice_realtime.livekit_token_issuer_config_plan.v1')
            ->assertJsonPath('mobile_first', true)
            ->assertJsonPath('kernel_only', true)
            ->assertJsonPath('security_contract.secrets_exposed', false);
    }

    public function test_voice_runtime_token_issuer_smoke_is_available_over_internal_api_without_secret_or_token_leak(): void
    {
        config()->set('atlas.voice.livekit.token_issuer_enabled', false);
        config()->set('atlas.voice.livekit.url', '');
        config()->set('atlas.voice.livekit.api_key', '');
        config()->set('atlas.voice.livekit.api_secret', 'api-secret-should-not-leak');

        $response = $this->getJson('/ai/voice/runtime/token-issuer-smoke?runtime=livekit_agents_sdk', $this->headers)
            ->assertOk()
            ->assertJsonPath('schema_version', 'atlas.voice_realtime.livekit_token_issuer_smoke.v1')
            ->assertJsonPath('status', 'blocked')
            ->assertJsonPath('surface_id', 'voice_realtime')
            ->assertJsonPath('runtime_id', 'livekit_agents_sdk')
            ->assertJsonPath('token_issued', false)
            ->assertJsonPath('access_token_exposed', false)
            ->assertJsonPath('security_contract.secrets_exposed', false)
            ->assertJsonPath('security_contract.starts_daemon', false);

        $this->assertStringNotContainsString('api-secret-should-not-leak', $response->getContent());
        $this->assertStringNotContainsString('"access_token":', $response->getContent());
    }

    public function test_voice_runtime_token_issuer_smoke_can_use_ephemeral_config_over_mobile_gateway(): void
    {
        config()->set('atlas.voice.livekit.token_issuer_enabled', false);
        config()->set('atlas.voice.livekit.url', '');
        config()->set('atlas.voice.livekit.api_key', '');
        config()->set('atlas.voice.livekit.api_secret', '');

        $token = $this->mobileDeviceToken();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/v1/mobile/ai/voice/runtime/token-issuer-smoke?runtime=livekit_agents_sdk&ephemeral_test_config=1')
            ->assertOk()
            ->assertJsonPath('schema_version', 'atlas.voice_realtime.livekit_token_issuer_smoke.v1')
            ->assertJsonPath('status', 'passed')
            ->assertJsonPath('mobile_first', true)
            ->assertJsonPath('kernel_only', true)
            ->assertJsonPath('ephemeral_test_config', true)
            ->assertJsonPath('production_readiness', 'not_proven_by_ephemeral_smoke')
            ->assertJsonPath('token_issued', true)
            ->assertJsonPath('token_segments_count', 3)
            ->assertJsonPath('access_token_exposed', false)
            ->assertJsonMissingPath('issued_token.access_token');

        $this->assertFalse((bool) config('atlas.voice.livekit.token_issuer_enabled'));
    }

    public function test_voice_runtime_pre_start_health_checks_smoke_is_available_over_internal_api(): void
    {
        $response = $this->getJson('/ai/voice/runtime/pre-start-health-checks-smoke?runtime=livekit_agents_sdk&base_url=http://atlas.test', $this->headers)
            ->assertOk()
            ->assertJsonPath('schema_version', 'atlas.voice_realtime.pre_start_health_checks_smoke.v1')
            ->assertJsonPath('status', 'passed_no_process_start')
            ->assertJsonPath('surface_id', 'voice_realtime')
            ->assertJsonPath('runtime_id', 'livekit_agents_sdk')
            ->assertJsonPath('kernel_only', true)
            ->assertJsonPath('mobile_first', true)
            ->assertJsonPath('smoke_only', true)
            ->assertJsonPath('production_readiness', 'not_proven_by_smoke')
            ->assertJsonPath('process_launch_attempted', false)
            ->assertJsonPath('daemon_started', false)
            ->assertJsonPath('subprocess_module_imported', false)
            ->assertJsonPath('livekit_sdk_imported', false)
            ->assertJsonPath('provider_calls_made', false)
            ->assertJsonPath('tool_calls_made', false)
            ->assertJsonPath('raw_audio_touched', false)
            ->assertJsonPath('managed_env_write_execution.target_path', '<temporary-managed-env-file>')
            ->assertJsonPath('temporary_env_file_removed_after_smoke', true)
            ->assertJsonPath('gates.pre_start_health_checks_passed', true)
            ->assertJsonPath('gates.subprocess_start_contract_ready', true)
            ->assertJsonPath('pre_start_health_checks.status', 'passed_no_process_start')
            ->assertJsonPath('subprocess_start_contract.status', 'ready_for_reviewed_subprocess_start_implementation');

        $this->assertStringNotContainsString('decision_receipt_pre_start_smoke_env_write=', $response->getContent());
        $this->assertStringNotContainsString('"access_token":', $response->getContent());
    }

    public function test_voice_runtime_pre_start_health_checks_smoke_is_available_over_mobile_gateway(): void
    {
        $token = $this->mobileDeviceToken();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/v1/mobile/ai/voice/runtime/pre-start-health-checks-smoke?runtime=livekit_agents_sdk&base_url=http://atlas.test')
            ->assertOk()
            ->assertJsonPath('schema_version', 'atlas.voice_realtime.pre_start_health_checks_smoke.v1')
            ->assertJsonPath('status', 'passed_no_process_start')
            ->assertJsonPath('mobile_first', true)
            ->assertJsonPath('kernel_only', true)
            ->assertJsonPath('smoke_only', true)
            ->assertJsonPath('process_launch_attempted', false)
            ->assertJsonPath('daemon_started', false);
    }

    public function test_voice_runtime_certification_is_available_over_internal_api(): void
    {
        $response = $this->getJson('/ai/voice/runtime/certification?runtime=livekit_agents_sdk&base_url=http://atlas.test', $this->headers)
            ->assertOk()
            ->assertJsonPath('schema_version', 'atlas.voice_realtime.runtime_certification.v1')
            ->assertJsonPath('status', 'certified_scaffold')
            ->assertJsonPath('surface_id', 'voice_realtime')
            ->assertJsonPath('runtime_id', 'livekit_agents_sdk')
            ->assertJsonPath('kernel_only', true)
            ->assertJsonPath('mobile_first', true)
            ->assertJsonPath('daemon_started', false)
            ->assertJsonPath('summary.gate_count', 8)
            ->assertJsonPath('summary.failed_gates', 0)
            ->assertJsonPath('gates.foundation_registry_ready.passed', true)
            ->assertJsonPath('gates.foundation_registry_ready.next_allowed_step', 'connect_through_authorized_adapter_with_existing_kernel_methods')
            ->assertJsonPath('gates.worker_start_blocked_safely.passed', true)
            ->assertJsonPath('gates.product_loop_check_available.passed', true)
            ->assertJsonPath('gates.product_loop_check_available.schema_version', 'atlas.voice_realtime.product_loop_check.v1')
            ->assertJsonPath('gates.product_loop_check_available.daemon_started', false)
            ->assertJsonPath('gates.product_loop_check_available.sdk_probe_import_safe', true)
            ->assertJsonPath('gates.product_loop_check_available.sdk_handler_blueprint_available', true)
            ->assertJsonPath('gates.product_loop_check_available.sdk_kernel_normalizer_required', true)
            ->assertJsonPath('gates.product_loop_check_available.production_promotion_blocked', true)
            ->assertJsonPath('gates.pre_start_health_checks_smoke_passed.passed', true)
            ->assertJsonPath('gates.pre_start_health_checks_smoke_passed.schema_version', 'atlas.voice_realtime.pre_start_health_checks_smoke.v1')
            ->assertJsonPath('gates.pre_start_health_checks_smoke_passed.status', 'passed_no_process_start')
            ->assertJsonPath('gates.pre_start_health_checks_smoke_passed.smoke_only', true)
            ->assertJsonPath('gates.pre_start_health_checks_smoke_passed.production_readiness', 'not_proven_by_smoke')
            ->assertJsonPath('gates.pre_start_health_checks_smoke_passed.process_launch_attempted', false)
            ->assertJsonPath('gates.pre_start_health_checks_smoke_passed.daemon_started', false)
            ->assertJsonPath('gates.pre_start_health_checks_smoke_passed.provider_calls_made', false)
            ->assertJsonPath('gates.pre_start_health_checks_smoke_passed.tool_calls_made', false)
            ->assertJsonPath('gates.pre_start_health_checks_smoke_passed.raw_audio_touched', false)
            ->assertJsonPath('gates.certification_artifacts_sanitized.passed', true)
            ->assertJsonPath('gates.certification_artifacts_sanitized.forbidden_key_count', 0)
            ->assertJsonPath('production_promotion_gate.schema_version', 'atlas.voice_realtime.production_promotion_gate.v1')
            ->assertJsonPath('production_promotion_gate.status', 'blocked')
            ->assertJsonPath('production_promotion_gate.human_review_required', true)
            ->assertJsonPath('production_promotion_gate.promotion_allowed', false)
            ->assertJsonPath('production_promotion_gate.auto_promotion_allowed', false)
            ->assertJsonPath('production_promotion_gate.decision_receipt_required', true)
            ->assertJsonPath('production_promotion_gate.rollback_plan_required', true)
            ->assertJsonPath('production_promotion_gate.review_packet.schema_version', 'atlas.voice_realtime.production_promotion_review_packet.v1')
            ->assertJsonPath('production_promotion_gate.review_packet.status', 'blocked_until_machine_gates_pass')
            ->assertJsonPath('production_promotion_gate.review_packet.required_decision_receipt', true)
            ->assertJsonPath('production_promotion_gate.review_packet.required_rollback_plan.0', 'disable_livekit_token_issuer')
            ->assertJsonPath('production_promotion_gate.review_packet.required_evidence.3', 'livekit_token_issuer_smoke')
            ->assertJsonPath('production_promotion_gate.review_packet.required_evidence.5', 'product_loop_check')
            ->assertJsonPath('production_promotion_gate.review_packet.required_evidence.6', 'pre_start_health_checks_smoke')
            ->assertJsonPath('production_promotion_gate.review_packet.required_evidence.7', 'rivals_voice_comparison')
            ->assertJsonPath('production_promotion_gate.review_packet.forbidden_actions.4', 'bypass_kernel_decision_receipt')
            ->assertJsonPath('production_promotion_gate.machine_gates.sdk_probe_import_safe.passed', true)
            ->assertJsonPath('production_promotion_gate.machine_gates.sdk_probe_import_safe.sdk_imported', false)
            ->assertJsonPath('production_promotion_gate.machine_gates.sdk_probe_import_safe.import_probe_only', true)
            ->assertJsonPath('production_promotion_gate.machine_gates.product_loop_check_available.passed', true)
            ->assertJsonPath('production_promotion_gate.machine_gates.product_loop_check_available.schema_version', 'atlas.voice_realtime.product_loop_check.v1')
            ->assertJsonPath('production_promotion_gate.machine_gates.product_loop_check_available.daemon_started', false)
            ->assertJsonPath('production_promotion_gate.machine_gates.product_loop_check_available.sdk_probe_import_safe', true)
            ->assertJsonPath('production_promotion_gate.machine_gates.product_loop_check_available.sdk_handler_blueprint_available', true)
            ->assertJsonPath('production_promotion_gate.machine_gates.product_loop_check_available.sdk_kernel_normalizer_required', true)
            ->assertJsonPath('production_promotion_gate.machine_gates.product_loop_check_available.production_promotion_blocked', true)
            ->assertJsonPath('production_promotion_gate.machine_gates.pre_start_health_checks_smoke_passed.passed', true)
            ->assertJsonPath('production_promotion_gate.machine_gates.pre_start_health_checks_smoke_passed.schema_version', 'atlas.voice_realtime.pre_start_health_checks_smoke.v1')
            ->assertJsonPath('production_promotion_gate.machine_gates.pre_start_health_checks_smoke_passed.status', 'passed_no_process_start')
            ->assertJsonPath('gates.production_loop_smoke_passed.bridge_contract_status', 'valid')
            ->assertJsonPath('production_promotion_gate.machine_gates.production_loop_smoke_passed.bridge_contract_status', 'valid')
            ->assertJsonPath('gates.production_loop_smoke_passed.handler_registry_contract_status', 'valid')
            ->assertJsonPath('production_promotion_gate.machine_gates.production_loop_smoke_passed.handler_registry_contract_status', 'valid')
            ->assertJsonPath('gates.production_loop_smoke_passed.worker_return_contract_status', 'valid')
            ->assertJsonPath('production_promotion_gate.machine_gates.production_loop_smoke_passed.worker_return_contract_status', 'valid')
            ->assertJsonPath('production_promotion_gate.next_action', 'rerun_runtime_certification_with_require_sdk')
            ->assertJsonPath('next_action', 'rerun_runtime_certification_with_require_sdk')
            ->assertJsonPath('artifacts.livekit_token_issuer.schema_version', 'atlas.voice_realtime.livekit_token_issuer_readiness.v1')
            ->assertJsonPath('artifacts.livekit_token_issuer.secrets_exposed', false)
            ->assertJsonPath('artifacts.product_loop_check.schema_version', 'atlas.voice_realtime.product_loop_check.v1')
            ->assertJsonPath('artifacts.product_loop_check.daemon_started', false)
            ->assertJsonPath('artifacts.pre_start_health_checks_smoke.schema_version', 'atlas.voice_realtime.pre_start_health_checks_smoke.v1')
            ->assertJsonPath('artifacts.pre_start_health_checks_smoke.status', 'passed_no_process_start')
            ->assertJsonPath('artifacts.pre_start_health_checks_smoke.smoke_only', true)
            ->assertJsonPath('artifacts.pre_start_health_checks_smoke.daemon_started', false)
            ->assertJsonPath('artifacts.pre_start_health_checks_smoke.process_launch_attempted', false)
            ->assertJsonPath('artifacts.production_loop_smoke.bridge_contract_report.schema_version', 'atlas.voice_realtime.bridge_contract_report.v1')
            ->assertJsonPath('artifacts.production_loop_smoke.bridge_contract_report.status', 'valid')
            ->assertJsonPath('artifacts.production_loop_smoke.handler_registry_contract_report.schema_version', 'atlas.voice_realtime.handler_registry_contract_report.v1')
            ->assertJsonPath('artifacts.production_loop_smoke.handler_registry_contract_report.status', 'valid')
            ->assertJsonPath('artifacts.production_loop_smoke.worker_return_contract.schema_version', 'atlas.voice_realtime.worker_return_contract_report.v1')
            ->assertJsonPath('artifacts.production_loop_smoke.worker_return_contract.status', 'valid')
            ->assertJsonMissingPath('artifacts.foundation_registry.summary')
            ->assertJsonMissingPath('artifacts.worker_start_check.worker_plan')
            ->assertJsonMissingPath('artifacts.product_loop_check.worker_start')
            ->assertJsonMissingPath('artifacts.pre_start_health_checks_smoke.bootstrap')
            ->assertJsonMissingPath('artifacts.pre_start_health_checks_smoke.pre_start_health_checks')
            ->assertJsonMissingPath('artifacts.production_loop_smoke.results');

        $this->assertContains($response->json('artifacts.product_loop_check.status'), ['blocked', 'ready_for_human_review']);
        $this->assertContains($response->json('artifacts.product_loop_check.next_action'), [
            'upgrade_python_runtime_for_livekit_agents_sdk',
            'install_livekit_agents_sdk',
            'submit_voice_production_promotion_for_human_review',
        ]);
    }

    public function test_voice_runtime_certification_is_available_over_mobile_gateway(): void
    {
        $token = $this->mobileDeviceToken();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/v1/mobile/ai/voice/runtime/certification?runtime=livekit_agents_sdk&base_url=http://atlas.test')
            ->assertOk()
            ->assertJsonPath('schema_version', 'atlas.voice_realtime.runtime_certification.v1')
            ->assertJsonPath('status', 'certified_scaffold')
            ->assertJsonPath('mobile_first', true)
            ->assertJsonPath('summary.failed_gates', 0)
            ->assertJsonPath('production_promotion_gate.status', 'blocked')
            ->assertJsonPath('production_promotion_gate.promotion_allowed', false)
            ->assertJsonPath('production_promotion_gate.auto_promotion_allowed', false)
            ->assertJsonPath('gates.certification_artifacts_sanitized.passed', true);
    }

    public function test_voice_runtime_certification_propagates_wiring_flags_over_api(): void
    {
        $response = $this->getJson('/ai/voice/runtime/certification?runtime=livekit_agents_sdk&base_url=http://atlas.test&callback_loop_wired=1&production_sdk_loop_wired=1', $this->headers)
            ->assertOk()
            ->assertJsonPath('schema_version', 'atlas.voice_realtime.runtime_certification.v1')
            ->assertJsonPath('daemon_started', false);

        $this->assertStringContainsString(
            '--callback-loop-wired --production-sdk-loop-wired',
            (string) $response->json('artifacts.worker_start_check.command'),
        );
        $this->assertStringContainsString(
            '--callback-loop-wired --production-sdk-loop-wired',
            (string) $response->json('artifacts.product_loop_check.command'),
        );
    }

    public function test_voice_product_loop_check_is_available_over_internal_api_without_daemon_start(): void
    {
        $response = $this->getJson('/ai/voice/runtime/product-loop-check?runtime=livekit_agents_sdk&base_url=http://atlas.test', $this->headers)
            ->assertOk()
            ->assertJsonPath('schema_version', 'atlas.voice_realtime.product_loop_check.v1')
            ->assertJsonPath('surface_id', 'voice_realtime')
            ->assertJsonPath('runtime_id', 'livekit_agents_sdk')
            ->assertJsonPath('kernel_only', true)
            ->assertJsonPath('mobile_first', true)
            ->assertJsonPath('daemon_started', false)
            ->assertJsonPath('gates.worker_start_still_blocked', true)
            ->assertJsonPath('gates.production_promotion_blocked', true)
            ->assertJsonPath('gates.direct_provider_forbidden', true)
            ->assertJsonPath('gates.raw_audio_forbidden', true)
            ->assertJsonPath('guardrails.direct_provider_call_allowed', false)
            ->assertJsonPath('guardrails.raw_audio_persistence_allowed', false);

        $this->assertContains($response->json('status'), ['blocked', 'ready_for_human_review'], true);
        $this->assertStringNotContainsString('product-loop-secret', $response->getContent());
        $this->assertStringNotContainsString('product-loop-token', $response->getContent());
        $this->assertStringNotContainsString('"raw_audio":', $response->getContent());
        $this->assertStringNotContainsString('"response_text":', $response->getContent());
    }

    public function test_voice_product_loop_check_propagates_mobile_wiring_flags_without_daemon_start(): void
    {
        $token = $this->mobileDeviceToken();

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/v1/mobile/ai/voice/runtime/product-loop-check?runtime=livekit_agents_sdk&base_url=http://atlas.test&callback_loop_wired=1&production_sdk_loop_wired=1')
            ->assertOk()
            ->assertJsonPath('schema_version', 'atlas.voice_realtime.product_loop_check.v1')
            ->assertJsonPath('mobile_first', true)
            ->assertJsonPath('daemon_started', false);

        $this->assertStringContainsString('--callback-loop-wired --production-sdk-loop-wired', (string) $response->json('command'));
        $this->assertStringNotContainsString('product-loop-secret', $response->getContent());
        $this->assertStringNotContainsString('"raw_audio":', $response->getContent());
    }

    public function test_voice_product_loop_check_is_available_over_mobile_gateway_without_daemon_start(): void
    {
        $token = $this->mobileDeviceToken();

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/v1/mobile/ai/voice/runtime/product-loop-check?runtime=livekit_agents_sdk&base_url=http://atlas.test')
            ->assertOk()
            ->assertJsonPath('schema_version', 'atlas.voice_realtime.product_loop_check.v1')
            ->assertJsonPath('surface_id', 'voice_realtime')
            ->assertJsonPath('runtime_id', 'livekit_agents_sdk')
            ->assertJsonPath('kernel_only', true)
            ->assertJsonPath('mobile_first', true)
            ->assertJsonPath('daemon_started', false)
            ->assertJsonPath('gates.worker_start_still_blocked', true)
            ->assertJsonPath('guardrails.auto_promotion_allowed', false);

        $this->assertContains($response->json('status'), ['blocked', 'ready_for_human_review'], true);
        $this->assertStringNotContainsString('product-loop-secret', $response->getContent());
        $this->assertStringNotContainsString('"raw_audio":', $response->getContent());
    }

    public function test_voice_promotion_review_packet_is_available_over_internal_api(): void
    {
        $response = $this->getJson('/ai/voice/runtime/promotion-review-packet?runtime=livekit_agents_sdk&base_url=http://atlas.test&hours=48&callback_loop_wired=1&production_sdk_loop_wired=1', $this->headers)
            ->assertOk()
            ->assertJsonPath('schema_version', 'atlas.voice_realtime.production_promotion_review_bundle.v1')
            ->assertJsonPath('surface_id', 'voice_realtime')
            ->assertJsonPath('runtime_id', 'livekit_agents_sdk')
            ->assertJsonPath('kernel_only', true)
            ->assertJsonPath('mobile_first', true)
            ->assertJsonPath('callback_loop_wired', true)
            ->assertJsonPath('production_sdk_loop_wired', true)
            ->assertJsonPath('promotion_allowed', false)
            ->assertJsonPath('auto_promotion_allowed', false)
            ->assertJsonPath('daemon_started', false)
            ->assertJsonPath('human_review_required', true)
            ->assertJsonPath('decision_receipt_required', true)
            ->assertJsonPath('rollback_plan_required', true)
            ->assertJsonPath('summary.evidence_count', 4)
            ->assertJsonPath('review_packet.schema_version', 'atlas.voice_realtime.production_promotion_review_packet.v1')
            ->assertJsonPath('evidence.pre_start_health_checks_smoke.schema_version', 'atlas.voice_realtime.pre_start_health_checks_smoke.v1')
            ->assertJsonPath('evidence.pre_start_health_checks_smoke.status', 'passed_no_process_start')
            ->assertJsonPath('evidence.pre_start_health_checks_smoke.daemon_started', false)
            ->assertJsonPath('evidence.pre_start_health_checks_smoke.promotion_allowed', false)
            ->assertJsonPath('evidence.pre_start_health_checks_smoke.auto_promotion_allowed', false)
            ->assertJsonPath('guardrails.raw_audio_persistence_allowed', false)
            ->assertJsonPath('guardrails.direct_provider_call_allowed', false)
            ->assertJsonPath('guardrails.direct_tool_execution_allowed', false)
            ->assertJsonPath('guardrails.start_daemon_allowed', false)
            ->assertJsonPath('guardrails.boolean_approval_is_sufficient', false);

        $this->assertContains($response->json('status'), ['blocked_until_machine_gates_pass', 'ready_for_human_review'], true);
        $this->assertArrayHasKey('runtime_certification', $response->json('evidence'));
        $this->assertArrayHasKey('product_loop_check', $response->json('evidence'));
        $this->assertArrayHasKey('pre_start_health_checks_smoke', $response->json('evidence'));
        $this->assertArrayHasKey('rivals_voice_comparison', $response->json('evidence'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $response->json('bundle_hash'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $response->json('evidence.pre_start_health_checks_smoke.payload_hash'));
        $this->assertStringNotContainsString('preflight-secret', $response->getContent());
        $this->assertStringNotContainsString('product-loop-secret', $response->getContent());
        $this->assertStringNotContainsString('"raw_audio":', $response->getContent());
        $this->assertStringNotContainsString('"response_text":', $response->getContent());
    }

    public function test_voice_promotion_review_packet_is_available_over_mobile_gateway(): void
    {
        $token = $this->mobileDeviceToken();

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/v1/mobile/ai/voice/runtime/promotion-review-packet?runtime=livekit_agents_sdk&base_url=http://atlas.test&hours=48')
            ->assertOk()
            ->assertJsonPath('schema_version', 'atlas.voice_realtime.production_promotion_review_bundle.v1')
            ->assertJsonPath('surface_id', 'voice_realtime')
            ->assertJsonPath('runtime_id', 'livekit_agents_sdk')
            ->assertJsonPath('mobile_first', true)
            ->assertJsonPath('kernel_only', true)
            ->assertJsonPath('promotion_allowed', false)
            ->assertJsonPath('auto_promotion_allowed', false)
            ->assertJsonPath('daemon_started', false)
            ->assertJsonPath('summary.evidence_count', 4)
            ->assertJsonPath('evidence.pre_start_health_checks_smoke.schema_version', 'atlas.voice_realtime.pre_start_health_checks_smoke.v1')
            ->assertJsonPath('evidence.pre_start_health_checks_smoke.status', 'passed_no_process_start')
            ->assertJsonPath('guardrails.boolean_approval_is_sufficient', false);

        $this->assertContains($response->json('status'), ['blocked_until_machine_gates_pass', 'ready_for_human_review'], true);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $response->json('bundle_hash'));
    }

    public function test_voice_promotion_review_packet_hash_changes_when_mobile_wiring_flags_change(): void
    {
        $token = $this->mobileDeviceToken();

        $unwired = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/v1/mobile/ai/voice/runtime/promotion-review-packet?runtime=livekit_agents_sdk&base_url=http://atlas.test&hours=48')
            ->assertOk();

        $wired = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/v1/mobile/ai/voice/runtime/promotion-review-packet?runtime=livekit_agents_sdk&base_url=http://atlas.test&hours=48&callback_loop_wired=1&production_sdk_loop_wired=1')
            ->assertOk()
            ->assertJsonPath('callback_loop_wired', true)
            ->assertJsonPath('production_sdk_loop_wired', true);

        $this->assertFalse($unwired->json('callback_loop_wired'));
        $this->assertFalse($unwired->json('production_sdk_loop_wired'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $unwired->json('bundle_hash'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $wired->json('bundle_hash'));
        $this->assertNotSame($unwired->json('bundle_hash'), $wired->json('bundle_hash'));
    }

    public function test_voice_mobile_gateway_records_wake_word_with_mobile_bearer(): void
    {
        $token = $this->mobileDeviceToken();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/v1/mobile/ai/voice/wake-word', [
                'session_id' => 'voice_mobile_wake',
                'envelope_id' => 'env_mobile_wake',
                'receipt_id' => 'receipt_mobile_wake',
                'wake_word_engine' => 'swift_mobile_edge',
                'client_surface' => 'mobile',
                'latency_ms' => 38,
            ])
            ->assertOk()
            ->assertJsonPath('status', 'wake_word_detected_recorded')
            ->assertJsonPath('wake_word.local_only', true)
            ->assertJsonPath('wake_word.raw_audio_persisted', false)
            ->assertJsonPath('evidence_ledger.wake_word_detected.event_type', LedgerEventType::VoiceWakeWordDetected->value)
            ->assertJsonPath('evidence_ledger.slo.event_type', LedgerEventType::SloObserved->value);

        $this->assertDatabaseHas('atlas_ledger_events', [
            'event_type' => LedgerEventType::VoiceWakeWordDetected->value,
            'envelope_id' => 'env_mobile_wake',
            'receipt_id' => 'receipt_mobile_wake',
            'emitter_stage' => 'atlas.voice_realtime',
        ]);
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
            ->assertJsonPath('kernel.session_start_url', 'http://atlas.test/ai/voice/session/start')
            ->assertJsonPath('kernel.session_end_url', 'http://atlas.test/ai/voice/session/end')
            ->assertJsonPath('kernel.readiness_url', 'http://atlas.test/ai/voice/readiness')
            ->assertJsonPath('kernel.rivals_url', 'http://atlas.test/ai/voice/rivals')
            ->assertJsonPath('kernel.runtime_dependencies_url', 'http://atlas.test/ai/voice/runtime/dependencies')
            ->assertJsonPath('kernel.runtime_dependency_install_plan_url', 'http://atlas.test/ai/voice/runtime/dependency-install-plan')
            ->assertJsonPath('kernel.runtime_token_issuer_plan_url', 'http://atlas.test/ai/voice/runtime/token-issuer-plan')
            ->assertJsonPath('kernel.runtime_token_issuer_smoke_url', 'http://atlas.test/ai/voice/runtime/token-issuer-smoke')
            ->assertJsonPath('kernel.runtime_pre_start_health_checks_smoke_url', 'http://atlas.test/ai/voice/runtime/pre-start-health-checks-smoke')
            ->assertJsonPath('kernel.runtime_certification_url', 'http://atlas.test/ai/voice/runtime/certification')
            ->assertJsonPath('kernel.runtime_product_loop_check_url', 'http://atlas.test/ai/voice/runtime/product-loop-check')
            ->assertJsonPath('kernel.runtime_promotion_review_packet_url', 'http://atlas.test/ai/voice/runtime/promotion-review-packet')
            ->assertJsonPath('kernel.runtime_event_normalizer_url', 'http://atlas.test/ai/voice/runtime/events/normalize')
            ->assertJsonPath('kernel.runtime_event_sequence_normalizer_url', 'http://atlas.test/ai/voice/runtime/events/normalize-sequence')
            ->assertJsonPath('kernel.mobile_readiness_url', 'http://atlas.test/v1/mobile/ai/voice/readiness')
            ->assertJsonPath('kernel.mobile_rivals_url', 'http://atlas.test/v1/mobile/ai/voice/rivals')
            ->assertJsonPath('kernel.mobile_runtime_dependencies_url', 'http://atlas.test/v1/mobile/ai/voice/runtime/dependencies')
            ->assertJsonPath('kernel.mobile_runtime_dependency_install_plan_url', 'http://atlas.test/v1/mobile/ai/voice/runtime/dependency-install-plan')
            ->assertJsonPath('kernel.mobile_runtime_token_issuer_plan_url', 'http://atlas.test/v1/mobile/ai/voice/runtime/token-issuer-plan')
            ->assertJsonPath('kernel.mobile_runtime_token_issuer_smoke_url', 'http://atlas.test/v1/mobile/ai/voice/runtime/token-issuer-smoke')
            ->assertJsonPath('kernel.mobile_runtime_pre_start_health_checks_smoke_url', 'http://atlas.test/v1/mobile/ai/voice/runtime/pre-start-health-checks-smoke')
            ->assertJsonPath('kernel.mobile_runtime_certification_url', 'http://atlas.test/v1/mobile/ai/voice/runtime/certification')
            ->assertJsonPath('kernel.mobile_runtime_product_loop_check_url', 'http://atlas.test/v1/mobile/ai/voice/runtime/product-loop-check')
            ->assertJsonPath('kernel.mobile_runtime_promotion_review_packet_url', 'http://atlas.test/v1/mobile/ai/voice/runtime/promotion-review-packet')
            ->assertJsonPath('kernel.mobile_runtime_event_normalizer_url', 'http://atlas.test/v1/mobile/ai/voice/runtime/events/normalize')
            ->assertJsonPath('kernel.mobile_runtime_event_sequence_normalizer_url', 'http://atlas.test/v1/mobile/ai/voice/runtime/events/normalize-sequence')
            ->assertJsonPath('kernel.wake_word_url', 'http://atlas.test/ai/voice/wake-word')
            ->assertJsonPath('kernel.mobile_wake_word_url', 'http://atlas.test/v1/mobile/ai/voice/wake-word')
            ->assertJsonPath('kernel.turn_url', 'http://atlas.test/ai/voice/turn')
            ->assertJsonPath('kernel.mobile_turn_url', 'http://atlas.test/v1/mobile/ai/voice/turn')
            ->assertJsonPath('kernel.callbacks.turn_synthesized', 'http://atlas.test/ai/voice/turn/synthesized')
            ->assertJsonPath('kernel.mobile_callbacks.turn_synthesized', 'http://atlas.test/v1/mobile/ai/voice/turn/synthesized')
            ->assertJsonPath('default_providers.llm', 'atlas_kernel_only')
            ->assertJsonPath('session_lease.schema_version', 'atlas.voice.session_lease.v1')
            ->assertJsonPath('session_lease.token_status', 'not_issued_scaffold')
            ->assertJsonPath('session_lease.room_prefix', 'atlas-voice-')
            ->assertJsonPath('session_lease.required_room_prefix', 'atlas-voice-')
            ->assertJsonPath('session_lease.participant_namespace_source', 'client_surface')
            ->assertJsonPath('activation_governance.first_product_surface', 'mobile')
            ->assertJsonPath('activation_governance.livekit_agents_direct_provider_allowed', false)
            ->assertJsonPath('activation_governance.always_on_listening_allowed_now', false)
            ->assertJsonPath('persistence_contract.raw_audio', false)
            ->assertJsonPath('production_loop_smoke.schema_version', 'atlas.voice_realtime.production_loop_smoke_contract.v1')
            ->assertJsonPath('production_loop_smoke.sdk_events_example_path', 'runtimes/python/voice_realtime/sdk-events.example.json')
            ->assertJsonPath('production_loop_smoke.cli_command', 'php artisan atlas:ai:voice production-loop-smoke --json')
            ->assertJsonPath('production_loop_smoke.daemon_started', false)
            ->assertJsonPath('production_loop_smoke.kernel_only', true)
            ->assertJsonPath('auth_contract.internal_api.middleware', 'atlas.token')
            ->assertJsonPath('runtime_invocation_contract.schema_version', 'atlas.runtime_invocation_contract.v1')
            ->assertJsonPath('runtime_invocation_contract.selected_runtime_family', 'python_ai_data')
            ->assertJsonPath('runtime_invocation_contract.runtime_id', 'livekit_agents_sdk')
            ->assertJson(fn ($json) => $json
                ->has('contract_hash')
                ->where('required_env.0', 'ATLAS_BASE_URL')
                ->where('required_env.5', 'ATLAS_VOICE_BOOTSTRAP')
                ->where('forbidden_capabilities.0', 'direct_llm_provider_call')
                ->has('required_runtime_behaviors')
                ->etc()
            );
    }

    public function test_voice_runtime_bootstrap_sanitizes_base_url_before_manifest_publication(): void
    {
        config()->set('app.url', 'http://atlas.test');

        $this->getJson('/ai/voice/runtime/bootstrap?runtime=livekit_agents_sdk&base_url='.urlencode("http://atlas.test\nLIVEKIT_API_SECRET=injected"), $this->headers)
            ->assertOk()
            ->assertJsonPath('schema_version', 'atlas.voice_realtime.runtime_bootstrap.v1')
            ->assertJsonPath('kernel.base_url', 'http://atlas.test')
            ->assertJsonPath('kernel.session_start_url', 'http://atlas.test/ai/voice/session/start')
            ->assertJsonMissingPath('kernel.LIVEKIT_API_SECRET');
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
        $this->assertSame(24, data_get($transcribed->payload, 'voice.transcript_length'));
        $this->assertStringNotContainsString('corrija o teste quebrado', json_encode($transcribed->payload, JSON_THROW_ON_ERROR));
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
            ->assertJsonPath('turn.interruption_source', 'operator')
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

    public function test_voice_runtime_interruption_callback_requires_accepted_kernel_turn(): void
    {
        $this->postJson('/ai/voice/turn/interrupted', [
            'session_id' => 'voice_session_runtime_interrupt',
            'envelope_id' => 'env_voice_runtime_interrupt',
            'receipt_id' => 'receipt_voice_runtime_interrupt',
            'turn_id' => 'voice_turn_runtime_interrupt',
            'runtime' => 'livekit_agents_sdk',
            'interruption_source' => 'runtime_callback',
            'reason' => 'barge_in',
            'interrupted_stage' => 'tts_streaming',
        ], $this->headers)
            ->assertOk()
            ->assertJsonPath('status', 'callback_rejected_missing_kernel_turn')
            ->assertJsonPath('turn.event_type', LedgerEventType::VoiceTurnInterrupted->value)
            ->assertJsonPath('turn.interruption_source', 'runtime_callback')
            ->assertJsonPath('turn.accepted_kernel_turn_required', true)
            ->assertJsonPath('turn.raw_audio_persisted', false)
            ->assertJsonPath('turn.raw_text_persisted', false)
            ->assertJsonPath('evidence_ledger.event_type', LedgerEventType::VoiceRuntimeFailed->value);

        $this->assertDatabaseHas('atlas_ledger_events', [
            'event_type' => LedgerEventType::VoiceRuntimeFailed->value,
            'envelope_id' => 'env_voice_runtime_interrupt',
            'receipt_id' => 'receipt_voice_runtime_interrupt',
        ]);
        $this->assertDatabaseMissing('atlas_ledger_events', [
            'event_type' => LedgerEventType::VoiceTurnInterrupted->value,
            'envelope_id' => 'env_voice_runtime_interrupt',
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

        $turn = $this->postJson('/ai/voice/turn', $base + [
            'audio_hash' => hash('sha256', 'runtime-callback-audio'),
            'transcript' => 'responda por voz',
            'turn_to_first_audio_ms' => 420,
        ], $this->headers)->assertOk();
        $base['envelope_id'] = (string) $turn->json('turn.operation_envelope.envelope_id');
        $base['receipt_id'] = (string) $turn->json('turn.decision_receipt.receipt_id');

        $this->postJson('/ai/voice/turn/synthesized', $base + [
            'response_text_hash' => hash('sha256', 'resposta falada sensivel'),
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

        $this->postJson('/ai/voice/turn/interrupted', $base + [
            'interruption_source' => 'runtime_callback',
            'reason' => 'barge_in',
            'interrupted_stage' => 'tts_streaming',
            'latency_ms' => 65,
        ], $this->headers)
            ->assertOk()
            ->assertJsonPath('status', 'turn_interrupted_recorded')
            ->assertJsonPath('turn.interruption_source', 'runtime_callback')
            ->assertJsonPath('evidence_ledger.interrupted.event_type', LedgerEventType::VoiceTurnInterrupted->value);

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
            LedgerEventType::VoiceTurnInterrupted,
            LedgerEventType::VoiceRuntimeFailed,
            LedgerEventType::VoiceProviderHealthDegraded,
        ] as $type) {
            $this->assertDatabaseHas('atlas_ledger_events', [
                'event_type' => $type->value,
                'envelope_id' => $base['envelope_id'],
                'receipt_id' => $base['receipt_id'],
            ]);
        }

        $synthesized = AtlasLedgerEvent::query()
            ->where('event_type', LedgerEventType::VoiceTurnSynthesized->value)
            ->where('envelope_id', $base['envelope_id'])
            ->firstOrFail();

        $this->assertArrayNotHasKey('response_text', data_get($synthesized->payload, 'voice'));
        $this->assertSame(hash('sha256', 'resposta falada sensivel'), data_get($synthesized->payload, 'voice.response_text_hash'));
    }

    public function test_voice_runtime_callbacks_fail_closed_without_accepted_kernel_turn(): void
    {
        $this->postJson('/ai/voice/turn/synthesized', [
            'session_id' => 'voice_session_orphan_callback',
            'envelope_id' => 'env_voice_orphan_callback',
            'receipt_id' => 'receipt_voice_orphan_callback',
            'turn_id' => 'voice_turn_orphan_callback',
            'runtime' => 'livekit_agents_sdk',
            'response_text_hash' => hash('sha256', 'orphan text'),
            'audio_hash' => hash('sha256', 'orphan-audio'),
        ], $this->headers)
            ->assertOk()
            ->assertJsonPath('status', 'callback_rejected_missing_kernel_turn')
            ->assertJsonPath('turn.accepted_kernel_turn_required', true)
            ->assertJsonPath('turn.raw_audio_persisted', false)
            ->assertJsonPath('turn.raw_text_persisted', false)
            ->assertJsonPath('evidence_ledger.event_type', LedgerEventType::VoiceRuntimeFailed->value);

        $this->assertDatabaseMissing('atlas_ledger_events', [
            'event_type' => LedgerEventType::VoiceTurnSynthesized->value,
            'envelope_id' => 'env_voice_orphan_callback',
        ]);
        $this->assertDatabaseHas('atlas_ledger_events', [
            'event_type' => LedgerEventType::VoiceRuntimeFailed->value,
            'envelope_id' => 'env_voice_orphan_callback',
            'receipt_id' => 'receipt_voice_orphan_callback',
        ]);

        $failure = AtlasLedgerEvent::query()
            ->where('event_type', LedgerEventType::VoiceRuntimeFailed->value)
            ->where('envelope_id', 'env_voice_orphan_callback')
            ->firstOrFail();

        $this->assertSame('kernel_turn_not_accepted', data_get($failure->payload, 'voice.failure_code'));
        $this->assertSame(LedgerEventType::VoiceTurnSynthesized->value, data_get($failure->payload, 'voice.rejected_callback_event_type'));
        $this->assertTrue(data_get($failure->payload, 'voice.requires_voice_turn_decided'));

        $this->postJson('/ai/voice/runtime/failed', [
            'session_id' => 'voice_session_orphan_runtime_failed',
            'envelope_id' => 'env_voice_orphan_runtime_failed',
            'receipt_id' => 'receipt_voice_orphan_runtime_failed',
            'turn_id' => 'voice_turn_orphan_runtime_failed',
            'runtime' => 'livekit_agents_sdk',
            'failure_code' => 'sdk_reported_without_kernel_turn',
        ], $this->headers)
            ->assertOk()
            ->assertJsonPath('status', 'callback_rejected_missing_kernel_turn')
            ->assertJsonPath('turn.accepted_kernel_turn_required', true)
            ->assertJsonPath('turn.raw_audio_persisted', false)
            ->assertJsonPath('turn.raw_text_persisted', false)
            ->assertJsonPath('evidence_ledger.event_type', LedgerEventType::VoiceRuntimeFailed->value);

        $this->assertDatabaseMissing('atlas_ledger_events', [
            'event_type' => LedgerEventType::VoiceRuntimeFailed->value,
            'envelope_id' => 'env_voice_orphan_runtime_failed',
            'receipt_id' => 'receipt_voice_orphan_runtime_failed',
            'payload->voice->failure_code' => 'sdk_reported_without_kernel_turn',
        ]);

        $orphanRuntimeFailure = AtlasLedgerEvent::query()
            ->where('event_type', LedgerEventType::VoiceRuntimeFailed->value)
            ->where('envelope_id', 'env_voice_orphan_runtime_failed')
            ->firstOrFail();

        $this->assertSame('kernel_turn_not_accepted', data_get($orphanRuntimeFailure->payload, 'voice.failure_code'));
        $this->assertSame(LedgerEventType::VoiceRuntimeFailed->value, data_get($orphanRuntimeFailure->payload, 'voice.rejected_callback_event_type'));
    }

    public function test_voice_runtime_callbacks_fail_closed_when_payload_contract_is_invalid(): void
    {
        $base = [
            'session_id' => 'voice_session_invalid_callback',
            'envelope_id' => 'env_voice_invalid_callback',
            'receipt_id' => 'receipt_voice_invalid_callback',
            'turn_id' => 'voice_turn_invalid_callback',
            'runtime' => 'livekit_agents_sdk',
        ];

        $turn = $this->postJson('/ai/voice/turn', $base + [
            'audio_hash' => hash('sha256', 'invalid-callback-audio'),
            'transcript' => 'prepare a callback',
        ], $this->headers)->assertOk();
        $base['envelope_id'] = (string) $turn->json('turn.operation_envelope.envelope_id');
        $base['receipt_id'] = (string) $turn->json('turn.decision_receipt.receipt_id');

        $this->postJson('/ai/voice/provider/health-degraded', $base + [
            'reason' => 'latency_p95_breach',
        ], $this->headers)
            ->assertOk()
            ->assertJsonPath('status', 'callback_rejected_payload_contract')
            ->assertJsonPath('turn.payload_contract_valid', false)
            ->assertJsonPath('turn.violations.0', 'missing_required_field:provider')
            ->assertJsonPath('turn.raw_audio_persisted', false)
            ->assertJsonPath('turn.raw_text_persisted', false)
            ->assertJsonPath('evidence_ledger.event_type', LedgerEventType::VoiceRuntimeFailed->value);

        $this->assertDatabaseMissing('atlas_ledger_events', [
            'event_type' => LedgerEventType::VoiceProviderHealthDegraded->value,
            'envelope_id' => $base['envelope_id'],
        ]);

        $failure = AtlasLedgerEvent::query()
            ->where('event_type', LedgerEventType::VoiceRuntimeFailed->value)
            ->where('envelope_id', $base['envelope_id'])
            ->where('receipt_id', $base['receipt_id'])
            ->firstOrFail();

        $this->assertSame('runtime_callback_payload_contract_violation', data_get($failure->payload, 'voice.failure_code'));
        $this->assertSame(LedgerEventType::VoiceProviderHealthDegraded->value, data_get($failure->payload, 'voice.rejected_callback_event_type'));
        $this->assertContains('missing_required_field:provider', data_get($failure->payload, 'voice.violations'));
        $this->assertArrayNotHasKey('reason', data_get($failure->payload, 'voice'));
        $this->assertArrayNotHasKey('provider_api_key', data_get($failure->payload, 'voice'));
    }

    public function test_voice_runtime_service_rejects_nested_sensitive_callback_fields(): void
    {
        /** @var AtlasVoiceRealtimeService $voice */
        $voice = app(AtlasVoiceRealtimeService::class);
        $base = [
            'session_id' => 'voice_session_nested_secret',
            'envelope_id' => 'env_voice_nested_secret',
            'receipt_id' => 'receipt_voice_nested_secret',
            'turn_id' => 'voice_turn_nested_secret',
            'runtime' => 'livekit_agents_sdk',
        ];

        $turn = $voice->handleTurn($base + [
            'audio_hash' => hash('sha256', 'nested-secret-audio'),
            'transcript' => 'prepare nested callback',
        ]);

        $base['envelope_id'] = (string) data_get($turn, 'turn.operation_envelope.envelope_id');
        $base['receipt_id'] = (string) data_get($turn, 'turn.decision_receipt.receipt_id');

        $result = $voice->recordProviderHealth($base + [
            'provider' => 'deepgram',
            'reason' => 'latency_p95_breach',
            'metadata' => [
                'provider_api_key' => 'sk-redacted',
            ],
        ]);

        $this->assertSame('callback_rejected_payload_contract', $result['status']);
        $this->assertContains('prohibited_field_present:metadata.provider_api_key', data_get($result, 'turn.violations'));
        $this->assertSame(LedgerEventType::VoiceRuntimeFailed->value, data_get($result, 'evidence_ledger.event_type'));

        $this->assertDatabaseMissing('atlas_ledger_events', [
            'event_type' => LedgerEventType::VoiceProviderHealthDegraded->value,
            'envelope_id' => $base['envelope_id'],
        ]);

        $failure = AtlasLedgerEvent::query()
            ->where('event_type', LedgerEventType::VoiceRuntimeFailed->value)
            ->where('envelope_id', $base['envelope_id'])
            ->firstOrFail();

        $this->assertSame('runtime_callback_payload_contract_violation', data_get($failure->payload, 'voice.failure_code'));
        $this->assertContains('prohibited_field_present:metadata.provider_api_key', data_get($failure->payload, 'voice.violations'));
        $this->assertNull(data_get($failure->payload, 'voice.metadata.provider_api_key'));
    }

    public function test_voice_readiness_reports_scorecard_from_ledger_events(): void
    {
        $base = [
            'session_id' => 'voice_session_ready',
            'envelope_id' => 'env_voice_ready',
            'receipt_id' => 'receipt_voice_ready',
            'turn_id' => 'voice_turn_ready',
        ];

        $this->postJson('/ai/voice/session/start', $base, $this->headers)->assertOk();
        $turn = $this->postJson('/ai/voice/turn', $base + [
            'audio_hash' => hash('sha256', 'ready-audio'),
            'transcript' => 'teste readiness',
            'turn_to_first_audio_ms' => 320,
        ], $this->headers)->assertOk();
        $envelopeId = (string) $turn->json('turn.operation_envelope.envelope_id');
        $this->postJson('/ai/voice/turn/synthesized', $base + [
            'envelope_id' => $envelopeId,
            'response_text_hash' => hash('sha256', 'ok'),
        ], $this->headers)->assertOk();
        $this->postJson('/ai/voice/turn/played', $base + [
            'envelope_id' => $envelopeId,
            'played_duration_ms' => 250,
        ], $this->headers)->assertOk();

        $this->getJson('/ai/voice/readiness?hours=1', $this->headers)
            ->assertOk()
            ->assertJsonPath('schema_version', 'atlas.voice.readiness.v1')
            ->assertJsonPath('status', 'ready')
            ->assertJsonPath('score', 100)
            ->assertJsonPath('gates.required_events_present', true)
            ->assertJsonPath('gates.latency_slo_clean', true)
            ->assertJsonPath('gates.rivals_voice_ready', true)
            ->assertJsonPath('product_loop_check.schema_version', 'atlas.voice_realtime.product_loop_check_reference.v1')
            ->assertJsonPath('product_loop_check.status', 'available_as_runtime_contract')
            ->assertJsonPath('product_loop_check.command', 'php artisan atlas:ai:voice product-loop-check --json')
            ->assertJsonPath('product_loop_check.promotion_allowed', false)
            ->assertJsonPath('product_loop_check.auto_promotion_allowed', false)
            ->assertJsonPath('product_loop_check.daemon_started', false)
            ->assertJsonPath('product_loop_check.required_gates.4', 'sdk_probe_import_safe')
            ->assertJsonPath('review_signal.recommended_action', 'voice_readiness_can_enter_rivals_voice');
    }

    public function test_voice_rivals_report_requires_baseline_after_readiness_is_ready(): void
    {
        $base = [
            'session_id' => 'voice_session_rivals',
            'envelope_id' => 'env_voice_rivals',
            'receipt_id' => 'receipt_voice_rivals',
            'turn_id' => 'voice_turn_rivals',
        ];

        $this->postJson('/ai/voice/session/start', $base, $this->headers)->assertOk();
        $turn = $this->postJson('/ai/voice/turn', $base + [
            'audio_hash' => hash('sha256', 'rivals-audio'),
            'transcript' => 'teste rivals voice',
            'turn_to_first_audio_ms' => 300,
        ], $this->headers)->assertOk();
        $envelopeId = (string) $turn->json('turn.operation_envelope.envelope_id');
        $this->postJson('/ai/voice/turn/synthesized', $base + [
            'envelope_id' => $envelopeId,
            'response_text_hash' => hash('sha256', 'ok'),
        ], $this->headers)->assertOk();
        $this->postJson('/ai/voice/turn/played', $base + [
            'envelope_id' => $envelopeId,
            'played_duration_ms' => 250,
        ], $this->headers)->assertOk();

        $response = $this->getJson('/ai/voice/rivals?hours=1', $this->headers)
            ->assertOk()
            ->assertJsonPath('schema_version', 'atlas.voice.rivals.v1')
            ->assertJsonPath('status', 'not_ready')
            ->assertJsonPath('readiness.status', 'ready')
            ->assertJsonPath('runtime_certification.status', 'certified_scaffold')
            ->assertJsonPath('runtime_certification.summary.failed_gates', 0)
            ->assertJsonPath('runtime_certification.artifact_sanitization.passed', true)
            ->assertJsonPath('runtime_certification.product_loop_check.schema_version', 'atlas.voice_realtime.product_loop_check.v1')
            ->assertJsonPath('runtime_certification.product_loop_check.daemon_started', false)
            ->assertJsonPath('runtime_certification.product_loop_gate.passed', true)
            ->assertJsonPath('runtime_certification.product_loop_gate.sdk_probe_import_safe', true)
            ->assertJsonPath('runtime_certification.product_loop_gate.sdk_handler_blueprint_available', true)
            ->assertJsonPath('runtime_certification.product_loop_gate.production_promotion_blocked', true)
            ->assertJsonPath('production_promotion_gate.status', 'blocked')
            ->assertJsonPath('production_promotion_gate.human_review_required', true)
            ->assertJsonPath('production_promotion_gate.promotion_allowed', false)
            ->assertJsonPath('production_promotion_gate.auto_promotion_allowed', false)
            ->assertJsonPath('arms.atlas_voice.completed_turn_count', 1)
            ->assertJsonPath('arms.direct_provider_baseline.completed_turn_count', 0)
            ->assertJsonPath('comparison.comparable_turn_count', 0)
            ->assertJsonPath('review_signal.recommended_action', 'rerun_runtime_certification_with_require_sdk')
            ->assertJsonPath('review_signal.promotion_allowed', false)
            ->assertJsonPath('review_signal.review_packet.schema_version', 'atlas.voice_realtime.production_promotion_review_packet.v1')
            ->assertJsonPath('review_signal.review_packet.required_rollback_plan.0', 'disable_livekit_token_issuer')
            ->assertJsonPath('review_signal.reasons.0', 'voice_production_promotion_gate_blocked');

        $this->assertContains($response->json('runtime_certification.product_loop_check.status'), ['blocked', 'ready_for_human_review']);
    }

    public function test_voice_rivals_report_accepts_direct_provider_baseline_arm(): void
    {
        $atlasBase = [
            'session_id' => 'voice_session_atlas_arm',
            'envelope_id' => 'env_voice_atlas_arm',
            'receipt_id' => 'receipt_voice_atlas_arm',
            'turn_id' => 'voice_turn_atlas_arm',
        ];
        $baselineBase = [
            'session_id' => 'voice_session_baseline_arm',
            'envelope_id' => 'env_voice_baseline_arm',
            'receipt_id' => 'receipt_voice_baseline_arm',
            'turn_id' => 'voice_turn_baseline_arm',
            'rivals_arm' => 'direct_provider_baseline',
        ];

        foreach ([$atlasBase, $baselineBase] as $base) {
            $this->postJson('/ai/voice/session/start', $base, $this->headers)->assertOk();
            $turn = $this->postJson('/ai/voice/turn', $base + [
                'audio_hash' => hash('sha256', $base['turn_id'].'-audio'),
                'transcript' => 'teste arm',
                'turn_to_first_audio_ms' => $base === $baselineBase ? 450 : 320,
            ], $this->headers)->assertOk();
            $envelopeId = (string) $turn->json('turn.operation_envelope.envelope_id');
            $this->postJson('/ai/voice/turn/synthesized', $base + [
                'envelope_id' => $envelopeId,
                'response_text_hash' => hash('sha256', 'ok'),
            ], $this->headers)->assertOk();
            $this->postJson('/ai/voice/turn/played', $base + [
                'envelope_id' => $envelopeId,
                'played_duration_ms' => 250,
            ], $this->headers)->assertOk();
        }

        $this->getJson('/ai/voice/rivals?hours=1', $this->headers)
            ->assertOk()
            ->assertJsonPath('schema_version', 'atlas.voice.rivals.v1')
            ->assertJsonPath('status', 'not_ready')
            ->assertJsonPath('runtime_certification.status', 'certified_scaffold')
            ->assertJsonPath('production_promotion_gate.status', 'blocked')
            ->assertJsonPath('production_promotion_gate.promotion_allowed', false)
            ->assertJsonPath('production_promotion_gate.review_packet.schema_version', 'atlas.voice_realtime.production_promotion_review_packet.v1')
            ->assertJsonPath('arms.atlas_voice.completed_turn_count', 1)
            ->assertJsonPath('arms.direct_provider_baseline.completed_turn_count', 1)
            ->assertJsonPath('comparison.comparable_turn_count', 1)
            ->assertJsonPath('review_signal.recommended_action', 'rerun_runtime_certification_with_require_sdk')
            ->assertJsonPath('review_signal.promotion_allowed', false)
            ->assertJsonPath('review_signal.review_packet.forbidden_actions.0', 'auto_promote_voice_runtime');

        $baselineEvent = AtlasLedgerEvent::query()
            ->where('event_type', LedgerEventType::VoiceTurnPlayed->value)
            ->where('correlation_id', 'voice_session_baseline_arm')
            ->firstOrFail();

        $this->assertSame('direct_provider_baseline', data_get($baselineEvent->payload, 'voice.rivals_arm'));
    }

    public function test_voice_rivals_report_blocks_when_runtime_certification_is_required_but_sdk_is_missing(): void
    {
        $base = [
            'session_id' => 'voice_session_cert_required',
            'envelope_id' => 'env_voice_cert_required',
            'receipt_id' => 'receipt_voice_cert_required',
            'turn_id' => 'voice_turn_cert_required',
        ];

        $this->postJson('/ai/voice/session/start', $base, $this->headers)->assertOk();
        $turn = $this->postJson('/ai/voice/turn', $base + [
            'audio_hash' => hash('sha256', 'cert-required-audio'),
            'transcript' => 'teste rivals voice com certificacao obrigatoria',
            'turn_to_first_audio_ms' => 300,
        ], $this->headers)->assertOk();
        $envelopeId = (string) $turn->json('turn.operation_envelope.envelope_id');
        $this->postJson('/ai/voice/turn/synthesized', $base + [
            'envelope_id' => $envelopeId,
            'response_text_hash' => hash('sha256', 'ok'),
        ], $this->headers)->assertOk();
        $this->postJson('/ai/voice/turn/played', $base + [
            'envelope_id' => $envelopeId,
            'played_duration_ms' => 250,
        ], $this->headers)->assertOk();

        $response = $this->getJson('/ai/voice/rivals?hours=1&require_sdk=1', $this->headers)
            ->assertOk()
            ->assertJsonPath('schema_version', 'atlas.voice.rivals.v1')
            ->assertJsonPath('status', 'not_ready')
            ->assertJsonPath('readiness.status', 'ready')
            ->assertJsonPath('runtime_certification.sdk_required_for_certification', true);

        $this->assertContains($response->json('runtime_certification.status'), ['failed', 'certified_scaffold']);
        $this->assertContains($response->json('review_signal.recommended_action'), [
            'fix_voice_runtime_certification_before_rivals_voice',
            'attach_decision_receipt_and_operator_review_file',
            'rerun_runtime_certification_with_require_sdk',
            'configure_livekit_token_issuer',
        ]);
    }

    public function test_voice_readiness_warns_when_required_events_are_missing(): void
    {
        $this->postJson('/ai/voice/session/start', [
            'session_id' => 'voice_session_incomplete',
            'envelope_id' => 'env_voice_incomplete',
        ], $this->headers)->assertOk();

        $this->getJson('/ai/voice/readiness?hours=1', $this->headers)
            ->assertOk()
            ->assertJsonPath('schema_version', 'atlas.voice.readiness.v1')
            ->assertJsonPath('status', 'attention')
            ->assertJsonPath('gates.required_events_present', false)
            ->assertJsonPath('product_loop_check.command', 'php artisan atlas:ai:voice product-loop-check --json')
            ->assertJsonPath('review_signal.recommended_action', 'complete_voice_required_events_before_rivals_voice');
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

    public function test_voice_turn_rejects_non_sha256_audio_hash(): void
    {
        $this->postJson('/ai/voice/turn', [
            'session_id' => 'voice_session_bad_hash',
            'turn_id' => 'voice_turn_bad_hash',
            'audio_hash' => 'not-a-sha256-hash',
        ], $this->headers)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['audio_hash']);
    }

    public function test_voice_session_rejects_token_or_api_secret_payloads(): void
    {
        foreach (['access_token', 'token', 'livekit_token', 'api_key', 'api_secret'] as $key) {
            $this->postJson('/ai/voice/session/start', [
                'session_id' => 'voice_session_secret_'.$key,
                $key => 'do-not-accept',
            ], $this->headers)
                ->assertUnprocessable()
                ->assertJsonValidationErrors([$key]);
        }
    }

    public function test_voice_wake_word_rejects_raw_audio_payloads(): void
    {
        $this->postJson('/ai/voice/wake-word', [
            'session_id' => 'voice_session_raw_wake',
            'audio_bytes' => 'do-not-accept',
        ], $this->headers)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['audio_bytes']);
    }

    public function test_voice_synthesis_rejects_raw_response_text_payloads(): void
    {
        foreach (['response_text', 'raw_response_text', 'tts_text'] as $key) {
            $this->postJson('/ai/voice/turn/synthesized', [
                'session_id' => 'voice_session_raw_tts_'.$key,
                'turn_id' => 'voice_turn_raw_tts',
                $key => 'do-not-accept',
            ], $this->headers)
                ->assertUnprocessable()
                ->assertJsonValidationErrors([$key]);
        }
    }

    public function test_voice_synthesis_requires_valid_hash_payload(): void
    {
        $this->postJson('/ai/voice/turn/synthesized', [
            'session_id' => 'voice_session_missing_hash',
            'turn_id' => 'voice_turn_missing_hash',
        ], $this->headers)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['response_text_hash', 'audio_hash']);

        $this->postJson('/ai/voice/turn/synthesized', [
            'session_id' => 'voice_session_bad_tts_hash',
            'turn_id' => 'voice_turn_bad_tts_hash',
            'response_text_hash' => 'not-a-sha256-hash',
        ], $this->headers)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['response_text_hash']);
    }

    public function test_voice_api_rejects_unknown_runtime_surface_transport_or_privacy_class(): void
    {
        foreach ([
            'runtime' => 'direct_runtime',
            'client_surface' => 'unknown_surface',
            'transport' => 'raw_udp',
            'privacy_class' => 'privateish',
        ] as $key => $value) {
            $this->postJson('/ai/voice/session/start', [
                'session_id' => 'voice_session_bad_'.$key,
                $key => $value,
            ], $this->headers)
                ->assertUnprocessable()
                ->assertJsonValidationErrors([$key]);
        }
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

    /**
     * @return array<string,mixed>
     */
    private function decodeJwtClaims(string $jwt): array
    {
        $segments = explode('.', $jwt);
        $this->assertCount(3, $segments);
        $payload = $segments[1];
        $payload .= str_repeat('=', (4 - strlen($payload) % 4) % 4);
        $decoded = json_decode((string) base64_decode(strtr($payload, '-_', '+/')), true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);

        return $decoded;
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
