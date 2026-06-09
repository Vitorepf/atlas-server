<?php

declare(strict_types=1);

namespace Tests\Unit\CodeGraph;

use App\Services\Engineering\CodeGraph\CodeGraphFirstIndexPlanner;
use Tests\TestCase;

/**
 * AP-815 · W-11 — contract for the staged, budgeted first-index planner.
 *
 * Pure (no DB): the planner is a deterministic function of (totalFiles,
 * estimatedBytes, opts). We prove the load-bearing invariants — a small repo is
 * indexed in full, a large repo is staged into prioritised tiers whose caps SUM to
 * at most the budget, and degenerate inputs (zero, negative, garbage config) yield a
 * safe plan and never throw.
 */
class CodeGraphFirstIndexPlannerTest extends TestCase
{
    private function planner(): CodeGraphFirstIndexPlanner
    {
        return new CodeGraphFirstIndexPlanner;
    }

    /**
     * Happy path (small repo): 100 files, budget 5000 → FULL, not sampled, a single
     * stage covering all 100 files.
     */
    public function test_small_repo_is_indexed_in_full_single_stage(): void
    {
        $plan = $this->planner()->plan(100, 0, ['budget_files' => 5000]);

        $this->assertSame('full', $plan['strategy']);
        $this->assertFalse($plan['sampled']);
        $this->assertSame(100, $plan['total_files']);
        $this->assertSame(5000, $plan['budget_files']);

        $this->assertCount(1, $plan['stages'], 'A full plan is a single stage.');
        $this->assertSame(1, $plan['stages'][0]['tier']);
        $this->assertSame(100, $plan['stages'][0]['max_files'], 'The single stage covers every file.');

        // A full plan never indexes more than exists, never above budget.
        $sum = array_sum(array_column($plan['stages'], 'max_files'));
        $this->assertSame(100, $sum);
        $this->assertLessThanOrEqual($plan['budget_files'], $sum);
    }

    /** Boundary: exactly at the budget is still FULL (the budget is inclusive). */
    public function test_repo_exactly_at_budget_is_full(): void
    {
        $plan = $this->planner()->plan(5000, 0, ['budget_files' => 5000]);

        $this->assertSame('full', $plan['strategy']);
        $this->assertFalse($plan['sampled']);
        $this->assertSame(5000, $plan['stages'][0]['max_files']);
    }

    /**
     * Happy path (huge repo): 200000 files → STAGED + sampled, multiple tiers, and
     * the HARD invariant Σ stage.max_files ≤ budget_files.
     */
    public function test_huge_repo_is_staged_and_sampled_within_budget(): void
    {
        $budget = 5000;
        $plan = $this->planner()->plan(200000, 0, ['budget_files' => $budget]);

        $this->assertSame('staged', $plan['strategy']);
        $this->assertTrue($plan['sampled']);
        $this->assertSame(200000, $plan['total_files']);
        $this->assertSame($budget, $plan['budget_files']);

        // Multiple prioritised tiers, in order 1,2,3.
        $this->assertGreaterThanOrEqual(2, count($plan['stages']), 'A staged plan has multiple tiers.');
        $this->assertSame([1, 2, 3], array_column($plan['stages'], 'tier'));

        // THE invariant: the staged caps never exceed the budget.
        $sum = array_sum(array_column($plan['stages'], 'max_files'));
        $this->assertLessThanOrEqual($budget, $sum, 'Sum of stage caps must never exceed budget_files.');

        // With far more files than budget, the plan spends the whole budget exactly.
        $this->assertSame($budget, $sum, 'A repo far over budget uses the full budget across tiers.');

        // Every tier cap is non-negative; tier-2 (core) carries the largest share.
        foreach ($plan['stages'] as $stage) {
            $this->assertGreaterThanOrEqual(0, $stage['max_files']);
            $this->assertNotSame('', $stage['reason'], 'Each stage carries an audit reason.');
        }
        $byTier = array_column($plan['stages'], 'max_files', 'tier');
        $this->assertGreaterThan($byTier[1], $byTier[2], 'Core tier gets more budget than entrypoints.');
    }

