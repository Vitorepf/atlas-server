<?php

namespace App\Services\Ai\Telemetry\Engine;

use App\Services\Ai\Telemetry\Engine\Dto\DiagnosticFinding;
use App\Services\Ai\Telemetry\Engine\Dto\DiagnosticResult;
use App\Services\Ai\Telemetry\Engine\Dto\ReportContext;
use App\Services\Ai\Telemetry\Engine\Dto\StatisticalResult;
use App\Services\Ai\Telemetry\Engine\Dto\TrustResult;
use App\Services\Ai\Telemetry\Engine\Dto\WindowAggregates;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Layer 4 — Diagnostic Attribution Engine.
 *
 * Consumes anomalies from StatisticalAnalysisService. For each anomaly, runs
 * GEVSS (Greedy Explained-Variance Subgroup Search) to identify the smallest
 * dimension cut that explains the largest fraction of the regression.
 *
 * GEVSS algorithm (per design):
 *   for round = 1..MAX_DEPTH:
 *     for each candidate dimension allowed by trust gate:
 *       for each value of that dimension in regression slice:
 *         if subgroup_n < MIN_N or coverage < MIN_COVERAGE: skip
 *         compute explained = (subgroup_delta * subgroup_n) / (total_delta * total_n)
 *         track best
 *     if best gain below GAIN_THRESHOLD or marginal gain too small: stop
 *     append best dim+value to selected, narrow current subgroup
 *
 * Decisão #10: Min explained_fraction = 0.35 to surface a finding (config-driven).
 * Decisão #12: Trust gate fail-open — if Agent 1 unavailable, attribute against
 *              all flat dimensions.
 */
class DiagnosticAttributionService
{
    public const MAX_DEPTH = 3;
    public const MIN_N_PER_SUBGROUP = 5;        // Pragmatic: matches Trust Gate's MIN_TRACES_DIMENSION
    public const MIN_COVERAGE_PCT = 0.05;       // Subgroup must be at least 5% of population
    public const MAX_COVERAGE_PCT = 0.85;       // Subgroup of >85% IS the population — not an attribution
    public const MARGINAL_GAIN_THRESHOLD = 0.05; // Stop when next round adds less than 5%

    /** Flat dimensions GEVSS considers as candidates. */
    public const FLAT_DIMENSIONS = [
        'provider', 'model', 'agent_slug', 'task_type', 'surface',
        'router_mode', 'router_selected_provider', 'cost_mode',
    ];

    public function diagnose(
        ReportContext $ctx,
        WindowAggregates $current,
        WindowAggregates $previous,
        StatisticalResult $statistical,
        TrustResult $trust,
        ?string $runId = null,
    ): DiagnosticResult {
        try {
            return $this->diagnoseInternal($ctx, $current, $previous, $statistical, $trust, $runId);
        } catch (Throwable $e) {
            Log::warning('DiagnosticAttributionService failed; returning empty result', [
                'exception' => $e->getMessage(),
                'trace' => substr((string) $e, 0, 500),
            ]);

            return DiagnosticResult::empty('exception:'.class_basename($e));
        }
    }

    private function diagnoseInternal(
        ReportContext $ctx,
        WindowAggregates $current,
        WindowAggregates $previous,
        StatisticalResult $statistical,
        TrustResult $trust,
        ?string $runId,
    ): DiagnosticResult {
        if ($statistical->isSkipped() || empty($statistical->anomalies)) {
            return new DiagnosticResult([], false, ['no_anomalies_to_diagnose' => true]);
        }

        $minGain = (float) config('atlas.report.finding_min_gain_fraction', 0.35);
        $allowedDims = $this->allowedDimensions($trust);

        $findings = [];
        foreach ($statistical->anomalies as $anomaly) {
            $finding = $this->attributeAnomaly($ctx, $anomaly, $current, $previous, $allowedDims, $minGain);
            if ($finding !== null) {
                $findings[] = $finding;
            }
        }

        $persisted = $this->persistFindings($ctx, $findings, $runId);

        return new DiagnosticResult($findings, $persisted, [
            'anomalies_input_count' => count($statistical->anomalies),
            'findings_output_count' => count($findings),
            'min_gain_threshold' => $minGain,
            'allowed_dimensions' => $allowedDims,
        ]);
    }

    /**
     * Filter candidate dimensions by trust gate's per-dimension usable_for_attribution.
     * Per Decisão #12: when trust gate is skipped, fail-open — use ALL flat dims.
     */
    private function allowedDimensions(TrustResult $trust): array
    {
        if ($trust->skipped || empty($trust->dimensions)) {
            return self::FLAT_DIMENSIONS;
        }

        return array_values(array_filter(
            self::FLAT_DIMENSIONS,
            fn (string $dim): bool => $trust->dimensions[$dim] ?? false,
        ));
    }

