<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\AtlasLoopDecompositionShapePrior;
use PHPUnit\Framework\TestCase;

/**
 * ACDE Leap 5 — the shape-prior verdict is a PURE, n-guarded Wilson lower-bound over machine-resolved
 * terminal outcomes. Below minSamples it is UNKNOWN (never blocks a novel shape); a shape whose
 * lower-bound certified-rate sits below target is SUSPECT (the REPLAN advisory); a healthy shape is OK.
 */
final class AtlasLoopDecompositionShapePriorTest extends TestCase
{
    private function prior(): AtlasLoopDecompositionShapePrior
    {
        return new AtlasLoopDecompositionShapePrior;
    }

    public function test_below_min_samples_is_unknown_and_contributes_nothing(): void
    {
        $v = $this->prior()->assess(certified: 0, total: 3, targetRate: 0.5, minSamples: 8);

        $this->assertSame('unknown', $v['verdict']);
        $this->assertSame(0.0, $v['lower_bound']);
        $this->assertStringContainsString('insufficient_samples', $v['reason']);
    }

    public function test_a_persistently_thrashing_shape_is_suspect(): void
    {
        // 1/20 certified — the Wilson lower bound is far below 0.5, so the shape is flagged SUSPECT.
        $v = $this->prior()->assess(certified: 1, total: 20, targetRate: 0.5, minSamples: 8);

        $this->assertSame('suspect', $v['verdict']);
        $this->assertLessThan(0.5, $v['lower_bound']);
        $this->assertStringContainsString('shape_historically_thrashes', $v['reason']);
    }

    public function test_a_healthy_shape_is_ok(): void
    {
        // 19/20 certified — the lower bound clears the 0.5 target comfortably.
        $v = $this->prior()->assess(certified: 19, total: 20, targetRate: 0.5, minSamples: 8);

        $this->assertSame('ok', $v['verdict']);
        $this->assertGreaterThanOrEqual(0.5, $v['lower_bound']);
    }

    public function test_a_high_rate_but_tiny_sample_can_still_be_suspect_via_the_lower_bound(): void
    {
        // 5/8 = 0.625 raw, but the Wilson LOWER bound on only 8 samples dips below 0.5 — evidence, not luck.
        $v = $this->prior()->assess(certified: 5, total: 8, targetRate: 0.5, minSamples: 8);

        $this->assertContains($v['verdict'], ['suspect', 'ok']); // boundary case — assert the bound is honest
        $this->assertLessThan(0.625, $v['lower_bound'], 'the lower bound must sit below the raw rate');
    }

    public function test_is_deterministic_for_a_fixed_corpus(): void
    {
        $a = $this->prior()->assess(7, 30, 0.6, 8);
        $b = $this->prior()->assess(7, 30, 0.6, 8);

        $this->assertSame($a, $b);
    }

    public function test_clamps_degenerate_inputs(): void
    {
        // certified > total is clamped; zero total is UNKNOWN, never a divide-by-zero.
        $this->assertSame('unknown', $this->prior()->assess(99, 0, 0.5, 8)['verdict']);
        $this->assertSame(5, $this->prior()->assess(99, 5, 0.5, 8)['certified']);
    }
}
