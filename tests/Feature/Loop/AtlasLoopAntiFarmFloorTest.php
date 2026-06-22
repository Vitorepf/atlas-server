<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\AtlasLoopAntiFarmFloor;
use Tests\TestCase;

/**
 * §3 · ANTI-FARM FLOOR — comprehension work merges ONLY when its diff BITES (reverting/neutralizing it makes
 * a frozen check go RED) AND, if it is behavior-ADDING (wired), it is wired into a REAL PRODUCTION caller.
 * The two farms this kills: a COSMETIC flip (bites nothing) and a TEST-ONLY wiring (a `new X()` in a test
 * that farms wiredEarned). Pure + deterministic over the cert evidence.
 */
final class AtlasLoopAntiFarmFloorTest extends TestCase
{
    private AtlasLoopAntiFarmFloor $floor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->floor = new AtlasLoopAntiFarmFloor;
    }

    public function test_a_genuine_orphan_wiring_into_a_production_caller_is_eligible(): void
    {
        $r = $this->floor->eligibleToMerge(['wired_proof' => true, 'method_kills' => true, 'production_caller' => true]);
        $this->assertTrue($r['eligible']);
        $this->assertTrue($r['bites']);
        $this->assertTrue($r['production_path_proven']);
        $this->assertSame([], $r['reasons']);
    }

    public function test_a_cosmetic_flip_that_bites_nothing_is_refused(): void
    {
        $r = $this->floor->eligibleToMerge(['wired_proof' => false, 'diff_earned' => false]);
        $this->assertFalse($r['eligible']);
        $this->assertFalse($r['bites']);
        $this->assertContains('not_load_bearing:no_bite_proof', $r['reasons']);
    }

    public function test_a_wiring_into_a_test_only_caller_is_refused_as_farmed(): void
    {
        // method_kills passes (the orphan is referenced + neutralization reds) but the caller is NOT a
        // production path — exactly the wiredEarned farm. The production floor refuses it.
        $r = $this->floor->eligibleToMerge(['wired_proof' => true, 'method_kills' => true, 'production_caller' => false]);
        $this->assertFalse($r['eligible']);
        $this->assertTrue($r['bites'], 'it does bite…');
        $this->assertFalse($r['production_path_proven'], '…but it is not on a production path');
        $this->assertContains('wired_into_non_production_path', $r['reasons']);
    }

    public function test_a_structural_dedup_is_exempt_from_the_production_caller_floor(): void
    {
        // dedup adds no caller; its realness IS the count/complexity drop under behavior preservation.
        $r = $this->floor->eligibleToMerge(['wired_proof' => false, 'dedup_proof' => true, 'complexity_dropped' => true]);
        $this->assertTrue($r['eligible']);
        $this->assertTrue($r['bites']);
    }

    public function test_a_bug_fix_that_was_red_before_and_load_bearing_is_eligible(): void
    {
        $r = $this->floor->eligibleToMerge(['red_required' => true, 'revert_recheck' => true, 'diff_earned' => true]);
        $this->assertTrue($r['eligible']);
        $this->assertTrue($r['bites']);
    }
}
