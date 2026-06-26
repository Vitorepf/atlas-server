<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\TerminalWorkerBootstrap;

use App\Services\Ai\SelfConstruction\TerminalWorkerBootstrap\AgentControlPlaneTerminalWorkerCommandFormatter;

/**
 * ITEM8 — the cohesive read-only terminal-loop guidance-artifact builder concern the bootstrap
 * service uses to assemble the operator-commands / queue-lane / resumption-checkpoint / iteration
 * runbook / shell recipe / current-step artefacts a long-running terminal worker needs.
 *
 * Seven methods migrated verbatim from
 * {@see \App\Services\Ai\SelfConstruction\AgentControlPlaneTerminalWorkerBootstrapService}:
 *  - {@see self::terminalLoopOperatorCommands}: the wide operator-commands artefact (claim / renew /
 *    complete / recover / next / inspect queue / inspect leases / long-running-loop contract /
 *    operator-loop contract).
 *  - {@see self::queueLaneContract}: per-terminal-lane contract derived from the actor + queue-tag
 *    list + bootstrap command (with a stable hash for audit traceability).
 *  - {@see self::terminalLoopResumptionCheckpoint}: the resumable-state checkpoint (current step +
 *    resume commands + required evidence + forbidden shortcuts + next operator action).
 *  - {@see self::terminalLoopIterationRunbook}: the per-iteration runbook (steps + proof commands
 *    + invariants + non-execution guarantees + stable hash).
 *  - {@see self::iterationStep}: one step in the runbook (id / phase / command / operator-action /
 *    produces / can-run-automatically).
 *  - {@see self::terminalLoopShellRecipe}: the copy-paste shell loop skeleton with manual slots +
 *    loop invariants + forbidden shortcuts + non-execution guarantees.
 *  - {@see self::terminalLoopCurrentStep}: derive the human-readable current step from the status
 *    triplet (status / claimEvent / oneShotWorkerPacketReady / previewOnly).
 *
 * The `commandValue` / `queueTagArgs` / `recommendedQueueTag` dependencies from the god-class are
 * shared via a constructor-injected {@see AgentControlPlaneTerminalWorkerCommandFormatter}; the
 * SHA-256 receipt hashing lives on this class (private) to avoid cross-class coupling. Pure / stateless /
 * zero Laravel surface (data_get for safe nested reads). All seven schemas / fields / hashes are
 * preserved verbatim so the byte-identical guidance contract survives the split.
 */
