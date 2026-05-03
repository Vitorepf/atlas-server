<?php

namespace App\Services\Ai\Telemetry\Engine\Dto;

/**
 * Output of StatisticalAnalysisService::analyze().
 *
 * Three flat arrays consumed by Agent 4 (Diagnostic) and Agent 5 (Recommendation):
 *  - anomalies[]: today's per-metric EWMA findings (z-score above threshold)
 *  - trends[]:    Mann-Kendall + CUSUM findings over windows
 *  - baselines[]: per-metric/window baseline values (mean, sigma, sample_n)
 *
 * Per Decisão #11: cross-version protection — when WindowAggregates has mixed
 * aggregator versions, statistical analysis is skipped. Metrics tied to modern
 * diagnostics are also skipped for legacy pure windows. The result still emits,
 * just without unsupported metrics.
 */
final readonly class StatisticalResult
{
    /**
     * @param  array<int,array<string,mixed>>  $anomalies
     * @param  array<int,array<string,mixed>>  $trends
     * @param  array<int,array<string,mixed>>  $baselines
     * @param  array<string,mixed>  $meta
     */
    public function __construct(
        public array $anomalies,
        public array $trends,
        public array $baselines,
        public array $meta = [],
    ) {}

    public static function empty(string $reason): self
    {
        return new self(
            anomalies: [],
            trends: [],
            baselines: [],
            meta: ['skipped' => true, 'skip_reason' => $reason],
        );
    }

    public function isSkipped(): bool
    {
        return (bool) ($this->meta['skipped'] ?? false);
    }
}
