<?php

declare(strict_types=1);

namespace App\Services\Ai\Finance\PolymarketShadow;

/**
 * Turns GROSS locked profit into NET profit after the real cost of capturing a
 * basket. This is the operator's "ser esperto" rule made structural: a $0.01
 * gross edge that costs $0.30 to capture is a LOSS — only net-positive baskets
 * are worth taking, however small.
 *
 * Cost model (parametrized, conservative defaults — to be REPLACED by the real
 * numbers the first $5 live basket measures on Friday; we estimate, never claim):
 *   - long side (buy every YES leg on the CLOB, then redeem/merge): CLOB trading
 *     is currently gasless/fee-free for takers, so the cost is the on-chain
 *     settle/redeem transaction(s) — modeled as a fixed per-basket cost.
 *   - short side (mint the full set on-chain, then sell legs): adds the mint
 *     transaction cost on top.
 * Per-leg cost stays a knob in case trading fees return.
 *
 * NET = gross_profit_usd - (fixed_cost[kind] + per_leg_cost * n_legs). A basket
 * is worth taking iff NET > 0 at the chosen stake/depth — independent of how
 * small the gross edge is.
 */
final class NetProfitModel
{
    public function __construct(
        private readonly float $longFixedCost = 0.10,   // settle/redeem tx (Polygon gas, conservative)
        private readonly float $shortFixedCost = 0.20,  // mint + settle txs
        private readonly float $perLegCost = 0.0,       // CLOB trading currently free; knob for future fees
    ) {}

    public function fixedCostFor(string $kind): float
    {
        return $kind === 'short_sum_over' ? $this->shortFixedCost : $this->longFixedCost;
    }

    /**
     * Capture cost for a basket of $n$ legs of the given kind.
     */
    public function captureCost(string $kind, int $nLegs): float
    {
        return $this->fixedCostFor($kind) + $this->perLegCost * max(0, $nLegs);
    }

    /**
     * @return array{gross_usd: float, cost_usd: float, net_usd: float, worth_taking: bool, breakeven_sets: float}
     */
    public function evaluate(string $kind, float $grossProfitUsd, float $profitPerSet, int $nLegs): array
    {
        $cost = $this->captureCost($kind, $nLegs);
        $net = $grossProfitUsd - $cost;

        // How many sets the basket must fill before it even covers its own cost.
        $breakeven = $profitPerSet > 0.0 ? $cost / $profitPerSet : INF;

        return [
            'gross_usd' => round($grossProfitUsd, 4),
            'cost_usd' => round($cost, 4),
            'net_usd' => round($net, 4),
            'worth_taking' => $net > 0.0,
            'breakeven_sets' => is_finite($breakeven) ? round($breakeven, 2) : -1.0,
        ];
    }
}
