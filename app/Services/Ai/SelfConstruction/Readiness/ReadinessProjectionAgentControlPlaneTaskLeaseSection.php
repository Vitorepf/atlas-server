<?php

namespace App\Services\Ai\SelfConstruction\Readiness;

use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneTaskLeaseRecoveryService;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneClaimLeaseRepository;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneTaskPacketQueueRepository;
use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskQueueOrchestrator;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneTaskPacketBuilder;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneScopeLockRuntimeValidator;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneEvidenceLedgerDryRun;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneContinuationSummaryBuilder;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneTerminalWorkerBootstrapService;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneTaskAutoReplenishmentService;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneOneShotWorkerPacketService;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentRuntimeRegistryHeartbeatRepository;

/**
 * GOD-DEBULK extracted stateful agent-control-plane task-lease/worker status family from AtlasSelfConstructionReadinessService (task lease recovery, claim lease runtime, task queue claim-next, task auto-replenishment, terminal worker bootstrap, agent runtime registry heartbeat).
 * Bound via setMother(); undefined method calls bridge through __call and undefined
 * property reads bridge through __get (ReflectionMethod / ReflectionProperty on the mother)
 * so the moved bodies stay byte-identical to the god service originals.
 */
final class ReadinessProjectionAgentControlPlaneTaskLeaseSection
{
    private ?AtlasSelfConstructionReadinessService $mother = null;

    public function setMother(AtlasSelfConstructionReadinessService $mother): self
    {
        $this->mother = $mother;

        return $this;
    }

    public function __call(string $name, array $arguments): mixed
    {
        if ($this->mother === null) {
            throw new \RuntimeException('ReadinessProjectionAgentControlPlaneTaskLeaseSection mother not bound for '.$name);
        }
        $method = new \ReflectionMethod($this->mother, $name);

        return $method->invokeArgs($this->mother, $arguments);
    }

    public function __get(string $name): mixed
    {
        $property = new \ReflectionProperty($this->mother, $name);

        return $property->getValue($this->mother);
    }


    public function agentControlPlaneTaskLeaseRecoveryStatus(array $options = []): array
    {
        $service = new AgentControlPlaneTaskLeaseRecoveryService;
        $actor = $this->reservationActor($options);
        $reason = trim((string) ($options['reason'] ?? ''));
        $packet = trim((string) ($options['packet'] ?? ''));

        $serviceOptions = array_filter([
            'actor' => $actor,
            'reason' => $reason,
            'packet' => $packet,
        ], static fn (string $v): bool => $v !== '');
        $queueTags = array_values(array_filter(array_map(
            static fn (mixed $tag): string => trim((string) $tag),
            (array) ($options['queue_tags'] ?? []),
        ), static fn (string $tag): bool => $tag !== ''));
        if ($queueTags !== []) {
            $serviceOptions['queue_tags'] = $queueTags;
        }

        $expiredResult = $service->recoverExpiredLeases($serviceOptions);
        $orphanResult = $service->recoverOrphanedClaims($serviceOptions);
        $releasedResult = $service->recoverReleasedTasks(
            $packet !== '' ? array_merge($serviceOptions, ['packet' => $packet]) : $serviceOptions,
        );
        // ponytail: write truth from recovered counts; repository-level write telemetry is the Runtime owner's job.
        $runtimeWritePerformed = ((int) data_get($expiredResult, 'recovered_count', 0)
            + (int) data_get($orphanResult, 'recovered_count', 0)
            + (int) data_get($releasedResult, 'recovered_count', 0)) > 0;
        $inspectResult = $service->inspectRecoverability(
            $packet !== '' ? array_merge($serviceOptions, ['packet' => $packet]) : $serviceOptions,
        );
        $resumePacket = $packet !== '' ? $service->buildResumePacket($packet) : null;

        $available = $service->isAvailable();
        $status = $available ? 'available' : 'blocked';

        $payload = [
            'schema_version' => AgentControlPlaneTaskLeaseRecoveryService::SCHEMA_VERSION,
            'status' => $status,
            'mode' => AgentControlPlaneTaskLeaseRecoveryService::MODE,
            'actor' => $actor,
            'reason' => $reason,
            'task_packet_filter' => $packet,
            'queue_tags' => $queueTags,
            'service_available' => $available,
            'expired_lease_recovery' => $expiredResult,
            'orphaned_claim_recovery' => $orphanResult,
            'released_task_recovery' => $releasedResult,
            'recoverability_inspection' => $inspectResult,
            'resume_packet' => $resumePacket,
            'runtime_safety' => $service->runtimeFlags(),
        ];

        return $this->wrapCertificationWorkbenchStatus(
            keyPrefix: 'task_lease_recovery',
            label: 'Task Lease Recovery',
            payload: $payload,
            statusKey: 'status',
            extraStatusFields: [
                'service_available' => $available,
                'actor' => $actor,
                'reason' => $reason,
                'task_packet_filter' => $packet,
                'queue_tags' => $queueTags,
                'expired_recovered_count' => (int) data_get($expiredResult, 'recovered_count', 0),
                'expired_skipped_count' => (int) data_get($expiredResult, 'skipped_count', 0),
                'orphaned_recovered_count' => (int) data_get($orphanResult, 'recovered_count', 0),
                'orphaned_skipped_count' => (int) data_get($orphanResult, 'skipped_count', 0),
                'released_recovered_count' => (int) data_get($releasedResult, 'recovered_count', 0),
                'released_skipped_count' => (int) data_get($releasedResult, 'skipped_count', 0),
                'recoverable_count' => (int) data_get($inspectResult, 'recoverable_count', 0),
                'inspected_count' => (int) data_get($inspectResult, 'inspected_count', 0),
                'resume_packet_event' => $resumePacket !== null ? (string) data_get($resumePacket, 'event') : '',
                'resume_contract_schema' => $resumePacket !== null ? (string) data_get($resumePacket, 'resume_packet.resume_contract.schema_version', '') : '',
                'resume_safe_next_action' => $resumePacket !== null ? (string) data_get($resumePacket, 'resume_packet.resume_contract.safe_next_action', '') : '',
                'resume_requires_fresh_claim_before_work' => $resumePacket !== null ? (bool) data_get($resumePacket, 'resume_packet.resume_contract.requires_fresh_claim_before_work', false) : false,
                'resume_requires_one_shot_packet_regeneration_after_claim' => $resumePacket !== null ? (bool) data_get($resumePacket, 'resume_packet.resume_contract.requires_one_shot_packet_regeneration_after_claim', false) : false,
            ],
            runtimeWritePerformed: $runtimeWritePerformed,
        );
    }

