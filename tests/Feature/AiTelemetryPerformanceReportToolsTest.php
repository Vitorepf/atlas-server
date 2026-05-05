<?php

namespace Tests\Feature;

use App\Models\AiTrace;
use App\Models\AiTraceMetricSummary;
use App\Models\AiToolEvent;
use App\Services\Ai\Telemetry\AiTelemetryPerformanceReportService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Fix 7c F5 — pin the contract for the daily report's tools section.
 *
 * Layers exercised:
 *  - windowAnalysis()        → produces 'tools' block (paralela to breakdowns/quality)
 *  - buildDaily() top-level  → exposes 'tools' so the report renderer doesn't dig into windowAnalysis
 *  - compactReportPayload()  → mobile inbox digest contains 'tools'
 *  - toolsReport()           → status promotes to warning/critical per thresholds
 */
class AiTelemetryPerformanceReportToolsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('atlas.ai_metrics.health_min_traces', 1);
        config()->set('atlas.ai_metrics.tool_denial_warning_above', 0.15);
        config()->set('atlas.ai_metrics.tool_denial_min_calls', 5);
        config()->set('atlas.ai_metrics.tool_failure_warning_above', 0.20);
        config()->set('atlas.ai_metrics.tool_failure_min_calls', 5);
        config()->set('atlas.token', 'testing-atlas-token-with-enough-length');

        $this->bootMinimalSchema();
    }

    protected function tearDown(): void
    {
        foreach ([
            'ai_trace_metric_summaries',
            'ai_tool_events',
            'ai_inbox_items',
            'ai_traces',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    public function test_daily_report_payload_contains_tools_block(): void
    {
        $traceId = $this->seedTraceAndSummary();

        $report = app(AiTelemetryPerformanceReportService::class)
            ->buildDaily($this->seededReportDate(), 'UTC');

        $this->assertArrayHasKey('tools', $report,
            'Top-level tools key surfaces digest without renderer needing to dig into windowAnalysis.');
        $this->assertSame('idle', $report['tools']['status'],
            'Idle = no tool events for the trace; not "unavailable" because the table exists.');
    }

    public function test_tools_status_is_healthy_when_metrics_are_within_thresholds(): void
    {
        $traceId = $this->seedTraceAndSummary();
        // 10 calls, all clean — 0% denial, 0% failure
        for ($i = 0; $i < 10; $i++) {
            $this->seedToolEvent($traceId, 'shell.run', 'medium', 'approved', exitCode: 0);
        }

        $report = app(AiTelemetryPerformanceReportService::class)
            ->buildDaily($this->seededReportDate(), 'UTC');

        $this->assertSame('healthy', $report['tools']['status']);
        $this->assertSame(10, $report['tools']['tool_calls_total']);
        $this->assertSame(0, $report['tools']['tool_failure_count']);
    }

    public function test_tools_status_respects_min_calls_floor_for_denial_and_failure_rates(): void
    {
        $traceId = $this->seedTraceAndSummary();
        // 1 call, denied and failed => 100% rates, but below min_calls=5. This must
        // stay healthy unless there is critical risk, matching AiTelemetryHealthService.
        $this->seedToolEvent($traceId, 'shell.run', 'medium', 'denied', exitCode: 1, error: 'boom');

        $report = app(AiTelemetryPerformanceReportService::class)
            ->buildDaily($this->seededReportDate(), 'UTC');

        $this->assertSame('healthy', $report['tools']['status']);
        $this->assertSame(1, $report['tools']['tool_calls_total']);
        $this->assertSame(1, $report['tools']['permission_denied_count']);
        $this->assertSame(1, $report['tools']['tool_failure_count']);
    }

    public function test_tools_status_promotes_to_warning_when_denial_rate_exceeds_threshold(): void
    {
        $traceId = $this->seedTraceAndSummary();
        // 10 calls, 3 denied (30%) — above 15% warning, below 40% critical default
        for ($i = 0; $i < 7; $i++) {
            $this->seedToolEvent($traceId, 'shell.run', 'medium', 'approved');
        }
        for ($i = 0; $i < 3; $i++) {
            $this->seedToolEvent($traceId, 'shell.run', 'medium', 'denied');
        }

        $report = app(AiTelemetryPerformanceReportService::class)
            ->buildDaily($this->seededReportDate(), 'UTC');

        $this->assertSame('warning', $report['tools']['status'],
            '30% denial rate above 15% threshold and above min_calls=5 floor → warning.');
    }

    public function test_tools_status_promotes_to_critical_when_critical_risk_present(): void
    {
        $traceId = $this->seedTraceAndSummary();
        // Single critical-risk call — no rate threshold needed
        $this->seedToolEvent($traceId, 'git.apply_patch', 'critical', 'approved');

        $report = app(AiTelemetryPerformanceReportService::class)
            ->buildDaily($this->seededReportDate(), 'UTC');

        $this->assertSame('critical', $report['tools']['status'],
            'Even one critical-risk operation forces status=critical regardless of rates.');
        $this->assertSame(1, $report['tools']['critical_risk_count']);
    }

    public function test_top_failures_filter_respects_min_calls_floor(): void
    {
        $traceId = $this->seedTraceAndSummary();
        // tool A: 1 call, fails → 100% failure rate but only 1 call (below min 5)
        $this->seedToolEvent($traceId, 'rare.tool', 'low', 'approved', exitCode: 1, error: 'boom');
        // tool B: 10 calls, 4 fail → 40% failure rate, qualifies
        for ($i = 0; $i < 6; $i++) {
            $this->seedToolEvent($traceId, 'common.tool', 'low', 'approved', exitCode: 0);
        }
        for ($i = 0; $i < 4; $i++) {
            $this->seedToolEvent($traceId, 'common.tool', 'low', 'approved', exitCode: 1, error: 'boom');
        }

        $report = app(AiTelemetryPerformanceReportService::class)
            ->buildDaily($this->seededReportDate(), 'UTC');

        $topFailures = $report['tools']['top_failures'];
        $this->assertCount(1, $topFailures, 'Only common.tool has >= 5 calls.');
        $this->assertSame('common.tool', $topFailures[0]['bucket']);
    }

    public function test_top_used_returns_tools_sorted_by_call_count(): void
    {
        $traceId = $this->seedTraceAndSummary();
        for ($i = 0; $i < 5; $i++) {
            $this->seedToolEvent($traceId, 'medium.tool', 'low', 'approved');
        }
        for ($i = 0; $i < 12; $i++) {
            $this->seedToolEvent($traceId, 'top.tool', 'low', 'approved');
        }
        for ($i = 0; $i < 2; $i++) {
            $this->seedToolEvent($traceId, 'rare.tool', 'low', 'approved');
        }

        $report = app(AiTelemetryPerformanceReportService::class)
            ->buildDaily($this->seededReportDate(), 'UTC');

        $topUsed = $report['tools']['top_used'];
        $this->assertSame('top.tool', $topUsed[0]['bucket'], '12 calls — most used.');
        $this->assertSame('medium.tool', $topUsed[1]['bucket']);
        $this->assertSame('rare.tool', $topUsed[2]['bucket']);
    }

    private function seedTraceAndSummary(): string
    {
        // Place the trace and summary inside the trace_created_at window of buildDaily()
        // without depending on the local wall-clock timezone near midnight.
        $insideWindow = $this->seededReportDate();

        $trace = AiTrace::query()->create([
            'trace_key' => 'trace_'.Str::uuid(),
            'source_type' => 'app',
            'status' => 'succeeded',
            'operator_input' => 't',
            'agent_slug' => 'orquestrador',
            'provider' => 'claude_cli',
            'skill_versions' => [],
            'context_refs' => [],
            'response_text' => 'ok',
            'completed_at' => $insideWindow,
            'metadata' => [],
        ]);

        // Force created_at to land in the report's window (Eloquent strips it from
        // mass assignment because it's not in $fillable).
        \Illuminate\Support\Facades\DB::table('ai_traces')
            ->where('id', $trace->id)
            ->update(['created_at' => $insideWindow]);

        AiTraceMetricSummary::query()->create([
            'trace_id' => $trace->id,
            'surface' => 'mobile',
            'status' => 'succeeded',
            'final_quality_score' => 80,
            'final_efficiency_score' => 80,
            'first_pass_success' => true,
            'needed_remediation' => false,
            'cost_microusd' => 0,
            'cost_confidence' => 'metered',
            'cost_mode' => 'metered_estimate',
            'score_components' => [],
            'metadata' => [],
            'computed_at' => $insideWindow,
        ]);

        return $trace->id;
    }

    private function seedToolEvent(
        string $traceId,
        string $tool,
        string $risk,
        string $permissionStatus,
        ?int $exitCode = 0,
        ?string $error = null,
    ): void {
        AiToolEvent::query()->create([
            'event_key' => 'test:'.Str::uuid(),
            'trace_id' => $traceId,
            'tool' => $tool,
            'risk' => $risk,
            'permission_status' => $permissionStatus,
            'input_summary' => [],
            'output_summary' => [],
            'exit_code' => $exitCode,
            'duration_ms' => 100,
            'error' => $error,
            'created_at' => $this->seededReportDate(),
        ]);
    }

    private function seededReportDate(): \Illuminate\Support\Carbon
    {
        return now('UTC')->subDay()->setTime(12, 0);
    }

    private function bootMinimalSchema(): void
    {
        foreach ([
            'ai_trace_metric_summaries',
            'ai_tool_events',
            'ai_inbox_items',
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
            $table->integer('latency_ms')->nullable();
            $table->smallInteger('feedback_score')->nullable();
            $table->string('feedback_action')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->json('metadata')->default('{}');
            $table->timestamps();
        });

        Schema::create('ai_tool_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('event_key', 180)->nullable()->unique();
            $table->uuid('trace_id')->nullable()->index();
            $table->uuid('session_id')->nullable()->index();
            $table->uuid('thread_id')->nullable()->index();
            $table->string('tool', 64);
            $table->string('risk', 16)->default('low');
            $table->string('permission_status', 16)->default('auto');
            $table->string('approval_source', 32)->nullable();
            $table->json('input_summary');
            $table->json('output_summary');
            $table->json('changed_files')->nullable();
            $table->string('checkpoint_id', 128)->nullable();
            $table->integer('exit_code')->nullable();
            $table->integer('duration_ms')->default(0);
            $table->text('error')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        (require database_path('migrations/2026_05_01_001000_create_ai_metric_summary_tables.php'))->up();
    }
}
