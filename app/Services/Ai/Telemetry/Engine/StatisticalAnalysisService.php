<?php

namespace App\Services\Ai\Telemetry\Engine;

use App\Models\AiMetricDailySnapshot;
use App\Services\Ai\Telemetry\Engine\Dto\ReportContext;
use App\Services\Ai\Telemetry\Engine\Dto\StatisticalResult;
use App\Services\Ai\Telemetry\Engine\Dto\TrustResult;
use App\Services\Ai\Telemetry\Engine\Dto\WindowAggregates;
use App\Services\Ai\Telemetry\Engine\Stats\BootstrapCalculator;
use App\Services\Ai\Telemetry\Engine\Stats\CusumDetector;
use App\Services\Ai\Telemetry\Engine\Stats\EwmaDetector;
use App\Services\Ai\Telemetry\Engine\Stats\MannKendallAnalyzer;
use App\Services\Ai\Telemetry\Engine\Stats\WilsonCalculator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Layer 3 of the Report Engine. Consumes today's WindowAggregates + historical
 * snapshots from ai_metric_daily_snapshots. Produces anomalies/trends/baselines
 * for Agent 4 (Diagnostic) and Agent 5 (Recommendation Lifecycle).
 *
 * Cross-version protection (Decisão #11): skips analysis entirely when the
 * window has mixed v1/v2 aggregator versions. Trust degraded gracefully via
 * empty result with explicit skip_reason.
 *
 * Snapshot strategy: read up to 30 trailing days from ai_metric_daily_snapshots
 * (filtered to aggregator_version=v2). Today's value comes from the live scorecard
 * via WindowAggregates. Multi-window analysis just uses different slices of the
 * same snapshot read.
 *
 * Metrics analyzed (subset of scorecard.totals — extended over time):
 *   - final_quality_avg (up=good)
 *   - final_efficiency_avg (up=good)
 *   - first_pass_success_rate (up=good)
 *   - needed_remediation_rate (up=bad)
 *   - tool_failure_rate (up=bad, v2-only)
 *   - permission_denial_rate (up=bad, v2-only)
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
        'tool_failure_rate' => 'up_bad',           // v2-only
        'permission_denial_rate' => 'up_bad',      // v2-only
        'app_visible_avg_ms' => 'up_bad',
    ];

    /** Metrics that depend on score_components.tools (Fix 7c) — skip on v1 windows. */
    public const V2_ONLY_METRICS = ['tool_failure_rate', 'permission_denial_rate'];

    public function __construct(
        private readonly EwmaDetector $ewma = new EwmaDetector,
        private readonly MannKendallAnalyzer $mannKendall = new MannKendallAnalyzer,
        private readonly CusumDetector $cusum = new CusumDetector,
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
        if (! Schema::hasTable('ai_metric_daily_snapshots')) {
            return StatisticalResult::empty('snapshot_table_missing');
        }

        if ($aggregates->hasMixedAggregatorVersions) {
            return StatisticalResult::empty('mixed_aggregator_versions');
        }

        $totals = (array) ($aggregates->scorecard['totals'] ?? []);
        $anomalies = [];
        $trends = [];
        $baselines = [];

        foreach (self::METRIC_POLARITY as $metric => $polarity) {
            // Skip v2-only metrics on v1 windows (defense even if mixed check passed)
            if (in_array($metric, self::V2_ONLY_METRICS, true) && ! $aggregates->isV2()) {
                continue;
            }

            $todayValue = $this->extractTodayValue($totals, $metric);
            if ($todayValue === null) {
                continue;
            }

            $series = $this->loadHistoricalSeries($ctx, $metric);
            $series[] = $todayValue;

            // Anomaly detection (today vs baseline)
            $ewmaResult = $this->ewma->detect($series);
            if ($ewmaResult['anomaly']) {
                $anomalies[] = [
                    'metric' => $metric,
                    'polarity' => $polarity,
                    'today_value' => $todayValue,
                    'baseline_ewma' => $ewmaResult['baseline_ewma'],
                    'sigma' => $ewmaResult['sigma'],
                    'z_score' => $ewmaResult['z_score'],
                    'severity' => abs($ewmaResult['z_score'] ?? 0) > 3.5 ? 'critical' : 'warning',
                ];
            }

            // Trend over the historical window
            $trendResult = $this->mannKendall->test($series);
            if (! $trendResult['suppressed'] && $trendResult['direction'] !== 'none') {
                $cusumResult = $this->cusum->detect($series);
                $trends[] = [
                    'metric' => $metric,
                    'polarity' => $polarity,
                    'window_days' => count($series),
                    'direction' => $trendResult['direction'],
                    'p_value' => $trendResult['p_value'],
                    'sens_slope' => $trendResult['sens_slope'],
                    'change_point' => $cusumResult['fired'] ? [
                        'index' => $cusumResult['change_point_index'],
                        'direction' => $cusumResult['direction'],
                        'magnitude_sigma' => $cusumResult['magnitude_sigma'],
                    ] : null,
                ];
            }

            // Baseline for Agent 5 to measure recommendation effectiveness against
            $baselines[] = [
                'metric' => $metric,
                'polarity' => $polarity,
                'mean' => $ewmaResult['baseline_ewma'] ?? $todayValue,
                'sigma' => $ewmaResult['sigma'],
                'sample_n' => count($series),
                'confidence' => $ewmaResult['confidence'],
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
     * Load up to TRAILING_DAYS daily values for a metric, filtering to v2-only.
     * @return array<int,float>
     */
    private function loadHistoricalSeries(ReportContext $ctx, string $metric): array
    {
        $since = $ctx->windowStart->subDays(self::TRAILING_DAYS);

        return AiMetricDailySnapshot::query()
            ->where('metric', $metric)
            ->where('snapshot_date', '>=', $since->toDateString())
            ->where('snapshot_date', '<', $ctx->windowStart->toDateString())
            ->where('aggregator_version', 'ai_trace_metric_aggregator_v2')
            ->orderBy('snapshot_date')
            ->get()
            ->pluck('value_mean')
            ->filter(fn ($v) => $v !== null)
            ->map(fn ($v) => (float) $v)
            ->values()
            ->all();
    }
}
