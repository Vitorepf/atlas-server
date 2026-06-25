<?php

namespace App\Services\Ai\MarketingDomain\Campaign;

/**
 * KeywordBudgetAllocator — o que os players de >R$200M/mês fazem: não escolher a MELHOR keyword, mas o melhor
 * CONJUNTO sob restrição de BUDGET. Dado o lucro/custo projetado por keyword (KeywordRevenueProjector) e um
 * budget, aloca o gasto pra MAXIMIZAR o lucro total.
 *
 * Spend em busca é divisível (cap de cliques) → greedy por EFICIÊNCIA (ROAS = receita/custo) é ÓTIMO: gasta o
 * budget no keyword de maior ROAS primeiro, depois o próximo, até esgotar. Money-losers (lucro ≤ 0) NUNCA
 * entram. É a diferença entre "tenho 50 boas keywords" e "sei EXATAMENTE como gastar R$X pra extrair o máximo
 * de lucro". Provider-free, determinístico.
 */
class KeywordBudgetAllocator
{
    /**
     * @param  array<int,array<string,mixed>>  $projected  linhas de KeywordRevenueProjector::project()
     * @return array{portfolio:array<int,array<string,mixed>>,total_profit:float,budget_used:float,budget:float,blended_roas:float,excluded_losers:int}
     */
    public function allocate(array $projected, float $budget): array
    {
        $budget = max(0.0, $budget);
        $losers = 0;
        $eligible = [];
        foreach ($projected as $p) {
            $cost = $this->cost($p);
            if (($p['expected_profit'] ?? 0) <= 0 || $cost <= 0) {
                $losers++; // money-loser ou sem custo → fora do portfólio

                continue;
            }
            $eligible[] = $p;
        }
        // mais eficiente (ROAS) primeiro — desempate estável pela keyword
        usort($eligible, fn ($a, $b) => ($this->roas($b) <=> $this->roas($a)) ?: strcmp((string) ($a['keyword'] ?? ''), (string) ($b['keyword'] ?? '')));

        $remaining = $budget;
        $portfolio = [];
        $totalProfit = 0.0;
        $totalSpend = 0.0;
        foreach ($eligible as $p) {
            if ($remaining <= 0.0) {
                break;
            }
            $maxCost = $this->cost($p);                 // gasto pleno se capturar todo o volume
            $spend = min($remaining, $maxCost);
            $frac = $maxCost > 0 ? $spend / $maxCost : 0.0; // fração do volume comprada com o budget restante
            $profit = (float) ($p['expected_profit'] ?? 0) * $frac;
            $portfolio[] = [
                'keyword' => $p['keyword'] ?? '',
                'spend' => round($spend, 2),
                'captured_profit' => round($profit, 2),
                'roas' => round($this->roas($p), 2),
                'fraction' => round($frac, 3),
            ];
            $totalProfit += $profit;
            $totalSpend += $spend;
            $remaining -= $spend;
        }

        return [
            'portfolio' => $portfolio,
            'total_profit' => round($totalProfit, 2),
            'budget_used' => round($totalSpend, 2),
            'budget' => round($budget, 2),
            'blended_roas' => $totalSpend > 0 ? round(($totalProfit + $totalSpend) / $totalSpend, 2) : 0.0,
            'excluded_losers' => $losers,
        ];
    }

    /** @param array<string,mixed> $p */
    private function cost(array $p): float
    {
        return (float) ($p['volume'] ?? 0) * (float) ($p['cpc'] ?? 0);
    }

    /** @param array<string,mixed> $p */
    private function roas(array $p): float
    {
        $cost = $this->cost($p);

        return $cost > 0 ? (float) ($p['expected_revenue'] ?? 0) / $cost : 0.0;
    }
}
