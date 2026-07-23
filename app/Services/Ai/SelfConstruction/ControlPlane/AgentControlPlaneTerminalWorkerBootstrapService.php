<?php

namespace App\Services\Ai\SelfConstruction\ControlPlane;

use App\Services\Ai\SelfConstruction\TerminalWorkerBootstrap\AgentControlPlaneTerminalLoopGuidanceBuilder;
use App\Services\Ai\SelfConstruction\TerminalWorkerBootstrap\AgentControlPlaneTerminalWorkerCommandFormatter;
use App\Services\Ai\SelfConstruction\TerminalWorkerBootstrap\AgentControlPlaneWorkerEligibilityGuard;
use Carbon\CarbonImmutable;

/**
 * One-command bootstrap for a terminal worker.
 *
 * This stitches the already-governed runtime pieces together:
 * auto-replenish the task queue, claim exactly one packet with a lease, then
 * build the one-shot worker packet for that lease. It still never runs the
 * worker, starts a process, invokes a provider, dispatches work or marks real
 * completion.
 */
use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskQueueOrchestrator;

final class AgentControlPlaneTerminalWorkerBootstrapService
{
    public const SCHEMA_VERSION = 'atlas.self_construction.agent_control_plane_terminal_worker_bootstrap.v1';

    public const MODE = 'persistent_local_agent_control_plane_terminal_worker_bootstrap';

    private const OPERATOR_ONLY_COMPLETION_CRITERIA = [
        'runtime_gap_matrix_all_runtime_y',
        'human_signed_os_complete_receipt_present',
        'end_to_end_real_provider_smoke_green',
    ];

