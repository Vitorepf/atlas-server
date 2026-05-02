<?php

namespace Tests\Feature\Engine;

use App\Models\AiMetricDailySnapshot;
use App\Services\Ai\Telemetry\Engine\Dto\ReportContext;
use App\Services\Ai\Telemetry\Engine\Dto\TrustResult;
use App\Services\Ai\Telemetry\Engine\Dto\WindowAggregates;
use App\Services\Ai\Telemetry\Engine\StatisticalAnalysisService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class StatisticalAnalysisServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropIfExists('ai_metric_daily_snapshots');
        (require database_path('migrations/2026_05_01_009000_create_ai_metric_daily_snapshots.php'))->up();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('ai_metric_daily_snapshots');
        parent::tearDown();
    }

    public function test_mixed_aggregator_versions_skips_analysis_entirely(): void
    {
        $aggregates = new WindowAggregates(
            scorecard: ['totals' => ['final_quality_avg' => 75]],
            health: [],
            summary: [],
            summaries: collect(),
            aggregatorVersion: 'ai_trace_metric_aggregator_v2',
            hasMixedAggregatorVersions: true,
        );

        $r = app(StatisticalAnalysisService::class)->analyze($this->ctx(), $aggregates, $this->trust());

        $this->assertTrue($r->isSkipped());
        $this->assertSame('mixed_aggregator_versions', $r->meta['skip_reason']);
        $this->assertEmpty($r->anomalies);
        $this->assertEmpty($r->trends);
    }

    public function test_no_history_returns_baselines_only_no_anomalies_no_trends(): void
    {
        $aggregates = $this->aggregates(['final_quality_avg' => 75.0]);

        $r = app(StatisticalAnalysisService::class)->analyze($this->ctx(), $aggregates, $this->trust());

        $this->assertEmpty($r->anomalies, 'No history → EWMA cold-start → no anomaly fires.');
        $this->assertEmpty($r->trends, 'No history → MK below min n → no trend.');
        $this->assertNotEmpty($r->baselines, 'Baselines always emit even on cold start (with confidence=cold_start).');
    }

    public function test_anomaly_fires_when_today_value_spikes_against_history(): void
    {
        // Seed 14 days of stable quality around 80, then today=20 (huge drop)
        $this->seedHistory('final_quality_avg', 14, baseValue: 80.0, noiseAmplitude: 3.0);
        $aggregates = $this->aggregates(['final_quality_avg' => 20.0]);

        $r = app(StatisticalAnalysisService::class)->analyze($this->ctx(), $aggregates, $this->trust());

        $anomaly = collect($r->anomalies)->firstWhere('metric', 'final_quality_avg');
        $this->assertNotNull($anomaly, 'Today=20 vs baseline=80 must fire EWMA anomaly.');
        $this->assertGreaterThan(2.5, abs($anomaly['z_score']));
    }

    public function test_trend_detection_requires_min_7_days(): void
    {
        $this->seedHistory('final_quality_avg', 5, baseValue: 80.0, noiseAmplitude: 2.0);
        $aggregates = $this->aggregates(['final_quality_avg' => 78.0]);

        $r = app(StatisticalAnalysisService::class)->analyze($this->ctx(), $aggregates, $this->trust());

        $this->assertEmpty(
            collect($r->trends)->where('metric', 'final_quality_avg'),
            'Below 7 days of history MK is suppressed; no trend should appear.'
        );
    }

    public function test_v2_only_metrics_skipped_on_v1_window(): void
    {
        $aggregates = new WindowAggregates(
            scorecard: ['totals' => ['final_quality_avg' => 75.0, 'tool_failure_rate' => 0.10]],
            health: [],
            summary: [],
            summaries: collect(),
            aggregatorVersion: 'ai_trace_metric_aggregator_v1',
            hasMixedAggregatorVersions: false,
        );

        $r = app(StatisticalAnalysisService::class)->analyze($this->ctx(), $aggregates, $this->trust());

        $this->assertEmpty(
            collect($r->baselines)->where('metric', 'tool_failure_rate'),
            'tool_failure_rate is v2-only (depends on score_components.tools); must NOT appear on v1 window.'
        );
        $this->assertNotEmpty(
            collect($r->baselines)->where('metric', 'final_quality_avg'),
            'final_quality_avg exists on both v1 and v2 — must still appear.'
        );
    }

    public function test_snapshot_filter_excludes_v1_history_rows(): void
    {
        // Seed 14 v2 rows + 5 v1 rows for the same metric — only v2 should be loaded
        $this->seedHistory('final_quality_avg', 14, baseValue: 80.0, noiseAmplitude: 2.0);
        for ($i = 0; $i < 5; $i++) {
            AiMetricDailySnapshot::query()->create([
                'snapshot_date' => CarbonImmutable::parse('2026-04-01')->addDays($i)->toDateString(),
                'metric' => 'final_quality_avg',
                'aggregator_version' => 'ai_trace_metric_aggregator_v1',  // legacy
                'n_traces' => 50,
                'value_mean' => 999.0, // would corrupt EWMA if loaded
            ]);
        }

        $aggregates = $this->aggregates(['final_quality_avg' => 75.0]);
        $r = app(StatisticalAnalysisService::class)->analyze($this->ctx(), $aggregates, $this->trust());

        $baseline = collect($r->baselines)->firstWhere('metric', 'final_quality_avg');
        $this->assertLessThan(150.0, $baseline['mean'],
            'Baseline must NOT include v1 rows (value=999) — that would poison the calculation.');
    }

    private function ctx(?CarbonImmutable $clock = null): ReportContext
    {
        $clock ??= CarbonImmutable::parse('2026-05-01T07:00:00Z');

        return new ReportContext(
            clock: $clock,
            windowStart: $clock->startOfDay(),
            windowEnd: $clock->endOfDay(),
            timezone: 'UTC',
            reportType: 'daily',
            engineVersion: 'shadow',
        );
    }

    private function trust(): TrustResult
    {
        return new TrustResult(
            trustScore: 0.78,
            coverage: 0.85,
            usableForAttribution: true,
            trustLevel: 'moderate',
            aggregatorVersion: 'ai_trace_metric_aggregator_v2',
            mixedAggregatorVersions: false,
            sampleCount: 50,
            dimensions: [],
            topGaps: [],
            scoreComponents: [],
            auditTrail: [],
        );
    }

    private function aggregates(array $totals): WindowAggregates
    {
        return new WindowAggregates(
            scorecard: ['totals' => $totals],
            health: [],
            summary: [],
            summaries: collect(),
            aggregatorVersion: 'ai_trace_metric_aggregator_v2',
            hasMixedAggregatorVersions: false,
        );
    }

    private function seedHistory(string $metric, int $days, float $baseValue, float $noiseAmplitude): void
    {
        mt_srand(42);
        $reportDate = CarbonImmutable::parse('2026-05-01');
        for ($i = 0; $i < $days; $i++) {
            $date = $reportDate->subDays($days - $i);
            AiMetricDailySnapshot::query()->create([
                'snapshot_date' => $date->toDateString(),
                'metric' => $metric,
                'aggregator_version' => 'ai_trace_metric_aggregator_v2',
                'n_traces' => 30,
                'value_mean' => $baseValue + (mt_rand(0, 1000) / 1000.0 - 0.5) * $noiseAmplitude * 2,
            ]);
        }
    }
}
