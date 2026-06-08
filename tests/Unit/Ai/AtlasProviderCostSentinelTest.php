<?php

declare(strict_types=1);

namespace Tests\Unit\Ai;

use App\Models\AiJob;
use App\Services\Ai\Caching\AtlasProviderCostSentinel;
use Tests\TestCase;

class AtlasProviderCostSentinelTest extends TestCase
{
    private function sentinel(): AtlasProviderCostSentinel
    {
        return app(AtlasProviderCostSentinel::class);
    }

    private function job(): AiJob
    {
        return (new AiJob())->forceFill(['kind' => 'atlas_dev', 'payload' => []]);
    }

    public function test_default_is_inert_hard_gate_off(): void
    {
        config(['atlas.ai.cost_sentinel.hard_gate_units' => 0.0]);

        $r = $this->sentinel()->assess($this->job(), str_repeat('expensive prompt ', 200));

        $this->assertTrue($r['evaluated']);
        // With no configured ceiling the sentinel NEVER blocks, regardless of cost.
        $this->assertFalse($r['hard_blocked']);
        $this->assertSame(0.0, $r['hard_threshold_units']);
    }

    public function test_hard_gate_blocks_only_when_operator_sets_a_ceiling(): void
    {
        config(['atlas.ai.cost_sentinel.hard_gate_units' => 0.0001]);

        $r = $this->sentinel()->assess($this->job(), str_repeat('expensive prompt ', 500));

        $this->assertTrue($r['hard_blocked']);
        $this->assertGreaterThan(0.0, $r['pre_cost_units']);
        $this->assertSame(0.0001, $r['hard_threshold_units']);
    }

    public function test_assess_returns_a_flag_never_throws(): void
    {
        config(['atlas.ai.cost_sentinel.hard_gate_units' => 0.0001]);

        // A refusal is a returned flag, never an exception (antifragile).
        $r = $this->sentinel()->assess($this->job(), 'x');

        $this->assertTrue($r['evaluated']);
        $this->assertSame(AtlasProviderCostSentinel::SCHEMA_VERSION, $r['schema_version']);
    }
}
