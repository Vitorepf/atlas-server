<?php

namespace App\Services\Ai\Telemetry\Engine;

use App\Models\AiMetricDailySnapshot;
use App\Services\Ai\Telemetry\Engine\Dto\ReportContext;
use App\Services\Ai\Telemetry\Engine\Dto\StatisticalResult;
use App\Services\Ai\Telemetry\Engine\Dto\TrustResult;
use App\Services\Ai\Telemetry\Engine\Dto\WindowAggregates;
use App\Services\Ai\RuntimeBoundary\StatsEngineRuntimeClient;
use App\Services\Ai\Support\DatabaseTableAvailability;
use App\Services\Ai\Telemetry\Engine\Stats\BootstrapCalculator;
use App\Services\Ai\Telemetry\Engine\Stats\WilsonCalculator;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Layer 3 of the Report Engine. Consumes today's WindowAggregates + historical
 * snapshots from ai_metric_daily_snapshots. Produces anomalies/trends/baselines
 * for Agent 4 (Diagnostic) and Agent 5 (Recommendation Lifecycle).
 *
 * Cross-version protection (Decisão #11): skips analysis entirely when the
 * window has mixed aggregator versions. Trust degraded gracefully via empty
 * result with explicit skip_reason.
 *
 * Snapshot strategy: read up to 30 trailing days from ai_metric_daily_snapshots
 * filtered to the same aggregator_version as the live window. Today's value
 * comes from the live scorecard via WindowAggregates. Multi-window analysis just
 * uses different slices of the same snapshot read.
 *
 * Metrics analyzed (subset of scorecard.totals — extended over time):
 *   - final_quality_avg (up=good)
 *   - final_efficiency_avg (up=good)
 *   - first_pass_success_rate (up=good)
 *   - needed_remediation_rate (up=bad)
 *   - tool_failure_rate (up=bad, modern diagnostics only)
 *   - permission_denial_rate (up=bad, modern diagnostics only)
 *   - app_visible_avg_ms (up=bad)
 */
class StatisticalAnalysisService
{
    public const TRAILING_DAYS = 30;

    /** Metric → polarity. up=bad means anomaly direction "above baseline" is the worry. */
    public const METRIC_POLARITY = [
        'final_quality_avg' => 'up_good',
        'final_efficiency_avg' => 'up_good',
        'first_pass_success_rate' => 'up_good',
        'needed_remediation_rate' => 'up_bad',
        'tool_failure_rate' => 'up_bad',           // modern diagnostics only
        'permission_denial_rate' => 'up_bad',      // modern diagnostics only
        'app_visible_avg_ms' => 'up_bad',
    ];

    /** Metrics that depend on score_components.tools (Fix 7c) — skip on legacy windows. */
    public const MODERN_DIAGNOSTIC_METRICS = ['tool_failure_rate', 'permission_denial_rate'];

    public function __construct(
        // The EWMA/Mann-Kendall/CUSUM numerics now live in numpy behind the
        // boundary; this service issues ONE batched boundary call per report
        // (all metrics × all detectors in a single subprocess) instead of one
        // subprocess per (metric, detector). The report engine is CLI/scheduled,
        // not a synchronous request path, so a per-report subprocess is fine.
        private readonly StatsEngineRuntimeClient $stats = new StatsEngineRuntimeClient,
        private readonly BootstrapCalculator $bootstrap = new BootstrapCalculator,
        private readonly WilsonCalculator $wilson = new WilsonCalculator,
    ) {}

    public function analyze(
        ReportContext $ctx,
        WindowAggregates $aggregates,
        TrustResult $trust,
    ): StatisticalResult {
        try {
            return $this->analyzeInternal($ctx, $aggregates, $trust);
        } catch (Throwable $e) {
            Log::warning('StatisticalAnalysisService failed; returning empty result', [
                'exception' => $e->getMessage(),
                'report_date' => $ctx->windowStart->toDateString(),
                'trace' => substr((string) $e, 0, 500),
            ]);

            return StatisticalResult::empty('exception:'.class_basename($e));
        }
    }

    private function analyzeInternal(ReportContext $ctx, WindowAggregates $aggregates, TrustResult $trust): StatisticalResult
    {
        if (! DatabaseTableAvailability::has('ai_metric_daily_snapshots')) {
            return StatisticalResult::empty('snapshot_table_missing');
        }

        if ($aggregates->hasMixedAggregatorVersions) {
            return StatisticalResult::empty('mixed_aggregator_versions');
        }

        $totals = (array) ($aggregates->scorecard['totals'] ?? []);
        $anomalies = [];
        $trends = [];
        $baselines = [];

        // ── Pass 1: assemble each metric's series + enqueue every detector job ──
        // One batched boundary call computes EWMA + Mann-Kendall + CUSUM for all
        // metrics in a single Python subprocess (instead of one subprocess per
        // (metric, detector)). CUSUM is enqueued unconditionally because its
        // result is cheap and only consumed when the trend fired — this keeps the
        // whole report to ONE subprocess while preserving exact output semantics.
        $metricRows = [];
        $jobs = [];
        foreach (self::METRIC_POLARITY as $metric => $polarity) {
            // Skip modern-diagnostic metrics on legacy windows (defense even if mixed check passed).
            if (in_array($metric, self::MODERN_DIAGNOSTIC_METRICS, true) && ! $aggregates->supportsModernDiagnostics()) {
                continue;
            }

            $todayValue = $this->extractTodayValue($totals, $metric);
            if ($todayValue === null) {
                continue;
            }

            $series = $this->loadHistoricalSeries($ctx, $metric, $aggregates->aggregatorVersion);
            $series[] = $todayValue;

            $metricRows[$metric] = [
                'polarity' => $polarity,
                'today_value' => $todayValue,
                'series' => $series,
            ];
            $jobs[] = ['id' => 'ewma:'.$metric, 'op' => 'ewma', 'series' => array_values($series)];
            $jobs[] = ['id' => 'mk:'.$metric, 'op' => 'mann_kendall', 'series' => array_values($series)];
            $jobs[] = ['id' => 'cusum:'.$metric, 'op' => 'cusum', 'series' => array_values($series)];
        }

        // Single batched boundary call — REAL numpy stats or an honest failure
        // (no PHP fallback math; the catch in analyze() degrades to empty result).
        $computed = $this->stats->computeBatch($jobs);

        // ── Pass 2: assemble anomalies / trends / baselines from the batch ─────
        foreach ($metricRows as $metric => $row) {
            $polarity = $row['polarity'];
            $todayValue = $row['today_value'];
            $series = $row['series'];

            $ewmaResult = $computed['ewma:'.$metric] ?? [];
            $trendResult = $computed['mk:'.$metric] ?? [];

            // Anomaly detection (today vs baseline)
            if (($ewmaResult['anomaly'] ?? false) === true) {
                $anomalies[] = [
                    'metric' => $metric,
                    'polarity' => $polarity,
                    'today_value' => $todayValue,
                    'baseline_ewma' => $ewmaResult['baseline_ewma'] ?? null,
                    'sigma' => $ewmaResult['sigma'] ?? null,
                    'z_score' => $ewmaResult['z_score'] ?? null,
                    'severity' => abs($ewmaResult['z_score'] ?? 0) > 3.5 ? 'critical' : 'warning',
                ];
            }

            // Trend over the historical window
            if (($trendResult['suppressed'] ?? true) === false && ($trendResult['direction'] ?? 'none') !== 'none') {
                $cusumResult = $computed['cusum:'.$metric] ?? [];
                $trends[] = [
                    'metric' => $metric,
                    'polarity' => $polarity,
                    'window_days' => count($series),
                    'direction' => $trendResult['direction'],
                    'p_value' => $trendResult['p_value'] ?? null,
                    'sens_slope' => $trendResult['sens_slope'] ?? null,
                    'change_point' => ($cusumResult['fired'] ?? false) ? [
                        'index' => $cusumResult['change_point_index'] ?? null,
                        'direction' => $cusumResult['direction'] ?? 'none',
                        'magnitude_sigma' => $cusumResult['magnitude_sigma'] ?? null,
                    ] : null,
                ];
            }

            // Baseline for Agent 5 to measure recommendation effectiveness against
            $baselines[] = [
                'metric' => $metric,
                'polarity' => $polarity,
                'mean' => $ewmaResult['baseline_ewma'] ?? $todayValue,
                'sigma' => $ewmaResult['sigma'] ?? null,
                'sample_n' => count($series),
                'confidence' => $ewmaResult['confidence'] ?? 'cold_start',
                'today_value' => $todayValue,
            ];
        }

        return new StatisticalResult(
            anomalies: $anomalies,
            trends: $trends,
            baselines: $baselines,
            meta: [
                'analyzed_at' => $ctx->clock->toIso8601String(),
                'aggregator_version' => $aggregates->aggregatorVersion,
                'trailing_days_loaded' => self::TRAILING_DAYS,
                'trust_score_at_analysis' => $trust->trustScore,
            ],
        );
    }

    private function extractTodayValue(array $totals, string $metric): ?float
    {
        // Mapping from our normalized metric names to scorecard total keys.
        $aliases = [
            'app_visible_avg_ms' => 'app_visible_avg_ms',
        ];
        $key = $aliases[$metric] ?? $metric;
        $value = $totals[$key] ?? null;

        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * Load up to TRAILING_DAYS daily values for a metric, filtering to the
     * exact live aggregator version so trend windows never mix schemas.
     *
     * @return array<int,float>
     */
    private function loadHistoricalSeries(ReportContext $ctx, string $metric, string $aggregatorVersion): array
    {
        $since = $ctx->windowStart->subDays(self::TRAILING_DAYS);

        return AiMetricDailySnapshot::query()
            ->where('metric', $metric)
            ->where('snapshot_date', '>=', $since->toDateString())
            ->where('snapshot_date', '<', $ctx->windowStart->toDateString())
            ->where('aggregator_version', $aggregatorVersion)
            ->orderBy('snapshot_date')
            ->get()
            ->pluck('value_mean')
            ->filter(fn ($v) => $v !== null)
            ->map(fn ($v) => (float) $v)
            ->values()
            ->all();
    }
}
