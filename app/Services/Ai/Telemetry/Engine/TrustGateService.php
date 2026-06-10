<?php

namespace App\Services\Ai\Telemetry\Engine;

use App\Models\AiDataConfidenceAudit;
use App\Services\Ai\Telemetry\AiTraceMetricAggregatorVersions;
use App\Services\Ai\Telemetry\Engine\Dto\ReportContext;
use App\Services\Ai\Telemetry\Engine\Dto\TrustResult;
use App\Services\Ai\Telemetry\Engine\Dto\WindowAggregates;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Layer 2 of the Report Engine pipeline. Decides — BEFORE any downstream
 * statistical or diagnostic claim — whether the window has enough sample and
 * coverage to make trustworthy assertions.
 *
 * Design decisions baked in (from the 5-lens synthesis):
 *
 *   #5: Cost confidence denominator = TRACE FRACTION (not spend fraction).
 *       Trace count is always available, performance-friendly (COUNT vs SUM),
 *       and honest about coverage independent of token volume.
 *
 *   #7: NO caching. Recomputed on every call. Trust Gate runs at most once per
 *       window per day (multi-window: 4x). Cache invalidation is the bug magnet
 *       this design refuses; if performance ever matters, cache is 1 hour to
 *       add later.
 *
 *  #11: Aggregator_version cross-version protection. Mixed-version
 *       windows penalize version_purity_score because trend analysis on mixed
 *       data lies silently.
 *
 *  #12: Fail-open with `trust_gate_skipped` flag in payload + log warning.
 *       When the audit table is missing or evaluate() throws, returns a
 *       TrustResult with skipped=true and trust_score=0.5 (neutral). The
 *       report still ships; degradation is observable, not silent.
 *
 * Trust score formula (weighted):
 *   trust_score = (volume × 0.30) + (coverage × 0.35)
 *               + (cost_confidence × 0.20) + (version_purity × 0.15)
 *
 * Trust levels (gates downstream claims):
 *   < 0.40 = insufficient (block trends, suppress comparisons)
 *   < 0.60 = low          (per-dim attribution blocked, CIs suppressed)
 *   < 0.80 = moderate     (claims with caveat label)
 *   ≥ 0.80 = sufficient   (no suppression)
 */
class TrustGateService
{
    /** Min traces in window for global claims to be valid. */
    public const MIN_TRACES_GLOBAL = 10;

    /** Min traces in a single dimension slice for attribution to be safe. */
    public const MIN_TRACES_DIMENSION = 5;

    /** Max null rate for a dimension to be `usable_for_attribution`. */
    public const NULL_RATE_THRESHOLD = 0.20;

    /** Min distinct values in a dimension for attribution to be meaningful. */
    public const MIN_DISTINCT_VALUES = 2;

    /** Trust level boundaries (inclusive lower bound). */
    public const LEVEL_THRESHOLDS = [
        'insufficient' => 0.0,
        'low' => 0.40,
        'moderate' => 0.60,
        'sufficient' => 0.80,
    ];

    /** Score component weights. Sum must equal 1.0. */
    public const SCORE_WEIGHTS = [
        'volume' => 0.30,
        'coverage' => 0.35,
        'cost_confidence' => 0.20,
        'version_purity' => 0.15,
    ];

    /**
     * Dimensions evaluated for usable_for_attribution. The ones tied to newer
     * migrations are guarded by column availability inside dimensionStats().
     */
    public const ATTRIBUTION_DIMENSIONS = [
        'provider',
        'model',
        'agent_slug',
        'task_type',
        'surface',
        'router_mode',         // Added with the v2 contract (Fix 7a)
        'router_selected_provider',
        'cost_mode',
    ];

    public function evaluate(
        ReportContext $ctx,
        WindowAggregates $aggregates,
        ?string $runId = null,
    ): TrustResult {
        // Fail-open: even if the entire computation throws, the report continues.
        try {
            return $this->evaluateInternal($ctx, $aggregates, $runId);
        } catch (Throwable $e) {
            Log::warning('TrustGateService failed; fail-open with skipped=true', [
                'exception' => $e->getMessage(),
                'report_date' => $ctx->windowStart->toDateString(),
                'report_type' => $ctx->reportType,
                'trace' => substr((string) $e, 0, 500),
            ]);

            return TrustResult::skipped(
                reason: 'evaluate_exception:'.class_basename($e),
                sampleCount: $aggregates->traceCount(),
            );
        }
    }

