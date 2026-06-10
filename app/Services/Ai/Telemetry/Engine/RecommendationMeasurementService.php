<?php

namespace App\Services\Ai\Telemetry\Engine;

use App\Models\AiPerformanceRecommendation;
use App\Models\AiTraceMetricSummary;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class RecommendationMeasurementService
{
    /**
     * @return array<string,mixed>
     */
    public function measureDue(CarbonInterface|string|null $now = null, int $limit = 100): array
    {
        if (! DatabaseTableAvailability::has('ai_performance_recommendations')) {
            return ['ok' => false, 'reason' => 'recommendations_table_missing', 'measured' => 0, 'deferred' => 0];
        }

        $clock = $this->clock($now);
        $limit = max(1, min(500, $limit));
        $measured = [];
        $deferred = [];

        $recommendations = AiPerformanceRecommendation::query()
            ->where('state', 'applied')
            ->where(function (Builder $query) use ($clock): void {
                $query
                    ->whereNull('measurement_due_at')
                    ->orWhere('measurement_due_at', '<=', $clock);
            })
            ->orderBy('measurement_due_at')
            ->orderBy('updated_at')
            ->limit($limit)
            ->get();

        foreach ($recommendations as $recommendation) {
            $result = $this->measure($recommendation, $clock);
            if (($result['status'] ?? null) === 'deferred') {
                $deferred[] = $result;
            } else {
                $measured[] = $result;
            }
        }

        $selfHealed = $this->detectSelfHealed($clock, $limit);

        return [
            'ok' => true,
            'measured' => count($measured),
            'deferred' => count($deferred),
            'self_healed' => $selfHealed['self_healed'],
            'checked' => $recommendations->count(),
            'results' => array_merge($measured, $deferred, $selfHealed['results']),
        ];
    }

    /**
     * @return array{self_healed:int,results:array<int,array<string,mixed>>}
     */
    public function detectSelfHealed(CarbonInterface|string|null $now = null, int $limit = 100): array
    {
        if (! DatabaseTableAvailability::has('ai_performance_recommendations')) {
            return ['self_healed' => 0, 'results' => []];
        }

        $clock = $this->clock($now);
        $limit = max(1, min(500, $limit));
        $minSample = (int) config('atlas.report.recommendation_measure_min_samples', 3);
        $results = [];

        $recommendations = AiPerformanceRecommendation::query()
            ->whereIn('state', ['proposed', 'acknowledged', 'in_progress', 'snoozed'])
            ->orderByDesc('priority_score')
            ->orderBy('updated_at')
            ->limit($limit)
            ->get();

        foreach ($recommendations as $recommendation) {
            $windowDays = max(1, (int) ($recommendation->measurement_window_days ?? 7));
            $observed = $this->observedValue($recommendation, $clock->subDays($windowDays), $clock);
            if (($observed['sample_n'] ?? 0) < $minSample) {
                continue;
            }

            $impact = $this->impact($recommendation, $this->baselineValue($recommendation), $observed, $clock);
            if (($impact['effective'] ?? false) !== true) {
                continue;
            }

            $history = array_merge((array) $recommendation->state_history, [[
                'state' => 'self_healed',
                'at' => $clock->toIso8601String(),
                'reason' => 'metric_recovered_without_applied_action',
                'observed_value' => $impact['observed_value'],
                'baseline_value' => $impact['baseline_value'],
            ]]);

            $recommendation->update([
                'state' => 'self_healed',
                'observed_impact' => array_merge($impact, ['measurement_status' => 'self_healed']),
                'closed_at' => $clock,
                'closed_reason' => 'self_healed',
                'state_history' => $history,
            ]);

            $results[] = [
                'id' => $recommendation->id,
                'status' => 'self_healed',
                'target_metric' => $recommendation->target_metric,
            ];
        }

        return ['self_healed' => count($results), 'results' => $results];
    }

    /**
     * @return array<string,mixed>
     */
    public function measure(AiPerformanceRecommendation $recommendation, CarbonImmutable $clock): array
    {
        $appliedAt = $this->appliedAt($recommendation) ?? $recommendation->updated_at ?? $clock;
        $windowDays = max(1, (int) ($recommendation->measurement_window_days ?? 7));
        $baseline = $this->baselineValue($recommendation);
        $observed = $this->observedValue($recommendation, CarbonImmutable::instance($appliedAt), $clock);
        $minSample = (int) config('atlas.report.recommendation_measure_min_samples', 3);

        if (($observed['sample_n'] ?? 0) < $minSample && $baseline !== null) {
            $nextDueAt = $clock->addDay();
            $recommendation->update([
                'measurement_due_at' => $nextDueAt,
                'state_history' => array_merge((array) $recommendation->state_history, [[
                    'state' => 'applied',
                    'at' => $clock->toIso8601String(),
                    'reason' => 'measurement_deferred_insufficient_sample',
                    'sample_n' => (int) ($observed['sample_n'] ?? 0),
                    'next_due_at' => $nextDueAt->toIso8601String(),
                ]]),
            ]);

            return [
                'id' => $recommendation->id,
                'status' => 'deferred',
                'reason' => 'insufficient_sample',
                'sample_n' => (int) ($observed['sample_n'] ?? 0),
            ];
        }

        $impact = $this->impact($recommendation, $baseline, $observed, $clock);
        $history = array_merge((array) $recommendation->state_history, [[
            'state' => 'measured',
            'at' => $clock->toIso8601String(),
            'reason' => $impact['measurement_status'],
            'observed_value' => $impact['observed_value'],
            'baseline_value' => $impact['baseline_value'],
        ]]);

        $nextState = 'measured';
        $closedAt = null;
        $closedReason = null;

        if (($impact['effective'] ?? false) === true) {
            $nextState = 'resolved';
            $closedAt = $clock;
            $closedReason = 'measured_effective';
            $history[] = [
                'state' => 'resolved',
                'at' => $clock->toIso8601String(),
                'reason' => $closedReason,
            ];
        }

        $recommendation->update([
            'state' => $nextState,
            'observed_impact' => $impact,
            'measurement_due_at' => null,
            'closed_at' => $closedAt,
            'closed_reason' => $closedReason,
            'state_history' => $history,
        ]);

        return [
            'id' => $recommendation->id,
            'status' => 'measured',
            'state' => $nextState,
            'effective' => (bool) ($impact['effective'] ?? false),
            'target_metric' => $recommendation->target_metric,
            'window_days' => $windowDays,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function observedValue(AiPerformanceRecommendation $recommendation, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $metric = (string) $recommendation->target_metric;

        if (in_array($metric, ['tool_failure_rate', 'permission_denial_rate'], true)) {
            return $this->observedToolRate($recommendation, $start, $end, $metric);
        }

        $summaries = $this->matchingSummaries($recommendation, $start, $end);
        $column = $this->metricColumn($metric);
        if ($column === null) {
            return ['value' => null, 'sample_n' => 0, 'reason' => 'unsupported_metric'];
        }

        if (in_array($metric, ['first_pass_success_rate', 'needed_remediation_rate'], true)) {
            $denominator = $summaries->whereNotNull($column)->count();
            $numerator = $summaries->where($column, true)->count();

            return [
                'value' => $denominator > 0 ? round($numerator / $denominator, 6) : null,
                'sample_n' => $denominator,
                'numerator' => $numerator,
                'denominator' => $denominator,
                'basis' => 'ai_trace_metric_summaries',
            ];
        }

        $values = $summaries
            ->pluck($column)
            ->filter(fn (mixed $value): bool => is_numeric($value))
            ->map(fn (mixed $value): float => (float) $value)
            ->values();

        return [
            'value' => $values->isEmpty() ? null : round((float) $values->avg(), 6),
            'sample_n' => $values->count(),
            'basis' => 'ai_trace_metric_summaries',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function observedToolRate(AiPerformanceRecommendation $recommendation, CarbonImmutable $start, CarbonImmutable $end, string $metric): array
    {
        if (! DatabaseTableAvailability::has('ai_tool_events')) {
            return ['value' => null, 'sample_n' => 0, 'reason' => 'ai_tool_events_missing'];
        }

        $traceIds = $this->matchingSummaries($recommendation, $start, $end)
            ->pluck('trace_id')
            ->filter()
            ->values()
            ->all();

        if ($traceIds === []) {
            return ['value' => null, 'sample_n' => 0, 'reason' => 'no_matching_traces'];
        }

        $query = DB::table('ai_tool_events')->whereIn('trace_id', $traceIds);
        $denominator = (int) (clone $query)->count();
        $numerator = $metric === 'permission_denial_rate'
            ? (int) (clone $query)->where('permission_status', 'denied')->count()
            : (int) (clone $query)
                ->where(function ($query): void {
                    $query->where(function ($q): void {
                        $q->whereNotNull('exit_code')->where('exit_code', '!=', 0);
                    })->orWhere(function ($q): void {
                        $q->whereNotNull('error')->where('error', '!=', '');
                    });
                })
                ->count();

        return [
            'value' => $denominator > 0 ? round($numerator / $denominator, 6) : null,
            'sample_n' => $denominator,
            'numerator' => $numerator,
            'denominator' => $denominator,
            'basis' => 'ai_tool_events',
        ];
    }

    /**
     * @return Collection<int,AiTraceMetricSummary>
     */
    private function matchingSummaries(AiPerformanceRecommendation $recommendation, CarbonImmutable $start, CarbonImmutable $end): Collection
    {
        if (! DatabaseTableAvailability::all(['ai_trace_metric_summaries', 'ai_traces'])) {
            return collect();
        }

        $dimensions = (array) $recommendation->target_dimension;

        $query = AiTraceMetricSummary::query()
            ->whereHas('trace', function (Builder $query) use ($start, $end): void {
                $query
                    ->where('created_at', '>=', $start)
                    ->where('created_at', '<', $end);
            });

        foreach ($dimensions as $key => $value) {
            if (! is_scalar($value) || ! DatabaseTableAvailability::hasColumn('ai_trace_metric_summaries', (string) $key)) {
                continue;
            }
            $query->where((string) $key, (string) $value);
        }

        return $query->get();
    }

    private function impact(AiPerformanceRecommendation $recommendation, ?float $baseline, array $observed, CarbonImmutable $clock): array
    {
        $observedValue = is_numeric($observed['value'] ?? null) ? (float) $observed['value'] : null;
        $direction = (string) data_get($recommendation->expected_impact, 'direction', 'increase');

        if ($baseline === null || $observedValue === null) {
            return [
                'measurement_status' => 'unmeasurable',
                'measured_at' => $clock->toIso8601String(),
                'baseline_value' => $baseline,
                'observed_value' => $observedValue,
                'sample_n' => (int) ($observed['sample_n'] ?? 0),
                'effective' => false,
                'reason' => $baseline === null ? 'baseline_missing' : ($observed['reason'] ?? 'observed_value_missing'),
            ];
        }

        $deltaAbs = round($observedValue - $baseline, 6);
        $deltaPct = abs($baseline) > 0.000001 ? round($deltaAbs / abs($baseline), 6) : null;
        $improved = $direction === 'decrease' ? $deltaAbs < 0 : $deltaAbs > 0;
        $minEffect = (float) config('atlas.report.recommendation_min_effect_fraction', 0.02);
        $effective = $improved && ($deltaPct === null || abs($deltaPct) >= $minEffect);

        return [
            'measurement_status' => 'measured',
            'measured_at' => $clock->toIso8601String(),
            'baseline_value' => $baseline,
            'observed_value' => $observedValue,
            'delta_abs' => $deltaAbs,
            'delta_pct' => $deltaPct,
            'expected_direction' => $direction,
            'sample_n' => (int) ($observed['sample_n'] ?? 0),
            'basis' => $observed['basis'] ?? null,
            'effective' => $effective,
        ];
    }

    private function baselineValue(AiPerformanceRecommendation $recommendation): ?float
    {
        $baseline = (array) $recommendation->baseline_snapshot;
        $value = $baseline['today_value'] ?? $baseline['mean'] ?? null;

        return is_numeric($value) ? (float) $value : null;
    }

    private function metricColumn(string $metric): ?string
    {
        return [
            'final_quality_avg' => 'final_quality_score',
            'auto_quality_score' => 'auto_quality_score',
            'final_efficiency_avg' => 'final_efficiency_score',
            'first_pass_success_rate' => 'first_pass_success',
            'needed_remediation_rate' => 'needed_remediation',
            'app_visible_avg_ms' => 'app_send_to_visible_ms',
            'cost_per_trace_microusd' => 'cost_microusd',
            'cost_microusd_sum' => 'cost_microusd',
        ][$metric] ?? null;
    }

    private function appliedAt(AiPerformanceRecommendation $recommendation): ?CarbonImmutable
    {
        $history = collect((array) $recommendation->state_history)->reverse();
        $entry = $history->first(fn (mixed $entry): bool => is_array($entry) && ($entry['state'] ?? null) === 'applied' && is_string($entry['at'] ?? null));

        return is_array($entry) ? CarbonImmutable::parse((string) $entry['at']) : null;
    }

    private function clock(CarbonInterface|string|null $now): CarbonImmutable
    {
        if ($now instanceof CarbonInterface) {
            return CarbonImmutable::instance($now);
        }

        if (is_string($now) && trim($now) !== '') {
            return CarbonImmutable::parse(trim($now));
        }

        return CarbonImmutable::now();
    }
}
