<?php

namespace App\Services\Ai\SelfConstruction;

use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

/**
 * Read-only operator digest for long-running terminal loops.
 *
 * This is the compact "can I keep looping?" surface for many human-launched
 * Codex/Claude terminals: it inspects queue supply, claimed packets,
 * recoverable leases and exact next commands without claiming, recovering,
 * dispatching or marking completion.
 */
final class AgentControlPlaneTerminalLoopHealthDigestService
{
    public const SCHEMA_VERSION = 'atlas.self_construction.agent_control_plane_terminal_loop_health_digest.v1';

    public const FLEET_LAUNCH_PLAN_SCHEMA_VERSION = 'atlas.self_construction.agent_control_plane_terminal_loop_fleet_launch_plan.v1';

    public const FLEET_REPLENISHMENT_PLAN_SCHEMA_VERSION = 'atlas.self_construction.agent_control_plane_terminal_loop_fleet_replenishment_plan.v1';

    public const FLEET_RESUME_ROLLUP_SCHEMA_VERSION = 'atlas.self_construction.agent_control_plane_terminal_loop_fleet_resume_rollup.v1';

    public const FLEET_EVIDENCE_ROLLUP_SCHEMA_VERSION = 'atlas.self_construction.agent_control_plane_terminal_loop_fleet_evidence_rollup.v1';

    public const FLEET_OPERATOR_HANDOFF_SCHEMA_VERSION = 'atlas.self_construction.agent_control_plane_terminal_loop_fleet_operator_handoff.v1';

    public const FLEET_LANE_ISOLATION_SCHEMA_VERSION = 'atlas.self_construction.agent_control_plane_terminal_loop_fleet_lane_isolation.v1';

    public const CYCLE_SUPERVISOR_SCHEMA_VERSION = 'atlas.self_construction.agent_control_plane_terminal_loop_cycle_supervisor.v1';

    public const FLEET_LAUNCH_RUNBOOK_SCHEMA_VERSION = 'atlas.self_construction.agent_control_plane_terminal_loop_fleet_launch_runbook.v1';

    public const END_TO_END_LOOP_CONTRACT_SCHEMA_VERSION = 'atlas.self_construction.agent_control_plane_terminal_loop_end_to_end_contract.v1';

    public const MODE = 'read_only_agent_control_plane_terminal_loop_health_digest';

    private const OPERATOR_ONLY_COMPLETION_CRITERIA = [
        'runtime_gap_matrix_all_runtime_y',
        'human_signed_os_complete_receipt_present',
        'end_to_end_real_provider_smoke_green',
    ];

    public function __construct(
        private readonly ?AgentControlPlaneTaskPacketQueueRepository $queue = null,
        private readonly ?AgentControlPlaneTaskLeaseRecoveryService $recovery = null,
    ) {}

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function digest(array $options = []): array
    {
        $actor = $this->stringOption($options, 'actor', 'operator');
        $queueTags = $this->stringList((array) ($options['queue_tags'] ?? []));
        $targetMinClaimable = max(1, min(25, (int) ($options['target_min_claimable_tasks'] ?? 6)));
        $maxNewTasks = max(0, min(25, (int) ($options['max_new_tasks'] ?? $targetMinClaimable)));

        $queue = $this->queueRepo();
        $recovery = $this->recoveryService();

        $queueRegistry = $queue->registry();
        $recoverability = $recovery->inspectRecoverability();
        $classifications = (array) ($recoverability['classifications'] ?? []);
        $statusCounts = (array) ($queueRegistry['status_counts'] ?? []);

        $unfilteredClaimableCount = $this->countQueueRecords($queue, 'claimable', []);
        $unfilteredClaimedCount = $this->countQueueRecords($queue, 'claimed', []);
        $claimableCount = $this->countQueueRecords($queue, 'claimable', $queueTags);
        $claimedCount = $this->countQueueRecords($queue, 'claimed', $queueTags);
        $activeLeaseCount = $this->classificationCount(
            $classifications,
            AgentControlPlaneTaskLeaseRecoveryService::RECOVERABILITY_ACTIVE_LEASE,
            $queueTags,
        );
        $recoverableExpiredCount = $this->classificationCount(
            $classifications,
            AgentControlPlaneTaskLeaseRecoveryService::RECOVERABILITY_RECOVERABLE_EXPIRED,
            $queueTags,
        );
        $recoverableOrphanCount = $this->classificationCount(
            $classifications,
            AgentControlPlaneTaskLeaseRecoveryService::RECOVERABILITY_RECOVERABLE_ORPHAN,
            $queueTags,
        );
        $recoverableReleasedCount = $this->classificationCount(
            $classifications,
            AgentControlPlaneTaskLeaseRecoveryService::RECOVERABILITY_RECOVERABLE_RELEASED,
            $queueTags,
        );
        $recoverableCount = $recoverableExpiredCount + $recoverableOrphanCount + $recoverableReleasedCount;
        $terminalCount = (int) ($statusCounts['completed_dry_run'] ?? 0) + (int) ($statusCounts['cancelled'] ?? 0);
        $hiddenClaimableOutsideRequestedTags = $queueTags === []
            ? 0
            : max(0, $unfilteredClaimableCount - $claimableCount);
        $tagFilteredSupplyGap = $queueTags !== [] && $claimableCount < $targetMinClaimable && $hiddenClaimableOutsideRequestedTags > 0;
        $workerEligibility = $this->workerTaskEligibility($queue, $queueTags);
        $workerEligibilityBlocked = (string) ($workerEligibility['status'] ?? '') === 'blocked';

        $recommendedAction = $this->recommendedAction(
            recoverableCount: $recoverableCount,
            claimableCount: $claimableCount,
            activeLeaseCount: $activeLeaseCount,
            targetMinClaimable: $targetMinClaimable,
            workerEligibilityBlocked: $workerEligibilityBlocked,
        );

        $commands = $this->commands($actor, $targetMinClaimable, $maxNewTasks, $queueTags);
        $status = $recommendedAction === 'continue_or_start_terminal_workers' ? 'ready' : 'action_required';
        $safeToStartNewWorker = $recoverableCount === 0
            && $claimableCount >= $targetMinClaimable
            && ! $workerEligibilityBlocked;
        $fleetLaunchPlan = $this->fleetLaunchPlan(
            actor: $actor,
            queueTags: $queueTags,
            targetMinClaimable: $targetMinClaimable,
            maxNewTasks: $maxNewTasks,
            claimableCount: $claimableCount,
            activeLeaseCount: $activeLeaseCount,
            recoverableCount: $recoverableCount,
            hiddenClaimableOutsideRequestedTags: $hiddenClaimableOutsideRequestedTags,
            tagFilteredSupplyGap: $tagFilteredSupplyGap,
            recommendedAction: $recommendedAction,
            safeToStartNewWorker: $safeToStartNewWorker,
            workerEligibility: $workerEligibility,
        );
        $fleetReplenishmentPlan = $this->fleetReplenishmentPlan(
            actor: $actor,
            queueTags: $queueTags,
            targetMinClaimable: $targetMinClaimable,
            maxNewTasks: $maxNewTasks,
            claimableCount: $claimableCount,
            recoverableCount: $recoverableCount,
            tagFilteredSupplyGap: $tagFilteredSupplyGap,
            hiddenClaimableOutsideRequestedTags: $hiddenClaimableOutsideRequestedTags,
            commands: $commands,
        );
        $fleetResumeRollup = $this->fleetResumeRollup($classifications, $queueTags, $actor);
        $fleetEvidenceRollup = $this->fleetEvidenceRollup($queue, $queueTags);
        $fleetOperatorHandoff = $this->fleetOperatorHandoff(
            launchPlan: $fleetLaunchPlan,
            replenishmentPlan: $fleetReplenishmentPlan,
            resumeRollup: $fleetResumeRollup,
            evidenceRollup: $fleetEvidenceRollup,
            commands: $commands,
        );
        $fleetLaneIsolation = $this->fleetLaneIsolation(
            queueTags: $queueTags,
            commands: $commands,
            launchPlan: $fleetLaunchPlan,
            operatorHandoff: $fleetOperatorHandoff,
        );
        $cycleSupervisor = $this->terminalLoopCycleSupervisor(
            recommendedAction: $recommendedAction,
            launchPlan: $fleetLaunchPlan,
            replenishmentPlan: $fleetReplenishmentPlan,
            resumeRollup: $fleetResumeRollup,
            evidenceRollup: $fleetEvidenceRollup,
            operatorHandoff: $fleetOperatorHandoff,
            laneIsolation: $fleetLaneIsolation,
            commands: $commands,
        );
        $fleetLaunchRunbook = $this->fleetLaunchRunbook(
            actor: $actor,
            queueTags: $queueTags,
            launchPlan: $fleetLaunchPlan,
            replenishmentPlan: $fleetReplenishmentPlan,
            operatorHandoff: $fleetOperatorHandoff,
            cycleSupervisor: $cycleSupervisor,
        );
        $endToEndLoopContract = $this->endToEndLoopContract(
            commands: $commands,
            launchPlan: $fleetLaunchPlan,
            replenishmentPlan: $fleetReplenishmentPlan,
            resumeRollup: $fleetResumeRollup,
            evidenceRollup: $fleetEvidenceRollup,
            workerEligibility: $workerEligibility,
            operatorHandoff: $fleetOperatorHandoff,
            laneIsolation: $fleetLaneIsolation,
            cycleSupervisor: $cycleSupervisor,
            launchRunbook: $fleetLaunchRunbook,
        );
        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'digest_id' => 'terminal_loop_health_digest_'.(string) Str::ulid(),
            'generated_at' => CarbonImmutable::now()->toIso8601String(),
            'status' => $status,
            'actor' => $actor,
            'queue_tags' => $queueTags,
            'target_min_claimable_tasks' => $targetMinClaimable,
            'max_new_tasks' => $maxNewTasks,
            'queue_health' => [
                'queue_total_count' => (int) ($queueRegistry['total_count'] ?? 0),
                'queue_status_counts' => $statusCounts,
                'claimable_task_count' => $claimableCount,
                'claimed_task_count' => $claimedCount,
                'unfiltered_claimable_task_count' => $unfilteredClaimableCount,
                'unfiltered_claimed_task_count' => $unfilteredClaimedCount,
                'tag_filter_active' => $queueTags !== [],
                'requested_queue_tags' => $queueTags,
                'hidden_claimable_outside_requested_tags' => $hiddenClaimableOutsideRequestedTags,
                'tag_filtered_supply_gap' => $tagFilteredSupplyGap,
                'tag_filter_explainer' => $tagFilteredSupplyGap
                    ? 'Queue has claimable packets outside the requested tags; replenish or change queue tags for this terminal lane.'
                    : '',
                'terminal_task_count' => $terminalCount,
                'target_min_claimable_tasks_met' => $claimableCount >= $targetMinClaimable,
                'queue_registry_corrupt' => (bool) ($queueRegistry['corrupt'] ?? false),
            ],
            'lease_health' => [
                'active_lease_count' => $activeLeaseCount,
                'recoverable_lease_count' => $recoverableCount,
                'recoverable_expired_lease_count' => $recoverableExpiredCount,
                'recoverable_orphaned_claim_count' => $recoverableOrphanCount,
                'recoverable_released_task_count' => $recoverableReleasedCount,
                'recoverability_totals' => (array) ($recoverability['totals_by_classification'] ?? []),
            ],
            'loop_decision' => [
                'recommended_action' => $recommendedAction,
                'safe_to_start_new_worker' => $safeToStartNewWorker,
                'worker_task_eligibility_required_before_worker_launch' => true,
                'worker_task_eligibility_status' => (string) ($workerEligibility['status'] ?? ''),
                'worker_task_eligibility_violation_count' => (int) ($workerEligibility['violation_count'] ?? 0),
                'should_replenish_before_next_claim' => $recoverableCount === 0 && $claimableCount < $targetMinClaimable,
                'should_recover_before_next_claim' => $recoverableCount > 0,
                'should_wait_for_active_workers' => $recoverableCount === 0 && $claimableCount === 0 && $activeLeaseCount > 0,
                'tag_filtered_supply_gap' => $tagFilteredSupplyGap,
                'hidden_claimable_outside_requested_tags' => $hiddenClaimableOutsideRequestedTags,
                'can_loop_without_chat_history' => true,
                'one_terminal_one_packet_at_a_time' => true,
            ],
            'terminal_loop_fleet_launch_plan' => $fleetLaunchPlan,
            'terminal_loop_fleet_replenishment_plan' => $fleetReplenishmentPlan,
            'terminal_loop_fleet_resume_rollup' => $fleetResumeRollup,
            'terminal_loop_fleet_evidence_rollup' => $fleetEvidenceRollup,
            'worker_task_eligibility' => $workerEligibility,
            'terminal_loop_fleet_operator_handoff' => $fleetOperatorHandoff,
            'terminal_loop_fleet_lane_isolation' => $fleetLaneIsolation,
            'terminal_loop_cycle_supervisor' => $cycleSupervisor,
            'terminal_loop_fleet_launch_runbook' => $fleetLaunchRunbook,
            'terminal_loop_end_to_end_contract' => $endToEndLoopContract,
            'next_commands' => $commands,
            'observability' => [
                'bootstrap_preview_command' => $commands['preview_bootstrap'],
                'worker_task_eligibility_certification_command' => $commands['worker_task_eligibility_certification'],
                'recoverability_command' => $commands['inspect_or_recover_leases'],
                'queue_inspection_command' => $commands['inspect_queue'],
                'active_lease_inspection_command' => $commands['inspect_leases'],
            ],
            'runtime_safety' => $this->runtimeFlags(),
            'non_execution_guarantees' => [
                'terminal_loop_health_digest_does_not_claim_tasks',
                'terminal_loop_health_digest_does_not_create_or_renew_leases',
                'terminal_loop_health_digest_does_not_complete_tasks',
                'terminal_loop_health_digest_does_not_recover_leases',
                'terminal_loop_health_digest_does_not_dispatch_work',
                'terminal_loop_health_digest_does_not_call_provider',
                'terminal_loop_health_digest_does_not_spend_tokens',
                'terminal_loop_health_digest_does_not_enable_self_programming',
                'terminal_loop_health_digest_does_not_mark_real_completion',
                'terminal_loop_fleet_launch_plan_does_not_start_terminals',
                'terminal_loop_fleet_launch_plan_does_not_claim_tasks',
                'terminal_loop_fleet_replenishment_plan_does_not_replenish_tasks',
                'terminal_loop_fleet_replenishment_plan_does_not_start_terminals',
                'terminal_loop_fleet_resume_rollup_does_not_recover_leases',
                'terminal_loop_fleet_resume_rollup_does_not_claim_tasks',
                'terminal_loop_fleet_evidence_rollup_does_not_write_receipts',
                'terminal_loop_fleet_evidence_rollup_does_not_mark_completion',
                'terminal_loop_fleet_operator_handoff_does_not_execute_commands',
                'terminal_loop_fleet_operator_handoff_does_not_mutate_queue_or_leases',
                'terminal_loop_fleet_lane_isolation_does_not_change_tags',
                'terminal_loop_fleet_lane_isolation_does_not_claim_tasks',
                'terminal_loop_cycle_supervisor_does_not_execute_next_command',
                'terminal_loop_cycle_supervisor_does_not_mutate_queue_or_leases',
                'terminal_loop_fleet_launch_runbook_does_not_start_terminals',
                'terminal_loop_fleet_launch_runbook_does_not_run_commands',
                'terminal_loop_end_to_end_contract_does_not_run_commands',
                'terminal_loop_end_to_end_contract_does_not_mutate_queue_or_leases',
            ],
        ];
        $payload['terminal_loop_health_digest_hash'] = $this->hashPayload($payload);

