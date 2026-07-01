<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainEnqueueValueThrottle;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainEnqueueValueThrottleTest extends TestCase
{
    private AtlasExternalBrainEnqueueValueThrottle $throttle;

    protected function setUp(): void
    {
        $this->throttle = new AtlasExternalBrainEnqueueValueThrottle;
    }

    private function input(array $overrides = []): array
    {
        return array_merge([
            'batch_value_score' => 0.8,
            'recommended_batch_size' => 6,
            'novelty_score' => 0.8,
            'impact_diversity_score' => 0.8,
            'worker_drain_confidence' => 0.8,
            'queue_depth' => 5,
            'roadmap_coverage_score' => 0.8,
            'candidate_task_ids' => ['t1', 't2', 't3', 't4', 't5', 't6'],
        ], $overrides);
    }

    // ── AC2: padding blocks enqueue ──────────────────────────────────────────

    public function test_high_padding_ratio_blocks_as_padding_detected(): void
    {
        $result = $this->throttle->throttle($this->input(['padding_ratio' => 0.5]));

        $this->assertFalse($result['allow_enqueue']);
        $this->assertContains('padding_detected', $result['blockers']);
        $this->assertSame('remove_padding_and_resubmit_only_substantive_tasks', $result['pivot_recommendation']);
    }

    public function test_low_padding_ratio_does_not_block(): void
    {
        $result = $this->throttle->throttle($this->input(['padding_ratio' => 0.1]));

        $this->assertTrue($result['allow_enqueue']);
        $this->assertNotContains('padding_detected', $result['blockers']);
    }

    public function test_padding_ratio_defaults_to_zero_and_does_not_block(): void
    {
        $result = $this->throttle->throttle($this->input());

        $this->assertTrue($result['allow_enqueue']);
    }

    // ── AC3: deep queue is throttled unless the batch is high-leverage ───────

    public function test_deep_queue_with_low_leverage_blocks(): void
    {
        $result = $this->throttle->throttle($this->input([
            'queue_depth' => 50,
            'structural_leverage_score' => 0.2,
            'proof_demand_score' => 0.2,
        ]));

        $this->assertFalse($result['allow_enqueue']);
        $this->assertContains('queue_saturated_low_leverage', $result['blockers']);
    }

    public function test_deep_queue_with_high_leverage_allows_enqueue(): void
    {
        $result = $this->throttle->throttle($this->input([
            'queue_depth' => 50,
            'novelty_score' => 0.9,
            'structural_leverage_score' => 0.9,
            'proof_demand_score' => 0.9,
        ]));

        $this->assertTrue($result['allow_enqueue']);
        $this->assertSame([], $result['blockers']);
    }

    public function test_shallow_queue_never_triggers_saturation_blocker_even_with_low_leverage(): void
    {
        $result = $this->throttle->throttle($this->input([
            'queue_depth' => 5,
            'structural_leverage_score' => 0.0,
            'proof_demand_score' => 0.0,
        ]));

        $this->assertNotContains('queue_saturated_low_leverage', $result['blockers']);
    }

    public function test_deep_queue_partial_leverage_still_blocks(): void
    {
        // novelty and proof_demand high but structural_leverage low — not "high leverage" on ALL three.
        $result = $this->throttle->throttle($this->input([
            'queue_depth' => 50,
            'novelty_score' => 0.9,
            'structural_leverage_score' => 0.2,
            'proof_demand_score' => 0.9,
        ]));

        $this->assertContains('queue_saturated_low_leverage', $result['blockers']);
    }

    // ── AC4: decision, value score, repetition risk, minimum improvement needed ──

    public function test_output_includes_value_score(): void
    {
        $result = $this->throttle->throttle($this->input(['batch_value_score' => 0.65]));

        $this->assertSame(0.65, $result['value_score']);
    }

    public function test_output_includes_repetition_risk(): void
    {
        $result = $this->throttle->throttle($this->input(['novelty_score' => 0.2, 'impact_diversity_score' => 0.4]));

        // repetition_risk = ((1-0.2)+(1-0.4))/2 = (0.8+0.6)/2 = 0.7
        $this->assertEqualsWithDelta(0.7, $result['repetition_risk'], 0.0001);
    }

    public function test_healthy_batch_has_zero_repetition_risk_and_zero_improvement_needed(): void
    {
        $result = $this->throttle->throttle($this->input(['novelty_score' => 1.0, 'impact_diversity_score' => 1.0]));

        $this->assertSame(0.0, $result['repetition_risk']);
        $this->assertSame(0.0, $result['minimum_improvement_needed']);
    }

    public function test_minimum_improvement_needed_for_low_value_rejection(): void
    {
        $result = $this->throttle->throttle($this->input(['batch_value_score' => 0.25]));

        // LOW_VALUE_THRESHOLD (0.4) - 0.25 = 0.15
        $this->assertEqualsWithDelta(0.15, $result['minimum_improvement_needed'], 0.0001);
    }

    public function test_minimum_improvement_needed_for_theme_saturated_rejection(): void
    {
        $result = $this->throttle->throttle($this->input(['novelty_score' => 0.1]));

        // SATURATION_NOVELTY_THRESHOLD (0.3) - 0.1 = 0.2
        $this->assertEqualsWithDelta(0.2, $result['minimum_improvement_needed'], 0.0001);
    }

    public function test_minimum_improvement_needed_is_zero_for_structural_only_blockers(): void
    {
        // Only batch_too_large blocks (a shape defect, not a score deficiency).
        $result = $this->throttle->throttle($this->input(['recommended_batch_size' => 20]));

        $this->assertContains('batch_too_large', $result['blockers']);
        $this->assertSame(0.0, $result['minimum_improvement_needed']);
    }

    public function test_allowed_batch_reports_zero_improvement_needed(): void
    {
        $result = $this->throttle->throttle($this->input());

        $this->assertTrue($result['allow_enqueue']);
        $this->assertSame(0.0, $result['minimum_improvement_needed']);
    }
}
