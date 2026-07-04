<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainStructuralLeverageBudgetAllocator;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainStructuralLeverageBudgetAllocatorTest extends TestCase
{
    private AtlasExternalBrainStructuralLeverageBudgetAllocator $allocator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->allocator = new AtlasExternalBrainStructuralLeverageBudgetAllocator();
    }

    // AC 1: test runs green (implicit — if this suite passes)

    // AC 2: High give_back or poison rate → at least 40% to repair/self_heal before build
    public function test_high_give_back_rate_allocates_at_least_40_percent_to_repair(): void
    {
        $result = $this->allocator->allocate([
            'give_back_rate' => 0.6,
            'poison_rate' => 0.3,
        ]);

        $repair = $result['lane_percentages']['repair'] ?? 0;
        $this->assertGreaterThanOrEqual(40, $repair, "repair lane must be >=40% when give_back=0.6 poison=0.3; got {$repair}");
    }

    public function test_high_poison_rate_allocates_at_least_40_percent_to_repair(): void
    {
        $result = $this->allocator->allocate([
            'give_back_rate' => 0.1,
            'poison_rate' => 0.5,
        ]);

        $repair = $result['lane_percentages']['repair'] ?? 0;
        $this->assertGreaterThanOrEqual(40, $repair, "repair lane must be >=40% when poison=0.5; got {$repair}");
    }

    // AC 3: High simplification debt → non-zero simplify lane even when build demand is high
    public function test_high_simplification_debt_allocates_non_zero_simplify(): void
    {
        $result = $this->allocator->allocate([
            'simplification_debt' => 0.8,
            'build_demand' => 1.0,
        ]);

        $simplify = $result['lane_percentages']['simplify'] ?? 0;
        $this->assertGreaterThan(0, $simplify, "simplify lane must be >0 when simplification_debt=0.8; got {$simplify}");
    }

    // AC 4: Low research freshness allocates research only when candidate leverage is not proven
    public function test_research_zeroed_when_leverage_proven_and_freshness_low(): void
    {
        $result = $this->allocator->allocate([
            'research_freshness' => 0.2,
            'candidate_leverage_proven' => true,
        ]);

        $research = $result['lane_percentages']['research'] ?? 0;
        $this->assertSame(0, $research, 'research must be 0 when freshness=0.2 and leverage is proven');
        $this->assertContains('research', $result['blocked_lanes']);
    }

    public function test_research_kept_when_leverage_not_proven(): void
    {
        $result = $this->allocator->allocate([
            'research_freshness' => 0.9,
            'candidate_leverage_proven' => false,
        ]);

        $research = $result['lane_percentages']['research'] ?? 0;
        $this->assertGreaterThan(0, $research, 'research must be >0 when freshness=0.9 and leverage is not proven');
    }

    // AC 5: Output includes lane_percentages summing to 100, rationale per lane, and blocked_lanes
    public function test_lane_percentages_sum_to_100(): void
    {
        $result = $this->allocator->allocate([]);

        $sum = array_sum($result['lane_percentages']);
        $this->assertSame(100, $sum, "lane_percentages must sum to exactly 100; got {$sum}");
    }

    public function test_output_has_rationale_per_lane(): void
    {
        $result = $this->allocator->allocate([]);

        foreach (AtlasExternalBrainStructuralLeverageBudgetAllocator::LANES as $lane) {
            $this->assertArrayHasKey($lane, $result['rationale'], "rationate must have entry for lane '{$lane}'");
        }
    }

    public function test_output_has_blocked_lanes_key(): void
    {
        $result = $this->allocator->allocate([]);

        $this->assertArrayHasKey('blocked_lanes', $result);
        $this->assertIsArray($result['blocked_lanes']);
    }

    public function test_all_six_lanes_present(): void
    {
        $result = $this->allocator->allocate([]);

        foreach (AtlasExternalBrainStructuralLeverageBudgetAllocator::LANES as $lane) {
            $this->assertArrayHasKey($lane, $result['lane_percentages'], "lane_percentages must have key '{$lane}'");
        }
    }

    // AC3: recommended_next_batch_shape always present.
    public function test_recommended_next_batch_shape_present(): void
    {
        $result = $this->allocator->allocate([]);

        $this->assertArrayHasKey('recommended_next_batch_shape', $result);
        $this->assertNotEmpty($result['recommended_next_batch_shape']);
    }

    // AC2: high simplification debt and stale proof freshness → non-zero simplify and proof (verification) lanes.
    public function test_high_simplification_debt_and_stale_proof_freshness_allocate_non_zero_simplify_and_verification(): void
    {
        $result = $this->allocator->allocate([
            'simplification_debt' => 0.8,
            'proof_freshness' => 0.1,
        ]);

        $this->assertGreaterThan(0, $result['lane_percentages']['simplify']);
        $this->assertGreaterThan(0, $result['lane_percentages']['verification']);
        $this->assertStringContainsString('proof_freshness', $result['rationale']['verification']);
    }

    // ── AC: budgets simplification, proof, autonomy, unblock, learning, additive capability ──

    public function test_autonomy_and_unblock_lanes_present(): void
    {
        $result = $this->allocator->allocate([]);

        $this->assertArrayHasKey('autonomy', $result['lane_percentages']);
        $this->assertArrayHasKey('unblock', $result['lane_percentages']);
    }

    public function test_high_autonomy_debt_allocates_non_zero_autonomy(): void
    {
        $result = $this->allocator->allocate(['autonomy_debt' => 0.9]);

        $this->assertGreaterThan(0, $result['lane_percentages']['autonomy']);
        $this->assertStringContainsString('autonomy_debt', $result['rationale']['autonomy']);
    }

    public function test_high_unblock_debt_allocates_non_zero_unblock(): void
    {
        $result = $this->allocator->allocate(['unblock_debt' => 0.9]);

        $this->assertGreaterThan(0, $result['lane_percentages']['unblock']);
        $this->assertStringContainsString('unblock_debt', $result['rationale']['unblock']);
    }

    public function test_capability_lane_percentages_maps_canonical_names(): void
    {
        $result = $this->allocator->allocate([]);

        foreach (['simplification', 'proof', 'autonomy', 'unblock', 'learning', 'additive_capability'] as $key) {
            $this->assertArrayHasKey($key, $result['capability_lane_percentages']);
        }
        $this->assertSame(100, array_sum($result['capability_lane_percentages']));
    }

    // ── AC: caps additive work when proof or simplification debt is high ──────

    public function test_additive_build_capped_when_simplification_debt_high(): void
    {
        $result = $this->allocator->allocate(['simplification_debt' => 0.35]);

        $this->assertArrayHasKey('build', $result['rationale']);
        $this->assertStringContainsString('capped', $result['rationale']['build']);
    }

    public function test_additive_build_not_capped_when_debt_low(): void
    {
        $result = $this->allocator->allocate([]);

        $this->assertStringNotContainsString('capped', $result['rationale']['build']);
    }

    // ── AC: returns next lane recommendation ───────────────────────────────────

    public function test_next_lane_recommendation_present(): void
    {
        $result = $this->allocator->allocate([]);

        $this->assertArrayHasKey('next_lane_recommendation', $result);
        $this->assertContains($result['next_lane_recommendation'], AtlasExternalBrainStructuralLeverageBudgetAllocator::LANES);
    }

    public function test_next_lane_recommendation_matches_dominant_lane(): void
    {
        $result = $this->allocator->allocate(['give_back_rate' => 0.9]);

        $this->assertSame('repair', $result['next_lane_recommendation']);
    }

    // ── AC2: budget_shares maps to the five portfolio categories ──────────────

    public function test_budget_shares_includes_five_portfolio_categories(): void
    {
        $result = $this->allocator->allocate([]);

        $this->assertArrayHasKey('budget_shares', $result);
        $shares = $result['budget_shares'];
        $this->assertArrayHasKey('proof', $shares);
        $this->assertArrayHasKey('simplification', $shares);
        $this->assertArrayHasKey('task_fabric', $shares);
        $this->assertArrayHasKey('model_amplifier', $shares);
        $this->assertArrayHasKey('autonomy_runtime', $shares);

        // Budget shares should sum to ~100 (same as lane_percentages).
        $this->assertEqualsWithDelta(100, array_sum($shares), 2);
    }

    // ── AC3: no single category consumes the whole wave ──────────────────────

    public function test_no_single_category_consumes_whole_wave(): void
    {
        // With no evidence of distress, no single lane should capture >60%.
        $result = $this->allocator->allocate([]);

        foreach ($result['lane_percentages'] as $lane => $pct) {
            $this->assertLessThanOrEqual(60, $pct, "Lane '{$lane}' must not exceed 60% in baseline allocation");
        }
    }

    // ── AC4: output includes evidence_refs and rebalance_reason ──────────────

    public function test_output_includes_evidence_refs_and_rebalance_reason(): void
    {
        $result = $this->allocator->allocate([]);

        $this->assertArrayHasKey('evidence_refs', $result);
        $this->assertIsArray($result['evidence_refs']);
        $this->assertArrayHasKey('rebalance_reason', $result);
        $this->assertIsString($result['rebalance_reason']);
    }

    public function test_evidence_refs_filled_when_shifts_occur(): void
    {
        $result = $this->allocator->allocate([
            'give_back_rate' => 0.5,
        ]);

        $this->assertNotEmpty($result['evidence_refs']);
        $this->assertContains('give_back_rate', $result['evidence_refs']);
        $this->assertStringStartsWith('budget_rebalanced_from_baseline', $result['rebalance_reason']);
    }

    public function test_rebalance_reason_baseline_when_no_shifts(): void
    {
        $result = $this->allocator->allocate([]);

        $this->assertSame('baseline_no_rebalance_needed', $result['rebalance_reason']);
    }
}
