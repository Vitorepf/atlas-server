<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Brain;

/**
 * HINT BURST DETECTOR — pattern-design organ. Finds runs of N+ consecutive identical hints in the
 * reflection tail (monoculture bursts). Distinct from oscillation (alternation between two paths)
 * — a burst is "stuck on one hint for too long". Returns all detected bursts with hint, length,
 * start_index.
 *
 * Pure scan. No IO. Pétreo: réu would only report bursts in OTHER hints, not its own.
 */
final class AtlasBrainHintBurstDetector
{
    public const SCHEMA = 'atlas.brain.hint_burst_detector.v1';

    public const DEFAULT_MIN_RUN = 5;

    /**
     * @param  list<array{action_hint?:string}>  $reflectionTail
     * @return array{schema:string, bursts:list<array{hint:string, length:int, start_index:int}>}
     */
    public function detect(array $reflectionTail, int $minRun = self::DEFAULT_MIN_RUN): array
    {
        $bursts = [];
        $n = count($reflectionTail);
        if ($n < $minRun) {
            return ['schema' => self::SCHEMA, 'bursts' => $bursts];
        }

        $i = 0;
        while ($i < $n) {
            $currentHint = (string) ($reflectionTail[$i]['action_hint'] ?? '');
            if ($currentHint === '') {
                $i++;

                continue;
            }
            $start = $i;
            $j = $i + 1;
            while ($j < $n && ((string) ($reflectionTail[$j]['action_hint'] ?? '')) === $currentHint) {
                $j++;
            }
            $length = $j - $start;
            if ($length >= $minRun) {
                $bursts[] = ['hint' => $currentHint, 'length' => $length, 'start_index' => $start];
            }
            $i = $j;
        }

        return ['schema' => self::SCHEMA, 'bursts' => $bursts];
    }
}
