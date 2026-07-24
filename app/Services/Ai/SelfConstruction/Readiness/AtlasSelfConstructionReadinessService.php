<?php

namespace App\Services\Ai\SelfConstruction\Readiness;

use App\Models\AtlasSelfConstructionAgentCostEvent;
use App\Models\AtlasSelfConstructionAgentDispatchReceipt;
use App\Models\AtlasSelfConstructionAgentHeartbeat;
use App\Models\AtlasSelfConstructionAgentRun;
use App\Models\AtlasSelfConstructionAgentWakeupItem;
use App\Models\AtlasSelfConstructionAgentWorkProduct;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use App\Services\Ai\SelfConstruction\NativeImplementation\AtlasSelfConstructionReservationRepository;
use App\Services\Ai\SelfConstruction\Support\ReadinessCatalog;
use App\Services\Ai\SelfConstruction\Support\ReadinessDocumentProbe;
use App\Services\Ai\SelfConstruction\Support\ReadinessPathPolicy;
use App\Services\Ai\SelfConstruction\Support\WriteSetOverlap;
use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskQueueOrchestrator;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentAutomaticDispatchSchedulerOneShotTickAdapterInvocationBoundaryInvoker;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentAutomaticDispatchSchedulerOneShotTickCodexExternalProcessInvokerDryRunInvoker;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentAutomaticDispatchSchedulerOneShotTickCodexExternalProcessRuntimeDriverInvoker;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentAutomaticDispatchSchedulerOneShotTickCodexFinalProcessSpawnExecutorInvoker;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentAutomaticDispatchSchedulerOneShotTickCodexProcessInvocationAuthorizationInvoker;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentAutomaticDispatchSchedulerOneShotTickCodexProcessSpawnEnablementInvoker;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentAutomaticDispatchSchedulerOneShotTickCodexProcessStartReleaseInvoker;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentAutomaticDispatchSchedulerOneShotTickCodexProviderExecutionContractInvoker;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerActualProcessStartRehearsalExecutorInvoker;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerExecutorEnablementGateInvoker;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerFinalProcessStartAuthorizationGateInvoker;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerGuardedProcessStartExecutorInvoker;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerImplementationBoundaryInvoker;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerManualStartExecutorReceiptInvoker;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerOperatorStartHandoffInvoker;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartAdapterExecutionGuardGateInvoker;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartAdapterInvocationBoundaryGateInvoker;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartDispatchExecutorHandoffInvoker;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartDispatchReceiptUseExecutorInvoker;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartDispatchReleaseGateInvoker;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartEvidenceAcceptanceBridgeInvoker;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartEvidenceReceiptInvoker;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartExecutorEnablementGateInvoker;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartExecutorFreshReleaseGateInvoker;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartExecutorPlanGateInvoker;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartExternalProcessInvokerDryRunGateInvoker;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartExternalProcessRuntimeGateInvoker;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartFinalProcessSpawnExecutorGateInvoker;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartImplementationBoundaryGateInvoker;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartLivenessMonitorInvoker;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartManualStartExecutorReceiptInvoker;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartOperatorStartHandoffInvoker;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProcessInvocationAuthorizationGateInvoker;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProcessSpawnEnablementGateInvoker;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProcessStartReleaseGateInvoker;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProcessStarterReadinessGateInvoker;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProviderExecutionContractGateInvoker;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProviderStartDriverGateInvoker;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartRealInvokerReleasePreflightGateInvoker;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartReceiptContractInvoker;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartSignedDispatchAuthorizationGateInvoker;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartSignedRealInvokerReleaseGateInvoker;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartSupervisedStartExecutorGateInvoker;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerProcessStartEnvelopeBuilderInvoker;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerProcessStarterReadinessGateInvoker;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerReleasePreflightInvoker;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerStartExecutionGateInvoker;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerSupervisedStartActivationGateInvoker;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentAutomaticDispatchSchedulerOneShotTickCodexSignedRealInvokerReleaseGateInvoker;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentAutomaticDispatchSchedulerOneShotTickCodexSupervisedStartExecutorInvoker;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentAutomaticDispatchSchedulerOneShotTickDispatchReceiptUseInvoker;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentAutomaticDispatchSchedulerOneShotTickProviderAdapterExecutionGuardInvoker;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentAutomaticDispatchSchedulerOneShotTickProviderStartDriverInvoker;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneAdapterExecutionRuntimeBoundaryCertificationService;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneAutomaticCostImportRuntimeCertificationService;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneAutomaticWorkProductCollectionCertificationService;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneBaselineCaptureReadinessService;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneCertificationBaselineService;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneCertificationCoverageReportService;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneCertificationEvidenceQueryService;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneCertificationFuzzHarness;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneCertificationMutationGuard;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneCertificationScenarioCorpusService;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneCertificationScenarioSimulator;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneCertificationStatusBatchService;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneChainIntegrityAuditService;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneClaimLeaseRepository;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneClaimLeaseSimulator;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneContinuationSummaryBuilder;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneCostImportDryRun;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneDeterministicChainReplayService;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneEvidenceLedgerDryRun;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneExecutionWorkspaceCertificationService;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneGovernanceApprovalCertificationService;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneMacroSprintPromotionGate;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneMultiAgentParallelismPlanner;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneMultiSnapshotComparisonService;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneOneShotWorkerPacketService;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneReleaseDossierExporter;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneReleaseDossierService;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneReplayDiffService;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneReplaySnapshotStore;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneRuntimePilotCertificationService;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneRuntimePilotOrchestrator;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneScopeLockPlanner;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneScopeLockRuntimeValidator;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneTaskAutoReplenishmentService;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneTaskLeaseRecoveryService;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneTaskPacketBuilder;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneTaskPacketQueueRepository;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneTaskQueueLeaseCertificationService;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneTerminalWorkerBootstrapService;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneWorkProductManifestPlanner;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneWorkerTaskEligibilityCertificationService;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentDispatchExecutorAdapterInvocationBoundary;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentDispatchExecutorProviderStartDriver;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentDispatchExecutorReceiptUseWriter;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentDispatchPlannerCertificationService;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentMergeReviewCertificationService;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentProviderAdapterExecutionGuard;
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
use App\Services\Ai\SelfConstruction\NativeImplementation\AtlasSelfConstructionFinalCompletionDossierExporterService;
use App\Services\Ai\SelfConstruction\NativeImplementation\AtlasSelfConstructionFinalCompletionHumanGateService;
use App\Services\Ai\SelfConstruction\NativeImplementation\AtlasSelfConstructionFinalCompletionReadinessGateService;
use App\Services\Ai\SelfConstruction\NativeImplementation\AtlasSelfConstructionFinalEvidenceBundleService;
use App\Services\Ai\SelfConstruction\NativeImplementation\AtlasSelfConstructionOperatorEvidenceArtifactTemplatePackService;
use App\Services\Ai\SelfConstruction\NativeImplementation\AtlasSelfConstructionOperatorEvidenceDraftHashFinalizerService;
use App\Services\Ai\SelfConstruction\NativeImplementation\AtlasSelfConstructionOperatorEvidenceDraftWorkspaceInspectorService;
use App\Services\Ai\SelfConstruction\NativeImplementation\AtlasSelfConstructionOperatorEvidenceDraftWorkspacePublisherService;
use App\Services\Ai\SelfConstruction\NativeImplementation\AtlasSelfConstructionOsCompletionAuditService;
use App\Services\Ai\SelfConstruction\NativeImplementation\AtlasSelfConstructionRuntimeGapMatrixAuditService;
use App\Services\Ai\SelfConstruction\NativeImplementation\AtlasSelfConstructionRuntimeGapMatrixService;
use App\Services\Ai\SelfConstruction\Support\AgentCodexExternalProcessInvocationAuthorizationGate;
use App\Services\Ai\SelfConstruction\Support\AgentCodexExternalProcessInvokerDryRun;
use App\Services\Ai\SelfConstruction\Support\AgentCodexExternalProcessRuntimeDriver;
use App\Services\Ai\SelfConstruction\Support\AgentCodexProcessSpawnEnablementGate;
use App\Services\Ai\SelfConstruction\Support\AgentCodexProcessSpawnExecutor;
use App\Services\Ai\SelfConstruction\Support\AgentCodexProcessStartReleaseGate;
use App\Services\Ai\SelfConstruction\Support\AgentCodexProviderExecutionDriver;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerActualProcessStartRehearsalExecutor;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerExecutorEnablementGate;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerExecutorFreshReleaseGate;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerFinalProcessStartAuthorizationGate;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerGuardedProcessStartExecutor;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerImplementationBoundary;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerManualStartExecutorReceiptWriter;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerOperatorStartHandoffBuilder;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerPostStartAdapterExecutionGuardGate;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerPostStartAdapterInvocationBoundaryGate;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerPostStartDispatchExecutorHandoff;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerPostStartDispatchReceiptUseExecutor;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerPostStartDispatchReleaseGate;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerPostStartEvidenceAcceptanceBridge;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerPostStartEvidenceReceiptWriter;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerPostStartExecutorEnablementGate;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerPostStartExecutorFreshReleaseGate;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerPostStartExecutorPlanGate;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerPostStartExternalProcessInvokerDryRunGate;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerPostStartExternalProcessRuntimeGate;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerPostStartFinalProcessSpawnExecutorGate;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerPostStartImplementationBoundaryGate;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerPostStartLivenessMonitor;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerPostStartManualStartExecutorReceiptWriter;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerPostStartOperatorStartHandoffBuilder;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerPostStartProcessInvocationAuthorizationGate;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerPostStartProcessSpawnEnablementGate;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerPostStartProcessStartReleaseGate;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerPostStartProcessStarterReadinessGate;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerPostStartProviderExecutionContractGate;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerPostStartProviderStartDriverGate;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerPostStartRealInvokerReleasePreflightGate;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerPostStartReceiptContractBuilder;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerPostStartSignedDispatchAuthorizationGate;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerPostStartSignedRealInvokerReleaseGate;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerPostStartStartExecutionGate;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerPostStartSupervisedStartExecutorGate;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerProcessStartEnvelopeBuilder;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerProcessStarterReadinessGate;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerReleasePreflight;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerStartExecutionGate;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerSupervisedStartActivationGate;
use App\Services\Ai\SelfConstruction\Support\AgentCodexSignedRealInvokerReleaseGate;
use App\Services\Ai\SelfConstruction\Support\AgentCodexSupervisedStartExecutor;
use App\Services\Ai\SelfConstruction\Support\AgentValidationGateCertificationService;
use App\Services\Ai\SelfConstruction\Support\AgentValidationGatePlanBuilder;
use App\Services\Ai\SelfConstruction\Support\AtlasSelfProgrammingSafetyContractCertificationService;
use App\Services\Ai\SelfConstruction\Support\ReadinessCommandSurface;
use App\Services\Ai\SelfConstruction\Support\ReadinessCompletionClaimAuthority;
use App\Services\Ai\SelfConstruction\Support\ReadinessHash;
use App\Services\Ai\SelfConstruction\Support\ReadinessJsonInput;

