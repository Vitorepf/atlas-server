<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainStructuralLeverageBudgetAllocator;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainStructuralLeverageBudgetAllocatorTest extends TestCase
{
    private function allocator(): AtlasExternalBrainStructuralLeverageBudgetAllocator
    {
        return new AtlasExternalBrainStructuralLeverageBudgetAllocator;
    }

    private function assertNormalizesToHundred(array $result): void
    {
        $this->assertSame(100, array_sum($result['lane_percentages']));
    }

    // ── AC2: high give_back/poison shifts budget from build into repair ───────

    public function test_high_give_back_rate_shifts_budget_from_build_into_repair(): void
    {
        $baseline = $this->allocator()->allocate([]);
        $result = $this->allocator()->allocate(['give_back_rate' => 0.8]);

        $this->assertGreaterThan($baseline['lane_percentages']['repair'], $result['lane_percentages']['repair']);
        $this->assertLessThan($baseline['lane_percentages']['build'], $result['lane_percentages']['build']);
        $this->assertArrayHasKey('repair', $result['rationale']);
        $this->assertStringContainsString('give_back_rate', $result['rationale']['repair']);
        $this->assertNormalizesToHundred($result);
    }

    public function test_high_poison_rate_shifts_budget_from_build_into_repair(): void
    {
        $baseline = $this->allocator()->allocate([]);
        $result = $this->allocator()->allocate(['poison_rate' => 0.9]);

        $this->assertGreaterThan($baseline['lane_percentages']['repair'], $result['lane_percentages']['repair']);
        $this->assertArrayHasKey('repair', $result['rationale']);
        $this->assertNormalizesToHundred($result);
    }

    // ── AC3: high simplification_debt gives simplify meaningful allocation ───

    public function test_high_simplification_debt_gives_simplify_nonzero_allocation_and_dominant_shape(): void
    {
        $result = $this->allocator()->allocate(['simplification_debt' => 1.0]);

        $this->assertGreaterThan(10, $result['lane_percentages']['simplify']);
        $this->assertStringContainsString('simplification_debt', $result['rationale']['simplify']);
        $this->assertStringContainsString('simplify', $result['recommended_next_batch_shape']);
        $this->assertNormalizesToHundred($result);
    }

    // ── AC4: weak evidence_strength increases verification; always normalizes to 100 ──

    public function test_weak_evidence_strength_increases_verification_allocation(): void
    {
        $baseline = $this->allocator()->allocate([]);
        $result = $this->allocator()->allocate(['evidence_strength' => 0.1]);

        $this->assertGreaterThan($baseline['lane_percentages']['verification'], $result['lane_percentages']['verification']);
        $this->assertArrayHasKey('verification', $result['rationale']);
        $this->assertStringContainsString('evidence_strength', $result['rationale']['verification']);
        $this->assertNormalizesToHundred($result);
    }

    public function test_every_output_normalizes_lane_percentages_to_exactly_100(): void
    {
        $scenarios = [
            [],
            ['give_back_rate' => 0.9, 'poison_rate' => 0.9, 'simplification_debt' => 1.0, 'evidence_strength' => 0.05],
            ['research_freshness' => 0.1, 'candidate_leverage_proven' => true],
            ['research_freshness' => 0.4],
        ];

        foreach ($scenarios as $facts) {
            $result = $this->allocator()->allocate($facts);
            $this->assertNormalizesToHundred($result);
            foreach ($result['lane_percentages'] as $pct) {
                $this->assertGreaterThanOrEqual(0, $pct);
            }
        }
    }
}
