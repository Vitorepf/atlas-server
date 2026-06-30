<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Replenisher;

use App\Services\Ai\SelfConstruction\Replenisher\AtlasSelfConstructionQueueTopUpPolicy;
use PHPUnit\Framework\TestCase;

/**
 * Proves AtlasSelfConstructionQueueTopUpPolicy: a dry queue with accepted frontiers ⇒ allow with
 * bounded new_packet_count; malformed_count > 0 ⇒ repair_first; a healthy queue above low_water_mark
 * ⇒ wait; queue_health_status='red' ⇒ stop_for_safety; risk_budget_remaining=0 ⇒ stop_for_safety.
 */
final class AtlasSelfConstructionQueueTopUpPolicyTest extends TestCase
{
    private function dryQueueFacts(): array
    {
        return [
            'queue_health_status' => 'green',
            'claimable_depth' => 5,
            'servable_depth' => 5,
            'malformed_count' => 0,
            'accepted_frontier_count' => 8,
            'risk_budget' => ['remaining_units' => 100, 'required_per_packet' => 10],
            'low_water_mark' => 25,
            'batch_cap' => 10,
        ];
    }

    public function test_dry_queue_with_frontiers_allows_top_up_bounded_by_caps(): void
    {
        $r = (new AtlasSelfConstructionQueueTopUpPolicy)->decide($this->dryQueueFacts());
        $this->assertSame(AtlasSelfConstructionQueueTopUpPolicy::OUTCOME_ALLOW, $r['outcome']);
        // bounded by min(batch_cap=10, accepted=8, budget=10, lowWater-claimable=20) = 8
        $this->assertSame(8, $r['new_packet_count']);
    }

    public function test_malformed_count_yields_repair_first(): void
    {
        $f = $this->dryQueueFacts();
        $f['malformed_count'] = 4;
        $r = (new AtlasSelfConstructionQueueTopUpPolicy)->decide($f);
        $this->assertSame(AtlasSelfConstructionQueueTopUpPolicy::OUTCOME_REPAIR_FIRST, $r['outcome']);
    }

    public function test_healthy_queue_above_low_water_mark_yields_wait(): void
    {
        $f = $this->dryQueueFacts();
        $f['claimable_depth'] = 50;
        $r = (new AtlasSelfConstructionQueueTopUpPolicy)->decide($f);
        $this->assertSame(AtlasSelfConstructionQueueTopUpPolicy::OUTCOME_WAIT, $r['outcome']);
    }

    public function test_queue_health_red_yields_stop_for_safety(): void
    {
        $f = $this->dryQueueFacts();
        $f['queue_health_status'] = 'red';
        $r = (new AtlasSelfConstructionQueueTopUpPolicy)->decide($f);
        $this->assertSame(AtlasSelfConstructionQueueTopUpPolicy::OUTCOME_STOP_SAFETY, $r['outcome']);
    }

    public function test_risk_budget_exhausted_yields_stop_for_safety(): void
    {
        $f = $this->dryQueueFacts();
        $f['risk_budget']['remaining_units'] = 0;
        $r = (new AtlasSelfConstructionQueueTopUpPolicy)->decide($f);
        $this->assertSame(AtlasSelfConstructionQueueTopUpPolicy::OUTCOME_STOP_SAFETY, $r['outcome']);
    }

    public function test_no_accepted_frontiers_yields_wait(): void
    {
        $f = $this->dryQueueFacts();
        $f['accepted_frontier_count'] = 0;
        $r = (new AtlasSelfConstructionQueueTopUpPolicy)->decide($f);
        $this->assertSame(AtlasSelfConstructionQueueTopUpPolicy::OUTCOME_WAIT, $r['outcome']);
    }

    public function test_claimable_cannot_feed_workers_sets_top_up_required_and_allows(): void
    {
        $r = (new AtlasSelfConstructionQueueTopUpPolicy)->decide([
            'queue_health_status' => 'green',
            'claimable_depth' => 3,
            'servable_depth' => 3,
            'malformed_count' => 0,
            'accepted_frontier_count' => 10,
            'risk_budget' => ['remaining_units' => 100, 'required_per_packet' => 10],
            'low_water_mark' => 25,
            'batch_cap' => 10,
            'target_worker_count' => 5,
            'active_leases' => 0,
            'quarantined_count' => 0,
        ]);

        $this->assertTrue($r['top_up_required'], 'net_claimable(3) < target_worker_count(5) must set top_up_required');
        $this->assertGreaterThan(0, $r['target_new_packets']);
        $this->assertSame(AtlasSelfConstructionQueueTopUpPolicy::OUTCOME_ALLOW, $r['outcome']);
    }

