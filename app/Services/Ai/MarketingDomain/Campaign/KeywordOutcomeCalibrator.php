<?php

namespace App\Services\Ai\MarketingDomain\Campaign;

/**
 * KeywordOutcomeCalibrator (L10 — flywheel) — dá ao OS a PRECISÃO que o classificador só-de-texto NÃO
 * dá (achado do ciclo 17: precisão 0% nos losers). A partir do RESULTADO REAL (term → clicks, conversions,
 * do Blackink read-only), calcula um peso CVR-lift Bayesian-shrunk por termo: o que VENDEU sobe, o loser
 * plausível-mas-não-vende (cliques sem conversão) desce. É o que separa "how to lower blood sugar" (vendeu)
 * de "how to lower a1c" (não vendeu) — gêmeos no texto, opostos no resultado.
 *
 * Bayesian shrinkage (prior = baseline da conta) evita overfit de baixa amostra: 1 venda de sorte não
 * vira peso alto; 200 cliques e 0 venda viram peso baixo robusto. Provider-free e determinístico.
 * Termo não-visto → peso 1.0 (neutro): o flywheel calibra o que já foi medido; o resto fica no prior do texto.
 */
class KeywordOutcomeCalibrator
{
    private const W_FLOOR = 0.2;

    private const W_CEIL = 2.5;

    /** abaixo disto a amostra é pequena demais pra calibrar → fica NEUTRA (não overfita 1 venda de sorte). */
    private const MIN_CLICKS = 30;

    /**
     * @param  array<int,array{term:string,clicks:int|float,conversions:int|float}>  $outcomes
     * @return array{baseline_cvr:float,prior:int,terms:int,weights:array<string,float>}
     */
    public function calibrate(array $outcomes, int $prior = 30): array
    {
        $totalClicks = 0;
        $totalConv = 0;
        foreach ($outcomes as $o) {
            $totalClicks += (int) ($o['clicks'] ?? 0);
            $totalConv += (int) ($o['conversions'] ?? 0);
        }
        $baseline = $totalClicks > 0 ? $totalConv / $totalClicks : 0.01;
        $baseline = max($baseline, 1e-5);

        $weights = [];
        foreach ($outcomes as $o) {
            $term = mb_strtolower(trim((string) ($o['term'] ?? '')));
            $c = (int) ($o['clicks'] ?? 0);
            $s = (int) ($o['conversions'] ?? 0);
            if ($term === '' || $c < self::MIN_CLICKS) {
                continue; // amostra pequena → neutro (weightFor devolve 1.0), não overfita
            }
            // CVR Bayesian-shrunk: (sucessos + prior·baseline) / (cliques + prior)
            $cvr = ($s + $prior * $baseline) / ($c + $prior);
            $lift = $cvr / $baseline;
            $weights[$term] = round(max(self::W_FLOOR, min(self::W_CEIL, $lift)), 3);
        }

        return ['baseline_cvr' => round($baseline, 5), 'prior' => $prior, 'terms' => count($weights), 'weights' => $weights];
    }

    /** Peso aprendido pro termo (1.0 neutro se nunca medido — o flywheel não inventa precisão). */
    public function weightFor(string $term, array $calibration): float
    {
        return (float) (($calibration['weights'] ?? [])[mb_strtolower(trim($term))] ?? 1.0);
    }

    /** Aplica o peso a um score de texto → score calibrado por venda real (precisão). */
    public function apply(int $textScore, string $term, array $calibration): int
    {
        return (int) round(max(0, min(100, $textScore * $this->weightFor($term, $calibration))));
    }
}
