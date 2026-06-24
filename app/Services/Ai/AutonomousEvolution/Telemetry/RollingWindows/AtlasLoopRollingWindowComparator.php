<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Telemetry\RollingWindows;

use InvalidArgumentException;

final class AtlasLoopRollingWindowComparator
{
    /**
     * @param  array<string,mixed>  $currentBucket
     * @param  array<string,mixed>  $previousBucket
     * @return array{
     *     window_label:string,
     *     current_window_start_iso:string,
     *     previous_window_start_iso:string,
     *     count_delta:array<string,array{current:?int,previous:?int,delta:?int,present_in_both:bool}>,
     *     duration_delta_ms:array<string,array{
     *         p50_delta_ms:?int,
     *         p95_delta_ms:?int,
     *         present_in_both:bool
     *     }>,
     *     cost_delta_micros:?int,
     *     anomaly_count_delta:?int
     * }
     */
    public function compare(array $currentBucket, array $previousBucket): array
    {
        $currentLabel = (string) ($currentBucket['window_label'] ?? '');
        $previousLabel = (string) ($previousBucket['window_label'] ?? '');

        if ($currentLabel === '' || $currentLabel !== $previousLabel) {
            throw new InvalidArgumentException('Buckets must share the same window_label.');
        }

        if ((string) ($previousBucket['window_end_iso'] ?? '') !== (string) ($currentBucket['window_start_iso'] ?? '')) {
            throw new InvalidArgumentException('Buckets must be adjacent: previous.window_end_iso must equal current.window_start_iso.');
        }

        return [
            'window_label' => $currentLabel,
            'current_window_start_iso' => (string) ($currentBucket['window_start_iso'] ?? ''),
            'previous_window_start_iso' => (string) ($previousBucket['window_start_iso'] ?? ''),
            'count_delta' => $this->countDelta(
                is_array($currentBucket['counts'] ?? null) ? $currentBucket['counts'] : [],
                is_array($previousBucket['counts'] ?? null) ? $previousBucket['counts'] : [],
            ),
            'duration_delta_ms' => $this->durationDelta(
                is_array($currentBucket['durations_ms'] ?? null) ? $currentBucket['durations_ms'] : [],
                is_array($previousBucket['durations_ms'] ?? null) ? $previousBucket['durations_ms'] : [],
            ),
            'cost_delta_micros' => $this->nullableDelta($currentBucket['cost_sum_micros'] ?? null, $previousBucket['cost_sum_micros'] ?? null),
            'anomaly_count_delta' => $this->nullableDelta($currentBucket['anomaly_count'] ?? null, $previousBucket['anomaly_count'] ?? null),
        ];
    }

    /**
     * @param  array<string,mixed>  $currentCounts
     * @param  array<string,mixed>  $previousCounts
     * @return array<string,array{current:?int,previous:?int,delta:?int,present_in_both:bool}>
     */
    private function countDelta(array $currentCounts, array $previousCounts): array
    {
        $signals = array_values(array_unique(array_merge(array_keys($currentCounts), array_keys($previousCounts))));
        sort($signals, SORT_STRING);

        $delta = [];
        foreach ($signals as $signal) {
            $current = isset($currentCounts[$signal]) && is_numeric($currentCounts[$signal]) ? (int) $currentCounts[$signal] : null;
            $previous = isset($previousCounts[$signal]) && is_numeric($previousCounts[$signal]) ? (int) $previousCounts[$signal] : null;

            $delta[$signal] = [
                'current' => $current,
                'previous' => $previous,
                'delta' => ($current !== null && $previous !== null) ? $current - $previous : null,
                'present_in_both' => $current !== null && $previous !== null,
            ];
        }

        return $delta;
    }

    /**
     * @param  array<string,mixed>  $currentDurations
     * @param  array<string,mixed>  $previousDurations
     * @return array<string,array{p50_delta_ms:?int,p95_delta_ms:?int,present_in_both:bool}>
     */
    private function durationDelta(array $currentDurations, array $previousDurations): array
    {
        $transitions = array_values(array_unique(array_merge(array_keys($currentDurations), array_keys($previousDurations))));
        sort($transitions, SORT_STRING);

        $delta = [];
        foreach ($transitions as $transition) {
            $current = is_array($currentDurations[$transition] ?? null) ? $currentDurations[$transition] : [];
            $previous = is_array($previousDurations[$transition] ?? null) ? $previousDurations[$transition] : [];

            $currentP50 = isset($current['p50']) && is_numeric($current['p50']) ? (int) $current['p50'] : null;
            $previousP50 = isset($previous['p50']) && is_numeric($previous['p50']) ? (int) $previous['p50'] : null;
            $currentP95 = isset($current['p95']) && is_numeric($current['p95']) ? (int) $current['p95'] : null;
            $previousP95 = isset($previous['p95']) && is_numeric($previous['p95']) ? (int) $previous['p95'] : null;

            $delta[$transition] = [
                'p50_delta_ms' => ($currentP50 !== null && $previousP50 !== null) ? $currentP50 - $previousP50 : null,
                'p95_delta_ms' => ($currentP95 !== null && $previousP95 !== null) ? $currentP95 - $previousP95 : null,
                'present_in_both' => ($currentP50 !== null || $currentP95 !== null) && ($previousP50 !== null || $previousP95 !== null),
            ];
        }

        return $delta;
    }

    private function nullableDelta(mixed $current, mixed $previous): ?int
    {
        if (! is_numeric($current) || ! is_numeric($previous)) {
            return null;
        }

        return (int) $current - (int) $previous;
    }
}