        return $payload;
    }

    /**
     * @param  array<string, string>  $commands
     * @param  array<string, mixed>  $launchPlan
     * @param  array<string, mixed>  $replenishmentPlan
     * @param  array<string, mixed>  $resumeRollup
     * @param  array<string, mixed>  $evidenceRollup
     * @param  array<string, mixed>  $workerEligibility
     * @param  array<string, mixed>  $operatorHandoff
     * @param  array<string, mixed>  $laneIsolation
     * @param  array<string, mixed>  $cycleSupervisor
     * @param  array<string, mixed>  $launchRunbook
     * @return array<string, mixed>
     */
    private function endToEndLoopContract(
        array $commands,
        array $launchPlan,
        array $replenishmentPlan,
        array $resumeRollup,
        array $evidenceRollup,
        array $workerEligibility,
        array $operatorHandoff,
        array $laneIsolation,
        array $cycleSupervisor,
        array $launchRunbook,
    ): array {
        $runtimeFlags = $this->runtimeFlags();
        $checks = [
            'auto_replenishment_surface_present' => str_contains((string) ($commands['replenish_tasks'] ?? ''), '--agent-control-plane-task-auto-replenishment-status')
                && (string) data_get($replenishmentPlan, 'schema_version', '') === self::FLEET_REPLENISHMENT_PLAN_SCHEMA_VERSION,
            'validation_surface_present' => (string) data_get($workerEligibility, 'schema_version', '') === 'atlas.self_construction.agent_control_plane_terminal_loop_worker_task_eligibility.v1'
                && array_key_exists('violation_count', $workerEligibility),
            'lease_surface_present' => str_contains((string) ($commands['inspect_or_recover_leases'] ?? ''), '--agent-control-plane-task-lease-recovery-status')
                && (string) data_get($resumeRollup, 'schema_version', '') === self::FLEET_RESUME_ROLLUP_SCHEMA_VERSION,
            'evidence_surface_present' => (string) data_get($evidenceRollup, 'schema_version', '') === self::FLEET_EVIDENCE_ROLLUP_SCHEMA_VERSION
                && array_key_exists('ready_for_operator_review', $evidenceRollup),
            'resume_surface_present' => (bool) data_get($launchRunbook, 'resume_after_interruption.can_resume_without_chat_history', false)
                && (string) data_get($launchRunbook, 'resume_after_interruption.resume_command', '') !== '',
            'lane_isolation_surface_present' => (string) data_get($laneIsolation, 'schema_version', '') === self::FLEET_LANE_ISOLATION_SCHEMA_VERSION
                && array_key_exists('all_commands_lane_bound', $laneIsolation),
            'cycle_supervisor_surface_present' => (string) data_get($cycleSupervisor, 'schema_version', '') === self::CYCLE_SUPERVISOR_SCHEMA_VERSION
                && (string) data_get($cycleSupervisor, 'next_command', '') !== '',
            'operator_handoff_surface_present' => (string) data_get($operatorHandoff, 'schema_version', '') === self::FLEET_OPERATOR_HANDOFF_SCHEMA_VERSION
                && (string) data_get($operatorHandoff, 'primary_command', '') !== '',
            'fleet_launch_surface_present' => (string) data_get($launchPlan, 'schema_version', '') === self::FLEET_LAUNCH_PLAN_SCHEMA_VERSION
                && array_key_exists('safe_to_start_now', $launchPlan),
            'all_surfaces_are_read_only' => (bool) data_get($launchPlan, 'can_execute_from_digest', true) === false
                && (bool) data_get($launchPlan, 'can_claim_from_digest', true) === false
                && (bool) data_get($replenishmentPlan, 'can_replenish_from_digest', true) === false
                && (bool) data_get($resumeRollup, 'can_recover_from_rollup', true) === false
                && in_array('fleet_evidence_rollup_does_not_mark_real_completion', (array) data_get($evidenceRollup, 'non_execution_guarantees', []), true)
                && (bool) data_get($operatorHandoff, 'can_execute_from_handoff', true) === false
                && (bool) data_get($cycleSupervisor, 'can_execute_next_command', true) === false
                && (bool) data_get($launchRunbook, 'can_execute_commands_from_runbook', true) === false,
            'runtime_flags_all_false' => ! in_array(true, array_map(
                static fn (mixed $flag): bool => (bool) $flag,
                $runtimeFlags,
            ), true),
        ];
        $failedChecks = array_keys(array_filter($checks, static fn (bool $passed): bool => ! $passed));
        $contract = [
            'schema_version' => self::END_TO_END_LOOP_CONTRACT_SCHEMA_VERSION,
            'status' => $failedChecks === [] ? 'terminal_loop_end_to_end_contract_available' : 'terminal_loop_end_to_end_contract_blocked',
            'covered_capabilities' => [
                'auto_replenishment',
                'validation',
                'leases',
                'evidence',
                'retomada',
                'lane_isolation',
                'cycle_supervision',
                'operator_handoff',
            ],
            'checks' => $checks,
            'failed_check_ids' => $failedChecks,
            'check_count' => count($checks),
            'passed_check_count' => count($checks) - count($failedChecks),
            'all_required_surfaces_present' => $failedChecks === [],
            'next_safe_command' => (string) data_get($cycleSupervisor, 'next_command', ''),
            'next_safe_command_purpose' => (string) data_get($cycleSupervisor, 'next_command_purpose', ''),
            'next_safe_command_requires_operator_execution' => true,
            'resume_without_chat_history_command' => (string) data_get($launchRunbook, 'resume_after_interruption.resume_command', ''),
            'source_hashes' => [
                'fleet_launch_plan_hash' => (string) data_get($launchPlan, 'terminal_loop_fleet_launch_plan_hash', ''),
                'fleet_replenishment_plan_hash' => (string) data_get($replenishmentPlan, 'terminal_loop_fleet_replenishment_plan_hash', ''),
                'fleet_resume_rollup_hash' => (string) data_get($resumeRollup, 'terminal_loop_fleet_resume_rollup_hash', ''),
                'fleet_evidence_rollup_hash' => (string) data_get($evidenceRollup, 'terminal_loop_fleet_evidence_rollup_hash', ''),
                'fleet_operator_handoff_hash' => (string) data_get($operatorHandoff, 'terminal_loop_fleet_operator_handoff_hash', ''),
                'fleet_lane_isolation_hash' => (string) data_get($laneIsolation, 'terminal_loop_fleet_lane_isolation_hash', ''),
                'cycle_supervisor_hash' => (string) data_get($cycleSupervisor, 'terminal_loop_cycle_supervisor_hash', ''),
                'fleet_launch_runbook_hash' => (string) data_get($launchRunbook, 'terminal_loop_fleet_launch_runbook_hash', ''),
            ],
            'forbidden_actions' => [
                'do_not_treat_contract_as_permission_to_execute_commands',
                'do_not_replenish_without_operator_running_the_returned_command',
                'do_not_recover_leases_without_operator_running_the_returned_command',
                'do_not_claim_or_complete_work_from_this_read_only_contract',
                'do_not_promote_self_construction_or_self_programming_from_this_contract',
            ],
            'runtime_safety' => $runtimeFlags,
            'can_execute_from_contract' => false,
            'can_replenish_from_contract' => false,
            'can_recover_from_contract' => false,
            'can_claim_from_contract' => false,
            'can_complete_from_contract' => false,
            'can_call_provider_from_contract' => false,
            'can_spend_tokens_from_contract' => false,
        ];
        $contract['terminal_loop_end_to_end_contract_hash'] = $this->hashPayload($contract);

        return $contract;
    }

    /**
     * @param  array<string, mixed>  $launchPlan
     * @param  array<string, mixed>  $replenishmentPlan
     * @param  array<string, mixed>  $resumeRollup
     * @param  array<string, mixed>  $evidenceRollup
     * @param  array<string, mixed>  $operatorHandoff
     * @param  array<string, mixed>  $laneIsolation
     * @param  array<string, string>  $commands
     * @return array<string, mixed>
     */
    private function terminalLoopCycleSupervisor(
        string $recommendedAction,
        array $launchPlan,
        array $replenishmentPlan,
        array $resumeRollup,
        array $evidenceRollup,
        array $operatorHandoff,
        array $laneIsolation,
        array $commands,
    ): array {
        $resumeAttention = (bool) ($resumeRollup['resume_attention_required'] ?? false);
        $shouldReplenish = (bool) ($replenishmentPlan['should_replenish_now'] ?? false);
        $launchReady = (string) ($launchPlan['status'] ?? '') === 'fleet_launch_plan_ready';
        $evidenceReady = (bool) ($evidenceRollup['ready_for_operator_review'] ?? false);
        $laneBound = (bool) ($laneIsolation['all_commands_lane_bound'] ?? false);

        if ($resumeAttention) {
            $cycleState = 'recover_before_claim';
            $status = 'cycle_recovery_required';
            $nextCommand = (string) ($operatorHandoff['primary_command'] ?? $commands['inspect_or_recover_leases']);
            $nextCommandPurpose = 'recover_released_expired_or_orphaned_task_before_any_new_claim';
        } elseif ($evidenceReady) {
            $cycleState = 'review_evidence';
            $status = 'cycle_evidence_review_ready';
            $nextCommand = $commands['terminal_loop_health_digest'];
            $nextCommandPurpose = 'review_completed_dry_run_evidence_and_rerun_digest';
        } elseif ($shouldReplenish) {
            $cycleState = 'replenish_before_launch';
            $status = 'cycle_replenishment_required';
            $nextCommand = (string) data_get($replenishmentPlan, 'commands.replenish_tasks', $commands['replenish_tasks']);
            $nextCommandPurpose = 'restore_lane_task_supply_before_worker_launch';
        } elseif ($launchReady) {
            $cycleState = 'launch_or_continue_workers';
            $status = 'cycle_worker_launch_ready';
            $nextCommand = (string) data_get($launchPlan, 'copy_paste_terminal_commands.0', $commands['execute_bootstrap']);
            $nextCommandPurpose = 'start_one_lane_bound_terminal_worker_with_one_packet';
        } else {
            $cycleState = 'wait_or_inspect';
            $status = 'cycle_wait_or_inspect';
            $nextCommand = $commands['terminal_loop_health_digest'];
            $nextCommandPurpose = 'wait_for_active_workers_or_inspect_canonical_sources';
        }

        $supervisor = [
            'schema_version' => self::CYCLE_SUPERVISOR_SCHEMA_VERSION,
            'status' => $status,
            'cycle_state' => $cycleState,
            'recommended_action' => $recommendedAction,
            'next_command' => $nextCommand,
            'next_command_purpose' => $nextCommandPurpose,
            'next_command_is_lane_bound' => $laneBound || $cycleState === 'wait_or_inspect',
            'next_command_source' => 'terminal_loop_health_digest_read_only_supervisor',
            'state_machine' => [
                'recover_before_claim',
                'replenish_before_launch',
                'launch_or_continue_workers',
                'complete_with_structured_evidence',
                'review_evidence',
                'rerun_digest',
            ],
            'transition_guards' => [
                'recover_before_replenish' => $resumeAttention,
                'replenish_before_launch' => $shouldReplenish,
                'launch_requires_lane_bound_commands' => $launchReady ? $laneBound : true,
                'launch_requires_no_recoverable_tasks' => ! $resumeAttention,
                'launch_requires_claimable_supply' => $launchReady,
                'evidence_review_after_completed_dry_run' => $evidenceReady,
                'rerun_digest_after_each_mutating_operator_command' => true,
            ],
            'operator_loop_contract' => [
                'one_terminal_one_packet_at_a_time' => true,
                'rerun_digest_after_next_command' => true,
                'complete_only_with_structured_evidence' => true,
                'recover_before_any_new_claim' => $resumeAttention,
                'do_not_cross_queue_lanes' => true,
                'do_not_continue_when_lane_binding_fails' => ! $laneBound,
                'max_recommended_terminals_per_batch' => (int) data_get($launchPlan, 'recommended_terminal_count', 0),
                'max_safe_parallel_terminals' => 6,
            ],
            'source_hashes' => [
                'fleet_launch_plan_hash' => (string) data_get($launchPlan, 'terminal_loop_fleet_launch_plan_hash', ''),
                'fleet_replenishment_plan_hash' => (string) data_get($replenishmentPlan, 'terminal_loop_fleet_replenishment_plan_hash', ''),
                'fleet_resume_rollup_hash' => (string) data_get($resumeRollup, 'terminal_loop_fleet_resume_rollup_hash', ''),
                'fleet_evidence_rollup_hash' => (string) data_get($evidenceRollup, 'terminal_loop_fleet_evidence_rollup_hash', ''),
                'fleet_operator_handoff_hash' => (string) data_get($operatorHandoff, 'terminal_loop_fleet_operator_handoff_hash', ''),
                'fleet_lane_isolation_hash' => (string) data_get($laneIsolation, 'terminal_loop_fleet_lane_isolation_hash', ''),
            ],
            'stop_conditions' => [
                'lane_binding_attention_required',
                'recoverable_task_present_after_recovery_attempt',
                'structured_completion_evidence_invalid',
                'git_diff_check_failed',
                'operator_requests_stop',
                'provider_or_token_action_would_be_required',
            ],
            'can_execute_next_command' => false,
            'can_recover_from_supervisor' => false,
            'can_replenish_from_supervisor' => false,
            'can_claim_from_supervisor' => false,
            'can_complete_from_supervisor' => false,
            'can_call_provider_from_supervisor' => false,
            'can_spend_tokens_from_supervisor' => false,
            'completion_real_allowed' => false,
            'non_execution_guarantees' => [
                'cycle_supervisor_is_read_only',
                'cycle_supervisor_does_not_run_next_command',
                'cycle_supervisor_does_not_claim_tasks',
                'cycle_supervisor_does_not_create_or_renew_leases',
                'cycle_supervisor_does_not_recover_leases',
                'cycle_supervisor_does_not_replenish_tasks',
                'cycle_supervisor_does_not_complete_tasks',
                'cycle_supervisor_does_not_call_provider',
                'cycle_supervisor_does_not_spend_tokens',
            ],
        ];
        $supervisor['terminal_loop_cycle_supervisor_hash'] = $this->hashPayload($supervisor);

        return $supervisor;
    }

    /**
     * @param  list<string>  $queueTags
     * @param  array<string, string>  $commands
     * @param  array<string, mixed>  $launchPlan
     * @param  array<string, mixed>  $operatorHandoff
     * @return array<string, mixed>
     */
    private function fleetLaneIsolation(array $queueTags, array $commands, array $launchPlan, array $operatorHandoff): array
    {
        $tagArgs = array_map(
            fn (string $tag): string => '--queue-tag='.$this->safeCommandToken($tag, 'queue'),
            $queueTags,
        );
        $commandsToCheck = [
            'preview_bootstrap' => (string) ($commands['preview_bootstrap'] ?? ''),
            'execute_bootstrap' => (string) ($commands['execute_bootstrap'] ?? ''),
            'replenish_tasks' => (string) ($commands['replenish_tasks'] ?? ''),
            'inspect_or_recover_leases' => (string) ($commands['inspect_or_recover_leases'] ?? ''),
            'terminal_loop_health_digest' => (string) ($commands['terminal_loop_health_digest'] ?? ''),
        ];
        $operatorPrimary = (string) ($operatorHandoff['primary_command'] ?? '');
        if ($operatorPrimary !== '') {
            $commandsToCheck['operator_handoff_primary'] = $operatorPrimary;
        }
        foreach ((array) data_get($launchPlan, 'copy_paste_terminal_commands', []) as $index => $command) {
            $commandsToCheck['fleet_terminal_'.((int) $index + 1)] = (string) $command;
        }

        $commandResults = [];
        foreach ($commandsToCheck as $name => $command) {
            $missingTags = $queueTags === []
                ? []
                : array_values(array_filter(
                    $tagArgs,
                    static fn (string $tagArg): bool => ! str_contains($command, $tagArg),
                ));
            $commandResults[] = [
                'name' => $name,
                'command_present' => $command !== '',
                'lane_bound' => $queueTags === [] || $missingTags === [],
                'missing_tag_args' => $missingTags,
            ];
        }

        $allCommandsLaneBound = count(array_filter(
            $commandResults,
            static fn (array $result): bool => (bool) ($result['command_present'] ?? false)
                && (bool) ($result['lane_bound'] ?? false),
        )) === count($commandResults);
        $status = $queueTags === []
            ? 'fleet_lane_isolation_shared_untagged_lane'
            : ($allCommandsLaneBound ? 'fleet_lane_isolation_tagged_lane_verified' : 'fleet_lane_isolation_attention_required');

        $isolation = [
            'schema_version' => self::FLEET_LANE_ISOLATION_SCHEMA_VERSION,
            'status' => $status,
            'requested_queue_tags' => $queueTags,
            'tag_filter_active' => $queueTags !== [],
            'required_tag_args' => $tagArgs,
            'all_commands_lane_bound' => $allCommandsLaneBound,
            'command_lane_checks' => $commandResults,
            'can_change_tags_from_digest' => false,
            'can_steal_unrelated_lane_work' => false,
            'operator_requirements' => [
                'start_all_terminals_with_the_returned_queue_tag_args',
                'rerun_health_digest_after_changing_queue_tags',
                'do_not_remove_queue_tag_args_from_copy_paste_commands',
                'do_not_use_global_queue_when_a_lane_tag_was_requested',
            ],
            'non_execution_guarantees' => [
                'fleet_lane_isolation_is_read_only',
                'fleet_lane_isolation_does_not_modify_queue_tags',
                'fleet_lane_isolation_does_not_claim_tasks',
                'fleet_lane_isolation_does_not_replenish_tasks',
                'fleet_lane_isolation_does_not_start_terminals',
            ],
        ];
        $isolation['terminal_loop_fleet_lane_isolation_hash'] = $this->hashPayload($isolation);

        return $isolation;
    }

    /**
     * @param  array<string, mixed>  $launchPlan
     * @param  array<string, mixed>  $replenishmentPlan
     * @param  array<string, mixed>  $resumeRollup
     * @param  array<string, mixed>  $evidenceRollup
     * @param  array<string, string>  $commands
     * @return array<string, mixed>
     */
    private function fleetOperatorHandoff(
        array $launchPlan,
        array $replenishmentPlan,
        array $resumeRollup,
        array $evidenceRollup,
        array $commands,
    ): array {
        $resumeAttention = (bool) ($resumeRollup['resume_attention_required'] ?? false);
        $shouldReplenish = (bool) ($replenishmentPlan['should_replenish_now'] ?? false);
        $launchReady = (string) ($launchPlan['status'] ?? '') === 'fleet_launch_plan_ready';
        $evidenceReady = (bool) ($evidenceRollup['ready_for_operator_review'] ?? false);

        if ($resumeAttention) {
            $status = 'fleet_operator_handoff_recover_before_loop';
            $nextAction = 'recover_orphaned_or_expired_task_leases';
            $primaryCommand = (string) data_get($resumeRollup, 'recoverable_task_summaries.0.recover_command', $commands['inspect_or_recover_leases']);
        } elseif ($shouldReplenish) {
            $status = 'fleet_operator_handoff_replenish_before_launch';
            $nextAction = 'replenish_task_supply_then_recheck_digest';
            $primaryCommand = (string) data_get($replenishmentPlan, 'commands.replenish_tasks', $commands['replenish_tasks']);
        } elseif ($launchReady) {
            $status = 'fleet_operator_handoff_launch_workers';
            $nextAction = 'start_recommended_terminal_workers';
            $primaryCommand = (string) data_get($launchPlan, 'copy_paste_terminal_commands.0', $commands['execute_bootstrap']);
        } elseif ($evidenceReady) {
            $status = 'fleet_operator_handoff_review_evidence';
            $nextAction = 'review_completed_dry_run_evidence';
            $primaryCommand = $commands['terminal_loop_health_digest'];
        } else {
            $status = 'fleet_operator_handoff_wait_or_inspect';
            $nextAction = 'rerun_health_digest_or_inspect_queue_and_leases';
            $primaryCommand = $commands['terminal_loop_health_digest'];
        }

        $handoff = [
            'schema_version' => self::FLEET_OPERATOR_HANDOFF_SCHEMA_VERSION,
            'status' => $status,
            'next_operator_action' => $nextAction,
            'primary_command' => $primaryCommand,
            'ordered_operator_sequence' => array_values(array_filter([
                $resumeAttention ? 'recover_before_any_new_claim' : null,
                $shouldReplenish ? 'replenish_to_target_supply' : null,
                'rerun_terminal_loop_health_digest',
                $launchReady ? 'start_recommended_terminal_workers' : null,
                $evidenceReady ? 'review_completed_dry_run_evidence' : null,
            ])),
            'source_statuses' => [
                'fleet_launch_plan_status' => (string) ($launchPlan['status'] ?? ''),
                'fleet_replenishment_plan_status' => (string) ($replenishmentPlan['status'] ?? ''),
                'fleet_resume_rollup_status' => (string) ($resumeRollup['status'] ?? ''),
                'fleet_evidence_rollup_status' => (string) ($evidenceRollup['status'] ?? ''),
            ],
            'copy_paste_commands' => [
                'primary' => $primaryCommand,
                'health_digest' => $commands['terminal_loop_health_digest'],
                'recover_or_inspect_leases' => $commands['inspect_or_recover_leases'],
                'replenish_tasks' => $commands['replenish_tasks'],
                'execute_bootstrap' => $commands['execute_bootstrap'],
            ],
            'can_execute_from_handoff' => false,
            'can_recover_from_handoff' => false,
            'can_replenish_from_handoff' => false,
            'can_claim_from_handoff' => false,
            'can_complete_from_handoff' => false,
            'operator_checks_before_running_primary_command' => [
                'read_source_statuses',
                'confirm_queue_tags_match_terminal_lane',
                'confirm_no_unreviewed_user_or_parallel_session_changes_are_being_overwritten',
                'rerun_health_digest_after_any_recovery_or_replenishment',
            ],
            'non_execution_guarantees' => [
                'fleet_operator_handoff_is_read_only',
                'fleet_operator_handoff_does_not_run_primary_command',
                'fleet_operator_handoff_does_not_recover_leases',
                'fleet_operator_handoff_does_not_replenish_tasks',
                'fleet_operator_handoff_does_not_claim_tasks',
                'fleet_operator_handoff_does_not_complete_tasks',
                'fleet_operator_handoff_does_not_call_provider',
                'fleet_operator_handoff_does_not_spend_tokens',
            ],
        ];
        $handoff['terminal_loop_fleet_operator_handoff_hash'] = $this->hashPayload($handoff);

        return $handoff;
    }

    /**
     * @param  list<array<string, mixed>>  $classifications
     * @param  list<string>  $queueTags
     * @return array<string, mixed>
     */
    private function fleetResumeRollup(array $classifications, array $queueTags, string $actor): array
    {
        $active = [];
        $recoverable = [];
        $terminal = [];

        foreach ($classifications as $item) {
            if (! $this->classificationMatchesTags($item, $queueTags)) {
                continue;
            }

            $classification = (string) ($item['classification'] ?? '');
            if ($classification === AgentControlPlaneTaskLeaseRecoveryService::RECOVERABILITY_ACTIVE_LEASE) {
                $active[] = $item;
            } elseif (in_array($classification, [
                AgentControlPlaneTaskLeaseRecoveryService::RECOVERABILITY_RECOVERABLE_EXPIRED,
                AgentControlPlaneTaskLeaseRecoveryService::RECOVERABILITY_RECOVERABLE_ORPHAN,
                AgentControlPlaneTaskLeaseRecoveryService::RECOVERABILITY_RECOVERABLE_RELEASED,
            ], true)) {
                $recoverable[] = $item;
            } elseif ($classification === AgentControlPlaneTaskLeaseRecoveryService::RECOVERABILITY_TERMINAL) {
                $terminal[] = $item;
            }
        }

        $status = $recoverable !== []
            ? 'fleet_resume_rollup_recovery_required'
            : ($active !== [] ? 'fleet_resume_rollup_active_workers_present' : 'fleet_resume_rollup_clear');
        $safeActor = $this->safeCommandToken($actor, 'operator');

        $rollup = [
            'schema_version' => self::FLEET_RESUME_ROLLUP_SCHEMA_VERSION,
            'status' => $status,
            'actor' => $safeActor,
            'requested_queue_tags' => $queueTags,
            'active_lease_count' => count($active),
            'recoverable_task_count' => count($recoverable),
            'terminal_task_count' => count($terminal),
            'resume_attention_required' => $recoverable !== [],
            'can_recover_from_rollup' => false,
            'can_claim_from_rollup' => false,
            'can_complete_from_rollup' => false,
            'recoverable_task_summaries' => array_map(
                fn (array $item): array => $this->resumeSummary($item, $safeActor),
                array_slice($recoverable, 0, 10),
            ),
            'active_task_summaries' => array_map(
                fn (array $item): array => $this->resumeSummary($item, $safeActor),
                array_slice($active, 0, 10),
            ),
            'operator_sequence' => $recoverable !== []
                ? ['run_recovery_command_for_each_recoverable_packet', 'rerun_health_digest', 'claim_fresh_lease_before_editing', 'regenerate_one_shot_packet_after_claim']
                : ($active !== []
                    ? ['wait_for_active_workers_or_inspect_leases', 'renew_active_lease_from_worker_terminal_if_needed', 'recover_only_after_expiry_or_orphan']
                    : ['no_resume_action_required']),
            'resume_invariants' => [
                'never_reuse_previous_lease_id',
                'recover_before_reclaiming_expired_or_orphaned_claim',
                'claim_fresh_lease_before_editing_files',
                'regenerate_one_shot_worker_packet_after_fresh_claim',
                'complete_only_with_structured_completion_evidence_json',
            ],
            'non_execution_guarantees' => [
                'fleet_resume_rollup_is_read_only',
                'fleet_resume_rollup_does_not_recover_leases',
                'fleet_resume_rollup_does_not_claim_tasks',
                'fleet_resume_rollup_does_not_create_or_renew_leases',
                'fleet_resume_rollup_does_not_complete_tasks',
                'fleet_resume_rollup_does_not_start_terminals',
                'fleet_resume_rollup_does_not_call_provider',
                'fleet_resume_rollup_does_not_spend_tokens',
            ],
        ];
        $rollup['terminal_loop_fleet_resume_rollup_hash'] = $this->hashPayload($rollup);

        return $rollup;
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function resumeSummary(array $item, string $actor): array
    {
        $taskPacketId = (string) ($item['task_packet_id'] ?? '');
        $classification = (string) ($item['classification'] ?? '');
        $recoverable = (bool) ($item['recoverable'] ?? false);

        return [
            'task_packet_id' => $taskPacketId,
            'queue_status' => (string) ($item['queue_status'] ?? ''),
            'queue_tags' => array_values(array_map('strval', (array) ($item['queue_tags'] ?? []))),
            'lease_id' => (string) ($item['lease_id'] ?? ''),
            'lease_status' => (string) ($item['lease_status'] ?? ''),
            'lease_expires_at_unix' => (int) ($item['lease_expires_at_unix'] ?? 0),
            'classification' => $classification,
            'recoverable' => $recoverable,
            'safe_next_action' => $recoverable ? 'recover_then_claim_fresh_lease' : 'do_not_steal_active_lease',
            'recover_command' => $recoverable
                ? 'php artisan atlas:ai:self-construction --agent-control-plane-task-lease-recovery-status --packet='.$taskPacketId.' --actor='.$actor.' --reason=terminal_loop_resume_rollup'.$this->queueTagArgs($this->recordQueueTags($item)).' --json'
                : '',
            'resume_packet_command' => 'php artisan atlas:ai:self-construction --agent-control-plane-task-lease-recovery-status --packet='.$taskPacketId.' --actor='.$actor.$this->queueTagArgs($this->recordQueueTags($item)).' --json',
            'fresh_claim_required_before_work' => true,
        ];
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  list<string>  $queueTags
     */
    private function classificationMatchesTags(array $item, array $queueTags): bool
    {
        if ($queueTags === []) {
            return true;
        }

        return array_diff($queueTags, array_map('strval', (array) ($item['queue_tags'] ?? []))) === [];
    }

    /**
     * @param  list<string>  $queueTags
     * @return array<string, mixed>
     */
    private function fleetEvidenceRollup(AgentControlPlaneTaskPacketQueueRepository $queue, array $queueTags): array
    {
        $completedRecords = $this->listQueueRecords($queue, 'completed_dry_run', $queueTags);
        $validEvidence = 0;
        $missingCompletionReceipt = 0;
        $invalidCompletionEvidence = 0;
        $receiptHashes = [];
        $evidenceHashes = [];
        $taskSummaries = [];

        foreach ($completedRecords as $record) {
            $completionReceipt = $this->completionReceipt($record);
            $taskPacketId = (string) ($record['task_packet_id'] ?? '');
            if ($completionReceipt === []) {
                $missingCompletionReceipt++;
                $taskSummaries[] = [
                    'task_packet_id' => $taskPacketId,
                    'status' => 'missing_completion_receipt',
                    'evidence_hash' => '',
                    'receipt_hash' => '',
                ];

                continue;
            }

            $receiptHash = (string) ($completionReceipt['receipt_hash'] ?? '');
            $evidenceHash = strtolower((string) ($completionReceipt['evidence_hash'] ?? ''));
            $evidenceDigest = strtolower((string) ($completionReceipt['evidence_digest'] ?? ''));
            $valid = (bool) ($completionReceipt['structured_completion_evidence_valid'] ?? false)
                && (string) ($completionReceipt['evidence_validation_status'] ?? '') === 'valid'
                && preg_match('/^[a-f0-9]{64}$/', $evidenceHash) === 1
                && preg_match('/^[a-f0-9]{64}$/', $evidenceDigest) === 1;

            if ($receiptHash !== '') {
                $receiptHashes[] = $receiptHash;
            }
            if ($evidenceHash !== '') {
                $evidenceHashes[] = $evidenceHash;
            }
            if ($valid) {
                $validEvidence++;
            } else {
                $invalidCompletionEvidence++;
            }

            $taskSummaries[] = [
                'task_packet_id' => $taskPacketId,
                'status' => $valid ? 'valid_completion_evidence' : 'invalid_completion_evidence',
                'evidence_hash' => $evidenceHash,
                'receipt_hash' => $receiptHash,
                'evidence_validation_hash' => (string) ($completionReceipt['evidence_validation_hash'] ?? ''),
            ];
        }

        $attentionCount = $missingCompletionReceipt + $invalidCompletionEvidence;
        $rollup = [
            'schema_version' => self::FLEET_EVIDENCE_ROLLUP_SCHEMA_VERSION,
            'status' => $completedRecords === []
                ? 'fleet_evidence_rollup_no_completed_tasks'
                : ($attentionCount === 0 ? 'fleet_evidence_rollup_green' : 'fleet_evidence_rollup_attention_required'),
            'requested_queue_tags' => $queueTags,
            'completed_dry_run_task_count' => count($completedRecords),
            'valid_completion_evidence_count' => $validEvidence,
            'missing_completion_receipt_count' => $missingCompletionReceipt,
            'invalid_completion_evidence_count' => $invalidCompletionEvidence,
            'attention_required_count' => $attentionCount,
            'ready_for_operator_review' => count($completedRecords) > 0 && $attentionCount === 0,
            'completion_receipt_hashes' => array_values(array_unique(array_filter($receiptHashes))),
            'evidence_hashes' => array_values(array_unique(array_filter($evidenceHashes))),
            'recent_completed_task_summaries' => array_slice($taskSummaries, -10),
            'operator_review_requirements' => [
                'verify_completion_evidence_json_for_each_completed_packet',
                'verify_git_status_short_and_git_diff_check_result_are_present',
                'verify_tests_or_gates_result_is_green',
                'do_not_promote_runtime_or_os_completion_from_rollup_alone',
            ],
            'non_execution_guarantees' => [
                'fleet_evidence_rollup_is_read_only',
                'fleet_evidence_rollup_does_not_append_receipts',
                'fleet_evidence_rollup_does_not_change_queue_status',
                'fleet_evidence_rollup_does_not_release_leases',
                'fleet_evidence_rollup_does_not_mark_real_completion',
            ],
        ];
        $rollup['terminal_loop_fleet_evidence_rollup_hash'] = $this->hashPayload($rollup);

        return $rollup;
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>
     */
    private function completionReceipt(array $record): array
    {
        $receipts = array_reverse((array) ($record['receipts'] ?? []));
        foreach ($receipts as $receipt) {
            if (is_array($receipt) && (string) ($receipt['receipt_kind'] ?? '') === 'dry_run_completion_recorded') {
                return $receipt;
            }
        }

        return [];
    }

    /**
     * @param  list<string>  $queueTags
     * @param  array<string, string>  $commands
     * @return array<string, mixed>
     */
    private function fleetReplenishmentPlan(
        string $actor,
        array $queueTags,
        int $targetMinClaimable,
        int $maxNewTasks,
        int $claimableCount,
        int $recoverableCount,
        bool $tagFilteredSupplyGap,
        int $hiddenClaimableOutsideRequestedTags,
        array $commands,
    ): array {
        $requiredNewTaskCount = max(0, $targetMinClaimable - $claimableCount);
        $boundedNewTaskCount = min($requiredNewTaskCount, $maxNewTasks);
        $shouldReplenishNow = $recoverableCount === 0 && $requiredNewTaskCount > 0;
        $status = $recoverableCount > 0
            ? 'fleet_replenishment_blocked_recover_leases_first'
            : ($shouldReplenishNow ? 'fleet_replenishment_required' : 'fleet_replenishment_not_required');

        $plan = [
            'schema_version' => self::FLEET_REPLENISHMENT_PLAN_SCHEMA_VERSION,
            'status' => $status,
            'actor' => $this->safeCommandToken($actor, 'operator'),
            'requested_queue_tags' => $queueTags,
            'target_min_claimable_tasks' => $targetMinClaimable,
            'claimable_task_count' => $claimableCount,
            'required_new_task_count' => $requiredNewTaskCount,
            'bounded_new_task_count' => $boundedNewTaskCount,
            'max_new_tasks' => $maxNewTasks,
            'should_replenish_now' => $shouldReplenishNow,
            'recover_before_replenishment' => $recoverableCount > 0,
            'tag_filtered_supply_gap' => $tagFilteredSupplyGap,
            'hidden_claimable_outside_requested_tags' => $hiddenClaimableOutsideRequestedTags,
            'can_replenish_from_digest' => false,
            'can_start_terminals_from_digest' => false,
            'can_claim_from_digest' => false,
            'recommended_sequence' => $recoverableCount > 0
                ? ['recover_stale_or_orphaned_leases', 'rerun_health_digest', 'replenish_if_still_below_target']
                : ($shouldReplenishNow
                    ? ['run_replenishment_command', 'rerun_health_digest', 'start_fleet_only_when_launch_plan_ready']
                    : ['rerun_health_digest_before_next_batch', 'start_or_continue_fleet']),
            'commands' => [
                'recover_first' => $commands['inspect_or_recover_leases'],
                'replenish_tasks' => $commands['replenish_tasks'],
                'recheck_health_digest' => $commands['terminal_loop_health_digest'],
                'preview_bootstrap_after_replenishment' => $commands['preview_bootstrap'],
                'execute_bootstrap_after_replenishment' => $commands['execute_bootstrap'],
            ],
            'post_replenishment_acceptance' => [
                'health_digest_status_ready_or_actionable',
                'claimable_task_count_at_or_above_target_or_blocker_explained',
                'recoverable_lease_count_zero_before_start',
                'fleet_launch_plan_ready_before_starting_new_terminals',
                'terminal_loop_fleet_launch_plan_hash_changed_or_queue_state_explained',
            ],
            'stop_conditions' => [
                'recoverable_leases_present',
                'max_new_tasks_zero',
                'all_candidate_replenishment_seeds_already_active',
                'scope_validation_blocks_candidate_packet',
                'operator_requests_stop',
            ],
            'non_execution_guarantees' => [
                'fleet_replenishment_plan_is_read_only',
                'fleet_replenishment_plan_does_not_call_auto_replenishment',
                'fleet_replenishment_plan_does_not_claim_tasks',
                'fleet_replenishment_plan_does_not_create_or_renew_leases',
                'fleet_replenishment_plan_does_not_start_terminals',
                'fleet_replenishment_plan_does_not_call_provider',
                'fleet_replenishment_plan_does_not_spend_tokens',
            ],
        ];
        $plan['terminal_loop_fleet_replenishment_plan_hash'] = $this->hashPayload($plan);

        return $plan;
    }

    /**
     * @param  list<string>  $queueTags
     * @return array<string, mixed>
     */
    private function fleetLaunchPlan(
        string $actor,
        array $queueTags,
        int $targetMinClaimable,
        int $maxNewTasks,
        int $claimableCount,
        int $activeLeaseCount,
        int $recoverableCount,
        int $hiddenClaimableOutsideRequestedTags,
        bool $tagFilteredSupplyGap,
        string $recommendedAction,
        bool $safeToStartNewWorker,
        array $workerEligibility,
    ): array {
        $recommendedTerminalCount = $safeToStartNewWorker
            ? max(1, min(6, $claimableCount, $targetMinClaimable))
            : 0;
        $blockedReasons = $this->fleetBlockedReasons(
            claimableCount: $claimableCount,
            recoverableCount: $recoverableCount,
            hiddenClaimableOutsideRequestedTags: $hiddenClaimableOutsideRequestedTags,
            tagFilteredSupplyGap: $tagFilteredSupplyGap,
            recommendedAction: $recommendedAction,
            workerEligibility: $workerEligibility,
        );
        $safeActorBase = $this->safeCommandToken($actor, 'operator');
        $terminals = [];

        for ($index = 1; $index <= $recommendedTerminalCount; $index++) {
            $terminalActor = $safeActorBase.'-fleet-'.str_pad((string) $index, 2, '0', STR_PAD_LEFT);
            $terminalCommands = $this->commands($terminalActor, $targetMinClaimable, $maxNewTasks, $queueTags);

            $terminals[] = [
                'terminal_index' => $index,
                'actor' => $terminalActor,
                'queue_tags' => $queueTags,
                'lane_mode' => $queueTags === [] ? 'shared_untagged_queue' : 'shared_requested_queue_tags',
                'one_terminal_one_packet_at_a_time' => true,
                'claim_strategy' => 'claim_exactly_one_packet_then_work_renew_evidence_complete',
                'preview_bootstrap_command' => $terminalCommands['preview_bootstrap'],
                'execute_bootstrap_command' => $terminalCommands['execute_bootstrap'],
                'recover_before_retry_command' => $terminalCommands['inspect_or_recover_leases'],
                'queue_inspection_command' => $terminalCommands['inspect_queue'],
                'lease_inspection_command' => $terminalCommands['inspect_leases'],
            ];
        }

        $plan = [
            'schema_version' => self::FLEET_LAUNCH_PLAN_SCHEMA_VERSION,
            'status' => $safeToStartNewWorker ? 'fleet_launch_plan_ready' : 'fleet_launch_plan_blocked',
            'actor_base' => $safeActorBase,
            'requested_queue_tags' => $queueTags,
            'safe_to_start_now' => $safeToStartNewWorker,
            'can_execute_from_digest' => false,
            'can_claim_from_digest' => false,
            'can_create_or_renew_leases_from_digest' => false,
            'can_complete_from_digest' => false,
            'recommended_terminal_count' => $recommendedTerminalCount,
            'max_recommended_terminal_count' => 6,
            'target_min_claimable_tasks' => $targetMinClaimable,
            'claimable_task_count' => $claimableCount,
            'active_lease_count' => $activeLeaseCount,
            'recoverable_lease_count' => $recoverableCount,
            'blocked_reasons' => $blockedReasons,
            'worker_task_eligibility' => [
                'status' => (string) ($workerEligibility['status'] ?? ''),
                'checked_candidate_task_count' => (int) ($workerEligibility['checked_candidate_task_count'] ?? 0),
                'violation_count' => (int) ($workerEligibility['violation_count'] ?? 0),
                'blocked_reasons' => (array) ($workerEligibility['blocked_reasons'] ?? []),
                'worker_task_eligibility_hash' => (string) ($workerEligibility['worker_task_eligibility_hash'] ?? ''),
            ],
            'start_policy' => [
                'start_only_when_recoverable_lease_count_is_zero' => true,
                'start_only_when_claimable_task_count_is_positive' => true,
                'start_only_when_worker_task_eligibility_available' => true,
                'replenish_before_start_when_below_target' => $claimableCount < $targetMinClaimable,
                'recover_before_start_when_recoverable_leases_exist' => $recoverableCount > 0,
                'change_tags_or_replenish_when_tag_filtered_supply_gap' => $tagFilteredSupplyGap,
                'operator_must_start_terminals_manually' => true,
            ],
            'terminal_assignments' => $terminals,
            'copy_paste_terminal_commands' => array_values(array_map(
                static fn (array $terminal): string => (string) $terminal['execute_bootstrap_command'],
                $terminals,
            )),
            'post_launch_observability_commands' => [
                'health_digest' => $this->commands($safeActorBase, $targetMinClaimable, $maxNewTasks, $queueTags)['terminal_loop_health_digest'],
                'queue_inspection' => $this->commands($safeActorBase, $targetMinClaimable, $maxNewTasks, $queueTags)['inspect_queue'],
                'lease_inspection' => $this->commands($safeActorBase, $targetMinClaimable, $maxNewTasks, $queueTags)['inspect_leases'],
                'multi_agent_certification' => $this->commands($safeActorBase, $targetMinClaimable, $maxNewTasks, $queueTags)['multi_agent_certification'],
            ],
            'loop_invariants' => [
                'each_terminal_claims_at_most_one_packet_before_returning_to_bootstrap',
                'leases_must_be_renewed_or_recovered_before_reclaim',
                'completion_requires_structured_evidence_json_and_evidence_hash',
                'no_terminal_may_work_outside_packet_allowed_files',
                'failed_or_stale_work_returns_through_recovery_before_new_claims',
                'digest_and_plan_are_read_only_and_never_dispatch',
            ],
            'forbidden_shortcuts' => [
                'do_not_share_one_claimed_packet_between_terminals',
                'do_not_complete_without_completion_evidence_json',
                'do_not_ignore_recoverable_leases',
                'do_not_change_queue_tags_to_steal_unrelated_lanes',
                'do_not_treat_this_plan_as_os_completion_or_runtime_promotion',
            ],
        ];
        $plan['terminal_loop_fleet_launch_plan_hash'] = $this->hashPayload($plan);

        return $plan;
    }

    /**
     * @param  list<string>  $queueTags
     * @param  array<string, mixed>  $launchPlan
     * @param  array<string, mixed>  $replenishmentPlan
     * @param  array<string, mixed>  $operatorHandoff
     * @param  array<string, mixed>  $cycleSupervisor
     * @return array<string, mixed>
     */
    private function fleetLaunchRunbook(
        string $actor,
        array $queueTags,
        array $launchPlan,
        array $replenishmentPlan,
        array $operatorHandoff,
        array $cycleSupervisor,
    ): array {
        $terminalAssignments = (array) data_get($launchPlan, 'terminal_assignments', []);
        $terminalSteps = array_values(array_map(
            static fn (array $terminal): array => [
                'step_id' => 'start_terminal_'.str_pad((string) ((int) ($terminal['terminal_index'] ?? 0)), 2, '0', STR_PAD_LEFT),
                'terminal_index' => (int) ($terminal['terminal_index'] ?? 0),
                'actor' => (string) ($terminal['actor'] ?? ''),
                'queue_tags' => (array) ($terminal['queue_tags'] ?? []),
                'command' => (string) ($terminal['execute_bootstrap_command'] ?? ''),
                'preview_command' => (string) ($terminal['preview_bootstrap_command'] ?? ''),
                'recover_before_retry_command' => (string) ($terminal['recover_before_retry_command'] ?? ''),
                'one_terminal_one_packet_at_a_time' => (bool) ($terminal['one_terminal_one_packet_at_a_time'] ?? false),
            ],
            $terminalAssignments,
        ));
        $ready = (string) data_get($launchPlan, 'status') === 'fleet_launch_plan_ready'
            && (bool) data_get($launchPlan, 'safe_to_start_now')
            && $terminalSteps !== [];

        $runbook = [
            'schema_version' => self::FLEET_LAUNCH_RUNBOOK_SCHEMA_VERSION,
            'status' => $ready ? 'fleet_launch_runbook_ready' : 'fleet_launch_runbook_blocked',
            'actor' => $this->safeCommandToken($actor, 'operator'),
            'queue_tags' => $queueTags,
            'can_resume_without_chat_history' => true,
            'safe_to_copy_after_operator_review' => $ready,
            'terminal_count' => count($terminalSteps),
            'max_safe_parallel_terminals' => (int) data_get($cycleSupervisor, 'operator_loop_contract.max_safe_parallel_terminals', 6),
            'terminal_steps' => $terminalSteps,
            'ordered_operator_sequence' => array_values(array_filter([
                data_get($replenishmentPlan, 'should_replenish_now') ? 'run_replenishment_command_before_launch' : null,
                'rerun_health_digest_before_launch',
                $ready ? 'copy_each_terminal_command_into_separate_terminal' : null,
                $ready ? 'wait_for_each_worker_to_return_to_bootstrap_or_evidence_review' : null,
                'rerun_post_launch_health_digest',
                'inspect_queue_and_leases',
                'run_multi_agent_loop_certification_if_needed',
            ])),
            'copy_paste_terminal_commands' => (array) data_get($launchPlan, 'copy_paste_terminal_commands', []),
            'post_launch_observability_commands' => (array) data_get($launchPlan, 'post_launch_observability_commands', []),
            'operator_handoff_primary_command' => (string) data_get($operatorHandoff, 'primary_command', ''),
            'cycle_supervisor_next_command' => (string) data_get($cycleSupervisor, 'next_command', ''),
            'resume_after_interruption' => [
                'status' => 'resume_from_health_digest',
                'resume_command' => (string) data_get($launchPlan, 'post_launch_observability_commands.health_digest', ''),
                'can_resume_without_chat_history' => true,
                'requires_fresh_health_digest_before_starting_more_terminals' => true,
                'recover_before_any_new_claim' => (bool) data_get($cycleSupervisor, 'operator_loop_contract.recover_before_any_new_claim', false),
            ],
            'operator_checks_before_starting' => [
                'confirm_no_recoverable_leases',
                'confirm_terminal_count_matches_recommended_terminal_count',
                'confirm_each_command_contains_requested_queue_tags',
                'confirm_worker_task_eligibility_status_available',
                'confirm_no_parallel_session_changed_the_lane_since_digest',
            ],
            'stop_conditions' => [
                'any_terminal_reports_scope_violation',
                'any_terminal_reports_evidence_hash_mismatch',
                'recoverable_leases_appear',
                'health_digest_no_longer_ready',
                'operator_requests_stop',
            ],
            'source_hashes' => [
                'fleet_launch_plan_hash' => (string) data_get($launchPlan, 'terminal_loop_fleet_launch_plan_hash', ''),
                'fleet_replenishment_plan_hash' => (string) data_get($replenishmentPlan, 'terminal_loop_fleet_replenishment_plan_hash', ''),
                'operator_handoff_hash' => (string) data_get($operatorHandoff, 'terminal_loop_fleet_operator_handoff_hash', ''),
                'cycle_supervisor_hash' => (string) data_get($cycleSupervisor, 'terminal_loop_cycle_supervisor_hash', ''),
            ],
            'can_start_terminals_from_runbook' => false,
            'can_execute_commands_from_runbook' => false,
            'can_claim_from_runbook' => false,
            'can_complete_from_runbook' => false,
            'can_call_provider_from_runbook' => false,
            'can_spend_tokens_from_runbook' => false,
            'completion_real_allowed' => false,
        ];
        $runbook['terminal_loop_fleet_launch_runbook_hash'] = $this->hashPayload($runbook);

        return $runbook;
    }

    /**
     * @return list<string>
     */
    private function fleetBlockedReasons(
        int $claimableCount,
        int $recoverableCount,
        int $hiddenClaimableOutsideRequestedTags,
        bool $tagFilteredSupplyGap,
        string $recommendedAction,
        array $workerEligibility,
    ): array {
        $reasons = [];

        if ($recoverableCount > 0) {
            $reasons[] = 'recoverable_leases_must_be_recovered_before_starting_new_terminals';
        }
        if ($claimableCount === 0) {
            $reasons[] = 'no_claimable_packets_for_requested_lane';
        }
        if ($tagFilteredSupplyGap) {
            $reasons[] = 'claimable_packets_exist_outside_requested_tags_'.$hiddenClaimableOutsideRequestedTags;
        }
        if ($recommendedAction === 'replenish_task_supply') {
            $reasons[] = 'task_supply_below_target_replenish_before_launch';
        }
        if ($recommendedAction === 'wait_for_active_workers_or_inspect_leases') {
            $reasons[] = 'active_workers_present_wait_or_inspect_before_launch';
        }
        if ($recommendedAction === 'inspect_canonical_sources') {
            $reasons[] = 'canonical_sources_need_inspection_before_launch';
        }
        if ((string) ($workerEligibility['status'] ?? '') === 'blocked') {
            $reasons[] = 'worker_task_eligibility_blocked_before_worker_launch';
            foreach ((array) ($workerEligibility['blocked_reasons'] ?? []) as $reason) {
                $reasons[] = 'worker_task_eligibility_'.$reason;
            }
        }

        return array_values(array_unique($reasons));
    }

    /**
     * @return array<string, bool>
     */
    public function runtimeFlags(): array
    {
        return [
            'runtime_execution_allowed' => false,
            'dispatch_allowed' => false,
            'provider_call_allowed' => false,
            'token_spend_allowed' => false,
            'self_programming_allowed' => false,
            'ledger_write_allowed' => false,
            'completion_real_allowed' => false,
        ];
    }

    private function recommendedAction(
        int $recoverableCount,
        int $claimableCount,
        int $activeLeaseCount,
        int $targetMinClaimable,
        bool $workerEligibilityBlocked,
    ): string {
        if ($workerEligibilityBlocked) {
            return 'inspect_worker_task_eligibility_before_launch';
        }
        if ($recoverableCount > 0) {
            return 'recover_stale_or_orphaned_leases';
        }
        if ($claimableCount < $targetMinClaimable) {
            return 'replenish_task_supply';
        }
        if ($claimableCount > 0) {
            return 'continue_or_start_terminal_workers';
        }
        if ($activeLeaseCount > 0) {
            return 'wait_for_active_workers_or_inspect_leases';
        }

        return 'inspect_canonical_sources';
    }

    /**
     * @param  list<string>  $queueTags
     * @return array<string, string>
     */
    private function commands(string $actor, int $targetMinClaimable, int $maxNewTasks, array $queueTags): array
    {
        return $this->commandComposer()->commands($actor, $targetMinClaimable, $maxNewTasks, $queueTags);
    }

    /**
     * @param  list<string>  $queueTags
     */
    private function queueTagArgs(array $queueTags): string
    {
        return $this->commandComposer()->queueTagArgs($queueTags);
    }

    private function safeCommandToken(string $value, string $default): string
    {
        return $this->commandComposer()->safeCommandToken($value, $default);
    }

    private function commandComposer(): \App\Services\Ai\SelfConstruction\ControlPlane\TerminalLoopHealthDigestCommandComposer
    {
        return $this->commandComposer ??= new \App\Services\Ai\SelfConstruction\ControlPlane\TerminalLoopHealthDigestCommandComposer;
    }

    /**
     * @param  array<string, mixed>  $item
     * @return list<string>
     */
    private function recordQueueTags(array $item): array
    {
        return array_values(array_map('strval', (array) ($item['queue_tags'] ?? [])));
    }

    /**
     * @param  list<string>  $queueTags
     */
    private function countQueueRecords(AgentControlPlaneTaskPacketQueueRepository $queue, string $status, array $queueTags): int
    {
        return count($this->listQueueRecords($queue, $status, $queueTags));
    }

    /**
     * @param  list<string>  $queueTags
     * @return list<array<string, mixed>>
     */
    private function listQueueRecords(AgentControlPlaneTaskPacketQueueRepository $queue, string $status, array $queueTags): array
    {
        if ($queueTags === []) {
            return $queue->list(['status' => $status]);
        }

        return $queue->list([
            'status' => $status,
            'tag' => $queueTags[0],
            'tags' => $queueTags,
        ]);
    }

    /**
     * @param  list<string>  $queueTags
     * @return array<string, mixed>
     */
    private function workerTaskEligibility(AgentControlPlaneTaskPacketQueueRepository $queue, array $queueTags): array
    {
        $records = [];
        foreach (['queued', 'claimable', 'claimed', 'lease_expired'] as $status) {
            foreach ($this->listQueueRecords($queue, $status, $queueTags) as $record) {
                $id = (string) ($record['task_packet_id'] ?? '');
                if ($id !== '') {
                    $records[$id] = $record;
                }
            }
        }

        $violations = [];
        foreach (array_values($records) as $record) {
            $taskPacketId = (string) ($record['task_packet_id'] ?? '');
            $recordStatus = (string) ($record['status'] ?? '');
            $reference = (string) data_get($record, 'task_packet.continuation_context.auto_replenishment_reference', '');
            if ((bool) data_get($record, 'task_packet.continuation_context.worker_executable', true) === false) {
                $violations[] = ['code' => 'worker_candidate_task_not_worker_executable', 'task_packet_id' => $taskPacketId, 'status' => $recordStatus];
            }
            if ((bool) data_get($record, 'task_packet.continuation_context.operator_handoff_required', false)) {
                $violations[] = ['code' => 'worker_candidate_task_requires_operator_handoff', 'task_packet_id' => $taskPacketId, 'status' => $recordStatus];
            }
            if (in_array($reference, self::OPERATOR_ONLY_COMPLETION_CRITERIA, true)) {
                $violations[] = [
                    'code' => 'worker_candidate_task_references_operator_only_completion_blocker',
                    'task_packet_id' => $taskPacketId,
                    'status' => $recordStatus,
                    'reference' => $reference,
                ];
            }
            foreach ([
                'dispatch_allowed',
                'provider_call_allowed',
                'token_spend_allowed',
                'self_programming_allowed',
                'ledger_write_allowed',
                'runtime_execution_allowed',
                'completion_real_allowed',
            ] as $flag) {
                if ((bool) data_get($record, $flag, false)) {
                    $violations[] = [
                        'code' => 'worker_candidate_task_runtime_flag_true',
                        'task_packet_id' => $taskPacketId,
                        'status' => $recordStatus,
                        'flag' => $flag,
                    ];
                }
            }
        }

        $eligibility = [
            'schema_version' => 'atlas.self_construction.agent_control_plane_terminal_loop_worker_task_eligibility.v1',
            'status' => $violations === [] ? 'available' : 'blocked',
            'queue_tags' => $queueTags,
            'candidate_statuses' => ['queued', 'claimable', 'claimed', 'lease_expired'],
            'checked_candidate_task_count' => count($records),
            'blocked_reasons' => array_values(array_unique(array_map(
                static fn (array $violation): string => (string) ($violation['code'] ?? ''),
                $violations,
            ))),
            'violations' => $violations,
            'violation_count' => count($violations),
            'worker_launch_allowed_after_eligibility' => $violations === [],
            'can_claim_from_eligibility' => false,
            'can_replenish_from_eligibility' => false,
            'can_dispatch_from_eligibility' => false,
            'can_call_provider_from_eligibility' => false,
        ];
        $eligibility['worker_task_eligibility_hash'] = $this->hashPayload($eligibility);

        return $eligibility;
    }

    /**
     * @param  list<array<string, mixed>>  $classifications
     * @param  list<string>  $queueTags
     */
    private function classificationCount(array $classifications, string $classification, array $queueTags): int
    {
        return count(array_filter(
            $classifications,
            function (array $item) use ($classification, $queueTags): bool {
                if ((string) ($item['classification'] ?? '') !== $classification) {
                    return false;
                }
                if ($queueTags === []) {
                    return true;
                }

                $tags = (array) data_get($item, 'queue_tags', []);

                return array_diff($queueTags, array_map('strval', $tags)) === [];
            },
        ));
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function stringOption(array $options, string $key, string $default): string
    {
        return $this->payloadNormalizer()->stringOption($options, $key, $default);
    }

    /**
     * @param  array<int, mixed>  $values
     * @return list<string>
     */
    private function stringList(array $values): array
    {
        return $this->payloadNormalizer()->stringList($values);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function hashPayload(array $payload): string
    {
        return $this->payloadNormalizer()->hashPayload($payload);
    }

    private function payloadNormalizer(): \App\Services\Ai\SelfConstruction\ControlPlane\TerminalLoopHealthDigestPayloadNormalizer
    {
        return $this->payloadNormalizer ??= new \App\Services\Ai\SelfConstruction\ControlPlane\TerminalLoopHealthDigestPayloadNormalizer;
    }

    private function queueRepo(): AgentControlPlaneTaskPacketQueueRepository
    {
        return $this->queue ?? new AgentControlPlaneTaskPacketQueueRepository;
    }

    private function recoveryService(): AgentControlPlaneTaskLeaseRecoveryService
    {
        return $this->recovery ?? new AgentControlPlaneTaskLeaseRecoveryService;
    }
}
