<?php

namespace App\Services\Ai\Telemetry;

use App\Models\AiTraceMetricSummary;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AiTelemetryScorecardService
{
    /**
     * @return array<string,mixed>
     */
    public function build(
        CarbonInterface $since,
        ?CarbonInterface $until = null,
        string $basis = 'computed_at',
        bool $exclusiveUntil = false,
    ): array {
        $until ??= now();

        if (! Schema::hasTable('ai_trace_metric_summaries')) {
            return ['available' => false];
        }

        if ($basis === 'trace_created_at' && ! Schema::hasTable('ai_traces')) {
            return ['available' => false, 'reason' => 'ai_traces_missing'];
        }

        $base = $this->baseQuery($since, $until, $basis, $exclusiveUntil);
        $count = (clone $base)->count();
        $tools = $this->toolMetrics($since, $until, $basis, $exclusiveUntil);
        $atlasDecide = $this->atlasDecideMetrics($base);
        $hyperflow = $this->hyperflowMetrics($since, $until, $exclusiveUntil);

        return [
            'available' => true,
            'window' => [
                'since' => $since->toJSON(),
                'until' => $until->toJSON(),
                'basis' => $basis,
                'exclusive_until' => $exclusiveUntil,
            ],
            'totals' => [
                'traces' => $count,
                'final_quality_avg' => $this->avg($base, 'final_quality_score'),
                'final_efficiency_avg' => $this->avg($base, 'final_efficiency_score'),
                'context_efficiency_avg' => $this->avg($base, 'context_efficiency_score'),
                'total_latency_avg_ms' => $this->avg($base, 'total_latency_ms'),
                'app_visible_avg_ms' => $this->avg($base, 'app_send_to_visible_ms'),
                // Legacy aggregate — sum across ALL cost modes. Mixes API spend with CLI
                // operational placeholders, so consumers needing accuracy should use the
                // segregated fields below. Kept for backward compatibility.
                'cost_microusd_sum' => (int) ((clone $base)->sum('cost_microusd') ?? 0),
                // Segregated cost totals by cost_mode. These do NOT mix moedas:
                //   - metered_estimate: real provider tokens × configured rate (closest to billing).
                //   - operational_estimate: CLI placeholders, accounting unit set by the operator.
                //   - unknown: rate missing → cost is null → sum stays 0 (surfaced for completeness).
                // Invariant: metered + operational + unknown == cost_microusd_sum.
                'metered_estimate_cost_microusd_sum' => (int) ((clone $base)->where('cost_mode', 'metered_estimate')->sum('cost_microusd') ?? 0),
                'operational_estimate_cost_microusd_sum' => (int) ((clone $base)->where('cost_mode', 'operational_estimate')->sum('cost_microusd') ?? 0),
                'unknown_cost_microusd_sum' => (int) ((clone $base)->where('cost_mode', 'unknown')->sum('cost_microusd') ?? 0),
                'unknown_cost_count' => (clone $base)->where('cost_confidence', AiCostEstimator::COST_CONFIDENCE_UNKNOWN)->count(),
                'estimated_cost_count' => (clone $base)->where('cost_confidence', AiCostEstimator::COST_CONFIDENCE_ESTIMATED)->count(),
                // metered_cost_count is the new vocabulary. It accepts both 'metered' (current writes)
                // and 'actual' (legacy rows pre-data-migration) so the scorecard stays accurate
                // during the transition window.
                'metered_cost_count' => (clone $base)->whereIn('cost_confidence', AiCostEstimator::COST_CONFIDENCE_METERED_ACCEPTED)->count(),
                // Legacy alias kept for any external consumer pinned to the old key. Same value
                // as metered_cost_count by design — drop after a release-cycle of dual emission.
                'actual_cost_count' => (clone $base)->whereIn('cost_confidence', AiCostEstimator::COST_CONFIDENCE_METERED_ACCEPTED)->count(),
                'operational_estimate_cost_count' => (clone $base)->where('cost_mode', 'operational_estimate')->count(),
                'first_pass_success_rate' => $this->rate($base, 'first_pass_success'),
                'needed_remediation_rate' => $this->rate($base, 'needed_remediation'),
                // router_override_rate is null if the column was never added (legacy schema).
                // Defensive guard — see comment on by_router_mode below.
                'router_override_rate' => Schema::hasColumn('ai_trace_metric_summaries', 'router_was_overridden')
                    ? $this->rate($base, 'router_was_overridden')
                    : null,
                'backgrounded_during_run_rate' => $this->rate($base, 'backgrounded_during_run'),
                'recovered_from_pending_count' => (clone $base)->where('recovered_from_pending', true)->count(),
                'reask_detected_count' => (clone $base)->where('reask_detected', true)->count(),
                'atlas_decide_trace_count' => (int) ($atlasDecide['traces'] ?? 0),
                'atlas_decide_multi_stage_count' => (int) ($atlasDecide['multi_stage_count'] ?? 0),
                'atlas_decide_degraded_count' => (int) ($atlasDecide['degraded_count'] ?? 0),
            ],
            'by_surface' => $this->aggregateBy($base, 'surface'),
            'by_provider' => $this->aggregateBy($base, 'provider'),
            'by_model' => $this->aggregateBy($base, 'model'),
            'by_agent' => $this->aggregateBy($base, 'agent_slug'),
            'by_task_type' => $this->aggregateBy($base, 'task_type'),
            // by_router_mode lets the report flag bad routing patterns: e.g., a 'fast'
            // router mode producing low quality scores, or 'council' producing high cost
            // without quality gain. Idx_ai_trace_metric_router_mode supports the GROUP BY.
            // Returns [] when the migration adding router_mode hasn't run on this DB.
            'by_router_mode' => Schema::hasColumn('ai_trace_metric_summaries', 'router_mode')
                ? $this->aggregateBy($base, 'router_mode')
                : [],
            'by_atlas_decide_execution_strategy' => (array) ($atlasDecide['by_execution_strategy'] ?? []),
            'by_atlas_decide_context_strategy' => (array) ($atlasDecide['by_context_strategy'] ?? []),
            // Tool diagnostics — Fix 7c F4. Queries ai_tool_events directly (not via
            // summaries) because tools is a separate event stream and the schema-safe
            // aggregator only stores aggregated counts in score_components.tools, not
            // queryable by-tool breakdowns. Joins to summary's window via trace_id.
            'tools' => $tools,
            'atlas_decide' => $atlasDecide,
            'hyperflow' => $hyperflow,
            'risks' => $this->risks($base, $tools),
            'recent_low_score' => $this->recentLowScore($base),
        ];
    }

    /**
     * Hyperflow aggregate observability is read from RouterDecision and Specialist
     * Flow execution records. It is intentionally table-based so operators can
     * inspect routing/delegation behavior even before provider quality scoring is
     * recomputed into ai_trace_metric_summaries.
     *
     * @return array<string,mixed>
     */
    private function hyperflowMetrics(CarbonInterface $since, CarbonInterface $until, bool $exclusiveUntil): array
    {
        if (! Schema::hasTable('ai_specialist_flow_executions') || ! Schema::hasTable('ai_router_decisions')) {
            return ['available' => false];
        }

        $untilOperator = $exclusiveUntil ? '<' : '<=';
        $executions = DB::table('ai_specialist_flow_executions')
            ->where('created_at', '>=', $since)
            ->where('created_at', $untilOperator, $until)
            ->get(['flow_id', 'status', 'delegation_status', 'delegation_target_flow_id', 'execution_payload']);
        $routerDecisions = DB::table('ai_router_decisions')
            ->where('created_at', '>=', $since)
            ->where('created_at', $untilOperator, $until)
            ->get(['flow_id', 'was_overridden']);

        $executionCount = $executions->count();
        $delegatedCount = $executions
            ->filter(fn ($row): bool => (string) ($row->status ?? '') === 'delegated' || (string) ($row->delegation_status ?? '') === 'delegate_to_other_flow')
            ->count();
        $overrideCount = $routerDecisions
            ->filter(fn ($row): bool => (bool) ($row->was_overridden ?? false))
            ->count();
        $qualityRefs = [];
        $failureRefs = [];

        $byFlow = $executions
            ->groupBy(fn ($row): string => (string) ($row->flow_id ?: 'unknown'))
            ->map(function (Collection $rows, string $flowId) use (&$qualityRefs, &$failureRefs): array {
                $flowDelegatedCount = $rows
                    ->filter(fn ($row): bool => (string) ($row->status ?? '') === 'delegated' || (string) ($row->delegation_status ?? '') === 'delegate_to_other_flow')
                    ->count();
                $qualityCount = 0;
                $failureCount = 0;

                foreach ($rows as $row) {
                    $payload = $this->jsonPayload($row->execution_payload ?? null);
                    foreach ((array) ($payload['quality_rubric'] ?? []) as $ref) {
                        $ref = (string) $ref;
                        $qualityRefs[$ref] = ($qualityRefs[$ref] ?? 0) + 1;
                        $qualityCount++;
                    }
                    foreach ((array) ($payload['failure_modes'] ?? []) as $ref) {
                        $ref = (string) $ref;
                        $failureRefs[$ref] = ($failureRefs[$ref] ?? 0) + 1;
                        $failureCount++;
                    }
                }

                return [
                    'bucket' => $flowId,
                    'executions' => $rows->count(),
                    'ready_for_provider_count' => $rows->where('status', 'ready_for_provider')->count(),
                    'delegated_count' => $flowDelegatedCount,
                    'delegation_rate' => $rows->count() > 0 ? round($flowDelegatedCount / $rows->count(), 4) : null,
                    'quality_rubric_ref_count' => $qualityCount,
                    'failure_mode_ref_count' => $failureCount,
                    'delegation_targets' => $rows
                        ->pluck('delegation_target_flow_id')
                        ->filter()
                        ->values()
                        ->unique()
                        ->all(),
                ];
            })
            ->values()
            ->all();

        ksort($qualityRefs);
        ksort($failureRefs);

        return [
            'available' => true,
            'executions' => $executionCount,
            'delegated_count' => $delegatedCount,
            'delegation_rate' => $executionCount > 0 ? round($delegatedCount / $executionCount, 4) : null,
            'router_decisions' => $routerDecisions->count(),
            'router_override_count' => $overrideCount,
            'router_override_rate' => $routerDecisions->count() > 0 ? round($overrideCount / $routerDecisions->count(), 4) : null,
            'by_flow' => $byFlow,
            'quality_rubric_refs' => $qualityRefs,
            'failure_mode_refs' => $failureRefs,
        ];
    }

    /**
     * Tool-level metrics computed directly from ai_tool_events. Joins to ai_traces
     * (or ai_trace_metric_summaries when basis=computed_at) via trace_id so the same
     * window definition applies to both the summary scorecard and tool aggregations.
     *
     * Returns ['available' => false] when the table is missing — every consumer
     * gets a definite negative signal instead of a key error.
     *
     * @return array<string,mixed>
     */
    private function toolMetrics(CarbonInterface $since, CarbonInterface $until, string $basis, bool $exclusiveUntil): array
    {
        if (! Schema::hasTable('ai_tool_events')) {
            return ['available' => false];
        }

        $untilOperator = $exclusiveUntil ? '<' : '<=';

        // Window scope: select tool events whose parent trace's window matches the
        // scorecard's time range. Using trace_created_at when basis allows (joins to
        // ai_traces), otherwise computed_at (joins to ai_trace_metric_summaries).
        $eventsQuery = DB::table('ai_tool_events as e');
        if ($basis === 'trace_created_at' && Schema::hasTable('ai_traces')) {
            $eventsQuery->join('ai_traces as t', 't.id', '=', 'e.trace_id')
                ->where('t.created_at', '>=', $since)
                ->where('t.created_at', $untilOperator, $until);
        } else {
            $eventsQuery->join('ai_trace_metric_summaries as s', 's.trace_id', '=', 'e.trace_id')
                ->where('s.computed_at', '>=', $since)
                ->where('s.computed_at', $untilOperator, $until);
        }

        $totalCalls = (int) (clone $eventsQuery)->count();

        if ($totalCalls === 0) {
            return [
                'available' => true,
                'tool_calls_total' => 0,
                'tool_failure_count' => 0,
                'tool_failure_rate' => null,
                'permission_denied_count' => 0,
                'permission_denial_rate' => null,
                'high_risk_tool_count' => 0,
                'critical_risk_tool_count' => 0,
                'by_tool' => [],
            ];
        }

        // Failures: exit_code != 0 OR error string present. Mirrors aggregator's isToolFailure().
        $failureCount = (int) (clone $eventsQuery)
            ->where(function ($q): void {
                $q->where(function ($qq): void {
                    $qq->whereNotNull('e.exit_code')->where('e.exit_code', '!=', 0);
                })->orWhere(function ($qq): void {
                    $qq->whereNotNull('e.error')->where('e.error', '!=', '');
                });
            })
            ->count();

        $deniedCount = (int) (clone $eventsQuery)->where('e.permission_status', 'denied')->count();
        $highRiskCount = (int) (clone $eventsQuery)->where('e.risk', 'high')->count();
        $criticalRiskCount = (int) (clone $eventsQuery)->where('e.risk', 'critical')->count();

        // by_tool aggregation: per-tool counts. Bucket name = tool name. Sorted by count desc.
        $byTool = (clone $eventsQuery)
            ->select([
                'e.tool as bucket',
                DB::raw('COUNT(*) as calls'),
                DB::raw("SUM(CASE WHEN (e.exit_code IS NOT NULL AND e.exit_code != 0) OR (e.error IS NOT NULL AND e.error != '') THEN 1 ELSE 0 END) as failures"),
                DB::raw("SUM(CASE WHEN e.permission_status = 'denied' THEN 1 ELSE 0 END) as denied"),
                DB::raw('SUM(e.duration_ms) as duration_ms_sum'),
            ])
            ->groupBy('e.tool')
            ->orderByDesc('calls')
            ->get()
            ->map(fn ($row): array => [
                'bucket' => (string) $row->bucket,
                'calls' => (int) $row->calls,
                'failures' => (int) $row->failures,
                'denied' => (int) $row->denied,
                'duration_ms_sum' => (int) $row->duration_ms_sum,
                'failure_rate' => (int) $row->calls > 0 ? round((int) $row->failures / (int) $row->calls, 4) : null,
            ])
            ->all();

        return [
            'available' => true,
            'tool_calls_total' => $totalCalls,
            'tool_failure_count' => $failureCount,
            'tool_failure_rate' => round($failureCount / $totalCalls, 4),
            'permission_denied_count' => $deniedCount,
            'permission_denial_rate' => round($deniedCount / $totalCalls, 4),
            'high_risk_tool_count' => $highRiskCount,
            'critical_risk_tool_count' => $criticalRiskCount,
            'by_tool' => $byTool,
        ];
    }

    /**
     * Scorecard-level Atlas Decide metrics are read from score_components instead
     * of SQL JSON paths so this works consistently across SQLite, Postgres, and
     * local test schemas.
     *
     * @return array<string,mixed>
     */
    private function atlasDecideMetrics(Builder $query): array
    {
        $rows = (clone $query)
            ->get([
                'provider',
                'final_quality_score',
                'final_efficiency_score',
                'total_latency_ms',
                'cost_microusd',
                'score_components',
            ])
            ->map(fn (AiTraceMetricSummary $summary): array => [
                'summary' => $summary,
                'atlas' => data_get($summary->score_components, 'atlas_decide'),
            ])
            ->filter(fn (array $row): bool => (bool) data_get($row, 'atlas.available', false))
            ->values();

        if ($rows->isEmpty()) {
            return [
                'available' => false,
                'traces' => 0,
                'multi_stage_count' => 0,
                'multi_stage_rate' => null,
                'degraded_count' => 0,
                'degraded_rate' => null,
                'by_execution_strategy' => [],
                'by_context_strategy' => [],
                'by_selected_provider' => [],
                'by_scout_provider' => [],
            ];
        }

        $multiStageCount = $rows->filter(fn (array $row): bool => (bool) data_get($row, 'atlas.scout_enabled', false))->count();
        $degradedCount = $rows->filter(fn (array $row): bool => (bool) data_get($row, 'atlas.degraded', false))->count();

        return [
            'available' => true,
            'traces' => $rows->count(),
            'multi_stage_count' => $multiStageCount,
            'multi_stage_rate' => round($multiStageCount / $rows->count(), 4),
            'degraded_count' => $degradedCount,
            'degraded_rate' => round($degradedCount / $rows->count(), 4),
            'by_execution_strategy' => $this->aggregateAtlasDecideRows($rows, 'execution_strategy'),
            'by_context_strategy' => $this->aggregateAtlasDecideRows($rows, 'context_strategy'),
            'by_selected_provider' => $this->aggregateAtlasDecideRows($rows, 'selected_provider'),
            'by_scout_provider' => $this->aggregateAtlasDecideRows($rows, 'scout_provider'),
        ];
    }

    /**
     * @param  Collection<int,array{summary:AiTraceMetricSummary,atlas:mixed}>  $rows
     * @return array<int,array<string,mixed>>
     */
    private function aggregateAtlasDecideRows(Collection $rows, string $field): array
    {
        return $rows
            ->groupBy(fn (array $row): string => (string) (data_get($row, 'atlas.'.$field) ?: 'unknown'))
            ->map(function (Collection $group, string $bucket): array {
                $multiStageCount = $group->filter(fn (array $row): bool => (bool) data_get($row, 'atlas.scout_enabled', false))->count();
                $degradedCount = $group->filter(fn (array $row): bool => (bool) data_get($row, 'atlas.degraded', false))->count();

                return [
                    'bucket' => $bucket,
                    'traces' => $group->count(),
                    'quality_avg' => $this->avgSummaryMetric($group, 'final_quality_score'),
                    'efficiency_avg' => $this->avgSummaryMetric($group, 'final_efficiency_score'),
                    'latency_avg_ms' => $this->avgSummaryMetric($group, 'total_latency_ms'),
                    'cost_microusd_sum' => (int) $group->sum(fn (array $row): int => (int) ($row['summary']->cost_microusd ?? 0)),
                    'multi_stage_rate' => round($multiStageCount / $group->count(), 4),
                    'degraded_rate' => round($degradedCount / $group->count(), 4),
                    'providers_used' => $group
                        ->flatMap(fn (array $row): array => is_array(data_get($row, 'atlas.providers_used')) ? data_get($row, 'atlas.providers_used') : [])
                        ->filter(fn (mixed $provider): bool => is_string($provider) && $provider !== '')
                        ->unique()
                        ->values()
                        ->all(),
                ];
            })
            ->sortByDesc('traces')
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int,array{summary:AiTraceMetricSummary,atlas:mixed}>  $rows
     */
    private function avgSummaryMetric(Collection $rows, string $column): ?float
    {
        $values = $rows
            ->map(fn (array $row): mixed => $row['summary']->{$column})
            ->filter(fn (mixed $value): bool => is_numeric($value))
            ->values();

        return $values->isEmpty() ? null : round((float) $values->avg(), 2);
    }

    private function baseQuery(CarbonInterface $since, CarbonInterface $until, string $basis, bool $exclusiveUntil): Builder
    {
        $query = AiTraceMetricSummary::query();
        $untilOperator = $exclusiveUntil ? '<' : '<=';

        if ($basis === 'trace_created_at') {
            return $query->whereHas('trace', function (Builder $query) use ($since, $until, $untilOperator): void {
                $query
                    ->where('created_at', '>=', $since)
                    ->where('created_at', $untilOperator, $until);
            });
        }

        return $query
            ->where('computed_at', '>=', $since)
            ->where('computed_at', $untilOperator, $until);
    }

    private function avg(Builder $query, string $column): ?float
    {
        $value = (clone $query)->whereNotNull($column)->avg($column);

        return $value === null ? null : round((float) $value, 2);
    }

    private function rate(Builder $query, string $column): ?float
    {
        $total = (clone $query)->whereNotNull($column)->count();
        if ($total === 0) {
            return null;
        }

        $positive = (clone $query)->where($column, true)->count();

        return round($positive / $total, 4);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function aggregateBy(Builder $query, string $column): array
    {
        return (clone $query)
            ->select([
                DB::raw("COALESCE({$column}, 'unknown') as bucket"),
                DB::raw('COUNT(*) as traces'),
                DB::raw('ROUND(AVG(final_quality_score), 2) as quality_avg'),
                DB::raw('ROUND(AVG(final_efficiency_score), 2) as efficiency_avg'),
                DB::raw('ROUND(AVG(total_latency_ms), 2) as latency_avg_ms'),
                DB::raw('SUM(COALESCE(cost_microusd, 0)) as cost_microusd_sum'),
                DB::raw("SUM(CASE WHEN cost_confidence = 'unknown' THEN 1 ELSE 0 END) as unknown_cost_count"),
                DB::raw("SUM(CASE WHEN cost_confidence = 'estimated' THEN 1 ELSE 0 END) as estimated_cost_count"),
                DB::raw('SUM(CASE WHEN first_pass_success THEN 1 ELSE 0 END) as first_pass_successes'),
                DB::raw('SUM(CASE WHEN needed_remediation THEN 1 ELSE 0 END) as needed_remediations'),
            ])
            ->groupBy('bucket')
            ->orderByDesc('traces')
            ->get()
            ->map(fn ($row): array => [
                'bucket' => (string) $row->bucket,
                'traces' => (int) $row->traces,
                'quality_avg' => $row->quality_avg === null ? null : (float) $row->quality_avg,
                'efficiency_avg' => $row->efficiency_avg === null ? null : (float) $row->efficiency_avg,
                'latency_avg_ms' => $row->latency_avg_ms === null ? null : (float) $row->latency_avg_ms,
                'cost_microusd_sum' => (int) $row->cost_microusd_sum,
                'unknown_cost_count' => (int) $row->unknown_cost_count,
                'estimated_cost_count' => (int) $row->estimated_cost_count,
                'first_pass_success_rate' => (int) $row->traces > 0
                    ? round((int) $row->first_pass_successes / (int) $row->traces, 4)
                    : null,
                'needed_remediation_rate' => (int) $row->traces > 0
                    ? round((int) $row->needed_remediations / (int) $row->traces, 4)
                    : null,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<string,mixed>
     */
    private function jsonPayload(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @return array<string,mixed>
     */
    private function risks(Builder $query, array $tools): array
    {
        return [
            'low_quality_count' => (clone $query)->where('final_quality_score', '<', 60)->count(),
            'low_efficiency_count' => (clone $query)->where('final_efficiency_score', '<', 60)->count(),
            'slow_count' => (clone $query)->where('total_latency_ms', '>', 120_000)->count(),
            'needs_remediation_count' => (clone $query)->where('needed_remediation', true)->count(),
            'reask_detected_count' => (clone $query)->where('reask_detected', true)->count(),
            'provider_switch_after_response_count' => (clone $query)->where('provider_switched_after_response', true)->count(),
            // Tool risks — pure counts; threshold evaluation lives in AiTelemetryHealthService
            // so it picks up the configurable values from atlas.ai_metrics.tool_*.
            // Use the already window-scoped tools block; querying ai_tool_events here
            // directly would let old/out-of-window risks contaminate today's report.
            'tool_critical_risk_count' => (bool) ($tools['available'] ?? false)
                ? (int) ($tools['critical_risk_tool_count'] ?? 0)
                : 0,
            'tool_high_risk_count' => (bool) ($tools['available'] ?? false)
                ? (int) ($tools['high_risk_tool_count'] ?? 0)
                : 0,
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function recentLowScore(Builder $query): array
    {
        return (clone $query)
            ->where(function ($query): void {
                $query->where('final_quality_score', '<', 60)
                    ->orWhere('final_efficiency_score', '<', 60)
                    ->orWhere('needed_remediation', true);
            })
            ->latest('computed_at')
            ->limit(10)
            ->get()
            ->map(fn (AiTraceMetricSummary $summary): array => [
                'trace_id' => $summary->trace_id,
                'thread_id' => $summary->thread_id,
                'surface' => $summary->surface,
                'provider' => $summary->provider,
                'task_type' => $summary->task_type,
                'status' => $summary->status,
                'quality' => $summary->final_quality_score,
                'efficiency' => $summary->final_efficiency_score,
                'latency_ms' => $summary->total_latency_ms,
                'needed_remediation' => $summary->needed_remediation,
                'computed_at' => $summary->computed_at?->toJSON(),
            ])
            ->values()
            ->all();
    }
}
