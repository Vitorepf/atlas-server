<?php

namespace App\Services\Ai\MarketingDomain\Campaign;

/**
 * RegimeCrossNegativeForge — ENFORCEMENT da alavanca #1 de escala: o KeywordRegimeClassifier ISOLA harvest/
 * seed/probe em 3 urnas, mas no Google Ads o isolamento só se SUSTENTA com NEGATIVOS CRUZADOS. Sem eles, com
 * Smart Bidding + phrase/broad, a campanha de clique-barato (harvest) e a de clique-caro (probe) servem nas
 * queries uma da outra → canibalização (a lei `scaling-readiness-2026` mede 20-30% de desperdício) + atribuição
 * suja que estrangula o tCPA dos winners.
 *
 * Gera, por urna, os negativos EXATOS = os termos das OUTRAS urnas (exact não over-bloqueia: barra só a query
 * idêntica, roteando-a pra urna dona). Determinístico, provider-free, sem buraco (cobre todo par de urnas).
 */
class RegimeCrossNegativeForge
{
    private const REGIMES = ['harvest', 'seed', 'probe'];

    /**
     * @param  array<string,mixed>  $partition  saída de KeywordRegimeClassifier::partition()
     * @return array{by_regime:array<string,array{negatives:array<int,array{term:string,match:string}>,why:string}>,summary:string}
     */
    public function forge(array $partition): array
    {
        $terms = [];
        foreach (self::REGIMES as $r) {
            $terms[$r] = array_values(array_unique(array_filter(array_map(
                fn ($x) => mb_strtolower(trim((string) (is_array($x) ? ($x['keyword'] ?? '') : $x))),
                (array) ($partition[$r] ?? []),
            ), fn ($t) => $t !== '')));
        }

        $byRegime = [];
        $totalNeg = 0;
        foreach (self::REGIMES as $r) {
            $own = array_flip($terms[$r]);
            $negs = [];
            foreach (self::REGIMES as $other) {
                if ($other === $r) {
                    continue;
                }
                foreach ($terms[$other] as $t) {
                    // não auto-bloquear: nunca negativa um termo que TAMBÉM é próprio desta urna
                    if (! isset($own[$t]) && ! isset($negs[$t])) {
                        $negs[$t] = true;
                    }
                }
            }
            $list = array_map(fn ($t) => ['term' => $t, 'match' => 'exact'], array_keys($negs));
            $totalNeg += count($list);
            $byRegime[$r] = [
                'negatives' => $list,
                'why' => "urna {$r} nega (exact) os termos das outras → não rouba o leilão delas (anti-canibalização)",
            ];
        }

        return [
            'by_regime' => $byRegime,
            'summary' => sprintf(
                '%d negativos cruzados (harvest⊥seed⊥probe) — isolamento de tCPA/budget sustentado, anti-canibalização (lei scaling-2026: ~20-30%% de waste evitado)',
                $totalNeg,
            ),
        ];
    }
}
