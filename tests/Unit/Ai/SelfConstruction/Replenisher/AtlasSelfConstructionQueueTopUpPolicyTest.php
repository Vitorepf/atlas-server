<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Replenisher;

use App\Services\Ai\SelfConstruction\Replenisher\AtlasSelfConstructionQueueTopUpPolicy;
use PHPUnit\Framework\TestCase;

/**
 * Core tests for AtlasSelfConstructionQueueTopUpPolicy.
 */
final class AtlasSelfConstructionQueueTopUpPolicyTest extends TestCase
{
    private AtlasSelfConstructionQueueTopUpPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = new AtlasSelfConstructionQueueTopUpPolicy();
    }

    public function test_stop_for_safety_when_queue_red(): void
    {
        $result = $this->policy->decide([
            'queue_health_status' => 'red',
            'claimable_depth' => 100,
            'malformed_count' => 0,
            'accepted_frontier_count' => 50,
            'risk_budget' => ['remaining_units' => 100, 'required_per_packet' => 1],
        ]);

        $this->assertSame('stop_for_safety', $result['outcome']);
        $this->assertSame(0, $result['new_packet_count']);
    }

    public function test_stop_for_safety_when_budget_exhausted(): void
    {
        $result = $this->policy->decide([
            'queue_health_status' => 'green',
            'claimable_depth' => 5,
            'malformed_count' => 0,
            'accepted_frontier_count' => 50,
            'risk_budget' => ['remaining_units' => 0, 'required_per_packet' => 1],
        ]);

        $this->assertSame('stop_for_safety', $result['outcome']);
    }

    public function test_repair_first_when_malformed(): void
    {
        $result = $this->policy->decide([
            'queue_health_status' => 'green',
            'claimable_depth' => 5,
            'malformed_count' => 3,
            'accepted_frontier_count' => 50,
            'risk_budget' => ['remaining_units' => 100, 'required_per_packet' => 1],
        ]);

        $this->assertSame('repair_first', $result['outcome']);
    }

    public function test_wait_when_claimable_above_low_water(): void
    {
        $result = $this->policy->decide([
            'queue_health_status' => 'green',
            'claimable_depth' => 100,
            'malformed_count' => 0,
            'accepted_frontier_count' => 50,
            'risk_budget' => ['remaining_units' => 100, 'required_per_packet' => 1],
            'active_leases' => 3,
            'claimable_per_active_worker' => 33.0,
            'replenish_recommendation' => 'comfortable',
        ]);

        $this->assertSame('wait', $result['outcome']);
    }

    public function test_allow_top_up_when_below_low_water(): void
    {
        $result = $this->policy->decide([
            'queue_health_status' => 'green',
            'claimable_depth' => 10,
            'malformed_count' => 0,
            'accepted_frontier_count' => 50,
            'risk_budget' => ['remaining_units' => 100, 'required_per_packet' => 1],
            'low_water_mark' => 25,
            'batch_cap' => 10,
        ]);

        $this->assertSame('allow', $result['outcome']);
        $this->assertGreaterThan(0, $result['new_packet_count']);
        $this->assertLessThanOrEqual(10, $result['new_packet_count']);
    }

    public function test_wait_when_no_accepted_frontiers(): void
    {
        $result = $this->policy->decide([
            'queue_health_status' => 'green',
            'claimable_depth' => 5,
            'malformed_count' => 0,
            'accepted_frontier_count' => 0,
            'risk_budget' => ['remaining_units' => 100, 'required_per_packet' => 1],
            'low_water_mark' => 25,
        ]);

        $this->assertSame('wait', $result['outcome']);
    }

    public function test_output_schema_is_present(): void
    {
        $result = $this->policy->decide([
            'queue_health_status' => 'green',
            'claimable_depth' => 100,
            'malformed_count' => 0,
            'accepted_frontier_count' => 50,
            'risk_budget' => ['remaining_units' => 100, 'required_per_packet' => 1],
        ]);

        $this->assertSame('atlas.replenisher.queue_top_up_policy.v1', $result['schema']);
    }

    // ── AC: deep queue plus weak candidate quality recommends hold_or_consolidate ──

    public function test_deep_queue_plus_weak_candidate_quality_recommends_hold_or_consolidate(): void
    {
        $result = $this->policy->decide([
            'queue_health_status' => 'green',
            'claimable_depth' => 100,
            'malformed_count' => 0,
            'accepted_frontier_count' => 50,
            'risk_budget' => ['remaining_units' => 100, 'required_per_packet' => 1],
            'candidate_quality_score' => 0.2,
        ]);

        $this->assertSame(AtlasSelfConstructionQueueTopUpPolicy::OUTCOME_HOLD_OR_CONSOLIDATE, $result['outcome']);
        $this->assertSame(0, $result['new_packet_count']);
    }

    // ── AC: high worker drain plus high candidate quality recommends top_up_selective ──

    public function test_high_worker_drain_plus_high_candidate_quality_recommends_top_up_selective(): void
    {
        $result = $this->policy->decide([
            'queue_health_status' => 'green',
            'claimable_depth' => 100,
            'malformed_count' => 0,
            'accepted_frontier_count' => 50,
            'risk_budget' => ['remaining_units' => 100, 'required_per_packet' => 1],
            'worker_drain_rate' => 0.5,
            'candidate_quality_score' => 0.9,
        ]);

        $this->assertSame(AtlasSelfConstructionQueueTopUpPolicy::OUTCOME_TOP_UP_SELECTIVE, $result['outcome']);
        $this->assertGreaterThan(0, $result['new_packet_count']);
    }

    // ── AC: output includes lane_balance and minimum_quality_threshold ────────

    public function test_output_includes_lane_balance_and_minimum_quality_threshold(): void
    {
        $result = $this->policy->decide([
            'queue_health_status' => 'green',
            'claimable_depth' => 100,
            'malformed_count' => 0,
            'accepted_frontier_count' => 50,
            'risk_budget' => ['remaining_units' => 100, 'required_per_packet' => 1],
            'lane_distribution' => ['feature' => 10, 'bug-fix' => 2],
            'minimum_quality_threshold' => 0.7,
        ]);

        $this->assertArrayHasKey('lane_balance', $result);
        $this->assertArrayHasKey('balanced', $result['lane_balance']);
        $this->assertSame(['feature' => 10, 'bug-fix' => 2], $result['lane_balance']['lanes']);
        $this->assertFalse($result['lane_balance']['balanced']);
        $this->assertSame(0.7, $result['minimum_quality_threshold']);
    }

    public function test_lane_balance_defaults_to_balanced_when_no_distribution_supplied(): void
    {
        $result = $this->policy->decide([
            'queue_health_status' => 'green',
            'claimable_depth' => 100,
            'malformed_count' => 0,
            'accepted_frontier_count' => 50,
            'risk_budget' => ['remaining_units' => 100, 'required_per_packet' => 1],
        ]);

        $this->assertTrue($result['lane_balance']['balanced']);
        $this->assertSame([], $result['lane_balance']['lanes']);
        $this->assertSame(AtlasSelfConstructionQueueTopUpPolicy::DEFAULT_MIN_QUALITY_THRESHOLD, $result['minimum_quality_threshold']);
    }
}
