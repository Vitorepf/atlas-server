<?php

namespace Tests\Feature;

use App\Models\AiDataConfidenceAudit;
use App\Models\AiMetricDailySnapshot;
use App\Models\AiPerformanceRecommendation;
use App\Models\AiPerformanceReportRun;
use App\Models\AiReportFinding;
use App\Models\AiTrace;
use App\Models\AiTraceMetricSummary;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class AiPerformanceSmokeCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('atlas.ai_metrics.performance_report_timezone', 'America/Sao_Paulo');
    }

    protected function tearDown(): void
    {
        foreach ([
            'ai_inbox_items',
            'ai_performance_recommendations',
            'ai_report_findings',
            'ai_data_confidence_audit',
            'ai_performance_report_runs',
            'ai_metric_daily_snapshots',
            'ai_tool_events',
            'ai_trace_metric_summaries',
            'ai_outcome_links',
            'ai_provider_cost_rates',
            'ai_traces',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    public function test_smoke_fails_closed_when_required_tables_are_missing(): void
    {
        $exitCode = Artisan::call('atlas:ai:performance:smoke', [
            '--date' => '2026-04-30',
            '--json' => true,
            '--skip-refresh' => true,
        ]);

        $this->assertSame(1, $exitCode);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertFalse($payload['ok']);
        $this->assertContains('missing_table:ai_trace_metric_summaries', $payload['failures']);
    }

    public function test_smoke_runs_snapshot_and_reports_without_engine_side_effect_writes(): void
    {
        $this->bootSchema();
        $this->seedTraceAt('2026-04-30 10:00:00');

        $exitCode = Artisan::call('atlas:ai:performance:smoke', [
            '--date' => '2026-04-30',
            '--timezone' => 'America/Sao_Paulo',
            '--snapshot-days' => 1,
            '--windows' => '3',
            '--json' => true,
        ]);

        $this->assertSame(0, $exitCode);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertTrue($payload['ok']);
        $this->assertTrue($payload['snapshot_refresh']['ok']);
        $this->assertTrue($payload['reports']['daily']['engine']['present']);
        $this->assertTrue($payload['reports']['multi']['engine']['present']);
        $this->assertSame(7, AiMetricDailySnapshot::query()->count());
        $this->assertSame(0, AiPerformanceReportRun::query()->count());
        $this->assertSame(0, AiDataConfidenceAudit::query()->count());
        $this->assertSame(0, AiReportFinding::query()->count());
        $this->assertSame(0, AiPerformanceRecommendation::query()->count());
    }

    private function seedTraceAt(string $localTimestamp): void
    {
        $traceId = (string) Str::uuid();
        $createdAt = CarbonImmutable::parse($localTimestamp, 'America/Sao_Paulo');

        $trace = new AiTrace();
        $trace->forceFill([
            'id' => $traceId,
            'trace_key' => 'trace-'.$traceId,
            'thread_id' => (string) Str::uuid(),
            'session_id' => (string) Str::uuid(),
            'source_type' => 'app',
            'status' => 'succeeded',
            'operator_input' => 'Teste smoke',
            'agent_slug' => 'orquestrador',
            'provider' => 'claude_cli',
            'model' => 'claude_cli_default',
            'skill_versions' => [],
            'context_refs' => [],
            'response_text' => 'ok',
            'completed_at' => $createdAt->addSeconds(2),
            'metadata' => [],
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
        $trace->save();

        AiTraceMetricSummary::query()->create([
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
            'app_send_to_visible_ms' => 100,
            'provider_latency_ms' => 1000,
            'total_latency_ms' => 1500,
            'first_pass_success' => true,
            'needed_remediation' => false,
            'cost_microusd' => 0,
            'cost_confidence' => 'metered',
            'cost_mode' => 'metered_estimate',
            'auto_quality_score' => 85,
            'final_quality_score' => 85,
            'final_efficiency_score' => 80,
            'score_components' => [],
            'metadata' => ['aggregator_version' => 'ai_trace_metric_aggregator_v2'],
            'computed_at' => $createdAt,
        ]);
    }

    private function bootSchema(): void
    {
        Schema::create('ai_traces', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('trace_key')->unique();
            $table->uuid('thread_id')->nullable();
            $table->uuid('session_id')->nullable();
            $table->string('source_type')->default('app');
            $table->string('status')->default('queued');
            $table->text('operator_input');
            $table->string('agent_slug');
            $table->string('provider')->nullable();
            $table->string('model')->nullable();
            $table->json('skill_versions')->default('{}');
            $table->json('context_refs')->default('[]');
            $table->text('response_text')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->json('metadata')->default('{}');
            $table->timestamps();
        });

        (require database_path('migrations/2026_04_30_120000_create_ai_tool_events_table.php'))->up();
        (require database_path('migrations/2026_04_30_152000_create_ai_inbox_items_table.php'))->up();
        (require database_path('migrations/2026_05_01_001000_create_ai_metric_summary_tables.php'))->up();
        (require database_path('migrations/2026_05_01_005000_add_router_columns_to_ai_trace_metric_summaries.php'))->up();
        (require database_path('migrations/2026_05_01_007000_create_ai_performance_report_runs.php'))->up();
        (require database_path('migrations/2026_05_01_008000_create_ai_data_confidence_audit.php'))->up();
        (require database_path('migrations/2026_05_01_009000_create_ai_metric_daily_snapshots.php'))->up();
        (require database_path('migrations/2026_05_01_010000_create_ai_report_findings.php'))->up();
        (require database_path('migrations/2026_05_01_011000_create_ai_performance_recommendations.php'))->up();
    }
}