    public function agentControlPlaneClaimLeaseRuntimeStatus(array $options = []): array
    {
        $repo = new AgentControlPlaneClaimLeaseRepository;
        $active = $repo->activeLeases();
        $available = $repo->isAvailable();
        $status = $available ? 'available' : 'blocked';

        $payload = [
            'schema_version' => AgentControlPlaneClaimLeaseRepository::SCHEMA_VERSION,
            'status' => $status,
            'storage_prefix' => AgentControlPlaneClaimLeaseRepository::STORAGE_PREFIX,
            'lease_repository_available' => $available,
            'active_lease_count' => count($active),
            'default_ttl_seconds' => AgentControlPlaneClaimLeaseRepository::DEFAULT_TTL_SECONDS,
            'min_ttl_seconds' => AgentControlPlaneClaimLeaseRepository::MIN_TTL_SECONDS,
            'max_ttl_seconds' => AgentControlPlaneClaimLeaseRepository::MAX_TTL_SECONDS,
            'lease_statuses' => [
                AgentControlPlaneClaimLeaseRepository::LEASE_STATUS_ACTIVE,
                AgentControlPlaneClaimLeaseRepository::LEASE_STATUS_EXPIRED,
                AgentControlPlaneClaimLeaseRepository::LEASE_STATUS_RELEASED,
            ],
            'runtime_safety' => $repo->runtimeFlags(),
        ];

        // A1-SC-0003 (Fase 2 characterization): this status route ALWAYS writes.
        // activeLeases() runs expireLeasesInternal() whose collectExpirations()
        // ends in an unconditional saveRegistry() (plus lease-file writes and
        // expiry receipts when leases are stale), and isAvailable() writes a
        // .health probe file. The envelope must not claim read_only.
        // ponytail: truth-flag only; re-point to the repository's read-only
        // registry() snapshot when the ControlPlane Projector/Runtime split lands.
        return $this->wrapCertificationWorkbenchStatus(
            keyPrefix: 'claim_lease_runtime',
            label: 'Claim/Lease Runtime',
            payload: $payload,
            statusKey: 'status',
            extraStatusFields: [
                'storage_prefix' => (string) $payload['storage_prefix'],
                'active_lease_count' => (int) $payload['active_lease_count'],
                'lease_repository_available' => (bool) $payload['lease_repository_available'],
                'default_ttl_seconds' => (int) $payload['default_ttl_seconds'],
            ],
            runtimeWritePerformed: true,
        );
    }