    public function test_enough_claimable_for_all_workers_yields_no_op(): void
    {
        $r = (new AtlasSelfConstructionQueueTopUpPolicy)->decide([
            'queue_health_status' => 'green',
            'claimable_depth' => 30,
            'servable_depth' => 30,
            'malformed_count' => 0,
            'accepted_frontier_count' => 10,
            'risk_budget' => ['remaining_units' => 100, 'required_per_packet' => 10],
            'low_water_mark' => 25,
            'batch_cap' => 10,
            'target_worker_count' => 5,
            'active_leases' => 0,
            'quarantined_count' => 0,
        ]);

        $this->assertFalse($r['top_up_required'], 'net_claimable(30) >= target_worker_count(5) must not require top-up');
        $this->assertSame(0, $r['target_new_packets']);
        $this->assertSame(AtlasSelfConstructionQueueTopUpPolicy::OUTCOME_WAIT, $r['outcome']);
    }

    public function test_worker_spike_above_low_water_still_triggers_prefill(): void
    {
        // claimable=30 > low_water_mark=25, so belowLowWater=FALSE
        // but target_worker_count=40 > net_claimable=30, so topUpRequired=TRUE
        // the !belowLowWater && !topUpRequired guard must NOT fire — must reach ALLOW
        $r = (new AtlasSelfConstructionQueueTopUpPolicy)->decide([
            'queue_health_status' => 'green',
            'claimable_depth' => 30,
            'malformed_count' => 0,
            'accepted_frontier_count' => 10,
            'risk_budget' => ['remaining_units' => 100, 'required_per_packet' => 5],
            'low_water_mark' => 25,
            'batch_cap' => 10,
            'target_worker_count' => 40,
            'quarantined_count' => 0,
        ]);

        $this->assertSame(AtlasSelfConstructionQueueTopUpPolicy::OUTCOME_ALLOW, $r['outcome']);
        $this->assertTrue($r['top_up_required']);
        $this->assertGreaterThan(0, $r['new_packet_count']);
    }

    public function test_quarantined_poisons_reduce_effective_claimable_and_trigger_top_up(): void
    {
        // claimable=30 looks healthy (>= low_water_mark=25), but quarantined=27 poisons leave only 3 net
        $r = (new AtlasSelfConstructionQueueTopUpPolicy)->decide([
            'queue_health_status' => 'green',
            'claimable_depth' => 30,
            'servable_depth' => 30,
            'malformed_count' => 0,
            'accepted_frontier_count' => 10,
            'risk_budget' => ['remaining_units' => 100, 'required_per_packet' => 10],
            'low_water_mark' => 25,
            'batch_cap' => 10,
            'target_worker_count' => 5,
            'active_leases' => 0,
            'quarantined_count' => 27,
        ]);

        $this->assertTrue($r['top_up_required'], 'net_claimable(3) < target_worker_count(5) despite claimable>=low_water');
        $this->assertSame(AtlasSelfConstructionQueueTopUpPolicy::OUTCOME_ALLOW, $r['outcome']);
    }

    // ── Stale-claimable backlog top-up path (AC2/AC3) ───────────────────────────

    public function test_stale_backlog_above_low_water_with_active_workers_allows_bounded_top_up(): void
    {
        // claimable=30 >= low_water_mark=25 (raw looks healthy), but stale_claimable_count=28
        // means effective supply is only 2 — and active_leases proves workers are present.
        $r = (new AtlasSelfConstructionQueueTopUpPolicy)->decide([
            'queue_health_status' => 'green',
            'claimable_depth' => 30,
            'malformed_count' => 0,
            'accepted_frontier_count' => 6,
            'risk_budget' => ['remaining_units' => 100, 'required_per_packet' => 10],
            'low_water_mark' => 25,
            'batch_cap' => 5,
            'active_leases' => 3,
            'stale_claimable_count' => 28,
            'oldest_claimable_seconds' => 7200,
        ]);

        $this->assertSame(AtlasSelfConstructionQueueTopUpPolicy::OUTCOME_ALLOW, $r['outcome']);
        // bounded by min(batch_cap=5, accepted=6, budget=10, lowWater-effective(25-2=23)) = 5
        $this->assertSame(5, $r['new_packet_count']);
        $this->assertSame(5, $r['target_new_packets']);
        $this->assertContains('allow:stale_backlog_effective_low_water:5', $r['reasons']);
    }

    public function test_stale_backlog_top_up_bounded_by_accepted_frontier_count(): void
    {
        $r = (new AtlasSelfConstructionQueueTopUpPolicy)->decide([
            'queue_health_status' => 'green',
            'claimable_depth' => 30,
            'malformed_count' => 0,
            'accepted_frontier_count' => 2,
            'risk_budget' => ['remaining_units' => 100, 'required_per_packet' => 10],
            'low_water_mark' => 25,
            'batch_cap' => 10,
            'target_worker_count' => 4,
            'stale_claimable_count' => 28,
        ]);

        $this->assertSame(AtlasSelfConstructionQueueTopUpPolicy::OUTCOME_ALLOW, $r['outcome']);
        $this->assertSame(2, $r['new_packet_count']);
    }

