<?php

namespace Tests\Feature;

use App\Models\AiMetricDailySnapshot;
use App\Models\AiToolEvent;
use App\Models\AiTrace;
use App\Models\AiTraceMetricSummary;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class AiMetricDailySnapshotRefreshTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('atlas.ai_metrics.performance_report_timezone', 'America/Sao_Paulo');
        $this->bootSchema();
    }

    protected function tearDown(): void
    {
        foreach ([
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

    public function test_snapshot_refresh_materializes_daily_metrics_with_trace_created_at_window(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-01 07:00:00', 'America/Sao_Paulo'));

        $outside = $this->seedTraceAt('2026-04-29 23:59:59', quality: 10, efficiency: 10);
        $traceA = $this->seedTraceAt('2026-04-30 10:00:00', quality: 80, efficiency: 70, firstPass: true, neededRemediation: false, appVisibleMs: 100);
        $traceB = $this->seedTraceAt('2026-04-30 16:00:00', quality: 90, efficiency: 60, firstPass: false, neededRemediation: true, appVisibleMs: 300);

        $this->seedToolEvent($outside, 'shell.run', exitCode: 1, permissionStatus: 'denied');
        $this->seedToolEvent($traceA, 'shell.run', exitCode: 0, permissionStatus: 'approved');
        $this->seedToolEvent($traceB, 'shell.run', exitCode: 1, permissionStatus: 'denied');

        $this->artisan('atlas:ai:metrics:snapshot-refresh', [
            '--date' => '2026-04-30',
            '--timezone' => 'America/Sao_Paulo',
            '--json' => true,
        ])->assertExitCode(0);

        $this->assertSame(7, AiMetricDailySnapshot::query()->count());

        $quality = AiMetricDailySnapshot::query()->where('metric', 'final_quality_avg')->firstOrFail();
        $this->assertSame(2, $quality->n_traces);
        $this->assertSame('ai_trace_metric_aggregator_v2', $quality->aggregator_version);
        $this->assertEqualsWithDelta(85.0, $quality->value_mean, 0.0001);

        $firstPass = AiMetricDailySnapshot::query()->where('metric', 'first_pass_success_rate')->firstOrFail();
        $this->assertEqualsWithDelta(0.5, $firstPass->value_rate, 0.0001);
        $this->assertSame(1, $firstPass->rate_numerator);
        $this->assertSame(2, $firstPass->rate_denominator);

        $toolFailure = AiMetricDailySnapshot::query()->where('metric', 'tool_failure_rate')->firstOrFail();
        $this->assertEqualsWithDelta(0.5, $toolFailure->value_mean, 0.0001);
        $this->assertSame(1, $toolFailure->rate_numerator);
        $this->assertSame(2, $toolFailure->rate_denominator);

        $denial = AiMetricDailySnapshot::query()->where('metric', 'permission_denial_rate')->firstOrFail();
        $this->assertEqualsWithDelta(0.5, $denial->value_mean, 0.0001);
    }

    public function test_snapshot_refresh_is_idempotent_for_same_date_and_metric(): void
    {
        $this->seedTraceAt('2026-04-30 10:00:00', quality: 80, efficiency: 70);

        $this->artisan('atlas:ai:metrics:snapshot-refresh', [
            '--date' => '2026-04-30',
            '--timezone' => 'America/Sao_Paulo',
        ])->assertExitCode(0);

        $this->artisan('atlas:ai:metrics:snapshot-refresh', [
            '--date' => '2026-04-30',
            '--timezone' => 'America/Sao_Paulo',
        ])->assertExitCode(0);

        $this->assertSame(7, AiMetricDailySnapshot::query()->count());
    }

    private function seedTraceAt(
        string $localTimestamp,
        int $quality,
        int $efficiency,
        bool $firstPass = true,
        bool $neededRemediation = false,
        int $appVisibleMs = 200,
    ): string {
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
            'operator_input' => 'Teste snapshot',
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
            'app_send_to_visible_ms' => $appVisibleMs,
            'provider_latency_ms' => 1000,
            'total_latency_ms' => 1500,
            'first_pass_success' => $firstPass,
            'needed_remediation' => $neededRemediation,
            'cost_microusd' => 0,
            'cost_confidence' => 'metered',
            'cost_mode' => 'metered_estimate',
            'auto_quality_score' => $quality,
            'final_quality_score' => $quality,
            'final_efficiency_score' => $efficiency,
            'score_components' => [],
            'metadata' => ['aggregator_version' => 'ai_trace_metric_aggregator_v2'],
            'computed_at' => $createdAt,
        ]);

        return $traceId;
    }

    private function seedToolEvent(string $traceId, string $tool, int $exitCode, string $permissionStatus): void
    {
        AiToolEvent::query()->create([
            'trace_id' => $traceId,
            'tool' => $tool,
            'risk' => 'low',
            'permission_status' => $permissionStatus,
            'input_summary' => [],
            'output_summary' => [],
            'exit_code' => $exitCode,
            'duration_ms' => 100,
            'created_at' => CarbonImmutable::parse('2026-04-30 12:00:00', 'America/Sao_Paulo'),
        ]);
    }

    private function bootSchema(): void
    {
        foreach ([
            'ai_metric_daily_snapshots',
            'ai_tool_events',
            'ai_trace_metric_summaries',
            'ai_outcome_links',
            'ai_provider_cost_rates',
            'ai_traces',
        ] as $table) {
            Schema::dropIfExists($table);
        }

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
        (require database_path('migrations/2026_05_01_001000_create_ai_metric_summary_tables.php'))->up();
        (require database_path('migrations/2026_05_01_009000_create_ai_metric_daily_snapshots.php'))->up();
    }
}
