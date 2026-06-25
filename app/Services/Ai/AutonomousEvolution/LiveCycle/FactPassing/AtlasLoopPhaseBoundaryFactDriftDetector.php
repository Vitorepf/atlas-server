<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\LiveCycle\FactPassing;

/**
 * Pure read-side detector: scans the boundary fact receipt ledger and produces a FACTual per-boundary
 * report. NO scalar 'health_score' / 'drift_score' — only counts, ordered top-key lists and the
 * first cycle_id where a missing/unknown key appeared.
 *
 * Consumed by the operator/CLI surfaces; never mutates anything.
 */
final class AtlasLoopPhaseBoundaryFactDriftDetector
{
    public function __construct(private readonly AtlasLoopPhaseBoundaryFactReceiptLedger $ledger) {}

    /**
     * @return array<string,mixed>
     */
    public function detect(int $windowCycles): array
    {
        $rows = $this->loadAllRows();
        if ($windowCycles > 0) {
            $cycles = [];
            foreach ($rows as $row) {
                $cycles[(string) ($row['cycle_id'] ?? '')] = true;
            }
            $cycleIds = array_keys($cycles);
            $cycleIds = array_slice($cycleIds, -$windowCycles);
            $allowed = array_flip($cycleIds);
            $rows = array_values(array_filter($rows, static fn (array $r): bool => isset($allowed[(string) ($r['cycle_id'] ?? '')])));
        }

        $byBoundary = [];
        foreach ($rows as $row) {
            $boundary = (string) ($row['boundary'] ?? '');
            $byBoundary[$boundary] ??= ['rows' => []];
            $byBoundary[$boundary]['rows'][] = $row;
        }
        ksort($byBoundary);

        $report = ['boundaries' => []];
        foreach ($byBoundary as $boundary => $bucket) {
            $rows = $bucket['rows'];
            $total = count($rows);
            $invalid = 0;
            $missingCounts = [];
            $unknownCounts = [];
            $firstDivergenceCycle = null;
            foreach ($rows as $row) {
                if ((bool) ($row['validation_ok'] ?? false) === false) {
                    $invalid++;
                    if ($firstDivergenceCycle === null) {
                        $firstDivergenceCycle = (string) ($row['cycle_id'] ?? '');
                    }
                }
                foreach ((array) ($row['missing_keys'] ?? []) as $key) {
                    $missingCounts[(string) $key] = ($missingCounts[(string) $key] ?? 0) + 1;
                }
                foreach ((array) ($row['unknown_keys'] ?? []) as $key) {
                    $unknownCounts[(string) $key] = ($unknownCounts[(string) $key] ?? 0) + 1;
                }
            }

            $report['boundaries'][$boundary] = [
                'total_count' => $total,
                'invalid_count' => $invalid,
                'top_missing_keys' => $this->topN($missingCounts, 3),
                'top_unknown_keys' => $this->topN($unknownCounts, 3),
                'first_divergence_cycle_id' => $firstDivergenceCycle,
            ];
        }

        return $report;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function loadAllRows(): array
    {
        $path = $this->ledger->ledgerPath();
        if (! is_file($path)) {
            return [];
        }
        $rows = [];
        $fh = @fopen($path, 'rb');
        if ($fh === false) {
            return [];
        }
        try {
            while (($line = fgets($fh)) !== false) {
                $line = rtrim($line, "\n");
                if ($line === '') {
                    continue;
                }
                $decoded = json_decode($line, true);
                if (is_array($decoded)) {
                    $rows[] = $decoded;
                }
            }
        } finally {
            fclose($fh);
        }

        return $rows;
    }

    /**
     * @param  array<string,int>  $counts
     * @return list<array<string,mixed>>
     */
    private function topN(array $counts, int $n): array
    {
        arsort($counts);
        $out = [];
        foreach ($counts as $key => $count) {
            $out[] = ['key' => $key, 'count' => $count];
            if (count($out) >= $n) {
                break;
            }
        }

        return $out;
    }
}
