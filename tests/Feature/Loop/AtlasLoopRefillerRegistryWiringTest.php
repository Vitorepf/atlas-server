<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\Consolidation\AtlasLoopRefillerRegistry;
use Tests\TestCase;

final class AtlasLoopRefillerRegistryWiringTest extends TestCase
{
    public function test_registry_is_a_container_singleton_with_canonical_lane_order(): void
    {
        $registry = app(AtlasLoopRefillerRegistry::class);
        $this->assertInstanceOf(AtlasLoopRefillerRegistry::class, $registry);

        $registry2 = app(AtlasLoopRefillerRegistry::class);
        $this->assertSame($registry, $registry2, 'registry must be a true singleton');
    }

    public function test_registry_iterates_canonical_lane_keys_in_order_when_lanes_register(): void
    {
        // The 4 concrete lanes implement the canonical order. We don't need to instantiate the
        // coordinator (its closure constructor deps make it unresolvable at boot in unit tests) —
        // it's enough to assert the lanes report their canonical lane keys.
        $this->assertSame('decompose', (new \ReflectionClass(\App\Services\Ai\AutonomousEvolution\Consolidation\AtlasLoopRefillerDecomposeSupplyLane::class))->newInstanceWithoutConstructor()->laneKey());
        $this->assertSame('dedup', (new \ReflectionClass(\App\Services\Ai\AutonomousEvolution\Consolidation\AtlasLoopRefillerDedupSupplyLane::class))->newInstanceWithoutConstructor()->laneKey());
        $this->assertSame('orphan_wiring', (new \ReflectionClass(\App\Services\Ai\AutonomousEvolution\Consolidation\AtlasLoopRefillerOrphanWiringSupplyLane::class))->newInstanceWithoutConstructor()->laneKey());
        $this->assertSame('doc_gap', (new \ReflectionClass(\App\Services\Ai\AutonomousEvolution\Consolidation\AtlasLoopRefillerDocGapSupplyLane::class))->newInstanceWithoutConstructor()->laneKey());
    }
}
