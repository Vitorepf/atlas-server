<?php

declare(strict_types=1);

namespace Tests\Feature\Loop\Discovery;

use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopParkEscalation;
use Tests\TestCase;

/**
 * Slice C-park-escalation — park-ledger escalation + loop-health (autonomy-theatre) metric.
 *
 * Each assertion pins EXACT values / booleans so the test goes RED if the escalation curve, the clamp,
 * or the autonomy-theatre flag is broken. No count>0 tautologies.
 */
final class AtlasLoopParkEscalationTest extends TestCase
{
    private AtlasLoopParkEscalation $unit;

    protected function setUp(): void
    {
        parent::setUp();
        $this->unit = new AtlasLoopParkEscalation;
    }

    public function test_zero_reattempts_leaves_base_priority_unchanged(): void
    {
        // No re-park => no bump. Exact passthrough.
        $this->assertSame(0.40, $this->unit->escalatePriority(0.40, 0));
        $this->assertSame(0.0, $this->unit->escalatePriority(0.0, 0));
        $this->assertSame(1.0, $this->unit->escalatePriority(1.0, 0));
    }

    public function test_escalate_priority_is_strictly_monotonic_in_reattempts(): void
    {
        $base = 0.30;
        $previous = $this->unit->escalatePriority($base, 0);
        $this->assertSame($base, $previous);

        // Climb 1..10 re-attempts: each step must be strictly higher than the last, until the clamp
        // ceiling saturates it (at which point it is non-decreasing and pinned at 1.0).
        for ($reattempts = 1; $reattempts <= 10; $reattempts++) {
            $current = $this->unit->escalatePriority($base, $reattempts);
            $this->assertGreaterThanOrEqual($previous, $current, "reattempt {$reattempts} must not regress");

            if ($current < 1.0) {
                $this->assertGreaterThan($previous, $current, "reattempt {$reattempts} must strictly increase below ceiling");
            }

            $previous = $current;
        }
    }

    public function test_escalate_priority_uses_exact_step_below_ceiling(): void
    {
        // base 0.30 + 3 * 0.05 step = 0.45 exactly (well below the clamp ceiling).
        $this->assertEqualsWithDelta(0.45, $this->unit->escalatePriority(0.30, 3), 1e-9);
        // base 0.50 + 2 * 0.05 = 0.60.
        $this->assertEqualsWithDelta(0.60, $this->unit->escalatePriority(0.50, 2), 1e-9);
    }

    public function test_escalate_priority_clamps_at_one(): void
    {
        // base 0.90 + 5 * 0.05 = 1.15 -> clamped to 1.0 exactly, and stays pinned for more reattempts.
        $this->assertSame(1.0, $this->unit->escalatePriority(0.90, 5));
        $this->assertSame(1.0, $this->unit->escalatePriority(0.90, 50));
        $this->assertSame(1.0, $this->unit->escalatePriority(1.0, 1));
    }

    public function test_escalate_priority_never_drops_below_zero(): void
    {
        // Degenerate / out-of-range base is clamped to the floor; negative reattempts treated as 0.
        $this->assertSame(0.0, $this->unit->escalatePriority(-0.50, 0));
        $this->assertSame(0.10, $this->unit->escalatePriority(0.10, -3));
    }

    public function test_loop_health_flags_parking_faster_than_delivering(): void
    {
        // parked(5) > delivered(1) AND parked > 0 => autonomy-theatre regression fires.
        $health = $this->unit->loopHealth(5, 1, 60);

        $this->assertFalse($health['healthy']);
        $this->assertTrue($health['parking_faster_than_delivering']);
        $this->assertEqualsWithDelta(5 / 60, $health['park_rate'], 1e-9);
        $this->assertEqualsWithDelta(1 / 60, $health['deliver_rate'], 1e-9);
        $this->assertStringContainsString('autonomy-theatre', $health['reason']);
    }

    public function test_loop_health_is_healthy_when_delivering_at_least_as_fast(): void
    {
        // delivered(5) >= parked(1) => healthy, flag down.
        $health = $this->unit->loopHealth(1, 5, 60);

        $this->assertTrue($health['healthy']);
        $this->assertFalse($health['parking_faster_than_delivering']);
        $this->assertEqualsWithDelta(1 / 60, $health['park_rate'], 1e-9);
        $this->assertEqualsWithDelta(5 / 60, $health['deliver_rate'], 1e-9);
        $this->assertStringNotContainsString('autonomy-theatre', $health['reason']);
    }

    public function test_loop_health_parking_nothing_is_never_a_regression(): void
    {
        // parked == 0 must NOT fire even when delivered is also 0 (parked > 0 guard).
        $health = $this->unit->loopHealth(0, 0, 60);

        $this->assertTrue($health['healthy']);
        $this->assertFalse($health['parking_faster_than_delivering']);
        $this->assertSame(0.0, $health['park_rate']);
        $this->assertSame(0.0, $health['deliver_rate']);
    }

    public function test_loop_health_equal_counts_are_healthy(): void
    {
        // parked == delivered (3 == 3): NOT strictly faster => healthy.
        $health = $this->unit->loopHealth(3, 3, 30);

        $this->assertTrue($health['healthy']);
        $this->assertFalse($health['parking_faster_than_delivering']);
    }

    public function test_loop_health_degenerate_window_collapses_rates_but_still_flags(): void
    {
        // Zero-second window: rates collapse to 0 (no division by zero), but the count-driven flag
        // still fires — a degenerate window must not launder away the regression.
        $health = $this->unit->loopHealth(4, 0, 0);

        $this->assertTrue($health['parking_faster_than_delivering']);
        $this->assertFalse($health['healthy']);
        $this->assertSame(0.0, $health['park_rate']);
        $this->assertSame(0.0, $health['deliver_rate']);
    }
}