    private function attributeAnomaly(
        ReportContext $ctx,
        array $anomaly,
        WindowAggregates $current,
        WindowAggregates $previous,
        array $allowedDims,
        float $minGain,
    ): ?DiagnosticFinding {
        $metric = (string) $anomaly['metric'];
        $summaryColumn = $this->summaryColumnForMetric($metric);
        $todayValue = (float) ($anomaly['today_value'] ?? 0);
        $baselineValue = (float) ($anomaly['baseline_ewma'] ?? 0);

        if ($summaryColumn === null || ! DatabaseTableAvailability::hasColumn('ai_trace_metric_summaries', $summaryColumn)) {
            return null;
        }

        $totalDelta = $todayValue - $baselineValue;
        if (abs($totalDelta) < 1e-6) {
            return null;
        }

        // GEVSS greedy search
        $selectedDims = [];
        $currentSubset = $current->summaries;
        $previousSubset = $previous->summaries;
        $bestExplainedFraction = 0.0;
        $previousGain = 0.0;

        for ($round = 1; $round <= self::MAX_DEPTH; $round++) {
            $bestDim = null;
            $bestVal = null;
            $bestGain = $previousGain;
            $bestSubsetCurrent = null;
            $bestSubsetPrevious = null;

            $candidates = array_diff($allowedDims, array_keys($selectedDims));
            foreach ($candidates as $dim) {
                if (! DatabaseTableAvailability::hasColumn('ai_trace_metric_summaries', $dim)) {
                    continue;
                }

                $values = $currentSubset->pluck($dim)->filter()->unique();
                foreach ($values as $val) {
                    $subgroupCurrent = $currentSubset->filter(fn ($s) => $s->{$dim} === $val);
                    $subgroupPrevious = $previousSubset->filter(fn ($s) => $s->{$dim} === $val);

                    $subgroupN = $subgroupCurrent->count();
                    if ($subgroupN < self::MIN_N_PER_SUBGROUP) {
                        continue;
                    }
                    $coverage = $subgroupN / max(1, $current->summaries->count());
                    if ($coverage < self::MIN_COVERAGE_PCT) {
                        continue;
                    }
                    // Subgroup that IS the population doesn't attribute anything.
                    // Skip when coverage > MAX so GEVSS finds real concentration.
                    if ($coverage > self::MAX_COVERAGE_PCT) {
                        continue;
                    }

                    $subgroupCurrentMean = $this->meanFor($subgroupCurrent, $summaryColumn);
                    $subgroupBaselineMean = $subgroupPrevious->isEmpty()
                        ? $baselineValue
                        : $this->meanFor($subgroupPrevious, $summaryColumn);

                    if ($subgroupCurrentMean === null || $subgroupBaselineMean === null) {
                        continue;
                    }

                    $subgroupDelta = $subgroupCurrentMean - $subgroupBaselineMean;
                    // Explained fraction: (subgroup_delta × subgroup_n) / (total_delta × total_n)
                    $explained = ($subgroupDelta * $subgroupN) / ($totalDelta * max(1, $current->summaries->count()));
                    $explained = abs($explained);

                    if ($explained > $bestGain) {
                        $bestDim = $dim;
                        $bestVal = $val;
                        $bestGain = $explained;
                        $bestSubsetCurrent = $subgroupCurrent;
                        $bestSubsetPrevious = $subgroupPrevious;
                    }
                }
            }

            if ($bestDim === null) {
                break;
            }
            if ($round > 1 && ($bestGain - $previousGain) < self::MARGINAL_GAIN_THRESHOLD) {
                break;
            }

            $selectedDims[$bestDim] = $bestVal;
            $currentSubset = $bestSubsetCurrent;
            $previousSubset = $bestSubsetPrevious;
            $previousGain = $bestGain;
            $bestExplainedFraction = $bestGain;
        }

        if (empty($selectedDims) || $bestExplainedFraction < $minGain) {
            return null;
        }

        $confidenceScore = $this->confidenceScore($currentSubset->count(), $bestExplainedFraction);
        $confidenceBand = match (true) {
            $confidenceScore >= 0.72 => 'high',
            $confidenceScore >= 0.45 => 'medium',
            default => 'low',
        };

        return new DiagnosticFinding(
            id: (string) Str::uuid(),
            metric: $metric,
            direction: $totalDelta > 0 ? 'up' : 'down',
            magnitudePct: $baselineValue !== 0.0 ? round(abs($totalDelta / $baselineValue), 4) : null,
            attributionDimensions: $selectedDims,
            explainedFraction: round($bestExplainedFraction, 4),
            affectedN: $currentSubset->count(),
            evidence: [
                'sample_trace_ids' => $currentSubset->pluck('trace_id')->take(10)->values()->all(),
                'window_start' => $ctx->windowStart->toIso8601String(),
                'window_end' => $ctx->windowEnd->toIso8601String(),
                'baseline_value' => $baselineValue,
                'today_value' => $todayValue,
            ],
            signals: $this->correlatedSignals($currentSubset, $previousSubset),
            confidenceScore: round($confidenceScore, 4),
            confidenceBand: $confidenceBand,
            suggestedActionSeed: $this->buildActionSeed($metric, $selectedDims),
        );
    }

