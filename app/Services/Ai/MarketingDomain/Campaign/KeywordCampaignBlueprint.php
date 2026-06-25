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
    /** raiz precisa de ≥ isto pra virar ad group próprio; senão consolida no long-tail (anti-starvation de sinal). */
    private const MIN_ADGROUP_SIZE = 3;

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

            // STAG single-theme por RAIZ, MAS com consolidação anti-starvation: raiz com ≥MIN keywords vira ad
            // group próprio (tema com sinal pro Smart Bidding); a CAUDA (raízes de 1-2 kw) consolida num único
            // "<regime>_long_tail" — senão viram 200+ grupos de 1 keyword que NUNCA atingem o limiar de conversão.
            $groups = [];
            foreach ($kws as $k) {
                $root = strtok($k, ' ') ?: $k;
                $groups[$root][] = ['keyword' => $k, 'match' => $plan['match']];
            }
            $adGroups = [];
            $tail = [];
            foreach ($groups as $root => $members) {
                if (count($members) >= self::MIN_ADGROUP_SIZE) {
                    $adGroups[] = ['ad_group' => (string) $root, 'keywords' => $members];
                } else {
                    $tail = array_merge($tail, $members);
                }
            }
            if ($tail !== []) {
                $adGroups[] = ['ad_group' => $regime.'_long_tail', 'consolidated' => true, 'keywords' => $tail];
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
