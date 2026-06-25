<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Consolidation;

use App\Models\AtlasLoopCampaign;

/**
 * Ordered, named map of {@see AtlasLoopRefillerSupplyLaneContract} implementations.
 *
 * Insertion order is preserved by {@see ordered()} — this MUST match the canonical Refiller lane
 * sequence (decompose → dedup → orphan_wiring → doc_gap) so behavior remains byte-identical after
 * the indirection is wired in.
 *
 * Future lanes are added by registering, not by re-opening the god-class Refiller.
 */
final class AtlasLoopRefillerRegistry
{
    /** @var array<string, AtlasLoopRefillerSupplyLaneContract> insertion-ordered */
    private array $lanes = [];

    public function register(AtlasLoopRefillerSupplyLaneContract $lane): void
    {
        $this->lanes[$lane->laneKey()] = $lane;
    }

    /**
     * @return list<AtlasLoopRefillerSupplyLaneContract>
     */
    public function ordered(): array
    {
        return array_values($this->lanes);
    }

    public function byKey(string $key): ?AtlasLoopRefillerSupplyLaneContract
    {
        return $this->lanes[$key] ?? null;
    }
}

/**
 * Concrete lane that delegates to {@see AtlasLoopRefillerSupplyLaneCoordinator::trySupply()} with
 * the canonical `decompose` lane key. Holds no policy — pure indirection so the registry can
 * iterate without the Refiller hard-coding switch arms.
 */
final class AtlasLoopRefillerDecomposeSupplyLane implements AtlasLoopRefillerSupplyLaneContract
{
    public function __construct(private readonly AtlasLoopRefillerSupplyLaneCoordinator $coordinator) {}

    public function laneKey(): string
    {
        return 'decompose';
    }

    public function trySupply(AtlasLoopCampaign $campaign, string $provider, string $repoRoot, int $want): int
    {
        return $this->coordinator->trySupply($campaign, $provider, $repoRoot, $want, $this->laneKey());
    }
}

final class AtlasLoopRefillerDedupSupplyLane implements AtlasLoopRefillerSupplyLaneContract
{
    public function __construct(private readonly AtlasLoopRefillerSupplyLaneCoordinator $coordinator) {}

    public function laneKey(): string
    {
        return 'dedup';
    }

    public function trySupply(AtlasLoopCampaign $campaign, string $provider, string $repoRoot, int $want): int
    {
        return $this->coordinator->trySupply($campaign, $provider, $repoRoot, $want, $this->laneKey());
    }
}

final class AtlasLoopRefillerOrphanWiringSupplyLane implements AtlasLoopRefillerSupplyLaneContract
{
    public function __construct(private readonly AtlasLoopRefillerSupplyLaneCoordinator $coordinator) {}

    public function laneKey(): string
    {
        return 'orphan_wiring';
    }

    public function trySupply(AtlasLoopCampaign $campaign, string $provider, string $repoRoot, int $want): int
    {
        return $this->coordinator->trySupply($campaign, $provider, $repoRoot, $want, $this->laneKey());
    }
}

final class AtlasLoopRefillerDocGapSupplyLane implements AtlasLoopRefillerSupplyLaneContract
{
    public function __construct(private readonly AtlasLoopRefillerSupplyLaneCoordinator $coordinator) {}

    public function laneKey(): string
    {
        return 'doc_gap';
    }

    public function trySupply(AtlasLoopCampaign $campaign, string $provider, string $repoRoot, int $want): int
    {
        return $this->coordinator->trySupply($campaign, $provider, $repoRoot, $want, $this->laneKey());
    }
}
