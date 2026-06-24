<?php

namespace App\Services\Ai\MarketingDomain\Campaign;

/**
 * KeywordInvestmentGate — the deterministic "investimento vs gasto" decision math for a Search keyword,
 * distilled from the keyword-decision report §5 (docs/affiliate-mastery/search-network-keyword-decision-
 * report.md). It turns the operator's law ("gente errada = gasto; certeza antes de gastar = investimento")
 * into a NUMBER, not a gut-feeling. Provider-free, zero live API.
 *
 * The structural truth (FACT, not heuristic prior):
 *   • breakeven CVR        = CPC / net_payout                 (below this, the click loses money)
 *   • rule-of-three cut    = ceil(3 / breakeven_cvr) clicks   (0 sales in that many clicks ⇒ 95% sure
 *                                                               the true CVR is below breakeven ⇒ GASTO)
 *   • EPC > CPC            ⇒ INVESTIMENTO                      (each click returns more than it cost)
 *
 * Two bases, honestly labeled (the report's structural-truth-vs-prior discipline):
 *   • basis = 'proven'         — real observed clicks/conversions/revenue fed the verdict (EPC / rule-of-three)
 *   • basis = 'forecast_prior' — no live data yet (DORMANT until the operator runs a campaign); the verdict
 *                                 is a forecast from CVR×payout vs CPC, and is flagged as a PRIOR, not proof.
 *
 * Honest scope (report §5): this gate is the SIGNIFICÂNCIA port. The other two ports — ATRIBUIÇÃO
 * (DDA vs last-click, real sale via postback) and LAG (the VSL conversion window) — require live data and
 * stay dormant; a 'proven' verdict here still assumes the fed numbers are correctly attributed.
 */
class KeywordInvestmentGate
{
    /** L0 KnowledgeCore: as leis que ESTE motor aplica (proveniência por decisão). */
    public const LAWS = ['breakeven-epc', 'rule-of-three'];

    /** rule-of-three multipliers: 95% → 3.0, 97% → 3.51, 99% → 4.61 (0-success upper bound). */
    private const RULE_OF_THREE = ['95' => 3.0, '97' => 3.51, '99' => 4.61];

    /**
     * Decide whether a keyword is INVESTIMENTO / TESTE / GASTO.
     *
     * @param  array<string,mixed>  $econ      payout (gross), refund, margin?, cpc|cpc_forecast, cvr|cvr_forecast
     * @param  array<string,mixed>  $observed  optional live data: clicks, conversions, revenue (proven path)
     * @return array{verdict:string,basis:string,reason:string,breakeven_cvr:float,cut_after_clicks:?int,epc:?float,net_payout:float,cpc:float}
     */
    public function decide(array $econ, array $observed = [], string $confidence = '95'): array
    {
        $payout = (float) ($econ['payout'] ?? 0);
        $refund = (float) ($econ['refund'] ?? 0.10);
        $net = $payout * (1 - $refund);
        $cpc = (float) ($econ['cpc'] ?? $econ['cpc_forecast'] ?? 0);

        if ($net <= 0 || $cpc <= 0) {
            return $this->result('unknown', 'none', 'economics incompletas (payout/cpc ausentes)', 0.0, null, null, $net, $cpc);
        }

        $breakevenCvr = $cpc / $net;
        $k = self::RULE_OF_THREE[$confidence] ?? 3.0;
        // n_cut: clicks of zero-sale proof needed to declare GASTO at the chosen confidence.
        $cutAfter = $breakevenCvr > 0 ? (int) ceil($k / min($breakevenCvr, 1.0)) : null;

        // --- PROVEN path: real observed data decides ---
        $clicks = (int) ($observed['clicks'] ?? 0);
        if ($clicks > 0) {
            $conversions = (int) ($observed['conversions'] ?? 0);
            $revenue = (float) ($observed['revenue'] ?? 0);
            $epc = $revenue / $clicks;

            if ($conversions === 0 && $cutAfter !== null && $clicks >= $cutAfter) {
                return $this->result('gasto', 'proven',
                    "rule-of-three: {$clicks} cliques com 0 venda ≥ corte {$cutAfter} ⇒ {$confidence}% de certeza que o CVR está abaixo do breakeven",
                    $breakevenCvr, $cutAfter, $epc, $net, $cpc);
            }
            if ($epc >= $cpc) {
                return $this->result('investimento', 'proven',
                    'EPC '.round($epc, 2).' ≥ CPC '.round($cpc, 2).' — cada clique devolve mais do que custou',
                    $breakevenCvr, $cutAfter, $epc, $net, $cpc);
            }
            if ($conversions === 0) {
                return $this->result('teste', 'proven',
                    "ainda sem significância: {$clicks}/{$cutAfter} cliques de prova sem venda",
                    $breakevenCvr, $cutAfter, $epc, $net, $cpc);
            }

            return $this->result('gasto', 'proven',
                'EPC '.round($epc, 2).' < CPC '.round($cpc, 2).' — vende mas não cobre o clique',
                $breakevenCvr, $cutAfter, $epc, $net, $cpc);
        }

        // --- FORECAST PRIOR: no live data (dormant until campaign) ---
        $cvr = (float) ($econ['cvr'] ?? $econ['cvr_forecast'] ?? 0);
        if ($cvr > 0) {
            $epcForecast = $cvr * $net;
            $verdict = $epcForecast >= $cpc ? 'investimento' : ($epcForecast >= 0.8 * $cpc ? 'teste' : 'gasto');

            return $this->result($verdict, 'forecast_prior',
                'prior: EPC previsto '.round($epcForecast, 2).' vs CPC '.round($cpc, 2).' (CVR previsto '.round($cvr, 4).') — NÃO provado, calibra com venda real',
                $breakevenCvr, $cutAfter, round($epcForecast, 2), $net, $cpc);
        }

        return $this->result('teste', 'forecast_prior',
            "sem CVR previsto — testar com corte definido em {$cutAfter} cliques (0 venda ⇒ gasto)",
            $breakevenCvr, $cutAfter, null, $net, $cpc);
    }

    /**
     * @return array{verdict:string,basis:string,reason:string,breakeven_cvr:float,cut_after_clicks:?int,epc:?float,net_payout:float,cpc:float}
     */
    private function result(string $verdict, string $basis, string $reason, float $breakevenCvr, ?int $cutAfter, ?float $epc, float $net, float $cpc): array
    {
        return [
            'verdict' => $verdict,
            'basis' => $basis,
            'reason' => $reason,
            'breakeven_cvr' => round($breakevenCvr, 4),
            'cut_after_clicks' => $cutAfter,
            'epc' => $epc === null ? null : round($epc, 2),
            'net_payout' => round($net, 2),
            'cpc' => round($cpc, 2),
        ];
    }
}
