<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskQueueOrchestrator;
use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskPacketQueueRepository;
use App\Services\Ai\SelfConstruction\AgentControlPlaneClaimLeaseRepository;
use App\Services\Ai\SelfConstruction\AgentControlPlaneTerminalWorkerBootstrapService;
use App\Services\Ai\SelfConstruction\AgentControlPlaneTerminalLoopHealthDigestService;
use App\Services\Ai\SelfConstruction\AgentControlPlaneOneShotWorkerPacketService;
use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskAutoReplenishmentService;
use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskLeaseRecoveryService;
use App\Services\Ai\SelfConstruction\AgentControlPlaneMultiAgentLoopCertificationService;
use App\Services\Ai\SelfConstruction\Support\AtlasEngineeringStringListNormalizer;
use Closure;
use Illuminate\Support\Facades\Storage;

/**
 * TERMINAL-LOOP PROBE concern, extracted from the god-class
 * {@see AgentControlPlaneMultiAgentLoopCertificationService}.
 *
 * Owns every runTerminal* probe + the terminalBootstrapContext helper.
 * Built from the same orchestrator/queue/leases dependencies the original
 * uses. Capabilities that STAY in the service (flattenStrings,
 * writeSetCollisionCount, terminalBootstrapRuntimeSafety) are passed in
 * as Closures — the SAME closure-binding pattern used by
 * AtlasLoopRefillerSupplyLaneCoordinator.
 */
class AgentControlPlaneMultiAgentLoopProbeRunner
{
    public const SYNTHETIC_FILE_NAMESPACE = AgentControlPlaneMultiAgentLoopCertificationService::SYNTHETIC_FILE_NAMESPACE;

    /**
     * @param  Closure(array<int,mixed>): array<int,string>  $flattenStringsFn
     * @param  Closure(array<int,string>): int  $writeSetCollisionCountFn
     * @param  Closure(array<int,mixed>): bool  $terminalBootstrapRuntimeSafetyFn
     */
    public function __construct(
        private readonly AgentControlPlaneTaskQueueOrchestrator $orchestrator,
        private readonly AgentControlPlaneTaskPacketQueueRepository $queue,
        private readonly AgentControlPlaneClaimLeaseRepository $leases,
        private readonly Closure $flattenStringsFn,
        private readonly Closure $writeSetCollisionCountFn,
        private readonly Closure $terminalBootstrapRuntimeSafetyFn,
    ) {}
    /**
     * Proxy: routes the kept-on-runtime `flattenStrings` capability through
     * the closure passed in __construct (same closure-binding pattern used by
     * AtlasLoopRefillerSupplyLaneCoordinator).
     *
     * @param  array<int,mixed>  $items
     * @return array<int,string>
     */
    private function flattenStrings(array $items): array
    {
        return ($this->flattenStringsFn)($items);
    }

    /**
     * Proxy: routes the kept-on-runtime `writeSetCollisionCount` capability.
     */
    private function writeSetCollisionCount(array $writeSets): int
    {
        return ($this->writeSetCollisionCountFn)($writeSets);
    }

