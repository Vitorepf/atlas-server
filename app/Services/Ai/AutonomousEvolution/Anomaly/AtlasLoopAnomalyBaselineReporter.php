<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Anomaly;

/**
 * FACTS-only baseline rate reporter for Loop signals.
 *
 * Computes per-signal counts and rates (count/total) over a caller-supplied window across a
 * caller-supplied list of receipts. NEVER alarmist: no adjectives, severities, scores or verdicts.
 *
 * Receipt shape (one row per loop event, the read-model emits these):
 *   { ts:iso8601, outcome:'completion'|'give_back'|'merge'|'cancel'|...other }
 *
 * Window:
 *   { start:iso8601, end:iso8601 }    [start, end)
 *
 * Output: deterministic, byte-identical across calls on the same input.
 */
final class AtlasLoopAnomalyBaselineReporter
{
    public const SCHEMA = 'atlas.loop.anomaly_baseline.v1';

    public const TRACKED_SIGNALS = ['completion', 'give_back', 'merge', 'cancel'];

    /**
     * @param  array{start:string, end:string}  $window
     * @param  list<array<string,mixed>>  $receipts
     * @return array<string,mixed>
     */
    public function baseline(array $window, array $receipts): array
    {
        $start = (string) ($window['start'] ?? '');
        $end = (string) ($window['end'] ?? '');

        $total = 0;
        $counts = array_fill_keys(self::TRACKED_SIGNALS, 0);

        foreach ($receipts as $r) {
            if (! is_array($r)) {
                continue;
            }
            $ts = (string) ($r['ts'] ?? '');
            if ($ts === '' || $ts < $start || $ts >= $end) {
                continue;
            }
            $total++;
            $outcome = (string) ($r['outcome'] ?? '');
            if (isset($counts[$outcome])) {
                $counts[$outcome]++;
            }
        }

        $out = ['schema_version' => self::SCHEMA];
        foreach (self::TRACKED_SIGNALS as $signal) {
            $out[$signal] = [
                'count_in_window' => $counts[$signal],
                'total_in_window' => $total,
                'rate' => $total === 0 ? 0.0 : $counts[$signal] / $total,
                'window_start' => $start,
                'window_end' => $end,
            ];
        }

        return $out;
    }
}
