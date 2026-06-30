<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\TaskServing;

use App\Services\Ai\SelfConstruction\TaskServing\AtlasTaskServingLeaseClaimParityInspector;
use App\Services\Ai\SelfConstruction\TaskServing\AtlasTaskServingLeaseMismatchRepairPlan;
use Tests\TestCase;

final class AtlasTaskServingLeaseMismatchRepairPlanTest extends TestCase
{
    private function plan(): AtlasTaskServingLeaseMismatchRepairPlan
    {
        return new AtlasTaskServingLeaseMismatchRepairPlan;
    }

    public function test_steps_carry_action_reason_safety_level_and_expected_health_delta(): void
    {
        $report = (new AtlasTaskServingLeaseClaimParityInspector)->inspect([], []);

        $result = $this->plan()->compile($report);

        $this->assertNotEmpty($result['steps']);
        $step = $result['steps'][0];
        $this->assertArrayHasKey('action', $step);
        $this->assertArrayHasKey('reason', $step);
        $this->assertArrayHasKey('safety_level', $step);
        $this->assertArrayHasKey('expected_health_delta', $step);
    }

    public function test_recoverable_candidates_recommend_reap_leases_before_heavier_repair(): void
    {
        // lease-without-claim => recoverable, AND active_leases(1) > claimed_records(0) would also
        // trigger registry repair if it ran first — reap_leases must come first in the plan.
        $report = (new AtlasTaskServingLeaseClaimParityInspector)->inspect(
            [['lease_id' => 'l1', 'task_packet_id' => 'tp-1']],
            [],
        );

        $result = $this->plan()->compile($report);

        $this->assertSame(AtlasTaskServingLeaseMismatchRepairPlan::ACTION_REAP_LEASES, $result['steps'][0]['action']);
    }

    public function test_non_recoverable_active_lease_surplus_recommends_registry_repair_not_deletion(): void
    {
        $report = (new AtlasTaskServingLeaseClaimParityInspector)->inspect(
            [['lease_id' => 'l1', 'task_packet_id' => 'tp-1'], ['lease_id' => 'l2', 'task_packet_id' => 'tp-1']],
            [['task_packet_id' => 'tp-1', 'status' => 'claimed']],
        );

        $result = $this->plan()->compile($report);

        $actions = array_column($result['steps'], 'action');
        $this->assertContains(AtlasTaskServingLeaseMismatchRepairPlan::ACTION_REPAIR_REGISTRY, $actions);
        foreach ($result['steps'] as $step) {
            $this->assertStringNotContainsString('delete', strtolower($step['action']));
            $this->assertStringNotContainsString('delete', strtolower($step['safety_level']));
        }
    }

    public function test_claim_without_lease_recommends_investigate_writer(): void
    {
        $report = (new AtlasTaskServingLeaseClaimParityInspector)->inspect(
            [],
            [['task_packet_id' => 'tp-1', 'status' => 'claimed']],
        );

        $result = $this->plan()->compile($report);

        $actions = array_column($result['steps'], 'action');
        $this->assertContains(AtlasTaskServingLeaseMismatchRepairPlan::ACTION_INVESTIGATE_WRITER, $actions);
    }

    public function test_clean_parity_emits_no_op_observe_with_clean_safety_level(): void
    {
        $report = (new AtlasTaskServingLeaseClaimParityInspector)->inspect(
            [['lease_id' => 'l1', 'task_packet_id' => 'tp-1']],
            [['task_packet_id' => 'tp-1', 'status' => 'claimed']],
        );

        $result = $this->plan()->compile($report);

        $this->assertCount(1, $result['steps']);
        $this->assertSame(AtlasTaskServingLeaseMismatchRepairPlan::ACTION_OBSERVE, $result['steps'][0]['action']);
        $this->assertSame('clean', $result['steps'][0]['safety_level']);
    }

    public function test_plan_never_mutates_the_queue(): void
    {
        $report = (new AtlasTaskServingLeaseClaimParityInspector)->inspect([], []);

        $result = $this->plan()->compile($report);

        $this->assertFalse($result['mutates_queue']);
    }

    public function test_result_is_deterministic_for_identical_input(): void
    {
        $report = (new AtlasTaskServingLeaseClaimParityInspector)->inspect(
            [['lease_id' => 'l1', 'task_packet_id' => 'tp-1']],
            [],
        );
        $plan = $this->plan();

        $this->assertSame($plan->compile($report), $plan->compile($report));
    }
}
