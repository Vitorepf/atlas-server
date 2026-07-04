<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainQueueSaturationStopPolicy;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainQueueSaturationStopPolicyTest extends TestCase
{
    private AtlasExternalBrainQueueSaturationStopPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = new AtlasExternalBrainQueueSaturationStopPolicy();
    }

    // AC 2: sufficient queue depth → continue_with_higher_selectivity, NOT stop
    public function test_saturated_queue_continues_with_higher_selectivity(): void
    {
        $result = $this->policy->evaluate([
            'claimable_depth' => 200,
            'saturation_threshold' => 150,
            'high_value_frontier_count' => 5,
            'research_path_available' => true,
        ]);

        $this->assertSame('continue_with_higher_selectivity', $result['action']);
    }

    // AC 3: true stop only when no high-value frontier, no research, no simplification
    public function test_stop_only_when_all_paths_exhausted(): void
    {
        $result = $this->policy->evaluate([
            'claimable_depth' => 300,
            'saturation_threshold' => 150,
            'high_value_frontier_count' => 0,
            'research_path_available' => false,
            'simplification_path_available' => false,
        ]);

        $this->assertSame('stop', $result['action']);
    }

    public function test_does_not_stop_when_simplification_path_remains(): void
    {
        $result = $this->policy->evaluate([
            'claimable_depth' => 300,
            'high_value_frontier_count' => 0,
            'research_path_available' => false,
            'simplification_path_available' => true,
        ]);

        $this->assertNotSame('stop', $result['action']);
    }

    // AC 4: saturation with weak task quality → consolidation_refill
    public function test_saturation_with_quality_erosion_recommends_consolidation(): void
    {
        $result = $this->policy->evaluate([
            'claimable_depth' => 200,
            'saturation_threshold' => 150,
            'high_value_frontier_count' => 5,
            'task_quality_erosion' => 0.4,
        ]);

        $this->assertSame('consolidation_refill', $result['action']);
    }

    public function test_not_saturated_continues_normally(): void
    {
        $result = $this->policy->evaluate([
            'claimable_depth' => 50,
            'saturation_threshold' => 150,
            'high_value_frontier_count' => 10,
        ]);

        $this->assertSame('continue_with_higher_selectivity', $result['action']);
    }

    public function test_stop_with_research_path_available_is_not_stop(): void
    {
        $result = $this->policy->evaluate([
            'claimable_depth' => 300,
            'high_value_frontier_count' => 0,
            'research_path_available' => true,
            'simplification_path_available' => false,
        ]);

        $this->assertNotSame('stop', $result['action']);
    }

    // ── create_high_value_batch ──

    public function test_create_high_value_batch_when_fresh_candidates_exist(): void
    {
        $result = $this->policy->evaluate([
            'claimable_depth' => 300,
            'high_value_frontier_count' => 5,
            'fresh_non_duplicate_candidates' => 3,
        ]);

        $this->assertSame('create_high_value_batch', $result['action']);
    }

    public function test_create_high_value_batch_reasons_include_queue_depth_never_blocks(): void
    {
        $result = $this->policy->evaluate([
            'claimable_depth' => 500,
            'fresh_non_duplicate_candidates' => 2,
        ]);

        $this->assertSame('create_high_value_batch', $result['action']);
        $this->assertContains('queue_depth_never_blocks_valuable_origination', $result['reasons']);
    }

    // ── wait only for duplicate/malformed/low-value/exhausted ──

    public function test_wait_when_candidates_exhausted(): void
    {
        $result = $this->policy->evaluate([
            'claimable_depth' => 10,
            'high_value_frontier_count' => 0,
            'research_path_available' => false,
            'simplification_path_available' => false,
            'candidates_exhausted' => true,
        ]);

        // All paths exhausted → stop (not wait)
        $this->assertSame('stop', $result['action']);
    }

    public function test_wait_when_only_duplicate_candidates(): void
    {
        $result = $this->policy->evaluate([
            'claimable_depth' => 10,
            'high_value_frontier_count' => 1,
            'research_path_available' => false,
            'simplification_path_available' => false,
            'fresh_non_duplicate_candidates' => 0,
            'duplicate_candidates' => 5,
        ]);

        $this->assertSame('wait', $result['action']);
    }

    public function test_wait_when_only_malformed_candidates(): void
    {
        $result = $this->policy->evaluate([
            'claimable_depth' => 10,
            'high_value_frontier_count' => 1,
            'fresh_non_duplicate_candidates' => 0,
            'malformed_candidates' => 3,
        ]);

        $this->assertSame('wait', $result['action']);
    }

    public function test_wait_when_only_low_value_candidates(): void
    {
        $result = $this->policy->evaluate([
            'claimable_depth' => 10,
            'high_value_frontier_count' => 1,
            'fresh_non_duplicate_candidates' => 0,
            'low_value_candidates' => 4,
        ]);

        $this->assertSame('wait', $result['action']);
    }

    // ── padding_throttle_explanation ──

    public function test_padding_throttle_explanation_present_in_all_outputs(): void
    {
        $result = $this->policy->evaluate([
            'claimable_depth' => 10,
            'high_value_frontier_count' => 1,
        ]);

        $this->assertArrayHasKey('padding_throttle_explanation', $result);
        $this->assertNotEmpty($result['padding_throttle_explanation']);
    }

    public function test_padding_throttle_explanation_distinguishes_throttle_from_block(): void
    {
        $result = $this->policy->evaluate([
            'claimable_depth' => 300,
            'fresh_non_duplicate_candidates' => 2,
        ]);

        $this->assertStringContainsString('queue_depth_never_blocks_valuable_origination', $result['padding_throttle_explanation']);
    }

    public function test_wait_explanation_mentions_candidate_quality_not_queue_depth(): void
    {
        $result = $this->policy->evaluate([
            'claimable_depth' => 10,
            'high_value_frontier_count' => 1,
            'fresh_non_duplicate_candidates' => 0,
            'duplicate_candidates' => 5,
        ]);

        $this->assertStringContainsString('wait_is_candidate_quality_gate_not_queue_depth', $result['padding_throttle_explanation']);
    }
}