    private function evaluateInternal(
        ReportContext $ctx,
        WindowAggregates $aggregates,
        ?string $runId,
    ): TrustResult {
        $sampleCount = $aggregates->traceCount();
        $summaries = $aggregates->summaries;

        // Component 1: volume_score (graduated by raw trace count)
        $volumeScore = $this->volumeScore($sampleCount);

        // Component 2: coverage_score (weighted average of per-dimension coverage)
        $dimensionStats = $this->dimensionStats($summaries);
        $coverageScore = $this->coverageScore($dimensionStats);

        // Component 3: cost_confidence_score — TRACE FRACTION per Decisão #5
        $costConfidenceScore = $this->costConfidenceScoreByTraceFraction($summaries);

        // Component 4: version_purity_score — penalize mixed aggregator versions
        $versionPurityScore = $this->versionPurityScore($summaries);

        $components = [
            'volume' => $volumeScore,
            'coverage' => $coverageScore,
            'cost_confidence' => $costConfidenceScore,
            'version_purity' => $versionPurityScore,
        ];

        // Weighted sum
        $trustScore = round(
            $volumeScore * self::SCORE_WEIGHTS['volume']
            + $coverageScore * self::SCORE_WEIGHTS['coverage']
            + $costConfidenceScore * self::SCORE_WEIGHTS['cost_confidence']
            + $versionPurityScore * self::SCORE_WEIGHTS['version_purity'],
            4,
        );

        $trustLevel = $this->trustLevelFor($trustScore);

        // Per-dimension usable flags consumed by Agent 4 (Diagnostic)
        $dimensions = collect($dimensionStats)
            ->map(fn (array $stats): bool => $stats['usable_for_attribution'])
            ->all();

        // Top gaps for Agent 5 (Recommendation Lifecycle) to seed actionable items
        $topGaps = $this->topGapsFrom($dimensionStats, $costConfidenceScore, $aggregates);

        $auditTrail = [
            'cost_confidence_method' => 'trace_fraction',
            'dimensions' => $dimensionStats,
            'top_gaps' => $topGaps,
            'min_traces_global' => self::MIN_TRACES_GLOBAL,
            'min_traces_dimension' => self::MIN_TRACES_DIMENSION,
        ];

        $result = new TrustResult(
            trustScore: $trustScore,
            coverage: $coverageScore,
            usableForAttribution: $trustScore >= self::LEVEL_THRESHOLDS['low'],
            trustLevel: $trustLevel,
            aggregatorVersion: $aggregates->aggregatorVersion,
            mixedAggregatorVersions: $aggregates->hasMixedAggregatorVersions,
            sampleCount: $sampleCount,
            dimensions: $dimensions,
            topGaps: $topGaps,
            scoreComponents: $components,
            auditTrail: $auditTrail,
        );

        // Persist to audit table — best-effort. If write fails, we log but don't
        // throw (the result is still useful to the caller).
        $this->persistAudit($ctx, $result, $runId);

        return $result;
    }

    private function volumeScore(int $sampleCount): float
    {
        return match (true) {
            $sampleCount >= 50 => 1.00,
            $sampleCount >= 20 => 0.85,
            $sampleCount >= self::MIN_TRACES_GLOBAL => 0.70,
            $sampleCount >= 5 => 0.50,
            $sampleCount >= 1 => 0.20,
            default => 0.00,
        };
    }

