<?php

namespace App\Services\Ai\Context;

/**
 * MAXG-05 — Diagnóstico por camada do golden vN.
 *
 * Report-only, deterministic, pure. Nenhum novo floor: `gate=false` cravado.
 * Entrada: itens de recall COM `source` ({registry,verbatim,semantic,compounding})
 * + must_include hashes já resolvidos.
 *
 * Saída, por caso:
 *   - by_source[source] => { recall_at_5_hit, first_match_rank_or_null, dcg_at_5, idcg_at_5, ndcg_at_5 }
 *   - overall => { first_match_rank_or_null (para MRR), dcg_at_5, idcg_at_5, ndcg_at_5 }
 *
 * Agregação:
 *   - by_source[source] => { recall_at_5, mrr, ndcg_at_5, cases_with_source_hit, cases }
 *   - overall => { mrr, ndcg_at_5, cases }
 *
 * Propriedade pétrea (testada): nDCG é MONOTÔNICO sob melhora de posição — mover um item
 * relevante para uma posição menor (mais alta) não pode diminuir o nDCG.
 */
final class LocalRagGoldenBySourceMetrics
{
    public const SCHEMA_VERSION = 'atlas.memory_recall_golden_by_source.v1';

    public const SOURCES = ['registry', 'verbatim', 'semantic', 'compounding'];

    public const GATE = false;

    /**
     * @param  array<int,array<string,mixed>>  $items  itens brutos do recall (com `source` e refs)
     * @param  array<int,array<string,string>>  $mustInclude  entradas normalizadas do golden
     * @return array<string,mixed>
     */
    public static function evaluateCase(array $items, array $mustInclude, string $refHashesKey = 'ref_hashes'): array
    {
        $bySource = [];
        foreach (self::SOURCES as $source) {
            $bySource[$source] = self::metricsForSubset(self::filterBySource($items, $source), $mustInclude, $refHashesKey);
        }
        $overall = self::metricsForSubset($items, $mustInclude, $refHashesKey);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'gate' => self::GATE,
            'by_source' => $bySource,
            'overall' => $overall,
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $caseMetrics  saída de evaluateCase por caso (chave 'by_source_metrics')
     * @return array<string,mixed>
     */
    public static function aggregate(array $caseMetrics): array
    {
        $sourceAgg = [];
        foreach (self::SOURCES as $source) {
            $sourceAgg[$source] = self::aggregateSubset(array_map(
                static fn (array $case): array => ($case['by_source'][$source] ?? []),
                $caseMetrics,
            ));
        }
        $overallAgg = self::aggregateSubset(array_map(
            static fn (array $case): array => ($case['overall'] ?? []),
            $caseMetrics,
        ));

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'gate' => self::GATE,
            'by_source' => $sourceAgg,
            'overall' => $overallAgg,
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $items
     * @return array<int,array<string,mixed>>
     */
    private static function filterBySource(array $items, string $source): array
    {
        return array_values(array_filter(
            $items,
            static fn (array $item): bool => (string) ($item['source'] ?? '') === $source,
        ));
    }

    /**
     * @param  array<int,array<string,mixed>>  $items
     * @param  array<int,array<string,string>>  $mustInclude
     * @return array<string,mixed>
     */
    private static function metricsForSubset(array $items, array $mustInclude, string $refHashesKey): array
    {
        $top5 = array_slice($items, 0, 5);
        $firstMatchRank = null;
        $dcg = 0.0;
        foreach ($top5 as $index => $item) {
            $rank = $index + 1;
            if (self::itemMatchesAnyExpected($item, $mustInclude, $refHashesKey)) {
                if ($firstMatchRank === null) {
                    $firstMatchRank = $rank;
                }
                $dcg += 1.0 / log($rank + 1.0, 2);
            }
        }
        $expectedCount = count($mustInclude);
        $idealHits = min($expectedCount, 5);
        $idcg = 0.0;
        for ($i = 1; $i <= $idealHits; $i++) {
            $idcg += 1.0 / log($i + 1.0, 2);
        }
        $ndcg = $idcg > 0.0 ? $dcg / $idcg : 0.0;

        return [
            'recall_at_5_hit' => $firstMatchRank !== null ? 1.0 : 0.0,
            'first_match_rank' => $firstMatchRank,
            'dcg_at_5' => round($dcg, 6),
            'idcg_at_5' => round($idcg, 6),
            'ndcg_at_5' => round($ndcg, 6),
            'candidate_count' => count($items),
        ];
    }

    /**
     * @param  array<string,mixed>  $item
     * @param  array<int,array<string,string>>  $mustInclude
     */
    private static function itemMatchesAnyExpected(array $item, array $mustInclude, string $refHashesKey): bool
    {
        $hashes = (array) ($item[$refHashesKey] ?? []);
        if ($hashes === []) {
            return false;
        }
        foreach ($mustInclude as $expected) {
            if (in_array($expected['source_ref_hash'] ?? '', $hashes, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int,array<string,mixed>>  $subsetMetrics
     * @return array<string,mixed>
     */
    private static function aggregateSubset(array $subsetMetrics): array
    {
        $n = 0;
        $hits = 0;
        $reciprocalRankSum = 0.0;
        $ndcgSum = 0.0;
        foreach ($subsetMetrics as $metric) {
            if ($metric === []) {
                continue;
            }
            $n++;
            if (($metric['recall_at_5_hit'] ?? 0.0) >= 1.0) {
                $hits++;
            }
            $rank = $metric['first_match_rank'] ?? null;
            if (is_int($rank) && $rank >= 1) {
                $reciprocalRankSum += 1.0 / (float) $rank;
            }
            $ndcgSum += (float) ($metric['ndcg_at_5'] ?? 0.0);
        }

        return [
            'cases' => $n,
            'cases_with_hit' => $hits,
            'recall_at_5' => $n > 0 ? round($hits / $n, 4) : 0.0,
            'mrr' => $n > 0 ? round($reciprocalRankSum / $n, 6) : 0.0,
            'ndcg_at_5' => $n > 0 ? round($ndcgSum / $n, 6) : 0.0,
        ];
    }
}