final class AtlasSelfConstructionReadinessService
{
    use \App\Services\Ai\SelfConstruction\Readiness\HubDelegators\AtlasSelfConstructionRuntimeGapMatrixServiceDelegators;
    use \App\Services\Ai\SelfConstruction\Readiness\HubDelegators\CertificationWorkbenchEvaluatorDelegators;
    use \App\Services\Ai\SelfConstruction\Readiness\HubDelegators\ReadinessCertificationChainQuartetProjectorDelegators;
    use \App\Services\Ai\SelfConstruction\Readiness\HubDelegators\ReadinessPacketProjectionDelegators;
    use \App\Services\Ai\SelfConstruction\Readiness\HubDelegators\ReadinessStatusProjectionDelegators;
    use \App\Services\Ai\SelfConstruction\Readiness\HubDelegators\AgentAutomaticDispatchBatch1SectionDelegators;
    use \App\Services\Ai\SelfConstruction\Readiness\HubDelegators\AgentAutomaticDispatchBatch2SectionDelegators;
    use \App\Services\Ai\SelfConstruction\Readiness\HubDelegators\AgentAutomaticTailPart1SectionDelegators;
    use \App\Services\Ai\SelfConstruction\Readiness\HubDelegators\AgentAutomaticTailPart2SectionDelegators;
    use \App\Services\Ai\SelfConstruction\Readiness\HubDelegators\AgentCodexSectionDelegators;
    use \App\Services\Ai\SelfConstruction\Readiness\HubDelegators\AgentControlPlaneCertificationSectionDelegators;
    use \App\Services\Ai\SelfConstruction\Readiness\HubDelegators\AgentControlPlaneReplaySectionDelegators;
    use \App\Services\Ai\SelfConstruction\Readiness\HubDelegators\AgentControlPlaneRuntimeStatusPart1SectionDelegators;
    use \App\Services\Ai\SelfConstruction\Readiness\HubDelegators\AgentControlPlaneRuntimeStatusPart2SectionDelegators;
    use \App\Services\Ai\SelfConstruction\Readiness\HubDelegators\AgentControlPlaneSectionDelegators;
    use \App\Services\Ai\SelfConstruction\Readiness\HubDelegators\AgentControlPlaneTaskLeaseSectionDelegators;
    use \App\Services\Ai\SelfConstruction\Readiness\HubDelegators\AgentDispatchProviderSectionDelegators;
    use \App\Services\Ai\SelfConstruction\Readiness\HubDelegators\AgentLivenessSectionDelegators;
    use \App\Services\Ai\SelfConstruction\Readiness\HubDelegators\AgentWakeupSectionDelegators;
    use \App\Services\Ai\SelfConstruction\Readiness\HubDelegators\CodexProjectionSectionDelegators;
    use \App\Services\Ai\SelfConstruction\Readiness\HubDelegators\CodexReviewMergeSectionDelegators;
    use \App\Services\Ai\SelfConstruction\Readiness\HubDelegators\DispatchGateSectionDelegators;
    use \App\Services\Ai\SelfConstruction\Readiness\HubDelegators\DurableReservationSectionDelegators;
    use \App\Services\Ai\SelfConstruction\Readiness\HubDelegators\FinalCompletionGateSectionDelegators;
    use \App\Services\Ai\SelfConstruction\Readiness\HubDelegators\ForgeWorkspaceSectionDelegators;
    use \App\Services\Ai\SelfConstruction\Readiness\HubDelegators\HumanCompletionReceiptSectionDelegators;
    use \App\Services\Ai\SelfConstruction\Readiness\HubDelegators\MiscProjectionsSectionDelegators;
    use \App\Services\Ai\SelfConstruction\Readiness\HubDelegators\MutatingWriterSectionDelegators;
    use \App\Services\Ai\SelfConstruction\Readiness\HubDelegators\OneShotTickCodexPart10SectionDelegators;
    use \App\Services\Ai\SelfConstruction\Readiness\HubDelegators\OneShotTickCodexPart1SectionDelegators;
    use \App\Services\Ai\SelfConstruction\Readiness\HubDelegators\OneShotTickCodexPart2SectionDelegators;
    use \App\Services\Ai\SelfConstruction\Readiness\HubDelegators\OneShotTickCodexPart3SectionDelegators;
    use \App\Services\Ai\SelfConstruction\Readiness\HubDelegators\OneShotTickCodexPart4SectionDelegators;
    use \App\Services\Ai\SelfConstruction\Readiness\HubDelegators\OneShotTickCodexPart5SectionDelegators;
    use \App\Services\Ai\SelfConstruction\Readiness\HubDelegators\OneShotTickCodexPart6SectionDelegators;
    use \App\Services\Ai\SelfConstruction\Readiness\HubDelegators\OneShotTickCodexPart7SectionDelegators;
    use \App\Services\Ai\SelfConstruction\Readiness\HubDelegators\OneShotTickCodexPart8SectionDelegators;
    use \App\Services\Ai\SelfConstruction\Readiness\HubDelegators\OneShotTickCodexPart9SectionDelegators;
    use \App\Services\Ai\SelfConstruction\Readiness\HubDelegators\OperatorEvidenceDraftSectionDelegators;
    use \App\Services\Ai\SelfConstruction\Readiness\HubDelegators\OsCompletionEvidenceSectionDelegators;
    use \App\Services\Ai\SelfConstruction\Readiness\HubDelegators\OsEvidenceSectionDelegators;
    use \App\Services\Ai\SelfConstruction\Readiness\HubDelegators\OwnershipBoundarySectionDelegators;
    use \App\Services\Ai\SelfConstruction\Readiness\HubDelegators\PacketLifecycleSectionDelegators;
    use \App\Services\Ai\SelfConstruction\Readiness\HubDelegators\PacketQueuePolicySectionDelegators;
    use \App\Services\Ai\SelfConstruction\Readiness\HubDelegators\ParallelSessionSectionDelegators;
    use \App\Services\Ai\SelfConstruction\Readiness\HubDelegators\PostStartGateBSectionDelegators;
    use \App\Services\Ai\SelfConstruction\Readiness\HubDelegators\PostStartGateStatusSectionDelegators;
    use \App\Services\Ai\SelfConstruction\Readiness\HubDelegators\RealProviderSmokeSectionDelegators;
    use \App\Services\Ai\SelfConstruction\Readiness\HubDelegators\ReleaseWriterSectionDelegators;
    use \App\Services\Ai\SelfConstruction\Readiness\HubDelegators\ReviewMergeSectionDelegators;
    use \App\Services\Ai\SelfConstruction\Readiness\HubDelegators\RuntimePromotionSectionDelegators;
    use \App\Services\Ai\SelfConstruction\Readiness\HubDelegators\SurfaceMatrixSectionDelegators;
    use \App\Services\Ai\SelfConstruction\Readiness\HubDelegators\TerminalLoopSectionDelegators;

