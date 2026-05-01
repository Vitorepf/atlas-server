<?php

namespace App\Services\Ai\Telemetry;

use App\Models\AiInboxItem;
use App\Models\AiProviderHealthSnapshot;
use App\Models\AiTraceMetricSummary;
use App\Services\Ai\Mobile\AtlasInboxService;
use App\Services\Ai\Mobile\ContextBundleService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class AiTelemetryPerformanceReportService
{
    public function __construct(
        private readonly AiTelemetryScorecardService $scorecards,
        private readonly AiTelemetryHealthService $health,
        private readonly ContextBundleService $bundles,
        private readonly AtlasInboxService $inbox,
    ) {
    }

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

        $current = $this->windowAnalysis($start, $end, $timezone);
        $previous = $this->windowAnalysis($previousStart, $start, $timezone);
        $comparison = $this->compareSummaries($current['summary'], $previous['summary']);
        $actions = $this->dailyActions($current, $comparison);
        $status = $this->statusFromHealth((string) data_get($current, 'health.status', 'unknown'), (int) data_get($current, 'summary.traces', 0));
        $title = 'Atlas AI: relatorio de performance de '.$date->format('d/m/Y');
        $summary = $this->dailySummaryText($current, $comparison);
        $body = $this->dailyBody($date, $timezone, $current, $previous, $comparison, $actions);

        return [
            'report_type' => 'atlas_ai_daily_performance',
            'schema_version' => 1,
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
            'risks' => $current['risks'],
            'actions' => $actions,
            'notable_traces' => $current['notable_traces'],
            'metric_refs' => $this->metricRefs($current['summary']),
            'source_refs' => [
                ['type' => 'ai_trace_metric_summaries', 'window' => $current['window']],
                ['type' => 'ai_traces', 'basis' => 'trace_created_at'],
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
        $reports = [];

        foreach ($windows as $days) {
            $start = $end->subDays($days);
            $previousStart = $start->subDays($days);
            $current = $this->windowAnalysis($start, $end, $timezone);
            $previous = $this->windowAnalysis($previousStart, $start, $timezone);

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
                'risks' => $current['risks'],
                'actions' => $this->dailyActions($current, $this->compareSummaries($current['summary'], $previous['summary'])),
                'notable_traces' => $current['notable_traces'],
            ];
        }

        $status = $this->strongestStatus(collect($reports)->pluck('status')->all());
        $title = 'Atlas AI: desempenho 3/7/15/30 dias ate '.$date->format('d/m/Y');
        $summary = $this->multiSummaryText($reports);
        $actions = $this->multiActions($reports);
        $body = $this->multiBody($date, $timezone, $reports, $actions);

        return [
            'report_type' => 'atlas_ai_multi_window_performance',
            'schema_version' => 1,
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

        if (! Schema::hasTable('ai_context_bundles') || ! Schema::hasTable('ai_inbox_items')) {
            return ['emitted' => false, 'item_id' => null, 'reason' => 'inbox_unavailable', 'dedupe_key' => $dedupeKey];
        }

        $bundle = $this->bundles->create([
            'user_id' => $userId,
            'purpose' => 'atlas_ai_performance_report',
            'title' => (string) ($report['title'] ?? 'Relatorio de performance do Atlas AI'),
            'summary' => (string) ($report['summary_text'] ?? 'Relatorio operacional do Atlas AI.'),
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
            'title' => (string) ($report['title'] ?? 'Relatorio de performance do Atlas AI'),
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
    private function windowAnalysis(CarbonImmutable $start, CarbonImmutable $end, string $timezone): array
    {
        $scorecard = $this->scorecards->build($start, $end, 'trace_created_at', true);
        $health = $this->health->evaluate($start, $end, 'trace_created_at', true);
        $summaries = $this->summaries($start, $end);
        $summary = $this->summary($scorecard, $summaries);

        return [
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
            'risks' => $this->risks($scorecard, $health, $summary),
            'notable_traces' => $this->notableTraces($summaries),
        ];
    }

    /**
     * @return Collection<int,AiTraceMetricSummary>
     */
    private function summaries(CarbonImmutable $start, CarbonImmutable $end): Collection
    {
        if (! Schema::hasTable('ai_trace_metric_summaries') || ! Schema::hasTable('ai_traces')) {
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
            'unknown_cost_count' => $unknownCostCount,
            'estimated_cost_count' => (int) ($totals['estimated_cost_count'] ?? 0),
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
        if (! Schema::hasTable('ai_provider_health_snapshots')) {
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
        $summary = (array) ($current['summary'] ?? []);
        $status = (string) data_get($current, 'health.status', 'unknown');
        $quality = $this->formatNumber($summary['quality_avg'] ?? null);
        $efficiency = $this->formatNumber($summary['efficiency_avg'] ?? null);
        $traces = (int) ($summary['traces'] ?? 0);
        $qualityDelta = $this->formatSigned($comparison['quality_avg']['delta_abs'] ?? null);

        return "Status {$status}; {$traces} traces; qualidade {$quality} ({$qualityDelta}); eficiencia {$efficiency}; custo ".$this->formatCostSummary($summary).'.';
    }

    /**
     * @param  array<int,array<string,mixed>>  $reports
     */
    private function multiSummaryText(array $reports): string
    {
        $parts = collect($reports)
            ->map(function (array $report): string {
                $summary = (array) ($report['summary'] ?? []);

                return "{$report['days']}d: {$report['status']}, {$summary['traces']} traces, qualidade ".$this->formatNumber($summary['quality_avg'] ?? null).', eficiencia '.$this->formatNumber($summary['efficiency_avg'] ?? null);
            })
            ->implode(' | ');

        return 'Estrutura de desempenho Atlas AI: '.$parts.'.';
    }

    private function dailyBody(CarbonImmutable $date, string $timezone, array $current, array $previous, array $comparison, array $actions): string
    {
        $summary = (array) ($current['summary'] ?? []);
        $risks = collect($current['risks'] ?? [])->take(6)->map(fn (array $risk): string => "- {$risk['severity']} {$risk['key']}: {$risk['summary']}")->implode("\n");
        $actionsText = collect($actions)->map(fn (string $action): string => '- '.$action)->implode("\n");

        return implode("\n\n", array_filter([
            'Relatorio diario de performance do Atlas AI - '.$date->format('d/m/Y').' ('.$timezone.').',
            'Resumo executivo: '.$this->dailySummaryText($current, $comparison),
            implode("\n", [
                'Metricas centrais:',
                '- Traces: '.(int) ($summary['traces'] ?? 0),
                '- Qualidade media: '.$this->formatNumber($summary['quality_avg'] ?? null).' | delta dia anterior: '.$this->formatSigned($comparison['quality_avg']['delta_abs'] ?? null),
                '- Eficiencia media: '.$this->formatNumber($summary['efficiency_avg'] ?? null).' | delta dia anterior: '.$this->formatSigned($comparison['efficiency_avg']['delta_abs'] ?? null),
                '- First-pass: '.$this->formatPercent($summary['first_pass_success_rate'] ?? null).' | remedicao: '.$this->formatPercent($summary['needed_remediation_rate'] ?? null),
                '- P95 app visivel: '.$this->formatMs($summary['app_visible_p95_ms'] ?? null).' | P95 provider: '.$this->formatMs($summary['provider_latency_p95_ms'] ?? null),
                '- Custo: '.$this->formatCostSummary($summary).' | custo desconhecido: '.$this->formatPercent($summary['unknown_cost_rate'] ?? null),
            ]),
            $risks !== '' ? "Falhas e riscos detectados:\n".$risks : 'Falhas e riscos detectados: nenhum risco forte nesta janela.',
            "Acoes recomendadas:\n".($actionsText !== '' ? $actionsText : '- Manter rollup horario e revisar novamente no proximo relatorio.'),
            'Cobertura de dados: '.json_encode($current['data_quality']['coverage_rates'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'Janela anterior comparada: '.($previous['window']['start'] ?? '-').' ate '.($previous['window']['end'] ?? '-').'.',
        ]));
    }

    /**
     * @param  array<int,array<string,mixed>>  $reports
     * @param  array<int,string>  $actions
     */
    private function multiBody(CarbonImmutable $date, string $timezone, array $reports, array $actions): string
    {
        $windows = collect($reports)
            ->map(function (array $report): string {
                $summary = (array) ($report['summary'] ?? []);
                $delta = (array) data_get($report, 'comparison_to_previous_same_window.deltas.quality_avg', []);

                return implode("\n", [
                    "{$report['label']} ({$report['status']}):",
                    '- Traces: '.(int) ($summary['traces'] ?? 0),
                    '- Qualidade: '.$this->formatNumber($summary['quality_avg'] ?? null).' | delta janela anterior: '.$this->formatSigned($delta['delta_abs'] ?? null),
                    '- Eficiencia: '.$this->formatNumber($summary['efficiency_avg'] ?? null),
                    '- First-pass: '.$this->formatPercent($summary['first_pass_success_rate'] ?? null).' | remedicao: '.$this->formatPercent($summary['needed_remediation_rate'] ?? null),
                    '- P95 app: '.$this->formatMs($summary['app_visible_p95_ms'] ?? null).' | custo: '.$this->formatCostSummary($summary),
                ]);
            })
            ->implode("\n\n");

        $actionsText = collect($actions)->map(fn (string $action): string => '- '.$action)->implode("\n");

        return implode("\n\n", [
            'Relatorio estrutural de performance do Atlas AI ate '.$date->format('d/m/Y').' ('.$timezone.').',
            'Resumo executivo: '.$this->multiSummaryText($reports),
            $windows,
            "Melhorias, pioras e proximas acoes:\n".($actionsText !== '' ? $actionsText : '- Nenhuma acao nova com confianca suficiente.'),
            'Este relatorio compara cada janela com a janela imediatamente anterior de mesmo tamanho e usa trace_created_at como base auditavel.',
        ]);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function metricRefs(array $summary): array
    {
        return collect($summary)
            ->only(['traces', 'quality_avg', 'efficiency_avg', 'first_pass_success_rate', 'needed_remediation_rate', 'cost_usd_estimate', 'unknown_cost_rate', 'app_visible_p95_ms'])
            ->map(fn (mixed $value, string $name): array => ['name' => $name, 'value' => $value])
            ->values()
            ->all();
    }

    /**
     * @param  array<int,array<string,mixed>>  $reports
     * @return array<int,array<string,mixed>>
     */
    private function multiMetricRefs(array $reports): array
    {
        return collect($reports)
            ->flatMap(fn (array $report): array => collect($this->metricRefs($report['summary'] ?? []))
                ->map(fn (array $metric): array => array_merge($metric, ['window_days' => $report['days']]))
                ->all())
            ->values()
            ->all();
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function traceRefs(array $report): array
    {
        $sets = $report['notable_traces'] ?? [];
        if ($sets === [] && isset($report['windows']) && is_array($report['windows'])) {
            foreach ($report['windows'] as $window) {
                foreach ((array) ($window['notable_traces'] ?? []) as $group) {
                    if (is_array($group)) {
                        $sets[] = $group;
                    }
                }
            }
        }

        return collect($sets)
            ->flatten(1)
            ->filter(fn (mixed $trace): bool => is_array($trace) && is_string($trace['trace_id'] ?? null))
            ->unique('trace_id')
            ->take(20)
            ->map(fn (array $trace): array => ['id' => $trace['trace_id'], 'type' => 'ai_trace', 'reason' => $trace['reason'] ?? 'performance_report'])
            ->values()
            ->all();
    }

    /**
     * @return array<string,mixed>
     */
    private function compactReportPayload(array $report): array
    {
        return [
            'report_type' => $report['report_type'] ?? null,
            'report_date' => $report['report_date'] ?? null,
            'timezone' => $report['timezone'] ?? null,
            'status' => $report['status'] ?? null,
            'health_score' => $report['health_score'] ?? null,
            'decision' => $report['executive_summary'] ?? $report['summary_text'] ?? null,
            'highlights' => $this->highlights($report),
            'next_actions' => $report['actions'] ?? [],
            'risks' => collect($report['risks'] ?? data_get($report, 'windows.0.risks', []))->take(6)->values()->all(),
            'validation' => [
                'basis' => data_get($report, 'window.basis', 'trace_created_at'),
                'dedupe_key' => $report['dedupe_key'] ?? null,
                'schema_version' => $report['schema_version'] ?? 1,
            ],
            'full_text' => $report['body'] ?? null,
        ];
    }

    /**
     * @return array<int,string>
     */
    private function highlights(array $report): array
    {
        if (($report['report_type'] ?? null) === 'atlas_ai_multi_window_performance') {
            return collect($report['windows'] ?? [])
                ->map(fn (array $window): string => "{$window['days']}d {$window['status']}: {$window['summary']['traces']} traces, Q ".$this->formatNumber($window['summary']['quality_avg'] ?? null).', E '.$this->formatNumber($window['summary']['efficiency_avg'] ?? null))
                ->values()
                ->all();
        }

        $summary = (array) ($report['summary'] ?? []);

        return [
            'Traces: '.(int) ($summary['traces'] ?? 0),
            'Qualidade: '.$this->formatNumber($summary['quality_avg'] ?? null),
            'Eficiencia: '.$this->formatNumber($summary['efficiency_avg'] ?? null),
            'Custo: '.$this->formatCostSummary($summary),
        ];
    }

    private function threadBody(array $report): string
    {
        return implode("\n\n", [
            'Use esta conversa para decidir como melhorar o Atlas AI com base no relatorio operacional.',
            'Resumo: '.(string) ($report['summary_text'] ?? $report['executive_summary'] ?? ''),
            'Relatorio completo:',
            (string) ($report['body'] ?? ''),
        ]);
    }

    /**
     * @return array<int,int>
     */
    private function normalizeWindows(array $windows): array
    {
        $normalized = collect($windows)
            ->map(fn (mixed $value): int => (int) $value)
            ->filter(fn (int $value): bool => $value > 0 && $value <= 365)
            ->unique()
            ->sort()
            ->values()
            ->all();

        return $normalized === [] ? [3, 7, 15, 30] : $normalized;
    }

    private function timezone(?string $timezone): string
    {
        $timezone = $timezone ?: (string) config('atlas.ai_metrics.performance_report_timezone', config('app.timezone', 'UTC'));

        return $timezone !== '' ? $timezone : 'UTC';
    }

    private function reportDate(CarbonInterface|string|null $reportDate, string $timezone): CarbonImmutable
    {
        if ($reportDate instanceof CarbonInterface) {
            return CarbonImmutable::parse($reportDate->copy()->timezone($timezone)->toDateString(), $timezone)->startOfDay();
        }

        if (is_string($reportDate) && trim($reportDate) !== '') {
            return CarbonImmutable::parse(trim($reportDate), $timezone)->startOfDay();
        }

        return CarbonImmutable::now($timezone)->subDay()->startOfDay();
    }

    private function statusFromHealth(string $healthStatus, int $traces): string
    {
        if ($traces === 0) {
            return 'watch';
        }

        return in_array($healthStatus, ['healthy', 'watch', 'warning', 'critical'], true) ? $healthStatus : 'unknown';
    }

    /**
     * @param  array<int,string>  $statuses
     */
    private function strongestStatus(array $statuses): string
    {
        return collect($statuses)
            ->sortByDesc(fn (string $status): int => $this->statusRank($status))
            ->first() ?: 'unknown';
    }

    private function statusRank(string $status): int
    {
        return match ($status) {
            'critical' => 4,
            'warning' => 3,
            'watch' => 2,
            'healthy' => 1,
            default => 0,
        };
    }

    private function severity(string $status): string
    {
        return match ($status) {
            'critical' => 'critical',
            'warning' => 'warning',
            default => 'info',
        };
    }

    private function priorityScore(string $status): int
    {
        return match ($status) {
            'critical' => 92,
            'warning' => 82,
            'watch' => 68,
            default => 60,
        };
    }

    private function confidence(array $analysis): float
    {
        $traces = (int) data_get($analysis, 'summary.traces', 0);
        if ($traces === 0) {
            return 0.45;
        }

        if ((bool) data_get($analysis, 'data_quality.sample_size_warning', false)) {
            return 0.62;
        }

        return 0.82;
    }

    /**
     * @param  array<int,array<string,mixed>>  $reports
     */
    private function multiConfidence(array $reports): float
    {
        $traces = collect($reports)->sum(fn (array $report): int => (int) data_get($report, 'summary.traces', 0));

        return $traces === 0 ? 0.45 : 0.78;
    }

    /**
     * @param  array<int,array<string,mixed>>  $reports
     */
    private function averageHealthScore(array $reports): ?int
    {
        $scores = collect($reports)->pluck('health_score')->filter(fn (mixed $value): bool => is_numeric($value))->values();

        return $scores->isEmpty() ? null : (int) round($scores->avg());
    }

    private function delta(mixed $current, mixed $previous): array
    {
        if (! is_numeric($current) || ! is_numeric($previous)) {
            return ['current' => $current, 'previous' => $previous, 'delta_abs' => null, 'delta_pct' => null];
        }

        $current = (float) $current;
        $previous = (float) $previous;

        return [
            'current' => $current,
            'previous' => $previous,
            'delta_abs' => round($current - $previous, 4),
            'delta_pct' => $previous == 0.0 ? null : round(($current - $previous) / abs($previous), 4),
        ];
    }

    private function deltaValue(array $comparison, string $field): float
    {
        return is_numeric(data_get($comparison, "{$field}.delta_abs")) ? (float) data_get($comparison, "{$field}.delta_abs") : 0.0;
    }

    private function deltaPct(array $comparison, string $field): float
    {
        return is_numeric(data_get($comparison, "{$field}.delta_pct")) ? (float) data_get($comparison, "{$field}.delta_pct") : 0.0;
    }

    private function number(mixed $value): ?float
    {
        return is_numeric($value) ? round((float) $value, 4) : null;
    }

    private function percentile(Collection $values, float $percentile): ?float
    {
        $numbers = $values
            ->filter(fn (mixed $value): bool => is_numeric($value))
            ->map(fn (mixed $value): float => (float) $value)
            ->sort()
            ->values();

        if ($numbers->isEmpty()) {
            return null;
        }

        $index = ($numbers->count() - 1) * $percentile;
        $lower = (int) floor($index);
        $upper = (int) ceil($index);
        if ($lower === $upper) {
            return round((float) $numbers[$lower], 2);
        }

        $weight = $index - $lower;

        return round(((float) $numbers[$lower] * (1 - $weight)) + ((float) $numbers[$upper] * $weight), 2);
    }

    private function avgCollection(Collection $values): ?float
    {
        $numbers = $values->filter(fn (mixed $value): bool => is_numeric($value));

        return $numbers->isEmpty() ? null : round((float) $numbers->avg(), 2);
    }

    /**
     * @param  Collection<int,AiTraceMetricSummary>  $summaries
     */
    private function usefulContextRefRate(Collection $summaries): ?float
    {
        $total = (int) $summaries->sum(fn (AiTraceMetricSummary $summary): int => (int) ($summary->context_refs_count ?? 0));
        if ($total <= 0) {
            return null;
        }

        $useful = (int) $summaries->sum(fn (AiTraceMetricSummary $summary): int => (int) ($summary->useful_context_refs_count ?? 0));

        return round($useful / $total, 4);
    }

    /**
     * @param  Collection<int,AiTraceMetricSummary>  $summaries
     * @return array<int,array<string,mixed>>
     */
    private function rankedTraces(Collection $summaries, string $field): array
    {
        return $summaries
            ->filter(fn (AiTraceMetricSummary $summary): bool => is_numeric($summary->{$field}))
            ->sortByDesc($field)
            ->take(10)
            ->values()
            ->map(fn (AiTraceMetricSummary $summary): array => array_merge($this->traceRef($summary), [
                'reason' => $field,
                'value' => $summary->{$field},
            ]))
            ->all();
    }

    /**
     * @return array<string,mixed>
     */
    private function traceRef(AiTraceMetricSummary $summary): array
    {
        return [
            'trace_id' => $summary->trace_id,
            'thread_id' => $summary->thread_id,
            'surface' => $summary->surface,
            'provider' => $summary->provider,
            'model' => $summary->model,
            'task_type' => $summary->task_type,
            'status' => $summary->status,
            'quality' => $summary->final_quality_score,
            'efficiency' => $summary->final_efficiency_score,
            'latency_ms' => $summary->total_latency_ms,
            'cost_microusd' => $summary->cost_microusd,
            'created_at' => $summary->trace?->created_at?->toJSON(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function providerSnapshot(AiProviderHealthSnapshot $snapshot): array
    {
        return [
            'provider' => $snapshot->provider,
            'status' => $snapshot->status,
            'checked_at' => $snapshot->checked_at?->toJSON(),
            'total_jobs_24h' => $snapshot->total_jobs_24h,
            'failed_jobs_24h' => $snapshot->failed_jobs_24h,
            'p50_latency_ms' => $snapshot->p50_latency_ms,
            'operational_pain_score' => $snapshot->operational_pain_score,
            'message' => $snapshot->message,
        ];
    }

    private function formatNumber(mixed $value): string
    {
        return is_numeric($value) ? number_format((float) $value, 1, ',', '.') : 'sem dado';
    }

    private function formatSigned(mixed $value): string
    {
        if (! is_numeric($value)) {
            return 'sem baseline';
        }

        $value = (float) $value;
        $sign = $value > 0 ? '+' : '';

        return $sign.number_format($value, 1, ',', '.');
    }

    private function formatPercent(mixed $value): string
    {
        return is_numeric($value) ? number_format((float) $value * 100, 1, ',', '.').'%' : 'sem dado';
    }

    private function formatMs(mixed $value): string
    {
        return is_numeric($value) ? number_format((float) $value, 0, ',', '.').'ms' : 'sem dado';
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
