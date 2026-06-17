<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\AtlasLoopLeverImpactMeter as M;
use PHPUnit\Framework\TestCase;

/**
 * ROADMAP #4 — the lever-impact + saturation measurement instrument (pure).
 */
final class AtlasLoopLeverImpactMeterTest extends TestCase
{
    public function test_rate_is_safe(): void
    {
        $this->assertSame(0.5, M::rate(1, 2));
        $this->assertSame(0.0, M::rate(5, 0));   // zero denominator
        $this->assertSame(1.0, M::rate(9, 3));   // clamped to 1
    }

    public function test_impact_flags_improvement(): void
    {
        $before = ['generated' => 100, 'admitted' => 80, 'attempted' => 80, 'certified' => 16]; // 20% conv
        $after = ['generated' => 100, 'admitted' => 78, 'attempted' => 78, 'certified' => 31];  // ~40% conv
        $r = M::impact($before, $after);
        $this->assertSame('improved', $r['verdict']);
        $this->assertGreaterThan(0, $r['conversion_delta']);
        $this->assertFalse($r['starvation_risk']);
    }

    public function test_impact_flags_starvation_even_if_survivors_convert_better(): void
    {
        // admission collapses (80% -> 20%) but the few survivors convert great -> STARVED, not "improved".
        $before = ['generated' => 100, 'admitted' => 80, 'attempted' => 80, 'certified' => 16];
        $after = ['generated' => 100, 'admitted' => 20, 'attempted' => 20, 'certified' => 12]; // 60% conv but starved
        $r = M::impact($before, $after);
        $this->assertTrue($r['starvation_risk']);
        $this->assertSame('starved', $r['verdict']);
    }

    public function test_impact_flat_and_regressed(): void
    {
        $base = ['generated' => 100, 'admitted' => 80, 'attempted' => 80, 'certified' => 20];
        $this->assertSame('flat', M::impact($base, $base)['verdict']);

        $worse = ['generated' => 100, 'admitted' => 80, 'attempted' => 80, 'certified' => 8];
        $this->assertSame('regressed', M::impact($base, $worse)['verdict']);
    }

    public function test_saturation_real_headroom_when_both_climb(): void
    {
        $r = M::saturation([0.50, 0.55, 0.62, 0.70], [0.40, 0.45, 0.52, 0.60]);
        $this->assertSame('real_headroom', $r['verdict']);
        $this->assertTrue($r['in_dist_climbing']);
        $this->assertTrue($r['transfer_climbing']);
    }

    public function test_saturation_goodhart_ceiling_when_transfer_flat(): void
    {
        // in-distribution climbs hard, transfer is flat => overfitting the proxy.
        $r = M::saturation([0.50, 0.62, 0.75, 0.88], [0.40, 0.40, 0.41, 0.40]);
        $this->assertSame('goodhart_ceiling', $r['verdict']);
    }

    public function test_saturation_shallow_when_in_dist_plateaus(): void
    {
        $r = M::saturation([0.70, 0.70, 0.71, 0.70], [0.60, 0.60, 0.60, 0.61]);
        $this->assertSame('shallow', $r['verdict']);
    }

    public function test_trend_is_net_per_step(): void
    {
        $this->assertEqualsWithDelta(0.1, M::trend([0.5, 0.6, 0.7]), 1e-9);
        $this->assertSame(0.0, M::trend([0.5]));
    }
}
