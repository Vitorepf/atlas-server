<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainAdaptiveBatchSizeGovernor;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainAdaptiveBatchSizeGovernorTest extends TestCase
{
    private function governor(): AtlasExternalBrainAdaptiveBatchSizeGovernor
    {
        return new AtlasExternalBrainAdaptiveBatchSizeGovernor;
    }

    private function input(array $overrides = []): array
    {
        return array_merge([
            'queue_depth' => 10,
            'servable_now' => 2,
            'active_workers' => 2,
            'drain_rate_per_hour' => 4.0,
            'theme_saturation' => 0.1,
            'candidate_value_score' => 0.1,
            'high_priority_gap_count' => 0,
        ], $overrides);
    }

    // ── AC1: batch increases on high worker drain + falling servable depth + high proof quality ──

    public function test_high_drain_falling_depth_and_high_proof_quality_raises_batch_above_baseline(): void
    {
        $result = $this->governor()->govern($this->input([
            'active_workers' => 2,
            'drain_rate_per_hour' => 10.0,
            'servable_now' => 1,
            'servable_now_delta' => -3.0,
            'recent_seed_proof_quality' => 0.9,
        ]));

        $this->assertGreaterThan(6, $result['recommended_batch_size']);
        $this->assertContains('high_drain_high_quality_raises_batch', $result['reason_codes']);
        $this->assertTrue($result['should_enqueue']);
    }

    public function test_high_drain_without_falling_depth_does_not_trigger_raise(): void
    {
        $result = $this->governor()->govern($this->input([
            'active_workers' => 2,
            'drain_rate_per_hour' => 10.0,
            'servable_now' => 1,
            'servable_now_delta' => 0.0,
            'recent_seed_proof_quality' => 0.9,
        ]));

        $this->assertNotContains('high_drain_high_quality_raises_batch', $result['reason_codes']);
    }

    public function test_high_drain_and_falling_depth_without_proof_quality_does_not_trigger_raise(): void
    {
        $result = $this->governor()->govern($this->input([
            'active_workers' => 2,
            'drain_rate_per_hour' => 10.0,
            'servable_now' => 1,
            'servable_now_delta' => -3.0,
            'recent_seed_proof_quality' => 0.2,
        ]));

        $this->assertNotContains('high_drain_high_quality_raises_batch', $result['reason_codes']);
    }

    // ── AC2: give_back/poison rate shrinks or pauses batch even under quota pressure ──

    public function test_high_poison_rate_shrinks_batch_despite_high_priority_gap_quota_pressure(): void
    {
        $result = $this->governor()->govern($this->input([
            'high_priority_gap_count' => 8,
            'give_back_poison_rate' => 0.5,
        ]));

        $this->assertContains('poison_rate_shrinks_batch', $result['reason_codes']);
        $this->assertLessThanOrEqual(2, $result['recommended_batch_size']);
    }

    public function test_high_poison_rate_overrides_high_drain_quality_raise(): void
    {
        $result = $this->governor()->govern($this->input([
            'active_workers' => 2,
            'drain_rate_per_hour' => 10.0,
            'servable_now' => 1,
            'servable_now_delta' => -3.0,
            'recent_seed_proof_quality' => 0.9,
            'give_back_poison_rate' => 0.5,
        ]));

        $this->assertContains('poison_rate_shrinks_batch', $result['reason_codes']);
        $this->assertLessThanOrEqual(2, $result['recommended_batch_size']);
    }

    public function test_low_poison_rate_never_triggers_shrink(): void
    {
        $result = $this->governor()->govern($this->input(['give_back_poison_rate' => 0.1]));

        $this->assertNotContains('poison_rate_shrinks_batch', $result['reason_codes']);
        $this->assertSame(6, $result['recommended_batch_size']);
    }

    // ── AC3: comfortable queue depth alone never stops enqueueing when frontier yield remains ──

    public function test_sufficient_queue_depth_with_high_value_frontier_yield_never_produces_a_stop(): void
    {
        $result = $this->governor()->govern($this->input([
            'servable_now' => 10,
            'active_workers' => 2,
            'candidate_value_score' => 0.9,
        ]));

        $this->assertTrue($result['should_enqueue']);
        $this->assertGreaterThan(0, $result['recommended_batch_size']);
    }

    public function test_default_optional_facts_reproduce_prior_baseline_behavior(): void
    {
        $result = $this->governor()->govern($this->input());

        $this->assertSame(6, $result['recommended_batch_size']);
        $this->assertContains('baseline_batch', $result['reason_codes']);
    }

    public function test_batch_size_stays_within_bounds_with_new_facts_present(): void
    {
        $result = $this->governor()->govern($this->input([
            'high_priority_gap_count' => 999,
            'recent_seed_proof_quality' => 1.0,
            'servable_now_delta' => -100.0,
            'drain_rate_per_hour' => 1000.0,
        ]));

        $this->assertGreaterThanOrEqual(0, $result['recommended_batch_size']);
        $this->assertLessThanOrEqual(12, $result['recommended_batch_size']);
    }
}
