<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\MultiAgentLoopCertification;

use App\Services\Ai\SelfConstruction\MultiAgentLoopCertification\AgentControlPlaneMultiAgentLoopCertificationSafetyPredicates;
use Tests\TestCase;

class AgentControlPlaneMultiAgentLoopCertificationSafetyPredicatesTest extends TestCase
{
    // ---- flattenStrings ----

    public function test_flatten_strings_handles_plain_string(): void
    {
        self::assertSame(['hello'], AgentControlPlaneMultiAgentLoopCertificationSafetyPredicates::flattenStrings('hello'));
    }

    public function test_flatten_strings_handles_empty_string(): void
    {
        self::assertSame([], AgentControlPlaneMultiAgentLoopCertificationSafetyPredicates::flattenStrings(''));
    }

    public function test_flatten_strings_handles_flat_array(): void
    {
        self::assertSame(['a', 'b', 'c'], AgentControlPlaneMultiAgentLoopCertificationSafetyPredicates::flattenStrings(['a', 'b', 'c']));
    }

    public function test_flatten_strings_handles_nested_array(): void
    {
        self::assertSame(['a', 'b', 'c'], AgentControlPlaneMultiAgentLoopCertificationSafetyPredicates::flattenStrings(['a', ['b', ['c']]]));
    }

    public function test_flatten_strings_deduplicates(): void
    {
        self::assertSame(['a', 'b'], AgentControlPlaneMultiAgentLoopCertificationSafetyPredicates::flattenStrings(['a', 'a', ['b', 'a']]));
    }

    public function test_flatten_strings_returns_empty_for_non_string_non_array(): void
    {
        self::assertSame([], AgentControlPlaneMultiAgentLoopCertificationSafetyPredicates::flattenStrings(42));
        self::assertSame([], AgentControlPlaneMultiAgentLoopCertificationSafetyPredicates::flattenStrings(null));
    }

    // ---- writeSetCollisionCount ----

    public function test_write_set_collision_count_zero_for_no_overlaps(): void
    {
        self::assertSame(0, AgentControlPlaneMultiAgentLoopCertificationSafetyPredicates::writeSetCollisionCount([
            ['file_a.php', 'file_b.php'],
            ['file_c.php'],
        ]));
    }

    public function test_write_set_collision_count_detects_overlap(): void
    {
        self::assertSame(1, AgentControlPlaneMultiAgentLoopCertificationSafetyPredicates::writeSetCollisionCount([
            ['file_a.php', 'file_b.php'],
            ['file_b.php', 'file_c.php'],
        ]));
    }

    public function test_write_set_collision_count_handles_empty(): void
    {
        self::assertSame(0, AgentControlPlaneMultiAgentLoopCertificationSafetyPredicates::writeSetCollisionCount([]));
    }

    // ---- terminalBootstrapRuntimeSafety ----

    public function test_terminal_bootstrap_runtime_safety_all_false(): void
    {
        $results = [
            ['runtime_execution_allowed' => false, 'dispatch_allowed' => false, 'provider_call_allowed' => false, 'token_spend_allowed' => false, 'self_programming_allowed' => false],
        ];

        self::assertTrue(AgentControlPlaneMultiAgentLoopCertificationSafetyPredicates::terminalBootstrapRuntimeSafety($results));
    }

    public function test_terminal_bootstrap_runtime_safety_detects_violation(): void
    {
        $results = [
            ['runtime_execution_allowed' => false, 'dispatch_allowed' => true, 'provider_call_allowed' => false, 'token_spend_allowed' => false, 'self_programming_allowed' => false],
        ];

        self::assertFalse(AgentControlPlaneMultiAgentLoopCertificationSafetyPredicates::terminalBootstrapRuntimeSafety($results));
    }

    public function test_terminal_bootstrap_runtime_safety_defaults_to_true_on_missing(): void
    {
        $results = [[]];

        self::assertFalse(AgentControlPlaneMultiAgentLoopCertificationSafetyPredicates::terminalBootstrapRuntimeSafety($results));
    }

    // ---- confirmCompletedNeverReclaimed ----

    public function test_confirm_completed_never_reclaimed_all_blocked(): void
    {
        self::assertTrue(AgentControlPlaneMultiAgentLoopCertificationSafetyPredicates::confirmCompletedNeverReclaimed([
            ['reclaim_completed_blocked' => true],
            ['reclaim_completed_blocked' => true],
        ]));
    }

    public function test_confirm_completed_never_reclaimed_detects_unblocked(): void
    {
        self::assertFalse(AgentControlPlaneMultiAgentLoopCertificationSafetyPredicates::confirmCompletedNeverReclaimed([
            ['reclaim_completed_blocked' => true],
            ['reclaim_completed_blocked' => false],
        ]));
    }

    // ---- confirmNoLegacyReservationUsed ----

