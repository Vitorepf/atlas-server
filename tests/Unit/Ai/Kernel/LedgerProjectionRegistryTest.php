<?php

namespace Tests\Unit\Ai\Kernel;

use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use App\Services\Ai\Kernel\Evidence\LedgerProjectionRegistry;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class LedgerProjectionRegistryTest extends TestCase
{
    private int $eventSeq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('atlas_ledger_events');
        $this->dropProjectionTables();
    }

    protected function tearDown(): void
    {
        $this->dropProjectionTables();
        Schema::dropIfExists('atlas_ledger_events');

        parent::tearDown();
    }

    public function test_registry_declares_core_ledger_projections(): void
    {
        $report = app(LedgerProjectionRegistry::class)->complianceReport();

        $this->assertSame('atlas.ledger_projection_registry.v1', $report['schema_version']);
        $this->assertTrue($report['ok']);
        $this->assertSame(3, $report['count']);
        $this->assertSame([
            'ai_traces',
            'atlas_engineering_runs',
            'atlas_tool_runs',
        ], $report['projection_ids']);
        $this->assertContains(LedgerEventType::ProviderCalled->value, data_get($report, 'projections.0.source_events'));
        $this->assertContains(LedgerEventType::RepairCompleted->value, data_get($report, 'projections.1.source_events'));
        $this->assertContains(LedgerEventType::ToolEvidenceRecorded->value, data_get($report, 'projections.2.source_events'));
        $this->assertSame(['trace_id', 'envelope_id', 'correlation_id'], data_get($report, 'projections.0.identity_keys'));
    }

    public function test_registry_reports_projection_readiness_from_schema(): void
    {
        $this->createProjectionTables();

        $report = app(LedgerProjectionRegistry::class)->complianceReport();

        $this->assertTrue($report['ok']);
        $this->assertTrue($report['ready']);
        $this->assertSame(3, $report['ready_count']);
        $this->assertSame([], $report['warnings']);
        $this->assertTrue((bool) data_get($report, 'projections.0.ready'));
        $this->assertSame([], data_get($report, 'projections.0.missing_columns'));
    }

    public function test_registry_reports_missing_table_without_column_noise(): void
    {
        $report = app(LedgerProjectionRegistry::class)->complianceReport();

        $this->assertTrue($report['ok']);
        $this->assertFalse($report['ready']);
        $this->assertContains('ai_traces:table_missing', $report['warnings']);
        $this->assertContains('atlas_engineering_runs:table_missing', $report['warnings']);
        $this->assertContains('atlas_tool_runs:table_missing', $report['warnings']);
        $this->assertNotContains('ai_traces:column_missing:id', $report['warnings']);
        $this->assertNotContains('atlas_tool_runs:column_missing:tool_slug', $report['warnings']);
        $this->assertSame([], data_get($report, 'projections.0.missing_columns'));
        $this->assertSame([], data_get($report, 'projections.1.missing_columns'));
        $this->assertSame([], data_get($report, 'projections.2.missing_columns'));
    }

    public function test_drift_report_is_unavailable_without_ledger_table(): void
    {
        $report = app(LedgerProjectionRegistry::class)->driftReport();

        $this->assertSame('atlas.ledger_projection_drift.v1', $report['schema_version']);
        $this->assertFalse($report['available']);
        $this->assertSame('ledger_missing', $report['status']);
        $this->assertSame(0, $report['attention_count']);
    }

    public function test_drift_report_marks_projection_current_when_projection_is_newer_than_source_events(): void
    {
        $this->createLedgerTable();
        $this->createProjectionTables();
        $this->recordLedgerEvent(LedgerEventType::ProviderCalled->value, '2026-05-06 02:00:00');
        $this->insertAiTrace('2026-05-06 02:05:00');
        $this->insertEngineeringRun('2026-05-06 02:05:00');

        $report = app(LedgerProjectionRegistry::class)->driftReport();

        $this->assertTrue($report['available']);
        $this->assertSame('ok', $report['status']);
        $this->assertSame(0, $report['drifted_count']);
        $this->assertSame('current', data_get($report, 'projections.0.status'));
        $this->assertFalse((bool) data_get($report, 'projections.0.drifted'));
        $this->assertSame(1, data_get($report, 'projections.0.source_event_count'));
    }

    public function test_drift_report_marks_projection_drifted_when_ledger_source_events_are_newer(): void
    {
        $this->createLedgerTable();
        $this->createProjectionTables();
        $this->recordLedgerEvent(LedgerEventType::ProviderReturned->value, '2026-05-06 03:00:00');
        $this->insertAiTrace('2026-05-06 02:55:00');
        $this->insertEngineeringRun('2026-05-06 03:05:00');

        $report = app(LedgerProjectionRegistry::class)->driftReport();

        $this->assertSame('attention_required', $report['status']);
        $this->assertSame(1, $report['drifted_count']);
        $this->assertSame(1, $report['attention_count']);
        $this->assertSame('drift_detected', data_get($report, 'projections.0.status'));
        $this->assertTrue((bool) data_get($report, 'projections.0.drifted'));
        $this->assertSame(300, data_get($report, 'projections.0.lag_seconds'));
    }

    public function test_drift_report_flags_missing_projection_only_when_source_events_exist(): void
    {
        $this->createLedgerTable();
        $this->recordLedgerEvent(LedgerEventType::ToolInvoked->value, '2026-05-06 04:00:00');

        $report = app(LedgerProjectionRegistry::class)->driftReport();

        $this->assertSame('attention_required', $report['status']);
        $this->assertSame('projection_table_missing', data_get($report, 'projections.0.status'));
        $this->assertFalse((bool) data_get($report, 'projections.0.needs_attention'));
        $this->assertSame('projection_unavailable', data_get($report, 'projections.2.status'));
        $this->assertTrue((bool) data_get($report, 'projections.2.needs_attention'));
    }

    public function test_health_report_summarizes_projection_drift_with_review_signal(): void
    {
        $this->createLedgerTable();
        $this->createProjectionTables();
        $this->recordLedgerEvent(LedgerEventType::ProviderReturned->value, '2026-05-06 03:00:00');
        $this->insertAiTrace('2026-05-06 02:30:00');
        $this->insertEngineeringRun('2026-05-06 03:05:00');

        $report = app(LedgerProjectionRegistry::class)->healthReport(maxLagSeconds: 900);

        $this->assertSame('atlas.ledger_projection_health.v1', $report['schema_version']);
        $this->assertTrue((bool) $report['available']);
        $this->assertSame('warning', $report['status']);
        $this->assertSame('warning', data_get($report, 'review_signal.status'));
        $this->assertSame('run_atlas_ai_ledger_project_or_review_projection_tables', data_get($report, 'review_signal.recommended_action'));
        $this->assertSame(1, $report['warning_count']);
        $this->assertSame('ai_traces', data_get($report, 'projections.0.id'));
        $this->assertSame('warning', data_get($report, 'projections.0.severity'));
        $this->assertSame(1800, data_get($report, 'projections.0.lag_seconds'));
        $this->assertSame('every_ten_minutes', data_get($report, 'scheduler.cadence'));
    }

    private function createLedgerTable(): void
    {
        (require database_path('migrations/2026_05_05_020000_create_atlas_ledger_events_table.php'))->up();
    }

    private function recordLedgerEvent(string $eventType, string $occurredAt): void
    {
        $this->eventSeq++;

        DB::table('atlas_ledger_events')->insert([
            'event_id' => 'evt'.str_pad((string) $this->eventSeq, 29, '0', STR_PAD_LEFT),
            'schema_version' => 'atlas.ledger_event.v1',
            'tenant_id' => 'default',
            'operator_id' => 'test',
            'envelope_id' => 'env-test',
            'receipt_id' => null,
            'trace_id' => 'trace-test',
            'correlation_id' => 'trace-test',
            'causation_id' => null,
            'event_type' => $eventType,
            'emitter_stage' => 'test',
            'emitter_version' => 'test',
            'payload' => json_encode(['event_type' => $eventType], JSON_THROW_ON_ERROR),
            'payload_hash' => str_repeat('a', 64),
            'occurred_at' => $occurredAt,
            'created_at' => $occurredAt,
            'updated_at' => $occurredAt,
        ]);
    }

    private function insertAiTrace(string $updatedAt): void
    {
        DB::table('ai_traces')->insert([
            'id' => '11111111-1111-4111-8111-111111111111',
            'trace_key' => 'trace-test',
            'status' => 'completed',
            'agent_slug' => 'atlas',
            'provider' => 'test',
            'model' => 'test-model',
            'metadata' => json_encode([], JSON_THROW_ON_ERROR),
            'created_at' => $updatedAt,
            'updated_at' => $updatedAt,
        ]);
    }

    private function insertEngineeringRun(string $updatedAt): void
    {
        DB::table('atlas_engineering_runs')->insert([
            'id' => '22222222-2222-4222-8222-222222222222',
            'task_id' => '33333333-3333-4333-8333-333333333333',
            'trace_id' => '44444444-4444-4444-8444-444444444444',
            'workspace_path_hash' => str_repeat('b', 64),
            'workspace_label' => 'test',
            'status' => 'completed',
            'metadata' => json_encode([], JSON_THROW_ON_ERROR),
            'created_at' => $updatedAt,
            'updated_at' => $updatedAt,
        ]);
    }

    private function createProjectionTables(): void
    {
        Schema::create('ai_traces', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('trace_key');
            $table->string('status');
            $table->string('agent_slug');
            $table->string('provider')->nullable();
            $table->string('model')->nullable();
            $table->json('metadata');
            $table->timestamps();
        });

        Schema::create('atlas_engineering_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('task_id');
            $table->uuid('trace_id')->nullable();
            $table->string('workspace_path_hash');
            $table->string('workspace_label');
            $table->string('status');
            $table->json('metadata');
            $table->timestamps();
        });

        Schema::create('atlas_tool_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('tool_slug');
            $table->string('surface');
            $table->string('run_context_type')->nullable();
            $table->string('run_context_id')->nullable();
            $table->string('status');
            $table->json('summary_json');
            $table->json('normalized_result_json');
            $table->json('metadata_json');
            $table->timestamps();
        });
    }

    private function dropProjectionTables(): void
    {
        Schema::dropIfExists('ai_traces');
        Schema::dropIfExists('atlas_engineering_runs');
        Schema::dropIfExists('atlas_tool_runs');
    }
}
