<?php

declare(strict_types=1);

namespace App\Services\Ai\Telemetry;

use Illuminate\Support\Collection;

/**
 * NUMERIC / STATISTICS helper concern, extracted from the god-class
 * {@see AiTelemetryPerformanceReportService}.
 *
 * Owns every pure numeric helper: percentile/avgCollection/delta/deltaValue/deltaPct/number/confidence/
 * multiConfidence/averageHealthScore/statusRank/strongestStatus/priorityScore.
 *
 * No dependencies on the service — purely deterministic transformations on the inputs.
 */
class AiTelemetryReportMetricMath
{
    /**
     * @param  array<int,string>  $statuses
     */
    public function strongestStatus(array $statuses): string
    {
        return collect($statuses)
            ->sortByDesc(fn (string $status): int => $this->statusRank($status))
            ->first() ?: 'unknown';
    }

    public function statusRank(string $status): int
    {
        return match ($status) {
            'critical' => 4,
            'warning' => 3,
            'watch' => 2,
            'healthy' => 1,
            default => 0,
        };
    }

    public function priorityScore(string $status): int
    {
        return match ($status) {
            'critical' => 92,
            'warning' => 82,
            'watch' => 68,
            default => 60,
        };
    }

    /**
     * @param  array<string,mixed>  $analysis
     */
    public function confidence(array $analysis): float
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
    public function multiConfidence(array $reports): float
    {
        $traces = collect($reports)->sum(fn (array $report): int => (int) data_get($report, 'summary.traces', 0));

        return $traces === 0 ? 0.45 : 0.78;
    }

    /**
     * @param  array<int,array<string,mixed>>  $reports
     */
    public function averageHealthScore(array $reports): ?int
    {
        $scores = collect($reports)->pluck('health_score')->filter(fn (mixed $value): bool => is_numeric($value))->values();

        return $scores->isEmpty() ? null : (int) round($scores->avg());
    }

    /**
     * @return array{current:mixed,previous:mixed,delta_abs:?float,delta_pct:?float}
     */
    public function delta(mixed $current, mixed $previous): array
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

    /**
     * @param  array<string,mixed>  $comparison
     */
    public function deltaValue(array $comparison, string $field): float
    {
        return is_numeric(data_get($comparison, "{$field}.delta_abs")) ? (float) data_get($comparison, "{$field}.delta_abs") : 0.0;
    }

    /**
     * @param  array<string,mixed>  $comparison
     */
    public function deltaPct(array $comparison, string $field): float
    {
        return is_numeric(data_get($comparison, "{$field}.delta_pct")) ? (float) data_get($comparison, "{$field}.delta_pct") : 0.0;
    }

    public function number(mixed $value): ?float
    {
        return is_numeric($value) ? round((float) $value, 4) : null;
    }

    /**
     * @param  Collection<int,mixed>  $values
     */
    public function percentile(Collection $values, float $percentile): ?float
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

    /**
     * @param  Collection<int,mixed>  $values
     */
    public function avgCollection(Collection $values): ?float
    {
        $numbers = $values->filter(fn (mixed $value): bool => is_numeric($value));

        return $numbers->isEmpty() ? null : round((float) $numbers->avg(), 2);
    }
}