class AgentControlPlaneTerminalLoopGuidanceBuilder
{
    public function __construct(
        private readonly ?AgentControlPlaneTerminalWorkerCommandFormatter $commandFormatter = null,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function terminalLoopOperatorCommands(
        string $actor,
        string $taskPacketId,
        string $leaseId,
        string $completionCommand,
        string $leaseRenewCommand,
        string $recoveryCommand,
        string $bootstrapCommand,
        int $targetMin,
        int $maxNew,
        array $queueTags,
        array $queueLaneContract,
    ): array {
        return [
            'schema_version' => 'atlas.self_construction.agent_control_plane_terminal_loop_operator_commands.v1',
            'actor' => $actor,
            'target_min_claimable_tasks' => $targetMin,
            'max_new_tasks' => $maxNew,
            'queue_tags' => $queueTags,
            'queue_lane_contract' => $queueLaneContract,
            'task_packet_id' => $taskPacketId,
            'lease_id' => $leaseId,
            'claim_or_replenish_next' => $bootstrapCommand,
            'renew_current_lease' => $leaseRenewCommand,
            'complete_current_dry_run' => $completionCommand,
            'recover_or_resume_current_packet' => $recoveryCommand,
            'next_after_completion' => $bootstrapCommand,
            'inspect_queue' => 'php artisan atlas:ai:self-construction --agent-control-plane-task-queue-status --json',
            'inspect_active_leases' => 'php artisan atlas:ai:self-construction --agent-control-plane-task-lease-recovery-status --actor='.$actor.$this->commandFormatter()->queueTagArgs($queueTags).' --json',
            'completion_evidence_template_path' => '/path/to/completion-evidence.json',
            'long_running_loop_contract' => [
                'schema_version' => 'atlas.self_construction.agent_control_plane_terminal_long_running_loop_contract.v1',
                'lease_renewal_cadence_seconds' => 600,
                'renew_before_seconds_remaining' => 600,
                'max_single_packet_minutes' => 240,
                'next_iteration_command' => $bootstrapCommand,
                'next_iteration_preserves_queue_tags' => true,
                'next_iteration_preserves_queue_lane' => (bool) ($queueLaneContract['next_iteration_preserves_queue_lane'] ?? false),
                'queue_lane_explicit' => (bool) ($queueLaneContract['queue_lane_explicit'] ?? false),
                'queue_lane_id' => (string) ($queueLaneContract['queue_lane_id'] ?? ''),
                'next_iteration_preserves_replenishment_bounds' => true,
                'pre_iteration_checks' => [
                    'inspect_or_recover_current_packet_before_editing_if_interrupted',
                    'ensure_no_active_foreign_lease_is_reused',
                    'ensure_completion_evidence_template_json_is_written_before_complete_dry_run',
                ],
                'post_completion_checks' => [
                    'complete_current_dry_run_returns_completed_dry_run',
                    'current_lease_is_closed_before_next_after_completion',
                    'run_next_after_completion_to_claim_fresh_work',
                ],
                'stop_conditions' => [
                    'worker_packet_status_not_ready_for_worker',
                    'one_shot_worker_packet_ready_false',
                    'completion_evidence_validation_not_valid',
                    'lease_recovery_reports_unrecoverable_scope_or_foreign_lease',
                    'operator_requests_stop',
                ],
            ],
            'operator_loop_contract' => [
                'one_terminal_runs_one_packet_at_a_time',
                'after_completed_dry_run_run_next_after_completion_to_claim_fresh_work',
                'if_interrupted_run_recover_or_resume_current_packet_before_editing',
                'prefer_explicit_queue_tag_per_terminal_lane_for_parallel_fleets',
                'never_complete_without_structured_completion_evidence_json',
                'never_reuse_expired_or_foreign_lease',
            ],
        ];
    }

    /**
     * @param  list<string>  $queueTags
     * @return array<string, mixed>
     */
    public function queueLaneContract(string $actor, array $queueTags, string $bootstrapCommand): array
    {
        $primaryTag = trim((string) ($queueTags[0] ?? ''));
        $explicit = $primaryTag !== '';
        $laneId = $explicit ? $primaryTag : 'global';
        $recommendedTag = $this->commandFormatter()->recommendedQueueTag($actor);
        $contract = [
            'schema_version' => 'atlas.self_construction.agent_control_plane_terminal_loop_queue_lane_contract.v1',
            'queue_lane_id' => $laneId,
            'queue_lane_explicit' => $explicit,
            'queue_lane_mode' => $explicit ? 'explicit_tagged_lane' : 'global_untagged_lane',
            'queue_tags' => $queueTags,
            'primary_queue_tag' => $primaryTag,
            'recommended_queue_tag' => $recommendedTag,
            'next_iteration_command_contains_primary_queue_tag' => $explicit && str_contains($bootstrapCommand, '--queue-tag='.$this->commandFormatter()->commandValue($primaryTag)),
            'next_iteration_preserves_queue_lane' => $explicit
                ? str_contains($bootstrapCommand, '--queue-tag='.$this->commandFormatter()->commandValue($primaryTag))
                : ! str_contains($bootstrapCommand, '--queue-tag='),
            'parallel_fleet_recommendation' => $explicit
                ? 'safe_parallel_lane_explicitly_scoped'
                : 'operator_should_pass_queue_tag_for_parallel_terminal_fleets',
            'can_switch_lane_from_contract' => false,
            'can_claim_from_contract' => false,
            'non_execution_guarantees' => [
                'queue_lane_contract_does_not_replenish_queue',
                'queue_lane_contract_does_not_claim_lease',
                'queue_lane_contract_does_not_complete_packet',
                'queue_lane_contract_does_not_dispatch_work',
            ],
        ];
        $contract['queue_lane_contract_hash'] = $this->stableHash($contract);

        return $contract;
    }

    /**
     * @param  array<string, mixed>  $terminalLoopOperatorCommands
     * @param  array<string, mixed>  $resumptionContract
     * @return array<string, mixed>
     */
    public function terminalLoopResumptionCheckpoint(
        string $status,
        string $actor,
        string $taskPacketId,
        string $leaseId,
        string $claimEvent,
        bool $oneShotWorkerPacketReady,
        array $terminalLoopOperatorCommands,
        array $resumptionContract,
        bool $previewOnly,
    ): array {
        $checkpoint = [
            'schema_version' => 'atlas.self_construction.agent_control_plane_terminal_loop_resumption_checkpoint.v1',
            'status' => $status,
            'current_step' => $this->terminalLoopCurrentStep($status, $claimEvent, $oneShotWorkerPacketReady, $previewOnly),
            'actor' => $actor,
            'task_packet_id' => $taskPacketId,
            'lease_id' => $leaseId,
            'claim_event' => $claimEvent,
            'one_shot_worker_packet_ready' => $oneShotWorkerPacketReady,
            'preview_only' => $previewOnly,
            'can_resume_without_chat_history' => true,
            'requires_active_lease_before_editing' => ! $previewOnly,
            'requires_fresh_bootstrap_after_completion' => true,
            'requires_structured_completion_evidence_before_complete_dry_run' => true,
            'queue_lane_contract' => (array) data_get($terminalLoopOperatorCommands, 'queue_lane_contract', []),
            'resume_commands' => [
                'recover_or_resume_current_packet' => (string) ($terminalLoopOperatorCommands['recover_or_resume_current_packet'] ?? ''),
                'renew_current_lease' => (string) ($terminalLoopOperatorCommands['renew_current_lease'] ?? ''),
                'complete_current_dry_run' => (string) ($terminalLoopOperatorCommands['complete_current_dry_run'] ?? ''),
                'claim_or_replenish_next' => (string) ($terminalLoopOperatorCommands['claim_or_replenish_next'] ?? ''),
                'next_after_completion' => (string) ($terminalLoopOperatorCommands['next_after_completion'] ?? ''),
                'regenerate_current_one_shot_packet_if_lease_still_active' => (string) data_get($resumptionContract, 'resume_commands.regenerate_current_one_shot_packet_if_lease_still_active', ''),
            ],
            'operator_stop_conditions' => (array) data_get($terminalLoopOperatorCommands, 'long_running_loop_contract.stop_conditions', []),
            'required_resume_evidence' => (array) data_get($resumptionContract, 'required_resume_evidence', [
                'last_git_status_short',
                'recovery_command_output',
                'new_lease_id_when_reclaimed',
            ]),
            'forbidden_resume_shortcuts' => (array) data_get($resumptionContract, 'forbidden_resume_shortcuts', [
                'do_not_reuse_expired_lease',
                'do_not_complete_with_a_different_agents_lease',
                'do_not_reopen_completed_dry_run_packet',
                'do_not_bypass_task_lease_recovery_surface',
            ]),
            'next_operator_action' => $previewOnly
                ? 'run_preview_execute_bootstrap_command_to_claim_deliberately'
                : ($oneShotWorkerPacketReady ? 'execute_worker_prompt_then_complete_dry_run_with_structured_evidence' : 'inspect_blocker_and_recover_or_claim_fresh_packet'),
            'non_execution_guarantees' => [
                'terminal_loop_resumption_checkpoint_does_not_replenish_queue',
                'terminal_loop_resumption_checkpoint_does_not_claim_lease',
                'terminal_loop_resumption_checkpoint_does_not_complete_packet',
                'terminal_loop_resumption_checkpoint_does_not_start_codex',
                'terminal_loop_resumption_checkpoint_does_not_call_provider',
                'terminal_loop_resumption_checkpoint_does_not_spend_tokens',
                'terminal_loop_resumption_checkpoint_does_not_dispatch_work',
                'terminal_loop_resumption_checkpoint_does_not_enable_self_programming',
            ],
            'resumption_checkpoint_hash' => '',
        ];
        $checkpoint['resumption_checkpoint_hash'] = $this->stableHash($checkpoint);

        return $checkpoint;
    }

    /**
     * @param  array<string, mixed>  $terminalLoopOperatorCommands
     * @param  array<string, mixed>  $terminalLoopResumptionCheckpoint
     * @return array<string, mixed>
     */
    public function terminalLoopIterationRunbook(
        string $status,
        string $actor,
        string $taskPacketId,
        string $leaseId,
        bool $oneShotWorkerPacketReady,
        bool $previewOnly,
        array $terminalLoopOperatorCommands,
        array $terminalLoopResumptionCheckpoint,
    ): array {
        $claimCommand = (string) data_get($terminalLoopOperatorCommands, 'claim_or_replenish_next', '');
        $recoverCommand = (string) data_get($terminalLoopOperatorCommands, 'recover_or_resume_current_packet', '');
        $renewCommand = (string) data_get($terminalLoopOperatorCommands, 'renew_current_lease', '');
        $completeCommand = (string) data_get($terminalLoopOperatorCommands, 'complete_current_dry_run', '');
        $nextCommand = (string) data_get($terminalLoopOperatorCommands, 'next_after_completion', '');
        $runbook = [
            'schema_version' => 'atlas.self_construction.agent_control_plane_terminal_loop_iteration_runbook.v1',
            'mode' => 'read_only_terminal_loop_iteration_runbook',
            'status' => $previewOnly
                ? 'preview_iteration_plan_ready'
                : ($oneShotWorkerPacketReady ? 'ready_for_single_packet_iteration' : 'blocked_before_worker_iteration'),
            'bootstrap_status' => $status,
            'actor' => $actor,
            'task_packet_id' => $taskPacketId,
            'lease_id' => $leaseId,
            'preview_only' => $previewOnly,
            'one_shot_worker_packet_ready' => $oneShotWorkerPacketReady,
            'can_loop_without_chat_history' => true,
            'one_terminal_one_packet_at_a_time' => true,
            'auto_replenishment_runs_at_iteration_start' => ! $previewOnly,
            'claim_or_replenish_next_command' => $claimCommand,
            'completion_evidence_template_path' => (string) data_get($terminalLoopOperatorCommands, 'completion_evidence_template_path', ''),
            'lease_renewal_cadence_seconds' => (int) data_get($terminalLoopOperatorCommands, 'long_running_loop_contract.lease_renewal_cadence_seconds', 0),
            'renew_before_seconds_remaining' => (int) data_get($terminalLoopOperatorCommands, 'long_running_loop_contract.renew_before_seconds_remaining', 0),
            'max_single_packet_minutes' => (int) data_get($terminalLoopOperatorCommands, 'long_running_loop_contract.max_single_packet_minutes', 0),
            'queue_lane_contract' => (array) data_get($terminalLoopOperatorCommands, 'queue_lane_contract', []),
            'iteration_steps' => [
                $this->iterationStep('inspect_or_recover_current_packet', 'preflight', $recoverCommand, 'run_when_resuming_or_after_interruption', ['active_lease_or_recovered_claim'], false),
                $this->iterationStep('execute_one_shot_worker_prompt', 'worker_execution', '', 'operator_runs_returned_worker_prompt_in_this_terminal_or_agent_session', ['work_product', 'commands_run', 'tests_or_gates_result'], false),
                $this->iterationStep('renew_lease_during_long_work', 'lease_maintenance', $renewCommand, 'run_before_lease_expires_and_on_long_tasks', ['renewed_lease_or_stop'], false),
                $this->iterationStep('write_structured_completion_evidence_json', 'evidence', '', 'operator_writes_completion_evidence_template_with_real_results', ['completion_evidence_json'], false),
                $this->iterationStep('complete_current_dry_run', 'completion', $completeCommand, 'run_only_after_structured_evidence_is_valid', ['completed_dry_run_receipt'], false),
                $this->iterationStep('claim_next_iteration', 'next_iteration', $nextCommand, 'run_after_current_lease_is_closed', ['fresh_task_packet_or_bounded_blocker'], false),
            ],
            'proof_commands_after_each_iteration' => [
                'inspect_queue' => (string) data_get($terminalLoopOperatorCommands, 'inspect_queue', ''),
                'inspect_active_leases' => (string) data_get($terminalLoopOperatorCommands, 'inspect_active_leases', ''),
                'recover_or_resume_current_packet' => $recoverCommand,
            ],
            'stop_conditions' => (array) data_get($terminalLoopOperatorCommands, 'long_running_loop_contract.stop_conditions', []),
            'resumption_checkpoint_hash' => (string) data_get($terminalLoopResumptionCheckpoint, 'resumption_checkpoint_hash', ''),
            'resumption_current_step' => (string) data_get($terminalLoopResumptionCheckpoint, 'current_step', ''),
            'next_operator_action' => (string) data_get($terminalLoopResumptionCheckpoint, 'next_operator_action', ''),
            'forbidden_loop_shortcuts' => [
                'do_not_run_multiple_packets_in_one_terminal_before_completing_current_dry_run',
                'do_not_complete_without_structured_completion_evidence_json',
                'do_not_reuse_expired_or_foreign_lease',
                'do_not_skip_lease_recovery_after_interruption',
                'do_not_treat_dry_run_completion_as_os_completion',
            ],
            'non_execution_guarantees' => [
                'terminal_loop_iteration_runbook_does_not_execute_worker_prompt',
                'terminal_loop_iteration_runbook_does_not_complete_packet',
                'terminal_loop_iteration_runbook_does_not_claim_additional_lease',
                'terminal_loop_iteration_runbook_does_not_call_provider',
                'terminal_loop_iteration_runbook_does_not_spend_tokens',
                'terminal_loop_iteration_runbook_does_not_dispatch_work',
                'terminal_loop_iteration_runbook_does_not_enable_self_programming',
            ],
            'iteration_runbook_hash' => '',
        ];
        $runbook['iteration_runbook_hash'] = $this->stableHash($runbook);

        return $runbook;
    }

    /**
     * @param  list<string>  $produces
     * @return array<string, mixed>
     */
    public function iterationStep(
        string $id,
        string $phase,
        string $command,
        string $operatorAction,
        array $produces,
        bool $canRunAutomatically,
    ): array {
        return [
            'id' => $id,
            'phase' => $phase,
            'command' => $command,
            'command_hash' => $command === '' ? '' : hash('sha256', $command),
            'operator_action' => $operatorAction,
            'produces' => $produces,
            'can_run_automatically' => $canRunAutomatically,
        ];
    }

    /**
     * @param  array<string, mixed>  $terminalLoopOperatorCommands
     * @param  array<string, mixed>  $terminalLoopIterationRunbook
     * @return array<string, mixed>
     */
    public function terminalLoopShellRecipe(string $actor, bool $previewOnly, array $terminalLoopOperatorCommands, array $terminalLoopIterationRunbook): array
    {
        $bootstrapCommand = (string) data_get($terminalLoopOperatorCommands, 'claim_or_replenish_next', '');
        $recoverCommand = (string) data_get($terminalLoopOperatorCommands, 'recover_or_resume_current_packet', '');
        $renewCommand = (string) data_get($terminalLoopOperatorCommands, 'renew_current_lease', '');
        $completeCommand = (string) data_get($terminalLoopOperatorCommands, 'complete_current_dry_run', '');

        $recipe = [
            'schema_version' => 'atlas.self_construction.agent_control_plane_terminal_loop_shell_recipe.v1',
            'mode' => 'read_only_terminal_loop_shell_recipe',
            'status' => $previewOnly ? 'preview_shell_recipe_ready' : 'shell_recipe_ready',
            'actor' => $actor,
            'preview_only' => $previewOnly,
            'safe_to_copy_after_operator_review' => true,
            'can_execute_from_bootstrap' => false,
            'can_complete_from_recipe' => false,
            'requires_operator_to_run_worker_prompt' => true,
            'requires_operator_to_write_completion_evidence_json' => true,
            'requires_operator_to_rerun_bootstrap_after_completion' => true,
            'max_cycles_recommended' => 6,
            'cycle_commands' => [
                'bootstrap_or_claim' => $bootstrapCommand,
                'recover_or_resume' => $recoverCommand,
                'renew_current_lease' => $renewCommand,
                'complete_current_dry_run' => $completeCommand,
            ],
            'manual_slots' => [
                'paste_worker_prompt_into_terminal_or_agent_session',
                'edit_files_only_inside_allowed_scope',
                'write_completion_evidence_json_at_declared_path',
                'review_git_status_and_diff_check_before_completion',
            ],
            'copy_paste_shell_loop_skeleton' => [
                'set -euo pipefail',
                'ATLAS_LOOP_CYCLES="${ATLAS_LOOP_CYCLES:-1}"',
                'for cycle in $(seq 1 "$ATLAS_LOOP_CYCLES"); do',
                '  '.$bootstrapCommand,
                '  # Operator/agent must run the returned worker_prompt_full manually.',
                '  # For long work, run the returned lease_renew_command before the lease expires.',
                '  # Then write completion-evidence.json using completion_evidence_template_json.',
                '  # Finally run the returned completion_command and inspect its JSON result.',
                'done',
            ],
            'loop_invariants' => [
                'one_terminal_one_packet_at_a_time',
                'bootstrap_auto_replenishes_before_claim',
                'lease_must_be_active_before_editing',
                'completion_requires_structured_evidence_json',
                'next_cycle_starts_only_after_completed_dry_run_or_safe_recovery',
                'operator_can_resume_without_chat_history_from_runbook_hash',
                'operator_should_use_explicit_queue_tag_for_parallel_terminal_fleets',
            ],
            'stop_conditions' => (array) data_get($terminalLoopIterationRunbook, 'stop_conditions', []),
            'forbidden_shortcuts' => (array) data_get($terminalLoopIterationRunbook, 'forbidden_loop_shortcuts', []),
            'iteration_runbook_hash' => (string) data_get($terminalLoopIterationRunbook, 'iteration_runbook_hash', ''),
            'non_execution_guarantees' => [
                'terminal_loop_shell_recipe_does_not_execute_shell',
                'terminal_loop_shell_recipe_does_not_start_codex',
                'terminal_loop_shell_recipe_does_not_call_provider',
                'terminal_loop_shell_recipe_does_not_spend_tokens',
                'terminal_loop_shell_recipe_does_not_dispatch_work',
                'terminal_loop_shell_recipe_does_not_complete_packet',
                'terminal_loop_shell_recipe_does_not_enable_self_programming',
            ],
        ];
        $recipe['shell_recipe_hash'] = $this->stableHash($recipe);

        return $recipe;
    }

    public function terminalLoopCurrentStep(string $status, string $claimEvent, bool $oneShotWorkerPacketReady, bool $previewOnly): string
    {
        if ($previewOnly) {
            return 'preview_inspection';
        }
        if ($claimEvent === 'worker_task_eligibility_blocked_before_claim') {
            return 'worker_task_eligibility_blocked_before_claim';
        }
        if ($status === 'ready_for_worker' && $claimEvent === 'claimed' && $oneShotWorkerPacketReady) {
            return 'claimed_packet_ready_for_one_shot_worker';
        }
        if ($claimEvent === 'claimed') {
            return 'claimed_packet_blocked_before_worker_handoff';
        }

        return 'claim_or_replenishment_blocked';
    }

    /**
     * Local stableHash — kept private to this class to avoid cross-class coupling. Identical
     * contract to the bootstrap service's original stableHash (SHA-256 over canonical JSON).
     *
     * @param  array<string, mixed>  $payload
     */
    private function stableHash(array $payload): string
    {
        return hash('sha256', (string) json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function commandFormatter(): AgentControlPlaneTerminalWorkerCommandFormatter
    {
        return $this->commandFormatter ?? new AgentControlPlaneTerminalWorkerCommandFormatter;
    }
}
