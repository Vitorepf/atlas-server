<?php

namespace App\Services\Ai\SelfConstruction\Readiness;

use App\Services\Ai\SelfConstruction\ControlPlane\AgentRuntimeEvidenceCertificationService;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneExecutionWorkspaceCertificationService;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneGovernanceApprovalCertificationService;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneAutomaticCostImportRuntimeCertificationService;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneAutomaticWorkProductCollectionCertificationService;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneAdapterExecutionRuntimeBoundaryCertificationService;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentDispatchPlannerCertificationService;
use App\Services\Ai\SelfConstruction\Support\AgentValidationGateCertificationService;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentMergeReviewCertificationService;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneTaskPacketBuilder;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneClaimLeaseSimulator;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneScopeLockPlanner;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneEvidenceLedgerDryRun;

/**
 * GOD-DEBULK extracted stateful agent-control-plane runtime status family (part 1) from AtlasSelfConstructionReadinessService (evidence journal, execution workspace, governance approval, automatic cost-import, automatic work-product, adapter execution boundary, dispatch planner, validation gate, merge review, task packet builder, claim lease simulator, scope lock planner, evidence ledger dry-run).
 * Bound via setMother(); undefined method calls bridge through __call and undefined
 * property reads bridge through __get (ReflectionMethod / ReflectionProperty on the mother)
 * so the moved bodies stay byte-identical to the god service originals.
 */
