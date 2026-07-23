<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Readiness\Catalog;

use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskQueueOrchestrator;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneAdapterExecutionRuntimeBoundaryCertificationService;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneAutomaticCostImportRuntimeCertificationService;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneAutomaticWorkProductCollectionCertificationService;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneCertificationBaselineService;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneCertificationCoverageReportService;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneCertificationEvidenceQueryService;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneCertificationFuzzHarness;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneCertificationMutationGuard;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneCertificationScenarioCorpusService;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneCertificationScenarioSimulator;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneCertificationStatusBatchService;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneClaimLeaseRepository;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneClaimLeaseSimulator;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneContinuationSummaryBuilder;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneCostImportDryRun;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneEvidenceLedgerDryRun;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneExecutionWorkspaceCertificationService;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneGovernanceApprovalCertificationService;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneMultiAgentLoopCertificationService;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneMultiAgentParallelismPlanner;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneMultiSnapshotComparisonService;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneOneShotWorkerPacketService;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneReleaseDossierExporter;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneReleaseDossierService;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneRuntimePilotCertificationService;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneRuntimePilotOrchestrator;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneScopeLockPlanner;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneScopeLockRuntimeValidator;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneTaskAutoReplenishmentService;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneTaskLeaseRecoveryService;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneTaskPacketBuilder;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneTaskPacketQueueRepository;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneTaskQueueLeaseCertificationService;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneTerminalLoopHealthDigestService;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneTerminalLoopOperationalProofService;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneTerminalWorkerBootstrapService;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneWorkProductManifestPlanner;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneWorkerTaskEligibilityCertificationService;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentDispatchPlannerCertificationService;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentMergeReviewCertificationService;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentRuntimeEvidenceCertificationService;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentRuntimeRegistryAvailabilityPlanner;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentRuntimeRegistryCapabilityCatalog;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentRuntimeRegistryCertificationService;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentRuntimeRegistryHandoffProtocolBuilder;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentRuntimeRegistryHeartbeatRepository;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentRuntimeRegistryLoadBalancingPolicy;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentRuntimeRegistryOrchestrator;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentRuntimeRegistryQuarantineRepository;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentRuntimeRegistryRepository;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentRuntimeRegistryTaskMatcher;
use App\Services\Ai\SelfConstruction\NativeImplementation\AtlasSelfConstructionCompletionAuditBlockerExplainerService;
use App\Services\Ai\SelfConstruction\NativeImplementation\AtlasSelfConstructionCompletionEvidenceHashComposerService;
use App\Services\Ai\SelfConstruction\NativeImplementation\AtlasSelfConstructionCompletionEvidenceSubmissionPreflightService;
use App\Services\Ai\SelfConstruction\NativeImplementation\AtlasSelfConstructionCompletionFinalizationGateService;
use App\Services\Ai\SelfConstruction\NativeImplementation\AtlasSelfConstructionCompletionOperatorActionPacketService;
use App\Services\Ai\SelfConstruction\NativeImplementation\AtlasSelfConstructionFinalCompletionDossierExporterService;
use App\Services\Ai\SelfConstruction\NativeImplementation\AtlasSelfConstructionFinalCompletionHumanGateService;
use App\Services\Ai\SelfConstruction\NativeImplementation\AtlasSelfConstructionFinalCompletionReadinessGateService;
use App\Services\Ai\SelfConstruction\NativeImplementation\AtlasSelfConstructionFinalEvidenceBundleService;
use App\Services\Ai\SelfConstruction\NativeImplementation\AtlasSelfConstructionFinalOperatorEvidenceClosureCorridorService;
use App\Services\Ai\SelfConstruction\NativeImplementation\AtlasSelfConstructionOperatorEvidenceArtifactTemplatePackService;
use App\Services\Ai\SelfConstruction\NativeImplementation\AtlasSelfConstructionOperatorEvidenceDraftHashFinalizerService;
use App\Services\Ai\SelfConstruction\NativeImplementation\AtlasSelfConstructionOperatorEvidenceDraftWorkspaceInspectorService;
use App\Services\Ai\SelfConstruction\NativeImplementation\AtlasSelfConstructionOperatorEvidenceDraftWorkspacePublisherService;
use App\Services\Ai\SelfConstruction\NativeImplementation\AtlasSelfConstructionOperatorEvidenceSubmissionReadinessService;
use App\Services\Ai\SelfConstruction\NativeImplementation\AtlasSelfConstructionOsCompletionAuditService;
use App\Services\Ai\SelfConstruction\NativeImplementation\AtlasSelfConstructionRuntimeGapMatrixAuditService;
use App\Services\Ai\SelfConstruction\Readiness\AtlasSelfConstructionReadinessService;
use App\Services\Ai\SelfConstruction\Support\AgentValidationGateCertificationService;
use App\Services\Ai\SelfConstruction\Support\AtlasSelfProgrammingSafetyContractCertificationService;

