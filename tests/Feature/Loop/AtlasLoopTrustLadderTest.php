<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopTrustLadder;
use Tests\TestCase;

/**
 * TRUST LADDER — frozen proof that autonomy is EARNED and HONEST about sample size: a few lucky wins
 * never unlock autonomous merge (the Wilson lower bound penalises small samples), while a long proven
 * record does. The ladder gates the RIGHT to attempt the governed crossing; it never bypasses a gate.
 */
final class AtlasLoopTrustLadderTest extends TestCase
{
    private function ladder(): AtlasLoopTrustLadder
    {
        return new AtlasLoopTrustLadder();
    }

    public function test_no_evidence_is_park_only(): void
    {
        $v = $this->ladder()->assess(['successes' => 0, 'failures' => 0]);
        $this->assertSame(AtlasLoopTrustLadder::PARK_ONLY, $v['level']);
        $this->assertFalse($v['can_auto_merge']);
        $this->assertSame(0.0, $v['wilson_lower']);
    }

    public function test_a_tiny_perfect_record_does_NOT_unlock_autonomy_small_sample_trap_closed(): void
    {
        $v = $this->ladder()->assess(['successes' => 3, 'failures' => 0]); // point estimate 1.0
        $this->assertSame(AtlasLoopTrustLadder::PARK_ONLY, $v['level'], '3/3 is not proof — Wilson lower bound is modest');
        $this->assertFalse($v['can_auto_merge']);
        $this->assertLessThan(0.7, $v['wilson_lower'], 'the lower bound for 3/3 is ~0.44, far below trust');
    }

    public function test_a_long_proven_record_earns_autonomous_merge(): void
    {
        $v = $this->ladder()->assess(['successes' => 50, 'failures' => 0]);
        $this->assertSame(AtlasLoopTrustLadder::AUTONOMOUS_MERGE, $v['level']);
        $this->assertTrue($v['can_auto_merge']);
        $this->assertGreaterThanOrEqual(0.9, $v['wilson_lower']);
    }

    public function test_a_good_but_imperfect_record_is_trusted_but_still_parked(): void
    {
        $v = $this->ladder()->assess(['successes' => 45, 'failures' => 5]); // 0.9 over 50 -> wilson_lower ~0.79
        $this->assertSame(AtlasLoopTrustLadder::TRUSTED_REVIEW, $v['level'], 'a strong record is trusted but not yet autonomous');
        $this->assertFalse($v['can_auto_merge'], 'autonomy requires the lower bound >= 0.9');
        $this->assertGreaterThanOrEqual(0.7, $v['wilson_lower']);
        $this->assertLessThan(0.9, $v['wilson_lower']);
    }

    public function test_a_moderate_rate_over_many_samples_is_still_park_only(): void
    {
        // 0.8 over 50 has a Wilson lower bound (~0.68) below the trust floor — honestly not yet proven.
        $v = $this->ladder()->assess(['successes' => 40, 'failures' => 10]);
        $this->assertSame(AtlasLoopTrustLadder::PARK_ONLY, $v['level']);
    }

    public function test_wilson_lower_bound_is_monotonic_in_successes(): void
    {
        $a = $this->ladder()->assess(['successes' => 10, 'failures' => 10])['wilson_lower'];
        $b = $this->ladder()->assess(['successes' => 18, 'failures' => 2])['wilson_lower'];
        $this->assertGreaterThan($a, $b, 'more successes (same n) raise the confidence floor — autonomy rises with proof');
    }
}
