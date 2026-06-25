<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Hotpath;

/**
 * FACT-only reporter: over a fixed window of cycle facts, emit per-fqcn rows
 * {fqcn, cycle_count, window_size, frequency} ordered by cycle_count DESC then fqcn ASC.
 * Emits per-fqcn observation rows only; no aggregate scalars.
 */
final class AtlasCortexHotpathFrequencyReporter
{
    /**
     * @param  list<array{cycle_id?:string,touched_fqcns?:list<string>}>  $cycleFacts
     * @return list<array{fqcn:string,cycle_count:int,window_size:int,frequency:float}>
     */
    public function report(array $cycleFacts): array
    {
        $windowSize = count($cycleFacts);
        if ($windowSize === 0) {
            return [];
        }

        $counts = [];
        foreach ($cycleFacts as $cycle) {
            $fqcns = $cycle['touched_fqcns'] ?? [];
            if (! is_array($fqcns)) {
                continue;
            }
            $seenThisCycle = [];
            foreach ($fqcns as $fqcn) {
                if (! is_string($fqcn) || $fqcn === '' || isset($seenThisCycle[$fqcn])) {
                    continue;
                }
                $seenThisCycle[$fqcn] = true;
                $counts[$fqcn] = ($counts[$fqcn] ?? 0) + 1;
            }
        }

        $rows = [];
        foreach ($counts as $fqcn => $cycleCount) {
            $rows[] = [
                'fqcn' => $fqcn,
                'cycle_count' => $cycleCount,
                'window_size' => $windowSize,
                'frequency' => $cycleCount / $windowSize,
            ];
        }

        usort($rows, static function (array $a, array $b): int {
            if ($a['cycle_count'] !== $b['cycle_count']) {
                return $b['cycle_count'] <=> $a['cycle_count'];
            }

            return strcmp($a['fqcn'], $b['fqcn']);
        });

        return $rows;
    }
}
