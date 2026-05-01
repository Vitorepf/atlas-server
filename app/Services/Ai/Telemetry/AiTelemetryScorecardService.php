<?php

namespace App\Services\Ai\Telemetry;

use App\Models\AiTraceMetricSummary;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
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
    ): array
    {
        $until ??= now();

        if (! Schema::hasTable('ai_trace_metric_summaries')) {
            return ['available' => false];
        }

        if ($basis === 'trace_created_at' && ! Schema::hasTable('ai_traces')) {
            return ['available' => false, 'reason' => 'ai_traces_missing'];
        }

        $base = $this->baseQuery($since, $until, $basis, $exclusiveUntil);
        $count = (clone $base)->count();

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
                'cost_microusd_sum' => (int) ((clone $base)->sum('cost_microusd') ?? 0),
                'unknown_cost_count' => (clone $base)->where('cost_confidence', 'unknown')->count(),
                'estimated_cost_count' => (clone $base)->where('cost_confidence', 'estimated')->count(),
                'actual_cost_count' => (clone $base)->where('cost_confidence', 'actual')->count(),
                'operational_estimate_cost_count' => (clone $base)->where('cost_mode', 'operational_estimate')->count(),
                'first_pass_success_rate' => $this->rate($base, 'first_pass_success'),
                'needed_remediation_rate' => $this->rate($base, 'needed_remediation'),
                'backgrounded_during_run_rate' => $this->rate($base, 'backgrounded_during_run'),
                'recovered_from_pending_count' => (clone $base)->where('recovered_from_pending', true)->count(),
                'reask_detected_count' => (clone $base)->where('reask_detected', true)->count(),
            ],
            'by_surface' => $this->aggregateBy($base, 'surface'),
            'by_provider' => $this->aggregateBy($base, 'provider'),
            'by_model' => $this->aggregateBy($base, 'model'),
            'by_agent' => $this->aggregateBy($base, 'agent_slug'),
            'by_task_type' => $this->aggregateBy($base, 'task_type'),
            'risks' => $this->risks($base),
            'recent_low_score' => $this->recentLowScore($base),
        ];
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
                DB::raw("SUM(CASE WHEN first_pass_success THEN 1 ELSE 0 END) as first_pass_successes"),
                DB::raw("SUM(CASE WHEN needed_remediation THEN 1 ELSE 0 END) as needed_remediations"),
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
    private function risks(Builder $query): array
    {
        return [
            'low_quality_count' => (clone $query)->where('final_quality_score', '<', 60)->count(),
            'low_efficiency_count' => (clone $query)->where('final_efficiency_score', '<', 60)->count(),
            'slow_count' => (clone $query)->where('total_latency_ms', '>', 120_000)->count(),
            'needs_remediation_count' => (clone $query)->where('needed_remediation', true)->count(),
            'reask_detected_count' => (clone $query)->where('reask_detected', true)->count(),
            'provider_switch_after_response_count' => (clone $query)->where('provider_switched_after_response', true)->count(),
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