    public function agentControlPlaneTaskQueueClaimNextStatus(array $options = []): array
    {
        $orchestrator = $this->buildTaskQueueOrchestrator();
        $actor = $this->reservationActor($options);
        $leaseMinutes = max(1, min(240, (int) ($options['lease_minutes'] ?? 30)));
        $queueTags = array_values(array_filter(array_map(
            static fn (mixed $tag): string => trim((string) $tag),
            (array) ($options['queue_tags'] ?? []),
        ), static fn (string $tag): bool => $tag !== ''));
        $claimFilters = [
            'ttl_seconds' => $leaseMinutes * 60,
        ];
        if ($queueTags !== []) {
            $claimFilters['tag'] = $queueTags[0];
            $claimFilters['tags'] = $queueTags;
        }
        $claim = $orchestrator->claimNext($actor, $claimFilters);

        $fallbackEnqueuePerformed = false;
        if ((string) ($claim['event'] ?? '') === 'no_claimable_task') {
            $prepareInput = ['task_packet' => $this->defaultRuntimePilotInput()];
            if ($queueTags !== []) {
                $prepareInput['queue'] = ['tags' => $queueTags];
            }
            $orchestrator->prepareAndEnqueue($prepareInput);
            $fallbackEnqueuePerformed = true;
            $claim = $orchestrator->claimNext($actor, $claimFilters);
        }

        $event = (string) ($claim['event'] ?? 'unknown');
        $status = $event === 'claimed' ? 'claimed' : 'blocked';
        $nextAgentCommand = 'php artisan atlas:ai:self-construction --agent-control-plane-task-queue-claim-next-status --actor=<agent-id>'.$this->queueTagCommandArgs($queueTags).' --json';
        $payload = array_merge($claim, [
            'status' => $status,
            'agent_id' => $actor,
            'lease_minutes' => $leaseMinutes,
            'queue_tags' => $queueTags,
            'claim_tag' => (string) ($claimFilters['tag'] ?? ''),
            'runtime_claim_persisted' => $event === 'claimed',
            'legacy_reservation_claim_used' => false,
            'safe_for_parallel_terminal_loop' => $event === 'claimed',
            'next_agent_command' => $nextAgentCommand,
            'non_execution_summary' => [
                'dispatch_allowed' => false,
                'provider_call_allowed' => false,
                'token_spend_allowed' => false,
                'self_programming_allowed' => false,
                'completion_real_allowed' => false,
            ],
        ]);

        return $this->wrapCertificationWorkbenchStatus(
            keyPrefix: 'task_queue_claim_next',
            label: 'Task Queue Claim Next',
            payload: $payload,
            statusKey: 'status',
            extraStatusFields: [
                'event' => $event,
                'agent_id' => $actor,
                'task_packet_id' => (string) data_get($claim, 'task_packet_id'),
                'lease_id' => (string) data_get($claim, 'lease_id'),
                'queue_tags' => $queueTags,
                'claim_tag' => (string) ($claimFilters['tag'] ?? ''),
                'next_agent_command' => $nextAgentCommand,
                'runtime_claim_persisted' => $event === 'claimed',
                'legacy_reservation_claim_used' => false,
                'safe_for_parallel_terminal_loop' => $event === 'claimed',
            ],
            runtimeWritePerformed: $event === 'claimed' || $fallbackEnqueuePerformed,
        );
    }

