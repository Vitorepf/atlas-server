<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Replenisher;

use App\Services\Ai\SelfConstruction\Replenisher\AtlasSelfConstructionQueueTopUpPolicy;
use PHPUnit\Framework\TestCase;

/**
 * Worker-floor hysteresis: replenish_soon restores a minimum claimable-per-active-worker
 * buffer instead of only adding active_leases - netClaimable (which is zero when the
 * queue is above worker count but still thin).
 */
final class AtlasSelfConstructionQueueTopUpPolicyWorkerFloorHysteresisTest extends TestCase
{
    private AtlasSelfConstructionQueueTopUpPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = new AtlasSelfConstructionQueueTopUpPolicy();
    }

    /**
     * AC: active_leases=7, claimable_depth=22, claimable_per_active_worker=3,
     * replenish_recommendation=replenish_soon, worker_buffer_target_per_worker=5,
     * accepted_frontier_count>=10, batch_cap=10 → allow bounded top-up > 0.
     *
     * Without the fix, hysteresisNeed = max(0, 7-22, 0) = 0 (netClaimable > active_leases)
     * so newCount=0 → wait. With the fix, the policy computes a buffer target of
     * worker_buffer_target_per_worker * active_leases = 5*7 = 35 and tops up to reach it.
     */
    public function test_replenish_soon_allows_bounded_top_up_when_queue_above_worker_count(): void
    {
        $result = $this->policy->decide([
            'queue_health_status' => 'green',
            'claimable_depth' => 22,
            'malformed_count' => 0,
            'accepted_frontier_count' => 10,
            'risk_budget' => ['remaining_units' => 100, 'required_per_packet' => 1],
            'active_leases' => 7,
            'claimable_per_active_worker' => 3.0,
            'replenish_recommendation' => 'replenish_soon',
            'worker_buffer_target_per_worker' => 5,
            'batch_cap' => 10,
            'low_water_mark' => 25,
        ]);

        $this->assertSame('allow', $result['outcome'], 'must allow top-up when replenish_soon + thin buffer');
        $this->assertGreaterThan(0, $result['new_packet_count'], 'must add at least 1 packet');
        $this->assertLessThanOrEqual(10, $result['new_packet_count'], 'must respect batch_cap');
    }

    /**
     * AC: comfortable claimable_per_active_worker, no replenish_soon → wait remains unchanged.
     */
    public function test_comfortable_buffer_with_no_replenish_soon_waits(): void
    {
        $result = $this->policy->decide([
            'queue_health_status' => 'green',
            'claimable_depth' => 100,
            'malformed_count' => 0,
            'accepted_frontier_count' => 50,
            'risk_budget' => ['remaining_units' => 100, 'required_per_packet' => 1],
            'active_leases' => 5,
            'claimable_per_active_worker' => 20.0,
            'replenish_recommendation' => 'comfortable',
            'worker_buffer_target_per_worker' => 5,
            'batch_cap' => 10,
            'low_water_mark' => 25,
        ]);

        $this->assertSame('wait', $result['outcome']);
        $this->assertSame(0, $result['new_packet_count']);
    }

    /**
     * Without worker_buffer_target_per_worker, the old behavior (max(0, active_leases - netClaimable))
     * is preserved — backward compatible.
     */
    public function test_backward_compatible_without_worker_buffer_target(): void
    {
        $result = $this->policy->decide([
            'queue_health_status' => 'green',
            'claimable_depth' => 22,
            'malformed_count' => 0,
            'accepted_frontier_count' => 10,
            'risk_budget' => ['remaining_units' => 100, 'required_per_packet' => 1],
            'active_leases' => 7,
            'claimable_per_active_worker' => 3.0,
            'replenish_recommendation' => 'replenish_soon',
            'batch_cap' => 10,
            'low_water_mark' => 25,
        ]);

        // Without worker_buffer_target_per_worker, old hysteresisNeed logic applies:
        // max(0, 7-22) = 0, but low_water is 25 > 22 so lowWaterNeed = 25-22 = 3
        $this->assertSame('allow', $result['outcome']);
        $this->assertGreaterThan(0, $result['new_packet_count']);
    }
}
