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

    // ── AC1/AC2: reap-leases tried before repair_registry for non-recoverable surplus ──

    public function test_non_recoverable_active_lease_surplus_tries_reap_leases_before_repair_registry(): void
    {
        $report = [
            'classification' => 'active_lease_surplus',
            'active_leases' => 2,
            'claimed_records' => 1,
            'recoverable_candidates' => ['total' => 0],
        ];

        $result = $this->plan()->compile($report);

        $actions = array_column($result['steps'], 'action');
        $this->assertSame(AtlasTaskServingLeaseMismatchRepairPlan::ACTION_REAP_LEASES, $actions[0]);
        $reapIndex = array_search(AtlasTaskServingLeaseMismatchRepairPlan::ACTION_REAP_LEASES, $actions, true);
        $repairIndex = array_search(AtlasTaskServingLeaseMismatchRepairPlan::ACTION_REPAIR_REGISTRY, $actions, true);
        $this->assertNotFalse($repairIndex);
        $this->assertLessThan($repairIndex, $reapIndex);
    }

    public function test_clean_parity_report_still_returns_observe_only(): void
    {
        $report = [
            'classification' => 'clean',
            'active_leases' => 1,
            'claimed_records' => 1,
            'recoverable_candidates' => ['total' => 0],
        ];

        $result = $this->plan()->compile($report);

        $this->assertCount(1, $result['steps']);
        $this->assertSame(AtlasTaskServingLeaseMismatchRepairPlan::ACTION_OBSERVE, $result['steps'][0]['action']);
    }

    // ── classifyRepairCategory(): automatic_reap / observe_ghost / quarantine_review / noop ──

    private function category(array $result): array
    {
        return $this->plan()->classifyRepairCategory($result);
    }

    // ── AC: true recoverable mismatch ────────────────────────────────────────────

    public function test_true_recoverable_mismatch_classifies_automatic_reap(): void
    {
        $report = (new AtlasTaskServingLeaseClaimParityInspector)->inspect(
            [['lease_id' => 'l1', 'task_packet_id' => 'tp-1']],
            [['task_packet_id' => 'tp-1', 'status' => 'completed']],
        );

        $result = $this->category($report);

        $this->assertSame(AtlasTaskServingLeaseMismatchRepairPlan::CATEGORY_AUTOMATIC_REAP, $result['category']);
        $this->assertNotEmpty($result['reason']);
        $this->assertNotEmpty($result['safety_note']);
    }

    // ── AC: ghost mismatch ────────────────────────────────────────────────────────

    public function test_ghost_mismatch_classifies_observe_ghost(): void
    {
        $report = (new AtlasTaskServingLeaseClaimParityInspector)->inspect(
            [['lease_id' => 'l1', 'task_packet_id' => 'tp-1']],
            [],
        );

        $result = $this->category($report);

        $this->assertSame(AtlasTaskServingLeaseMismatchRepairPlan::CATEGORY_OBSERVE_GHOST, $result['category']);
    }

    // ── AC: blocked/quarantined mismatch ────────────────────────────────────────

    public function test_blocked_or_quarantined_mismatch_classifies_quarantine_review(): void
    {
        $report = (new AtlasTaskServingLeaseClaimParityInspector)->inspect(
            [['lease_id' => 'l1', 'task_packet_id' => 'tp-1']],
            [['task_packet_id' => 'tp-1', 'status' => 'completed']],
        );
        $report['blocked_or_quarantined_task_ids'] = ['tp-1'];

        $result = $this->category($report);

        $this->assertSame(AtlasTaskServingLeaseMismatchRepairPlan::CATEGORY_QUARANTINE_REVIEW, $result['category']);
        $this->assertStringContainsString('never_auto_repair', $result['safety_note']);
    }

    // ── AC: clean parity ─────────────────────────────────────────────────────────

    public function test_clean_parity_classifies_noop(): void
    {
        $report = (new AtlasTaskServingLeaseClaimParityInspector)->inspect(
            [['lease_id' => 'l1', 'task_packet_id' => 'tp-1']],
            [['task_packet_id' => 'tp-1', 'status' => 'claimed']],
        );

        $result = $this->category($report);

        $this->assertSame(AtlasTaskServingLeaseMismatchRepairPlan::CATEGORY_NOOP, $result['category']);
    }

    // ── AC: mixed anomalies — quarantine review always wins ─────────────────────

    public function test_mixed_anomalies_quarantine_review_takes_precedence_over_reap_and_ghost(): void
    {
        $report = (new AtlasTaskServingLeaseClaimParityInspector)->inspect(
            [
                ['lease_id' => 'l1', 'task_packet_id' => 'tp-1'], // recoverable (terminal_with_active_lease)
                ['lease_id' => 'l2', 'task_packet_id' => 'tp-2'], // ghost (lease_without_claim)
            ],
            [['task_packet_id' => 'tp-1', 'status' => 'completed']],
        );
        $report['blocked_or_quarantined_task_ids'] = ['tp-2'];

        $result = $this->category($report);

        $this->assertSame(AtlasTaskServingLeaseMismatchRepairPlan::CATEGORY_QUARANTINE_REVIEW, $result['category']);
    }

    public function test_mixed_recoverable_and_ghost_without_quarantine_prefers_automatic_reap(): void
    {
        $report = (new AtlasTaskServingLeaseClaimParityInspector)->inspect(
            [
                ['lease_id' => 'l1', 'task_packet_id' => 'tp-1'], // recoverable (terminal_with_active_lease)
                ['lease_id' => 'l2', 'task_packet_id' => 'tp-2'], // ghost (lease_without_claim)
            ],
            [['task_packet_id' => 'tp-1', 'status' => 'completed']],
        );

        $result = $this->category($report);

        $this->assertSame(AtlasTaskServingLeaseMismatchRepairPlan::CATEGORY_AUTOMATIC_REAP, $result['category']);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // AC1: lease_mismatch_without_recoverable detection
    // ═══════════════════════════════════════════════════════════════════════

    public function test_active_leases_exceeds_claimed_without_recoverable_detected_as_mismatch(): void
    {
        $report = [
            'active_leases' => 3,
            'claimed_records' => 1,
            'recoverable_candidates' => ['total' => 0],
        ];

        $result = $this->plan()->compile($report);

        $this->assertSame(
            AtlasTaskServingLeaseMismatchRepairPlan::MISMATCH_LEASE_MISMATCH_WITHOUT_RECOVERABLE,
            $result['mismatch_type'],
        );
    }

    public function test_clean_parity_does_not_flag_mismatch(): void
    {
        $report = [
            'active_leases' => 1,
            'claimed_records' => 1,
            'recoverable_candidates' => ['total' => 0],
        ];

        $result = $this->plan()->compile($report);

        $this->assertSame(
            AtlasTaskServingLeaseMismatchRepairPlan::MISMATCH_CLEAN,
            $result['mismatch_type'],
        );
    }

    // ═══════════════════════════════════════════════════════════════════════
    // AC2/AC3/AC4: output contract keys — inspect_target, safe_action, operator_free,
    // expected_health_delta, do_not_create_more_tasks_as_fix, claimable_depth, servable_now
    // ═══════════════════════════════════════════════════════════════════════

    public function test_output_includes_all_new_keys(): void
    {
        $report = [
            'active_leases' => 3,
            'claimed_records' => 1,
            'recoverable_candidates' => ['total' => 0],
            'claimable_depth' => 5,
            'servable_now' => 10,
        ];

        $result = $this->plan()->compile($report);

        $this->assertArrayHasKey('mismatch_type', $result);
        $this->assertArrayHasKey('inspect_target', $result);
        $this->assertArrayHasKey('safe_action', $result);
        $this->assertArrayHasKey('operator_free', $result);
        $this->assertArrayHasKey('expected_health_delta', $result);
        $this->assertArrayHasKey('do_not_create_more_tasks_as_fix', $result);
        $this->assertArrayHasKey('claimable_depth', $result);
        $this->assertArrayHasKey('servable_now', $result);
    }

    public function test_lease_mismatch_without_recoverable_has_inspect_target_and_do_not_create_tasks(): void
    {
        $report = [
            'active_leases' => 3,
            'claimed_records' => 1,
            'recoverable_candidates' => ['total' => 0],
        ];

        $result = $this->plan()->compile($report);

        $this->assertSame('lease_registry', $result['inspect_target']);
        $this->assertTrue($result['do_not_create_more_tasks_as_fix'],
            'lease_mismatch_without_recoverable must flag do_not_create_more_tasks');
    }

    public function test_recoverable_mismatch_has_safe_action_and_operator_free(): void
    {
        $report = [
            'active_leases' => 2,
            'claimed_records' => 0,
            'recoverable_candidates' => ['total' => 1],
        ];

        $result = $this->plan()->compile($report);

        $this->assertSame('atlas:acp:reap-leases', $result['safe_action']);
        $this->assertTrue($result['operator_free'],
            'reap-leases must be operator-free');
    }

    public function test_claim_without_lease_is_not_operator_free(): void
    {
        $report = (new AtlasTaskServingLeaseClaimParityInspector)->inspect(
            [],
            [['task_packet_id' => 'tp-1', 'status' => 'claimed']],
        );

        $result = $this->plan()->compile($report);

        $this->assertFalse($result['operator_free'],
            'investigate_writer must NOT be operator-free');
    }

    public function test_claimable_depth_and_servable_now_preserved_from_input(): void
    {
        $report = [
            'active_leases' => 1,
            'claimed_records' => 1,
            'recoverable_candidates' => ['total' => 0],
            'claimable_depth' => 42,
            'servable_now' => 7,
        ];

        $result = $this->plan()->compile($report);

        $this->assertSame(42, $result['claimable_depth']);
        $this->assertSame(7, $result['servable_now']);
    }
}
