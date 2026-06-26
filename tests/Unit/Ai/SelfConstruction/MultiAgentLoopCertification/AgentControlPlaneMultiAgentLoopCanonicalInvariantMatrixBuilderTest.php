<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\MultiAgentLoopCertification;

use App\Services\Ai\SelfConstruction\MultiAgentLoopCertification\AgentControlPlaneMultiAgentLoopCanonicalInvariantMatrixBuilder;
use Tests\TestCase;

class AgentControlPlaneMultiAgentLoopCanonicalInvariantMatrixBuilderTest extends TestCase
{
    public function test_build_returns_expected_structure(): void
    {
        $result = AgentControlPlaneMultiAgentLoopCanonicalInvariantMatrixBuilder::build([], [], 1, 0);

        self::assertArrayHasKey('invariants', $result);
        self::assertArrayHasKey('violations', $result);
        self::assertArrayHasKey('all_true', $result);
        self::assertArrayHasKey('invariant_names', $result);
        self::assertIsArray($result['invariants']);
        self::assertIsArray($result['violations']);
        self::assertIsBool($result['all_true']);
        self::assertIsArray($result['invariant_names']);
    }

    public function test_each_invariant_entry_has_value_and_why(): void
    {
        $result = AgentControlPlaneMultiAgentLoopCanonicalInvariantMatrixBuilder::build([], [], 1, 0);

        foreach ($result['invariants'] as $name => $entry) {
            self::assertArrayHasKey('value', $entry, "{$name} must have value");
            self::assertArrayHasKey('why', $entry, "{$name} must have why");
            self::assertIsBool($entry['value']);
            self::assertIsString($entry['why']);
            self::assertNotEmpty($entry['why']);
        }
    }

    public function test_all_true_when_all_invariants_hold_and_cycles_clean(): void
    {
        $coreKeys = [
            'no_duplicate_claims', 'no_cross_agent_completion', 'complete_dry_run_requires_queue_claim_binding',
            'recovery_never_reopens_completed', 'no_legacy_reservation_used', 'runtime_safety_all_false',
            'queue_transition_policy_enforced', 'terminal_worker_bootstrap_resumption_contract_present',
            'terminal_worker_bootstrap_resumption_checkpoint_present', 'terminal_worker_bootstrap_iteration_runbook_present',
            'terminal_worker_bootstrap_shell_recipe_present', 'terminal_loop_health_digest_present',
            'terminal_loop_fleet_launch_plan_present', 'terminal_loop_fleet_launch_plan_ready_path_verified',
            'terminal_loop_fleet_partial_supply_launch_blocked', 'terminal_loop_fleet_replenishment_plan_present',
            'terminal_loop_fleet_resume_rollup_present', 'terminal_loop_fleet_resume_recovery_path_verified',
            'terminal_loop_fleet_metadata_orphan_recovery_verified', 'terminal_loop_fleet_released_task_requeue_verified',
            'terminal_loop_fleet_evidence_rollup_present', 'terminal_loop_fleet_evidence_rollup_green_path_verified',
            'terminal_loop_fleet_operator_handoff_present', 'terminal_loop_fleet_operator_handoff_recovery_priority_verified',
            'terminal_loop_fleet_lane_isolation_present', 'terminal_loop_fleet_lane_bound_commands_verified',
            'terminal_loop_fleet_lane_no_cross_lane_launch_verified', 'terminal_loop_cycle_supervisor_present',
            'terminal_loop_cycle_supervisor_launch_path_verified', 'terminal_loop_cycle_supervisor_evidence_review_path_verified',
            'terminal_loop_fleet_launch_runbook_present', 'terminal_loop_fleet_launch_runbook_ready_path_verified',
            'certification_cleanup_leaves_no_recoverable_terminal_loop_artifacts',
            'terminal_worker_bootstrap_rejects_invalid_worker_scope',
            'terminal_worker_bootstrap_completion_evidence_template_present',
            'completion_evidence_files_within_scope',
            'terminal_worker_bootstrap_completion_evidence_files_within_scope',
            'terminal_worker_bootstrap_operator_commands_present',
            'terminal_worker_bootstrap_preview_read_only',
            'terminal_worker_bootstrap_partial_supply_blocks_before_claim',
            'evidence_hash_present',
            'structured_completion_evidence_valid',
        ];
        $invariants = array_fill_keys($coreKeys, true);

        $cycleEvidence = [
            [
                'claimable_before_claim' => 2,
                'continuation_summary_count' => 1,
                'recovery' => ['expired_resolved' => true, 'orphan_resolved' => true],
                'reclaim_completed_blocked' => true,
                'write_set_collision_count' => 0,
                'agents' => [['id' => 1], ['id' => 2]],
                'distinct_task_count' => 2,
                'distinct_lease_count' => 2,
            ],
        ];

        $result = AgentControlPlaneMultiAgentLoopCanonicalInvariantMatrixBuilder::build($invariants, $cycleEvidence, 2, 1);

        self::assertTrue($result['all_true']);
        self::assertSame([], $result['violations']);
    }