    /**
     * Per-dimension coverage stats. Schema-safe: dimensions tied to newer
     * columns (router_mode etc.) are excluded when the column doesn't exist
     * — they don't penalize the score for an unmigrated DB.
     *
     * @return array<string,array{coverage_rate:float,distinct_values:int,sample_with_value:int,usable_for_attribution:bool,schema_available:bool}>
     */
    private function dimensionStats(Collection $summaries): array
    {
        $total = $summaries->count();
        $stats = [];

        foreach (self::ATTRIBUTION_DIMENSIONS as $dimension) {
            // Schema-safe: skip dimensions whose column doesn't exist
            $schemaAvailable = DatabaseTableAvailability::hasColumn('ai_trace_metric_summaries', $dimension);
            if (! $schemaAvailable) {
                $stats[$dimension] = [
                    'coverage_rate' => 0.0,
                    'distinct_values' => 0,
                    'sample_with_value' => 0,
                    'usable_for_attribution' => false,
                    'schema_available' => false,
                ];

                continue;
            }

            if ($total === 0) {
                $stats[$dimension] = [
                    'coverage_rate' => 0.0,
                    'distinct_values' => 0,
                    'sample_with_value' => 0,
                    'usable_for_attribution' => false,
                    'schema_available' => true,
                ];

                continue;
            }

            $values = $summaries->pluck($dimension)->filter(fn ($v): bool => $v !== null && $v !== '');
            $sampleWithValue = $values->count();
            $coverageRate = $sampleWithValue / $total;
            $distinctValues = $values->unique()->count();
            $nullRate = 1.0 - $coverageRate;

            // Three-condition rule from Trust Gate design:
            //   null_rate < threshold AND distinct >= 2 AND n >= MIN_TRACES_DIMENSION
            $usable = $nullRate < self::NULL_RATE_THRESHOLD
                && $distinctValues >= self::MIN_DISTINCT_VALUES
                && $sampleWithValue >= self::MIN_TRACES_DIMENSION;

            $stats[$dimension] = [
                'coverage_rate' => round($coverageRate, 4),
                'distinct_values' => $distinctValues,
                'sample_with_value' => $sampleWithValue,
                'usable_for_attribution' => $usable,
                'schema_available' => true,
            ];
        }

        return $stats;
    }

    /**
     * Coverage score = average of coverage_rate across dimensions whose schema
     * is available. Dimensions excluded by column availability don't affect the
     * average (avoids penalizing unmigrated DBs).
     */
    private function coverageScore(array $dimensionStats): float
    {
        $available = array_filter($dimensionStats, fn (array $s): bool => $s['schema_available']);
        if (empty($available)) {
            return 0.0;
        }

        $sum = array_sum(array_column($available, 'coverage_rate'));

        return round($sum / count($available), 4);
    }

    /**
     * Cost confidence score by TRACE FRACTION (Decisão #5).
     *
     * Returns the fraction of traces with cost_confidence='metered' OR 'estimated'
     * (i.e. NOT 'unknown'). 'metered' is the gold standard but 'estimated'
     * (CLI traces, valid by design) also counts as confident — only 'unknown'
     * (rate missing) signals real lack of trust.
     */
    private function costConfidenceScoreByTraceFraction(Collection $summaries): float
    {
        $total = $summaries->count();
        if ($total === 0) {
            return 1.0; // empty window — no cost data to be unconfident about
        }

        // Schema-safe: cost_confidence column may not exist in partial test fixtures
        if (! DatabaseTableAvailability::hasColumn('ai_trace_metric_summaries', 'cost_confidence')) {
            return 1.0;
        }

        $confidentCount = $summaries->filter(fn ($s): bool => in_array(
            $s->cost_confidence ?? null,
            ['metered', 'actual', 'estimated'],  // 'actual' is a compatibility alias.
            true,
        ))->count();

        return round($confidentCount / $total, 4);
    }

    /**
     * Version purity score: 1.0 for pure modern windows, 0.5 for pure v1
     * (acceptable but limited), 0.0 for mixed/unknown (poison for trend analysis).
     */
    private function versionPurityScore(Collection $summaries): float
    {
        if ($summaries->isEmpty()) {
            return 1.0;
        }

        // Inspect aggregator_version inside metadata.aggregator_version for each row
        $versions = $summaries
            ->map(fn ($s): string => (string) (data_get($s->metadata ?? [], 'aggregator_version') ?: 'unknown'))
            ->countBy()
            ->all();

        $total = array_sum($versions);

        foreach (AiTraceMetricAggregatorVersions::MODERN_DIAGNOSTIC_VERSIONS as $version) {
            if (($versions[$version] ?? 0) === $total) {
                return 1.0;
            }
        }

        if (($versions[AiTraceMetricAggregatorVersions::V1] ?? 0) === $total) {
            return 0.5; // v1-only: works but lacks newer router/tools/diagnostics fields.
        }

        return 0.0;
    }