    public function __construct(
        private readonly AgentControlPlaneTaskAutoReplenishmentService $replenishment,
        private readonly AgentControlPlaneTaskQueueOrchestrator $orchestrator,
        private readonly AgentControlPlaneOneShotWorkerPacketService $workerPacket,
        private readonly AgentControlPlaneTaskPacketQueueRepository $queue,
        private readonly AgentControlPlaneClaimLeaseRepository $leases,
    ) {}

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function bootstrap(array $context = [], array $options = []): array
    {
        $actor = trim((string) ($options['actor'] ?? 'codex'));
        $actor = $actor === '' ? 'codex' : $actor;
        $leaseMinutes = max(1, min(240, (int) ($options['lease_minutes'] ?? 30)));
        $targetMin = max(1, min(25, (int) ($options['target_min_claimable_tasks'] ?? 6)));
        $maxNew = max(0, min(25, (int) ($options['max_new_tasks'] ?? $targetMin)));
        $queueTags = $this->stringList((array) ($options['queue_tags'] ?? []));
        $previewOnly = (bool) ($options['preview_only'] ?? $options['terminal_worker_bootstrap_preview'] ?? false);

        if ($previewOnly) {
            return $this->preview($actor, $leaseMinutes, $targetMin, $maxNew, $queueTags);
        }

        $replenishment = $this->replenishment->replenish($context, [
            'target_min_claimable_tasks' => $targetMin,
            'max_new_tasks' => $maxNew,
            'actor' => $actor,
            'reason' => (string) ($options['reason'] ?? 'terminal_worker_bootstrap'),
            'queue_tags' => $queueTags,
        ]);

        $workerEligibility = $this->workerEligibilityGuard($queueTags);
        if (
            (int) ($workerEligibility['eligible_claimable_task_count'] ?? 0) === 0
            && (int) ($workerEligibility['violation_count'] ?? 0) === 0
            && $maxNew > 0
        ) {
            $replenishment = $this->replenishment->replenish(array_replace_recursive($context, [
                'terminal_bootstrap_probe' => [
                    'enabled' => true,
                    'namespace' => $queueTags === [] ? 'terminal_worker_bootstrap_'.$actor : 'terminal_worker_bootstrap_'.$queueTags[0],
                    'target_task_count' => $targetMin,
                ],
            ]), [
                'target_min_claimable_tasks' => $targetMin,
                'max_new_tasks' => $maxNew,
                'actor' => $actor,
                'reason' => (string) ($options['reason'] ?? 'terminal_worker_bootstrap').'_worker_supply_fallback',
                'queue_tags' => $queueTags,
            ]);
            $workerEligibility = $this->workerEligibilityGuard($queueTags);
        }
        if ((string) ($workerEligibility['status'] ?? '') === 'blocked') {
            return $this->blockedBeforeClaimPayload(
                actor: $actor,
                leaseMinutes: $leaseMinutes,
                targetMin: $targetMin,
                maxNew: $maxNew,
                queueTags: $queueTags,
                replenishment: $replenishment,
                workerEligibility: $workerEligibility,
            );
        }

        $claimableAfterReplenishment = count($this->claimableRecords($queueTags));
        if ($claimableAfterReplenishment < $targetMin && (int) data_get($replenishment, 'generated_task_count', 0) === 0 && $maxNew === 0) {
            return $this->blockedBeforeClaimPayload(
                actor: $actor,
                leaseMinutes: $leaseMinutes,
                targetMin: $targetMin,
                maxNew: $maxNew,
                queueTags: $queueTags,
                replenishment: $replenishment,
                workerEligibility: $workerEligibility,
                claimEvent: 'task_supply_below_target_blocked_before_claim',
                workerPacketBlockedReason: 'task_supply_below_target_blocked_before_claim',
                workerPacketScopeBlockers: [
                    'claimable_task_count_below_target_min',
                    'run_replenishment_and_recheck_health_digest_before_claim',
                ],
                claimReason: 'task_supply_below_target_guard_blocked',
            );
        }

        $claimFilters = [
            'ttl_seconds' => $leaseMinutes * 60,
        ];
        if ($queueTags !== []) {
            $claimFilters['tag'] = $queueTags[0];
            $claimFilters['tags'] = $queueTags;
        }
        $claim = $this->claimNext($context, $actor, $claimFilters);
        if ((string) ($claim['event'] ?? '') === 'no_claimable_task' && (int) ($claim['candidate_count'] ?? 0) > 0 && $maxNew > 0) {
            $replenishment = $this->replenishment->replenish(array_replace_recursive($context, [
                'terminal_bootstrap_probe' => [
                    'enabled' => true,
                    'namespace' => $queueTags === [] ? 'terminal_worker_bootstrap_'.$actor : 'terminal_worker_bootstrap_'.$queueTags[0],
                    'target_task_count' => $targetMin,
                ],
            ]), [
                'target_min_claimable_tasks' => $targetMin,
                'max_new_tasks' => $maxNew,
                'actor' => $actor,
                'reason' => (string) ($options['reason'] ?? 'terminal_worker_bootstrap').'_claim_conflict_worker_supply_fallback',
                'queue_tags' => $queueTags,
            ]);
            $workerEligibility = $this->workerEligibilityGuard($queueTags);
            $claim = $this->claimNext($context, $actor, $claimFilters);
        }
        $claimEvent = (string) ($claim['event'] ?? 'unknown');
        $workerPacket = [];
        if ($claimEvent === 'claimed') {
            $workerPacket = $this->workerPacket->generate([
                'task_packet_id' => (string) data_get($claim, 'task_packet_id', ''),
                'lease_id' => (string) data_get($claim, 'lease_id', ''),
                'actor' => $actor,
            ]);
        }

        $workerReady = (string) data_get($workerPacket, 'status', '') === 'ok';
        $blockedLeaseRelease = [];
        if ($claimEvent === 'claimed' && ! $workerReady) {
            $blockedLeaseRelease = $this->orchestrator->releaseLease(
                (string) data_get($claim, 'lease_id', ''),
                $actor,
                ['reason' => 'worker_packet_blocked_before_handoff'],
            );
        }
        $status = $claimEvent === 'claimed' && $workerReady ? 'ready_for_worker' : 'blocked';
        $nextWorkerCommand = $this->bootstrapCommand($actor, $targetMin, $maxNew, $queueTags);
        $queueLaneContract = $this->queueLaneContract($actor, $queueTags, $nextWorkerCommand);
        $terminalLoopOperatorCommands = $this->terminalLoopOperatorCommands(
            actor: $actor,
            taskPacketId: (string) data_get($claim, 'task_packet_id', ''),
            leaseId: (string) data_get($claim, 'lease_id', ''),
            completionCommand: (string) data_get($workerPacket, 'completion_command', ''),
            leaseRenewCommand: (string) data_get($workerPacket, 'lease_renew_command', ''),
            recoveryCommand: (string) data_get($workerPacket, 'resumption_contract.resume_commands.inspect_or_recover_current_packet', ''),
            bootstrapCommand: $nextWorkerCommand,
            targetMin: $targetMin,
            maxNew: $maxNew,
            queueTags: $queueTags,
            queueLaneContract: $queueLaneContract,
        );
        $terminalLoopResumptionCheckpoint = $this->terminalLoopResumptionCheckpoint(
            status: $status,
            actor: $actor,
            taskPacketId: (string) data_get($claim, 'task_packet_id', ''),
            leaseId: (string) data_get($claim, 'lease_id', ''),
            claimEvent: $claimEvent,
            oneShotWorkerPacketReady: $workerReady,
            terminalLoopOperatorCommands: $terminalLoopOperatorCommands,
            resumptionContract: (array) data_get($workerPacket, 'resumption_contract', []),
            previewOnly: false,
        );
        $terminalLoopIterationRunbook = $this->terminalLoopIterationRunbook(
            status: $status,
            actor: $actor,
            taskPacketId: (string) data_get($claim, 'task_packet_id', ''),
            leaseId: (string) data_get($claim, 'lease_id', ''),
            oneShotWorkerPacketReady: $workerReady,
            previewOnly: false,
            terminalLoopOperatorCommands: $terminalLoopOperatorCommands,
            terminalLoopResumptionCheckpoint: $terminalLoopResumptionCheckpoint,
        );
        $terminalLoopShellRecipe = $this->terminalLoopShellRecipe(
            actor: $actor,
            previewOnly: false,
            terminalLoopOperatorCommands: $terminalLoopOperatorCommands,
            terminalLoopIterationRunbook: $terminalLoopIterationRunbook,
        );

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'status' => $status,
            'generated_at' => CarbonImmutable::now()->toIso8601String(),
            'actor' => $actor,
            'lease_minutes' => $leaseMinutes,
            'target_min_claimable_tasks' => $targetMin,
            'max_new_tasks' => $maxNew,
            'queue_tags' => $queueTags,
            'queue_lane_contract' => $queueLaneContract,
            'never_stop_before_drain_contract' => [
                'contract' => 'never_stop_before_drain',
                'description' => 'Worker must never stop the loop based on comfortable-queue or sufficient-depth heuristics. Continue until the queue is truly drained: no_claimable_task triggers retry with replenishment, waiting_on_dependencies triggers retry, and give_back triggers pull-next. Only stop when explicitly told by the operator or when disabled.',
                'retry_on' => [
                    'no_claimable_task' => 'replenish_and_retry',
                    'waiting_on_dependencies' => 'retry_after_delay',
                    'give_back' => 'pull_next_task',
                ],
                'forbidden_stop_language' => [
                    'comfortable_queue',
                    'sufficient_depth',
                    'adequate_supply',
                ],
                'constraints' => [
                    'allowed_files_only' => true,
                    'one_task_at_a_time' => true,
                ],
            ],
            'claim_tag' => (string) ($claimFilters['tag'] ?? ''),
            'auto_replenishment_status' => (string) data_get($replenishment, 'status', 'unknown'),
            'auto_replenishment_hash' => (string) data_get($replenishment, 'auto_replenishment_hash', ''),
            'generated_task_count' => (int) data_get($replenishment, 'generated_task_count', 0),
            'claim_event' => $claimEvent,
            'runtime_claim_persisted' => $claimEvent === 'claimed',
            'task_packet_id' => (string) data_get($claim, 'task_packet_id', ''),
            'lease_id' => (string) data_get($claim, 'lease_id', ''),
            'one_shot_worker_packet_ready' => $workerReady,
            'worker_packet_blocked_reason' => $workerReady ? '' : (string) data_get($workerPacket, 'reason', ''),
            'worker_packet_scope_blockers' => (array) data_get($workerPacket, 'scope_blockers', []),
            'lease_released_after_worker_packet_blocked' => $blockedLeaseRelease !== [] && (string) data_get($blockedLeaseRelease, 'release.status', '') === 'ok',
            'blocked_worker_packet_lease_release' => $blockedLeaseRelease,
            'one_shot_packet_hash' => (string) data_get($workerPacket, 'one_shot_packet_hash', ''),
            'worker_prompt_goal_short' => (string) data_get($workerPacket, 'worker_prompt_goal_short', ''),
            'worker_prompt_full' => (string) data_get($workerPacket, 'worker_prompt_full', ''),
            'completion_command' => (string) data_get($workerPacket, 'completion_command', ''),
            'completion_evidence_template' => (array) data_get($workerPacket, 'completion_evidence_template', []),
            'completion_evidence_template_json' => (string) data_get($workerPacket, 'completion_evidence_template_json', ''),
            'lease_renew_command' => (string) data_get($workerPacket, 'lease_renew_command', ''),
            'resumption_contract' => (array) data_get($workerPacket, 'resumption_contract', []),
            'resume_after_interruption_command' => (string) data_get($workerPacket, 'resumption_contract.resume_commands.inspect_or_recover_current_packet', ''),
            'next_worker_command' => $nextWorkerCommand,
            'terminal_loop_operator_commands' => $terminalLoopOperatorCommands,
            'terminal_loop_resumption_checkpoint' => $terminalLoopResumptionCheckpoint,
            'terminal_loop_resumption_checkpoint_hash' => (string) ($terminalLoopResumptionCheckpoint['resumption_checkpoint_hash'] ?? ''),
            'terminal_loop_iteration_runbook' => $terminalLoopIterationRunbook,
            'terminal_loop_iteration_runbook_hash' => (string) ($terminalLoopIterationRunbook['iteration_runbook_hash'] ?? ''),
            'terminal_loop_shell_recipe' => $terminalLoopShellRecipe,
            'terminal_loop_shell_recipe_hash' => (string) ($terminalLoopShellRecipe['shell_recipe_hash'] ?? ''),
            'auto_replenishment' => $replenishment,
            'worker_task_eligibility_guard' => $workerEligibility,
            'claim' => $claim,
            'one_shot_worker_packet' => $workerPacket,
            'queue_summary' => $this->queue->registry(),
            'lease_summary' => [
                'active_lease_count' => count($this->leases->activeLeases()),
                'runtime_flags' => $this->leases->runtimeFlags(),
            ],
            'runtime_execution_allowed' => false,
            'dispatch_allowed' => false,
            'provider_call_allowed' => false,
            'token_spend_allowed' => false,
            'self_programming_allowed' => false,
            'ledger_write_allowed' => false,
            'completion_real_allowed' => false,
            'non_execution_guarantees' => [
                'terminal_worker_bootstrap_does_not_start_codex',
                'terminal_worker_bootstrap_does_not_call_codex_cli_or_app',
                'terminal_worker_bootstrap_does_not_spawn_subprocess',
                'terminal_worker_bootstrap_does_not_invoke_adapter',
                'terminal_worker_bootstrap_does_not_call_provider',
                'terminal_worker_bootstrap_does_not_dispatch_work',
                'terminal_worker_bootstrap_does_not_spend_tokens',
                'terminal_worker_bootstrap_does_not_enable_self_programming',
                'terminal_worker_bootstrap_does_not_write_ledger',
                'terminal_worker_bootstrap_does_not_mark_real_completion',
            ],
            'bootstrap_hash' => '',
        ];
        $payload['bootstrap_hash'] = $this->stableHash($payload);