    public function test_auto_replenishment_false_when_claimable_below_target(): void
    {
        $cycleEvidence = [
            ['claimable_before_claim' => 0],
        ];

        $result = AgentControlPlaneMultiAgentLoopCanonicalInvariantMatrixBuilder::build([], $cycleEvidence, 2, 1);

        self::assertFalse($result['invariants']['auto_replenishment_target_met']['value']);
        self::assertContains('auto_replenishment_target_met', $result['violations']);
    }

    public function test_stale_lease_recovered_false_when_recovery_unresolved(): void
    {
        $cycleEvidence = [
            ['recovery' => ['expired_resolved' => false, 'orphan_resolved' => true]],
        ];

        $result = AgentControlPlaneMultiAgentLoopCanonicalInvariantMatrixBuilder::build([], $cycleEvidence, 1, 1);

        self::assertFalse($result['invariants']['stale_lease_recovered']['value']);
    }

    public function test_continuation_summary_present_false_when_missing(): void
    {
        $cycleEvidence = [
            ['continuation_summary_count' => 0],
        ];

        $result = AgentControlPlaneMultiAgentLoopCanonicalInvariantMatrixBuilder::build([], $cycleEvidence, 1, 1);

        self::assertFalse($result['invariants']['continuation_summary_present']['value']);
    }

    public function test_violations_list_contains_all_false_invariants(): void
    {
        $result = AgentControlPlaneMultiAgentLoopCanonicalInvariantMatrixBuilder::build([], [], 1, 0);

        self::assertNotEmpty($result['violations']);
        self::assertContains('no_duplicate_claims', $result['violations']);
    }

    public function test_invariant_names_match_matrix_keys(): void
    {
        $result = AgentControlPlaneMultiAgentLoopCanonicalInvariantMatrixBuilder::build([], [], 1, 0);

        self::assertSame(array_keys($result['invariants']), $result['invariant_names']);
    }

    public function test_build_is_deterministic(): void
    {
        $invariants = ['evidence_hash_present' => true, 'structured_completion_evidence_valid' => true];
        $cycles = [
            ['claimable_before_claim' => 3, 'continuation_summary_count' => 1, 'recovery' => ['expired_resolved' => true, 'orphan_resolved' => true], 'reclaim_completed_blocked' => true],
        ];

        $a = AgentControlPlaneMultiAgentLoopCanonicalInvariantMatrixBuilder::build($invariants, $cycles, 2, 1);
        $b = AgentControlPlaneMultiAgentLoopCanonicalInvariantMatrixBuilder::build($invariants, $cycles, 2, 1);

        self::assertSame($a, $b);
    }

    public function test_auto_replenishment_why_includes_cycle_and_target(): void
    {
        $result = AgentControlPlaneMultiAgentLoopCanonicalInvariantMatrixBuilder::build([], [], 3, 2);

        $why = $result['invariants']['auto_replenishment_target_met']['why'];
        self::assertStringContainsString('2', $why);
        self::assertStringContainsString('3', $why);
    }
}
