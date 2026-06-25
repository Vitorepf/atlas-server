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

    public function __construct(
        private readonly KeywordRegimeClassifier $regimes = new KeywordRegimeClassifier,
    ) {}

    /**
     * @param  array<string,mixed>  $row  linha de KeywordQualityIndex::score()
     * @param  array<string,mixed>  $econ  payout, refund, cpc
     * @param  array<string,int|float>  $volumePrior  termo→cliques/mês REAIS (Blackink), domina o morfológico
     * @return array{keyword:string,expected_sales:float,expected_revenue:float,cvr_prior:float,volume:int,net_payout:float,basis:string}
     */
    public function project(array $row, array $econ = [], array $volumePrior = []): array
    {
        $kw = mb_strtolower(trim((string) ($row['keyword'] ?? '')));
        $payout = (float) ($econ['payout'] ?? 100.0);
        $refund = (float) ($econ['refund'] ?? 0.10);
        $cpc = (float) ($econ['cpc'] ?? 1.0);
        $netPayout = max(0.0, $payout * (1 - $refund));

        $cvr = $this->scoreToCvr((int) ($row['score'] ?? 0));
        $real = $volumePrior[$kw] ?? null;
        $volume = $real !== null ? (int) $real : $this->morphologyVolume($row);

        $expectedSales = $volume * $cvr;
        $expectedRevenue = $expectedSales * $netPayout - $volume * $cpc;

        return [
            'keyword' => $kw,
            'expected_sales' => round($expectedSales, 1),
            'expected_revenue' => round($expectedRevenue, 2),
            'cvr_prior' => round($cvr, 4),
            'volume' => $volume,
            'net_payout' => round($netPayout, 2),
            'basis' => $real !== null ? 'real_volume(blackink)' : 'morphology_volume',
        ];
    }

    /**
     * Rankeia um conjunto pontuado por RECEITA esperada (não por CVR). Devolve a lista ordenada + o total.
     *
     * @param  array<int,array<string,mixed>>  $scored
     * @return array{ranked:array<int,array<string,mixed>>,total_expected_revenue:float}
     */
    public function rank(array $scored, array $econ = [], array $volumePrior = []): array
    {
        $proj = array_map(fn ($r) => $this->project($r, $econ, $volumePrior), $scored);
        usort($proj, fn ($a, $b) => ($b['expected_revenue'] <=> $a['expected_revenue']) ?: strcmp($a['keyword'], $b['keyword']));

        return [
            'ranked' => $proj,
            'total_expected_revenue' => round(array_sum(array_column($proj, 'expected_revenue')), 2),
        ];
    }

    /** score (CVR-ordering prior 0-100) → estimativa de CVR. Calibrado no corpus real (score-alto≈4%, baixo≈2%). */
    private function scoreToCvr(int $score): float
    {
        $s = max(0, min(100, $score));

        return round(0.005 + ($s / 100) * 0.05, 5); // 0.5% (score 0) → 5.5% (score 100), monotônico
    }

    /** @param array<string,mixed> $row */
    private function morphologyVolume(array $row): int
    {
        $regime = $this->regimes->classify($row)['regime'];

        return self::VOLUME_PRIOR[$regime] ?? self::VOLUME_PRIOR['probe'];
    }
}
