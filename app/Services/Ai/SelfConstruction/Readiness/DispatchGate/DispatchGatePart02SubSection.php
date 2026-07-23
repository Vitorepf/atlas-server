<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Readiness\DispatchGate;

use App\Models\AtlasSelfConstructionAgentCostEvent;
use App\Models\AtlasSelfConstructionAgentDispatchExecutorReleaseAuthorization;
use App\Models\AtlasSelfConstructionAgentDispatchReceipt;
use App\Models\AtlasSelfConstructionAgentHeartbeat;
use App\Models\AtlasSelfConstructionAgentRun;
use App\Models\AtlasSelfConstructionAgentSandboxBinding;
use App\Models\AtlasSelfConstructionAgentWakeupItem;
use App\Models\AtlasSelfConstructionAgentWorkProduct;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
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
use App\Services\Ai\SelfConstruction\ControlPlane\AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerExecutorFreshReleaseGateInvoker;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerExecutorPlanInvoker;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerFinalProcessStartAuthorizationGateInvoker;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerGuardedProcessStartExecutorInvoker;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerImplementationBoundaryInvoker;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerManualStartExecutorReceiptInvoker;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerOperatorStartHandoffInvoker;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartActualProcessStartRehearsalGateInvoker;
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
use App\Services\Ai\SelfConstruction\ControlPlane\AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartFinalProcessStartAuthorizationGateInvoker;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartGuardedProcessStartExecutorGateInvoker;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartImplementationBoundaryGateInvoker;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartLivenessMonitorInvoker;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartManualStartExecutorReceiptInvoker;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartOperatorStartHandoffInvoker;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProcessInvocationAuthorizationGateInvoker;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProcessSpawnEnablementGateInvoker;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProcessStartEnvelopeGateInvoker;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProcessStartReleaseGateInvoker;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProcessStarterReadinessGateInvoker;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProviderExecutionContractGateInvoker;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProviderStartDriverGateInvoker;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartRealInvokerReleasePreflightGateInvoker;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartReceiptContractInvoker;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartSignedDispatchAuthorizationGateInvoker;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartSignedRealInvokerReleaseGateInvoker;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartStartExecutionGateInvoker;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartSupervisedStartActivationGateInvoker;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartSupervisedStartExecutorGateInvoker;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerProcessStartEnvelopeBuilderInvoker;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerProcessStarterReadinessGateInvoker;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerReleasePreflightInvoker;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerStartExecutionGateInvoker;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerSupervisedStartActivationGateInvoker;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentAutomaticDispatchSchedulerOneShotTickCodexSignedRealInvokerReleaseGateInvoker;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentAutomaticDispatchSchedulerOneShotTickCodexSupervisedStartExecutorInvoker;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentAutomaticDispatchSchedulerOneShotTickDispatchReceiptUseInvoker;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentAutomaticDispatchSchedulerOneShotTickGuardedRuntimeInvoker;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentAutomaticDispatchSchedulerOneShotTickMutatingWriter;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentAutomaticDispatchSchedulerOneShotTickProviderAdapterExecutionGuardInvoker;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentAutomaticDispatchSchedulerOneShotTickProviderStartDriverInvoker;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentAutomaticDispatchSchedulerOneShotTickReleaseReceiptPersistenceWriter;
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
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneMultiAgentLoopCertificationService;
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
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneTerminalLoopHealthDigestService;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneTerminalLoopOperationalProofService;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneTerminalWorkerBootstrapService;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneWorkProductManifestPlanner;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneWorkerTaskEligibilityCertificationService;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentDispatchExecutorAdapterInvocationBoundary;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentDispatchExecutorProviderStartDriver;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentDispatchExecutorReceiptUseWriter;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentDispatchPlannerCertificationService;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentMergeReviewCertificationService;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentProviderAdapterExecutionGuard;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentProviderAdapterRegistry;
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
use App\Services\Ai\SelfConstruction\NativeImplementation\AtlasSelfConstructionForgeSelfImprovementIntegrationSmokeService;
use App\Services\Ai\SelfConstruction\NativeImplementation\AtlasSelfConstructionHumanCompletionReceiptVerifierService;
use App\Services\Ai\SelfConstruction\NativeImplementation\AtlasSelfConstructionOperatorEvidenceArtifactTemplatePackService;
use App\Services\Ai\SelfConstruction\NativeImplementation\AtlasSelfConstructionOperatorEvidenceDraftHashFinalizerService;
use App\Services\Ai\SelfConstruction\NativeImplementation\AtlasSelfConstructionOperatorEvidenceDraftWorkspaceInspectorService;
use App\Services\Ai\SelfConstruction\NativeImplementation\AtlasSelfConstructionOperatorEvidenceDraftWorkspacePublisherService;
use App\Services\Ai\SelfConstruction\NativeImplementation\AtlasSelfConstructionOperatorEvidenceSubmissionReadinessService;
use App\Services\Ai\SelfConstruction\NativeImplementation\AtlasSelfConstructionOsCompletionAuditService;
use App\Services\Ai\SelfConstruction\NativeImplementation\AtlasSelfConstructionRealProviderSmokeCertificationService;
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
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerExecutorPlan;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerFinalProcessStartAuthorizationGate;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerGuardedProcessStartExecutor;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerImplementationBoundary;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerManualStartExecutorReceiptWriter;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerOperatorStartHandoffBuilder;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerPostStartActualProcessStartRehearsalGate;
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
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerPostStartFinalProcessStartAuthorizationGate;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerPostStartGuardedProcessStartExecutorGate;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerPostStartImplementationBoundaryGate;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerPostStartLivenessMonitor;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerPostStartManualStartExecutorReceiptWriter;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerPostStartOperatorStartHandoffBuilder;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerPostStartProcessInvocationAuthorizationGate;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerPostStartProcessSpawnEnablementGate;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerPostStartProcessStartEnvelopeGate;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerPostStartProcessStartReleaseGate;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerPostStartProcessStarterReadinessGate;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerPostStartProviderExecutionContractGate;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerPostStartProviderStartDriverGate;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerPostStartRealInvokerReleasePreflightGate;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerPostStartReceiptContractBuilder;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerPostStartSignedDispatchAuthorizationGate;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerPostStartSignedRealInvokerReleaseGate;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerPostStartStartExecutionGate;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerPostStartSupervisedStartActivationGate;
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
use App\Services\Ai\SelfConstruction\Readiness\AtlasSelfConstructionReadinessService;

/**
 * DispatchGate sub-section 02 of ReadinessProjectionDispatchGateSection.
 * Method bodies are byte-identical to the pre-split facade (GOD-DEBULK sub-split).
 */
