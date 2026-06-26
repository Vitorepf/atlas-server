<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\MultiAgentLoopCertification;

/**
 * Builds the canonical invariant matrix proved by the multi-agent loop cert.
 *
 * Extracted from AgentControlPlaneMultiAgentLoopCertificationService to reduce
 * the god-class. Pure static method — no instance state.
 */
final class AgentControlPlaneMultiAgentLoopCanonicalInvariantMatrixBuilder
{
    /**
     * @param  array<string, bool>  $invariants
     * @param  list<array<string, mixed>>  $cycleEvidence
     * @return array<string, mixed>
     */
    public static function build(array $invariants, array $cycleEvidence, int $targetMin, int $cycles): array
    {
        $autoReplenished = true;
        foreach ($cycleEvidence as $cycle) {
            if ((int) ($cycle['claimable_before_claim'] ?? 0) < $targetMin) {
                $autoReplenished = false;
                break;
            }
        }
        $continuationPresent = AgentControlPlaneMultiAgentLoopCertificationSafetyPredicates::allCyclesTrue(
            array_map(static fn (array $c): array => ['k' => (int) ($c['continuation_summary_count'] ?? 0) > 0], $cycleEvidence),
            'k',
        );
        $staleRecovered = true;
        foreach ($cycleEvidence as $cycle) {
            $r = (array) ($cycle['recovery'] ?? []);
            if (! (bool) ($r['expired_resolved'] ?? false) || ! (bool) ($r['orphan_resolved'] ?? false)) {
                $staleRecovered = false;
                break;
            }
        }
        $completedNotReclaimed = AgentControlPlaneMultiAgentLoopCertificationSafetyPredicates::allCyclesTrue($cycleEvidence, 'reclaim_completed_blocked')
            && (bool) ($invariants['recovery_never_reopens_completed'] ?? false);

        $matrix = [
            'no_duplicate_claims' => [
                'value' => (bool) ($invariants['no_duplicate_claims'] ?? false),
                'why' => 'Each cycle attempts a duplicate claim against a live lease and asserts the repository returns status=error with reason=task_already_claimed|write_set_overlap.',
            ],
            'no_cross_agent_completion' => [
                'value' => (bool) ($invariants['no_cross_agent_completion'] ?? false),
                'why' => 'Each cycle invokes completeDryRun with agent A packet + agent B lease and asserts event=complete_dry_run_blocked, reason=task_packet_lease_mismatch.',
            ],
            'complete_dry_run_requires_queue_claim_binding' => [
                'value' => (bool) ($invariants['complete_dry_run_requires_queue_claim_binding'] ?? false),
                'why' => 'Every completed_dry_run packet proves the queue record was still claimed and its metadata.lease_id/metadata.agent_id matched the active lease before completion.',
            ],
            'stale_lease_recovered' => [
                'value' => $staleRecovered,
                'why' => 'Each cycle injects an expired+orphaned lease through the recovery probe and asserts expired_resolved=true AND orphan_resolved=true.',
            ],
            'completed_task_not_reclaimed' => [
                'value' => $completedNotReclaimed,
                'why' => 'Every completed_dry_run packet stays in terminal state; the recheck claimNext never re-acquires a completed packet.',
            ],
            'auto_replenishment_target_met' => [
                'value' => $autoReplenished,
                'why' => sprintf('Each of the %d cycle(s) starts with claimable_before_claim >= target_min_claimable_tasks (%d).', $cycles, $targetMin),
            ],
            'continuation_summary_present' => [
                'value' => $continuationPresent,
                'why' => 'Each cycle exposes a non-empty continuation_summary_count from the orchestrator continuation builder.',
            ],
            'evidence_hash_present' => [
                'value' => (bool) ($invariants['evidence_hash_present'] ?? false),
                'why' => 'Every completed_dry_run receipt persists an evidence_digest sha256 plus the claim/lease/release receipt trail.',
            ],
            'structured_completion_evidence_valid' => [
                'value' => (bool) ($invariants['structured_completion_evidence_valid'] ?? false),
                'why' => 'Every completed_dry_run call includes a structured completion evidence payload with files_changed, commands_run, tests_or_gates_result, git_status_short, git_diff_check_result and validation hash.',
            ],
            'completion_evidence_files_within_scope' => [
                'value' => (bool) ($invariants['completion_evidence_files_within_scope'] ?? false)
                    && (bool) ($invariants['terminal_worker_bootstrap_completion_evidence_files_within_scope'] ?? false),
                'why' => 'Every completed_dry_run evidence payload has files_changed constrained to the claimed task packet normalized allowed_files/write_set; scope escapes are blocked before lease release.',
            ],
            'worker_completion_evidence_template_present' => [
                'value' => (bool) ($invariants['terminal_worker_bootstrap_completion_evidence_template_present'] ?? false),
                'why' => 'Terminal bootstrap one-shot packets include the machine-readable completion evidence JSON template required by complete-dry-run.',
            ],
            'worker_operator_loop_commands_present' => [
                'value' => (bool) ($invariants['terminal_worker_bootstrap_operator_commands_present'] ?? false),
                'why' => 'Terminal bootstrap exposes copy-paste operator commands for claim/replenish, renew, complete, recover/resume, and continue-after-completion.',
            ],
            'runtime_safety_all_false' => [
                'value' => (bool) ($invariants['runtime_safety_all_false'] ?? false),
                'why' => 'Queue + lease repositories advertise every runtime flag (execution/dispatch/provider/token/self_programming/ledger/completion_real) as false.',
            ],
            'queue_transition_policy_enforced' => [
                'value' => (bool) ($invariants['queue_transition_policy_enforced'] ?? false),
                'why' => 'AgentControlPlaneTaskPacketQueueRepository::ALLOWED_STATUS_TRANSITIONS exposes a non-empty deterministic policy map.',
            ],
            'worker_resumption_contract_present' => [
                'value' => (bool) ($invariants['terminal_worker_bootstrap_resumption_contract_present'] ?? false),
                'why' => 'Terminal bootstrap one-shot packets include an explicit resumption contract with task-lease recovery command, fresh-bootstrap command and active-lease requirement.',
            ],
            'worker_resumption_checkpoint_present' => [
                'value' => (bool) ($invariants['terminal_worker_bootstrap_resumption_checkpoint_present'] ?? false),
                'why' => 'Terminal bootstrap one-shot packets include a hash-addressed resumption checkpoint that names the current step, active task/lease, exact resume commands and chat-history-free continuation guarantee.',
            ],
            'worker_iteration_runbook_present' => [
                'value' => (bool) ($invariants['terminal_worker_bootstrap_iteration_runbook_present'] ?? false),
                'why' => 'Terminal bootstrap one-shot packets include a hash-addressed six-step iteration runbook for recover, execute prompt, renew lease, write evidence, complete dry-run and claim next without chat history.',
            ],
            'worker_shell_recipe_present' => [
                'value' => (bool) ($invariants['terminal_worker_bootstrap_shell_recipe_present'] ?? false),
                'why' => 'Terminal bootstrap one-shot packets include a hash-addressed read-only shell recipe with bootstrap/recover/renew/complete commands, copy-paste loop skeleton and manual evidence slots.',
            ],
            'terminal_loop_health_digest_present' => [
                'value' => (bool) ($invariants['terminal_loop_health_digest_present'] ?? false),
                'why' => 'The read-only terminal loop health digest is wired so operators can inspect supply, stale leases and next commands before running long-lived terminal fleets.',
            ],
            'terminal_loop_fleet_launch_plan_present' => [
                'value' => (bool) ($invariants['terminal_loop_fleet_launch_plan_present'] ?? false),
                'why' => 'The read-only terminal loop health digest emits a fleet launch plan with distinct terminal actors, copy-paste bootstrap commands, start blockers and observability commands without claiming or executing work.',
            ],
            'terminal_loop_fleet_launch_plan_ready_path_verified' => [
                'value' => (bool) ($invariants['terminal_loop_fleet_launch_plan_ready_path_verified'] ?? false),
                'why' => 'The certification seeds a tagged claimable lane, asks the health digest for a fleet plan, and proves it recommends distinct terminal actors with lane-bound bootstrap commands while remaining unable to claim or execute from the digest.',
            ],
            'terminal_loop_fleet_partial_supply_launch_blocked' => [
                'value' => (bool) ($invariants['terminal_loop_fleet_partial_supply_launch_blocked'] ?? false),
                'why' => 'The certification seeds a partially supplied tagged lane, requests a larger target, and proves the health digest blocks fleet launch, preserves zero terminal assignments and routes the cycle supervisor to replenishment before any worker start.',
            ],
            'terminal_loop_fleet_replenishment_plan_present' => [
                'value' => (bool) ($invariants['terminal_loop_fleet_replenishment_plan_present'] ?? false),
                'why' => 'The read-only terminal loop health digest emits a fleet replenishment plan with exact shortage counts, replenish/recheck/start sequence and non-execution guarantees so a fleet lane can be refilled before workers claim.',
            ],
            'terminal_loop_fleet_resume_rollup_present' => [
                'value' => (bool) ($invariants['terminal_loop_fleet_resume_rollup_present'] ?? false),
                'why' => 'The read-only terminal loop health digest emits a fleet resume rollup that summarizes active and recoverable task leases, exact recovery commands and fresh-claim requirements without mutating leases or queue records.',
            ],
            'terminal_loop_fleet_resume_recovery_path_verified' => [
                'value' => (bool) ($invariants['terminal_loop_fleet_resume_recovery_path_verified'] ?? false),
                'why' => 'The certification creates an orphaned claimed packet, asks the digest for resume guidance, and proves it reports fleet_resume_rollup_recovery_required with a packet-scoped recovery command while remaining unable to recover or claim from the rollup.',
            ],
            'terminal_loop_fleet_metadata_orphan_recovery_verified' => [
                'value' => (bool) ($invariants['terminal_loop_fleet_metadata_orphan_recovery_verified'] ?? false),
                'why' => 'The certification creates a claimed packet whose lease metadata is missing, proves the health digest classifies it as a recoverable orphan with a packet-scoped recovery command, then runs task-lease recovery to return only that packet to claimable with a recovery receipt.',
            ],
            'terminal_loop_fleet_released_task_requeue_verified' => [
                'value' => (bool) ($invariants['terminal_loop_fleet_released_task_requeue_verified'] ?? false),
                'why' => 'The certification creates a released non-terminal packet, proves the health digest classifies it as recoverable_released_task, then runs task-lease recovery to move it back to claimable with a released_task_requeued receipt.',
            ],
            'terminal_loop_fleet_evidence_rollup_present' => [
                'value' => (bool) ($invariants['terminal_loop_fleet_evidence_rollup_present'] ?? false),
                'why' => 'The read-only terminal loop health digest emits a fleet evidence rollup that summarizes completed dry-run packets, valid completion evidence, missing receipts and operator-review readiness without writing receipts or promoting completion.',
            ],
            'terminal_loop_fleet_evidence_rollup_green_path_verified' => [
                'value' => (bool) ($invariants['terminal_loop_fleet_evidence_rollup_green_path_verified'] ?? false),
                'why' => 'The certification seeds, claims and completes one tagged dry-run packet with structured evidence, then proves the health digest rollup reports fleet_evidence_rollup_green and ready_for_operator_review without writing receipts from the digest.',
            ],
            'terminal_loop_fleet_operator_handoff_present' => [
                'value' => (bool) ($invariants['terminal_loop_fleet_operator_handoff_present'] ?? false),
                'why' => 'The read-only terminal loop health digest emits a unified fleet operator handoff that prioritizes recovery, replenishment, launch and evidence review with copy-paste commands while executing none of them.',
            ],
            'terminal_loop_fleet_operator_handoff_recovery_priority_verified' => [
                'value' => (bool) ($invariants['terminal_loop_fleet_operator_handoff_recovery_priority_verified'] ?? false),
                'why' => 'The resume-rollup probe also proves the unified handoff chooses recovery before replenish/launch when an orphaned claimed packet is present.',
            ],
            'terminal_loop_fleet_lane_isolation_present' => [
                'value' => (bool) ($invariants['terminal_loop_fleet_lane_isolation_present'] ?? false),
                'why' => 'The read-only terminal loop health digest emits a fleet lane-isolation block that checks all copy-paste commands preserve requested queue tags.',
            ],
            'terminal_loop_fleet_lane_bound_commands_verified' => [
                'value' => (bool) ($invariants['terminal_loop_fleet_lane_bound_commands_verified'] ?? false),
                'why' => 'The fleet-launch probe seeds a tagged lane and proves bootstrap, replenishment, health and per-terminal commands remain bound to that queue tag.',
            ],
            'terminal_loop_fleet_lane_no_cross_lane_launch_verified' => [
                'value' => (bool) ($invariants['terminal_loop_fleet_lane_no_cross_lane_launch_verified'] ?? false),
                'why' => 'The negative lane-isolation probe seeds claimable work in a different lane, requests an empty target lane, and proves launch stays blocked while handoff recommends replenishing the requested lane instead of stealing unrelated work.',
            ],
            'terminal_loop_cycle_supervisor_present' => [
                'value' => (bool) ($invariants['terminal_loop_cycle_supervisor_present'] ?? false),
                'why' => 'The read-only terminal loop health digest emits a cycle supervisor that collapses recovery, replenishment, launch, evidence review and wait/inspect into one exact next state and command without executing it.',
            ],
            'terminal_loop_cycle_supervisor_launch_path_verified' => [
                'value' => (bool) ($invariants['terminal_loop_cycle_supervisor_launch_path_verified'] ?? false),
                'why' => 'The fleet-launch probe proves the cycle supervisor selects launch_or_continue_workers, emits a lane-bound bootstrap command and keeps execute/claim flags false when claimable supply is ready.',
            ],
            'terminal_loop_cycle_supervisor_evidence_review_path_verified' => [
                'value' => (bool) ($invariants['terminal_loop_cycle_supervisor_evidence_review_path_verified'] ?? false),
                'why' => 'The evidence-rollup probe proves the cycle supervisor selects review_evidence before more replenishment when completed dry-run evidence is ready for operator review.',
            ],
            'terminal_loop_fleet_launch_runbook_present' => [
                'value' => (bool) ($invariants['terminal_loop_fleet_launch_runbook_present'] ?? false),
                'why' => 'The read-only terminal loop health digest emits a fleet launch runbook with numbered terminal steps, copy-paste commands, post-launch observability commands, interruption-resume instructions and non-execution flags.',
            ],
            'terminal_loop_fleet_launch_runbook_ready_path_verified' => [
                'value' => (bool) ($invariants['terminal_loop_fleet_launch_runbook_ready_path_verified'] ?? false),
                'why' => 'The fleet-launch probe proves the runbook becomes ready on a worker-eligible tagged lane, carries one lane-bound command per recommended terminal, requires a fresh health digest before more terminals, and cannot execute or start terminals from the runbook.',
            ],
            'certification_cleanup_leaves_no_recoverable_terminal_loop_artifacts' => [
                'value' => (bool) ($invariants['certification_cleanup_leaves_no_recoverable_terminal_loop_artifacts'] ?? false),
                'why' => 'After pruning the run-scoped synthetic packets and leases, the certification asks the read-only terminal-loop health digest to prove there are zero claimed packets, zero active leases and zero recoverable lease/task artifacts left by the run.',
            ],
            'worker_invalid_scope_rejected' => [
                'value' => (bool) ($invariants['terminal_worker_bootstrap_rejects_invalid_worker_scope'] ?? false),
                'why' => 'Terminal bootstrap probes an empty allowed_files worker packet and requires blocked status plus lease release before handoff.',
            ],
            'worker_bootstrap_preview_read_only' => [
                'value' => (bool) ($invariants['terminal_worker_bootstrap_preview_read_only'] ?? false),
                'why' => 'Terminal bootstrap preview mode proves operators can inspect claimable supply and the execute command without auto-replenishing, claiming a lease or mutating the queue.',
            ],
            'worker_bootstrap_partial_supply_blocks_before_claim' => [
                'value' => (bool) ($invariants['terminal_worker_bootstrap_partial_supply_blocks_before_claim'] ?? false),
                'why' => 'Terminal bootstrap probes a partially supplied lane with no new replenishment available and proves the command blocks before claim/lease, preserving the claimable packet for replenishment-first launch.',
            ],
            'safe_for_parallel_terminal_loop' => [
                'value' => AgentControlPlaneMultiAgentLoopCertificationSafetyPredicates::safeForParallelTerminalLoop($invariants, $cycleEvidence),
                'why' => 'All other canonical invariants hold, no write-set collision was observed, and no legacy reservation ledger was used.',
            ],
        ];

        $violations = [];
        foreach ($matrix as $name => $entry) {
            if ($entry['value'] !== true) {
                $violations[] = $name;
            }
        }

        return [
            'invariants' => $matrix,
            'violations' => $violations,
            'all_true' => $violations === [],
            'invariant_names' => array_keys($matrix),
        ];
    }
}
