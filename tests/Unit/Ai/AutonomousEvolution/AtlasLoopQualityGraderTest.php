<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\AtlasLoopQualityGrader;
use PHPUnit\Framework\TestCase;

/**
 * The ≥9 quality bar (operator directive). Objective, ungameable floor over the verified signals.
 */
final class AtlasLoopQualityGraderTest extends TestCase
{
    public function test_the_proven_adep_refactor_scores_at_the_top_and_passes(): void
    {
        // The live ADEP delivery: decompose() cx 27→8, file total branches 42→24, new pinning test.
        $g = (new AtlasLoopQualityGrader())->grade([
            'behavior_preserved' => true,
            'scope_clean' => true,
            'cx_before' => 27,
            'cx_after' => 8,
            'total_branches_before' => 42,
            'total_branches_after' => 24,
            'coverage_added' => true,
        ], 9.0);

        $this->assertGreaterThanOrEqual(9.5, $g['score'], 'a real high-value extract-class scores near the top');
        $this->assertTrue($g['passes_bar']);
    }

    public function test_broken_behavior_is_a_hard_zero(): void
    {
        $g = (new AtlasLoopQualityGrader())->grade([
            'behavior_preserved' => false,
            'scope_clean' => true,
            'cx_before' => 27, 'cx_after' => 5,
        ], 9.0);

        $this->assertSame(0.0, $g['score']);
        $this->assertFalse($g['passes_bar']);
        $this->assertContains('behavior_not_preserved_or_tests_red', $g['reasons']);
    }

    public function test_out_of_scope_is_capped_low(): void
    {
        $g = (new AtlasLoopQualityGrader())->grade([
            'behavior_preserved' => true,
            'scope_clean' => false,
            'cx_before' => 27, 'cx_after' => 5,
        ], 9.0);

        $this->assertSame(2.0, $g['score']);
        $this->assertFalse($g['passes_bar']);
    }

    public function test_marginal_complexity_drop_falls_below_the_bar(): void
    {
        // cx 27→25 (~7% drop): green + in scope but not high-value enough — correctly rejected.
        $g = (new AtlasLoopQualityGrader())->grade([
            'behavior_preserved' => true,
            'scope_clean' => true,
            'cx_before' => 27,
            'cx_after' => 25,
            'total_branches_before' => 42,
            'total_branches_after' => 42,
            'coverage_added' => false,
        ], 9.0);

        $this->assertLessThan(9.0, $g['score'], 'a marginal refactor does NOT clear the >=9 bar');
        $this->assertFalse($g['passes_bar']);
    }

    public function test_inflating_net_branches_is_penalized(): void
    {
        // Drops the worst method but ADDS net branches elsewhere (gaming) — penalized below the bar.
        $g = (new AtlasLoopQualityGrader())->grade([
            'behavior_preserved' => true,
            'scope_clean' => true,
            'cx_before' => 27,
            'cx_after' => 8,
            'total_branches_before' => 42,
            'total_branches_after' => 60,
            'coverage_added' => true,
        ], 9.0);

        $this->assertFalse($g['passes_bar'], 'adding net branches must not pass the bar');
        $this->assertContains('net_branches_increased', $g['reasons']);
    }
}
