<?php

namespace App\Services\Ai\MarketingDomain\Campaign;

/**
 * KeywordUniverseEnumerator (L2 — descoberta) — ORIGINA o universo de candidatas de forma DETERMINÍSTICA
 * e SEM BURACOS, em vez de só ranquear o que recebe. Cruza as raízes próprias (owned roots — mechanism/
 * trick/slogan, que provam exposição à VSL) × uma grade de modificadores tier-tipada (PT-BR + EN), gerando
 * a matriz completa root×classe. Informacional fica FORA (vira negativa, não keyword).
 *
 * Determinismo por construção: mesma lista de roots → mesmo universo (ordenado, deduplicado), com cobertura
 * comprovável (toda raiz × toda classe = grid cheio, zero buraco) e proveniência por keyword (root +
 * modifier_class + tier_hint). É a camada que separa "escalar milhões" de "gerar 5 keywords": sem enumerar
 * o espaço, a priorização é cega ao tamanho do mercado. Alimenta o KeywordQualityIndex via asScorableTier().
 */
class KeywordUniverseEnumerator
{
    /** Modifier grid by intent class (PT-BR + EN). NO informational class — that is negative-keyword territory. */
    private const GRID = [
        'transactional' => ['comprar', 'onde comprar', 'preço', 'preco', 'site oficial', 'buy', 'order', 'where to buy', 'official'],
        'mechanism_suffix' => ['protocol', 'protocolo', 'reviews', 'review', 'funciona', 'does it work', 'results', 'resultados', 'recipe'],
        'solution' => ['como tomar', 'como usar', 'tratamento', 'natural', 'em casa', 'sem receita', 'how to use', 'at home', 'naturally'],
        'urgency' => ['rapido', 'rápido', 'agora', 'de vez', 'definitivo', 'fast', 'now'],
    ];

    private const TIER_OF = ['transactional' => 'T4', 'mechanism_suffix' => 'T4', 'solution' => 'T2', 'urgency' => 'T3', 'bare' => 'T4'];

    /**
     * @param  array<int,string>  $roots  owned roots (mechanism/trick/slogan) from the dissected asset
     * @return array{keywords:array<int,array{keyword:string,root:string,modifier_class:string,modifier:string,tier_hint:string}>,count:int,roots:array<int,string>,coverage:array<string,array<string,bool>>,complete:bool}
     */
    public function enumerate(array $roots, array $opts = []): array
    {
        $roots = array_values(array_unique(array_filter(array_map(
            fn ($r) => mb_strtolower(trim((string) $r)),
            $roots,
        ), fn ($r) => $r !== '')));
        sort($roots);

        $byKw = [];
        foreach ($roots as $root) {
            $byKw[$root] = ['keyword' => $root, 'root' => $root, 'modifier_class' => 'bare', 'modifier' => '', 'tier_hint' => self::TIER_OF['bare']];
            foreach (self::GRID as $class => $mods) {
                foreach ($mods as $m) {
                    $kw = $root.' '.$m;
                    if (! isset($byKw[$kw])) {
                        $byKw[$kw] = ['keyword' => $kw, 'root' => $root, 'modifier_class' => $class, 'modifier' => $m, 'tier_hint' => self::TIER_OF[$class]];
                    }
                }
            }
        }
        ksort($byKw); // deterministic, bit-a-bit reproducible order

        // coverage matrix: every root × every modifier class must be present → no holes.
        $classes = array_keys(self::GRID);
        $coverage = [];
        $complete = true;
        foreach ($roots as $root) {
            foreach ($classes as $class) {
                $has = false;
                foreach ($byKw as $row) {
                    if ($row['root'] === $root && $row['modifier_class'] === $class) {
                        $has = true;
                        break;
                    }
                }
                $coverage[$root][$class] = $has;
                $complete = $complete && $has;
            }
        }

        return [
            'keywords' => array_values($byKw),
            'count' => count($byKw),
            'roots' => $roots,
            'coverage' => $coverage,
            'complete' => $roots !== [] && $complete,
        ];
    }

    /**
     * Adapt the enumerated universe to the QualifiedKeywordPatternEngine result shape so it can be scored
     * by the KeywordQualityIndex (the discovery vector feeds the grading pipeline).
     *
     * @param  array<string,mixed>  $enumeration  output of enumerate()
     * @return array{tiers:array<int,array<string,mixed>>,artifacts:array<string,mixed>,flat:array<int,string>}
     */
    public function asScorableTier(array $enumeration, string $product = ''): array
    {
        $byTier = [];
        foreach ((array) ($enumeration['keywords'] ?? []) as $row) {
            $byTier[$row['tier_hint']][] = $row['keyword'];
        }
        $tiers = [];
        foreach ($byTier as $tierHint => $kws) {
            $tiers[] = [
                'family' => 'power_phrase', // owned root → high-provenance family
                'qualification' => 'high',
                'match_type' => 'exact/phrase',
                'roots' => (array) ($enumeration['roots'] ?? []),
                'keywords' => array_values(array_unique($kws)),
                'tier_hint' => $tierHint,
            ];
        }

        return [
            'tiers' => $tiers,
            'artifacts' => ['product_name' => $product],
            'flat' => array_column((array) ($enumeration['keywords'] ?? []), 'keyword'),
        ];
    }
}
