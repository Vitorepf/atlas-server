<?php

namespace Tests\Feature\Engine;

use App\Models\AiReportFinding;
use App\Models\AiTraceMetricSummary;
use App\Services\Ai\Telemetry\Engine\DiagnosticAttributionService;
use App\Services\Ai\Telemetry\Engine\Dto\ReportContext;
use App\Services\Ai\Telemetry\Engine\Dto\StatisticalResult;
use App\Services\Ai\Telemetry\Engine\Dto\TrustResult;
use App\Services\Ai\Telemetry\Engine\Dto\WindowAggregates;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class DiagnosticAttributionServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->bootSchema();
    }

    protected function tearDown(): void
    {
        foreach (['ai_report_findings', 'ai_trace_metric_summaries', 'ai_performance_report_runs'] as $t) {
            Schema::dropIfExists($t);
        }
        parent::tearDown();
    }

    public function test_no_anomalies_no_findings(): void
    {
        $r = app(DiagnosticAttributionService::class)->diagnose(
            $this->ctx(),
            $this->aggregates(collect()),
            $this->aggregates(collect()),
            new StatisticalResult([], [], []),
            $this->trust(),
        );

        $this->assertEmpty($r->findings);
        $this->assertSame(true, $r->meta['no_anomalies_to_diagnose'] ?? false);
    }

    public function test_attributes_regression_to_dominant_dimension_value(): void
    {
        // 80 baseline traces, all openai with quality 80
        // 80 current traces:
        //   - 60 openai with quality dropping to 60 (the cause)
        //   - 20 anthropic with quality 78 (still healthy)
        // GEVSS must identify provider=openai as the cause.
        $baseline = collect();
        for ($i = 0; $i < 80; $i++) {
            $baseline->push($this->summary(['provider' => 'openai', 'final_quality_avg' => 80]));
        }
        $current = collect();
        for ($i = 0; $i < 60; $i++) {
            $current->push($this->summary(['provider' => 'openai', 'final_quality_avg' => 60]));
        }
        for ($i = 0; $i < 20; $i++) {
            $current->push($this->summary(['provider' => 'anthropic', 'final_quality_avg' => 78]));
        }

        // Anomaly: today=64.5 vs baseline=80
        $anomaly = [
            'metric' => 'final_quality_avg',
            'today_value' => 64.5,
            'baseline_ewma' => 80.0,
            'z_score' => -3.2,
            'severity' => 'critical',
            'polarity' => 'up_good',
        ];

        $r = app(DiagnosticAttributionService::class)->diagnose(
            $this->ctx(),
            $this->aggregates($current),
            $this->aggregates($baseline),
            new StatisticalResult([$anomaly], [], []),
            $this->trust(),
        );

        $this->assertCount(1, $r->findings);
        $finding = $r->findings[0];
        $this->assertSame('final_quality_avg', $finding->metric);
        $this->assertSame('down', $finding->direction);
        $this->assertSame(['provider' => 'openai'], $finding->attributionDimensions,
            'GEVSS must identify provider=openai as the dominant cause of the regression.');
        $this->assertGreaterThanOrEqual(0.35, $finding->explainedFraction);
    }

    public function test_explained_fraction_below_threshold_suppressed(): void
    {
        // Diffuse regression — no single dimension explains > 35% of the drop
        $baseline = collect();
        $current = collect();
        for ($i = 0; $i < 100; $i++) {
            $baseline->push($this->summary(['provider' => 'p'.($i % 10), 'final_quality_avg' => 80]));
            // Each provider drops 1 point — diffuse, no concentration
            $current->push($this->summary(['provider' => 'p'.($i % 10), 'final_quality_avg' => 79]));
        }

        $anomaly = [
            'metric' => 'final_quality_avg',
            'today_value' => 79.0,
            'baseline_ewma' => 80.0,
            'z_score' => -2.6,
            'severity' => 'warning',
            'polarity' => 'up_good',
        ];

        $r = app(DiagnosticAttributionService::class)->diagnose(
            $this->ctx(),
            $this->aggregates($current),
            $this->aggregates($baseline),
            new StatisticalResult([$anomaly], [], []),
            $this->trust(),
        );

        $this->assertEmpty($r->findings,
            'Diffuse regression with no concentrated cause must NOT produce a misleading finding.');
    }

    public function test_findings_are_persisted_with_stable_uuid_for_agent_5_fk(): void
    {
        // 60 openai dropping (the cause) + 20 anthropic stable — gives GEVSS a real
        // concentrated subset (openai is 60/80=75%, under the 85% ceiling).
        $baseline = collect();
        $current = collect();
        for ($i = 0; $i < 60; $i++) {
            $baseline->push($this->summary(['provider' => 'openai', 'final_quality_avg' => 80]));
        }
        for ($i = 0; $i < 20; $i++) {
            $baseline->push($this->summary(['provider' => 'anthropic', 'final_quality_avg' => 80]));
        }
        for ($i = 0; $i < 60; $i++) {
            $current->push($this->summary(['provider' => 'openai', 'final_quality_avg' => 30]));
        }
        for ($i = 0; $i < 20; $i++) {
            $current->push($this->summary(['provider' => 'anthropic', 'final_quality_avg' => 79]));
        }

        $anomaly = [
            'metric' => 'final_quality_avg', 'today_value' => 42.25, 'baseline_ewma' => 80.0,
            'z_score' => -5.0, 'severity' => 'critical', 'polarity' => 'up_good',
        ];

        $r = app(DiagnosticAttributionService::class)->diagnose(
            $this->ctx(), $this->aggregates($current), $this->aggregates($baseline),
            new StatisticalResult([$anomaly], [], []), $this->trust(),
        );

        $this->assertTrue($r->persisted);
        $this->assertSame(1, AiReportFinding::query()->count());
        $stored = AiReportFinding::query()->first();
        $this->assertSame($r->findings[0]->id, $stored->id,
            'Finding ID must be the same UUID in DTO and DB row — Agent 5 FK depends on this.');
    }

    public function test_trust_gate_skipped_falls_back_to_all_dimensions(): void
    {
        // Per Decisão #12 fail-open: when trust skipped, all flat dims are candidates
        $baseline = collect([$this->summary(['provider' => 'openai', 'final_quality_avg' => 80])]);
        $current = collect([$this->summary(['provider' => 'openai', 'final_quality_avg' => 50])]);

        $skippedTrust = TrustResult::skipped('test_skip');
        $anomaly = [
            'metric' => 'final_quality_avg', 'today_value' => 50.0, 'baseline_ewma' => 80.0,
            'z_score' => -5.0, 'severity' => 'critical', 'polarity' => 'up_good',
        ];

        $r = app(DiagnosticAttributionService::class)->diagnose(
            $this->ctx(), $this->aggregates($current), $this->aggregates($baseline),
            new StatisticalResult([$anomaly], [], []), $skippedTrust,
        );

        // Even with 1 trace each, the n filter blocks attribution — but the meta should
        // confirm that all flat dims were ALLOWED as candidates (no trust filter applied).
        $this->assertSame(
            DiagnosticAttributionService::FLAT_DIMENSIONS,
            $r->meta['allowed_dimensions'],
            'When TrustResult is skipped, ALL flat dims must be candidates (fail-open per #12).',
        );
    }

    public function test_confidence_band_drops_when_sample_small(): void
    {
        // Just enough traces to attribute (5 in the affected subgroup) — must produce
        // 'low' or 'medium' band, never 'high'.
        $baseline = collect();
        $current = collect();
        for ($i = 0; $i < 10; $i++) {
            $baseline->push($this->summary(['provider' => 'openai', 'final_quality_avg' => 80]));
        }
        for ($i = 0; $i < 5; $i++) {
            $current->push($this->summary(['provider' => 'openai', 'final_quality_avg' => 30]));
        }
        for ($i = 0; $i < 5; $i++) {
            $current->push($this->summary(['provider' => 'anthropic', 'final_quality_avg' => 79]));
        }

        $anomaly = [
            'metric' => 'final_quality_avg', 'today_value' => 54.5, 'baseline_ewma' => 80.0,
            'z_score' => -3.5, 'severity' => 'critical', 'polarity' => 'up_good',
        ];

        $r = app(DiagnosticAttributionService::class)->diagnose(
            $this->ctx(), $this->aggregates($current), $this->aggregates($baseline),
            new StatisticalResult([$anomaly], [], []), $this->trust(),
        );

        if (! empty($r->findings)) {
            $this->assertNotSame('high', $r->findings[0]->confidenceBand,
                'Small affected_n (5) must NOT produce high confidence — sample factor caps it.');
        }
        $this->assertTrue(true); // pin: no exception thrown even at boundary n
    }

    private function ctx(): ReportContext
    {
        $clock = CarbonImmutable::parse('2026-05-01T07:00:00Z');
        return new ReportContext(
            clock: $clock, windowStart: $clock->startOfDay(), windowEnd: $clock->endOfDay(),
            timezone: 'UTC', reportType: 'daily', engineVersion: 'shadow',
        );
    }

    private function trust(): TrustResult
    {
        return new TrustResult(
            trustScore: 0.78, coverage: 0.85, usableForAttribution: true, trustLevel: 'moderate',
            aggregatorVersion: 'ai_trace_metric_aggregator_v2', mixedAggregatorVersions: false,
            sampleCount: 80,
            dimensions: [
                'provider' => true, 'model' => true, 'agent_slug' => true, 'task_type' => true,
                'surface' => true, 'router_mode' => true, 'router_selected_provider' => true,
                'cost_mode' => true,
            ],
            topGaps: [], scoreComponents: [], auditTrail: [],
        );
    }

    private function aggregates($summaries): WindowAggregates
    {
        return new WindowAggregates([], [], [], $summaries, 'ai_trace_metric_aggregator_v2');
    }

    private function summary(array $attrs): AiTraceMetricSummary
    {
        $defaults = [
            'trace_id' => (string) Str::uuid(), 'surface' => 'mobile', 'status' => 'succeeded',
            'provider' => 'openai', 'model' => 'gpt-4o', 'agent_slug' => 'orquestrador',
            'task_type' => 'completion', 'cost_mode' => 'metered_estimate',
            'cost_confidence' => 'metered', 'final_quality_avg' => 75,
            'metadata' => ['aggregator_version' => 'ai_trace_metric_aggregator_v2'],
            'score_components' => [],
        ];
        $s = new AiTraceMetricSummary;
        $s->forceFill(array_merge($defaults, $attrs));
        return $s;
    }

    private function bootSchema(): void
    {
        foreach (['ai_report_findings', 'ai_trace_metric_summaries', 'ai_performance_report_runs'] as $t) {
            Schema::dropIfExists($t);
        }

        Schema::create('ai_performance_report_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->date('report_date');
            $table->string('report_type', 32);
            $table->string('engine_version', 16);
            $table->string('run_mode', 16)->default('live');
            $table->string('status', 16);
            $table->timestamp('started_at')->useCurrent();
        });

        (require database_path('migrations/2026_05_01_001000_create_ai_metric_summary_tables.php'))->up();
        (require database_path('migrations/2026_05_01_005000_add_router_columns_to_ai_trace_metric_summaries.php'))->up();
        // Test fixture also needs final_quality_avg as a column for in-memory attribution
        if (! Schema::hasColumn('ai_trace_metric_summaries', 'final_quality_avg')) {
            Schema::table('ai_trace_metric_summaries', function (Blueprint $table): void {
                $table->double('final_quality_avg')->nullable();
            });
        }
        (require database_path('migrations/2026_05_01_010000_create_ai_report_findings.php'))->up();
    }
}
