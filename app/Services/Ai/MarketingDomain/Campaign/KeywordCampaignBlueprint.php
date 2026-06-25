<?php

namespace App\Services\Ai\MarketingDomain\Campaign;

/**
 * KeywordCampaignBlueprint (L12 — a ponte INTELIGÊNCIA→AÇÃO) — compõe os outputs já produzidos (partição de
 * regime + negativos cruzados) no ARTEFATO DEPLOYÁVEL: 3 campanhas isoladas (budget/tCPA separados — a
 * alavanca #1 de escala), cada uma com ad groups single-theme (STAG por raiz), match-type e bid-strategy
 * corretos pro seu regime, e os negativos cruzados que sustentam o isolamento. É o que o operador SOBE no
 * Google Ads — sem ele, a inteligência é um relatório, não um plano.
 *
 * Assembly puro, provider-free, determinístico. Zero teoria nova: só junta o que os motores já decidiram.
 */
class KeywordCampaignBlueprint
{
    /** plano econômico por regime (da dissecação): bid/match/budget conforme o abismo de cliques-por-venda. */
    private const REGIME_PLAN = [
        'harvest' => ['bid' => 'tCPA agressivo (Maximize Conversions)', 'match' => ['exact', 'phrase'], 'budget' => 'alto', 'why' => 'colheita ~3 cliques/venda, ~30% CVR — bid agressivo, captura tudo'],
        'seed' => ['bid' => 'tCPA frouxo / tROAS moderado', 'match' => ['phrase'], 'budget' => 'médio', 'why' => 'semeadura/recall ~1,5% CVR — bid moderado, não estrangula nem desperdiça'],
        'probe' => ['bid' => 'Manual CPC / budget capado', 'match' => ['broad'], 'budget' => 'capado', 'why' => 'sonda <1,3% CVR, sensor de demanda — budget capado, NUNCA no tCPA de venda'],
    ];

    /**
     * @param  array<string,mixed>  $partition  saída de KeywordRegimeClassifier::partition()
     * @param  array<string,mixed>  $crossNegatives  saída de RegimeCrossNegativeForge::forge()
     * @return array{campaigns:array<int,array<string,mixed>>,summary:string}
     */
    public function build(array $partition, array $crossNegatives): array
    {
        $campaigns = [];
        foreach (['harvest', 'seed', 'probe'] as $regime) {
            $kws = array_values(array_filter(array_map(
                fn ($x) => mb_strtolower(trim((string) (is_array($x) ? ($x['keyword'] ?? '') : $x))),
                (array) ($partition[$regime] ?? []),
            ), fn ($k) => $k !== ''));
            if ($kws === []) {
                continue;
            }
            $plan = self::REGIME_PLAN[$regime];

            // STAG: ad groups single-theme agrupados pela RAIZ (1ª palavra) — cada keyword num só grupo
            $groups = [];
            foreach ($kws as $k) {
                $root = strtok($k, ' ') ?: $k;
                $groups[$root][] = ['keyword' => $k, 'match' => $plan['match']];
            }
            $adGroups = [];
            foreach ($groups as $root => $members) {
                $adGroups[] = ['ad_group' => $root, 'keywords' => $members];
            }

            $campaigns[] = [
                'campaign' => 'ATLAS_'.$regime,
                'regime' => $regime,
                'bid_strategy' => $plan['bid'],
                'budget' => $plan['budget'],
                'isolation' => 'budget/tCPA ISOLADO — nunca compartilha urna com outro regime',
                'ad_groups' => $adGroups,
                'negatives' => array_values((array) ($crossNegatives['by_regime'][$regime]['negatives'] ?? [])),
                'why' => $plan['why'],
            ];
        }

        return [
            'campaigns' => $campaigns,
            'summary' => sprintf(
                '%d campanhas isoladas (deployável) — %s',
                count($campaigns),
                implode(' · ', array_map(fn ($c) => $c['campaign'].'('.count($c['ad_groups']).'ag/'.$c['budget'].')', $campaigns)) ?: 'vazio',
            ),
        ];
    }
}
