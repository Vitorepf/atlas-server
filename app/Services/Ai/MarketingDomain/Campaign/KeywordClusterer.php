<?php

namespace App\Services\Ai\MarketingDomain\Campaign;

/**
 * KeywordClusterer (L7 — estrutura/clustering) — agrupa o universo enumerado em AD GROUPS single-theme
 * (STAG) de forma DETERMINÍSTICA, por owned root × tier de intenção. STAG não é estética: é o que GARANTE
 * a landing certa (priorização do Google) e mantém o Quality Score limpo — relatório §3. Aplica o barbell
 * (T4 herói → exact; T3/T2 → phrase) e isola o herói. SEM BURACOS: cada keyword cai em EXATAMENTE 1 cluster.
 *
 * Provider-free, sem dado externo. Mesma entrada → mesma topologia (clusters ordenados por tema).
 * SERP-overlap (agrupar por similaridade de resultado real) é vetor futuro — gated por dado de SERP.
 */
class KeywordClusterer
{
    /** L0 KnowledgeCore: as leis que ESTE motor aplica (proveniência por decisão). */
    public const LAWS = ['priority-exact-identical', 'exact-by-intent', 'pareto-peel-stick'];

    /**
     * @param  array<int,array{keyword:string,root?:string,tier_hint?:string}>  $rows  e.g. KeywordUniverseEnumerator::enumerate()['keywords']
     * @return array{clusters:array<int,array<string,mixed>>,count:int,keywords_total:int,no_holes:bool}
     */
    public function cluster(array $rows): array
    {
        $groups = [];
        $seen = [];
        foreach ($rows as $r) {
            $kw = mb_strtolower(trim((string) ($r['keyword'] ?? '')));
            if ($kw === '' || isset($seen[$kw])) {
                continue; // dedup: cada keyword em exatamente 1 cluster
            }
            $seen[$kw] = true;
            $root = (string) ($r['root'] ?? '');
            $tier = (string) ($r['tier_hint'] ?? 'T2');
            $key = $root.'|'.$tier;
            $groups[$key]['root'] = $root;
            $groups[$key]['tier'] = $tier;
            $groups[$key]['keywords'][] = $kw;
        }

        $clusters = [];
        foreach ($groups as $g) {
            $kws = $g['keywords'];
            sort($kws);
            $clusters[] = [
                'theme' => trim($g['root'].' — '.$g['tier']),
                'root' => $g['root'],
                'tier' => $g['tier'],
                'match_type' => $this->matchType($g['tier']),
                'is_hero' => $g['tier'] === 'T4',
                'keywords' => $kws,
                'size' => count($kws),
            ];
        }
        usort($clusters, static fn ($a, $b): int => strcmp((string) $a['theme'], (string) $b['theme']));

        $total = array_sum(array_column($clusters, 'size'));

        return [
            'clusters' => $clusters,
            'count' => count($clusters),
            'keywords_total' => $total,
            'no_holes' => $total === count($seen), // toda keyword única alocada
        ];
    }

    /** Barbell: o herói most-aware (T4) merece exact; a cauda quente (T3/T2) phrase; broad só descoberta. */
    private function matchType(string $tier): string
    {
        return match ($tier) {
            'T4' => 'exact/phrase',
            'T3', 'T2' => 'phrase',
            default => 'phrase',
        };
    }
}