    /**
     * Edge case: zero files → FULL, empty plan, no throw. The single stage has a
     * max_files of 0 and nothing is sampled.
     */
    public function test_zero_files_returns_safe_empty_full_plan(): void
    {
        $plan = $this->planner()->plan(0, 0);

        $this->assertSame('full', $plan['strategy']);
        $this->assertFalse($plan['sampled']);
        $this->assertSame(0, $plan['total_files']);
        $this->assertCount(1, $plan['stages']);
        $this->assertSame(0, $plan['stages'][0]['max_files'], 'Zero-file repo plans an empty stage.');
        $this->assertSame(0, array_sum(array_column($plan['stages'], 'max_files')));
    }

    /**
     * Edge case: negative / garbage inputs are clamped fail-safe and never throw.
     * Negative file/byte counts clamp to 0 (→ full empty plan); a garbage budget
     * falls back to the configured default; a zero batch is floored to a usable 1.
     */
    public function test_negative_and_garbage_inputs_are_clamped_failsafe(): void
    {
        $plan = $this->planner()->plan(-500, -10, [
            'budget_files' => 'not-a-number',
            'batch_size' => 0,
        ]);

        // Negative totals clamp to 0 → a valid full empty plan.
        $this->assertSame('full', $plan['strategy']);
        $this->assertSame(0, $plan['total_files']);
        $this->assertSame(0, $plan['stages'][0]['max_files']);

        // Garbage budget → configured default (5000), never below the floor.
        $this->assertGreaterThanOrEqual(1, $plan['budget_files']);
        $this->assertSame(5000, $plan['budget_files']);

        // Zero batch is floored to at least 1 so the indexer can never stall.
        $this->assertGreaterThanOrEqual(1, $plan['batch_size']);
        $this->assertSame(1, $plan['batch_size']);
    }

    /**
     * Refinement: a BYTE-HEAVY repo whose file count fits the budget is still staged,
     * because the parse cost is bytes, not just files. 1000 files (< 5000 budget) but
     * ~1 GiB of bytes (avg ~1 MiB/file, far over the per-file ceiling) → staged, and
     * the planned budget shrinks to the byte-derived limit.
     */
    public function test_byte_heavy_repo_under_file_budget_is_staged(): void
    {
        $oneGiB = 1024 * 1024 * 1024;
        $plan = $this->planner()->plan(1000, $oneGiB, ['budget_files' => 5000]);

        $this->assertSame('staged', $plan['strategy'], 'Byte-heavy repo is staged even under the file budget.');
        $this->assertTrue($plan['sampled']);

        // The staged caps still respect the file budget ceiling AND never exceed the
        // file count being indexed.
        $sum = array_sum(array_column($plan['stages'], 'max_files'));
        $this->assertLessThanOrEqual(5000, $sum);
        $this->assertLessThanOrEqual(1000, $sum, 'Never plan to index more files than exist.');
    }

    /**
     * A modestly-byte repo (normal source files) is NOT penalised by the byte guard:
     * 1000 files at ~10 KiB each is well under both budgets → FULL.
     */
    public function test_normal_byte_repo_is_not_penalised_and_stays_full(): void
    {
        $tenKiBEach = 1000 * 10 * 1024; // ~10 MiB total, ~10 KiB/file.
        $plan = $this->planner()->plan(1000, $tenKiBEach, ['budget_files' => 5000]);

        $this->assertSame('full', $plan['strategy'], 'Ordinary source files are never staged by the byte guard.');
        $this->assertFalse($plan['sampled']);
        $this->assertSame(1000, $plan['stages'][0]['max_files']);
    }

    /** Determinism: identical inputs yield byte-identical plans. */
    public function test_plan_is_deterministic(): void
    {
        $a = $this->planner()->plan(200000, 5_000_000, ['budget_files' => 4321, 'batch_size' => 250]);
        $b = $this->planner()->plan(200000, 5_000_000, ['budget_files' => 4321, 'batch_size' => 250]);

        $this->assertSame($a, $b, 'Same inputs must produce byte-identical output.');
    }

    /**
     * Opts override config: a per-call budget/batch wins over the config defaults,
     * and the override budget drives the full/staged decision.
     */
    public function test_opts_override_budget_and_batch(): void
    {
        // Budget of 50 makes a 100-file repo cross into staged.
        $plan = $this->planner()->plan(100, 0, ['budget_files' => 50, 'batch_size' => 7]);

        $this->assertSame('staged', $plan['strategy']);
        $this->assertSame(50, $plan['budget_files']);
        $this->assertSame(7, $plan['batch_size']);

        $sum = array_sum(array_column($plan['stages'], 'max_files'));
        $this->assertLessThanOrEqual(50, $sum);
    }
}
