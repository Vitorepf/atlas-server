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
        $g = (new AtlasLoopQualityGrader)->grade([
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
        $g = (new AtlasLoopQualityGrader)->grade([
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
        $g = (new AtlasLoopQualityGrader)->grade([
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
        $g = (new AtlasLoopQualityGrader)->grade([
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

    public function test_surgical_branch_drop_sequence_step_passes_the_bar(): void
    {
        // Live loop case: a small in-method guard simplification lowered both max and total branches.
        $g = (new AtlasLoopQualityGrader)->grade([
            'behavior_preserved' => true,
            'scope_clean' => true,
            'cx_before' => 18,
            'cx_after' => 16,
            'total_branches_before' => 28,
            'total_branches_after' => 26,
            'coverage_added' => false,
        ], 9.0);

        $this->assertGreaterThanOrEqual(9.0, $g['score']);
        $this->assertTrue($g['passes_bar']);
        $this->assertSame(2, $g['dimensions']['branch_drop']);
    }

    public function test_inflating_net_branches_is_penalized(): void
    {
        // Drops the worst method but ADDS net branches elsewhere (gaming) — penalized below the bar.
        $g = (new AtlasLoopQualityGrader)->grade([
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

    // ITEM10 — FEATURE LANE (non-refactor) quality grade. Same shape contract, signals that fit a
    // feature/bugfix (no complexity drop): diff_earned + mutation kill strength + coverage.

    public function test_feature_grade_full_evidence_passes_bar(): void
    {
        // 6 (base) + 1.5 (diff_earned) + 2.0 (kill 1.0) + 0.5 (coverage) = 10.0 >= 9.
        $g = (new AtlasLoopQualityGrader)->gradeFeature([
            'behavior_preserved' => true,
            'scope_clean' => true,
            'diff_earned' => true,
            'mutation_kill_ratio' => 1.0,
            'adversarial_refuted_count' => 0,
            'coverage_added' => true,
        ], 9.0);

        $this->assertGreaterThanOrEqual(9.5, $g['score'], 'a fully-evidenced feature scores near the top');
        $this->assertTrue($g['passes_bar']);
    }

    public function test_feature_grade_thin_feature_below_bar(): void
    {
        // 6 (base) + 1.5 (diff_earned) + 0 (no kill) + 0 (no coverage) = 7.5 < 9 — correctly rejected.
        $g = (new AtlasLoopQualityGrader)->gradeFeature([
            'behavior_preserved' => true,
            'scope_clean' => true,
            'diff_earned' => true,
            'mutation_kill_ratio' => 0.0,
            'adversarial_refuted_count' => 0,
            'coverage_added' => false,
        ], 9.0);

        $this->assertSame(7.5, $g['score']);
        $this->assertLessThan(9.0, $g['score']);
        $this->assertFalse($g['passes_bar']);
        $this->assertContains('no_mutation_kill_signal', $g['reasons']);
    }

    public function test_feature_grade_broken_behavior_hard_zero(): void
    {
        $g = (new AtlasLoopQualityGrader)->gradeFeature([
            'behavior_preserved' => false,
            'scope_clean' => true,
            'diff_earned' => true,
            'mutation_kill_ratio' => 1.0,
            'adversarial_refuted_count' => 0,
            'coverage_added' => true,
        ], 9.0);

        $this->assertSame(0.0, $g['score']);
        $this->assertFalse($g['passes_bar']);
        $this->assertContains('behavior_not_preserved_or_tests_red', $g['reasons']);
    }

    public function test_feature_grade_refuted_caps_low(): void
    {
        // Otherwise fully green, but ONE adversarial refutation caps the score at 2.0.
        $g = (new AtlasLoopQualityGrader)->gradeFeature([
            'behavior_preserved' => true,
            'scope_clean' => true,
            'diff_earned' => true,
            'mutation_kill_ratio' => 1.0,
            'adversarial_refuted_count' => 1,
            'coverage_added' => true,
        ], 9.0);

        $this->assertSame(2.0, $g['score']);
        $this->assertFalse($g['passes_bar']);
        $this->assertContains('adversarial_refuted', $g['reasons']);
    }

    public function test_feature_grade_out_of_scope_capped(): void
    {
        $g = (new AtlasLoopQualityGrader)->gradeFeature([
            'behavior_preserved' => true,
            'scope_clean' => false,
            'diff_earned' => true,
            'mutation_kill_ratio' => 1.0,
            'adversarial_refuted_count' => 0,
            'coverage_added' => true,
        ], 9.0);

        $this->assertSame(2.0, $g['score']);
        $this->assertFalse($g['passes_bar']);
        $this->assertContains('out_of_scope_or_frozen_tampered', $g['reasons']);
    }
}
