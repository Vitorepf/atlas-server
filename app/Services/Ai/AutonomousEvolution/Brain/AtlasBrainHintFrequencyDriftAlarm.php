<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Brain;

/**
 * HINT FREQUENCY DRIFT ALARM — splits the recent reflection tail into baseline (first half) and
 * current (second half) windows; per hint compares current frequency to baseline. Flags hints
 * whose current freq is >= UPPER multiplier or <= LOWER multiplier of baseline (e.g. doubled or
 * halved). The alarm catches regime shifts that path-level signals (momentum / starvation) miss
 * because they roll up at path granularity, not hint granularity.
 *
 * Pure + deterministic, no IO. Pétreo: réu would widen multipliers to silence alarms.
 */
final class AtlasBrainHintFrequencyDriftAlarm
{
    public const SCHEMA = 'atlas.brain.hint_frequency_drift_alarm.v1';

    private const MIN_TOTAL = 8;

    private const UPPER_MULTIPLIER = 2.0;

    private const LOWER_MULTIPLIER = 0.5;

    private const MIN_BASELINE_COUNT = 2;

    /**
     * @param  list<array{action_hint?:string}>  $reflectionTail
     * @return array{schema:string, alerts:list<array{hint:string, baseline_count:int, current_count:int, ratio:float, direction:string}>}
     */
    public function detect(array $reflectionTail): array
    {
        $n = count($reflectionTail);
        if ($n < self::MIN_TOTAL) {
            return ['schema' => self::SCHEMA, 'alerts' => []];
        }

        $mid = intdiv($n, 2);
        $baseline = array_slice($reflectionTail, 0, $mid);
        $current = array_slice($reflectionTail, $mid);

        $bCounts = $this->countByHint($baseline);
        $cCounts = $this->countByHint($current);

        $hints = array_unique(array_merge(array_keys($bCounts), array_keys($cCounts)));
        $alerts = [];
        foreach ($hints as $hint) {
            $b = $bCounts[$hint] ?? 0;
            $c = $cCounts[$hint] ?? 0;
            if ($b < self::MIN_BASELINE_COUNT) {
                continue;
            }
            $ratio = $c / $b;
            if ($ratio >= self::UPPER_MULTIPLIER) {
                $alerts[] = ['hint' => $hint, 'baseline_count' => $b, 'current_count' => $c, 'ratio' => round($ratio, 4), 'direction' => 'surge'];
            } elseif ($ratio <= self::LOWER_MULTIPLIER) {
                $alerts[] = ['hint' => $hint, 'baseline_count' => $b, 'current_count' => $c, 'ratio' => round($ratio, 4), 'direction' => 'collapse'];
            }
        }

        return ['schema' => self::SCHEMA, 'alerts' => $alerts];
    }

    /**
     * @param  list<array{action_hint?:string}>  $rs
     * @return array<string, int>
     */
    private function countByHint(array $rs): array
    {
        $out = [];
        foreach ($rs as $r) {
            $h = (string) ($r['action_hint'] ?? '');
            if ($h === '') {
                continue;
            }
            $out[$h] = ($out[$h] ?? 0) + 1;
        }

        return $out;
    }
}
