<?php

namespace Tests\Feature\Ai;

use App\Models\AtlasLedgerEvent;
use App\Models\AtlasMobileDevice;
use App\Models\Capture;
use App\Models\SemanticNote;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use App\Services\Ai\Mobile\MobilePairingService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AtlasConstelacaoPositionsApiTest extends TestCase
{
    private array $headers = ['X-Atlas-Token' => 'test-token-with-enough-length-123'];

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('atlas.token', 'test-token-with-enough-length-123');

        Schema::dropIfExists('atlas_ledger_events');
        Schema::dropIfExists('atlas_mobile_devices');
        Schema::dropIfExists('ai_attachment_index_entries');
        Schema::dropIfExists('semantic_notes');
        Schema::dropIfExists('captures');

        (require database_path('migrations/2026_05_05_020000_create_atlas_ledger_events_table.php'))->up();
        $this->createSemanticNotesTable();
        $this->createCapturesTable();
        $this->createMobileDeviceTable();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('atlas_ledger_events');
        Schema::dropIfExists('atlas_mobile_devices');
        Schema::dropIfExists('ai_attachment_index_entries');
        Schema::dropIfExists('semantic_notes');
        Schema::dropIfExists('captures');

        parent::tearDown();
    }

    public function test_positions_endpoint_returns_governed_constelacao_payload_without_raw_content(): void
    {
        SemanticNote::query()->create([
            'note_key' => 'programming-pattern',
            'path' => '02-modelos/programming-pattern.md',
            'title' => 'Programming Pattern',
            'type' => 'mental_model',
            'status' => 'active',
            'confidence' => 'high',
            'maturity' => 'useful',
            'domains' => ['programming'],
            'summary' => 'Resumo seguro',
            'body_excerpt' => 'conteudo sensivel que nao pode sair',
            'content_hash' => hash('sha256', 'programming-pattern'),
            'activation_count' => 4,
            'usefulness_avg' => 4.5,
            'metadata' => ['secret' => 'do-not-return'],
            'indexed_at' => now(),
        ]);

        Capture::query()->create([
            'client_id' => (string) Str::uuid(),
            'kind' => 'text',
            'domain' => 'programming',
            'content_text' => 'texto bruto que nao pode sair',
            'transcription_status' => 'na',
            'captured_at' => now(),
            'captured_timezone' => 'America/Sao_Paulo',
            'metadata' => ['title' => 'Programming Capture', 'secret' => 'do-not-return'],
        ]);

        $response = $this->getJson('/atlas/celestial/positions?domain=programming&limit=10', $this->headers)
            ->assertOk()
            ->assertJsonPath('schema_version', 'atlas.constelacao.positions.v1')
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('surface_id', 'constelacao')
            ->assertJsonPath('lens', 'bilderatlas')
            ->assertJsonPath('lens_maturity_gate.schema_version', 'atlas.constelacao.lens_maturity_gate.v1')
            ->assertJsonPath('lens_maturity_gate.current_lens', 'bilderatlas')
            ->assertJsonPath('lens_maturity_gate.promotion_allowed', false)
            ->assertJsonPath('lens_maturity_gate.lens2_promotion_allowed', false)
            ->assertJsonPath('lens_maturity_gate.command_sky_allowed', false)
            ->assertJsonPath('lens_maturity_gate.graph_rag_positioning_allowed', false)
            ->assertJsonPath('lens_maturity_gate.observation_window_days_required', 30)
            ->assertJsonPath('lens_maturity_gate.minimum_review_event_families.0', 'constelacao_opened')
            ->assertJsonPath('lens_maturity_gate.requires_human_review', true)
            ->assertJsonPath('lens_maturity_gate.requires_decision_receipt', true)
            ->assertJsonPath('lens_maturity_gate.next_action', 'collect_constelacao_lens1_usage_telemetry_for_30_days_before_review')
            ->assertJsonPath('lens1_usage_review_contract.schema_version', 'atlas.constelacao.lens1_usage_review.v1')
            ->assertJsonPath('lens1_usage_review_contract.status', 'observation_required')
            ->assertJsonPath('lens1_usage_review_contract.promotion_allowed', false)
            ->assertJsonPath('lens1_usage_review_contract.human_review_required', true)
            ->assertJsonPath('lens1_usage_review_contract.curator_review_required', true)
            ->assertJsonPath('lens1_usage_review_contract.decision_receipt_required', true)
            ->assertJsonPath('lens1_usage_review_contract.required_human_decision', 'approve_or_reject_constelacao_lens1_promotion_after_usage_review')
            ->assertJsonPath('lens1_usage_review_contract.rollback_plan_required', true)
            ->assertJsonPath('lens1_usage_review_contract.policy_patch_review_required', true)
            ->assertJsonPath('lens1_usage_review_contract.auto_promotion_allowed', false)
            ->assertJsonPath('lens1_usage_review_contract.rollback_required.0', 'keep_lens_bilderatlas_only')
            ->assertJsonPath('lens1_usage_review_contract.forbidden_until_review.1', 'enable_command_sky')
            ->assertJsonPath('lens1_usage_review_contract.forbidden_until_review.3', 'enable_graph_rag_positioning')
            ->assertJsonPath('lens1_usage_review_contract.blocked_targets.0', 'lens2')
            ->assertJsonPath('lens1_usage_review_contract.blocked_targets.1', 'command_sky')
            ->assertJsonPath('lens1_usage_review_contract.blocked_targets.3', 'graph_rag_positioning')
            ->assertJsonPath('lens1_usage_review_contract.future_runtime_invocation_contract.schema_version', 'atlas.runtime_invocation_contract.v1')
            ->assertJsonPath('lens1_usage_review_contract.future_runtime_invocation_contract.selected_runtime_family', 'python_ai_data')
            ->assertJsonPath('lens1_usage_review_contract.future_runtime_invocation_contract.runtime_id', 'constelacao_lens_future_candidate')
            ->assertJsonPath('lens1_usage_review_contract.future_runtime_invocation_contract.promotion_allowed_now', false)
            ->assertJsonPath('lens1_usage_review_contract.future_runtime_invocation_contract.auto_enable_allowed_now', false)
            ->assertJsonPath('lens1_usage_review_contract.required_telemetry.0', 'constelacao_opened')
            ->assertJsonPath('lens1_usage_review_contract.next_action', 'collect_constelacao_lens1_usage_telemetry_for_30_days_before_review')
            ->assertJsonPath('ui_contract.schema_version', 'atlas.constelacao.ui_contract.v1')
            ->assertJsonPath('ui_contract.lens_role', 'contemplative_serendipity')
            ->assertJsonPath('ui_contract.operational_chrome_allowed', false)
            ->assertJsonPath('ui_contract.default_interaction', 'tap_star_opens_existing_detail_sheet')
            ->assertJsonPath('ui_contract.raw_reading_allowed', false)
            ->assertJsonPath('ui_contract.telemetry.privacy_class', 'p2_metadata')
            ->assertJsonPath('ui_contract.telemetry.raw_content_allowed', false)
            ->assertJsonPath('ui_contract.telemetry.required_events.0', 'constelacao_opened')
            ->assertJsonPath('ui_contract.telemetry.required_events.1', 'constelacao_backend_loaded')
            ->assertJsonPath('ui_contract.telemetry.required_events.2', 'constelacao_backend_failed')
            ->assertJsonPath('ui_contract.telemetry.required_events.3', 'constelacao_star_tapped')
            ->assertJsonPath('position_engine.source', 'deterministic_semantic_fallback')
            ->assertJsonPath('position_engine.semantic_positioning_mode', 'fallback_until_promotion_gate_passes')
            ->assertJsonPath('position_engine.graph_rag_status', 'future_governed')
            ->assertJsonPath('position_engine.fallback_active', true)
            ->assertJsonPath('position_engine.semantic_positioning_readiness.schema_version', 'atlas.constelacao.semantic_positioning_readiness.v1')
            ->assertJsonPath('position_engine.semantic_positioning_readiness.status', 'degraded')
            ->assertJsonPath('position_engine.semantic_positioning_readiness.vector_ready', true)
            ->assertJsonPath('position_engine.semantic_positioning_readiness.graph_rag_status', 'future_governed')
            ->assertJsonPath('position_engine.semantic_positioning_readiness.provider_bypass_allowed', false)
            ->assertJsonPath('position_engine.semantic_positioning_readiness.parallel_memory_allowed', false)
            ->assertJsonPath('position_engine.semantic_positioning_readiness.promotion_gate.schema_version', 'atlas.constelacao.semantic_positioning_promotion_gate.v1')
            ->assertJsonPath('position_engine.semantic_positioning_readiness.promotion_gate.vector_positioning_allowed', false)
            ->assertJsonPath('position_engine.semantic_positioning_readiness.promotion_gate.graph_rag_promotion_allowed', false)
            ->assertJsonPath('position_engine.semantic_positioning_readiness.promotion_gate.python_runtime_allowed', false)
            ->assertJsonPath('position_engine.semantic_positioning_readiness.promotion_gate.requires_human_review', true)
            ->assertJsonPath('position_engine.semantic_positioning_readiness.promotion_gate.requires_decision_receipt', true)
            ->assertJsonPath('position_engine.semantic_positioning_readiness.promotion_gate.future_runtime_invocation_contract.schema_version', 'atlas.runtime_invocation_contract.v1')
            ->assertJsonPath('position_engine.semantic_positioning_readiness.promotion_gate.future_runtime_invocation_contract.selected_runtime_family', 'python_ai_data')
            ->assertJsonPath('position_engine.semantic_positioning_readiness.promotion_gate.future_runtime_invocation_contract.runtime_id', 'constelacao_semantic_positioning_candidate')
            ->assertJsonPath('privacy.raw_content_exposed', false)
            ->assertJsonPath('privacy.body_excerpt_exposed', false)
            ->assertJsonPath('evidence_ledger.recorded', true)
            ->assertJsonPath('evidence_ledger.event_type', LedgerEventType::ConstelacaoPositionsServed->value);

        $payload = $response->json();
        $this->assertCount(2, $payload['items']);
        $this->assertSame(['capture', 'semantic_note'], collect($payload['items'])->pluck('source_type')->sort()->values()->all());
        $this->assertStringNotContainsString('conteudo sensivel', json_encode($payload, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('texto bruto', json_encode($payload, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('do-not-return', json_encode($payload, JSON_THROW_ON_ERROR));

        foreach ($payload['items'] as $item) {
            $this->assertIsFloat($item['x']);
            $this->assertIsFloat($item['y']);
            $this->assertGreaterThanOrEqual(-1, $item['x']);
            $this->assertLessThanOrEqual(1, $item['x']);
            $this->assertGreaterThanOrEqual(-1, $item['y']);
            $this->assertLessThanOrEqual(1, $item['y']);
            $this->assertTrue($item['preview']['content_redacted']);
            $this->assertSame('deterministic_domain_jitter', $item['position_method']);
        }

        $event = AtlasLedgerEvent::query()
            ->where('event_type', LedgerEventType::ConstelacaoPositionsServed->value)
            ->firstOrFail();

        $this->assertSame('atlas.constelacao_surface', $event->emitter_stage);
        $this->assertSame('p2_metadata', data_get($event->payload, 'privacy_class'));
        $this->assertFalse((bool) data_get($event->payload, 'raw_content_exposed'));
        $this->assertSame('fallback_until_promotion_gate_passes', data_get($event->payload, 'semantic_positioning_mode'));
        $this->assertSame('degraded', data_get($event->payload, 'semantic_readiness_status'));
        $this->assertFalse((bool) data_get($event->payload, 'provider_bypass_allowed'));
        $this->assertFalse((bool) data_get($event->payload, 'parallel_memory_allowed'));
        $this->assertSame('unknown', data_get($event->payload, 'client_surface'));
        $this->assertFalse((bool) data_get($event->payload, 'promotion_gate.graph_rag_promotion_allowed'));
        $this->assertFalse((bool) data_get($event->payload, 'promotion_gate.promotion_allowed'));
        $this->assertTrue((bool) data_get($event->payload, 'promotion_gate.requires_human_review'));
        $this->assertTrue((bool) data_get($event->payload, 'promotion_gate.requires_decision_receipt'));
        $this->assertSame('collect_constelacao_lens1_usage_telemetry_for_30_days_before_review', data_get($event->payload, 'promotion_gate.next_action'));
        $this->assertSame('atlas.constelacao.lens1_usage_review.v1', data_get($event->payload, 'lens1_usage_review_contract.schema_version'));
        $this->assertFalse((bool) data_get($event->payload, 'lens1_usage_review_contract.promotion_allowed'));
        $this->assertFalse((bool) data_get($event->payload, 'lens1_usage_review_contract.auto_promotion_allowed'));
        $this->assertSame('approve_or_reject_constelacao_lens1_promotion_after_usage_review', data_get($event->payload, 'lens1_usage_review_contract.required_human_decision'));
        $this->assertTrue((bool) data_get($event->payload, 'lens1_usage_review_contract.rollback_plan_required'));
        $this->assertContains('keep_graph_rag_positioning_disabled', data_get($event->payload, 'lens1_usage_review_contract.rollback_required'));
        $this->assertContains('enable_command_sky', data_get($event->payload, 'lens1_usage_review_contract.forbidden_until_review'));
        $this->assertSame('atlas.runtime_invocation_contract.v1', data_get($event->payload, 'lens1_usage_review_contract.future_runtime_invocation_contract.schema_version'));
        $this->assertSame('python_ai_data', data_get($event->payload, 'lens1_usage_review_contract.future_runtime_invocation_contract.selected_runtime_family'));
        $this->assertContains('command_sky', data_get($event->payload, 'lens1_usage_review_contract.blocked_targets'));
        $this->assertSame(2, data_get($event->payload, 'item_count'));
        $this->assertStringNotContainsString('conteudo sensivel', json_encode($event->payload, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('texto bruto', json_encode($event->payload, JSON_THROW_ON_ERROR));
    }

    public function test_mobile_positions_route_uses_mobile_bearer_surface(): void
    {
        $token = 'atlas_mobile_constelacao_test_token';
        AtlasMobileDevice::query()->create([
            'user_id' => 'vitor',
            'device_label' => 'Constelacao Test iPhone',
            'platform' => 'ios',
            'device_token_hash' => app(MobilePairingService::class)->hashSecret($token),
            'notification_permissions' => 'granted',
            'last_seen_at' => now(),
            'paired_at' => now(),
            'metadata' => [],
        ]);

        SemanticNote::query()->create([
            'note_key' => 'finance-note',
            'path' => '01-acervo/finance-note.md',
            'title' => 'Finance Note',
            'type' => 'source_note',
            'status' => 'active',
            'confidence' => 'medium',
            'maturity' => 'draft',
            'domains' => ['finance'],
            'content_hash' => hash('sha256', 'finance-note'),
            'metadata' => [],
            'indexed_at' => now(),
        ]);

        $this->getJson('/v1/mobile/atlas/celestial/positions?domain=finance', [
            'Authorization' => 'Bearer '.$token,
        ])
            ->assertOk()
            ->assertJsonPath('schema_version', 'atlas.constelacao.positions.v1')
            ->assertJsonPath('items.0.source_type', 'semantic_note')
            ->assertJsonPath('items.0.cluster_key', 'finance')
            ->assertJsonPath('ui_contract.operational_chrome_allowed', false)
            ->assertJsonPath('evidence_ledger.recorded', true);

        $this->assertDatabaseHas('atlas_ledger_events', [
            'event_type' => LedgerEventType::ConstelacaoPositionsServed->value,
            'operator_id' => 'vitor',
            'emitter_stage' => 'atlas.constelacao_surface',
        ]);

        $event = AtlasLedgerEvent::query()
            ->where('event_type', LedgerEventType::ConstelacaoPositionsServed->value)
            ->latest('id')
            ->firstOrFail();

        $this->assertSame('atlas_app_mobile', data_get($event->payload, 'client_surface'));
    }

    public function test_command_sky_lens_request_is_blocked_until_formal_promotion(): void
    {
        SemanticNote::query()->create([
            'note_key' => 'lens-gate-note',
            'path' => '02-modelos/lens-gate-note.md',
            'title' => 'Lens Gate Note',
            'type' => 'mental_model',
            'status' => 'active',
            'confidence' => 'medium',
            'maturity' => 'draft',
            'domains' => ['programming'],
            'content_hash' => hash('sha256', 'lens-gate-note'),
            'metadata' => [],
            'indexed_at' => now(),
        ]);

        $this->getJson('/atlas/celestial/positions?lens=command_sky&limit=5', $this->headers)
            ->assertOk()
            ->assertJsonPath('lens', 'bilderatlas')
            ->assertJsonPath('requested_lens', 'command_sky')
            ->assertJsonPath('lens_gate.schema_version', 'atlas.constelacao.lens_gate.v1')
            ->assertJsonPath('lens_gate.active_lens', 'bilderatlas')
            ->assertJsonPath('lens_gate.requested_lens', 'command_sky')
            ->assertJsonPath('lens_gate.blocked', true)
            ->assertJsonPath('lens_gate.reason', 'command_sky_requires_future_ap_human_review_and_decision_receipt')
            ->assertJsonPath('ui_contract.lens', 'bilderatlas')
            ->assertJsonPath('ui_contract.requested_lens', 'command_sky')
            ->assertJsonPath('ui_contract.operational_chrome_allowed', false);

        $event = AtlasLedgerEvent::query()
            ->where('event_type', LedgerEventType::ConstelacaoPositionsServed->value)
            ->get()
            ->first(fn (AtlasLedgerEvent $event): bool => data_get($event->payload, 'lens_gate.requested_lens') === 'command_sky');

        $this->assertNotNull($event);

        $this->assertSame('bilderatlas', data_get($event->payload, 'lens_gate.active_lens'));
        $this->assertSame('command_sky', data_get($event->payload, 'lens_gate.requested_lens'));
        $this->assertTrue((bool) data_get($event->payload, 'lens_gate.blocked'));
        $this->assertFalse((bool) data_get($event->payload, 'lens_maturity_gate.command_sky_allowed'));
        $this->assertFalse((bool) data_get($event->payload, 'lens_maturity_gate.lens2_promotion_allowed'));
        $this->assertSame(30, data_get($event->payload, 'lens_maturity_gate.observation_window_days_required'));
        $this->assertSame('atlas.constelacao.lens1_usage_review.v1', data_get($event->payload, 'lens1_usage_review_contract.schema_version'));
        $this->assertFalse((bool) data_get($event->payload, 'lens1_usage_review_contract.auto_promotion_allowed'));
        $this->assertContains('graph_rag_positioning', data_get($event->payload, 'lens1_usage_review_contract.blocked_targets'));
    }

    public function test_unknown_lens_request_is_sanitized_preserved_and_blocked(): void
    {
        SemanticNote::query()->create([
            'note_key' => 'unknown-lens-note',
            'path' => '02-modelos/unknown-lens-note.md',
            'title' => 'Unknown Lens Note',
            'type' => 'mental_model',
            'status' => 'active',
            'confidence' => 'medium',
            'maturity' => 'draft',
            'domains' => ['programming'],
            'content_hash' => hash('sha256', 'unknown-lens-note'),
            'metadata' => [],
            'indexed_at' => now(),
        ]);

        $this->getJson('/atlas/celestial/positions?lens=../../Command Sky!!!&limit=5', $this->headers)
            ->assertOk()
            ->assertJsonPath('lens', 'bilderatlas')
            ->assertJsonPath('requested_lens', 'command_sky')
            ->assertJsonPath('lens_gate.blocked', true)
            ->assertJsonPath('lens_gate.reason', 'command_sky_requires_future_ap_human_review_and_decision_receipt')
            ->assertJsonPath('ui_contract.operational_chrome_allowed', false);

        $this->getJson('/atlas/celestial/positions?lens=graph-rag-admin&limit=5', $this->headers)
            ->assertOk()
            ->assertJsonPath('lens', 'bilderatlas')
            ->assertJsonPath('requested_lens', 'graph-rag-admin')
            ->assertJsonPath('lens_gate.blocked', true)
            ->assertJsonPath('lens_gate.reason', 'unsupported_lens_requires_future_ap_human_review_and_decision_receipt')
            ->assertJsonPath('lens_maturity_gate.graph_rag_positioning_allowed', false);

        $event = AtlasLedgerEvent::query()
            ->where('event_type', LedgerEventType::ConstelacaoPositionsServed->value)
            ->get()
            ->first(fn (AtlasLedgerEvent $event): bool => data_get($event->payload, 'lens_gate.requested_lens') === 'graph-rag-admin');

        $this->assertNotNull($event);

        $this->assertSame('bilderatlas', data_get($event->payload, 'lens_gate.active_lens'));
        $this->assertSame('graph-rag-admin', data_get($event->payload, 'lens_gate.requested_lens'));
        $this->assertTrue((bool) data_get($event->payload, 'lens_gate.blocked'));
        $this->assertSame('unsupported_lens_requires_future_ap_human_review_and_decision_receipt', data_get($event->payload, 'lens_gate.reason'));
        $this->assertFalse((bool) data_get($event->payload, 'promotion_gate.graph_rag_promotion_allowed'));
        $this->assertFalse((bool) data_get($event->payload, 'promotion_gate.python_runtime_allowed'));
    }

    public function test_positions_endpoint_falls_back_to_empty_sky_when_sources_are_missing(): void
    {
        Schema::dropIfExists('semantic_notes');
        Schema::dropIfExists('captures');

        $this->getJson('/atlas/celestial/positions?limit=5', $this->headers)
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('position_engine.fallback_active', true)
            ->assertJsonPath('position_engine.semantic_positioning_readiness.status', 'blocked')
            ->assertJsonPath('position_engine.semantic_positioning_readiness.provider_bypass_allowed', false)
            ->assertJsonPath('privacy.raw_content_exposed', false)
            ->assertJsonCount(0, 'items')
            ->assertJsonPath('evidence_ledger.recorded', true);
    }

    public function test_semantic_positioning_promotion_gate_allows_vector_only_when_local_rag_is_ready(): void
    {
        $this->createAttachmentIndexEntriesTable();

        $this->getJson('/atlas/celestial/positions?limit=5', $this->headers)
            ->assertOk()
            ->assertJsonPath('position_engine.semantic_positioning_readiness.status', 'ready')
            ->assertJsonPath('position_engine.semantic_positioning_readiness.promotion_gate.vector_positioning_allowed', true)
            ->assertJsonPath('position_engine.semantic_positioning_readiness.promotion_gate.graph_rag_promotion_allowed', false)
            ->assertJsonPath('position_engine.semantic_positioning_readiness.promotion_gate.python_runtime_allowed', false)
            ->assertJsonPath('position_engine.semantic_positioning_readiness.promotion_gate.requires_human_review', true)
            ->assertJsonPath('position_engine.semantic_positioning_readiness.promotion_gate.required_evidence.0', LedgerEventType::LocalRagPlanCreated->value)
            ->assertJsonPath('position_engine.semantic_positioning_readiness.promotion_gate.required_evidence.1', LedgerEventType::LocalRagQualityCorpusEvaluated->value)
            ->assertJsonPath('position_engine.semantic_positioning_readiness.promotion_gate.required_evidence.2', LedgerEventType::LocalRagGraphPromotionBlocked->value);

        $event = AtlasLedgerEvent::query()
            ->where('event_type', LedgerEventType::ConstelacaoPositionsServed->value)
            ->latest('id')
            ->firstOrFail();

        $this->assertTrue((bool) data_get($event->payload, 'promotion_gate.vector_positioning_allowed'));
        $this->assertFalse((bool) data_get($event->payload, 'promotion_gate.graph_rag_promotion_allowed'));
        $this->assertFalse((bool) data_get($event->payload, 'promotion_gate.python_runtime_allowed'));
    }

    private function createSemanticNotesTable(): void
    {
        Schema::create('semantic_notes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('note_key')->unique();
            $table->string('path')->unique();
            $table->string('title');
            $table->string('type')->default('source_note');
            $table->string('status')->default('active');
            $table->string('confidence')->default('low');
            $table->string('maturity')->default('draft');
            $table->json('domains')->nullable();
            $table->text('summary')->nullable();
            $table->text('body_excerpt')->nullable();
            $table->json('frontmatter')->nullable();
            $table->json('when_to_use')->nullable();
            $table->json('trigger_signals')->nullable();
            $table->json('do_not_use_when')->nullable();
            $table->json('postgres_refs')->nullable();
            $table->string('content_hash');
            $table->text('embedding')->nullable();
            $table->timestamp('indexed_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('last_activated_at')->nullable();
            $table->timestamp('last_practiced_at')->nullable();
            $table->integer('activation_count')->default(0);
            $table->float('usefulness_avg')->nullable();
            $table->json('validation_errors')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    private function createCapturesTable(): void
    {
        Schema::create('captures', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('client_id')->unique();
            $table->string('kind');
            $table->string('domain');
            $table->text('content_text')->nullable();
            $table->string('content_file_path')->nullable();
            $table->integer('content_duration_ms')->nullable();
            $table->integer('content_size_bytes')->nullable();
            $table->string('content_sha256')->nullable();
            $table->string('content_mime_type')->nullable();
            $table->string('transcription_status')->default('na');
            $table->string('transcription_engine')->nullable();
            $table->text('transcription_error')->nullable();
            $table->timestamp('captured_at');
            $table->string('captured_timezone');
            $table->float('captured_lat')->nullable();
            $table->float('captured_lng')->nullable();
            $table->json('pre_capture_digital_context')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
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

    private function createAttachmentIndexEntriesTable(): void
    {
        Schema::create('ai_attachment_index_entries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('attachment_id')->index();
            $table->string('source_type')->default('test');
            $table->string('title')->nullable();
            $table->text('excerpt')->nullable();
            $table->text('embedding')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }
}
