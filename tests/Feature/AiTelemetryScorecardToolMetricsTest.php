<?php

namespace Tests\Feature;

use App\Models\AiToolEvent;
use App\Models\AiTrace;
use App\Models\AiTraceMetricSummary;
use App\Services\Ai\Telemetry\AiTelemetryHealthService;
use App\Services\Ai\Telemetry\AiTelemetryScorecardService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Fix 7c F4 — pin the contract for the scorecard's tools block and the health
 * service's tool-aware risk evaluation.
 *
 * Properties under test:
 *  1. Empty window (no tool events): tools block returns counts=0, rates=null
 *  2. Window with tool events: structured per-tool + total + rate metrics
 *  3. Health issues fire ONLY when min_calls threshold is met (no false positives at low n)
 *  4. Critical risk count fires regardless of total — single critical-risk event = alarm
 *  5. Configurable thresholds resolve from atlas.ai_metrics.tool_*
 */
class AiTelemetryScorecardToolMetricsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->bootMinimalSchema();
    }

    protected function tearDown(): void
    {
        foreach ([
            'ai_trace_metric_summaries',
            'ai_tool_events',
            'ai_traces',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    public function test_scorecard_returns_zero_metrics_when_no_tool_events(): void
    {
        $this->seedSummary('trace-1');

        $scorecard = app(AiTelemetryScorecardService::class)
            ->build(now()->subHour(), now()->addMinute());

        $tools = $scorecard['tools'];
        $this->assertTrue($tools['available']);
        $this->assertSame(0, $tools['tool_calls_total']);
        $this->assertNull($tools['tool_failure_rate'],
            'Rate is null (not 0.0) when there are no calls — distinguishes "no data" from "0% failure rate".');
        $this->assertNull($tools['permission_denial_rate']);
        $this->assertSame([], $tools['by_tool']);
    }

    public function test_scorecard_aggregates_tool_metrics_with_window_scoping(): void
    {
        // 2 traces, both with summaries inside the window
        $this->seedSummary('trace-A');
        $this->seedSummary('trace-B');

        // Trace A: 3 shell.run (1 fail, 1 denied), 2 file.read (clean)
        $this->seedToolEvent('trace-A', 'shell.run', 'medium', 'approved', exitCode: 0, durationMs: 100);
        $this->seedToolEvent('trace-A', 'shell.run', 'medium', 'approved', exitCode: 1, durationMs: 50);
        $this->seedToolEvent('trace-A', 'shell.run', 'medium', 'denied',   exitCode: null, durationMs: 0);
        $this->seedToolEvent('trace-A', 'file.read', 'low',    'auto',     exitCode: 0, durationMs: 20);
        $this->seedToolEvent('trace-A', 'file.read', 'low',    'auto',     exitCode: 0, durationMs: 30);
        // Trace B: 1 git.apply_patch with critical risk
        $this->seedToolEvent('trace-B', 'git.apply_patch', 'critical', 'approved', exitCode: 0, durationMs: 200);

        $scorecard = app(AiTelemetryScorecardService::class)
            ->build(now()->subHour(), now()->addMinute());

        $tools = $scorecard['tools'];

        $this->assertSame(6, $tools['tool_calls_total']);
        $this->assertSame(1, $tools['tool_failure_count'], 'Only one event has exit_code != 0.');
        $this->assertEqualsWithDelta(1 / 6, $tools['tool_failure_rate'], 0.0001);
        $this->assertSame(1, $tools['permission_denied_count']);
        $this->assertEqualsWithDelta(1 / 6, $tools['permission_denial_rate'], 0.0001);
        $this->assertSame(0, $tools['high_risk_tool_count']);
        $this->assertSame(1, $tools['critical_risk_tool_count']);

        // by_tool is sorted by call count descending
        $byTool = collect($tools['by_tool'])->keyBy('bucket');
        $this->assertSame(3, $byTool['shell.run']['calls']);
        $this->assertSame(1, $byTool['shell.run']['failures']);
        $this->assertSame(1, $byTool['shell.run']['denied']);
        $this->assertEqualsWithDelta(1 / 3, $byTool['shell.run']['failure_rate'], 0.0001);
        $this->assertSame(2, $byTool['file.read']['calls']);
        $this->assertSame(0, $byTool['file.read']['failures']);
        $this->assertSame(1, $byTool['git.apply_patch']['calls']);
    }

    public function test_scorecard_tool_risk_counts_are_window_scoped(): void
    {
        $this->seedSummary('trace-current');
        $this->seedSummary('trace-old', now()->subDays(3));
        $this->seedToolEvent('trace-old', 'git.apply_patch', 'critical', 'approved');

        $scorecard = app(AiTelemetryScorecardService::class)
            ->build(now()->subHour(), now()->addMinute());

        $this->assertSame(0, $scorecard['tools']['critical_risk_tool_count']);
        $this->assertSame(0, $scorecard['risks']['tool_critical_risk_count'],
            'Out-of-window critical tool events must not contaminate the current scorecard risk digest.');
    }

    public function test_health_does_not_fire_denial_warning_below_min_calls_floor(): void
    {
        // Configure aggressive thresholds but with a min_calls floor that the test
        // intentionally does NOT meet — proves the floor protects against false positives.
        config()->set('atlas.ai_metrics.tool_denial_warning_above', 0.10);
        config()->set('atlas.ai_metrics.tool_denial_min_calls', 10);
        config()->set('atlas.ai_metrics.health_min_traces', 1);

        $this->seedSummary('trace-1');
        // Only 5 tool calls, 2 denied → 40% denial rate, BUT below the min_calls=10 floor
        for ($i = 0; $i < 3; $i++) {
            $this->seedToolEvent('trace-1', 'shell.run', 'low', 'approved');
        }
        for ($i = 0; $i < 2; $i++) {
            $this->seedToolEvent('trace-1', 'shell.run', 'low', 'denied');
        }

        $health = app(AiTelemetryHealthService::class)
            ->evaluate(now()->subHour(), now()->addMinute());

        $denialIssues = collect($health['issues'])->where('key', 'tool_denial_rate');
        $this->assertCount(0, $denialIssues,
            '40% denial rate looks scary but is statistically meaningless on 5 calls. '
            .'min_calls=10 prevents false positive — the entire reason for the floor.');
    }

    public function test_health_fires_denial_warning_when_min_calls_met_and_threshold_exceeded(): void
    {
        config()->set('atlas.ai_metrics.tool_denial_warning_above', 0.15);
        config()->set('atlas.ai_metrics.tool_denial_min_calls', 10);
        config()->set('atlas.ai_metrics.health_min_traces', 1);

        $this->seedSummary('trace-1');
        // 12 calls, 3 denied (25%) — above 15% warning AND above min 10 floor
        for ($i = 0; $i < 9; $i++) {
            $this->seedToolEvent('trace-1', 'shell.run', 'low', 'approved');
        }
        for ($i = 0; $i < 3; $i++) {
            $this->seedToolEvent('trace-1', 'shell.run', 'low', 'denied');
        }

        $health = app(AiTelemetryHealthService::class)
            ->evaluate(now()->subHour(), now()->addMinute());

        $denialIssue = collect($health['issues'])->firstWhere('key', 'tool_denial_rate');
        $this->assertNotNull($denialIssue, '25% denial in 12 calls must trigger the warning.');
        $this->assertSame('warning', $denialIssue['severity']);
    }

    public function test_health_fires_critical_for_any_critical_risk_tool_call(): void
    {
        config()->set('atlas.ai_metrics.health_min_traces', 1);

        $this->seedSummary('trace-1');
        // Just ONE critical-risk operation — no rate threshold, must fire.
        $this->seedToolEvent('trace-1', 'git.apply_patch', 'critical', 'approved');

        $health = app(AiTelemetryHealthService::class)
            ->evaluate(now()->subHour(), now()->addMinute());

        $criticalIssue = collect($health['issues'])->firstWhere('key', 'tool_critical_risk_count');
        $this->assertNotNull($criticalIssue,
            'Critical-risk tool calls deserve attention even at count=1 — no min_calls floor here, '
            .'because the absolute presence is the alarm, not the rate.');
        $this->assertSame('critical', $criticalIssue['severity']);
    }

    public function test_thresholds_resolve_from_config_keys(): void
    {
        config()->set('atlas.ai_metrics.tool_denial_warning_above', 0.99);
        config()->set('atlas.ai_metrics.tool_failure_critical_above', 0.99);

        $health = app(AiTelemetryHealthService::class)
            ->evaluate(now()->subHour(), now()->addMinute());

        $this->assertEqualsWithDelta(0.99, $health['thresholds']['tool_denial_warning_above'], 0.0001);
        $this->assertEqualsWithDelta(0.99, $health['thresholds']['tool_failure_critical_above'], 0.0001);
    }

    private function seedSummary(string $traceKey, ?\DateTimeInterface $computedAt = null): void
    {
        $computedAt ??= now();

        // AiTrace uses HasUuids and does not have 'id' in $fillable, so passing id
        // explicitly via mass assignment gets stripped — letting Laravel auto-generate
        // is the only way that gives us a working trace ID. Read it back after create.
        $trace = AiTrace::query()->create([
            'trace_key' => $traceKey,
            'source_type' => 'app',
            'status' => 'succeeded',
            'operator_input' => 't',
            'agent_slug' => 'orquestrador',
            'provider' => 'claude_cli',
            'skill_versions' => [],
            'context_refs' => [],
            'response_text' => 'ok',
            'completed_at' => now(),
            'metadata' => [],
        ]);

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
            'computed_at' => $computedAt,
        ]);
    }

    private function seedToolEvent(
        string $traceKey,
        string $tool,
        string $risk,
        string $permissionStatus,
        ?int $exitCode = 0,
        int $durationMs = 100,
    ): void {
        $traceId = (string) AiTrace::query()->where('trace_key', $traceKey)->value('id');
        AiToolEvent::query()->create([
            'event_key' => 'test:'.Str::uuid(),
            'trace_id' => $traceId,
            'tool' => $tool,
            'risk' => $risk,
            'permission_status' => $permissionStatus,
            'input_summary' => [],
            'output_summary' => [],
            'exit_code' => $exitCode,
            'duration_ms' => $durationMs,
            'created_at' => now(),
        ]);
    }

    private function bootMinimalSchema(): void
    {
        foreach ([
            'ai_trace_metric_summaries',
            'ai_tool_events',
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