    private function trustLevelFor(float $trustScore): string
    {
        return match (true) {
            $trustScore >= self::LEVEL_THRESHOLDS['sufficient'] => 'sufficient',
            $trustScore >= self::LEVEL_THRESHOLDS['moderate'] => 'moderate',
            $trustScore >= self::LEVEL_THRESHOLDS['low'] => 'low',
            default => 'insufficient',
        };
    }

    /**
     * Build top_gaps actionable items for Agent 5. Limit to top 5 by impact.
     * Per Architecture Conductor recommendation: structured format (not raw shell).
     *
     * @return array<int,array{dimension:string,gap:string,action:string}>
     */
    private function topGapsFrom(array $dimensionStats, float $costConfidenceScore, WindowAggregates $aggregates): array
    {
        $gaps = [];

        // System-wide gaps first — they affect ALL downstream analysis, so they
        // must appear in the top of the list even when many per-dimension gaps
        // exist that would otherwise crowd them out at the slice boundary.

        // Gap A (highest priority): mixed aggregator versions poison trends
        if ($aggregates->hasMixedAggregatorVersions) {
            $gaps[] = [
                'dimension' => 'aggregator_version',
                'gap' => 'mixed_aggregator_versions_in_window',
                'action' => 'php artisan atlas:ai:telemetry:rollup --hours=720',
            ];
        }

        // Gap B: low cost confidence (when window has enough traces to make the claim)
        if ($costConfidenceScore < 0.80 && $aggregates->traceCount() >= self::MIN_TRACES_GLOBAL) {
            $gaps[] = [
                'dimension' => 'cost_confidence',
                'gap' => sprintf('cost_confidence_below_threshold:%.2f', $costConfidenceScore),
                'action' => 'configure_missing_cost_rates_then_run_atlas:ai:telemetry:rollup',
            ];
        }

        // Gap C: per-dimension null rate / cardinality issues
        foreach ($dimensionStats as $dimension => $stats) {
            if (! $stats['schema_available'] || $stats['usable_for_attribution']) {
                continue;
            }
            // Skip dimensions where the issue is just empty window (common in tests)
            if ($stats['sample_with_value'] === 0 && $aggregates->traceCount() === 0) {
                continue;
            }

            $nullRate = round(1.0 - $stats['coverage_rate'], 2);
            $gaps[] = [
                'dimension' => $dimension,
                'gap' => sprintf('null_rate_high:%.2f', $nullRate),
                'action' => sprintf('investigate_missing_%s_attribution_in_aggregator', $dimension),
            ];
        }

        return array_slice($gaps, 0, 5);
    }

    private function persistAudit(ReportContext $ctx, TrustResult $result, ?string $runId): void
    {
        if ($ctx->runMode === 'dry_run') {
            return;
        }

        if (! DatabaseTableAvailability::has('ai_data_confidence_audit')) {
            return; // table missing — fail-open path; nothing to persist
        }

        try {
            AiDataConfidenceAudit::query()->create([
                'run_id' => $runId,
                'report_date' => $ctx->windowStart->toDateString(),
                'report_type' => $ctx->reportType,
                'trust_score' => $result->trustScore,
                'trust_level' => $result->trustLevel,
                'coverage' => $result->coverage,
                'usable_for_attribution' => $result->usableForAttribution,
                'aggregator_version' => $result->aggregatorVersion,
                'mixed_aggregator_versions' => $result->mixedAggregatorVersions,
                'sample_count' => $result->sampleCount,
                'score_components' => $result->scoreComponents,
                'audit_trail' => $result->auditTrail,
                'evaluated_at' => $ctx->clock,
            ]);
        } catch (Throwable $e) {
            Log::warning('TrustGateService::persistAudit failed; not blocking report', [
                'exception' => $e->getMessage(),
                'report_date' => $ctx->windowStart->toDateString(),
            ]);
        }
    }
}
