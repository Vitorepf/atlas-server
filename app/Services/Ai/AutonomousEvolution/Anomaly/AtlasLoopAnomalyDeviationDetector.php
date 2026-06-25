<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Anomaly;

/**
 * Facts-only deviation detector.
 *
 * Consumes a baseline (per-signal rate + per-signal historical buckets) and a current-window rate
 * snapshot, and emits a FACT list of signals whose current-window rate deviates by more than
 * `THRESHOLD_SIGMAS` from the baseline mean.
 *
 * NEVER emits scores, verdicts, anomalous=true/false flags or alarm wording — just numbers.
 *
 * Baseline input shape:
 *   {
 *     <signal>: {
 *       rate: float,
 *       buckets: list<float>  // per-window historical rates used to compute sigma
 *     }
 *   }
 *
 * Current input shape:
 *   {
 *     window_start: iso8601,
 *     window_end: iso8601,
 *     <signal>: { rate: float }
 *   }
 */
final class AtlasLoopAnomalyDeviationDetector
{
    public const SCHEMA = 'atlas.loop.anomaly_deviation.v1';

    public const THRESHOLD_SIGMAS = 2.0;

    /**
     * @param  array<string,mixed>  $baseline
     * @param  array<string,mixed>  $currentWindow
     * @return list<array<string,mixed>>
     */
    public function detect(array $baseline, array $currentWindow): array
    {
        $start = (string) ($currentWindow['window_start'] ?? '');
        $end = (string) ($currentWindow['window_end'] ?? '');

        $signals = array_keys($baseline);
        sort($signals, SORT_STRING);

        $out = [];
        foreach ($signals as $signal) {
            $row = is_array($baseline[$signal] ?? null) ? $baseline[$signal] : null;
            if ($row === null) {
                continue;
            }
            $baselineRate = (float) ($row['rate'] ?? 0.0);
            $buckets = array_values((array) ($row['buckets'] ?? []));
            $sigma = $this->sigma($buckets);
            if ($sigma <= 0.0) {
                continue;
            }
            $currentRow = is_array($currentWindow[$signal] ?? null) ? $currentWindow[$signal] : null;
            if ($currentRow === null) {
                continue;
            }
            $currentRate = (float) ($currentRow['rate'] ?? 0.0);
            $delta = ($currentRate - $baselineRate) / $sigma;
            if (abs($delta) <= self::THRESHOLD_SIGMAS) {
                continue;
            }
            $out[] = [
                'signal' => $signal,
                'baseline_rate' => $baselineRate,
                'current_rate' => $currentRate,
                'sigma' => $sigma,
                'delta_in_sigmas' => $delta,
                'window_start' => $start,
                'window_end' => $end,
            ];
        }

        return $out;
    }

    /**
     * @param  list<float>  $buckets
     */
    private function sigma(array $buckets): float
    {
        $n = count($buckets);
        if ($n === 0) {
            return 0.0;
        }
        $mean = array_sum($buckets) / $n;
        $sumSq = 0.0;
        foreach ($buckets as $b) {
            $sumSq += ($b - $mean) ** 2;
        }

        return sqrt($sumSq / $n);
    }
}