    public function agentControlPlaneTaskAutoReplenishmentStatus(array $options = []): array
    {
        $targetMin = max(1, min(25, (int) ($options['target_min_claimable_tasks'] ?? 3)));
        $maxNew = max(0, min(25, (int) ($options['max_new_tasks'] ?? $targetMin)));
        $controlPlane = $this->agentControlPlane();
        $completionAuditContext = $this->taskAutoReplenishmentCompletionAuditContext($options);
        $service = $this->buildTaskAutoReplenishmentService();
        $result = $service->replenish([
            'control_plane' => $controlPlane,
            'completion_audit' => $completionAuditContext,
        ], [
            'target_min_claimable_tasks' => $targetMin,
            'max_new_tasks' => $maxNew,
            'actor' => $this->reservationActor($options),
            'reason' => (string) ($options['reason'] ?? 'claimable_queue_below_target'),
            'queue_tags' => (array) ($options['queue_tags'] ?? []),
        ]);

        return $this->wrapCertificationWorkbenchStatus(
            keyPrefix: 'task_auto_replenishment',
            label: 'Task Auto-Replenishment',
            payload: $result,
            statusKey: 'status',
            extraStatusFields: [
                'event' => (string) data_get($result, 'event'),
                'generated_task_count' => (int) data_get($result, 'generated_task_count'),
                'skipped_existing_task_count' => (int) data_get($result, 'skipped_existing_task_count'),
                'skipped_duplicate_seed_count' => (int) data_get($result, 'skipped_duplicate_seed_count'),
                'operator_handoff_seed_count' => (int) data_get($result, 'operator_handoff_seed_count'),
                'operator_handoff_seed_keys' => (array) data_get($result, 'plan_evaluation.operator_handoff_seed_keys', []),
                'operator_handoff_tasks' => (array) data_get($result, 'operator_handoff_tasks', []),
                'completion_audit_context_status' => (string) data_get($completionAuditContext, 'status', ''),
                'completion_audit_context_failed_count' => (int) data_get($completionAuditContext, 'failed_count', 0),
                'completion_audit_context_failed_criteria' => (array) data_get($completionAuditContext, 'failed_criteria', []),
                'active_seed_count' => (int) data_get($result, 'active_seed_count'),
                'claimable_task_count_before' => (int) data_get($result, 'claimable_task_count_before'),
                'claimable_task_count_after' => (int) data_get($result, 'claimable_task_count_after'),
                'target_min_claimable_tasks' => (int) data_get($result, 'target_min_claimable_tasks'),
                'queue_tags' => (array) data_get($result, 'queue_tags', []),
                'replenishment_loop_contract_schema' => (string) data_get($result, 'replenishment_loop_contract.schema_version'),
                'replenishment_stop_conditions' => (array) data_get($result, 'replenishment_loop_contract.stop_conditions', []),
                'plan_evaluation_status' => (string) data_get($result, 'plan_evaluation.status'),
                'accepted_seed_count' => (int) data_get($result, 'plan_evaluation.accepted_seed_count'),
                'accepted_seed_keys' => (array) data_get($result, 'plan_evaluation.accepted_seed_keys', []),
                'replenishment_plan_hash' => (string) data_get($result, 'replenishment_plan_hash'),
            ],
            runtimeWritePerformed: (int) data_get($result, 'generated_task_count', 0) > 0,
        );
    }