    public function test_confirm_no_legacy_reservation_all_valid(): void
    {
        self::assertTrue(AgentControlPlaneMultiAgentLoopCertificationSafetyPredicates::confirmNoLegacyReservationUsed([
            ['agents' => [['claim_event' => 'claimed'], ['claim_event' => 'no_claimable_task']]],
        ]));
    }

    public function test_confirm_no_legacy_reservation_detects_invalid(): void
    {
        self::assertFalse(AgentControlPlaneMultiAgentLoopCertificationSafetyPredicates::confirmNoLegacyReservationUsed([
            ['agents' => [['claim_event' => 'legacy_reservation']]],
        ]));
    }

    // ---- runtimeSafetyAllFalse ----

    public function test_runtime_safety_all_false_passes(): void
    {
        $flags = [
            'runtime_execution_allowed' => false,
            'dispatch_allowed' => false,
            'provider_call_allowed' => false,
            'token_spend_allowed' => false,
            'self_programming_allowed' => false,
            'ledger_write_allowed' => false,
            'completion_real_allowed' => false,
        ];

        self::assertTrue(AgentControlPlaneMultiAgentLoopCertificationSafetyPredicates::runtimeSafetyAllFalse($flags, $flags));
    }

    public function test_runtime_safety_all_false_detects_true(): void
    {
        $safe = ['runtime_execution_allowed' => false, 'dispatch_allowed' => false, 'provider_call_allowed' => false, 'token_spend_allowed' => false, 'self_programming_allowed' => false, 'ledger_write_allowed' => false, 'completion_real_allowed' => false];
        $unsafe = ['dispatch_allowed' => true, 'runtime_execution_allowed' => false, 'provider_call_allowed' => false, 'token_spend_allowed' => false, 'self_programming_allowed' => false, 'ledger_write_allowed' => false, 'completion_real_allowed' => false];

        self::assertFalse(AgentControlPlaneMultiAgentLoopCertificationSafetyPredicates::runtimeSafetyAllFalse($safe, $unsafe));
    }

    // ---- allCyclesTrue ----

    public function test_all_cycles_true_passes(): void
    {
        self::assertTrue(AgentControlPlaneMultiAgentLoopCertificationSafetyPredicates::allCyclesTrue([
            ['foo' => true],
            ['foo' => true],
        ], 'foo'));
    }

    public function test_all_cycles_true_detects_false(): void
    {
        self::assertFalse(AgentControlPlaneMultiAgentLoopCertificationSafetyPredicates::allCyclesTrue([
            ['foo' => true],
            ['foo' => false],
        ], 'foo'));
    }

    public function test_all_cycles_true_returns_false_for_empty(): void
    {
        self::assertFalse(AgentControlPlaneMultiAgentLoopCertificationSafetyPredicates::allCyclesTrue([], 'foo'));
    }

    public function test_all_cycles_true_skips_missing_key(): void
    {
        self::assertTrue(AgentControlPlaneMultiAgentLoopCertificationSafetyPredicates::allCyclesTrue([
            ['foo' => true],
            ['bar' => true],
        ], 'foo'));
    }

    // ---- safeForParallelTerminalLoop ----

    public function test_safe_for_parallel_returns_false_when_invariant_missing(): void
    {
        $result = AgentControlPlaneMultiAgentLoopCertificationSafetyPredicates::safeForParallelTerminalLoop([], []);

        self::assertFalse($result);
    }

    public function test_safe_for_parallel_returns_false_when_collision(): void
    {
        $invariants = array_fill_keys([
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
            'terminal_worker_bootstrap_operator_commands_present',
            'terminal_worker_bootstrap_preview_read_only',
            'terminal_worker_bootstrap_partial_supply_blocks_before_claim',
            'evidence_hash_present',
        ], true);

        $cycleEvidence = [
            ['write_set_collision_count' => 1, 'agents' => [], 'distinct_task_count' => 0, 'distinct_lease_count' => 0],
        ];

        self::assertFalse(AgentControlPlaneMultiAgentLoopCertificationSafetyPredicates::safeForParallelTerminalLoop($invariants, $cycleEvidence));
    }

    public function test_safe_for_parallel_passes_when_all_invariants_and_cycles_clean(): void
    {
        $core = [
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
            'terminal_worker_bootstrap_operator_commands_present',
            'terminal_worker_bootstrap_preview_read_only',
            'terminal_worker_bootstrap_partial_supply_blocks_before_claim',
            'evidence_hash_present',
        ];
        $invariants = array_fill_keys($core, true);

        $cycleEvidence = [
            [
                'write_set_collision_count' => 0,
                'agents' => [['id' => 1], ['id' => 2]],
                'distinct_task_count' => 2,
                'distinct_lease_count' => 2,
            ],
        ];

        self::assertTrue(AgentControlPlaneMultiAgentLoopCertificationSafetyPredicates::safeForParallelTerminalLoop($invariants, $cycleEvidence));
    }
}
