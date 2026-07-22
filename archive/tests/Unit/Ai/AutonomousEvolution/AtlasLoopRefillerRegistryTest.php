<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Models\AtlasLoopCampaign;
use App\Services\Ai\AutonomousEvolution\Consolidation\AtlasLoopRefillerRegistry;
use App\Services\Ai\AutonomousEvolution\Consolidation\AtlasLoopRefillerSupplyLaneContract;
use Tests\TestCase;

final class AtlasLoopRefillerRegistryTest extends TestCase
{
    private function lane(string $key): AtlasLoopRefillerSupplyLaneContract
    {
        return new class($key) implements AtlasLoopRefillerSupplyLaneContract
        {
            public function __construct(private string $key) {}

            public function laneKey(): string
            {
                return $this->key;
            }

            public function trySupply(AtlasLoopCampaign $campaign, string $provider, string $repoRoot, int $want): int
            {
                return 0;
            }
        };
    }

    public function test_register_and_byKey_return_the_registered_lane(): void
    {
        $registry = new AtlasLoopRefillerRegistry;
        $registry->register($lane = $this->lane('decompose'));

        $this->assertSame($lane, $registry->byKey('decompose'));
        $this->assertNull($registry->byKey('unknown'));
    }

    public function test_ordered_preserves_insertion_order(): void
    {
        $registry = new AtlasLoopRefillerRegistry;
        foreach (['decompose', 'dedup', 'orphan_wiring', 'doc_gap'] as $key) {
            $registry->register($this->lane($key));
        }

        $keys = array_map(static fn (AtlasLoopRefillerSupplyLaneContract $l): string => $l->laneKey(), $registry->ordered());
        $this->assertSame(['decompose', 'dedup', 'orphan_wiring', 'doc_gap'], $keys);
    }

    public function test_re_registering_same_key_replaces_the_lane_in_place(): void
    {
        $registry = new AtlasLoopRefillerRegistry;
        $registry->register($this->lane('decompose'));
        $registry->register($newer = $this->lane('decompose'));

        $this->assertSame($newer, $registry->byKey('decompose'));
        $this->assertCount(1, $registry->ordered());
    }

    public function test_empty_registry_returns_empty_ordered_and_null_byKey(): void
    {
        $registry = new AtlasLoopRefillerRegistry;
        $this->assertSame([], $registry->ordered());
        $this->assertNull($registry->byKey('decompose'));
    }
}