    private function meanFor(Collection $summaries, string $metric): ?float
    {
        $values = $summaries->pluck($metric)
            ->filter(fn ($v): bool => is_numeric($v) || is_bool($v))
            ->map(fn ($v): float => is_bool($v) ? ($v ? 1.0 : 0.0) : (float) $v);
        if ($values->isEmpty()) {
            return null;
        }
        return $values->avg();
    }

    private function summaryColumnForMetric(string $metric): ?string
    {
        if (DatabaseTableAvailability::hasColumn('ai_trace_metric_summaries', $metric)) {
            return $metric;
        }

        return match ($metric) {
            'final_quality_avg' => 'final_quality_score',
            'final_efficiency_avg' => 'final_efficiency_score',
            'app_visible_avg_ms' => 'app_send_to_visible_ms',
            'first_pass_success_rate' => 'first_pass_success',
            'needed_remediation_rate' => 'needed_remediation',
            default => null,
        };
    }

    private function confidenceScore(int $affectedN, float $explainedFraction): float
    {
        $base = 1.0;
        $sampleFactor = match (true) {
            $affectedN >= 500 => 1.0,
            $affectedN >= 200 => 0.90,
            $affectedN >= 80 => 0.75,
            $affectedN >= 30 => 0.60,
            default => 0.40,
        };
        $coverageFactor = min(1.0, $explainedFraction / 0.60);
        $detectionFactor = 0.85; // EWMA z_score baseline; future: bump to 1.0 when CUSUM provides onset

        return $base * $sampleFactor * $coverageFactor * $detectionFactor;
    }

    /**
     * Cross-signal correlations: tool failures + quality flags appearing
     * disproportionately in the affected subgroup vs. baseline subgroup.
     */
    private function correlatedSignals(Collection $affected, Collection $baseline): array
    {
        $signals = [];

        // Quality flag prevalence — read from score_components.flags (Fix 7c)
        $flagCounts = $affected
            ->flatMap(fn ($s) => (array) data_get($s->score_components ?? [], 'flags', []))
            ->countBy()
            ->all();

        $totalAffected = max(1, $affected->count());
        foreach ($flagCounts as $flag => $count) {
            $rate = $count / $totalAffected;
            if ($rate >= 0.50 && $count >= 5) {
                $signals[] = [
                    'type' => 'quality_flag_prevalence',
                    'flag' => $flag,
                    'rate_in_affected' => round($rate, 3),
                    'note' => sprintf('%.0f%% of affected traces carry flag=%s', $rate * 100, $flag),
                ];
            }
        }

        return $signals;
    }

    private function buildActionSeed(string $metric, array $dimensions): string
    {
        $dimDesc = collect($dimensions)
            ->map(fn ($v, $k) => "{$k}={$v}")
            ->implode(' × ');

        return "Investigate {$metric} regression concentrated in {$dimDesc}";
    }

    /**
     * Persist via DB::table() to preserve the DTO's UUID. HasUuids on the model
     * overrides explicit id values in the creating hook — incompatible with
     * Agent 5's contract that requires stable IDs from DTO to DB.
     *
     * @param  array<int,DiagnosticFinding>  $findings
     */
    private function persistFindings(ReportContext $ctx, array $findings, ?string $runId): bool
    {
        if ($ctx->runMode === 'dry_run') {
            return false;
        }

        if (empty($findings) || ! DatabaseTableAvailability::has('ai_report_findings')) {
            return false;
        }

        try {
            $now = $ctx->clock;
            foreach ($findings as $f) {
                \Illuminate\Support\Facades\DB::table('ai_report_findings')->insert([
                    'id' => $f->id,
                    'run_id' => $runId,
                    'report_date' => $ctx->windowStart->toDateString(),
                    'report_type' => $ctx->reportType,
                    'metric' => $f->metric,
                    'direction' => $f->direction,
                    'magnitude_pct' => $f->magnitudePct,
                    'explained_fraction' => $f->explainedFraction,
                    'affected_n' => $f->affectedN,
                    'attribution_dimensions' => json_encode($f->attributionDimensions),
                    'evidence' => json_encode($f->evidence),
                    'signals' => json_encode($f->signals),
                    'confidence_score' => $f->confidenceScore,
                    'confidence_band' => $f->confidenceBand,
                    'suggested_action_seed' => $f->suggestedActionSeed,
                    'created_at' => $now,
                ]);
            }
            return true;
        } catch (Throwable $e) {
            Log::warning('Diagnostic finding persistence failed', ['exception' => $e->getMessage()]);
            return false;
        }
    }
}
