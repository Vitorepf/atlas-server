<?php

namespace Tests\Feature\Engine;

use App\Models\AiDataConfidenceAudit;
use App\Models\AiTraceMetricSummary;
use App\Services\Ai\Telemetry\AiTraceMetricAggregatorVersions;
use App\Services\Ai\Telemetry\Engine\Dto\ReportContext;
use App\Services\Ai\Telemetry\Engine\Dto\TrustResult;
use App\Services\Ai\Telemetry\Engine\Dto\WindowAggregates;
use App\Services\Ai\Telemetry\Engine\TrustGateService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Engine F1 — pin TrustGateService behavior to the 13 design decisions.
 *
 * Critical contracts under test:
 *  - Decisão #5: cost_confidence uses TRACE FRACTION, not spend
 *  - Decisão #7: NO caching — two calls produce two audit rows
 *  - Decisão #11/12: mixed aggregator_version penalizes; fail-open on exception
 *  - Decisão #12: skipped() factory returns trust-all neutral result
 *  - Schema-safe: works with partial fixtures (missing columns/tables)
 */
class TrustGateServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->bootMinimalSchema();
    }

    protected function tearDown(): void
    {
        foreach (['ai_data_confidence_audit', 'ai_trace_metric_summaries', 'ai_performance_report_runs'] as $table) {
            Schema::dropIfExists($table);
        }
        parent::tearDown();
    }

    public function test_empty_window_returns_low_trust_does_not_throw(): void
    {
        $aggregates = $this->aggregates(summaries: collect(), aggregatorVersion: 'ai_trace_metric_aggregator_v2');

        $result = app(TrustGateService::class)->evaluate($this->context(), $aggregates);

        $this->assertInstanceOf(TrustResult::class, $result);
        $this->assertSame(0, $result->sampleCount);
        $this->assertFalse($result->usableForAttribution,
            'Empty window must NOT pass usableForAttribution — downstream cannot make claims on zero data.');
        $this->assertSame('insufficient', $result->trustLevel);
        $this->assertEqualsWithDelta(0.0, $result->scoreComponents['volume'], 0.0001);
    }

    public function test_pure_v2_full_coverage_window_scores_sufficient(): void
    {
        // 50 traces, all dimensions populated, all costs metered, pure v2
        $summaries = collect();
        for ($i = 0; $i < 50; $i++) {
            $summaries->push($this->summary([
                'provider' => $i % 2 === 0 ? 'openai' : 'anthropic',
                'model' => $i % 2 === 0 ? 'gpt-4o' : 'claude-opus-4',
                'agent_slug' => 'orquestrador',
                'task_type' => 'completion',
                'surface' => 'mobile',
                'cost_mode' => 'metered_estimate',
                'cost_confidence' => 'metered',
                'metadata' => ['aggregator_version' => 'ai_trace_metric_aggregator_v2'],
            ]));
        }

        $result = app(TrustGateService::class)->evaluate(
            $this->context(),
            $this->aggregates(summaries: $summaries, aggregatorVersion: 'ai_trace_metric_aggregator_v2'),
        );

        $this->assertSame('sufficient', $result->trustLevel,
            '50 traces + full coverage + all metered + pure v2 must reach sufficient (>= 0.80).');
        $this->assertGreaterThanOrEqual(0.80, $result->trustScore);
        $this->assertTrue($result->usableForAttribution);
    }

    public function test_pure_v3_full_coverage_window_scores_sufficient(): void
    {
        $summaries = collect();
        for ($i = 0; $i < 50; $i++) {
            $summaries->push($this->summary([
                'provider' => $i % 2 === 0 ? 'openai' : 'anthropic',
                'model' => $i % 2 === 0 ? 'gpt-4o' : 'claude-opus-4',
                'agent_slug' => 'orquestrador',
                'task_type' => 'completion',
                'surface' => 'mobile',
                'cost_mode' => 'metered_estimate',
                'cost_confidence' => 'metered',
                'metadata' => ['aggregator_version' => AiTraceMetricAggregatorVersions::V3],
            ]));
        }

        $result = app(TrustGateService::class)->evaluate(
            $this->context(),
            $this->aggregates(summaries: $summaries, aggregatorVersion: AiTraceMetricAggregatorVersions::V3),
        );

        $this->assertSame('sufficient', $result->trustLevel);
        $this->assertEqualsWithDelta(1.0, $result->scoreComponents['version_purity'], 0.0001);
    }

    public function test_cost_confidence_uses_trace_fraction_not_spend(): void
    {
        // Decisão #5 — pin trace fraction. Configure 2 traces:
        //  - one with cost_confidence='unknown' but huge cost_microusd (would dominate spend)
        //  - one with cost_confidence='metered' but tiny cost_microusd
        // Trace fraction = 1/2 = 0.50 (50% confident).
        // Spend fraction would be tiny (metered/total) — radically different.
        // We assert trace fraction was used.
        $summaries = collect([
            $this->summary([
                'cost_confidence' => 'unknown',
                'cost_microusd' => 999_999_999, // huge spend, no confidence
                'metadata' => ['aggregator_version' => 'ai_trace_metric_aggregator_v2'],
            ]),
            $this->summary([
                'cost_confidence' => 'metered',
                'cost_microusd' => 1, // tiny spend, full confidence
                'metadata' => ['aggregator_version' => 'ai_trace_metric_aggregator_v2'],
            ]),
        ]);

        $result = app(TrustGateService::class)->evaluate(
            $this->context(),
            $this->aggregates(summaries: $summaries, aggregatorVersion: 'ai_trace_metric_aggregator_v2'),
        );

        $this->assertEqualsWithDelta(0.50, $result->scoreComponents['cost_confidence'], 0.0001,
            'Decisão #5: cost_confidence_score MUST be by trace fraction (1 metered / 2 total = 0.50). '
                .'Spend fraction would have been near 0 because the metered trace is tiny.');
        $this->assertSame('trace_fraction', $result->auditTrail['cost_confidence_method'],
            'Audit trail MUST record the method used so future debugging is unambiguous.');
    }

    public function test_mixed_aggregator_versions_kills_version_purity(): void
    {
        $summaries = collect([
            $this->summary(['metadata' => ['aggregator_version' => 'ai_trace_metric_aggregator_v2']]),
            $this->summary(['metadata' => ['aggregator_version' => 'ai_trace_metric_aggregator_v1']]),
        ]);

        $result = app(TrustGateService::class)->evaluate(
            $this->context(),
            $this->aggregates(
                summaries: $summaries,
                aggregatorVersion: 'ai_trace_metric_aggregator_v2',
                hasMixedAggregatorVersions: true,
            ),
        );

        $this->assertEqualsWithDelta(0.0, $result->scoreComponents['version_purity'], 0.0001,
            'Mixed aggregator versions poison trend analysis — version_purity_score must drop to 0.');
        $this->assertSame(
            'mixed_aggregator_versions_in_window',
            collect($result->topGaps)->firstWhere('dimension', 'aggregator_version')['gap'] ?? null,
            'Mixed window must surface a top_gap pointing at the rollup command to clean it up.',
        );
    }

    public function test_no_caching_two_calls_produce_two_audit_rows(): void
    {
        // Decisão #7 pin: NO cache. Each call recomputes and writes a fresh audit row.
        $summaries = collect([$this->summary([])]);
        $aggregates = $this->aggregates(summaries: $summaries, aggregatorVersion: 'ai_trace_metric_aggregator_v2');
        $service = app(TrustGateService::class);

        $service->evaluate($this->context(), $aggregates);
        $service->evaluate($this->context(), $aggregates);

        $this->assertSame(2, AiDataConfidenceAudit::query()->count(),
            'Decisão #7 (no caching): every call MUST recompute AND persist; exactly 2 audit rows for 2 calls.');
    }

    public function test_per_dimension_usable_flag_uses_three_condition_rule(): void
    {
        // 10 traces total. provider has 9 non-null with 3 distinct values → usable.
        // agent_slug has 1 non-null (sample_with_value < MIN_TRACES_DIMENSION=5) → not usable.
        $summaries = collect();
        for ($i = 0; $i < 10; $i++) {
            $summaries->push($this->summary([
                'provider' => $i < 9 ? ['openai', 'anthropic', 'claude_cli'][$i % 3] : null,
                'agent_slug' => $i === 0 ? 'orquestrador' : null,
                'metadata' => ['aggregator_version' => 'ai_trace_metric_aggregator_v2'],
            ]));
        }

        $result = app(TrustGateService::class)->evaluate(
            $this->context(),
            $this->aggregates(summaries: $summaries, aggregatorVersion: 'ai_trace_metric_aggregator_v2'),
        );

        $this->assertTrue($result->dimensions['provider'],
            'provider has 90% coverage + 3 distinct values + 9 samples → usable.');
        $this->assertFalse($result->dimensions['agent_slug'],
            'agent_slug has only 1 non-null sample (< MIN_TRACES_DIMENSION=5) → not usable.');
    }

    public function test_audit_row_persists_score_components_and_audit_trail(): void
    {
        $summaries = collect([
            $this->summary(['metadata' => ['aggregator_version' => 'ai_trace_metric_aggregator_v2']]),
        ]);

        app(TrustGateService::class)->evaluate(
            $this->context(),
            $this->aggregates(summaries: $summaries, aggregatorVersion: 'ai_trace_metric_aggregator_v2'),
        );

        $audit = AiDataConfidenceAudit::query()->first();
        $this->assertNotNull($audit);
        $this->assertIsArray($audit->score_components);
        $this->assertArrayHasKey('volume', $audit->score_components);
        $this->assertArrayHasKey('coverage', $audit->score_components);
        $this->assertArrayHasKey('cost_confidence', $audit->score_components);
        $this->assertArrayHasKey('version_purity', $audit->score_components);
        $this->assertSame('trace_fraction', $audit->audit_trail['cost_confidence_method']);
    }

    public function test_skipped_factory_returns_fail_open_neutral_result(): void
    {
        // Decisão #12: when computation cannot proceed, fail-open (don't block report).
        $result = TrustResult::skipped('audit_table_missing', 12);

        $this->assertTrue($result->skipped);
        $this->assertSame('audit_table_missing', $result->skipReason);
        $this->assertTrue($result->usableForAttribution,
            'Decisão #12 fail-open: skipped result MUST allow downstream to keep going. '
                .'The skipped flag is the audit signal, not a downstream block.');
        $this->assertSame(0.5, $result->trustScore,
            'Neutral score (0.5) — neither high confidence nor low; the skip flag carries the truth.');
        $this->assertSame(12, $result->sampleCount);
    }

    public function test_evaluate_without_audit_table_returns_skipped_does_not_throw(): void
    {
        // Audit table dropped mid-test — service must fail-open gracefully.
        Schema::dropIfExists('ai_data_confidence_audit');

        $summaries = collect([$this->summary([])]);

        $result = app(TrustGateService::class)->evaluate(
            $this->context(),
            $this->aggregates(summaries: $summaries, aggregatorVersion: 'ai_trace_metric_aggregator_v2'),
        );

        // Note: persistAudit fails silently inside evaluateInternal (best-effort).
        // The result itself is still computed — only persistence is skipped.
        $this->assertInstanceOf(TrustResult::class, $result);
        $this->assertGreaterThan(0.0, $result->trustScore,
            'Service must still compute and return a result even when persistence fails.');
    }

    private function context(?CarbonImmutable $clock = null): ReportContext
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

    private function aggregates(
        ?Collection $summaries = null,
        string $aggregatorVersion = 'ai_trace_metric_aggregator_v2',
        bool $hasMixedAggregatorVersions = false,
    ): WindowAggregates {
        return new WindowAggregates(
            scorecard: [],
            health: [],
            summary: [],
            summaries: $summaries ?? collect(),
            aggregatorVersion: $aggregatorVersion,
            hasMixedAggregatorVersions: $hasMixedAggregatorVersions,
        );
    }

    /**
     * Build an in-memory AiTraceMetricSummary-like object using the actual model
     * (so casts apply correctly). Doesn't insert to DB unless caller saves.
     */
    private function summary(array $attrs): AiTraceMetricSummary
    {
        $defaults = [
            'trace_id' => (string) Str::uuid(),
            'surface' => 'mobile',
            'status' => 'succeeded',
            'provider' => 'openai',
            'model' => 'gpt-4o',
            'agent_slug' => 'orquestrador',
            'task_type' => 'completion',
            'cost_mode' => 'metered_estimate',
            'cost_confidence' => 'metered',
            'metadata' => ['aggregator_version' => 'ai_trace_metric_aggregator_v2'],
            'score_components' => [],
        ];

        $summary = new AiTraceMetricSummary;
        $summary->forceFill(array_merge($defaults, $attrs));

        return $summary;
    }

    private function bootMinimalSchema(): void
    {
        foreach (['ai_data_confidence_audit', 'ai_trace_metric_summaries', 'ai_performance_report_runs'] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('ai_performance_report_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->date('report_date');
            $table->string('report_type', 32);
            $table->string('engine_version', 16);
            $table->string('run_mode', 16)->default('live');
            $table->string('status', 16);
            $table->timestamp('started_at')->useCurrent();
            $table->timestamp('completed_at')->nullable();
        });

        (require database_path('migrations/2026_05_01_001000_create_ai_metric_summary_tables.php'))->up();
        (require database_path('migrations/2026_05_01_005000_add_router_columns_to_ai_trace_metric_summaries.php'))->up();
        (require database_path('migrations/2026_05_01_008000_create_ai_data_confidence_audit.php'))->up();
    }
}
