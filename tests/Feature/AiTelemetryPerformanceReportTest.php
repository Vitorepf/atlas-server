<?php

namespace Tests\Feature;

use App\Console\Commands\AiTelemetryPerformanceReportCommand;
use App\Models\AiInboxItem;
use App\Models\AiPerformanceReportRun;
use App\Models\AiTrace;
use App\Models\AiTraceMetricSummary;
use App\Services\Ai\Telemetry\AiTelemetryPerformanceReportService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class AiTelemetryPerformanceReportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('atlas.ai_metrics.health_min_traces', 3);
        config()->set('atlas.ai_metrics.performance_report_timezone', 'America/Sao_Paulo');
        config()->set('atlas.mobile.enabled', false);

        $this->createTables();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        $this->dropTables();

        parent::tearDown();
    }

    public function test_daily_report_uses_previous_local_day_trace_window_and_dedupes_inbox(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-01 07:10:00', 'America/Sao_Paulo'));

        $this->seedTraceAt('2026-04-29 23:59:59', ['final_quality_score' => 15]);
        $this->seedTraceAt('2026-04-30 00:00:00', ['final_quality_score' => 80]);
        $this->seedTraceAt('2026-04-30 12:00:00', ['final_quality_score' => 90]);
        $this->seedTraceAt('2026-05-01 00:00:00', ['final_quality_score' => 20]);

        $this->artisan('atlas:ai:telemetry:performance-report', [
            '--emit' => true,
            '--json' => true,
        ])->assertExitCode(0);

        $item = AiInboxItem::query()
            ->where('dedupe_key', 'atlas-ai-performance:daily:2026-04-30')
            ->firstOrFail();

        $this->assertSame('insight', $item->type);
        $this->assertSame('atlas_ai_performance', $item->category);
        $this->assertStringContainsString('Traces: 2', (string) $item->body);
        $this->assertSame('2026-04-30', data_get($item->payload, 'report.report_date'));
        $this->assertSame('trace_created_at', data_get($item->payload, 'report.validation.basis'));

        $this->artisan('atlas:ai:telemetry:performance-report', [
            '--emit' => true,
            '--json' => true,
        ])->assertExitCode(0);

        $this->assertSame(1, AiInboxItem::query()->where('dedupe_key', 'atlas-ai-performance:daily:2026-04-30')->count());
        $this->assertSame($item->id, AiInboxItem::query()->where('dedupe_key', 'atlas-ai-performance:daily:2026-04-30')->firstOrFail()->id);
    }

    public function test_auto_report_emits_daily_and_multi_window_on_day_15(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-15 07:10:00', 'America/Sao_Paulo'));

        $this->seedTraceAt('2026-05-12 09:00:00', ['final_quality_score' => 72]);
        $this->seedTraceAt('2026-05-13 09:00:00', ['final_quality_score' => 75]);
        $this->seedTraceAt('2026-05-14 09:00:00', ['final_quality_score' => 82]);

        $this->artisan('atlas:ai:telemetry:performance-report', [
            '--type' => 'auto',
            '--emit' => true,
            '--json' => true,
        ])->assertExitCode(0);

        $this->assertDatabaseHas('ai_inbox_items', [
            'dedupe_key' => 'atlas-ai-performance:daily:2026-05-14',
        ]);
        $this->assertDatabaseHas('ai_inbox_items', [
            'dedupe_key' => 'atlas-ai-performance:multi:2026-05-14',
        ]);

        $multi = AiInboxItem::query()
            ->where('dedupe_key', 'atlas-ai-performance:multi:2026-05-14')
            ->firstOrFail();

        $this->assertStringContainsString('3/7/15/30', $multi->title);
        $this->assertSame('atlas_ai_multi_window_performance', data_get($multi->payload, 'report.report_type'));
        $this->assertNotEmpty(data_get($multi->payload, 'report.highlights'));
    }

    public function test_auto_report_uses_explicit_report_date_delivery_day_for_backfill(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-01 07:10:00', 'America/Sao_Paulo'));

        $this->seedTraceAt('2026-05-12 09:00:00', ['final_quality_score' => 72]);
        $this->seedTraceAt('2026-05-13 09:00:00', ['final_quality_score' => 75]);
        $this->seedTraceAt('2026-05-14 09:00:00', ['final_quality_score' => 82]);

        $this->artisan('atlas:ai:telemetry:performance-report', [
            '--date' => '2026-05-14',
            '--type' => 'auto',
            '--emit' => true,
            '--json' => true,
        ])->assertExitCode(0);

        $this->assertDatabaseHas('ai_inbox_items', [
            'dedupe_key' => 'atlas-ai-performance:daily:2026-05-14',
        ]);
        $this->assertDatabaseHas('ai_inbox_items', [
            'dedupe_key' => 'atlas-ai-performance:multi:2026-05-14',
        ]);
    }

    public function test_performance_report_dry_run_restores_engine_run_mode_config(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-01 07:10:00', 'America/Sao_Paulo'));
        config()->set('atlas.report.engine_run_mode', 'shadow');

        $this->artisan('atlas:ai:telemetry:performance-report', [
            '--date' => '2026-04-30',
            '--dry-run' => true,
            '--json' => true,
        ])->assertExitCode(0);

        $this->assertSame('shadow', config('atlas.report.engine_run_mode'));
    }

    public function test_daily_report_with_no_data_is_watch_not_false_critical(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-01 07:10:00', 'America/Sao_Paulo'));

        $report = app(AiTelemetryPerformanceReportService::class)->buildDaily('2026-04-30', 'America/Sao_Paulo');

        $this->assertSame('watch', $report['status']);
        $this->assertSame(0, $report['summary']['traces']);
        $this->assertLessThan(0.5, $report['confidence']);
        $this->assertContains('Tratar conclusoes como observacao ate acumular amostra minima de traces.', $report['actions']);
    }

    public function test_shadow_engine_runs_for_current_daily_window_without_changing_legacy_schema(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-01 07:10:00', 'America/Sao_Paulo'));
        config()->set('atlas.report.engine_version', 'shadow');
        $this->bootEngineTables();

        $this->seedTraceAt('2026-04-30 12:00:00', [
            'metadata' => ['aggregator_version' => 'ai_trace_metric_aggregator_v2'],
        ]);

        $report = app(AiTelemetryPerformanceReportService::class)
            ->buildDaily('2026-04-30', 'America/Sao_Paulo');

        $this->assertSame(1, $report['schema_version']);
        $this->assertSame('shadow', $report['engine_version']);
        $this->assertSame('shadow', data_get($report, 'engine._meta.engine_version'));
        $this->assertSame('daily', data_get($report, 'engine._meta.report_type'));
        $this->assertSame(1, AiPerformanceReportRun::query()->count(),
            'Only the current daily window should run through the engine; comparison baseline must not create a separate run.');
    }

    public function test_engine_run_command_persists_replayable_input_and_output_snapshots(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-01 07:10:00', 'America/Sao_Paulo'));
        config()->set('atlas.report.engine_version', 'legacy');
        config()->set('atlas.report.engine_run_mode', 'live');
        $this->bootEngineTables();
        $this->seedTraceAt('2026-04-30 12:00:00', [
            'metadata' => ['aggregator_version' => 'ai_trace_metric_aggregator_v2'],
        ]);

        $this->artisan('atlas:ai:engine:run', [
            '--date' => '2026-04-30',
            '--timezone' => 'America/Sao_Paulo',
            '--engine-version' => 'shadow',
            '--json' => true,
        ])->assertExitCode(0);

        $this->assertSame('legacy', config('atlas.report.engine_version'));
        $this->assertSame('live', config('atlas.report.engine_run_mode'));

        $run = AiPerformanceReportRun::query()->firstOrFail();
        $this->assertSame('shadow', $run->engine_version);
        $this->assertNotNull($run->input_hash);
        $this->assertNotNull($run->output_hash);
        $this->assertNotEmpty($run->input_snapshot);
        $this->assertNotEmpty($run->output_snapshot);
        $this->assertSame($run->id, data_get($run->output_snapshot, '_meta.run_id'));

        $this->artisan('atlas:ai:engine:run', [
            '--replay' => $run->id,
            '--json' => true,
        ])->assertExitCode(0);
    }

    public function test_engine_backfill_dry_run_restores_engine_config_without_persisting_runs(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-01 07:10:00', 'America/Sao_Paulo'));
        config()->set('atlas.report.engine_version', 'legacy');
        config()->set('atlas.report.engine_run_mode', 'live');
        $this->bootEngineTables();
        $this->seedTraceAt('2026-04-30 12:00:00', [
            'metadata' => ['aggregator_version' => 'ai_trace_metric_aggregator_v2'],
        ]);

        $this->artisan('atlas:ai:engine:backfill', [
            '--from' => '2026-04-30',
            '--to' => '2026-04-30',
            '--type' => 'daily',
            '--engine-version' => 'shadow',
            '--run-mode' => 'dry_run',
            '--json' => true,
        ])->assertExitCode(0);

        $this->assertSame('legacy', config('atlas.report.engine_version'));
        $this->assertSame('live', config('atlas.report.engine_run_mode'));
        $this->assertSame(0, AiPerformanceReportRun::query()->count());
    }

    public function test_recompute_lookback_covers_report_and_comparison_baseline(): void
    {
        $this->assertSame(2, AiTelemetryPerformanceReportCommand::recomputeLookbackDays('daily', [3, 7, 15, 30]));
        $this->assertSame(60, AiTelemetryPerformanceReportCommand::recomputeLookbackDays('multi', [3, 7, 15, 30]));
        $this->assertSame(60, AiTelemetryPerformanceReportCommand::recomputeLookbackDays('both', [3, 7, 15, 30]));
        $this->assertSame(6, AiTelemetryPerformanceReportCommand::recomputeLookbackDays('multi', [3]));
    }

    private function seedTraceAt(string $localTimestamp, array $summaryOverrides = []): void
    {
        $traceId = (string) Str::uuid();
        $createdAt = CarbonImmutable::parse($localTimestamp, 'America/Sao_Paulo');
        $quality = (int) ($summaryOverrides['final_quality_score'] ?? 80);
        $efficiency = (int) ($summaryOverrides['final_efficiency_score'] ?? 76);

        $trace = new AiTrace();
        $trace->forceFill([
            'id' => $traceId,
            'trace_key' => 'trace-'.$traceId,
            'thread_id' => (string) Str::uuid(),
            'session_id' => (string) Str::uuid(),
            'source_type' => 'app',
            'status' => 'succeeded',
            'operator_input' => 'Teste de performance',
            'intent' => 'test',
            'agent_slug' => 'orquestrador',
            'provider' => 'claude_cli',
            'model' => 'claude_cli_default',
            'skill_versions' => [],
            'context_refs' => [],
            'response_text' => 'ok',
            'latency_ms' => 2000,
            'completed_at' => $createdAt->addSeconds(2),
            'metadata' => [],
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
        $trace->save();

        AiTraceMetricSummary::query()->create(array_merge([
            'trace_id' => $traceId,
            'thread_id' => (string) Str::uuid(),
            'session_id' => (string) Str::uuid(),
            'surface' => 'mobile',
            'runtime' => 'ios',
            'provider' => 'claude_cli',
            'model' => 'claude_cli_default',
            'agent_slug' => 'orquestrador',
            'task_type' => 'chat',
            'status' => 'succeeded',
            'app_send_to_visible_ms' => 350,
            'queue_wait_ms' => 40,
            'provider_latency_ms' => 1800,
            'total_latency_ms' => 2200,
            'backgrounded_during_run' => false,
            'recovered_from_pending' => false,
            'prompt_tokens' => 1000,
            'completion_tokens' => 300,
            'total_tokens' => 1300,
            'estimated_tokens' => 1300,
            'token_source' => 'provider_usage',
            'cost_microusd' => 120,
            'cost_confidence' => 'estimated',
            'cost_source' => 'cli_provider_usage_estimate',
            'cost_mode' => 'operational_estimate',
            'context_tokens' => 500,
            'context_refs_count' => 4,
            'useful_context_refs_count' => 3,
            'context_efficiency_score' => 75,
            'auto_quality_score' => $quality,
            'continuity_score' => 80,
            'final_quality_score' => $quality,
            'final_efficiency_score' => $efficiency,
            'first_pass_success' => true,
            'needed_remediation' => false,
            'remediation_count' => 0,
            'reask_detected' => false,
            'provider_switched_after_response' => false,
            'score_components' => [],
            'metadata' => [],
            'computed_at' => CarbonImmutable::parse('2026-05-01 12:00:00', 'America/Sao_Paulo'),
        ], $summaryOverrides));
    }

    private function createTables(): void
    {
        $this->dropTables();

        Schema::create('ai_traces', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('trace_key')->unique();
            $table->uuid('thread_id')->nullable();
            $table->uuid('session_id')->nullable();
            $table->string('source_type')->default('app');
            $table->uuid('source_id')->nullable();
            $table->string('status')->default('queued');
            $table->text('operator_input');
            $table->string('intent')->nullable();
            $table->string('agent_slug');
            $table->string('provider')->nullable();
            $table->string('model')->nullable();
            $table->json('skill_versions')->default('{}');
            $table->json('context_refs')->default('[]');
            $table->string('prompt_hash')->nullable();
            $table->string('response_hash')->nullable();
            $table->text('response_text')->nullable();
            $table->integer('latency_ms')->nullable();
            $table->smallInteger('feedback_score')->nullable();
            $table->string('feedback_action')->nullable();
            $table->text('feedback_comment')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->json('metadata')->default('{}');
            $table->timestamps();
        });

        (require database_path('migrations/2026_05_01_001000_create_ai_metric_summary_tables.php'))->up();
        (require database_path('migrations/2026_04_30_151000_create_ai_context_bundles_table.php'))->up();
        (require database_path('migrations/2026_04_30_152000_create_ai_inbox_items_table.php'))->up();
    }

    private function bootEngineTables(): void
    {
        (require database_path('migrations/2026_05_01_007000_create_ai_performance_report_runs.php'))->up();
        (require database_path('migrations/2026_05_01_008000_create_ai_data_confidence_audit.php'))->up();
        (require database_path('migrations/2026_05_01_009000_create_ai_metric_daily_snapshots.php'))->up();
        (require database_path('migrations/2026_05_01_010000_create_ai_report_findings.php'))->up();
        (require database_path('migrations/2026_05_01_011000_create_ai_performance_recommendations.php'))->up();
        (require database_path('migrations/2026_05_01_140000_add_payload_snapshots_to_ai_performance_report_runs.php'))->up();
    }

    private function dropTables(): void
    {
        foreach ([
            'ai_performance_recommendations',
            'ai_report_findings',
            'ai_metric_daily_snapshots',
            'ai_data_confidence_audit',
            'ai_performance_report_runs',
            'ai_inbox_items',
            'ai_context_bundles',
            'ai_trace_metric_summaries',
            'ai_outcome_links',
            'ai_provider_cost_rates',
            'ai_traces',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
}