    public function test_stale_backlog_top_up_bounded_by_risk_budget(): void
    {
        $r = (new AtlasSelfConstructionQueueTopUpPolicy)->decide([
            'queue_health_status' => 'green',
            'claimable_depth' => 30,
            'malformed_count' => 0,
            'accepted_frontier_count' => 10,
            'risk_budget' => ['remaining_units' => 20, 'required_per_packet' => 10],
            'low_water_mark' => 25,
            'batch_cap' => 10,
            'active_leases' => 1,
            'stale_claimable_count' => 28,
        ]);

        $this->assertSame(AtlasSelfConstructionQueueTopUpPolicy::OUTCOME_ALLOW, $r['outcome']);
        $this->assertSame(2, $r['new_packet_count']);
    }

    public function test_stale_backlog_without_active_workers_does_not_trigger_top_up(): void
    {
        // claimable=30 above low_water, stale_claimable_count high, but NO active_leases or
        // target_worker_count present — workers are not proven active, so no top-up.
        $r = (new AtlasSelfConstructionQueueTopUpPolicy)->decide([
            'queue_health_status' => 'green',
            'claimable_depth' => 30,
            'malformed_count' => 0,
            'accepted_frontier_count' => 10,
            'risk_budget' => ['remaining_units' => 100, 'required_per_packet' => 10],
            'low_water_mark' => 25,
            'batch_cap' => 10,
            'stale_claimable_count' => 28,
        ]);

        $this->assertSame(AtlasSelfConstructionQueueTopUpPolicy::OUTCOME_WAIT, $r['outcome']);
    }

    public function test_stale_backlog_below_effective_low_water_threshold_does_not_trigger(): void
    {
        // stale_claimable_count is small enough that effective claimable still clears low_water.
        $r = (new AtlasSelfConstructionQueueTopUpPolicy)->decide([
            'queue_health_status' => 'green',
            'claimable_depth' => 30,
            'malformed_count' => 0,
            'accepted_frontier_count' => 10,
            'risk_budget' => ['remaining_units' => 100, 'required_per_packet' => 10],
            'low_water_mark' => 25,
            'batch_cap' => 10,
            'active_leases' => 2,
            'stale_claimable_count' => 3,
        ]);

        $this->assertSame(AtlasSelfConstructionQueueTopUpPolicy::OUTCOME_WAIT, $r['outcome']);
    }

    public function test_queue_health_red_takes_precedence_over_stale_backlog_top_up(): void
    {
        $r = (new AtlasSelfConstructionQueueTopUpPolicy)->decide([
            'queue_health_status' => 'red',
            'claimable_depth' => 30,
            'malformed_count' => 0,
            'accepted_frontier_count' => 10,
            'risk_budget' => ['remaining_units' => 100, 'required_per_packet' => 10],
            'low_water_mark' => 25,
            'batch_cap' => 10,
            'active_leases' => 3,
            'stale_claimable_count' => 28,
        ]);

        $this->assertSame(AtlasSelfConstructionQueueTopUpPolicy::OUTCOME_STOP_SAFETY, $r['outcome']);
    }

    public function test_exhausted_risk_budget_takes_precedence_over_stale_backlog_top_up(): void
    {
        $r = (new AtlasSelfConstructionQueueTopUpPolicy)->decide([
            'queue_health_status' => 'green',
            'claimable_depth' => 30,
            'malformed_count' => 0,
            'accepted_frontier_count' => 10,
            'risk_budget' => ['remaining_units' => 0, 'required_per_packet' => 10],
            'low_water_mark' => 25,
            'batch_cap' => 10,
            'active_leases' => 3,
            'stale_claimable_count' => 28,
        ]);

        $this->assertSame(AtlasSelfConstructionQueueTopUpPolicy::OUTCOME_STOP_SAFETY, $r['outcome']);
    }

    public function test_malformed_count_takes_precedence_over_stale_backlog_top_up(): void
    {
        $r = (new AtlasSelfConstructionQueueTopUpPolicy)->decide([
            'queue_health_status' => 'green',
            'claimable_depth' => 30,
            'malformed_count' => 2,
            'accepted_frontier_count' => 10,
            'risk_budget' => ['remaining_units' => 100, 'required_per_packet' => 10],
            'low_water_mark' => 25,
            'batch_cap' => 10,
            'active_leases' => 3,
            'stale_claimable_count' => 28,
        ]);

        $this->assertSame(AtlasSelfConstructionQueueTopUpPolicy::OUTCOME_REPAIR_FIRST, $r['outcome']);
    }
}
