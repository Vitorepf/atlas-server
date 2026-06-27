<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Brain;

/**
 * ORIGINATION GAP DETECTOR — over the done-set cycles, counts how many CYCLES have elapsed since the
 * last served|seeded one. Different from L94 freshness (which is wall-clock time on reflections):
 * this is cycle-count distance, useful when wall-clock isn't meaningful (cron paused, batch run).
 *
 * Pure + deterministic + read-only. Pétreo.
 */
final class AtlasBrainOriginationGapDetector
{
    public const SCHEMA = 'atlas.brain.origination_gap_detector.v1';

    /**
     * @return array{schema:string, total_cycles:int, last_served_index:?int, gap:int}
     */
    public function inspect(AtlasBrainDoneSetLedger $ledger, int $window = 50): array
    {
        $rows = $ledger->recentCycles(max(1, $window));
        $total = count($rows);
        if ($total === 0) {
            return ['schema' => self::SCHEMA, 'total_cycles' => 0, 'last_served_index' => null, 'gap' => 0];
        }

        $lastServed = null;
        foreach ($rows as $i => $row) {
            $status = trim((string) ($row['status'] ?? ''));
            if ($status === 'served' || $status === 'seeded') {
                $lastServed = $i;
            }
        }

        $gap = $lastServed === null ? $total : ($total - 1 - $lastServed);

        return [
            'schema' => self::SCHEMA,
            'total_cycles' => $total,
            'last_served_index' => $lastServed,
            'gap' => $gap,
        ];
    }
}