    public function agentControlPlaneTerminalWorkerBootstrapStatus(array $options = []): array
    {
        $queue = new AgentControlPlaneTaskPacketQueueRepository;
        $leases = new AgentControlPlaneClaimLeaseRepository;
        $orchestrator = new AgentControlPlaneTaskQueueOrchestrator(
            new AgentControlPlaneTaskPacketBuilder,
            new AgentControlPlaneScopeLockRuntimeValidator,
            $queue,
            $leases,
            new AgentControlPlaneEvidenceLedgerDryRun,
            new AgentControlPlaneContinuationSummaryBuilder,
        );
        $service = new AgentControlPlaneTerminalWorkerBootstrapService(
            new AgentControlPlaneTaskAutoReplenishmentService($orchestrator, $queue),
            $orchestrator,
            new AgentControlPlaneOneShotWorkerPacketService($leases, $queue),
            $queue,
            $leases,
        );
        $targetMin = max(1, min(25, (int) ($options['target_min_claimable_tasks'] ?? 6)));
        $maxNew = max(0, min(25, (int) ($options['max_new_tasks'] ?? $targetMin)));
        $result = $service->bootstrap([
            'control_plane' => $this->agentControlPlane(),
        ], [
            'target_min_claimable_tasks' => $targetMin,
            'max_new_tasks' => $maxNew,
            'lease_minutes' => (int) ($options['lease_minutes'] ?? 30),
            'actor' => $this->reservationActor($options),
            'reason' => (string) ($options['reason'] ?? 'terminal_worker_bootstrap'),
            'queue_tags' => (array) ($options['queue_tags'] ?? []),
            'preview_only' => (bool) ($options['terminal_worker_bootstrap_preview'] ?? false),
        ]);

        return $this->wrapCertificationWorkbenchStatus(
            keyPrefix: 'terminal_worker_bootstrap',
            label: 'Terminal Worker Bootstrap',
            payload: $result,
            statusKey: 'status',
            extraStatusFields: [
                'actor' => (string) data_get($result, 'actor'),
                'preview_only' => (bool) data_get($result, 'preview_only', false),
                'claim_event' => (string) data_get($result, 'claim_event'),
                'task_packet_id' => (string) data_get($result, 'task_packet_id'),
                'lease_id' => (string) data_get($result, 'lease_id'),
                'runtime_claim_persisted' => (bool) data_get($result, 'runtime_claim_persisted'),
                'one_shot_worker_packet_ready' => (bool) data_get($result, 'one_shot_worker_packet_ready'),
                'worker_task_eligibility_guard_status' => (string) data_get($result, 'worker_task_eligibility_guard.status', ''),
                'worker_task_eligibility_guard_violation_count' => (int) data_get($result, 'worker_task_eligibility_guard.violation_count', 0),
                'worker_task_eligibility_guard_hash' => (string) data_get($result, 'worker_task_eligibility_guard.worker_task_eligibility_guard_hash', ''),
                'preview_claimable_count' => (int) data_get($result, 'preview_claimable_count', 0),
                'preview_would_replenish' => (bool) data_get($result, 'preview_would_replenish', false),
                'preview_would_generate_task_count' => (int) data_get($result, 'preview_would_generate_task_count', 0),
                'preview_execute_bootstrap_command' => (string) data_get($result, 'preview_execute_bootstrap_command', ''),
                'one_shot_packet_hash' => (string) data_get($result, 'one_shot_packet_hash'),
                'bootstrap_hash' => (string) data_get($result, 'bootstrap_hash'),
                'completion_command' => (string) data_get($result, 'completion_command'),
                'terminal_loop_operator_commands_schema' => (string) data_get($result, 'terminal_loop_operator_commands.schema_version', ''),
                'terminal_loop_queue_lane_contract_schema' => (string) data_get($result, 'queue_lane_contract.schema_version', ''),
                'terminal_loop_queue_lane_id' => (string) data_get($result, 'queue_lane_contract.queue_lane_id', ''),
                'terminal_loop_queue_lane_explicit' => (bool) data_get($result, 'queue_lane_contract.queue_lane_explicit', false),
                'terminal_loop_queue_lane_mode' => (string) data_get($result, 'queue_lane_contract.queue_lane_mode', ''),
                'terminal_loop_queue_lane_recommended_tag' => (string) data_get($result, 'queue_lane_contract.recommended_queue_tag', ''),
                'terminal_loop_queue_lane_next_iteration_preserves_lane' => (bool) data_get($result, 'queue_lane_contract.next_iteration_preserves_queue_lane', false),
                'terminal_loop_queue_lane_contract_hash' => (string) data_get($result, 'queue_lane_contract.queue_lane_contract_hash', ''),
                'terminal_loop_long_running_contract_schema' => (string) data_get($result, 'terminal_loop_operator_commands.long_running_loop_contract.schema_version', ''),
                'terminal_loop_next_iteration_command' => (string) data_get($result, 'terminal_loop_operator_commands.long_running_loop_contract.next_iteration_command', ''),
                'terminal_loop_recover_or_resume_current_packet_command' => (string) data_get($result, 'terminal_loop_operator_commands.recover_or_resume_current_packet', ''),
                'terminal_loop_inspect_active_leases_command' => (string) data_get($result, 'terminal_loop_operator_commands.inspect_active_leases', ''),
                'terminal_loop_stop_conditions' => (array) data_get($result, 'terminal_loop_operator_commands.long_running_loop_contract.stop_conditions', []),
                'terminal_loop_lease_renewal_cadence_seconds' => (int) data_get($result, 'terminal_loop_operator_commands.long_running_loop_contract.lease_renewal_cadence_seconds', 0),
                'terminal_loop_resumption_checkpoint_schema' => (string) data_get($result, 'terminal_loop_resumption_checkpoint.schema_version', ''),
                'terminal_loop_resumption_checkpoint_hash' => (string) data_get($result, 'terminal_loop_resumption_checkpoint_hash', ''),
                'terminal_loop_resumption_current_step' => (string) data_get($result, 'terminal_loop_resumption_checkpoint.current_step', ''),
                'terminal_loop_can_resume_without_chat_history' => (bool) data_get($result, 'terminal_loop_resumption_checkpoint.can_resume_without_chat_history', false),
                'terminal_loop_next_operator_action' => (string) data_get($result, 'terminal_loop_resumption_checkpoint.next_operator_action', ''),
                'terminal_loop_iteration_runbook_schema' => (string) data_get($result, 'terminal_loop_iteration_runbook.schema_version', ''),
                'terminal_loop_iteration_runbook_status' => (string) data_get($result, 'terminal_loop_iteration_runbook.status', ''),
                'terminal_loop_iteration_step_count' => count((array) data_get($result, 'terminal_loop_iteration_runbook.iteration_steps', [])),
                'terminal_loop_can_loop_without_chat_history' => (bool) data_get($result, 'terminal_loop_iteration_runbook.can_loop_without_chat_history', false),
                'terminal_loop_iteration_runbook_hash' => (string) data_get($result, 'terminal_loop_iteration_runbook_hash', ''),
                'terminal_loop_shell_recipe_schema' => (string) data_get($result, 'terminal_loop_shell_recipe.schema_version', ''),
                'terminal_loop_shell_recipe_status' => (string) data_get($result, 'terminal_loop_shell_recipe.status', ''),
                'terminal_loop_shell_recipe_safe_to_copy_after_operator_review' => (bool) data_get($result, 'terminal_loop_shell_recipe.safe_to_copy_after_operator_review', false),
                'terminal_loop_shell_recipe_can_execute_from_bootstrap' => (bool) data_get($result, 'terminal_loop_shell_recipe.can_execute_from_bootstrap', false),
                'terminal_loop_shell_recipe_requires_operator_to_run_worker_prompt' => (bool) data_get($result, 'terminal_loop_shell_recipe.requires_operator_to_run_worker_prompt', false),
                'terminal_loop_shell_recipe_max_cycles_recommended' => (int) data_get($result, 'terminal_loop_shell_recipe.max_cycles_recommended', 0),
                'terminal_loop_shell_recipe_hash' => (string) data_get($result, 'terminal_loop_shell_recipe_hash', ''),
            ],
            runtimeWritePerformed: ! (bool) data_get($result, 'preview_only', false)
                && ((bool) data_get($result, 'runtime_claim_persisted', false)
                    || (int) data_get($result, 'generated_task_count', 0) > 0),
        );
    }

