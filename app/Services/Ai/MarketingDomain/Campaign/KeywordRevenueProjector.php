<?php

namespace App\Services\Ai\MarketingDomain\Campaign;

/**
 * KeywordRevenueProjector — o NORTE do operador: não basta ordenar por CVR (o score já faz), o OS tem que
 * saber EXATAMENTE quais keywords trazem o MÁXIMO DE RECEITA. E receita ≠ CVR: "gelatin trick" converte só
 * 1.95% mas com 9171 cliques traz 179 vendas; "orivelle anti fungal pen" converte 37% mas com 933 cliques.
 *
 *   RECEITA ESPERADA = volume × CVR × payout_líquido − volume × CPC
 *
 * O pico de receita mora no TRADE-OFF volume×CVR — coined = CVR alta/volume baixo; sintoma/recall = CVR
 * baixa/volume alto. Este projetor cruza o CVR-prior (do score/flywheel) com o volume-prior (REAL do Blackink
 * quando existe; senão morfológico — coined tem menos busca que sintoma) e a economia, rankeando por RECEITA,
 * não por CVR. Provider-free, determinístico, honesto (basis distingue volume real de prior).
 */
class KeywordRevenueProjector
{
    /** volume-prior morfológico (buscas/mês aproximadas) quando não há dado real: o trade-off volume×CVR. */
    private const VOLUME_PRIOR = [
        'harvest' => 400,   // coined/posse/celebridade: alta intenção, POUCA busca (re-finder)
        'seed' => 4000,     // recall/recipe/trick: muita busca (a isca de topo), CVR baixa
        'probe' => 2500,    // sintoma frio: busca média-alta, CVR no chão
    ];

    /** CPC-prior por regime: a ARBITRAGEM está no clique barato — coined/mistype = leilão sem concorrente. */
    private const CPC_PRIOR = [
        'harvest' => 0.35,  // coined/marca/mistype: QS de marca alto, ninguém disputa → CPC mínimo
        'seed' => 0.80,     // recall/recipe: concorrência média
        'probe' => 1.60,    // sintoma genérico: todo afiliado/marca bida → CPC caro
    ];

    public function __construct(
        private readonly KeywordRegimeClassifier $regimes = new KeywordRegimeClassifier,
    ) {}

    /**
     * @param  array<string,mixed>  $row  linha de KeywordQualityIndex::score()
     * @param  array<string,mixed>  $econ  payout, refund, cpc
     * @param  array<string,int|float>  $volumePrior  termo→cliques/mês REAIS (Blackink), domina o morfológico
     * @param  array<string,float>  $cvrPrior  termo→CVR REAL (fração); domina o prior do score quando há
     * @return array{keyword:string,expected_sales:float,expected_revenue:float,cvr_prior:float,volume:int,net_payout:float,basis:string}
     */
    public function project(array $row, array $econ = [], array $volumePrior = [], array $cvrPrior = []): array
    {
        $kw = mb_strtolower(trim((string) ($row['keyword'] ?? '')));
        $payout = (float) ($econ['payout'] ?? 100.0);
        $refund = (float) ($econ['refund'] ?? 0.10);
        $netPayout = max(0.0, $payout * (1 - $refund));

        $regime = $this->regimes->classify($row)['regime'];
        // CVR: a REAL do termo (Blackink) domina o prior do score — projeção quase exata pra termo conhecido
        $cvr = isset($cvrPrior[$kw]) ? max(0.0, (float) $cvrPrior[$kw]) : $this->scoreToCvr((int) ($row['score'] ?? 0));
        $real = $volumePrior[$kw] ?? null;
        $volume = $real !== null ? (int) $real : (self::VOLUME_PRIOR[$regime] ?? self::VOLUME_PRIOR['probe']);
        // CPC: dado real domina; senão o prior por regime — a ARBITRAGEM está no clique barato (coined/mistype)
        $cpc = isset($econ['cpc']) ? (float) $econ['cpc'] : (self::CPC_PRIOR[$regime] ?? self::CPC_PRIOR['probe']);

        $expectedSales = $volume * $cvr;
        $expectedRevenue = $expectedSales * $netPayout;           // receita BRUTA
        $expectedProfit = $expectedRevenue - $volume * $cpc;       // LUCRO (líquido do custo de clique)

        return [
            'keyword' => $kw,
            'expected_sales' => round($expectedSales, 1),
            'expected_revenue' => round($expectedRevenue, 2),
            'expected_profit' => round($expectedProfit, 2),
            'cvr_prior' => round($cvr, 4),
            'volume' => $volume,
            'cpc' => round($cpc, 2),
            'net_payout' => round($netPayout, 2),
            'regime' => $regime,
            'basis' => $real !== null ? 'real_volume(blackink)' : 'morphology_volume',
        ];
    }

    /**
     * Rankeia um conjunto pontuado por RECEITA esperada (não por CVR). Devolve a lista ordenada + o total.
     *
     * @param  array<int,array<string,mixed>>  $scored
     * @return array{ranked:array<int,array<string,mixed>>,total_expected_revenue:float}
     */
    public function rank(array $scored, array $econ = [], array $volumePrior = [], array $cvrPrior = []): array
    {
        $proj = array_map(fn ($r) => $this->project($r, $econ, $volumePrior, $cvrPrior), $scored);
        // ordena por LUCRO esperado (o norte: máxima receita LÍQUIDA, premia a arbitragem do clique barato)
        usort($proj, fn ($a, $b) => ($b['expected_profit'] <=> $a['expected_profit']) ?: strcmp($a['keyword'], $b['keyword']));

        return [
            'ranked' => $proj,
            'total_expected_profit' => round(array_sum(array_column($proj, 'expected_profit')), 2),
            'total_expected_revenue' => round(array_sum(array_column($proj, 'expected_revenue')), 2),
        ];
    }

    /** score (CVR-ordering prior 0-100) → estimativa de CVR. Calibrado no corpus real (score-alto≈4%, baixo≈2%). */
    private function scoreToCvr(int $score): float
    {
        $s = max(0, min(100, $score));

        return round(0.005 + ($s / 100) * 0.05, 5); // 0.5% (score 0) → 5.5% (score 100), monotônico
    }
}
