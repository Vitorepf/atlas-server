<?php

namespace App\Services\Ai\SelfConstruction\Readiness;

use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneTaskPacketBuilder;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneClaimLeaseSimulator;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneScopeLockPlanner;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneEvidenceLedgerDryRun;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneContinuationSummaryBuilder;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneWorkProductManifestPlanner;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneCostImportDryRun;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneMultiAgentParallelismPlanner;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneRuntimePilotCertificationService;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneTaskPacketQueueRepository;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneScopeLockRuntimeValidator;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneWorkerTaskEligibilityCertificationService;
use App\Services\Ai\SelfConstruction\NativeImplementation\AtlasSelfConstructionRuntimeGapMatrixAuditService;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneTaskQueueLeaseCertificationService;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneClaimLeaseRepository;
/**
 * GOD-DEBULK extracted stateful agent-control-plane runtime status family (part 2) from AtlasSelfConstructionReadinessService (continuation summary, work-product manifest planner, cost-import dry-run, multi-agent parallelism planner, runtime pilot orchestrator+certification, task packet queue, scope-lock runtime validator, task queue orchestrator, worker task eligibility, OS runtime gap-matrix audit, task-queue lease certification).
 * Bound via setMother(); undefined method calls bridge through __call and undefined
 * property reads bridge through __get (ReflectionMethod / ReflectionProperty on the mother)
 * so the moved bodies stay byte-identical to the god service originals.
 */
