<?php

namespace App\Services\Ai\Telemetry;

use App\Models\AiInboxItem;
use App\Models\AiProviderHealthSnapshot;
use App\Models\AiTraceMetricSummary;
use App\Services\Ai\Mobile\AtlasInboxService;
use App\Services\Ai\Mobile\ContextBundleService;
use App\Services\Ai\Support\DatabaseTableAvailability;
use App\Services\Ai\Telemetry\Engine\Dto\ReportContext;
use App\Services\Ai\Telemetry\Engine\Dto\WindowAggregates;
use App\Services\Ai\Telemetry\Engine\EngineOrchestrator;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class AiTelemetryPerformanceReportService
{
    private ?AiTelemetryReportBodyRenderer $reportBodyRendererInstance = null;
    public function __construct(
        private readonly AiTelemetryScorecardService $scorecards,
        private readonly AiTelemetryHealthService $health,
        private readonly ContextBundleService $bundles,
        private readonly AtlasInboxService $inbox,
        private readonly EngineOrchestrator $engine,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function buildDaily(CarbonInterface|string|null $reportDate = null, ?string $timezone = null): array
    {
        $timezone = $this->timezone($timezone);
        $date = $this->reportDate($reportDate, $timezone);
        $start = $date->startOfDay();
        $end = $start->addDay();
        $previousStart = $start->subDay();

        $current = $this->windowAnalysis($start, $end, $timezone, 'daily');
        $previous = $this->windowAnalysis($previousStart, $start, $timezone);
        $comparison = $this->compareSummaries($current['summary'], $previous['summary']);
        $actions = $this->dailyActions($current, $comparison);
        $status = $this->statusFromHealth((string) data_get($current, 'health.status', 'unknown'), (int) data_get($current, 'summary.traces', 0));
        $title = 'Atlas: relatorio de performance de '.$date->format('d/m/Y');
        $summary = $this->dailySummaryText($current, $comparison);
        $body = $this->dailyBody($date, $timezone, $current, $previous, $comparison, $actions);
        $enginePayload = is_array($current['engine'] ?? null) ? $current['engine'] : null;

        return [
            'report_type' => 'atlas_ai_daily_performance',
            'schema_version' => $this->schemaVersionFor($enginePayload),
            'engine_version' => $this->engineVersion(),
            'generated_at' => now($timezone)->toJSON(),
            'timezone' => $timezone,
            'report_date' => $date->toDateString(),
            'window' => $current['window'],
            'status' => $status,
            'health_score' => data_get($current, 'health.health_score'),
            'title' => $title,
            'summary_text' => $summary,
            'body' => $body,
            'executive_summary' => $summary,
            'scorecard' => $current['scorecard'],
            'health' => $current['health'],
            'summary' => $current['summary'],
            'comparisons' => [
                'previous_day' => [
                    'window' => $previous['window'],
                    'summary' => $previous['summary'],
                    'deltas' => $comparison,
                ],
            ],
            'breakdowns' => $current['breakdowns'],
            'quality' => $current['quality'],
            'efficiency' => $current['efficiency'],
            'reliability' => $current['reliability'],
            'provider_health' => $current['provider_health'],
            'data_quality' => $current['data_quality'],
            // Tools — Fix 7c F5. Top-level so the morning report renderer doesn't
            // need to dig into windowAnalysis. Same shape as in `windows[N]['tools']`
            // for consistency between daily and multi-window reports.
            'tools' => $current['tools'],
            'risks' => $current['risks'],
            'actions' => $actions,
            'notable_traces' => $current['notable_traces'],
            'engine' => $enginePayload,
            'metric_refs' => $this->metricRefs($current['summary']),
            'source_refs' => [
                ['type' => 'ai_trace_metric_summaries', 'window' => $current['window']],
                ['type' => 'ai_traces', 'basis' => 'trace_created_at'],
                ['type' => 'ai_tool_events', 'basis' => 'computed_at'],
            ],
            'dedupe_key' => 'atlas-ai-performance:daily:'.$date->toDateString(),
            'confidence' => $this->confidence($current),
        ];
    }

    /**
     * @param  array<int,int>  $windows
     * @return array<string,mixed>
     */
    public function buildMultiWindow(CarbonInterface|string|null $reportDate = null, array $windows = [3, 7, 15, 30], ?string $timezone = null): array
    {
        $timezone = $this->timezone($timezone);
        $date = $this->reportDate($reportDate, $timezone);
        $end = $date->startOfDay()->addDay();
        $windows = $this->normalizeWindows($windows);
        $largestWindow = max($windows);
        $enginePayload = null;
        $reports = [];

        foreach ($windows as $days) {
            $start = $end->subDays($days);
            $previousStart = $start->subDays($days);
            $current = $this->windowAnalysis($start, $end, $timezone, $days === $largestWindow ? 'multi_window' : null);
            $previous = $this->windowAnalysis($previousStart, $start, $timezone);
            if (is_array($current['engine'] ?? null)) {
                $enginePayload = $current['engine'];
            }

            $reports[] = [
                'days' => $days,
                'label' => "ultimos {$days} dias",
                'window' => $current['window'],
                'status' => $this->statusFromHealth((string) data_get($current, 'health.status', 'unknown'), (int) data_get($current, 'summary.traces', 0)),
                'health_score' => data_get($current, 'health.health_score'),
                'summary' => $current['summary'],
                'comparison_to_previous_same_window' => [
                    'window' => $previous['window'],
                    'summary' => $previous['summary'],
                    'deltas' => $this->compareSummaries($current['summary'], $previous['summary']),
                ],
                'breakdowns' => $current['breakdowns'],
                'quality' => $current['quality'],
                'efficiency' => $current['efficiency'],
                'reliability' => $current['reliability'],
                'provider_health' => $current['provider_health'],
                'data_quality' => $current['data_quality'],
                // Tools — Fix 7c F5. Same shape as the daily report's top-level 'tools'.
                'tools' => $current['tools'],
                'risks' => $current['risks'],
                'actions' => $this->dailyActions($current, $this->compareSummaries($current['summary'], $previous['summary'])),
                'notable_traces' => $current['notable_traces'],
                'engine' => $current['engine'] ?? null,
            ];
        }

        $status = $this->strongestStatus(collect($reports)->pluck('status')->all());
        $title = 'Atlas: desempenho 3/7/15/30 dias ate '.$date->format('d/m/Y');
        $summary = $this->multiSummaryText($reports);
        $actions = $this->multiActions($reports);
        $body = $this->multiBody($date, $timezone, $reports, $actions);

        return [
            'report_type' => 'atlas_ai_multi_window_performance',
            'schema_version' => $this->schemaVersionFor($enginePayload),
            'engine_version' => $this->engineVersion(),
            'generated_at' => now($timezone)->toJSON(),
            'timezone' => $timezone,
            'report_date' => $date->toDateString(),
            'status' => $status,
            'health_score' => $this->averageHealthScore($reports),
            'title' => $title,
            'summary_text' => $summary,
            'body' => $body,
            'executive_summary' => $summary,
            'windows' => $reports,
            'actions' => $actions,
            'engine' => $enginePayload,
            'metric_refs' => $this->multiMetricRefs($reports),
            'source_refs' => [
                ['type' => 'ai_trace_metric_summaries', 'windows' => $windows],
                ['type' => 'ai_traces', 'basis' => 'trace_created_at'],
            ],
            'dedupe_key' => 'atlas-ai-performance:multi:'.$date->toDateString(),
            'confidence' => $this->multiConfidence($reports),
        ];
    }

    /**
     * @return array{emitted:bool,item_id:?string,reason:string,dedupe_key:?string}
     */
    public function emit(array $report, string $userId = 'vitor', bool $dryRun = false): array
    {
        $dedupeKey = is_string($report['dedupe_key'] ?? null) ? $report['dedupe_key'] : null;
        if ($dryRun) {
            return ['emitted' => false, 'item_id' => null, 'reason' => 'dry_run', 'dedupe_key' => $dedupeKey];
        }

        if (! DatabaseTableAvailability::all(['ai_context_bundles', 'ai_inbox_items'])) {
            return ['emitted' => false, 'item_id' => null, 'reason' => 'inbox_unavailable', 'dedupe_key' => $dedupeKey];
        }

        $bundle = $this->bundles->create([
            'user_id' => $userId,
            'purpose' => 'atlas_ai_performance_report',
            'title' => (string) ($report['title'] ?? 'Relatorio de performance do Atlas'),
            'summary' => (string) ($report['summary_text'] ?? 'Relatorio operacional do Atlas.'),
            'body_for_thread' => $this->threadBody($report),
            'source_refs' => $report['source_refs'] ?? [],
            'trace_refs' => $this->traceRefs($report),
            'metric_refs' => $report['metric_refs'] ?? [],
            'raw_payload' => $report,
            'expires_at' => now()->addDays(($report['report_type'] ?? null) === 'atlas_ai_multi_window_performance' ? 45 : 14),
        ]);

        $item = $this->inbox->create([
            'user_id' => $userId,
            'type' => 'insight',
            'category' => 'atlas_ai_performance',
            'severity' => $this->severity((string) ($report['status'] ?? 'unknown')),
            'title' => (string) ($report['title'] ?? 'Relatorio de performance do Atlas'),
            'summary' => Str::limit((string) ($report['summary_text'] ?? ''), 220, '...'),
            'body' => (string) ($report['body'] ?? $report['summary_text'] ?? ''),
            'source_type' => 'atlas_ai_performance_report',
            'initiator' => 'atlas',
            'context_bundle_id' => $bundle->id,
            'dedupe_key' => $dedupeKey,
            'available_actions' => [
                ['id' => 'discuss', 'label' => 'Discutir com Atlas', 'style' => 'primary'],
                ['id' => 'snooze', 'label' => 'Adiar', 'style' => 'default'],
                ['id' => 'dismiss', 'label' => 'Descartar', 'style' => 'default'],
            ],
            'payload' => [
                'category' => 'atlas_ai_performance',
                'insight_kind' => (string) ($report['report_type'] ?? 'atlas_ai_performance_report'),
                'confidence' => (float) ($report['confidence'] ?? 0.75),
                'report' => $this->compactReportPayload($report),
            ],
            'push_policy' => [
                'send' => 'immediate',
                'reason' => (string) ($report['report_type'] ?? 'atlas_ai_performance_report'),
                'force' => true,
            ],
            'priority_score' => $this->priorityScore((string) ($report['status'] ?? 'unknown')),
            'confidence_score' => (float) ($report['confidence'] ?? 0.75),
            'expires_at' => now()->addDays(($report['report_type'] ?? null) === 'atlas_ai_multi_window_performance' ? 45 : 14),
        ]);

        return [
            'emitted' => $item instanceof AiInboxItem,
            'item_id' => $item->id,
            'reason' => 'emitted_or_deduped',
            'dedupe_key' => $dedupeKey,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function windowAnalysis(CarbonImmutable $start, CarbonImmutable $end, string $timezone, ?string $engineReportType = null): array
    {
        $scorecard = $this->scorecards->build($start, $end, 'trace_created_at', true);
        $health = $this->health->evaluate($start, $end, 'trace_created_at', true);
        $summaries = $this->summaries($start, $end);
        $summary = $this->summary($scorecard, $summaries);

        $analysis = [
            'window' => [
                'start' => $start->toJSON(),
                'end' => $end->toJSON(),
                'timezone' => $timezone,
                'basis' => 'trace_created_at',
                'inclusive_start' => true,
                'exclusive_end' => true,
            ],
            'scorecard' => $scorecard,
            'health' => $health,
            'summary' => $summary,
            'breakdowns' => [
                'by_surface' => $scorecard['by_surface'] ?? [],
                'by_provider' => $scorecard['by_provider'] ?? [],
                'by_model' => $scorecard['by_model'] ?? [],
                'by_agent' => $scorecard['by_agent'] ?? [],
                'by_task_type' => $scorecard['by_task_type'] ?? [],
            ],
            'quality' => $this->quality($scorecard, $summaries),
            'efficiency' => $this->efficiency($scorecard, $summaries),
            'reliability' => $this->reliability($scorecard, $summaries),
            'provider_health' => $this->providerHealth($start, $end),
            'data_quality' => $this->dataQuality($scorecard, $health, $summaries),
            // Tools — Fix 7c F5. Surfaces the scorecard's tools block directly (the
            // scorecard is the single source of truth) and adds report-friendly
            // top-N highlights and threshold-aware status.
            'tools' => $this->toolsReport($scorecard),
            'risks' => $this->risks($scorecard, $health, $summary),
            'notable_traces' => $this->notableTraces($summaries),
        ];

        if ($engineReportType !== null) {
            $analysis['engine'] = $this->runEngine($start, $end, $timezone, $engineReportType, $scorecard, $health, $summary, $summaries);
        }

        return $analysis;
    }

    /**
     * @param  Collection<int,AiTraceMetricSummary>  $summaries
     * @return array<string,mixed>|null
     */
    private function runEngine(
        CarbonImmutable $start,
        CarbonImmutable $end,
        string $timezone,
        string $reportType,
        array $scorecard,
        array $health,
        array $summary,
        Collection $summaries,
    ): ?array {
        $engineVersion = $this->engineVersion();
        if ($engineVersion === 'legacy') {
            return null;
        }

        try {
            [$aggregatorVersion, $hasMixedVersions] = $this->aggregatorVersionState($summaries);
            $ctx = new ReportContext(
                clock: CarbonImmutable::now($timezone),
                windowStart: $start,
                windowEnd: $end,
                timezone: $timezone,
                reportType: $reportType,
                engineVersion: $engineVersion,
                runMode: $this->engineRunMode($engineVersion),
            );

            $aggregates = new WindowAggregates(
                scorecard: $scorecard,
                health: $health,
                summary: $summary,
                summaries: $summaries,
                aggregatorVersion: $aggregatorVersion,
                hasMixedAggregatorVersions: $hasMixedVersions,
            );

            return $this->engine->execute($ctx, $aggregates)->toArray();
        } catch (Throwable $e) {
            Log::warning('Atlas report engine execution failed', [
                'report_type' => $reportType,
                'window_start' => $start->toIso8601String(),
                'window_end' => $end->toIso8601String(),
                'engine_version' => $engineVersion,
                'exception' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @param  Collection<int,AiTraceMetricSummary>  $summaries
     * @return array{0:string,1:bool}
     */
    private function aggregatorVersionState(Collection $summaries): array
    {
        $counts = $summaries
            ->map(fn (AiTraceMetricSummary $summary): string => (string) (data_get($summary->metadata, 'aggregator_version') ?: 'unknown'))
            ->filter(fn (string $version): bool => $version !== '')
            ->countBy();

        if ($counts->isEmpty()) {
            return ['unknown', false];
        }

        return [
            (string) $counts->sortDesc()->keys()->first(),
            $counts->count() > 1,
        ];
    }

    private function engineVersion(): string
    {
        $version = (string) config('atlas.report.engine_version', 'legacy');

        return in_array($version, ['legacy', 'shadow', 'next'], true) ? $version : 'legacy';
    }

    private function engineRunMode(string $engineVersion): string
    {
        $runMode = (string) config('atlas.report.engine_run_mode', '');
        if (in_array($runMode, ['live', 'shadow', 'replay', 'dry_run'], true)) {
            return $runMode;
        }

        return $engineVersion === 'shadow' ? 'shadow' : 'live';
    }

    private function schemaVersionFor(?array $enginePayload): int
    {
        if ($this->engineVersion() !== 'next' || $enginePayload === null) {
            return 1;
        }

        return (int) data_get($enginePayload, 'validation.schema_version', 2);
    }

    /**
     * Project the scorecard's tools block into a report-shaped digest:
     *   - status: 'unavailable' | 'idle' | 'healthy' | 'warning' | 'critical'
     *   - top_used: top 5 tools by call count
     *   - top_failures: top 3 tools by failure_rate (with min N=10 floor to suppress noise)
     *   - critical_risk_count: surfaces independently of rates
     *   - permission_denial_rate / tool_failure_rate: pre-rounded for display
     *
     * @param  array<string,mixed>  $scorecard
     * @return array<string,mixed>
     */
    private function toolsReport(array $scorecard): array
    {
        $tools = (array) ($scorecard['tools'] ?? ['available' => false]);
        if (! ($tools['available'] ?? false)) {
            return ['status' => 'unavailable'];
        }

        $totalCalls = (int) ($tools['tool_calls_total'] ?? 0);
        if ($totalCalls === 0) {
            return [
                'status' => 'idle',
                'tool_calls_total' => 0,
                'critical_risk_count' => 0,
            ];
        }

        $denialRate = (float) ($tools['permission_denial_rate'] ?? 0);
        $failureRate = (float) ($tools['tool_failure_rate'] ?? 0);
        $criticalCount = (int) ($tools['critical_risk_tool_count'] ?? 0);
        $denialMinCalls = (int) config('atlas.ai_metrics.tool_denial_min_calls', 10);
        $failureMinCalls = (int) config('atlas.ai_metrics.tool_failure_min_calls', 10);
        $canEvaluateDenialRate = $totalCalls >= $denialMinCalls;
        $canEvaluateFailureRate = $totalCalls >= $failureMinCalls;

        // Status mirrors the health service's logic so report consumers don't need
        // to query health separately to know if tool metrics are concerning.
        $status = 'healthy';
        if ($criticalCount > 0
            || ($canEvaluateDenialRate && $denialRate > (float) config('atlas.ai_metrics.tool_denial_critical_above', 0.40))
            || ($canEvaluateFailureRate && $failureRate > (float) config('atlas.ai_metrics.tool_failure_critical_above', 0.50))
        ) {
            $status = 'critical';
        } elseif (($canEvaluateDenialRate && $denialRate > (float) config('atlas.ai_metrics.tool_denial_warning_above', 0.15))
            || ($canEvaluateFailureRate && $failureRate > (float) config('atlas.ai_metrics.tool_failure_warning_above', 0.20))
        ) {
            $status = 'warning';
        }

        $byTool = collect((array) ($tools['by_tool'] ?? []));

        return [
            'status' => $status,
            'tool_calls_total' => $totalCalls,
            'tool_failure_count' => (int) ($tools['tool_failure_count'] ?? 0),
            'tool_failure_rate' => $failureRate,
            'permission_denied_count' => (int) ($tools['permission_denied_count'] ?? 0),
            'permission_denial_rate' => $denialRate,
            'high_risk_count' => (int) ($tools['high_risk_tool_count'] ?? 0),
            'critical_risk_count' => $criticalCount,
            'min_calls' => [
                'permission_denial_rate' => $denialMinCalls,
                'tool_failure_rate' => $failureMinCalls,
            ],
            'top_used' => $byTool
                ->sortByDesc('calls')
                ->take(5)
                ->values()
                ->all(),
            // top_failures: only tools with >= 10 calls so we don't flag a tool with
            // "100% failure" based on a single call. Same min-N principle as the
            // health denial threshold (configurable, see tool_failure_min_calls).
            'top_failures' => $byTool
                ->filter(fn (array $t): bool => ($t['calls'] ?? 0) >= (int) config('atlas.ai_metrics.tool_failure_min_calls', 10))
                ->sortByDesc('failure_rate')
                ->take(3)
                ->values()
                ->all(),
        ];
    }

    /**
     * @return Collection<int,AiTraceMetricSummary>
     */
    private function summaries(CarbonImmutable $start, CarbonImmutable $end): Collection
    {
        if (! DatabaseTableAvailability::all(['ai_trace_metric_summaries', 'ai_traces'])) {
            return collect();
        }

        return AiTraceMetricSummary::query()
            ->with('trace')
            ->whereHas('trace', function ($query) use ($start, $end): void {
                $query
                    ->where('created_at', '>=', $start)
                    ->where('created_at', '<', $end);
            })
            ->get();
    }

    /**
     * @param  Collection<int,AiTraceMetricSummary>  $summaries
     * @return array<string,mixed>
     */
    private function summary(array $scorecard, Collection $summaries): array
    {
        $totals = (array) ($scorecard['totals'] ?? []);
        $traces = (int) ($totals['traces'] ?? $summaries->count());
        $unknownCostCount = (int) ($totals['unknown_cost_count'] ?? 0);
        $costMicrousd = (int) ($totals['cost_microusd_sum'] ?? 0);
        // Segregated cost totals — surfaced so the report can show CLI placeholders and
        // real API spend separately. cost_microusd_sum (legacy) sums both, which mistakenly
        // implies they share a unit. metered_estimate_microusd reflects real billing-track spend.
        $meteredCostMicrousd = (int) ($totals['metered_estimate_cost_microusd_sum'] ?? 0);
        $operationalCostMicrousd = (int) ($totals['operational_estimate_cost_microusd_sum'] ?? 0);
        $unknownCostMicrousd = (int) ($totals['unknown_cost_microusd_sum'] ?? 0);

        return [
            'traces' => $traces,
            'quality_avg' => $this->number($totals['final_quality_avg'] ?? null),
            'efficiency_avg' => $this->number($totals['final_efficiency_avg'] ?? null),
            'context_efficiency_avg' => $this->number($totals['context_efficiency_avg'] ?? null),
            'first_pass_success_rate' => $this->number($totals['first_pass_success_rate'] ?? null),
            'needed_remediation_rate' => $this->number($totals['needed_remediation_rate'] ?? null),
            'backgrounded_rate' => $this->number($totals['backgrounded_during_run_rate'] ?? null),
            'recovered_pending_count' => (int) ($totals['recovered_from_pending_count'] ?? 0),
            'reask_count' => (int) ($totals['reask_detected_count'] ?? 0),
            'reask_rate' => $traces > 0 ? round((int) ($totals['reask_detected_count'] ?? 0) / $traces, 4) : null,
            'cost_microusd_sum' => $costMicrousd,
            'cost_usd_estimate' => round($costMicrousd / 1_000_000, 6),
            'metered_estimate_cost_microusd_sum' => $meteredCostMicrousd,
            'metered_estimate_cost_usd_estimate' => round($meteredCostMicrousd / 1_000_000, 6),
            'operational_estimate_cost_microusd_sum' => $operationalCostMicrousd,
            'operational_estimate_cost_usd_estimate' => round($operationalCostMicrousd / 1_000_000, 6),
            'unknown_cost_microusd_sum' => $unknownCostMicrousd,
            'unknown_cost_count' => $unknownCostCount,
            'estimated_cost_count' => (int) ($totals['estimated_cost_count'] ?? 0),
            // metered_cost_count is the new vocabulary; actual_cost_count is the legacy alias
            // kept for any external consumer pinned to the old key. Same value during transition.
            'metered_cost_count' => (int) ($totals['metered_cost_count'] ?? $totals['actual_cost_count'] ?? 0),
            'actual_cost_count' => (int) ($totals['actual_cost_count'] ?? 0),
            'operational_estimate_cost_count' => (int) ($totals['operational_estimate_cost_count'] ?? 0),
            'unknown_cost_rate' => $traces > 0 ? round($unknownCostCount / $traces, 4) : null,
            'total_latency_avg_ms' => $this->number($totals['total_latency_avg_ms'] ?? null),
            'app_visible_avg_ms' => $this->number($totals['app_visible_avg_ms'] ?? null),
            'app_visible_p50_ms' => $this->percentile($summaries->pluck('app_send_to_visible_ms'), 0.50),
            'app_visible_p95_ms' => $this->percentile($summaries->pluck('app_send_to_visible_ms'), 0.95),
            'total_latency_p50_ms' => $this->percentile($summaries->pluck('total_latency_ms'), 0.50),
            'total_latency_p95_ms' => $this->percentile($summaries->pluck('total_latency_ms'), 0.95),
            'provider_latency_p50_ms' => $this->percentile($summaries->pluck('provider_latency_ms'), 0.50),
            'provider_latency_p95_ms' => $this->percentile($summaries->pluck('provider_latency_ms'), 0.95),
            'queue_wait_p50_ms' => $this->percentile($summaries->pluck('queue_wait_ms'), 0.50),
            'queue_wait_p95_ms' => $this->percentile($summaries->pluck('queue_wait_ms'), 0.95),
            'tokens_sum' => (int) $summaries->sum(fn (AiTraceMetricSummary $summary): int => (int) ($summary->total_tokens ?? 0)),
            'tokens_avg' => $this->avgCollection($summaries->pluck('total_tokens')),
            'context_tokens_avg' => $this->avgCollection($summaries->pluck('context_tokens')),
        ];
    }

    /**
     * @param  Collection<int,AiTraceMetricSummary>  $summaries
     * @return array<string,mixed>
     */
    private function quality(array $scorecard, Collection $summaries): array
    {
        $risks = (array) ($scorecard['risks'] ?? []);
        $traces = max(0, (int) data_get($scorecard, 'totals.traces', $summaries->count()));

        return [
            'auto_quality_avg' => $this->avgCollection($summaries->pluck('auto_quality_score')),
            'continuity_avg' => $this->avgCollection($summaries->pluck('continuity_score')),
            'human_feedback_avg' => $this->avgCollection($summaries->pluck('human_feedback_score')),
            'outcome_avg' => $this->avgCollection($summaries->pluck('outcome_score')),
            'remediation_avg' => $this->avgCollection($summaries->pluck('remediation_score')),
            'low_quality_count' => (int) ($risks['low_quality_count'] ?? 0),
            'low_quality_rate' => $traces > 0 ? round((int) ($risks['low_quality_count'] ?? 0) / $traces, 4) : null,
            'recent_low_score' => $scorecard['recent_low_score'] ?? [],
        ];
    }

    /**
     * @param  Collection<int,AiTraceMetricSummary>  $summaries
     * @return array<string,mixed>
     */
    private function efficiency(array $scorecard, Collection $summaries): array
    {
        $totals = (array) ($scorecard['totals'] ?? []);

        return [
            'context_efficiency_avg' => $this->number($totals['context_efficiency_avg'] ?? null),
            'tokens_sum' => (int) $summaries->sum(fn (AiTraceMetricSummary $summary): int => (int) ($summary->total_tokens ?? 0)),
            'context_tokens_avg' => $this->avgCollection($summaries->pluck('context_tokens')),
            'useful_context_ref_rate' => $this->usefulContextRefRate($summaries),
            'cost_confidence_counts' => $summaries
                ->groupBy(fn (AiTraceMetricSummary $summary): string => $summary->cost_confidence ?: 'unknown')
                ->map->count()
                ->all(),
            'cost_mode_counts' => $summaries
                ->groupBy(fn (AiTraceMetricSummary $summary): string => $summary->cost_mode ?: 'unknown')
                ->map->count()
                ->all(),
        ];
    }

    /**
     * @param  Collection<int,AiTraceMetricSummary>  $summaries
     * @return array<string,mixed>
     */
    private function reliability(array $scorecard, Collection $summaries): array
    {
        $traces = max(0, (int) data_get($scorecard, 'totals.traces', $summaries->count()));
        $failed = $summaries->whereIn('status', ['failed', 'cancelled'])->count();
        $providerSwitches = $summaries->where('provider_switched_after_response', true)->count();

        return [
            'status_counts' => $summaries->groupBy(fn (AiTraceMetricSummary $summary): string => $summary->status ?: 'unknown')->map->count()->all(),
            'failed_trace_rate' => $traces > 0 ? round($failed / $traces, 4) : null,
            'reask_rate' => $traces > 0 ? round($summaries->where('reask_detected', true)->count() / $traces, 4) : null,
            'provider_switch_rate' => $traces > 0 ? round($providerSwitches / $traces, 4) : null,
            'backgrounded_rate' => data_get($scorecard, 'totals.backgrounded_during_run_rate'),
            'recovered_pending_count' => (int) data_get($scorecard, 'totals.recovered_from_pending_count', 0),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function providerHealth(CarbonImmutable $start, CarbonImmutable $end): array
    {
        if (! DatabaseTableAvailability::has('ai_provider_health_snapshots')) {
            return ['latest' => [], 'daily_rollup' => []];
        }

        $latest = AiProviderHealthSnapshot::query()
            ->where('checked_at', '<', $end)
            ->orderBy('provider')
            ->orderByDesc('checked_at')
            ->get()
            ->unique('provider')
            ->values()
            ->map(fn (AiProviderHealthSnapshot $snapshot): array => $this->providerSnapshot($snapshot))
            ->all();

        $daily = AiProviderHealthSnapshot::query()
            ->where('checked_at', '>=', $start)
            ->where('checked_at', '<', $end)
            ->get()
            ->groupBy('provider')
            ->map(function (Collection $snapshots, string $provider): array {
                $count = max(1, $snapshots->count());

                return [
                    'provider' => $provider,
                    'checks' => $snapshots->count(),
                    'offline_or_degraded_count' => $snapshots->whereIn('status', ['offline', 'degraded'])->count(),
                    'avg_pain_score' => round($snapshots->avg('operational_pain_score') ?? 0, 2),
                    'max_pain_score' => (int) ($snapshots->max('operational_pain_score') ?? 0),
                    'availability_issue_rate' => round($snapshots->whereIn('status', ['offline', 'degraded'])->count() / $count, 4),
                ];
            })
            ->values()
            ->all();

        return ['latest' => $latest, 'daily_rollup' => $daily];
    }

    /**
     * @param  Collection<int,AiTraceMetricSummary>  $summaries
     * @return array<string,mixed>
     */
    private function dataQuality(array $scorecard, array $health, Collection $summaries): array
    {
        $traces = max(0, (int) data_get($scorecard, 'totals.traces', $summaries->count()));
        $coverage = [
            'with_cost' => $summaries->filter(fn (AiTraceMetricSummary $summary): bool => $summary->cost_confidence !== 'unknown')->count(),
            'with_app_visible_latency' => $summaries->whereNotNull('app_send_to_visible_ms')->count(),
            'with_provider_model' => $summaries->filter(fn (AiTraceMetricSummary $summary): bool => $summary->provider !== null && $summary->model !== null)->count(),
            'with_human_feedback' => $summaries->whereNotNull('human_feedback_score')->count(),
            'with_outcome' => $summaries->whereNotNull('outcome_score')->count(),
            'with_context_signal' => $summaries->filter(fn (AiTraceMetricSummary $summary): bool => $summary->context_tokens !== null || $summary->context_refs_count !== null)->count(),
        ];

        return [
            'coverage' => $coverage,
            'coverage_rates' => collect($coverage)
                ->map(fn (int $value): ?float => $traces > 0 ? round($value / $traces, 4) : null)
                ->all(),
            'missing_cost_rates' => data_get($health, 'evidence.missing_cost_rates', []),
            'sample_size_warning' => collect(data_get($health, 'issues', []))->contains('key', 'insufficient_sample'),
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function risks(array $scorecard, array $health, array $summary): array
    {
        $risks = collect(data_get($health, 'issues', []))
            ->map(fn (array $issue): array => [
                'key' => $issue['key'] ?? 'unknown',
                'severity' => $issue['severity'] ?? 'warning',
                'summary' => $issue['summary'] ?? 'Risco operacional identificado.',
                'value' => $issue['value'] ?? null,
                'threshold' => $issue['threshold'] ?? null,
            ]);

        if (($summary['unknown_cost_rate'] ?? 0) > 0) {
            $risks->push([
                'key' => 'cost_visibility',
                'severity' => ($summary['unknown_cost_rate'] ?? 0) > 0.9 ? 'critical' : 'warning',
                'summary' => 'Ha traces sem custo calculavel; custo CLI precisa continuar marcado como estimativa operacional.',
                'value' => $summary['unknown_cost_rate'],
                'threshold' => 0,
            ]);
        }

        return $risks->unique('key')->values()->all();
    }

    /**
     * @param  Collection<int,AiTraceMetricSummary>  $summaries
     * @return array<string,array<int,array<string,mixed>>>
     */
    private function notableTraces(Collection $summaries): array
    {
        return [
            'slowest' => $this->rankedTraces($summaries, 'total_latency_ms'),
            'highest_cost' => $this->rankedTraces($summaries, 'cost_microusd'),
            'needs_remediation' => $summaries
                ->where('needed_remediation', true)
                ->sortByDesc('computed_at')
                ->take(10)
                ->values()
                ->map(fn (AiTraceMetricSummary $summary): array => $this->traceRef($summary))
                ->all(),
        ];
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function compareSummaries(array $current, array $previous): array
    {
        $fields = [
            'traces',
            'quality_avg',
            'efficiency_avg',
            'first_pass_success_rate',
            'needed_remediation_rate',
            'unknown_cost_rate',
            'cost_microusd_sum',
            'app_visible_p95_ms',
            'total_latency_p95_ms',
            'provider_latency_p95_ms',
        ];

        $deltas = [];
        foreach ($fields as $field) {
            $deltas[$field] = $this->delta($current[$field] ?? null, $previous[$field] ?? null);
        }

        return $deltas;
    }

    /**
     * @return array<int,string>
     */
    private function dailyActions(array $current, array $comparison): array
    {
        $actions = collect(data_get($current, 'health.actions', []))
            ->filter(fn (mixed $action): bool => is_string($action) && trim($action) !== '')
            ->map(fn (string $action): string => $this->translateAction($action))
            ->values();

        if ($this->deltaValue($comparison, 'quality_avg') <= -8) {
            $actions->push('Investigar queda de qualidade contra o dia anterior por provider, task type e traces de baixa pontuacao.');
        }

        if ($this->deltaValue($comparison, 'efficiency_avg') <= -8 || $this->deltaPct($comparison, 'app_visible_p95_ms') >= 0.25) {
            $actions->push('Separar latencia entre fila, provider e visibilidade no app antes de trocar modelo ou prompt.');
        }

        if ((float) data_get($current, 'summary.unknown_cost_rate', 0) > 0
            && ! $actions->contains(fn (string $action): bool => str_contains($action, 'rates de estimativa operacional'))
        ) {
            $actions->push('Configurar rates de custo para providers/modelos sem custo e rodar rollup novamente.');
        }

        if ((bool) data_get($current, 'data_quality.sample_size_warning', false)) {
            $actions->push('Tratar conclusoes como observacao ate acumular amostra minima de traces.');
        }

        return $actions->unique()->values()->all();
    }

    /**
     * @param  array<int,array<string,mixed>>  $reports
     * @return array<int,string>
     */
    private function multiActions(array $reports): array
    {
        return collect($reports)
            ->flatMap(fn (array $report): array => $report['actions'] ?? [])
            ->filter()
            ->unique()
            ->take(8)
            ->values()
            ->all();
    }

    private function dailySummaryText(array $current, array $comparison): string
    {
        return $this->reportBodyRenderer()->dailySummaryText($current, $comparison);
    }

    /**
     * @param  array<int,array<string,mixed>>  $reports
     */
    private function multiSummaryText(array $reports): string
    {
        return $this->reportBodyRenderer()->multiSummaryText($reports);
    }

    private function dailyBody(CarbonImmutable $date, string $timezone, array $current, array $previous, array $comparison, array $actions): string
    {
        return $this->reportBodyRenderer()->dailyBody($date, $timezone, $current, $previous, $comparison, $actions);
    }

    /**
     * @param  array<int,array<string,mixed>>  $reports
     * @param  array<int,string>  $actions
     */
    private function multiBody(CarbonImmutable $date, string $timezone, array $reports, array $actions): string
    {
        return $this->reportBodyRenderer()->multiBody($date, $timezone, $reports, $actions);
    }

    public function threadBody(array $report): string
    {
        return $this->reportBodyRenderer()->threadBody($report);
    }

    /**
     * @return array<int,string>
     */
    private function highlights(array $report): array
    {
        return $this->reportBodyRenderer()->highlights($report);
    }

    /**
     * @return array<string,mixed>
     */
    private function compactReportPayload(array $report): array
    {
        return $this->reportBodyRenderer()->compactReportPayload($report);
    }

    public function formatMs(mixed $value): string
    {
        return $this->reportBodyRenderer()->formatMs($value);
    }

    private function reportBodyRenderer(): AiTelemetryReportBodyRenderer
    {
        return $this->reportBodyRendererInstance ??= new AiTelemetryReportBodyRenderer(
            fn (mixed $value): string => $this->formatCostSummary($value),
            fn (mixed $value): string => $this->formatNumber($value),
            fn (mixed $value): string => $this->formatSigned($value),
            fn (mixed $value): string => $this->formatPercent($value),
        );
    }


    private function formatUsd(float $value): string
    {
        return '$'.number_format($value, 4, '.', '');
    }

    private function translateAction(string $action): string
    {
        return match (true) {
            str_starts_with($action, 'Open recent low-score traces') => 'Abrir traces recentes com baixa pontuacao e revisar prompt, contexto, provider e evidencia antes de alterar comportamento.',
            str_starts_with($action, 'Configure operational estimate cost rates for') => str_replace(
                ['Configure operational estimate cost rates for ', ' and rerun telemetry rollup.'],
                ['Configurar rates de estimativa operacional para ', ' e rodar o rollup de telemetria novamente.'],
                $action,
            ),
            $action === 'Configure operational estimate cost rates and rerun telemetry rollup.' => 'Configurar rates de estimativa operacional e rodar o rollup de telemetria novamente.',
            $action === 'Fix provider/model attribution for traces with unknown cost before importing rates.' => 'Corrigir atribuicao de provider/model nas traces com custo desconhecido antes de importar rates.',
            $action === 'Review quality flags and recent human feedback; adjust context selection or response policy first.' => 'Revisar flags de qualidade e feedback humano recente; ajustar selecao de contexto ou politica de resposta primeiro.',
            $action === 'Check queue wait, provider latency and mobile visibility events to isolate where time is spent.' => 'Separar tempo de fila, latencia de provider e eventos de visibilidade mobile para localizar onde o tempo esta sendo gasto.',
            $action === 'Compare first-pass failures against successful traces by task type and provider.' => 'Comparar falhas de first-pass com traces bem-sucedidas por task type e provider.',
            $action === 'Collect more traces before changing prompts/providers.' => 'Coletar mais traces antes de mudar prompts ou providers.',
            $action === 'Keep monitoring the same window after the next few runs.' => 'Manter monitoramento da mesma janela nas proximas execucoes.',
            $action === 'Keep hourly rollup enabled and review provider/cost rates when models change.' => 'Manter o rollup horario ativo e revisar provider/cost rates quando modelos mudarem.',
            default => $action,
        };
    }

    private function formatCostSummary(array $summary): string
    {
        $unknownRate = is_numeric($summary['unknown_cost_rate'] ?? null) ? (float) $summary['unknown_cost_rate'] : null;
        $usd = (float) ($summary['cost_usd_estimate'] ?? 0);

        if ((int) ($summary['traces'] ?? 0) > 0 && $unknownRate !== null && $unknownRate >= 0.99) {
            return 'sem rate configurado para '.$this->formatPercent($unknownRate).' das traces';
        }

        if ($unknownRate !== null && $unknownRate > 0) {
            return $this->formatUsd($usd).' parcial; '.$this->formatPercent($unknownRate).' desconhecido';
        }

        return $this->formatUsd($usd);
    }
}