    private ?\App\Services\Ai\SelfConstruction\Readiness\ReadinessProjectionHumanCompletionReceiptSection $humanCompletionReceiptSection = null;

    private ?\App\Services\Ai\SelfConstruction\Readiness\ReadinessProjectionRealProviderSmokeSection $realProviderSmokeSection = null;

    private ?\App\Services\Ai\SelfConstruction\Readiness\ReadinessProjectionRuntimePromotionSection $runtimePromotionSection = null;

    private ?\App\Services\Ai\SelfConstruction\Readiness\ReadinessProjectionAgentControlPlaneSection $agentControlPlaneSection = null;

    private ?ReadinessEnvelopeProjector $envelopeProjector = null;
    private ?\App\Services\Ai\SelfConstruction\Readiness\ReadinessProjectionForgeWorkspaceSection $forgeWorkspaceSection = null;
    private ?\App\Services\Ai\SelfConstruction\Readiness\ReadinessProjectionParallelSessionSection $parallelSessionSection = null;
    private ?\App\Services\Ai\SelfConstruction\Readiness\ReadinessProjectionSurfaceMatrixSection $surfaceMatrixSection = null;
    private ?\App\Services\Ai\SelfConstruction\Readiness\ReadinessProjectionAgentAutomaticDispatchBatch2Section $agentAutomaticDispatchBatch2Section = null;

    private ?\App\Services\Ai\SelfConstruction\Readiness\ReadinessProjectionAgentAutomaticDispatchBatch1Section $agentAutomaticDispatchBatch1Section = null;

    private ?\App\Services\Ai\SelfConstruction\Readiness\ReadinessProjectionOwnershipBoundarySection $ownershipBoundarySection = null;

    private ?\App\Services\Ai\SelfConstruction\Readiness\ReadinessProjectionPostStartGateStatusSection $postStartGateStatusSection = null;

    private ?\App\Services\Ai\SelfConstruction\Readiness\ReadinessProjectionPostStartGateBSection $postStartGateBSection = null;

    private ?\App\Services\Ai\SelfConstruction\Readiness\ReadinessProjectionTerminalLoopSection $terminalLoopSection = null;

    private ?\App\Services\Ai\SelfConstruction\Readiness\ReadinessProjectionReleaseWriterSection $releaseWriterSection = null;

    private ?\App\Services\Ai\SelfConstruction\Readiness\ReadinessProjectionMutatingWriterSection $mutatingWriterSection = null;

    private ?\App\Services\Ai\SelfConstruction\Readiness\ReadinessProjectionDispatchGateSection $dispatchGateSection = null;

    private ?\App\Services\Ai\SelfConstruction\Readiness\ReadinessProjectionAgentWakeupSection $agentWakeupSection = null;

    private ?\App\Services\Ai\SelfConstruction\Readiness\ReadinessProjectionOsEvidenceSection $osEvidenceSection = null;

    private ?\App\Services\Ai\SelfConstruction\Readiness\ReadinessProjectionDurableReservationSection $durableReservationSection = null;

    private ?\App\Services\Ai\SelfConstruction\Readiness\ReadinessProjectionAgentDispatchProviderSection $agentDispatchProviderSection = null;

    private ?\App\Services\Ai\SelfConstruction\Readiness\ReadinessProjectionAgentCodexSection $agentCodexSection = null;

    private ?\App\Services\Ai\SelfConstruction\Readiness\ReadinessProjectionCodexReviewMergeSection $codexReviewMergeSection = null;

    private ?ReadinessProjectionOneShotTickCodexPart1Section $oneShotTickCodexPart1Section = null;

    private ?ReadinessProjectionOneShotTickCodexPart2Section $oneShotTickCodexPart2Section = null;

    private ?ReadinessProjectionOneShotTickCodexPart3Section $oneShotTickCodexPart3Section = null;

    private ?ReadinessProjectionOneShotTickCodexPart4Section $oneShotTickCodexPart4Section = null;

    private ?ReadinessProjectionOneShotTickCodexPart5Section $oneShotTickCodexPart5Section = null;

    private ?ReadinessProjectionOneShotTickCodexPart6Section $oneShotTickCodexPart6Section = null;

    private ?ReadinessProjectionOneShotTickCodexPart7Section $oneShotTickCodexPart7Section = null;

    private ?ReadinessProjectionOneShotTickCodexPart8Section $oneShotTickCodexPart8Section = null;

    private ?ReadinessProjectionOneShotTickCodexPart9Section $oneShotTickCodexPart9Section = null;

    private ?ReadinessProjectionOneShotTickCodexPart10Section $oneShotTickCodexPart10Section = null;

    private ?ReadinessProjectionAgentAutomaticTailPart1Section $agentAutomaticTailPart1Section = null;

    private ?ReadinessProjectionAgentAutomaticTailPart2Section $agentAutomaticTailPart2Section = null;

    private ?ReadinessProjectionCodexProjectionSection $codexProjectionSection = null;

    private ?ReadinessProjectionMiscProjectionsPart1Section $miscProjectionsPart1Section = null;

    private ?ReadinessProjectionMiscProjectionsPart2Section $miscProjectionsPart2Section = null;

    private ?ReadinessProjectionMiscProjectionsPart3Section $miscProjectionsPart3Section = null;

    public const CANONICAL_OPERATOR_SUBMISSION_PATHS = [
        'runtime_promotion_receipt' => 'atlas/self-construction/operator-submissions/runtime-promotion.json',
        'real_provider_smoke' => 'atlas/self-construction/operator-submissions/real-provider-smoke.json',
        'completion_receipt' => 'atlas/self-construction/operator-submissions/completion-receipt.json',
        'terminal_loop_operational_proof_binding' => 'atlas/self-construction/operator-submissions/terminal-loop-operational-proof-binding.json',
    ];

    public function __construct(
        private readonly AtlasSelfConstructionReservationRepository $reservations,
    ) {}


    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function reservationStatus(array $options = []): array
    {
        $ledger = $this->reservations->status();

        return [
            'schema_version' => 'atlas.self_construction_reservation_status.v1',
            'status' => 'reservation_ledger_ready',
            'mode' => 'durable_local_reservation_status',
            'execution_allowed' => false,
            'completion_allowed' => false,
            'claim_persisted' => false,
            'ledger_write_allowed' => false,
            'dispatch_allowed' => false,
            'ledger' => $ledger,
            'ledger_hash' => $this->stableHash($ledger),
            'human_summary' => 'Durable local reservation ledger is available: status can be read without claiming, dispatching or executing work.',
        ];
    }


    private ?ReadinessProjectionAgentReviewMergeSection $reviewMergeSection = null;

    private function reviewMergeSection(): ReadinessProjectionAgentReviewMergeSection
    {
        return $this->reviewMergeSection ??= new ReadinessProjectionAgentReviewMergeSection($this);
    }


    private function codexReviewMergeSection(): \App\Services\Ai\SelfConstruction\Readiness\ReadinessProjectionCodexReviewMergeSection
    {
        return $this->codexReviewMergeSection ??= new \App\Services\Ai\SelfConstruction\Readiness\ReadinessProjectionCodexReviewMergeSection($this);
    }

public function releasePacket(array $options = []): array
    {
        $packetId = (string) ($options['packet'] ?? '');
        $release = $this->reservations->release(
            packetId: $packetId,
            actor: $this->reservationActor($options),
            session: $this->reservationSession($options),
            reason: (string) ($options['reason'] ?? 'operator_released'),
        );

        return [
            'schema_version' => 'atlas.self_construction_release_packet.v1',
            'status' => data_get($release, 'status') === 'released' ? 'released' : 'blocked',
            'mode' => 'durable_local_packet_release',
            'execution_allowed' => false,
            'completion_allowed' => false,
            'claim_persisted' => false,
            'ledger_write_allowed' => true,
            'dispatch_allowed' => false,
            'release' => [
                ...$release,
                'packet_id' => $packetId,
            ],
            'release_hash' => $this->stableHash($release),
            'human_summary' => data_get($release, 'status') === 'released'
                ? 'Packet reservation was released in the local reservation ledger.'
                : 'Packet release was blocked because no active owner-matching reservation was found.',
        ];
    }