final class ReadinessProjectionAgentControlPlaneRuntimeStatusPart1Section
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
            throw new \RuntimeException('ReadinessProjectionAgentControlPlaneRuntimeStatusPart1Section mother not bound for '.$name);
        }
        $method = new \ReflectionMethod($this->mother, $name);

        return $method->invokeArgs($this->mother, $arguments);
    }

    public function __get(string $name): mixed
    {
        $property = new \ReflectionProperty($this->mother, $name);

        return $property->getValue($this->mother);
    }


    public function agentControlPlaneRuntimeEvidenceJournalStatus(array $options = []): array
    {
        $result = (new AgentRuntimeEvidenceCertificationService)->certify($options);

        return $this->wrapCertificationWorkbenchStatus(
            keyPrefix: 'runtime_evidence_journal',
            label: 'Runtime Evidence Journal',
            payload: $result,
            statusKey: 'status',
            extraStatusFields: [
                'certification_hash' => (string) data_get($result, 'certification_hash'),
                'invariants_all_true' => (bool) data_get($result, 'invariants_all_true', false),
                'violation_count' => (int) data_get($result, 'violation_count', 0),
                'journal_entry_count' => (int) data_get($result, 'journal_summary.entry_count', 0),
                'sample_receipt_hash' => (string) data_get($result, 'sample_receipt_hash'),
                'complete_continuity_index_hash' => (string) data_get($result, 'complete_continuity_index_hash'),
                'runtime_safety_all_false' => (bool) data_get($result, 'runtime_safety.runtime_safety_all_false', false),
            ],
        );
    }

    public function agentControlPlaneExecutionWorkspaceRuntimeStatus(array $options = []): array
    {
        $result = (new AgentControlPlaneExecutionWorkspaceCertificationService)->certify($options);

        return $this->wrapCertificationWorkbenchStatus(
            keyPrefix: 'execution_workspace_runtime',
            label: 'Execution Workspace Runtime',
            payload: $result,
            statusKey: 'status',
            extraStatusFields: [
                'certification_hash' => (string) data_get($result, 'certification_hash'),
                'invariants_all_true' => (bool) data_get($result, 'invariants_all_true', false),
                'violation_count' => (int) data_get($result, 'violation_count', 0),
                'workspace_plan_status' => (string) data_get($result, 'workspace_plan.status'),
                'diff_preview_status' => (string) data_get($result, 'diff_artifact_preview.status'),
                'rollback_plan_status' => (string) data_get($result, 'rollback_plan.status'),
                'runtime_safety_all_false' => (bool) data_get($result, 'runtime_safety.runtime_safety_all_false', false),
            ],
        );
    }

    public function agentControlPlaneGovernanceApprovalRuntimeStatus(array $options = []): array
    {
        $result = (new AgentControlPlaneGovernanceApprovalCertificationService)->certify($options);

        return $this->wrapCertificationWorkbenchStatus(
            keyPrefix: 'governance_approval_runtime',
            label: 'Governance Approval Runtime',
            payload: $result,
            statusKey: 'status',
            extraStatusFields: [
                'certification_hash' => (string) data_get($result, 'certification_hash'),
                'invariants_all_true' => (bool) data_get($result, 'invariants_all_true', false),
                'violation_count' => (int) data_get($result, 'violation_count', 0),
                'policy_blocked_status' => (string) data_get($result, 'policy_blocked_sample.status'),
                'policy_clear_status' => (string) data_get($result, 'policy_clear_sample.status'),
                'approval_receipt_plan_status' => (string) data_get($result, 'approval_receipt_plan.status'),
                'runtime_safety_all_false' => (bool) data_get($result, 'runtime_safety.runtime_safety_all_false', false),
            ],
        );
    }

    public function agentControlPlaneAutomaticCostImportRuntimeStatus(array $options = []): array
    {
        $result = (new AgentControlPlaneAutomaticCostImportRuntimeCertificationService)->certify($options);

        return $this->wrapCertificationWorkbenchStatus(
            keyPrefix: 'automatic_cost_import_runtime',
            label: 'Automatic Cost Import Runtime',
            payload: $result,
            statusKey: 'status',
            extraStatusFields: [
                'certification_hash' => (string) data_get($result, 'certification_hash'),
                'invariants_all_true' => (bool) data_get($result, 'invariants_all_true', false),
                'violation_count' => (int) data_get($result, 'violation_count', 0),
                'normalized_cost_events_hash' => (string) data_get($result, 'cost_event_normalization.normalized_cost_events_hash'),
                'cost_import_receipt_plan_hash' => (string) data_get($result, 'cost_import_receipt_plan.cost_import_receipt_plan_hash'),
                'reconciliation_dry_run_hash' => (string) data_get($result, 'cost_import_reconciliation_dry_run.reconciliation_dry_run_hash'),
                'runtime_safety_all_false' => (bool) data_get($result, 'runtime_safety.runtime_safety_all_false', false),
            ],
        );
    }

    public function agentControlPlaneAutomaticWorkProductCollectionRuntimeStatus(array $options = []): array
    {
        $result = (new AgentControlPlaneAutomaticWorkProductCollectionCertificationService)->certify($options);

        return $this->wrapCertificationWorkbenchStatus(
            keyPrefix: 'automatic_work_product_collection_runtime',
            label: 'Automatic Work Product Collection Runtime',
            payload: $result,
            statusKey: 'status',
            extraStatusFields: [
                'certification_hash' => (string) data_get($result, 'certification_hash'),
                'invariants_all_true' => (bool) data_get($result, 'invariants_all_true', false),
                'violation_count' => (int) data_get($result, 'violation_count', 0),
                'normalized_work_products_hash' => (string) data_get($result, 'work_product_normalization.normalized_work_products_hash'),
                'work_product_collection_receipt_plan_hash' => (string) data_get($result, 'work_product_collection_receipt_plan.work_product_collection_receipt_plan_hash'),
                'manifest_reconciliation_hash' => (string) data_get($result, 'work_product_manifest_reconciliation.manifest_reconciliation_hash'),
                'runtime_safety_all_false' => (bool) data_get($result, 'runtime_safety.runtime_safety_all_false', false),
            ],
        );
    }

    public function agentControlPlaneAdapterExecutionRuntimeBoundaryStatus(array $options = []): array
    {
        $result = (new AgentControlPlaneAdapterExecutionRuntimeBoundaryCertificationService)->certify($options);

        return $this->wrapCertificationWorkbenchStatus(
            keyPrefix: 'adapter_execution_runtime_boundary',
            label: 'Adapter Execution Runtime Boundary',
            payload: $result,
            statusKey: 'status',
            extraStatusFields: [
                'certification_hash' => (string) data_get($result, 'certification_hash'),
                'violation_count' => (int) data_get($result, 'violation_count', 0),
                'adapter_descriptor_hash' => (string) data_get($result, 'adapter_descriptor_hash'),
                'execution_envelope_hash' => (string) data_get($result, 'execution_envelope_dry_run.execution_envelope_hash'),
                'guardrail_matrix_hash' => (string) data_get($result, 'guardrail_matrix_hash'),
                'failure_taxonomy_hash' => (string) data_get($result, 'failure_taxonomy_hash'),
                'runtime_safety_all_false' => (bool) data_get($result, 'runtime_safety.runtime_safety_all_false', false),
            ],
        );
    }

    public function agentControlPlaneDispatchPlannerRuntimeStatus(array $options = []): array
    {
        $result = (new AgentDispatchPlannerCertificationService)->certify($options);

        return $this->wrapCertificationWorkbenchStatus(
            keyPrefix: 'dispatch_planner_runtime',
            label: 'Dispatch Planner Runtime',
            payload: $result,
            statusKey: 'status',
            extraStatusFields: [
                'certification_hash' => (string) data_get($result, 'certification_hash'),
                'invariants_all_true' => (bool) data_get($result, 'invariants_all_true', false),
                'violation_count' => (int) data_get($result, 'violation_count', 0),
                'warning_count' => (int) data_get($result, 'warning_count', 0),
                'runtime_safety_all_false' => (bool) data_get($result, 'runtime_safety.runtime_safety_all_false', false),
                'dispatch_allowed' => (bool) data_get($result, 'runtime_safety.dispatch_allowed', false),
                'claim_real_allowed' => (bool) data_get($result, 'runtime_safety.claim_real_allowed', false),
            ],
        );
    }

    public function agentControlPlaneValidationGateRuntimeStatus(array $options = []): array
    {
        $context = (array) ($options['validation_context'] ?? []);
        $inputs = (array) ($options['synthetic_inputs'] ?? $this->agentControlPlaneValidationGateRuntimeSyntheticPassInputs($context));
        $result = (new AgentValidationGateCertificationService)->certify($context, $inputs);

        return $this->wrapCertificationWorkbenchStatus(
            keyPrefix: 'validation_gate_runtime',
            label: 'Validation Gate Runtime',
            payload: $result,
            statusKey: 'status',
            extraStatusFields: [
                'certification_hash' => (string) data_get($result, 'certification_hash'),
                'invariants_all_true' => (bool) data_get($result, 'invariants_all_true', false),
                'violation_count' => (int) data_get($result, 'violation_count', 0),
                'overall_evaluation' => (string) data_get($result, 'summary.overall_evaluation'),
                'failure_count' => (int) data_get($result, 'summary.failure_count', 0),
                'human_required_count' => (int) data_get($result, 'summary.human_required_count', 0),
                'runtime_safety_all_false' => (bool) data_get($result, 'runtime_safety.runtime_safety_all_false', false),
            ],
        );
    }

    public function agentControlPlaneMergeReviewRuntimeStatus(array $options = []): array
    {
        [$diffManifest, $artifactManifest, $declaredScope, $context] = $this->agentControlPlaneMergeReviewRuntimeCleanArgs();
        $result = (new AgentMergeReviewCertificationService)->certify($diffManifest, $artifactManifest, $declaredScope, $context, $options);

        return $this->wrapCertificationWorkbenchStatus(
            keyPrefix: 'merge_review_runtime',
            label: 'Merge Review Runtime',
            payload: $result,
            statusKey: 'status',
            extraStatusFields: [
                'certification_hash' => (string) data_get($result, 'certification_hash'),
                'invariants_all_true' => (bool) data_get($result, 'invariants_all_true', false),
                'violation_count' => (int) data_get($result, 'violation_count', 0),
                'promotion_allowed' => (bool) data_get($result, 'promotion_allowed', true),
                'completion_claim_allowed' => (bool) data_get($result, 'completion_claim_allowed', true),
                'approval_plan_status' => (string) data_get($result, 'inputs.approval_plan.status'),
                'promotion_dry_run_status' => (string) data_get($result, 'inputs.promotion_dry_run.status'),
                'runtime_safety_all_false' => (bool) data_get($result, 'runtime_safety.runtime_safety_all_false', false),
            ],
        );
    }

    public function agentControlPlaneTaskPacketBuilderStatus(array $options = []): array
    {
        $svc = new AgentControlPlaneTaskPacketBuilder;
        $input = (array) ($options['input'] ?? $this->defaultRuntimePilotInput());
        $result = $svc->build($input);

        return $this->wrapCertificationWorkbenchStatus(
            keyPrefix: 'task_packet_builder',
            label: 'Task Packet Builder',
            payload: $result,
            statusKey: 'status',
            extraStatusFields: [
                'task_packet_id' => (string) data_get($result, 'task_packet_id'),
                'task_packet_hash' => (string) data_get($result, 'task_packet_hash'),
                'scope_hash' => (string) data_get($result, 'scope_hash'),
                'acceptance_hash' => (string) data_get($result, 'acceptance_hash'),
                'allowed_file_count' => count((array) data_get($result, 'normalized_scope.allowed_files', [])),
                'blocking_count' => count((array) data_get($result, 'blocking_reasons', [])),
            ],
        );
    }

    public function agentControlPlaneClaimLeaseSimulatorStatus(array $options = []): array
    {
        $builder = new AgentControlPlaneTaskPacketBuilder;
        $packet = $builder->build((array) ($options['input'] ?? $this->defaultRuntimePilotInput()));
        $svc = new AgentControlPlaneClaimLeaseSimulator;
        $result = $svc->simulate($packet, (array) ($options['simulator_options'] ?? []));

        return $this->wrapCertificationWorkbenchStatus(
            keyPrefix: 'claim_lease_simulator',
            label: 'Claim/Lease Simulator',
            payload: $result,
            statusKey: 'lease_status',
            extraStatusFields: [
                'lease_status' => (string) data_get($result, 'lease_status'),
                'task_packet_id' => (string) data_get($result, 'task_packet_id'),
                'claim_id' => (string) data_get($result, 'claim_id'),
                'lease_id' => (string) data_get($result, 'lease_id'),
                'claim_hash' => (string) data_get($result, 'claim_hash'),
                'lease_hash' => (string) data_get($result, 'lease_hash'),
                'simulation_hash' => (string) data_get($result, 'simulation_hash'),
                'conflict_count' => (int) data_get($result, 'conflict_count'),
            ],
        );
    }

    public function agentControlPlaneScopeLockPlannerStatus(array $options = []): array
    {
        $builder = new AgentControlPlaneTaskPacketBuilder;
        $packet = $builder->build((array) ($options['input'] ?? $this->defaultRuntimePilotInput()));
        $claimLease = (new AgentControlPlaneClaimLeaseSimulator)->simulate($packet);
        $svc = new AgentControlPlaneScopeLockPlanner;
        $result = $svc->plan($packet, $claimLease, (array) ($options['planner_options'] ?? []));

        return $this->wrapCertificationWorkbenchStatus(
            keyPrefix: 'scope_lock_planner',
            label: 'Scope Lock Planner',
            payload: $result,
            statusKey: 'status',
            extraStatusFields: [
                'scope_lock_plan_id' => (string) data_get($result, 'scope_lock_plan_id'),
                'scope_lock_plan_hash' => (string) data_get($result, 'scope_lock_plan_hash'),
                'write_set_count' => count((array) data_get($result, 'write_set', [])),
                'read_set_count' => count((array) data_get($result, 'read_set', [])),
                'cross_axis_blocker_count' => count((array) data_get($result, 'cross_axis_blockers', [])),
                'unsafe_path_blocker_count' => count((array) data_get($result, 'unsafe_path_blockers', [])),
            ],
        );
    }

    public function agentControlPlaneEvidenceLedgerDryRunStatus(array $options = []): array
    {
        $builder = new AgentControlPlaneTaskPacketBuilder;
        $packet = $builder->build((array) ($options['input'] ?? $this->defaultRuntimePilotInput()));
        $claimLease = (new AgentControlPlaneClaimLeaseSimulator)->simulate($packet);
        $scopeLock = (new AgentControlPlaneScopeLockPlanner)->plan($packet, $claimLease);
        $svc = new AgentControlPlaneEvidenceLedgerDryRun;
        $result = $svc->plan($packet, $scopeLock, (array) ($options['ledger_options'] ?? []));

        return $this->wrapCertificationWorkbenchStatus(
            keyPrefix: 'evidence_ledger_dry_run',
            label: 'Evidence Ledger Dry-Run',
            payload: $result,
            statusKey: 'status',
            extraStatusFields: [
                'evidence_plan_id' => (string) data_get($result, 'evidence_plan_id'),
                'evidence_hash' => (string) data_get($result, 'evidence_hash'),
                'evidence_plan_hash' => (string) data_get($result, 'evidence_plan_hash'),
                'receipt_count' => (int) data_get($result, 'receipt_count'),
                'event_count' => (int) data_get($result, 'event_count'),
            ],
        );
    }

}