/**
 * Declarative catalog of every certification-workbench capability
 * (GOD-DEBULK Phase 2, ARCH blueprint SelfConstructionReadiness §2.1/§4.1).
 *
 * One entry per capability key: label, schema version and owning service.
 * This file is data, not behavior — CertificationWorkbenchEvaluator is the
 * single consumer and the only place quartet stages are projected from.
 * Generated from the 219 buildCertificationWorkbenchQuartet call sites the
 * readiness god service used to carry inline (A1-SC-0005).
 */
final class CertificationWorkbenchEntries
{
    /**
     * @return array<string, array{label: string, schema_version: string, service_class: class-string}>
     */
    public static function entries(): array
    {
        return [
            'adapter_execution_runtime_boundary' => [
                'label' => 'Adapter Execution Runtime Boundary',
                'schema_version' => AgentControlPlaneAdapterExecutionRuntimeBoundaryCertificationService::SCHEMA_VERSION,
                'service_class' => AgentControlPlaneAdapterExecutionRuntimeBoundaryCertificationService::class,
            ],
            'agent_runtime_registry' => [
                'label' => 'Agent Runtime Registry',
                'schema_version' => AgentRuntimeRegistryRepository::SCHEMA_VERSION,
                'service_class' => AgentRuntimeRegistryRepository::class,
            ],
            'agent_runtime_registry_availability' => [
                'label' => 'Agent Runtime Registry Availability Planner',
                'schema_version' => AgentRuntimeRegistryAvailabilityPlanner::SCHEMA_VERSION,
                'service_class' => AgentRuntimeRegistryAvailabilityPlanner::class,
            ],
            'agent_runtime_registry_capability_catalog' => [
                'label' => 'Agent Runtime Registry Capability Catalog',
                'schema_version' => AgentRuntimeRegistryCapabilityCatalog::SCHEMA_VERSION,
                'service_class' => AgentRuntimeRegistryCapabilityCatalog::class,
            ],
            'agent_runtime_registry_certification' => [
                'label' => 'Agent Runtime Registry Certification',
                'schema_version' => AgentRuntimeRegistryCertificationService::SCHEMA_VERSION,
                'service_class' => AgentRuntimeRegistryCertificationService::class,
            ],
            'agent_runtime_registry_handoff' => [
                'label' => 'Agent Runtime Registry Handoff Protocol',
                'schema_version' => AgentRuntimeRegistryHandoffProtocolBuilder::SCHEMA_VERSION,
                'service_class' => AgentRuntimeRegistryHandoffProtocolBuilder::class,
            ],
            'agent_runtime_registry_heartbeat' => [
                'label' => 'Agent Runtime Registry Heartbeat',
                'schema_version' => AgentRuntimeRegistryHeartbeatRepository::SCHEMA_VERSION,
                'service_class' => AgentRuntimeRegistryHeartbeatRepository::class,
            ],
            'agent_runtime_registry_load_balancing' => [
                'label' => 'Agent Runtime Registry Load Balancing Policy',
                'schema_version' => AgentRuntimeRegistryLoadBalancingPolicy::SCHEMA_VERSION,
                'service_class' => AgentRuntimeRegistryLoadBalancingPolicy::class,
            ],
            'agent_runtime_registry_orchestrator' => [
                'label' => 'Agent Runtime Registry Orchestrator',
                'schema_version' => AgentRuntimeRegistryOrchestrator::SCHEMA_VERSION,
                'service_class' => AgentRuntimeRegistryOrchestrator::class,
            ],
            'agent_runtime_registry_quarantine' => [
                'label' => 'Agent Runtime Registry Quarantine',
                'schema_version' => AgentRuntimeRegistryQuarantineRepository::SCHEMA_VERSION,
                'service_class' => AgentRuntimeRegistryQuarantineRepository::class,
            ],
            'agent_runtime_registry_task_matcher' => [
                'label' => 'Agent Runtime Registry Task Matcher',
                'schema_version' => AgentRuntimeRegistryTaskMatcher::SCHEMA_VERSION,
                'service_class' => AgentRuntimeRegistryTaskMatcher::class,
            ],
            'atlas_self_construction_completion_audit_blocker_explainer' => [
                'label' => 'Atlas Self-Construction Completion Audit Blocker Explainer',
                'schema_version' => AtlasSelfConstructionCompletionAuditBlockerExplainerService::SCHEMA_VERSION,
                'service_class' => AtlasSelfConstructionCompletionAuditBlockerExplainerService::class,
            ],
            'atlas_self_construction_completion_evidence_hash_composer' => [
                'label' => 'Atlas Self-Construction Completion Evidence Hash Composer',
                'schema_version' => AtlasSelfConstructionCompletionEvidenceHashComposerService::SCHEMA_VERSION,
                'service_class' => AtlasSelfConstructionCompletionEvidenceHashComposerService::class,
            ],
            'atlas_self_construction_completion_evidence_submission_preflight' => [
                'label' => 'Atlas Self-Construction Completion Evidence Submission Preflight',
                'schema_version' => AtlasSelfConstructionCompletionEvidenceSubmissionPreflightService::SCHEMA_VERSION,
                'service_class' => AtlasSelfConstructionCompletionEvidenceSubmissionPreflightService::class,
            ],
            'atlas_self_construction_completion_finalization_gate' => [
                'label' => 'Atlas Self-Construction Completion Finalization Gate',
                'schema_version' => AtlasSelfConstructionCompletionFinalizationGateService::SCHEMA_VERSION,
                'service_class' => AtlasSelfConstructionCompletionFinalizationGateService::class,
            ],
            'atlas_self_construction_final_completion_dossier_exporter' => [
                'label' => 'Atlas Self-Construction Final Completion Dossier Exporter',
                'schema_version' => AtlasSelfConstructionFinalCompletionDossierExporterService::SCHEMA_VERSION,
                'service_class' => AtlasSelfConstructionFinalCompletionDossierExporterService::class,
            ],
            'atlas_self_construction_final_completion_human_gate' => [
                'label' => 'Atlas Self-Construction Final Completion Human Gate',
                'schema_version' => AtlasSelfConstructionFinalCompletionHumanGateService::SCHEMA_VERSION,
                'service_class' => AtlasSelfConstructionFinalCompletionHumanGateService::class,
            ],
            'atlas_self_construction_final_completion_readiness_gate' => [
                'label' => 'Atlas Self-Construction Final Completion Readiness Gate',
                'schema_version' => AtlasSelfConstructionFinalCompletionReadinessGateService::SCHEMA_VERSION,
                'service_class' => AtlasSelfConstructionFinalCompletionReadinessGateService::class,
            ],
            'atlas_self_construction_final_evidence_bundle' => [
                'label' => 'Atlas Self-Construction Final Evidence Bundle',
                'schema_version' => AtlasSelfConstructionFinalEvidenceBundleService::SCHEMA_VERSION,
                'service_class' => AtlasSelfConstructionFinalEvidenceBundleService::class,
            ],
            'atlas_self_construction_final_operator_evidence_closure_corridor' => [
                'label' => 'Atlas Self-Construction Final Operator Evidence Closure Corridor',
                'schema_version' => AtlasSelfConstructionFinalOperatorEvidenceClosureCorridorService::SCHEMA_VERSION,
                'service_class' => AtlasSelfConstructionFinalOperatorEvidenceClosureCorridorService::class,
            ],
            'atlas_self_construction_operator_evidence_artifact_template_pack' => [
                'label' => 'Atlas Self-Construction Operator Evidence Artifact Template Pack',
                'schema_version' => AtlasSelfConstructionOperatorEvidenceArtifactTemplatePackService::SCHEMA_VERSION,
                'service_class' => AtlasSelfConstructionOperatorEvidenceArtifactTemplatePackService::class,
            ],
            'atlas_self_construction_operator_evidence_draft_hash_finalizer' => [
                'label' => 'Atlas Self-Construction Operator Evidence Draft Hash Finalizer',
                'schema_version' => AtlasSelfConstructionOperatorEvidenceDraftHashFinalizerService::SCHEMA_VERSION,
                'service_class' => AtlasSelfConstructionOperatorEvidenceDraftHashFinalizerService::class,
            ],
            'atlas_self_construction_operator_evidence_draft_workspace_inspector' => [
                'label' => 'Atlas Self-Construction Operator Evidence Draft Workspace Inspector',
                'schema_version' => AtlasSelfConstructionOperatorEvidenceDraftWorkspaceInspectorService::SCHEMA_VERSION,
                'service_class' => AtlasSelfConstructionOperatorEvidenceDraftWorkspaceInspectorService::class,
            ],
            'atlas_self_construction_operator_evidence_draft_workspace_publisher' => [
                'label' => 'Atlas Self-Construction Operator Evidence Draft Workspace Publisher',
                'schema_version' => AtlasSelfConstructionOperatorEvidenceDraftWorkspacePublisherService::SCHEMA_VERSION,
                'service_class' => AtlasSelfConstructionOperatorEvidenceDraftWorkspacePublisherService::class,
            ],
            'atlas_self_construction_operator_evidence_submission_readiness' => [
                'label' => 'Atlas Self-Construction Operator Evidence Submission Readiness',
                'schema_version' => AtlasSelfConstructionOperatorEvidenceSubmissionReadinessService::SCHEMA_VERSION,
                'service_class' => AtlasSelfConstructionOperatorEvidenceSubmissionReadinessService::class,
            ],
            'atlas_self_construction_os_completion_audit' => [
                'label' => 'Atlas Self-Construction OS Completion Audit',
                'schema_version' => AtlasSelfConstructionOsCompletionAuditService::SCHEMA_VERSION,
                'service_class' => AtlasSelfConstructionOsCompletionAuditService::class,
            ],
            'atlas_self_construction_os_completion_operator_action_packet' => [
                'label' => 'Atlas Self-Construction OS Completion Operator Action Packet',
                'schema_version' => AtlasSelfConstructionCompletionOperatorActionPacketService::SCHEMA_VERSION,
                'service_class' => AtlasSelfConstructionCompletionOperatorActionPacketService::class,
            ],
            'atlas_self_construction_os_handoff' => [
                'label' => 'Atlas Self-Construction OS Handoff',
                'schema_version' => 'atlas.self_construction.os_handoff.v1',
                'service_class' => AtlasSelfConstructionReadinessService::class,
            ],
            'atlas_self_programming_os_transition_readiness' => [
                'label' => 'Atlas Self-Programming OS Transition Readiness',
                'schema_version' => 'atlas.self_programming.transition_readiness.v1',
                'service_class' => AtlasSelfConstructionFinalCompletionReadinessGateService::class,
            ],
            'atlas_self_programming_safety_contract_certification' => [
                'label' => 'Atlas Self-Programming Safety Contract Certification',
                'schema_version' => AtlasSelfProgrammingSafetyContractCertificationService::SCHEMA_VERSION,
                'service_class' => AtlasSelfProgrammingSafetyContractCertificationService::class,
            ],
            'automatic_cost_import_runtime' => [
                'label' => 'Automatic Cost Import Runtime',
                'schema_version' => AgentControlPlaneAutomaticCostImportRuntimeCertificationService::SCHEMA_VERSION,
                'service_class' => AgentControlPlaneAutomaticCostImportRuntimeCertificationService::class,
            ],
            'automatic_work_product_collection_runtime' => [
                'label' => 'Automatic Work Product Collection Runtime',
                'schema_version' => AgentControlPlaneAutomaticWorkProductCollectionCertificationService::SCHEMA_VERSION,
                'service_class' => AgentControlPlaneAutomaticWorkProductCollectionCertificationService::class,
            ],
            'certification_baseline' => [
                'label' => 'Certification Baseline',
                'schema_version' => AgentControlPlaneCertificationBaselineService::SCHEMA_VERSION,
                'service_class' => AgentControlPlaneCertificationBaselineService::class,
            ],
            'certification_coverage_report' => [
                'label' => 'Certification Coverage Report',
                'schema_version' => AgentControlPlaneCertificationCoverageReportService::SCHEMA_VERSION,
                'service_class' => AgentControlPlaneCertificationCoverageReportService::class,
            ],
            'certification_evidence_query' => [
                'label' => 'Certification Evidence Query',
                'schema_version' => AgentControlPlaneCertificationEvidenceQueryService::SCHEMA_VERSION,
                'service_class' => AgentControlPlaneCertificationEvidenceQueryService::class,
            ],
            'certification_fuzz_harness' => [
                'label' => 'Certification Fuzz Harness',
                'schema_version' => AgentControlPlaneCertificationFuzzHarness::SCHEMA_VERSION,
                'service_class' => AgentControlPlaneCertificationFuzzHarness::class,
            ],
            'certification_mutation_guard' => [
                'label' => 'Certification Mutation Guard',
                'schema_version' => AgentControlPlaneCertificationMutationGuard::SCHEMA_VERSION,
                'service_class' => AgentControlPlaneCertificationMutationGuard::class,
            ],
            'certification_scenario_corpus' => [
                'label' => 'Certification Scenario Corpus',
                'schema_version' => AgentControlPlaneCertificationScenarioCorpusService::SCHEMA_VERSION,
                'service_class' => AgentControlPlaneCertificationScenarioCorpusService::class,
            ],
            'certification_scenario_simulator' => [
                'label' => 'Certification Scenario Simulator',
                'schema_version' => AgentControlPlaneCertificationScenarioSimulator::SCHEMA_VERSION,
                'service_class' => AgentControlPlaneCertificationScenarioSimulator::class,
            ],
            'certification_status_batch' => [
                'label' => 'Certification Status Batch',
                'schema_version' => AgentControlPlaneCertificationStatusBatchService::SCHEMA_VERSION,
                'service_class' => AgentControlPlaneCertificationStatusBatchService::class,
            ],
            'claim_lease_runtime' => [
                'label' => 'Claim/Lease Runtime',
                'schema_version' => AgentControlPlaneClaimLeaseRepository::SCHEMA_VERSION,
                'service_class' => AgentControlPlaneClaimLeaseRepository::class,
            ],
            'claim_lease_simulator' => [
                'label' => 'Claim/Lease Simulator',
                'schema_version' => AgentControlPlaneClaimLeaseSimulator::SCHEMA_VERSION,
                'service_class' => AgentControlPlaneClaimLeaseSimulator::class,
            ],
            'continuation_summary_builder' => [
                'label' => 'Continuation Summary Builder',
                'schema_version' => AgentControlPlaneContinuationSummaryBuilder::SCHEMA_VERSION,
                'service_class' => AgentControlPlaneContinuationSummaryBuilder::class,
            ],
            'cost_import_dry_run' => [
                'label' => 'Cost Import Dry-Run',
                'schema_version' => AgentControlPlaneCostImportDryRun::SCHEMA_VERSION,
                'service_class' => AgentControlPlaneCostImportDryRun::class,
            ],
            'dispatch_planner_runtime' => [
                'label' => 'Dispatch Planner Runtime',
                'schema_version' => AgentDispatchPlannerCertificationService::SCHEMA_VERSION,
                'service_class' => AgentDispatchPlannerCertificationService::class,
            ],
            'evidence_ledger_dry_run' => [
                'label' => 'Evidence Ledger Dry-Run',
                'schema_version' => AgentControlPlaneEvidenceLedgerDryRun::SCHEMA_VERSION,
                'service_class' => AgentControlPlaneEvidenceLedgerDryRun::class,
            ],
            'execution_workspace_runtime' => [
                'label' => 'Execution Workspace Runtime',
                'schema_version' => AgentControlPlaneExecutionWorkspaceCertificationService::SCHEMA_VERSION,
                'service_class' => AgentControlPlaneExecutionWorkspaceCertificationService::class,
            ],
            'governance_approval_runtime' => [
                'label' => 'Governance Approval Runtime',
                'schema_version' => AgentControlPlaneGovernanceApprovalCertificationService::SCHEMA_VERSION,
                'service_class' => AgentControlPlaneGovernanceApprovalCertificationService::class,
            ],
            'merge_review_runtime' => [
                'label' => 'Merge Review Runtime',
                'schema_version' => AgentMergeReviewCertificationService::SCHEMA_VERSION,
                'service_class' => AgentMergeReviewCertificationService::class,
            ],
            'multi_agent_loop_certification' => [
                'label' => 'Multi-Agent Loop Certification',
                'schema_version' => AgentControlPlaneMultiAgentLoopCertificationService::SCHEMA_VERSION,
                'service_class' => AgentControlPlaneMultiAgentLoopCertificationService::class,
            ],
            'multi_agent_parallelism_planner' => [
                'label' => 'Multi-Agent Parallelism Planner',
                'schema_version' => AgentControlPlaneMultiAgentParallelismPlanner::SCHEMA_VERSION,
                'service_class' => AgentControlPlaneMultiAgentParallelismPlanner::class,
            ],
            'multi_snapshot_comparison' => [
                'label' => 'Multi-Snapshot Comparison',
                'schema_version' => AgentControlPlaneMultiSnapshotComparisonService::SCHEMA_VERSION,
                'service_class' => AgentControlPlaneMultiSnapshotComparisonService::class,
            ],
            'one_shot_worker_packet' => [
                'label' => 'One-Shot Worker Packet',
                'schema_version' => AgentControlPlaneOneShotWorkerPacketService::SCHEMA_VERSION,
                'service_class' => AgentControlPlaneOneShotWorkerPacketService::class,
            ],
            'release_dossier' => [
                'label' => 'Release Dossier',
                'schema_version' => AgentControlPlaneReleaseDossierService::SCHEMA_VERSION,
                'service_class' => AgentControlPlaneReleaseDossierService::class,
            ],
            'release_dossier_exporter' => [
                'label' => 'Release Dossier Exporter',
                'schema_version' => AgentControlPlaneReleaseDossierExporter::SCHEMA_VERSION,
                'service_class' => AgentControlPlaneReleaseDossierExporter::class,
            ],
            'runtime_evidence_journal' => [
                'label' => 'Runtime Evidence Journal',
                'schema_version' => AgentRuntimeEvidenceCertificationService::SCHEMA_VERSION,
                'service_class' => AgentRuntimeEvidenceCertificationService::class,
            ],
            'runtime_gap_matrix_audit' => [
                'label' => 'Runtime Gap Matrix Audit',
                'schema_version' => AtlasSelfConstructionRuntimeGapMatrixAuditService::SCHEMA_VERSION,
                'service_class' => AtlasSelfConstructionRuntimeGapMatrixAuditService::class,
            ],
            'runtime_pilot_certification' => [
                'label' => 'Runtime Pilot Certification',
                'schema_version' => AgentControlPlaneRuntimePilotCertificationService::SCHEMA_VERSION,
                'service_class' => AgentControlPlaneRuntimePilotCertificationService::class,
            ],
            'runtime_pilot_orchestrator' => [
                'label' => 'Runtime Pilot Orchestrator',
                'schema_version' => AgentControlPlaneRuntimePilotOrchestrator::SCHEMA_VERSION,
                'service_class' => AgentControlPlaneRuntimePilotOrchestrator::class,
            ],
            'scope_lock_planner' => [
                'label' => 'Scope Lock Planner',
                'schema_version' => AgentControlPlaneScopeLockPlanner::SCHEMA_VERSION,
                'service_class' => AgentControlPlaneScopeLockPlanner::class,
            ],
            'scope_lock_runtime_validator' => [
                'label' => 'Scope Lock Runtime Validator',
                'schema_version' => AgentControlPlaneScopeLockRuntimeValidator::SCHEMA_VERSION,
                'service_class' => AgentControlPlaneScopeLockRuntimeValidator::class,
            ],
            'task_auto_replenishment' => [
                'label' => 'Task Auto-Replenishment',
                'schema_version' => AgentControlPlaneTaskAutoReplenishmentService::SCHEMA_VERSION,
                'service_class' => AgentControlPlaneTaskAutoReplenishmentService::class,
            ],
            'task_lease_recovery' => [
                'label' => 'Task Lease Recovery',
                'schema_version' => AgentControlPlaneTaskLeaseRecoveryService::SCHEMA_VERSION,
                'service_class' => AgentControlPlaneTaskLeaseRecoveryService::class,
            ],
            'task_packet_builder' => [
                'label' => 'Task Packet Builder',
                'schema_version' => AgentControlPlaneTaskPacketBuilder::SCHEMA_VERSION,
                'service_class' => AgentControlPlaneTaskPacketBuilder::class,
            ],
            'task_packet_queue' => [
                'label' => 'Task Packet Queue',
                'schema_version' => AgentControlPlaneTaskPacketQueueRepository::SCHEMA_VERSION,
                'service_class' => AgentControlPlaneTaskPacketQueueRepository::class,
            ],
            'task_queue_lease_certification' => [
                'label' => 'Task Queue + Lease Certification',
                'schema_version' => AgentControlPlaneTaskQueueLeaseCertificationService::SCHEMA_VERSION,
                'service_class' => AgentControlPlaneTaskQueueLeaseCertificationService::class,
            ],
            'task_queue_orchestrator' => [
                'label' => 'Task Queue Orchestrator',
                'schema_version' => AgentControlPlaneTaskQueueOrchestrator::SCHEMA_VERSION,
                'service_class' => AgentControlPlaneTaskQueueOrchestrator::class,
            ],
            'terminal_loop_health_digest' => [
                'label' => 'Terminal Loop Health Digest',
                'schema_version' => AgentControlPlaneTerminalLoopHealthDigestService::SCHEMA_VERSION,
                'service_class' => AgentControlPlaneTerminalLoopHealthDigestService::class,
            ],
            'terminal_loop_operational_proof' => [
                'label' => 'Terminal Loop Operational Proof',
                'schema_version' => AgentControlPlaneTerminalLoopOperationalProofService::SCHEMA_VERSION,
                'service_class' => AgentControlPlaneTerminalLoopOperationalProofService::class,
            ],
            'terminal_worker_bootstrap' => [
                'label' => 'Terminal Worker Bootstrap',
                'schema_version' => AgentControlPlaneTerminalWorkerBootstrapService::SCHEMA_VERSION,
                'service_class' => AgentControlPlaneTerminalWorkerBootstrapService::class,
            ],
            'validation_gate_runtime' => [
                'label' => 'Validation Gate Runtime',
                'schema_version' => AgentValidationGateCertificationService::SCHEMA_VERSION,
                'service_class' => AgentValidationGateCertificationService::class,
            ],
            'work_product_manifest_planner' => [
                'label' => 'Work Product Manifest Planner',
                'schema_version' => AgentControlPlaneWorkProductManifestPlanner::SCHEMA_VERSION,
                'service_class' => AgentControlPlaneWorkProductManifestPlanner::class,
            ],
            'worker_task_eligibility_certification' => [
                'label' => 'Worker Task Eligibility Certification',
                'schema_version' => AgentControlPlaneWorkerTaskEligibilityCertificationService::SCHEMA_VERSION,
                'service_class' => AgentControlPlaneWorkerTaskEligibilityCertificationService::class,
            ],
        ];
    }
}