    public function agentControlPlaneAgentRuntimeRegistryHeartbeatStatus(array $options = []): array
    {
        $repo = new AgentRuntimeRegistryHeartbeatRepository;
        $available = $repo->isAvailable();
        $stale = $repo->staleAgents();
        $status = $available ? 'available' : 'blocked';

        $payload = [
            'schema_version' => AgentRuntimeRegistryHeartbeatRepository::SCHEMA_VERSION,
            'status' => $status,
            'storage_prefix' => AgentRuntimeRegistryHeartbeatRepository::STORAGE_PREFIX,
            'default_ttl_seconds' => AgentRuntimeRegistryHeartbeatRepository::DEFAULT_TTL_SECONDS,
            'default_per_agent_cap' => AgentRuntimeRegistryHeartbeatRepository::DEFAULT_PER_AGENT_CAP,
            'default_stale_agent_detail_cap' => AgentRuntimeRegistryHeartbeatRepository::DEFAULT_STALE_AGENT_DETAIL_CAP,
            'default_index_agent_cap' => AgentRuntimeRegistryHeartbeatRepository::DEFAULT_INDEX_AGENT_CAP,
            'heartbeat_repository_available' => $available,
            'stale_agent_count' => (int) data_get($stale, 'stale_count', 0),
            'stale_agent_detail_count' => (int) data_get($stale, 'stale_detail_count', 0),
            'stale_agent_detail_truncated' => (bool) data_get($stale, 'stale_detail_truncated', false),
            'fresh_agent_count' => (int) data_get($stale, 'fresh_count', 0),
            'fresh_agent_detail_count' => (int) data_get($stale, 'fresh_detail_count', 0),
            'fresh_agent_detail_truncated' => (bool) data_get($stale, 'fresh_detail_truncated', false),
            'runtime_safety' => $repo->runtimeFlags(),
        ];

        return $this->wrapCertificationWorkbenchStatus(
            keyPrefix: 'agent_runtime_registry_heartbeat',
            label: 'Agent Runtime Registry Heartbeat',
            payload: $payload,
            statusKey: 'status',
            extraStatusFields: [
                'storage_prefix' => (string) $payload['storage_prefix'],
                'heartbeat_repository_available' => (bool) $payload['heartbeat_repository_available'],
                'default_ttl_seconds' => (int) $payload['default_ttl_seconds'],
                'stale_agent_count' => (int) $payload['stale_agent_count'],
                'stale_agent_detail_count' => (int) $payload['stale_agent_detail_count'],
                'stale_agent_detail_truncated' => (bool) $payload['stale_agent_detail_truncated'],
            ],
        );
    }

}
