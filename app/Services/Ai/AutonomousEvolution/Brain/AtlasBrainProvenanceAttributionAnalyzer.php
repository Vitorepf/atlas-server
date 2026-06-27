<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Brain;

/**
 * PROVENANCE ATTRIBUTION ANALYZER — over the L112 ledger tail, counts seeds per source_finding code.
 * Lets operator answer "which finding code originated the most packets?" without grepping raw NDJSON.
 *
 * Pure + deterministic + read-only over L112 ledger output. Pétreo.
 */
final class AtlasBrainProvenanceAttributionAnalyzer
{
    public const SCHEMA = 'atlas.brain.provenance_attribution_analyzer.v1';

    /**
     * @param  list<array<string,mixed>>  $rows  output of L112 ledger.tail
     * @return array{schema:string, total:int, by_finding:list<array{source_finding:string, count:int, pct:int}>}
     */
    public function analyze(array $rows): array
    {
        $counts = [];
        foreach ($rows as $r) {
            $code = trim((string) ($r['source_finding'] ?? ''));
            if ($code === '') {
                $code = '(none)';
            }
            $counts[$code] = ($counts[$code] ?? 0) + 1;
        }

        $total = array_sum($counts);
        $out = [];
        foreach ($counts as $code => $n) {
            $out[] = ['source_finding' => (string) $code, 'count' => (int) $n, 'pct' => $total > 0 ? (int) round(($n * 100) / $total) : 0];
        }
        usort($out, static fn (array $a, array $b): int => [$b['count'], $a['source_finding']] <=> [$a['count'], $b['source_finding']]);

        return ['schema' => self::SCHEMA, 'total' => $total, 'by_finding' => $out];
    }
}