    /**
     * Proxy: routes the kept-on-runtime `terminalBootstrapRuntimeSafety` capability.
     *
     * @param  array<int,mixed>  $results
     */
    private function terminalBootstrapRuntimeSafety(array $results): bool
    {
        return ($this->terminalBootstrapRuntimeSafetyFn)($results);
    }


function runTerminalBootstrapProbe(string $runId, int $probeAgentCount): array
    {
        $probeAgentCount = max(1, $probeAgentCount);
        $probeTag = 'terminal_worker_bootstrap_probe_'.$runId;
        $bootstrap = new AgentControlPlaneTerminalWorkerBootstrapService(
            new AgentControlPlaneTaskAutoReplenishmentService($this->orchestrator, $this->queue),
            $this->orchestrator,
            new AgentControlPlaneOneShotWorkerPacketService($this->leases, $this->queue),
            $this->queue,
            $this->leases,
        );

        $results = [];
        $taskIds = [];
        $leaseIds = [];
        $writeSets = [];
        $completionCommands = [];
        $completionResults = [];

        for ($i = 0; $i < $probeAgentCount; $i++) {
            $actor = sprintf('terminal_bootstrap_probe_%s_a%d', $runId, $i);
            $result = $bootstrap->bootstrap($this->terminalBootstrapContext($runId, $probeAgentCount), [
                'actor' => $actor,
                'target_min_claimable_tasks' => $probeAgentCount,
                'max_new_tasks' => $probeAgentCount,
                'queue_tags' => [$probeTag, 'terminal_worker_bootstrap_probe'],
                'reason' => 'multi_agent_loop_certification_terminal_bootstrap_probe',
            ]);

            $results[] = [
                'actor' => $actor,
                'status' => (string) ($result['status'] ?? ''),
                'task_packet_id' => (string) ($result['task_packet_id'] ?? ''),
                'lease_id' => (string) ($result['lease_id'] ?? ''),
                'one_shot_worker_packet_ready' => (bool) ($result['one_shot_worker_packet_ready'] ?? false),
                'one_shot_packet_hash' => (string) ($result['one_shot_packet_hash'] ?? ''),
                'completion_command' => (string) ($result['completion_command'] ?? ''),
                'completion_evidence_template_schema' => (string) data_get($result, 'completion_evidence_template.schema_version', ''),
                'completion_evidence_template_json_present' => (string) ($result['completion_evidence_template_json'] ?? '') !== '',
                'terminal_loop_operator_commands_schema' => (string) data_get($result, 'terminal_loop_operator_commands.schema_version', ''),
                'terminal_loop_queue_lane_contract_schema' => (string) data_get($result, 'queue_lane_contract.schema_version', ''),
                'terminal_loop_queue_lane_id' => (string) data_get($result, 'queue_lane_contract.queue_lane_id', ''),
                'terminal_loop_queue_lane_explicit' => (bool) data_get($result, 'queue_lane_contract.queue_lane_explicit', false),
                'terminal_loop_queue_lane_next_iteration_preserves_lane' => (bool) data_get($result, 'queue_lane_contract.next_iteration_preserves_queue_lane', false),
                'terminal_loop_queue_lane_contract_hash' => (string) data_get($result, 'queue_lane_contract.queue_lane_contract_hash', ''),
                'terminal_long_running_loop_contract_schema' => (string) data_get($result, 'terminal_loop_operator_commands.long_running_loop_contract.schema_version', ''),
                'terminal_long_running_loop_next_iteration_command' => (string) data_get($result, 'terminal_loop_operator_commands.long_running_loop_contract.next_iteration_command', ''),
                'terminal_long_running_loop_stop_conditions' => (array) data_get($result, 'terminal_loop_operator_commands.long_running_loop_contract.stop_conditions', []),
                'terminal_loop_resumption_checkpoint_schema' => (string) data_get($result, 'terminal_loop_resumption_checkpoint.schema_version', ''),
                'terminal_loop_resumption_checkpoint_hash' => (string) ($result['terminal_loop_resumption_checkpoint_hash'] ?? ''),
                'terminal_loop_resumption_current_step' => (string) data_get($result, 'terminal_loop_resumption_checkpoint.current_step', ''),
                'terminal_loop_can_resume_without_chat_history' => (bool) data_get($result, 'terminal_loop_resumption_checkpoint.can_resume_without_chat_history', false),
                'terminal_loop_iteration_runbook_schema' => (string) data_get($result, 'terminal_loop_iteration_runbook.schema_version', ''),
                'terminal_loop_iteration_runbook_hash' => (string) ($result['terminal_loop_iteration_runbook_hash'] ?? ''),
                'terminal_loop_iteration_status' => (string) data_get($result, 'terminal_loop_iteration_runbook.status', ''),
                'terminal_loop_iteration_step_count' => count((array) data_get($result, 'terminal_loop_iteration_runbook.iteration_steps', [])),
                'terminal_loop_can_loop_without_chat_history' => (bool) data_get($result, 'terminal_loop_iteration_runbook.can_loop_without_chat_history', false),
                'terminal_loop_shell_recipe_schema' => (string) data_get($result, 'terminal_loop_shell_recipe.schema_version', ''),
                'terminal_loop_shell_recipe_hash' => (string) ($result['terminal_loop_shell_recipe_hash'] ?? ''),
                'terminal_loop_shell_recipe_status' => (string) data_get($result, 'terminal_loop_shell_recipe.status', ''),
                'terminal_loop_shell_recipe_safe_to_copy_after_operator_review' => (bool) data_get($result, 'terminal_loop_shell_recipe.safe_to_copy_after_operator_review', false),
                'terminal_loop_shell_recipe_can_execute_from_bootstrap' => (bool) data_get($result, 'terminal_loop_shell_recipe.can_execute_from_bootstrap', true),
                'terminal_loop_shell_recipe_requires_operator_to_run_worker_prompt' => (bool) data_get($result, 'terminal_loop_shell_recipe.requires_operator_to_run_worker_prompt', false),
                'resume_after_interruption_command' => (string) ($result['resume_after_interruption_command'] ?? ''),
                'resumption_contract_present' => (string) data_get($result, 'resumption_contract.schema_version', '') === 'atlas.self_construction.agent_control_plane_worker_resumption_contract.v1',
                'resumption_requires_active_lease' => (bool) data_get($result, 'resumption_contract.resume_requires_active_lease', false),
                'claim_tag' => (string) ($result['claim_tag'] ?? ''),
                'write_set' => (array) data_get($result, 'one_shot_worker_packet.lease.write_set', []),
                'runtime_execution_allowed' => (bool) ($result['runtime_execution_allowed'] ?? true),
                'dispatch_allowed' => (bool) ($result['dispatch_allowed'] ?? true),
                'provider_call_allowed' => (bool) ($result['provider_call_allowed'] ?? true),
                'token_spend_allowed' => (bool) ($result['token_spend_allowed'] ?? true),
                'self_programming_allowed' => (bool) ($result['self_programming_allowed'] ?? true),
            ];

            if ((string) ($result['status'] ?? '') === 'ready_for_worker') {
                $taskIds[] = (string) ($result['task_packet_id'] ?? '');
                $leaseIds[] = (string) ($result['lease_id'] ?? '');
                $writeSets[] = (array) data_get($result, 'one_shot_worker_packet.lease.write_set', []);
                $completionCommands[] = (string) ($result['completion_command'] ?? '');

                $completionEvidence = [
                    'packet_id' => (string) ($result['task_packet_id'] ?? ''),
                    'lease_id' => (string) ($result['lease_id'] ?? ''),
                    'actor' => $actor,
                    'agent_id' => $actor,
                    'evidence_kind' => 'terminal_worker_bootstrap_probe',
                    'files_changed' => (array) data_get($result, 'one_shot_worker_packet.lease.write_set', []),
                    'commands_run' => ['terminal_worker_bootstrap_probe_complete_dry_run: passed'],
                    'tests_or_gates_result' => 'passed',
                    'git_status_short' => 'synthetic terminal bootstrap probe storage-only dry-run',
                    'git_diff_check_result' => 'clean',
                ];
                $completionEvidence['evidence_hash'] = AgentControlPlaneTaskQueueOrchestrator::canonicalCompletionEvidenceHash($completionEvidence);
                $completionResults[] = $this->orchestrator->completeDryRun(
                    (string) ($result['task_packet_id'] ?? ''),
                    (string) ($result['lease_id'] ?? ''),
                    $completionEvidence,
                );
            }
        }
        $invalidScopeProbe = $this->runTerminalBootstrapInvalidScopeProbe($bootstrap, $runId);
        $previewProbe = $this->runTerminalBootstrapPreviewProbe($bootstrap, $runId);
        $partialSupplyProbe = $this->runTerminalBootstrapPartialSupplyProbe($bootstrap, $runId);

        $readyCount = count(array_filter(
            $results,
            static fn (array $result): bool => (string) ($result['status'] ?? '') === 'ready_for_worker'
                && (bool) ($result['one_shot_worker_packet_ready'] ?? false),
        ));
        $completedCount = count(array_filter(
            $completionResults,
            static fn (array $result): bool => (string) ($result['event'] ?? '') === 'completed_dry_run',
        ));
        $structuredCompletionEvidenceValidCount = count(array_filter(
            $completionResults,
            static fn (array $result): bool => (bool) data_get($result, 'evidence_validation.structured_completion_evidence_valid', false),
        ));
        $completionEvidenceFilesWithinScopeCount = count(array_filter(
            $completionResults,
            static fn (array $result): bool => (bool) data_get($result, 'evidence_validation.files_changed_within_allowed_scope', false)
                && data_get($result, 'evidence_validation.files_changed_outside_allowed_scope', []) === [],
        ));
        $completionEvidenceValidationHashes = array_values(array_filter(array_map(
            static fn (array $result): string => (string) data_get($result, 'evidence_validation.evidence_validation_hash', ''),
            $completionResults,
        )));
        $writeSetCollisionCount = $this->writeSetCollisionCount($writeSets);
        $runtimeSafety = $this->terminalBootstrapRuntimeSafety($results);
        $completionCommandUsesDryRun = $completionCommands !== [] && count(array_filter(
            $completionCommands,
            static fn (string $command): bool => str_contains($command, '--agent-control-plane-task-queue-complete-dry-run-status'),
        )) === count($completionCommands);
        $completionEvidenceTemplatePresent = $results !== [] && count(array_filter(
            $results,
            static fn (array $result): bool => (string) ($result['completion_evidence_template_schema'] ?? '') === 'atlas.self_construction.agent_control_plane_task_queue_completion_evidence.v1'
                && (bool) ($result['completion_evidence_template_json_present'] ?? false),
        )) === count($results);
        $terminalLoopOperatorCommandsPresent = $results !== [] && count(array_filter(
            $results,
            static fn (array $result): bool => (string) ($result['terminal_loop_operator_commands_schema'] ?? '') === 'atlas.self_construction.agent_control_plane_terminal_loop_operator_commands.v1'
                && (string) ($result['terminal_loop_queue_lane_contract_schema'] ?? '') === 'atlas.self_construction.agent_control_plane_terminal_loop_queue_lane_contract.v1'
                && (bool) ($result['terminal_loop_queue_lane_explicit'] ?? false)
                && (bool) ($result['terminal_loop_queue_lane_next_iteration_preserves_lane'] ?? false)
                && (string) ($result['terminal_loop_queue_lane_contract_hash'] ?? '') !== ''
                && (string) ($result['terminal_long_running_loop_contract_schema'] ?? '') === 'atlas.self_construction.agent_control_plane_terminal_long_running_loop_contract.v1'
                && str_contains((string) ($result['terminal_long_running_loop_next_iteration_command'] ?? ''), '--agent-control-plane-terminal-worker-bootstrap-status')
                && in_array('completion_evidence_validation_not_valid', (array) ($result['terminal_long_running_loop_stop_conditions'] ?? []), true),
        )) === count($results);
        $resumptionContractsPresent = $results !== [] && count(array_filter(
            $results,
            static fn (array $result): bool => (bool) ($result['resumption_contract_present'] ?? false)
                && (bool) ($result['resumption_requires_active_lease'] ?? false)
                && str_contains((string) ($result['resume_after_interruption_command'] ?? ''), '--agent-control-plane-task-lease-recovery-status'),
        )) === count($results);
        $resumptionCheckpointsPresent = $results !== [] && count(array_filter(
            $results,
            static fn (array $result): bool => (string) ($result['terminal_loop_resumption_checkpoint_schema'] ?? '') === 'atlas.self_construction.agent_control_plane_terminal_loop_resumption_checkpoint.v1'
                && (string) ($result['terminal_loop_resumption_checkpoint_hash'] ?? '') !== ''
                && (string) ($result['terminal_loop_resumption_current_step'] ?? '') === 'claimed_packet_ready_for_one_shot_worker'
                && (bool) ($result['terminal_loop_can_resume_without_chat_history'] ?? false),
        )) === count($results);
        $iterationRunbooksPresent = $results !== [] && count(array_filter(
            $results,
            static fn (array $result): bool => (string) ($result['terminal_loop_iteration_runbook_schema'] ?? '') === 'atlas.self_construction.agent_control_plane_terminal_loop_iteration_runbook.v1'
                && (string) ($result['terminal_loop_iteration_runbook_hash'] ?? '') !== ''
                && (string) ($result['terminal_loop_iteration_status'] ?? '') === 'ready_for_single_packet_iteration'
                && (int) ($result['terminal_loop_iteration_step_count'] ?? 0) === 6
                && (bool) ($result['terminal_loop_can_loop_without_chat_history'] ?? false),
        )) === count($results);
        $shellRecipesPresent = $results !== [] && count(array_filter(
            $results,
            static fn (array $result): bool => (string) ($result['terminal_loop_shell_recipe_schema'] ?? '') === 'atlas.self_construction.agent_control_plane_terminal_loop_shell_recipe.v1'
                && (string) ($result['terminal_loop_shell_recipe_hash'] ?? '') !== ''
                && (string) ($result['terminal_loop_shell_recipe_status'] ?? '') === 'shell_recipe_ready'
                && (bool) ($result['terminal_loop_shell_recipe_safe_to_copy_after_operator_review'] ?? false)
                && ! (bool) ($result['terminal_loop_shell_recipe_can_execute_from_bootstrap'] ?? true)
                && (bool) ($result['terminal_loop_shell_recipe_requires_operator_to_run_worker_prompt'] ?? false),
        )) === count($results);
        $leasesClosed = $completedCount === $readyCount
            && count(array_filter(
                $leaseIds,
                fn (string $leaseId): bool => $leaseId !== ''
                    && (string) data_get($this->leases->get($leaseId), 'lease_status', '') === AgentControlPlaneClaimLeaseRepository::LEASE_STATUS_ACTIVE,
            )) === 0;

        $status = $readyCount === $probeAgentCount
            && count(array_unique($taskIds)) === $probeAgentCount
            && count(array_unique($leaseIds)) === $probeAgentCount
            && $writeSetCollisionCount === 0
            && $completionCommandUsesDryRun
            && $completionEvidenceTemplatePresent
            && $terminalLoopOperatorCommandsPresent
            && $completedCount === $probeAgentCount
            && $leasesClosed
            && $resumptionContractsPresent
            && $resumptionCheckpointsPresent
            && $iterationRunbooksPresent
            && $shellRecipesPresent
            && (bool) ($invalidScopeProbe['rejected'] ?? false)
            && (bool) ($previewProbe['read_only_verified'] ?? false)
            && (bool) ($partialSupplyProbe['blocked_before_claim'] ?? false)
            && $runtimeSafety
                ? 'available'
                : 'blocked';

        return [
            'status' => $status,
            'probe_agent_count' => $probeAgentCount,
            'ready_count' => $readyCount,
            'completed_dry_run_count' => $completedCount,
            'distinct_task_count' => count(array_unique($taskIds)),
            'distinct_lease_count' => count(array_unique($leaseIds)),
            'write_set_collision_count' => $writeSetCollisionCount,
            'one_shot_packets_ready' => $readyCount === $probeAgentCount,
            'completion_command_uses_dry_run' => $completionCommandUsesDryRun,
            'completion_evidence_template_present' => $completionEvidenceTemplatePresent,
            'terminal_loop_operator_commands_present' => $terminalLoopOperatorCommandsPresent,
            'structured_completion_evidence_all_valid' => $structuredCompletionEvidenceValidCount === $readyCount,
            'structured_completion_evidence_valid_count' => $structuredCompletionEvidenceValidCount,
            'completion_evidence_files_within_scope_all_valid' => $completionEvidenceFilesWithinScopeCount === $readyCount,
            'completion_evidence_files_within_scope_count' => $completionEvidenceFilesWithinScopeCount,
            'completion_evidence_scope_escape_count' => $readyCount - $completionEvidenceFilesWithinScopeCount,
            'completion_evidence_validation_hash_count' => count(array_unique($completionEvidenceValidationHashes)),
            'completion_evidence_validation_hashes' => array_values(array_unique($completionEvidenceValidationHashes)),
            'resumption_contracts_present' => $resumptionContractsPresent,
            'resumption_checkpoints_present' => $resumptionCheckpointsPresent,
            'iteration_runbooks_present' => $iterationRunbooksPresent,
            'shell_recipes_present' => $shellRecipesPresent,
            'parallel_lanes_distinct' => count(array_unique($taskIds)) === $probeAgentCount
                && count(array_unique($leaseIds)) === $probeAgentCount
                && $writeSetCollisionCount === 0,
            'leases_closed_by_dry_run' => $leasesClosed,
            'invalid_scope_rejected' => (bool) ($invalidScopeProbe['rejected'] ?? false),
            'invalid_scope_probe' => $invalidScopeProbe,
            'preview_read_only' => (bool) ($previewProbe['read_only_verified'] ?? false),
            'preview_probe' => $previewProbe,
            'partial_supply_blocks_before_claim' => (bool) ($partialSupplyProbe['blocked_before_claim'] ?? false),
            'partial_supply_probe' => $partialSupplyProbe,
            'runtime_safety_all_false' => $runtimeSafety,
            'queue_tag' => $probeTag,
            'results' => $results,
            'completion_events' => array_map(
                static fn (array $result): string => (string) ($result['event'] ?? ''),
                $completionResults,
            ),
        ];
    }

function runTerminalFleetLaunchPlanProbe(string $runId, int $probeTerminalCount): array
    {
        $queueTag = 'terminal_fleet_launch_plan_probe_'.$runId;
        $seededPacketIds = [];
        $seedFailures = [];
        $namespace = self::SYNTHETIC_FILE_NAMESPACE.'/fleet_launch_plan_probe';

        for ($index = 0; $index < $probeTerminalCount; $index++) {
            $taskPacketId = sprintf('fleet_probe_%s_%d', $runId, $index);
            $allowedFile = sprintf('%s/%s_%d.php', $namespace, strtolower($runId), $index);
            $orchestration = $this->orchestrator->prepareAndEnqueue([
                'task_packet' => [
                    'task_packet_id' => $taskPacketId,
                    'objective' => sprintf('terminal fleet launch plan probe %d', $index),
                    'operator_id' => 'multi-agent-loop-certification',
                    'allowed_files' => [$allowedFile],
                    'scope_in' => [$allowedFile],
                    'acceptance_criteria' => ['fleet_launch_plan_probe_ok'],
                    'required_evidence' => ['fleet_launch_plan_checked'],
                    'risk_level' => 'low',
                    'rollback_strategy' => 'plan_only',
                ],
                'queue' => ['priority' => 5, 'tags' => ['terminal_fleet_launch_plan_probe', $queueTag]],
            ]);

            if ((string) ($orchestration['event'] ?? '') === 'prepared_and_enqueued'
                && (string) data_get($orchestration, 'queue_entry.status', '') === 'ok') {
                $seededPacketIds[] = $taskPacketId;
            } else {
                $seedFailures[] = [
                    'task_packet_id' => $taskPacketId,
                    'event' => (string) ($orchestration['event'] ?? ''),
                    'queue_status' => (string) data_get($orchestration, 'queue_entry.status', ''),
                ];
            }
        }

        $digest = (new AgentControlPlaneTerminalLoopHealthDigestService($this->queue, new AgentControlPlaneTaskLeaseRecoveryService))->digest([
            'actor' => 'terminal-fleet-probe-'.$runId,
            'target_min_claimable_tasks' => $probeTerminalCount,
            'max_new_tasks' => $probeTerminalCount,
            'queue_tags' => [$queueTag],
        ]);
        $assignments = (array) data_get($digest, 'terminal_loop_fleet_launch_plan.terminal_assignments', []);
        $actors = array_values(array_filter(array_map(
            static fn (array $assignment): string => (string) ($assignment['actor'] ?? ''),
            $assignments,
        )));
        $commands = array_values(array_filter(array_map(
            static fn (array $assignment): string => (string) ($assignment['execute_bootstrap_command'] ?? ''),
            $assignments,
        )));
        $allCommandsLaneBound = $commands !== [] && count(array_filter(
            $commands,
            static fn (string $command): bool => str_contains($command, '--agent-control-plane-terminal-worker-bootstrap-status')
                && str_contains($command, '--queue-tag='.$queueTag)
                && ! str_contains($command, '--terminal-worker-bootstrap-preview'),
        )) === count($commands);

        $readyPathVerified = (string) data_get($digest, 'terminal_loop_fleet_launch_plan.status') === 'fleet_launch_plan_ready'
            && (bool) data_get($digest, 'terminal_loop_fleet_launch_plan.safe_to_start_now') === true
            && (bool) data_get($digest, 'terminal_loop_fleet_launch_plan.can_execute_from_digest') === false
            && (bool) data_get($digest, 'terminal_loop_fleet_launch_plan.can_claim_from_digest') === false
            && (int) data_get($digest, 'terminal_loop_fleet_launch_plan.recommended_terminal_count') === $probeTerminalCount
            && count($assignments) === $probeTerminalCount
            && count(array_unique($actors)) === $probeTerminalCount
            && $allCommandsLaneBound;
        $laneBoundCommandsVerified = (string) data_get($digest, 'terminal_loop_fleet_lane_isolation.status') === 'fleet_lane_isolation_tagged_lane_verified'
            && (bool) data_get($digest, 'terminal_loop_fleet_lane_isolation.all_commands_lane_bound') === true
            && data_get($digest, 'terminal_loop_fleet_lane_isolation.can_change_tags_from_digest') === false
            && in_array('--queue-tag='.$queueTag, (array) data_get($digest, 'terminal_loop_fleet_lane_isolation.required_tag_args', []), true)
            && data_get($digest, 'terminal_loop_fleet_lane_isolation.terminal_loop_fleet_lane_isolation_hash') !== null;
        $cycleSupervisorLaunchPathVerified = (string) data_get($digest, 'terminal_loop_cycle_supervisor.schema_version') === AgentControlPlaneTerminalLoopHealthDigestService::CYCLE_SUPERVISOR_SCHEMA_VERSION
            && (string) data_get($digest, 'terminal_loop_cycle_supervisor.status') === 'cycle_worker_launch_ready'
            && (string) data_get($digest, 'terminal_loop_cycle_supervisor.cycle_state') === 'launch_or_continue_workers'
            && (bool) data_get($digest, 'terminal_loop_cycle_supervisor.next_command_is_lane_bound') === true
            && str_contains((string) data_get($digest, 'terminal_loop_cycle_supervisor.next_command'), '--queue-tag='.$queueTag)
            && data_get($digest, 'terminal_loop_cycle_supervisor.can_execute_next_command') === false
            && data_get($digest, 'terminal_loop_cycle_supervisor.can_claim_from_supervisor') === false
            && data_get($digest, 'terminal_loop_cycle_supervisor.terminal_loop_cycle_supervisor_hash') !== null;
        $runbookSteps = (array) data_get($digest, 'terminal_loop_fleet_launch_runbook.terminal_steps', []);
        $runbookCommands = array_values(array_filter(array_map(
            static fn (array $step): string => (string) ($step['command'] ?? ''),
            $runbookSteps,
        )));
        $runbookReadyPathVerified = (string) data_get($digest, 'terminal_loop_fleet_launch_runbook.schema_version') === AgentControlPlaneTerminalLoopHealthDigestService::FLEET_LAUNCH_RUNBOOK_SCHEMA_VERSION
            && (string) data_get($digest, 'terminal_loop_fleet_launch_runbook.status') === 'fleet_launch_runbook_ready'
            && (bool) data_get($digest, 'terminal_loop_fleet_launch_runbook.safe_to_copy_after_operator_review') === true
            && (bool) data_get($digest, 'terminal_loop_fleet_launch_runbook.can_resume_without_chat_history') === true
            && (int) data_get($digest, 'terminal_loop_fleet_launch_runbook.terminal_count') === $probeTerminalCount
            && count($runbookSteps) === $probeTerminalCount
            && $runbookCommands !== []
            && count(array_filter(
                $runbookCommands,
                static fn (string $command): bool => str_contains($command, '--agent-control-plane-terminal-worker-bootstrap-status')
                    && str_contains($command, '--queue-tag='.$queueTag),
            )) === count($runbookCommands)
            && in_array('copy_each_terminal_command_into_separate_terminal', (array) data_get($digest, 'terminal_loop_fleet_launch_runbook.ordered_operator_sequence', []), true)
            && str_contains((string) data_get($digest, 'terminal_loop_fleet_launch_runbook.resume_after_interruption.resume_command'), '--agent-control-plane-terminal-loop-health-digest-status')
            && (bool) data_get($digest, 'terminal_loop_fleet_launch_runbook.resume_after_interruption.requires_fresh_health_digest_before_starting_more_terminals') === true
            && in_array('health_digest_no_longer_ready', (array) data_get($digest, 'terminal_loop_fleet_launch_runbook.stop_conditions', []), true)
            && data_get($digest, 'terminal_loop_fleet_launch_runbook.can_start_terminals_from_runbook') === false
            && data_get($digest, 'terminal_loop_fleet_launch_runbook.can_execute_commands_from_runbook') === false
            && data_get($digest, 'terminal_loop_fleet_launch_runbook.terminal_loop_fleet_launch_runbook_hash') !== null;

        return [
            'status' => $readyPathVerified ? 'available' : 'blocked',
            'queue_tag' => $queueTag,
            'probe_terminal_count' => $probeTerminalCount,
            'seeded_packet_count' => count($seededPacketIds),
            'seeded_packet_ids' => $seededPacketIds,
            'seed_failures' => $seedFailures,
            'digest_status' => (string) data_get($digest, 'status'),
            'fleet_plan_status' => (string) data_get($digest, 'terminal_loop_fleet_launch_plan.status'),
            'safe_to_start_now' => (bool) data_get($digest, 'terminal_loop_fleet_launch_plan.safe_to_start_now'),
            'recommended_terminal_count' => (int) data_get($digest, 'terminal_loop_fleet_launch_plan.recommended_terminal_count'),
            'terminal_assignment_count' => count($assignments),
            'terminal_actors_distinct' => count(array_unique($actors)) === count($actors) && count($actors) === $probeTerminalCount,
            'terminal_commands_lane_bound' => $allCommandsLaneBound,
            'lane_isolation_status' => (string) data_get($digest, 'terminal_loop_fleet_lane_isolation.status'),
            'lane_bound_commands_verified' => $laneBoundCommandsVerified,
            'cycle_supervisor_status' => (string) data_get($digest, 'terminal_loop_cycle_supervisor.status'),
            'cycle_supervisor_cycle_state' => (string) data_get($digest, 'terminal_loop_cycle_supervisor.cycle_state'),
            'cycle_supervisor_next_command_is_lane_bound' => (bool) data_get($digest, 'terminal_loop_cycle_supervisor.next_command_is_lane_bound'),
            'cycle_supervisor_launch_path_verified' => $cycleSupervisorLaunchPathVerified,
            'cycle_supervisor_hash' => (string) data_get($digest, 'terminal_loop_cycle_supervisor.terminal_loop_cycle_supervisor_hash'),
            'fleet_launch_runbook_status' => (string) data_get($digest, 'terminal_loop_fleet_launch_runbook.status'),
            'fleet_launch_runbook_terminal_count' => (int) data_get($digest, 'terminal_loop_fleet_launch_runbook.terminal_count'),
            'fleet_launch_runbook_ready_path_verified' => $runbookReadyPathVerified,
            'fleet_launch_runbook_hash' => (string) data_get($digest, 'terminal_loop_fleet_launch_runbook.terminal_loop_fleet_launch_runbook_hash'),
            'can_execute_from_digest' => (bool) data_get($digest, 'terminal_loop_fleet_launch_plan.can_execute_from_digest'),
            'can_claim_from_digest' => (bool) data_get($digest, 'terminal_loop_fleet_launch_plan.can_claim_from_digest'),
            'blocked_reasons' => (array) data_get($digest, 'terminal_loop_fleet_launch_plan.blocked_reasons', []),
            'fleet_launch_plan_hash' => (string) data_get($digest, 'terminal_loop_fleet_launch_plan.terminal_loop_fleet_launch_plan_hash'),
            'ready_path_verified' => $readyPathVerified,
        ];
    }

function runTerminalBootstrapPartialSupplyProbe(AgentControlPlaneTerminalWorkerBootstrapService $bootstrap, string $runId): array
    {
        $queueTag = 'terminal_bootstrap_partial_supply_'.$runId;
        $taskPacketId = 'terminal_bootstrap_partial_supply_'.$runId;
        $allowedFile = sprintf('%s/terminal_bootstrap_partial_supply/%s.php', self::SYNTHETIC_FILE_NAMESPACE, strtolower($runId));

        $orchestration = $this->orchestrator->prepareAndEnqueue([
            'task_packet' => [
                'task_packet_id' => $taskPacketId,
                'objective' => 'terminal bootstrap partial supply should block before claim',
                'operator_id' => 'multi-agent-loop-certification',
                'allowed_files' => [$allowedFile],
                'scope_in' => [$allowedFile],
                'acceptance_criteria' => ['terminal_bootstrap_partial_supply_blocks_before_claim'],
                'required_evidence' => ['terminal_bootstrap_partial_supply_checked'],
                'risk_level' => 'low',
                'rollback_strategy' => 'dry_run_only',
            ],
            'queue' => ['priority' => 5, 'tags' => ['terminal_bootstrap_partial_supply', $queueTag]],
        ]);

        $result = $bootstrap->bootstrap([], [
            'actor' => 'terminal_bootstrap_partial_supply_'.$runId,
            'target_min_claimable_tasks' => 3,
            'max_new_tasks' => 0,
            'queue_tags' => [$queueTag],
            'reason' => 'multi_agent_loop_certification_terminal_bootstrap_partial_supply_probe',
        ]);
        $queueRecord = $this->queue->get($taskPacketId);
        $activeLeaseCount = count($this->leases->activeLeases(['task_packet_id' => $taskPacketId]));
        $blockedBeforeClaim = (string) data_get($result, 'status') === 'blocked'
            && (string) data_get($result, 'claim_event') === 'task_supply_below_target_blocked_before_claim'
            && (bool) data_get($result, 'runtime_claim_persisted', true) === false
            && (bool) data_get($result, 'one_shot_worker_packet_ready', true) === false
            && (string) data_get($queueRecord, 'status') === 'claimable'
            && $activeLeaseCount === 0
            && in_array('terminal_worker_bootstrap_task_supply_gate_blocks_before_claim', (array) data_get($result, 'non_execution_guarantees', []), true);

        return [
            'status' => $blockedBeforeClaim ? 'available' : 'blocked',
            'queue_tag' => $queueTag,
            'seeded_task_packet_id' => $taskPacketId,
            'orchestration_event' => (string) ($orchestration['event'] ?? ''),
            'queue_entry_status' => (string) data_get($orchestration, 'queue_entry.status', ''),
            'bootstrap_status' => (string) data_get($result, 'status', ''),
            'claim_event' => (string) data_get($result, 'claim_event', ''),
            'runtime_claim_persisted' => (bool) data_get($result, 'runtime_claim_persisted', false),
            'one_shot_worker_packet_ready' => (bool) data_get($result, 'one_shot_worker_packet_ready', false),
            'queue_status_after_bootstrap' => (string) data_get($queueRecord, 'status', ''),
            'active_lease_count_after_bootstrap' => $activeLeaseCount,
            'blocked_before_claim' => $blockedBeforeClaim,
        ];
    }

function runTerminalFleetPartialSupplyGateProbe(string $runId): array
    {
        $queueTag = 'terminal_fleet_partial_supply_probe_'.$runId;
        $taskPacketId = 'fleet_partial_supply_probe_'.$runId;
        $allowedFile = sprintf('%s/fleet_partial_supply_probe/%s.php', self::SYNTHETIC_FILE_NAMESPACE, strtolower($runId));

        $orchestration = $this->orchestrator->prepareAndEnqueue([
            'task_packet' => [
                'task_packet_id' => $taskPacketId,
                'objective' => 'terminal fleet partial supply launch gate probe',
                'operator_id' => 'multi-agent-loop-certification',
                'allowed_files' => [$allowedFile],
                'scope_in' => [$allowedFile],
                'acceptance_criteria' => ['fleet_partial_supply_gate_ok'],
                'required_evidence' => ['fleet_partial_supply_gate_checked'],
                'risk_level' => 'low',
                'rollback_strategy' => 'dry_run_only',
            ],
            'queue' => ['priority' => 5, 'tags' => ['terminal_fleet_partial_supply_probe', $queueTag]],
        ]);

        $digest = (new AgentControlPlaneTerminalLoopHealthDigestService($this->queue, new AgentControlPlaneTaskLeaseRecoveryService))->digest([
            'actor' => 'terminal-fleet-partial-supply-probe-'.$runId,
            'target_min_claimable_tasks' => 3,
            'max_new_tasks' => 3,
            'queue_tags' => [$queueTag],
        ]);

        $targetTagArg = '--queue-tag='.$queueTag;
        $launchBlocked = (string) data_get($digest, 'terminal_loop_fleet_launch_plan.status') === 'fleet_launch_plan_blocked'
            && (bool) data_get($digest, 'terminal_loop_fleet_launch_plan.safe_to_start_now') === false
            && (int) data_get($digest, 'terminal_loop_fleet_launch_plan.recommended_terminal_count') === 0
            && data_get($digest, 'terminal_loop_fleet_launch_plan.copy_paste_terminal_commands', []) === [];
        $replenishmentRequired = (string) data_get($digest, 'terminal_loop_fleet_replenishment_plan.status') === 'fleet_replenishment_required'
            && (int) data_get($digest, 'terminal_loop_fleet_replenishment_plan.required_new_task_count') === 2
            && (bool) data_get($digest, 'terminal_loop_fleet_replenishment_plan.should_replenish_now') === true
            && str_contains((string) data_get($digest, 'terminal_loop_fleet_operator_handoff.primary_command'), $targetTagArg);
        $cycleSupervisorBlocksLaunch = (string) data_get($digest, 'terminal_loop_cycle_supervisor.status') === 'cycle_replenishment_required'
            && (string) data_get($digest, 'terminal_loop_cycle_supervisor.cycle_state') === 'replenish_before_launch'
            && (bool) data_get($digest, 'terminal_loop_cycle_supervisor.transition_guards.replenish_before_launch') === true
            && data_get($digest, 'terminal_loop_cycle_supervisor.can_execute_next_command') === false;
        $partialSupplyLaunchBlocked = (int) data_get($digest, 'queue_health.claimable_task_count') === 1
            && (bool) data_get($digest, 'queue_health.target_min_claimable_tasks_met') === false
            && in_array('task_supply_below_target_replenish_before_launch', (array) data_get($digest, 'terminal_loop_fleet_launch_plan.blocked_reasons', []), true)
            && $launchBlocked
            && $replenishmentRequired
            && $cycleSupervisorBlocksLaunch;

        return [
            'status' => $partialSupplyLaunchBlocked ? 'available' : 'blocked',
            'queue_tag' => $queueTag,
            'seeded_task_packet_id' => $taskPacketId,
            'orchestration_event' => (string) ($orchestration['event'] ?? ''),
            'queue_entry_status' => (string) data_get($orchestration, 'queue_entry.status', ''),
            'target_min_claimable_tasks' => 3,
            'claimable_task_count' => (int) data_get($digest, 'queue_health.claimable_task_count'),
            'target_min_claimable_tasks_met' => (bool) data_get($digest, 'queue_health.target_min_claimable_tasks_met'),
            'fleet_plan_status' => (string) data_get($digest, 'terminal_loop_fleet_launch_plan.status'),
            'safe_to_start_now' => (bool) data_get($digest, 'terminal_loop_fleet_launch_plan.safe_to_start_now'),
            'recommended_terminal_count' => (int) data_get($digest, 'terminal_loop_fleet_launch_plan.recommended_terminal_count'),
            'blocked_reasons' => (array) data_get($digest, 'terminal_loop_fleet_launch_plan.blocked_reasons', []),
            'replenishment_status' => (string) data_get($digest, 'terminal_loop_fleet_replenishment_plan.status'),
            'required_new_task_count' => (int) data_get($digest, 'terminal_loop_fleet_replenishment_plan.required_new_task_count'),
            'cycle_supervisor_status' => (string) data_get($digest, 'terminal_loop_cycle_supervisor.status'),
            'cycle_supervisor_cycle_state' => (string) data_get($digest, 'terminal_loop_cycle_supervisor.cycle_state'),
            'partial_supply_launch_blocked' => $partialSupplyLaunchBlocked,
        ];
    }

function runTerminalFleetLaneIsolationNegativeProbe(string $runId): array
    {
        $targetQueueTag = 'terminal_fleet_lane_negative_target_'.$runId;
        $otherQueueTag = 'terminal_fleet_lane_negative_other_'.$runId;
        $taskPacketId = 'fleet_lane_negative_probe_'.$runId;
        $allowedFile = sprintf('%s/fleet_lane_negative_probe/%s.php', self::SYNTHETIC_FILE_NAMESPACE, strtolower($runId));

        $orchestration = $this->orchestrator->prepareAndEnqueue([
            'task_packet' => [
                'task_packet_id' => $taskPacketId,
                'objective' => 'terminal fleet lane isolation negative probe',
                'operator_id' => 'multi-agent-loop-certification',
                'allowed_files' => [$allowedFile],
                'scope_in' => [$allowedFile],
                'acceptance_criteria' => ['fleet_lane_negative_probe_ok'],
                'required_evidence' => ['fleet_lane_negative_probe_checked'],
                'risk_level' => 'low',
                'rollback_strategy' => 'dry_run_only',
            ],
            'queue' => ['priority' => 5, 'tags' => ['terminal_fleet_lane_isolation_negative_probe', $otherQueueTag]],
        ]);

        $digest = (new AgentControlPlaneTerminalLoopHealthDigestService($this->queue, new AgentControlPlaneTaskLeaseRecoveryService))->digest([
            'actor' => 'terminal-fleet-lane-negative-probe-'.$runId,
            'target_min_claimable_tasks' => 1,
            'max_new_tasks' => 1,
            'queue_tags' => [$targetQueueTag],
        ]);

        $commands = $this->flattenStrings([
            data_get($digest, 'terminal_loop_fleet_launch_plan.copy_paste_terminal_commands', []),
            data_get($digest, 'terminal_loop_fleet_operator_handoff.primary_command'),
            data_get($digest, 'terminal_loop_fleet_operator_handoff.copy_paste_commands', []),
            data_get($digest, 'terminal_loop_fleet_replenishment_plan.commands', []),
            data_get($digest, 'terminal_loop_fleet_launch_plan.post_launch_observability_commands', []),
            data_get($digest, 'terminal_loop_fleet_lane_isolation.command_lane_checks', []),
        ]);

        $targetTagArg = '--queue-tag='.$targetQueueTag;
        $otherTagArg = '--queue-tag='.$otherQueueTag;
        $commandsUsingOtherTag = array_values(array_filter(
            $commands,
            static fn (string $command): bool => str_contains($command, $otherTagArg),
        ));

        $requiredTagArgs = (array) data_get($digest, 'terminal_loop_fleet_lane_isolation.required_tag_args', []);
        $launchBlocked = (string) data_get($digest, 'terminal_loop_fleet_launch_plan.status') === 'fleet_launch_plan_blocked'
            && (bool) data_get($digest, 'terminal_loop_fleet_launch_plan.safe_to_start_now') === false
            && (int) data_get($digest, 'terminal_loop_fleet_launch_plan.recommended_terminal_count') === 0
            && data_get($digest, 'terminal_loop_fleet_launch_plan.copy_paste_terminal_commands', []) === [];
        $hiddenSupplyDetected = (int) data_get($digest, 'queue_health.claimable_task_count') === 0
            && (int) data_get($digest, 'queue_health.hidden_claimable_outside_requested_tags') >= 1
            && (bool) data_get($digest, 'queue_health.tag_filtered_supply_gap') === true;
        $replenishOwnLane = (string) data_get($digest, 'terminal_loop_fleet_replenishment_plan.status') === 'fleet_replenishment_required'
            && (string) data_get($digest, 'terminal_loop_fleet_operator_handoff.status') === 'fleet_operator_handoff_replenish_before_launch'
            && str_contains((string) data_get($digest, 'terminal_loop_fleet_operator_handoff.primary_command'), $targetTagArg)
            && ! str_contains((string) data_get($digest, 'terminal_loop_fleet_operator_handoff.primary_command'), $otherTagArg);
        $laneGuardHeld = (string) data_get($digest, 'terminal_loop_fleet_lane_isolation.status') === 'fleet_lane_isolation_tagged_lane_verified'
            && (bool) data_get($digest, 'terminal_loop_fleet_lane_isolation.all_commands_lane_bound') === true
            && in_array($targetTagArg, $requiredTagArgs, true)
            && $commandsUsingOtherTag === [];

        $noCrossLaneLaunchVerified = $hiddenSupplyDetected && $launchBlocked && $replenishOwnLane && $laneGuardHeld;

        return [
            'status' => $noCrossLaneLaunchVerified ? 'available' : 'blocked',
            'target_queue_tag' => $targetQueueTag,
            'other_queue_tag' => $otherQueueTag,
            'seeded_task_packet_id' => $taskPacketId,
            'orchestration_event' => (string) ($orchestration['event'] ?? ''),
            'queue_entry_status' => (string) data_get($orchestration, 'queue_entry.status', ''),
            'target_claimable_task_count' => (int) data_get($digest, 'queue_health.claimable_task_count'),
            'hidden_claimable_outside_requested_tags' => (int) data_get($digest, 'queue_health.hidden_claimable_outside_requested_tags'),
            'tag_filtered_supply_gap' => (bool) data_get($digest, 'queue_health.tag_filtered_supply_gap'),
            'launch_blocked' => $launchBlocked,
            'fleet_plan_status' => (string) data_get($digest, 'terminal_loop_fleet_launch_plan.status'),
            'recommended_terminal_count' => (int) data_get($digest, 'terminal_loop_fleet_launch_plan.recommended_terminal_count'),
            'copy_paste_terminal_command_count' => count((array) data_get($digest, 'terminal_loop_fleet_launch_plan.copy_paste_terminal_commands', [])),
            'replenishment_status' => (string) data_get($digest, 'terminal_loop_fleet_replenishment_plan.status'),
            'handoff_status' => (string) data_get($digest, 'terminal_loop_fleet_operator_handoff.status'),
            'handoff_replenishes_target_lane' => $replenishOwnLane,
            'lane_isolation_status' => (string) data_get($digest, 'terminal_loop_fleet_lane_isolation.status'),
            'required_tag_args' => $requiredTagArgs,
            'commands_using_other_lane_tag_count' => count($commandsUsingOtherTag),
            'no_cross_lane_launch_verified' => $noCrossLaneLaunchVerified,
        ];
    }

function runTerminalFleetResumeRollupProbe(string $runId): array
    {
        $queueTag = 'terminal_fleet_resume_rollup_probe_'.$runId;
        $taskPacketId = 'fleet_resume_rollup_probe_'.$runId;
        $allowedFile = sprintf('%s/fleet_resume_rollup_probe/%s.php', self::SYNTHETIC_FILE_NAMESPACE, strtolower($runId));
        $this->orchestrator->prepareAndEnqueue([
            'task_packet' => [
                'task_packet_id' => $taskPacketId,
                'objective' => 'terminal fleet resume rollup recovery-path probe',
                'operator_id' => 'multi-agent-loop-certification',
                'allowed_files' => [$allowedFile],
                'scope_in' => [$allowedFile],
                'acceptance_criteria' => ['fleet_resume_rollup_probe_ok'],
                'required_evidence' => ['fleet_resume_rollup_checked'],
                'risk_level' => 'low',
                'rollback_strategy' => 'dry_run_only',
            ],
            'queue' => ['priority' => 5, 'tags' => ['terminal_fleet_resume_rollup_probe', $queueTag]],
        ]);

        $claim = $this->orchestrator->claimNext('terminal_fleet_resume_rollup_probe_'.$runId, [
            'tag' => $queueTag,
            'ttl_seconds' => 600,
        ]);
        $leaseId = (string) ($claim['lease_id'] ?? '');
        if ($leaseId !== '') {
            Storage::disk('local')->delete(AgentControlPlaneClaimLeaseRepository::STORAGE_PREFIX.'/'.$leaseId.'.json');
        }

        $digest = (new AgentControlPlaneTerminalLoopHealthDigestService($this->queue, new AgentControlPlaneTaskLeaseRecoveryService))->digest([
            'actor' => 'terminal-fleet-resume-rollup-probe-'.$runId,
            'target_min_claimable_tasks' => 1,
            'max_new_tasks' => 1,
            'queue_tags' => [$queueTag],
        ]);

        $rollup = (array) data_get($digest, 'terminal_loop_fleet_resume_rollup', []);
        $handoff = (array) data_get($digest, 'terminal_loop_fleet_operator_handoff', []);
        $summary = (array) data_get($rollup, 'recoverable_task_summaries.0', []);
        $recoveryPathVerified = (string) data_get($rollup, 'status') === 'fleet_resume_rollup_recovery_required'
            && (int) data_get($rollup, 'recoverable_task_count') === 1
            && (bool) data_get($rollup, 'resume_attention_required') === true
            && (bool) data_get($rollup, 'can_recover_from_rollup') === false
            && (bool) data_get($rollup, 'can_claim_from_rollup') === false
            && (string) data_get($summary, 'task_packet_id') === $taskPacketId
            && (string) data_get($summary, 'safe_next_action') === 'recover_then_claim_fresh_lease'
            && str_contains((string) data_get($summary, 'recover_command'), '--agent-control-plane-task-lease-recovery-status')
            && str_contains((string) data_get($summary, 'recover_command'), '--packet='.$taskPacketId)
            && data_get($rollup, 'terminal_loop_fleet_resume_rollup_hash') !== null;
        $operatorHandoffRecoveryPriorityVerified = (string) data_get($handoff, 'status') === 'fleet_operator_handoff_recover_before_loop'
            && (string) data_get($handoff, 'next_operator_action') === 'recover_orphaned_or_expired_task_leases'
            && str_contains((string) data_get($handoff, 'primary_command'), '--packet='.$taskPacketId)
            && data_get($handoff, 'can_execute_from_handoff') === false
            && data_get($handoff, 'terminal_loop_fleet_operator_handoff_hash') !== null;

        return [
            'status' => $recoveryPathVerified ? 'available' : 'blocked',
            'queue_tag' => $queueTag,
            'task_packet_id' => $taskPacketId,
            'claim_event' => (string) ($claim['event'] ?? ''),
            'lease_id' => $leaseId,
            'lease_file_deleted_for_orphan_probe' => $leaseId !== '',
            'rollup_status' => (string) data_get($rollup, 'status'),
            'recoverable_task_count' => (int) data_get($rollup, 'recoverable_task_count'),
            'resume_attention_required' => (bool) data_get($rollup, 'resume_attention_required'),
            'recover_command_present' => str_contains((string) data_get($summary, 'recover_command'), '--agent-control-plane-task-lease-recovery-status'),
            'can_recover_from_rollup' => (bool) data_get($rollup, 'can_recover_from_rollup'),
            'can_claim_from_rollup' => (bool) data_get($rollup, 'can_claim_from_rollup'),
            'fleet_resume_rollup_hash' => (string) data_get($rollup, 'terminal_loop_fleet_resume_rollup_hash'),
            'recovery_path_verified' => $recoveryPathVerified,
            'operator_handoff_status' => (string) data_get($handoff, 'status'),
            'operator_handoff_next_action' => (string) data_get($handoff, 'next_operator_action'),
            'operator_handoff_recovery_priority_verified' => $operatorHandoffRecoveryPriorityVerified,
        ];
    }

function runTerminalFleetMetadataOrphanRecoveryProbe(string $runId): array
    {
        $queueTag = 'terminal_fleet_metadata_orphan_probe_'.$runId;
        $taskPacketId = 'fleet_metadata_orphan_probe_'.$runId;
        $packet = [
            'schema_version' => 'atlas.self_construction.agent_control_plane_task_packet.v1',
            'task_packet_id' => $taskPacketId,
            'task_packet_hash' => hash('sha256', $taskPacketId),
            'status' => 'claimed',
            'objective' => 'terminal fleet metadata-less orphan recovery probe',
            'allowed_files' => [self::SYNTHETIC_FILE_NAMESPACE.'/metadata_orphan.php'],
            'normalized_scope' => [
                'allowed_files' => [self::SYNTHETIC_FILE_NAMESPACE.'/metadata_orphan.php'],
                'forbidden_files' => [],
                'forbidden_in_allowed' => [],
                'forbidden_axis_hits' => [],
            ],
            'acceptance_criteria' => ['metadata_less_orphan_must_be_recoverable'],
            'required_tests' => ['php artisan test --filter=metadata_orphan_recovery_probe'],
        ];
        $this->queue->enqueue($packet, [
            'tags' => [$queueTag],
            'metadata' => ['agent_id' => 'terminal_fleet_metadata_orphan_probe_'.$runId],
        ]);

        $digest = (new AgentControlPlaneTerminalLoopHealthDigestService($this->queue, new AgentControlPlaneTaskLeaseRecoveryService($this->queue, $this->leases)))->digest([
            'actor' => 'terminal-fleet-metadata-orphan-probe-'.$runId,
            'target_min_claimable_tasks' => 1,
            'max_new_tasks' => 1,
            'queue_tags' => [$queueTag],
        ]);
        $rollup = (array) data_get($digest, 'terminal_loop_fleet_resume_rollup', []);
        $summary = (array) data_get($rollup, 'recoverable_task_summaries.0', []);

        $recovery = (new AgentControlPlaneTaskLeaseRecoveryService($this->queue, $this->leases))->recoverOrphanedClaims([
            'actor' => 'terminal-fleet-metadata-orphan-probe-'.$runId,
            'packet' => $taskPacketId,
            'reason' => 'metadata_less_orphan_probe',
        ]);
        $recordAfter = $this->queue->get($taskPacketId);
        $receiptKinds = array_column((array) data_get($recordAfter, 'receipts', []), 'receipt_kind');

        $digestDetected = (string) data_get($rollup, 'status') === 'fleet_resume_rollup_recovery_required'
            && (string) data_get($summary, 'task_packet_id') === $taskPacketId
            && (string) data_get($summary, 'lease_id') === ''
            && (string) data_get($summary, 'classification') === AgentControlPlaneTaskLeaseRecoveryService::RECOVERABILITY_RECOVERABLE_ORPHAN
            && str_contains((string) data_get($summary, 'recover_command'), '--packet='.$taskPacketId);
        $recoveryVerified = (string) data_get($recovery, 'event') === 'recover_orphaned_claims'
            && (int) data_get($recovery, 'claimed_record_count') === 1
            && (int) data_get($recovery, 'recovered_count') === 1
            && (string) data_get($recordAfter, 'status') === 'claimable'
            && in_array(AgentControlPlaneTaskLeaseRecoveryService::RECEIPT_TASK_LEASE_RECOVERY_EXECUTED, $receiptKinds, true);

        return [
            'status' => ($digestDetected && $recoveryVerified) ? 'available' : 'blocked',
            'queue_tag' => $queueTag,
            'task_packet_id' => $taskPacketId,
            'rollup_status' => (string) data_get($rollup, 'status'),
            'recoverable_task_count' => (int) data_get($rollup, 'recoverable_task_count'),
            'classification' => (string) data_get($summary, 'classification'),
            'recover_command_present' => str_contains((string) data_get($summary, 'recover_command'), '--agent-control-plane-task-lease-recovery-status'),
            'packet_scoped_recover_command' => str_contains((string) data_get($summary, 'recover_command'), '--packet='.$taskPacketId),
            'recovery_event' => (string) data_get($recovery, 'event'),
            'recovered_count' => (int) data_get($recovery, 'recovered_count'),
            'final_queue_status' => (string) data_get($recordAfter, 'status'),
            'metadata_orphan_digest_detected' => $digestDetected,
            'metadata_orphan_recovery_verified' => $recoveryVerified,
        ];
    }

function runTerminalFleetReleasedResumeProbe(string $runId): array
    {
        $queueTag = 'terminal_fleet_released_resume_probe_'.$runId;
        $taskPacketId = 'fleet_released_resume_probe_'.$runId;
        $allowedFile = sprintf('%s/fleet_released_resume_probe/%s.php', self::SYNTHETIC_FILE_NAMESPACE, strtolower($runId));
        $this->orchestrator->prepareAndEnqueue([
            'task_packet' => [
                'task_packet_id' => $taskPacketId,
                'objective' => 'terminal fleet released-task resume probe',
                'operator_id' => 'multi-agent-loop-certification',
                'allowed_files' => [$allowedFile],
                'scope_in' => [$allowedFile],
                'acceptance_criteria' => ['fleet_released_resume_probe_ok'],
                'required_evidence' => ['fleet_released_resume_checked'],
                'risk_level' => 'low',
                'rollback_strategy' => 'dry_run_only',
            ],
            'queue' => ['priority' => 5, 'tags' => ['terminal_fleet_released_resume_probe', $queueTag]],
        ]);

        $claim = $this->orchestrator->claimNext('terminal_fleet_released_resume_probe_'.$runId, [
            'tag' => $queueTag,
            'ttl_seconds' => 600,
        ]);
        $leaseId = (string) ($claim['lease_id'] ?? '');
        if ($leaseId !== '') {
            $this->orchestrator->releaseLease($leaseId, 'terminal_fleet_released_resume_probe_'.$runId, [
                'reason' => 'operator_interrupted_terminal',
            ]);
        }

        $digest = (new AgentControlPlaneTerminalLoopHealthDigestService($this->queue, new AgentControlPlaneTaskLeaseRecoveryService($this->queue, $this->leases)))->digest([
            'actor' => 'terminal-fleet-released-resume-probe-'.$runId,
            'target_min_claimable_tasks' => 1,
            'max_new_tasks' => 1,
            'queue_tags' => [$queueTag],
        ]);
        $rollup = (array) data_get($digest, 'terminal_loop_fleet_resume_rollup', []);
        $summary = (array) data_get($rollup, 'recoverable_task_summaries.0', []);

        $recovery = (new AgentControlPlaneTaskLeaseRecoveryService($this->queue, $this->leases))->recoverReleasedTasks([
            'actor' => 'terminal_fleet_released_resume_probe_'.$runId,
            'reason' => 'multi_agent_loop_released_resume_probe',
            'packet' => $taskPacketId,
        ]);
        $record = $this->queue->get($taskPacketId) ?? [];
        $receiptKinds = array_values(array_map(
            static fn (array $receipt): string => (string) ($receipt['receipt_kind'] ?? ''),
            (array) ($record['receipts'] ?? []),
        ));

        $digestRecoveryPathVerified = (string) data_get($rollup, 'status') === 'fleet_resume_rollup_recovery_required'
            && (int) data_get($rollup, 'recoverable_task_count') === 1
            && (string) data_get($summary, 'task_packet_id') === $taskPacketId
            && (string) data_get($summary, 'classification') === AgentControlPlaneTaskLeaseRecoveryService::RECOVERABILITY_RECOVERABLE_RELEASED
            && (string) data_get($summary, 'safe_next_action') === 'recover_then_claim_fresh_lease'
            && str_contains((string) data_get($summary, 'recover_command'), '--packet='.$taskPacketId)
            && (int) data_get($digest, 'lease_health.recoverable_released_task_count') === 1;
        $requeueVerified = $digestRecoveryPathVerified
            && (string) data_get($recovery, 'event') === 'recover_released_tasks'
            && (int) data_get($recovery, 'recovered_count') === 1
            && (string) data_get($record, 'status') === 'claimable'
            && in_array(AgentControlPlaneTaskLeaseRecoveryService::RECEIPT_RELEASED_TASK_REQUEUED, $receiptKinds, true);

        return [
            'status' => $requeueVerified ? 'available' : 'blocked',
            'queue_tag' => $queueTag,
            'task_packet_id' => $taskPacketId,
            'claim_event' => (string) ($claim['event'] ?? ''),
            'lease_id' => $leaseId,
            'release_reason' => 'operator_interrupted_terminal',
            'rollup_status' => (string) data_get($rollup, 'status'),
            'recoverable_task_count' => (int) data_get($rollup, 'recoverable_task_count'),
            'recoverable_released_task_count' => (int) data_get($digest, 'lease_health.recoverable_released_task_count'),
            'summary_classification' => (string) data_get($summary, 'classification'),
            'digest_recovery_path_verified' => $digestRecoveryPathVerified,
            'recovery_event' => (string) data_get($recovery, 'event'),
            'released_recovered_count' => (int) data_get($recovery, 'recovered_count'),
            'final_queue_status' => (string) data_get($record, 'status'),
            'receipt_written' => in_array(AgentControlPlaneTaskLeaseRecoveryService::RECEIPT_RELEASED_TASK_REQUEUED, $receiptKinds, true),
            'released_task_requeue_verified' => $requeueVerified,
        ];
    }

function runTerminalFleetEvidenceRollupProbe(string $runId): array
    {
        $queueTag = 'terminal_fleet_evidence_rollup_probe_'.$runId;
        $taskPacketId = 'fleet_evidence_rollup_probe_'.$runId;
        $allowedFile = sprintf('%s/fleet_evidence_rollup_probe/%s.php', self::SYNTHETIC_FILE_NAMESPACE, strtolower($runId));
        $orchestration = $this->orchestrator->prepareAndEnqueue([
            'task_packet' => [
                'task_packet_id' => $taskPacketId,
                'objective' => 'terminal fleet evidence rollup green-path probe',
                'operator_id' => 'multi-agent-loop-certification',
                'allowed_files' => [$allowedFile],
                'scope_in' => [$allowedFile],
                'acceptance_criteria' => ['fleet_evidence_rollup_probe_ok'],
                'required_evidence' => ['fleet_evidence_rollup_checked'],
                'risk_level' => 'low',
                'rollback_strategy' => 'dry_run_only',
            ],
            'queue' => ['priority' => 5, 'tags' => ['terminal_fleet_evidence_rollup_probe', $queueTag]],
        ]);

        $claim = $this->orchestrator->claimNext('terminal_fleet_evidence_rollup_probe_'.$runId, [
            'tag' => $queueTag,
            'ttl_seconds' => 600,
        ]);
        $completion = [];
        $evidenceHash = '';
        if ((string) ($claim['event'] ?? '') === 'claimed') {
            $completionEvidence = [
                'packet_id' => (string) ($claim['task_packet_id'] ?? ''),
                'lease_id' => (string) ($claim['lease_id'] ?? ''),
                'actor' => 'terminal_fleet_evidence_rollup_probe_'.$runId,
                'files_changed' => [$allowedFile],
                'commands_run' => ['php artisan test --filter=terminal_fleet_evidence_rollup_probe'],
                'tests_or_gates_result' => 'passed',
                'git_status_short' => 'M '.$allowedFile,
                'git_diff_check_result' => 'clean',
            ];
            $completionEvidence['evidence_hash'] = AgentControlPlaneTaskQueueOrchestrator::canonicalCompletionEvidenceHash($completionEvidence);
            $evidenceHash = (string) $completionEvidence['evidence_hash'];
            $completion = $this->orchestrator->completeDryRun(
                (string) ($claim['task_packet_id'] ?? ''),
                (string) ($claim['lease_id'] ?? ''),
                $completionEvidence,
            );
        }

        $digest = (new AgentControlPlaneTerminalLoopHealthDigestService($this->queue, new AgentControlPlaneTaskLeaseRecoveryService))->digest([
            'actor' => 'terminal-fleet-evidence-rollup-probe-'.$runId,
            'target_min_claimable_tasks' => 1,
            'max_new_tasks' => 1,
            'queue_tags' => [$queueTag],
        ]);

        $rollup = (array) data_get($digest, 'terminal_loop_fleet_evidence_rollup', []);
        $greenPathVerified = (string) data_get($rollup, 'status') === 'fleet_evidence_rollup_green'
            && (int) data_get($rollup, 'completed_dry_run_task_count') === 1
            && (int) data_get($rollup, 'valid_completion_evidence_count') === 1
            && (int) data_get($rollup, 'attention_required_count') === 0
            && (bool) data_get($rollup, 'ready_for_operator_review') === true
            && in_array($evidenceHash, (array) data_get($rollup, 'evidence_hashes', []), true)
            && data_get($rollup, 'terminal_loop_fleet_evidence_rollup_hash') !== null;
        $cycleSupervisorEvidenceReviewPathVerified = (string) data_get($digest, 'terminal_loop_cycle_supervisor.schema_version') === AgentControlPlaneTerminalLoopHealthDigestService::CYCLE_SUPERVISOR_SCHEMA_VERSION
            && (string) data_get($digest, 'terminal_loop_cycle_supervisor.status') === 'cycle_evidence_review_ready'
            && (string) data_get($digest, 'terminal_loop_cycle_supervisor.cycle_state') === 'review_evidence'
            && (string) data_get($digest, 'terminal_loop_cycle_supervisor.next_command_purpose') === 'review_completed_dry_run_evidence_and_rerun_digest'
            && data_get($digest, 'terminal_loop_cycle_supervisor.can_complete_from_supervisor') === false
            && data_get($digest, 'terminal_loop_cycle_supervisor.terminal_loop_cycle_supervisor_hash') !== null;

        return [
            'status' => $greenPathVerified ? 'available' : 'blocked',
            'queue_tag' => $queueTag,
            'task_packet_id' => $taskPacketId,
            'orchestration_event' => (string) ($orchestration['event'] ?? ''),
            'queue_entry_status' => (string) data_get($orchestration, 'queue_entry.status', ''),
            'claim_event' => (string) ($claim['event'] ?? ''),
            'completion_event' => (string) ($completion['event'] ?? ''),
            'rollup_status' => (string) data_get($rollup, 'status'),
            'completed_dry_run_task_count' => (int) data_get($rollup, 'completed_dry_run_task_count'),
            'valid_completion_evidence_count' => (int) data_get($rollup, 'valid_completion_evidence_count'),
            'attention_required_count' => (int) data_get($rollup, 'attention_required_count'),
            'ready_for_operator_review' => (bool) data_get($rollup, 'ready_for_operator_review'),
            'evidence_hash_present' => in_array($evidenceHash, (array) data_get($rollup, 'evidence_hashes', []), true),
            'fleet_evidence_rollup_hash' => (string) data_get($rollup, 'terminal_loop_fleet_evidence_rollup_hash'),
            'green_path_verified' => $greenPathVerified,
            'cycle_supervisor_status' => (string) data_get($digest, 'terminal_loop_cycle_supervisor.status'),
            'cycle_supervisor_cycle_state' => (string) data_get($digest, 'terminal_loop_cycle_supervisor.cycle_state'),
            'cycle_supervisor_evidence_review_path_verified' => $cycleSupervisorEvidenceReviewPathVerified,
            'cycle_supervisor_hash' => (string) data_get($digest, 'terminal_loop_cycle_supervisor.terminal_loop_cycle_supervisor_hash'),
        ];
    }

function runTerminalBootstrapPreviewProbe(AgentControlPlaneTerminalWorkerBootstrapService $bootstrap, string $runId): array
    {
        $tag = 'terminal_bootstrap_preview_'.$runId.'_tag';
        $queueTotalBefore = (int) data_get($this->queue->registry(), 'total_count', 0);
        $activeLeasesBefore = count($this->leases->activeLeases());

        $result = $bootstrap->bootstrap([], [
            'actor' => 'terminal_bootstrap_preview_'.$runId,
            'target_min_claimable_tasks' => 2,
            'max_new_tasks' => 2,
            'queue_tags' => [$tag],
            'reason' => 'multi_agent_loop_certification_preview_probe',
            'preview_only' => true,
        ]);

        $queueTotalAfter = (int) data_get($this->queue->registry(), 'total_count', 0);
        $activeLeasesAfter = count($this->leases->activeLeases());
        $executeCommand = (string) ($result['preview_execute_bootstrap_command'] ?? '');
        $readOnlyVerified = (bool) ($result['preview_only'] ?? false)
            && (string) ($result['auto_replenishment_status'] ?? '') === 'preview_only_not_run'
            && (string) ($result['claim_event'] ?? '') === 'preview_only_no_claim_attempted'
            && ! (bool) ($result['runtime_claim_persisted'] ?? true)
            && (int) ($result['generated_task_count'] ?? 1) === 0
            && $queueTotalAfter === $queueTotalBefore
            && $activeLeasesAfter === $activeLeasesBefore
            && str_contains($executeCommand, '--agent-control-plane-terminal-worker-bootstrap-status')
            && ! str_contains($executeCommand, '--terminal-worker-bootstrap-preview');

        return [
            'queue_tag' => $tag,
            'status' => (string) ($result['status'] ?? ''),
            'preview_only' => (bool) ($result['preview_only'] ?? false),
            'auto_replenishment_status' => (string) ($result['auto_replenishment_status'] ?? ''),
            'claim_event' => (string) ($result['claim_event'] ?? ''),
            'runtime_claim_persisted' => (bool) ($result['runtime_claim_persisted'] ?? true),
            'generated_task_count' => (int) ($result['generated_task_count'] ?? 0),
            'preview_claimable_count' => (int) ($result['preview_claimable_count'] ?? 0),
            'preview_would_replenish' => (bool) ($result['preview_would_replenish'] ?? false),
            'preview_would_generate_task_count' => (int) ($result['preview_would_generate_task_count'] ?? 0),
            'preview_execute_bootstrap_command' => $executeCommand,
            'queue_total_before' => $queueTotalBefore,
            'queue_total_after' => $queueTotalAfter,
            'active_leases_before' => $activeLeasesBefore,
            'active_leases_after' => $activeLeasesAfter,
            'read_only_verified' => $readOnlyVerified,
        ];
    }

function runTerminalBootstrapInvalidScopeProbe(AgentControlPlaneTerminalWorkerBootstrapService $bootstrap, string $runId): array
    {
        $packetId = 'terminal_bootstrap_invalid_scope_'.$runId;
        $tag = $packetId.'_tag';
        $packet = [
            'schema_version' => 'atlas.self_construction.agent_control_plane_task_packet.v1',
            'task_packet_id' => $packetId,
            'task_packet_hash' => hash('sha256', $packetId),
            'status' => 'planned',
            'objective' => 'Invalid terminal worker scope probe',
            'allowed_files' => [],
            'normalized_scope' => [
                'allowed_files' => [],
                'forbidden_files' => [],
                'forbidden_in_allowed' => [],
                'forbidden_axis_hits' => [],
            ],
            'acceptance_criteria' => ['invalid_scope_must_not_reach_worker'],
            'required_tests' => ['php artisan test --filter=invalid_scope_probe'],
        ];
        $this->queue->enqueue($packet, ['tags' => [$tag]]);

        $result = $bootstrap->bootstrap([], [
            'actor' => 'terminal_bootstrap_invalid_scope_'.$runId,
            'target_min_claimable_tasks' => 1,
            'max_new_tasks' => 0,
            'queue_tags' => [$tag],
            'reason' => 'multi_agent_loop_certification_invalid_scope_probe',
        ]);

        $queueStatus = (string) data_get($this->queue->get($packetId), 'status', '');
        $rejected = (string) ($result['status'] ?? '') === 'blocked'
            && (string) ($result['worker_packet_blocked_reason'] ?? '') === 'unsafe_worker_scope'
            && in_array('allowed_files_empty', (array) ($result['worker_packet_scope_blockers'] ?? []), true)
            && (bool) ($result['lease_released_after_worker_packet_blocked'] ?? false)
            && $queueStatus === 'released';

        return [
            'packet_id' => $packetId,
            'queue_tag' => $tag,
            'status' => (string) ($result['status'] ?? ''),
            'claim_event' => (string) ($result['claim_event'] ?? ''),
            'worker_packet_ready' => (bool) ($result['one_shot_worker_packet_ready'] ?? true),
            'worker_packet_blocked_reason' => (string) ($result['worker_packet_blocked_reason'] ?? ''),
            'worker_packet_scope_blockers' => (array) ($result['worker_packet_scope_blockers'] ?? []),
            'lease_released_after_worker_packet_blocked' => (bool) ($result['lease_released_after_worker_packet_blocked'] ?? false),
            'queue_status_after_rejection' => $queueStatus,
            'rejected' => $rejected,
        ];
    }

function terminalBootstrapContext(string $runId, int $probeAgentCount): array
    {
        return [
            'terminal_bootstrap_probe' => [
                'enabled' => true,
                'namespace' => 'terminal_bootstrap_probe_'.$runId,
                'target_task_count' => $probeAgentCount,
            ],
        ];
    }
}
