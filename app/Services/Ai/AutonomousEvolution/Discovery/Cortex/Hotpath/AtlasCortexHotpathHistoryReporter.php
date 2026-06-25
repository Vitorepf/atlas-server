<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Hotpath;

/**
 * FACT-only history reporter: over a window of cycle facts (oldest-first), emit per-FQCN rows
 * with a per-cycle 0/1 appearance vector. Never scores, never judges what is hot.
 */
final class AtlasCortexHotpathHistoryReporter
{
    /**
     * @param  list<array{cycle_id?:string,touched_fqcns?:list<string>}>  $cycleFacts  oldest-first
     * @return list<array{fqcn:string,appearance_vector:list<int>,window_size:int}>
     */
    public function report(array $cycleFacts): array
    {
        $windowSize = count($cycleFacts);
        if ($windowSize === 0) {
            return [];
        }

        $allFqcns = [];
        $vectors = [];
        foreach ($cycleFacts as $index => $cycle) {
            $fqcns = $cycle['touched_fqcns'] ?? [];
            $unique = [];
            foreach ($fqcns as $fqcn) {
                if (is_string($fqcn) && $fqcn !== '') {
                    $unique[$fqcn] = true;
                }
            }
            foreach (array_keys($unique) as $fqcn) {
                if (! isset($vectors[$fqcn])) {
                    $vectors[$fqcn] = array_fill(0, $windowSize, 0);
                    $allFqcns[$fqcn] = true;
                }
                $vectors[$fqcn][$index] = 1;
            }
        }

        ksort($allFqcns, SORT_STRING);
        $rows = [];
        foreach (array_keys($allFqcns) as $fqcn) {
            $rows[] = [
                'fqcn' => $fqcn,
                'appearance_vector' => $vectors[$fqcn],
                'window_size' => $windowSize,
            ];
        }

        return $rows;
    }
}