        return $payload;
    }

    /**
     * @param  list<string>  $queueTags
     * @param  array<string, mixed>  $replenishment
     * @param  array<string, mixed>  $workerEligibility
     * @return array<string, mixed>
     */
    private function blockedBeforeClaimPayload(
        string $actor,
        int $leaseMinutes,
        int $targetMin,
        int $maxNew,
        array $queueTags,
        array $replenishment,
        array $workerEligibility,
        string $claimEvent = 'worker_task_eligibility_blocked_before_claim',
        string $workerPacketBlockedReason = 'worker_task_eligibility_blocked_before_claim',
        array $workerPacketScopeBlockers = [],
        string $claimReason = 'worker_task_eligibility_guard_blocked',
    ): array {
        $workerPacketScopeBlockers = $workerPacketScopeBlockers === []
            ? (array) ($workerEligibility['blocked_reasons'] ?? [])
            : $workerPacketScopeBlockers;
        $nextWorkerCommand = $this->bootstrapCommand($actor, $targetMin, $maxNew, $queueTags);
        $queueLaneContract = $this->queueLaneContract($actor, $queueTags, $nextWorkerCommand);
        $terminalLoopOperatorCommands = $this->terminalLoopOperatorCommands(
            actor: $actor,
            taskPacketId: '',
            leaseId: '',
            completionCommand: '',
            leaseRenewCommand: '',
            recoveryCommand: '',
            bootstrapCommand: $nextWorkerCommand,
            targetMin: $targetMin,
            maxNew: $maxNew,
            queueTags: $queueTags,
            queueLaneContract: $queueLaneContract,
        );
        $terminalLoopResumptionCheckpoint = $this->terminalLoopResumptionCheckpoint(
            status: 'blocked',
            actor: $actor,
            taskPacketId: '',
            leaseId: '',
            claimEvent: $claimEvent,
            oneShotWorkerPacketReady: false,
            terminalLoopOperatorCommands: $terminalLoopOperatorCommands,
            resumptionContract: [],
            previewOnly: false,
        );
        $terminalLoopIterationRunbook = $this->terminalLoopIterationRunbook(
            status: 'blocked',
            actor: $actor,
            taskPacketId: '',
            leaseId: '',
            oneShotWorkerPacketReady: false,
            previewOnly: false,
            terminalLoopOperatorCommands: $terminalLoopOperatorCommands,
            terminalLoopResumptionCheckpoint: $terminalLoopResumptionCheckpoint,
        );
        $terminalLoopShellRecipe = $this->terminalLoopShellRecipe(
            actor: $actor,
            previewOnly: false,
            terminalLoopOperatorCommands: $terminalLoopOperatorCommands,
            terminalLoopIterationRunbook: $terminalLoopIterationRunbook,
        );

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'status' => 'blocked',
            'generated_at' => CarbonImmutable::now()->toIso8601String(),
            'actor' => $actor,
            'lease_minutes' => $leaseMinutes,
            'target_min_claimable_tasks' => $targetMin,
            'max_new_tasks' => $maxNew,
            'queue_tags' => $queueTags,
            'queue_lane_contract' => $queueLaneContract,
            'never_stop_before_drain_contract' => [
                'contract' => 'never_stop_before_drain',
                'description' => 'Worker must never stop the loop based on comfortable-queue or sufficient-depth heuristics. Continue until the queue is truly drained: no_claimable_task triggers retry with replenishment, waiting_on_dependencies triggers retry, and give_back triggers pull-next. Only stop when explicitly told by the operator or when disabled.',
                'retry_on' => [
                    'no_claimable_task' => 'replenish_and_retry',
                    'waiting_on_dependencies' => 'retry_after_delay',
                    'give_back' => 'pull_next_task',
                ],
                'forbidden_stop_language' => [
                    'comfortable_queue',
                    'sufficient_depth',
                    'adequate_supply',
                ],
                'constraints' => [
                    'allowed_files_only' => true,
                    'one_task_at_a_time' => true,
                ],
            ],
            'claim_tag' => (string) ($queueTags[0] ?? ''),
            'auto_replenishment_status' => (string) data_get($replenishment, 'status', 'unknown'),
            'auto_replenishment_hash' => (string) data_get($replenishment, 'auto_replenishment_hash', ''),
            'generated_task_count' => (int) data_get($replenishment, 'generated_task_count', 0),
            'claim_event' => $claimEvent,
            'runtime_claim_persisted' => false,
            'task_packet_id' => '',
            'lease_id' => '',
            'one_shot_worker_packet_ready' => false,
            'worker_packet_blocked_reason' => $workerPacketBlockedReason,
            'worker_packet_scope_blockers' => $workerPacketScopeBlockers,
            'lease_released_after_worker_packet_blocked' => false,
            'blocked_worker_packet_lease_release' => [],
            'one_shot_packet_hash' => '',
            'worker_prompt_goal_short' => '',
            'worker_prompt_full' => '',
            'completion_command' => '',
            'completion_evidence_template' => [],
            'completion_evidence_template_json' => '',
            'lease_renew_command' => '',
            'resumption_contract' => [],
            'resume_after_interruption_command' => '',
            'next_worker_command' => $nextWorkerCommand,
            'terminal_loop_operator_commands' => $terminalLoopOperatorCommands,
            'terminal_loop_resumption_checkpoint' => $terminalLoopResumptionCheckpoint,
            'terminal_loop_resumption_checkpoint_hash' => (string) ($terminalLoopResumptionCheckpoint['resumption_checkpoint_hash'] ?? ''),
            'terminal_loop_iteration_runbook' => $terminalLoopIterationRunbook,
            'terminal_loop_iteration_runbook_hash' => (string) ($terminalLoopIterationRunbook['iteration_runbook_hash'] ?? ''),
            'terminal_loop_shell_recipe' => $terminalLoopShellRecipe,
            'terminal_loop_shell_recipe_hash' => (string) ($terminalLoopShellRecipe['shell_recipe_hash'] ?? ''),
            'auto_replenishment' => $replenishment,
            'worker_task_eligibility_guard' => $workerEligibility,
            'claim' => [
                'status' => 'blocked',
                'event' => $claimEvent,
                'reason' => $claimReason,
            ],
            'one_shot_worker_packet' => [],
            'queue_summary' => $this->queue->registry(),
            'lease_summary' => [
                'active_lease_count' => count($this->leases->activeLeases()),
                'runtime_flags' => $this->leases->runtimeFlags(),
            ],
            'runtime_execution_allowed' => false,
            'dispatch_allowed' => false,
            'provider_call_allowed' => false,
            'token_spend_allowed' => false,
            'self_programming_allowed' => false,
            'ledger_write_allowed' => false,
            'completion_real_allowed' => false,
            'non_execution_guarantees' => [
                'terminal_worker_bootstrap_worker_task_eligibility_guard_blocks_before_claim',
                'terminal_worker_bootstrap_task_supply_gate_blocks_before_claim',
                'terminal_worker_bootstrap_does_not_start_codex',
                'terminal_worker_bootstrap_does_not_call_codex_cli_or_app',
                'terminal_worker_bootstrap_does_not_spawn_subprocess',
                'terminal_worker_bootstrap_does_not_invoke_adapter',
                'terminal_worker_bootstrap_does_not_call_provider',
                'terminal_worker_bootstrap_does_not_dispatch_work',
                'terminal_worker_bootstrap_does_not_spend_tokens',
                'terminal_worker_bootstrap_does_not_enable_self_programming',
                'terminal_worker_bootstrap_does_not_write_ledger',
                'terminal_worker_bootstrap_does_not_mark_real_completion',
            ],
            'bootstrap_hash' => '',
        ];
        $payload['bootstrap_hash'] = $this->stableHash($payload);

        return $payload;
    }

    /**
     * Preview the next terminal bootstrap without replenishing or claiming.
     *
     * @param  list<string>  $queueTags
     * @return array<string, mixed>
     */
    private function preview(string $actor, int $leaseMinutes, int $targetMin, int $maxNew, array $queueTags): array
    {
        $claimable = $this->claimableRecords($queueTags, 10);
        $claimableCount = count($this->claimableRecords($queueTags));
        $wouldGenerateTaskCount = max(0, min($maxNew, $targetMin - $claimableCount));
        $wouldReplenish = $claimableCount < $targetMin && $wouldGenerateTaskCount > 0;
        $nextWorkerCommand = $this->bootstrapCommand($actor, $targetMin, $maxNew, $queueTags);
        $queueLaneContract = $this->queueLaneContract($actor, $queueTags, $nextWorkerCommand);
        $terminalLoopOperatorCommands = $this->terminalLoopOperatorCommands(
            actor: $actor,
            taskPacketId: '',
            leaseId: '',
            completionCommand: '',
            leaseRenewCommand: '',
            recoveryCommand: '',
            bootstrapCommand: $nextWorkerCommand,
            targetMin: $targetMin,
            maxNew: $maxNew,
            queueTags: $queueTags,
            queueLaneContract: $queueLaneContract,
        );
        $terminalLoopResumptionCheckpoint = $this->terminalLoopResumptionCheckpoint(
            status: $claimableCount > 0 ? 'preview_available' : 'preview_blocked_no_claimable_task',
            actor: $actor,
            taskPacketId: '',
            leaseId: '',
            claimEvent: 'preview_only_no_claim_attempted',
            oneShotWorkerPacketReady: false,
            terminalLoopOperatorCommands: $terminalLoopOperatorCommands,
            resumptionContract: [],
            previewOnly: true,
        );
        $terminalLoopIterationRunbook = $this->terminalLoopIterationRunbook(
            status: $claimableCount > 0 ? 'preview_available' : 'preview_blocked_no_claimable_task',
            actor: $actor,
            taskPacketId: '',
            leaseId: '',
            oneShotWorkerPacketReady: false,
            previewOnly: true,
            terminalLoopOperatorCommands: $terminalLoopOperatorCommands,
            terminalLoopResumptionCheckpoint: $terminalLoopResumptionCheckpoint,
        );
        $terminalLoopShellRecipe = $this->terminalLoopShellRecipe(
            actor: $actor,
            previewOnly: true,
            terminalLoopOperatorCommands: $terminalLoopOperatorCommands,
            terminalLoopIterationRunbook: $terminalLoopIterationRunbook,
        );

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'status' => $claimableCount > 0 ? 'preview_available' : 'preview_blocked_no_claimable_task',
            'generated_at' => CarbonImmutable::now()->toIso8601String(),
            'preview_only' => true,
            'actor' => $actor,
            'lease_minutes' => $leaseMinutes,
            'target_min_claimable_tasks' => $targetMin,
            'max_new_tasks' => $maxNew,
            'queue_tags' => $queueTags,
            'queue_lane_contract' => $queueLaneContract,
            'never_stop_before_drain_contract' => [
                'contract' => 'never_stop_before_drain',
                'description' => 'Worker must never stop the loop based on comfortable-queue or sufficient-depth heuristics. Continue until the queue is truly drained: no_claimable_task triggers retry with replenishment, waiting_on_dependencies triggers retry, and give_back triggers pull-next. Only stop when explicitly told by the operator or when disabled.',
                'retry_on' => [
                    'no_claimable_task' => 'replenish_and_retry',
                    'waiting_on_dependencies' => 'retry_after_delay',
                    'give_back' => 'pull_next_task',
                ],
                'forbidden_stop_language' => [
                    'comfortable_queue',
                    'sufficient_depth',
                    'adequate_supply',
                ],
                'constraints' => [
                    'allowed_files_only' => true,
                    'one_task_at_a_time' => true,
                ],
            ],
            'claim_tag' => (string) ($queueTags[0] ?? ''),
            'auto_replenishment_status' => 'preview_only_not_run',
            'auto_replenishment_hash' => '',
            'generated_task_count' => 0,
            'claim_event' => 'preview_only_no_claim_attempted',
            'runtime_claim_persisted' => false,
            'task_packet_id' => '',
            'lease_id' => '',
            'one_shot_worker_packet_ready' => false,
            'worker_packet_blocked_reason' => 'preview_only',
            'worker_packet_scope_blockers' => [],
            'lease_released_after_worker_packet_blocked' => false,
            'blocked_worker_packet_lease_release' => [],
            'one_shot_packet_hash' => '',
            'worker_prompt_goal_short' => '',
            'worker_prompt_full' => '',
            'completion_command' => '',
            'completion_evidence_template' => [],
            'completion_evidence_template_json' => '',
            'lease_renew_command' => '',
            'resumption_contract' => [],
            'resume_after_interruption_command' => '',
            'next_worker_command' => $nextWorkerCommand,
            'preview_claimable_count' => $claimableCount,
            'preview_claimable_packet_ids' => array_values(array_map(
                static fn (array $record): string => (string) ($record['task_packet_id'] ?? ''),
                $claimable,
            )),
            'preview_would_replenish' => $wouldReplenish,
            'preview_would_generate_task_count' => $wouldGenerateTaskCount,
            'preview_execute_bootstrap_command' => $nextWorkerCommand,
            'terminal_loop_operator_commands' => $terminalLoopOperatorCommands,
            'terminal_loop_resumption_checkpoint' => $terminalLoopResumptionCheckpoint,
            'terminal_loop_resumption_checkpoint_hash' => (string) ($terminalLoopResumptionCheckpoint['resumption_checkpoint_hash'] ?? ''),
            'terminal_loop_iteration_runbook' => $terminalLoopIterationRunbook,
            'terminal_loop_iteration_runbook_hash' => (string) ($terminalLoopIterationRunbook['iteration_runbook_hash'] ?? ''),
            'terminal_loop_shell_recipe' => $terminalLoopShellRecipe,
            'terminal_loop_shell_recipe_hash' => (string) ($terminalLoopShellRecipe['shell_recipe_hash'] ?? ''),
            'auto_replenishment' => [],
            'claim' => [
                'status' => 'preview',
                'event' => 'preview_only_no_claim_attempted',
            ],
            'one_shot_worker_packet' => [],
            'queue_summary' => $this->queue->registry(),
            'lease_summary' => [
                'active_lease_count' => count($this->leases->activeLeases()),
                'runtime_flags' => $this->leases->runtimeFlags(),
            ],
            'runtime_execution_allowed' => false,
            'dispatch_allowed' => false,
            'provider_call_allowed' => false,
            'token_spend_allowed' => false,
            'self_programming_allowed' => false,
            'ledger_write_allowed' => false,
            'completion_real_allowed' => false,
            'non_execution_guarantees' => [
                'terminal_worker_bootstrap_preview_does_not_replenish_queue',
                'terminal_worker_bootstrap_preview_does_not_claim_lease',
                'terminal_worker_bootstrap_does_not_start_codex',
                'terminal_worker_bootstrap_does_not_call_codex_cli_or_app',
                'terminal_worker_bootstrap_does_not_spawn_subprocess',
                'terminal_worker_bootstrap_does_not_invoke_adapter',
                'terminal_worker_bootstrap_does_not_call_provider',
                'terminal_worker_bootstrap_does_not_dispatch_work',
                'terminal_worker_bootstrap_does_not_spend_tokens',
                'terminal_worker_bootstrap_does_not_enable_self_programming',
                'terminal_worker_bootstrap_does_not_write_ledger',
                'terminal_worker_bootstrap_does_not_mark_real_completion',
            ],
            'bootstrap_hash' => '',
        ];
        $payload['bootstrap_hash'] = $this->stableHash($payload);

        return $payload;
    }

    /**
     * @param  list<string>  $queueTags
     * @return list<array<string, mixed>>
     */
    private function claimableRecords(array $queueTags, int $limit = 0): array
    {
        $filters = [
            'status' => 'claimable',
        ];
        if ($queueTags !== []) {
            $filters['tag'] = $queueTags[0];
            $filters['tags'] = $queueTags;
        }
        if ($limit > 0) {
            $filters['limit'] = $limit;
        }

        return $this->queue->list($filters);
    }

    /**
     * The terminal-bootstrap certification probe owns its synthetic claim path.
     * Normal workers always continue through the orchestrator, where probe
     * packets remain unservable by design.
     *
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function claimNext(array $context, string $actor, array $filters): array
    {
        $queueTags = $this->stringList((array) ($filters['tags'] ?? []));
        if (! $this->isTerminalBootstrapProbe($context, $queueTags)) {
            return $this->orchestrator->claimNext($actor, $filters);
        }

        return $this->claimTerminalBootstrapProbePacket(
            $actor,
            $queueTags,
            max(60, (int) ($filters['ttl_seconds'] ?? 1800)),
        );
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  list<string>  $queueTags
     */
    private function isTerminalBootstrapProbe(array $context, array $queueTags): bool
    {
        if (! (bool) data_get($context, 'terminal_bootstrap_probe.enabled', false)) {
            return false;
        }

        foreach ($queueTags as $queueTag) {
            if (str_starts_with($queueTag, 'terminal_worker_bootstrap_probe')
                || str_starts_with($queueTag, 'terminal_bootstrap_invalid_scope_')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Claim only a packet explicitly created for the synthetic terminal
     * bootstrap probe. This mirrors the certification-only lease/CAS protocol
     * without opening the real-worker serving surface to probes.
     *
     * @param  list<string>  $queueTags
     * @return array<string, mixed>
     */
    private function claimTerminalBootstrapProbePacket(string $actor, array $queueTags, int $ttlSeconds): array
    {
        $candidates = $this->claimableRecords($queueTags);
        foreach ($candidates as $record) {
            if (! $this->hasTerminalBootstrapProbeTag((array) ($record['tags'] ?? []))) {
                continue;
            }

            $taskPacketId = (string) ($record['task_packet_id'] ?? '');
            if ($taskPacketId === '') {
                continue;
            }

            $scopeLock = [
                'write_set' => (array) data_get($record, 'task_packet.normalized_scope.allowed_files', []),
                'read_set' => (array) data_get($record, 'task_packet.normalized_scope.scope_in', []),
                'scope_lock_plan_hash' => (string) data_get($record, 'metadata.scope_lock_hash', ''),
            ];
            $claim = $this->leases->claim($taskPacketId, $actor, $scopeLock, ['ttl_seconds' => $ttlSeconds]);
            if ((string) ($claim['status'] ?? '') !== 'ok') {
                continue;
            }

            $leaseId = (string) ($claim['lease_id'] ?? '');
            $swap = $this->queue->compareAndSwapStatus($taskPacketId, 'claimable', 'claimed', [
                'lease_id' => $leaseId,
                'agent_id' => $actor,
            ]);
            if (($swap['swapped'] ?? false) !== true) {
                $this->leases->release($leaseId, $actor, ['reason' => 'terminal_bootstrap_probe_queue_status_moved']);

                continue;
            }

            $this->queue->appendReceipt($taskPacketId, [
                'receipt_kind' => 'claim_acquired_by_terminal_bootstrap_probe',
                'lease_id' => $leaseId,
                'agent_id' => $actor,
            ]);

            return [
                'event' => 'claimed',
                'queue_entry' => $record,
                'lease' => $claim['lease'] ?? null,
                'lease_id' => $leaseId,
                'task_packet_id' => $taskPacketId,
                'agent_id' => $actor,
            ];
        }

        return [
            'event' => 'no_claimable_task',
            'reason' => 'terminal_bootstrap_probe_packet_not_claimable',
            'candidate_count' => count($candidates),
        ];
    }

    /** @param  list<string>  $tags */
    private function hasTerminalBootstrapProbeTag(array $tags): bool
    {
        foreach ($tags as $tag) {
            if ($tag === 'terminal_bootstrap_probe'
                || str_starts_with($tag, 'terminal_bootstrap_invalid_scope_')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function stableHash(array $payload): string
    {
        unset($payload['generated_at'], $payload['bootstrap_hash']);
        ksort($payload);

        return hash('sha256', (string) json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * @return array<string,mixed>
     */
    private function terminalLoopOperatorCommands(
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
        return $this->loopGuidanceBuilder()->terminalLoopOperatorCommands(
            $actor,
            $taskPacketId,
            $leaseId,
            $completionCommand,
            $leaseRenewCommand,
            $recoveryCommand,
            $bootstrapCommand,
            $targetMin,
            $maxNew,
            $queueTags,
            $queueLaneContract,
        );
    }

    /**
     * @param  list<string>  $queueTags
     * @return array<string, mixed>
     */
    private function queueLaneContract(string $actor, array $queueTags, string $bootstrapCommand): array
    {
        return $this->loopGuidanceBuilder()->queueLaneContract($actor, $queueTags, $bootstrapCommand);
    }

    private function recommendedQueueTag(string $actor): string
    {
        return $this->commandFormatter()->recommendedQueueTag($actor);
    }

    /**
     * @param  array<string, mixed>  $terminalLoopOperatorCommands
     * @param  array<string, mixed>  $resumptionContract
     * @return array<string, mixed>
     */
    private function terminalLoopResumptionCheckpoint(
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
        return $this->loopGuidanceBuilder()->terminalLoopResumptionCheckpoint(
            $status,
            $actor,
            $taskPacketId,
            $leaseId,
            $claimEvent,
            $oneShotWorkerPacketReady,
            $terminalLoopOperatorCommands,
            $resumptionContract,
            $previewOnly,
        );
    }

    /**
     * @param  array<string, mixed>  $terminalLoopOperatorCommands
     * @param  array<string, mixed>  $terminalLoopResumptionCheckpoint
     * @return array<string, mixed>
     */
    private function terminalLoopIterationRunbook(
        string $status,
        string $actor,
        string $taskPacketId,
        string $leaseId,
        bool $oneShotWorkerPacketReady,
        bool $previewOnly,
        array $terminalLoopOperatorCommands,
        array $terminalLoopResumptionCheckpoint,
    ): array {
        return $this->loopGuidanceBuilder()->terminalLoopIterationRunbook(
            $status,
            $actor,
            $taskPacketId,
            $leaseId,
            $oneShotWorkerPacketReady,
            $previewOnly,
            $terminalLoopOperatorCommands,
            $terminalLoopResumptionCheckpoint,
        );
    }

    /**
     * @param  list<string>  $produces
     * @return array<string, mixed>
     */
    private function iterationStep(
        string $id,
        string $phase,
        string $command,
        string $operatorAction,
        array $produces,
        bool $canRunAutomatically,
    ): array {
        return $this->loopGuidanceBuilder()->iterationStep($id, $phase, $command, $operatorAction, $produces, $canRunAutomatically);
    }

    /**
     * @param  array<string, mixed>  $terminalLoopOperatorCommands
     * @param  array<string, mixed>  $terminalLoopIterationRunbook
     * @return array<string, mixed>
     */
    private function terminalLoopShellRecipe(string $actor, bool $previewOnly, array $terminalLoopOperatorCommands, array $terminalLoopIterationRunbook): array
    {
        return $this->loopGuidanceBuilder()->terminalLoopShellRecipe($actor, $previewOnly, $terminalLoopOperatorCommands, $terminalLoopIterationRunbook);
    }

    private function terminalLoopCurrentStep(string $status, string $claimEvent, bool $oneShotWorkerPacketReady, bool $previewOnly): string
    {
        return $this->loopGuidanceBuilder()->terminalLoopCurrentStep($status, $claimEvent, $oneShotWorkerPacketReady, $previewOnly);
    }

    /**
     * @param  list<string>  $queueTags
     * @return array<string, mixed>
     */
    private function workerEligibilityGuard(array $queueTags): array
    {
        return $this->workerEligibilityGuardService()->workerEligibilityGuard($queueTags);
    }

    /**
     * ITEM8 — cohesive stateless command / string-formatting the terminal-worker bootstrap service
     * uses to render CLI arguments, escape values, recommend queue tags, and normalize string lists.
     * Extracted into {@see AgentControlPlaneTerminalWorkerCommandFormatter}; we keep the five private
     * methods (`bootstrapCommand`, `commandValue`, `queueTagArgs`, `recommendedQueueTag`, `stringList`)
     * as thin private delegators so every existing call site stays byte-identical and the public
     * signature of the service does not move. Lazy-instantiated per call so production callers pay no
     * construction cost beyond the first use.
     */
    private function commandFormatter(): AgentControlPlaneTerminalWorkerCommandFormatter
    {
        return new AgentControlPlaneTerminalWorkerCommandFormatter;
    }

    /**
     * ITEM8 — cohesive stateless read-only terminal-loop guidance-artifact builder the bootstrap
     * service uses to assemble the operator-commands / queue-lane / resumption-checkpoint / iteration
     * runbook / shell recipe / current-step artefacts. Extracted into
     * {@see AgentControlPlaneTerminalLoopGuidanceBuilder}; we keep the seven private methods
     * (`terminalLoopOperatorCommands`, `queueLaneContract`, `terminalLoopResumptionCheckpoint`,
     * `terminalLoopIterationRunbook`, `iterationStep`, `terminalLoopShellRecipe`,
     * `terminalLoopCurrentStep`) as thin private delegators so every existing call site (inside
     * `bootstrap()`'s pipeline) stays byte-identical and the public signature of the service does
     * not move. Lazy-instantiated per call so production callers pay no construction cost beyond the
     * first use.
     */
    private function loopGuidanceBuilder(): AgentControlPlaneTerminalLoopGuidanceBuilder
    {
        return new AgentControlPlaneTerminalLoopGuidanceBuilder;
    }

    /**
     * ITEM8 — cohesive worker-eligibility validation the bootstrap service uses to assert every
     * claimable record satisfies the "one-terminal-runs-one-packet-at-a-time" contract. Extracted into
     * {@see AgentControlPlaneWorkerEligibilityGuard}; we keep the single private method
     * (`workerEligibilityGuard`) as a thin delegator so every existing call site (inside `bootstrap()`'s
     * pipeline) stays byte-identical and the public signature of the service does not move.
     * Lazy-instantiated per call with the queue repository threaded through, so production callers pay
     * no construction cost beyond the first use.
     */
    private function workerEligibilityGuardService(): AgentControlPlaneWorkerEligibilityGuard
    {
        return new AgentControlPlaneWorkerEligibilityGuard($this->queue);
    }

    /**
     * @param  list<string>  $queueTags
     */
    private function bootstrapCommand(string $actor, int $targetMin, int $maxNew, array $queueTags): string
    {
        return $this->commandFormatter()->bootstrapCommand($actor, $targetMin, $maxNew, $queueTags);
    }

    private function commandValue(string $value): string
    {
        return $this->commandFormatter()->commandValue($value);
    }

    /**
     * @param  list<string>  $queueTags
     */
    private function queueTagArgs(array $queueTags): string
    {
        return $this->commandFormatter()->queueTagArgs($queueTags);
    }

    /**
     * @param  array<int, mixed>  $values
     * @return list<string>
     */
    private function stringList(array $values): array
    {
        return $this->commandFormatter()->stringList($values);
    }
}
