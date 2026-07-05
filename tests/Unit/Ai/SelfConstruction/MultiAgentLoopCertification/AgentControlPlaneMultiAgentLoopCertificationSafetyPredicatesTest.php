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

    public function test_write_set_collision_count_normalizes_backslashes(): void
    {
        self::assertSame(1, AgentControlPlaneMultiAgentLoopCertificationSafetyPredicates::writeSetCollisionCount([
            ['path\\file.php'],
            ['path/file.php'],
        ]));
    }

    public function test_write_set_collision_count_normalizes_leading_slash(): void
    {
        self::assertSame(1, AgentControlPlaneMultiAgentLoopCertificationSafetyPredicates::writeSetCollisionCount([
            ['/file.php'],
            ['file.php'],
        ]));
    }

    public function test_write_set_collision_count_normalizes_dot_slash_prefix(): void
    {
        self::assertSame(1, AgentControlPlaneMultiAgentLoopCertificationSafetyPredicates::writeSetCollisionCount([
            ['./file.php'],
            ['file.php'],
        ]));
    }

    public function test_write_set_collision_count_normalizes_duplicate_slashes(): void
    {
        self::assertSame(1, AgentControlPlaneMultiAgentLoopCertificationSafetyPredicates::writeSetCollisionCount([
            ['path//file.php'],
            ['path/file.php'],
        ]));
    }

    public function test_write_set_collision_count_normalizes_whitespace(): void
    {
        self::assertSame(1, AgentControlPlaneMultiAgentLoopCertificationSafetyPredicates::writeSetCollisionCount([
            ['  file.php  '],
            ['file.php'],
        ]));
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

    // ── AC: evaluateSafety structured evaluation ───────────────────────────

    private function safeSnapshot(): array
    {
        return [
            'queue_state' => [
                'recoverable_depth' => 0,
                'active_workers' => 5,
                'required_workers' => 5,
                'queue_age_hours' => 1.0,
            ],
            'lease_state' => [
                'expected_lease_id' => 'l1',
                'observed_lease_id' => 'l1',
                'claimed_lane' => 'lane-A',
                'allowed_lane' => 'lane-A',
            ],
            'packets' => [
                ['task_packet_id' => 'tp1', 'allowed_files' => ['app/Foo.php'], 'objective' => 'do X'],
            ],
            'write_sets' => [
                ['app/Foo.php'],
                ['app/Bar.php'],
            ],
            'proof_receipt_present' => true,
        ];
    }

    public function test_evaluate_safety_all_pass_for_safe_snapshot(): void
    {
        $result = AgentControlPlaneMultiAgentLoopCertificationSafetyPredicates::evaluateSafety($this->safeSnapshot());

        self::assertTrue($result['continuation_allowed']);
        self::assertSame(0, $result['blocker_count']);
        self::assertSame(0, $result['warning_count']);
        foreach ($result['predicates'] as $predicate) {
            self::assertArrayHasKey('id', $predicate);
            self::assertArrayHasKey('observed', $predicate);
            self::assertArrayHasKey('expected', $predicate);
            self::assertArrayHasKey('severity', $predicate);
            self::assertArrayHasKey('next_repair_action', $predicate);
            self::assertSame('pass', $predicate['severity']);
        }
    }

    public function test_evaluate_safety_blocks_recoverable_backlog(): void
    {
        $snapshot = $this->safeSnapshot();
        $snapshot['queue_state']['recoverable_depth'] = 10;

        $result = AgentControlPlaneMultiAgentLoopCertificationSafetyPredicates::evaluateSafety($snapshot);

        self::assertFalse($result['continuation_allowed']);
        self::assertGreaterThan(0, $result['blocker_count']);
        $found = false;
        foreach ($result['predicates'] as $p) {
            if ($p['id'] === 'no_recoverable_backlog' && $p['severity'] === 'blocker') {
                $found = true;
                self::assertSame('drain_or_repair_recoverable_backlog', $p['next_repair_action']);
            }
        }
        self::assertTrue($found);
    }

    public function test_evaluate_safety_blocks_malformed_packets(): void
    {
        $snapshot = $this->safeSnapshot();
        $snapshot['packets'][] = ['task_packet_id' => '', 'allowed_files' => [], 'objective' => ''];

        $result = AgentControlPlaneMultiAgentLoopCertificationSafetyPredicates::evaluateSafety($snapshot);

        self::assertFalse($result['continuation_allowed']);
        $found = false;
        foreach ($result['predicates'] as $p) {
            if ($p['id'] === 'no_malformed_packets' && $p['severity'] === 'blocker') {
                $found = true;
            }
        }
        self::assertTrue($found);
    }

    public function test_evaluate_safety_blocks_target_collisions(): void
    {
        $snapshot = $this->safeSnapshot();
        $snapshot['write_sets'] = [['app/Foo.php'], ['app/Foo.php']];

        $result = AgentControlPlaneMultiAgentLoopCertificationSafetyPredicates::evaluateSafety($snapshot);

        self::assertFalse($result['continuation_allowed']);
        $found = false;
        foreach ($result['predicates'] as $p) {
            if ($p['id'] === 'no_target_collisions' && $p['severity'] === 'blocker') {
                $found = true;
            }
        }
        self::assertTrue($found);
    }

    public function test_evaluate_safety_blocks_lease_mismatch(): void
    {
        $snapshot = $this->safeSnapshot();
        $snapshot['lease_state']['observed_lease_id'] = 'different-lease';

        $result = AgentControlPlaneMultiAgentLoopCertificationSafetyPredicates::evaluateSafety($snapshot);

        self::assertFalse($result['continuation_allowed']);
        $found = false;
        foreach ($result['predicates'] as $p) {
            if ($p['id'] === 'lease_matches_expected' && $p['severity'] === 'blocker') {
                $found = true;
            }
        }
        self::assertTrue($found);
    }

    public function test_evaluate_safety_blocks_cross_lane_claim(): void
    {
        $snapshot = $this->safeSnapshot();
        $snapshot['lease_state']['claimed_lane'] = 'lane-B';
        $snapshot['lease_state']['allowed_lane'] = 'lane-A';

        $result = AgentControlPlaneMultiAgentLoopCertificationSafetyPredicates::evaluateSafety($snapshot);

        self::assertFalse($result['continuation_allowed']);
        $found = false;
        foreach ($result['predicates'] as $p) {
            if ($p['id'] === 'no_cross_lane_claim' && $p['severity'] === 'blocker') {
                $found = true;
            }
        }
        self::assertTrue($found);
    }

    public function test_evaluate_safety_blocks_missing_proof_receipt(): void
    {
        $snapshot = $this->safeSnapshot();
        $snapshot['proof_receipt_present'] = false;

        $result = AgentControlPlaneMultiAgentLoopCertificationSafetyPredicates::evaluateSafety($snapshot);

        self::assertFalse($result['continuation_allowed']);
        $found = false;
        foreach ($result['predicates'] as $p) {
            if ($p['id'] === 'proof_receipt_present' && $p['severity'] === 'blocker') {
                $found = true;
            }
        }
        self::assertTrue($found);
    }

    public function test_evaluate_safety_warns_on_degraded_worker_pool(): void
    {
        $snapshot = $this->safeSnapshot();
        $snapshot['queue_state']['active_workers'] = 2;
        $snapshot['queue_state']['required_workers'] = 5;

        $result = AgentControlPlaneMultiAgentLoopCertificationSafetyPredicates::evaluateSafety($snapshot);

        self::assertTrue($result['continuation_allowed']);
        self::assertSame(0, $result['blocker_count']);
        self::assertGreaterThan(0, $result['warning_count']);
    }

    public function test_evaluate_safety_warns_on_stale_queue(): void
    {
        $snapshot = $this->safeSnapshot();
        $snapshot['queue_state']['queue_age_hours'] = 48.0;

        $result = AgentControlPlaneMultiAgentLoopCertificationSafetyPredicates::evaluateSafety($snapshot);

        self::assertTrue($result['continuation_allowed']);
        self::assertGreaterThan(0, $result['warning_count']);
    }

    public function test_evaluate_safety_continues_with_warnings_but_not_blockers(): void
    {
        $snapshot = $this->safeSnapshot();
        $snapshot['queue_state']['active_workers'] = 1;
        $snapshot['queue_state']['required_workers'] = 5;
        $snapshot['queue_state']['queue_age_hours'] = 48.0;

        $result = AgentControlPlaneMultiAgentLoopCertificationSafetyPredicates::evaluateSafety($snapshot);

        self::assertTrue($result['continuation_allowed']);
        self::assertSame(0, $result['blocker_count']);
        self::assertSame(2, $result['warning_count']);
    }

    public function test_evaluate_safety_empty_snapshot_blocks_on_missing_proof(): void
    {
        $result = AgentControlPlaneMultiAgentLoopCertificationSafetyPredicates::evaluateSafety([]);

        self::assertFalse($result['continuation_allowed']);
    }

    public function test_evaluate_safety_includes_repair_action_only_on_failure(): void
    {
        $result = AgentControlPlaneMultiAgentLoopCertificationSafetyPredicates::evaluateSafety($this->safeSnapshot());

        foreach ($result['predicates'] as $p) {
            if ($p['severity'] === 'pass') {
                self::assertSame('none', $p['next_repair_action']);
            }
        }
    }
}
