<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainAdaptiveBatchSizeGovernor;
use Tests\TestCase;

class AtlasExternalBrainAdaptiveBatchSizeGovernorWorkerFloorTest extends TestCase
{
    public function test_near_starvation_recommends_bounded_top_up_batch_when_drain_rate_unknown(): void
    {
        $governor = new AtlasExternalBrainAdaptiveBatchSizeGovernor();

        $result = $governor->govern([
            'queue_depth' => 10,
            'servable_now' => 0,
            'active_workers' => 3,
            'minimum_claimable_per_worker' => 2,
        ]);

        $this->assertTrue($result['should_enqueue']);
        $this->assertGreaterThanOrEqual(6, $result['recommended_batch_size']);
        $this->assertLessThanOrEqual(12, $result['recommended_batch_size']);
        $this->assertContains('near_starvation_top_up_batch', $result['reason_codes']);
    }

    public function test_servable_now_meeting_worker_floor_emits_queue_depth_sufficient_with_zero_batch(): void
    {
        $governor = new AtlasExternalBrainAdaptiveBatchSizeGovernor();

        $result = $governor->govern([
            'queue_depth' => 10,
            'servable_now' => 6,
            'active_workers' => 3,
            'minimum_claimable_per_worker' => 2,
        ]);

        $this->assertSame(0, $result['recommended_batch_size']);
        $this->assertFalse($result['should_enqueue']);
        $this->assertContains('queue_depth_sufficient', $result['reason_codes']);
    }

    public function test_servable_now_meeting_worker_floor_but_high_priority_gap_overrides(): void
    {
        $governor = new AtlasExternalBrainAdaptiveBatchSizeGovernor();

        $result = $governor->govern([
            'queue_depth' => 10,
            'servable_now' => 6,
            'active_workers' => 3,
            'minimum_claimable_per_worker' => 2,
            'high_priority_gap_count' => 5,
        ]);

        $this->assertGreaterThanOrEqual(5, $result['recommended_batch_size']);
        $this->assertTrue($result['should_enqueue']);
        $this->assertContains('high_priority_gap_needs_fill', $result['reason_codes']);
    }
}