    /**
     * @return array<string, mixed>
     */
    private function terminalLoopOperationalProofPayloadFromJson(mixed $proof): array
    {
        return ReadinessTerminalLoopProofResolver::payloadFromJson($proof);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function withTerminalLoopOperationalProofPayload(array $options): array
    {
        return ReadinessTerminalLoopProofResolver::withPayload(
            $options,
            self::CANONICAL_OPERATOR_SUBMISSION_PATHS['terminal_loop_operational_proof_binding'],
        );
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, array<string, mixed>>
     */
    private function agentControlPlaneValidationGateRuntimeSyntheticPassInputs(array $context = []): array
    {
        $plan = (new AgentValidationGatePlanBuilder)->buildPlan($context);
        $inputs = [];
        foreach ((array) ($plan['ordered_runs'] ?? []) as $run) {
            $gateId = (string) ($run['gate_id'] ?? '');
            if ($gateId === '') {
                continue;
            }
            $inputs[$gateId] = [
                'status' => 'pass',
                'evidence_artifact' => (string) ($run['expected_artifact'] ?? 'synthetic_validation_evidence'),
            ];
        }

        return $inputs;
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: array<string, mixed>, 3: array<string, mixed>}
     */
    private function agentControlPlaneMergeReviewRuntimeCleanArgs(): array
    {
        return [
            [
                'source' => 'agent_control_plane_merge_review_runtime_synthetic_diff',
                'base_revision' => 'baseline-runtime-certification',
                'head_revision' => 'head-runtime-certification',
                'files' => [
                    ['path' => 'app/Services/Ai/SelfConstruction/SyntheticSlice.php', 'change_kind' => 'modified', 'lines_added' => 12, 'lines_deleted' => 4, 'hunk_count' => 3, 'content_hash' => str_repeat('a', 64)],
                    ['path' => 'tests/Feature/Ai/SelfConstruction/SyntheticSliceTest.php', 'change_kind' => 'added', 'lines_added' => 25, 'lines_deleted' => 0, 'hunk_count' => 1, 'content_hash' => str_repeat('b', 64)],
                ],
            ],
            ['artifacts' => [
                ['kind' => 'test', 'name' => 'focused-self-construction', 'status' => 'passed', 'evidence_hash' => str_repeat('c', 64)],
                ['kind' => 'lint', 'name' => 'php-lint', 'status' => 'passed', 'evidence_hash' => str_repeat('d', 64)],
            ]],
            ['allowed_files' => ['app/Services/Ai/SelfConstruction/', 'tests/Feature/Ai/SelfConstruction/']],
            ['packet_id' => 'merge-review-runtime-certification', 'claim_id' => 'claim-merge-review-runtime', 'task_packet_id' => 'task-merge-review-runtime', 'generated_at' => '2026-05-14T00:00:00+00:00'],
        ];
    }

    /**
     * @param  array<string, mixed>  $proof
     * @return array{requested: bool, status: string, path: string, absolute_path: string, hash: string, write_performed: bool, audit_command: string}
     */
    private function persistTerminalLoopOperationalProofBinding(array $proof, bool $persist): array
    {
        $path = self::CANONICAL_OPERATOR_SUBMISSION_PATHS['terminal_loop_operational_proof_binding'];
        $absolutePath = Storage::disk('local')->path($path);
        $auditCommand = 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-audit-status --agent-control-plane-terminal-loop-operational-proof-json=@'.$absolutePath.' --json';
        $bindingPacket = (array) data_get($proof, 'completion_audit_binding_packet', []);
        $json = (string) json_encode($bindingPacket, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if (! $persist) {
            return [
                'requested' => false,
                'status' => 'not_requested',
                'path' => $path,
                'absolute_path' => $absolutePath,
                'hash' => hash('sha256', $json),
                'write_performed' => false,
                'audit_command' => $auditCommand,
            ];
        }

        Storage::disk('local')->put($path, $json.PHP_EOL);

        return [
            'requested' => true,
            'status' => 'persisted',
            'path' => $path,
            'absolute_path' => $absolutePath,
            'hash' => hash('sha256', $json.PHP_EOL),
            'write_performed' => true,
            'audit_command' => $auditCommand,
        ];
    }

    private function buildTaskQueueOrchestrator(): AgentControlPlaneTaskQueueOrchestrator
    {
        return ReadinessAgentControlPlaneOrchestratorFactory::buildTaskQueueOrchestrator();
    }

    /**
     * Keep the public auto-replenishment status aware of final completion
     * blockers without letting a read-only status call manufacture worker
     * packets for human/provider-only evidence.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function taskAutoReplenishmentCompletionAuditContext(array $options): array
    {
        if (isset($options['completion_audit']) && is_array($options['completion_audit'])) {
            return $this->taskAutoReplenishmentCompletionAuditContextFromAudit((array) $options['completion_audit']);
        }

        try {
            $audit = (new AtlasSelfConstructionOsCompletionAuditService($this))->audit([
                'completion_receipt' => (array) ($options['completion_receipt'] ?? []),
                'real_provider_smoke' => (array) ($options['real_provider_smoke'] ?? []),
                'forge_self_improvement_smoke' => (array) ($options['forge_self_improvement_smoke'] ?? []),
                'agent_control_plane_terminal_loop_operational_proof' => (array) ($options['agent_control_plane_terminal_loop_operational_proof'] ?? []),
            ]);
        } catch (\Throwable $e) {
            return [
                'status' => 'completion_audit_context_unavailable',
                'failed_count' => 0,
                'failed_criteria' => [],
                'error' => $e->getMessage(),
            ];
        }

        return $this->taskAutoReplenishmentCompletionAuditContextFromAudit((array) $audit);
    }

    /**
     * @param  array<string, mixed>  $auditEnvelope
     * @return array<string, mixed>
     */
    private function taskAutoReplenishmentCompletionAuditContextFromAudit(array $auditEnvelope): array
    {
        $audit = $auditEnvelope;
        foreach ([
            'agent_control_plane_atlas_self_construction_os_completion_audit',
            'agent_control_plane_atlas_self_construction_os_completion_audit_status',
            'current_completion_audit',
            'completion_audit',
            'operator_handoff_packet.completion_audit',
        ] as $path) {
            $candidate = data_get($auditEnvelope, $path);
            if (is_array($candidate) && $candidate !== []) {
                $audit = (array) $candidate;
                break;
            }
        }

        $failedCriteria = array_values(array_filter(array_map('strval', (array) data_get($audit, 'failed_criteria', []))));
        foreach ((array) data_get($audit, 'failed_criteria_detailed', []) as $entry) {
            $id = (string) data_get($entry, 'id', '');
            if ($id !== '') {
                $failedCriteria[] = $id;
            }
        }
        foreach ([
            'current_blocks_completion_criteria',
            'operator_handoff_packet.current_blocks_completion_criteria',
            'blocker_classification.human_blockers',
            'blocker_classification.real_provider_blockers',
            'blocker_classification.technical_blockers',
        ] as $path) {
            foreach ((array) data_get($audit, $path, []) as $id) {
                $id = (string) $id;
                if ($id !== '') {
                    $failedCriteria[] = $id;
                }
            }
        }

        $operatorOnlyFailedCriteria = array_values(array_intersect(
            array_values(array_unique($failedCriteria)),
            [
                'runtime_gap_matrix_all_runtime_y',
                'human_signed_os_complete_receipt_present',
                'end_to_end_real_provider_smoke_green',
            ],
        ));

        return [
            'status' => (string) data_get($audit, 'status', 'unknown'),
            'failed_count' => count($operatorOnlyFailedCriteria),
            'failed_criteria' => $operatorOnlyFailedCriteria,
            'completion_audit_hash' => (string) data_get($audit, 'completion_audit_hash', ''),
            'context_scope' => 'operator_only_completion_blockers_for_auto_replenishment',
            'worker_task_creation_allowed_for_failed_criteria' => false,
        ];
    }

    private function buildTaskAutoReplenishmentService(): AgentControlPlaneTaskAutoReplenishmentService
    {
        return ReadinessAgentControlPlaneOrchestratorFactory::buildTaskAutoReplenishmentService();
    }

    /**
     * Task packet shape understood by the Agent Runtime Registry orchestrator.
     * Returns a registry-specific payload — distinct from defaultRuntimePilotInput()
     * because the registry consumes scalar workspace_policy, required_capabilities[]
     * and requires_lease.
     *
     * @return array<string, mixed>
     */
    private function defaultAgentRuntimeRegistryTaskPacket(): array
    {
        return ReadinessRuntimePilotInputFixtures::defaultAgentRuntimeRegistryTaskPacket();
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneAgentRuntimeRegistryStatus(array $options = []): array
    {
        $repo = new AgentRuntimeRegistryRepository;
        $registry = $repo->registry();
        $status = $repo->isAvailable() ? 'available' : 'blocked';

        return $this->wrapCertificationWorkbenchStatus(
            keyPrefix: 'agent_runtime_registry',
            label: 'Agent Runtime Registry',
            payload: array_merge($registry, ['status' => $status]),
            statusKey: 'status',
            extraStatusFields: [
                'storage_prefix' => (string) data_get($registry, 'storage_prefix'),
                'entry_count' => (int) data_get($registry, 'entry_count'),
                'total_count' => (int) data_get($registry, 'total_count'),
                'corrupt' => (bool) data_get($registry, 'corrupt', false),
                'registry_available' => $repo->isAvailable(),
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneAgentRuntimeRegistryCapabilityCatalogStatus(array $options = []): array
    {
        $catalog = (new AgentRuntimeRegistryCapabilityCatalog)->catalog();
        $status = (int) ($catalog['capability_count'] ?? 0) > 0 ? 'available' : 'blocked';

        return $this->wrapCertificationWorkbenchStatus(
            keyPrefix: 'agent_runtime_registry_capability_catalog',
            label: 'Agent Runtime Registry Capability Catalog',
            payload: array_merge($catalog, ['status' => $status]),
            statusKey: 'status',
            extraStatusFields: [
                'capability_count' => (int) data_get($catalog, 'capability_count', 0),
                'catalog_hash' => (string) data_get($catalog, 'catalog_hash', ''),
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneAgentRuntimeRegistryAvailabilityStatus(array $options = []): array
    {
        $planner = new AgentRuntimeRegistryAvailabilityPlanner;
        $plan = $planner->plan([], [], (array) ($options['planner_options'] ?? []));
        $available = method_exists($planner, 'plan');

        return $this->wrapCertificationWorkbenchStatus(
            keyPrefix: 'agent_runtime_registry_availability',
            label: 'Agent Runtime Registry Availability Planner',
            payload: array_merge($plan, ['status' => $available ? 'available' : 'blocked']),
            statusKey: 'status',
            extraStatusFields: [
                'default_ttl_seconds' => AgentRuntimeRegistryAvailabilityPlanner::DEFAULT_TTL_SECONDS,
                'available_agent_count' => count((array) data_get($plan, 'available_agents', [])),
                'planner_available' => $available,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneAgentRuntimeRegistryTaskMatcherStatus(array $options = []): array
    {
        $matcher = new AgentRuntimeRegistryTaskMatcher;
        $match = $matcher->match([], []);

        return $this->wrapCertificationWorkbenchStatus(
            keyPrefix: 'agent_runtime_registry_task_matcher',
            label: 'Agent Runtime Registry Task Matcher',
            payload: array_merge($match, ['status' => 'available']),
            statusKey: 'status',
            extraStatusFields: [
                'risk_levels' => AgentRuntimeRegistryTaskMatcher::RISK_LEVELS,
                'matcher_available' => true,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneAgentRuntimeRegistryLoadBalancingStatus(array $options = []): array
    {
        $policy = new AgentRuntimeRegistryLoadBalancingPolicy;
        $ranking = $policy->rank([]);

        return $this->wrapCertificationWorkbenchStatus(
            keyPrefix: 'agent_runtime_registry_load_balancing',
            label: 'Agent Runtime Registry Load Balancing Policy',
            payload: array_merge($ranking, ['status' => 'available']),
            statusKey: 'status',
            extraStatusFields: [
                'policies' => AgentRuntimeRegistryLoadBalancingPolicy::POLICIES,
                'policy_available' => true,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneAgentRuntimeRegistryQuarantineStatus(array $options = []): array
    {
        $repo = new AgentRuntimeRegistryQuarantineRepository;
        $available = $repo->isAvailable();
        $active = $repo->activeAgentIds();

        $payload = [
            'schema_version' => AgentRuntimeRegistryQuarantineRepository::SCHEMA_VERSION,
            'status' => $available ? 'available' : 'blocked',
            'storage_prefix' => AgentRuntimeRegistryQuarantineRepository::STORAGE_PREFIX,
            'quarantine_repository_available' => $available,
            'active_quarantine_count' => count($active),
            'reasons' => AgentRuntimeRegistryQuarantineRepository::REASONS,
            'runtime_safety' => $repo->runtimeFlags(),
        ];

        return $this->wrapCertificationWorkbenchStatus(
            keyPrefix: 'agent_runtime_registry_quarantine',
            label: 'Agent Runtime Registry Quarantine',
            payload: $payload,
            statusKey: 'status',
            extraStatusFields: [
                'storage_prefix' => (string) $payload['storage_prefix'],
                'active_quarantine_count' => (int) $payload['active_quarantine_count'],
                'quarantine_repository_available' => (bool) $payload['quarantine_repository_available'],
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneAgentRuntimeRegistryHandoffStatus(array $options = []): array
    {
        $builder = new AgentRuntimeRegistryHandoffProtocolBuilder;
        $protocol = $builder->build([], [], []);

        return $this->wrapCertificationWorkbenchStatus(
            keyPrefix: 'agent_runtime_registry_handoff',
            label: 'Agent Runtime Registry Handoff Protocol',
            payload: array_merge($protocol, ['status' => 'available']),
            statusKey: 'status',
            extraStatusFields: [
                'handoff_runtime_execution_allowed' => false,
                'builder_available' => true,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneAgentRuntimeRegistryOrchestratorStatus(array $options = []): array
    {
        $orchestrator = new AgentRuntimeRegistryOrchestrator;
        $taskPacket = (array) ($options['task_packet'] ?? $this->defaultAgentRuntimeRegistryTaskPacket());
        $plan = $orchestrator->planAssignment($taskPacket, (array) ($options['planner_options'] ?? []));

        return $this->wrapCertificationWorkbenchStatus(
            keyPrefix: 'agent_runtime_registry_orchestrator',
            label: 'Agent Runtime Registry Orchestrator',
            payload: array_merge($plan, ['status' => 'available']),
            statusKey: 'status',
            extraStatusFields: [
                'event' => (string) data_get($plan, 'event', 'plan_assignment'),
                'blocker_count' => count((array) data_get($plan, 'blockers', [])),
                'available_agent_count' => count((array) data_get($plan, 'availability_plan.available_agents', [])),
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneAgentRuntimeRegistryCertificationStatus(array $options = []): array
    {
        $svc = new AgentRuntimeRegistryCertificationService;
        $result = $svc->certify($options);

        return $this->wrapCertificationWorkbenchStatus(
            keyPrefix: 'agent_runtime_registry_certification',
            label: 'Agent Runtime Registry Certification',
            payload: $result,
            statusKey: 'status',
            extraStatusFields: [
                'invariants_all_true' => (bool) data_get($result, 'invariants_all_true', false),
                'violation_count' => (int) data_get($result, 'violation_count', 0),
                'warning_count' => (int) data_get($result, 'warning_count', 0),
                'certification_hash' => (string) data_get($result, 'certification_hash', ''),
                'runtime_safety_all_false' => (bool) data_get($result, 'runtime_safety.runtime_safety_all_false', false),
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function defaultRuntimePilotInput(): array
    {
        return ReadinessRuntimePilotInputFixtures::defaultRuntimePilotInput();
    }

    /**
     * @return array<string, mixed>
     */
    private function defaultRuntimePilotInputSecondary(): array
    {
        return ReadinessRuntimePilotInputFixtures::defaultRuntimePilotInputSecondary();
    }

    private function buildRuntimePilotOrchestrator(): AgentControlPlaneRuntimePilotOrchestrator
    {
        $audit = new AgentControlPlaneChainIntegrityAuditService($this);
        $replay = new AgentControlPlaneDeterministicChainReplayService($audit, $this);

        return new AgentControlPlaneRuntimePilotOrchestrator(
            new AgentControlPlaneTaskPacketBuilder,
            new AgentControlPlaneClaimLeaseSimulator,
            new AgentControlPlaneScopeLockPlanner,
            new AgentControlPlaneEvidenceLedgerDryRun,
            new AgentControlPlaneContinuationSummaryBuilder,
            new AgentControlPlaneWorkProductManifestPlanner,
            new AgentControlPlaneCostImportDryRun,
            new AgentControlPlaneMultiAgentParallelismPlanner,
            $audit,
            $replay,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function buildCertificationWorkbenchQuartet(string $keyPrefix, string $label, string $schemaVersion, string $serviceClass, string $stage): array
    {
        return ReadinessCertificationWorkbenchQuartetBuilder::build(
            $keyPrefix, $label, $schemaVersion, $serviceClass, $stage,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $extraStatusFields
     * @return array<string, mixed>
     */
    private function wrapCertificationWorkbenchStatus(string $keyPrefix, string $label, array $payload, string $statusKey, array $extraStatusFields, bool $runtimeWritePerformed = false): array
    {
        // GOD-DEBULK Fase 1 (A1-SC-0003): envelope construction is owned by
        // ReadinessEnvelopeProjector; routes that actually wrote durable state
        // pass $runtimeWritePerformed and stop claiming read_only.
        return ($this->envelopeProjector ??= new ReadinessEnvelopeProjector)->projectCertificationWorkbenchStatus(
            $keyPrefix,
            $label,
            $payload,
            $statusKey,
            $extraStatusFields,
            $runtimeWritePerformed,
        );
    }

    private function agentCodexSection(): \App\Services\Ai\SelfConstruction\Readiness\ReadinessProjectionAgentCodexSection
    {
        return $this->agentCodexSection ??= new \App\Services\Ai\SelfConstruction\Readiness\ReadinessProjectionAgentCodexSection(
            $this->agentDispatchProviderSection(),
        );
    }

    /**
     * @return list<string>
     */
    private function requiredDocs(): array
    {
        return ReadinessCatalog::requiredDocs();
    }

    /**
     * @return list<string>
     */
    private function receiptAllowedFiles(): array
    {
        return ReadinessCatalog::receiptAllowedFiles();
    }

    /**
     * @return list<string>
     */
    private function hotForbiddenFiles(): array
    {
        return ReadinessCatalog::hotForbiddenFiles();
    }

    /**
     * @param  list<string>  $paths
     */
    private function hasHotScope(array $paths): bool
    {
        return ReadinessPathPolicy::hasHotScope($paths);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null}  $options
     * @return array<string, mixed>|null
     */
    private function selectedSplitPacket(array $options): ?array
    {
        $packetId = $options['packet'] ?? null;
        if ($packetId === null || $packetId === '') {
            return null;
        }

        $splitter = $this->workSplitter([
            'workspace' => $options['workspace'] ?? null,
            'target' => $options['target'] ?? null,
        ]);

        $packet = collect((array) data_get($splitter, 'split.packets', []))->firstWhere('packet_id', $packetId);

        return is_array($packet) ? $packet : null;
    }

    private function packetCommand(string $option, mixed $packetId): string
    {
        return ReadinessCommandSurface::packetCommand($option, $packetId);
    }

    /**
     * @param  list<string>  $queueTags
     */
    private function queueTagCommandArgs(array $queueTags): string
    {
        return ReadinessCommandSurface::queueTagCommandArgs($queueTags);
    }

    private function safeCommandValue(string $value): string
    {
        return ReadinessCommandSurface::safeCommandValue($value);
    }

    /**
     * @param  array{actor?: string|null}  $options
     */
    private function reservationActor(array $options): string
    {
        return ReadinessCommandSurface::reservationActor($options);
    }

    /**
     * @param  array{session?: string|null}  $options
     */
    private function reservationSession(array $options): string
    {
        return ReadinessCommandSurface::reservationSession($options);
    }

    /**
     * @return array{provider: string, role: string, receives: string, best_for: list<string>}
     */
    private function providerRoleForActor(string $actor): array
    {
        return ReadinessCommandSurface::providerRoleForActor($actor);
    }

    private function agentControlPlaneRuntimeSchemaMigration(): string
    {
        return ReadinessAgentControlPlaneSchemaProbe::migration();
    }

    /**
     * @return array<string, bool>
     */
    private function agentControlPlaneRuntimeTables(): array
    {
        return ReadinessAgentControlPlaneSchemaProbe::tables();
    }

    /**
     * @param  array<string, bool>|null  $tables
     */
    private function agentControlPlaneRuntimeSchemaReady(?array $tables = null): bool
    {
        return ReadinessAgentControlPlaneSchemaProbe::schemaReady($tables);
    }

    /**
     * @return array<string, mixed>
     */
    private function agentWakeupItemPreview(AtlasSelfConstructionAgentWakeupItem $item): array
    {
        return [
            'wakeup_item_id' => $item->id,
            'wakeup_key' => $item->wakeup_key,
            'run_id' => $item->agent_run_id,
            'packet_id' => $item->packet_id,
            'actor' => $item->actor,
            'provider' => $item->provider,
            'reason' => $item->reason,
            'priority' => $item->priority,
            'status' => $item->status,
            'scheduled_for' => $item->scheduled_for?->toIso8601String(),
            'claimed_at' => $item->claimed_at?->toIso8601String(),
            'completed_at' => $item->completed_at?->toIso8601String(),
            'payload_hash' => is_array($item->payload) ? $this->stableHash($item->payload) : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $reservation
     * @return array<string, mixed>
     */
    private function syncAgentRunFromReservation(array $reservation): array
    {
        $actor = (string) data_get($reservation, 'actor');
        $providerRole = $this->providerRoleForActor($actor);
        $state = (string) data_get($reservation, 'state', 'claimed');
        $status = match ($state) {
            'completed' => 'succeeded',
            'released' => 'cancelled',
            default => 'running',
        };
        $leaseExpiresAt = (string) data_get($reservation, 'lease_expires_at');
        $expiresAt = strtotime($leaseExpiresAt);
        $liveness = match (true) {
            $status === 'succeeded' => 'completed',
            $status === 'cancelled' => 'released',
            $expiresAt !== false && $expiresAt > time() => 'active_lease',
            $expiresAt !== false => 'expired_lease',
            default => 'unknown',
        };
        $reservationId = (string) data_get($reservation, 'reservation_id');
        $packetId = (string) data_get($reservation, 'packet_id');
        $runKey = 'SELF-CONSTRUCTION-RUN-'.strtoupper(substr(hash('sha256', $reservationId.'|'.$packetId), 0, 24));

        $run = AtlasSelfConstructionAgentRun::query()->updateOrCreate(
            ['run_key' => $runKey],
            [
                'packet_id' => $packetId,
                'reservation_id' => $reservationId,
                'actor' => $actor,
                'provider' => (string) data_get($providerRole, 'provider', 'generic_ai_agent'),
                'provider_role' => (string) data_get($providerRole, 'role', 'implementation_worker'),
                'session_id' => (string) data_get($reservation, 'session'),
                'workspace_id' => 'FORGE-WORKSPACE-ATLAS-SELF-CONSTRUCTION-0001',
                'obra_id' => 'OBRA-ATLAS-SELF-CONSTRUCTION-OS',
                'status' => $status,
                'liveness' => $liveness,
                'packet_hash' => data_get($reservation, 'packet_hash'),
                'allowed_files_hash' => data_get($reservation, 'allowed_files_hash'),
                'lease_expires_at' => data_get($reservation, 'lease_expires_at'),
                'started_at' => data_get($reservation, 'claimed_at'),
                'finished_at' => data_get($reservation, 'completed_at') ?: data_get($reservation, 'released_at'),
                'completion_evidence_hash' => data_get($reservation, 'completion_evidence_hash'),
                'summary' => $status === 'succeeded'
                    ? 'Self-Construction packet completed from reservation ledger.'
                    : 'Self-Construction packet active from reservation ledger.',
                'metadata' => [
                    'source' => 'self_construction_reservation_ledger',
                    'reservation_state' => $state,
                    'release_reason' => data_get($reservation, 'release_reason'),
                    'completion_reason' => data_get($reservation, 'completion_reason'),
                ],
            ],
        );

        return [
            'run_id' => $run->id,
            'run_key' => $run->run_key,
            'packet_id' => $run->packet_id,
            'reservation_id' => $run->reservation_id,
            'actor' => $run->actor,
            'provider' => $run->provider,
            'session' => $run->session_id,
            'status' => $run->status,
            'liveness' => $run->liveness,
            'created' => $run->wasRecentlyCreated,
        ];
    }

    private function recommendedProviderForLane(string $lane): string
    {
        return ReadinessCommandSurface::recommendedProviderForLane($lane);
    }

    /**
     * @return list<string>
     */
    private function changedFiles(): array
    {
        return ReadinessPathPolicy::changedFiles();
    }

    /**
     * @param  list<string>  $allowed
     * @param  list<string>  $forbidden
     */
    private function classifyPath(string $path, array $allowed, array $forbidden): string
    {
        return ReadinessPathPolicy::classifyPath($path, $allowed, $forbidden);
    }

    /** @return array<string, mixed> */
    private function decodeJsonOption(mixed $value): array
    {
        return ReadinessJsonInput::decodeOption($value);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function completionEvidenceSubmissionInput(
        array $options,
        string $payloadKey,
        string $jsonKey,
        string $canonicalPath,
        bool $canonicalLoadAllowed,
    ): array {
        return ReadinessJsonInput::completionEvidenceSubmissionInput(
            $options,
            $payloadKey,
            $jsonKey,
            $canonicalPath,
            $canonicalLoadAllowed,
        );
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function completionEvidenceSubmissionInputSummary(array $input): array
    {
        return ReadinessJsonInput::completionEvidenceSubmissionInputSummary($input);
    }

    /**
     * @param  list<string>  $failedCriteria
     * @return array<string, mixed>
     */
    private function completionClaimAuthorityAliases(array $failedCriteria, string $currentRequiredOperatorArtifact): array
    {
        return ReadinessCompletionClaimAuthority::aliases($failedCriteria, $currentRequiredOperatorArtifact);
    }

    /** @param array<string, mixed> $value */
    private function ksortRecursive(array $value): array
    {
        return ReadinessHash::ksortRecursive($value);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function stableHash(array $payload): string
    {
        return ReadinessHash::stable($payload);
    }

    /**
     * @return list<string>
     */
    private function placeholderFieldsFromCommand(string $command): array
    {
        return ReadinessCommandPlaceholderExtractor::fieldsFromCommand($command);
    }

    private function agentDispatchProviderSection(): \App\Services\Ai\SelfConstruction\Readiness\ReadinessProjectionAgentDispatchProviderSection
    {
        return $this->agentDispatchProviderSection ??= (new \App\Services\Ai\SelfConstruction\Readiness\ReadinessProjectionAgentDispatchProviderSection(
            fn (array $payload): string => $this->stableHash($payload),
        ))->setMother($this);
    }



    private function agentControlPlaneSection(): \App\Services\Ai\SelfConstruction\Readiness\ReadinessProjectionAgentControlPlaneSection
    {
        return $this->agentControlPlaneSection ??= (new \App\Services\Ai\SelfConstruction\Readiness\ReadinessProjectionAgentControlPlaneSection())->setMother($this);
    }




    private function osEvidenceSection(): \App\Services\Ai\SelfConstruction\Readiness\ReadinessProjectionOsEvidenceSection
    {
        return $this->osEvidenceSection ??= (new \App\Services\Ai\SelfConstruction\Readiness\ReadinessProjectionOsEvidenceSection())->setMother($this);
    }

    private function agentWakeupSection(): \App\Services\Ai\SelfConstruction\Readiness\ReadinessProjectionAgentWakeupSection
    {
        return $this->agentWakeupSection ??= (new \App\Services\Ai\SelfConstruction\Readiness\ReadinessProjectionAgentWakeupSection())->setMother($this);
    }

    private ?ReadinessProjectionAgentLivenessSection $agentLivenessSection = null;

    private function agentLivenessSection(): ReadinessProjectionAgentLivenessSection
    {
        return $this->agentLivenessSection ??= (new ReadinessProjectionAgentLivenessSection())->setMother($this);
    }

    private ?ReadinessProjectionPacketLifecycleSection $packetLifecycleSection = null;

    private function packetLifecycleSection(): ReadinessProjectionPacketLifecycleSection
    {
        return $this->packetLifecycleSection ??= (new ReadinessProjectionPacketLifecycleSection())->setMother($this);
    }

    private ?ReadinessProjectionFinalCompletionGateSection $finalCompletionGateSection = null;

    private function finalCompletionGateSection(): ReadinessProjectionFinalCompletionGateSection
    {
        return $this->finalCompletionGateSection ??= (new ReadinessProjectionFinalCompletionGateSection())->setMother($this);
    }

    private ?ReadinessProjectionAgentControlPlaneReplaySection $agentControlPlaneReplaySection = null;

    private function agentControlPlaneReplaySection(): ReadinessProjectionAgentControlPlaneReplaySection
    {
        return $this->agentControlPlaneReplaySection ??= (new ReadinessProjectionAgentControlPlaneReplaySection())->setMother($this);
    }

    private ?ReadinessProjectionOsCompletionEvidenceSection $osCompletionEvidenceSection = null;

    private function osCompletionEvidenceSection(): ReadinessProjectionOsCompletionEvidenceSection
    {
        return $this->osCompletionEvidenceSection ??= (new ReadinessProjectionOsCompletionEvidenceSection())->setMother($this);
    }

    private ?ReadinessProjectionAgentControlPlaneTaskLeaseSection $agentControlPlaneTaskLeaseSection = null;

    private function agentControlPlaneTaskLeaseSection(): ReadinessProjectionAgentControlPlaneTaskLeaseSection
    {
        return $this->agentControlPlaneTaskLeaseSection ??= (new ReadinessProjectionAgentControlPlaneTaskLeaseSection())->setMother($this);
    }

    private ?ReadinessProjectionPacketQueuePolicySection $packetQueuePolicySection = null;

    private function packetQueuePolicySection(): ReadinessProjectionPacketQueuePolicySection
    {
        return $this->packetQueuePolicySection ??= (new ReadinessProjectionPacketQueuePolicySection())->setMother($this);
    }

    private ?ReadinessProjectionAgentControlPlaneCertificationSection $agentControlPlaneCertificationSection = null;

    private function agentControlPlaneCertificationSection(): ReadinessProjectionAgentControlPlaneCertificationSection
    {
        return $this->agentControlPlaneCertificationSection ??= (new ReadinessProjectionAgentControlPlaneCertificationSection())->setMother($this);
    }

    private ?ReadinessProjectionAgentControlPlaneRuntimeStatusPart1Section $agentControlPlaneRuntimeStatusPart1Section = null;

    private function agentControlPlaneRuntimeStatusPart1Section(): ReadinessProjectionAgentControlPlaneRuntimeStatusPart1Section
    {
        return $this->agentControlPlaneRuntimeStatusPart1Section ??= (new ReadinessProjectionAgentControlPlaneRuntimeStatusPart1Section())->setMother($this);
    }

    private ?ReadinessProjectionAgentControlPlaneRuntimeStatusPart2Section $agentControlPlaneRuntimeStatusPart2Section = null;

    private function agentControlPlaneRuntimeStatusPart2Section(): ReadinessProjectionAgentControlPlaneRuntimeStatusPart2Section
    {
        return $this->agentControlPlaneRuntimeStatusPart2Section ??= (new ReadinessProjectionAgentControlPlaneRuntimeStatusPart2Section())->setMother($this);
    }

    private ?ReadinessProjectionOperatorEvidenceDraftSection $operatorEvidenceDraftSection = null;

    private function operatorEvidenceDraftSection(): ReadinessProjectionOperatorEvidenceDraftSection
    {
        return $this->operatorEvidenceDraftSection ??= (new ReadinessProjectionOperatorEvidenceDraftSection())->setMother($this);
    }

    private function dispatchGateSection(): \App\Services\Ai\SelfConstruction\Readiness\ReadinessProjectionDispatchGateSection
    {
        return $this->dispatchGateSection ??= (new \App\Services\Ai\SelfConstruction\Readiness\ReadinessProjectionDispatchGateSection())->setMother($this);
    }

    private function mutatingWriterSection(): \App\Services\Ai\SelfConstruction\Readiness\ReadinessProjectionMutatingWriterSection
    {
        return $this->mutatingWriterSection ??= (new \App\Services\Ai\SelfConstruction\Readiness\ReadinessProjectionMutatingWriterSection())->setMother($this);
    }

    private function releaseWriterSection(): \App\Services\Ai\SelfConstruction\Readiness\ReadinessProjectionReleaseWriterSection
    {
        return $this->releaseWriterSection ??= (new \App\Services\Ai\SelfConstruction\Readiness\ReadinessProjectionReleaseWriterSection())->setMother($this);
    }

    private function terminalLoopSection(): \App\Services\Ai\SelfConstruction\Readiness\ReadinessProjectionTerminalLoopSection
    {
        return $this->terminalLoopSection ??= (new \App\Services\Ai\SelfConstruction\Readiness\ReadinessProjectionTerminalLoopSection())->setMother($this);
    }

    private function postStartGateBSection(): \App\Services\Ai\SelfConstruction\Readiness\ReadinessProjectionPostStartGateBSection
    {
        return $this->postStartGateBSection ??= (new \App\Services\Ai\SelfConstruction\Readiness\ReadinessProjectionPostStartGateBSection())->setMother($this);
    }

    private function postStartGateStatusSection(): \App\Services\Ai\SelfConstruction\Readiness\ReadinessProjectionPostStartGateStatusSection
    {
        return $this->postStartGateStatusSection ??= (new \App\Services\Ai\SelfConstruction\Readiness\ReadinessProjectionPostStartGateStatusSection())->setMother($this);
    }

    private function ownershipBoundarySection(): \App\Services\Ai\SelfConstruction\Readiness\ReadinessProjectionOwnershipBoundarySection
    {
        return $this->ownershipBoundarySection ??= (new \App\Services\Ai\SelfConstruction\Readiness\ReadinessProjectionOwnershipBoundarySection())->setMother($this);
    }

    private function agentAutomaticDispatchBatch1Section(): \App\Services\Ai\SelfConstruction\Readiness\ReadinessProjectionAgentAutomaticDispatchBatch1Section
    {
        return $this->agentAutomaticDispatchBatch1Section ??= (new \App\Services\Ai\SelfConstruction\Readiness\ReadinessProjectionAgentAutomaticDispatchBatch1Section())->setMother($this);
    }

    private function agentAutomaticDispatchBatch2Section(): \App\Services\Ai\SelfConstruction\Readiness\ReadinessProjectionAgentAutomaticDispatchBatch2Section
    {
        return $this->agentAutomaticDispatchBatch2Section ??= (new \App\Services\Ai\SelfConstruction\Readiness\ReadinessProjectionAgentAutomaticDispatchBatch2Section())->setMother($this);
    }
    private function forgeWorkspaceSection(): \App\Services\Ai\SelfConstruction\Readiness\ReadinessProjectionForgeWorkspaceSection
    {
        return $this->forgeWorkspaceSection ??= (new \App\Services\Ai\SelfConstruction\Readiness\ReadinessProjectionForgeWorkspaceSection())->setMother($this);
    }


    private function parallelSessionSection(): \App\Services\Ai\SelfConstruction\Readiness\ReadinessProjectionParallelSessionSection
    {
        return $this->parallelSessionSection ??= (new \App\Services\Ai\SelfConstruction\Readiness\ReadinessProjectionParallelSessionSection())->setMother($this);
    }

    private function surfaceMatrixSection(): \App\Services\Ai\SelfConstruction\Readiness\ReadinessProjectionSurfaceMatrixSection
    {
        return $this->surfaceMatrixSection ??= (new \App\Services\Ai\SelfConstruction\Readiness\ReadinessProjectionSurfaceMatrixSection())->setMother($this);
    }

    private function durableReservationSection(): \App\Services\Ai\SelfConstruction\Readiness\ReadinessProjectionDurableReservationSection
    {
        return $this->durableReservationSection ??= (new \App\Services\Ai\SelfConstruction\Readiness\ReadinessProjectionDurableReservationSection(
            fn (array $payload): string => $this->stableHash($payload),
        ))->setMother($this);
    }


    private function runtimePromotionSection(): \App\Services\Ai\SelfConstruction\Readiness\ReadinessProjectionRuntimePromotionSection
    {
        return $this->runtimePromotionSection ??= (new \App\Services\Ai\SelfConstruction\Readiness\ReadinessProjectionRuntimePromotionSection(
            fn (array $payload): string => $this->stableHash($payload),
        ))->setMother($this);
    }


    private function realProviderSmokeSection(): \App\Services\Ai\SelfConstruction\Readiness\ReadinessProjectionRealProviderSmokeSection
    {
        return $this->realProviderSmokeSection ??= (new \App\Services\Ai\SelfConstruction\Readiness\ReadinessProjectionRealProviderSmokeSection(
            fn (array $payload): string => $this->stableHash($payload),
        ))->setMother($this);
    }


    private function humanCompletionReceiptSection(): \App\Services\Ai\SelfConstruction\Readiness\ReadinessProjectionHumanCompletionReceiptSection
    {
        return $this->humanCompletionReceiptSection ??= (new \App\Services\Ai\SelfConstruction\Readiness\ReadinessProjectionHumanCompletionReceiptSection(
            fn (array $payload): string => $this->stableHash($payload),
        ))->setMother($this);
    }

    /**
     * Fail-closed go/replenish/stop signal for keeping muscles fed without padding: turns real
     * queue_health facts and active worker counts into autonomous_os_runway rather than a raw
     * quota check. Pure — no I/O, callers supply already-gathered facts.
     *
     * Priority (first match wins):
     *   1. stop_and_repair — any malformed, recoverable, or lease-leak signal is present; these
     *      are concrete blockers that must clear before origination continues.
     *   2. replenish_now   — no blockers, but claimable_per_active_worker is at/below the floor;
     *      servable_now alone is never enough, it must be enough PER active worker.
     *   3. ready           — no blockers and claimable_per_active_worker clears the floor.
     *
     * @param  array{
     *   queue_health?: array{servable_now?: int, malformed_count?: int, recoverable_count?: int, lease_leak_count?: int},
     *   active_worker_count?: int,
     *   floor_per_worker?: float,
     * }  $facts
     * @return array{schema:string, runway_status:string, servable_now:int, active_worker_count:int, claimable_per_active_worker:float, floor_per_worker:float, blockers:list<string>}
     */
    public function autonomousOsRunway(array $facts): array
    {
        $queueHealth = (array) ($facts['queue_health'] ?? []);
        $servableNow = max(0, (int) ($queueHealth['servable_now'] ?? 0));
        $malformedCount = max(0, (int) ($queueHealth['malformed_count'] ?? 0));
        $recoverableCount = max(0, (int) ($queueHealth['recoverable_count'] ?? 0));
        $leaseLeakCount = max(0, (int) ($queueHealth['lease_leak_count'] ?? 0));
        $activeWorkerCount = max(0, (int) ($facts['active_worker_count'] ?? 0));
        $floorPerWorker = max(0.0, (float) ($facts['floor_per_worker'] ?? 2.0));

        $claimablePerActiveWorker = $activeWorkerCount > 0
            ? round($servableNow / $activeWorkerCount, 4)
            : (float) $servableNow;

        $blockers = [];
        if ($malformedCount > 0) {
            $blockers[] = "malformed_tasks:{$malformedCount}";
        }
        if ($recoverableCount > 0) {
            $blockers[] = "recoverable_tasks_pending_recovery:{$recoverableCount}";
        }
        if ($leaseLeakCount > 0) {
            $blockers[] = "lease_leak_detected:{$leaseLeakCount}";
        }

        $runwayStatus = match (true) {
            $blockers !== [] => 'stop_and_repair',
            $claimablePerActiveWorker <= $floorPerWorker => 'replenish_now',
            default => 'ready',
        };

        return [
            'schema' => 'atlas.self_construction.autonomous_os_runway.v1',
            'runway_status' => $runwayStatus,
            'servable_now' => $servableNow,
            'active_worker_count' => $activeWorkerCount,
            'claimable_per_active_worker' => $claimablePerActiveWorker,
            'floor_per_worker' => $floorPerWorker,
            'blockers' => $blockers,
        ];
    }


    private function oneShotTickCodexPart1Section(): ReadinessProjectionOneShotTickCodexPart1Section
    {
        return $this->oneShotTickCodexPart1Section ??= new ReadinessProjectionOneShotTickCodexPart1Section($this);
    }

    private function oneShotTickCodexPart2Section(): ReadinessProjectionOneShotTickCodexPart2Section
    {
        return $this->oneShotTickCodexPart2Section ??= new ReadinessProjectionOneShotTickCodexPart2Section($this);
    }

    private function oneShotTickCodexPart3Section(): ReadinessProjectionOneShotTickCodexPart3Section
    {
        return $this->oneShotTickCodexPart3Section ??= new ReadinessProjectionOneShotTickCodexPart3Section($this);
    }

    private function oneShotTickCodexPart4Section(): ReadinessProjectionOneShotTickCodexPart4Section
    {
        return $this->oneShotTickCodexPart4Section ??= new ReadinessProjectionOneShotTickCodexPart4Section($this);
    }

    private function oneShotTickCodexPart5Section(): ReadinessProjectionOneShotTickCodexPart5Section
    {
        return $this->oneShotTickCodexPart5Section ??= new ReadinessProjectionOneShotTickCodexPart5Section($this);
    }

    private function oneShotTickCodexPart6Section(): ReadinessProjectionOneShotTickCodexPart6Section
    {
        return $this->oneShotTickCodexPart6Section ??= new ReadinessProjectionOneShotTickCodexPart6Section($this);
    }

    private function oneShotTickCodexPart7Section(): ReadinessProjectionOneShotTickCodexPart7Section
    {
        return $this->oneShotTickCodexPart7Section ??= new ReadinessProjectionOneShotTickCodexPart7Section($this);
    }

    private function oneShotTickCodexPart8Section(): ReadinessProjectionOneShotTickCodexPart8Section
    {
        return $this->oneShotTickCodexPart8Section ??= new ReadinessProjectionOneShotTickCodexPart8Section($this);
    }

    private function oneShotTickCodexPart9Section(): ReadinessProjectionOneShotTickCodexPart9Section
    {
        return $this->oneShotTickCodexPart9Section ??= new ReadinessProjectionOneShotTickCodexPart9Section($this);
    }

    private function oneShotTickCodexPart10Section(): ReadinessProjectionOneShotTickCodexPart10Section
    {
        return $this->oneShotTickCodexPart10Section ??= new ReadinessProjectionOneShotTickCodexPart10Section($this);
    }

    private function agentAutomaticTailPart1Section(): ReadinessProjectionAgentAutomaticTailPart1Section
    {
        return $this->agentAutomaticTailPart1Section ??= new ReadinessProjectionAgentAutomaticTailPart1Section($this);
    }

    private function agentAutomaticTailPart2Section(): ReadinessProjectionAgentAutomaticTailPart2Section
    {
        return $this->agentAutomaticTailPart2Section ??= new ReadinessProjectionAgentAutomaticTailPart2Section($this);
    }

    private function codexProjectionSection(): ReadinessProjectionCodexProjectionSection
    {
        return $this->codexProjectionSection ??= new ReadinessProjectionCodexProjectionSection($this);
    }

    private function miscProjectionsPart1Section(): ReadinessProjectionMiscProjectionsPart1Section
    {
        return $this->miscProjectionsPart1Section ??= new ReadinessProjectionMiscProjectionsPart1Section($this);
    }

    private function miscProjectionsPart2Section(): ReadinessProjectionMiscProjectionsPart2Section
    {
        return $this->miscProjectionsPart2Section ??= new ReadinessProjectionMiscProjectionsPart2Section($this);
    }

    private function miscProjectionsPart3Section(): ReadinessProjectionMiscProjectionsPart3Section
    {
        return $this->miscProjectionsPart3Section ??= new ReadinessProjectionMiscProjectionsPart3Section($this);
    }
}