final class ReadinessProjectionAgentControlPlaneRuntimeStatusPart2Section
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
            throw new \RuntimeException('ReadinessProjectionAgentControlPlaneRuntimeStatusPart2Section mother not bound for '.$name);
        }
        $method = new \ReflectionMethod($this->mother, $name);

        return $method->invokeArgs($this->mother, $arguments);
    }

    public function __get(string $name): mixed
    {
        $property = new \ReflectionProperty($this->mother, $name);

        return $property->getValue($this->mother);
    }


    public function agentControlPlaneContinuationSummaryBuilderStatus(array $options = []): array
    {
        $builder = new AgentControlPlaneTaskPacketBuilder;
        $packet = $builder->build((array) ($options['input'] ?? $this->defaultRuntimePilotInput()));
        $claimLease = (new AgentControlPlaneClaimLeaseSimulator)->simulate($packet);
        $scopeLock = (new AgentControlPlaneScopeLockPlanner)->plan($packet, $claimLease);
        $evidence = (new AgentControlPlaneEvidenceLedgerDryRun)->plan($packet, $scopeLock);
        $svc = new AgentControlPlaneContinuationSummaryBuilder;
        $result = $svc->build($packet, $evidence, (array) ($options['continuation_options'] ?? []));

        return $this->wrapCertificationWorkbenchStatus(
            keyPrefix: 'continuation_summary_builder',
            label: 'Continuation Summary Builder',
            payload: $result,
            statusKey: 'status',
            extraStatusFields: [
                'continuation_summary_id' => (string) data_get($result, 'continuation_summary_id'),
                'continuation_hash' => (string) data_get($result, 'continuation_hash'),
                'next_action_count' => count((array) data_get($result, 'next_actions', [])),
                'blocker_count' => count((array) data_get($result, 'blockers', [])),
            ],
        );
    }

    public function agentControlPlaneWorkProductManifestPlannerStatus(array $options = []): array
    {
        $builder = new AgentControlPlaneTaskPacketBuilder;
        $packet = $builder->build((array) ($options['input'] ?? $this->defaultRuntimePilotInput()));
        $claimLease = (new AgentControlPlaneClaimLeaseSimulator)->simulate($packet);
        $scopeLock = (new AgentControlPlaneScopeLockPlanner)->plan($packet, $claimLease);
        $svc = new AgentControlPlaneWorkProductManifestPlanner;
        $result = $svc->plan($packet, $scopeLock, (array) ($options['manifest_options'] ?? []));

        return $this->wrapCertificationWorkbenchStatus(
            keyPrefix: 'work_product_manifest_planner',
            label: 'Work Product Manifest Planner',
            payload: $result,
            statusKey: 'status',
            extraStatusFields: [
                'manifest_plan_id' => (string) data_get($result, 'manifest_plan_id'),
                'work_product_manifest_hash' => (string) data_get($result, 'work_product_manifest_hash'),
                'expected_output_count' => (int) data_get($result, 'expected_output_count'),
            ],
        );
    }

    public function agentControlPlaneCostImportDryRunStatus(array $options = []): array
    {
        $builder = new AgentControlPlaneTaskPacketBuilder;
        $packet = $builder->build((array) ($options['input'] ?? $this->defaultRuntimePilotInput()));
        $svc = new AgentControlPlaneCostImportDryRun;
        $result = $svc->plan($packet, (array) ($options['cost_options'] ?? []));

        return $this->wrapCertificationWorkbenchStatus(
            keyPrefix: 'cost_import_dry_run',
            label: 'Cost Import Dry-Run',
            payload: $result,
            statusKey: 'status',
            extraStatusFields: [
                'cost_import_plan_id' => (string) data_get($result, 'cost_import_plan_id'),
                'cost_import_plan_hash' => (string) data_get($result, 'cost_import_plan_hash'),
                'token_budget' => (int) data_get($result, 'token_budget'),
                'import_source_count' => (int) data_get($result, 'import_source_count'),
            ],
        );
    }

    public function agentControlPlaneMultiAgentParallelismPlannerStatus(array $options = []): array
    {
        $builder = new AgentControlPlaneTaskPacketBuilder;
        $packets = [];
        $inputs = (array) ($options['inputs'] ?? [$this->defaultRuntimePilotInput(), $this->defaultRuntimePilotInputSecondary()]);
        foreach ($inputs as $input) {
            $packets[] = $builder->build((array) $input);
        }
        $svc = new AgentControlPlaneMultiAgentParallelismPlanner;
        $result = $svc->plan($packets, (array) ($options['parallelism_options'] ?? []));

        return $this->wrapCertificationWorkbenchStatus(
            keyPrefix: 'multi_agent_parallelism_planner',
            label: 'Multi-Agent Parallelism Planner',
            payload: $result,
            statusKey: 'status',
            extraStatusFields: [
                'parallelism_plan_id' => (string) data_get($result, 'parallelism_plan_id'),
                'parallelism_hash' => (string) data_get($result, 'parallelism_hash'),
                'agent_count' => (int) data_get($result, 'agent_count'),
                'blocked_pair_count' => (int) data_get($result, 'blocked_pair_count'),
                'parallelism_allowed' => (bool) data_get($result, 'parallelism_allowed'),
            ],
        );
    }

    public function agentControlPlaneRuntimePilotOrchestratorStatus(array $options = []): array
    {
        $svc = $this->buildRuntimePilotOrchestrator();
        $input = (array) ($options['input'] ?? ['task_packet' => $this->defaultRuntimePilotInput(), 'additional_task_packets' => [$this->defaultRuntimePilotInputSecondary()]]);
        $result = $svc->run($input);

        return $this->wrapCertificationWorkbenchStatus(
            keyPrefix: 'runtime_pilot_orchestrator',
            label: 'Runtime Pilot Orchestrator',
            payload: $result,
            statusKey: 'status',
            extraStatusFields: [
                'pilot_id' => (string) data_get($result, 'pilot_id'),
                'pilot_hash' => (string) data_get($result, 'pilot_hash'),
                'blocker_count' => (int) data_get($result, 'blocker_count'),
                'task_packet_hash' => (string) data_get($result, 'task_packet.task_packet_hash'),
                'continuation_hash' => (string) data_get($result, 'continuation_summary.continuation_hash'),
                'evidence_hash' => (string) data_get($result, 'evidence_ledger_dry_run.evidence_hash'),
                'cost_import_plan_hash' => (string) data_get($result, 'cost_import_dry_run.cost_import_plan_hash'),
                'work_product_manifest_hash' => (string) data_get($result, 'work_product_manifest_plan.work_product_manifest_hash'),
                'parallelism_hash' => (string) data_get($result, 'multi_agent_parallelism_plan.parallelism_hash'),
            ],
        );
    }

    public function agentControlPlaneRuntimePilotCertificationStatus(array $options = []): array
    {
        $orchestrator = $this->buildRuntimePilotOrchestrator();
        $pilot = $orchestrator->run((array) ($options['input'] ?? ['task_packet' => $this->defaultRuntimePilotInput(), 'additional_task_packets' => [$this->defaultRuntimePilotInputSecondary()]]));
        $svc = new AgentControlPlaneRuntimePilotCertificationService;
        $result = $svc->certify($pilot, (array) ($options['certification_options'] ?? []));

        return $this->wrapCertificationWorkbenchStatus(
            keyPrefix: 'runtime_pilot_certification',
            label: 'Runtime Pilot Certification',
            payload: $result,
            statusKey: 'status',
            extraStatusFields: [
                'certification_id' => (string) data_get($result, 'certification_id'),
                'certification_hash' => (string) data_get($result, 'certification_hash'),
                'pilot_hash' => (string) data_get($result, 'pilot_hash'),
                'passed_count' => (int) data_get($result, 'passed_count'),
                'failed_count' => (int) data_get($result, 'failed_count'),
                'check_count' => (int) data_get($result, 'check_count'),
            ],
        );
    }

    public function agentControlPlaneTaskPacketQueueStatus(array $options = []): array
    {
        $repo = new AgentControlPlaneTaskPacketQueueRepository;
        $registry = $repo->registry();
        $status = (bool) $registry['corrupt'] ? 'blocked' : 'available';

        return $this->wrapCertificationWorkbenchStatus(
            keyPrefix: 'task_packet_queue',
            label: 'Task Packet Queue',
            payload: array_merge($registry, ['status' => $status]),
            statusKey: 'status',
            extraStatusFields: [
                'storage_prefix' => (string) $registry['storage_prefix'],
                'entry_count' => (int) $registry['entry_count'],
                'total_count' => (int) $registry['total_count'],
                'status_counts' => (array) $registry['status_counts'],
                'corrupt' => (bool) $registry['corrupt'],
                'status_transition_policy_hash' => (string) $registry['status_transition_policy_hash'],
                'claim_transition_requires_lease_id' => (bool) $registry['claim_transition_requires_lease_id'],
                'claim_transition_requires_agent_id' => (bool) $registry['claim_transition_requires_agent_id'],
                'queue_available' => $repo->isAvailable(),
            ],
        );
    }

    public function agentControlPlaneScopeLockRuntimeValidatorStatus(array $options = []): array
    {
        $builder = new AgentControlPlaneTaskPacketBuilder;
        $packet = $builder->build((array) ($options['input'] ?? $this->defaultRuntimePilotInput()));
        $validator = new AgentControlPlaneScopeLockRuntimeValidator;
        $result = $validator->validate($packet, (array) ($options['validator_options'] ?? []));

        return $this->wrapCertificationWorkbenchStatus(
            keyPrefix: 'scope_lock_runtime_validator',
            label: 'Scope Lock Runtime Validator',
            payload: $result,
            statusKey: 'status',
            extraStatusFields: [
                'task_packet_id' => (string) data_get($result, 'task_packet_id'),
                'scope_lock_hash' => (string) data_get($result, 'scope_lock_hash'),
                'validation_hash' => (string) data_get($result, 'validation_hash'),
                'blocker_count' => count((array) data_get($result, 'blockers', [])),
                'forbidden_axis_count' => (int) data_get($result, 'forbidden_axis_count'),
                'traversal_count' => (int) data_get($result, 'traversal_count'),
            ],
        );
    }

    public function agentControlPlaneTaskQueueOrchestratorStatus(array $options = []): array
    {
        $orchestrator = $this->buildTaskQueueOrchestrator();
        $input = (array) ($options['input'] ?? ['task_packet' => $this->defaultRuntimePilotInput()]);
        $result = $orchestrator->prepareAndEnqueue($input);
        $event = (string) ($result['event'] ?? 'unknown');
        $status = $event === 'prepared_and_enqueued' ? 'available' : 'blocked';

        return $this->wrapCertificationWorkbenchStatus(
            keyPrefix: 'task_queue_orchestrator',
            label: 'Task Queue Orchestrator',
            payload: array_merge($result, ['status' => $status]),
            statusKey: 'status',
            extraStatusFields: [
                'event' => $event,
                'orchestration_id' => (string) data_get($result, 'orchestration_id'),
                'task_packet_id' => (string) data_get($result, 'task_packet.task_packet_id'),
                'task_packet_hash' => (string) data_get($result, 'task_packet.task_packet_hash'),
                'scope_lock_hash' => (string) data_get($result, 'validation.scope_lock_hash'),
                'queue_event' => (string) data_get($result, 'queue_entry.event'),
                'continuation_hash' => (string) data_get($result, 'continuation_summary.continuation_hash'),
            ],
            runtimeWritePerformed: $event === 'prepared_and_enqueued',
        );
    }

    public function agentControlPlaneWorkerTaskEligibilityCertificationStatus(array $options = []): array
    {
        $result = (new AgentControlPlaneWorkerTaskEligibilityCertificationService(
            $this->mother,
            new AgentControlPlaneTaskPacketQueueRepository,
        ))->certify($options);

        return $this->wrapCertificationWorkbenchStatus(
            keyPrefix: 'worker_task_eligibility_certification',
            label: 'Worker Task Eligibility Certification',
            payload: $result,
            statusKey: 'status',
            extraStatusFields: [
                'certification_hash' => (string) data_get($result, 'certification_hash', ''),
                'checks_all_true' => (bool) data_get($result, 'checks_all_true', false),
                'violation_count' => (int) data_get($result, 'violation_count', 0),
                'failed_check_ids' => (array) data_get($result, 'failed_check_ids', []),
                'worker_candidate_statuses' => (array) data_get($result, 'worker_candidate_statuses', []),
                'claimable_task_count' => (int) data_get($result, 'claimable_task_count', 0),
                'active_worker_task_count' => (int) data_get($result, 'active_worker_task_count', 0),
                'active_worker_tasks' => (array) data_get($result, 'active_worker_tasks', []),
                'operator_handoff_seed_count' => (int) data_get($result, 'operator_handoff_seed_count', 0),
                'operator_only_failed_criteria' => (array) data_get($result, 'operator_only_failed_criteria', []),
                'missing_operator_handoff_criteria' => (array) data_get($result, 'missing_operator_handoff_criteria', []),
                'completion_audit_context_status' => (string) data_get($result, 'completion_audit_context_status', ''),
            ],
        );
    }

    public function atlasSelfConstructionOsRuntimeGapMatrixAuditStatus(array $options = []): array
    {
        $liveStatusProjection = $options === [];
        $service = new AtlasSelfConstructionRuntimeGapMatrixAuditService($this->mother);
        $result = $service->audit($options);
        $currentRequiredOperatorArtifact = (string) data_get($result, 'current_required_operator_artifact', '');
        $nextRequiredCommand = (string) data_get($result, 'operator_next_action_command', '');
        $nextRequiredPersistCommand = (string) data_get($result, 'operator_next_action_persist_command', '');
        if ($liveStatusProjection) {
            $completionEvidence = $this->atlasSelfConstructionOsCompletionEvidenceStatus($options);
            $currentRequiredOperatorArtifact = (string) data_get($completionEvidence, 'current_required_operator_artifact', $currentRequiredOperatorArtifact);
            $nextRequiredCommand = (string) data_get($completionEvidence, 'next_required_command', $nextRequiredCommand);
            $nextRequiredPersistCommand = (string) data_get($completionEvidence, 'next_required_persist_command', $nextRequiredPersistCommand);
        }

        return $this->wrapCertificationWorkbenchStatus(
            keyPrefix: 'runtime_gap_matrix_audit',
            label: 'Runtime Gap Matrix Audit',
            payload: $result,
            statusKey: 'status',
            extraStatusFields: [
                'gap_count' => (int) data_get($result, 'gap_count'),
                'still_open_count' => (int) data_get($result, 'still_open_count'),
                'auto_closeable_locally_count' => (int) data_get($result, 'auto_closeable_locally_count'),
                'all_runtime_y' => (bool) data_get($result, 'all_runtime_y', false),
                'runtime_gap_count' => (int) data_get($result, 'runtime_gap_count', 0),
                'runtime_y_count' => (int) data_get($result, 'runtime_y_count', 0),
                'runtime_y_candidate_count' => (int) data_get($result, 'runtime_y_candidate_count', 0),
                'blocked_gap_ids' => (array) data_get($result, 'blocked_gap_ids', []),
                'graduation_candidate_gap_ids' => (array) data_get($result, 'graduation_candidate_gap_ids', []),
                'human_signed_os_complete_receipt_present' => (bool) data_get($result, 'human_signed_os_complete_receipt_present', false),
                'real_provider_smoke_green' => (bool) data_get($result, 'real_provider_smoke_green', false),
                'os_complete_promotion_allowed' => (bool) data_get($result, 'os_complete_promotion_allowed', false),
                'runtime_gap_matrix_audit_hash' => (string) data_get($result, 'runtime_gap_matrix_audit_hash'),
                'runtime_gap_matrix_hash' => (string) data_get($result, 'runtime_gap_matrix_hash'),
                'expected_runtime_gap_matrix_hash_for_promotion_receipt' => (string) data_get($result, 'expected_runtime_gap_matrix_hash_for_promotion_receipt', ''),
                'runtime_promotion_basis_hash' => (string) data_get($result, 'runtime_promotion_basis_hash', ''),
                'runtime_promotion_closure_basis_hash' => (string) data_get($result, 'runtime_promotion_closure_basis_hash', ''),
                'current_required_operator_artifact' => $currentRequiredOperatorArtifact,
                'operator_next_action_command' => (string) data_get($result, 'operator_next_action_command', ''),
                'operator_next_action_persist_command' => (string) data_get($result, 'operator_next_action_persist_command', ''),
                'next_required_command' => $nextRequiredCommand,
                'next_required_persist_command' => $nextRequiredPersistCommand,
                'completion_allowed' => false,
                'completion_claim_allowed' => false,
                'self_programming_allowed' => false,
                'terminal_loop_operational_proof_required_before_completion_claim' => true,
                'completion_audit_without_terminal_loop_operational_proof_is_diagnostic_only' => true,
                'implementation_packet_command_surface_status' => (string) data_get($result, 'implementation_packet_command_surface.status', ''),
                'implementation_packet_command_surface_hash' => (string) data_get($result, 'implementation_packet_command_surface.command_surface_hash', ''),
                'implementation_packet_command_count' => (int) data_get($result, 'implementation_packet_command_surface.command_count', 0),
                'implementation_packet_command_missing_option_count' => (int) data_get($result, 'implementation_packet_command_surface.missing_option_count', 0),
                'implementation_packet_command_legacy_alias_count' => (int) data_get($result, 'implementation_packet_command_surface.legacy_alias_count', 0),
            ],
        );
    }

    public function agentControlPlaneTaskQueueLeaseCertificationStatus(array $options = []): array
    {
        $svc = new AgentControlPlaneTaskQueueLeaseCertificationService(
            new AgentControlPlaneTaskPacketBuilder,
            new AgentControlPlaneScopeLockRuntimeValidator,
            new AgentControlPlaneTaskPacketQueueRepository,
            new AgentControlPlaneClaimLeaseRepository,
        );
        $result = $svc->certify($options);

        return $this->wrapCertificationWorkbenchStatus(
            keyPrefix: 'task_queue_lease_certification',
            label: 'Task Queue + Lease Certification',
            payload: $result,
            statusKey: 'status',
            extraStatusFields: [
                'certification_id' => (string) data_get($result, 'certification_id'),
                'certification_hash' => (string) data_get($result, 'certification_hash'),
                'invariants_all_true' => (bool) data_get($result, 'invariants_all_true'),
                'violation_count' => (int) data_get($result, 'violation_count'),
                'warning_count' => (int) data_get($result, 'warning_count'),
                'probe_count' => (int) data_get($result, 'probe_evidence.probe_count'),
            ],
        );
    }

}
