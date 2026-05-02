<?php

namespace App\Services\Ai\Telemetry\Engine\Dto;

use App\Models\AiTraceMetricSummary;
use Illuminate\Support\Collection;

/**
 * Bridge DTO between the legacy aggregation layer (Layers 0-1: AiTelemetryCollector
 * + AiTraceMetricAggregator) and the new engine layers (2-6).
 *
 * The orchestrator builds this once per window by calling the existing services
 * (AiTelemetryScorecardService, AiTelemetryHealthService) and pre-loading the
 * summaries Collection. All five new layers receive the SAME WindowAggregates
 * instance — no duplicate queries, no inconsistency between layers reading
 * different "current" snapshots.
 *
 * aggregatorVersion is the cross-version protection signal. The statistical and
 * diagnostic layers must check this before computing trends, because v1 traces
 * lack score_components.tools / .router / .diagnostics — mixing them in a trend
 * window produces silently wrong results.
 */
final readonly class WindowAggregates
{
    /**
     * @param  array<string,mixed>  $scorecard  output of AiTelemetryScorecardService::build()
     * @param  array<string,mixed>  $health     output of AiTelemetryHealthService::evaluate()
     * @param  array<string,mixed>  $summary    summary block assembled by the orchestrator
     * @param  Collection<int,AiTraceMetricSummary>  $summaries  in-memory collection for the window
     * @param  string  $aggregatorVersion  the dominant aggregator_version across summaries
     *                                     ('ai_trace_metric_aggregator_v1' or 'v2')
     * @param  bool  $hasMixedAggregatorVersions  true if window contains both v1 and v2 traces
     */
    public function __construct(
        public array $scorecard,
        public array $health,
        public array $summary,
        public Collection $summaries,
        public string $aggregatorVersion,
        public bool $hasMixedAggregatorVersions = false,
    ) {}

    public function isV2(): bool
    {
        return $this->aggregatorVersion === 'ai_trace_metric_aggregator_v2'
            && ! $this->hasMixedAggregatorVersions;
    }

    public function traceCount(): int
    {
        return $this->summaries->count();
    }
}
