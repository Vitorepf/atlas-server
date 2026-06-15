<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\AtlasLoopBehavioralEquivalenceGate;
use PHPUnit\Framework\TestCase;

/**
 * Lever 3 — the behavioral-equivalence strength gate. Pure + fail-open: OFF or no mutants never gates;
 * a suite whose kill ratio is below the floor is refused with a precise reason.
 */
final class AtlasLoopBehavioralEquivalenceGateTest extends TestCase
{
    public function test_off_when_floor_zero(): void
    {
        $r = (new AtlasLoopBehavioralEquivalenceGate)->evaluate(10, 1, 0.0);
        $this->assertTrue($r['passes']);
        $this->assertNull($r['reason']);
        $this->assertSame(0.1, $r['kill_ratio']);
    }

    public function test_fail_open_when_no_mutants_sampled(): void
    {
        // A floor set, but the gate sampled nothing => never penalise (no false-reject when signal absent).
        $r = (new AtlasLoopBehavioralEquivalenceGate)->evaluate(0, 0, 0.9);
        $this->assertTrue($r['passes']);
        $this->assertNull($r['reason']);
        $this->assertNull($r['kill_ratio']);
    }

    public function test_passes_when_ratio_meets_floor(): void
    {
        $r = (new AtlasLoopBehavioralEquivalenceGate)->evaluate(10, 9, 0.9);
        $this->assertTrue($r['passes'], 'ratio 0.9 meets floor 0.9');
        $this->assertSame(0.9, $r['kill_ratio']);
    }

    public function test_refuses_when_ratio_below_floor(): void
    {
        $r = (new AtlasLoopBehavioralEquivalenceGate)->evaluate(10, 5, 0.8);
        $this->assertFalse($r['passes']);
        $this->assertSame('behavioral_equivalence:kill_ratio_below_floor:0.5<0.8', $r['reason']);
    }

    public function test_floor_clamped_and_killed_clamped(): void
    {
        // floor > 1 clamps to 1.0; killed > sampled clamps to sampled (ratio never exceeds 1).
        $r = (new AtlasLoopBehavioralEquivalenceGate)->evaluate(4, 99, 5.0);
        $this->assertSame(1.0, $r['floor']);
        $this->assertSame(1.0, $r['kill_ratio']);
        $this->assertTrue($r['passes']);
    }
}
