<?php

namespace App\Services\Ai\SelfConstruction;

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

        $claimFilters = [
            'ttl_seconds' => $leaseMinutes * 60,
        ];
        if ($queueTags !== []) {
            $claimFilters['tag'] = $queueTags[0];
        }
        $claim = $this->orchestrator->claimNext($actor, $claimFilters);
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
            $claim = $this->orchestrator->claimNext($actor, $claimFilters);
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
    ): array {
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
            claimEvent: 'worker_task_eligibility_blocked_before_claim',
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
            'claim_tag' => (string) ($queueTags[0] ?? ''),
            'auto_replenishment_status' => (string) data_get($replenishment, 'status', 'unknown'),
            'auto_replenishment_hash' => (string) data_get($replenishment, 'auto_replenishment_hash', ''),
            'generated_task_count' => (int) data_get($replenishment, 'generated_task_count', 0),
            'claim_event' => 'worker_task_eligibility_blocked_before_claim',
            'runtime_claim_persisted' => false,
            'task_packet_id' => '',
            'lease_id' => '',
            'one_shot_worker_packet_ready' => false,
            'worker_packet_blocked_reason' => 'worker_task_eligibility_blocked_before_claim',
            'worker_packet_scope_blockers' => (array) ($workerEligibility['blocked_reasons'] ?? []),
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
                'event' => 'worker_task_eligibility_blocked_before_claim',
                'reason' => 'worker_task_eligibility_guard_blocked',
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
        }
        if ($limit > 0) {
            $filters['limit'] = $limit;
        }

        return $this->queue->list($filters);
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
            'inspect_active_leases' => 'php artisan atlas:ai:self-construction --agent-control-plane-task-lease-recovery-status --actor='.$actor.' --json',
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
    private function queueLaneContract(string $actor, array $queueTags, string $bootstrapCommand): array
    {
        $primaryTag = trim((string) ($queueTags[0] ?? ''));
        $explicit = $primaryTag !== '';
        $laneId = $explicit ? $primaryTag : 'global';
        $recommendedTag = $this->recommendedQueueTag($actor);
        $contract = [
            'schema_version' => 'atlas.self_construction.agent_control_plane_terminal_loop_queue_lane_contract.v1',
            'queue_lane_id' => $laneId,
            'queue_lane_explicit' => $explicit,
            'queue_lane_mode' => $explicit ? 'explicit_tagged_lane' : 'global_untagged_lane',
            'queue_tags' => $queueTags,
            'primary_queue_tag' => $primaryTag,
            'recommended_queue_tag' => $recommendedTag,
            'next_iteration_command_contains_primary_queue_tag' => $explicit && str_contains($bootstrapCommand, '--queue-tag='.$this->commandValue($primaryTag)),
            'next_iteration_preserves_queue_lane' => $explicit
                ? str_contains($bootstrapCommand, '--queue-tag='.$this->commandValue($primaryTag))
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

    private function recommendedQueueTag(string $actor): string
    {
        $slug = strtolower((string) preg_replace('/[^A-Za-z0-9_.:-]+/', '-', trim($actor)));
        $slug = trim($slug, '-._:');

        return 'terminal-loop-'.($slug === '' ? 'codex' : $slug);
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
    private function iterationStep(
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
    private function terminalLoopShellRecipe(string $actor, bool $previewOnly, array $terminalLoopOperatorCommands, array $terminalLoopIterationRunbook): array
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

    private function terminalLoopCurrentStep(string $status, string $claimEvent, bool $oneShotWorkerPacketReady, bool $previewOnly): string
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
     * @param  list<string>  $queueTags
     * @return array<string, mixed>
     */
    private function workerEligibilityGuard(array $queueTags): array
    {
        $records = $this->claimableRecords($queueTags);
        $violations = [];
        $ineligibleTaskIds = [];

        foreach ($records as $record) {
            $taskPacketId = (string) ($record['task_packet_id'] ?? '');
            $recordViolationCountBefore = count($violations);
            $reference = (string) data_get($record, 'task_packet.continuation_context.auto_replenishment_reference', '');
            if ((bool) data_get($record, 'task_packet.continuation_context.worker_executable', true) === false) {
                $violations[] = ['code' => 'claimable_task_not_worker_executable', 'task_packet_id' => $taskPacketId];
            }
            if ((bool) data_get($record, 'task_packet.continuation_context.operator_handoff_required', false)) {
                $violations[] = ['code' => 'claimable_task_requires_operator_handoff', 'task_packet_id' => $taskPacketId];
            }
            if (in_array($reference, self::OPERATOR_ONLY_COMPLETION_CRITERIA, true)) {
                $violations[] = [
                    'code' => 'claimable_task_references_operator_only_completion_blocker',
                    'task_packet_id' => $taskPacketId,
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
                        'code' => 'claimable_task_runtime_flag_true',
                        'task_packet_id' => $taskPacketId,
                        'flag' => $flag,
                    ];
                }
            }
            if (count($violations) > $recordViolationCountBefore && $taskPacketId !== '') {
                $ineligibleTaskIds[$taskPacketId] = true;
            }
        }
        $eligibleClaimableCount = count(array_values(array_filter(
            $records,
            static fn (array $record): bool => ! isset($ineligibleTaskIds[(string) ($record['task_packet_id'] ?? '')]),
        )));
        $status = $eligibleClaimableCount > 0
            ? ($violations === [] ? 'available' : 'available_with_non_worker_candidates')
            : ($violations === [] ? 'available' : 'blocked');

        $guard = [
            'schema_version' => 'atlas.self_construction.agent_control_plane_terminal_worker_bootstrap_worker_task_eligibility_guard.v1',
            'status' => $status,
            'queue_tags' => $queueTags,
            'checked_claimable_task_count' => count($records),
            'eligible_claimable_task_count' => $eligibleClaimableCount,
            'blocked_reasons' => array_values(array_unique(array_map(
                static fn (array $violation): string => (string) ($violation['code'] ?? ''),
                $violations,
            ))),
            'violations' => $violations,
            'violation_count' => count($violations),
            'can_claim_after_guard' => $eligibleClaimableCount > 0,
            'non_execution_guarantees' => [
                'worker_task_eligibility_guard_does_not_claim_tasks',
                'worker_task_eligibility_guard_does_not_create_or_renew_leases',
                'worker_task_eligibility_guard_does_not_call_provider',
                'worker_task_eligibility_guard_does_not_spend_tokens',
                'worker_task_eligibility_guard_does_not_dispatch_work',
            ],
        ];
        $guard['worker_task_eligibility_guard_hash'] = $this->stableHash($guard);

        return $guard;
    }

    /**
     * @param  list<string>  $queueTags
     */
    private function bootstrapCommand(string $actor, int $targetMin, int $maxNew, array $queueTags): string
    {
        $parts = [
            'php artisan atlas:ai:self-construction',
            '--agent-control-plane-terminal-worker-bootstrap-status',
            '--actor='.$this->commandValue($actor),
            '--target-min-claimable-tasks='.$targetMin,
            '--max-new-tasks='.$maxNew,
        ];

        foreach ($queueTags as $tag) {
            $parts[] = '--queue-tag='.$this->commandValue($tag);
        }

        $parts[] = '--json';

        return implode(' ', $parts);
    }

    private function commandValue(string $value): string
    {
        if (preg_match('/^[A-Za-z0-9_.:@\\/-]+$/', $value) === 1) {
            return $value;
        }

        return escapeshellarg($value);
    }

    /**
     * @param  array<int, mixed>  $values
     * @return list<string>
     */
    private function stringList(array $values): array
    {
        return array_values(array_filter(array_map(
            static fn (mixed $value): string => trim((string) $value),
            $values,
        ), static fn (string $value): bool => $value !== ''));
    }
}
