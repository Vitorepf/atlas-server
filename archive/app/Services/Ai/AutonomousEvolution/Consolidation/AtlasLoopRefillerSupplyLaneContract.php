<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Consolidation;

use App\Models\AtlasLoopCampaign;

/**
 * Registry-mediated contract for a Loop Refiller supply lane.
 *
 * Each concrete lane (decompose, dedup, orphan_wiring, doc_gap, …) implements `trySupply()` and
 * carries its `laneKey()` so the registry can preserve canonical ordering. Future lanes register
 * without editing the Refiller — this is the indirection that makes the root↔Discovery edges
 * collapse to a single registry-mediated hop.
 */
interface AtlasLoopRefillerSupplyLaneContract
{
    public function laneKey(): string;

    public function trySupply(AtlasLoopCampaign $campaign, string $provider, string $repoRoot, int $want): int;
}