final class DispatchGatePart02SubSection
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
            throw new \RuntimeException('DispatchGatePart02SubSection mother not bound for '.$name);
        }
        $method = new \ReflectionMethod($this->mother, $name);

        return $method->invokeArgs($this->mother, $arguments);
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartActualProcessStartRehearsalGateContract(array $options = []): array
    {
        $finalAuthorizationStatusPayload = $this->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartFinalProcessStartAuthorizationGateStatus($options);
        $finalAuthorizationStatus = (array) data_get($finalAuthorizationStatusPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_final_process_start_authorization_gate_status', []);
        $postStartRehearsalPayload = $this->agentCodexRealInvokerPostStartActualProcessStartRehearsalGateContractTemplate($options);
        $rehearsalExecutorPayload = $this->agentCodexRealInvokerActualProcessStartRehearsalExecutorContractTemplate($options);

        $contract = [
            'status' => 'one_shot_tick_codex_real_invoker_post_start_actual_process_start_rehearsal_gate_contract_ready',
            'contract_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-REAL-INVOKER-POST-START-ACTUAL-PROCESS-START-REHEARSAL-GATE-CONTRACT-SELF-CONSTRUCTION-0001',
            'parent_program' => 'Atlas Self-Construction OS',
            'submodule' => 'Atlas Agent Control Plane',
            'provider' => 'codex',
            'adapter' => 'codex',
            'source_codex_real_invoker_post_start_final_process_start_authorization_gate_status' => data_get($finalAuthorizationStatus, 'status'),
            'source_codex_real_invoker_post_start_final_process_start_authorization_gate_status_hash' => data_get($finalAuthorizationStatusPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_final_process_start_authorization_gate_status_hash'),
            'source_codex_real_invoker_post_start_actual_process_start_rehearsal_gate_contract_status' => data_get($postStartRehearsalPayload, 'status'),
            'source_codex_real_invoker_post_start_actual_process_start_rehearsal_gate_contract_hash' => data_get($postStartRehearsalPayload, 'codex_real_invoker_post_start_actual_process_start_rehearsal_gate_contract_template_hash'),
            'source_codex_real_invoker_actual_process_start_rehearsal_executor_contract_status' => data_get($rehearsalExecutorPayload, 'status'),
            'source_codex_real_invoker_actual_process_start_rehearsal_executor_contract_hash' => data_get($rehearsalExecutorPayload, 'codex_real_invoker_actual_process_start_rehearsal_executor_contract_template_hash'),
            'actual_process_start_rehearsal' => [
                'canonical_post_start_actual_process_start_rehearsal_gate' => AgentCodexRealInvokerPostStartActualProcessStartRehearsalGate::class,
                'canonical_post_start_actual_process_start_rehearsal_gate_method' => 'rehearsePostStartActualProcessStart',
                'scheduler_invoker' => AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartActualProcessStartRehearsalGateInvoker::class,
                'scheduler_invoker_method' => 'prepareCodexRealInvokerPostStartActualProcessStartRehearsalGate',
                'generic_actual_process_start_rehearsal_executor' => AgentCodexRealInvokerActualProcessStartRehearsalExecutor::class,
                'generic_actual_process_start_rehearsal_executor_method' => 'rehearseActualStart',
                'post_start_final_process_start_authorization_required_before_rehearsal' => true,
                'post_start_evidence_acceptance_bridge_required_before_rehearsal' => true,
                'provider_start_final_authorization_required_before_rehearsal' => true,
                'gate_delegates_to_codex_real_invoker_actual_process_start_rehearsal_executor' => true,
                'process_start_rehearsed_by_contract' => true,
                'rehearsal_is_not_actual_process_start' => true,
                'actual_process_start_allowed_by_contract' => false,
                'external_process_started_by_contract' => false,
                'provider_process_call_allowed_by_contract' => false,
                'provider_started_by_contract' => false,
                'adapter_invocation_allowed_by_contract' => false,
                'adapter_execution_allowed_by_contract' => false,
                'token_spend_allowed_by_contract' => false,
                'dispatch_allowed_by_contract' => false,
                'idempotency_key' => 'real_invoker_actual_process_start_rehearsal_id',
            ],
            'required_input_fields_for_future_invoker' => [
                'run_key',
                'post_start_actual_process_start_rehearsal_gate_id',
                'post_start_final_process_start_authorization_gate_id',
                'post_start_evidence_acceptance_bridge_id',
                'post_start_guarded_process_start_gate_id',
                'post_start_supervised_start_activation_gate_id',
                'post_start_executor_enablement_gate_id',
                'post_start_executor_fresh_release_gate_id',
                'post_start_executor_plan_gate_id',
                'post_start_implementation_boundary_gate_id',
                'post_start_signed_real_invoker_release_gate_id',
                'post_start_real_invoker_release_preflight_gate_id',
                'post_start_external_process_invoker_dry_run_gate_id',
                'post_start_process_invocation_authorization_gate_id',
                'post_start_external_process_runtime_gate_id',
                'post_start_final_process_spawn_executor_gate_id',
                'post_start_process_spawn_enablement_gate_id',
                'post_start_supervised_start_gate_id',
                'post_start_process_start_release_gate_id',
                'process_start_release_id',
                'provider_execution_contract_gate_id',
                'codex_execution_id',
                'supervised_start_id',
                'spawn_enablement_id',
                'spawn_executor_id',
                'runtime_driver_id',
                'invocation_authorization_id',
                'dry_run_id',
                'real_invoker_release_preflight_id',
                'signed_real_invoker_release_id',
                'real_invoker_implementation_boundary_id',
                'real_invoker_executor_plan_id',
                'real_invoker_executor_fresh_release_id',
                'real_invoker_executor_enablement_id',
                'real_invoker_supervised_start_activation_id',
                'real_invoker_guarded_process_start_id',
                'real_invoker_final_process_start_authorization_id',
                'real_invoker_actual_process_start_rehearsal_id',
                'adapter_execution_guard_gate_id',
                'execution_guard_id',
                'adapter_invocation_boundary_gate_id',
                'adapter_invocation_id',
                'provider_start_driver_gate_id',
                'provider_start_attempt_id',
                'process_start_rehearsal_hash',
                'command_resolution_hash',
                'environment_resolution_hash',
                'cwd_verification_hash',
                'supervisor_dry_run_hash',
                'liveness_probe_rehearsal_hash',
                'operator_final_start_receipt_hash',
                'final_start_signature_hash',
                'final_start_policy_hash',
                'final_start_window_hash',
                'final_start_replay_guard_hash',
                'final_start_kill_switch_hash',
                'actor',
                'session',
                'reason',
            ],
            'forbidden_true_input_flags' => [
                'actual_process_start_allowed',
                'provider_process_call_allowed',
                'adapter_invocation_allowed',
                'adapter_execution_allowed',
                'token_spend_allowed',
                'dispatch_allowed',
                'self_programming_allowed',
                'external_process_started',
                'provider_started',
            ],
            'allowed_future_mutations' => [
                'record_codex_real_invoker_actual_process_start_rehearsal_metadata_on_provider_start_run',
                'append_codex_real_invoker_actual_process_start_rehearsal_evidence_event',
                'record_codex_real_invoker_post_start_actual_process_start_rehearsal_metadata_on_observed_run',
            ],
            'forbidden_even_after_contract' => [
                'invoke_codex_process',
                'spawn_shell_or_subprocess',
                'call_codex_cli_or_codex_app',
                'run_real_external_process_invoker',
                'dispatch_work_to_codex',
                'send_prompt_to_provider',
                'call_provider_process',
                'enable_adapter_execution',
                'spend_provider_tokens',
                'mark_observed_run_running_or_terminal',
                'start_actual_process_without_separate_envelope_contract',
                'enable_self_programming',
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_actual_process_start_rehearsal_gate_preflight',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_actual_process_start_rehearsal_gate_contract.v1',
            'status' => (string) $contract['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_actual_process_start_rehearsal_gate_contract',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'post_start_actual_process_start_rehearsal_gate_allowed' => false,
            'actual_process_start_allowed' => false,
            'provider_process_call_allowed' => false,
            'executor_enabled' => false,
            'process_start_armed' => false,
            'adapter_invocation_allowed' => false,
            'adapter_execution_allowed' => false,
            'token_spend_allowed' => false,
            'self_programming_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_actual_process_start_rehearsal_gate_contract' => $contract,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_actual_process_start_rehearsal_gate_contract_hash' => $this->stableHash($contract),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_actual_process_start_rehearsal_gate_contract_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_actual_process_start_rehearsal_gate_contract_does_not_call_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_actual_process_start_rehearsal_gate_contract_does_not_execute_adapter',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_actual_process_start_rehearsal_gate_contract_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_actual_process_start_rehearsal_gate_contract_does_not_dispatch_work',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_actual_process_start_rehearsal_gate_contract_does_not_enable_self_programming',
            ],
            'human_summary' => 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start actual process start rehearsal gate contract is ready; rehearsal records readiness metadata while actual process start, dispatch, token spend, adapter execution and self-programming stay disabled.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartExecutorPlanGateContract(array $options = []): array
    {
        $implementationBoundaryStatusPayload = $this->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartImplementationBoundaryGateStatus($options);
        $implementationBoundaryStatus = (array) data_get($implementationBoundaryStatusPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_implementation_boundary_gate_status', []);
        $executorPlanPayload = $this->agentCodexRealInvokerPostStartExecutorPlanGateContractTemplate($options);

        $contract = [
            'status' => 'one_shot_tick_codex_real_invoker_post_start_executor_plan_gate_contract_ready',
            'contract_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-REAL-INVOKER-POST-START-EXECUTOR-PLAN-GATE-CONTRACT-SELF-CONSTRUCTION-0001',
            'parent_program' => 'Atlas Self-Construction OS',
            'submodule' => 'Atlas Agent Control Plane',
            'provider' => 'codex',
            'adapter' => 'codex',
            'source_codex_real_invoker_post_start_implementation_boundary_gate_status' => data_get($implementationBoundaryStatus, 'status'),
            'source_codex_real_invoker_post_start_implementation_boundary_gate_status_hash' => data_get($implementationBoundaryStatusPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_implementation_boundary_gate_status_hash'),
            'source_codex_real_invoker_post_start_executor_plan_gate_status' => data_get($executorPlanPayload, 'status'),
            'source_codex_real_invoker_post_start_executor_plan_gate_hash' => data_get($executorPlanPayload, 'codex_real_invoker_post_start_executor_plan_gate_contract_template_hash'),
            'executor_plan' => [
                'canonical_post_start_executor_plan_gate' => AgentCodexRealInvokerPostStartExecutorPlanGate::class,
                'canonical_post_start_executor_plan_gate_method' => 'preparePostStartExecutorPlan',
                'scheduler_invoker' => AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartExecutorPlanGateInvoker::class,
                'scheduler_invoker_method' => 'prepareCodexRealInvokerPostStartExecutorPlanGate',
                'codex_real_invoker_executor_plan_service' => AgentCodexRealInvokerExecutorPlan::class,
                'codex_real_invoker_executor_plan_method' => 'prepareExecutorPlan',
                'executor_plan_effect' => 'prepare_post_start_executor_plan_without_enabling_executor',
                'post_start_implementation_boundary_required_before_plan' => true,
                'post_start_evidence_acceptance_bridge_required_before_plan' => true,
                'provider_start_run_with_codex_real_invoker_implementation_boundary_required_before_plan' => true,
                'operator_executor_plan_receipt_hash_required' => true,
                'executor_binary_contract_hash_required' => true,
                'executor_observability_contract_hash_required' => true,
                'gate_delegates_to_codex_real_invoker_executor_plan' => true,
                'gate_records_codex_real_invoker_executor_plan_metadata_on_provider_start_run' => true,
                'gate_records_bridge_metadata_on_observed_run' => true,
                'executor_fresh_release_required_after_plan' => true,
                'executor_plan_is_not_executor_enablement' => true,
                'actual_process_start_allowed_by_contract' => false,
                'external_process_started_by_contract' => false,
                'provider_process_call_allowed_by_contract' => false,
                'provider_started_by_contract' => false,
                'executor_enabled_by_contract' => false,
                'adapter_invocation_allowed_by_contract' => false,
                'adapter_execution_allowed_by_contract' => false,
                'token_spend_allowed_by_contract' => false,
                'dispatch_allowed_by_contract' => false,
                'idempotency_key' => 'real_invoker_executor_plan_id',
            ],
            'required_input_fields_for_future_invoker' => [
                'run_key',
                'post_start_executor_plan_gate_id',
                'post_start_evidence_acceptance_bridge_id',
                'post_start_implementation_boundary_gate_id',
                'post_start_signed_real_invoker_release_gate_id',
                'post_start_real_invoker_release_preflight_gate_id',
                'post_start_external_process_invoker_dry_run_gate_id',
                'post_start_process_invocation_authorization_gate_id',
                'post_start_external_process_runtime_gate_id',
                'post_start_final_process_spawn_executor_gate_id',
                'post_start_process_spawn_enablement_gate_id',
                'post_start_supervised_start_gate_id',
                'post_start_process_start_release_gate_id',
                'process_start_release_id',
                'provider_execution_contract_gate_id',
                'codex_execution_id',
                'supervised_start_id',
                'spawn_enablement_id',
                'spawn_executor_id',
                'runtime_driver_id',
                'invocation_authorization_id',
                'dry_run_id',
                'real_invoker_release_preflight_id',
                'signed_real_invoker_release_id',
                'real_invoker_implementation_boundary_id',
                'real_invoker_executor_plan_id',
                'adapter_execution_guard_gate_id',
                'execution_guard_id',
                'adapter_invocation_boundary_gate_id',
                'adapter_invocation_id',
                'provider_start_driver_gate_id',
                'provider_start_attempt_id',
                'signed_dispatch_receipt_hash',
                'operator_release_receipt_hash',
                'operator_spawn_receipt_hash',
                'operator_final_spawn_receipt_hash',
                'operator_runtime_receipt_hash',
                'operator_invocation_receipt_hash',
                'operator_dry_run_receipt_hash',
                'operator_release_preflight_receipt_hash',
                'operator_signed_release_receipt_hash',
                'operator_implementation_boundary_receipt_hash',
                'operator_executor_plan_receipt_hash',
                'signature_verification_report_hash',
                'codex_execution_contract_hash',
                'supervised_start_contract_hash',
                'runtime_supervision_plan_hash',
                'stdout_stderr_sink_hash',
                'liveness_probe_hash',
                'runtime_driver_contract_hash',
                'invoker_contract_hash',
                'real_invoker_contract_hash',
                'release_policy_hash',
                'implementation_plan_hash',
                'executor_binary_contract_hash',
                'executor_observability_contract_hash',
                'process_command_hash',
                'environment_contract_hash',
                'termination_policy_hash',
                'rollback_plan_hash',
                'max_runtime_policy_hash',
                'actor',
                'session',
                'reason',
            ],
            'allowed_future_mutations' => [
                'record_codex_real_invoker_executor_plan_metadata_on_provider_start_run',
                'append_codex_real_invoker_executor_plan_evidence_event',
                'record_codex_real_invoker_post_start_executor_plan_metadata_on_observed_run',
            ],
            'forbidden_even_after_contract' => [
                'invoke_codex_process',
                'spawn_shell_or_subprocess',
                'call_codex_cli_or_codex_app',
                'run_real_external_process_invoker',
                'dispatch_work_to_codex',
                'send_prompt_to_provider',
                'call_provider_process',
                'enable_executor',
                'enable_adapter_execution',
                'spend_provider_tokens',
                'mark_observed_run_running_or_terminal',
                'authorize_executor_fresh_release_without_separate_contract',
                'enable_self_programming',
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_executor_plan_gate_preflight',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_plan_gate_contract.v1',
            'status' => (string) $contract['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_plan_gate_contract',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'post_start_executor_plan_gate_allowed' => false,
            'actual_process_start_allowed' => false,
            'provider_process_call_allowed' => false,
            'executor_enabled' => false,
            'adapter_invocation_allowed' => false,
            'adapter_execution_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_plan_gate_contract' => $contract,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_plan_gate_contract_hash' => $this->stableHash($contract),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_plan_gate_contract_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_plan_gate_contract_does_not_call_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_plan_gate_contract_does_not_enable_executor',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_plan_gate_contract_does_not_execute_adapter',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_plan_gate_contract_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_plan_gate_contract_does_not_dispatch_work',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_plan_gate_contract_does_not_enable_self_programming',
            ],
            'human_summary' => 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start executor plan gate contract is ready; it prepares executor-plan metadata only and still requires a separate fresh release.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartFinalProcessStartAuthorizationGateContract(array $options = []): array
    {
        $guardedStatusPayload = $this->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartGuardedProcessStartExecutorGateStatus($options);
        $guardedStatus = (array) data_get($guardedStatusPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_guarded_process_start_executor_gate_status', []);
        $postStartFinalPayload = $this->agentCodexRealInvokerPostStartFinalProcessStartAuthorizationGateContractTemplate($options);
        $finalAuthorizationPayload = $this->agentCodexRealInvokerFinalProcessStartAuthorizationGateContractTemplate($options);

        $contract = [
            'status' => 'one_shot_tick_codex_real_invoker_post_start_final_process_start_authorization_gate_contract_ready',
            'contract_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-REAL-INVOKER-POST-START-FINAL-PROCESS-START-AUTHORIZATION-GATE-CONTRACT-SELF-CONSTRUCTION-0001',
            'parent_program' => 'Atlas Self-Construction OS',
            'submodule' => 'Atlas Agent Control Plane',
            'provider' => 'codex',
            'adapter' => 'codex',
            'source_codex_real_invoker_post_start_guarded_process_start_executor_gate_status' => data_get($guardedStatus, 'status'),
            'source_codex_real_invoker_post_start_guarded_process_start_executor_gate_status_hash' => data_get($guardedStatusPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_guarded_process_start_executor_gate_status_hash'),
            'source_codex_real_invoker_post_start_final_process_start_authorization_gate_contract_status' => data_get($postStartFinalPayload, 'status'),
            'source_codex_real_invoker_post_start_final_process_start_authorization_gate_contract_hash' => data_get($postStartFinalPayload, 'codex_real_invoker_post_start_final_process_start_authorization_gate_contract_template_hash'),
            'source_codex_real_invoker_final_process_start_authorization_gate_contract_status' => data_get($finalAuthorizationPayload, 'status'),
            'source_codex_real_invoker_final_process_start_authorization_gate_contract_hash' => data_get($finalAuthorizationPayload, 'codex_real_invoker_final_process_start_authorization_gate_contract_template_hash'),
            'final_process_start_authorization' => [
                'canonical_post_start_final_process_start_authorization_gate' => AgentCodexRealInvokerPostStartFinalProcessStartAuthorizationGate::class,
                'canonical_post_start_final_process_start_authorization_gate_method' => 'authorizePostStartFinalProcessStart',
                'scheduler_invoker' => AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartFinalProcessStartAuthorizationGateInvoker::class,
                'scheduler_invoker_method' => 'prepareCodexRealInvokerPostStartFinalProcessStartAuthorizationGate',
                'generic_final_process_start_authorization_gate' => AgentCodexRealInvokerFinalProcessStartAuthorizationGate::class,
                'generic_final_process_start_authorization_gate_method' => 'authorizeFinalStart',
                'post_start_guarded_process_start_required_before_final_authorization' => true,
                'post_start_evidence_acceptance_bridge_required_before_final_authorization' => true,
                'provider_start_guarded_process_start_required_before_final_authorization' => true,
                'gate_delegates_to_codex_real_invoker_final_process_start_authorization_gate' => true,
                'final_process_start_authorized_by_contract' => true,
                'final_authorization_is_not_actual_process_start' => true,
                'actual_process_start_allowed_by_contract' => false,
                'external_process_started_by_contract' => false,
                'provider_process_call_allowed_by_contract' => false,
                'provider_started_by_contract' => false,
                'adapter_invocation_allowed_by_contract' => false,
                'adapter_execution_allowed_by_contract' => false,
                'token_spend_allowed_by_contract' => false,
                'dispatch_allowed_by_contract' => false,
                'idempotency_key' => 'real_invoker_final_process_start_authorization_id',
            ],
            'required_input_fields_for_future_invoker' => [
                'run_key',
                'post_start_final_process_start_authorization_gate_id',
                'post_start_guarded_process_start_gate_id',
                'post_start_evidence_acceptance_bridge_id',
                'post_start_supervised_start_activation_gate_id',
                'post_start_executor_enablement_gate_id',
                'post_start_executor_fresh_release_gate_id',
                'post_start_executor_plan_gate_id',
                'post_start_implementation_boundary_gate_id',
                'post_start_signed_real_invoker_release_gate_id',
                'post_start_real_invoker_release_preflight_gate_id',
                'post_start_external_process_invoker_dry_run_gate_id',
                'post_start_process_invocation_authorization_gate_id',
                'post_start_external_process_runtime_gate_id',
                'post_start_final_process_spawn_executor_gate_id',
                'post_start_process_spawn_enablement_gate_id',
                'post_start_supervised_start_gate_id',
                'post_start_process_start_release_gate_id',
                'process_start_release_id',
                'provider_execution_contract_gate_id',
                'codex_execution_id',
                'supervised_start_id',
                'spawn_enablement_id',
                'spawn_executor_id',
                'runtime_driver_id',
                'invocation_authorization_id',
                'dry_run_id',
                'real_invoker_release_preflight_id',
                'signed_real_invoker_release_id',
                'real_invoker_implementation_boundary_id',
                'real_invoker_executor_plan_id',
                'real_invoker_executor_fresh_release_id',
                'real_invoker_executor_enablement_id',
                'real_invoker_supervised_start_activation_id',
                'real_invoker_guarded_process_start_id',
                'real_invoker_final_process_start_authorization_id',
                'adapter_execution_guard_gate_id',
                'execution_guard_id',
                'adapter_invocation_boundary_gate_id',
                'adapter_invocation_id',
                'provider_start_driver_gate_id',
                'provider_start_attempt_id',
                'operator_final_start_receipt_hash',
                'final_start_signature_hash',
                'final_start_policy_hash',
                'final_start_window_hash',
                'final_start_replay_guard_hash',
                'final_start_kill_switch_hash',
                'operator_guarded_start_receipt_hash',
                'process_runner_contract_hash',
                'dry_run_rehearsal_hash',
                'launch_invocation_contract_hash',
                'post_start_observability_hash',
                'revoke_guard_hash',
                'actor',
                'session',
                'reason',
            ],
            'forbidden_true_input_flags' => [
                'actual_process_start_allowed',
                'provider_process_call_allowed',
                'adapter_invocation_allowed',
                'adapter_execution_allowed',
                'token_spend_allowed',
                'dispatch_allowed',
                'self_programming_allowed',
                'external_process_started',
                'provider_started',
            ],
            'allowed_future_mutations' => [
                'record_codex_real_invoker_final_process_start_authorization_metadata_on_provider_start_run',
                'append_codex_real_invoker_final_process_start_authorization_evidence_event',
                'record_codex_real_invoker_post_start_final_process_start_authorization_metadata_on_observed_run',
            ],
            'forbidden_even_after_contract' => [
                'invoke_codex_process',
                'spawn_shell_or_subprocess',
                'call_codex_cli_or_codex_app',
                'run_real_external_process_invoker',
                'dispatch_work_to_codex',
                'send_prompt_to_provider',
                'call_provider_process',
                'enable_adapter_execution',
                'spend_provider_tokens',
                'mark_observed_run_running_or_terminal',
                'execute_actual_process_start_without_separate_rehearsal_contract',
                'enable_self_programming',
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_final_process_start_authorization_gate_preflight',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_final_process_start_authorization_gate_contract.v1',
            'status' => (string) $contract['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_final_process_start_authorization_gate_contract',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'post_start_final_process_start_authorization_gate_allowed' => false,
            'actual_process_start_allowed' => false,
            'provider_process_call_allowed' => false,
            'executor_enabled' => false,
            'process_start_armed' => false,
            'adapter_invocation_allowed' => false,
            'adapter_execution_allowed' => false,
            'token_spend_allowed' => false,
            'self_programming_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_final_process_start_authorization_gate_contract' => $contract,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_final_process_start_authorization_gate_contract_hash' => $this->stableHash($contract),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_final_process_start_authorization_gate_contract_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_final_process_start_authorization_gate_contract_does_not_call_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_final_process_start_authorization_gate_contract_does_not_execute_adapter',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_final_process_start_authorization_gate_contract_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_final_process_start_authorization_gate_contract_does_not_dispatch_work',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_final_process_start_authorization_gate_contract_does_not_enable_self_programming',
            ],
            'human_summary' => 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start final process start authorization gate contract is ready; it scopes the final-start authorization bridge while real process start, dispatch, token spend, adapter execution and self-programming stay disabled.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartImplementationBoundaryGateContract(array $options = []): array
    {
        $signedReleaseStatusPayload = $this->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartSignedRealInvokerReleaseGateStatus($options);
        $signedReleaseStatus = (array) data_get($signedReleaseStatusPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_signed_real_invoker_release_gate_status', []);
        $boundaryPayload = $this->agentCodexRealInvokerPostStartImplementationBoundaryGateContractTemplate($options);

        $contract = [
            'status' => 'one_shot_tick_codex_real_invoker_post_start_implementation_boundary_gate_contract_ready',
            'contract_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-REAL-INVOKER-POST-START-IMPLEMENTATION-BOUNDARY-GATE-CONTRACT-SELF-CONSTRUCTION-0001',
            'parent_program' => 'Atlas Self-Construction OS',
            'submodule' => 'Atlas Agent Control Plane',
            'provider' => 'codex',
            'adapter' => 'codex',
            'source_codex_real_invoker_post_start_signed_real_invoker_release_gate_status' => data_get($signedReleaseStatus, 'status'),
            'source_codex_real_invoker_post_start_signed_real_invoker_release_gate_status_hash' => data_get($signedReleaseStatusPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_signed_real_invoker_release_gate_status_hash'),
            'source_codex_real_invoker_post_start_implementation_boundary_gate_status' => data_get($boundaryPayload, 'status'),
            'source_codex_real_invoker_post_start_implementation_boundary_gate_hash' => data_get($boundaryPayload, 'codex_real_invoker_post_start_implementation_boundary_gate_contract_template_hash'),
            'implementation_boundary' => [
                'canonical_post_start_implementation_boundary_gate' => AgentCodexRealInvokerPostStartImplementationBoundaryGate::class,
                'canonical_post_start_implementation_boundary_gate_method' => 'preparePostStartImplementationBoundary',
                'scheduler_invoker' => AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartImplementationBoundaryGateInvoker::class,
                'scheduler_invoker_method' => 'prepareCodexRealInvokerPostStartImplementationBoundaryGate',
                'codex_real_invoker_implementation_boundary_service' => AgentCodexRealInvokerImplementationBoundary::class,
                'codex_real_invoker_implementation_boundary_method' => 'prepareBoundary',
                'implementation_boundary_effect' => 'prepare_post_start_implementation_boundary_without_running_real_invoker',
                'post_start_signed_real_invoker_release_required_before_boundary' => true,
                'post_start_evidence_acceptance_bridge_required_before_boundary' => true,
                'provider_start_run_with_codex_signed_release_required_before_boundary' => true,
                'operator_implementation_boundary_receipt_hash_required' => true,
                'implementation_plan_hash_required' => true,
                'real_invoker_contract_hash_required' => true,
                'release_policy_hash_required' => true,
                'gate_delegates_to_codex_real_invoker_implementation_boundary' => true,
                'gate_records_codex_real_invoker_implementation_boundary_metadata_on_provider_start_run' => true,
                'gate_records_bridge_metadata_on_observed_run' => true,
                'executor_plan_required_after_boundary' => true,
                'implementation_boundary_is_not_real_invoker_execution' => true,
                'actual_process_start_allowed_by_contract' => false,
                'external_process_started_by_contract' => false,
                'provider_process_call_allowed_by_contract' => false,
                'provider_started_by_contract' => false,
                'adapter_invocation_allowed_by_contract' => false,
                'adapter_execution_allowed_by_contract' => false,
                'token_spend_allowed_by_contract' => false,
                'dispatch_allowed_by_contract' => false,
                'idempotency_key' => 'real_invoker_implementation_boundary_id',
            ],
            'required_input_fields_for_future_invoker' => [
                'run_key',
                'post_start_implementation_boundary_gate_id',
                'post_start_evidence_acceptance_bridge_id',
                'post_start_signed_real_invoker_release_gate_id',
                'post_start_real_invoker_release_preflight_gate_id',
                'post_start_external_process_invoker_dry_run_gate_id',
                'post_start_process_invocation_authorization_gate_id',
                'post_start_external_process_runtime_gate_id',
                'post_start_final_process_spawn_executor_gate_id',
                'post_start_process_spawn_enablement_gate_id',
                'post_start_supervised_start_gate_id',
                'post_start_process_start_release_gate_id',
                'process_start_release_id',
                'provider_execution_contract_gate_id',
                'codex_execution_id',
                'supervised_start_id',
                'spawn_enablement_id',
                'spawn_executor_id',
                'runtime_driver_id',
                'invocation_authorization_id',
                'dry_run_id',
                'real_invoker_release_preflight_id',
                'signed_real_invoker_release_id',
                'real_invoker_implementation_boundary_id',
                'adapter_execution_guard_gate_id',
                'execution_guard_id',
                'adapter_invocation_boundary_gate_id',
                'adapter_invocation_id',
                'provider_start_driver_gate_id',
                'provider_start_attempt_id',
                'signed_dispatch_receipt_hash',
                'operator_release_receipt_hash',
                'operator_spawn_receipt_hash',
                'operator_final_spawn_receipt_hash',
                'operator_runtime_receipt_hash',
                'operator_invocation_receipt_hash',
                'operator_dry_run_receipt_hash',
                'operator_release_preflight_receipt_hash',
                'operator_signed_release_receipt_hash',
                'operator_implementation_boundary_receipt_hash',
                'signature_verification_report_hash',
                'codex_execution_contract_hash',
                'supervised_start_contract_hash',
                'runtime_supervision_plan_hash',
                'stdout_stderr_sink_hash',
                'liveness_probe_hash',
                'runtime_driver_contract_hash',
                'invoker_contract_hash',
                'real_invoker_contract_hash',
                'release_policy_hash',
                'implementation_plan_hash',
                'process_command_hash',
                'environment_contract_hash',
                'termination_policy_hash',
                'rollback_plan_hash',
                'max_runtime_policy_hash',
                'actor',
                'session',
                'reason',
            ],
            'allowed_future_mutations' => [
                'record_codex_real_invoker_implementation_boundary_metadata_on_provider_start_run',
                'append_codex_real_invoker_implementation_boundary_evidence_event',
                'record_codex_real_invoker_post_start_implementation_boundary_metadata_on_observed_run',
            ],
            'forbidden_even_after_contract' => [
                'invoke_codex_process',
                'spawn_shell_or_subprocess',
                'call_codex_cli_or_codex_app',
                'run_real_external_process_invoker',
                'dispatch_work_to_codex',
                'send_prompt_to_provider',
                'call_provider_process',
                'enable_adapter_execution',
                'spend_provider_tokens',
                'mark_observed_run_running_or_terminal',
                'prepare_executor_plan_without_separate_contract',
                'enable_self_programming',
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_implementation_boundary_gate_preflight',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_implementation_boundary_gate_contract.v1',
            'status' => (string) $contract['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_implementation_boundary_gate_contract',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'post_start_implementation_boundary_gate_allowed' => false,
            'actual_process_start_allowed' => false,
            'provider_process_call_allowed' => false,
            'adapter_invocation_allowed' => false,
            'adapter_execution_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_implementation_boundary_gate_contract' => $contract,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_implementation_boundary_gate_contract_hash' => $this->stableHash($contract),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_implementation_boundary_gate_contract_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_implementation_boundary_gate_contract_does_not_call_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_implementation_boundary_gate_contract_does_not_execute_adapter',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_implementation_boundary_gate_contract_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_implementation_boundary_gate_contract_does_not_dispatch_work',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_implementation_boundary_gate_contract_does_not_enable_self_programming',
            ],
            'human_summary' => 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start implementation boundary gate contract is ready; it prepares boundary metadata only and still requires a separate executor plan.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartSignedRealInvokerReleaseGateContract(array $options = []): array
    {
        $preflightStatusPayload = $this->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartRealInvokerReleasePreflightGateStatus($options);
        $preflightStatus = (array) data_get($preflightStatusPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_real_invoker_release_preflight_gate_status', []);
        $signedReleasePayload = $this->agentCodexRealInvokerPostStartSignedRealInvokerReleaseGateContractTemplate($options);

        $contract = [
            'status' => 'one_shot_tick_codex_real_invoker_post_start_signed_real_invoker_release_gate_contract_ready',
            'contract_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-REAL-INVOKER-POST-START-SIGNED-REAL-INVOKER-RELEASE-GATE-CONTRACT-SELF-CONSTRUCTION-0001',
            'parent_program' => 'Atlas Self-Construction OS',
            'submodule' => 'Atlas Agent Control Plane',
            'provider' => 'codex',
            'adapter' => 'codex',
            'source_codex_real_invoker_post_start_real_invoker_release_preflight_gate_status' => data_get($preflightStatus, 'status'),
            'source_codex_real_invoker_post_start_real_invoker_release_preflight_gate_status_hash' => data_get($preflightStatusPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_real_invoker_release_preflight_gate_status_hash'),
            'source_codex_real_invoker_post_start_signed_real_invoker_release_gate_status' => data_get($signedReleasePayload, 'status'),
            'source_codex_real_invoker_post_start_signed_real_invoker_release_gate_hash' => data_get($signedReleasePayload, 'codex_real_invoker_post_start_signed_real_invoker_release_gate_contract_template_hash'),
            'signed_real_invoker_release' => [
                'canonical_post_start_signed_real_invoker_release_gate' => AgentCodexRealInvokerPostStartSignedRealInvokerReleaseGate::class,
                'canonical_post_start_signed_real_invoker_release_gate_method' => 'authorizePostStartSignedRealInvokerRelease',
                'scheduler_invoker' => AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartSignedRealInvokerReleaseGateInvoker::class,
                'scheduler_invoker_method' => 'authorizeCodexRealInvokerPostStartSignedRealInvokerReleaseGate',
                'codex_signed_real_invoker_release_gate_service' => AgentCodexSignedRealInvokerReleaseGate::class,
                'codex_signed_real_invoker_release_gate_method' => 'authorizeSignedRelease',
                'signed_release_effect' => 'authorize_post_start_signed_real_invoker_release_without_running_real_invoker',
                'post_start_real_invoker_release_preflight_required_before_signed_release' => true,
                'post_start_evidence_acceptance_bridge_required_before_signed_release' => true,
                'provider_start_run_with_codex_real_invoker_release_preflight_required_before_signed_release' => true,
                'operator_signed_release_receipt_hash_required' => true,
                'signature_verification_report_hash_required' => true,
                'real_invoker_contract_hash_required' => true,
                'release_policy_hash_required' => true,
                'gate_delegates_to_codex_signed_real_invoker_release_gate' => true,
                'gate_records_codex_signed_real_invoker_release_metadata_on_provider_start_run' => true,
                'gate_records_bridge_metadata_on_observed_run' => true,
                'implementation_boundary_required_after_signed_release' => true,
                'signed_real_invoker_release_is_not_real_invoker_execution' => true,
                'actual_process_start_allowed_by_contract' => false,
                'external_process_started_by_contract' => false,
                'provider_process_call_allowed_by_contract' => false,
                'provider_started_by_contract' => false,
                'adapter_invocation_allowed_by_contract' => false,
                'adapter_execution_allowed_by_contract' => false,
                'token_spend_allowed_by_contract' => false,
                'dispatch_allowed_by_contract' => false,
                'idempotency_key' => 'signed_real_invoker_release_id',
            ],
            'required_input_fields_for_future_invoker' => [
                'run_key',
                'post_start_signed_real_invoker_release_gate_id',
                'post_start_real_invoker_release_preflight_gate_id',
                'post_start_external_process_invoker_dry_run_gate_id',
                'post_start_process_invocation_authorization_gate_id',
                'post_start_external_process_runtime_gate_id',
                'post_start_final_process_spawn_executor_gate_id',
                'post_start_process_spawn_enablement_gate_id',
                'post_start_supervised_start_gate_id',
                'post_start_process_start_release_gate_id',
                'process_start_release_id',
                'provider_execution_contract_gate_id',
                'codex_execution_id',
                'supervised_start_id',
                'spawn_enablement_id',
                'spawn_executor_id',
                'runtime_driver_id',
                'invocation_authorization_id',
                'dry_run_id',
                'real_invoker_release_preflight_id',
                'signed_real_invoker_release_id',
                'adapter_execution_guard_gate_id',
                'execution_guard_id',
                'adapter_invocation_boundary_gate_id',
                'adapter_invocation_id',
                'provider_start_driver_gate_id',
                'provider_start_attempt_id',
                'post_start_evidence_acceptance_bridge_id',
                'signed_dispatch_receipt_hash',
                'operator_release_receipt_hash',
                'operator_spawn_receipt_hash',
                'operator_final_spawn_receipt_hash',
                'operator_runtime_receipt_hash',
                'operator_invocation_receipt_hash',
                'operator_dry_run_receipt_hash',
                'operator_release_preflight_receipt_hash',
                'operator_signed_release_receipt_hash',
                'signature_verification_report_hash',
                'codex_execution_contract_hash',
                'supervised_start_contract_hash',
                'runtime_supervision_plan_hash',
                'stdout_stderr_sink_hash',
                'liveness_probe_hash',
                'runtime_driver_contract_hash',
                'invoker_contract_hash',
                'real_invoker_contract_hash',
                'release_policy_hash',
                'process_command_hash',
                'environment_contract_hash',
                'termination_policy_hash',
                'rollback_plan_hash',
                'max_runtime_policy_hash',
                'actor',
                'session',
                'reason',
            ],
            'allowed_future_mutations' => [
                'record_codex_signed_real_invoker_release_metadata_on_provider_start_run',
                'append_codex_signed_real_invoker_release_evidence_event',
                'record_codex_real_invoker_post_start_signed_release_metadata_on_observed_run',
            ],
            'forbidden_even_after_contract' => [
                'invoke_codex_process',
                'spawn_shell_or_subprocess',
                'call_codex_cli_or_codex_app',
                'run_real_external_process_invoker',
                'dispatch_work_to_codex',
                'send_prompt_to_provider',
                'call_provider_process',
                'enable_adapter_execution',
                'spend_provider_tokens',
                'mark_observed_run_running_or_terminal',
                'enable_self_programming',
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_signed_real_invoker_release_gate_preflight',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_signed_real_invoker_release_gate_contract.v1',
            'status' => (string) $contract['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_signed_real_invoker_release_gate_contract',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'post_start_signed_real_invoker_release_gate_allowed' => false,
            'actual_process_start_allowed' => false,
            'provider_process_call_allowed' => false,
            'adapter_invocation_allowed' => false,
            'adapter_execution_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_signed_real_invoker_release_gate_contract' => $contract,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_signed_real_invoker_release_gate_contract_hash' => $this->stableHash($contract),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_signed_real_invoker_release_gate_contract_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_signed_real_invoker_release_gate_contract_does_not_call_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_signed_real_invoker_release_gate_contract_does_not_execute_adapter',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_signed_real_invoker_release_gate_contract_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_signed_real_invoker_release_gate_contract_does_not_dispatch_work',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_signed_real_invoker_release_gate_contract_does_not_enable_self_programming',
            ],
            'human_summary' => 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start signed release gate contract is ready; it authorizes signed release only and still requires a separate implementation boundary.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartRealInvokerReleasePreflightGateContract(array $options = []): array
    {
        $dryRunStatusPayload = $this->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartExternalProcessInvokerDryRunGateStatus($options);
        $dryRunStatus = (array) data_get($dryRunStatusPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_external_process_invoker_dry_run_gate_status', []);
        $releasePreflightPayload = $this->agentCodexRealInvokerPostStartRealInvokerReleasePreflightGateContractTemplate($options);

        $contract = [
            'status' => 'one_shot_tick_codex_real_invoker_post_start_real_invoker_release_preflight_gate_contract_ready',
            'contract_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-REAL-INVOKER-POST-START-REAL-INVOKER-RELEASE-PREFLIGHT-GATE-CONTRACT-SELF-CONSTRUCTION-0001',
            'parent_program' => 'Atlas Self-Construction OS',
            'submodule' => 'Atlas Agent Control Plane',
            'provider' => 'codex',
            'adapter' => 'codex',
            'source_codex_real_invoker_post_start_external_process_invoker_dry_run_gate_status' => data_get($dryRunStatus, 'status'),
            'source_codex_real_invoker_post_start_external_process_invoker_dry_run_gate_status_hash' => data_get($dryRunStatusPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_external_process_invoker_dry_run_gate_status_hash'),
            'source_codex_real_invoker_post_start_real_invoker_release_preflight_gate_status' => data_get($releasePreflightPayload, 'status'),
            'source_codex_real_invoker_post_start_real_invoker_release_preflight_gate_hash' => data_get($releasePreflightPayload, 'codex_real_invoker_post_start_real_invoker_release_preflight_gate_contract_template_hash'),
            'real_invoker_release_preflight' => [
                'canonical_post_start_real_invoker_release_preflight_gate' => AgentCodexRealInvokerPostStartRealInvokerReleasePreflightGate::class,
                'canonical_post_start_real_invoker_release_preflight_gate_method' => 'recordPostStartRealInvokerReleasePreflight',
                'scheduler_invoker' => AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartRealInvokerReleasePreflightGateInvoker::class,
                'scheduler_invoker_method' => 'recordCodexRealInvokerPostStartRealInvokerReleasePreflightGate',
                'codex_real_invoker_release_preflight_service' => AgentCodexRealInvokerReleasePreflight::class,
                'codex_real_invoker_release_preflight_method' => 'recordPreflight',
                'preflight_effect' => 'record_post_start_real_invoker_release_preflight_without_signing_or_running_real_invoker',
                'post_start_external_process_invoker_dry_run_required_before_preflight' => true,
                'post_start_evidence_acceptance_bridge_required_before_preflight' => true,
                'provider_start_run_with_codex_external_process_invoker_dry_run_required_before_preflight' => true,
                'operator_release_preflight_receipt_hash_required' => true,
                'real_invoker_contract_hash_required' => true,
                'rollback_plan_hash_required' => true,
                'max_runtime_policy_hash_required' => true,
                'gate_delegates_to_codex_real_invoker_release_preflight' => true,
                'gate_records_codex_real_invoker_release_preflight_metadata_on_provider_start_run' => true,
                'gate_records_bridge_metadata_on_observed_run' => true,
                'signed_release_gate_required_after_preflight' => true,
                'real_invoker_release_preflight_is_not_signed_release' => true,
                'actual_process_start_allowed_by_contract' => false,
                'external_process_started_by_contract' => false,
                'provider_process_call_allowed_by_contract' => false,
                'provider_started_by_contract' => false,
                'adapter_invocation_allowed_by_contract' => false,
                'adapter_execution_allowed_by_contract' => false,
                'token_spend_allowed_by_contract' => false,
                'dispatch_allowed_by_contract' => false,
                'idempotency_key' => 'real_invoker_release_preflight_id',
            ],
            'required_input_fields_for_future_invoker' => [
                'run_key',
                'post_start_real_invoker_release_preflight_gate_id',
                'post_start_external_process_invoker_dry_run_gate_id',
                'post_start_process_invocation_authorization_gate_id',
                'post_start_external_process_runtime_gate_id',
                'post_start_final_process_spawn_executor_gate_id',
                'post_start_process_spawn_enablement_gate_id',
                'post_start_supervised_start_gate_id',
                'post_start_process_start_release_gate_id',
                'process_start_release_id',
                'provider_execution_contract_gate_id',
                'codex_execution_id',
                'supervised_start_id',
                'spawn_enablement_id',
                'spawn_executor_id',
                'runtime_driver_id',
                'invocation_authorization_id',
                'dry_run_id',
                'real_invoker_release_preflight_id',
                'adapter_execution_guard_gate_id',
                'execution_guard_id',
                'adapter_invocation_boundary_gate_id',
                'adapter_invocation_id',
                'provider_start_driver_gate_id',
                'provider_start_attempt_id',
                'post_start_evidence_acceptance_bridge_id',
                'signed_dispatch_receipt_hash',
                'operator_release_receipt_hash',
                'operator_spawn_receipt_hash',
                'operator_final_spawn_receipt_hash',
                'operator_runtime_receipt_hash',
                'operator_invocation_receipt_hash',
                'operator_dry_run_receipt_hash',
                'operator_release_preflight_receipt_hash',
                'codex_execution_contract_hash',
                'supervised_start_contract_hash',
                'runtime_supervision_plan_hash',
                'stdout_stderr_sink_hash',
                'liveness_probe_hash',
                'runtime_driver_contract_hash',
                'invoker_contract_hash',
                'real_invoker_contract_hash',
                'process_command_hash',
                'environment_contract_hash',
                'termination_policy_hash',
                'rollback_plan_hash',
                'max_runtime_policy_hash',
                'actor',
                'session',
                'reason',
            ],
            'allowed_future_mutations' => [
                'record_codex_real_invoker_release_preflight_metadata_on_provider_start_run',
                'append_codex_real_invoker_release_preflight_evidence_event',
                'record_codex_real_invoker_post_start_release_preflight_metadata_on_observed_run',
            ],
            'forbidden_even_after_contract' => [
                'sign_real_invoker_release',
                'invoke_codex_process',
                'spawn_shell_or_subprocess',
                'call_codex_cli_or_codex_app',
                'run_real_external_process_invoker',
                'dispatch_work_to_codex',
                'send_prompt_to_provider',
                'call_provider_process',
                'enable_adapter_execution',
                'spend_provider_tokens',
                'mark_observed_run_running_or_terminal',
                'enable_self_programming',
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_real_invoker_release_preflight_gate_preflight',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_real_invoker_release_preflight_gate_contract.v1',
            'status' => (string) $contract['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_real_invoker_release_preflight_gate_contract',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'post_start_real_invoker_release_preflight_gate_allowed' => false,
            'actual_process_start_allowed' => false,
            'provider_process_call_allowed' => false,
            'adapter_invocation_allowed' => false,
            'adapter_execution_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_real_invoker_release_preflight_gate_contract' => $contract,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_real_invoker_release_preflight_gate_contract_hash' => $this->stableHash($contract),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_real_invoker_release_preflight_gate_contract_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_real_invoker_release_preflight_gate_contract_does_not_call_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_real_invoker_release_preflight_gate_contract_does_not_execute_adapter',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_real_invoker_release_preflight_gate_contract_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_real_invoker_release_preflight_gate_contract_does_not_dispatch_work',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_real_invoker_release_preflight_gate_contract_does_not_enable_self_programming',
            ],
            'human_summary' => 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start release preflight gate contract is ready; it records release preflight only and still requires a separate signed release gate.',
        ];
    }
}
