<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Brain;

/**
 * PATH INACTIVITY ALARM — metrics-optimization organ. Given per-path "cycles since last touch"
 * counters and a threshold, returns the list of paths that have crossed the threshold (silent
 * for too long). Distinct from starvation summary in that it emits a discrete alarm event the
 * caller can act on (page operator, force rotation), instead of just reporting counts.
 *
 * Pure decision over already-built counters. No IO. Pétreo: réu would suppress alarms on its
 * preferred path.
 */
final class AtlasBrainPathInactivityAlarm
{
    public const SCHEMA = 'atlas.brain.path_inactivity_alarm.v1';

    public const DEFAULT_THRESHOLD = 10;

    /**
     * @param  array<string, int>  $cyclesSinceLast
     * @return array{schema:string, threshold:int, alarms:list<array{path:string, cycles_since_last:int}>}
     */
    public function check(array $cyclesSinceLast, int $threshold = self::DEFAULT_THRESHOLD): array
    {
        $threshold = max(1, $threshold);
        $alarms = [];
        foreach ($cyclesSinceLast as $path => $cycles) {
            $cycles = max(0, (int) $cycles);
            if ($cycles >= $threshold) {
                $alarms[] = ['path' => (string) $path, 'cycles_since_last' => $cycles];
            }
        }
        usort($alarms, static fn (array $a, array $b) => $b['cycles_since_last'] <=> $a['cycles_since_last']);

        return ['schema' => self::SCHEMA, 'threshold' => $threshold, 'alarms' => $alarms];
    }
}
