<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Replenisher;

use App\Services\Ai\SelfConstruction\Replenisher\AtlasSelfConstructionQueueTopUpPolicy;
use PHPUnit\Framework\TestCase;

final class AtlasSelfConstructionQueueTopUpPolicyWorkerFloorHysteresisTest extends TestCase
{
    private function baseFacts(): array
    {
        return [
            'queue_health_status' => 'green',
            'malformed_count' => 0,
            'accepted_frontier_count' => 8,
            'risk_budget' => ['remaining_units' => 100, 'required_per_packet' => 10],
            'low_water_mark' => 25,
            'batch_cap' => 10,
        ];
    }

    public function test_worker_floor_hysteresis_trigger_requires_top_up(): void
    {
        $facts = array_merge($this->baseFacts(), [
            'active_leases' => 6,
            'claimable_depth' => 13,
            'claimable_per_active_worker' => 2,
            'replenish_recommendation' => 'replenish_soon',
        ]);

        $result = (new AtlasSelfConstructionQueueTopUpPolicy)->decide($facts);

        $this->assertTrue($result['top_up_required']);
    }

    public function test_comfortable_queue_above_worker_floor_does_not_require_top_up(): void
    {
        $facts = array_merge($this->baseFacts(), [
            'active_leases' => 6,
            'claimable_depth' => 30,
            'claimable_per_active_worker' => 5,
            'replenish_recommendation' => 'comfortable',
        ]);

        $result = (new AtlasSelfConstructionQueueTopUpPolicy)->decide($facts);

        $this->assertFalse($result['top_up_required']);
        $this->assertSame(AtlasSelfConstructionQueueTopUpPolicy::OUTCOME_WAIT, $result['outcome']);
    }

    public function test_malformed_queue_still_takes_precedence_over_hysteresis_trigger(): void
    {
        $facts = array_merge($this->baseFacts(), [
            'malformed_count' => 3,
            'active_leases' => 6,
            'claimable_depth' => 13,
            'claimable_per_active_worker' => 2,
            'replenish_recommendation' => 'replenish_soon',
        ]);

        $result = (new AtlasSelfConstructionQueueTopUpPolicy)->decide($facts);

        $this->assertSame(AtlasSelfConstructionQueueTopUpPolicy::OUTCOME_REPAIR_FIRST, $result['outcome']);
    }

    public function test_dry_queue_behavior_unchanged_without_hysteresis_facts(): void
    {
        $facts = array_merge($this->baseFacts(), [
            'claimable_depth' => 5,
            'servable_depth' => 5,
        ]);

        $result = (new AtlasSelfConstructionQueueTopUpPolicy)->decide($facts);

        $this->assertSame(AtlasSelfConstructionQueueTopUpPolicy::OUTCOME_ALLOW, $result['outcome']);
        $this->assertGreaterThan(0, $result['new_packet_count']);
    }

    // ── worker_buffer_target_per_worker ──────────────────────────────────────

    public function test_replenish_soon_restores_configurable_worker_buffer_target(): void
    {
        $facts = array_merge($this->baseFacts(), [
            'active_leases' => 7,
            'claimable_depth' => 22,
            'servable_depth' => 22,
            'claimable_per_active_worker' => 3,
            'replenish_recommendation' => 'replenish_soon',
            'worker_buffer_target_per_worker' => 5,
            'accepted_frontier_count' => 10,
            'batch_cap' => 10,
            'low_water_mark' => 0,
        ]);

        $result = (new AtlasSelfConstructionQueueTopUpPolicy)->decide($facts);

        $this->assertSame(AtlasSelfConstructionQueueTopUpPolicy::OUTCOME_ALLOW, $result['outcome']);
        $this->assertGreaterThan(0, $result['new_packet_count']);
    }

    public function test_comfortable_buffer_without_replenish_soon_stays_wait(): void
    {
        $facts = array_merge($this->baseFacts(), [
            'active_leases' => 7,
            'claimable_depth' => 40,
            'servable_depth' => 40,
            'claimable_per_active_worker' => 6,
            'worker_buffer_target_per_worker' => 5,
            'low_water_mark' => 0,
        ]);

        $result = (new AtlasSelfConstructionQueueTopUpPolicy)->decide($facts);

        $this->assertSame(AtlasSelfConstructionQueueTopUpPolicy::OUTCOME_WAIT, $result['outcome']);
        $this->assertSame(0, $result['new_packet_count']);
    }
}
