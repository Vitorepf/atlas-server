<?php

declare(strict_types=1);

namespace App\Services\Ai\Finance\PolymarketExec;

/**
 * An immutable, fully-priced execution plan for one long basket. Produced by
 * {@see BasketPlanner} from a verified opportunity; consumed by the gate (which
 * may reject it) and the state machine (which may execute it). Legs are already
 * ordered thinnest-first — the riskiest fill is attempted first so an abort
 * leaves the fewest filled legs to unwind.
 *
 * @phpstan-type PlanLeg array{token: string, question: string, position: int, plan_depth: float, target_price: float, limit_price: float, target_size: float}
 */
final class BasketPlan
{
    /**
     * @param  list<PlanLeg>  $legs
     */
    public function __construct(
        public readonly string $eventSlug,
        public readonly string $kind,
        public readonly string $executionClass,
        public readonly array $legs,
        public readonly float $targetSets,
        public readonly float $targetSum,
        public readonly float $targetCostUsd,
        public readonly float $estProfitUsd,
        public readonly float $estEdgePerSet,
        public readonly float $estFeeUsd,
        public readonly float $estGasUsd,
        public readonly float $capUsd,
        public readonly int $slippageBps,
        public readonly float $executableDepthShares,
        public readonly int $persistenceSeconds,
        public readonly ?string $resolutionAt,
        public readonly ?float $resolutionHours,
        public readonly float $minLegPrice,
        public readonly float $maxLegPrice,
        // On-chain merge identifiers (optional; only used when long_realize_method=merge).
        // The leg tokens already form the full set, so a merge needs only the condition.
        public readonly ?string $conditionId = null,
        public readonly bool $negRisk = false,
    ) {}

    /**
     * Every leg token — the full set to merge back to $1 when realizing early.
     *
     * @return list<string>
     */
    public function tokenIds(): array
    {
        return array_map(static fn (array $leg): string => (string) $leg['token'], $this->legs);
    }

    public function nLegs(): int
    {
        return count($this->legs);
    }

    /**
     * The exact shape {@see PolyExecGate::checkOpportunity()} consumes.
     *
     * @return array{net_edge_per_set: float, executable_depth_shares: float, target_sets: float, target_cost_usd: float, persistence_seconds: int, resolution_hours: float|null, min_leg_price: float, max_leg_price: float}
     */
    public function toGateInput(): array
    {
        return [
            'net_edge_per_set' => $this->estEdgePerSet,
            'executable_depth_shares' => $this->executableDepthShares,
            'target_sets' => $this->targetSets,
            'target_cost_usd' => $this->targetCostUsd,
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
            'n_legs' => $this->nLegs(),
            'target_sets' => $this->targetSets,
            'target_sum' => $this->targetSum,
            'target_cost_usd' => $this->targetCostUsd,
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
        ];
    }
}
