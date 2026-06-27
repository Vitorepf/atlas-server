<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Brain;

/**
 * TREND ANALYZER — split-window comparison over the reflection stream. Splits the recent N rows into
 * two equal halves (older / newer), runs the result-kind histogram on each, and reports the starvation_pct
 * DELTA: positive = getting worse, negative = getting better, zero = flat. Same shape works for any
 * scalar over the rows; today it surfaces starvation specifically because that's the queue-health metric.
 *
 * Why a trend leap on top of the snapshot organs: snapshots tell you the brain IS stuck right now; trend
 * tells you whether it's STUCKING (deteriorating) or RECOVERING. Different action ("rotate now" vs "wait,
 * recovering on its own").
 *
 * Pure + deterministic + read-only. Pétreo: réu never edits the trend (else it'd flip the delta sign).
 */
final class AtlasBrainTrendAnalyzer
{
    public const SCHEMA = 'atlas.brain.trend_analyzer.v1';

    /**
     * @param  list<array<string,mixed>>  $reflectionRows  oldest-first
     * @return array{schema:string, window:int, older_starvation_pct:int, newer_starvation_pct:int, delta_pct:int, direction:string}
     */
    public function starvation(array $reflectionRows, int $windowEach = 25): array
    {
        $windowEach = max(1, $windowEach);
        $tail = array_slice($reflectionRows, -2 * $windowEach);

        if (count($tail) < 2 * $windowEach) {
            // Not enough data to split honestly — return flat ("insufficient_data" direction).
            return [
                'schema' => self::SCHEMA,
                'window' => $windowEach,
                'older_starvation_pct' => 0,
                'newer_starvation_pct' => 0,
                'delta_pct' => 0,
                'direction' => 'insufficient_data',
            ];
        }

        $older = array_slice($tail, 0, $windowEach);
        $newer = array_slice($tail, $windowEach);

        $hist = new AtlasBrainResultKindHistogram;
        $olderPct = $hist->histogram($older)['starvation_pct'];
        $newerPct = $hist->histogram($newer)['starvation_pct'];
        $delta = $newerPct - $olderPct;

        $direction = match (true) {
            $delta > 5 => 'worsening',
            $delta < -5 => 'recovering',
            default => 'flat',
        };

        return [
            'schema' => self::SCHEMA,
            'window' => $windowEach,
            'older_starvation_pct' => $olderPct,
            'newer_starvation_pct' => $newerPct,
            'delta_pct' => $delta,
            'direction' => $direction,
        ];
    }
}
