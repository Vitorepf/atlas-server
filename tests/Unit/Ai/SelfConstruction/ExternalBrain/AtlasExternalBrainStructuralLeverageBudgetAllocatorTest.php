<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainStructuralLeverageBudgetAllocator;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainStructuralLeverageBudgetAllocatorTest extends TestCase
{
    private function allocator(): AtlasExternalBrainStructuralLeverageBudgetAllocator
    {
        return new AtlasExternalBrainStructuralLeverageBudgetAllocator;
    }

    // ── AC: output shape ───────────────────────────────────────────────────────

    public function test_output_has_required_keys(): void
    {
        $r = $this->allocator()->allocate([]);

        $this->assertArrayHasKey('lane_percentages', $r);
        $this->assertArrayHasKey('rationale', $r);
        $this->assertArrayHasKey('blocked_lanes', $r);
    }

    public function test_lane_percentages_sum_to_100_for_baseline_facts(): void
    {
        $r = $this->allocator()->allocate([]);
        $this->assertSame(100, array_sum($r['lane_percentages']));
    }

    public function test_lane_percentages_sum_to_100_under_combined_pressure(): void
    {
        $r = $this->allocator()->allocate([
            'give_back_rate' => 0.6,
            'poison_rate' => 0.5,
            'simplification_debt' => 0.8,
            'research_freshness' => 0.1,
            'evidence_strength' => 0.2,
        ]);
        $this->assertSame(100, array_sum($r['lane_percentages']));
    }

    public function test_rationale_present_for_every_lane(): void
    {
        $r = $this->allocator()->allocate([]);
        foreach (AtlasExternalBrainStructuralLeverageBudgetAllocator::LANES as $lane) {
            $this->assertArrayHasKey($lane, $r['rationale'], "Missing rationale for lane: {$lane}");
            $this->assertNotEmpty($r['rationale'][$lane]);
        }
    }

    // ── AC: high give_back/poison → repair >= 40% before build ───────────────

    public function test_high_give_back_rate_allocates_at_least_40_percent_to_repair(): void
    {
        $r = $this->allocator()->allocate(['give_back_rate' => 1.0]);

        $this->assertGreaterThanOrEqual(40, $r['lane_percentages']['repair']);
    }

    public function test_high_poison_rate_allocates_at_least_40_percent_to_repair(): void
    {
        $r = $this->allocator()->allocate(['poison_rate' => 1.0]);

        $this->assertGreaterThanOrEqual(40, $r['lane_percentages']['repair']);
    }

    public function test_high_give_back_repair_allocation_exceeds_build(): void
    {
        $r = $this->allocator()->allocate(['give_back_rate' => 1.0]);

        $this->assertGreaterThan($r['lane_percentages']['build'], $r['lane_percentages']['repair']);
    }

    // ── AC: high simplification debt → non-zero simplify even with high build demand ──

    public function test_high_simplification_debt_allocates_non_zero_simplify_lane_with_high_build_demand(): void
    {
        $r = $this->allocator()->allocate([
            'simplification_debt' => 1.0,
            'build_demand' => 1.0,
        ]);

        $this->assertGreaterThan(0, $r['lane_percentages']['simplify']);
    }

    public function test_high_simplification_debt_increases_simplify_above_baseline(): void
    {
        $baseline = $this->allocator()->allocate([])['lane_percentages']['simplify'];
        $high = $this->allocator()->allocate(['simplification_debt' => 1.0])['lane_percentages']['simplify'];

        $this->assertGreaterThan($baseline, $high);
    }

    // ── AC: research allocated only when leverage is not already proven ──────

    public function test_low_research_freshness_with_proven_leverage_zeroes_research_lane(): void
    {
        $r = $this->allocator()->allocate([
            'research_freshness' => 0.1,
            'candidate_leverage_proven' => true,
        ]);

        $this->assertSame(0, $r['lane_percentages']['research']);
        $this->assertContains('research', $r['blocked_lanes']);
    }

    public function test_low_research_freshness_with_unproven_leverage_keeps_research_non_zero(): void
    {
        $r = $this->allocator()->allocate([
            'research_freshness' => 0.1,
            'candidate_leverage_proven' => false,
        ]);

        $this->assertGreaterThan(0, $r['lane_percentages']['research']);
        $this->assertNotContains('research', $r['blocked_lanes']);
    }

    public function test_proven_leverage_research_allocation_lower_than_unproven(): void
    {
        $proven = $this->allocator()->allocate(['research_freshness' => 0.1, 'candidate_leverage_proven' => true])['lane_percentages']['research'];
        $unproven = $this->allocator()->allocate(['research_freshness' => 0.1, 'candidate_leverage_proven' => false])['lane_percentages']['research'];

        $this->assertLessThan($unproven, $proven);
    }

    // ── determinism + purity ──────────────────────────────────────────────────

    public function test_allocate_is_deterministic(): void
    {
        $facts = ['give_back_rate' => 0.5, 'simplification_debt' => 0.4];
        $a = $this->allocator()->allocate($facts);
        $b = $this->allocator()->allocate($facts);

        $this->assertSame(json_encode($a), json_encode($b));
    }

    public function test_allocator_source_has_no_io_calls(): void
    {
        $src = (string) file_get_contents(__DIR__.'/../../../../../app/Services/Ai/SelfConstruction/ExternalBrain/AtlasExternalBrainStructuralLeverageBudgetAllocator.php');
        foreach (['DB::', 'Http::', 'file_put_contents', 'exec(', 'shell_exec', 'Process::'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $src, "allocator must not call {$forbidden}");
        }
    }

    public function test_all_lane_percentages_are_non_negative(): void
    {
        $r = $this->allocator()->allocate([
            'give_back_rate' => 1.0,
            'poison_rate' => 1.0,
            'simplification_debt' => 1.0,
            'evidence_strength' => 0.0,
        ]);

        foreach ($r['lane_percentages'] as $lane => $pct) {
            $this->assertGreaterThanOrEqual(0, $pct, "Lane {$lane} went negative");
        }
    }

    // ── recommended_next_batch_shape ────────────────────────────────────────

    public function test_output_has_recommended_next_batch_shape_key(): void
    {
        $r = $this->allocator()->allocate([]);
        $this->assertArrayHasKey('recommended_next_batch_shape', $r);
        $this->assertIsString($r['recommended_next_batch_shape']);
    }

    public function test_recommended_next_batch_shape_names_dominant_lane_under_high_distress(): void
    {
        $r = $this->allocator()->allocate([
            'give_back_rate' => 0.9,
            'poison_rate' => 0.9,
        ]);

        $this->assertStringContainsString('repair', $r['recommended_next_batch_shape']);
    }

    public function test_balanced_baseline_does_not_crash_shape_computation(): void
    {
        $r = $this->allocator()->allocate([]);
        $this->assertNotEmpty($r['recommended_next_batch_shape']);
    }
}
