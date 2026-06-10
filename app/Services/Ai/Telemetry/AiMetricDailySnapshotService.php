<?php

namespace App\Services\Ai\Telemetry;

use App\Models\AiMetricDailySnapshot;
use App\Models\AiTraceMetricSummary;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class AiMetricDailySnapshotService
{
    public function __construct(
        private readonly AiTelemetryScorecardService $scorecards,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function refresh(CarbonInterface|string $date, ?string $timezone = null): array
    {
        $timezone = $this->timezone($timezone);
        $snapshotDate = $this->date($date, $timezone);
        $start = $snapshotDate->startOfDay();
        $end = $start->addDay();

        if (! DatabaseTableAvailability::has('ai_metric_daily_snapshots')) {
            return [
                'ok' => false,
                'reason' => 'ai_metric_daily_snapshots_missing',
                'snapshot_date' => $snapshotDate->toDateString(),
            ];
        }

        $scorecard = $this->scorecards->build($start, $end, 'trace_created_at', true);
        $summaries = $this->summaries($start, $end);
        [$aggregatorVersion, $hasMixedVersions] = $this->aggregatorVersionState($summaries);
        $totals = (array) ($scorecard['totals'] ?? []);
        $tools = (array) ($scorecard['tools'] ?? []);

        $snapshots = [
            $this->valueSnapshot($snapshotDate, 'final_quality_avg', $aggregatorVersion, $summaries, 'final_quality_score', $totals['final_quality_avg'] ?? null),
            $this->valueSnapshot($snapshotDate, 'final_efficiency_avg', $aggregatorVersion, $summaries, 'final_efficiency_score', $totals['final_efficiency_avg'] ?? null),
            $this->rateSnapshot($snapshotDate, 'first_pass_success_rate', $aggregatorVersion, $summaries, 'first_pass_success', $totals['first_pass_success_rate'] ?? null),
            $this->rateSnapshot($snapshotDate, 'needed_remediation_rate', $aggregatorVersion, $summaries, 'needed_remediation', $totals['needed_remediation_rate'] ?? null),
            $this->valueSnapshot($snapshotDate, 'app_visible_avg_ms', $aggregatorVersion, $summaries, 'app_send_to_visible_ms', $totals['app_visible_avg_ms'] ?? null),
            $this->toolRateSnapshot(
                $snapshotDate,
                'tool_failure_rate',
                $aggregatorVersion,
                $summaries->count(),
                (int) ($tools['tool_failure_count'] ?? 0),
                (int) ($tools['tool_calls_total'] ?? 0),
                $tools['tool_failure_rate'] ?? null,
            ),
            $this->toolRateSnapshot(
                $snapshotDate,
                'permission_denial_rate',
                $aggregatorVersion,
                $summaries->count(),
                (int) ($tools['permission_denied_count'] ?? 0),
                (int) ($tools['tool_calls_total'] ?? 0),
                $tools['permission_denial_rate'] ?? null,
            ),
        ];

        foreach ($snapshots as $snapshot) {
            $this->persistSnapshot($snapshot);
        }

        return [
            'ok' => true,
            'snapshot_date' => $snapshotDate->toDateString(),
            'window' => [
                'start' => $start->toIso8601String(),
                'end' => $end->toIso8601String(),
                'timezone' => $timezone,
                'basis' => 'trace_created_at',
            ],
            'n_traces' => $summaries->count(),
            'aggregator_version' => $aggregatorVersion,
            'mixed_aggregator_versions' => $hasMixedVersions,
            'snapshots' => count($snapshots),
            'metrics' => collect($snapshots)->pluck('metric')->all(),
        ];
    }

    /**
     * @param  array<string,mixed>  $snapshot
     */
    private function persistSnapshot(array $snapshot): void
    {
        $row = AiMetricDailySnapshot::query()
            ->whereDate('snapshot_date', (string) $snapshot['snapshot_date'])
            ->where('metric', (string) $snapshot['metric'])
            ->first();

        if ($row instanceof AiMetricDailySnapshot) {
            $row->fill(collect($snapshot)->except(['snapshot_date', 'metric'])->all());
            $row->save();

            return;
        }

        AiMetricDailySnapshot::query()->create($snapshot);
    }

    /**
     * @return array<string,mixed>
     */
    public function refreshRange(CarbonInterface|string $endDate, int $days, ?string $timezone = null): array
    {
        $timezone = $this->timezone($timezone);
        $end = $this->date($endDate, $timezone);
        $days = max(1, min(365, $days));
        $results = [];

        for ($offset = $days - 1; $offset >= 0; $offset--) {
            $results[] = $this->refresh($end->subDays($offset), $timezone);
        }

        return [
            'ok' => collect($results)->every(fn (array $result): bool => (bool) ($result['ok'] ?? false)),
            'timezone' => $timezone,
            'end_date' => $end->toDateString(),
            'days' => $days,
            'results' => $results,
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

        if ($counts->count() > 1) {
            return ['mixed', true];
        }

        return [(string) $counts->keys()->first(), false];
    }

    /**
     * @param  Collection<int,AiTraceMetricSummary>  $summaries
     * @return array<string,mixed>
     */
    private function valueSnapshot(
        CarbonImmutable $date,
        string $metric,
        string $aggregatorVersion,
        Collection $summaries,
        string $column,
        mixed $mean,
    ): array {
        $values = $summaries
            ->pluck($column)
            ->filter(fn (mixed $value): bool => is_numeric($value))
            ->map(fn (mixed $value): float => (float) $value)
            ->values();

        return [
            'snapshot_date' => $date->toDateString(),
            'metric' => $metric,
            'aggregator_version' => $aggregatorVersion,
            'n_traces' => $summaries->count(),
            'value_mean' => is_numeric($mean) ? round((float) $mean, 6) : $this->avg($values),
            'value_p50' => $this->percentile($values, 0.50),
            'value_p95' => $this->percentile($values, 0.95),
            'value_sum' => $values->isEmpty() ? null : round((float) $values->sum(), 6),
            'value_rate' => null,
            'rate_numerator' => null,
            'rate_denominator' => null,
            'distribution_sample' => $values->take(100)->all(),
            'ewma_state' => null,
            'computed_at' => now(),
        ];
    }

    /**
     * @param  Collection<int,AiTraceMetricSummary>  $summaries
     * @return array<string,mixed>
     */
    private function rateSnapshot(
        CarbonImmutable $date,
        string $metric,
        string $aggregatorVersion,
        Collection $summaries,
        string $column,
        mixed $rate,
    ): array {
        $denominator = $summaries->whereNotNull($column)->count();
        $numerator = $summaries->where($column, true)->count();
        $value = is_numeric($rate) ? round((float) $rate, 6) : ($denominator > 0 ? round($numerator / $denominator, 6) : null);

        return [
            'snapshot_date' => $date->toDateString(),
            'metric' => $metric,
            'aggregator_version' => $aggregatorVersion,
            'n_traces' => $summaries->count(),
            'value_mean' => $value,
            'value_p50' => null,
            'value_p95' => null,
            'value_sum' => null,
            'value_rate' => $value,
            'rate_numerator' => $denominator > 0 ? $numerator : null,
            'rate_denominator' => $denominator > 0 ? $denominator : null,
            'distribution_sample' => null,
            'ewma_state' => null,
            'computed_at' => now(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function toolRateSnapshot(
        CarbonImmutable $date,
        string $metric,
        string $aggregatorVersion,
        int $traceCount,
        int $numerator,
        int $denominator,
        mixed $rate,
    ): array {
        $value = is_numeric($rate) ? round((float) $rate, 6) : ($denominator > 0 ? round($numerator / $denominator, 6) : null);

        return [
            'snapshot_date' => $date->toDateString(),
            'metric' => $metric,
            'aggregator_version' => $aggregatorVersion,
            'n_traces' => $traceCount,
            'value_mean' => $value,
            'value_p50' => null,
            'value_p95' => null,
            'value_sum' => null,
            'value_rate' => $value,
            'rate_numerator' => $denominator > 0 ? $numerator : null,
            'rate_denominator' => $denominator > 0 ? $denominator : null,
            'distribution_sample' => null,
            'ewma_state' => null,
            'computed_at' => now(),
        ];
    }

    /**
     * @param  Collection<int,float>  $values
     */
    private function avg(Collection $values): ?float
    {
        return $values->isEmpty() ? null : round((float) $values->avg(), 6);
    }

    /**
     * @param  Collection<int,float>  $values
     */
    private function percentile(Collection $values, float $percentile): ?float
    {
        $sorted = $values->sort()->values();
        $count = $sorted->count();
        if ($count === 0) {
            return null;
        }

        $index = (int) floor(($count - 1) * $percentile);

        return round((float) $sorted[$index], 6);
    }

    private function timezone(?string $timezone): string
    {
        $configured = $timezone ?: (string) config('atlas.ai_metrics.performance_report_timezone', config('app.timezone', 'UTC'));

        return $configured !== '' ? $configured : 'UTC';
    }

    private function date(CarbonInterface|string $date, string $timezone): CarbonImmutable
    {
        if ($date instanceof CarbonInterface) {
            return CarbonImmutable::instance($date)->setTimezone($timezone)->startOfDay();
        }

        return CarbonImmutable::parse($date, $timezone)->startOfDay();
    }
}
