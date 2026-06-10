<?php

declare(strict_types=1);

namespace App\Services\Ai\Finance\PolymarketExec;

/**
 * An immutable, fully-priced execution plan for one SHORT basket
 * (short_sum_over / requires_minting_full_set).
 *
 * The short arb: mint a full set of the event's outcomes on-chain for $1/set,
 * then sell the sellable legs on the CLOB for a per-set bid sum > $1, banking
 * the difference immediately. Any leg with no bid (or a bid below the sell
 * floor) is NOT sold — it is kept as a freeroll (it can only add value at
 * resolution; the profit is already locked by the sellable legs summing > $1).
 *
 * Sell legs are ordered thinnest-bid-first (the most failure-prone sale first).
 * `allTokenIds` is EVERY outcome token (what must be minted to form a full set),
 * which is a superset of the sellable `legs`.
 *
 * @phpstan-type SellLeg array{token: string, question: string, position: int, plan_depth: float, target_price: float, limit_price: float, target_size: float}
 */
final class ShortBasketPlan
{
    /**
     * @param  list<SellLeg>  $legs           sellable legs to sell (thinnest-bid-first)
     * @param  list<string>   $allTokenIds    every outcome token (the full set to mint)
     * @param  list<string>   $freerollTokens unsold outcome tokens held as freeroll
     */
    public function __construct(
        public readonly string $eventSlug,
        public readonly array $legs,
        public readonly array $allTokenIds,
        public readonly array $freerollTokens,
        public readonly ?string $conditionId,
        public readonly bool $negRisk,
        public readonly float $targetSets,
        public readonly float $targetSumBids,    // per-set sum of sellable best bids (> 1)
        public readonly float $mintCostUsd,       // capital deployed = sets * $1
        public readonly float $estProfitUsd,      // net of mint gas + fee (held legs not counted)
        public readonly float $estEdgePerSet,     // net edge per set after fee + gas
        public readonly float $estFeeUsd,
        public readonly float $estGasUsd,         // mint gas
        public readonly float $capUsd,
        public readonly int $slippageBps,
        public readonly float $executableDepthShares, // binding sellable bid depth at floor
        public readonly int $persistenceSeconds,
        public readonly ?string $resolutionAt,
        public readonly ?float $resolutionHours,
        public readonly float $minLegPrice,
        public readonly float $maxLegPrice,
    ) {}

    public string $kind { get => 'short_sum_over'; }

    public string $executionClass { get => 'requires_minting_full_set'; }

    public function nLegs(): int
    {
        return count($this->legs);
    }

    /**
     * The exact shape {@see PolyExecGate::checkOpportunity()} consumes — so the
     * short side reuses the identical structural gate as the long side.
     *
     * @return array{net_edge_per_set: float, executable_depth_shares: float, target_sets: float, target_cost_usd: float, persistence_seconds: int, resolution_hours: float|null, min_leg_price: float, max_leg_price: float}
     */
    public function toGateInput(): array
    {
        return [
            'net_edge_per_set' => $this->estEdgePerSet,
            'executable_depth_shares' => $this->executableDepthShares,
            'target_sets' => $this->targetSets,
            'target_cost_usd' => $this->mintCostUsd,
            'persistence_seconds' => $this->persistenceSeconds,
            'resolution_hours' => $this->resolutionHours,
            'min_leg_price' => $this->minLegPrice,
            'max_leg_price' => $this->maxLegPrice,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'event_slug' => $this->eventSlug,
            'kind' => $this->kind,
            'execution_class' => $this->executionClass,
            'condition_id' => $this->conditionId,
            'neg_risk' => $this->negRisk,
            'n_legs' => $this->nLegs(),
            'n_outcomes' => count($this->allTokenIds),
            'n_freeroll' => count($this->freerollTokens),
            'target_sets' => $this->targetSets,
            'target_sum_bids' => $this->targetSumBids,
            'mint_cost_usd' => $this->mintCostUsd,
            'est_profit_usd' => $this->estProfitUsd,
            'est_edge_per_set' => $this->estEdgePerSet,
            'est_fee_usd' => $this->estFeeUsd,
            'est_gas_usd' => $this->estGasUsd,
            'cap_usd' => $this->capUsd,
            'slippage_bps' => $this->slippageBps,
            'executable_depth_shares' => $this->executableDepthShares,
            'persistence_seconds' => $this->persistenceSeconds,
            'resolution_at' => $this->resolutionAt,
            'resolution_hours' => $this->resolutionHours,
            'legs' => $this->legs,
            'all_token_ids' => $this->allTokenIds,
            'freeroll_tokens' => $this->freerollTokens,
        ];
    }
}
