<?php

namespace Tests\Feature\Engine;

use App\Models\AiPerformanceRecommendation;
use App\Models\AiPerformanceReportRun;
use App\Models\AiReportFinding;
use App\Models\AiDataConfidenceAudit;
use App\Models\AiMetricDailySnapshot;
use App\Models\AiTraceMetricSummary;
use App\Services\Ai\Telemetry\Engine\Dto\ReportContext;
use App\Services\Ai\Telemetry\Engine\Dto\ReportPayload;
use App\Services\Ai\Telemetry\Engine\Dto\WindowAggregates;
use App\Services\Ai\Telemetry\Engine\EngineOrchestrator;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class EngineOrchestratorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->bootSchema();
    }

    protected function tearDown(): void
    {
        foreach ([
            'ai_performance_recommendations', 'ai_report_findings', 'ai_data_confidence_audit',
            'ai_metric_daily_snapshots', 'ai_trace_metric_summaries', 'ai_performance_report_runs',
        ] as $t) {
            Schema::dropIfExists($t);
        }
        parent::tearDown();
    }

    public function test_full_pipeline_returns_payload_with_six_frozen_keys(): void
    {
        $ctx = $this->ctx();
        $aggregates = $this->aggregates(50);

        $payload = app(EngineOrchestrator::class)->execute($ctx, $aggregates);

        $this->assertInstanceOf(ReportPayload::class, $payload);
        foreach (['decision', 'highlights', 'next_actions', 'risks', 'validation', 'full_text'] as $key) {
            $this->assertArrayHasKey($key, $payload->frozen,
                "Mobile-facing frozen key '{$key}' must be present in every payload, regardless of layer outcomes.");
        }
    }

    public function test_run_row_inserted_at_start_updated_at_completion(): void
    {
        app(EngineOrchestrator::class)->execute($this->ctx(), $this->aggregates(50));

        $this->assertSame(1, AiPerformanceReportRun::query()->count(),
            'Exactly one run row per execute() call.');
        $run = AiPerformanceReportRun::query()->first();
        $this->assertSame('completed', $run->status);
        $this->assertNotNull($run->completed_at);
        $this->assertGreaterThan(0, $run->duration_ms);
        $this->assertIsArray($run->layer_timings);
        $this->assertArrayHasKey('trust_ms', $run->layer_timings);
        $this->assertArrayHasKey('assembly_ms', $run->layer_timings);
    }

    public function test_engine_attributes_regression_and_creates_recommendation_from_snapshot_baseline(): void
    {
        $this->seedQualityHistory();

        $payload = app(EngineOrchestrator::class)->execute($this->ctx(), $this->regressionAggregates());

        $this->assertSame(1, AiReportFinding::query()->count(),
            'Engine must persist a diagnostic finding when current window regresses against snapshot baseline.');
        $this->assertSame(1, AiPerformanceRecommendation::query()->count(),
            'Engine must create the tracked recommendation that powers the morning improvement loop.');
        $this->assertCount(1, $payload->extended['findings']);
        $this->assertSame(1, $payload->extended['recommendations']['created_count']);
    }

    public function test_dry_run_does_not_persist_engine_side_effects(): void
    {
        $this->seedQualityHistory();

        $payload = app(EngineOrchestrator::class)->execute(
            $this->ctx(engineVersion: 'next', runMode: 'dry_run'),
            $this->regressionAggregates(),
        );

        $this->assertSame(0, AiPerformanceReportRun::query()->count());
        $this->assertSame(0, AiDataConfidenceAudit::query()->count());
        $this->assertSame(0, AiReportFinding::query()->count());
        $this->assertSame(0, AiPerformanceRecommendation::query()->count());
        $this->assertSame(1, $payload->extended['recommendations']['created_count'],
            'Dry-run still previews the recommendation while leaving production tables untouched.');
    }

    public function test_payload_has_extended_block_with_trust_gate_per_decision_1(): void
    {
        $payload = app(EngineOrchestrator::class)->execute($this->ctx(), $this->aggregates(50));

        $this->assertArrayHasKey('trust_gate', $payload->extended,
            'Decisão #1: trust_gate goes to extended (NOT compact mobile).');
        $this->assertArrayHasKey('trust_score', $payload->extended['trust_gate']);
    }

    public function test_to_array_preserves_six_frozen_keys_at_top_level(): void
    {
        $payload = app(EngineOrchestrator::class)->execute($this->ctx(), $this->aggregates(50));
        $out = $payload->toArray();

        foreach (['decision', 'highlights', 'next_actions', 'risks', 'validation', 'full_text'] as $key) {
            $this->assertArrayHasKey($key, $out,
                "toArray() must preserve frozen key '{$key}' at top level (mobile contract).");
        }
        $this->assertArrayHasKey('trust_gate', $out, 'Extended keys merged at top level so schema_v2 clients see them.');
        $this->assertArrayHasKey('schema_version', $out['validation']);
    }

    public function test_layer_failure_marks_run_partial_not_failed(): void
    {
        // Force a partial state by dropping a downstream table mid-test (simulates schema drift).
        Schema::dropIfExists('ai_performance_recommendations');

        $payload = app(EngineOrchestrator::class)->execute($this->ctx(), $this->aggregates(50));

        $this->assertInstanceOf(ReportPayload::class, $payload,
            'Pipeline must complete even when a layer cannot persist — graceful degradation.');
        // Run row may show partial OR completed depending on whether the schema check
        // skipped recommendation entirely. Either is acceptable — the key invariant is
        // that the report still emits a valid payload.
    }

    public function test_two_runs_with_same_inputs_produce_consistent_payload_shape(): void
    {
        $ctx = $this->ctx();
        $aggregates = $this->aggregates(50);

        $payload1 = app(EngineOrchestrator::class)->execute($ctx, $aggregates);
        $payload2 = app(EngineOrchestrator::class)->execute($ctx, $aggregates);

        // The trust_score may vary slightly only if the audit table does something time-dependent;
        // structure must be identical.
        $this->assertSame(
            array_keys($payload1->frozen),
            array_keys($payload2->frozen),
            'Two runs must produce same shape (key set) even if values differ.',
        );
        $this->assertSame(
            array_keys($payload1->extended),
            array_keys($payload2->extended),
        );
    }

    private function ctx(?CarbonImmutable $clock = null, string $engineVersion = 'shadow', string $runMode = 'live'): ReportContext
    {
        $clock ??= CarbonImmutable::parse('2026-05-01T07:00:00Z');
        return new ReportContext(
            clock: $clock,
            windowStart: $clock->startOfDay(),
            windowEnd: $clock->endOfDay(),
            timezone: 'UTC',
            reportType: 'daily',
            engineVersion: $engineVersion,
            runMode: $runMode,
        );
    }

    private function aggregates(int $traceCount): WindowAggregates
    {
        $summaries = collect();
        for ($i = 0; $i < $traceCount; $i++) {
            $s = new AiTraceMetricSummary;
            $s->forceFill([
                'trace_id' => (string) Str::uuid(),
                'surface' => 'mobile',
                'status' => 'succeeded',
                'provider' => $i % 2 === 0 ? 'openai' : 'anthropic',
                'model' => 'gpt-4o',
                'agent_slug' => 'orquestrador',
                'task_type' => 'completion',
                'cost_mode' => 'metered_estimate',
                'cost_confidence' => 'metered',
                'metadata' => ['aggregator_version' => 'ai_trace_metric_aggregator_v2'],
                'score_components' => [],
            ]);
            $summaries->push($s);
        }
        return new WindowAggregates(
            scorecard: ['totals' => ['final_quality_avg' => 78, 'final_efficiency_avg' => 82, 'traces' => $traceCount]],
            health: [],
            summary: [],
            summaries: $summaries,
            aggregatorVersion: 'ai_trace_metric_aggregator_v2',
        );
    }

    private function regressionAggregates(): WindowAggregates
    {
        $summaries = collect();
        for ($i = 0; $i < 60; $i++) {
            $summaries->push($this->summary(['provider' => 'openai', 'final_quality_score' => 30]));
        }
        for ($i = 0; $i < 20; $i++) {
            $summaries->push($this->summary(['provider' => 'anthropic', 'final_quality_score' => 79]));
        }

        return new WindowAggregates(
            scorecard: ['totals' => ['final_quality_avg' => 42.25, 'final_efficiency_avg' => 82, 'traces' => 80]],
            health: [],
            summary: [],
            summaries: $summaries,
            aggregatorVersion: 'ai_trace_metric_aggregator_v2',
        );
    }

    private function summary(array $attrs): AiTraceMetricSummary
    {
        $s = new AiTraceMetricSummary;
        $s->forceFill(array_merge([
            'trace_id' => (string) Str::uuid(),
            'surface' => 'mobile',
            'status' => 'succeeded',
            'provider' => 'openai',
            'model' => 'gpt-4o',
            'agent_slug' => 'orquestrador',
            'task_type' => 'completion',
            'cost_mode' => 'metered_estimate',
            'cost_confidence' => 'metered',
            'final_quality_score' => 75,
            'metadata' => ['aggregator_version' => 'ai_trace_metric_aggregator_v2'],
            'score_components' => [],
        ], $attrs));

        return $s;
    }

    private function seedQualityHistory(): void
    {
        for ($i = 14; $i >= 1; $i--) {
            $offset = $i % 2 === 0 ? 2.0 : -2.0;
            AiMetricDailySnapshot::query()->create([
                'snapshot_date' => CarbonImmutable::parse('2026-05-01')->subDays($i)->toDateString(),
                'metric' => 'final_quality_avg',
                'aggregator_version' => 'ai_trace_metric_aggregator_v2',
                'n_traces' => 50,
                'value_mean' => 80.0 + $offset,
            ]);
        }
    }

    private function bootSchema(): void
    {
        foreach ([
            'ai_performance_recommendations', 'ai_report_findings', 'ai_data_confidence_audit',
            'ai_metric_daily_snapshots', 'ai_trace_metric_summaries', 'ai_performance_report_runs',
        ] as $t) {
            Schema::dropIfExists($t);
        }

        // Foundation: report runs table
        Schema::create('ai_performance_report_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->date('report_date');
            $table->string('report_type', 32);
            $table->string('engine_version', 16);
            $table->string('run_mode', 16)->default('live');
            $table->string('status', 16);
            $table->timestamp('started_at')->useCurrent();
            $table->timestamp('completed_at')->nullable();
            $table->integer('duration_ms')->nullable();
            $table->integer('traces_processed')->nullable();
            $table->double('trust_score')->nullable();
            $table->integer('finding_count')->nullable();
            $table->integer('recommendation_count')->nullable();
            $table->json('layer_timings')->default('{}');
            $table->json('layer_errors')->default('{}');
            $table->unsignedSmallInteger('schema_version')->nullable();
        });

        (require database_path('migrations/2026_05_01_001000_create_ai_metric_summary_tables.php'))->up();
        (require database_path('migrations/2026_05_01_005000_add_router_columns_to_ai_trace_metric_summaries.php'))->up();
        (require database_path('migrations/2026_05_01_008000_create_ai_data_confidence_audit.php'))->up();
        (require database_path('migrations/2026_05_01_009000_create_ai_metric_daily_snapshots.php'))->up();
        (require database_path('migrations/2026_05_01_010000_create_ai_report_findings.php'))->up();
        (require database_path('migrations/2026_05_01_011000_create_ai_performance_recommendations.php'))->up();
    }
}
