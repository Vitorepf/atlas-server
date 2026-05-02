<?php

namespace Tests\Feature\Engine;

use App\Models\AiPerformanceRecommendation;
use App\Services\Ai\Telemetry\Engine\Dto\DiagnosticFinding;
use App\Services\Ai\Telemetry\Engine\Dto\DiagnosticResult;
use App\Services\Ai\Telemetry\Engine\Dto\ReportContext;
use App\Services\Ai\Telemetry\Engine\Dto\StatisticalResult;
use App\Services\Ai\Telemetry\Engine\RecommendationLifecycleService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class RecommendationLifecycleServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->bootSchema();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        foreach (['ai_performance_recommendations', 'ai_report_findings', 'ai_performance_report_runs'] as $t) {
            Schema::dropIfExists($t);
        }
        parent::tearDown();
    }

    public function test_no_findings_no_recommendations(): void
    {
        $r = app(RecommendationLifecycleService::class)->recommend(
            $this->ctx(),
            new DiagnosticResult([]),
            $this->stats(),
        );

        $this->assertEmpty($r->created);
        $this->assertEmpty($r->reaffirmed);
        $this->assertSame(0, AiPerformanceRecommendation::query()->count());
    }

    public function test_first_finding_creates_recommendation_in_proposed_state(): void
    {
        $finding = $this->finding(['metric' => 'final_quality_avg', 'attributionDimensions' => ['provider' => 'openai']]);

        $r = app(RecommendationLifecycleService::class)->recommend(
            $this->ctx(),
            new DiagnosticResult([$finding]),
            $this->stats(),
        );

        $this->assertCount(1, $r->created);
        $rec = AiPerformanceRecommendation::query()->first();
        $this->assertSame('proposed', $rec->state);
        $this->assertSame('quality_drop', $rec->kind);
        $this->assertSame(['provider' => 'openai'], $rec->target_dimension);
        $this->assertCount(1, $rec->state_history,
            'First state_history entry must record the proposed transition with finding_id ref.');
    }

    public function test_same_finding_next_day_reaffirms_not_duplicates(): void
    {
        $service = app(RecommendationLifecycleService::class);
        $f1 = $this->finding(['metric' => 'final_quality_avg', 'attributionDimensions' => ['provider' => 'openai']]);
        $f2 = $this->finding(['metric' => 'final_quality_avg', 'attributionDimensions' => ['provider' => 'openai']]);

        $service->recommend($this->ctx(), new DiagnosticResult([$f1]), $this->stats());
        $r = $service->recommend($this->ctx(CarbonImmutable::parse('2026-05-02T07:00:00Z')), new DiagnosticResult([$f2]), $this->stats());

        $this->assertCount(0, $r->created, 'Day 2 same finding must NOT create new — must reaffirm.');
        $this->assertCount(1, $r->reaffirmed);
        $this->assertSame(1, AiPerformanceRecommendation::query()->count(),
            'Single recommendation row total — no duplicates across days.');
        $rec = AiPerformanceRecommendation::query()->first();
        $this->assertCount(2, $rec->state_history,
            'state_history grows: proposed (day 1) + reaffirmed (day 2).');
    }

    public function test_snoozed_recommendation_reaffirms_not_duplicates(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-02T07:00:00Z'));
        $service = app(RecommendationLifecycleService::class);
        $f1 = $this->finding(['metric' => 'final_quality_avg', 'attributionDimensions' => ['provider' => 'openai']]);
        $f2 = $this->finding(['metric' => 'final_quality_avg', 'attributionDimensions' => ['provider' => 'openai']]);

        $service->recommend($this->ctx(), new DiagnosticResult([$f1]), $this->stats());
        $service->transition(
            AiPerformanceRecommendation::query()->firstOrFail(),
            'snoozed',
            'wait for more data',
            ['snoozed_until' => '2026-05-05T07:00:00Z'],
        );

        $r = $service->recommend($this->ctx(CarbonImmutable::parse('2026-05-03T07:00:00Z')), new DiagnosticResult([$f2]), $this->stats());

        $this->assertCount(0, $r->created, 'Snoozed recommendation still owns the problem and must not duplicate.');
        $this->assertCount(1, $r->reaffirmed);
        $this->assertSame(1, AiPerformanceRecommendation::query()->count());
        $this->assertSame('snoozed', AiPerformanceRecommendation::query()->first()->state);
    }

    public function test_more_specific_finding_supersedes_less_specific(): void
    {
        $service = app(RecommendationLifecycleService::class);
        $broad = $this->finding(['metric' => 'final_quality_avg', 'attributionDimensions' => ['provider' => 'openai']]);
        $specific = $this->finding(['metric' => 'final_quality_avg', 'attributionDimensions' => ['provider' => 'openai', 'model' => 'gpt-4o']]);

        $service->recommend($this->ctx(), new DiagnosticResult([$broad]), $this->stats());
        $r = $service->recommend($this->ctx(CarbonImmutable::parse('2026-05-02T07:00:00Z')), new DiagnosticResult([$specific]), $this->stats());

        $this->assertCount(1, $r->superseded,
            'More specific dimension (provider+model) MUST supersede less specific (provider only).');
        $rows = AiPerformanceRecommendation::query()->get();
        $this->assertSame(2, $rows->count(), 'Both rows persist — old marked superseded, new created.');

        $superseded = $rows->firstWhere('state', 'superseded');
        $newOne = $rows->firstWhere('state', 'proposed');
        $this->assertNotNull($superseded);
        $this->assertNotNull($newOne);
        $this->assertSame($newOne->id, $superseded->superseded_by_id,
            'Superseded row points at the new more-specific row via superseded_by_id.');
        $this->assertSame(['provider' => 'openai', 'model' => 'gpt-4o'], $newOne->target_dimension);
    }

    public function test_different_provider_does_not_match_creates_separately(): void
    {
        $service = app(RecommendationLifecycleService::class);
        $openai = $this->finding(['metric' => 'final_quality_avg', 'attributionDimensions' => ['provider' => 'openai']]);
        $anthropic = $this->finding(['metric' => 'final_quality_avg', 'attributionDimensions' => ['provider' => 'anthropic']]);

        $service->recommend($this->ctx(), new DiagnosticResult([$openai, $anthropic]), $this->stats());

        $this->assertSame(2, AiPerformanceRecommendation::query()->count(),
            'Different provider values are different problems — must create separate recommendations.');
    }

    public function test_measurement_window_varies_by_kind_family_per_decision_13(): void
    {
        $service = app(RecommendationLifecycleService::class);

        $costFinding = $this->finding(['metric' => 'cost_per_trace_microusd', 'attributionDimensions' => ['provider' => 'openai']]);
        $service->recommend($this->ctx(), new DiagnosticResult([$costFinding]), $this->stats());

        $latencyFinding = $this->finding(['metric' => 'app_visible_avg_ms', 'attributionDimensions' => ['provider' => 'openai']]);
        $service->recommend($this->ctx(), new DiagnosticResult([$latencyFinding]), $this->stats());

        $qualityFinding = $this->finding(['metric' => 'final_quality_avg', 'attributionDimensions' => ['provider' => 'openai']]);
        $service->recommend($this->ctx(), new DiagnosticResult([$qualityFinding]), $this->stats());

        $cost = AiPerformanceRecommendation::query()->where('kind', 'metric_anomaly:cost_per_trace_microusd')->first();
        $latency = AiPerformanceRecommendation::query()->where('kind', 'latency_regression_user_visible')->first();
        $quality = AiPerformanceRecommendation::query()->where('kind', 'quality_drop')->first();

        // Default fallback kicks in for unmapped cost metric — using default window
        $this->assertSame(7, $cost->measurement_window_days, 'Unmapped kind defaults to 7d.');
        $this->assertSame(7, $latency->measurement_window_days, 'latency_* family → 7 days.');
        $this->assertSame(14, $quality->measurement_window_days, 'quality_* family → 14 days (2 weekly cycles).');
    }

    public function test_baseline_snapshot_captured_from_statistical_result(): void
    {
        $finding = $this->finding(['metric' => 'final_quality_avg']);
        $stats = new StatisticalResult(
            anomalies: [],
            trends: [],
            baselines: [['metric' => 'final_quality_avg', 'mean' => 78.5, 'sigma' => 4.2, 'sample_n' => 25]],
        );

        $r = app(RecommendationLifecycleService::class)->recommend(
            $this->ctx(),
            new DiagnosticResult([$finding]),
            $stats,
        );

        $rec = $r->created[0];
        $this->assertSame(78.5, $rec->baseline_snapshot['mean']);
        $this->assertSame(25, $rec->baseline_snapshot['sample_n'],
            'baseline_snapshot enables effect measurement after user marks applied.');
    }

    public function test_transition_to_applied_sets_measurement_due_at(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-04T07:00:00Z'));
        $finding = $this->finding(['metric' => 'final_quality_avg']);
        $result = app(RecommendationLifecycleService::class)->recommend(
            $this->ctx(),
            new DiagnosticResult([$finding]),
            $this->stats(),
        );

        $updated = app(RecommendationLifecycleService::class)->transition(
            $result->created[0],
            'applied',
            'test_applied',
        );

        $this->assertSame('applied', $updated->state);
        $this->assertSame('2026-05-18T07:00:00.000000Z', $updated->measurement_due_at?->toJSON(),
            'quality_drop recommendations use the configured 14d measurement window once marked applied.');
        $this->assertSame('applied', data_get($updated->state_history, '1.state'));
    }

    public function test_priority_score_reflects_confidence_band(): void
    {
        $service = app(RecommendationLifecycleService::class);
        foreach (['high' => 80, 'medium' => 60, 'low' => 40] as $band => $expected) {
            $finding = $this->finding(['confidenceBand' => $band, 'attributionDimensions' => ['provider' => 'p_'.$band]]);
            $r = $service->recommend($this->ctx(), new DiagnosticResult([$finding]), $this->stats());
            $this->assertSame($expected, $r->created[0]->priority_score,
                "Confidence band '{$band}' must produce priority {$expected}.");
        }
    }

    public function test_skip_returns_empty_when_table_missing(): void
    {
        Schema::dropIfExists('ai_performance_recommendations');
        $finding = $this->finding([]);

        $r = app(RecommendationLifecycleService::class)->recommend(
            $this->ctx(),
            new DiagnosticResult([$finding]),
            $this->stats(),
        );

        $this->assertEmpty($r->created);
        $this->assertSame('recommendations_table_missing', $r->meta['skip_reason']);
    }

    private function ctx(?CarbonImmutable $clock = null): ReportContext
    {
        $clock ??= CarbonImmutable::parse('2026-05-01T07:00:00Z');
        return new ReportContext(
            clock: $clock, windowStart: $clock->startOfDay(), windowEnd: $clock->endOfDay(),
            timezone: 'UTC', reportType: 'daily', engineVersion: 'shadow',
        );
    }

    private function stats(): StatisticalResult
    {
        return new StatisticalResult([], [], []);
    }

    private function finding(array $overrides): DiagnosticFinding
    {
        $defaults = [
            'id' => (string) Str::uuid(),
            'metric' => 'final_quality_avg',
            'direction' => 'down',
            'magnitudePct' => 0.10,
            'attributionDimensions' => ['provider' => 'openai'],
            'explainedFraction' => 0.65,
            'affectedN' => 50,
            'evidence' => [],
            'signals' => [],
            'confidenceScore' => 0.72,
            'confidenceBand' => 'high',
            'suggestedActionSeed' => 'Investigate openai',
        ];
        $merged = array_merge($defaults, $overrides);
        return new DiagnosticFinding(
            $merged['id'], $merged['metric'], $merged['direction'], $merged['magnitudePct'],
            $merged['attributionDimensions'], $merged['explainedFraction'], $merged['affectedN'],
            $merged['evidence'], $merged['signals'], $merged['confidenceScore'],
            $merged['confidenceBand'], $merged['suggestedActionSeed'],
        );
    }

    private function bootSchema(): void
    {
        foreach (['ai_performance_recommendations', 'ai_report_findings', 'ai_performance_report_runs'] as $t) {
            Schema::dropIfExists($t);
        }

        Schema::create('ai_performance_report_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->date('report_date');
            $table->string('report_type', 32);
            $table->string('engine_version', 16);
            $table->string('status', 16);
            $table->timestamp('started_at')->useCurrent();
        });
        (require database_path('migrations/2026_05_01_010000_create_ai_report_findings.php'))->up();
        (require database_path('migrations/2026_05_01_011000_create_ai_performance_recommendations.php'))->up();
    }
}
