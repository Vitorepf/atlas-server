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
     * @param  array{workspace?: string|null}  $options
     * @return array<string, mixed>
     */
    public function snapshot(array $options = []): array
    {
        return app(ReadinessStatusProjection::class)->snapshot(
            $options,
            fn (): array => $this->requiredDocs(),
        );
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null}  $options
     * @return array<string, mixed>
     */
    public function metaSddPacket(array $options = []): array
    {
        return app(ReadinessPacketProjection::class)->metaSddPacket(
            $options,
            $this->snapshot($options),
        );
    }

    public function receiptPreview(array $options = []): array
    {
        return $this->packetLifecycleSection()->receiptPreview($options);
    }

    public function traceabilityAudit(array $options = []): array
    {
        return $this->miscProjectionsPart1Section()->traceabilityAudit($options);
    }

    public function promotionGate(array $options = []): array
    {
        return $this->miscProjectionsPart1Section()->promotionGate($options);
    }

    public function executionCandidate(array $options = []): array
    {
        return $this->miscProjectionsPart1Section()->executionCandidate($options);
    }

    public function approvalPacket(array $options = []): array
    {
        return $this->miscProjectionsPart1Section()->approvalPacket($options);
    }

    public function receiptDraft(array $options = []): array
    {
        return $this->miscProjectionsPart1Section()->receiptDraft($options);
    }

    public function executionPreflight(array $options = []): array
    {
        return $this->miscProjectionsPart1Section()->executionPreflight($options);
    }

    public function signatureRequest(array $options = []): array
    {
        return $this->miscProjectionsPart1Section()->signatureRequest($options);
    }

    public function executionRunbook(array $options = []): array
    {
        return $this->miscProjectionsPart1Section()->executionRunbook($options);
    }

    public function evidencePacket(array $options = []): array
    {
        return $this->miscProjectionsPart1Section()->evidencePacket($options);
    }

    public function completionReadiness(array $options = []): array
    {
        return $this->miscProjectionsPart1Section()->completionReadiness($options);
    }

    public function residualRisk(array $options = []): array
    {
        return $this->miscProjectionsPart1Section()->residualRisk($options);
    }

    public function handoffPacket(array $options = []): array
    {
        return $this->miscProjectionsPart1Section()->handoffPacket($options);
    }

    public function nextAction(array $options = []): array
    {
        return $this->miscProjectionsPart2Section()->nextAction($options);
    }
    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function surfaceMatrix(array $options = []): array
    {
        return $this->surfaceMatrixSection()->surfaceMatrix($options);
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null}  $options
     * @return array<string, mixed>
     */
    public function externalBlockers(array $options = []): array
    {
        return $this->packetLifecycleSection()->externalBlockers($options);
    }

    public function phaseLedger(array $options = []): array
    {
        return $this->miscProjectionsPart2Section()->phaseLedger($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null}  $options
     * @return array<string, mixed>
     */
    public function implementationPacket(array $options = []): array
    {
        return $this->packetLifecycleSection()->implementationPacket($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null}  $options
     * @return array<string, mixed>
     */
    public function workSplitter(array $options = []) : array
    {
        return $this->releaseWriterSection()->workSplitter($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null}  $options
     * @return array<string, mixed>
     */
    public function scopeValidator(array $options = []): array
    {
        return $this->packetLifecycleSection()->scopeValidator($options);
    }

    public function assignmentPreview(array $options = []): array
    {
        return $this->miscProjectionsPart2Section()->assignmentPreview($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null}  $options
     * @return array<string, mixed>
     */
    public function packetRunbook(array $options = []): array
    {
        return $this->packetLifecycleSection()->packetRunbook($options);
    }

    public function packetEvidenceReport(array $options = []): array
    {
        return $this->miscProjectionsPart2Section()->packetEvidenceReport($options);
    }

    public function packetCompletionGate(array $options = []): array
    {
        return $this->miscProjectionsPart2Section()->packetCompletionGate($options);
    }

    public function reservationLedgerPreview(array $options = []): array
    {
        return $this->miscProjectionsPart2Section()->reservationLedgerPreview($options);
    }

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

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function claimPacket(array $options = []): array
    {
        return $this->packetLifecycleSection()->claimPacket($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function claimNextPacket(array $options = []): array
    {
        return $this->packetLifecycleSection()->claimNextPacket($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function codexStartPacket(array $options = []): array
    {
        return $this->packetLifecycleSection()->codexStartPacket($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentStartPacket(array $options = []) : array
    {
        return $this->agentWakeupSection()->agentStartPacket($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentLaunchPlan(array $options = []): array
    {
        return $this->packetLifecycleSection()->agentLaunchPlan($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentExecutionStatus(array $options = []): array
    {
        return $this->packetLifecycleSection()->agentExecutionStatus($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentIntegrationReport(array $options = []) : array
    {
        return $this->agentWakeupSection()->agentIntegrationReport($options);
    }

    public function agentMergeReadiness(array $options = []): array
    {
        return $this->miscProjectionsPart2Section()->agentMergeReadiness($options);
    }

    public function agentFinalReviewPacket(array $options = []): array
    {
        return $this->miscProjectionsPart2Section()->agentFinalReviewPacket($options);
    }

    public function agentReviewDecisionTemplate(array $options = []): array
    {
        return $this->miscProjectionsPart2Section()->agentReviewDecisionTemplate($options);
    }

    public function agentReviewReceiptDraft(array $options = []): array
    {
        return $this->miscProjectionsPart2Section()->agentReviewReceiptDraft($options);
    }

    public function agentReviewSignatureRequest(array $options = []): array
    {
        return $this->miscProjectionsPart2Section()->agentReviewSignatureRequest($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewPostSignatureRunbook(array $options = []) : array
    {
        return $this->ownershipBoundarySection()->agentReviewPostSignatureRunbook($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergeActionTemplate(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergeActionTemplate($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePreflight(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergePreflight($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergeActionDraft(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergeActionDraft($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergeReceiptDraft(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergeReceiptDraft($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergeSignatureRequest(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergeSignatureRequest($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostSignatureRunbook(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergePostSignatureRunbook($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergeExecutionChecklist(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergeExecutionChecklist($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergeAuthorizationTemplate(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergeAuthorizationTemplate($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergeAuthorizationReceiptDraft(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergeAuthorizationReceiptDraft($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergeAuthorizationSignatureRequest(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergeAuthorizationSignatureRequest($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergeAuthorizationPostSignatureRunbook(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergeAuthorizationPostSignatureRunbook($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergeFinalAuthorizationPreflight(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergeFinalAuthorizationPreflight($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergeAuthorizingActionTemplate(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergeAuthorizingActionTemplate($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergeFinalReceiptDraft(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergeFinalReceiptDraft($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergeFinalSignatureRequest(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergeFinalSignatureRequest($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergeFinalPostSignatureRunbook(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergeFinalPostSignatureRunbook($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergeSignedFinalReceiptTemplate(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergeSignedFinalReceiptTemplate($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergeSignedFinalReceiptPreflight(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergeSignedFinalReceiptPreflight($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergeSignedFinalReceiptPersistenceTemplate(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergeSignedFinalReceiptPersistenceTemplate($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergeExecutorReleasePreflight(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergeExecutorReleasePreflight($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergeExecutorContractTemplate(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergeExecutorContractTemplate($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergeExecutionReceiptTemplate(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergeExecutionReceiptTemplate($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionPreflight(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergePostExecutionPreflight($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionTemplate(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergePostExecutionActionTemplate($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionReceiptDraft(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergePostExecutionActionReceiptDraft($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignatureRequest(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergePostExecutionActionSignatureRequest($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionPostSignatureRunbook(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergePostExecutionActionPostSignatureRunbook($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptTemplate(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergePostExecutionActionSignedReceiptTemplate($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPreflight(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergePostExecutionActionSignedReceiptPreflight($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceTemplate(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergePostExecutionActionSignedReceiptPersistenceTemplate($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceReceiptDraft(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergePostExecutionActionSignedReceiptPersistenceReceiptDraft($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistencePreflight(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergePostExecutionActionSignedReceiptPersistencePreflight($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistencePostPreflightRunbook(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergePostExecutionActionSignedReceiptPersistencePostPreflightRunbook($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceAppendOnlyEventPayloadTemplate(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergePostExecutionActionSignedReceiptPersistenceAppendOnlyEventPayloadTemplate($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterPreflight(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterPreflight($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterContractTemplate(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterContractTemplate($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterImplementationPreflight(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterImplementationPreflight($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseAuthorizationTemplate(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseAuthorizationTemplate($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseAuthorizationPreflight(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseAuthorizationPreflight($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseAuthorizationReceiptDraft(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseAuthorizationReceiptDraft($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseAuthorizationSignatureRequest(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseAuthorizationSignatureRequest($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseAuthorizationPostSignatureRunbook(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseAuthorizationPostSignatureRunbook($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseAuthorizationSignedReceiptTemplate(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseAuthorizationSignedReceiptTemplate($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleasePreflight(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleasePreflight($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseReceiptDraft(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseReceiptDraft($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseSignatureRequest(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseSignatureRequest($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleasePostSignatureRunbook(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleasePostSignatureRunbook($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseSignedReceiptTemplate(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseSignedReceiptTemplate($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseExecutionContractPreflight(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseExecutionContractPreflight($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseExecutionContractTemplate(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseExecutionContractTemplate($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseDisableContractTemplate(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseDisableContractTemplate($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseObservabilityContractTemplate(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseObservabilityContractTemplate($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleasePostMonitoringReviewTemplate(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleasePostMonitoringReviewTemplate($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseReenableReviewPacketTemplate(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseReenableReviewPacketTemplate($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationRequestTemplate(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationRequestTemplate($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationReceiptDraftTemplate(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationReceiptDraftTemplate($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationSignatureRequestTemplate(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationSignatureRequestTemplate($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationPostSignatureRunbookTemplate(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationPostSignatureRunbookTemplate($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationSignedReceiptTemplate(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationSignedReceiptTemplate($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationExecutionContractPreflightTemplate(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationExecutionContractPreflightTemplate($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationExecutionContractTemplate(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationExecutionContractTemplate($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationDisableContractTemplate(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationDisableContractTemplate($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationObservabilityContractTemplate(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationObservabilityContractTemplate($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationPostMonitoringReviewTemplate(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationPostMonitoringReviewTemplate($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationHealthDecisionTemplate(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationHealthDecisionTemplate($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationDisableRequestTemplate(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationDisableRequestTemplate($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleRequestTemplate(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleRequestTemplate($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleAuthorizationRequestTemplate(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleAuthorizationRequestTemplate($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleReceiptDraftTemplate(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleReceiptDraftTemplate($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleSignatureRequestTemplate(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleSignatureRequestTemplate($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCyclePostSignatureRunbookTemplate(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCyclePostSignatureRunbookTemplate($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleSignedReceiptTemplate(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleSignedReceiptTemplate($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleExecutionContractPreflightTemplate(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleExecutionContractPreflightTemplate($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleExecutionContractTemplate(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleExecutionContractTemplate($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableContractTemplate(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableContractTemplate($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleObservabilityContractTemplate(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleObservabilityContractTemplate($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCyclePostMonitoringReviewTemplate(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCyclePostMonitoringReviewTemplate($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleHealthDecisionTemplate(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleHealthDecisionTemplate($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableRequestTemplate(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableRequestTemplate($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionPreflightTemplate(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionPreflightTemplate($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionReceiptDraftTemplate(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionReceiptDraftTemplate($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionSignedReceiptTemplate(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionSignedReceiptTemplate($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionPersistencePreflightTemplate(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionPersistencePreflightTemplate($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionPersistenceReceiptTemplate(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionPersistenceReceiptTemplate($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionPostPersistenceReviewTemplate(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionPostPersistenceReviewTemplate($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionFollowUpObservabilityTemplate(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionFollowUpObservabilityTemplate($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionEvidenceRepairRequestTemplate(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionEvidenceRepairRequestTemplate($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionRepairedEvidencePacketTemplate(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionRepairedEvidencePacketTemplate($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionRepairReviewTemplate(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionRepairReviewTemplate($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionRepairOutcomePacketTemplate(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionRepairOutcomePacketTemplate($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleRequestTemplate(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleRequestTemplate($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCyclePreflightTemplate(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCyclePreflightTemplate($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationRequestTemplate(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationRequestTemplate($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationReceiptDraftTemplate(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationReceiptDraftTemplate($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationSignatureRequestTemplate(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationSignatureRequestTemplate($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationPostSignatureRunbookTemplate(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationPostSignatureRunbookTemplate($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationSignatureValidationReportTemplate(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationSignatureValidationReportTemplate($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationSignedReceiptTemplate(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationSignedReceiptTemplate($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationSignedReceiptPreflightTemplate(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationSignedReceiptPreflightTemplate($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationPersistencePreflightTemplate(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationPersistencePreflightTemplate($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationPersistenceReceiptTemplate(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationPersistenceReceiptTemplate($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationPostPersistenceReviewTemplate(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationPostPersistenceReviewTemplate($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationFollowUpObservabilityTemplate(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationFollowUpObservabilityTemplate($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationEvidenceRepairRequestTemplate(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationEvidenceRepairRequestTemplate($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationRepairedEvidencePacketTemplate(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationRepairedEvidencePacketTemplate($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationRepairReviewTemplate(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationRepairReviewTemplate($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationPersistenceRejectionTemplate(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationPersistenceRejectionTemplate($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationHumanEscalationTemplate(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationHumanEscalationTemplate($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationManualDecisionRequestTemplate(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationManualDecisionRequestTemplate($options);
    }


    private ?ReadinessProjectionAgentReviewMergeSection $reviewMergeSection = null;

    private function reviewMergeSection(): ReadinessProjectionAgentReviewMergeSection
    {
        return $this->reviewMergeSection ??= new ReadinessProjectionAgentReviewMergeSection($this);
    }


    public function codexLaunchPlan(array $options = []): array
    {
        return $this->codexProjectionSection()->codexLaunchPlan($options);
    }

    public function codexExecutionStatus(array $options = []): array
    {
        return $this->codexProjectionSection()->codexExecutionStatus($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function codexIntegrationReport(array $options = []) : array
    {
        return $this->releaseWriterSection()->codexIntegrationReport($options);
    }

    public function codexMergeReadiness(array $options = []): array
    {
        return $this->codexProjectionSection()->codexMergeReadiness($options);
    }

    public function codexFinalReviewPacket(array $options = []): array
    {
        return $this->codexProjectionSection()->codexFinalReviewPacket($options);
    }

    public function codexReviewDecisionTemplate(array $options = []): array
    {
        return $this->codexProjectionSection()->codexReviewDecisionTemplate($options);
    }

    public function codexReviewReceiptDraft(array $options = []): array
    {
        return $this->codexProjectionSection()->codexReviewReceiptDraft($options);
    }

    public function codexReviewSignatureRequest(array $options = []): array
    {
        return $this->codexProjectionSection()->codexReviewSignatureRequest($options);
    }

    public function codexReviewPostSignatureRunbook(array $options = []): array
    {
        return $this->codexProjectionSection()->codexReviewPostSignatureRunbook($options);
    }

    public function codexReviewMergeActionTemplate(array $options = []): array
    {
        return $this->codexReviewMergeSection()->codexReviewMergeActionTemplate($options);
    }

    public function codexReviewMergePreflight(array $options = []): array
    {
        return $this->codexReviewMergeSection()->codexReviewMergePreflight($options);
    }

    public function codexReviewMergeActionDraft(array $options = []): array
    {
        return $this->codexReviewMergeSection()->codexReviewMergeActionDraft($options);
    }

    public function codexReviewMergeReceiptDraft(array $options = []): array
    {
        return $this->codexReviewMergeSection()->codexReviewMergeReceiptDraft($options);
    }

    public function codexReviewMergeSignatureRequest(array $options = []): array
    {
        return $this->codexReviewMergeSection()->codexReviewMergeSignatureRequest($options);
    }

    public function codexReviewMergePostSignatureRunbook(array $options = []): array
    {
        return $this->codexReviewMergeSection()->codexReviewMergePostSignatureRunbook($options);
    }

    public function codexReviewMergeExecutionChecklist(array $options = []): array
    {
        return $this->codexReviewMergeSection()->codexReviewMergeExecutionChecklist($options);
    }

    public function codexReviewMergeAuthorizationTemplate(array $options = []): array
    {
        return $this->codexReviewMergeSection()->codexReviewMergeAuthorizationTemplate($options);
    }

    public function codexReviewMergeAuthorizationReceiptDraft(array $options = []): array
    {
        return $this->codexReviewMergeSection()->codexReviewMergeAuthorizationReceiptDraft($options);
    }

    public function codexReviewMergeAuthorizationSignatureRequest(array $options = []): array
    {
        return $this->codexReviewMergeSection()->codexReviewMergeAuthorizationSignatureRequest($options);
    }

    public function codexReviewMergeAuthorizationPostSignatureRunbook(array $options = []): array
    {
        return $this->codexReviewMergeSection()->codexReviewMergeAuthorizationPostSignatureRunbook($options);
    }

    public function codexReviewMergeFinalAuthorizationPreflight(array $options = []): array
    {
        return $this->codexReviewMergeSection()->codexReviewMergeFinalAuthorizationPreflight($options);
    }

    public function codexReviewMergeAuthorizingActionTemplate(array $options = []): array
    {
        return $this->codexReviewMergeSection()->codexReviewMergeAuthorizingActionTemplate($options);
    }

    public function codexReviewMergeFinalReceiptDraft(array $options = []): array
    {
        return $this->codexReviewMergeSection()->codexReviewMergeFinalReceiptDraft($options);
    }

    public function codexReviewMergeFinalSignatureRequest(array $options = []): array
    {
        return $this->codexReviewMergeSection()->codexReviewMergeFinalSignatureRequest($options);
    }

    public function codexReviewMergeFinalPostSignatureRunbook(array $options = []): array
    {
        return $this->codexReviewMergeSection()->codexReviewMergeFinalPostSignatureRunbook($options);
    }

    public function codexReviewMergeSignedFinalReceiptTemplate(array $options = []): array
    {
        return $this->codexReviewMergeSection()->codexReviewMergeSignedFinalReceiptTemplate($options);
    }

    public function codexReviewMergeSignedFinalReceiptPreflight(array $options = []): array
    {
        return $this->codexReviewMergeSection()->codexReviewMergeSignedFinalReceiptPreflight($options);
    }

    public function codexReviewMergeSignedFinalReceiptPersistenceTemplate(array $options = []): array
    {
        return $this->codexReviewMergeSection()->codexReviewMergeSignedFinalReceiptPersistenceTemplate($options);
    }

    public function codexReviewMergeExecutorReleasePreflight(array $options = []): array
    {
        return $this->codexReviewMergeSection()->codexReviewMergeExecutorReleasePreflight($options);
    }

    public function codexReviewMergeExecutorContractTemplate(array $options = []): array
    {
        return $this->codexReviewMergeSection()->codexReviewMergeExecutorContractTemplate($options);
    }

    public function codexReviewMergeExecutionReceiptTemplate(array $options = []): array
    {
        return $this->codexReviewMergeSection()->codexReviewMergeExecutionReceiptTemplate($options);
    }

    public function codexReviewMergePostExecutionPreflight(array $options = []): array
    {
        return $this->codexReviewMergeSection()->codexReviewMergePostExecutionPreflight($options);
    }

    public function codexReviewMergePostExecutionActionTemplate(array $options = []): array
    {
        return $this->codexReviewMergeSection()->codexReviewMergePostExecutionActionTemplate($options);
    }

    public function codexReviewMergePostExecutionActionReceiptDraft(array $options = []): array
    {
        return $this->codexReviewMergeSection()->codexReviewMergePostExecutionActionReceiptDraft($options);
    }

    public function codexReviewMergePostExecutionActionSignatureRequest(array $options = []): array
    {
        return $this->codexReviewMergeSection()->codexReviewMergePostExecutionActionSignatureRequest($options);
    }

    public function codexReviewMergePostExecutionActionPostSignatureRunbook(array $options = []): array
    {
        return $this->codexReviewMergeSection()->codexReviewMergePostExecutionActionPostSignatureRunbook($options);
    }

    public function codexReviewMergePostExecutionActionSignedReceiptTemplate(array $options = []): array
    {
        return $this->codexReviewMergeSection()->codexReviewMergePostExecutionActionSignedReceiptTemplate($options);
    }

    public function codexReviewMergePostExecutionActionSignedReceiptPreflight(array $options = []): array
    {
        return $this->codexReviewMergeSection()->codexReviewMergePostExecutionActionSignedReceiptPreflight($options);
    }

    public function codexReviewMergePostExecutionActionSignedReceiptPersistenceTemplate(array $options = []): array
    {
        return $this->codexReviewMergeSection()->codexReviewMergePostExecutionActionSignedReceiptPersistenceTemplate($options);
    }

    public function codexReviewMergePostExecutionActionSignedReceiptPersistenceReceiptDraft(array $options = []): array
    {
        return $this->codexReviewMergeSection()->codexReviewMergePostExecutionActionSignedReceiptPersistenceReceiptDraft($options);
    }

    public function codexReviewMergePostExecutionActionSignedReceiptPersistencePreflight(array $options = []): array
    {
        return $this->codexReviewMergeSection()->codexReviewMergePostExecutionActionSignedReceiptPersistencePreflight($options);
    }

    public function codexReviewMergePostExecutionActionSignedReceiptPersistencePostPreflightRunbook(array $options = []): array
    {
        return $this->codexReviewMergeSection()->codexReviewMergePostExecutionActionSignedReceiptPersistencePostPreflightRunbook($options);
    }

    public function codexReviewMergePostExecutionActionSignedReceiptPersistenceAppendOnlyEventPayloadTemplate(array $options = []): array
    {
        return $this->codexReviewMergeSection()->codexReviewMergePostExecutionActionSignedReceiptPersistenceAppendOnlyEventPayloadTemplate($options);
    }

    public function codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterPreflight(array $options = []): array
    {
        return $this->codexReviewMergeSection()->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterPreflight($options);
    }

    public function codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterContractTemplate(array $options = []): array
    {
        return $this->codexReviewMergeSection()->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterContractTemplate($options);
    }

    public function codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterImplementationPreflight(array $options = []): array
    {
        return $this->codexReviewMergeSection()->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterImplementationPreflight($options);
    }

    public function codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseAuthorizationTemplate(array $options = []): array
    {
        return $this->codexReviewMergeSection()->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseAuthorizationTemplate($options);
    }

    public function codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseAuthorizationPreflight(array $options = []): array
    {
        return $this->codexReviewMergeSection()->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseAuthorizationPreflight($options);
    }

    public function codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseAuthorizationReceiptDraft(array $options = []): array
    {
        return $this->codexReviewMergeSection()->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseAuthorizationReceiptDraft($options);
    }

    public function codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseAuthorizationSignatureRequest(array $options = []): array
    {
        return $this->codexReviewMergeSection()->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseAuthorizationSignatureRequest($options);
    }

    public function codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseAuthorizationPostSignatureRunbook(array $options = []): array
    {
        return $this->codexReviewMergeSection()->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseAuthorizationPostSignatureRunbook($options);
    }

    public function codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseAuthorizationSignedReceiptTemplate(array $options = []): array
    {
        return $this->codexReviewMergeSection()->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseAuthorizationSignedReceiptTemplate($options);
    }

    public function codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleasePreflight(array $options = []): array
    {
        return $this->codexReviewMergeSection()->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleasePreflight($options);
    }

    public function codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseReceiptDraft(array $options = []): array
    {
        return $this->codexReviewMergeSection()->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseReceiptDraft($options);
    }

    public function codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseSignatureRequest(array $options = []): array
    {
        return $this->codexReviewMergeSection()->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseSignatureRequest($options);
    }

    public function codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleasePostSignatureRunbook(array $options = []): array
    {
        return $this->codexReviewMergeSection()->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleasePostSignatureRunbook($options);
    }

    public function codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseSignedReceiptTemplate(array $options = []): array
    {
        return $this->codexReviewMergeSection()->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseSignedReceiptTemplate($options);
    }

    public function codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseExecutionContractPreflight(array $options = []): array
    {
        return $this->codexReviewMergeSection()->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseExecutionContractPreflight($options);
    }

    public function codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseExecutionContractTemplate(array $options = []): array
    {
        return $this->codexReviewMergeSection()->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseExecutionContractTemplate($options);
    }

    public function codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseDisableContractTemplate(array $options = []): array
    {
        return $this->codexReviewMergeSection()->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseDisableContractTemplate($options);
    }

    public function codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseObservabilityContractTemplate(array $options = []): array
    {
        return $this->codexReviewMergeSection()->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseObservabilityContractTemplate($options);
    }

    public function codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleasePostMonitoringReviewTemplate(array $options = []): array
    {
        return $this->codexReviewMergeSection()->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleasePostMonitoringReviewTemplate($options);
    }

    public function codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseReenableReviewPacketTemplate(array $options = []): array
    {
        return $this->codexReviewMergeSection()->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseReenableReviewPacketTemplate($options);
    }

    public function codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationRequestTemplate(array $options = []): array
    {
        return $this->codexReviewMergeSection()->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationRequestTemplate($options);
    }

    public function codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationReceiptDraftTemplate(array $options = []): array
    {
        return $this->codexReviewMergeSection()->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationReceiptDraftTemplate($options);
    }

    public function codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationSignatureRequestTemplate(array $options = []): array
    {
        return $this->codexReviewMergeSection()->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationSignatureRequestTemplate($options);
    }

    public function codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationPostSignatureRunbookTemplate(array $options = []): array
    {
        return $this->codexReviewMergeSection()->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationPostSignatureRunbookTemplate($options);
    }

    public function codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationSignedReceiptTemplate(array $options = []): array
    {
        return $this->codexReviewMergeSection()->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationSignedReceiptTemplate($options);
    }

    public function codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationExecutionContractPreflightTemplate(array $options = []): array
    {
        return $this->codexReviewMergeSection()->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationExecutionContractPreflightTemplate($options);
    }

    public function codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationExecutionContractTemplate(array $options = []): array
    {
        return $this->codexReviewMergeSection()->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationExecutionContractTemplate($options);
    }

    public function codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationDisableContractTemplate(array $options = []): array
    {
        return $this->codexReviewMergeSection()->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationDisableContractTemplate($options);
    }

    public function codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationObservabilityContractTemplate(array $options = []): array
    {
        return $this->codexReviewMergeSection()->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationObservabilityContractTemplate($options);
    }

    public function codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationPostMonitoringReviewTemplate(array $options = []): array
    {
        return $this->codexReviewMergeSection()->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationPostMonitoringReviewTemplate($options);
    }

    public function codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationHealthDecisionTemplate(array $options = []): array
    {
        return $this->codexReviewMergeSection()->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationHealthDecisionTemplate($options);
    }

    public function codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationDisableRequestTemplate(array $options = []): array
    {
        return $this->codexReviewMergeSection()->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationDisableRequestTemplate($options);
    }

    public function codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleRequestTemplate(array $options = []): array
    {
        return $this->codexReviewMergeSection()->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleRequestTemplate($options);
    }

    public function codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleAuthorizationRequestTemplate(array $options = []): array
    {
        return $this->codexReviewMergeSection()->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleAuthorizationRequestTemplate($options);
    }

    public function codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleReceiptDraftTemplate(array $options = []): array
    {
        return $this->codexReviewMergeSection()->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleReceiptDraftTemplate($options);
    }

    public function codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleSignatureRequestTemplate(array $options = []): array
    {
        return $this->codexReviewMergeSection()->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleSignatureRequestTemplate($options);
    }

    public function codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCyclePostSignatureRunbookTemplate(array $options = []): array
    {
        return $this->codexReviewMergeSection()->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCyclePostSignatureRunbookTemplate($options);
    }

    public function codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleSignedReceiptTemplate(array $options = []): array
    {
        return $this->codexReviewMergeSection()->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleSignedReceiptTemplate($options);
    }

    public function codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleExecutionContractPreflightTemplate(array $options = []): array
    {
        return $this->codexReviewMergeSection()->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleExecutionContractPreflightTemplate($options);
    }

    public function codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleExecutionContractTemplate(array $options = []): array
    {
        return $this->codexReviewMergeSection()->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleExecutionContractTemplate($options);
    }

    public function codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableContractTemplate(array $options = []): array
    {
        return $this->codexReviewMergeSection()->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableContractTemplate($options);
    }

    public function codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleObservabilityContractTemplate(array $options = []): array
    {
        return $this->codexReviewMergeSection()->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleObservabilityContractTemplate($options);
    }

    public function codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCyclePostMonitoringReviewTemplate(array $options = []): array
    {
        return $this->codexReviewMergeSection()->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCyclePostMonitoringReviewTemplate($options);
    }

    public function codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleHealthDecisionTemplate(array $options = []): array
    {
        return $this->codexReviewMergeSection()->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleHealthDecisionTemplate($options);
    }

    public function codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableRequestTemplate(array $options = []): array
    {
        return $this->codexReviewMergeSection()->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableRequestTemplate($options);
    }

    public function codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionPreflightTemplate(array $options = []): array
    {
        return $this->codexReviewMergeSection()->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionPreflightTemplate($options);
    }

    public function codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionReceiptDraftTemplate(array $options = []): array
    {
        return $this->codexReviewMergeSection()->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionReceiptDraftTemplate($options);
    }

    public function codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionSignedReceiptTemplate(array $options = []): array
    {
        return $this->codexReviewMergeSection()->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionSignedReceiptTemplate($options);
    }

    public function codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionPersistencePreflightTemplate(array $options = []): array
    {
        return $this->codexReviewMergeSection()->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionPersistencePreflightTemplate($options);
    }

    public function codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionPersistenceReceiptTemplate(array $options = []): array
    {
        return $this->codexReviewMergeSection()->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionPersistenceReceiptTemplate($options);
    }

    public function codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionPostPersistenceReviewTemplate(array $options = []): array
    {
        return $this->codexReviewMergeSection()->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionPostPersistenceReviewTemplate($options);
    }

    public function codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionFollowUpObservabilityTemplate(array $options = []): array
    {
        return $this->codexReviewMergeSection()->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionFollowUpObservabilityTemplate($options);
    }

    public function codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionEvidenceRepairRequestTemplate(array $options = []): array
    {
        return $this->codexReviewMergeSection()->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionEvidenceRepairRequestTemplate($options);
    }

    public function codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionRepairedEvidencePacketTemplate(array $options = []): array
    {
        return $this->codexReviewMergeSection()->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionRepairedEvidencePacketTemplate($options);
    }

    public function codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionRepairReviewTemplate(array $options = []): array
    {
        return $this->codexReviewMergeSection()->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionRepairReviewTemplate($options);
    }

    public function codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionRepairOutcomePacketTemplate(array $options = []): array
    {
        return $this->codexReviewMergeSection()->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionRepairOutcomePacketTemplate($options);
    }

    public function codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleRequestTemplate(array $options = []): array
    {
        return $this->codexReviewMergeSection()->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleRequestTemplate($options);
    }

    public function codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCyclePreflightTemplate(array $options = []): array
    {
        return $this->codexReviewMergeSection()->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCyclePreflightTemplate($options);
    }

    public function codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationRequestTemplate(array $options = []): array
    {
        return $this->codexReviewMergeSection()->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationRequestTemplate($options);
    }

    public function codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationReceiptDraftTemplate(array $options = []): array
    {
        return $this->codexReviewMergeSection()->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationReceiptDraftTemplate($options);
    }

    public function codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationSignatureRequestTemplate(array $options = []): array
    {
        return $this->codexReviewMergeSection()->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationSignatureRequestTemplate($options);
    }

    public function codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationPostSignatureRunbookTemplate(array $options = []): array
    {
        return $this->codexReviewMergeSection()->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationPostSignatureRunbookTemplate($options);
    }

    public function codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationSignatureValidationReportTemplate(array $options = []): array
    {
        return $this->codexReviewMergeSection()->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationSignatureValidationReportTemplate($options);
    }

    public function codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationSignedReceiptTemplate(array $options = []): array
    {
        return $this->codexReviewMergeSection()->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationSignedReceiptTemplate($options);
    }

    public function codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationSignedReceiptPreflightTemplate(array $options = []): array
    {
        return $this->codexReviewMergeSection()->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationSignedReceiptPreflightTemplate($options);
    }

    public function codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationPersistencePreflightTemplate(array $options = []): array
    {
        return $this->codexReviewMergeSection()->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationPersistencePreflightTemplate($options);
    }

    public function codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationPersistenceReceiptTemplate(array $options = []): array
    {
        return $this->codexReviewMergeSection()->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationPersistenceReceiptTemplate($options);
    }

    public function codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationPostPersistenceReviewTemplate(array $options = []): array
    {
        return $this->codexReviewMergeSection()->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationPostPersistenceReviewTemplate($options);
    }

    public function codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationFollowUpObservabilityTemplate(array $options = []): array
    {
        return $this->codexReviewMergeSection()->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationFollowUpObservabilityTemplate($options);
    }

    public function codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationEvidenceRepairRequestTemplate(array $options = []): array
    {
        return $this->codexReviewMergeSection()->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationEvidenceRepairRequestTemplate($options);
    }

    public function codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationRepairedEvidencePacketTemplate(array $options = []): array
    {
        return $this->codexReviewMergeSection()->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationRepairedEvidencePacketTemplate($options);
    }

    public function codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationRepairReviewTemplate(array $options = []): array
    {
        return $this->codexReviewMergeSection()->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationRepairReviewTemplate($options);
    }

    public function codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationPersistenceRejectionTemplate(array $options = []): array
    {
        return $this->codexReviewMergeSection()->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationPersistenceRejectionTemplate($options);
    }

    public function codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationHumanEscalationTemplate(array $options = []): array
    {
        return $this->codexReviewMergeSection()->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationHumanEscalationTemplate($options);
    }

    public function codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationManualDecisionRequestTemplate(array $options = []): array
    {
        return $this->codexReviewMergeSection()->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationManualDecisionRequestTemplate($options);
    }

    public function codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationManualDecisionResponseTemplate(array $options = []): array
    {
        return $this->codexReviewMergeSection()->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationManualDecisionResponseTemplate($options);
    }

    public function codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationDecisionRecordDraftTemplate(array $options = []): array
    {
        return $this->codexReviewMergeSection()->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationDecisionRecordDraftTemplate($options);
    }

    public function codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationDecisionRecordPersistencePreflightTemplate(array $options = []): array
    {
        return $this->codexReviewMergeSection()->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationDecisionRecordPersistencePreflightTemplate($options);
    }

    public function codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationDecisionRecordPersistenceReceiptTemplate(array $options = []): array
    {
        return $this->codexReviewMergeSection()->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationDecisionRecordPersistenceReceiptTemplate($options);
    }

    public function codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationDecisionRecordPostPersistenceReviewTemplate(array $options = []): array
    {
        return $this->codexReviewMergeSection()->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationDecisionRecordPostPersistenceReviewTemplate($options);
    }

    public function codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationDecisionRecordPersistenceRejectionTemplate(array $options = []): array
    {
        return $this->codexReviewMergeSection()->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationDecisionRecordPersistenceRejectionTemplate($options);
    }

    public function codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationDecisionRecordFollowUpObservabilityTemplate(array $options = []): array
    {
        return $this->codexReviewMergeSection()->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationDecisionRecordFollowUpObservabilityTemplate($options);
    }

    public function codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationDecisionRecordFinalNonExecutionReportTemplate(array $options = []): array
    {
        return $this->codexReviewMergeSection()->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationDecisionRecordFinalNonExecutionReportTemplate($options);
    }

    public function codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationDecisionRecordArchiveIndexTemplate(array $options = []): array
    {
        return $this->codexReviewMergeSection()->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationDecisionRecordArchiveIndexTemplate($options);
    }

    public function codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationDecisionRecordHumanReviewPacketTemplate(array $options = []): array
    {
        return $this->codexReviewMergeSection()->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationDecisionRecordHumanReviewPacketTemplate($options);
    }

    public function codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationDecisionRecordDurableWriterCandidateTemplate(array $options = []): array
    {
        return $this->codexReviewMergeSection()->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationDecisionRecordDurableWriterCandidateTemplate($options);
    }

    public function codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationDecisionRecordWriterActivationRequestTemplate(array $options = []): array
    {
        return $this->codexReviewMergeSection()->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationDecisionRecordWriterActivationRequestTemplate($options);
    }

    public function codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationDecisionRecordWriterActivationReceiptTemplate(array $options = []): array
    {
        return $this->codexReviewMergeSection()->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationDecisionRecordWriterActivationReceiptTemplate($options);
    }

    public function codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationDecisionRecordPostActivationObservabilityTemplate(array $options = []): array
    {
        return $this->codexReviewMergeSection()->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationDecisionRecordPostActivationObservabilityTemplate($options);
    }

    public function codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationDecisionRecordWriterActivationRejectionTemplate(array $options = []): array
    {
        return $this->codexReviewMergeSection()->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationDecisionRecordWriterActivationRejectionTemplate($options);
    }

    public function codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationDecisionRecordFinalActivationNonExecutionReportTemplate(array $options = []): array
    {
        return $this->codexReviewMergeSection()->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationDecisionRecordFinalActivationNonExecutionReportTemplate($options);
    }

    public function codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationDecisionRecordActivationArchiveIndexTemplate(array $options = []): array
    {
        return $this->codexReviewMergeSection()->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationDecisionRecordActivationArchiveIndexTemplate($options);
    }

    public function codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationDecisionRecordActivationHumanReviewPacketTemplate(array $options = []): array
    {
        return $this->codexReviewMergeSection()->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationDecisionRecordActivationHumanReviewPacketTemplate($options);
    }

    public function codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationDecisionRecordActivationDurableWriterCandidateTemplate(array $options = []): array
    {
        return $this->codexReviewMergeSection()->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationDecisionRecordActivationDurableWriterCandidateTemplate($options);
    }

    public function codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationDecisionRecordActivationWriterActivationRequestTemplate(array $options = []): array
    {
        return $this->codexReviewMergeSection()->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationDecisionRecordActivationWriterActivationRequestTemplate($options);
    }

    public function codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationDecisionRecordActivationWriterActivationReceiptTemplate(array $options = []): array
    {
        return $this->codexReviewMergeSection()->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationDecisionRecordActivationWriterActivationReceiptTemplate($options);
    }

    public function codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationDecisionRecordActivationPostActivationObservabilityTemplate(array $options = []): array
    {
        return $this->codexReviewMergeSection()->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationDecisionRecordActivationPostActivationObservabilityTemplate($options);
    }

    public function codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationDecisionRecordActivationWriterActivationRejectionTemplate(array $options = []): array
    {
        return $this->codexReviewMergeSection()->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationDecisionRecordActivationWriterActivationRejectionTemplate($options);
    }

    public function codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationDecisionRecordActivationFinalActivationNonExecutionReportTemplate(array $options = []): array
    {
        return $this->codexReviewMergeSection()->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationDecisionRecordActivationFinalActivationNonExecutionReportTemplate($options);
    }

    public function codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationDecisionRecordActivationArchiveClosureIndexTemplate(array $options = []): array
    {
        return $this->codexReviewMergeSection()->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationDecisionRecordActivationArchiveClosureIndexTemplate($options);
    }

    public function codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationDecisionRecordActivationCloseoutPacketTemplate(array $options = []): array
    {
        return $this->codexReviewMergeSection()->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationDecisionRecordActivationCloseoutPacketTemplate($options);
    }

    public function codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationDecisionRecordActivationReadinessReconciliationTemplate(array $options = []): array
    {
        return $this->codexReviewMergeSection()->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationDecisionRecordActivationReadinessReconciliationTemplate($options);
    }

    public function codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationDecisionRecordActivationGovernanceSummaryTemplate(array $options = []): array
    {
        return $this->codexReviewMergeSection()->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationDecisionRecordActivationGovernanceSummaryTemplate($options);
    }

    public function codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationDecisionRecordActivationOperatorReadinessDigestTemplate(array $options = []): array
    {
        return $this->codexReviewMergeSection()->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationDecisionRecordActivationOperatorReadinessDigestTemplate($options);
    }

    public function codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationDecisionRecordActivationHandoffDigestTemplate(array $options = []): array
    {
        return $this->codexReviewMergeSection()->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationDecisionRecordActivationHandoffDigestTemplate($options);
    }

    public function codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationDecisionRecordActivationSessionBootstrapSummaryTemplate(array $options = []): array
    {
        return $this->codexReviewMergeSection()->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationDecisionRecordActivationSessionBootstrapSummaryTemplate($options);
    }

    public function codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationDecisionRecordActivationSessionPreflightDigestTemplate(array $options = []): array
    {
        return $this->codexReviewMergeSection()->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationDecisionRecordActivationSessionPreflightDigestTemplate($options);
    }

    public function codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationDecisionRecordActivationSessionScopeGuardTemplate(array $options = []): array
    {
        return $this->codexReviewMergeSection()->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationDecisionRecordActivationSessionScopeGuardTemplate($options);
    }

    public function codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationDecisionRecordActivationSessionWorkIntakePreviewTemplate(array $options = []): array
    {
        return $this->codexReviewMergeSection()->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationDecisionRecordActivationSessionWorkIntakePreviewTemplate($options);
    }

    public function codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationDecisionRecordActivationSessionTaskCandidateOutlineTemplate(array $options = []): array
    {
        return $this->codexReviewMergeSection()->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationDecisionRecordActivationSessionTaskCandidateOutlineTemplate($options);
    }

    public function codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationDecisionRecordActivationSessionPacketDraftPreviewTemplate(array $options = []): array
    {
        return $this->codexReviewMergeSection()->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationDecisionRecordActivationSessionPacketDraftPreviewTemplate($options);
    }

    public function codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationDecisionRecordActivationSessionPacketScopePreviewTemplate(array $options = []): array
    {
        return $this->codexReviewMergeSection()->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationDecisionRecordActivationSessionPacketScopePreviewTemplate($options);
    }

    public function codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationDecisionRecordActivationSessionPacketStartContractPreviewTemplate(array $options = []): array
    {
        return $this->codexReviewMergeSection()->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationDecisionRecordActivationSessionPacketStartContractPreviewTemplate($options);
    }

    public function codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationDecisionRecordActivationSessionSessionOperatorPromptPreviewTemplate(array $options = []): array
    {
        return $this->codexReviewMergeSection()->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationDecisionRecordActivationSessionSessionOperatorPromptPreviewTemplate($options);
    }

    public function codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationDecisionRecordActivationSessionSessionReadyCheckPreviewTemplate(array $options = []): array
    {
        return $this->codexReviewMergeSection()->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationDecisionRecordActivationSessionSessionReadyCheckPreviewTemplate($options);
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
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null, evidence_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function completePacket(array $options = []): array
    {
        $packetId = (string) ($options['packet'] ?? '');
        $completion = $this->reservations->complete(
            packetId: $packetId,
            actor: $this->reservationActor($options),
            session: $this->reservationSession($options),
            reason: (string) ($options['reason'] ?? 'operator_reported_packet_complete'),
            evidenceHash: $options['evidence_hash'] ?? null,
        );

        return [
            'schema_version' => 'atlas.self_construction_complete_packet.v1',
            'status' => data_get($completion, 'status') === 'completed' ? 'completed' : 'blocked',
            'mode' => 'durable_local_packet_completion',
            'execution_allowed' => false,
            'completion_allowed' => false,
            'completion_persisted' => data_get($completion, 'status') === 'completed',
            'ledger_write_allowed' => true,
            'dispatch_allowed' => false,
            'approval_granted' => false,
            'completion' => [
                ...$completion,
                'packet_id' => $packetId,
            ],
            'completion_hash' => $this->stableHash($completion),
            'non_execution_guarantees' => [
                'complete_packet_does_not_approve_code',
                'complete_packet_does_not_dispatch_work',
                'complete_packet_does_not_enable_execution',
                'complete_packet_does_not_auto_merge',
            ],
            'human_summary' => data_get($completion, 'status') === 'completed'
                ? 'Packet reservation was durably marked completed in the local ledger. This records packet state only; it does not approve code or bypass gates.'
                : 'Packet completion was blocked because no active owner-matching reservation was found.',
        ];
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null}  $options
     * @return array<string, mixed>
     */
        public function durableReservationLedgerImplementationPlan(array $options = []): array
    {
        return $this->durableReservationSection()->durableReservationLedgerImplementationPlan($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null}  $options
     * @return array<string, mixed>
     */
        public function durableReservationApCandidate(array $options = []): array
    {
        return $this->durableReservationSection()->durableReservationApCandidate($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null}  $options
     * @return array<string, mixed>
     */
        public function durableReservationApprovalRequest(array $options = []): array
    {
        return $this->durableReservationSection()->durableReservationApprovalRequest($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null}  $options
     * @return array<string, mixed>
     */
        public function durableReservationApprovalDecisionTemplate(array $options = []): array
    {
        return $this->durableReservationSection()->durableReservationApprovalDecisionTemplate($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null}  $options
     * @return array<string, mixed>
     */
        public function durableReservationPostApprovalPreflight(array $options = []): array
    {
        return $this->durableReservationSection()->durableReservationPostApprovalPreflight($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null}  $options
     * @return array<string, mixed>
     */
        public function durableReservationImplementationPacket(array $options = []): array
    {
        return $this->durableReservationSection()->durableReservationImplementationPacket($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null}  $options
     * @return array<string, mixed>
     */
        public function durableReservationStorageSchema(array $options = []): array
    {
        return $this->durableReservationSection()->durableReservationStorageSchema($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null}  $options
     * @return array<string, mixed>
     */
        public function durableReservationRepositoryContract(array $options = []): array
    {
        return $this->durableReservationSection()->durableReservationRepositoryContract($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null}  $options
     * @return array<string, mixed>
     */
        public function durableReservationCollisionGuard(array $options = []): array
    {
        return $this->durableReservationSection()->durableReservationCollisionGuard($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null}  $options
     * @return array<string, mixed>
     */
        public function durableReservationLeaseLifecycle(array $options = []): array
    {
        return $this->durableReservationSection()->durableReservationLeaseLifecycle($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null}  $options
     * @return array<string, mixed>
     */
        public function durableReservationReadinessProjection(array $options = []): array
    {
        return $this->durableReservationSection()->durableReservationReadinessProjection($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null}  $options
     * @return array<string, mixed>
     */
        public function durableReservationImplementationPreflight(array $options = []): array
    {
        return $this->durableReservationSection()->durableReservationImplementationPreflight($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null}  $options
     * @return array<string, mixed>
     */
        public function durableReservationMigrationBlueprint(array $options = []): array
    {
        return $this->durableReservationSection()->durableReservationMigrationBlueprint($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null}  $options
     * @return array<string, mixed>
     */
        public function durableReservationRepositoryBlueprint(array $options = []): array
    {
        return $this->durableReservationSection()->durableReservationRepositoryBlueprint($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null}  $options
     * @return array<string, mixed>
     */
        public function durableReservationCollisionGuardBlueprint(array $options = []): array
    {
        return $this->durableReservationSection()->durableReservationCollisionGuardBlueprint($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null}  $options
     * @return array<string, mixed>
     */
        public function durableReservationLeaseLifecycleBlueprint(array $options = []): array
    {
        return $this->durableReservationSection()->durableReservationLeaseLifecycleBlueprint($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null}  $options
     * @return array<string, mixed>
     */
        public function durableReservationReadinessProjectionBlueprint(array $options = []): array
    {
        return $this->durableReservationSection()->durableReservationReadinessProjectionBlueprint($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null}  $options
     * @return array<string, mixed>
     */
        public function durableReservationRuntimeBuildPacket(array $options = []): array
    {
        return $this->durableReservationSection()->durableReservationRuntimeBuildPacket($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null}  $options
     * @return array<string, mixed>
     */
    public function aiSessionBootstrap(array $options = []): array
    {
        $assignmentPayload = $this->assignmentPreview($options);
        $reservationPayload = $this->reservationLedgerPreview($options);
        $runbookPayload = $this->packetRunbook($options);
        $scopePayload = $this->scopeValidator($options);
        $evidencePayload = $this->packetEvidenceReport($options);
        $completionGatePayload = $this->packetCompletionGate($options);

        $bootstrap = [
            'bootstrap_id' => 'BOOTSTRAP-SELF-CONSTRUCTION-AI-SESSION-0001',
            'session_mode' => 'read_only_ai_bootstrap',
            'selected_packet_id' => data_get($assignmentPayload, 'assignment.selected_packet_id'),
            'assignment_hash' => data_get($assignmentPayload, 'assignment_hash'),
            'reservation_hash' => data_get($reservationPayload, 'reservation_hash'),
            'runbook_hash' => data_get($runbookPayload, 'runbook_hash'),
            'scope_validator_status' => data_get($scopePayload, 'status'),
            'evidence_report_status' => data_get($evidencePayload, 'status'),
            'completion_gate_status' => data_get($completionGatePayload, 'status'),
            'execution_allowed' => false,
            'completion_allowed' => false,
            'claim_persisted' => false,
            'ledger_write_allowed' => false,
            'required_first_commands' => [
                'git status --short',
                'git diff --stat',
                'git diff --name-only',
                $this->packetCommand('ai-session-bootstrap', data_get($assignmentPayload, 'assignment.selected_packet_id')),
                $this->packetCommand('scope-validator', data_get($assignmentPayload, 'assignment.selected_packet_id')),
            ],
            'stop_conditions' => array_values(array_unique(array_merge(
                (array) data_get($assignmentPayload, 'assignment.stop_conditions', []),
                (array) data_get($reservationPayload, 'reservation.stop_conditions', []),
                [
                    'bootstrap_hash_changed',
                    'reservation_hash_changed',
                    'scope_validator_blocked',
                    'packet_completion_gate_blocked',
                    'operator_evidence_missing',
                ],
            ))),
            'forbidden_hot_scopes' => $this->hotForbiddenFiles(),
            'payload_refs' => [
                'assignment_schema' => data_get($assignmentPayload, 'schema_version'),
                'reservation_schema' => data_get($reservationPayload, 'schema_version'),
                'runbook_schema' => data_get($runbookPayload, 'schema_version'),
                'scope_validator_schema' => data_get($scopePayload, 'schema_version'),
                'evidence_report_schema' => data_get($evidencePayload, 'schema_version'),
                'completion_gate_schema' => data_get($completionGatePayload, 'schema_version'),
            ],
        ];

        return [
            'schema_version' => 'atlas.self_construction_ai_session_bootstrap.v1',
            'status' => data_get($assignmentPayload, 'status') === 'claim_preview_ready'
                ? 'bootstrap_ready'
                : 'blocked_by_assignment',
            'mode' => 'read_only_ai_session_bootstrap',
            'execution_allowed' => false,
            'completion_allowed' => false,
            'claim_persisted' => false,
            'ledger_write_allowed' => false,
            'bootstrap' => $bootstrap,
            'bootstrap_hash' => $this->stableHash($bootstrap),
            'non_execution_guarantees' => [
                'ai_session_bootstrap_does_not_persist_claim',
                'ai_session_bootstrap_does_not_write_ledger',
                'ai_session_bootstrap_does_not_apply_patch',
                'ai_session_bootstrap_does_not_enable_execution',
            ],
            'human_summary' => 'AI session bootstrap is ready: a new AI can read one canonical packet, but claims, ledger writes, execution and completion remain disabled.',
        ];
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null}  $options
     * @return array<string, mixed>
     */
    public function packetQueue(array $options = []): array
    {
        $splitter = $this->workSplitter($options);
        $packets = (array) data_get($splitter, 'split.packets', []);
        $withheld = (array) data_get($splitter, 'split.withheld_work', []);
        $activeReservations = $this->reservations->activeByPacket();
        $completedReservations = $this->reservations->completedByPacket();

        $entries = [];
        foreach ($packets as $index => $packet) {
            $dependsOn = (array) data_get($packet, 'depends_on', []);
            $packetId = (string) data_get($packet, 'packet_id');
            $activeReservation = $activeReservations[$packetId] ?? null;
            $completedReservation = $completedReservations[$packetId] ?? null;
            $dependenciesComplete = $dependsOn === []
                || count(array_diff($dependsOn, array_keys($completedReservations))) === 0;
            $queueState = match (true) {
                is_array($completedReservation) => 'completed',
                is_array($activeReservation) => 'claimed',
                $dependenciesComplete => 'available',
                default => 'blocked_by_dependency',
            };

            $entries[] = [
                'packet_id' => $packetId,
                'lane' => data_get($packet, 'lane'),
                'objective' => data_get($packet, 'objective'),
                'queue_state' => $queueState,
                'rank' => $queueState === 'available' ? $index + 1 : null,
                'active_reservation_id' => data_get($activeReservation, 'reservation_id'),
                'active_reservation_actor' => data_get($activeReservation, 'actor'),
                'active_reservation_session' => data_get($activeReservation, 'session'),
                'lease_expires_at' => data_get($activeReservation, 'lease_expires_at'),
                'completed_reservation_id' => data_get($completedReservation, 'reservation_id'),
                'completed_at' => data_get($completedReservation, 'completed_at'),
                'completion_actor' => data_get($completedReservation, 'actor'),
                'claim_policy' => data_get($packet, 'claim_policy'),
                'collision_risk' => data_get($packet, 'collision_risk'),
                'recommended' => false,
                'depends_on' => $dependsOn,
                'allowed_files' => (array) data_get($packet, 'allowed_files', []),
                'forbidden_files' => (array) data_get($packet, 'forbidden_files', []),
                'required_bootstrap_command' => $this->packetCommand('ai-session-bootstrap', data_get($packet, 'packet_id')),
            ];
        }

        foreach ($withheld as $index => $item) {
            $entries[] = [
                'packet_id' => data_get($item, 'id'),
                'lane' => 'withheld_hot_external',
                'objective' => data_get($item, 'reason'),
                'queue_state' => 'withheld',
                'rank' => null,
                'claim_policy' => 'not_assignable',
                'collision_risk' => 'blocked',
                'recommended' => false,
                'depends_on' => [],
                'allowed_files' => [],
                'forbidden_files' => [data_get($item, 'forbidden_scope')],
                'withheld_order' => $index + 1,
            ];
        }

        $firstAvailableIndex = null;
        foreach ($entries as $index => $entry) {
            if ($entry['queue_state'] === 'available') {
                $firstAvailableIndex = $index;
                break;
            }
        }
        if ($firstAvailableIndex !== null) {
            $entries[$firstAvailableIndex]['recommended'] = true;
        }

        $queue = [
            'queue_id' => 'PACKET-QUEUE-SELF-CONSTRUCTION-READ-ONLY-0001',
            'source_split_hash' => data_get($splitter, 'split_hash'),
            'entry_count' => count($entries),
            'available_count' => count(array_filter($entries, fn (array $entry): bool => $entry['queue_state'] === 'available')),
            'blocked_count' => count(array_filter($entries, fn (array $entry): bool => $entry['queue_state'] === 'blocked_by_dependency')),
            'claimed_count' => count(array_filter($entries, fn (array $entry): bool => $entry['queue_state'] === 'claimed')),
            'completed_count' => count(array_filter($entries, fn (array $entry): bool => $entry['queue_state'] === 'completed')),
            'withheld_count' => count(array_filter($entries, fn (array $entry): bool => $entry['queue_state'] === 'withheld')),
            'recommended_packet_id' => data_get(collect($entries)->firstWhere('recommended', true), 'packet_id'),
            'entries' => $entries,
            'execution_allowed' => false,
            'claim_persisted' => false,
            'ledger_write_allowed' => false,
            'queue_write_allowed' => false,
        ];

        return [
            'schema_version' => 'atlas.self_construction_packet_queue.v1',
            'status' => 'packet_queue_ready',
            'mode' => 'read_only_packet_queue',
            'execution_allowed' => false,
            'completion_allowed' => false,
            'claim_persisted' => false,
            'ledger_write_allowed' => false,
            'queue_write_allowed' => false,
            'queue' => $queue,
            'queue_hash' => $this->stableHash($queue),
            'non_execution_guarantees' => [
                'packet_queue_does_not_persist_claim',
                'packet_queue_does_not_write_ledger',
                'packet_queue_does_not_dispatch_work',
                'packet_queue_does_not_enable_execution',
            ],
            'human_summary' => 'Packet queue is ready: available, blocked and withheld packets are visible without claims, dispatch, ledger writes or execution.',
        ];
    }
    public function parallelSessionPlan(array $options = []): array
    {
        return $this->parallelSessionSection()->parallelSessionPlan($options);
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null}  $options
     * @return array<string, mixed>
     */
    public function collisionMatrix(array $options = []): array
    {
        $queuePayload = $this->packetQueue($options);
        $entries = (array) data_get($queuePayload, 'queue.entries', []);
        $pairs = [];

        for ($left = 0; $left < count($entries); $left++) {
            for ($right = $left + 1; $right < count($entries); $right++) {
                $leftEntry = $entries[$left];
                $rightEntry = $entries[$right];
                $leftAllowed = array_filter((array) data_get($leftEntry, 'allowed_files', []));
                $rightAllowed = array_filter((array) data_get($rightEntry, 'allowed_files', []));
                $overlap = WriteSetOverlap::collidingPaths($leftAllowed, $rightAllowed); // A5/MF-12: prefix-aware dir-vs-file
                $leftDepends = (array) data_get($leftEntry, 'depends_on', []);
                $rightDepends = (array) data_get($rightEntry, 'depends_on', []);
                $dependencyRelated = in_array(data_get($leftEntry, 'packet_id'), $rightDepends, true)
                    || in_array(data_get($rightEntry, 'packet_id'), $leftDepends, true);
                $hotScopePresent = data_get($leftEntry, 'queue_state') === 'withheld'
                    || data_get($rightEntry, 'queue_state') === 'withheld'
                    || $this->hasHotScope($leftAllowed)
                    || $this->hasHotScope($rightAllowed);
                $collision = $overlap !== [] || $dependencyRelated || $hotScopePresent;

                $pairs[] = [
                    'left_packet_id' => data_get($leftEntry, 'packet_id'),
                    'right_packet_id' => data_get($rightEntry, 'packet_id'),
                    'overlap' => $overlap,
                    'dependency_related' => $dependencyRelated,
                    'hot_scope_present' => $hotScopePresent,
                    'collision' => $collision,
                    'decision' => $collision ? 'blocked' : 'parallel_safe',
                ];
            }
        }

        $safePackets = array_values(array_map(
            fn (array $entry): string => (string) data_get($entry, 'packet_id'),
            array_filter($entries, fn (array $entry): bool => data_get($entry, 'queue_state') === 'available'),
        ));

        $matrix = [
            'matrix_id' => 'COLLISION-MATRIX-SELF-CONSTRUCTION-READ-ONLY-0001',
            'source_queue_hash' => data_get($queuePayload, 'queue_hash'),
            'entry_count' => count($entries),
            'pair_count' => count($pairs),
            'safe_pair_count' => count(array_filter($pairs, fn (array $pair): bool => $pair['decision'] === 'parallel_safe')),
            'blocked_pair_count' => count(array_filter($pairs, fn (array $pair): bool => $pair['decision'] === 'blocked')),
            'pairs' => $pairs,
            'safe_parallel_groups' => [
                [
                    'group_id' => 'SAFE-PARALLEL-GROUP-001',
                    'packet_ids' => $safePackets,
                    'execution_allowed' => false,
                    'claim_persisted' => false,
                ],
            ],
            'execution_allowed' => false,
            'claim_persisted' => false,
            'ledger_write_allowed' => false,
            'dispatch_allowed' => false,
        ];

        return [
            'schema_version' => 'atlas.self_construction_collision_matrix.v1',
            'status' => 'collision_matrix_ready',
            'mode' => 'read_only_collision_matrix',
            'execution_allowed' => false,
            'completion_allowed' => false,
            'claim_persisted' => false,
            'ledger_write_allowed' => false,
            'dispatch_allowed' => false,
            'matrix' => $matrix,
            'matrix_hash' => $this->stableHash($matrix),
            'non_execution_guarantees' => [
                'collision_matrix_does_not_persist_claim',
                'collision_matrix_does_not_write_ledger',
                'collision_matrix_does_not_dispatch_work',
                'collision_matrix_does_not_enable_execution',
            ],
            'human_summary' => 'Collision matrix is ready: packet overlap and hot scopes are visible without claims, dispatch, ledger writes or execution.',
        ];
    }

    public function dependencyUnlockPlan(array $options = []): array
    {
        return $this->miscProjectionsPart2Section()->dependencyUnlockPlan($options);
    }

    public function multiSessionReadinessGate(array $options = []): array
    {
        return $this->miscProjectionsPart3Section()->multiSessionReadinessGate($options);
    }
    public function forgeWorkspaceStatus(array $options = []): array
    {
        return $this->forgeWorkspaceSection()->forgeWorkspaceStatus($options);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlane(array $options = []): array
    {
        return $this->agentControlPlaneSection()->agentControlPlane($options);
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
        public function agentProviderAdapterInvocationRuntimePolicy(array $options = []): array
    {
        return $this->agentDispatchProviderSection()->agentProviderAdapterInvocationRuntimePolicy($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
        public function agentProviderProcessSupervisionPolicy(array $options = []): array
    {
        return $this->agentDispatchProviderSection()->agentProviderProcessSupervisionPolicy($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticCostImportPolicy(array $options = []): array
    {
        $processSupervisionPayload = $this->agentProviderProcessSupervisionPolicy($options);
        $runtimeTables = $this->agentControlPlaneRuntimeTables();

        $componentReadiness = [
            'provider_process_supervision_policy' => data_get($processSupervisionPayload, 'status') === 'agent_provider_process_supervision_policy_ready',
            'agent_runs_table' => $runtimeTables['atlas_self_construction_agent_runs'],
            'agent_cost_events_table' => $runtimeTables['atlas_self_construction_agent_cost_events'],
            'agent_cost_event_model' => class_exists(AtlasSelfConstructionAgentCostEvent::class),
            'manual_cost_event_writer' => method_exists($this, 'agentCostEvent'),
        ];
        $blockingReasons = array_values(array_map(
            static fn (string $component): string => $component.'_not_ready',
            array_keys(array_filter($componentReadiness, static fn (bool $ready): bool => ! $ready))
        ));

        $policy = [
            'status' => $blockingReasons === [] ? 'agent_automatic_cost_import_policy_ready' : 'blocked',
            'policy_id' => 'AGENT-AUTOMATIC-COST-IMPORT-POLICY-SELF-CONSTRUCTION-0001',
            'parent_program' => 'Atlas Self-Construction OS',
            'submodule' => 'Atlas Agent Control Plane',
            'component_readiness' => $componentReadiness,
            'component_preflight_hashes' => [
                'provider_process_supervision_policy' => data_get($processSupervisionPayload, 'agent_provider_process_supervision_policy_hash'),
            ],
            'blocking_count' => count($blockingReasons),
            'blocking_reasons' => $blockingReasons,
            'import_sources_allowed_after_release' => [
                'provider_adapter_usage_payload',
                'supervised_process_usage_summary',
                'operator_supplied_cost_event',
                'provider_usage_export_reconciled_by_run_key',
            ],
            'normalization_contract' => [
                'required_identity' => ['run_key', 'provider', 'model', 'occurred_at', 'source_hash'],
                'required_metrics' => ['input_tokens', 'output_tokens', 'cost_usd'],
                'required_metadata' => ['packet_id', 'actor', 'session', 'import_source', 'import_policy_hash'],
                'idempotency_key' => 'sha256(run_key|provider|model|occurred_at|source_hash)',
            ],
            'cost_quality_guards' => [
                'reject_negative_tokens',
                'reject_negative_cost',
                'dedupe_by_idempotency_key',
                'attach_cost_to_existing_agent_run_only',
                'preserve_raw_usage_hash_without_storing_sensitive_prompt_text',
                'mark_unpriced_usage_as_zero_cost_with_pricing_missing_reason',
            ],
            'allowed_now' => [
                'automatic_cost_import_policy_projection',
                'cost_schema_readiness_check',
                'manual_cost_writer_contract_reference',
            ],
            'forbidden_now' => [
                'read_provider_billing_api',
                'parse_live_provider_logs',
                'write_cost_events_automatically',
                'mutate_run_totals_from_import_policy',
                'start_or_supervise_provider_process',
                'spend_provider_tokens',
                'self_program_or_self_merge',
            ],
            'activation_policy' => [
                'policy_is_read_only' => true,
                'automatic_import_allowed_here' => false,
                'runtime_write_allowed_here' => false,
                'billing_api_access_allowed_here' => false,
                'requires_future_signed_cost_import_execution_gate' => true,
            ],
            'next_required_slice' => $blockingReasons === []
                ? 'activate_automatic_dispatch_scheduler_policy'
                : 'repair_automatic_cost_import_policy_blockers',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_cost_import_policy.v1',
            'status' => (string) $policy['status'],
            'mode' => 'read_only_agent_automatic_cost_import_policy',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'automatic_import_allowed' => false,
            'billing_api_access_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_cost_import_policy' => $policy,
            'agent_automatic_cost_import_policy_hash' => $this->stableHash($policy),
            'non_execution_guarantees' => [
                'agent_automatic_cost_import_policy_does_not_call_billing_apis',
                'agent_automatic_cost_import_policy_does_not_parse_live_provider_logs',
                'agent_automatic_cost_import_policy_does_not_write_cost_events',
                'agent_automatic_cost_import_policy_does_not_start_providers',
                'agent_automatic_cost_import_policy_does_not_enable_self_programming',
            ],
            'human_summary' => $blockingReasons === []
                ? 'Automatic cost import policy is ready: Atlas has the normalization and guard contract, but automatic import remains disabled until a signed execution gate.'
                : 'Automatic cost import policy is blocked until every cost import prerequisite is ready.',
        ];
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticWorkProductCollectionPolicy(array $options = []): array
    {
        $costImportPayload = $this->agentAutomaticCostImportPolicy($options);
        $runtimeTables = $this->agentControlPlaneRuntimeTables();

        $componentReadiness = [
            'automatic_cost_import_policy' => data_get($costImportPayload, 'status') === 'agent_automatic_cost_import_policy_ready',
            'agent_runs_table' => $runtimeTables['atlas_self_construction_agent_runs'],
            'agent_work_products_table' => $runtimeTables['atlas_self_construction_agent_work_products'],
            'agent_work_product_model' => class_exists(AtlasSelfConstructionAgentWorkProduct::class),
            'manual_work_product_registry' => method_exists($this, 'agentWorkProduct'),
        ];
        $blockingReasons = array_values(array_map(
            static fn (string $component): string => $component.'_not_ready',
            array_keys(array_filter($componentReadiness, static fn (bool $ready): bool => ! $ready))
        ));

        $policy = [
            'status' => $blockingReasons === [] ? 'agent_automatic_work_product_collection_policy_ready' : 'blocked',
            'policy_id' => 'AGENT-AUTOMATIC-WORK-PRODUCT-COLLECTION-POLICY-SELF-CONSTRUCTION-0001',
            'parent_program' => 'Atlas Self-Construction OS',
            'submodule' => 'Atlas Agent Control Plane',
            'component_readiness' => $componentReadiness,
            'component_preflight_hashes' => [
                'automatic_cost_import_policy' => data_get($costImportPayload, 'agent_automatic_cost_import_policy_hash'),
            ],
            'blocking_count' => count($blockingReasons),
            'blocking_reasons' => $blockingReasons,
            'collection_sources_allowed_after_release' => [
                'provider_adapter_return_envelope',
                'supervised_process_output_manifest',
                'operator_supplied_artifact_pointer',
                'workspace_diff_manifest',
                'test_and_gate_output_manifest',
            ],
            'normalization_contract' => [
                'required_identity' => ['run_key', 'packet_id', 'artifact_type', 'artifact_hash', 'source_hash'],
                'required_metadata' => ['actor', 'session', 'provider', 'collection_source', 'collection_policy_hash'],
                'idempotency_key' => 'sha256(run_key|packet_id|artifact_type|artifact_hash|source_hash)',
                'artifact_hash_algorithm' => 'sha256',
            ],
            'work_product_quality_guards' => [
                'reject_artifact_without_existing_agent_run',
                'reject_path_outside_allowed_workspace_scope',
                'require_hash_for_patch_diff_test_output_and_report_artifacts',
                'dedupe_by_idempotency_key',
                'store_pointer_and_hash_not_sensitive_raw_payload_by_default',
                'link_artifact_to_packet_run_actor_provider_and_evidence_chain',
            ],
            'allowed_now' => [
                'automatic_work_product_collection_policy_projection',
                'work_product_schema_readiness_check',
                'manual_work_product_registry_contract_reference',
            ],
            'forbidden_now' => [
                'scan_workspace_files_automatically',
                'read_provider_output_streams',
                'write_work_products_automatically',
                'mutate_packet_completion_state',
                'start_or_supervise_provider_process',
                'dispatch_work_to_provider',
                'self_program_or_self_merge',
            ],
            'activation_policy' => [
                'policy_is_read_only' => true,
                'automatic_collection_allowed_here' => false,
                'runtime_write_allowed_here' => false,
                'workspace_scan_allowed_here' => false,
                'requires_future_signed_work_product_collection_execution_gate' => true,
            ],
            'next_required_slice' => $blockingReasons === []
                ? 'activate_automatic_dispatch_scheduler_runtime_execution_gate'
                : 'repair_automatic_work_product_collection_policy_blockers',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_work_product_collection_policy.v1',
            'status' => (string) $policy['status'],
            'mode' => 'read_only_agent_automatic_work_product_collection_policy',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'automatic_collection_allowed' => false,
            'workspace_scan_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_work_product_collection_policy' => $policy,
            'agent_automatic_work_product_collection_policy_hash' => $this->stableHash($policy),
            'non_execution_guarantees' => [
                'agent_automatic_work_product_collection_policy_does_not_scan_workspace',
                'agent_automatic_work_product_collection_policy_does_not_read_provider_streams',
                'agent_automatic_work_product_collection_policy_does_not_write_work_products',
                'agent_automatic_work_product_collection_policy_does_not_start_providers',
                'agent_automatic_work_product_collection_policy_does_not_enable_self_programming',
            ],
            'human_summary' => $blockingReasons === []
                ? 'Automatic work product collection policy is ready: Atlas has the artifact normalization and guard contract, but automatic collection remains disabled until a signed execution gate.'
                : 'Automatic work product collection policy is blocked until every artifact collection prerequisite is ready.',
        ];
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerPolicy(array $options = []) : array
    {
        return $this->agentAutomaticDispatchBatch1Section()->agentAutomaticDispatchSchedulerPolicy($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerRuntimeExecutionGate(array $options = []) : array
    {
        return $this->agentAutomaticDispatchBatch1Section()->agentAutomaticDispatchSchedulerRuntimeExecutionGate($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerDryRunTick(array $options = []) : array
    {
        return $this->mutatingWriterSection()->agentAutomaticDispatchSchedulerDryRunTick($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickWriterContract(array $options = []) : array
    {
        return $this->agentAutomaticDispatchBatch1Section()->agentAutomaticDispatchSchedulerOneShotTickWriterContract($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickWriterPreflight(array $options = []) : array
    {
        return $this->releaseWriterSection()->agentAutomaticDispatchSchedulerOneShotTickWriterPreflight($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickReleaseTemplate(array $options = []) : array
    {
        return $this->releaseWriterSection()->agentAutomaticDispatchSchedulerOneShotTickReleaseTemplate($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickReleaseReceiptDraft(array $options = []) : array
    {
        return $this->releaseWriterSection()->agentAutomaticDispatchSchedulerOneShotTickReleaseReceiptDraft($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickReleaseReceiptValidationPreflight(array $options = []) : array
    {
        return $this->agentAutomaticDispatchBatch1Section()->agentAutomaticDispatchSchedulerOneShotTickReleaseReceiptValidationPreflight($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickReleaseReceiptPersistenceContract(array $options = []) : array
    {
        return $this->releaseWriterSection()->agentAutomaticDispatchSchedulerOneShotTickReleaseReceiptPersistenceContract($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickReleaseReceiptPersistenceWriterPreflight(array $options = []) : array
    {
        return $this->releaseWriterSection()->agentAutomaticDispatchSchedulerOneShotTickReleaseReceiptPersistenceWriterPreflight($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickReleaseReceiptPersistenceWriterImplementationPacket(array $options = []) : array
    {
        return $this->mutatingWriterSection()->agentAutomaticDispatchSchedulerOneShotTickReleaseReceiptPersistenceWriterImplementationPacket($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickReleaseReceiptPersistenceStatus(array $options = []) : array
    {
        return $this->agentAutomaticDispatchBatch2Section()->agentAutomaticDispatchSchedulerOneShotTickReleaseReceiptPersistenceStatus($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickMutatingWriterReleasePreflight(array $options = []) : array
    {
        return $this->mutatingWriterSection()->agentAutomaticDispatchSchedulerOneShotTickMutatingWriterReleasePreflight($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickMutatingWriterContract(array $options = []) : array
    {
        return $this->releaseWriterSection()->agentAutomaticDispatchSchedulerOneShotTickMutatingWriterContract($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickMutatingWriterPreflight(array $options = []) : array
    {
        return $this->mutatingWriterSection()->agentAutomaticDispatchSchedulerOneShotTickMutatingWriterPreflight($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickMutatingWriterImplementationPacket(array $options = []) : array
    {
        return $this->mutatingWriterSection()->agentAutomaticDispatchSchedulerOneShotTickMutatingWriterImplementationPacket($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickMutatingWriterStatus(array $options = []) : array
    {
        return $this->agentAutomaticDispatchBatch2Section()->agentAutomaticDispatchSchedulerOneShotTickMutatingWriterStatus($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickGuardedRuntimeInvocationContract(array $options = []) : array
    {
        return $this->agentAutomaticDispatchBatch1Section()->agentAutomaticDispatchSchedulerOneShotTickGuardedRuntimeInvocationContract($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickGuardedRuntimeInvocationPreflight(array $options = []) : array
    {
        return $this->agentAutomaticDispatchBatch2Section()->agentAutomaticDispatchSchedulerOneShotTickGuardedRuntimeInvocationPreflight($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickGuardedRuntimeInvocationImplementationPacket(array $options = []) : array
    {
        return $this->agentAutomaticDispatchBatch1Section()->agentAutomaticDispatchSchedulerOneShotTickGuardedRuntimeInvocationImplementationPacket($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickGuardedRuntimeInvocationStatus(array $options = []) : array
    {
        return $this->agentAutomaticDispatchBatch2Section()->agentAutomaticDispatchSchedulerOneShotTickGuardedRuntimeInvocationStatus($options);
    }

    public function agentAutomaticDispatchSchedulerOneShotTickDispatchReceiptUseReleaseContract(array $options = []): array
    {
        return $this->agentAutomaticTailPart1Section()->agentAutomaticDispatchSchedulerOneShotTickDispatchReceiptUseReleaseContract($options);
    }

    public function agentAutomaticDispatchSchedulerOneShotTickDispatchReceiptUsePreflight(array $options = []): array
    {
        return $this->agentAutomaticTailPart1Section()->agentAutomaticDispatchSchedulerOneShotTickDispatchReceiptUsePreflight($options);
    }

    public function agentAutomaticDispatchSchedulerOneShotTickDispatchReceiptUseImplementationPacket(array $options = []): array
    {
        return $this->agentAutomaticTailPart1Section()->agentAutomaticDispatchSchedulerOneShotTickDispatchReceiptUseImplementationPacket($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickDispatchReceiptUseStatus(array $options = []) : array
    {
        return $this->agentAutomaticDispatchBatch2Section()->agentAutomaticDispatchSchedulerOneShotTickDispatchReceiptUseStatus($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickProviderStartDriverReleaseContract(array $options = []) : array
    {
        return $this->agentAutomaticDispatchBatch2Section()->agentAutomaticDispatchSchedulerOneShotTickProviderStartDriverReleaseContract($options);
    }

    public function agentAutomaticDispatchSchedulerOneShotTickProviderStartDriverPreflight(array $options = []): array
    {
        return $this->agentAutomaticTailPart1Section()->agentAutomaticDispatchSchedulerOneShotTickProviderStartDriverPreflight($options);
    }

    public function agentAutomaticDispatchSchedulerOneShotTickProviderStartDriverImplementationPacket(array $options = []): array
    {
        return $this->agentAutomaticTailPart1Section()->agentAutomaticDispatchSchedulerOneShotTickProviderStartDriverImplementationPacket($options);
    }

    public function agentAutomaticDispatchSchedulerOneShotTickProviderStartDriverStatus(array $options = []): array
    {
        return $this->agentAutomaticTailPart1Section()->agentAutomaticDispatchSchedulerOneShotTickProviderStartDriverStatus($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickAdapterInvocationBoundaryReleaseContract(array $options = []) : array
    {
        return $this->agentAutomaticDispatchBatch2Section()->agentAutomaticDispatchSchedulerOneShotTickAdapterInvocationBoundaryReleaseContract($options);
    }

    public function agentAutomaticDispatchSchedulerOneShotTickAdapterInvocationBoundaryPreflight(array $options = []): array
    {
        return $this->agentAutomaticTailPart1Section()->agentAutomaticDispatchSchedulerOneShotTickAdapterInvocationBoundaryPreflight($options);
    }

    public function agentAutomaticDispatchSchedulerOneShotTickAdapterInvocationBoundaryImplementationPacket(array $options = []): array
    {
        return $this->agentAutomaticTailPart1Section()->agentAutomaticDispatchSchedulerOneShotTickAdapterInvocationBoundaryImplementationPacket($options);
    }

    public function agentAutomaticDispatchSchedulerOneShotTickAdapterInvocationBoundaryStatus(array $options = []): array
    {
        return $this->agentAutomaticTailPart2Section()->agentAutomaticDispatchSchedulerOneShotTickAdapterInvocationBoundaryStatus($options);
    }

    public function agentAutomaticDispatchSchedulerOneShotTickProviderAdapterExecutionGuardReleaseContract(array $options = []): array
    {
        return $this->agentAutomaticTailPart2Section()->agentAutomaticDispatchSchedulerOneShotTickProviderAdapterExecutionGuardReleaseContract($options);
    }

    public function agentAutomaticDispatchSchedulerOneShotTickProviderAdapterExecutionGuardPreflight(array $options = []): array
    {
        return $this->agentAutomaticTailPart2Section()->agentAutomaticDispatchSchedulerOneShotTickProviderAdapterExecutionGuardPreflight($options);
    }

    public function agentAutomaticDispatchSchedulerOneShotTickProviderAdapterExecutionGuardImplementationPacket(array $options = []): array
    {
        return $this->agentAutomaticTailPart2Section()->agentAutomaticDispatchSchedulerOneShotTickProviderAdapterExecutionGuardImplementationPacket($options);
    }

    public function agentAutomaticDispatchSchedulerOneShotTickProviderAdapterExecutionGuardStatus(array $options = []): array
    {
        return $this->agentAutomaticTailPart2Section()->agentAutomaticDispatchSchedulerOneShotTickProviderAdapterExecutionGuardStatus($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickProviderSpecificExecutionContractRelease(array $options = []) : array
    {
        return $this->agentAutomaticDispatchBatch2Section()->agentAutomaticDispatchSchedulerOneShotTickProviderSpecificExecutionContractRelease($options);
    }

    public function agentAutomaticDispatchSchedulerOneShotTickProviderSpecificExecutionContractPreflight(array $options = []): array
    {
        return $this->agentAutomaticTailPart2Section()->agentAutomaticDispatchSchedulerOneShotTickProviderSpecificExecutionContractPreflight($options);
    }

    public function agentAutomaticDispatchSchedulerOneShotTickProviderSpecificExecutionContractImplementationPacket(array $options = []): array
    {
        return $this->agentAutomaticTailPart2Section()->agentAutomaticDispatchSchedulerOneShotTickProviderSpecificExecutionContractImplementationPacket($options);
    }

    public function agentAutomaticDispatchSchedulerOneShotTickProviderSpecificExecutionContractStatus(array $options = []): array
    {
        return $this->agentAutomaticTailPart2Section()->agentAutomaticDispatchSchedulerOneShotTickProviderSpecificExecutionContractStatus($options);
    }

    public function agentAutomaticDispatchSchedulerOneShotTickCodexProcessStartReleaseContract(array $options = []): array
    {
        return $this->oneShotTickCodexPart1Section()->agentAutomaticDispatchSchedulerOneShotTickCodexProcessStartReleaseContract($options);
    }

    public function agentAutomaticDispatchSchedulerOneShotTickCodexProcessStartReleasePreflight(array $options = []): array
    {
        return $this->oneShotTickCodexPart1Section()->agentAutomaticDispatchSchedulerOneShotTickCodexProcessStartReleasePreflight($options);
    }

    public function agentAutomaticDispatchSchedulerOneShotTickCodexProcessStartReleaseImplementationPacket(array $options = []): array
    {
        return $this->oneShotTickCodexPart1Section()->agentAutomaticDispatchSchedulerOneShotTickCodexProcessStartReleaseImplementationPacket($options);
    }

    public function agentAutomaticDispatchSchedulerOneShotTickCodexProcessStartReleaseStatus(array $options = []): array
    {
        return $this->oneShotTickCodexPart1Section()->agentAutomaticDispatchSchedulerOneShotTickCodexProcessStartReleaseStatus($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexSupervisedStartExecutorReleaseContract(array $options = []) : array
    {
        return $this->agentAutomaticDispatchBatch2Section()->agentAutomaticDispatchSchedulerOneShotTickCodexSupervisedStartExecutorReleaseContract($options);
    }

    public function agentAutomaticDispatchSchedulerOneShotTickCodexSupervisedStartExecutorPreflight(array $options = []): array
    {
        return $this->oneShotTickCodexPart1Section()->agentAutomaticDispatchSchedulerOneShotTickCodexSupervisedStartExecutorPreflight($options);
    }

    public function agentAutomaticDispatchSchedulerOneShotTickCodexSupervisedStartExecutorImplementationPacket(array $options = []): array
    {
        return $this->oneShotTickCodexPart1Section()->agentAutomaticDispatchSchedulerOneShotTickCodexSupervisedStartExecutorImplementationPacket($options);
    }

    public function agentAutomaticDispatchSchedulerOneShotTickCodexSupervisedStartExecutorStatus(array $options = []): array
    {
        return $this->oneShotTickCodexPart1Section()->agentAutomaticDispatchSchedulerOneShotTickCodexSupervisedStartExecutorStatus($options);
    }

    public function agentAutomaticDispatchSchedulerOneShotTickCodexProcessSpawnEnablementContract(array $options = []): array
    {
        return $this->oneShotTickCodexPart1Section()->agentAutomaticDispatchSchedulerOneShotTickCodexProcessSpawnEnablementContract($options);
    }

    public function agentAutomaticDispatchSchedulerOneShotTickCodexProcessSpawnEnablementPreflight(array $options = []): array
    {
        return $this->oneShotTickCodexPart1Section()->agentAutomaticDispatchSchedulerOneShotTickCodexProcessSpawnEnablementPreflight($options);
    }

    public function agentAutomaticDispatchSchedulerOneShotTickCodexProcessSpawnEnablementImplementationPacket(array $options = []): array
    {
        return $this->oneShotTickCodexPart1Section()->agentAutomaticDispatchSchedulerOneShotTickCodexProcessSpawnEnablementImplementationPacket($options);
    }

    public function agentAutomaticDispatchSchedulerOneShotTickCodexProcessSpawnEnablementStatus(array $options = []): array
    {
        return $this->oneShotTickCodexPart1Section()->agentAutomaticDispatchSchedulerOneShotTickCodexProcessSpawnEnablementStatus($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexFinalProcessSpawnExecutorContract(array $options = []) : array
    {
        return $this->agentAutomaticDispatchBatch2Section()->agentAutomaticDispatchSchedulerOneShotTickCodexFinalProcessSpawnExecutorContract($options);
    }

    public function agentAutomaticDispatchSchedulerOneShotTickCodexFinalProcessSpawnExecutorPreflight(array $options = []): array
    {
        return $this->oneShotTickCodexPart1Section()->agentAutomaticDispatchSchedulerOneShotTickCodexFinalProcessSpawnExecutorPreflight($options);
    }

    public function agentAutomaticDispatchSchedulerOneShotTickCodexFinalProcessSpawnExecutorImplementationPacket(array $options = []): array
    {
        return $this->oneShotTickCodexPart2Section()->agentAutomaticDispatchSchedulerOneShotTickCodexFinalProcessSpawnExecutorImplementationPacket($options);
    }

    public function agentAutomaticDispatchSchedulerOneShotTickCodexFinalProcessSpawnExecutorStatus(array $options = []): array
    {
        return $this->oneShotTickCodexPart2Section()->agentAutomaticDispatchSchedulerOneShotTickCodexFinalProcessSpawnExecutorStatus($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexExternalProcessRuntimeDriverContract(array $options = []) : array
    {
        return $this->agentAutomaticDispatchBatch2Section()->agentAutomaticDispatchSchedulerOneShotTickCodexExternalProcessRuntimeDriverContract($options);
    }

    public function agentAutomaticDispatchSchedulerOneShotTickCodexExternalProcessRuntimeDriverPreflight(array $options = []): array
    {
        return $this->oneShotTickCodexPart2Section()->agentAutomaticDispatchSchedulerOneShotTickCodexExternalProcessRuntimeDriverPreflight($options);
    }

    public function agentAutomaticDispatchSchedulerOneShotTickCodexExternalProcessRuntimeDriverImplementationPacket(array $options = []): array
    {
        return $this->oneShotTickCodexPart2Section()->agentAutomaticDispatchSchedulerOneShotTickCodexExternalProcessRuntimeDriverImplementationPacket($options);
    }

    public function agentAutomaticDispatchSchedulerOneShotTickCodexExternalProcessRuntimeDriverStatus(array $options = []): array
    {
        return $this->oneShotTickCodexPart2Section()->agentAutomaticDispatchSchedulerOneShotTickCodexExternalProcessRuntimeDriverStatus($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexProcessInvocationAuthorizationContract(array $options = []) : array
    {
        return $this->agentAutomaticDispatchBatch2Section()->agentAutomaticDispatchSchedulerOneShotTickCodexProcessInvocationAuthorizationContract($options);
    }

    public function agentAutomaticDispatchSchedulerOneShotTickCodexProcessInvocationAuthorizationPreflight(array $options = []): array
    {
        return $this->oneShotTickCodexPart2Section()->agentAutomaticDispatchSchedulerOneShotTickCodexProcessInvocationAuthorizationPreflight($options);
    }

    public function agentAutomaticDispatchSchedulerOneShotTickCodexProcessInvocationAuthorizationImplementationPacket(array $options = []): array
    {
        return $this->oneShotTickCodexPart2Section()->agentAutomaticDispatchSchedulerOneShotTickCodexProcessInvocationAuthorizationImplementationPacket($options);
    }

    public function agentAutomaticDispatchSchedulerOneShotTickCodexProcessInvocationAuthorizationStatus(array $options = []): array
    {
        return $this->oneShotTickCodexPart2Section()->agentAutomaticDispatchSchedulerOneShotTickCodexProcessInvocationAuthorizationStatus($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexExternalProcessInvokerDryRunContract(array $options = []) : array
    {
        return $this->agentAutomaticDispatchBatch2Section()->agentAutomaticDispatchSchedulerOneShotTickCodexExternalProcessInvokerDryRunContract($options);
    }

    public function agentAutomaticDispatchSchedulerOneShotTickCodexExternalProcessInvokerDryRunPreflight(array $options = []): array
    {
        return $this->oneShotTickCodexPart2Section()->agentAutomaticDispatchSchedulerOneShotTickCodexExternalProcessInvokerDryRunPreflight($options);
    }

    public function agentAutomaticDispatchSchedulerOneShotTickCodexExternalProcessInvokerDryRunImplementationPacket(array $options = []): array
    {
        return $this->oneShotTickCodexPart2Section()->agentAutomaticDispatchSchedulerOneShotTickCodexExternalProcessInvokerDryRunImplementationPacket($options);
    }

    public function agentAutomaticDispatchSchedulerOneShotTickCodexExternalProcessInvokerDryRunStatus(array $options = []): array
    {
        return $this->oneShotTickCodexPart2Section()->agentAutomaticDispatchSchedulerOneShotTickCodexExternalProcessInvokerDryRunStatus($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerReleasePreflightContract(array $options = []) : array
    {
        return $this->agentAutomaticDispatchBatch1Section()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerReleasePreflightContract($options);
    }

    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerReleasePreflightPreflight(array $options = []): array
    {
        return $this->oneShotTickCodexPart2Section()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerReleasePreflightPreflight($options);
    }

    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerReleasePreflightImplementationPacket(array $options = []): array
    {
        return $this->oneShotTickCodexPart3Section()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerReleasePreflightImplementationPacket($options);
    }

    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerReleasePreflightStatus(array $options = []): array
    {
        return $this->oneShotTickCodexPart3Section()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerReleasePreflightStatus($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexSignedRealInvokerReleaseGateContract(array $options = []) : array
    {
        return $this->agentAutomaticDispatchBatch1Section()->agentAutomaticDispatchSchedulerOneShotTickCodexSignedRealInvokerReleaseGateContract($options);
    }

    public function agentAutomaticDispatchSchedulerOneShotTickCodexSignedRealInvokerReleaseGatePreflight(array $options = []): array
    {
        return $this->oneShotTickCodexPart3Section()->agentAutomaticDispatchSchedulerOneShotTickCodexSignedRealInvokerReleaseGatePreflight($options);
    }

    public function agentAutomaticDispatchSchedulerOneShotTickCodexSignedRealInvokerReleaseGateImplementationPacket(array $options = []): array
    {
        return $this->oneShotTickCodexPart3Section()->agentAutomaticDispatchSchedulerOneShotTickCodexSignedRealInvokerReleaseGateImplementationPacket($options);
    }

    public function agentAutomaticDispatchSchedulerOneShotTickCodexSignedRealInvokerReleaseGateStatus(array $options = []): array
    {
        return $this->oneShotTickCodexPart3Section()->agentAutomaticDispatchSchedulerOneShotTickCodexSignedRealInvokerReleaseGateStatus($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerImplementationBoundaryContract(array $options = []) : array
    {
        return $this->agentAutomaticDispatchBatch1Section()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerImplementationBoundaryContract($options);
    }

    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerImplementationBoundaryPreflight(array $options = []): array
    {
        return $this->oneShotTickCodexPart3Section()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerImplementationBoundaryPreflight($options);
    }

    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerImplementationBoundaryImplementationPacket(array $options = []): array
    {
        return $this->oneShotTickCodexPart3Section()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerImplementationBoundaryImplementationPacket($options);
    }

    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerImplementationBoundaryStatus(array $options = []): array
    {
        return $this->oneShotTickCodexPart3Section()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerImplementationBoundaryStatus($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerExecutorPlanContract(array $options = []) : array
    {
        return $this->agentAutomaticDispatchBatch1Section()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerExecutorPlanContract($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerExecutorPlanPreflight(array $options = []) : array
    {
        return $this->agentAutomaticDispatchBatch2Section()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerExecutorPlanPreflight($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerExecutorPlanImplementationPacket(array $options = []) : array
    {
        return $this->agentAutomaticDispatchBatch2Section()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerExecutorPlanImplementationPacket($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerExecutorPlanStatus(array $options = []) : array
    {
        return $this->agentAutomaticDispatchBatch2Section()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerExecutorPlanStatus($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerExecutorFreshReleaseGateContract(array $options = []) : array
    {
        return $this->agentAutomaticDispatchBatch1Section()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerExecutorFreshReleaseGateContract($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerExecutorFreshReleaseGatePreflight(array $options = []) : array
    {
        return $this->agentAutomaticDispatchBatch2Section()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerExecutorFreshReleaseGatePreflight($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerExecutorFreshReleaseGateImplementationPacket(array $options = []) : array
    {
        return $this->agentAutomaticDispatchBatch2Section()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerExecutorFreshReleaseGateImplementationPacket($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerExecutorFreshReleaseGateStatus(array $options = []) : array
    {
        return $this->agentAutomaticDispatchBatch2Section()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerExecutorFreshReleaseGateStatus($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerExecutorEnablementGateContract(array $options = []) : array
    {
        return $this->agentAutomaticDispatchBatch1Section()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerExecutorEnablementGateContract($options);
    }

    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerExecutorEnablementGatePreflight(array $options = []): array
    {
        return $this->oneShotTickCodexPart3Section()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerExecutorEnablementGatePreflight($options);
    }

    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerExecutorEnablementGateImplementationPacket(array $options = []): array
    {
        return $this->oneShotTickCodexPart3Section()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerExecutorEnablementGateImplementationPacket($options);
    }

    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerExecutorEnablementGateStatus(array $options = []): array
    {
        return $this->oneShotTickCodexPart3Section()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerExecutorEnablementGateStatus($options);
    }

    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerSupervisedStartActivationGateContract(array $options = []): array
    {
        return $this->oneShotTickCodexPart3Section()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerSupervisedStartActivationGateContract($options);
    }

    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerSupervisedStartActivationGatePreflight(array $options = []): array
    {
        return $this->oneShotTickCodexPart4Section()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerSupervisedStartActivationGatePreflight($options);
    }

    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerSupervisedStartActivationGateImplementationPacket(array $options = []): array
    {
        return $this->oneShotTickCodexPart4Section()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerSupervisedStartActivationGateImplementationPacket($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerSupervisedStartActivationGateStatus(array $options = []) : array
    {
        return $this->agentAutomaticDispatchBatch2Section()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerSupervisedStartActivationGateStatus($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerGuardedProcessStartExecutorContract(array $options = []) : array
    {
        return $this->agentAutomaticDispatchBatch2Section()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerGuardedProcessStartExecutorContract($options);
    }

    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerGuardedProcessStartExecutorPreflight(array $options = []): array
    {
        return $this->oneShotTickCodexPart4Section()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerGuardedProcessStartExecutorPreflight($options);
    }

    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerGuardedProcessStartExecutorImplementationPacket(array $options = []): array
    {
        return $this->oneShotTickCodexPart4Section()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerGuardedProcessStartExecutorImplementationPacket($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerGuardedProcessStartExecutorStatus(array $options = []) : array
    {
        return $this->agentAutomaticDispatchBatch2Section()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerGuardedProcessStartExecutorStatus($options);
    }

    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerFinalProcessStartAuthorizationGateContract(array $options = []): array
    {
        return $this->oneShotTickCodexPart4Section()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerFinalProcessStartAuthorizationGateContract($options);
    }

    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerFinalProcessStartAuthorizationGatePreflight(array $options = []): array
    {
        return $this->oneShotTickCodexPart4Section()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerFinalProcessStartAuthorizationGatePreflight($options);
    }

    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerFinalProcessStartAuthorizationGateImplementationPacket(array $options = []): array
    {
        return $this->oneShotTickCodexPart4Section()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerFinalProcessStartAuthorizationGateImplementationPacket($options);
    }

    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerFinalProcessStartAuthorizationGateStatus(array $options = []): array
    {
        return $this->oneShotTickCodexPart4Section()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerFinalProcessStartAuthorizationGateStatus($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerActualProcessStartRehearsalExecutorContract(array $options = []) : array
    {
        return $this->agentAutomaticDispatchBatch2Section()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerActualProcessStartRehearsalExecutorContract($options);
    }

    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerActualProcessStartRehearsalExecutorPreflight(array $options = []): array
    {
        return $this->oneShotTickCodexPart4Section()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerActualProcessStartRehearsalExecutorPreflight($options);
    }

    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerActualProcessStartRehearsalExecutorImplementationPacket(array $options = []): array
    {
        return $this->oneShotTickCodexPart4Section()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerActualProcessStartRehearsalExecutorImplementationPacket($options);
    }

    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerActualProcessStartRehearsalExecutorStatus(array $options = []): array
    {
        return $this->oneShotTickCodexPart4Section()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerActualProcessStartRehearsalExecutorStatus($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerProcessStartEnvelopeBuilderContract(array $options = []) : array
    {
        return $this->agentAutomaticDispatchBatch2Section()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerProcessStartEnvelopeBuilderContract($options);
    }

    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerProcessStartEnvelopeBuilderPreflight(array $options = []): array
    {
        return $this->oneShotTickCodexPart4Section()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerProcessStartEnvelopeBuilderPreflight($options);
    }

    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerProcessStartEnvelopeBuilderImplementationPacket(array $options = []): array
    {
        return $this->oneShotTickCodexPart5Section()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerProcessStartEnvelopeBuilderImplementationPacket($options);
    }

    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerProcessStartEnvelopeBuilderStatus(array $options = []): array
    {
        return $this->oneShotTickCodexPart5Section()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerProcessStartEnvelopeBuilderStatus($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerStartExecutionGateContract(array $options = []) : array
    {
        return $this->agentAutomaticDispatchBatch1Section()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerStartExecutionGateContract($options);
    }

    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerStartExecutionGatePreflight(array $options = []): array
    {
        return $this->oneShotTickCodexPart5Section()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerStartExecutionGatePreflight($options);
    }

    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerStartExecutionGateImplementationPacket(array $options = []): array
    {
        return $this->oneShotTickCodexPart5Section()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerStartExecutionGateImplementationPacket($options);
    }

    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerStartExecutionGateStatus(array $options = []): array
    {
        return $this->oneShotTickCodexPart5Section()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerStartExecutionGateStatus($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerProcessStarterReadinessGateContract(array $options = []) : array
    {
        return $this->agentAutomaticDispatchBatch1Section()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerProcessStarterReadinessGateContract($options);
    }

    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerProcessStarterReadinessGatePreflight(array $options = []): array
    {
        return $this->oneShotTickCodexPart5Section()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerProcessStarterReadinessGatePreflight($options);
    }

    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerProcessStarterReadinessGateImplementationPacket(array $options = []): array
    {
        return $this->oneShotTickCodexPart5Section()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerProcessStarterReadinessGateImplementationPacket($options);
    }

    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerProcessStarterReadinessGateStatus(array $options = []): array
    {
        return $this->oneShotTickCodexPart5Section()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerProcessStarterReadinessGateStatus($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerManualStartExecutorReceiptContract(array $options = []) : array
    {
        return $this->agentAutomaticDispatchBatch1Section()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerManualStartExecutorReceiptContract($options);
    }

    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerManualStartExecutorReceiptPreflight(array $options = []): array
    {
        return $this->oneShotTickCodexPart5Section()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerManualStartExecutorReceiptPreflight($options);
    }

    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerManualStartExecutorReceiptImplementationPacket(array $options = []): array
    {
        return $this->oneShotTickCodexPart5Section()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerManualStartExecutorReceiptImplementationPacket($options);
    }

    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerManualStartExecutorReceiptStatus(array $options = []): array
    {
        return $this->oneShotTickCodexPart5Section()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerManualStartExecutorReceiptStatus($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerOperatorStartHandoffContract(array $options = []) : array
    {
        return $this->agentAutomaticDispatchBatch1Section()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerOperatorStartHandoffContract($options);
    }

    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerOperatorStartHandoffPreflight(array $options = []): array
    {
        return $this->oneShotTickCodexPart5Section()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerOperatorStartHandoffPreflight($options);
    }

    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerOperatorStartHandoffImplementationPacket(array $options = []): array
    {
        return $this->oneShotTickCodexPart6Section()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerOperatorStartHandoffImplementationPacket($options);
    }

    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerOperatorStartHandoffStatus(array $options = []): array
    {
        return $this->oneShotTickCodexPart6Section()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerOperatorStartHandoffStatus($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartReceiptContract(array $options = []) : array
    {
        return $this->agentAutomaticDispatchBatch2Section()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartReceiptContract($options);
    }

    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartReceiptContractPreflight(array $options = []): array
    {
        return $this->oneShotTickCodexPart6Section()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartReceiptContractPreflight($options);
    }

    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartReceiptContractImplementationPacket(array $options = []): array
    {
        return $this->oneShotTickCodexPart6Section()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartReceiptContractImplementationPacket($options);
    }

    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartReceiptContractStatus(array $options = []): array
    {
        return $this->oneShotTickCodexPart6Section()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartReceiptContractStatus($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartEvidenceReceiptContract(array $options = []) : array
    {
        return $this->agentAutomaticDispatchBatch2Section()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartEvidenceReceiptContract($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartEvidenceReceiptPreflight(array $options = []) : array
    {
        return $this->agentAutomaticDispatchBatch2Section()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartEvidenceReceiptPreflight($options);
    }

    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartEvidenceReceiptImplementationPacket(array $options = []): array
    {
        return $this->oneShotTickCodexPart6Section()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartEvidenceReceiptImplementationPacket($options);
    }

    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartEvidenceReceiptStatus(array $options = []): array
    {
        return $this->oneShotTickCodexPart6Section()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartEvidenceReceiptStatus($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartEvidenceAcceptanceBridgeContract(array $options = []) : array
    {
        return $this->agentAutomaticDispatchBatch1Section()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartEvidenceAcceptanceBridgeContract($options);
    }

    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartEvidenceAcceptanceBridgePreflight(array $options = []): array
    {
        return $this->oneShotTickCodexPart6Section()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartEvidenceAcceptanceBridgePreflight($options);
    }

    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartEvidenceAcceptanceBridgeImplementationPacket(array $options = []): array
    {
        return $this->oneShotTickCodexPart6Section()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartEvidenceAcceptanceBridgeImplementationPacket($options);
    }

    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartEvidenceAcceptanceBridgeStatus(array $options = []): array
    {
        return $this->oneShotTickCodexPart6Section()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartEvidenceAcceptanceBridgeStatus($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartLivenessMonitorContract(array $options = []) : array
    {
        return $this->agentAutomaticDispatchBatch2Section()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartLivenessMonitorContract($options);
    }

    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartLivenessMonitorPreflight(array $options = []): array
    {
        return $this->oneShotTickCodexPart6Section()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartLivenessMonitorPreflight($options);
    }

    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartLivenessMonitorImplementationPacket(array $options = []): array
    {
        return $this->oneShotTickCodexPart6Section()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartLivenessMonitorImplementationPacket($options);
    }

    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartLivenessMonitorStatus(array $options = []): array
    {
        return $this->oneShotTickCodexPart7Section()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartLivenessMonitorStatus($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartDispatchReleaseGateContract(array $options = []) : array
    {
        return $this->agentAutomaticDispatchBatch2Section()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartDispatchReleaseGateContract($options);
    }

    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartDispatchReleaseGatePreflight(array $options = []): array
    {
        return $this->oneShotTickCodexPart7Section()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartDispatchReleaseGatePreflight($options);
    }

    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartDispatchReleaseGateImplementationPacket(array $options = []): array
    {
        return $this->oneShotTickCodexPart7Section()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartDispatchReleaseGateImplementationPacket($options);
    }

    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartDispatchReleaseGateStatus(array $options = []): array
    {
        return $this->oneShotTickCodexPart7Section()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartDispatchReleaseGateStatus($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartSignedDispatchAuthorizationGateContract(array $options = []) : array
    {
        return $this->agentAutomaticDispatchBatch1Section()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartSignedDispatchAuthorizationGateContract($options);
    }

    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartSignedDispatchAuthorizationGatePreflight(array $options = []): array
    {
        return $this->oneShotTickCodexPart7Section()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartSignedDispatchAuthorizationGatePreflight($options);
    }

    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartSignedDispatchAuthorizationGateImplementationPacket(array $options = []): array
    {
        return $this->oneShotTickCodexPart7Section()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartSignedDispatchAuthorizationGateImplementationPacket($options);
    }

    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartSignedDispatchAuthorizationGateStatus(array $options = []): array
    {
        return $this->oneShotTickCodexPart7Section()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartSignedDispatchAuthorizationGateStatus($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartDispatchExecutorHandoffContract(array $options = []) : array
    {
        return $this->postStartGateBSection()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartDispatchExecutorHandoffContract($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartDispatchExecutorHandoffPreflight(array $options = []) : array
    {
        return $this->agentAutomaticDispatchBatch2Section()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartDispatchExecutorHandoffPreflight($options);
    }

    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartDispatchExecutorHandoffImplementationPacket(array $options = []): array
    {
        return $this->oneShotTickCodexPart7Section()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartDispatchExecutorHandoffImplementationPacket($options);
    }

    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartDispatchExecutorHandoffStatus(array $options = []): array
    {
        return $this->oneShotTickCodexPart7Section()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartDispatchExecutorHandoffStatus($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartDispatchReceiptUseExecutorContract(array $options = []) : array
    {
        return $this->agentAutomaticDispatchBatch2Section()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartDispatchReceiptUseExecutorContract($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartDispatchReceiptUseExecutorPreflight(array $options = []) : array
    {
        return $this->agentAutomaticDispatchBatch2Section()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartDispatchReceiptUseExecutorPreflight($options);
    }

    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartDispatchReceiptUseExecutorImplementationPacket(array $options = []): array
    {
        return $this->oneShotTickCodexPart7Section()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartDispatchReceiptUseExecutorImplementationPacket($options);
    }

    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartDispatchReceiptUseExecutorStatus(array $options = []): array
    {
        return $this->oneShotTickCodexPart7Section()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartDispatchReceiptUseExecutorStatus($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProviderStartDriverGateContract(array $options = []) : array
    {
        return $this->agentAutomaticDispatchBatch1Section()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProviderStartDriverGateContract($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProviderStartDriverGatePreflight(array $options = []) : array
    {
        return $this->agentAutomaticDispatchBatch1Section()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProviderStartDriverGatePreflight($options);
    }

    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProviderStartDriverGateImplementationPacket(array $options = []): array
    {
        return $this->oneShotTickCodexPart7Section()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProviderStartDriverGateImplementationPacket($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProviderStartDriverGateStatus(array $options = []) : array
    {
        return $this->agentAutomaticDispatchBatch1Section()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProviderStartDriverGateStatus($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartAdapterInvocationBoundaryGateContract(array $options = []) : array
    {
        return $this->agentAutomaticDispatchBatch1Section()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartAdapterInvocationBoundaryGateContract($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartAdapterInvocationBoundaryGatePreflight(array $options = []) : array
    {
        return $this->agentAutomaticDispatchBatch2Section()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartAdapterInvocationBoundaryGatePreflight($options);
    }

    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartAdapterInvocationBoundaryGateImplementationPacket(array $options = []): array
    {
        return $this->oneShotTickCodexPart8Section()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartAdapterInvocationBoundaryGateImplementationPacket($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartAdapterInvocationBoundaryGateStatus(array $options = []) : array
    {
        return $this->agentAutomaticDispatchBatch2Section()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartAdapterInvocationBoundaryGateStatus($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartAdapterExecutionGuardGateContract(array $options = []) : array
    {
        return $this->agentAutomaticDispatchBatch1Section()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartAdapterExecutionGuardGateContract($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartAdapterExecutionGuardGatePreflight(array $options = []) : array
    {
        return $this->agentAutomaticDispatchBatch2Section()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartAdapterExecutionGuardGatePreflight($options);
    }

    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartAdapterExecutionGuardGateImplementationPacket(array $options = []): array
    {
        return $this->oneShotTickCodexPart8Section()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartAdapterExecutionGuardGateImplementationPacket($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartAdapterExecutionGuardGateStatus(array $options = []) : array
    {
        return $this->agentAutomaticDispatchBatch2Section()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartAdapterExecutionGuardGateStatus($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProviderExecutionContractGateContract(array $options = []) : array
    {
        return $this->agentAutomaticDispatchBatch1Section()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProviderExecutionContractGateContract($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProviderExecutionContractGatePreflight(array $options = []) : array
    {
        return $this->agentAutomaticDispatchBatch1Section()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProviderExecutionContractGatePreflight($options);
    }

    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProviderExecutionContractGateImplementationPacket(array $options = []): array
    {
        return $this->oneShotTickCodexPart8Section()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProviderExecutionContractGateImplementationPacket($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProviderExecutionContractGateStatus(array $options = []) : array
    {
        return $this->agentAutomaticDispatchBatch1Section()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProviderExecutionContractGateStatus($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProcessStartReleaseGateContract(array $options = []) : array
    {
        return $this->agentAutomaticDispatchBatch1Section()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProcessStartReleaseGateContract($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProcessStartReleaseGatePreflight(array $options = []) : array
    {
        return $this->agentAutomaticDispatchBatch1Section()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProcessStartReleaseGatePreflight($options);
    }

    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProcessStartReleaseGateImplementationPacket(array $options = []): array
    {
        return $this->oneShotTickCodexPart8Section()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProcessStartReleaseGateImplementationPacket($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProcessStartReleaseGateStatus(array $options = []) : array
    {
        return $this->agentAutomaticDispatchBatch2Section()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProcessStartReleaseGateStatus($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartSupervisedStartExecutorGateContract(array $options = []) : array
    {
        return $this->agentAutomaticDispatchBatch1Section()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartSupervisedStartExecutorGateContract($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartSupervisedStartExecutorGatePreflight(array $options = []) : array
    {
        return $this->agentAutomaticDispatchBatch1Section()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartSupervisedStartExecutorGatePreflight($options);
    }

    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartSupervisedStartExecutorGateImplementationPacket(array $options = []): array
    {
        return $this->oneShotTickCodexPart8Section()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartSupervisedStartExecutorGateImplementationPacket($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartSupervisedStartExecutorGateStatus(array $options = []) : array
    {
        return $this->agentAutomaticDispatchBatch2Section()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartSupervisedStartExecutorGateStatus($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProcessSpawnEnablementGateContract(array $options = []) : array
    {
        return $this->agentAutomaticDispatchBatch1Section()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProcessSpawnEnablementGateContract($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProcessSpawnEnablementGatePreflight(array $options = []) : array
    {
        return $this->agentAutomaticDispatchBatch1Section()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProcessSpawnEnablementGatePreflight($options);
    }

    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProcessSpawnEnablementGateImplementationPacket(array $options = []): array
    {
        return $this->oneShotTickCodexPart8Section()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProcessSpawnEnablementGateImplementationPacket($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProcessSpawnEnablementGateStatus(array $options = []) : array
    {
        return $this->agentAutomaticDispatchBatch2Section()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProcessSpawnEnablementGateStatus($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartFinalProcessSpawnExecutorGateContract(array $options = []) : array
    {
        return $this->postStartGateBSection()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartFinalProcessSpawnExecutorGateContract($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartFinalProcessSpawnExecutorGatePreflight(array $options = []) : array
    {
        return $this->agentAutomaticDispatchBatch1Section()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartFinalProcessSpawnExecutorGatePreflight($options);
    }

    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartFinalProcessSpawnExecutorGateImplementationPacket(array $options = []): array
    {
        return $this->oneShotTickCodexPart8Section()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartFinalProcessSpawnExecutorGateImplementationPacket($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartFinalProcessSpawnExecutorGateStatus(array $options = []) : array
    {
        return $this->agentAutomaticDispatchBatch1Section()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartFinalProcessSpawnExecutorGateStatus($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartExternalProcessRuntimeGateContract(array $options = []) : array
    {
        return $this->postStartGateBSection()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartExternalProcessRuntimeGateContract($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartExternalProcessRuntimeGatePreflight(array $options = []) : array
    {
        return $this->agentAutomaticDispatchBatch1Section()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartExternalProcessRuntimeGatePreflight($options);
    }

    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartExternalProcessRuntimeGateImplementationPacket(array $options = []): array
    {
        return $this->oneShotTickCodexPart8Section()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartExternalProcessRuntimeGateImplementationPacket($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartExternalProcessRuntimeGateStatus(array $options = []) : array
    {
        return $this->agentAutomaticDispatchBatch1Section()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartExternalProcessRuntimeGateStatus($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProcessInvocationAuthorizationGateContract(array $options = []) : array
    {
        return $this->postStartGateBSection()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProcessInvocationAuthorizationGateContract($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProcessInvocationAuthorizationGatePreflight(array $options = []) : array
    {
        return $this->agentAutomaticDispatchBatch2Section()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProcessInvocationAuthorizationGatePreflight($options);
    }

    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProcessInvocationAuthorizationGateImplementationPacket(array $options = []): array
    {
        return $this->oneShotTickCodexPart8Section()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProcessInvocationAuthorizationGateImplementationPacket($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProcessInvocationAuthorizationGateStatus(array $options = []) : array
    {
        return $this->agentAutomaticDispatchBatch1Section()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProcessInvocationAuthorizationGateStatus($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartExternalProcessInvokerDryRunGateContract(array $options = []) : array
    {
        return $this->postStartGateBSection()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartExternalProcessInvokerDryRunGateContract($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartExternalProcessInvokerDryRunGatePreflight(array $options = []) : array
    {
        return $this->agentAutomaticDispatchBatch2Section()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartExternalProcessInvokerDryRunGatePreflight($options);
    }

    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartExternalProcessInvokerDryRunGateImplementationPacket(array $options = []): array
    {
        return $this->oneShotTickCodexPart8Section()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartExternalProcessInvokerDryRunGateImplementationPacket($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartExternalProcessInvokerDryRunGateStatus(array $options = []) : array
    {
        return $this->agentAutomaticDispatchBatch1Section()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartExternalProcessInvokerDryRunGateStatus($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartRealInvokerReleasePreflightGateContract(array $options = []) : array
    {
        return $this->dispatchGateSection()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartRealInvokerReleasePreflightGateContract($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartRealInvokerReleasePreflightGatePreflight(array $options = []) : array
    {
        return $this->agentAutomaticDispatchBatch2Section()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartRealInvokerReleasePreflightGatePreflight($options);
    }

    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartRealInvokerReleasePreflightGateImplementationPacket(array $options = []): array
    {
        return $this->oneShotTickCodexPart8Section()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartRealInvokerReleasePreflightGateImplementationPacket($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartRealInvokerReleasePreflightGateStatus(array $options = []) : array
    {
        return $this->agentAutomaticDispatchBatch1Section()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartRealInvokerReleasePreflightGateStatus($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartSignedRealInvokerReleaseGateContract(array $options = []) : array
    {
        return $this->dispatchGateSection()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartSignedRealInvokerReleaseGateContract($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartSignedRealInvokerReleaseGatePreflight(array $options = []) : array
    {
        return $this->agentAutomaticDispatchBatch2Section()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartSignedRealInvokerReleaseGatePreflight($options);
    }

    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartSignedRealInvokerReleaseGateImplementationPacket(array $options = []): array
    {
        return $this->oneShotTickCodexPart8Section()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartSignedRealInvokerReleaseGateImplementationPacket($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartSignedRealInvokerReleaseGateStatus(array $options = []) : array
    {
        return $this->agentAutomaticDispatchBatch1Section()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartSignedRealInvokerReleaseGateStatus($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartImplementationBoundaryGateContract(array $options = []) : array
    {
        return $this->dispatchGateSection()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartImplementationBoundaryGateContract($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartImplementationBoundaryGatePreflight(array $options = []) : array
    {
        return $this->agentAutomaticDispatchBatch2Section()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartImplementationBoundaryGatePreflight($options);
    }

    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartImplementationBoundaryGateImplementationPacket(array $options = []): array
    {
        return $this->oneShotTickCodexPart9Section()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartImplementationBoundaryGateImplementationPacket($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartImplementationBoundaryGateStatus(array $options = []) : array
    {
        return $this->agentAutomaticDispatchBatch1Section()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartImplementationBoundaryGateStatus($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartExecutorPlanGateContract(array $options = []) : array
    {
        return $this->dispatchGateSection()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartExecutorPlanGateContract($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartExecutorPlanGatePreflight(array $options = []) : array
    {
        return $this->agentAutomaticDispatchBatch1Section()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartExecutorPlanGatePreflight($options);
    }

    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartExecutorPlanGateImplementationPacket(array $options = []): array
    {
        return $this->oneShotTickCodexPart9Section()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartExecutorPlanGateImplementationPacket($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartExecutorPlanGateStatus(array $options = []) : array
    {
        return $this->postStartGateStatusSection()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartExecutorPlanGateStatus($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartExecutorFreshReleaseGateContract(array $options = []) : array
    {
        return $this->dispatchGateSection()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartExecutorFreshReleaseGateContract($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartExecutorFreshReleaseGatePreflight(array $options = []) : array
    {
        return $this->agentAutomaticDispatchBatch1Section()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartExecutorFreshReleaseGatePreflight($options);
    }

    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartExecutorFreshReleaseGateImplementationPacket(array $options = []): array
    {
        return $this->oneShotTickCodexPart9Section()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartExecutorFreshReleaseGateImplementationPacket($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartExecutorFreshReleaseGateStatus(array $options = []) : array
    {
        return $this->postStartGateStatusSection()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartExecutorFreshReleaseGateStatus($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartExecutorEnablementGateContract(array $options = []) : array
    {
        return $this->dispatchGateSection()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartExecutorEnablementGateContract($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartExecutorEnablementGatePreflight(array $options = []) : array
    {
        return $this->agentAutomaticDispatchBatch1Section()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartExecutorEnablementGatePreflight($options);
    }

    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartExecutorEnablementGateImplementationPacket(array $options = []): array
    {
        return $this->oneShotTickCodexPart9Section()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartExecutorEnablementGateImplementationPacket($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartExecutorEnablementGateStatus(array $options = []) : array
    {
        return $this->agentAutomaticDispatchBatch1Section()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartExecutorEnablementGateStatus($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartSupervisedStartActivationGateContract(array $options = []) : array
    {
        return $this->dispatchGateSection()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartSupervisedStartActivationGateContract($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartSupervisedStartActivationGatePreflight(array $options = []) : array
    {
        return $this->agentAutomaticDispatchBatch1Section()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartSupervisedStartActivationGatePreflight($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartSupervisedStartActivationGateImplementationPacket(array $options = []) : array
    {
        return $this->agentAutomaticDispatchBatch2Section()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartSupervisedStartActivationGateImplementationPacket($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartSupervisedStartActivationGateStatus(array $options = []) : array
    {
        return $this->postStartGateStatusSection()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartSupervisedStartActivationGateStatus($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartGuardedProcessStartExecutorGateContract(array $options = []) : array
    {
        return $this->dispatchGateSection()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartGuardedProcessStartExecutorGateContract($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartGuardedProcessStartExecutorGatePreflight(array $options = []) : array
    {
        return $this->agentAutomaticDispatchBatch1Section()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartGuardedProcessStartExecutorGatePreflight($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartGuardedProcessStartExecutorGateImplementationPacket(array $options = []) : array
    {
        return $this->agentAutomaticDispatchBatch2Section()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartGuardedProcessStartExecutorGateImplementationPacket($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartGuardedProcessStartExecutorGateStatus(array $options = []) : array
    {
        return $this->postStartGateStatusSection()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartGuardedProcessStartExecutorGateStatus($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartFinalProcessStartAuthorizationGateContract(array $options = []) : array
    {
        return $this->dispatchGateSection()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartFinalProcessStartAuthorizationGateContract($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartFinalProcessStartAuthorizationGatePreflight(array $options = []) : array
    {
        return $this->agentAutomaticDispatchBatch1Section()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartFinalProcessStartAuthorizationGatePreflight($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartFinalProcessStartAuthorizationGateImplementationPacket(array $options = []) : array
    {
        return $this->agentAutomaticDispatchBatch2Section()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartFinalProcessStartAuthorizationGateImplementationPacket($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartFinalProcessStartAuthorizationGateStatus(array $options = []) : array
    {
        return $this->postStartGateStatusSection()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartFinalProcessStartAuthorizationGateStatus($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartActualProcessStartRehearsalGateContract(array $options = []) : array
    {
        return $this->dispatchGateSection()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartActualProcessStartRehearsalGateContract($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartActualProcessStartRehearsalGatePreflight(array $options = []) : array
    {
        return $this->agentAutomaticDispatchBatch1Section()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartActualProcessStartRehearsalGatePreflight($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartActualProcessStartRehearsalGateImplementationPacket(array $options = []) : array
    {
        return $this->agentAutomaticDispatchBatch2Section()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartActualProcessStartRehearsalGateImplementationPacket($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartActualProcessStartRehearsalGateStatus(array $options = []) : array
    {
        return $this->postStartGateStatusSection()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartActualProcessStartRehearsalGateStatus($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProcessStartEnvelopeGateContract(array $options = []) : array
    {
        return $this->dispatchGateSection()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProcessStartEnvelopeGateContract($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProcessStartEnvelopeGatePreflight(array $options = []) : array
    {
        return $this->agentAutomaticDispatchBatch1Section()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProcessStartEnvelopeGatePreflight($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProcessStartEnvelopeGateImplementationPacket(array $options = []) : array
    {
        return $this->agentAutomaticDispatchBatch2Section()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProcessStartEnvelopeGateImplementationPacket($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProcessStartEnvelopeGateStatus(array $options = []) : array
    {
        return $this->postStartGateStatusSection()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProcessStartEnvelopeGateStatus($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartStartExecutionGateContract(array $options = []) : array
    {
        return $this->dispatchGateSection()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartStartExecutionGateContract($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartStartExecutionGatePreflight(array $options = []) : array
    {
        return $this->postStartGateStatusSection()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartStartExecutionGatePreflight($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartStartExecutionGateImplementationPacket(array $options = []) : array
    {
        return $this->agentAutomaticDispatchBatch2Section()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartStartExecutionGateImplementationPacket($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartStartExecutionGateStatus(array $options = []) : array
    {
        return $this->postStartGateStatusSection()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartStartExecutionGateStatus($options);
    }

    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProcessStarterReadinessGateContract(array $options = []): array
    {
        return $this->oneShotTickCodexPart9Section()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProcessStarterReadinessGateContract($options);
    }

    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProcessStarterReadinessGatePreflight(array $options = []): array
    {
        return $this->oneShotTickCodexPart9Section()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProcessStarterReadinessGatePreflight($options);
    }

    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProcessStarterReadinessGateImplementationPacket(array $options = []): array
    {
        return $this->oneShotTickCodexPart9Section()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProcessStarterReadinessGateImplementationPacket($options);
    }

    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProcessStarterReadinessGateStatus(array $options = []): array
    {
        return $this->oneShotTickCodexPart9Section()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProcessStarterReadinessGateStatus($options);
    }

    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartManualStartExecutorReceiptContract(array $options = []): array
    {
        return $this->oneShotTickCodexPart9Section()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartManualStartExecutorReceiptContract($options);
    }

    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartManualStartExecutorReceiptPreflight(array $options = []): array
    {
        return $this->oneShotTickCodexPart9Section()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartManualStartExecutorReceiptPreflight($options);
    }

    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartManualStartExecutorReceiptImplementationPacket(array $options = []): array
    {
        return $this->oneShotTickCodexPart9Section()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartManualStartExecutorReceiptImplementationPacket($options);
    }

    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartManualStartExecutorReceiptStatus(array $options = []): array
    {
        return $this->oneShotTickCodexPart9Section()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartManualStartExecutorReceiptStatus($options);
    }

    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartOperatorStartHandoffContract(array $options = []): array
    {
        return $this->oneShotTickCodexPart10Section()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartOperatorStartHandoffContract($options);
    }

    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartOperatorStartHandoffPreflight(array $options = []): array
    {
        return $this->oneShotTickCodexPart10Section()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartOperatorStartHandoffPreflight($options);
    }

    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartOperatorStartHandoffImplementationPacket(array $options = []): array
    {
        return $this->oneShotTickCodexPart10Section()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartOperatorStartHandoffImplementationPacket($options);
    }

    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartOperatorStartHandoffStatus(array $options = []): array
    {
        return $this->oneShotTickCodexPart10Section()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartOperatorStartHandoffStatus($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneRuntimeSchemaPreflight(array $options = []): array
    {
        return $this->agentControlPlaneReplaySection()->agentControlPlaneRuntimeSchemaPreflight($options);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneChainIntegrityCertificationContract(array $options = []): array
    {
        return (new ReadinessCertificationChainQuartetProjector)->agentControlPlaneChainIntegrityCertificationContract($options);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneChainIntegrityCertificationPreflight(array $options = []): array
    {
        return (new ReadinessCertificationChainQuartetProjector)->agentControlPlaneChainIntegrityCertificationPreflight($options);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneChainIntegrityCertificationImplementationPacket(array $options = []): array
    {
        return (new ReadinessCertificationChainQuartetProjector)->agentControlPlaneChainIntegrityCertificationImplementationPacket($options);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneChainIntegrityCertificationStatus(array $options = []): array
    {
        return $this->agentControlPlaneReplaySection()->agentControlPlaneChainIntegrityCertificationStatus($options);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneDeterministicChainReplayContract(array $options = []): array
    {
        return (new ReadinessCertificationChainQuartetProjector)->agentControlPlaneDeterministicChainReplayContract($options);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneDeterministicChainReplayPreflight(array $options = []): array
    {
        return (new ReadinessCertificationChainQuartetProjector)->agentControlPlaneDeterministicChainReplayPreflight($options);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneDeterministicChainReplayImplementationPacket(array $options = []): array
    {
        return (new ReadinessCertificationChainQuartetProjector)->agentControlPlaneDeterministicChainReplayImplementationPacket($options);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneDeterministicChainReplayStatus(array $options = []): array
    {
        return $this->agentControlPlaneReplaySection()->agentControlPlaneDeterministicChainReplayStatus($options);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneReplaySnapshotStoreContract(array $options = []): array
    {
        return (new ReadinessCertificationChainQuartetProjector)->agentControlPlaneReplaySnapshotStoreContract($options);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneReplaySnapshotStorePreflight(array $options = []): array
    {
        return (new ReadinessCertificationChainQuartetProjector)->agentControlPlaneReplaySnapshotStorePreflight($options);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneReplaySnapshotStoreImplementationPacket(array $options = []): array
    {
        return (new ReadinessCertificationChainQuartetProjector)->agentControlPlaneReplaySnapshotStoreImplementationPacket($options);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneReplaySnapshotStoreStatus(array $options = []): array
    {
        return $this->agentControlPlaneReplaySection()->agentControlPlaneReplaySnapshotStoreStatus($options);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneReplaySnapshotStoreCapture(array $options = []): array
    {
        return $this->agentControlPlaneReplaySection()->agentControlPlaneReplaySnapshotStoreCapture($options);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneReplayDiffContract(array $options = []): array
    {
        return (new ReadinessCertificationChainQuartetProjector)->agentControlPlaneReplayDiffContract($options);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneReplayDiffPreflight(array $options = []): array
    {
        return (new ReadinessCertificationChainQuartetProjector)->agentControlPlaneReplayDiffPreflight($options);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneReplayDiffImplementationPacket(array $options = []): array
    {
        return (new ReadinessCertificationChainQuartetProjector)->agentControlPlaneReplayDiffImplementationPacket($options);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneReplayDiffStatus(array $options = []): array
    {
        return $this->agentControlPlaneReplaySection()->agentControlPlaneReplayDiffStatus($options);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneMacroSprintPromotionGateContract(array $options = []): array
    {
        return (new ReadinessCertificationChainQuartetProjector)->agentControlPlaneMacroSprintPromotionGateContract($options);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneMacroSprintPromotionGatePreflight(array $options = []): array
    {
        return (new ReadinessCertificationChainQuartetProjector)->agentControlPlaneMacroSprintPromotionGatePreflight($options);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneMacroSprintPromotionGateImplementationPacket(array $options = []): array
    {
        return (new ReadinessCertificationChainQuartetProjector)->agentControlPlaneMacroSprintPromotionGateImplementationPacket($options);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneMacroSprintPromotionGateStatus(array $options = []): array
    {
        return $this->agentControlPlaneReplaySection()->agentControlPlaneMacroSprintPromotionGateStatus($options);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneCertificationBaselineContract(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('certification_baseline', 'contract');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneCertificationBaselinePreflight(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('certification_baseline', 'preflight');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneCertificationBaselineImplementationPacket(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('certification_baseline', 'implementation_packet');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneCertificationBaselineStatus(array $options = []): array
    {
        return $this->agentControlPlaneReplaySection()->agentControlPlaneCertificationBaselineStatus($options);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneCertificationScenarioSimulatorContract(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('certification_scenario_simulator', 'contract');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneCertificationScenarioSimulatorPreflight(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('certification_scenario_simulator', 'preflight');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneCertificationScenarioSimulatorImplementationPacket(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('certification_scenario_simulator', 'implementation_packet');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneCertificationScenarioSimulatorStatus(array $options = []): array
    {
        $auditService = new AgentControlPlaneChainIntegrityAuditService($this);
        $replayService = new AgentControlPlaneDeterministicChainReplayService($auditService, $this);
        $store = new AgentControlPlaneReplaySnapshotStore;
        $diffService = new AgentControlPlaneReplayDiffService($store, $replayService);
        $gate = new AgentControlPlaneMacroSprintPromotionGate($diffService, $auditService, $replayService);
        $simulator = new AgentControlPlaneCertificationScenarioSimulator(
            $this,
            $auditService,
            $replayService,
            $diffService,
            $store,
            $gate,
        );
        $result = $simulator->simulate($options);

        return $this->wrapCertificationWorkbenchStatus(
            keyPrefix: 'certification_scenario_simulator',
            label: 'Certification Scenario Simulator',
            payload: $result,
            statusKey: 'status',
            extraStatusFields: [
                'simulation_id' => (string) data_get($result, 'simulation_id'),
                'scenario_count' => (int) data_get($result, 'scenario_count'),
                'passed_count' => (int) data_get($result, 'passed_count'),
                'failed_count' => (int) data_get($result, 'failed_count'),
                'detection_rate' => (float) data_get($result, 'detection_rate'),
                'all_expected_faults_detected' => (bool) data_get($result, 'all_expected_faults_detected'),
                'scenario_matrix_hash' => (string) data_get($result, 'scenario_matrix_hash'),
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneReleaseDossierContract(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('release_dossier', 'contract');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneReleaseDossierPreflight(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('release_dossier', 'preflight');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneReleaseDossierImplementationPacket(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('release_dossier', 'implementation_packet');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneReleaseDossierStatus(array $options = []): array
    {
        return $this->agentControlPlaneReplaySection()->agentControlPlaneReleaseDossierStatus($options);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneCertificationMutationGuardContract(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('certification_mutation_guard', 'contract');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneCertificationMutationGuardPreflight(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('certification_mutation_guard', 'preflight');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneCertificationMutationGuardImplementationPacket(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('certification_mutation_guard', 'implementation_packet');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneCertificationMutationGuardStatus(array $options = []): array
    {
        $auditService = new AgentControlPlaneChainIntegrityAuditService($this);
        $replayService = new AgentControlPlaneDeterministicChainReplayService($auditService, $this);
        $store = new AgentControlPlaneReplaySnapshotStore;
        $guard = new AgentControlPlaneCertificationMutationGuard($this, $replayService, $store);
        $result = $guard->guard(null, $options);

        return $this->wrapCertificationWorkbenchStatus(
            keyPrefix: 'certification_mutation_guard',
            label: 'Certification Mutation Guard',
            payload: $result,
            statusKey: 'status',
            extraStatusFields: [
                'guard_id' => (string) data_get($result, 'guard_id'),
                'before_hash' => (string) data_get($result, 'before_hash'),
                'after_hash' => (string) data_get($result, 'after_hash'),
                'mutation_hash' => (string) data_get($result, 'mutation_hash'),
                'guard_passed' => (bool) data_get($result, 'guard_passed'),
                'pointer_mutated' => (bool) data_get($result, 'pointer_mutated'),
                'runtime_safety_mutated' => (bool) data_get($result, 'runtime_safety_mutated'),
                'ledger_mutated' => (bool) data_get($result, 'ledger_mutated'),
                'storage_mutated' => (bool) data_get($result, 'storage_mutated'),
                'mutation_count' => (int) data_get($result, 'mutation_count'),
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneCertificationEvidenceQueryContract(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('certification_evidence_query', 'contract');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneCertificationEvidenceQueryPreflight(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('certification_evidence_query', 'preflight');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneCertificationEvidenceQueryImplementationPacket(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('certification_evidence_query', 'implementation_packet');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneCertificationEvidenceQueryStatus(array $options = []): array
    {
        $audit = new AgentControlPlaneChainIntegrityAuditService($this);
        $replay = new AgentControlPlaneDeterministicChainReplayService($audit, $this);
        $store = new AgentControlPlaneReplaySnapshotStore;
        $diffSvc = new AgentControlPlaneReplayDiffService($store, $replay);
        $gate = new AgentControlPlaneMacroSprintPromotionGate($diffSvc, $audit, $replay);
        $baseline = new AgentControlPlaneCertificationBaselineService($this, $audit, $replay, $store, $diffSvc, $gate);
        $svc = new AgentControlPlaneCertificationEvidenceQueryService($audit, $replay, $baseline);
        $result = $svc->query($options);

        return $this->wrapCertificationWorkbenchStatus(
            keyPrefix: 'certification_evidence_query',
            label: 'Certification Evidence Query',
            payload: $result,
            statusKey: 'status',
            extraStatusFields: [
                'query_id' => (string) data_get($result, 'query_id'),
                'query_hash' => (string) data_get($result, 'query_hash'),
                'result_count' => (int) data_get($result, 'result_count'),
                'record_count' => (int) data_get($result, 'record_count'),
                'invalid_filter_count' => count((array) data_get($result, 'invalid_filters', [])),
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneCertificationScenarioCorpusContract(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('certification_scenario_corpus', 'contract');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneCertificationScenarioCorpusPreflight(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('certification_scenario_corpus', 'preflight');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneCertificationScenarioCorpusImplementationPacket(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('certification_scenario_corpus', 'implementation_packet');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneCertificationScenarioCorpusStatus(array $options = []): array
    {
        $audit = new AgentControlPlaneChainIntegrityAuditService($this);
        $replay = new AgentControlPlaneDeterministicChainReplayService($audit, $this);
        $store = new AgentControlPlaneReplaySnapshotStore;
        $diffSvc = new AgentControlPlaneReplayDiffService($store, $replay);
        $gate = new AgentControlPlaneMacroSprintPromotionGate($diffSvc, $audit, $replay);
        $simulator = new AgentControlPlaneCertificationScenarioSimulator($this, $audit, $replay, $diffSvc, $store, $gate);
        $svc = new AgentControlPlaneCertificationScenarioCorpusService($simulator);
        $result = $svc->corpus($options);

        return $this->wrapCertificationWorkbenchStatus(
            keyPrefix: 'certification_scenario_corpus',
            label: 'Certification Scenario Corpus',
            payload: $result,
            statusKey: 'status',
            extraStatusFields: [
                'corpus_id' => (string) data_get($result, 'corpus_id'),
                'corpus_hash' => (string) data_get($result, 'corpus_hash'),
                'scenario_count' => (int) data_get($result, 'scenario_count'),
                'category_count' => count((array) data_get($result, 'categories', [])),
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneCertificationFuzzHarnessContract(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('certification_fuzz_harness', 'contract');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneCertificationFuzzHarnessPreflight(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('certification_fuzz_harness', 'preflight');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneCertificationFuzzHarnessImplementationPacket(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('certification_fuzz_harness', 'implementation_packet');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneCertificationFuzzHarnessStatus(array $options = []): array
    {
        $audit = new AgentControlPlaneChainIntegrityAuditService($this);
        $replay = new AgentControlPlaneDeterministicChainReplayService($audit, $this);
        $store = new AgentControlPlaneReplaySnapshotStore;
        $diffSvc = new AgentControlPlaneReplayDiffService($store, $replay);
        $svc = new AgentControlPlaneCertificationFuzzHarness($audit, $replay, $diffSvc);
        $iterations = isset($options['iteration_count']) && is_int($options['iteration_count'])
            ? $options['iteration_count']
            : 8;
        $result = $svc->run(array_merge($options, ['iteration_count' => $iterations]));

        return $this->wrapCertificationWorkbenchStatus(
            keyPrefix: 'certification_fuzz_harness',
            label: 'Certification Fuzz Harness',
            payload: $result,
            statusKey: 'status',
            extraStatusFields: [
                'fuzz_id' => (string) data_get($result, 'fuzz_id'),
                'fuzz_hash' => (string) data_get($result, 'fuzz_hash'),
                'seed' => (int) data_get($result, 'seed'),
                'iteration_count' => (int) data_get($result, 'iteration_count'),
                'detected_count' => (int) data_get($result, 'detected_count'),
                'missed_count' => (int) data_get($result, 'missed_count'),
                'all_expected_detected' => (bool) data_get($result, 'all_expected_detected'),
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneMultiSnapshotComparisonContract(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('multi_snapshot_comparison', 'contract');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneMultiSnapshotComparisonPreflight(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('multi_snapshot_comparison', 'preflight');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneMultiSnapshotComparisonImplementationPacket(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('multi_snapshot_comparison', 'implementation_packet');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneMultiSnapshotComparisonStatus(array $options = []): array
    {
        $audit = new AgentControlPlaneChainIntegrityAuditService($this);
        $replay = new AgentControlPlaneDeterministicChainReplayService($audit, $this);
        $store = new AgentControlPlaneReplaySnapshotStore;
        $svc = new AgentControlPlaneMultiSnapshotComparisonService($store, $replay);
        $result = $svc->compare($options);

        return $this->wrapCertificationWorkbenchStatus(
            keyPrefix: 'multi_snapshot_comparison',
            label: 'Multi-Snapshot Comparison',
            payload: $result,
            statusKey: 'status',
            extraStatusFields: [
                'comparison_id' => (string) data_get($result, 'comparison_id'),
                'trend_status' => (string) data_get($result, 'trend_status'),
                'trend_hash' => (string) data_get($result, 'trend_hash'),
                'snapshot_count' => (int) data_get($result, 'snapshot_count'),
                'regression_window_count' => (int) data_get($result, 'regression_window_count'),
                'improvement_window_count' => (int) data_get($result, 'improvement_window_count'),
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneReleaseDossierExporterContract(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('release_dossier_exporter', 'contract');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneReleaseDossierExporterPreflight(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('release_dossier_exporter', 'preflight');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneReleaseDossierExporterImplementationPacket(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('release_dossier_exporter', 'implementation_packet');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneReleaseDossierExporterStatus(array $options = []): array
    {
        $audit = new AgentControlPlaneChainIntegrityAuditService($this);
        $replay = new AgentControlPlaneDeterministicChainReplayService($audit, $this);
        $store = new AgentControlPlaneReplaySnapshotStore;
        $diffSvc = new AgentControlPlaneReplayDiffService($store, $replay);
        $gate = new AgentControlPlaneMacroSprintPromotionGate($diffSvc, $audit, $replay);
        $simulator = new AgentControlPlaneCertificationScenarioSimulator($this, $audit, $replay, $diffSvc, $store, $gate);
        $baseline = new AgentControlPlaneCertificationBaselineService($this, $audit, $replay, $store, $diffSvc, $gate);
        $mutationGuard = new AgentControlPlaneCertificationMutationGuard($this, $replay, $store);
        $dossier = new AgentControlPlaneReleaseDossierService($baseline, $replay, $store, $diffSvc, $gate, $simulator, $audit, $mutationGuard);
        $svc = new AgentControlPlaneReleaseDossierExporter($dossier);
        $result = $svc->export(array_merge(['skip_simulator' => true], $options));

        return $this->wrapCertificationWorkbenchStatus(
            keyPrefix: 'release_dossier_exporter',
            label: 'Release Dossier Exporter',
            payload: $result,
            statusKey: 'status',
            extraStatusFields: [
                'export_id' => (string) data_get($result, 'export_id'),
                'export_hash' => (string) data_get($result, 'export_hash'),
                'markdown_hash' => (string) data_get($result, 'markdown_hash'),
                'machine_summary_hash' => (string) data_get($result, 'machine_summary_hash'),
                'operator_summary_hash' => (string) data_get($result, 'operator_summary_hash'),
                'persist' => (bool) data_get($result, 'persist'),
                'persisted_md_path' => (string) data_get($result, 'persisted_md_path'),
                'markdown_byte_size' => (int) data_get($result, 'markdown_byte_size'),
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneCertificationCoverageReportContract(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('certification_coverage_report', 'contract');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneCertificationCoverageReportPreflight(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('certification_coverage_report', 'preflight');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneCertificationCoverageReportImplementationPacket(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('certification_coverage_report', 'implementation_packet');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneCertificationCoverageReportStatus(array $options = []): array
    {
        $audit = new AgentControlPlaneChainIntegrityAuditService($this);
        $replay = new AgentControlPlaneDeterministicChainReplayService($audit, $this);
        $store = new AgentControlPlaneReplaySnapshotStore;
        $diffSvc = new AgentControlPlaneReplayDiffService($store, $replay);
        $gate = new AgentControlPlaneMacroSprintPromotionGate($diffSvc, $audit, $replay);
        $simulator = new AgentControlPlaneCertificationScenarioSimulator($this, $audit, $replay, $diffSvc, $store, $gate);
        $fuzz = new AgentControlPlaneCertificationFuzzHarness($audit, $replay, $diffSvc);
        $svc = new AgentControlPlaneCertificationCoverageReportService($audit, $replay, $simulator, $fuzz);
        $result = $svc->report(array_merge(['skip_simulator' => true, 'skip_fuzz' => true], $options));

        return $this->wrapCertificationWorkbenchStatus(
            keyPrefix: 'certification_coverage_report',
            label: 'Certification Coverage Report',
            payload: $result,
            statusKey: 'status',
            extraStatusFields: [
                'report_id' => (string) data_get($result, 'report_id'),
                'coverage_hash' => (string) data_get($result, 'coverage_hash'),
                'coverage_score' => (float) data_get($result, 'coverage_score'),
                'coverage_grade' => (string) data_get($result, 'coverage_grade'),
                'block_count' => (int) data_get($result, 'block_count'),
                'missing_block_count' => count((array) data_get($result, 'missing_coverage', [])),
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneCertificationStatusBatchContract(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('certification_status_batch', 'contract');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneCertificationStatusBatchPreflight(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('certification_status_batch', 'preflight');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneCertificationStatusBatchImplementationPacket(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('certification_status_batch', 'implementation_packet');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneCertificationStatusBatchStatus(array $options = []): array
    {
        $svc = new AgentControlPlaneCertificationStatusBatchService($this);
        $result = $svc->run(array_merge(['skip_batch_self' => true], $options));

        return $this->wrapCertificationWorkbenchStatus(
            keyPrefix: 'certification_status_batch',
            label: 'Certification Status Batch',
            payload: $result,
            statusKey: 'status',
            extraStatusFields: [
                'batch_id' => (string) data_get($result, 'batch_id'),
                'batch_hash' => (string) data_get($result, 'batch_hash'),
                'checked_count' => (int) data_get($result, 'checked_count'),
                'passed_count' => (int) data_get($result, 'passed_count'),
                'failed_count' => (int) data_get($result, 'failed_count'),
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneCertificationStatusBatchSelfStatus(array $options = []): array
    {
        // Echo the contract+stamp without invoking the batch again, to avoid
        // recursion when the batch itself iterates the status projections.
        return [
            'schema_version' => 'atlas.self_construction_agent_control_plane_certification_status_batch_self_status.v1',
            'status' => 'available',
            'mode' => 'read_only_agent_control_plane_certification_status_batch_self_status',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'agent_control_plane_certification_status_batch_self_status' => [
                'status' => 'available',
                'note' => 'Echo to avoid recursion when batch iterates status projections.',
            ],
            'agent_control_plane_certification_status_batch_self_status_hash' => $this->stableHash([
                'self' => 'available',
            ]),
            'non_execution_guarantees' => [],
            'human_summary' => 'Status batch self-echo (no recursion).',
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function atlasSelfConstructionRuntimeGapMatrix(array $options = []): array
    {
        return (new AtlasSelfConstructionRuntimeGapMatrixService($this))->matrix([
            'runtime_promotion_receipt' => (array) ($options['runtime_promotion_receipt'] ?? $this->decodeJsonOption($options['runtime_promotion_receipt_json'] ?? null)),
            'persist_runtime_promotion_receipt' => (bool) ($options['persist_runtime_promotion_receipt'] ?? false),
        ]);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function atlasSelfConstructionOsCompletionAuditContract(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('atlas_self_construction_os_completion_audit', 'contract');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function atlasSelfConstructionOsCompletionAuditPreflight(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('atlas_self_construction_os_completion_audit', 'preflight');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function atlasSelfConstructionOsCompletionAuditImplementationPacket(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('atlas_self_construction_os_completion_audit', 'implementation_packet');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function atlasSelfConstructionOsCompletionAuditStatus(array $options = []): array
    {
        return $this->osCompletionEvidenceSection()->atlasSelfConstructionOsCompletionAuditStatus($options);
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
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function atlasSelfConstructionOsCompletionOperatorActionPacketContract(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('atlas_self_construction_os_completion_operator_action_packet', 'contract');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function atlasSelfConstructionOsCompletionOperatorActionPacketPreflight(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('atlas_self_construction_os_completion_operator_action_packet', 'preflight');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function atlasSelfConstructionOsCompletionOperatorActionPacketImplementationPacket(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('atlas_self_construction_os_completion_operator_action_packet', 'implementation_packet');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function atlasSelfConstructionOsCompletionOperatorActionPacketStatus(array $options = []): array
    {
        return $this->osCompletionEvidenceSection()->atlasSelfConstructionOsCompletionOperatorActionPacketStatus($options);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function atlasSelfConstructionFinalEvidenceBundleContract(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('atlas_self_construction_final_evidence_bundle', 'contract');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function atlasSelfConstructionFinalEvidenceBundlePreflight(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('atlas_self_construction_final_evidence_bundle', 'preflight');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function atlasSelfConstructionFinalEvidenceBundleImplementationPacket(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('atlas_self_construction_final_evidence_bundle', 'implementation_packet');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function atlasSelfConstructionFinalEvidenceBundleStatus(array $options = []): array
    {
        return $this->osCompletionEvidenceSection()->atlasSelfConstructionFinalEvidenceBundleStatus($options);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function atlasSelfConstructionCompletionAuditBlockerExplainerContract(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('atlas_self_construction_completion_audit_blocker_explainer', 'contract');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function atlasSelfConstructionCompletionAuditBlockerExplainerPreflight(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('atlas_self_construction_completion_audit_blocker_explainer', 'preflight');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function atlasSelfConstructionCompletionAuditBlockerExplainerImplementationPacket(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('atlas_self_construction_completion_audit_blocker_explainer', 'implementation_packet');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function atlasSelfConstructionCompletionAuditBlockerExplainerStatus(array $options = []): array
    {
        return $this->osCompletionEvidenceSection()->atlasSelfConstructionCompletionAuditBlockerExplainerStatus($options);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function atlasSelfConstructionCompletionEvidenceSubmissionPreflightContract(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('atlas_self_construction_completion_evidence_submission_preflight', 'contract');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function atlasSelfConstructionCompletionEvidenceSubmissionPreflightPreflight(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('atlas_self_construction_completion_evidence_submission_preflight', 'preflight');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function atlasSelfConstructionCompletionEvidenceSubmissionPreflightImplementationPacket(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('atlas_self_construction_completion_evidence_submission_preflight', 'implementation_packet');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function atlasSelfConstructionCompletionEvidenceSubmissionPreflightStatus(array $options = []): array
    {
        return $this->osCompletionEvidenceSection()->atlasSelfConstructionCompletionEvidenceSubmissionPreflightStatus($options);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function atlasSelfConstructionOsHandoffContract(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('atlas_self_construction_os_handoff', 'contract');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function atlasSelfConstructionOsHandoffPreflight(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('atlas_self_construction_os_handoff', 'preflight');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function atlasSelfConstructionOsHandoffImplementationPacket(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('atlas_self_construction_os_handoff', 'implementation_packet');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function atlasSelfConstructionOsHandoffStatus(array $options = []) : array
    {
        return $this->osEvidenceSection()->atlasSelfConstructionOsHandoffStatus($options);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function atlasSelfConstructionCompletionEvidenceHashComposerContract(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('atlas_self_construction_completion_evidence_hash_composer', 'contract');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function atlasSelfConstructionCompletionEvidenceHashComposerPreflight(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('atlas_self_construction_completion_evidence_hash_composer', 'preflight');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function atlasSelfConstructionCompletionEvidenceHashComposerImplementationPacket(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('atlas_self_construction_completion_evidence_hash_composer', 'implementation_packet');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function atlasSelfConstructionCompletionEvidenceHashComposerStatus(array $options = []): array
    {
        $result = (new AtlasSelfConstructionCompletionEvidenceHashComposerService)->compose([
            'runtime_promotion_receipt' => (array) ($options['runtime_promotion_receipt'] ?? $this->decodeJsonOption($options['runtime_promotion_receipt_json'] ?? null)),
            'completion_receipt' => (array) ($options['completion_receipt'] ?? $this->decodeJsonOption($options['completion_receipt_json'] ?? null)),
            'real_provider_smoke' => (array) ($options['real_provider_smoke'] ?? $this->decodeJsonOption($options['real_provider_smoke_json'] ?? null)),
        ]);

        return $this->wrapCertificationWorkbenchStatus(
            keyPrefix: 'atlas_self_construction_completion_evidence_hash_composer',
            label: 'Atlas Self-Construction Completion Evidence Hash Composer',
            payload: $result,
            statusKey: 'status',
            extraStatusFields: [
                'composer_hash' => (string) data_get($result, 'composer_hash'),
                'runtime_promotion_receipt_hash' => (string) data_get($result, 'runtime_promotion_receipt.computed_hash'),
                'human_completion_receipt_hash' => (string) data_get($result, 'human_completion_receipt.computed_hash'),
                'real_provider_smoke_hash' => (string) data_get($result, 'real_provider_smoke.computed_hash'),
                'completion_claim_allowed' => false,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
        public function atlasSelfConstructionRuntimePromotionReceiptDraftContract(array $options = []): array
    {
        return $this->runtimePromotionSection()->atlasSelfConstructionRuntimePromotionReceiptDraftContract($options);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
        public function atlasSelfConstructionRuntimePromotionReceiptDraftPreflight(array $options = []): array
    {
        return $this->runtimePromotionSection()->atlasSelfConstructionRuntimePromotionReceiptDraftPreflight($options);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
        public function atlasSelfConstructionRuntimePromotionReceiptDraftImplementationPacket(array $options = []): array
    {
        return $this->runtimePromotionSection()->atlasSelfConstructionRuntimePromotionReceiptDraftImplementationPacket($options);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
        public function atlasSelfConstructionRuntimePromotionReceiptDraftStatus(array $options = []): array
    {
        return $this->runtimePromotionSection()->atlasSelfConstructionRuntimePromotionReceiptDraftStatus($options);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
        public function atlasSelfConstructionRuntimePromotionDraftHashFinalizerContract(array $options = []): array
    {
        return $this->runtimePromotionSection()->atlasSelfConstructionRuntimePromotionDraftHashFinalizerContract($options);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
        public function atlasSelfConstructionRuntimePromotionDraftHashFinalizerPreflight(array $options = []): array
    {
        return $this->runtimePromotionSection()->atlasSelfConstructionRuntimePromotionDraftHashFinalizerPreflight($options);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
        public function atlasSelfConstructionRuntimePromotionDraftHashFinalizerImplementationPacket(array $options = []): array
    {
        return $this->runtimePromotionSection()->atlasSelfConstructionRuntimePromotionDraftHashFinalizerImplementationPacket($options);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
        public function atlasSelfConstructionRuntimePromotionDraftHashFinalizerStatus(array $options = []): array
    {
        return $this->runtimePromotionSection()->atlasSelfConstructionRuntimePromotionDraftHashFinalizerStatus($options);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function atlasSelfConstructionOperatorEvidenceDraftHashFinalizerContract(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('atlas_self_construction_operator_evidence_draft_hash_finalizer', 'contract');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function atlasSelfConstructionOperatorEvidenceDraftHashFinalizerPreflight(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('atlas_self_construction_operator_evidence_draft_hash_finalizer', 'preflight');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function atlasSelfConstructionOperatorEvidenceDraftHashFinalizerImplementationPacket(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('atlas_self_construction_operator_evidence_draft_hash_finalizer', 'implementation_packet');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function atlasSelfConstructionOperatorEvidenceDraftHashFinalizerStatus(array $options = []): array
    {
        $result = (new AtlasSelfConstructionOperatorEvidenceDraftHashFinalizerService)->finalize([
            'operator_draft_workspace_path' => (string) ($options['operator_draft_workspace_path'] ?? ''),
            'write_computed_operator_draft_hashes' => (bool) ($options['write_computed_operator_draft_hashes'] ?? false),
        ]);

        return $this->wrapCertificationWorkbenchStatus(
            keyPrefix: 'atlas_self_construction_operator_evidence_draft_hash_finalizer',
            label: 'Atlas Self-Construction Operator Evidence Draft Hash Finalizer',
            payload: $result,
            statusKey: 'status',
            extraStatusFields: [
                'finalizer_hash' => (string) data_get($result, 'finalizer_hash'),
                'artifact_count' => (int) data_get($result, 'artifact_count', 0),
                'ready_artifact_count' => (int) data_get($result, 'ready_artifact_count', 0),
                'blocked_artifact_count' => (int) data_get($result, 'blocked_artifact_count', 0),
                'written_artifact_count' => (int) data_get($result, 'written_artifact_count', 0),
                'write_requested' => (bool) data_get($result, 'write_requested', false),
                'completion_allowed' => false,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function atlasSelfConstructionOperatorEvidenceDraftWorkspacePublisherContract(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('atlas_self_construction_operator_evidence_draft_workspace_publisher', 'contract');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function atlasSelfConstructionOperatorEvidenceDraftWorkspacePublisherPreflight(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('atlas_self_construction_operator_evidence_draft_workspace_publisher', 'preflight');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function atlasSelfConstructionOperatorEvidenceDraftWorkspacePublisherImplementationPacket(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('atlas_self_construction_operator_evidence_draft_workspace_publisher', 'implementation_packet');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function atlasSelfConstructionOperatorEvidenceDraftWorkspacePublisherStatus(array $options = []): array
    {
        $result = (new AtlasSelfConstructionOperatorEvidenceDraftWorkspacePublisherService)->publish([
            'operator_draft_workspace_path' => (string) ($options['operator_draft_workspace_path'] ?? ''),
            'publish_operator_draft_workspace' => (bool) ($options['publish_operator_draft_workspace'] ?? false),
        ]);

        return $this->wrapCertificationWorkbenchStatus(
            keyPrefix: 'atlas_self_construction_operator_evidence_draft_workspace_publisher',
            label: 'Atlas Self-Construction Operator Evidence Draft Workspace Publisher',
            payload: $result,
            statusKey: 'status',
            extraStatusFields: [
                'publisher_hash' => (string) data_get($result, 'publisher_hash'),
                'artifact_count' => (int) data_get($result, 'artifact_count', 0),
                'publishable_artifact_count' => (int) data_get($result, 'publishable_artifact_count', 0),
                'blocked_artifact_count' => (int) data_get($result, 'blocked_artifact_count', 0),
                'published_artifact_count' => (int) data_get($result, 'published_artifact_count', 0),
                'publish_requested' => (bool) data_get($result, 'publish_requested', false),
                'completion_allowed' => false,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
        public function atlasSelfConstructionHumanCompletionReceiptDraftContract(array $options = []): array
    {
        return $this->humanCompletionReceiptSection()->atlasSelfConstructionHumanCompletionReceiptDraftContract($options);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
        public function atlasSelfConstructionHumanCompletionReceiptDraftPreflight(array $options = []): array
    {
        return $this->humanCompletionReceiptSection()->atlasSelfConstructionHumanCompletionReceiptDraftPreflight($options);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
        public function atlasSelfConstructionHumanCompletionReceiptDraftImplementationPacket(array $options = []): array
    {
        return $this->humanCompletionReceiptSection()->atlasSelfConstructionHumanCompletionReceiptDraftImplementationPacket($options);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
        public function atlasSelfConstructionHumanCompletionReceiptDraftStatus(array $options = []): array
    {
        return $this->humanCompletionReceiptSection()->atlasSelfConstructionHumanCompletionReceiptDraftStatus($options);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function atlasSelfConstructionFinalOperatorEvidenceClosureCorridorContract(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('atlas_self_construction_final_operator_evidence_closure_corridor', 'contract');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function atlasSelfConstructionFinalOperatorEvidenceClosureCorridorPreflight(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('atlas_self_construction_final_operator_evidence_closure_corridor', 'preflight');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function atlasSelfConstructionFinalOperatorEvidenceClosureCorridorImplementationPacket(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('atlas_self_construction_final_operator_evidence_closure_corridor', 'implementation_packet');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function atlasSelfConstructionFinalOperatorEvidenceClosureCorridorStatus(array $options = []) : array
    {
        return $this->osEvidenceSection()->atlasSelfConstructionFinalOperatorEvidenceClosureCorridorStatus($options);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function atlasSelfConstructionOperatorEvidenceArtifactTemplatePackContract(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('atlas_self_construction_operator_evidence_artifact_template_pack', 'contract');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function atlasSelfConstructionOperatorEvidenceArtifactTemplatePackPreflight(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('atlas_self_construction_operator_evidence_artifact_template_pack', 'preflight');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function atlasSelfConstructionOperatorEvidenceArtifactTemplatePackImplementationPacket(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('atlas_self_construction_operator_evidence_artifact_template_pack', 'implementation_packet');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function atlasSelfConstructionOperatorEvidenceArtifactTemplatePackStatus(array $options = []): array
    {
        $result = (new AtlasSelfConstructionOperatorEvidenceArtifactTemplatePackService($this))->build($options);

        return $this->wrapCertificationWorkbenchStatus(
            keyPrefix: 'atlas_self_construction_operator_evidence_artifact_template_pack',
            label: 'Atlas Self-Construction Operator Evidence Artifact Template Pack',
            payload: $result,
            statusKey: 'status',
            extraStatusFields: [
                'template_pack_hash' => (string) data_get($result, 'template_pack_hash'),
                'template_count' => (int) data_get($result, 'template_count', 0),
                'operator_draft_workspace_status' => (string) data_get($result, 'operator_draft_workspace.status', ''),
                'operator_draft_workspace_persisted' => (bool) data_get($result, 'operator_draft_workspace.persisted', false),
                'operator_draft_workspace_directory' => (string) data_get($result, 'operator_draft_workspace.workspace_directory', ''),
                'operator_draft_workspace_manifest_path' => (string) data_get($result, 'operator_draft_workspace.manifest_path', ''),
                'operator_draft_workspace_cli_path' => (string) data_get($result, 'operator_draft_workspace.workspace_cli_path', ''),
                'operator_draft_workspace_private_storage_path' => (string) data_get($result, 'operator_draft_workspace.workspace_private_storage_path', ''),
                'operator_draft_workspace_manifest_cli_path' => (string) data_get($result, 'operator_draft_workspace.manifest_cli_path', ''),
                'operator_draft_workspace_manifest_private_storage_path' => (string) data_get($result, 'operator_draft_workspace.manifest_private_storage_path', ''),
                'operator_draft_workspace_artifact_count' => (int) data_get($result, 'operator_draft_workspace.artifact_count', data_get($result, 'operator_draft_workspace.manifest.artifact_count', 0)),
                'operator_draft_workspace_next_required_submission' => (string) data_get($result, 'operator_draft_workspace.manifest.next_required_submission', ''),
                'operator_draft_workspace_finalize_hashes_command' => (string) data_get($result, 'operator_draft_workspace.manifest.files.0.command_to_finalize_workspace_hashes', ''),
                'operator_draft_workspace_publish_command' => (string) data_get($result, 'operator_draft_workspace.manifest.command_to_publish_finalized_workspace', ''),
                'operator_draft_workspace_can_persist_completion_evidence' => (bool) data_get($result, 'operator_draft_workspace.can_persist_completion_evidence_from_draft_workspace', false),
                'operator_draft_workspace_can_promote_completion' => (bool) data_get($result, 'operator_draft_workspace.can_promote_completion_from_draft_workspace', false),
                'completion_allowed' => false,
                'completion_claim_allowed' => false,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function atlasSelfConstructionOperatorEvidenceDraftWorkspaceInspectorContract(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('atlas_self_construction_operator_evidence_draft_workspace_inspector', 'contract');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function atlasSelfConstructionOperatorEvidenceDraftWorkspaceInspectorPreflight(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('atlas_self_construction_operator_evidence_draft_workspace_inspector', 'preflight');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function atlasSelfConstructionOperatorEvidenceDraftWorkspaceInspectorImplementationPacket(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('atlas_self_construction_operator_evidence_draft_workspace_inspector', 'implementation_packet');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function atlasSelfConstructionOperatorEvidenceDraftWorkspaceInspectorStatus(array $options = []): array
    {
        $result = (new AtlasSelfConstructionOperatorEvidenceDraftWorkspaceInspectorService)->inspect([
            'operator_draft_workspace_path' => (string) ($options['operator_draft_workspace_path'] ?? ''),
        ]);

        return $this->wrapCertificationWorkbenchStatus(
            keyPrefix: 'atlas_self_construction_operator_evidence_draft_workspace_inspector',
            label: 'Atlas Self-Construction Operator Evidence Draft Workspace Inspector',
            payload: $result,
            statusKey: 'status',
            extraStatusFields: [
                'inspector_hash' => (string) data_get($result, 'inspector_hash'),
                'manifest_path' => (string) data_get($result, 'manifest_path'),
                'artifact_count' => (int) data_get($result, 'artifact_count', 0),
                'violation_count' => (int) data_get($result, 'violation_count', 0),
                'warning_count' => (int) data_get($result, 'warning_count', 0),
                'workspace_safe_for_operator_editing' => (bool) data_get($result, 'workspace_safe_for_operator_editing', false),
                'completion_allowed' => false,
                'completion_claim_allowed' => false,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function atlasSelfConstructionOperatorEvidenceSubmissionReadinessContract(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('atlas_self_construction_operator_evidence_submission_readiness', 'contract');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function atlasSelfConstructionOperatorEvidenceSubmissionReadinessPreflight(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('atlas_self_construction_operator_evidence_submission_readiness', 'preflight');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function atlasSelfConstructionOperatorEvidenceSubmissionReadinessImplementationPacket(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('atlas_self_construction_operator_evidence_submission_readiness', 'implementation_packet');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function atlasSelfConstructionOperatorEvidenceSubmissionReadinessStatus(array $options = []) : array
    {
        return $this->osEvidenceSection()->atlasSelfConstructionOperatorEvidenceSubmissionReadinessStatus($options);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
        public function atlasSelfConstructionRuntimePromotionEvidenceDossierContract(array $options = []): array
    {
        return $this->runtimePromotionSection()->atlasSelfConstructionRuntimePromotionEvidenceDossierContract($options);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
        public function atlasSelfConstructionRuntimePromotionEvidenceDossierPreflight(array $options = []): array
    {
        return $this->runtimePromotionSection()->atlasSelfConstructionRuntimePromotionEvidenceDossierPreflight($options);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
        public function atlasSelfConstructionRuntimePromotionEvidenceDossierImplementationPacket(array $options = []): array
    {
        return $this->runtimePromotionSection()->atlasSelfConstructionRuntimePromotionEvidenceDossierImplementationPacket($options);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
        public function atlasSelfConstructionRuntimePromotionEvidenceDossierStatus(array $options = []): array
    {
        return $this->runtimePromotionSection()->atlasSelfConstructionRuntimePromotionEvidenceDossierStatus($options);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
        public function atlasSelfConstructionHumanCompletionReceiptDossierContract(array $options = []): array
    {
        return $this->humanCompletionReceiptSection()->atlasSelfConstructionHumanCompletionReceiptDossierContract($options);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
        public function atlasSelfConstructionHumanCompletionReceiptDossierPreflight(array $options = []): array
    {
        return $this->humanCompletionReceiptSection()->atlasSelfConstructionHumanCompletionReceiptDossierPreflight($options);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
        public function atlasSelfConstructionHumanCompletionReceiptDossierImplementationPacket(array $options = []): array
    {
        return $this->humanCompletionReceiptSection()->atlasSelfConstructionHumanCompletionReceiptDossierImplementationPacket($options);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
        public function atlasSelfConstructionHumanCompletionReceiptDossierStatus(array $options = []): array
    {
        return $this->humanCompletionReceiptSection()->atlasSelfConstructionHumanCompletionReceiptDossierStatus($options);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
        public function atlasSelfConstructionHumanCompletionReceiptClosureExecutionPackContract(array $options = []): array
    {
        return $this->humanCompletionReceiptSection()->atlasSelfConstructionHumanCompletionReceiptClosureExecutionPackContract($options);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
        public function atlasSelfConstructionHumanCompletionReceiptClosureExecutionPackPreflight(array $options = []): array
    {
        return $this->humanCompletionReceiptSection()->atlasSelfConstructionHumanCompletionReceiptClosureExecutionPackPreflight($options);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
        public function atlasSelfConstructionHumanCompletionReceiptClosureExecutionPackImplementationPacket(array $options = []): array
    {
        return $this->humanCompletionReceiptSection()->atlasSelfConstructionHumanCompletionReceiptClosureExecutionPackImplementationPacket($options);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
        public function atlasSelfConstructionHumanCompletionReceiptClosureExecutionPackStatus(array $options = []): array
    {
        return $this->humanCompletionReceiptSection()->atlasSelfConstructionHumanCompletionReceiptClosureExecutionPackStatus($options);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
        public function atlasSelfConstructionRealProviderSmokeEvidenceDossierContract(array $options = []): array
    {
        return $this->realProviderSmokeSection()->atlasSelfConstructionRealProviderSmokeEvidenceDossierContract($options);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
        public function atlasSelfConstructionRealProviderSmokeEvidenceDossierPreflight(array $options = []): array
    {
        return $this->realProviderSmokeSection()->atlasSelfConstructionRealProviderSmokeEvidenceDossierPreflight($options);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
        public function atlasSelfConstructionRealProviderSmokeEvidenceDossierImplementationPacket(array $options = []): array
    {
        return $this->realProviderSmokeSection()->atlasSelfConstructionRealProviderSmokeEvidenceDossierImplementationPacket($options);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
        public function atlasSelfConstructionRealProviderSmokeEvidenceDossierStatus(array $options = []): array
    {
        return $this->realProviderSmokeSection()->atlasSelfConstructionRealProviderSmokeEvidenceDossierStatus($options);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
        public function atlasSelfConstructionRealProviderSmokeRunbookContract(array $options = []): array
    {
        return $this->realProviderSmokeSection()->atlasSelfConstructionRealProviderSmokeRunbookContract($options);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
        public function atlasSelfConstructionRealProviderSmokeRunbookPreflight(array $options = []): array
    {
        return $this->realProviderSmokeSection()->atlasSelfConstructionRealProviderSmokeRunbookPreflight($options);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
        public function atlasSelfConstructionRealProviderSmokeRunbookImplementationPacket(array $options = []): array
    {
        return $this->realProviderSmokeSection()->atlasSelfConstructionRealProviderSmokeRunbookImplementationPacket($options);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
        public function atlasSelfConstructionRealProviderSmokeRunbookStatus(array $options = []): array
    {
        return $this->realProviderSmokeSection()->atlasSelfConstructionRealProviderSmokeRunbookStatus($options);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
        public function atlasSelfConstructionRealProviderSmokeOfflineHarnessContract(array $options = []): array
    {
        return $this->realProviderSmokeSection()->atlasSelfConstructionRealProviderSmokeOfflineHarnessContract($options);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
        public function atlasSelfConstructionRealProviderSmokeOfflineHarnessPreflight(array $options = []): array
    {
        return $this->realProviderSmokeSection()->atlasSelfConstructionRealProviderSmokeOfflineHarnessPreflight($options);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
        public function atlasSelfConstructionRealProviderSmokeOfflineHarnessImplementationPacket(array $options = []): array
    {
        return $this->realProviderSmokeSection()->atlasSelfConstructionRealProviderSmokeOfflineHarnessImplementationPacket($options);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
        public function atlasSelfConstructionRealProviderSmokeOfflineHarnessStatus(array $options = []): array
    {
        return $this->realProviderSmokeSection()->atlasSelfConstructionRealProviderSmokeOfflineHarnessStatus($options);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
        public function atlasSelfConstructionRealProviderSmokeDraftContract(array $options = []): array
    {
        return $this->realProviderSmokeSection()->atlasSelfConstructionRealProviderSmokeDraftContract($options);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
        public function atlasSelfConstructionRealProviderSmokeDraftPreflight(array $options = []): array
    {
        return $this->realProviderSmokeSection()->atlasSelfConstructionRealProviderSmokeDraftPreflight($options);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
        public function atlasSelfConstructionRealProviderSmokeDraftImplementationPacket(array $options = []): array
    {
        return $this->realProviderSmokeSection()->atlasSelfConstructionRealProviderSmokeDraftImplementationPacket($options);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
        public function atlasSelfConstructionRealProviderSmokeDraftStatus(array $options = []): array
    {
        return $this->realProviderSmokeSection()->atlasSelfConstructionRealProviderSmokeDraftStatus($options);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
        public function atlasSelfConstructionHumanCompletionReceiptRunbookContract(array $options = []): array
    {
        return $this->humanCompletionReceiptSection()->atlasSelfConstructionHumanCompletionReceiptRunbookContract($options);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
        public function atlasSelfConstructionHumanCompletionReceiptRunbookPreflight(array $options = []): array
    {
        return $this->humanCompletionReceiptSection()->atlasSelfConstructionHumanCompletionReceiptRunbookPreflight($options);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
        public function atlasSelfConstructionHumanCompletionReceiptRunbookImplementationPacket(array $options = []): array
    {
        return $this->humanCompletionReceiptSection()->atlasSelfConstructionHumanCompletionReceiptRunbookImplementationPacket($options);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
        public function atlasSelfConstructionHumanCompletionReceiptRunbookStatus(array $options = []): array
    {
        return $this->humanCompletionReceiptSection()->atlasSelfConstructionHumanCompletionReceiptRunbookStatus($options);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
        public function atlasSelfConstructionRuntimePromotionReceiptRunbookContract(array $options = []): array
    {
        return $this->runtimePromotionSection()->atlasSelfConstructionRuntimePromotionReceiptRunbookContract($options);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
        public function atlasSelfConstructionRuntimePromotionReceiptRunbookPreflight(array $options = []): array
    {
        return $this->runtimePromotionSection()->atlasSelfConstructionRuntimePromotionReceiptRunbookPreflight($options);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
        public function atlasSelfConstructionRuntimePromotionReceiptRunbookImplementationPacket(array $options = []): array
    {
        return $this->runtimePromotionSection()->atlasSelfConstructionRuntimePromotionReceiptRunbookImplementationPacket($options);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
        public function atlasSelfConstructionRuntimePromotionReceiptRunbookStatus(array $options = []): array
    {
        return $this->runtimePromotionSection()->atlasSelfConstructionRuntimePromotionReceiptRunbookStatus($options);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
        public function atlasSelfConstructionRuntimePromotionClosureExecutionPackContract(array $options = []): array
    {
        return $this->runtimePromotionSection()->atlasSelfConstructionRuntimePromotionClosureExecutionPackContract($options);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
        public function atlasSelfConstructionRuntimePromotionClosureExecutionPackPreflight(array $options = []): array
    {
        return $this->runtimePromotionSection()->atlasSelfConstructionRuntimePromotionClosureExecutionPackPreflight($options);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
        public function atlasSelfConstructionRuntimePromotionClosureExecutionPackImplementationPacket(array $options = []): array
    {
        return $this->runtimePromotionSection()->atlasSelfConstructionRuntimePromotionClosureExecutionPackImplementationPacket($options);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
        public function atlasSelfConstructionRuntimePromotionClosureExecutionPackStatus(array $options = []): array
    {
        return $this->runtimePromotionSection()->atlasSelfConstructionRuntimePromotionClosureExecutionPackStatus($options);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
        public function atlasSelfConstructionRuntimePromotionReceiptPreSubmissionVerifierContract(array $options = []): array
    {
        return $this->runtimePromotionSection()->atlasSelfConstructionRuntimePromotionReceiptPreSubmissionVerifierContract($options);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
        public function atlasSelfConstructionRuntimePromotionReceiptPreSubmissionVerifierPreflight(array $options = []): array
    {
        return $this->runtimePromotionSection()->atlasSelfConstructionRuntimePromotionReceiptPreSubmissionVerifierPreflight($options);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
        public function atlasSelfConstructionRuntimePromotionReceiptPreSubmissionVerifierImplementationPacket(array $options = []): array
    {
        return $this->runtimePromotionSection()->atlasSelfConstructionRuntimePromotionReceiptPreSubmissionVerifierImplementationPacket($options);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
        public function atlasSelfConstructionRuntimePromotionReceiptPreSubmissionVerifierStatus(array $options = []): array
    {
        return $this->runtimePromotionSection()->atlasSelfConstructionRuntimePromotionReceiptPreSubmissionVerifierStatus($options);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
        public function atlasSelfConstructionRuntimePromotionEndgameContract(array $options = []): array
    {
        return $this->runtimePromotionSection()->atlasSelfConstructionRuntimePromotionEndgameContract($options);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
        public function atlasSelfConstructionRuntimePromotionEndgamePreflight(array $options = []): array
    {
        return $this->runtimePromotionSection()->atlasSelfConstructionRuntimePromotionEndgamePreflight($options);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
        public function atlasSelfConstructionRuntimePromotionEndgameImplementationPacket(array $options = []): array
    {
        return $this->runtimePromotionSection()->atlasSelfConstructionRuntimePromotionEndgameImplementationPacket($options);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
        public function atlasSelfConstructionRuntimePromotionEndgameStatus(array $options = []): array
    {
        return $this->runtimePromotionSection()->atlasSelfConstructionRuntimePromotionEndgameStatus($options);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
        public function atlasSelfConstructionRuntimePromotionEndgameVerifierContract(array $options = []): array
    {
        return $this->runtimePromotionSection()->atlasSelfConstructionRuntimePromotionEndgameVerifierContract($options);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
        public function atlasSelfConstructionRuntimePromotionEndgameVerifierPreflight(array $options = []): array
    {
        return $this->runtimePromotionSection()->atlasSelfConstructionRuntimePromotionEndgameVerifierPreflight($options);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
        public function atlasSelfConstructionRuntimePromotionEndgameVerifierImplementationPacket(array $options = []): array
    {
        return $this->runtimePromotionSection()->atlasSelfConstructionRuntimePromotionEndgameVerifierImplementationPacket($options);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
        public function atlasSelfConstructionRuntimePromotionEndgameVerifierStatus(array $options = []): array
    {
        return $this->runtimePromotionSection()->atlasSelfConstructionRuntimePromotionEndgameVerifierStatus($options);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
        public function atlasSelfConstructionRuntimePromotionOperatorRunbookExporterContract(array $options = []): array
    {
        return $this->runtimePromotionSection()->atlasSelfConstructionRuntimePromotionOperatorRunbookExporterContract($options);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
        public function atlasSelfConstructionRuntimePromotionOperatorRunbookExporterPreflight(array $options = []): array
    {
        return $this->runtimePromotionSection()->atlasSelfConstructionRuntimePromotionOperatorRunbookExporterPreflight($options);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
        public function atlasSelfConstructionRuntimePromotionOperatorRunbookExporterImplementationPacket(array $options = []): array
    {
        return $this->runtimePromotionSection()->atlasSelfConstructionRuntimePromotionOperatorRunbookExporterImplementationPacket($options);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
        public function atlasSelfConstructionRuntimePromotionOperatorRunbookExporterStatus(array $options = []): array
    {
        return $this->runtimePromotionSection()->atlasSelfConstructionRuntimePromotionOperatorRunbookExporterStatus($options);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
        public function atlasSelfConstructionRealProviderSmokeEndgameContract(array $options = []): array
    {
        return $this->realProviderSmokeSection()->atlasSelfConstructionRealProviderSmokeEndgameContract($options);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
        public function atlasSelfConstructionRealProviderSmokeEndgamePreflight(array $options = []): array
    {
        return $this->realProviderSmokeSection()->atlasSelfConstructionRealProviderSmokeEndgamePreflight($options);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
        public function atlasSelfConstructionRealProviderSmokeEndgameImplementationPacket(array $options = []): array
    {
        return $this->realProviderSmokeSection()->atlasSelfConstructionRealProviderSmokeEndgameImplementationPacket($options);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
        public function atlasSelfConstructionRealProviderSmokeEndgameStatus(array $options = []): array
    {
        return $this->realProviderSmokeSection()->atlasSelfConstructionRealProviderSmokeEndgameStatus($options);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
        public function atlasSelfConstructionRealProviderSmokeEndgameVerifierContract(array $options = []): array
    {
        return $this->realProviderSmokeSection()->atlasSelfConstructionRealProviderSmokeEndgameVerifierContract($options);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
        public function atlasSelfConstructionRealProviderSmokeEndgameVerifierPreflight(array $options = []): array
    {
        return $this->realProviderSmokeSection()->atlasSelfConstructionRealProviderSmokeEndgameVerifierPreflight($options);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
        public function atlasSelfConstructionRealProviderSmokeEndgameVerifierImplementationPacket(array $options = []): array
    {
        return $this->realProviderSmokeSection()->atlasSelfConstructionRealProviderSmokeEndgameVerifierImplementationPacket($options);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
        public function atlasSelfConstructionRealProviderSmokeEndgameVerifierStatus(array $options = []): array
    {
        return $this->realProviderSmokeSection()->atlasSelfConstructionRealProviderSmokeEndgameVerifierStatus($options);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
        public function atlasSelfConstructionRealProviderSmokeEvidenceLedgerPreflightContract(array $options = []): array
    {
        return $this->realProviderSmokeSection()->atlasSelfConstructionRealProviderSmokeEvidenceLedgerPreflightContract($options);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
        public function atlasSelfConstructionRealProviderSmokeEvidenceLedgerPreflightPreflight(array $options = []): array
    {
        return $this->realProviderSmokeSection()->atlasSelfConstructionRealProviderSmokeEvidenceLedgerPreflightPreflight($options);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
        public function atlasSelfConstructionRealProviderSmokeEvidenceLedgerPreflightImplementationPacket(array $options = []): array
    {
        return $this->realProviderSmokeSection()->atlasSelfConstructionRealProviderSmokeEvidenceLedgerPreflightImplementationPacket($options);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
        public function atlasSelfConstructionRealProviderSmokeEvidenceLedgerPreflightStatus(array $options = []): array
    {
        return $this->realProviderSmokeSection()->atlasSelfConstructionRealProviderSmokeEvidenceLedgerPreflightStatus($options);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
        public function atlasSelfConstructionRealProviderSmokeOperatorRunbookExporterContract(array $options = []): array
    {
        return $this->realProviderSmokeSection()->atlasSelfConstructionRealProviderSmokeOperatorRunbookExporterContract($options);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
        public function atlasSelfConstructionRealProviderSmokeOperatorRunbookExporterPreflight(array $options = []): array
    {
        return $this->realProviderSmokeSection()->atlasSelfConstructionRealProviderSmokeOperatorRunbookExporterPreflight($options);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
        public function atlasSelfConstructionRealProviderSmokeOperatorRunbookExporterImplementationPacket(array $options = []): array
    {
        return $this->realProviderSmokeSection()->atlasSelfConstructionRealProviderSmokeOperatorRunbookExporterImplementationPacket($options);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
        public function atlasSelfConstructionRealProviderSmokeOperatorRunbookExporterStatus(array $options = []): array
    {
        return $this->realProviderSmokeSection()->atlasSelfConstructionRealProviderSmokeOperatorRunbookExporterStatus($options);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
        public function atlasSelfConstructionHumanCompletionReceiptEndgameVerifierContract(array $options = []): array
    {
        return $this->humanCompletionReceiptSection()->atlasSelfConstructionHumanCompletionReceiptEndgameVerifierContract($options);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
        public function atlasSelfConstructionHumanCompletionReceiptEndgameVerifierPreflight(array $options = []): array
    {
        return $this->humanCompletionReceiptSection()->atlasSelfConstructionHumanCompletionReceiptEndgameVerifierPreflight($options);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
        public function atlasSelfConstructionHumanCompletionReceiptEndgameVerifierImplementationPacket(array $options = []): array
    {
        return $this->humanCompletionReceiptSection()->atlasSelfConstructionHumanCompletionReceiptEndgameVerifierImplementationPacket($options);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
        public function atlasSelfConstructionHumanCompletionReceiptEndgameVerifierStatus(array $options = []): array
    {
        return $this->humanCompletionReceiptSection()->atlasSelfConstructionHumanCompletionReceiptEndgameVerifierStatus($options);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function atlasSelfConstructionFinalCompletionHumanGateContract(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('atlas_self_construction_final_completion_human_gate', 'contract');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function atlasSelfConstructionFinalCompletionHumanGatePreflight(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('atlas_self_construction_final_completion_human_gate', 'preflight');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function atlasSelfConstructionFinalCompletionHumanGateImplementationPacket(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('atlas_self_construction_final_completion_human_gate', 'implementation_packet');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function atlasSelfConstructionFinalCompletionHumanGateStatus(array $options = []): array
    {
        return $this->finalCompletionGateSection()->atlasSelfConstructionFinalCompletionHumanGateStatus($options);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function atlasSelfConstructionFinalCompletionDossierExporterContract(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('atlas_self_construction_final_completion_dossier_exporter', 'contract');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function atlasSelfConstructionFinalCompletionDossierExporterPreflight(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('atlas_self_construction_final_completion_dossier_exporter', 'preflight');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function atlasSelfConstructionFinalCompletionDossierExporterImplementationPacket(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('atlas_self_construction_final_completion_dossier_exporter', 'implementation_packet');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function atlasSelfConstructionFinalCompletionDossierExporterStatus(array $options = []): array
    {
        return $this->finalCompletionGateSection()->atlasSelfConstructionFinalCompletionDossierExporterStatus($options);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function atlasSelfConstructionFinalCompletionReadinessGateContract(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('atlas_self_construction_final_completion_readiness_gate', 'contract');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function atlasSelfConstructionFinalCompletionReadinessGatePreflight(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('atlas_self_construction_final_completion_readiness_gate', 'preflight');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function atlasSelfConstructionFinalCompletionReadinessGateImplementationPacket(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('atlas_self_construction_final_completion_readiness_gate', 'implementation_packet');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function atlasSelfConstructionFinalCompletionReadinessGateStatus(array $options = []): array
    {
        return $this->finalCompletionGateSection()->atlasSelfConstructionFinalCompletionReadinessGateStatus($options);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function atlasSelfProgrammingOsTransitionReadinessContract(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('atlas_self_programming_os_transition_readiness', 'contract');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function atlasSelfProgrammingOsTransitionReadinessPreflight(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('atlas_self_programming_os_transition_readiness', 'preflight');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function atlasSelfProgrammingOsTransitionReadinessImplementationPacket(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('atlas_self_programming_os_transition_readiness', 'implementation_packet');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function atlasSelfProgrammingOsTransitionReadinessStatus(array $options = []) : array
    {
        return $this->osEvidenceSection()->atlasSelfProgrammingOsTransitionReadinessStatus($options);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function atlasSelfProgrammingSafetyContractCertificationContract(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('atlas_self_programming_safety_contract_certification', 'contract');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function atlasSelfProgrammingSafetyContractCertificationPreflight(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('atlas_self_programming_safety_contract_certification', 'preflight');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function atlasSelfProgrammingSafetyContractCertificationImplementationPacket(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('atlas_self_programming_safety_contract_certification', 'implementation_packet');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function atlasSelfProgrammingSafetyContractCertificationStatus(array $options = []): array
    {
        return $this->finalCompletionGateSection()->atlasSelfProgrammingSafetyContractCertificationStatus($options);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function atlasSelfConstructionCompletionFinalizationGateContract(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('atlas_self_construction_completion_finalization_gate', 'contract');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function atlasSelfConstructionCompletionFinalizationGatePreflight(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('atlas_self_construction_completion_finalization_gate', 'preflight');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function atlasSelfConstructionCompletionFinalizationGateImplementationPacket(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('atlas_self_construction_completion_finalization_gate', 'implementation_packet');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function atlasSelfConstructionCompletionFinalizationGateStatus(array $options = []): array
    {
        return $this->finalCompletionGateSection()->atlasSelfConstructionCompletionFinalizationGateStatus($options);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function atlasSelfConstructionOsCompletionEvidenceStatus(array $options = []) : array
    {
        return $this->osEvidenceSection()->atlasSelfConstructionOsCompletionEvidenceStatus($options);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneRuntimeEvidenceJournalContract(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('runtime_evidence_journal', 'contract');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneRuntimeEvidenceJournalPreflight(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('runtime_evidence_journal', 'preflight');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneRuntimeEvidenceJournalImplementationPacket(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('runtime_evidence_journal', 'implementation_packet');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
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

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneExecutionWorkspaceRuntimeContract(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('execution_workspace_runtime', 'contract');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneExecutionWorkspaceRuntimePreflight(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('execution_workspace_runtime', 'preflight');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneExecutionWorkspaceRuntimeImplementationPacket(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('execution_workspace_runtime', 'implementation_packet');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
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

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneGovernanceApprovalRuntimeContract(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('governance_approval_runtime', 'contract');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneGovernanceApprovalRuntimePreflight(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('governance_approval_runtime', 'preflight');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneGovernanceApprovalRuntimeImplementationPacket(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('governance_approval_runtime', 'implementation_packet');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
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

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneAutomaticCostImportRuntimeContract(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('automatic_cost_import_runtime', 'contract');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneAutomaticCostImportRuntimePreflight(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('automatic_cost_import_runtime', 'preflight');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneAutomaticCostImportRuntimeImplementationPacket(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('automatic_cost_import_runtime', 'implementation_packet');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
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

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneAutomaticWorkProductCollectionRuntimeContract(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('automatic_work_product_collection_runtime', 'contract');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneAutomaticWorkProductCollectionRuntimePreflight(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('automatic_work_product_collection_runtime', 'preflight');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneAutomaticWorkProductCollectionRuntimeImplementationPacket(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('automatic_work_product_collection_runtime', 'implementation_packet');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
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

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneAdapterExecutionRuntimeBoundaryContract(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('adapter_execution_runtime_boundary', 'contract');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneAdapterExecutionRuntimeBoundaryPreflight(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('adapter_execution_runtime_boundary', 'preflight');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneAdapterExecutionRuntimeBoundaryImplementationPacket(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('adapter_execution_runtime_boundary', 'implementation_packet');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
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

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneDispatchPlannerRuntimeContract(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('dispatch_planner_runtime', 'contract');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneDispatchPlannerRuntimePreflight(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('dispatch_planner_runtime', 'preflight');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneDispatchPlannerRuntimeImplementationPacket(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('dispatch_planner_runtime', 'implementation_packet');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
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

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneValidationGateRuntimeContract(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('validation_gate_runtime', 'contract');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneValidationGateRuntimePreflight(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('validation_gate_runtime', 'preflight');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneValidationGateRuntimeImplementationPacket(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('validation_gate_runtime', 'implementation_packet');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
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
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneMergeReviewRuntimeContract(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('merge_review_runtime', 'contract');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneMergeReviewRuntimePreflight(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('merge_review_runtime', 'preflight');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneMergeReviewRuntimeImplementationPacket(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('merge_review_runtime', 'implementation_packet');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
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
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneTaskPacketBuilderContract(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('task_packet_builder', 'contract');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneTaskPacketBuilderPreflight(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('task_packet_builder', 'preflight');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneTaskPacketBuilderImplementationPacket(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('task_packet_builder', 'implementation_packet');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
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

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneClaimLeaseSimulatorContract(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('claim_lease_simulator', 'contract');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneClaimLeaseSimulatorPreflight(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('claim_lease_simulator', 'preflight');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneClaimLeaseSimulatorImplementationPacket(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('claim_lease_simulator', 'implementation_packet');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
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

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneScopeLockPlannerContract(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('scope_lock_planner', 'contract');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneScopeLockPlannerPreflight(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('scope_lock_planner', 'preflight');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneScopeLockPlannerImplementationPacket(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('scope_lock_planner', 'implementation_packet');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
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

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneEvidenceLedgerDryRunContract(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('evidence_ledger_dry_run', 'contract');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneEvidenceLedgerDryRunPreflight(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('evidence_ledger_dry_run', 'preflight');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneEvidenceLedgerDryRunImplementationPacket(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('evidence_ledger_dry_run', 'implementation_packet');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
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

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneContinuationSummaryBuilderContract(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('continuation_summary_builder', 'contract');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneContinuationSummaryBuilderPreflight(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('continuation_summary_builder', 'preflight');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneContinuationSummaryBuilderImplementationPacket(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('continuation_summary_builder', 'implementation_packet');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
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

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneWorkProductManifestPlannerContract(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('work_product_manifest_planner', 'contract');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneWorkProductManifestPlannerPreflight(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('work_product_manifest_planner', 'preflight');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneWorkProductManifestPlannerImplementationPacket(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('work_product_manifest_planner', 'implementation_packet');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
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

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneCostImportDryRunContract(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('cost_import_dry_run', 'contract');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneCostImportDryRunPreflight(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('cost_import_dry_run', 'preflight');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneCostImportDryRunImplementationPacket(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('cost_import_dry_run', 'implementation_packet');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
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

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneMultiAgentParallelismPlannerContract(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('multi_agent_parallelism_planner', 'contract');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneMultiAgentParallelismPlannerPreflight(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('multi_agent_parallelism_planner', 'preflight');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneMultiAgentParallelismPlannerImplementationPacket(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('multi_agent_parallelism_planner', 'implementation_packet');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
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

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneRuntimePilotOrchestratorContract(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('runtime_pilot_orchestrator', 'contract');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneRuntimePilotOrchestratorPreflight(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('runtime_pilot_orchestrator', 'preflight');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneRuntimePilotOrchestratorImplementationPacket(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('runtime_pilot_orchestrator', 'implementation_packet');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
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

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneRuntimePilotCertificationContract(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('runtime_pilot_certification', 'contract');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneRuntimePilotCertificationPreflight(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('runtime_pilot_certification', 'preflight');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneRuntimePilotCertificationImplementationPacket(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('runtime_pilot_certification', 'implementation_packet');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
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

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneTaskPacketQueueContract(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('task_packet_queue', 'contract');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneTaskPacketQueuePreflight(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('task_packet_queue', 'preflight');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneTaskPacketQueueImplementationPacket(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('task_packet_queue', 'implementation_packet');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
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

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneClaimLeaseRuntimeContract(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('claim_lease_runtime', 'contract');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneClaimLeaseRuntimePreflight(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('claim_lease_runtime', 'preflight');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneClaimLeaseRuntimeImplementationPacket(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('claim_lease_runtime', 'implementation_packet');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneTaskLeaseRecoveryContract(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('task_lease_recovery', 'contract');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneTaskLeaseRecoveryPreflight(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('task_lease_recovery', 'preflight');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneTaskLeaseRecoveryImplementationPacket(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('task_lease_recovery', 'implementation_packet');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
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

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
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

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneScopeLockRuntimeValidatorContract(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('scope_lock_runtime_validator', 'contract');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneScopeLockRuntimeValidatorPreflight(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('scope_lock_runtime_validator', 'preflight');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneScopeLockRuntimeValidatorImplementationPacket(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('scope_lock_runtime_validator', 'implementation_packet');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
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

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneTaskQueueOrchestratorContract(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('task_queue_orchestrator', 'contract');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneTaskQueueOrchestratorPreflight(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('task_queue_orchestrator', 'preflight');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneTaskQueueOrchestratorImplementationPacket(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('task_queue_orchestrator', 'implementation_packet');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
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

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
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

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneTaskQueueCompleteDryRunStatus(array $options = []) : array
    {
        return $this->agentWakeupSection()->agentControlPlaneTaskQueueCompleteDryRunStatus($options);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneTaskAutoReplenishmentContract(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('task_auto_replenishment', 'contract');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneTaskAutoReplenishmentPreflight(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('task_auto_replenishment', 'preflight');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneTaskAutoReplenishmentImplementationPacket(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('task_auto_replenishment', 'implementation_packet');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
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

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneWorkerTaskEligibilityCertificationContract(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('worker_task_eligibility_certification', 'contract');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneWorkerTaskEligibilityCertificationPreflight(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('worker_task_eligibility_certification', 'preflight');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneWorkerTaskEligibilityCertificationImplementationPacket(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('worker_task_eligibility_certification', 'implementation_packet');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneWorkerTaskEligibilityCertificationStatus(array $options = []): array
    {
        $result = (new AgentControlPlaneWorkerTaskEligibilityCertificationService(
            $this,
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

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneTerminalLoopHealthDigestContract(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('terminal_loop_health_digest', 'contract');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneTerminalLoopHealthDigestPreflight(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('terminal_loop_health_digest', 'preflight');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneTerminalLoopHealthDigestImplementationPacket(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('terminal_loop_health_digest', 'implementation_packet');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneTerminalLoopHealthDigestStatus(array $options = []) : array
    {
        return $this->terminalLoopSection()->agentControlPlaneTerminalLoopHealthDigestStatus($options);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneTerminalLoopOperationalProofContract(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('terminal_loop_operational_proof', 'contract');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneTerminalLoopOperationalProofPreflight(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('terminal_loop_operational_proof', 'preflight');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneTerminalLoopOperationalProofImplementationPacket(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('terminal_loop_operational_proof', 'implementation_packet');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneTerminalLoopOperationalProofStatus(array $options = []) : array
    {
        return $this->terminalLoopSection()->agentControlPlaneTerminalLoopOperationalProofStatus($options);
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

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneTerminalWorkerBootstrapContract(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('terminal_worker_bootstrap', 'contract');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneTerminalWorkerBootstrapPreflight(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('terminal_worker_bootstrap', 'preflight');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneTerminalWorkerBootstrapImplementationPacket(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('terminal_worker_bootstrap', 'implementation_packet');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
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

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function atlasSelfConstructionOsRuntimeGapMatrixAuditContract(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('runtime_gap_matrix_audit', 'contract');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function atlasSelfConstructionOsRuntimeGapMatrixAuditPreflight(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('runtime_gap_matrix_audit', 'preflight');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function atlasSelfConstructionOsRuntimeGapMatrixAuditImplementationPacket(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('runtime_gap_matrix_audit', 'implementation_packet');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function atlasSelfConstructionOsRuntimeGapMatrixAuditStatus(array $options = []): array
    {
        $liveStatusProjection = $options === [];
        $service = new AtlasSelfConstructionRuntimeGapMatrixAuditService($this);
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

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneTaskQueueLeaseCertificationContract(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('task_queue_lease_certification', 'contract');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneTaskQueueLeaseCertificationPreflight(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('task_queue_lease_certification', 'preflight');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneTaskQueueLeaseCertificationImplementationPacket(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('task_queue_lease_certification', 'implementation_packet');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
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

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneMultiAgentLoopCertificationContract(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('multi_agent_loop_certification', 'contract');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneMultiAgentLoopCertificationPreflight(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('multi_agent_loop_certification', 'preflight');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneMultiAgentLoopCertificationImplementationPacket(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('multi_agent_loop_certification', 'implementation_packet');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneMultiAgentLoopCertificationStatus(array $options = []) : array
    {
        return $this->ownershipBoundarySection()->agentControlPlaneMultiAgentLoopCertificationStatus($options);
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

    // ---------- Agent Runtime Registry quartets (integrated surface) ----------

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneAgentRuntimeRegistryContract(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('agent_runtime_registry', 'contract');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneAgentRuntimeRegistryPreflight(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('agent_runtime_registry', 'preflight');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneAgentRuntimeRegistryImplementationPacket(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('agent_runtime_registry', 'implementation_packet');
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
    public function agentControlPlaneAgentRuntimeRegistryHeartbeatContract(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('agent_runtime_registry_heartbeat', 'contract');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneAgentRuntimeRegistryHeartbeatPreflight(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('agent_runtime_registry_heartbeat', 'preflight');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneAgentRuntimeRegistryHeartbeatImplementationPacket(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('agent_runtime_registry_heartbeat', 'implementation_packet');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
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

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneAgentRuntimeRegistryCapabilityCatalogContract(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('agent_runtime_registry_capability_catalog', 'contract');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneAgentRuntimeRegistryCapabilityCatalogPreflight(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('agent_runtime_registry_capability_catalog', 'preflight');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneAgentRuntimeRegistryCapabilityCatalogImplementationPacket(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('agent_runtime_registry_capability_catalog', 'implementation_packet');
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
    public function agentControlPlaneAgentRuntimeRegistryAvailabilityContract(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('agent_runtime_registry_availability', 'contract');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneAgentRuntimeRegistryAvailabilityPreflight(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('agent_runtime_registry_availability', 'preflight');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneAgentRuntimeRegistryAvailabilityImplementationPacket(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('agent_runtime_registry_availability', 'implementation_packet');
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
    public function agentControlPlaneAgentRuntimeRegistryTaskMatcherContract(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('agent_runtime_registry_task_matcher', 'contract');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneAgentRuntimeRegistryTaskMatcherPreflight(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('agent_runtime_registry_task_matcher', 'preflight');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneAgentRuntimeRegistryTaskMatcherImplementationPacket(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('agent_runtime_registry_task_matcher', 'implementation_packet');
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
    public function agentControlPlaneAgentRuntimeRegistryLoadBalancingContract(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('agent_runtime_registry_load_balancing', 'contract');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneAgentRuntimeRegistryLoadBalancingPreflight(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('agent_runtime_registry_load_balancing', 'preflight');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneAgentRuntimeRegistryLoadBalancingImplementationPacket(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('agent_runtime_registry_load_balancing', 'implementation_packet');
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
    public function agentControlPlaneAgentRuntimeRegistryQuarantineContract(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('agent_runtime_registry_quarantine', 'contract');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneAgentRuntimeRegistryQuarantinePreflight(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('agent_runtime_registry_quarantine', 'preflight');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneAgentRuntimeRegistryQuarantineImplementationPacket(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('agent_runtime_registry_quarantine', 'implementation_packet');
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
    public function agentControlPlaneAgentRuntimeRegistryHandoffContract(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('agent_runtime_registry_handoff', 'contract');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneAgentRuntimeRegistryHandoffPreflight(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('agent_runtime_registry_handoff', 'preflight');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneAgentRuntimeRegistryHandoffImplementationPacket(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('agent_runtime_registry_handoff', 'implementation_packet');
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
    public function agentControlPlaneAgentRuntimeRegistryOrchestratorContract(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('agent_runtime_registry_orchestrator', 'contract');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneAgentRuntimeRegistryOrchestratorPreflight(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('agent_runtime_registry_orchestrator', 'preflight');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneAgentRuntimeRegistryOrchestratorImplementationPacket(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('agent_runtime_registry_orchestrator', 'implementation_packet');
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
    public function agentControlPlaneAgentRuntimeRegistryCertificationContract(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('agent_runtime_registry_certification', 'contract');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneAgentRuntimeRegistryCertificationPreflight(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('agent_runtime_registry_certification', 'preflight');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneAgentRuntimeRegistryCertificationImplementationPacket(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('agent_runtime_registry_certification', 'implementation_packet');
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

    /**
     * @param  array{workspace?: string|null, target?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentRunSync(array $options = []): array
    {
        return $this->agentLivenessSection()->agentRunSync($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentHeartbeat(array $options = []): array
    {
        return $this->agentLivenessSection()->agentHeartbeat($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentRunLiveness(array $options = []): array
    {
        return $this->agentLivenessSection()->agentRunLiveness($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentRunLivenessWrite(array $options = []): array
    {
        return $this->agentLivenessSection()->agentRunLivenessWrite($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, model?: string|null, input_tokens?: int|string|null, output_tokens?: int|string|null, cost_usd?: float|string|null}  $options
     * @return array<string, mixed>
     */
    public function agentCostEvent(array $options = []) : array
    {
        return $this->terminalLoopSection()->agentCostEvent($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, artifact_type?: string|null, artifact_path?: string|null, artifact_hash?: string|null, summary?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentWorkProduct(array $options = []) : array
    {
        return $this->ownershipBoundarySection()->agentWorkProduct($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAdapterContract(array $options = []) : array
    {
        return $this->agentWakeupSection()->agentAdapterContract($options);
    }

    public function agentWakeupQueue(array $options = []): array
    {
        return $this->miscProjectionsPart3Section()->agentWakeupQueue($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentWakeupWrite(array $options = []): array
    {
        return $this->agentLivenessSection()->agentWakeupWrite($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentWakeupScheduler(array $options = []): array
    {
        return $this->agentLivenessSection()->agentWakeupScheduler($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentWakeupClaim(array $options = []) : array
    {
        return $this->agentWakeupSection()->agentWakeupClaim($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null}  $options
     * @return array<string, mixed>
     */
        public function agentDispatchPreflight(array $options = []): array
    {
        return $this->agentDispatchProviderSection()->agentDispatchPreflight($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null}  $options
     * @return array<string, mixed>
     */
        public function agentDispatchReceiptTemplate(array $options = []): array
    {
        return $this->agentDispatchProviderSection()->agentDispatchReceiptTemplate($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null}  $options
     * @return array<string, mixed>
     */
        public function agentDispatchReceiptValidationPreflight(array $options = []): array
    {
        return $this->agentDispatchProviderSection()->agentDispatchReceiptValidationPreflight($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, decision?: string|null, signed_by?: string|null, receipt_hash?: string|null, dispatch_envelope_hash?: string|null, adapter_contract_hash?: string|null, expires_at?: string|null}  $options
     * @return array<string, mixed>
     */
        public function agentDispatchReceiptWrite(array $options = []): array
    {
        return $this->agentDispatchProviderSection()->agentDispatchReceiptWrite($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
        public function agentDispatchExecutorPreflight(array $options = []): array
    {
        return $this->agentDispatchProviderSection()->agentDispatchExecutorPreflight($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
        public function agentDispatchExecutorContractTemplate(array $options = []): array
    {
        return $this->agentDispatchProviderSection()->agentDispatchExecutorContractTemplate($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
        public function agentDispatchExecutorReleasePreflight(array $options = []): array
    {
        return $this->agentDispatchProviderSection()->agentDispatchExecutorReleasePreflight($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
        public function agentDispatchExecutorReceiptUseWriterContractTemplate(array $options = []): array
    {
        return $this->agentDispatchProviderSection()->agentDispatchExecutorReceiptUseWriterContractTemplate($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
        public function agentDispatchExecutorReceiptUseWriterPreflight(array $options = []): array
    {
        return $this->agentDispatchProviderSection()->agentDispatchExecutorReceiptUseWriterPreflight($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
        public function agentDispatchExecutorReceiptUseWriterImplementationPacket(array $options = []): array
    {
        return $this->agentDispatchProviderSection()->agentDispatchExecutorReceiptUseWriterImplementationPacket($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
        public function agentDispatchExecutorSandboxBindingContractTemplate(array $options = []): array
    {
        return $this->agentDispatchProviderSection()->agentDispatchExecutorSandboxBindingContractTemplate($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
        public function agentDispatchExecutorSandboxBindingPreflight(array $options = []): array
    {
        return $this->agentDispatchProviderSection()->agentDispatchExecutorSandboxBindingPreflight($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
        public function agentDispatchExecutorSandboxBindingImplementationPacket(array $options = []): array
    {
        return $this->agentDispatchProviderSection()->agentDispatchExecutorSandboxBindingImplementationPacket($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
        public function agentDispatchExecutorProviderStartDriverContractTemplate(array $options = []): array
    {
        return $this->agentDispatchProviderSection()->agentDispatchExecutorProviderStartDriverContractTemplate($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
        public function agentDispatchExecutorProviderStartDriverPreflight(array $options = []): array
    {
        return $this->agentDispatchProviderSection()->agentDispatchExecutorProviderStartDriverPreflight($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
        public function agentDispatchExecutorProviderStartDriverImplementationPacket(array $options = []): array
    {
        return $this->agentDispatchProviderSection()->agentDispatchExecutorProviderStartDriverImplementationPacket($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
        public function agentProviderAdapterRegistryContractTemplate(array $options = []): array
    {
        return $this->agentDispatchProviderSection()->agentProviderAdapterRegistryContractTemplate($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
        public function agentProviderAdapterRegistryPreflight(array $options = []): array
    {
        return $this->agentDispatchProviderSection()->agentProviderAdapterRegistryPreflight($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
        public function agentProviderAdapterRegistryImplementationPacket(array $options = []): array
    {
        return $this->agentDispatchProviderSection()->agentProviderAdapterRegistryImplementationPacket($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
        public function agentProviderAdapterExecutionGuardContractTemplate(array $options = []): array
    {
        return $this->agentDispatchProviderSection()->agentProviderAdapterExecutionGuardContractTemplate($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
        public function agentProviderAdapterExecutionGuardPreflight(array $options = []): array
    {
        return $this->agentDispatchProviderSection()->agentProviderAdapterExecutionGuardPreflight($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
        public function agentProviderAdapterExecutionGuardImplementationPacket(array $options = []): array
    {
        return $this->agentDispatchProviderSection()->agentProviderAdapterExecutionGuardImplementationPacket($options);
    }

    public function agentCodexProviderExecutionContractTemplate(array $options = []): array
    {
        return $this->agentCodexSection()->agentCodexProviderExecutionContractTemplate($options);
    }

    public function agentCodexProviderExecutionPreflight(array $options = []): array
    {
        return $this->agentCodexSection()->agentCodexProviderExecutionPreflight($options);
    }

    public function agentCodexProviderExecutionImplementationPacket(array $options = []): array
    {
        return $this->agentCodexSection()->agentCodexProviderExecutionImplementationPacket($options);
    }

    public function agentCodexProcessStartReleaseContractTemplate(array $options = []): array
    {
        return $this->agentCodexSection()->agentCodexProcessStartReleaseContractTemplate($options);
    }

    public function agentCodexProcessStartReleasePreflight(array $options = []): array
    {
        return $this->agentCodexSection()->agentCodexProcessStartReleasePreflight($options);
    }

    public function agentCodexProcessStartReleaseImplementationPacket(array $options = []): array
    {
        return $this->agentCodexSection()->agentCodexProcessStartReleaseImplementationPacket($options);
    }

    public function agentCodexSupervisedStartExecutorContractTemplate(array $options = []): array
    {
        return $this->agentCodexSection()->agentCodexSupervisedStartExecutorContractTemplate($options);
    }

    public function agentCodexSupervisedStartExecutorPreflight(array $options = []): array
    {
        return $this->agentCodexSection()->agentCodexSupervisedStartExecutorPreflight($options);
    }

    public function agentCodexSupervisedStartExecutorImplementationPacket(array $options = []): array
    {
        return $this->agentCodexSection()->agentCodexSupervisedStartExecutorImplementationPacket($options);
    }

    public function agentCodexProcessSpawnEnablementContractTemplate(array $options = []): array
    {
        return $this->agentCodexSection()->agentCodexProcessSpawnEnablementContractTemplate($options);
    }

    public function agentCodexProcessSpawnEnablementPreflight(array $options = []): array
    {
        return $this->agentCodexSection()->agentCodexProcessSpawnEnablementPreflight($options);
    }

    public function agentCodexProcessSpawnEnablementImplementationPacket(array $options = []): array
    {
        return $this->agentCodexSection()->agentCodexProcessSpawnEnablementImplementationPacket($options);
    }

    public function agentCodexProcessSpawnExecutorContractTemplate(array $options = []): array
    {
        return $this->agentCodexSection()->agentCodexProcessSpawnExecutorContractTemplate($options);
    }

    public function agentCodexProcessSpawnExecutorPreflight(array $options = []): array
    {
        return $this->agentCodexSection()->agentCodexProcessSpawnExecutorPreflight($options);
    }

    public function agentCodexProcessSpawnExecutorImplementationPacket(array $options = []): array
    {
        return $this->agentCodexSection()->agentCodexProcessSpawnExecutorImplementationPacket($options);
    }

    public function agentCodexExternalProcessRuntimeDriverContractTemplate(array $options = []): array
    {
        return $this->agentCodexSection()->agentCodexExternalProcessRuntimeDriverContractTemplate($options);
    }

    public function agentCodexExternalProcessRuntimeDriverPreflight(array $options = []): array
    {
        return $this->agentCodexSection()->agentCodexExternalProcessRuntimeDriverPreflight($options);
    }

    public function agentCodexExternalProcessRuntimeDriverImplementationPacket(array $options = []): array
    {
        return $this->agentCodexSection()->agentCodexExternalProcessRuntimeDriverImplementationPacket($options);
    }

    public function agentCodexExternalProcessInvocationAuthorizationContractTemplate(array $options = []): array
    {
        return $this->agentCodexSection()->agentCodexExternalProcessInvocationAuthorizationContractTemplate($options);
    }

    public function agentCodexExternalProcessInvocationAuthorizationPreflight(array $options = []): array
    {
        return $this->agentCodexSection()->agentCodexExternalProcessInvocationAuthorizationPreflight($options);
    }

    public function agentCodexExternalProcessInvocationAuthorizationImplementationPacket(array $options = []): array
    {
        return $this->agentCodexSection()->agentCodexExternalProcessInvocationAuthorizationImplementationPacket($options);
    }

    public function agentCodexExternalProcessInvokerDryRunContractTemplate(array $options = []): array
    {
        return $this->agentCodexSection()->agentCodexExternalProcessInvokerDryRunContractTemplate($options);
    }

    public function agentCodexExternalProcessInvokerDryRunPreflight(array $options = []): array
    {
        return $this->agentCodexSection()->agentCodexExternalProcessInvokerDryRunPreflight($options);
    }

    public function agentCodexExternalProcessInvokerDryRunImplementationPacket(array $options = []): array
    {
        return $this->agentCodexSection()->agentCodexExternalProcessInvokerDryRunImplementationPacket($options);
    }

    public function agentCodexRealInvokerReleasePreflightContractTemplate(array $options = []): array
    {
        return $this->agentCodexSection()->agentCodexRealInvokerReleasePreflightContractTemplate($options);
    }

    public function agentCodexRealInvokerReleasePreflightPreflight(array $options = []): array
    {
        return $this->agentCodexSection()->agentCodexRealInvokerReleasePreflightPreflight($options);
    }

    public function agentCodexRealInvokerReleasePreflightImplementationPacket(array $options = []): array
    {
        return $this->agentCodexSection()->agentCodexRealInvokerReleasePreflightImplementationPacket($options);
    }

    public function agentCodexSignedRealInvokerReleaseGateContractTemplate(array $options = []): array
    {
        return $this->agentCodexSection()->agentCodexSignedRealInvokerReleaseGateContractTemplate($options);
    }

    public function agentCodexSignedRealInvokerReleaseGatePreflight(array $options = []): array
    {
        return $this->agentCodexSection()->agentCodexSignedRealInvokerReleaseGatePreflight($options);
    }

    public function agentCodexSignedRealInvokerReleaseGateImplementationPacket(array $options = []): array
    {
        return $this->agentCodexSection()->agentCodexSignedRealInvokerReleaseGateImplementationPacket($options);
    }

    public function agentCodexRealInvokerImplementationBoundaryContractTemplate(array $options = []): array
    {
        return $this->agentCodexSection()->agentCodexRealInvokerImplementationBoundaryContractTemplate($options);
    }

    public function agentCodexRealInvokerImplementationBoundaryPreflight(array $options = []): array
    {
        return $this->agentCodexSection()->agentCodexRealInvokerImplementationBoundaryPreflight($options);
    }

    public function agentCodexRealInvokerImplementationBoundaryImplementationPacket(array $options = []): array
    {
        return $this->agentCodexSection()->agentCodexRealInvokerImplementationBoundaryImplementationPacket($options);
    }

    public function agentCodexRealInvokerExecutorPlanContractTemplate(array $options = []): array
    {
        return $this->agentCodexSection()->agentCodexRealInvokerExecutorPlanContractTemplate($options);
    }

    public function agentCodexRealInvokerExecutorPlanPreflight(array $options = []): array
    {
        return $this->agentCodexSection()->agentCodexRealInvokerExecutorPlanPreflight($options);
    }

    public function agentCodexRealInvokerExecutorPlanImplementationPacket(array $options = []): array
    {
        return $this->agentCodexSection()->agentCodexRealInvokerExecutorPlanImplementationPacket($options);
    }

    public function agentCodexRealInvokerExecutorFreshReleaseGateContractTemplate(array $options = []): array
    {
        return $this->agentCodexSection()->agentCodexRealInvokerExecutorFreshReleaseGateContractTemplate($options);
    }

    public function agentCodexRealInvokerExecutorFreshReleaseGatePreflight(array $options = []): array
    {
        return $this->agentCodexSection()->agentCodexRealInvokerExecutorFreshReleaseGatePreflight($options);
    }

    public function agentCodexRealInvokerExecutorFreshReleaseGateImplementationPacket(array $options = []): array
    {
        return $this->agentCodexSection()->agentCodexRealInvokerExecutorFreshReleaseGateImplementationPacket($options);
    }

    public function agentCodexRealInvokerExecutorEnablementGateContractTemplate(array $options = []): array
    {
        return $this->agentCodexSection()->agentCodexRealInvokerExecutorEnablementGateContractTemplate($options);
    }

    public function agentCodexRealInvokerExecutorEnablementGatePreflight(array $options = []): array
    {
        return $this->agentCodexSection()->agentCodexRealInvokerExecutorEnablementGatePreflight($options);
    }

    public function agentCodexRealInvokerExecutorEnablementGateImplementationPacket(array $options = []): array
    {
        return $this->agentCodexSection()->agentCodexRealInvokerExecutorEnablementGateImplementationPacket($options);
    }

    public function agentCodexRealInvokerSupervisedStartActivationGateContractTemplate(array $options = []): array
    {
        return $this->agentCodexSection()->agentCodexRealInvokerSupervisedStartActivationGateContractTemplate($options);
    }

    public function agentCodexRealInvokerSupervisedStartActivationGatePreflight(array $options = []): array
    {
        return $this->agentCodexSection()->agentCodexRealInvokerSupervisedStartActivationGatePreflight($options);
    }

    public function agentCodexRealInvokerSupervisedStartActivationGateImplementationPacket(array $options = []): array
    {
        return $this->agentCodexSection()->agentCodexRealInvokerSupervisedStartActivationGateImplementationPacket($options);
    }

    public function agentCodexRealInvokerGuardedProcessStartExecutorContractTemplate(array $options = []): array
    {
        return $this->agentCodexSection()->agentCodexRealInvokerGuardedProcessStartExecutorContractTemplate($options);
    }

    public function agentCodexRealInvokerGuardedProcessStartExecutorPreflight(array $options = []): array
    {
        return $this->agentCodexSection()->agentCodexRealInvokerGuardedProcessStartExecutorPreflight($options);
    }

    public function agentCodexRealInvokerGuardedProcessStartExecutorImplementationPacket(array $options = []): array
    {
        return $this->agentCodexSection()->agentCodexRealInvokerGuardedProcessStartExecutorImplementationPacket($options);
    }

    public function agentCodexRealInvokerFinalProcessStartAuthorizationGateContractTemplate(array $options = []): array
    {
        return $this->agentCodexSection()->agentCodexRealInvokerFinalProcessStartAuthorizationGateContractTemplate($options);
    }

    public function agentCodexRealInvokerFinalProcessStartAuthorizationGatePreflight(array $options = []): array
    {
        return $this->agentCodexSection()->agentCodexRealInvokerFinalProcessStartAuthorizationGatePreflight($options);
    }

    public function agentCodexRealInvokerFinalProcessStartAuthorizationGateImplementationPacket(array $options = []): array
    {
        return $this->agentCodexSection()->agentCodexRealInvokerFinalProcessStartAuthorizationGateImplementationPacket($options);
    }

    public function agentCodexRealInvokerActualProcessStartRehearsalExecutorContractTemplate(array $options = []): array
    {
        return $this->agentCodexSection()->agentCodexRealInvokerActualProcessStartRehearsalExecutorContractTemplate($options);
    }

    public function agentCodexRealInvokerActualProcessStartRehearsalExecutorPreflight(array $options = []): array
    {
        return $this->agentCodexSection()->agentCodexRealInvokerActualProcessStartRehearsalExecutorPreflight($options);
    }

    public function agentCodexRealInvokerActualProcessStartRehearsalExecutorImplementationPacket(array $options = []): array
    {
        return $this->agentCodexSection()->agentCodexRealInvokerActualProcessStartRehearsalExecutorImplementationPacket($options);
    }

    public function agentCodexRealInvokerProcessStartEnvelopeBuilderContractTemplate(array $options = []): array
    {
        return $this->agentCodexSection()->agentCodexRealInvokerProcessStartEnvelopeBuilderContractTemplate($options);
    }

    public function agentCodexRealInvokerProcessStartEnvelopeBuilderPreflight(array $options = []): array
    {
        return $this->agentCodexSection()->agentCodexRealInvokerProcessStartEnvelopeBuilderPreflight($options);
    }

    public function agentCodexRealInvokerProcessStartEnvelopeBuilderImplementationPacket(array $options = []): array
    {
        return $this->agentCodexSection()->agentCodexRealInvokerProcessStartEnvelopeBuilderImplementationPacket($options);
    }

    public function agentCodexRealInvokerStartExecutionGateContractTemplate(array $options = []): array
    {
        return $this->agentCodexSection()->agentCodexRealInvokerStartExecutionGateContractTemplate($options);
    }

    public function agentCodexRealInvokerStartExecutionGatePreflight(array $options = []): array
    {
        return $this->agentCodexSection()->agentCodexRealInvokerStartExecutionGatePreflight($options);
    }

    public function agentCodexRealInvokerStartExecutionGateImplementationPacket(array $options = []): array
    {
        return $this->agentCodexSection()->agentCodexRealInvokerStartExecutionGateImplementationPacket($options);
    }

    public function agentCodexRealInvokerProcessStarterReadinessGateContractTemplate(array $options = []): array
    {
        return $this->agentCodexSection()->agentCodexRealInvokerProcessStarterReadinessGateContractTemplate($options);
    }

    public function agentCodexRealInvokerProcessStarterReadinessGatePreflight(array $options = []): array
    {
        return $this->agentCodexSection()->agentCodexRealInvokerProcessStarterReadinessGatePreflight($options);
    }

    public function agentCodexRealInvokerProcessStarterReadinessGateImplementationPacket(array $options = []): array
    {
        return $this->agentCodexSection()->agentCodexRealInvokerProcessStarterReadinessGateImplementationPacket($options);
    }

    public function agentCodexRealInvokerManualStartExecutorReceiptWriterContractTemplate(array $options = []): array
    {
        return $this->agentCodexSection()->agentCodexRealInvokerManualStartExecutorReceiptWriterContractTemplate($options);
    }

    public function agentCodexRealInvokerManualStartExecutorReceiptWriterPreflight(array $options = []): array
    {
        return $this->agentCodexSection()->agentCodexRealInvokerManualStartExecutorReceiptWriterPreflight($options);
    }

    public function agentCodexRealInvokerManualStartExecutorReceiptWriterImplementationPacket(array $options = []): array
    {
        return $this->agentCodexSection()->agentCodexRealInvokerManualStartExecutorReceiptWriterImplementationPacket($options);
    }

    public function agentCodexRealInvokerOperatorStartHandoffBuilderContractTemplate(array $options = []): array
    {
        return $this->agentCodexSection()->agentCodexRealInvokerOperatorStartHandoffBuilderContractTemplate($options);
    }

    public function agentCodexRealInvokerOperatorStartHandoffBuilderPreflight(array $options = []): array
    {
        return $this->agentCodexSection()->agentCodexRealInvokerOperatorStartHandoffBuilderPreflight($options);
    }

    public function agentCodexRealInvokerOperatorStartHandoffBuilderImplementationPacket(array $options = []): array
    {
        return $this->agentCodexSection()->agentCodexRealInvokerOperatorStartHandoffBuilderImplementationPacket($options);
    }

    public function agentCodexRealInvokerPostStartReceiptContractBuilderContractTemplate(array $options = []): array
    {
        return $this->agentCodexSection()->agentCodexRealInvokerPostStartReceiptContractBuilderContractTemplate($options);
    }

    public function agentCodexRealInvokerPostStartReceiptContractBuilderPreflight(array $options = []): array
    {
        return $this->agentCodexSection()->agentCodexRealInvokerPostStartReceiptContractBuilderPreflight($options);
    }

    public function agentCodexRealInvokerPostStartReceiptContractBuilderImplementationPacket(array $options = []): array
    {
        return $this->agentCodexSection()->agentCodexRealInvokerPostStartReceiptContractBuilderImplementationPacket($options);
    }

    public function agentCodexRealInvokerPostStartEvidenceReceiptWriterContractTemplate(array $options = []): array
    {
        return $this->agentCodexSection()->agentCodexRealInvokerPostStartEvidenceReceiptWriterContractTemplate($options);
    }

    public function agentCodexRealInvokerPostStartEvidenceReceiptWriterPreflight(array $options = []): array
    {
        return $this->agentCodexSection()->agentCodexRealInvokerPostStartEvidenceReceiptWriterPreflight($options);
    }

    public function agentCodexRealInvokerPostStartEvidenceReceiptWriterImplementationPacket(array $options = []): array
    {
        return $this->agentCodexSection()->agentCodexRealInvokerPostStartEvidenceReceiptWriterImplementationPacket($options);
    }

    public function agentCodexRealInvokerPostStartEvidenceAcceptanceBridgeContractTemplate(array $options = []): array
    {
        return $this->agentCodexSection()->agentCodexRealInvokerPostStartEvidenceAcceptanceBridgeContractTemplate($options);
    }

    public function agentCodexRealInvokerPostStartEvidenceAcceptanceBridgePreflight(array $options = []): array
    {
        return $this->agentCodexSection()->agentCodexRealInvokerPostStartEvidenceAcceptanceBridgePreflight($options);
    }

    public function agentCodexRealInvokerPostStartEvidenceAcceptanceBridgeImplementationPacket(array $options = []): array
    {
        return $this->agentCodexSection()->agentCodexRealInvokerPostStartEvidenceAcceptanceBridgeImplementationPacket($options);
    }

    public function agentCodexRealInvokerPostStartLivenessMonitorContractTemplate(array $options = []): array
    {
        return $this->agentCodexSection()->agentCodexRealInvokerPostStartLivenessMonitorContractTemplate($options);
    }

    public function agentCodexRealInvokerPostStartLivenessMonitorPreflight(array $options = []): array
    {
        return $this->agentCodexSection()->agentCodexRealInvokerPostStartLivenessMonitorPreflight($options);
    }

    public function agentCodexRealInvokerPostStartLivenessMonitorImplementationPacket(array $options = []): array
    {
        return $this->agentCodexSection()->agentCodexRealInvokerPostStartLivenessMonitorImplementationPacket($options);
    }

    public function agentCodexRealInvokerPostStartDispatchReleaseGateContractTemplate(array $options = []): array
    {
        return $this->agentCodexSection()->agentCodexRealInvokerPostStartDispatchReleaseGateContractTemplate($options);
    }

    public function agentCodexRealInvokerPostStartDispatchReleaseGatePreflight(array $options = []): array
    {
        return $this->agentCodexSection()->agentCodexRealInvokerPostStartDispatchReleaseGatePreflight($options);
    }

    public function agentCodexRealInvokerPostStartDispatchReleaseGateImplementationPacket(array $options = []): array
    {
        return $this->agentCodexSection()->agentCodexRealInvokerPostStartDispatchReleaseGateImplementationPacket($options);
    }

    public function agentCodexRealInvokerPostStartSignedDispatchAuthorizationGateContractTemplate(array $options = []): array
    {
        return $this->agentCodexSection()->agentCodexRealInvokerPostStartSignedDispatchAuthorizationGateContractTemplate($options);
    }

    public function agentCodexRealInvokerPostStartSignedDispatchAuthorizationGatePreflight(array $options = []): array
    {
        return $this->agentCodexSection()->agentCodexRealInvokerPostStartSignedDispatchAuthorizationGatePreflight($options);
    }

    public function agentCodexRealInvokerPostStartSignedDispatchAuthorizationGateImplementationPacket(array $options = []): array
    {
        return $this->agentCodexSection()->agentCodexRealInvokerPostStartSignedDispatchAuthorizationGateImplementationPacket($options);
    }

    public function agentCodexRealInvokerPostStartDispatchExecutorHandoffContractTemplate(array $options = []): array
    {
        return $this->agentCodexSection()->agentCodexRealInvokerPostStartDispatchExecutorHandoffContractTemplate($options);
    }

    public function agentCodexRealInvokerPostStartDispatchExecutorHandoffPreflight(array $options = []): array
    {
        return $this->agentCodexSection()->agentCodexRealInvokerPostStartDispatchExecutorHandoffPreflight($options);
    }

    public function agentCodexRealInvokerPostStartDispatchExecutorHandoffImplementationPacket(array $options = []): array
    {
        return $this->agentCodexSection()->agentCodexRealInvokerPostStartDispatchExecutorHandoffImplementationPacket($options);
    }

    public function agentCodexRealInvokerPostStartDispatchReceiptUseExecutorContractTemplate(array $options = []): array
    {
        return $this->agentCodexSection()->agentCodexRealInvokerPostStartDispatchReceiptUseExecutorContractTemplate($options);
    }

    public function agentCodexRealInvokerPostStartDispatchReceiptUseExecutorPreflight(array $options = []): array
    {
        return $this->agentCodexSection()->agentCodexRealInvokerPostStartDispatchReceiptUseExecutorPreflight($options);
    }

    public function agentCodexRealInvokerPostStartDispatchReceiptUseExecutorImplementationPacket(array $options = []): array
    {
        return $this->agentCodexSection()->agentCodexRealInvokerPostStartDispatchReceiptUseExecutorImplementationPacket($options);
    }

    public function agentCodexRealInvokerPostStartProviderStartDriverGateContractTemplate(array $options = []): array
    {
        return $this->agentCodexSection()->agentCodexRealInvokerPostStartProviderStartDriverGateContractTemplate($options);
    }

    public function agentCodexRealInvokerPostStartProviderStartDriverGatePreflight(array $options = []): array
    {
        return $this->agentCodexSection()->agentCodexRealInvokerPostStartProviderStartDriverGatePreflight($options);
    }

    public function agentCodexRealInvokerPostStartProviderStartDriverGateImplementationPacket(array $options = []): array
    {
        return $this->agentCodexSection()->agentCodexRealInvokerPostStartProviderStartDriverGateImplementationPacket($options);
    }

    public function agentCodexRealInvokerPostStartAdapterInvocationBoundaryGateContractTemplate(array $options = []): array
    {
        return $this->agentCodexSection()->agentCodexRealInvokerPostStartAdapterInvocationBoundaryGateContractTemplate($options);
    }

    public function agentCodexRealInvokerPostStartAdapterInvocationBoundaryGatePreflight(array $options = []): array
    {
        return $this->agentCodexSection()->agentCodexRealInvokerPostStartAdapterInvocationBoundaryGatePreflight($options);
    }

    public function agentCodexRealInvokerPostStartAdapterInvocationBoundaryGateImplementationPacket(array $options = []): array
    {
        return $this->agentCodexSection()->agentCodexRealInvokerPostStartAdapterInvocationBoundaryGateImplementationPacket($options);
    }

    public function agentCodexRealInvokerPostStartAdapterExecutionGuardGateContractTemplate(array $options = []): array
    {
        return $this->agentCodexSection()->agentCodexRealInvokerPostStartAdapterExecutionGuardGateContractTemplate($options);
    }

    public function agentCodexRealInvokerPostStartAdapterExecutionGuardGatePreflight(array $options = []): array
    {
        return $this->agentCodexSection()->agentCodexRealInvokerPostStartAdapterExecutionGuardGatePreflight($options);
    }

    public function agentCodexRealInvokerPostStartAdapterExecutionGuardGateImplementationPacket(array $options = []): array
    {
        return $this->agentCodexSection()->agentCodexRealInvokerPostStartAdapterExecutionGuardGateImplementationPacket($options);
    }

    public function agentCodexRealInvokerPostStartProviderExecutionContractGateContractTemplate(array $options = []): array
    {
        return $this->agentCodexSection()->agentCodexRealInvokerPostStartProviderExecutionContractGateContractTemplate($options);
    }

    public function agentCodexRealInvokerPostStartProviderExecutionContractGatePreflight(array $options = []): array
    {
        return $this->agentCodexSection()->agentCodexRealInvokerPostStartProviderExecutionContractGatePreflight($options);
    }

    public function agentCodexRealInvokerPostStartProviderExecutionContractGateImplementationPacket(array $options = []): array
    {
        return $this->agentCodexSection()->agentCodexRealInvokerPostStartProviderExecutionContractGateImplementationPacket($options);
    }

    public function agentCodexRealInvokerPostStartProcessStartReleaseGateContractTemplate(array $options = []): array
    {
        return $this->agentCodexSection()->agentCodexRealInvokerPostStartProcessStartReleaseGateContractTemplate($options);
    }

    public function agentCodexRealInvokerPostStartProcessStartReleaseGatePreflight(array $options = []): array
    {
        return $this->agentCodexSection()->agentCodexRealInvokerPostStartProcessStartReleaseGatePreflight($options);
    }

    public function agentCodexRealInvokerPostStartProcessStartReleaseGateImplementationPacket(array $options = []): array
    {
        return $this->agentCodexSection()->agentCodexRealInvokerPostStartProcessStartReleaseGateImplementationPacket($options);
    }

    public function agentCodexRealInvokerPostStartSupervisedStartExecutorGateContractTemplate(array $options = []): array
    {
        return $this->agentCodexSection()->agentCodexRealInvokerPostStartSupervisedStartExecutorGateContractTemplate($options);
    }

    public function agentCodexRealInvokerPostStartSupervisedStartExecutorGatePreflight(array $options = []): array
    {
        return $this->agentCodexSection()->agentCodexRealInvokerPostStartSupervisedStartExecutorGatePreflight($options);
    }

    public function agentCodexRealInvokerPostStartSupervisedStartExecutorGateImplementationPacket(array $options = []): array
    {
        return $this->agentCodexSection()->agentCodexRealInvokerPostStartSupervisedStartExecutorGateImplementationPacket($options);
    }

    public function agentCodexRealInvokerPostStartProcessSpawnEnablementGateContractTemplate(array $options = []): array
    {
        return $this->agentCodexSection()->agentCodexRealInvokerPostStartProcessSpawnEnablementGateContractTemplate($options);
    }

    public function agentCodexRealInvokerPostStartProcessSpawnEnablementGatePreflight(array $options = []): array
    {
        return $this->agentCodexSection()->agentCodexRealInvokerPostStartProcessSpawnEnablementGatePreflight($options);
    }

    public function agentCodexRealInvokerPostStartProcessSpawnEnablementGateImplementationPacket(array $options = []): array
    {
        return $this->agentCodexSection()->agentCodexRealInvokerPostStartProcessSpawnEnablementGateImplementationPacket($options);
    }

    public function agentCodexRealInvokerPostStartFinalProcessSpawnExecutorGateContractTemplate(array $options = []): array
    {
        return $this->agentCodexSection()->agentCodexRealInvokerPostStartFinalProcessSpawnExecutorGateContractTemplate($options);
    }

    public function agentCodexRealInvokerPostStartFinalProcessSpawnExecutorGatePreflight(array $options = []): array
    {
        return $this->agentCodexSection()->agentCodexRealInvokerPostStartFinalProcessSpawnExecutorGatePreflight($options);
    }

    public function agentCodexRealInvokerPostStartFinalProcessSpawnExecutorGateImplementationPacket(array $options = []): array
    {
        return $this->agentCodexSection()->agentCodexRealInvokerPostStartFinalProcessSpawnExecutorGateImplementationPacket($options);
    }

    public function agentCodexRealInvokerPostStartExternalProcessRuntimeGateContractTemplate(array $options = []): array
    {
        return $this->agentCodexSection()->agentCodexRealInvokerPostStartExternalProcessRuntimeGateContractTemplate($options);
    }

    public function agentCodexRealInvokerPostStartExternalProcessRuntimeGatePreflight(array $options = []): array
    {
        return $this->agentCodexSection()->agentCodexRealInvokerPostStartExternalProcessRuntimeGatePreflight($options);
    }

    public function agentCodexRealInvokerPostStartExternalProcessRuntimeGateImplementationPacket(array $options = []): array
    {
        return $this->agentCodexSection()->agentCodexRealInvokerPostStartExternalProcessRuntimeGateImplementationPacket($options);
    }

    public function agentCodexRealInvokerPostStartProcessInvocationAuthorizationGateContractTemplate(array $options = []): array
    {
        return $this->agentCodexSection()->agentCodexRealInvokerPostStartProcessInvocationAuthorizationGateContractTemplate($options);
    }

    public function agentCodexRealInvokerPostStartProcessInvocationAuthorizationGatePreflight(array $options = []): array
    {
        return $this->agentCodexSection()->agentCodexRealInvokerPostStartProcessInvocationAuthorizationGatePreflight($options);
    }

    public function agentCodexRealInvokerPostStartProcessInvocationAuthorizationGateImplementationPacket(array $options = []): array
    {
        return $this->agentCodexSection()->agentCodexRealInvokerPostStartProcessInvocationAuthorizationGateImplementationPacket($options);
    }

    public function agentCodexRealInvokerPostStartExternalProcessInvokerDryRunGateContractTemplate(array $options = []): array
    {
        return $this->agentCodexSection()->agentCodexRealInvokerPostStartExternalProcessInvokerDryRunGateContractTemplate($options);
    }

    public function agentCodexRealInvokerPostStartExternalProcessInvokerDryRunGatePreflight(array $options = []): array
    {
        return $this->agentCodexSection()->agentCodexRealInvokerPostStartExternalProcessInvokerDryRunGatePreflight($options);
    }

    public function agentCodexRealInvokerPostStartExternalProcessInvokerDryRunGateImplementationPacket(array $options = []): array
    {
        return $this->agentCodexSection()->agentCodexRealInvokerPostStartExternalProcessInvokerDryRunGateImplementationPacket($options);
    }

    public function agentCodexRealInvokerPostStartRealInvokerReleasePreflightGateContractTemplate(array $options = []): array
    {
        return $this->agentCodexSection()->agentCodexRealInvokerPostStartRealInvokerReleasePreflightGateContractTemplate($options);
    }

    public function agentCodexRealInvokerPostStartRealInvokerReleasePreflightGatePreflight(array $options = []): array
    {
        return $this->agentCodexSection()->agentCodexRealInvokerPostStartRealInvokerReleasePreflightGatePreflight($options);
    }

    public function agentCodexRealInvokerPostStartRealInvokerReleasePreflightGateImplementationPacket(array $options = []): array
    {
        return $this->agentCodexSection()->agentCodexRealInvokerPostStartRealInvokerReleasePreflightGateImplementationPacket($options);
    }

    public function agentCodexRealInvokerPostStartSignedRealInvokerReleaseGateContractTemplate(array $options = []): array
    {
        return $this->agentCodexSection()->agentCodexRealInvokerPostStartSignedRealInvokerReleaseGateContractTemplate($options);
    }

    public function agentCodexRealInvokerPostStartSignedRealInvokerReleaseGatePreflight(array $options = []): array
    {
        return $this->agentCodexSection()->agentCodexRealInvokerPostStartSignedRealInvokerReleaseGatePreflight($options);
    }

    public function agentCodexRealInvokerPostStartSignedRealInvokerReleaseGateImplementationPacket(array $options = []): array
    {
        return $this->agentCodexSection()->agentCodexRealInvokerPostStartSignedRealInvokerReleaseGateImplementationPacket($options);
    }

    public function agentCodexRealInvokerPostStartImplementationBoundaryGateContractTemplate(array $options = []): array
    {
        return $this->agentCodexSection()->agentCodexRealInvokerPostStartImplementationBoundaryGateContractTemplate($options);
    }

    public function agentCodexRealInvokerPostStartImplementationBoundaryGatePreflight(array $options = []): array
    {
        return $this->agentCodexSection()->agentCodexRealInvokerPostStartImplementationBoundaryGatePreflight($options);
    }

    public function agentCodexRealInvokerPostStartImplementationBoundaryGateImplementationPacket(array $options = []): array
    {
        return $this->agentCodexSection()->agentCodexRealInvokerPostStartImplementationBoundaryGateImplementationPacket($options);
    }

    public function agentCodexRealInvokerPostStartExecutorPlanGateContractTemplate(array $options = []): array
    {
        return $this->agentCodexSection()->agentCodexRealInvokerPostStartExecutorPlanGateContractTemplate($options);
    }

    public function agentCodexRealInvokerPostStartExecutorPlanGatePreflight(array $options = []): array
    {
        return $this->agentCodexSection()->agentCodexRealInvokerPostStartExecutorPlanGatePreflight($options);
    }

    public function agentCodexRealInvokerPostStartExecutorPlanGateImplementationPacket(array $options = []): array
    {
        return $this->agentCodexSection()->agentCodexRealInvokerPostStartExecutorPlanGateImplementationPacket($options);
    }

    public function agentCodexRealInvokerPostStartExecutorFreshReleaseGateContractTemplate(array $options = []): array
    {
        return $this->agentCodexSection()->agentCodexRealInvokerPostStartExecutorFreshReleaseGateContractTemplate($options);
    }

    public function agentCodexRealInvokerPostStartExecutorFreshReleaseGatePreflight(array $options = []): array
    {
        return $this->agentCodexSection()->agentCodexRealInvokerPostStartExecutorFreshReleaseGatePreflight($options);
    }

    public function agentCodexRealInvokerPostStartExecutorFreshReleaseGateImplementationPacket(array $options = []): array
    {
        return $this->agentCodexSection()->agentCodexRealInvokerPostStartExecutorFreshReleaseGateImplementationPacket($options);
    }

    public function agentCodexRealInvokerPostStartExecutorEnablementGateContractTemplate(array $options = []): array
    {
        return $this->agentCodexSection()->agentCodexRealInvokerPostStartExecutorEnablementGateContractTemplate($options);
    }

    public function agentCodexRealInvokerPostStartExecutorEnablementGatePreflight(array $options = []): array
    {
        return $this->agentCodexSection()->agentCodexRealInvokerPostStartExecutorEnablementGatePreflight($options);
    }

    public function agentCodexRealInvokerPostStartExecutorEnablementGateImplementationPacket(array $options = []): array
    {
        return $this->agentCodexSection()->agentCodexRealInvokerPostStartExecutorEnablementGateImplementationPacket($options);
    }

    public function agentCodexRealInvokerPostStartSupervisedStartActivationGateContractTemplate(array $options = []): array
    {
        return $this->agentCodexSection()->agentCodexRealInvokerPostStartSupervisedStartActivationGateContractTemplate($options);
    }

    public function agentCodexRealInvokerPostStartSupervisedStartActivationGatePreflight(array $options = []): array
    {
        return $this->agentCodexSection()->agentCodexRealInvokerPostStartSupervisedStartActivationGatePreflight($options);
    }

    public function agentCodexRealInvokerPostStartSupervisedStartActivationGateImplementationPacket(array $options = []): array
    {
        return $this->agentCodexSection()->agentCodexRealInvokerPostStartSupervisedStartActivationGateImplementationPacket($options);
    }

    public function agentCodexRealInvokerPostStartGuardedProcessStartExecutorGateContractTemplate(array $options = []): array
    {
        return $this->agentCodexSection()->agentCodexRealInvokerPostStartGuardedProcessStartExecutorGateContractTemplate($options);
    }

    public function agentCodexRealInvokerPostStartGuardedProcessStartExecutorGatePreflight(array $options = []): array
    {
        return $this->agentCodexSection()->agentCodexRealInvokerPostStartGuardedProcessStartExecutorGatePreflight($options);
    }

    public function agentCodexRealInvokerPostStartGuardedProcessStartExecutorGateImplementationPacket(array $options = []): array
    {
        return $this->agentCodexSection()->agentCodexRealInvokerPostStartGuardedProcessStartExecutorGateImplementationPacket($options);
    }

    public function agentCodexRealInvokerPostStartFinalProcessStartAuthorizationGateContractTemplate(array $options = []): array
    {
        return $this->agentCodexSection()->agentCodexRealInvokerPostStartFinalProcessStartAuthorizationGateContractTemplate($options);
    }

    public function agentCodexRealInvokerPostStartFinalProcessStartAuthorizationGatePreflight(array $options = []): array
    {
        return $this->agentCodexSection()->agentCodexRealInvokerPostStartFinalProcessStartAuthorizationGatePreflight($options);
    }

    public function agentCodexRealInvokerPostStartFinalProcessStartAuthorizationGateImplementationPacket(array $options = []): array
    {
        return $this->agentCodexSection()->agentCodexRealInvokerPostStartFinalProcessStartAuthorizationGateImplementationPacket($options);
    }

    public function agentCodexRealInvokerPostStartActualProcessStartRehearsalGateContractTemplate(array $options = []): array
    {
        return $this->agentCodexSection()->agentCodexRealInvokerPostStartActualProcessStartRehearsalGateContractTemplate($options);
    }

    public function agentCodexRealInvokerPostStartActualProcessStartRehearsalGatePreflight(array $options = []): array
    {
        return $this->agentCodexSection()->agentCodexRealInvokerPostStartActualProcessStartRehearsalGatePreflight($options);
    }

    public function agentCodexRealInvokerPostStartActualProcessStartRehearsalGateImplementationPacket(array $options = []): array
    {
        return $this->agentCodexSection()->agentCodexRealInvokerPostStartActualProcessStartRehearsalGateImplementationPacket($options);
    }

    public function agentCodexRealInvokerPostStartProcessStartEnvelopeGateContractTemplate(array $options = []): array
    {
        return $this->agentCodexSection()->agentCodexRealInvokerPostStartProcessStartEnvelopeGateContractTemplate($options);
    }

    public function agentCodexRealInvokerPostStartProcessStartEnvelopeGatePreflight(array $options = []): array
    {
        return $this->agentCodexSection()->agentCodexRealInvokerPostStartProcessStartEnvelopeGatePreflight($options);
    }

    public function agentCodexRealInvokerPostStartProcessStartEnvelopeGateImplementationPacket(array $options = []): array
    {
        return $this->agentCodexSection()->agentCodexRealInvokerPostStartProcessStartEnvelopeGateImplementationPacket($options);
    }

    public function agentCodexRealInvokerPostStartStartExecutionGateContractTemplate(array $options = []): array
    {
        return $this->agentCodexSection()->agentCodexRealInvokerPostStartStartExecutionGateContractTemplate($options);
    }

    public function agentCodexRealInvokerPostStartStartExecutionGatePreflight(array $options = []): array
    {
        return $this->agentCodexSection()->agentCodexRealInvokerPostStartStartExecutionGatePreflight($options);
    }

    public function agentCodexRealInvokerPostStartStartExecutionGateImplementationPacket(array $options = []): array
    {
        return $this->agentCodexSection()->agentCodexRealInvokerPostStartStartExecutionGateImplementationPacket($options);
    }

    public function agentCodexRealInvokerPostStartProcessStarterReadinessGateContractTemplate(array $options = []): array
    {
        return $this->agentCodexSection()->agentCodexRealInvokerPostStartProcessStarterReadinessGateContractTemplate($options);
    }

    public function agentCodexRealInvokerPostStartProcessStarterReadinessGatePreflight(array $options = []): array
    {
        return $this->agentCodexSection()->agentCodexRealInvokerPostStartProcessStarterReadinessGatePreflight($options);
    }

    public function agentCodexRealInvokerPostStartProcessStarterReadinessGateImplementationPacket(array $options = []): array
    {
        return $this->agentCodexSection()->agentCodexRealInvokerPostStartProcessStarterReadinessGateImplementationPacket($options);
    }

    public function agentCodexRealInvokerPostStartManualStartExecutorReceiptWriterContractTemplate(array $options = []): array
    {
        return $this->agentCodexSection()->agentCodexRealInvokerPostStartManualStartExecutorReceiptWriterContractTemplate($options);
    }

    public function agentCodexRealInvokerPostStartManualStartExecutorReceiptWriterPreflight(array $options = []): array
    {
        return $this->agentCodexSection()->agentCodexRealInvokerPostStartManualStartExecutorReceiptWriterPreflight($options);
    }

    public function agentCodexRealInvokerPostStartManualStartExecutorReceiptWriterImplementationPacket(array $options = []): array
    {
        return $this->agentCodexSection()->agentCodexRealInvokerPostStartManualStartExecutorReceiptWriterImplementationPacket($options);
    }

    public function agentCodexRealInvokerPostStartOperatorStartHandoffBuilderContractTemplate(array $options = []): array
    {
        return $this->agentCodexSection()->agentCodexRealInvokerPostStartOperatorStartHandoffBuilderContractTemplate($options);
    }

    public function agentCodexRealInvokerPostStartOperatorStartHandoffBuilderPreflight(array $options = []): array
    {
        return $this->agentCodexSection()->agentCodexRealInvokerPostStartOperatorStartHandoffBuilderPreflight($options);
    }

    public function agentCodexRealInvokerPostStartOperatorStartHandoffBuilderImplementationPacket(array $options = []): array
    {
        return $this->agentCodexSection()->agentCodexRealInvokerPostStartOperatorStartHandoffBuilderImplementationPacket($options);
    }

    private function agentCodexSection(): \App\Services\Ai\SelfConstruction\Readiness\ReadinessProjectionAgentCodexSection
    {
        return $this->agentCodexSection ??= new \App\Services\Ai\SelfConstruction\Readiness\ReadinessProjectionAgentCodexSection(
            $this->agentDispatchProviderSection(),
        );
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
        public function agentDispatchExecutorAdapterInvocationBoundaryContractTemplate(array $options = []): array
    {
        return $this->agentDispatchProviderSection()->agentDispatchExecutorAdapterInvocationBoundaryContractTemplate($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
        public function agentDispatchExecutorAdapterInvocationBoundaryPreflight(array $options = []): array
    {
        return $this->agentDispatchProviderSection()->agentDispatchExecutorAdapterInvocationBoundaryPreflight($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
        public function agentDispatchExecutorAdapterInvocationBoundaryImplementationPacket(array $options = []): array
    {
        return $this->agentDispatchProviderSection()->agentDispatchExecutorAdapterInvocationBoundaryImplementationPacket($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
        public function agentDispatchExecutorReleaseAuthorizationTemplate(array $options = []): array
    {
        return $this->agentDispatchProviderSection()->agentDispatchExecutorReleaseAuthorizationTemplate($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
        public function agentDispatchExecutorReleaseAuthorizationReceiptDraft(array $options = []): array
    {
        return $this->agentDispatchProviderSection()->agentDispatchExecutorReleaseAuthorizationReceiptDraft($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
        public function agentDispatchExecutorReleaseAuthorizationSignatureRequest(array $options = []): array
    {
        return $this->agentDispatchProviderSection()->agentDispatchExecutorReleaseAuthorizationSignatureRequest($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
        public function agentDispatchExecutorReleaseAuthorizationPostSignatureRunbook(array $options = []): array
    {
        return $this->agentDispatchProviderSection()->agentDispatchExecutorReleaseAuthorizationPostSignatureRunbook($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
        public function agentDispatchExecutorReleaseAuthorizationSignedReceiptTemplate(array $options = []): array
    {
        return $this->agentDispatchProviderSection()->agentDispatchExecutorReleaseAuthorizationSignedReceiptTemplate($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
        public function agentDispatchExecutorReleaseAuthorizationSignedReceiptPreflight(array $options = []): array
    {
        return $this->agentDispatchProviderSection()->agentDispatchExecutorReleaseAuthorizationSignedReceiptPreflight($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
        public function agentDispatchExecutorReleaseAuthorizationPersistenceTemplate(array $options = []): array
    {
        return $this->agentDispatchProviderSection()->agentDispatchExecutorReleaseAuthorizationPersistenceTemplate($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
        public function agentDispatchExecutorReleaseAuthorizationPersistencePreflight(array $options = []): array
    {
        return $this->agentDispatchProviderSection()->agentDispatchExecutorReleaseAuthorizationPersistencePreflight($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
        public function agentDispatchExecutorReleaseAuthorizationPersistenceWriterContractTemplate(array $options = []): array
    {
        return $this->agentDispatchProviderSection()->agentDispatchExecutorReleaseAuthorizationPersistenceWriterContractTemplate($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
        public function agentDispatchExecutorReleaseAuthorizationPersistenceWriterImplementationPreflight(array $options = []): array
    {
        return $this->agentDispatchProviderSection()->agentDispatchExecutorReleaseAuthorizationPersistenceWriterImplementationPreflight($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
        public function agentDispatchExecutorReleaseAuthorizationPersistenceWriterImplementationPacket(array $options = []): array
    {
        return $this->agentDispatchProviderSection()->agentDispatchExecutorReleaseAuthorizationPersistenceWriterImplementationPacket($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
        public function agentDispatchExecutorReleaseAuthorizationPersistenceStatus(array $options = []): array
    {
        return $this->agentDispatchProviderSection()->agentDispatchExecutorReleaseAuthorizationPersistenceStatus($options);
    }

    public function singleSessionInstructionPacket(array $options = []): array
    {
        return $this->miscProjectionsPart3Section()->singleSessionInstructionPacket($options);
    }

    public function coldLaneCertification(array $options = []): array
    {
        return $this->miscProjectionsPart3Section()->coldLaneCertification($options);
    }

    public function operatorChecklist(array $options = []): array
    {
        return $this->miscProjectionsPart3Section()->operatorChecklist($options);
    }

    public function promotionBlockers(array $options = []): array
    {
        return $this->miscProjectionsPart3Section()->promotionBlockers($options);
    }

    public function readinessDigest(array $options = []): array
    {
        return $this->miscProjectionsPart3Section()->readinessDigest($options);
    }

    public function governanceScorecard(array $options = []): array
    {
        return $this->miscProjectionsPart3Section()->governanceScorecard($options);
    }

    public function integrityManifest(array $options = []): array
    {
        return $this->miscProjectionsPart3Section()->integrityManifest($options);
    }

    public function continuationToken(array $options = []): array
    {
        return $this->miscProjectionsPart3Section()->continuationToken($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null}  $options
     * @return array<string, mixed>
     */
    public function ownershipBoundary(array $options = []) : array
    {
        return $this->ownershipBoundarySection()->ownershipBoundary($options);
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
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneOneShotWorkerPacketContract(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('one_shot_worker_packet', 'contract');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneOneShotWorkerPacketPreflight(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('one_shot_worker_packet', 'preflight');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneOneShotWorkerPacketImplementationPacket(array $options = []): array
    {
        return CertificationWorkbenchEvaluator::certify('one_shot_worker_packet', 'implementation_packet');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneOneShotWorkerPacketStatus(array $options = []) : array
    {
        return $this->ownershipBoundarySection()->agentControlPlaneOneShotWorkerPacketStatus($options);
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
