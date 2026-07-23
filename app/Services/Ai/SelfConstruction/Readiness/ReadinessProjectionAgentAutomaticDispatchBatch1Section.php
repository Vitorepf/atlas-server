<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Readiness;

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

/**
 * SC-01 fatia ReadinessProjectionAgentAutomaticDispatchBatch1Section (Obra 4 Residual Elite).
 */
final class ReadinessProjectionAgentAutomaticDispatchBatch1Section
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
            throw new \RuntimeException('ReadinessProjectionAgentAutomaticDispatchBatch1Section mother not bound for '.$name);
        }
        $method = new \ReflectionMethod($this->mother, $name);

        return $method->invokeArgs($this->mother, $arguments);
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProcessStartEnvelopeGatePreflight(array $options = []): array
    {
        $contractPayload = $this->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProcessStartEnvelopeGateContract($options);
        $contract = (array) data_get($contractPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_start_envelope_gate_contract', []);
        $contractHash = (string) data_get($contractPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_start_envelope_gate_contract_hash');
        $gateReady = class_exists(AgentCodexRealInvokerPostStartProcessStartEnvelopeGate::class)
            && method_exists(AgentCodexRealInvokerPostStartProcessStartEnvelopeGate::class, 'buildPostStartProcessStartEnvelope');
        $invokerReady = class_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProcessStartEnvelopeGateInvoker::class)
            && method_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProcessStartEnvelopeGateInvoker::class, 'prepareCodexRealInvokerPostStartProcessStartEnvelopeGate');
        $envelopeBuilderReady = class_exists(AgentCodexRealInvokerProcessStartEnvelopeBuilder::class)
            && method_exists(AgentCodexRealInvokerProcessStartEnvelopeBuilder::class, 'buildStartEnvelope');
        $rehearsalReady = class_exists(AgentCodexRealInvokerPostStartActualProcessStartRehearsalGate::class)
            && method_exists(AgentCodexRealInvokerPostStartActualProcessStartRehearsalGate::class, 'rehearsePostStartActualProcessStart');
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerTableReady = Schema::hasTable('atlas_ledger_events');

        $checks = [
            'post_start_process_start_envelope_gate_contract_ready' => data_get($contractPayload, 'status') === 'one_shot_tick_codex_real_invoker_post_start_process_start_envelope_gate_contract_ready',
            'post_start_process_start_envelope_gate_contract_hash_present' => $contractHash !== '',
            'post_start_actual_process_start_rehearsal_gate_status_ready' => data_get($contract, 'source_codex_real_invoker_post_start_actual_process_start_rehearsal_gate_status') === 'one_shot_tick_codex_real_invoker_post_start_actual_process_start_rehearsal_gate_service_ready',
            'generic_post_start_process_start_envelope_gate_template_ready' => data_get($contract, 'source_codex_real_invoker_post_start_process_start_envelope_gate_contract_status') === 'codex_real_invoker_post_start_process_start_envelope_gate_contract_template_ready',
            'generic_process_start_envelope_builder_template_ready' => data_get($contract, 'source_codex_real_invoker_process_start_envelope_builder_contract_status') === 'codex_real_invoker_process_start_envelope_builder_contract_template_ready',
            'codex_real_invoker_post_start_process_start_envelope_gate_ready' => $gateReady,
            'codex_real_invoker_post_start_process_start_envelope_gate_invoker_ready' => $invokerReady,
            'codex_real_invoker_process_start_envelope_builder_ready' => $envelopeBuilderReady,
            'codex_real_invoker_post_start_actual_process_start_rehearsal_gate_ready' => $rehearsalReady,
            'canonical_post_start_process_start_envelope_gate_method_ready' => data_get($contract, 'process_start_envelope.canonical_post_start_process_start_envelope_gate_method') === 'buildPostStartProcessStartEnvelope',
            'scheduler_invoker_method_ready' => data_get($contract, 'process_start_envelope.scheduler_invoker_method') === 'prepareCodexRealInvokerPostStartProcessStartEnvelopeGate',
            'contract_requires_post_start_actual_process_start_rehearsal' => data_get($contract, 'process_start_envelope.post_start_actual_process_start_rehearsal_required_before_envelope') === true,
            'contract_requires_post_start_evidence_acceptance_bridge' => data_get($contract, 'process_start_envelope.post_start_evidence_acceptance_bridge_required_before_envelope') === true,
            'contract_requires_provider_start_rehearsal' => data_get($contract, 'process_start_envelope.provider_start_rehearsal_required_before_envelope') === true,
            'contract_delegates_to_codex_real_invoker_process_start_envelope_builder' => data_get($contract, 'process_start_envelope.gate_delegates_to_codex_real_invoker_process_start_envelope_builder') === true,
            'contract_declares_envelope_is_not_actual_process_start' => data_get($contract, 'process_start_envelope.envelope_is_not_actual_process_start') === true,
            'contract_requires_start_execution_gate_after_envelope' => in_array('execute_start_without_separate_start_execution_gate_contract', (array) data_get($contract, 'forbidden_even_after_contract', []), true),
            'contract_builds_envelope' => data_get($contract, 'process_start_envelope.process_start_envelope_built_by_contract') === true,
            'contract_keeps_actual_process_start_disabled' => data_get($contract, 'process_start_envelope.actual_process_start_allowed_by_contract') === false,
            'contract_keeps_process_started_false' => data_get($contract, 'process_start_envelope.process_started_by_contract') === false,
            'contract_keeps_adapter_execution_disabled' => data_get($contract, 'process_start_envelope.adapter_execution_allowed_by_contract') === false,
            'contract_does_not_dispatch_work' => data_get($contract, 'process_start_envelope.dispatch_allowed_by_contract') === false,
            'contract_does_not_spend_tokens' => data_get($contract, 'process_start_envelope.token_spend_allowed_by_contract') === false,
            'contract_lists_runtime_enabling_flags_as_forbidden' => in_array('actual_process_start_allowed', (array) data_get($contract, 'forbidden_true_input_flags', []), true)
                && in_array('dispatch_allowed', (array) data_get($contract, 'forbidden_true_input_flags', []), true)
                && in_array('process_started', (array) data_get($contract, 'forbidden_true_input_flags', []), true)
                && in_array('self_programming_allowed', (array) data_get($contract, 'forbidden_true_input_flags', []), true),
            'agent_runs_table_ready' => $runsTableReady,
            'ledger_table_ready' => $ledgerTableReady,
            'self_programming_forbidden' => in_array('enable_self_programming', (array) data_get($contract, 'forbidden_even_after_contract', []), true),
        ];
        $blockingReasons = array_values(array_keys(array_filter($checks, static fn (bool $passed): bool => ! $passed)));

        $preflight = [
            'status' => $blockingReasons === [] ? 'one_shot_tick_codex_real_invoker_post_start_process_start_envelope_gate_preflight_ready' : 'blocked',
            'preflight_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-REAL-INVOKER-POST-START-PROCESS-START-ENVELOPE-GATE-PREFLIGHT-SELF-CONSTRUCTION-0001',
            'source_post_start_process_start_envelope_gate_contract_hash' => $contractHash,
            'preflight_checks' => $checks,
            'blocking_count' => count($blockingReasons),
            'blocking_reasons' => $blockingReasons,
            'implementation_requirements' => [
                'create_scheduler_specific_post_start_process_start_envelope_gate_invoker',
                'delegate_to_agent_codex_real_invoker_post_start_process_start_envelope_gate',
                'require_post_start_actual_process_start_rehearsal_metadata',
                'require_post_start_evidence_acceptance_bridge',
                'require_process_start_envelope_hash',
                'require_start_command_hash',
                'require_start_environment_hash',
                'require_start_cwd_hash',
                'require_start_supervisor_hash',
                'require_start_liveness_contract_hash',
                'reject_caller_inputs_that_try_to_enable_runtime_flags',
                'project_readiness_status_without_invoking_codex',
            ],
            'runtime_policy' => [
                'preflight_is_read_only' => true,
                'post_start_process_start_envelope_gate_call_allowed_by_future_invoker' => true,
                'process_start_envelope_built_after_future_invoker' => true,
                'start_execution_gate_required_after_envelope' => true,
                'actual_process_start_allowed_here' => false,
                'provider_process_call_allowed_here' => false,
                'executor_enabled_here' => false,
                'process_start_armed_here' => false,
                'process_started_here' => false,
                'adapter_invocation_allowed_here' => false,
                'adapter_execution_allowed_here' => false,
                'dispatch_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'self_programming_allowed_here' => false,
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_process_start_envelope_gate_implementation_packet',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_start_envelope_gate_preflight.v1',
            'status' => (string) $preflight['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_start_envelope_gate_preflight',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'post_start_process_start_envelope_gate_allowed' => false,
            'actual_process_start_allowed' => false,
            'provider_process_call_allowed' => false,
            'executor_enabled' => false,
            'process_start_armed' => false,
            'process_started' => false,
            'adapter_invocation_allowed' => false,
            'adapter_execution_allowed' => false,
            'token_spend_allowed' => false,
            'self_programming_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_start_envelope_gate_preflight' => $preflight,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_start_envelope_gate_preflight_hash' => $this->stableHash($preflight),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_start_envelope_gate_preflight_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_start_envelope_gate_preflight_does_not_call_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_start_envelope_gate_preflight_does_not_execute_adapter',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_start_envelope_gate_preflight_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_start_envelope_gate_preflight_does_not_dispatch_work',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_start_envelope_gate_preflight_does_not_enable_self_programming',
            ],
            'human_summary' => $blockingReasons === []
                ? 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start process start envelope gate preflight is ready; start execution gate remains separate.'
                : 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start process start envelope gate preflight is blocked until rehearsal, envelope builder, storage and no-runtime prerequisites are ready.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickWriterContract(array $options = []): array
    {
        $dryRunTickPayload = $this->agentAutomaticDispatchSchedulerDryRunTick($options);
        $dryRunTick = (array) data_get($dryRunTickPayload, 'agent_automatic_dispatch_scheduler_dry_run_tick', []);
        $runtimeTables = $this->agentControlPlaneRuntimeTables();

        $componentReadiness = [
            'dry_run_tick' => data_get($dryRunTickPayload, 'status') === 'agent_automatic_dispatch_scheduler_dry_run_tick_ready',
            'wakeup_items_table' => $runtimeTables['atlas_self_construction_agent_wakeup_items'],
            'dispatch_receipts_table' => $runtimeTables['atlas_self_construction_agent_dispatch_receipts'],
            'agent_runs_table' => $runtimeTables['atlas_self_construction_agent_runs'],
            'candidate_selection_contract_available' => data_get($dryRunTick, 'queue_projection.selection_order') !== null,
            'dispatch_envelope_preview_contract_available' => array_key_exists('dispatch_envelope_preview', $dryRunTick),
        ];
        $blockingReasons = array_values(array_map(
            static fn (string $component): string => $component.'_not_ready',
            array_keys(array_filter($componentReadiness, static fn (bool $ready): bool => ! $ready))
        ));

        $contract = [
            'status' => $blockingReasons === [] ? 'agent_automatic_dispatch_scheduler_one_shot_tick_writer_contract_ready' : 'blocked',
            'contract_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-WRITER-CONTRACT-SELF-CONSTRUCTION-0001',
            'parent_program' => 'Atlas Self-Construction OS',
            'submodule' => 'Atlas Agent Control Plane',
            'source_dry_run_tick_hash' => data_get($dryRunTickPayload, 'agent_automatic_dispatch_scheduler_dry_run_tick_hash'),
            'component_readiness' => $componentReadiness,
            'blocking_count' => count($blockingReasons),
            'blocking_reasons' => $blockingReasons,
            'writer_scope' => [
                'max_wakeup_items_per_invocation' => 1,
                'max_dispatch_receipts_per_invocation' => 1,
                'allowed_wakeup_source_status' => 'queued',
                'new_wakeup_status_after_claim' => 'claimed',
                'new_dispatch_receipt_status' => 'signed_pending_dispatch',
                'provider_start_after_write' => false,
                'receipt_use_after_write' => false,
            ],
            'required_signed_release' => [
                'decision' => 'approve_scheduler_claim_and_receipt_once',
                'required_fields' => [
                    'signed_by',
                    'signed_at',
                    'expires_at',
                    'dry_run_tick_hash',
                    'selected_wakeup_key',
                    'dispatch_envelope_hash',
                    'max_wakeup_items',
                    'max_dispatch_receipts',
                    'reason',
                ],
                'idempotency_key' => 'sha256(selected_wakeup_key|packet_id|provider|dry_run_tick_hash|release_receipt_hash)',
                'expires_required' => true,
                'human_or_kernel_signature_required' => true,
            ],
            'allowed_mutations_after_future_release' => [
                'claim_selected_wakeup_item_atomically',
                'write_one_signed_pending_dispatch_receipt',
                'append_scheduler_tick_evidence',
                'stop_before_receipt_use',
                'stop_before_provider_start',
            ],
            'forbidden_mutations_even_after_release' => [
                'mark_dispatch_receipt_used',
                'start_provider_process',
                'invoke_provider_adapter',
                'mark_packet_completed',
                'merge_or_apply_work_products',
                'self_program_or_self_merge',
            ],
            'preflight_must_verify' => [
                'dry_run_tick_hash_matches_latest_projection',
                'selected_wakeup_item_is_still_queued',
                'selected_wakeup_item_is_still_ready',
                'packet_scope_has_no_active_conflicting_writer',
                'release_receipt_is_fresh_and_unexpired',
                'dispatch_envelope_hash_matches_candidate',
                'dispatch_receipt_dedupe_key_is_unused',
            ],
            'allowed_now' => [
                'one_shot_tick_writer_contract_projection',
                'dry_run_tick_contract_reference',
                'future_mutation_scope_projection',
            ],
            'forbidden_now' => [
                'claim_wakeup_item',
                'write_dispatch_receipt',
                'mark_dispatch_receipt_used',
                'start_provider_process',
                'invoke_provider_adapter',
                'spend_provider_tokens',
            ],
            'next_required_slice' => $blockingReasons === []
                ? 'activate_signed_one_shot_scheduler_tick_writer_preflight'
                : 'repair_signed_one_shot_scheduler_tick_writer_contract_blockers',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_writer_contract.v1',
            'status' => (string) $contract['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_writer_contract',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'claim_allowed' => false,
            'dispatch_receipt_write_allowed' => false,
            'provider_start_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_writer_contract' => $contract,
            'agent_automatic_dispatch_scheduler_one_shot_tick_writer_contract_hash' => $this->stableHash($contract),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_writer_contract_does_not_claim_wakeup_items',
                'agent_automatic_dispatch_scheduler_one_shot_tick_writer_contract_does_not_write_dispatch_receipts',
                'agent_automatic_dispatch_scheduler_one_shot_tick_writer_contract_does_not_mark_receipts_used',
                'agent_automatic_dispatch_scheduler_one_shot_tick_writer_contract_does_not_start_providers',
                'agent_automatic_dispatch_scheduler_one_shot_tick_writer_contract_does_not_enable_self_programming',
            ],
            'human_summary' => $blockingReasons === []
                ? 'Automatic dispatch scheduler one-shot tick writer contract is ready: Atlas has the bounded mutation contract for one wakeup claim and one signed pending receipt, but this command does not mutate runtime or start providers.'
                : 'Automatic dispatch scheduler one-shot tick writer contract is blocked until every dry-run and runtime prerequisite is ready.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartExecutorEnablementGateStatus(array $options = []): array
    {
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerTableReady = Schema::hasTable('atlas_ledger_events');
        $gateReady = class_exists(AgentCodexRealInvokerPostStartExecutorEnablementGate::class)
            && method_exists(AgentCodexRealInvokerPostStartExecutorEnablementGate::class, 'enablePostStartExecutor');
        $invokerReady = class_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartExecutorEnablementGateInvoker::class)
            && method_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartExecutorEnablementGateInvoker::class, 'enableCodexRealInvokerPostStartExecutorGate');
        $enablementReady = class_exists(AgentCodexRealInvokerExecutorEnablementGate::class)
            && method_exists(AgentCodexRealInvokerExecutorEnablementGate::class, 'enableExecutor');
        $freshReleaseReady = class_exists(AgentCodexRealInvokerPostStartExecutorFreshReleaseGate::class)
            && method_exists(AgentCodexRealInvokerPostStartExecutorFreshReleaseGate::class, 'authorizePostStartExecutorFreshRelease');

        $observedEnablementRunsQuery = $runsTableReady
            ? AtlasSelfConstructionAgentRun::query()
                ->whereNotNull('metadata->codex_real_invoker_post_start_executor_enablement->real_invoker_executor_enablement_id')
            : null;
        $providerRunsWithEnablementQuery = $runsTableReady
            ? AtlasSelfConstructionAgentRun::query()
                ->where('run_key', 'like', 'provider-start:%')
                ->where('status', 'adapter_invocation_prepared')
                ->whereNotNull('metadata->codex_real_invoker_executor_enablement->real_invoker_executor_enablement_id')
            : null;
        $latestObservedEnablement = $observedEnablementRunsQuery === null
            ? null
            : (clone $observedEnablementRunsQuery)->latest('updated_at')->first();

        $statusReady = $runsTableReady
            && $ledgerTableReady
            && $gateReady
            && $invokerReady
            && $enablementReady
            && $freshReleaseReady;
        $status = [
            'status' => $statusReady ? 'one_shot_tick_codex_real_invoker_post_start_executor_enablement_gate_service_ready' : 'blocked',
            'provider' => 'codex',
            'adapter' => 'codex',
            'invoker_service' => AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartExecutorEnablementGateInvoker::class,
            'invoker_service_ready' => $invokerReady,
            'invoker_canonical_method' => 'enableCodexRealInvokerPostStartExecutorGate',
            'generic_post_start_executor_enablement_gate_service' => AgentCodexRealInvokerPostStartExecutorEnablementGate::class,
            'generic_post_start_executor_enablement_gate_service_ready' => $gateReady,
            'generic_post_start_executor_enablement_gate_canonical_method' => 'enablePostStartExecutor',
            'codex_real_invoker_executor_enablement_gate_service' => AgentCodexRealInvokerExecutorEnablementGate::class,
            'codex_real_invoker_executor_enablement_gate_ready' => $enablementReady,
            'codex_real_invoker_executor_enablement_gate_canonical_method' => 'enableExecutor',
            'post_start_executor_fresh_release_gate_service' => AgentCodexRealInvokerPostStartExecutorFreshReleaseGate::class,
            'post_start_executor_fresh_release_gate_ready' => $freshReleaseReady,
            'agent_runs_table_ready' => $runsTableReady,
            'ledger_table_ready' => $ledgerTableReady,
            'codex_real_invoker_post_start_executor_enablement_recorded_run_count' => $observedEnablementRunsQuery === null ? null : (clone $observedEnablementRunsQuery)->count(),
            'provider_start_runs_with_codex_real_invoker_executor_enablement_count' => $providerRunsWithEnablementQuery === null ? null : (clone $providerRunsWithEnablementQuery)->count(),
            'latest_codex_real_invoker_post_start_executor_enablement' => $latestObservedEnablement instanceof AtlasSelfConstructionAgentRun
                ? [
                    'agent_run_id' => $latestObservedEnablement->id,
                    'run_key' => $latestObservedEnablement->run_key,
                    'packet_id' => $latestObservedEnablement->packet_id,
                    'provider' => $latestObservedEnablement->provider,
                    'status' => $latestObservedEnablement->status,
                    'post_start_executor_enablement_gate_id' => data_get($latestObservedEnablement->metadata, 'codex_real_invoker_post_start_executor_enablement.post_start_executor_enablement_gate_id'),
                    'post_start_executor_fresh_release_gate_id' => data_get($latestObservedEnablement->metadata, 'codex_real_invoker_post_start_executor_enablement.post_start_executor_fresh_release_gate_id'),
                    'post_start_evidence_acceptance_bridge_id' => data_get($latestObservedEnablement->metadata, 'codex_real_invoker_post_start_executor_enablement.post_start_evidence_acceptance_bridge_id'),
                    'real_invoker_executor_fresh_release_id' => data_get($latestObservedEnablement->metadata, 'codex_real_invoker_post_start_executor_enablement.real_invoker_executor_fresh_release_id'),
                    'real_invoker_executor_enablement_id' => data_get($latestObservedEnablement->metadata, 'codex_real_invoker_post_start_executor_enablement.real_invoker_executor_enablement_id'),
                    'codex_execution_id' => data_get($latestObservedEnablement->metadata, 'codex_real_invoker_post_start_executor_enablement.codex_execution_id'),
                    'real_invoker_executor_enabled' => data_get($latestObservedEnablement->metadata, 'codex_real_invoker_post_start_executor_enablement.real_invoker_executor_enabled'),
                    'executor_enabled' => data_get($latestObservedEnablement->metadata, 'codex_real_invoker_post_start_executor_enablement.executor_enabled'),
                    'supervised_start_required' => data_get($latestObservedEnablement->metadata, 'codex_real_invoker_post_start_executor_enablement.supervised_start_required'),
                    'actual_process_start_allowed' => data_get($latestObservedEnablement->metadata, 'codex_real_invoker_post_start_executor_enablement.actual_process_start_allowed'),
                    'token_spend_allowed' => data_get($latestObservedEnablement->metadata, 'codex_real_invoker_post_start_executor_enablement.token_spend_allowed'),
                ]
                : null,
            'runtime_policy' => [
                'status_projection_is_read_only' => true,
                'invoker_may_enable_executor_when_called_with_fresh_release_input' => true,
                'executor_enablement_is_not_real_process_invocation' => true,
                'supervised_start_required_before_process_start' => true,
                'atlas_process_invocation_allowed_here' => false,
                'actual_process_start_allowed_here' => false,
                'provider_process_call_allowed_here' => false,
                'executor_enabled_here' => false,
                'adapter_invocation_allowed_here' => false,
                'adapter_execution_allowed_here' => false,
                'dispatch_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'self_programming_allowed_here' => false,
            ],
            'next_required_slice' => $statusReady
                ? 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_supervised_start_activation_gate_contract'
                : 'repair_one_shot_scheduler_tick_codex_real_invoker_post_start_executor_enablement_gate_service_blockers',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_enablement_gate_status.v1',
            'status' => (string) $status['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_enablement_gate_status',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'post_start_executor_enablement_gate_allowed' => false,
            'actual_process_start_allowed' => false,
            'provider_process_call_allowed' => false,
            'executor_enabled' => false,
            'adapter_invocation_allowed' => false,
            'adapter_execution_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_enablement_gate_status' => $status,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_enablement_gate_status_hash' => $this->stableHash($status),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_enablement_gate_status_does_not_call_enablement_gate',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_enablement_gate_status_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_enablement_gate_status_does_not_execute_adapter',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_enablement_gate_status_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_enablement_gate_status_does_not_dispatch_work',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_enablement_gate_status_does_not_enable_self_programming',
            ],
            'human_summary' => $statusReady
                ? 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start executor enablement gate service is ready and inspectable; supervised start activation remains separate.'
                : 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start executor enablement gate service is blocked until invoker, generic enablement gate, fresh release gate and storage are ready.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerExecutorEnablementGateContract(array $options = []): array
    {
        $freshReleaseStatusPayload = $this->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerExecutorFreshReleaseGateStatus($options);
        $freshReleaseStatus = (array) data_get($freshReleaseStatusPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_fresh_release_gate_status', []);
        $enablementPayload = $this->agentCodexRealInvokerExecutorEnablementGateContractTemplate($options);

        $contract = [
            'status' => 'one_shot_tick_codex_real_invoker_executor_enablement_gate_contract_ready',
            'contract_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-REAL-INVOKER-EXECUTOR-ENABLEMENT-GATE-CONTRACT-SELF-CONSTRUCTION-0001',
            'parent_program' => 'Atlas Self-Construction OS',
            'submodule' => 'Atlas Agent Control Plane',
            'provider' => 'codex',
            'adapter' => 'codex',
            'source_codex_real_invoker_executor_fresh_release_gate_status' => data_get($freshReleaseStatus, 'status'),
            'source_codex_real_invoker_executor_fresh_release_gate_status_hash' => data_get($freshReleaseStatusPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_fresh_release_gate_status_hash'),
            'source_codex_real_invoker_executor_enablement_gate_contract_status' => data_get($enablementPayload, 'status'),
            'source_codex_real_invoker_executor_enablement_gate_contract_hash' => data_get($enablementPayload, 'codex_real_invoker_executor_enablement_gate_contract_template_hash'),
            'release_boundary' => [
                'canonical_executor_enablement_gate' => AgentCodexRealInvokerExecutorEnablementGate::class,
                'canonical_executor_enablement_gate_method' => 'enableExecutor',
                'scheduler_invoker' => AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerExecutorEnablementGateInvoker::class,
                'scheduler_invoker_method' => 'enableCodexRealInvokerExecutor',
                'enablement_effect' => 'enable_codex_real_invoker_executor_metadata_without_starting_process',
                'executor_enabled_by_enablement' => true,
                'external_process_started_by_enablement' => false,
                'provider_started_by_enablement' => false,
                'adapter_execution_allowed_by_enablement' => false,
                'token_spend_allowed_by_enablement' => false,
                'required_fresh_release_status_before_enablement' => 'real_invoker_executor_fresh_release_authorized_pending_enablement',
                'prepared_status_after_enablement' => 'real_invoker_executor_enabled_pending_supervised_start',
                'idempotency_key' => 'real_invoker_executor_enablement_id',
            ],
            'required_input_fields_for_future_invoker' => [
                'run_key',
                'codex_execution_id',
                'process_start_release_id',
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
                'operator_enablement_receipt_hash',
                'enablement_policy_hash',
                'pre_start_checklist_hash',
                'disable_switch_hash',
                'operator_fresh_release_receipt_hash',
                'plan_revalidation_report_hash',
                'freshness_window_hash',
                'final_human_signature_hash',
                'real_invoker_contract_hash',
                'release_policy_hash',
                'implementation_plan_hash',
                'executor_binary_contract_hash',
                'executor_observability_contract_hash',
                'process_command_hash',
                'environment_contract_hash',
                'termination_policy_hash',
                'stdout_stderr_sink_hash',
                'liveness_probe_hash',
                'rollback_plan_hash',
                'max_runtime_policy_hash',
                'actor',
                'session',
                'reason',
            ],
            'allowed_future_mutations' => [
                'write_codex_real_invoker_executor_enablement_metadata_on_agent_run',
                'append_codex_real_invoker_executor_enabled_evidence_event',
            ],
            'forbidden_even_after_contract' => [
                'spawn_codex_process',
                'call_codex_cli_or_codex_app',
                'spawn_shell_or_subprocess',
                'spend_provider_tokens',
                'mark_run_running_or_terminal',
                'mark_packet_completed',
                'merge_work_products',
                'enable_self_programming',
            ],
            'handoff_policy' => [
                'enablement_is_executor_metadata_not_process_start' => true,
                'supervised_start_activation_requires_separate_contract' => true,
                'operator_enablement_receipt_hash_required' => true,
                'enablement_policy_hash_required' => true,
                'pre_start_checklist_hash_required' => true,
                'disable_switch_hash_required' => true,
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_executor_enablement_gate_preflight',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_enablement_gate_contract.v1',
            'status' => (string) $contract['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_enablement_gate_contract',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_executor_enablement_gate_allowed' => false,
            'executor_enabled' => false,
            'adapter_execution_allowed' => false,
            'provider_start_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_enablement_gate_contract' => $contract,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_enablement_gate_contract_hash' => $this->stableHash($contract),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_enablement_gate_contract_does_not_enable_executor',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_enablement_gate_contract_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_enablement_gate_contract_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_enablement_gate_contract_does_not_enable_self_programming',
            ],
            'human_summary' => 'Automatic dispatch scheduler one-shot tick Codex real invoker executor enablement gate contract is ready; it defines executor enablement metadata but still cannot start Codex.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartImplementationBoundaryGateStatus(array $options = []): array
    {
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerTableReady = Schema::hasTable('atlas_ledger_events');
        $gateReady = class_exists(AgentCodexRealInvokerPostStartImplementationBoundaryGate::class)
            && method_exists(AgentCodexRealInvokerPostStartImplementationBoundaryGate::class, 'preparePostStartImplementationBoundary');
        $invokerReady = class_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartImplementationBoundaryGateInvoker::class)
            && method_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartImplementationBoundaryGateInvoker::class, 'prepareCodexRealInvokerPostStartImplementationBoundaryGate');
        $boundaryReady = class_exists(AgentCodexRealInvokerImplementationBoundary::class)
            && method_exists(AgentCodexRealInvokerImplementationBoundary::class, 'prepareBoundary');
        $signedReleaseReady = class_exists(AgentCodexRealInvokerPostStartSignedRealInvokerReleaseGate::class)
            && method_exists(AgentCodexRealInvokerPostStartSignedRealInvokerReleaseGate::class, 'authorizePostStartSignedRealInvokerRelease');

        $observedBoundaryRunsQuery = $runsTableReady
            ? AtlasSelfConstructionAgentRun::query()
                ->whereNotNull('metadata->codex_real_invoker_post_start_implementation_boundary->real_invoker_implementation_boundary_id')
            : null;
        $providerRunsWithBoundaryQuery = $runsTableReady
            ? AtlasSelfConstructionAgentRun::query()
                ->where('run_key', 'like', 'provider-start:%')
                ->where('status', 'adapter_invocation_prepared')
                ->whereNotNull('metadata->codex_real_invoker_implementation_boundary->real_invoker_implementation_boundary_id')
            : null;
        $latestObservedBoundary = $observedBoundaryRunsQuery === null
            ? null
            : (clone $observedBoundaryRunsQuery)->latest('updated_at')->first();

        $statusReady = $runsTableReady
            && $ledgerTableReady
            && $gateReady
            && $invokerReady
            && $boundaryReady
            && $signedReleaseReady;
        $status = [
            'status' => $statusReady ? 'one_shot_tick_codex_real_invoker_post_start_implementation_boundary_gate_service_ready' : 'blocked',
            'provider' => 'codex',
            'adapter' => 'codex',
            'invoker_service' => AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartImplementationBoundaryGateInvoker::class,
            'invoker_service_ready' => $invokerReady,
            'invoker_canonical_method' => 'prepareCodexRealInvokerPostStartImplementationBoundaryGate',
            'generic_post_start_implementation_boundary_gate_service' => AgentCodexRealInvokerPostStartImplementationBoundaryGate::class,
            'generic_post_start_implementation_boundary_gate_service_ready' => $gateReady,
            'generic_post_start_implementation_boundary_gate_canonical_method' => 'preparePostStartImplementationBoundary',
            'codex_real_invoker_implementation_boundary_service' => AgentCodexRealInvokerImplementationBoundary::class,
            'codex_real_invoker_implementation_boundary_ready' => $boundaryReady,
            'codex_real_invoker_implementation_boundary_canonical_method' => 'prepareBoundary',
            'post_start_signed_real_invoker_release_gate_service' => AgentCodexRealInvokerPostStartSignedRealInvokerReleaseGate::class,
            'post_start_signed_real_invoker_release_gate_ready' => $signedReleaseReady,
            'agent_runs_table_ready' => $runsTableReady,
            'ledger_table_ready' => $ledgerTableReady,
            'codex_real_invoker_post_start_implementation_boundary_recorded_run_count' => $observedBoundaryRunsQuery === null ? null : (clone $observedBoundaryRunsQuery)->count(),
            'provider_start_runs_with_codex_real_invoker_implementation_boundary_count' => $providerRunsWithBoundaryQuery === null ? null : (clone $providerRunsWithBoundaryQuery)->count(),
            'latest_codex_real_invoker_post_start_implementation_boundary' => $latestObservedBoundary instanceof AtlasSelfConstructionAgentRun
                ? [
                    'agent_run_id' => $latestObservedBoundary->id,
                    'run_key' => $latestObservedBoundary->run_key,
                    'packet_id' => $latestObservedBoundary->packet_id,
                    'provider' => $latestObservedBoundary->provider,
                    'status' => $latestObservedBoundary->status,
                    'post_start_implementation_boundary_gate_id' => data_get($latestObservedBoundary->metadata, 'codex_real_invoker_post_start_implementation_boundary.post_start_implementation_boundary_gate_id'),
                    'post_start_evidence_acceptance_bridge_id' => data_get($latestObservedBoundary->metadata, 'codex_real_invoker_post_start_implementation_boundary.post_start_evidence_acceptance_bridge_id'),
                    'post_start_signed_real_invoker_release_gate_id' => data_get($latestObservedBoundary->metadata, 'codex_real_invoker_post_start_implementation_boundary.post_start_signed_real_invoker_release_gate_id'),
                    'real_invoker_implementation_boundary_id' => data_get($latestObservedBoundary->metadata, 'codex_real_invoker_post_start_implementation_boundary.real_invoker_implementation_boundary_id'),
                    'signed_real_invoker_release_id' => data_get($latestObservedBoundary->metadata, 'codex_real_invoker_post_start_implementation_boundary.signed_real_invoker_release_id'),
                    'real_invoker_release_preflight_id' => data_get($latestObservedBoundary->metadata, 'codex_real_invoker_post_start_implementation_boundary.real_invoker_release_preflight_id'),
                    'dry_run_id' => data_get($latestObservedBoundary->metadata, 'codex_real_invoker_post_start_implementation_boundary.dry_run_id'),
                    'codex_execution_id' => data_get($latestObservedBoundary->metadata, 'codex_real_invoker_post_start_implementation_boundary.codex_execution_id'),
                    'real_invoker_implementation_boundary_prepared' => data_get($latestObservedBoundary->metadata, 'codex_real_invoker_post_start_implementation_boundary.real_invoker_implementation_boundary_prepared'),
                    'executor_plan_required' => data_get($latestObservedBoundary->metadata, 'codex_real_invoker_post_start_implementation_boundary.executor_plan_required'),
                    'actual_process_start_allowed' => data_get($latestObservedBoundary->metadata, 'codex_real_invoker_post_start_implementation_boundary.actual_process_start_allowed'),
                    'token_spend_allowed' => data_get($latestObservedBoundary->metadata, 'codex_real_invoker_post_start_implementation_boundary.token_spend_allowed'),
                ]
                : null,
            'runtime_policy' => [
                'status_projection_is_read_only' => true,
                'invoker_may_prepare_implementation_boundary_when_called_with_signed_input' => true,
                'implementation_boundary_is_not_real_invoker_execution' => true,
                'executor_plan_required_before_execution' => true,
                'atlas_process_invocation_allowed_here' => false,
                'actual_process_start_allowed_here' => false,
                'provider_process_call_allowed_here' => false,
                'adapter_invocation_allowed_here' => false,
                'adapter_execution_allowed_here' => false,
                'dispatch_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'self_programming_allowed_here' => false,
            ],
            'next_required_slice' => $statusReady
                ? 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_executor_plan_gate_contract'
                : 'repair_one_shot_scheduler_tick_codex_real_invoker_post_start_implementation_boundary_gate_service_blockers',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_implementation_boundary_gate_status.v1',
            'status' => (string) $status['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_implementation_boundary_gate_status',
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
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_implementation_boundary_gate_status' => $status,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_implementation_boundary_gate_status_hash' => $this->stableHash($status),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_implementation_boundary_gate_status_does_not_call_implementation_boundary_gate',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_implementation_boundary_gate_status_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_implementation_boundary_gate_status_does_not_execute_adapter',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_implementation_boundary_gate_status_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_implementation_boundary_gate_status_does_not_dispatch_work',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_implementation_boundary_gate_status_does_not_enable_self_programming',
            ],
            'human_summary' => $statusReady
                ? 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start implementation boundary gate service is ready and inspectable; executor plan remains separate.'
                : 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start implementation boundary gate service is blocked until invoker, generic boundary, signed release and storage are ready.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerRuntimeExecutionGate(array $options = []): array
    {
        $schedulerPolicyPayload = $this->agentAutomaticDispatchSchedulerPolicy($options);
        $dispatchPreflightPayload = $this->agentDispatchPreflight($options);
        $executorPreflightPayload = $this->agentDispatchExecutorPreflight($options);
        $runtimeTables = $this->agentControlPlaneRuntimeTables();

        $componentReadiness = [
            'automatic_dispatch_scheduler_policy' => data_get($schedulerPolicyPayload, 'status') === 'agent_automatic_dispatch_scheduler_policy_ready',
            'wakeup_items_table' => $runtimeTables['atlas_self_construction_agent_wakeup_items'],
            'dispatch_receipts_table' => $runtimeTables['atlas_self_construction_agent_dispatch_receipts'],
            'agent_runs_table' => $runtimeTables['atlas_self_construction_agent_runs'],
            'dispatch_preflight_contract_available' => in_array(data_get($dispatchPreflightPayload, 'status'), ['agent_dispatch_preflight_ready', 'blocked'], true),
            'dispatch_executor_preflight_contract_available' => in_array(data_get($executorPreflightPayload, 'status'), ['agent_dispatch_executor_preflight_ready', 'blocked'], true),
        ];
        $blockingReasons = array_values(array_map(
            static fn (string $component): string => $component.'_not_ready',
            array_keys(array_filter($componentReadiness, static fn (bool $ready): bool => ! $ready))
        ));

        $gate = [
            'status' => $blockingReasons === [] ? 'agent_automatic_dispatch_scheduler_runtime_execution_gate_ready' : 'blocked',
            'gate_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-RUNTIME-EXECUTION-GATE-SELF-CONSTRUCTION-0001',
            'parent_program' => 'Atlas Self-Construction OS',
            'submodule' => 'Atlas Agent Control Plane',
            'source_policy_hash' => data_get($schedulerPolicyPayload, 'agent_automatic_dispatch_scheduler_policy_hash'),
            'component_readiness' => $componentReadiness,
            'component_preflight_hashes' => [
                'automatic_dispatch_scheduler_policy' => data_get($schedulerPolicyPayload, 'agent_automatic_dispatch_scheduler_policy_hash'),
                'dispatch_preflight' => data_get($dispatchPreflightPayload, 'dispatch_preflight_hash'),
                'dispatch_executor_preflight' => data_get($executorPreflightPayload, 'dispatch_executor_preflight_hash'),
            ],
            'blocking_count' => count($blockingReasons),
            'blocking_reasons' => $blockingReasons,
            'required_runtime_release_receipt' => [
                'decision' => 'approve_scheduler_dry_run_tick_once',
                'signed_by' => 'human_or_atlas_kernel_after_policy_review',
                'scope' => 'one_tick_max_one_wakeup_item_no_provider_start',
                'expires_required' => true,
                'receipt_hash_required' => true,
            ],
            'runtime_tick_contract' => [
                'max_wakeup_items_per_tick' => 1,
                'allowed_tick_mode_after_future_release' => 'dry_run_only',
                'allowed_mutations_after_future_release' => [
                    'claim_one_ready_wakeup_item_atomically',
                    'prepare_dispatch_preflight',
                    'create_or_request_signed_dispatch_receipt',
                    'append_scheduler_decision_evidence',
                ],
                'forbidden_mutations_even_after_dry_run_release' => [
                    'start_provider_process',
                    'invoke_provider_adapter',
                    'mark_dispatch_receipt_used',
                    'mark_packet_completed',
                    'merge_or_apply_work_products',
                ],
            ],
            'gate_quality_guards' => [
                'runtime_release_must_be_one_shot',
                'scheduler_tick_must_be_idempotent',
                'tick_must_stop_before_provider_start',
                'tick_must_emit_evidence_before_any_future_dispatch',
                'tick_must_refuse_stale_wakeup_or_expired_receipt',
                'human_override_required_for_provider_start',
            ],
            'allowed_now' => [
                'runtime_execution_gate_projection',
                'scheduler_policy_readiness_check',
                'dispatch_preflight_contract_reference',
                'executor_preflight_contract_reference',
            ],
            'forbidden_now' => [
                'run_scheduler_tick',
                'claim_wakeup_items',
                'write_or_sign_dispatch_receipts',
                'mark_receipts_used',
                'start_or_supervise_provider_process',
                'invoke_provider_adapters',
                'self_program_or_self_merge',
            ],
            'activation_policy' => [
                'gate_is_read_only' => true,
                'scheduler_runtime_execution_allowed_here' => false,
                'dry_run_tick_allowed_here' => false,
                'runtime_write_allowed_here' => false,
                'provider_start_allowed_here' => false,
                'requires_future_signed_one_shot_dry_run_tick_release' => true,
            ],
            'next_required_slice' => $blockingReasons === []
                ? 'activate_automatic_dispatch_scheduler_dry_run_tick'
                : 'repair_automatic_dispatch_scheduler_runtime_execution_gate_blockers',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_runtime_execution_gate.v1',
            'status' => (string) $gate['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_runtime_execution_gate',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'scheduler_runtime_execution_allowed' => false,
            'dry_run_tick_allowed' => false,
            'provider_start_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_runtime_execution_gate' => $gate,
            'agent_automatic_dispatch_scheduler_runtime_execution_gate_hash' => $this->stableHash($gate),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_runtime_execution_gate_does_not_run_scheduler_tick',
                'agent_automatic_dispatch_scheduler_runtime_execution_gate_does_not_claim_wakeup_items',
                'agent_automatic_dispatch_scheduler_runtime_execution_gate_does_not_write_dispatch_receipts',
                'agent_automatic_dispatch_scheduler_runtime_execution_gate_does_not_start_providers',
                'agent_automatic_dispatch_scheduler_runtime_execution_gate_does_not_enable_self_programming',
            ],
            'human_summary' => $blockingReasons === []
                ? 'Automatic dispatch scheduler runtime execution gate is ready: Atlas has the one-shot dry-run release contract, but scheduler runtime remains disabled until a signed dry-run tick release.'
                : 'Automatic dispatch scheduler runtime execution gate is blocked until every runtime prerequisite is ready.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickReleaseReceiptValidationPreflight(array $options = []): array
    {
        $draftPayload = $this->agentAutomaticDispatchSchedulerOneShotTickReleaseReceiptDraft($options);
        $draft = (array) data_get($draftPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_release_receipt_draft', []);
        $receiptSubject = (array) data_get($draft, 'receipt_subject', []);
        $unsignedReceiptPayload = (array) data_get($draft, 'unsigned_receipt_payload', []);
        $draftHash = (string) data_get($draftPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_release_receipt_draft_hash');
        $signatureProvided = (string) data_get($unsignedReceiptPayload, 'signed_by', '') !== ''
            && (string) data_get($unsignedReceiptPayload, 'signed_at', '') !== ''
            && (string) data_get($unsignedReceiptPayload, 'expires_at', '') !== '';
        $selectedWakeupKeyPresent = (string) data_get($receiptSubject, 'selected_wakeup_key', '') !== '';
        $dispatchEnvelopeHashPresent = (string) data_get($receiptSubject, 'dispatch_envelope_hash', '') !== '';
        $scopeHashPresent = (string) data_get($unsignedReceiptPayload, 'scope_hash', '') !== '';
        $sourceReleaseTemplateHashPresent = (string) data_get($draft, 'source_release_template_hash', '') !== '';
        $sourcePreflightReady = data_get($draft, 'source_preflight_status') === 'agent_automatic_dispatch_scheduler_one_shot_tick_writer_preflight_ready';

        $preflightChecks = [
            'draft_available' => data_get($draftPayload, 'status') === 'agent_automatic_dispatch_scheduler_one_shot_tick_release_receipt_draft_ready',
            'draft_hash_present' => $draftHash !== '',
            'source_release_template_hash_present' => $sourceReleaseTemplateHashPresent,
            'source_preflight_ready' => $sourcePreflightReady,
            'signature_present_and_fresh' => $signatureProvided,
            'selected_wakeup_key_present' => $selectedWakeupKeyPresent,
            'dispatch_envelope_hash_present' => $dispatchEnvelopeHashPresent,
            'scope_hash_present' => $scopeHashPresent,
            'decision_matches_one_shot_scheduler_release' => data_get($draft, 'receipt_decision.decision') === 'approve_scheduler_claim_and_receipt_once',
            'max_wakeup_items_equals_one' => data_get($draft, 'receipt_decision.max_wakeup_items') === 1,
            'max_dispatch_receipts_equals_one' => data_get($draft, 'receipt_decision.max_dispatch_receipts') === 1,
            'provider_start_forbidden' => data_get($draft, 'receipt_decision.provider_start_allowed') === false,
            'receipt_use_forbidden' => data_get($draft, 'receipt_decision.receipt_use_allowed') === false,
            'adapter_invocation_forbidden' => data_get($draft, 'receipt_decision.adapter_invocation_allowed') === false,
            'token_spend_forbidden' => data_get($draft, 'receipt_decision.token_spend_allowed') === false,
        ];
        $failedChecks = array_values(array_keys(array_filter(
            $preflightChecks,
            fn (bool $passed): bool => ! $passed,
        )));
        $blockingReasons = array_values(array_unique($failedChecks));

        $preflight = [
            'status' => $blockingReasons === [] ? 'release_receipt_validation_preflight_ready' : 'blocked',
            'blocking_count' => count($blockingReasons),
            'blocking_reasons' => $blockingReasons,
            'failed_preflight_checks' => $failedChecks,
            'source_release_receipt_draft_hash' => $draftHash,
            'source_release_template_hash' => data_get($draft, 'source_release_template_hash'),
            'source_preflight_status' => data_get($draft, 'source_preflight_status'),
            'preflight_checks' => $preflightChecks,
            'validation_decision' => [
                'ready_for_persistence_contract' => $blockingReasons === [],
                'persistence_allowed_here' => false,
                'signature_acceptance_allowed_here' => false,
                'runtime_mutation_allowed_here' => false,
                'claim_allowed_here' => false,
                'dispatch_receipt_write_allowed_here' => false,
                'provider_start_allowed_here' => false,
                'requires_future_signed_receipt' => true,
            ],
            'validated_subject' => [
                'selected_wakeup_key' => data_get($receiptSubject, 'selected_wakeup_key'),
                'packet_id' => data_get($receiptSubject, 'packet_id'),
                'provider' => data_get($receiptSubject, 'provider'),
                'dispatch_envelope_hash' => data_get($receiptSubject, 'dispatch_envelope_hash'),
            ],
            'required_before_persistence' => [
                'fresh_human_or_operator_signature',
                'selected_wakeup_key_from_current_wakeup_queue',
                'dispatch_envelope_hash_from_current_dry_run_tick',
                'source_release_template_hash_match',
                'source_release_receipt_draft_hash_match',
                'scope_hash_match',
                'decision_approve_scheduler_claim_and_receipt_once',
                'max_one_wakeup_claim',
                'max_one_dispatch_receipt',
                'provider_start_still_forbidden',
            ],
            'forbidden_now' => [
                'accept_signature',
                'persist_release_receipt',
                'claim_wakeup_item',
                'write_dispatch_receipt',
                'mark_dispatch_receipt_used',
                'start_provider_process',
                'invoke_provider_adapter',
                'spend_provider_tokens',
            ],
            'next_required_slice' => $blockingReasons === []
                ? 'activate_signed_one_shot_scheduler_tick_release_receipt_persistence_contract'
                : 'repair_signed_one_shot_scheduler_tick_release_receipt_validation_preflight_blockers',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_release_receipt_validation_preflight.v1',
            'status' => (string) $preflight['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_release_receipt_validation_preflight',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'signature_acceptance_allowed' => false,
            'release_receipt_persistence_allowed' => false,
            'claim_allowed' => false,
            'dispatch_receipt_write_allowed' => false,
            'provider_start_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_release_receipt_validation_preflight' => $preflight,
            'agent_automatic_dispatch_scheduler_one_shot_tick_release_receipt_validation_preflight_hash' => $this->stableHash($preflight),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_release_receipt_validation_preflight_does_not_accept_signatures',
                'agent_automatic_dispatch_scheduler_one_shot_tick_release_receipt_validation_preflight_does_not_persist_release_receipts',
                'agent_automatic_dispatch_scheduler_one_shot_tick_release_receipt_validation_preflight_does_not_claim_wakeup_items',
                'agent_automatic_dispatch_scheduler_one_shot_tick_release_receipt_validation_preflight_does_not_write_dispatch_receipts',
                'agent_automatic_dispatch_scheduler_one_shot_tick_release_receipt_validation_preflight_does_not_start_providers',
                'agent_automatic_dispatch_scheduler_one_shot_tick_release_receipt_validation_preflight_does_not_enable_self_programming',
            ],
            'human_summary' => $blockingReasons === []
                ? 'Automatic dispatch scheduler one-shot tick release receipt validation preflight is ready; Atlas can define the persistence contract without mutating runtime.'
                : 'Automatic dispatch scheduler one-shot tick release receipt validation preflight is blocked until signature, candidate and envelope evidence are complete.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartRealInvokerReleasePreflightGateStatus(array $options = []): array
    {
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerTableReady = Schema::hasTable('atlas_ledger_events');
        $gateReady = class_exists(AgentCodexRealInvokerPostStartRealInvokerReleasePreflightGate::class)
            && method_exists(AgentCodexRealInvokerPostStartRealInvokerReleasePreflightGate::class, 'recordPostStartRealInvokerReleasePreflight');
        $invokerReady = class_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartRealInvokerReleasePreflightGateInvoker::class)
            && method_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartRealInvokerReleasePreflightGateInvoker::class, 'recordCodexRealInvokerPostStartRealInvokerReleasePreflightGate');
        $releasePreflightReady = class_exists(AgentCodexRealInvokerReleasePreflight::class)
            && method_exists(AgentCodexRealInvokerReleasePreflight::class, 'recordPreflight');
        $dryRunGateReady = class_exists(AgentCodexRealInvokerPostStartExternalProcessInvokerDryRunGate::class)
            && method_exists(AgentCodexRealInvokerPostStartExternalProcessInvokerDryRunGate::class, 'preparePostStartExternalProcessInvokerDryRun');

        $observedPreflightRunsQuery = $runsTableReady
            ? AtlasSelfConstructionAgentRun::query()
                ->whereNotNull('metadata->codex_real_invoker_post_start_real_invoker_release_preflight->real_invoker_release_preflight_id')
            : null;
        $providerRunsWithPreflightQuery = $runsTableReady
            ? AtlasSelfConstructionAgentRun::query()
                ->where('run_key', 'like', 'provider-start:%')
                ->where('status', 'adapter_invocation_prepared')
                ->whereNotNull('metadata->codex_real_invoker_release_preflight->real_invoker_release_preflight_id')
            : null;
        $latestObservedPreflight = $observedPreflightRunsQuery === null
            ? null
            : (clone $observedPreflightRunsQuery)->latest('updated_at')->first();

        $statusReady = $runsTableReady
            && $ledgerTableReady
            && $gateReady
            && $invokerReady
            && $releasePreflightReady
            && $dryRunGateReady;
        $status = [
            'status' => $statusReady ? 'one_shot_tick_codex_real_invoker_post_start_real_invoker_release_preflight_gate_service_ready' : 'blocked',
            'provider' => 'codex',
            'adapter' => 'codex',
            'invoker_service' => AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartRealInvokerReleasePreflightGateInvoker::class,
            'invoker_service_ready' => $invokerReady,
            'invoker_canonical_method' => 'recordCodexRealInvokerPostStartRealInvokerReleasePreflightGate',
            'generic_post_start_real_invoker_release_preflight_gate_service' => AgentCodexRealInvokerPostStartRealInvokerReleasePreflightGate::class,
            'generic_post_start_real_invoker_release_preflight_gate_service_ready' => $gateReady,
            'generic_post_start_real_invoker_release_preflight_gate_canonical_method' => 'recordPostStartRealInvokerReleasePreflight',
            'codex_real_invoker_release_preflight_service' => AgentCodexRealInvokerReleasePreflight::class,
            'codex_real_invoker_release_preflight_service_ready' => $releasePreflightReady,
            'codex_real_invoker_release_preflight_canonical_method' => 'recordPreflight',
            'post_start_external_process_invoker_dry_run_gate_service' => AgentCodexRealInvokerPostStartExternalProcessInvokerDryRunGate::class,
            'post_start_external_process_invoker_dry_run_gate_ready' => $dryRunGateReady,
            'agent_runs_table_ready' => $runsTableReady,
            'ledger_table_ready' => $ledgerTableReady,
            'codex_real_invoker_post_start_real_invoker_release_preflight_run_count' => $observedPreflightRunsQuery === null ? null : (clone $observedPreflightRunsQuery)->count(),
            'provider_start_runs_with_codex_real_invoker_release_preflight_count' => $providerRunsWithPreflightQuery === null ? null : (clone $providerRunsWithPreflightQuery)->count(),
            'latest_codex_real_invoker_post_start_real_invoker_release_preflight' => $latestObservedPreflight instanceof AtlasSelfConstructionAgentRun
                ? [
                    'agent_run_id' => $latestObservedPreflight->id,
                    'run_key' => $latestObservedPreflight->run_key,
                    'packet_id' => $latestObservedPreflight->packet_id,
                    'provider' => $latestObservedPreflight->provider,
                    'status' => $latestObservedPreflight->status,
                    'post_start_real_invoker_release_preflight_gate_id' => data_get($latestObservedPreflight->metadata, 'codex_real_invoker_post_start_real_invoker_release_preflight.post_start_real_invoker_release_preflight_gate_id'),
                    'post_start_external_process_invoker_dry_run_gate_id' => data_get($latestObservedPreflight->metadata, 'codex_real_invoker_post_start_real_invoker_release_preflight.post_start_external_process_invoker_dry_run_gate_id'),
                    'real_invoker_release_preflight_id' => data_get($latestObservedPreflight->metadata, 'codex_real_invoker_post_start_real_invoker_release_preflight.real_invoker_release_preflight_id'),
                    'dry_run_id' => data_get($latestObservedPreflight->metadata, 'codex_real_invoker_post_start_real_invoker_release_preflight.dry_run_id'),
                    'invocation_authorization_id' => data_get($latestObservedPreflight->metadata, 'codex_real_invoker_post_start_real_invoker_release_preflight.invocation_authorization_id'),
                    'runtime_driver_id' => data_get($latestObservedPreflight->metadata, 'codex_real_invoker_post_start_real_invoker_release_preflight.runtime_driver_id'),
                    'post_start_evidence_acceptance_bridge_id' => data_get($latestObservedPreflight->metadata, 'codex_real_invoker_post_start_real_invoker_release_preflight.post_start_evidence_acceptance_bridge_id'),
                    'real_invoker_release_preflight_passed' => data_get($latestObservedPreflight->metadata, 'codex_real_invoker_post_start_real_invoker_release_preflight.real_invoker_release_preflight_passed'),
                    'signed_release_gate_required' => data_get($latestObservedPreflight->metadata, 'codex_real_invoker_post_start_real_invoker_release_preflight.signed_release_gate_required'),
                    'actual_process_start_allowed' => data_get($latestObservedPreflight->metadata, 'codex_real_invoker_post_start_real_invoker_release_preflight.actual_process_start_allowed'),
                    'token_spend_allowed' => data_get($latestObservedPreflight->metadata, 'codex_real_invoker_post_start_real_invoker_release_preflight.token_spend_allowed'),
                ]
                : null,
            'runtime_policy' => [
                'status_projection_is_read_only' => true,
                'invoker_may_record_release_preflight_when_called_with_signed_input' => true,
                'real_invoker_release_preflight_is_not_signed_release' => true,
                'signed_real_invoker_release_required_before_execution' => true,
                'atlas_process_invocation_allowed_here' => false,
                'actual_process_start_allowed_here' => false,
                'provider_process_call_allowed_here' => false,
                'adapter_invocation_allowed_here' => false,
                'adapter_execution_allowed_here' => false,
                'dispatch_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'self_programming_allowed_here' => false,
            ],
            'next_required_slice' => $statusReady
                ? 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_signed_real_invoker_release_gate_contract'
                : 'repair_one_shot_scheduler_tick_codex_real_invoker_post_start_real_invoker_release_preflight_gate_service_blockers',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_real_invoker_release_preflight_gate_status.v1',
            'status' => (string) $status['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_real_invoker_release_preflight_gate_status',
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
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_real_invoker_release_preflight_gate_status' => $status,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_real_invoker_release_preflight_gate_status_hash' => $this->stableHash($status),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_real_invoker_release_preflight_gate_status_does_not_call_release_preflight_gate',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_real_invoker_release_preflight_gate_status_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_real_invoker_release_preflight_gate_status_does_not_execute_adapter',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_real_invoker_release_preflight_gate_status_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_real_invoker_release_preflight_gate_status_does_not_dispatch_work',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_real_invoker_release_preflight_gate_status_does_not_enable_self_programming',
            ],
            'human_summary' => $statusReady
                ? 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start release preflight gate service is ready and inspectable; signed real invoker release remains separate.'
                : 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start release preflight gate service is blocked until invoker, generic gate, release preflight and storage are ready.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartSignedRealInvokerReleaseGateStatus(array $options = []): array
    {
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerTableReady = Schema::hasTable('atlas_ledger_events');
        $gateReady = class_exists(AgentCodexRealInvokerPostStartSignedRealInvokerReleaseGate::class)
            && method_exists(AgentCodexRealInvokerPostStartSignedRealInvokerReleaseGate::class, 'authorizePostStartSignedRealInvokerRelease');
        $invokerReady = class_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartSignedRealInvokerReleaseGateInvoker::class)
            && method_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartSignedRealInvokerReleaseGateInvoker::class, 'authorizeCodexRealInvokerPostStartSignedRealInvokerReleaseGate');
        $signedReleaseReady = class_exists(AgentCodexSignedRealInvokerReleaseGate::class)
            && method_exists(AgentCodexSignedRealInvokerReleaseGate::class, 'authorizeSignedRelease');
        $releasePreflightGateReady = class_exists(AgentCodexRealInvokerPostStartRealInvokerReleasePreflightGate::class)
            && method_exists(AgentCodexRealInvokerPostStartRealInvokerReleasePreflightGate::class, 'recordPostStartRealInvokerReleasePreflight');

        $observedSignedReleaseRunsQuery = $runsTableReady
            ? AtlasSelfConstructionAgentRun::query()
                ->whereNotNull('metadata->codex_real_invoker_post_start_signed_real_invoker_release->signed_real_invoker_release_id')
            : null;
        $providerRunsWithSignedReleaseQuery = $runsTableReady
            ? AtlasSelfConstructionAgentRun::query()
                ->where('run_key', 'like', 'provider-start:%')
                ->where('status', 'adapter_invocation_prepared')
                ->whereNotNull('metadata->codex_signed_real_invoker_release->signed_real_invoker_release_id')
            : null;
        $latestObservedSignedRelease = $observedSignedReleaseRunsQuery === null
            ? null
            : (clone $observedSignedReleaseRunsQuery)->latest('updated_at')->first();

        $statusReady = $runsTableReady
            && $ledgerTableReady
            && $gateReady
            && $invokerReady
            && $signedReleaseReady
            && $releasePreflightGateReady;
        $status = [
            'status' => $statusReady ? 'one_shot_tick_codex_real_invoker_post_start_signed_real_invoker_release_gate_service_ready' : 'blocked',
            'provider' => 'codex',
            'adapter' => 'codex',
            'invoker_service' => AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartSignedRealInvokerReleaseGateInvoker::class,
            'invoker_service_ready' => $invokerReady,
            'invoker_canonical_method' => 'authorizeCodexRealInvokerPostStartSignedRealInvokerReleaseGate',
            'generic_post_start_signed_real_invoker_release_gate_service' => AgentCodexRealInvokerPostStartSignedRealInvokerReleaseGate::class,
            'generic_post_start_signed_real_invoker_release_gate_service_ready' => $gateReady,
            'generic_post_start_signed_real_invoker_release_gate_canonical_method' => 'authorizePostStartSignedRealInvokerRelease',
            'codex_signed_real_invoker_release_gate_service' => AgentCodexSignedRealInvokerReleaseGate::class,
            'codex_signed_real_invoker_release_gate_ready' => $signedReleaseReady,
            'codex_signed_real_invoker_release_gate_canonical_method' => 'authorizeSignedRelease',
            'post_start_real_invoker_release_preflight_gate_service' => AgentCodexRealInvokerPostStartRealInvokerReleasePreflightGate::class,
            'post_start_real_invoker_release_preflight_gate_ready' => $releasePreflightGateReady,
            'agent_runs_table_ready' => $runsTableReady,
            'ledger_table_ready' => $ledgerTableReady,
            'codex_real_invoker_post_start_signed_real_invoker_release_run_count' => $observedSignedReleaseRunsQuery === null ? null : (clone $observedSignedReleaseRunsQuery)->count(),
            'provider_start_runs_with_codex_signed_real_invoker_release_count' => $providerRunsWithSignedReleaseQuery === null ? null : (clone $providerRunsWithSignedReleaseQuery)->count(),
            'latest_codex_real_invoker_post_start_signed_real_invoker_release' => $latestObservedSignedRelease instanceof AtlasSelfConstructionAgentRun
                ? [
                    'agent_run_id' => $latestObservedSignedRelease->id,
                    'run_key' => $latestObservedSignedRelease->run_key,
                    'packet_id' => $latestObservedSignedRelease->packet_id,
                    'provider' => $latestObservedSignedRelease->provider,
                    'status' => $latestObservedSignedRelease->status,
                    'post_start_signed_real_invoker_release_gate_id' => data_get($latestObservedSignedRelease->metadata, 'codex_real_invoker_post_start_signed_real_invoker_release.post_start_signed_real_invoker_release_gate_id'),
                    'post_start_real_invoker_release_preflight_gate_id' => data_get($latestObservedSignedRelease->metadata, 'codex_real_invoker_post_start_signed_real_invoker_release.post_start_real_invoker_release_preflight_gate_id'),
                    'signed_real_invoker_release_id' => data_get($latestObservedSignedRelease->metadata, 'codex_real_invoker_post_start_signed_real_invoker_release.signed_real_invoker_release_id'),
                    'real_invoker_release_preflight_id' => data_get($latestObservedSignedRelease->metadata, 'codex_real_invoker_post_start_signed_real_invoker_release.real_invoker_release_preflight_id'),
                    'dry_run_id' => data_get($latestObservedSignedRelease->metadata, 'codex_real_invoker_post_start_signed_real_invoker_release.dry_run_id'),
                    'codex_execution_id' => data_get($latestObservedSignedRelease->metadata, 'codex_real_invoker_post_start_signed_real_invoker_release.codex_execution_id'),
                    'post_start_evidence_acceptance_bridge_id' => data_get($latestObservedSignedRelease->metadata, 'codex_real_invoker_post_start_signed_real_invoker_release.post_start_evidence_acceptance_bridge_id'),
                    'signed_real_invoker_release_authorized' => data_get($latestObservedSignedRelease->metadata, 'codex_real_invoker_post_start_signed_real_invoker_release.signed_real_invoker_release_authorized'),
                    'implementation_boundary_required' => data_get($latestObservedSignedRelease->metadata, 'codex_real_invoker_post_start_signed_real_invoker_release.implementation_boundary_required'),
                    'actual_process_start_allowed' => data_get($latestObservedSignedRelease->metadata, 'codex_real_invoker_post_start_signed_real_invoker_release.actual_process_start_allowed'),
                    'token_spend_allowed' => data_get($latestObservedSignedRelease->metadata, 'codex_real_invoker_post_start_signed_real_invoker_release.token_spend_allowed'),
                ]
                : null,
            'runtime_policy' => [
                'status_projection_is_read_only' => true,
                'invoker_may_authorize_signed_release_when_called_with_signed_input' => true,
                'signed_real_invoker_release_gate_is_not_real_invoker_execution' => true,
                'implementation_boundary_required_before_execution' => true,
                'atlas_process_invocation_allowed_here' => false,
                'actual_process_start_allowed_here' => false,
                'provider_process_call_allowed_here' => false,
                'adapter_invocation_allowed_here' => false,
                'adapter_execution_allowed_here' => false,
                'dispatch_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'self_programming_allowed_here' => false,
            ],
            'next_required_slice' => $statusReady
                ? 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_implementation_boundary_gate_contract'
                : 'repair_one_shot_scheduler_tick_codex_real_invoker_post_start_signed_real_invoker_release_gate_service_blockers',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_signed_real_invoker_release_gate_status.v1',
            'status' => (string) $status['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_signed_real_invoker_release_gate_status',
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
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_signed_real_invoker_release_gate_status' => $status,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_signed_real_invoker_release_gate_status_hash' => $this->stableHash($status),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_signed_real_invoker_release_gate_status_does_not_call_signed_release_gate',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_signed_real_invoker_release_gate_status_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_signed_real_invoker_release_gate_status_does_not_execute_adapter',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_signed_real_invoker_release_gate_status_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_signed_real_invoker_release_gate_status_does_not_dispatch_work',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_signed_real_invoker_release_gate_status_does_not_enable_self_programming',
            ],
            'human_summary' => $statusReady
                ? 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start signed release gate service is ready and inspectable; implementation boundary remains separate.'
                : 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start signed release gate service is blocked until invoker, generic gate, signed release and storage are ready.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartExternalProcessInvokerDryRunGateStatus(array $options = []): array
    {
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerTableReady = Schema::hasTable('atlas_ledger_events');
        $gateReady = class_exists(AgentCodexRealInvokerPostStartExternalProcessInvokerDryRunGate::class)
            && method_exists(AgentCodexRealInvokerPostStartExternalProcessInvokerDryRunGate::class, 'preparePostStartExternalProcessInvokerDryRun');
        $invokerReady = class_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartExternalProcessInvokerDryRunGateInvoker::class)
            && method_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartExternalProcessInvokerDryRunGateInvoker::class, 'prepareCodexRealInvokerPostStartExternalProcessInvokerDryRunGate');
        $dryRunReady = class_exists(AgentCodexExternalProcessInvokerDryRun::class)
            && method_exists(AgentCodexExternalProcessInvokerDryRun::class, 'prepareDryRun');
        $authorizationGateReady = class_exists(AgentCodexRealInvokerPostStartProcessInvocationAuthorizationGate::class)
            && method_exists(AgentCodexRealInvokerPostStartProcessInvocationAuthorizationGate::class, 'authorizePostStartProcessInvocation');

        $observedDryRunRunsQuery = $runsTableReady
            ? AtlasSelfConstructionAgentRun::query()
                ->whereNotNull('metadata->codex_real_invoker_post_start_external_process_invoker_dry_run->dry_run_id')
            : null;
        $providerRunsWithDryRunQuery = $runsTableReady
            ? AtlasSelfConstructionAgentRun::query()
                ->where('run_key', 'like', 'provider-start:%')
                ->where('status', 'adapter_invocation_prepared')
                ->whereNotNull('metadata->codex_external_process_invoker_dry_run->dry_run_id')
            : null;
        $latestObservedDryRun = $observedDryRunRunsQuery === null
            ? null
            : (clone $observedDryRunRunsQuery)->latest('updated_at')->first();

        $statusReady = $runsTableReady
            && $ledgerTableReady
            && $gateReady
            && $invokerReady
            && $dryRunReady
            && $authorizationGateReady;
        $status = [
            'status' => $statusReady ? 'one_shot_tick_codex_real_invoker_post_start_external_process_invoker_dry_run_gate_service_ready' : 'blocked',
            'provider' => 'codex',
            'adapter' => 'codex',
            'invoker_service' => AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartExternalProcessInvokerDryRunGateInvoker::class,
            'invoker_service_ready' => $invokerReady,
            'invoker_canonical_method' => 'prepareCodexRealInvokerPostStartExternalProcessInvokerDryRunGate',
            'generic_post_start_external_process_invoker_dry_run_gate_service' => AgentCodexRealInvokerPostStartExternalProcessInvokerDryRunGate::class,
            'generic_post_start_external_process_invoker_dry_run_gate_service_ready' => $gateReady,
            'generic_post_start_external_process_invoker_dry_run_gate_canonical_method' => 'preparePostStartExternalProcessInvokerDryRun',
            'codex_external_process_invoker_dry_run_service' => AgentCodexExternalProcessInvokerDryRun::class,
            'codex_external_process_invoker_dry_run_service_ready' => $dryRunReady,
            'codex_external_process_invoker_dry_run_canonical_method' => 'prepareDryRun',
            'post_start_process_invocation_authorization_gate_service' => AgentCodexRealInvokerPostStartProcessInvocationAuthorizationGate::class,
            'post_start_process_invocation_authorization_gate_ready' => $authorizationGateReady,
            'agent_runs_table_ready' => $runsTableReady,
            'ledger_table_ready' => $ledgerTableReady,
            'codex_real_invoker_post_start_external_process_invoker_dry_run_run_count' => $observedDryRunRunsQuery === null ? null : (clone $observedDryRunRunsQuery)->count(),
            'provider_start_runs_with_codex_external_process_invoker_dry_run_count' => $providerRunsWithDryRunQuery === null ? null : (clone $providerRunsWithDryRunQuery)->count(),
            'latest_codex_real_invoker_post_start_external_process_invoker_dry_run' => $latestObservedDryRun instanceof AtlasSelfConstructionAgentRun
                ? [
                    'agent_run_id' => $latestObservedDryRun->id,
                    'run_key' => $latestObservedDryRun->run_key,
                    'packet_id' => $latestObservedDryRun->packet_id,
                    'provider' => $latestObservedDryRun->provider,
                    'status' => $latestObservedDryRun->status,
                    'post_start_external_process_invoker_dry_run_gate_id' => data_get($latestObservedDryRun->metadata, 'codex_real_invoker_post_start_external_process_invoker_dry_run.post_start_external_process_invoker_dry_run_gate_id'),
                    'post_start_process_invocation_authorization_gate_id' => data_get($latestObservedDryRun->metadata, 'codex_real_invoker_post_start_external_process_invoker_dry_run.post_start_process_invocation_authorization_gate_id'),
                    'dry_run_id' => data_get($latestObservedDryRun->metadata, 'codex_real_invoker_post_start_external_process_invoker_dry_run.dry_run_id'),
                    'invocation_authorization_id' => data_get($latestObservedDryRun->metadata, 'codex_real_invoker_post_start_external_process_invoker_dry_run.invocation_authorization_id'),
                    'runtime_driver_id' => data_get($latestObservedDryRun->metadata, 'codex_real_invoker_post_start_external_process_invoker_dry_run.runtime_driver_id'),
                    'post_start_evidence_acceptance_bridge_id' => data_get($latestObservedDryRun->metadata, 'codex_real_invoker_post_start_external_process_invoker_dry_run.post_start_evidence_acceptance_bridge_id'),
                    'external_process_invoker_dry_run_prepared' => data_get($latestObservedDryRun->metadata, 'codex_real_invoker_post_start_external_process_invoker_dry_run.external_process_invoker_dry_run_prepared'),
                    'real_invoker_execution_gate_required' => data_get($latestObservedDryRun->metadata, 'codex_real_invoker_post_start_external_process_invoker_dry_run.real_invoker_execution_gate_required'),
                    'actual_process_start_allowed' => data_get($latestObservedDryRun->metadata, 'codex_real_invoker_post_start_external_process_invoker_dry_run.actual_process_start_allowed'),
                    'token_spend_allowed' => data_get($latestObservedDryRun->metadata, 'codex_real_invoker_post_start_external_process_invoker_dry_run.token_spend_allowed'),
                ]
                : null,
            'runtime_policy' => [
                'status_projection_is_read_only' => true,
                'invoker_may_prepare_dry_run_when_called_with_signed_input' => true,
                'external_process_invoker_dry_run_is_not_real_invoker_execution' => true,
                'real_invoker_release_preflight_required_before_execution' => true,
                'atlas_process_invocation_allowed_here' => false,
                'actual_process_start_allowed_here' => false,
                'provider_process_call_allowed_here' => false,
                'adapter_invocation_allowed_here' => false,
                'adapter_execution_allowed_here' => false,
                'dispatch_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'self_programming_allowed_here' => false,
            ],
            'next_required_slice' => $statusReady
                ? 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_real_invoker_release_preflight_gate_contract'
                : 'repair_one_shot_scheduler_tick_codex_real_invoker_post_start_external_process_invoker_dry_run_gate_service_blockers',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_external_process_invoker_dry_run_gate_status.v1',
            'status' => (string) $status['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_external_process_invoker_dry_run_gate_status',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'post_start_external_process_invoker_dry_run_gate_allowed' => false,
            'actual_process_start_allowed' => false,
            'provider_process_call_allowed' => false,
            'adapter_invocation_allowed' => false,
            'adapter_execution_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_external_process_invoker_dry_run_gate_status' => $status,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_external_process_invoker_dry_run_gate_status_hash' => $this->stableHash($status),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_external_process_invoker_dry_run_gate_status_does_not_call_dry_run_gate',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_external_process_invoker_dry_run_gate_status_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_external_process_invoker_dry_run_gate_status_does_not_execute_adapter',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_external_process_invoker_dry_run_gate_status_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_external_process_invoker_dry_run_gate_status_does_not_dispatch_work',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_external_process_invoker_dry_run_gate_status_does_not_enable_self_programming',
            ],
            'human_summary' => $statusReady
                ? 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start external process invoker dry-run gate service is ready and inspectable; real invoker release preflight remains separate.'
                : 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start external process invoker dry-run gate service is blocked until invoker, generic gate, dry-run and storage are ready.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartFinalProcessStartAuthorizationGatePreflight(array $options = []): array
    {
        $contractPayload = $this->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartFinalProcessStartAuthorizationGateContract($options);
        $contract = (array) data_get($contractPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_final_process_start_authorization_gate_contract', []);
        $contractHash = (string) data_get($contractPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_final_process_start_authorization_gate_contract_hash');
        $gateReady = class_exists(AgentCodexRealInvokerPostStartFinalProcessStartAuthorizationGate::class)
            && method_exists(AgentCodexRealInvokerPostStartFinalProcessStartAuthorizationGate::class, 'authorizePostStartFinalProcessStart');
        $invokerReady = class_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartFinalProcessStartAuthorizationGateInvoker::class)
            && method_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartFinalProcessStartAuthorizationGateInvoker::class, 'prepareCodexRealInvokerPostStartFinalProcessStartAuthorizationGate');
        $authorizationReady = class_exists(AgentCodexRealInvokerFinalProcessStartAuthorizationGate::class)
            && method_exists(AgentCodexRealInvokerFinalProcessStartAuthorizationGate::class, 'authorizeFinalStart');
        $guardedReady = class_exists(AgentCodexRealInvokerPostStartGuardedProcessStartExecutorGate::class)
            && method_exists(AgentCodexRealInvokerPostStartGuardedProcessStartExecutorGate::class, 'preparePostStartGuardedProcessStart');
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerTableReady = Schema::hasTable('atlas_ledger_events');

        $checks = [
            'post_start_final_process_start_authorization_gate_contract_ready' => data_get($contractPayload, 'status') === 'one_shot_tick_codex_real_invoker_post_start_final_process_start_authorization_gate_contract_ready',
            'post_start_final_process_start_authorization_gate_contract_hash_present' => $contractHash !== '',
            'post_start_guarded_process_start_executor_gate_status_ready' => data_get($contract, 'source_codex_real_invoker_post_start_guarded_process_start_executor_gate_status') === 'one_shot_tick_codex_real_invoker_post_start_guarded_process_start_executor_gate_service_ready',
            'generic_post_start_final_process_start_authorization_gate_template_ready' => data_get($contract, 'source_codex_real_invoker_post_start_final_process_start_authorization_gate_contract_status') === 'codex_real_invoker_post_start_final_process_start_authorization_gate_contract_template_ready',
            'generic_final_process_start_authorization_gate_template_ready' => data_get($contract, 'source_codex_real_invoker_final_process_start_authorization_gate_contract_status') === 'codex_real_invoker_final_process_start_authorization_gate_contract_template_ready',
            'codex_real_invoker_post_start_final_process_start_authorization_gate_ready' => $gateReady,
            'codex_real_invoker_post_start_final_process_start_authorization_gate_invoker_ready' => $invokerReady,
            'codex_real_invoker_final_process_start_authorization_gate_ready' => $authorizationReady,
            'codex_real_invoker_post_start_guarded_process_start_executor_gate_ready' => $guardedReady,
            'canonical_post_start_final_process_start_authorization_gate_method_ready' => data_get($contract, 'final_process_start_authorization.canonical_post_start_final_process_start_authorization_gate_method') === 'authorizePostStartFinalProcessStart',
            'scheduler_invoker_method_ready' => data_get($contract, 'final_process_start_authorization.scheduler_invoker_method') === 'prepareCodexRealInvokerPostStartFinalProcessStartAuthorizationGate',
            'contract_requires_post_start_guarded_process_start' => data_get($contract, 'final_process_start_authorization.post_start_guarded_process_start_required_before_final_authorization') === true,
            'contract_requires_post_start_evidence_acceptance_bridge' => data_get($contract, 'final_process_start_authorization.post_start_evidence_acceptance_bridge_required_before_final_authorization') === true,
            'contract_requires_provider_start_guarded_process_start' => data_get($contract, 'final_process_start_authorization.provider_start_guarded_process_start_required_before_final_authorization') === true,
            'contract_delegates_to_codex_real_invoker_final_process_start_authorization_gate' => data_get($contract, 'final_process_start_authorization.gate_delegates_to_codex_real_invoker_final_process_start_authorization_gate') === true,
            'contract_declares_final_authorization_is_not_actual_process_start' => data_get($contract, 'final_process_start_authorization.final_authorization_is_not_actual_process_start') === true,
            'contract_requires_actual_process_start_rehearsal_after_final_authorization' => in_array('execute_actual_process_start_without_separate_rehearsal_contract', (array) data_get($contract, 'forbidden_even_after_contract', []), true),
            'contract_authorizes_final_process_start' => data_get($contract, 'final_process_start_authorization.final_process_start_authorized_by_contract') === true,
            'contract_keeps_actual_process_start_disabled' => data_get($contract, 'final_process_start_authorization.actual_process_start_allowed_by_contract') === false,
            'contract_keeps_adapter_execution_disabled' => data_get($contract, 'final_process_start_authorization.adapter_execution_allowed_by_contract') === false,
            'contract_does_not_dispatch_work' => data_get($contract, 'final_process_start_authorization.dispatch_allowed_by_contract') === false,
            'contract_does_not_spend_tokens' => data_get($contract, 'final_process_start_authorization.token_spend_allowed_by_contract') === false,
            'contract_lists_runtime_enabling_flags_as_forbidden' => in_array('actual_process_start_allowed', (array) data_get($contract, 'forbidden_true_input_flags', []), true)
                && in_array('dispatch_allowed', (array) data_get($contract, 'forbidden_true_input_flags', []), true)
                && in_array('self_programming_allowed', (array) data_get($contract, 'forbidden_true_input_flags', []), true),
            'agent_runs_table_ready' => $runsTableReady,
            'ledger_table_ready' => $ledgerTableReady,
            'self_programming_forbidden' => in_array('enable_self_programming', (array) data_get($contract, 'forbidden_even_after_contract', []), true),
        ];
        $blockingReasons = array_values(array_keys(array_filter($checks, static fn (bool $passed): bool => ! $passed)));

        $preflight = [
            'status' => $blockingReasons === [] ? 'one_shot_tick_codex_real_invoker_post_start_final_process_start_authorization_gate_preflight_ready' : 'blocked',
            'preflight_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-REAL-INVOKER-POST-START-FINAL-PROCESS-START-AUTHORIZATION-GATE-PREFLIGHT-SELF-CONSTRUCTION-0001',
            'source_post_start_final_process_start_authorization_gate_contract_hash' => $contractHash,
            'preflight_checks' => $checks,
            'blocking_count' => count($blockingReasons),
            'blocking_reasons' => $blockingReasons,
            'implementation_requirements' => [
                'create_scheduler_specific_post_start_final_process_start_authorization_gate_invoker',
                'delegate_to_agent_codex_real_invoker_post_start_final_process_start_authorization_gate',
                'require_post_start_guarded_process_start_metadata',
                'require_post_start_evidence_acceptance_bridge',
                'require_operator_final_start_receipt_hash',
                'require_final_start_signature_hash',
                'require_final_start_policy_hash',
                'require_final_start_window_hash',
                'require_final_start_replay_guard_hash',
                'require_final_start_kill_switch_hash',
                'reject_caller_inputs_that_try_to_enable_runtime_flags',
                'project_readiness_status_without_invoking_codex',
            ],
            'runtime_policy' => [
                'preflight_is_read_only' => true,
                'post_start_final_process_start_authorization_gate_call_allowed_by_future_invoker' => true,
                'final_process_start_authorized_after_future_invoker' => true,
                'actual_process_start_rehearsal_required_after_final_authorization' => true,
                'actual_process_start_allowed_here' => false,
                'provider_process_call_allowed_here' => false,
                'executor_enabled_here' => false,
                'process_start_armed_here' => false,
                'adapter_invocation_allowed_here' => false,
                'adapter_execution_allowed_here' => false,
                'dispatch_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'self_programming_allowed_here' => false,
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_final_process_start_authorization_gate_implementation_packet',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_final_process_start_authorization_gate_preflight.v1',
            'status' => (string) $preflight['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_final_process_start_authorization_gate_preflight',
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
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_final_process_start_authorization_gate_preflight' => $preflight,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_final_process_start_authorization_gate_preflight_hash' => $this->stableHash($preflight),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_final_process_start_authorization_gate_preflight_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_final_process_start_authorization_gate_preflight_does_not_call_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_final_process_start_authorization_gate_preflight_does_not_execute_adapter',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_final_process_start_authorization_gate_preflight_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_final_process_start_authorization_gate_preflight_does_not_dispatch_work',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_final_process_start_authorization_gate_preflight_does_not_enable_self_programming',
            ],
            'human_summary' => $blockingReasons === []
                ? 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start final process start authorization gate preflight is ready; actual process start rehearsal remains separate.'
                : 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start final process start authorization gate preflight is blocked until guarded process start, generic final authorization gate, storage and no-runtime prerequisites are ready.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartActualProcessStartRehearsalGatePreflight(array $options = []): array
    {
        $contractPayload = $this->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartActualProcessStartRehearsalGateContract($options);
        $contract = (array) data_get($contractPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_actual_process_start_rehearsal_gate_contract', []);
        $contractHash = (string) data_get($contractPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_actual_process_start_rehearsal_gate_contract_hash');
        $gateReady = class_exists(AgentCodexRealInvokerPostStartActualProcessStartRehearsalGate::class)
            && method_exists(AgentCodexRealInvokerPostStartActualProcessStartRehearsalGate::class, 'rehearsePostStartActualProcessStart');
        $invokerReady = class_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartActualProcessStartRehearsalGateInvoker::class)
            && method_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartActualProcessStartRehearsalGateInvoker::class, 'prepareCodexRealInvokerPostStartActualProcessStartRehearsalGate');
        $rehearsalExecutorReady = class_exists(AgentCodexRealInvokerActualProcessStartRehearsalExecutor::class)
            && method_exists(AgentCodexRealInvokerActualProcessStartRehearsalExecutor::class, 'rehearseActualStart');
        $finalAuthorizationReady = class_exists(AgentCodexRealInvokerPostStartFinalProcessStartAuthorizationGate::class)
            && method_exists(AgentCodexRealInvokerPostStartFinalProcessStartAuthorizationGate::class, 'authorizePostStartFinalProcessStart');
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerTableReady = Schema::hasTable('atlas_ledger_events');

        $checks = [
            'post_start_actual_process_start_rehearsal_gate_contract_ready' => data_get($contractPayload, 'status') === 'one_shot_tick_codex_real_invoker_post_start_actual_process_start_rehearsal_gate_contract_ready',
            'post_start_actual_process_start_rehearsal_gate_contract_hash_present' => $contractHash !== '',
            'post_start_final_process_start_authorization_gate_status_ready' => data_get($contract, 'source_codex_real_invoker_post_start_final_process_start_authorization_gate_status') === 'one_shot_tick_codex_real_invoker_post_start_final_process_start_authorization_gate_service_ready',
            'generic_post_start_actual_process_start_rehearsal_gate_template_ready' => data_get($contract, 'source_codex_real_invoker_post_start_actual_process_start_rehearsal_gate_contract_status') === 'codex_real_invoker_post_start_actual_process_start_rehearsal_gate_contract_template_ready',
            'generic_actual_process_start_rehearsal_executor_template_ready' => data_get($contract, 'source_codex_real_invoker_actual_process_start_rehearsal_executor_contract_status') === 'codex_real_invoker_actual_process_start_rehearsal_executor_contract_template_ready',
            'codex_real_invoker_post_start_actual_process_start_rehearsal_gate_ready' => $gateReady,
            'codex_real_invoker_post_start_actual_process_start_rehearsal_gate_invoker_ready' => $invokerReady,
            'codex_real_invoker_actual_process_start_rehearsal_executor_ready' => $rehearsalExecutorReady,
            'codex_real_invoker_post_start_final_process_start_authorization_gate_ready' => $finalAuthorizationReady,
            'canonical_post_start_actual_process_start_rehearsal_gate_method_ready' => data_get($contract, 'actual_process_start_rehearsal.canonical_post_start_actual_process_start_rehearsal_gate_method') === 'rehearsePostStartActualProcessStart',
            'scheduler_invoker_method_ready' => data_get($contract, 'actual_process_start_rehearsal.scheduler_invoker_method') === 'prepareCodexRealInvokerPostStartActualProcessStartRehearsalGate',
            'contract_requires_post_start_final_process_start_authorization' => data_get($contract, 'actual_process_start_rehearsal.post_start_final_process_start_authorization_required_before_rehearsal') === true,
            'contract_requires_post_start_evidence_acceptance_bridge' => data_get($contract, 'actual_process_start_rehearsal.post_start_evidence_acceptance_bridge_required_before_rehearsal') === true,
            'contract_requires_provider_start_final_authorization' => data_get($contract, 'actual_process_start_rehearsal.provider_start_final_authorization_required_before_rehearsal') === true,
            'contract_delegates_to_codex_real_invoker_actual_process_start_rehearsal_executor' => data_get($contract, 'actual_process_start_rehearsal.gate_delegates_to_codex_real_invoker_actual_process_start_rehearsal_executor') === true,
            'contract_declares_rehearsal_is_not_actual_process_start' => data_get($contract, 'actual_process_start_rehearsal.rehearsal_is_not_actual_process_start') === true,
            'contract_requires_process_start_envelope_after_rehearsal' => in_array('start_actual_process_without_separate_envelope_contract', (array) data_get($contract, 'forbidden_even_after_contract', []), true),
            'contract_marks_process_start_rehearsed_by_contract' => data_get($contract, 'actual_process_start_rehearsal.process_start_rehearsed_by_contract') === true,
            'contract_keeps_actual_process_start_disabled' => data_get($contract, 'actual_process_start_rehearsal.actual_process_start_allowed_by_contract') === false,
            'contract_keeps_adapter_execution_disabled' => data_get($contract, 'actual_process_start_rehearsal.adapter_execution_allowed_by_contract') === false,
            'contract_does_not_dispatch_work' => data_get($contract, 'actual_process_start_rehearsal.dispatch_allowed_by_contract') === false,
            'contract_does_not_spend_tokens' => data_get($contract, 'actual_process_start_rehearsal.token_spend_allowed_by_contract') === false,
            'contract_lists_runtime_enabling_flags_as_forbidden' => in_array('actual_process_start_allowed', (array) data_get($contract, 'forbidden_true_input_flags', []), true)
                && in_array('dispatch_allowed', (array) data_get($contract, 'forbidden_true_input_flags', []), true)
                && in_array('self_programming_allowed', (array) data_get($contract, 'forbidden_true_input_flags', []), true),
            'agent_runs_table_ready' => $runsTableReady,
            'ledger_table_ready' => $ledgerTableReady,
            'self_programming_forbidden' => in_array('enable_self_programming', (array) data_get($contract, 'forbidden_even_after_contract', []), true),
        ];
        $blockingReasons = array_values(array_keys(array_filter($checks, static fn (bool $passed): bool => ! $passed)));

        $preflight = [
            'status' => $blockingReasons === [] ? 'one_shot_tick_codex_real_invoker_post_start_actual_process_start_rehearsal_gate_preflight_ready' : 'blocked',
            'preflight_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-REAL-INVOKER-POST-START-ACTUAL-PROCESS-START-REHEARSAL-GATE-PREFLIGHT-SELF-CONSTRUCTION-0001',
            'source_post_start_actual_process_start_rehearsal_gate_contract_hash' => $contractHash,
            'preflight_checks' => $checks,
            'blocking_count' => count($blockingReasons),
            'blocking_reasons' => $blockingReasons,
            'implementation_requirements' => [
                'create_scheduler_specific_post_start_actual_process_start_rehearsal_gate_invoker',
                'delegate_to_agent_codex_real_invoker_post_start_actual_process_start_rehearsal_gate',
                'require_post_start_final_process_start_authorization_metadata',
                'require_post_start_evidence_acceptance_bridge',
                'require_process_start_rehearsal_hash',
                'require_command_resolution_hash',
                'require_environment_resolution_hash',
                'require_cwd_verification_hash',
                'require_supervisor_dry_run_hash',
                'require_liveness_probe_rehearsal_hash',
                'reject_caller_inputs_that_try_to_enable_runtime_flags',
                'project_readiness_status_without_invoking_codex',
            ],
            'runtime_policy' => [
                'preflight_is_read_only' => true,
                'post_start_actual_process_start_rehearsal_gate_call_allowed_by_future_invoker' => true,
                'process_start_rehearsed_after_future_invoker' => true,
                'process_start_envelope_required_after_rehearsal' => true,
                'actual_process_start_allowed_here' => false,
                'provider_process_call_allowed_here' => false,
                'executor_enabled_here' => false,
                'process_start_armed_here' => false,
                'adapter_invocation_allowed_here' => false,
                'adapter_execution_allowed_here' => false,
                'dispatch_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'self_programming_allowed_here' => false,
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_actual_process_start_rehearsal_gate_implementation_packet',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_actual_process_start_rehearsal_gate_preflight.v1',
            'status' => (string) $preflight['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_actual_process_start_rehearsal_gate_preflight',
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
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_actual_process_start_rehearsal_gate_preflight' => $preflight,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_actual_process_start_rehearsal_gate_preflight_hash' => $this->stableHash($preflight),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_actual_process_start_rehearsal_gate_preflight_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_actual_process_start_rehearsal_gate_preflight_does_not_call_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_actual_process_start_rehearsal_gate_preflight_does_not_execute_adapter',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_actual_process_start_rehearsal_gate_preflight_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_actual_process_start_rehearsal_gate_preflight_does_not_dispatch_work',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_actual_process_start_rehearsal_gate_preflight_does_not_enable_self_programming',
            ],
            'human_summary' => $blockingReasons === []
                ? 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start actual process start rehearsal gate preflight is ready; process start envelope remains separate.'
                : 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start actual process start rehearsal gate preflight is blocked until final authorization, rehearsal executor, storage and no-runtime prerequisites are ready.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerPolicy(array $options = []): array
    {
        $workProductPolicyPayload = $this->agentAutomaticWorkProductCollectionPolicy($options);
        $wakeupSchedulerPayload = $this->agentWakeupScheduler($options);
        $runtimeTables = $this->agentControlPlaneRuntimeTables();

        $componentReadiness = [
            'automatic_work_product_collection_policy' => data_get($workProductPolicyPayload, 'status') === 'agent_automatic_work_product_collection_policy_ready',
            'wakeup_scheduler_projection' => data_get($wakeupSchedulerPayload, 'status') === 'agent_wakeup_scheduler_ready',
            'agent_runs_table' => $runtimeTables['atlas_self_construction_agent_runs'],
            'wakeup_items_table' => $runtimeTables['atlas_self_construction_agent_wakeup_items'],
            'dispatch_receipts_table' => $runtimeTables['atlas_self_construction_agent_dispatch_receipts'],
            'dispatch_preflight_contract' => method_exists($this, 'agentDispatchPreflight'),
            'dispatch_receipt_writer' => method_exists($this, 'agentDispatchReceiptWrite'),
            'dispatch_executor_preflight_contract' => method_exists($this, 'agentDispatchExecutorPreflight'),
        ];
        $blockingReasons = array_values(array_map(
            static fn (string $component): string => $component.'_not_ready',
            array_keys(array_filter($componentReadiness, static fn (bool $ready): bool => ! $ready))
        ));

        $policy = [
            'status' => $blockingReasons === [] ? 'agent_automatic_dispatch_scheduler_policy_ready' : 'blocked',
            'policy_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-POLICY-SELF-CONSTRUCTION-0001',
            'parent_program' => 'Atlas Self-Construction OS',
            'submodule' => 'Atlas Agent Control Plane',
            'component_readiness' => $componentReadiness,
            'component_preflight_hashes' => [
                'automatic_work_product_collection_policy' => data_get($workProductPolicyPayload, 'agent_automatic_work_product_collection_policy_hash'),
                'wakeup_scheduler' => data_get($wakeupSchedulerPayload, 'wakeup_scheduler_hash'),
            ],
            'blocking_count' => count($blockingReasons),
            'blocking_reasons' => $blockingReasons,
            'scheduler_selection_contract' => [
                'eligible_item_status' => 'queued',
                'required_order' => ['priority', 'scheduled_for', 'created_at'],
                'required_identity' => ['wakeup_key', 'packet_id', 'provider', 'actor', 'reason'],
                'required_guards' => [
                    'one_active_claim_per_wakeup_item',
                    'one_active_packet_reservation_per_packet',
                    'signed_dispatch_receipt_before_provider_start',
                    'dispatch_executor_preflight_before_adapter_invocation',
                    'liveness_heartbeat_before_and_after_dispatch',
                ],
            ],
            'dispatch_runtime_contract' => [
                'required_stage_order' => [
                    'select_ready_wakeup_item',
                    'claim_wakeup_item_atomically',
                    'prepare_dispatch_preflight',
                    'write_signed_dispatch_receipt_or_block_for_human',
                    'validate_receipt',
                    'prepare_executor_preflight',
                    'require_future_runtime_execution_gate',
                ],
                'dispatch_idempotency_key' => 'sha256(wakeup_key|packet_id|provider|signed_receipt_hash)',
                'max_dispatches_per_tick_default' => 1,
            ],
            'scheduler_quality_guards' => [
                'never_dispatch_without_signed_receipt',
                'never_start_provider_from_scheduler_policy',
                'never_claim_packet_outside_packet_scope',
                'never_reuse_raw_chat_history_as_provider_context',
                'stop_on_stale_liveness_or_expired_receipt',
                'record_dispatch_decision_as_evidence_before_runtime_release',
            ],
            'allowed_now' => [
                'automatic_dispatch_scheduler_policy_projection',
                'scheduler_prerequisite_readiness_check',
                'wakeup_queue_projection_reference',
                'dispatch_contract_reference',
            ],
            'forbidden_now' => [
                'claim_wakeup_items_automatically',
                'write_dispatch_receipts_automatically',
                'mark_receipts_used',
                'start_or_supervise_provider_process',
                'invoke_provider_adapters',
                'spend_provider_tokens',
                'self_program_or_self_merge',
            ],
            'activation_policy' => [
                'policy_is_read_only' => true,
                'automatic_dispatch_allowed_here' => false,
                'runtime_write_allowed_here' => false,
                'provider_start_allowed_here' => false,
                'requires_future_signed_dispatch_scheduler_runtime_execution_gate' => true,
            ],
            'next_required_slice' => $blockingReasons === []
                ? 'activate_automatic_dispatch_scheduler_dry_run_tick'
                : 'repair_automatic_dispatch_scheduler_policy_blockers',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_policy.v1',
            'status' => (string) $policy['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_policy',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'automatic_dispatch_allowed' => false,
            'provider_start_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_policy' => $policy,
            'agent_automatic_dispatch_scheduler_policy_hash' => $this->stableHash($policy),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_policy_does_not_claim_wakeup_items',
                'agent_automatic_dispatch_scheduler_policy_does_not_write_dispatch_receipts',
                'agent_automatic_dispatch_scheduler_policy_does_not_mark_receipts_used',
                'agent_automatic_dispatch_scheduler_policy_does_not_start_providers',
                'agent_automatic_dispatch_scheduler_policy_does_not_enable_self_programming',
            ],
            'human_summary' => $blockingReasons === []
                ? 'Automatic dispatch scheduler policy is ready: Atlas has the dispatch selection and guard contract, but automatic dispatch remains disabled until a signed runtime execution gate.'
                : 'Automatic dispatch scheduler policy is blocked until every scheduler prerequisite is ready.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerExecutorFreshReleaseGateContract(array $options = []): array
    {
        $executorPlanStatusPayload = $this->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerExecutorPlanStatus($options);
        $executorPlanStatus = (array) data_get($executorPlanStatusPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_plan_status', []);
        $freshReleasePayload = $this->agentCodexRealInvokerExecutorFreshReleaseGateContractTemplate($options);

        $contract = [
            'status' => 'one_shot_tick_codex_real_invoker_executor_fresh_release_gate_contract_ready',
            'contract_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-REAL-INVOKER-EXECUTOR-FRESH-RELEASE-GATE-CONTRACT-SELF-CONSTRUCTION-0001',
            'parent_program' => 'Atlas Self-Construction OS',
            'submodule' => 'Atlas Agent Control Plane',
            'provider' => 'codex',
            'adapter' => 'codex',
            'source_codex_real_invoker_executor_plan_status' => data_get($executorPlanStatus, 'status'),
            'source_codex_real_invoker_executor_plan_status_hash' => data_get($executorPlanStatusPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_plan_status_hash'),
            'source_codex_real_invoker_executor_fresh_release_gate_contract_status' => data_get($freshReleasePayload, 'status'),
            'source_codex_real_invoker_executor_fresh_release_gate_contract_hash' => data_get($freshReleasePayload, 'codex_real_invoker_executor_fresh_release_gate_contract_template_hash'),
            'release_boundary' => [
                'canonical_executor_fresh_release_gate' => AgentCodexRealInvokerExecutorFreshReleaseGate::class,
                'canonical_executor_fresh_release_gate_method' => 'authorizeFreshRelease',
                'scheduler_invoker' => AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerExecutorFreshReleaseGateInvoker::class,
                'scheduler_invoker_method' => 'authorizeCodexRealInvokerExecutorFreshRelease',
                'fresh_release_effect' => 'authorize_disabled_codex_real_invoker_executor_fresh_release_metadata_without_enabling_executor',
                'executor_enabled_by_fresh_release' => false,
                'external_process_started_by_fresh_release' => false,
                'provider_started_by_fresh_release' => false,
                'adapter_execution_allowed_by_fresh_release' => false,
                'token_spend_allowed_by_fresh_release' => false,
                'required_executor_plan_status_before_fresh_release' => 'real_invoker_executor_plan_prepared_disabled_pending_fresh_release',
                'prepared_status_after_fresh_release' => 'real_invoker_executor_fresh_release_authorized_pending_enablement',
                'idempotency_key' => 'real_invoker_executor_fresh_release_id',
            ],
            'required_input_fields_for_future_invoker' => [
                'run_key',
                'codex_execution_id',
                'process_start_release_id',
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
                'operator_fresh_release_receipt_hash',
                'plan_revalidation_report_hash',
                'freshness_window_hash',
                'final_human_signature_hash',
                'real_invoker_contract_hash',
                'release_policy_hash',
                'implementation_plan_hash',
                'executor_binary_contract_hash',
                'executor_observability_contract_hash',
                'process_command_hash',
                'environment_contract_hash',
                'termination_policy_hash',
                'stdout_stderr_sink_hash',
                'liveness_probe_hash',
                'rollback_plan_hash',
                'max_runtime_policy_hash',
                'actor',
                'session',
                'reason',
            ],
            'allowed_future_mutations' => [
                'write_codex_real_invoker_executor_fresh_release_metadata_on_agent_run',
                'append_codex_real_invoker_executor_fresh_release_authorized_evidence_event',
            ],
            'forbidden_even_after_contract' => [
                'enable_executor',
                'spawn_codex_process',
                'call_codex_cli_or_codex_app',
                'spawn_shell_or_subprocess',
                'spend_provider_tokens',
                'mark_run_running_or_terminal',
                'mark_packet_completed',
                'merge_work_products',
                'enable_self_programming',
            ],
            'handoff_policy' => [
                'fresh_release_is_authorization_not_execution' => true,
                'executor_enablement_requires_separate_contract' => true,
                'operator_fresh_release_receipt_hash_required' => true,
                'plan_revalidation_report_hash_required' => true,
                'freshness_window_hash_required' => true,
                'final_human_signature_hash_required' => true,
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_executor_fresh_release_gate_preflight',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_fresh_release_gate_contract.v1',
            'status' => (string) $contract['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_fresh_release_gate_contract',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_executor_fresh_release_gate_allowed' => false,
            'executor_enabled' => false,
            'adapter_execution_allowed' => false,
            'provider_start_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_fresh_release_gate_contract' => $contract,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_fresh_release_gate_contract_hash' => $this->stableHash($contract),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_fresh_release_gate_contract_does_not_authorize_fresh_release',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_fresh_release_gate_contract_does_not_enable_executor',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_fresh_release_gate_contract_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_fresh_release_gate_contract_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_fresh_release_gate_contract_does_not_enable_self_programming',
            ],
            'human_summary' => 'Automatic dispatch scheduler one-shot tick Codex real invoker executor fresh release gate contract is ready; it authorizes only the fresh release boundary and still cannot start Codex.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartFinalProcessSpawnExecutorGateStatus(array $options = []): array
    {
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerTableReady = Schema::hasTable('atlas_ledger_events');
        $gateReady = class_exists(AgentCodexRealInvokerPostStartFinalProcessSpawnExecutorGate::class)
            && method_exists(AgentCodexRealInvokerPostStartFinalProcessSpawnExecutorGate::class, 'preparePostStartFinalProcessSpawn');
        $invokerReady = class_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartFinalProcessSpawnExecutorGateInvoker::class)
            && method_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartFinalProcessSpawnExecutorGateInvoker::class, 'prepareCodexRealInvokerPostStartFinalProcessSpawnExecutorGate');
        $spawnExecutorReady = class_exists(AgentCodexProcessSpawnExecutor::class)
            && method_exists(AgentCodexProcessSpawnExecutor::class, 'prepareProcessSpawn');
        $spawnEnablementGateReady = class_exists(AgentCodexRealInvokerPostStartProcessSpawnEnablementGate::class)
            && method_exists(AgentCodexRealInvokerPostStartProcessSpawnEnablementGate::class, 'enablePostStartProcessSpawn');

        $observedFinalSpawnRunsQuery = $runsTableReady
            ? AtlasSelfConstructionAgentRun::query()
                ->whereNotNull('metadata->codex_real_invoker_post_start_final_process_spawn_executor->spawn_executor_id')
            : null;
        $providerRunsWithSpawnExecutorQuery = $runsTableReady
            ? AtlasSelfConstructionAgentRun::query()
                ->where('run_key', 'like', 'provider-start:%')
                ->where('status', 'adapter_invocation_prepared')
                ->whereNotNull('metadata->codex_process_spawn_executor->spawn_executor_id')
            : null;
        $latestObservedFinalSpawnRun = $observedFinalSpawnRunsQuery === null
            ? null
            : (clone $observedFinalSpawnRunsQuery)->latest('updated_at')->first();

        $statusReady = $runsTableReady
            && $ledgerTableReady
            && $gateReady
            && $invokerReady
            && $spawnExecutorReady
            && $spawnEnablementGateReady;
        $status = [
            'status' => $statusReady ? 'one_shot_tick_codex_real_invoker_post_start_final_process_spawn_executor_gate_service_ready' : 'blocked',
            'provider' => 'codex',
            'adapter' => 'codex',
            'invoker_service' => AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartFinalProcessSpawnExecutorGateInvoker::class,
            'invoker_service_ready' => $invokerReady,
            'invoker_canonical_method' => 'prepareCodexRealInvokerPostStartFinalProcessSpawnExecutorGate',
            'generic_post_start_final_process_spawn_executor_gate_service' => AgentCodexRealInvokerPostStartFinalProcessSpawnExecutorGate::class,
            'generic_post_start_final_process_spawn_executor_gate_service_ready' => $gateReady,
            'generic_post_start_final_process_spawn_executor_gate_canonical_method' => 'preparePostStartFinalProcessSpawn',
            'codex_process_spawn_executor_service' => AgentCodexProcessSpawnExecutor::class,
            'codex_process_spawn_executor_ready' => $spawnExecutorReady,
            'codex_process_spawn_executor_canonical_method' => 'prepareProcessSpawn',
            'post_start_process_spawn_enablement_gate_service' => AgentCodexRealInvokerPostStartProcessSpawnEnablementGate::class,
            'post_start_process_spawn_enablement_gate_ready' => $spawnEnablementGateReady,
            'agent_runs_table_ready' => $runsTableReady,
            'ledger_table_ready' => $ledgerTableReady,
            'codex_real_invoker_post_start_final_process_spawn_executor_prepared_run_count' => $observedFinalSpawnRunsQuery === null ? null : (clone $observedFinalSpawnRunsQuery)->count(),
            'provider_start_runs_with_codex_process_spawn_executor_count' => $providerRunsWithSpawnExecutorQuery === null ? null : (clone $providerRunsWithSpawnExecutorQuery)->count(),
            'latest_codex_real_invoker_post_start_final_process_spawn_executor_run' => $latestObservedFinalSpawnRun instanceof AtlasSelfConstructionAgentRun
                ? [
                    'agent_run_id' => $latestObservedFinalSpawnRun->id,
                    'run_key' => $latestObservedFinalSpawnRun->run_key,
                    'packet_id' => $latestObservedFinalSpawnRun->packet_id,
                    'provider' => $latestObservedFinalSpawnRun->provider,
                    'status' => $latestObservedFinalSpawnRun->status,
                    'post_start_final_process_spawn_executor_gate_id' => data_get($latestObservedFinalSpawnRun->metadata, 'codex_real_invoker_post_start_final_process_spawn_executor.post_start_final_process_spawn_executor_gate_id'),
                    'post_start_process_spawn_enablement_gate_id' => data_get($latestObservedFinalSpawnRun->metadata, 'codex_real_invoker_post_start_final_process_spawn_executor.post_start_process_spawn_enablement_gate_id'),
                    'spawn_executor_id' => data_get($latestObservedFinalSpawnRun->metadata, 'codex_real_invoker_post_start_final_process_spawn_executor.spawn_executor_id'),
                    'spawn_enablement_id' => data_get($latestObservedFinalSpawnRun->metadata, 'codex_real_invoker_post_start_final_process_spawn_executor.spawn_enablement_id'),
                    'post_start_evidence_acceptance_bridge_id' => data_get($latestObservedFinalSpawnRun->metadata, 'codex_real_invoker_post_start_final_process_spawn_executor.post_start_evidence_acceptance_bridge_id'),
                    'process_spawn_executor_prepared' => data_get($latestObservedFinalSpawnRun->metadata, 'codex_real_invoker_post_start_final_process_spawn_executor.process_spawn_executor_prepared'),
                    'external_process_runtime_required' => data_get($latestObservedFinalSpawnRun->metadata, 'codex_real_invoker_post_start_final_process_spawn_executor.external_process_runtime_required'),
                    'actual_process_start_allowed' => data_get($latestObservedFinalSpawnRun->metadata, 'codex_real_invoker_post_start_final_process_spawn_executor.actual_process_start_allowed'),
                    'token_spend_allowed' => data_get($latestObservedFinalSpawnRun->metadata, 'codex_real_invoker_post_start_final_process_spawn_executor.token_spend_allowed'),
                ]
                : null,
            'runtime_policy' => [
                'status_projection_is_read_only' => true,
                'invoker_may_prepare_final_process_spawn_executor_when_called_with_signed_input' => true,
                'final_process_spawn_executor_is_not_external_process_runtime' => true,
                'external_process_runtime_required_before_invocation' => true,
                'atlas_process_spawn_allowed_here' => false,
                'actual_process_start_allowed_here' => false,
                'provider_process_call_allowed_here' => false,
                'adapter_invocation_allowed_here' => false,
                'adapter_execution_allowed_here' => false,
                'dispatch_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'self_programming_allowed_here' => false,
            ],
            'next_required_slice' => $statusReady
                ? 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_external_process_runtime_gate_contract'
                : 'repair_one_shot_scheduler_tick_codex_real_invoker_post_start_final_process_spawn_executor_gate_service_blockers',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_final_process_spawn_executor_gate_status.v1',
            'status' => (string) $status['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_final_process_spawn_executor_gate_status',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'post_start_final_process_spawn_executor_gate_allowed' => false,
            'actual_process_start_allowed' => false,
            'provider_process_call_allowed' => false,
            'adapter_invocation_allowed' => false,
            'adapter_execution_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_final_process_spawn_executor_gate_status' => $status,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_final_process_spawn_executor_gate_status_hash' => $this->stableHash($status),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_final_process_spawn_executor_gate_status_does_not_call_final_process_spawn_executor_gate',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_final_process_spawn_executor_gate_status_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_final_process_spawn_executor_gate_status_does_not_execute_adapter',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_final_process_spawn_executor_gate_status_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_final_process_spawn_executor_gate_status_does_not_dispatch_work',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_final_process_spawn_executor_gate_status_does_not_enable_self_programming',
            ],
            'human_summary' => $statusReady
                ? 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start final process spawn executor gate service is ready and inspectable; external process runtime remains separate.'
                : 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start final process spawn executor gate service is blocked until invoker, generic gate, process spawn executor and storage are ready.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartExternalProcessRuntimeGateStatus(array $options = []): array
    {
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerTableReady = Schema::hasTable('atlas_ledger_events');
        $gateReady = class_exists(AgentCodexRealInvokerPostStartExternalProcessRuntimeGate::class)
            && method_exists(AgentCodexRealInvokerPostStartExternalProcessRuntimeGate::class, 'preparePostStartExternalRuntime');
        $invokerReady = class_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartExternalProcessRuntimeGateInvoker::class)
            && method_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartExternalProcessRuntimeGateInvoker::class, 'prepareCodexRealInvokerPostStartExternalProcessRuntimeGate');
        $externalRuntimeDriverReady = class_exists(AgentCodexExternalProcessRuntimeDriver::class)
            && method_exists(AgentCodexExternalProcessRuntimeDriver::class, 'prepareExternalRuntime');
        $finalSpawnGateReady = class_exists(AgentCodexRealInvokerPostStartFinalProcessSpawnExecutorGate::class)
            && method_exists(AgentCodexRealInvokerPostStartFinalProcessSpawnExecutorGate::class, 'preparePostStartFinalProcessSpawn');

        $observedRuntimeRunsQuery = $runsTableReady
            ? AtlasSelfConstructionAgentRun::query()
                ->whereNotNull('metadata->codex_real_invoker_post_start_external_process_runtime->runtime_driver_id')
            : null;
        $providerRunsWithRuntimeQuery = $runsTableReady
            ? AtlasSelfConstructionAgentRun::query()
                ->where('run_key', 'like', 'provider-start:%')
                ->where('status', 'adapter_invocation_prepared')
                ->whereNotNull('metadata->codex_external_process_runtime_driver->runtime_driver_id')
            : null;
        $latestObservedRuntimeRun = $observedRuntimeRunsQuery === null
            ? null
            : (clone $observedRuntimeRunsQuery)->latest('updated_at')->first();

        $statusReady = $runsTableReady
            && $ledgerTableReady
            && $gateReady
            && $invokerReady
            && $externalRuntimeDriverReady
            && $finalSpawnGateReady;
        $status = [
            'status' => $statusReady ? 'one_shot_tick_codex_real_invoker_post_start_external_process_runtime_gate_service_ready' : 'blocked',
            'provider' => 'codex',
            'adapter' => 'codex',
            'invoker_service' => AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartExternalProcessRuntimeGateInvoker::class,
            'invoker_service_ready' => $invokerReady,
            'invoker_canonical_method' => 'prepareCodexRealInvokerPostStartExternalProcessRuntimeGate',
            'generic_post_start_external_process_runtime_gate_service' => AgentCodexRealInvokerPostStartExternalProcessRuntimeGate::class,
            'generic_post_start_external_process_runtime_gate_service_ready' => $gateReady,
            'generic_post_start_external_process_runtime_gate_canonical_method' => 'preparePostStartExternalRuntime',
            'codex_external_process_runtime_driver_service' => AgentCodexExternalProcessRuntimeDriver::class,
            'codex_external_process_runtime_driver_ready' => $externalRuntimeDriverReady,
            'codex_external_process_runtime_driver_canonical_method' => 'prepareExternalRuntime',
            'post_start_final_process_spawn_executor_gate_service' => AgentCodexRealInvokerPostStartFinalProcessSpawnExecutorGate::class,
            'post_start_final_process_spawn_executor_gate_ready' => $finalSpawnGateReady,
            'agent_runs_table_ready' => $runsTableReady,
            'ledger_table_ready' => $ledgerTableReady,
            'codex_real_invoker_post_start_external_process_runtime_prepared_run_count' => $observedRuntimeRunsQuery === null ? null : (clone $observedRuntimeRunsQuery)->count(),
            'provider_start_runs_with_codex_external_process_runtime_count' => $providerRunsWithRuntimeQuery === null ? null : (clone $providerRunsWithRuntimeQuery)->count(),
            'latest_codex_real_invoker_post_start_external_process_runtime_run' => $latestObservedRuntimeRun instanceof AtlasSelfConstructionAgentRun
                ? [
                    'agent_run_id' => $latestObservedRuntimeRun->id,
                    'run_key' => $latestObservedRuntimeRun->run_key,
                    'packet_id' => $latestObservedRuntimeRun->packet_id,
                    'provider' => $latestObservedRuntimeRun->provider,
                    'status' => $latestObservedRuntimeRun->status,
                    'post_start_external_process_runtime_gate_id' => data_get($latestObservedRuntimeRun->metadata, 'codex_real_invoker_post_start_external_process_runtime.post_start_external_process_runtime_gate_id'),
                    'post_start_final_process_spawn_executor_gate_id' => data_get($latestObservedRuntimeRun->metadata, 'codex_real_invoker_post_start_external_process_runtime.post_start_final_process_spawn_executor_gate_id'),
                    'runtime_driver_id' => data_get($latestObservedRuntimeRun->metadata, 'codex_real_invoker_post_start_external_process_runtime.runtime_driver_id'),
                    'spawn_executor_id' => data_get($latestObservedRuntimeRun->metadata, 'codex_real_invoker_post_start_external_process_runtime.spawn_executor_id'),
                    'post_start_evidence_acceptance_bridge_id' => data_get($latestObservedRuntimeRun->metadata, 'codex_real_invoker_post_start_external_process_runtime.post_start_evidence_acceptance_bridge_id'),
                    'external_runtime_driver_prepared' => data_get($latestObservedRuntimeRun->metadata, 'codex_real_invoker_post_start_external_process_runtime.external_runtime_driver_prepared'),
                    'process_invocation_required' => data_get($latestObservedRuntimeRun->metadata, 'codex_real_invoker_post_start_external_process_runtime.process_invocation_required'),
                    'actual_process_start_allowed' => data_get($latestObservedRuntimeRun->metadata, 'codex_real_invoker_post_start_external_process_runtime.actual_process_start_allowed'),
                    'token_spend_allowed' => data_get($latestObservedRuntimeRun->metadata, 'codex_real_invoker_post_start_external_process_runtime.token_spend_allowed'),
                ]
                : null,
            'runtime_policy' => [
                'status_projection_is_read_only' => true,
                'invoker_may_prepare_external_process_runtime_when_called_with_signed_input' => true,
                'external_process_runtime_is_not_process_invocation' => true,
                'process_invocation_authorization_required_before_invocation' => true,
                'atlas_process_invocation_allowed_here' => false,
                'actual_process_start_allowed_here' => false,
                'provider_process_call_allowed_here' => false,
                'adapter_invocation_allowed_here' => false,
                'adapter_execution_allowed_here' => false,
                'dispatch_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'self_programming_allowed_here' => false,
            ],
            'next_required_slice' => $statusReady
                ? 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_process_invocation_authorization_gate_contract'
                : 'repair_one_shot_scheduler_tick_codex_real_invoker_post_start_external_process_runtime_gate_service_blockers',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_external_process_runtime_gate_status.v1',
            'status' => (string) $status['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_external_process_runtime_gate_status',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'post_start_external_process_runtime_gate_allowed' => false,
            'actual_process_start_allowed' => false,
            'provider_process_call_allowed' => false,
            'adapter_invocation_allowed' => false,
            'adapter_execution_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_external_process_runtime_gate_status' => $status,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_external_process_runtime_gate_status_hash' => $this->stableHash($status),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_external_process_runtime_gate_status_does_not_call_external_process_runtime_gate',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_external_process_runtime_gate_status_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_external_process_runtime_gate_status_does_not_execute_adapter',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_external_process_runtime_gate_status_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_external_process_runtime_gate_status_does_not_dispatch_work',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_external_process_runtime_gate_status_does_not_enable_self_programming',
            ],
            'human_summary' => $statusReady
                ? 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start external process runtime gate service is ready and inspectable; process invocation authorization remains separate.'
                : 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start external process runtime gate service is blocked until invoker, generic gate, runtime driver and storage are ready.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProcessInvocationAuthorizationGateStatus(array $options = []): array
    {
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerTableReady = Schema::hasTable('atlas_ledger_events');
        $gateReady = class_exists(AgentCodexRealInvokerPostStartProcessInvocationAuthorizationGate::class)
            && method_exists(AgentCodexRealInvokerPostStartProcessInvocationAuthorizationGate::class, 'authorizePostStartProcessInvocation');
        $invokerReady = class_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProcessInvocationAuthorizationGateInvoker::class)
            && method_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProcessInvocationAuthorizationGateInvoker::class, 'authorizeCodexRealInvokerPostStartProcessInvocationAuthorizationGate');
        $authorizationGateReady = class_exists(AgentCodexExternalProcessInvocationAuthorizationGate::class)
            && method_exists(AgentCodexExternalProcessInvocationAuthorizationGate::class, 'authorizeExternalProcessInvocation');
        $externalRuntimeGateReady = class_exists(AgentCodexRealInvokerPostStartExternalProcessRuntimeGate::class)
            && method_exists(AgentCodexRealInvokerPostStartExternalProcessRuntimeGate::class, 'preparePostStartExternalRuntime');

        $observedAuthorizationRunsQuery = $runsTableReady
            ? AtlasSelfConstructionAgentRun::query()
                ->whereNotNull('metadata->codex_real_invoker_post_start_process_invocation_authorization->invocation_authorization_id')
            : null;
        $providerRunsWithAuthorizationQuery = $runsTableReady
            ? AtlasSelfConstructionAgentRun::query()
                ->where('run_key', 'like', 'provider-start:%')
                ->where('status', 'adapter_invocation_prepared')
                ->whereNotNull('metadata->codex_external_process_invocation_authorization->invocation_authorization_id')
            : null;
        $latestObservedAuthorizationRun = $observedAuthorizationRunsQuery === null
            ? null
            : (clone $observedAuthorizationRunsQuery)->latest('updated_at')->first();

        $statusReady = $runsTableReady
            && $ledgerTableReady
            && $gateReady
            && $invokerReady
            && $authorizationGateReady
            && $externalRuntimeGateReady;
        $status = [
            'status' => $statusReady ? 'one_shot_tick_codex_real_invoker_post_start_process_invocation_authorization_gate_service_ready' : 'blocked',
            'provider' => 'codex',
            'adapter' => 'codex',
            'invoker_service' => AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProcessInvocationAuthorizationGateInvoker::class,
            'invoker_service_ready' => $invokerReady,
            'invoker_canonical_method' => 'authorizeCodexRealInvokerPostStartProcessInvocationAuthorizationGate',
            'generic_post_start_process_invocation_authorization_gate_service' => AgentCodexRealInvokerPostStartProcessInvocationAuthorizationGate::class,
            'generic_post_start_process_invocation_authorization_gate_service_ready' => $gateReady,
            'generic_post_start_process_invocation_authorization_gate_canonical_method' => 'authorizePostStartProcessInvocation',
            'codex_external_process_invocation_authorization_gate_service' => AgentCodexExternalProcessInvocationAuthorizationGate::class,
            'codex_external_process_invocation_authorization_gate_ready' => $authorizationGateReady,
            'codex_external_process_invocation_authorization_gate_canonical_method' => 'authorizeExternalProcessInvocation',
            'post_start_external_process_runtime_gate_service' => AgentCodexRealInvokerPostStartExternalProcessRuntimeGate::class,
            'post_start_external_process_runtime_gate_ready' => $externalRuntimeGateReady,
            'agent_runs_table_ready' => $runsTableReady,
            'ledger_table_ready' => $ledgerTableReady,
            'codex_real_invoker_post_start_process_invocation_authorized_run_count' => $observedAuthorizationRunsQuery === null ? null : (clone $observedAuthorizationRunsQuery)->count(),
            'provider_start_runs_with_codex_external_process_invocation_authorization_count' => $providerRunsWithAuthorizationQuery === null ? null : (clone $providerRunsWithAuthorizationQuery)->count(),
            'latest_codex_real_invoker_post_start_process_invocation_authorization_run' => $latestObservedAuthorizationRun instanceof AtlasSelfConstructionAgentRun
                ? [
                    'agent_run_id' => $latestObservedAuthorizationRun->id,
                    'run_key' => $latestObservedAuthorizationRun->run_key,
                    'packet_id' => $latestObservedAuthorizationRun->packet_id,
                    'provider' => $latestObservedAuthorizationRun->provider,
                    'status' => $latestObservedAuthorizationRun->status,
                    'post_start_process_invocation_authorization_gate_id' => data_get($latestObservedAuthorizationRun->metadata, 'codex_real_invoker_post_start_process_invocation_authorization.post_start_process_invocation_authorization_gate_id'),
                    'post_start_external_process_runtime_gate_id' => data_get($latestObservedAuthorizationRun->metadata, 'codex_real_invoker_post_start_process_invocation_authorization.post_start_external_process_runtime_gate_id'),
                    'invocation_authorization_id' => data_get($latestObservedAuthorizationRun->metadata, 'codex_real_invoker_post_start_process_invocation_authorization.invocation_authorization_id'),
                    'runtime_driver_id' => data_get($latestObservedAuthorizationRun->metadata, 'codex_real_invoker_post_start_process_invocation_authorization.runtime_driver_id'),
                    'post_start_evidence_acceptance_bridge_id' => data_get($latestObservedAuthorizationRun->metadata, 'codex_real_invoker_post_start_process_invocation_authorization.post_start_evidence_acceptance_bridge_id'),
                    'external_process_invocation_authorized' => data_get($latestObservedAuthorizationRun->metadata, 'codex_real_invoker_post_start_process_invocation_authorization.external_process_invocation_authorized'),
                    'external_process_invoker_dry_run_required' => data_get($latestObservedAuthorizationRun->metadata, 'codex_real_invoker_post_start_process_invocation_authorization.external_process_invoker_dry_run_required'),
                    'actual_process_start_allowed' => data_get($latestObservedAuthorizationRun->metadata, 'codex_real_invoker_post_start_process_invocation_authorization.actual_process_start_allowed'),
                    'token_spend_allowed' => data_get($latestObservedAuthorizationRun->metadata, 'codex_real_invoker_post_start_process_invocation_authorization.token_spend_allowed'),
                ]
                : null,
            'runtime_policy' => [
                'status_projection_is_read_only' => true,
                'invoker_may_authorize_process_invocation_when_called_with_signed_input' => true,
                'process_invocation_authorization_is_not_process_invocation' => true,
                'external_process_invoker_dry_run_required_before_invocation' => true,
                'atlas_process_invocation_allowed_here' => false,
                'actual_process_start_allowed_here' => false,
                'provider_process_call_allowed_here' => false,
                'adapter_invocation_allowed_here' => false,
                'adapter_execution_allowed_here' => false,
                'dispatch_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'self_programming_allowed_here' => false,
            ],
            'next_required_slice' => $statusReady
                ? 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_external_process_invoker_dry_run_gate_contract'
                : 'repair_one_shot_scheduler_tick_codex_real_invoker_post_start_process_invocation_authorization_gate_service_blockers',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_invocation_authorization_gate_status.v1',
            'status' => (string) $status['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_invocation_authorization_gate_status',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'post_start_process_invocation_authorization_gate_allowed' => false,
            'actual_process_start_allowed' => false,
            'provider_process_call_allowed' => false,
            'adapter_invocation_allowed' => false,
            'adapter_execution_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_invocation_authorization_gate_status' => $status,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_invocation_authorization_gate_status_hash' => $this->stableHash($status),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_invocation_authorization_gate_status_does_not_call_authorization_gate',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_invocation_authorization_gate_status_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_invocation_authorization_gate_status_does_not_execute_adapter',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_invocation_authorization_gate_status_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_invocation_authorization_gate_status_does_not_dispatch_work',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_invocation_authorization_gate_status_does_not_enable_self_programming',
            ],
            'human_summary' => $statusReady
                ? 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start process invocation authorization gate service is ready and inspectable; external process invoker dry-run remains separate.'
                : 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start process invocation authorization gate service is blocked until invoker, generic gate, authorization gate and storage are ready.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProviderStartDriverGateContract(array $options = []): array
    {
        $receiptUseStatusPayload = $this->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartDispatchReceiptUseExecutorStatus($options);
        $receiptUseStatus = (array) data_get($receiptUseStatusPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_dispatch_receipt_use_executor_status', []);
        $providerStartPayload = $this->agentCodexRealInvokerPostStartProviderStartDriverGateContractTemplate($options);

        $contract = [
            'status' => 'one_shot_tick_codex_real_invoker_post_start_provider_start_driver_gate_contract_ready',
            'contract_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-REAL-INVOKER-POST-START-PROVIDER-START-DRIVER-GATE-CONTRACT-SELF-CONSTRUCTION-0001',
            'parent_program' => 'Atlas Self-Construction OS',
            'submodule' => 'Atlas Agent Control Plane',
            'provider' => 'codex',
            'adapter' => 'codex',
            'source_codex_real_invoker_post_start_dispatch_receipt_use_executor_status' => data_get($receiptUseStatus, 'status'),
            'source_codex_real_invoker_post_start_dispatch_receipt_use_executor_status_hash' => data_get($receiptUseStatusPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_dispatch_receipt_use_executor_status_hash'),
            'source_codex_real_invoker_post_start_provider_start_driver_gate_status' => data_get($providerStartPayload, 'status'),
            'source_codex_real_invoker_post_start_provider_start_driver_gate_hash' => data_get($providerStartPayload, 'codex_real_invoker_post_start_provider_start_driver_gate_contract_template_hash'),
            'provider_start_driver_boundary' => [
                'canonical_post_start_provider_start_driver_gate' => AgentCodexRealInvokerPostStartProviderStartDriverGate::class,
                'canonical_post_start_provider_start_driver_gate_method' => 'preparePostStartProviderStartDriver',
                'scheduler_invoker' => AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProviderStartDriverGateInvoker::class,
                'scheduler_invoker_method' => 'prepareCodexRealInvokerPostStartProviderStartDriverGate',
                'provider_start_driver_effect' => 'create_or_reuse_pre_start_guarded_provider_start_projection_after_receipt_use_without_spawning_codex',
                'post_start_dispatch_receipt_use_required_before_provider_start_driver' => true,
                'post_start_evidence_acceptance_bridge_required_before_provider_start_driver' => true,
                'signed_dispatch_authorization_required_before_provider_start_driver' => true,
                'sandbox_binding_required_before_provider_start_driver' => true,
                'executor_release_authorization_required_before_provider_start_driver' => true,
                'signed_dispatch_receipt_hash_required' => true,
                'executor_contract_hash_required' => true,
                'executor_release_authorization_hash_required' => true,
                'executor_handoff_packet_hash_required' => true,
                'executor_workspace_hash_required' => true,
                'executor_scope_lock_hash_required' => true,
                'pre_start_guarded_run_may_be_created_by_driver' => true,
                'pre_start_heartbeat_may_be_written_by_driver' => true,
                'observed_external_process_started_required_before_bridge' => true,
                'observed_provider_started_required_before_bridge' => true,
                'actual_process_start_allowed_by_contract' => false,
                'driver_provider_started_by_contract' => false,
                'provider_process_call_allowed_by_contract' => false,
                'adapter_invocation_allowed_by_contract' => false,
                'adapter_execution_allowed_by_contract' => false,
                'token_spend_allowed_by_contract' => false,
                'dispatch_allowed_by_contract' => false,
                'idempotency_key' => 'provider_start_attempt_id',
            ],
            'required_input_fields_for_future_invoker' => [
                'run_key',
                'provider_start_driver_gate_id',
                'provider_start_attempt_id',
                'dispatch_executor_handoff_id',
                'signed_dispatch_authorization_id',
                'post_start_evidence_acceptance_bridge_id',
                'signed_dispatch_receipt_hash',
                'executor_contract_hash',
                'executor_release_authorization_hash',
                'executor_handoff_packet_hash',
                'executor_workspace_hash',
                'executor_scope_lock_hash',
                'sandbox_binding_key',
                'command',
                'cwd',
                'actor',
                'session',
                'max_runtime_minutes',
                'max_cost_usd',
                'reason',
            ],
            'allowed_future_mutations' => [
                'create_or_reuse_pre_start_guarded_provider_start_projection_run',
                'write_pre_start_guard_heartbeat',
                'append_provider_start_driver_bridge_evidence_event',
                'record_codex_real_invoker_post_start_provider_start_driver_metadata_on_observed_run',
            ],
            'forbidden_even_after_contract' => [
                'spawn_codex_process',
                'call_codex_cli_or_codex_app',
                'dispatch_work_to_codex',
                'send_prompt_to_provider',
                'call_provider_process',
                'enable_adapter_invocation',
                'enable_adapter_execution',
                'spend_provider_tokens',
                'mark_observed_run_running_or_terminal',
                'enable_self_programming',
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_provider_start_driver_gate_preflight',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_provider_start_driver_gate_contract.v1',
            'status' => (string) $contract['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_provider_start_driver_gate_contract',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'post_start_provider_start_driver_gate_allowed' => false,
            'actual_process_start_allowed' => false,
            'adapter_invocation_allowed' => false,
            'adapter_execution_allowed' => false,
            'provider_start_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_provider_start_driver_gate_contract' => $contract,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_provider_start_driver_gate_contract_hash' => $this->stableHash($contract),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_provider_start_driver_gate_contract_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_provider_start_driver_gate_contract_does_not_call_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_provider_start_driver_gate_contract_does_not_enable_adapter_invocation',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_provider_start_driver_gate_contract_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_provider_start_driver_gate_contract_does_not_dispatch_work',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_provider_start_driver_gate_contract_does_not_enable_self_programming',
            ],
            'human_summary' => 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start provider start driver gate contract is ready; it bridges used receipt metadata to the provider start driver without starting Codex.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartSupervisedStartExecutorGateContract(array $options = []): array
    {
        $processStartReleasePayload = $this->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProcessStartReleaseGateStatus($options);
        $processStartReleaseStatus = (array) data_get($processStartReleasePayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_start_release_gate_status', []);
        $supervisedStartPayload = $this->agentCodexRealInvokerPostStartSupervisedStartExecutorGateContractTemplate($options);

        $contract = [
            'status' => 'one_shot_tick_codex_real_invoker_post_start_supervised_start_executor_gate_contract_ready',
            'contract_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-REAL-INVOKER-POST-START-SUPERVISED-START-EXECUTOR-GATE-CONTRACT-SELF-CONSTRUCTION-0001',
            'parent_program' => 'Atlas Self-Construction OS',
            'submodule' => 'Atlas Agent Control Plane',
            'provider' => 'codex',
            'adapter' => 'codex',
            'source_codex_real_invoker_post_start_process_start_release_gate_status' => data_get($processStartReleaseStatus, 'status'),
            'source_codex_real_invoker_post_start_process_start_release_gate_status_hash' => data_get($processStartReleasePayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_start_release_gate_status_hash'),
            'source_codex_real_invoker_post_start_supervised_start_executor_gate_status' => data_get($supervisedStartPayload, 'status'),
            'source_codex_real_invoker_post_start_supervised_start_executor_gate_hash' => data_get($supervisedStartPayload, 'codex_real_invoker_post_start_supervised_start_executor_gate_contract_template_hash'),
            'supervised_start' => [
                'canonical_post_start_supervised_start_executor_gate' => AgentCodexRealInvokerPostStartSupervisedStartExecutorGate::class,
                'canonical_post_start_supervised_start_executor_gate_method' => 'preparePostStartSupervisedStart',
                'scheduler_invoker' => AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartSupervisedStartExecutorGateInvoker::class,
                'scheduler_invoker_method' => 'prepareCodexRealInvokerPostStartSupervisedStartExecutorGate',
                'codex_supervised_start_executor' => AgentCodexSupervisedStartExecutor::class,
                'codex_supervised_start_executor_method' => 'prepareSupervisedStart',
                'supervised_start_effect' => 'prepare_codex_supervised_start_without_spawning_codex',
                'post_start_process_start_release_required_before_supervised_start' => true,
                'post_start_evidence_acceptance_bridge_required_before_supervised_start' => true,
                'provider_start_run_with_codex_process_start_release_required_before_supervised_start' => true,
                'stdout_stderr_sanitizer_hash_required' => true,
                'ready_probe_plan_hash_required' => true,
                'rollback_plan_hash_required' => true,
                'gate_delegates_to_codex_supervised_start_executor' => true,
                'gate_records_codex_supervised_start_metadata_on_provider_start_run' => true,
                'gate_records_bridge_metadata_on_observed_run' => true,
                'process_spawn_enablement_required_after_supervised_start' => true,
                'supervised_start_preparation_is_not_process_spawn' => true,
                'actual_process_start_allowed_by_contract' => false,
                'provider_process_call_allowed_by_contract' => false,
                'adapter_invocation_allowed_by_contract' => false,
                'adapter_execution_allowed_by_contract' => false,
                'token_spend_allowed_by_contract' => false,
                'dispatch_allowed_by_contract' => false,
                'idempotency_key' => 'supervised_start_id',
            ],
            'required_input_fields_for_future_invoker' => [
                'run_key',
                'post_start_supervised_start_gate_id',
                'post_start_process_start_release_gate_id',
                'process_start_release_id',
                'provider_execution_contract_gate_id',
                'codex_execution_id',
                'supervised_start_id',
                'adapter_execution_guard_gate_id',
                'execution_guard_id',
                'adapter_invocation_boundary_gate_id',
                'adapter_invocation_id',
                'provider_start_driver_gate_id',
                'provider_start_attempt_id',
                'post_start_evidence_acceptance_bridge_id',
                'signed_dispatch_receipt_hash',
                'operator_release_receipt_hash',
                'codex_execution_contract_hash',
                'stdout_stderr_sanitizer_hash',
                'ready_probe_plan_hash',
                'rollback_plan_hash',
                'actor',
                'session',
                'reason',
            ],
            'allowed_future_mutations' => [
                'record_codex_supervised_start_metadata_on_provider_start_run',
                'append_codex_supervised_start_preparation_evidence_event',
                'record_codex_real_invoker_post_start_supervised_start_metadata_on_observed_run',
            ],
            'forbidden_even_after_contract' => [
                'spawn_codex_process',
                'call_codex_cli_or_codex_app',
                'run_process_spawn_enablement',
                'dispatch_work_to_codex',
                'send_prompt_to_provider',
                'call_provider_process',
                'enable_adapter_execution',
                'spend_provider_tokens',
                'mark_observed_run_running_or_terminal',
                'enable_self_programming',
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_supervised_start_executor_gate_preflight',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_supervised_start_executor_gate_contract.v1',
            'status' => (string) $contract['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_supervised_start_executor_gate_contract',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'post_start_supervised_start_executor_gate_allowed' => false,
            'actual_process_start_allowed' => false,
            'provider_process_call_allowed' => false,
            'adapter_invocation_allowed' => false,
            'adapter_execution_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_supervised_start_executor_gate_contract' => $contract,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_supervised_start_executor_gate_contract_hash' => $this->stableHash($contract),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_supervised_start_executor_gate_contract_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_supervised_start_executor_gate_contract_does_not_call_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_supervised_start_executor_gate_contract_does_not_execute_adapter',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_supervised_start_executor_gate_contract_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_supervised_start_executor_gate_contract_does_not_dispatch_work',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_supervised_start_executor_gate_contract_does_not_enable_self_programming',
            ],
            'human_summary' => 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start supervised start executor gate contract is ready; it prepares supervised start and still does not spawn Codex.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProcessSpawnEnablementGateContract(array $options = []): array
    {
        $supervisedPayload = $this->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartSupervisedStartExecutorGateStatus($options);
        $supervisedStatus = (array) data_get($supervisedPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_supervised_start_executor_gate_status', []);
        $processSpawnEnablementPayload = $this->agentCodexRealInvokerPostStartProcessSpawnEnablementGateContractTemplate($options);

        $contract = [
            'status' => 'one_shot_tick_codex_real_invoker_post_start_process_spawn_enablement_gate_contract_ready',
            'contract_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-REAL-INVOKER-POST-START-PROCESS-SPAWN-ENABLEMENT-GATE-CONTRACT-SELF-CONSTRUCTION-0001',
            'parent_program' => 'Atlas Self-Construction OS',
            'submodule' => 'Atlas Agent Control Plane',
            'provider' => 'codex',
            'adapter' => 'codex',
            'source_codex_real_invoker_post_start_supervised_start_executor_gate_status' => data_get($supervisedStatus, 'status'),
            'source_codex_real_invoker_post_start_supervised_start_executor_gate_status_hash' => data_get($supervisedPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_supervised_start_executor_gate_status_hash'),
            'source_codex_real_invoker_post_start_process_spawn_enablement_gate_status' => data_get($processSpawnEnablementPayload, 'status'),
            'source_codex_real_invoker_post_start_process_spawn_enablement_gate_hash' => data_get($processSpawnEnablementPayload, 'codex_real_invoker_post_start_process_spawn_enablement_gate_contract_template_hash'),
            'process_spawn_enablement' => [
                'canonical_post_start_process_spawn_enablement_gate' => AgentCodexRealInvokerPostStartProcessSpawnEnablementGate::class,
                'canonical_post_start_process_spawn_enablement_gate_method' => 'enablePostStartProcessSpawn',
                'scheduler_invoker' => AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProcessSpawnEnablementGateInvoker::class,
                'scheduler_invoker_method' => 'enableCodexRealInvokerPostStartProcessSpawnEnablementGate',
                'codex_process_spawn_enablement_gate' => AgentCodexProcessSpawnEnablementGate::class,
                'codex_process_spawn_enablement_gate_method' => 'enableCodexProcessSpawn',
                'process_spawn_enablement_effect' => 'record_process_spawn_enablement_without_spawning_codex',
                'post_start_supervised_start_required_before_enablement' => true,
                'post_start_evidence_acceptance_bridge_required_before_enablement' => true,
                'provider_start_run_with_codex_supervised_start_required_before_enablement' => true,
                'operator_spawn_receipt_hash_required' => true,
                'supervised_start_contract_hash_required' => true,
                'gate_delegates_to_codex_process_spawn_enablement_gate' => true,
                'gate_records_codex_process_spawn_enablement_metadata_on_provider_start_run' => true,
                'gate_records_bridge_metadata_on_observed_run' => true,
                'final_process_spawn_executor_required_after_enablement' => true,
                'process_spawn_enablement_is_not_process_spawn' => true,
                'actual_process_start_allowed_by_contract' => false,
                'provider_process_call_allowed_by_contract' => false,
                'adapter_invocation_allowed_by_contract' => false,
                'adapter_execution_allowed_by_contract' => false,
                'token_spend_allowed_by_contract' => false,
                'dispatch_allowed_by_contract' => false,
                'idempotency_key' => 'spawn_enablement_id',
            ],
            'required_input_fields_for_future_invoker' => [
                'run_key',
                'post_start_process_spawn_enablement_gate_id',
                'post_start_supervised_start_gate_id',
                'post_start_process_start_release_gate_id',
                'process_start_release_id',
                'provider_execution_contract_gate_id',
                'codex_execution_id',
                'supervised_start_id',
                'spawn_enablement_id',
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
                'codex_execution_contract_hash',
                'supervised_start_contract_hash',
                'actor',
                'session',
                'reason',
            ],
            'allowed_future_mutations' => [
                'record_codex_process_spawn_enablement_metadata_on_provider_start_run',
                'append_codex_process_spawn_enablement_recorded_evidence_event',
                'record_codex_real_invoker_post_start_process_spawn_enablement_metadata_on_observed_run',
            ],
            'forbidden_even_after_contract' => [
                'spawn_codex_process',
                'call_codex_cli_or_codex_app',
                'run_final_process_spawn_executor',
                'dispatch_work_to_codex',
                'send_prompt_to_provider',
                'call_provider_process',
                'enable_adapter_execution',
                'spend_provider_tokens',
                'mark_observed_run_running_or_terminal',
                'enable_self_programming',
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_process_spawn_enablement_gate_preflight',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_spawn_enablement_gate_contract.v1',
            'status' => (string) $contract['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_spawn_enablement_gate_contract',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'post_start_process_spawn_enablement_gate_allowed' => false,
            'actual_process_start_allowed' => false,
            'provider_process_call_allowed' => false,
            'adapter_invocation_allowed' => false,
            'adapter_execution_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_spawn_enablement_gate_contract' => $contract,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_spawn_enablement_gate_contract_hash' => $this->stableHash($contract),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_spawn_enablement_gate_contract_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_spawn_enablement_gate_contract_does_not_call_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_spawn_enablement_gate_contract_does_not_execute_adapter',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_spawn_enablement_gate_contract_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_spawn_enablement_gate_contract_does_not_dispatch_work',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_spawn_enablement_gate_contract_does_not_enable_self_programming',
            ],
            'human_summary' => 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start process spawn enablement gate contract is ready; it records enablement and still does not spawn Codex.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickGuardedRuntimeInvocationImplementationPacket(array $options = []): array
    {
        $preflightPayload = $this->agentAutomaticDispatchSchedulerOneShotTickGuardedRuntimeInvocationPreflight($options);
        $preflight = (array) data_get($preflightPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_guarded_runtime_invocation_preflight', []);
        $preflightHash = (string) data_get($preflightPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_guarded_runtime_invocation_preflight_hash');

        $allowedFiles = [
            'app/Services/Ai/SelfConstruction/ControlPlane/AgentAutomaticDispatchSchedulerOneShotTickGuardedRuntimeInvoker.php',
            'tests/Feature/Ai/AtlasAiSelfConstructionAgentAutomaticDispatchSchedulerOneShotTickGuardedRuntimeInvokerTest.php',
            'app/Services/Ai/SelfConstruction/Readiness/AtlasSelfConstructionReadinessService.php',
            'app/Console/Commands/AtlasAiSelfConstructionCommand.php',
            'tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php',
            'docs/engineering-knowledge-base/self-construction/agent-control-plane-contract.md',
        ];

        $packet = [
            'status' => 'ready_for_scoped_one_shot_tick_guarded_runtime_invocation_implementation',
            'packet_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-GUARDED-RUNTIME-INVOCATION-IMPLEMENTATION-PACKET-SELF-CONSTRUCTION-0001',
            'parent_program' => 'Atlas Self-Construction OS',
            'submodule' => 'Atlas Agent Control Plane',
            'workspace_id' => 'FORGE-WORKSPACE-ATLAS-SELF-CONSTRUCTION-0001',
            'source_guarded_invocation_preflight_status' => data_get($preflight, 'status'),
            'source_guarded_invocation_preflight_hash' => $preflightHash,
            'allowed_files' => $allowedFiles,
            'tasks' => [
                [
                    'id' => 'T1',
                    'title' => 'Create guarded runtime invoker boundary',
                    'type' => 'service',
                    'acceptance' => 'Invoker validates input shape, calls AgentAutomaticDispatchSchedulerOneShotTickMutatingWriter exactly once and returns its result without using dispatch receipts.',
                ],
                [
                    'id' => 'T2',
                    'title' => 'Expose guarded invocation status/run surface without provider start',
                    'type' => 'projection_or_command',
                    'acceptance' => 'Surface can inspect or run the guarded invocation only with signed release inputs, and output still states provider_start_allowed=false.',
                ],
                [
                    'id' => 'T3',
                    'title' => 'Add focused guarded invoker tests',
                    'type' => 'test',
                    'acceptance' => 'Tests cover one successful invocation, idempotent retry, missing signature rejection and proof that receipt use/provider start/adapter invocation/token spend remain forbidden.',
                ],
                [
                    'id' => 'T4',
                    'title' => 'Update Agent Control Plane documentation',
                    'type' => 'documentation',
                    'acceptance' => 'Documentation describes the guarded invocation boundary as the only official runtime path into the one-shot mutating writer.',
                ],
            ],
            'task_count' => 4,
            'acceptance_criteria' => [
                'guarded_invoker_calls_mutating_writer_once_only',
                'guarded_invoker_returns_signed_pending_dispatch_receipt_result',
                'guarded_invoker_is_idempotent_by_receipt_hash',
                'guarded_invoker_never_uses_dispatch_receipt',
                'guarded_invoker_never_starts_provider_or_invokes_adapter',
                'guarded_invoker_never_spends_tokens_or_enables_self_programming',
            ],
            'required_gates' => [
                'php_lint_guarded_invoker',
                'dedicated_guarded_invoker_feature_tests',
                'mutating_writer_feature_tests',
                'focused_self_construction_command_tests',
                'architecture_validate',
                'docs_health',
                'git_diff_check',
            ],
            'implementation_policy' => [
                'implementation_allowed_by_packet' => true,
                'runtime_invocation_allowed_by_future_invoker' => true,
                'mutating_writer_call_allowed_by_future_invoker' => true,
                'dispatch_receipt_use_allowed_by_packet' => false,
                'provider_start_allowed_by_packet' => false,
                'adapter_invocation_allowed_by_packet' => false,
                'token_spend_allowed_by_packet' => false,
                'self_programming_allowed_by_packet' => false,
            ],
            'stop_conditions' => [
                'need_to_use_dispatch_receipt',
                'need_to_start_provider_or_call_adapter',
                'need_to_spend_provider_tokens',
                'need_to_mark_packet_completed',
                'need_to_enable_self_programming',
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_guarded_runtime_invocation_service',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_guarded_runtime_invocation_implementation_packet.v1',
            'status' => (string) $packet['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_guarded_runtime_invocation_implementation_packet',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'claim_allowed' => false,
            'dispatch_receipt_write_allowed' => false,
            'dispatch_receipt_use_allowed' => false,
            'provider_start_allowed' => false,
            'adapter_invocation_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_guarded_runtime_invocation_implementation_packet' => $packet,
            'agent_automatic_dispatch_scheduler_one_shot_tick_guarded_runtime_invocation_implementation_packet_hash' => $this->stableHash($packet),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_guarded_runtime_invocation_implementation_packet_does_not_call_mutating_writer',
                'agent_automatic_dispatch_scheduler_one_shot_tick_guarded_runtime_invocation_implementation_packet_does_not_claim_wakeup_items',
                'agent_automatic_dispatch_scheduler_one_shot_tick_guarded_runtime_invocation_implementation_packet_does_not_write_dispatch_receipts',
                'agent_automatic_dispatch_scheduler_one_shot_tick_guarded_runtime_invocation_implementation_packet_does_not_use_dispatch_receipts',
                'agent_automatic_dispatch_scheduler_one_shot_tick_guarded_runtime_invocation_implementation_packet_does_not_start_providers',
                'agent_automatic_dispatch_scheduler_one_shot_tick_guarded_runtime_invocation_implementation_packet_does_not_enable_self_programming',
            ],
            'human_summary' => 'Automatic dispatch scheduler one-shot tick guarded runtime invocation implementation packet is ready; it scopes the future official invoker that may call the mutating writer once and then stop before provider start.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartSignedDispatchAuthorizationGateContract(array $options = []): array
    {
        $dispatchReleaseStatusPayload = $this->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartDispatchReleaseGateStatus($options);
        $dispatchReleaseStatus = (array) data_get($dispatchReleaseStatusPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_dispatch_release_gate_status', []);
        $authorizationPayload = $this->agentCodexRealInvokerPostStartSignedDispatchAuthorizationGateContractTemplate($options);

        $contract = [
            'status' => 'one_shot_tick_codex_real_invoker_post_start_signed_dispatch_authorization_gate_contract_ready',
            'contract_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-REAL-INVOKER-POST-START-SIGNED-DISPATCH-AUTHORIZATION-GATE-CONTRACT-SELF-CONSTRUCTION-0001',
            'parent_program' => 'Atlas Self-Construction OS',
            'submodule' => 'Atlas Agent Control Plane',
            'provider' => 'codex',
            'adapter' => 'codex',
            'source_codex_real_invoker_post_start_dispatch_release_gate_status' => data_get($dispatchReleaseStatus, 'status'),
            'source_codex_real_invoker_post_start_dispatch_release_gate_status_hash' => data_get($dispatchReleaseStatusPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_dispatch_release_gate_status_hash'),
            'source_codex_real_invoker_post_start_signed_dispatch_authorization_gate_status' => data_get($authorizationPayload, 'status'),
            'source_codex_real_invoker_post_start_signed_dispatch_authorization_gate_hash' => data_get($authorizationPayload, 'codex_real_invoker_post_start_signed_dispatch_authorization_gate_contract_template_hash'),
            'release_boundary' => [
                'canonical_post_start_signed_dispatch_authorization_gate' => AgentCodexRealInvokerPostStartSignedDispatchAuthorizationGate::class,
                'canonical_post_start_signed_dispatch_authorization_gate_method' => 'authorizePostStartSignedDispatch',
                'scheduler_invoker' => AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartSignedDispatchAuthorizationGateInvoker::class,
                'scheduler_invoker_method' => 'authorizeCodexRealInvokerPostStartSignedDispatch',
                'gate_effect' => 'record_signed_dispatch_authorization_after_dispatch_release_without_dispatching',
                'post_start_dispatch_release_gate_required_before_authorization' => true,
                'post_start_evidence_acceptance_bridge_required_before_authorization' => true,
                'required_liveness_state' => 'alive',
                'signed_dispatch_receipt_hash_required' => true,
                'human_dispatch_signature_hash_required' => true,
                'signed_dispatch_policy_hash_required' => true,
                'dispatch_window_hash_required' => true,
                'dispatch_scope_hash_required' => true,
                'continuation_summary_hash_required' => true,
                'context_pack_hash_required' => true,
                'dispatch_replay_guard_hash_required' => true,
                'dispatch_kill_switch_hash_required' => true,
                'no_direct_provider_call_attestation_required' => true,
                'future_dispatch_authorized_by_contract' => true,
                'actual_process_start_allowed_by_contract' => false,
                'atlas_process_spawned_by_contract' => false,
                'provider_marked_started_by_contract' => true,
                'provider_process_call_allowed_by_contract' => false,
                'adapter_execution_allowed_by_contract' => false,
                'token_spend_allowed_by_contract' => false,
                'dispatch_allowed_by_contract' => false,
                'idempotency_key' => 'signed_dispatch_authorization_id',
            ],
            'required_input_fields_for_future_invoker' => [
                'run_key',
                'codex_execution_id',
                'manual_start_executor_receipt_id',
                'operator_start_handoff_id',
                'post_start_receipt_contract_id',
                'post_start_evidence_receipt_id',
                'post_start_evidence_acceptance_bridge_id',
                'post_start_liveness_monitor_id',
                'dispatch_release_gate_id',
                'signed_dispatch_authorization_id',
                'signed_dispatch_receipt_hash',
                'human_dispatch_signature_hash',
                'signed_dispatch_policy_hash',
                'dispatch_window_hash',
                'dispatch_scope_hash',
                'continuation_summary_hash',
                'context_pack_hash',
                'dispatch_replay_guard_hash',
                'dispatch_kill_switch_hash',
                'no_direct_provider_call_attestation_hash',
                'actor',
                'session',
                'reason',
            ],
            'allowed_future_mutations' => [
                'write_codex_real_invoker_post_start_signed_dispatch_authorization_metadata_on_agent_run',
                'append_codex_real_invoker_post_start_signed_dispatch_authorization_evidence_event',
                'mark_run_as_future_dispatch_authorized',
            ],
            'forbidden_even_after_contract' => [
                'spawn_codex_process',
                'call_codex_cli_or_codex_app',
                'dispatch_work_to_codex',
                'send_prompt_to_provider',
                'call_provider_process',
                'spend_provider_tokens',
                'mark_run_running_or_terminal',
                'use_signed_dispatch_receipt',
                'enable_self_programming',
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_signed_dispatch_authorization_gate_preflight',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_signed_dispatch_authorization_gate_contract.v1',
            'status' => (string) $contract['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_signed_dispatch_authorization_gate_contract',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'post_start_signed_dispatch_authorization_gate_allowed' => false,
            'actual_process_start_allowed' => false,
            'adapter_execution_allowed' => false,
            'provider_start_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_signed_dispatch_authorization_gate_contract' => $contract,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_signed_dispatch_authorization_gate_contract_hash' => $this->stableHash($contract),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_signed_dispatch_authorization_gate_contract_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_signed_dispatch_authorization_gate_contract_does_not_call_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_signed_dispatch_authorization_gate_contract_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_signed_dispatch_authorization_gate_contract_does_not_dispatch_work',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_signed_dispatch_authorization_gate_contract_does_not_enable_self_programming',
            ],
            'human_summary' => 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start signed dispatch authorization gate contract is ready; it records a signed future authorization without dispatching work.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerExecutorPlanContract(array $options = []): array
    {
        $boundaryStatusPayload = $this->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerImplementationBoundaryStatus($options);
        $boundaryStatus = (array) data_get($boundaryStatusPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_implementation_boundary_status', []);
        $executorPlanPayload = $this->agentCodexRealInvokerExecutorPlanContractTemplate($options);

        $contract = [
            'status' => 'one_shot_tick_codex_real_invoker_executor_plan_contract_ready',
            'contract_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-REAL-INVOKER-EXECUTOR-PLAN-CONTRACT-SELF-CONSTRUCTION-0001',
            'parent_program' => 'Atlas Self-Construction OS',
            'submodule' => 'Atlas Agent Control Plane',
            'provider' => 'codex',
            'adapter' => 'codex',
            'source_codex_real_invoker_implementation_boundary_status' => data_get($boundaryStatus, 'status'),
            'source_codex_real_invoker_implementation_boundary_status_hash' => data_get($boundaryStatusPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_implementation_boundary_status_hash'),
            'source_codex_real_invoker_executor_plan_contract_status' => data_get($executorPlanPayload, 'status'),
            'source_codex_real_invoker_executor_plan_contract_hash' => data_get($executorPlanPayload, 'codex_real_invoker_executor_plan_contract_template_hash'),
            'release_boundary' => [
                'canonical_executor_plan' => AgentCodexRealInvokerExecutorPlan::class,
                'canonical_executor_plan_method' => 'prepareExecutorPlan',
                'scheduler_invoker' => AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerExecutorPlanInvoker::class,
                'scheduler_invoker_method' => 'prepareCodexRealInvokerExecutorPlan',
                'executor_plan_effect' => 'prepare_disabled_codex_real_invoker_executor_plan_metadata_without_enabling_executor',
                'executor_enabled_by_executor_plan' => false,
                'external_process_started_by_executor_plan' => false,
                'provider_started_by_executor_plan' => false,
                'adapter_execution_allowed_by_executor_plan' => false,
                'token_spend_allowed_by_executor_plan' => false,
                'required_implementation_boundary_status_before_plan' => 'real_invoker_implementation_boundary_prepared_pending_executor',
                'prepared_status_after_executor_plan' => 'real_invoker_executor_plan_prepared_disabled_pending_fresh_release',
                'idempotency_key' => 'real_invoker_executor_plan_id',
            ],
            'required_input_fields_for_future_invoker' => [
                'run_key',
                'codex_execution_id',
                'process_start_release_id',
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
                'operator_executor_plan_receipt_hash',
                'real_invoker_contract_hash',
                'release_policy_hash',
                'implementation_plan_hash',
                'executor_binary_contract_hash',
                'executor_observability_contract_hash',
                'process_command_hash',
                'environment_contract_hash',
                'termination_policy_hash',
                'stdout_stderr_sink_hash',
                'liveness_probe_hash',
                'rollback_plan_hash',
                'max_runtime_policy_hash',
                'actor',
                'session',
                'reason',
            ],
            'allowed_future_mutations' => [
                'write_codex_real_invoker_executor_plan_metadata_on_agent_run',
                'append_codex_real_invoker_executor_plan_prepared_evidence_event',
            ],
            'forbidden_even_after_contract' => [
                'enable_executor',
                'spawn_codex_process',
                'call_codex_cli_or_codex_app',
                'spawn_shell_or_subprocess',
                'spend_provider_tokens',
                'mark_run_running_or_terminal',
                'mark_packet_completed',
                'merge_work_products',
                'enable_self_programming',
            ],
            'handoff_policy' => [
                'executor_plan_is_disabled_plan_not_execution' => true,
                'executor_fresh_release_requires_separate_contract' => true,
                'operator_executor_plan_receipt_hash_required' => true,
                'executor_binary_contract_hash_required' => true,
                'executor_observability_contract_hash_required' => true,
                'fresh_release_required_before_start' => true,
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_executor_plan_preflight',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_plan_contract.v1',
            'status' => (string) $contract['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_plan_contract',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_executor_plan_allowed' => false,
            'executor_enabled' => false,
            'adapter_execution_allowed' => false,
            'provider_start_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_plan_contract' => $contract,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_plan_contract_hash' => $this->stableHash($contract),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_plan_contract_does_not_prepare_executor_plan',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_plan_contract_does_not_enable_executor',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_plan_contract_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_plan_contract_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_plan_contract_does_not_enable_self_programming',
            ],
            'human_summary' => 'Automatic dispatch scheduler one-shot tick Codex real invoker executor plan contract is ready; it defines a disabled executor plan and still cannot start Codex.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProviderExecutionContractGateContract(array $options = []): array
    {
        $guardStatusPayload = $this->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartAdapterExecutionGuardGateStatus($options);
        $guardStatus = (array) data_get($guardStatusPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_adapter_execution_guard_gate_status', []);
        $providerExecutionPayload = $this->agentCodexRealInvokerPostStartProviderExecutionContractGateContractTemplate($options);

        $contract = [
            'status' => 'one_shot_tick_codex_real_invoker_post_start_provider_execution_contract_gate_contract_ready',
            'contract_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-REAL-INVOKER-POST-START-PROVIDER-EXECUTION-CONTRACT-GATE-CONTRACT-SELF-CONSTRUCTION-0001',
            'parent_program' => 'Atlas Self-Construction OS',
            'submodule' => 'Atlas Agent Control Plane',
            'provider' => 'codex',
            'adapter' => 'codex',
            'source_codex_real_invoker_post_start_adapter_execution_guard_gate_status' => data_get($guardStatus, 'status'),
            'source_codex_real_invoker_post_start_adapter_execution_guard_gate_status_hash' => data_get($guardStatusPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_adapter_execution_guard_gate_status_hash'),
            'source_codex_real_invoker_post_start_provider_execution_contract_gate_status' => data_get($providerExecutionPayload, 'status'),
            'source_codex_real_invoker_post_start_provider_execution_contract_gate_hash' => data_get($providerExecutionPayload, 'codex_real_invoker_post_start_provider_execution_contract_gate_contract_template_hash'),
            'provider_execution_contract' => [
                'canonical_post_start_provider_execution_contract_gate' => AgentCodexRealInvokerPostStartProviderExecutionContractGate::class,
                'canonical_post_start_provider_execution_contract_gate_method' => 'preparePostStartProviderExecutionContract',
                'scheduler_invoker' => AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProviderExecutionContractGateInvoker::class,
                'scheduler_invoker_method' => 'prepareCodexRealInvokerPostStartProviderExecutionContractGate',
                'codex_provider_execution_driver' => AgentCodexProviderExecutionDriver::class,
                'codex_provider_execution_driver_method' => 'prepareCodexExecution',
                'provider_execution_contract_effect' => 'record_codex_provider_execution_contract_without_starting_codex',
                'post_start_adapter_execution_guard_required_before_contract' => true,
                'post_start_evidence_acceptance_bridge_required_before_contract' => true,
                'provider_start_run_with_provider_adapter_execution_guard_required_before_contract' => true,
                'active_sandbox_binding_required_by_codex_driver' => true,
                'context_pack_and_continuation_summary_hashes_required_from_boundary' => true,
                'process_start_release_required_before_any_later_process_start' => true,
                'gate_delegates_to_codex_provider_execution_driver' => true,
                'gate_records_codex_provider_execution_metadata_on_provider_start_run' => true,
                'gate_records_bridge_metadata_on_observed_run' => true,
                'provider_specific_execution_contract_ready_after_gate' => true,
                'actual_process_start_allowed_by_contract' => false,
                'provider_process_call_allowed_by_contract' => false,
                'adapter_invocation_allowed_by_contract' => false,
                'adapter_execution_allowed_by_contract' => false,
                'token_spend_allowed_by_contract' => false,
                'dispatch_allowed_by_contract' => false,
                'idempotency_key' => 'codex_execution_id',
            ],
            'required_input_fields_for_future_invoker' => [
                'run_key',
                'provider_execution_contract_gate_id',
                'codex_execution_id',
                'adapter_execution_guard_gate_id',
                'execution_guard_id',
                'adapter_invocation_boundary_gate_id',
                'adapter_invocation_id',
                'provider_start_driver_gate_id',
                'provider_start_attempt_id',
                'dispatch_executor_handoff_id',
                'signed_dispatch_authorization_id',
                'post_start_evidence_acceptance_bridge_id',
                'signed_dispatch_receipt_hash',
                'command',
                'cwd',
                'context_pack_hash',
                'continuation_summary_hash',
                'actor',
                'session',
                'max_runtime_minutes',
                'max_cost_usd',
                'reason',
            ],
            'allowed_future_mutations' => [
                'record_codex_provider_execution_metadata_on_provider_start_run',
                'append_codex_provider_execution_contract_evidence_event',
                'record_codex_real_invoker_post_start_provider_execution_contract_metadata_on_observed_run',
            ],
            'forbidden_even_after_contract' => [
                'spawn_codex_process',
                'call_codex_cli_or_codex_app',
                'dispatch_work_to_codex',
                'send_prompt_to_provider',
                'call_provider_process',
                'enable_adapter_execution',
                'authorize_process_start_release',
                'spend_provider_tokens',
                'mark_observed_run_running_or_terminal',
                'enable_self_programming',
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_provider_execution_contract_gate_preflight',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_provider_execution_contract_gate_contract.v1',
            'status' => (string) $contract['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_provider_execution_contract_gate_contract',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'post_start_provider_execution_contract_gate_allowed' => false,
            'actual_process_start_allowed' => false,
            'provider_process_call_allowed' => false,
            'adapter_invocation_allowed' => false,
            'adapter_execution_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_provider_execution_contract_gate_contract' => $contract,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_provider_execution_contract_gate_contract_hash' => $this->stableHash($contract),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_provider_execution_contract_gate_contract_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_provider_execution_contract_gate_contract_does_not_call_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_provider_execution_contract_gate_contract_does_not_execute_adapter',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_provider_execution_contract_gate_contract_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_provider_execution_contract_gate_contract_does_not_dispatch_work',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_provider_execution_contract_gate_contract_does_not_enable_self_programming',
            ],
            'human_summary' => 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start provider execution contract gate contract is ready; it prepares the Codex-specific execution contract without starting Codex.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartEvidenceAcceptanceBridgeContract(array $options = []): array
    {
        $receiptStatusPayload = $this->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartEvidenceReceiptStatus($options);
        $receiptStatus = (array) data_get($receiptStatusPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_evidence_receipt_status', []);
        $bridgePayload = $this->agentCodexRealInvokerPostStartEvidenceAcceptanceBridgeContractTemplate($options);

        $contract = [
            'status' => 'one_shot_tick_codex_real_invoker_post_start_evidence_acceptance_bridge_contract_ready',
            'contract_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-REAL-INVOKER-POST-START-EVIDENCE-ACCEPTANCE-BRIDGE-CONTRACT-SELF-CONSTRUCTION-0001',
            'parent_program' => 'Atlas Self-Construction OS',
            'submodule' => 'Atlas Agent Control Plane',
            'provider' => 'codex',
            'adapter' => 'codex',
            'source_codex_real_invoker_post_start_evidence_receipt_status' => data_get($receiptStatus, 'status'),
            'source_codex_real_invoker_post_start_evidence_receipt_status_hash' => data_get($receiptStatusPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_evidence_receipt_status_hash'),
            'source_codex_real_invoker_post_start_evidence_acceptance_bridge_status' => data_get($bridgePayload, 'status'),
            'source_codex_real_invoker_post_start_evidence_acceptance_bridge_hash' => data_get($bridgePayload, 'codex_real_invoker_post_start_evidence_acceptance_bridge_contract_template_hash'),
            'release_boundary' => [
                'canonical_post_start_evidence_acceptance_bridge' => AgentCodexRealInvokerPostStartEvidenceAcceptanceBridge::class,
                'canonical_post_start_evidence_acceptance_bridge_method' => 'acceptPostStartEvidence',
                'scheduler_invoker' => AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartEvidenceAcceptanceBridgeInvoker::class,
                'scheduler_invoker_method' => 'acceptCodexRealInvokerPostStartEvidence',
                'gate_effect' => 'accept_attested_external_start_evidence_before_liveness_monitoring',
                'post_start_operator_start_handoff_required_before_acceptance' => true,
                'post_start_receipt_contract_required_by_bridge' => true,
                'post_start_evidence_receipt_required_by_bridge' => true,
                'operator_external_start_attestation_required' => true,
                'no_atlas_process_spawn_attestation_required' => true,
                'external_process_evidence_accepted_by_contract' => true,
                'actual_process_start_allowed_by_contract' => false,
                'atlas_process_spawned_by_contract' => false,
                'provider_marked_started_by_contract' => true,
                'adapter_execution_allowed_by_contract' => false,
                'token_spend_allowed_by_contract' => false,
                'dispatch_allowed_by_contract' => false,
                'idempotency_key' => 'post_start_evidence_acceptance_bridge_id',
            ],
            'required_input_fields_for_future_invoker' => [
                'run_key',
                'post_start_evidence_acceptance_bridge_id',
                'post_start_operator_start_handoff_id',
                'post_start_receipt_contract_id',
                'post_start_evidence_receipt_id',
                'codex_execution_id',
                'real_invoker_process_starter_readiness_gate_id',
                'real_invoker_start_execution_gate_id',
                'manual_start_executor_receipt_id',
                'operator_start_handoff_id',
                'handoff_packet_hash',
                'operator_runbook_hash',
                'external_terminal_handoff_hash',
                'post_start_liveness_probe_contract_hash',
                'post_start_receipt_contract_hash',
                'failure_escalation_contract_hash',
                'external_process_identity_contract_hash',
                'startup_evidence_contract_hash',
                'terminal_pid_capture_contract_hash',
                'post_start_cost_meter_contract_hash',
                'external_process_identity_evidence_hash',
                'startup_evidence_hash',
                'terminal_pid_capture_hash',
                'post_start_liveness_probe_hash',
                'post_start_cost_meter_evidence_hash',
                'operator_external_start_attestation_hash',
                'no_atlas_process_spawn_attestation_hash',
                'actor',
                'session',
                'reason',
            ],
            'allowed_future_mutations' => [
                'write_codex_real_invoker_post_start_receipt_contract_metadata_on_agent_run',
                'write_codex_real_invoker_post_start_evidence_receipt_metadata_on_agent_run',
                'write_codex_real_invoker_post_start_evidence_acceptance_bridge_metadata_on_agent_run',
                'append_codex_real_invoker_post_start_evidence_acceptance_bridge_evidence_event',
            ],
            'forbidden_even_after_contract' => [
                'spawn_codex_process',
                'call_codex_cli_or_codex_app',
                'spawn_shell_or_subprocess',
                'spend_provider_tokens',
                'mark_run_terminal',
                'dispatch_work_to_codex',
                'enable_self_programming',
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_evidence_acceptance_bridge_preflight',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_evidence_acceptance_bridge_contract.v1',
            'status' => (string) $contract['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_evidence_acceptance_bridge_contract',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_post_start_evidence_acceptance_bridge_allowed' => false,
            'external_process_evidence_acceptance_allowed' => false,
            'actual_process_start_allowed' => false,
            'adapter_execution_allowed' => false,
            'provider_start_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_evidence_acceptance_bridge_contract' => $contract,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_evidence_acceptance_bridge_contract_hash' => $this->stableHash($contract),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_evidence_acceptance_bridge_contract_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_evidence_acceptance_bridge_contract_does_not_call_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_evidence_acceptance_bridge_contract_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_evidence_acceptance_bridge_contract_does_not_enable_self_programming',
            ],
            'human_summary' => 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start evidence acceptance bridge contract is ready; it records acceptance only as governed evidence before liveness monitoring.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerManualStartExecutorReceiptContract(array $options = []): array
    {
        $starterStatusPayload = $this->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerProcessStarterReadinessGateStatus($options);
        $starterStatus = (array) data_get($starterStatusPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_process_starter_readiness_gate_status', []);
        $manualReceiptPayload = $this->agentCodexRealInvokerManualStartExecutorReceiptWriterContractTemplate($options);

        $contract = [
            'status' => 'one_shot_tick_codex_real_invoker_manual_start_executor_receipt_contract_ready',
            'contract_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-REAL-INVOKER-MANUAL-START-EXECUTOR-RECEIPT-CONTRACT-SELF-CONSTRUCTION-0001',
            'parent_program' => 'Atlas Self-Construction OS',
            'submodule' => 'Atlas Agent Control Plane',
            'provider' => 'codex',
            'adapter' => 'codex',
            'source_codex_real_invoker_process_starter_readiness_gate_status' => data_get($starterStatus, 'status'),
            'source_codex_real_invoker_process_starter_readiness_gate_status_hash' => data_get($starterStatusPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_process_starter_readiness_gate_status_hash'),
            'source_codex_real_invoker_manual_start_executor_receipt_contract_status' => data_get($manualReceiptPayload, 'status'),
            'source_codex_real_invoker_manual_start_executor_receipt_contract_hash' => data_get($manualReceiptPayload, 'codex_real_invoker_manual_start_executor_receipt_writer_contract_template_hash'),
            'release_boundary' => [
                'canonical_manual_start_executor_receipt_writer' => AgentCodexRealInvokerManualStartExecutorReceiptWriter::class,
                'canonical_manual_start_executor_receipt_writer_method' => 'writeManualStartExecutorReceipt',
                'scheduler_invoker' => AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerManualStartExecutorReceiptInvoker::class,
                'scheduler_invoker_method' => 'writeCodexRealInvokerManualStartExecutorReceipt',
                'gate_effect' => 'record_manual_start_executor_receipt_without_starting_process',
                'process_starter_readiness_required_before_manual_receipt' => true,
                'manual_operator_start_required_by_receipt' => true,
                'actual_process_start_allowed_by_receipt' => false,
                'external_process_started_by_receipt' => false,
                'provider_started_by_receipt' => false,
                'adapter_execution_allowed_by_receipt' => false,
                'token_spend_allowed_by_receipt' => false,
                'dispatch_allowed_by_receipt' => false,
                'idempotency_key' => 'manual_start_executor_receipt_id',
            ],
            'required_input_fields_for_future_invoker' => [
                'run_key',
                'codex_execution_id',
                'real_invoker_executor_plan_id',
                'real_invoker_executor_fresh_release_id',
                'real_invoker_executor_enablement_id',
                'real_invoker_supervised_start_activation_id',
                'real_invoker_guarded_process_start_id',
                'real_invoker_final_process_start_authorization_id',
                'real_invoker_actual_process_start_rehearsal_id',
                'real_invoker_process_start_envelope_id',
                'real_invoker_start_execution_gate_id',
                'real_invoker_process_starter_readiness_gate_id',
                'manual_start_executor_receipt_id',
                'process_starter_manifest_hash',
                'supervisor_binding_hash',
                'liveness_monitor_binding_hash',
                'cancellation_contract_hash',
                'output_capture_contract_hash',
                'cost_meter_contract_hash',
                'start_replay_guard_hash',
                'operator_process_starter_signature_hash',
                'manual_start_command_hash',
                'terminal_session_binding_hash',
                'operator_presence_hash',
                'live_supervisor_ack_hash',
                'initial_liveness_probe_hash',
                'kill_switch_ack_hash',
                'output_stream_capture_hash',
                'cost_meter_initial_hash',
                'no_autostart_attestation_hash',
                'actor',
                'session',
                'reason',
            ],
            'allowed_future_mutations' => [
                'write_codex_real_invoker_manual_start_executor_receipt_metadata_on_agent_run',
                'append_codex_real_invoker_manual_start_executor_receipt_evidence_event',
            ],
            'forbidden_even_after_contract' => [
                'spawn_codex_process',
                'call_codex_cli_or_codex_app',
                'spawn_shell_or_subprocess',
                'spend_provider_tokens',
                'mark_run_running_or_terminal',
                'mark_packet_completed',
                'merge_work_products',
                'dispatch_work_to_codex',
                'enable_self_programming',
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_manual_start_executor_receipt_preflight',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_manual_start_executor_receipt_contract.v1',
            'status' => (string) $contract['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_manual_start_executor_receipt_contract',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_manual_start_executor_receipt_allowed' => false,
            'manual_operator_start_required' => false,
            'actual_process_start_allowed' => false,
            'adapter_execution_allowed' => false,
            'provider_start_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_manual_start_executor_receipt_contract' => $contract,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_manual_start_executor_receipt_contract_hash' => $this->stableHash($contract),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_manual_start_executor_receipt_contract_does_not_write_manual_receipt',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_manual_start_executor_receipt_contract_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_manual_start_executor_receipt_contract_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_manual_start_executor_receipt_contract_does_not_enable_self_programming',
            ],
            'human_summary' => 'Automatic dispatch scheduler one-shot tick Codex real invoker manual start executor receipt contract is ready; it records only the operator manual-start receipt boundary and still cannot start Codex.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProviderStartDriverGateStatus(array $options = []): array
    {
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $dispatchReceiptsTableReady = Schema::hasTable('atlas_self_construction_agent_dispatch_receipts');
        $releaseAuthorizationsTableReady = Schema::hasTable('atlas_self_construction_agent_dispatch_executor_release_authorizations');
        $sandboxBindingsTableReady = Schema::hasTable('atlas_self_construction_agent_sandbox_bindings');
        $heartbeatsTableReady = Schema::hasTable('atlas_self_construction_agent_heartbeats');
        $ledgerTableReady = Schema::hasTable('atlas_ledger_events');
        $gateReady = class_exists(AgentCodexRealInvokerPostStartProviderStartDriverGate::class)
            && method_exists(AgentCodexRealInvokerPostStartProviderStartDriverGate::class, 'preparePostStartProviderStartDriver');
        $invokerReady = class_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProviderStartDriverGateInvoker::class)
            && method_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProviderStartDriverGateInvoker::class, 'prepareCodexRealInvokerPostStartProviderStartDriverGate');
        $driverReady = class_exists(AgentDispatchExecutorProviderStartDriver::class)
            && method_exists(AgentDispatchExecutorProviderStartDriver::class, 'startProviderOnce');

        $providerStartGateRunsQuery = $runsTableReady
            ? AtlasSelfConstructionAgentRun::query()
                ->whereNotNull('metadata->codex_real_invoker_post_start_provider_start_driver->provider_start_attempt_id')
            : null;
        $preStartRunsQuery = $runsTableReady
            ? AtlasSelfConstructionAgentRun::query()
                ->where('run_key', 'like', 'provider-start:%')
                ->where('status', 'pre_start_guarded')
            : null;
        $preStartHeartbeatsQuery = $heartbeatsTableReady
            ? AtlasSelfConstructionAgentHeartbeat::query()
                ->where('signal', 'pre_start_guard')
            : null;
        $activeSandboxBindingsQuery = $sandboxBindingsTableReady
            ? AtlasSelfConstructionAgentSandboxBinding::query()
                ->where('status', 'active_pending_provider_start')
            : null;

        $statusReady = $runsTableReady
            && $dispatchReceiptsTableReady
            && $releaseAuthorizationsTableReady
            && $sandboxBindingsTableReady
            && $heartbeatsTableReady
            && $ledgerTableReady
            && $gateReady
            && $invokerReady
            && $driverReady;
        $status = [
            'status' => $statusReady ? 'one_shot_tick_codex_real_invoker_post_start_provider_start_driver_gate_service_ready' : 'blocked',
            'provider' => 'codex',
            'adapter' => 'codex',
            'invoker_service' => AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProviderStartDriverGateInvoker::class,
            'invoker_service_ready' => $invokerReady,
            'invoker_canonical_method' => 'prepareCodexRealInvokerPostStartProviderStartDriverGate',
            'generic_post_start_provider_start_driver_gate_service' => AgentCodexRealInvokerPostStartProviderStartDriverGate::class,
            'generic_post_start_provider_start_driver_gate_service_ready' => $gateReady,
            'generic_post_start_provider_start_driver_gate_canonical_method' => 'preparePostStartProviderStartDriver',
            'dispatch_executor_provider_start_driver_service' => AgentDispatchExecutorProviderStartDriver::class,
            'dispatch_executor_provider_start_driver_ready' => $driverReady,
            'agent_runs_table_ready' => $runsTableReady,
            'dispatch_receipts_table_ready' => $dispatchReceiptsTableReady,
            'dispatch_executor_release_authorizations_table_ready' => $releaseAuthorizationsTableReady,
            'sandbox_bindings_table_ready' => $sandboxBindingsTableReady,
            'agent_heartbeats_table_ready' => $heartbeatsTableReady,
            'ledger_table_ready' => $ledgerTableReady,
            'codex_real_invoker_post_start_provider_start_driver_recorded_run_count' => $providerStartGateRunsQuery === null ? null : (clone $providerStartGateRunsQuery)->count(),
            'pre_start_guarded_provider_start_run_count' => $preStartRunsQuery === null ? null : (clone $preStartRunsQuery)->count(),
            'pre_start_guard_heartbeat_count' => $preStartHeartbeatsQuery === null ? null : (clone $preStartHeartbeatsQuery)->count(),
            'active_pending_provider_start_sandbox_binding_count' => $activeSandboxBindingsQuery === null ? null : (clone $activeSandboxBindingsQuery)->count(),
            'runtime_policy' => [
                'status_projection_is_read_only' => true,
                'invoker_may_prepare_pre_start_guarded_provider_start_projection_when_called_with_signed_input' => true,
                'provider_start_driver_bridge_is_not_process_start' => true,
                'atlas_process_spawn_allowed_here' => false,
                'provider_external_process_start_allowed_here' => false,
                'provider_process_call_allowed_here' => false,
                'adapter_invocation_allowed_here' => false,
                'adapter_execution_allowed_here' => false,
                'dispatch_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'self_programming_allowed_here' => false,
            ],
            'next_required_slice' => $statusReady
                ? 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_adapter_invocation_boundary_gate_contract'
                : 'repair_one_shot_scheduler_tick_codex_real_invoker_post_start_provider_start_driver_gate_service_blockers',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_provider_start_driver_gate_status.v1',
            'status' => (string) $status['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_provider_start_driver_gate_status',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'post_start_provider_start_driver_gate_allowed' => false,
            'actual_process_start_allowed' => false,
            'adapter_invocation_allowed' => false,
            'adapter_execution_allowed' => false,
            'provider_start_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_provider_start_driver_gate_status' => $status,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_provider_start_driver_gate_status_hash' => $this->stableHash($status),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_provider_start_driver_gate_status_does_not_call_provider_start_driver_gate',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_provider_start_driver_gate_status_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_provider_start_driver_gate_status_does_not_enable_adapter_invocation',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_provider_start_driver_gate_status_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_provider_start_driver_gate_status_does_not_dispatch_work',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_provider_start_driver_gate_status_does_not_enable_self_programming',
            ],
            'human_summary' => $statusReady
                ? 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start provider start driver gate service is ready and inspectable; adapter invocation boundary remains separate.'
                : 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start provider start driver gate service is blocked until invoker, generic gate, sandbox, driver and storage are ready.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickGuardedRuntimeInvocationContract(array $options = []): array
    {
        $writerStatusPayload = $this->agentAutomaticDispatchSchedulerOneShotTickMutatingWriterStatus($options);
        $writerStatus = (array) data_get($writerStatusPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_mutating_writer_status', []);

        $contract = [
            'status' => 'one_shot_tick_guarded_runtime_invocation_contract_ready',
            'contract_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-GUARDED-RUNTIME-INVOCATION-CONTRACT-SELF-CONSTRUCTION-0001',
            'parent_program' => 'Atlas Self-Construction OS',
            'submodule' => 'Atlas Agent Control Plane',
            'workspace_id' => 'FORGE-WORKSPACE-ATLAS-SELF-CONSTRUCTION-0001',
            'source_mutating_writer_status' => data_get($writerStatus, 'status'),
            'source_mutating_writer_status_hash' => data_get($writerStatusPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_mutating_writer_status_hash'),
            'invocation_boundary' => [
                'canonical_method' => 'invokeSignedOneShotSchedulerTick',
                'allowed_runtime_service' => AgentAutomaticDispatchSchedulerOneShotTickMutatingWriter::class,
                'allowed_runtime_method' => 'executeOneShotSchedulerTickAfterReleasePreflight',
                'max_writer_invocations_per_command' => 1,
                'requires_signed_release_receipt_hash' => true,
                'requires_signed_dispatch_receipt_hash' => true,
                'requires_operator_supplied_signature_metadata' => true,
                'requires_append_only_evidence' => true,
            ],
            'input_contract' => [
                'release_receipt_hash',
                'selected_wakeup_key',
                'dispatch_envelope_hash',
                'source_release_preflight_hash',
                'source_mutating_writer_contract_hash',
                'source_mutating_writer_preflight_hash',
                'receipt_hash',
                'signed_by',
                'signed_at',
                'expires_at',
                'payload',
            ],
            'allowed_mutations' => [
                'call_mutating_writer_once',
                'claim_one_queued_wakeup_via_mutating_writer',
                'write_one_signed_pending_dispatch_receipt_via_mutating_writer',
                'append_one_scheduler_tick_evidence_event_via_mutating_writer',
            ],
            'forbidden_even_after_invocation' => [
                'use_dispatch_receipt',
                'mark_dispatch_receipt_used',
                'start_provider_process',
                'invoke_provider_adapter',
                'spend_provider_tokens',
                'merge_work_products',
                'mark_packet_completed',
                'enable_self_programming',
            ],
            'idempotency_policy' => [
                'idempotency_key' => 'receipt_hash',
                'same_receipt_hash_must_return_existing_signed_pending_dispatch_receipt' => true,
                'duplicate_receipt_key_with_different_hash_must_fail' => true,
                'invocation_must_be_safe_to_retry_after_client_timeout' => true,
            ],
            'output_contract' => [
                'status',
                'created',
                'wakeup_claimed',
                'dispatch_receipt_written',
                'receipt_key',
                'receipt_hash',
                'wakeup_item_id',
                'ledger_event_id',
                'provider_start_allowed=false',
                'adapter_invocation_allowed=false',
                'token_spend_allowed=false',
                'self_programming_allowed=false',
            ],
            'required_gates' => [
                'dedicated_guarded_invocation_contract_tests',
                'mutating_writer_feature_tests',
                'focused_self_construction_command_tests',
                'architecture_validate',
                'docs_health',
                'git_diff_check',
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_guarded_runtime_invocation_preflight',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_guarded_runtime_invocation_contract.v1',
            'status' => (string) $contract['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_guarded_runtime_invocation_contract',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'claim_allowed' => false,
            'dispatch_receipt_write_allowed' => false,
            'dispatch_receipt_use_allowed' => false,
            'provider_start_allowed' => false,
            'adapter_invocation_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_guarded_runtime_invocation_contract' => $contract,
            'agent_automatic_dispatch_scheduler_one_shot_tick_guarded_runtime_invocation_contract_hash' => $this->stableHash($contract),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_guarded_runtime_invocation_contract_does_not_call_mutating_writer',
                'agent_automatic_dispatch_scheduler_one_shot_tick_guarded_runtime_invocation_contract_does_not_claim_wakeup_items',
                'agent_automatic_dispatch_scheduler_one_shot_tick_guarded_runtime_invocation_contract_does_not_write_dispatch_receipts',
                'agent_automatic_dispatch_scheduler_one_shot_tick_guarded_runtime_invocation_contract_does_not_use_dispatch_receipts',
                'agent_automatic_dispatch_scheduler_one_shot_tick_guarded_runtime_invocation_contract_does_not_start_providers',
                'agent_automatic_dispatch_scheduler_one_shot_tick_guarded_runtime_invocation_contract_does_not_enable_self_programming',
            ],
            'human_summary' => 'Automatic dispatch scheduler one-shot tick guarded runtime invocation contract is ready; it defines the only future path that may call the mutating writer once, while still stopping before receipt use and provider start.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerOperatorStartHandoffContract(array $options = []): array
    {
        $manualReceiptStatusPayload = $this->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerManualStartExecutorReceiptStatus($options);
        $manualReceiptStatus = (array) data_get($manualReceiptStatusPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_manual_start_executor_receipt_status', []);
        $handoffPayload = $this->agentCodexRealInvokerOperatorStartHandoffBuilderContractTemplate($options);

        $contract = [
            'status' => 'one_shot_tick_codex_real_invoker_operator_start_handoff_contract_ready',
            'contract_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-REAL-INVOKER-OPERATOR-START-HANDOFF-CONTRACT-SELF-CONSTRUCTION-0001',
            'parent_program' => 'Atlas Self-Construction OS',
            'submodule' => 'Atlas Agent Control Plane',
            'provider' => 'codex',
            'adapter' => 'codex',
            'source_codex_real_invoker_manual_start_executor_receipt_status' => data_get($manualReceiptStatus, 'status'),
            'source_codex_real_invoker_manual_start_executor_receipt_status_hash' => data_get($manualReceiptStatusPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_manual_start_executor_receipt_status_hash'),
            'source_codex_real_invoker_operator_start_handoff_contract_status' => data_get($handoffPayload, 'status'),
            'source_codex_real_invoker_operator_start_handoff_contract_hash' => data_get($handoffPayload, 'codex_real_invoker_operator_start_handoff_builder_contract_template_hash'),
            'release_boundary' => [
                'canonical_operator_start_handoff_builder' => AgentCodexRealInvokerOperatorStartHandoffBuilder::class,
                'canonical_operator_start_handoff_builder_method' => 'buildOperatorStartHandoff',
                'scheduler_invoker' => AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerOperatorStartHandoffInvoker::class,
                'scheduler_invoker_method' => 'buildCodexRealInvokerOperatorStartHandoff',
                'gate_effect' => 'record_operator_start_handoff_without_starting_process',
                'manual_start_executor_receipt_required_before_handoff' => true,
                'manual_operator_start_required_by_handoff' => true,
                'actual_process_start_allowed_by_handoff' => false,
                'external_process_started_by_handoff' => false,
                'provider_started_by_handoff' => false,
                'adapter_execution_allowed_by_handoff' => false,
                'token_spend_allowed_by_handoff' => false,
                'dispatch_allowed_by_handoff' => false,
                'idempotency_key' => 'operator_start_handoff_id',
            ],
            'required_input_fields_for_future_invoker' => [
                'run_key',
                'codex_execution_id',
                'real_invoker_executor_plan_id',
                'real_invoker_executor_fresh_release_id',
                'real_invoker_executor_enablement_id',
                'real_invoker_supervised_start_activation_id',
                'real_invoker_guarded_process_start_id',
                'real_invoker_final_process_start_authorization_id',
                'real_invoker_actual_process_start_rehearsal_id',
                'real_invoker_process_start_envelope_id',
                'real_invoker_start_execution_gate_id',
                'real_invoker_process_starter_readiness_gate_id',
                'manual_start_executor_receipt_id',
                'operator_start_handoff_id',
                'manual_start_command_hash',
                'terminal_session_binding_hash',
                'operator_presence_hash',
                'live_supervisor_ack_hash',
                'initial_liveness_probe_hash',
                'kill_switch_ack_hash',
                'output_stream_capture_hash',
                'cost_meter_initial_hash',
                'no_autostart_attestation_hash',
                'handoff_packet_hash',
                'operator_runbook_hash',
                'external_terminal_handoff_hash',
                'post_start_liveness_probe_contract_hash',
                'post_start_receipt_contract_hash',
                'failure_escalation_contract_hash',
                'actor',
                'session',
                'reason',
            ],
            'allowed_future_mutations' => [
                'write_codex_real_invoker_operator_start_handoff_metadata_on_agent_run',
                'append_codex_real_invoker_operator_start_handoff_evidence_event',
            ],
            'forbidden_even_after_contract' => [
                'spawn_codex_process',
                'call_codex_cli_or_codex_app',
                'spawn_shell_or_subprocess',
                'spend_provider_tokens',
                'mark_run_running_or_terminal',
                'mark_packet_completed',
                'merge_work_products',
                'dispatch_work_to_codex',
                'enable_self_programming',
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_operator_start_handoff_preflight',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_operator_start_handoff_contract.v1',
            'status' => (string) $contract['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_operator_start_handoff_contract',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_operator_start_handoff_allowed' => false,
            'manual_operator_start_required' => false,
            'actual_process_start_allowed' => false,
            'adapter_execution_allowed' => false,
            'provider_start_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_operator_start_handoff_contract' => $contract,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_operator_start_handoff_contract_hash' => $this->stableHash($contract),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_operator_start_handoff_contract_does_not_build_handoff',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_operator_start_handoff_contract_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_operator_start_handoff_contract_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_operator_start_handoff_contract_does_not_enable_self_programming',
            ],
            'human_summary' => 'Automatic dispatch scheduler one-shot tick Codex real invoker operator start handoff contract is ready; it prepares only a manual external-start handoff and still cannot start Codex.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProcessStartReleaseGateContract(array $options = []): array
    {
        $providerExecutionPayload = $this->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProviderExecutionContractGateStatus($options);
        $providerExecutionStatus = (array) data_get($providerExecutionPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_provider_execution_contract_gate_status', []);
        $processStartReleasePayload = $this->agentCodexRealInvokerPostStartProcessStartReleaseGateContractTemplate($options);

        $contract = [
            'status' => 'one_shot_tick_codex_real_invoker_post_start_process_start_release_gate_contract_ready',
            'contract_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-REAL-INVOKER-POST-START-PROCESS-START-RELEASE-GATE-CONTRACT-SELF-CONSTRUCTION-0001',
            'parent_program' => 'Atlas Self-Construction OS',
            'submodule' => 'Atlas Agent Control Plane',
            'provider' => 'codex',
            'adapter' => 'codex',
            'source_codex_real_invoker_post_start_provider_execution_contract_gate_status' => data_get($providerExecutionStatus, 'status'),
            'source_codex_real_invoker_post_start_provider_execution_contract_gate_status_hash' => data_get($providerExecutionPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_provider_execution_contract_gate_status_hash'),
            'source_codex_real_invoker_post_start_process_start_release_gate_status' => data_get($processStartReleasePayload, 'status'),
            'source_codex_real_invoker_post_start_process_start_release_gate_hash' => data_get($processStartReleasePayload, 'codex_real_invoker_post_start_process_start_release_gate_contract_template_hash'),
            'process_start_release' => [
                'canonical_post_start_process_start_release_gate' => AgentCodexRealInvokerPostStartProcessStartReleaseGate::class,
                'canonical_post_start_process_start_release_gate_method' => 'authorizePostStartProcessStartRelease',
                'scheduler_invoker' => AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProcessStartReleaseGateInvoker::class,
                'scheduler_invoker_method' => 'authorizeCodexRealInvokerPostStartProcessStartReleaseGate',
                'codex_process_start_release_gate' => AgentCodexProcessStartReleaseGate::class,
                'codex_process_start_release_gate_method' => 'authorizeCodexProcessStart',
                'process_start_release_effect' => 'authorize_codex_process_start_release_without_starting_codex',
                'post_start_provider_execution_contract_required_before_release' => true,
                'post_start_evidence_acceptance_bridge_required_before_release' => true,
                'provider_start_run_with_codex_provider_execution_required_before_release' => true,
                'operator_release_receipt_hash_required' => true,
                'codex_execution_contract_hash_required' => true,
                'gate_delegates_to_codex_process_start_release_gate' => true,
                'gate_records_codex_process_start_release_metadata_on_provider_start_run' => true,
                'gate_records_bridge_metadata_on_observed_run' => true,
                'supervised_start_executor_required_after_release' => true,
                'release_authorization_is_not_process_start' => true,
                'actual_process_start_allowed_by_contract' => false,
                'provider_process_call_allowed_by_contract' => false,
                'adapter_invocation_allowed_by_contract' => false,
                'adapter_execution_allowed_by_contract' => false,
                'token_spend_allowed_by_contract' => false,
                'dispatch_allowed_by_contract' => false,
                'idempotency_key' => 'process_start_release_id',
            ],
            'required_input_fields_for_future_invoker' => [
                'run_key',
                'post_start_process_start_release_gate_id',
                'process_start_release_id',
                'provider_execution_contract_gate_id',
                'codex_execution_id',
                'adapter_execution_guard_gate_id',
                'execution_guard_id',
                'adapter_invocation_boundary_gate_id',
                'adapter_invocation_id',
                'provider_start_driver_gate_id',
                'provider_start_attempt_id',
                'post_start_evidence_acceptance_bridge_id',
                'signed_dispatch_receipt_hash',
                'operator_release_receipt_hash',
                'codex_execution_contract_hash',
                'actor',
                'session',
                'reason',
            ],
            'allowed_future_mutations' => [
                'record_codex_process_start_release_metadata_on_provider_start_run',
                'append_codex_process_start_release_authorization_evidence_event',
                'record_codex_real_invoker_post_start_process_start_release_metadata_on_observed_run',
            ],
            'forbidden_even_after_contract' => [
                'spawn_codex_process',
                'call_codex_cli_or_codex_app',
                'run_supervised_start_executor',
                'dispatch_work_to_codex',
                'send_prompt_to_provider',
                'call_provider_process',
                'enable_adapter_execution',
                'spend_provider_tokens',
                'mark_observed_run_running_or_terminal',
                'enable_self_programming',
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_process_start_release_gate_preflight',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_start_release_gate_contract.v1',
            'status' => (string) $contract['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_start_release_gate_contract',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'post_start_process_start_release_gate_allowed' => false,
            'actual_process_start_allowed' => false,
            'provider_process_call_allowed' => false,
            'adapter_invocation_allowed' => false,
            'adapter_execution_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_start_release_gate_contract' => $contract,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_start_release_gate_contract_hash' => $this->stableHash($contract),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_start_release_gate_contract_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_start_release_gate_contract_does_not_call_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_start_release_gate_contract_does_not_execute_adapter',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_start_release_gate_contract_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_start_release_gate_contract_does_not_dispatch_work',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_start_release_gate_contract_does_not_enable_self_programming',
            ],
            'human_summary' => 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start process start release gate contract is ready; it authorizes only a later supervised path and does not start Codex.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartGuardedProcessStartExecutorGatePreflight(array $options = []): array
    {
        $contractPayload = $this->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartGuardedProcessStartExecutorGateContract($options);
        $contract = (array) data_get($contractPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_guarded_process_start_executor_gate_contract', []);
        $contractHash = (string) data_get($contractPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_guarded_process_start_executor_gate_contract_hash');
        $gateReady = class_exists(AgentCodexRealInvokerPostStartGuardedProcessStartExecutorGate::class)
            && method_exists(AgentCodexRealInvokerPostStartGuardedProcessStartExecutorGate::class, 'preparePostStartGuardedProcessStart');
        $invokerReady = class_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartGuardedProcessStartExecutorGateInvoker::class)
            && method_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartGuardedProcessStartExecutorGateInvoker::class, 'prepareCodexRealInvokerPostStartGuardedProcessStartExecutorGate');
        $guardedReady = class_exists(AgentCodexRealInvokerGuardedProcessStartExecutor::class)
            && method_exists(AgentCodexRealInvokerGuardedProcessStartExecutor::class, 'prepareGuardedStart');
        $activationReady = class_exists(AgentCodexRealInvokerPostStartSupervisedStartActivationGate::class)
            && method_exists(AgentCodexRealInvokerPostStartSupervisedStartActivationGate::class, 'preparePostStartSupervisedStartActivation');
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerTableReady = Schema::hasTable('atlas_ledger_events');

        $checks = [
            'post_start_guarded_process_start_executor_gate_contract_ready' => data_get($contractPayload, 'status') === 'one_shot_tick_codex_real_invoker_post_start_guarded_process_start_executor_gate_contract_ready',
            'post_start_guarded_process_start_executor_gate_contract_hash_present' => $contractHash !== '',
            'post_start_supervised_start_activation_gate_status_ready' => data_get($contract, 'source_codex_real_invoker_post_start_supervised_start_activation_gate_status') === 'one_shot_tick_codex_real_invoker_post_start_supervised_start_activation_gate_service_ready',
            'generic_post_start_guarded_process_start_executor_gate_template_ready' => data_get($contract, 'source_codex_real_invoker_post_start_guarded_process_start_executor_gate_contract_status') === 'codex_real_invoker_post_start_guarded_process_start_executor_gate_contract_template_ready',
            'generic_guarded_process_start_executor_template_ready' => data_get($contract, 'source_codex_real_invoker_guarded_process_start_executor_contract_status') === 'codex_real_invoker_guarded_process_start_executor_contract_template_ready',
            'codex_real_invoker_post_start_guarded_process_start_executor_gate_ready' => $gateReady,
            'codex_real_invoker_post_start_guarded_process_start_executor_gate_invoker_ready' => $invokerReady,
            'codex_real_invoker_guarded_process_start_executor_ready' => $guardedReady,
            'codex_real_invoker_post_start_supervised_start_activation_gate_ready' => $activationReady,
            'canonical_post_start_guarded_process_start_executor_gate_method_ready' => data_get($contract, 'guarded_process_start.canonical_post_start_guarded_process_start_executor_gate_method') === 'preparePostStartGuardedProcessStart',
            'scheduler_invoker_method_ready' => data_get($contract, 'guarded_process_start.scheduler_invoker_method') === 'prepareCodexRealInvokerPostStartGuardedProcessStartExecutorGate',
            'contract_requires_post_start_supervised_start_activation' => data_get($contract, 'guarded_process_start.post_start_supervised_start_activation_required_before_guarded_process_start') === true,
            'contract_requires_post_start_evidence_acceptance_bridge' => data_get($contract, 'guarded_process_start.post_start_evidence_acceptance_bridge_required_before_guarded_process_start') === true,
            'contract_requires_provider_start_supervised_activation' => data_get($contract, 'guarded_process_start.provider_start_supervised_activation_required_before_guarded_process_start') === true,
            'contract_delegates_to_codex_real_invoker_guarded_process_start_executor' => data_get($contract, 'guarded_process_start.gate_delegates_to_codex_real_invoker_guarded_process_start_executor') === true,
            'contract_declares_guarded_process_start_is_not_process_start' => data_get($contract, 'guarded_process_start.guarded_process_start_is_not_process_start') === true,
            'contract_requires_final_process_start_authorization_after_guarded_process_start' => in_array('authorize_final_process_start_without_separate_contract', (array) data_get($contract, 'forbidden_even_after_contract', []), true),
            'contract_allows_process_start_armed_by_future_invoker' => data_get($contract, 'guarded_process_start.process_start_armed_by_contract') === true,
            'contract_keeps_actual_process_start_disabled' => data_get($contract, 'guarded_process_start.actual_process_start_allowed_by_contract') === false,
            'contract_keeps_adapter_execution_disabled' => data_get($contract, 'guarded_process_start.adapter_execution_allowed_by_contract') === false,
            'contract_does_not_dispatch_work' => data_get($contract, 'guarded_process_start.dispatch_allowed_by_contract') === false,
            'contract_does_not_spend_tokens' => data_get($contract, 'guarded_process_start.token_spend_allowed_by_contract') === false,
            'agent_runs_table_ready' => $runsTableReady,
            'ledger_table_ready' => $ledgerTableReady,
            'self_programming_forbidden' => in_array('enable_self_programming', (array) data_get($contract, 'forbidden_even_after_contract', []), true),
        ];
        $blockingReasons = array_values(array_keys(array_filter($checks, static fn (bool $passed): bool => ! $passed)));

        $preflight = [
            'status' => $blockingReasons === [] ? 'one_shot_tick_codex_real_invoker_post_start_guarded_process_start_executor_gate_preflight_ready' : 'blocked',
            'preflight_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-REAL-INVOKER-POST-START-GUARDED-PROCESS-START-EXECUTOR-GATE-PREFLIGHT-SELF-CONSTRUCTION-0001',
            'source_post_start_guarded_process_start_executor_gate_contract_hash' => $contractHash,
            'preflight_checks' => $checks,
            'blocking_count' => count($blockingReasons),
            'blocking_reasons' => $blockingReasons,
            'implementation_requirements' => [
                'create_scheduler_specific_post_start_guarded_process_start_executor_gate_invoker',
                'delegate_to_agent_codex_real_invoker_post_start_guarded_process_start_executor_gate',
                'require_post_start_supervised_start_activation_metadata',
                'require_post_start_evidence_acceptance_bridge',
                'require_operator_guarded_start_receipt_hash',
                'require_process_runner_contract_hash',
                'project_readiness_status_without_invoking_codex',
            ],
            'runtime_policy' => [
                'preflight_is_read_only' => true,
                'post_start_guarded_process_start_executor_gate_call_allowed_by_future_invoker' => true,
                'process_start_armed_after_future_invoker' => true,
                'final_process_start_authorization_required_after_guarded_process_start' => true,
                'actual_process_start_allowed_here' => false,
                'provider_process_call_allowed_here' => false,
                'executor_enabled_here' => false,
                'process_start_armed_here' => false,
                'adapter_invocation_allowed_here' => false,
                'adapter_execution_allowed_here' => false,
                'dispatch_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'self_programming_allowed_here' => false,
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_guarded_process_start_executor_gate_implementation_packet',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_guarded_process_start_executor_gate_preflight.v1',
            'status' => (string) $preflight['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_guarded_process_start_executor_gate_preflight',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'post_start_guarded_process_start_executor_gate_allowed' => false,
            'actual_process_start_allowed' => false,
            'provider_process_call_allowed' => false,
            'executor_enabled' => false,
            'process_start_armed' => false,
            'adapter_invocation_allowed' => false,
            'adapter_execution_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_guarded_process_start_executor_gate_preflight' => $preflight,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_guarded_process_start_executor_gate_preflight_hash' => $this->stableHash($preflight),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_guarded_process_start_executor_gate_preflight_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_guarded_process_start_executor_gate_preflight_does_not_call_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_guarded_process_start_executor_gate_preflight_does_not_execute_adapter',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_guarded_process_start_executor_gate_preflight_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_guarded_process_start_executor_gate_preflight_does_not_dispatch_work',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_guarded_process_start_executor_gate_preflight_does_not_enable_self_programming',
            ],
            'human_summary' => $blockingReasons === []
                ? 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start guarded process start executor gate preflight is ready; final process start authorization remains separate.'
                : 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start guarded process start executor gate preflight is blocked until activation, guarded executor, storage and no-runtime prerequisites are ready.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartAdapterInvocationBoundaryGateContract(array $options = []): array
    {
        $providerStartStatusPayload = $this->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProviderStartDriverGateStatus($options);
        $providerStartStatus = (array) data_get($providerStartStatusPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_provider_start_driver_gate_status', []);
        $boundaryPayload = $this->agentCodexRealInvokerPostStartAdapterInvocationBoundaryGateContractTemplate($options);

        $contract = [
            'status' => 'one_shot_tick_codex_real_invoker_post_start_adapter_invocation_boundary_gate_contract_ready',
            'contract_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-REAL-INVOKER-POST-START-ADAPTER-INVOCATION-BOUNDARY-GATE-CONTRACT-SELF-CONSTRUCTION-0001',
            'parent_program' => 'Atlas Self-Construction OS',
            'submodule' => 'Atlas Agent Control Plane',
            'provider' => 'codex',
            'adapter' => 'codex',
            'source_codex_real_invoker_post_start_provider_start_driver_gate_status' => data_get($providerStartStatus, 'status'),
            'source_codex_real_invoker_post_start_provider_start_driver_gate_status_hash' => data_get($providerStartStatusPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_provider_start_driver_gate_status_hash'),
            'source_codex_real_invoker_post_start_adapter_invocation_boundary_gate_status' => data_get($boundaryPayload, 'status'),
            'source_codex_real_invoker_post_start_adapter_invocation_boundary_gate_hash' => data_get($boundaryPayload, 'codex_real_invoker_post_start_adapter_invocation_boundary_gate_contract_template_hash'),
            'adapter_invocation_boundary' => [
                'canonical_post_start_adapter_invocation_boundary_gate' => AgentCodexRealInvokerPostStartAdapterInvocationBoundaryGate::class,
                'canonical_post_start_adapter_invocation_boundary_gate_method' => 'preparePostStartAdapterInvocationBoundary',
                'scheduler_invoker' => AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartAdapterInvocationBoundaryGateInvoker::class,
                'scheduler_invoker_method' => 'prepareCodexRealInvokerPostStartAdapterInvocationBoundaryGate',
                'adapter_invocation_boundary_effect' => 'prepare_adapter_invocation_metadata_on_the_pre_start_guarded_provider_run_without_calling_codex',
                'post_start_provider_start_driver_required_before_boundary' => true,
                'post_start_evidence_acceptance_bridge_required_before_boundary' => true,
                'pre_start_guarded_provider_start_run_required_before_boundary' => true,
                'pre_start_heartbeat_required_before_boundary' => true,
                'provider_adapter_registry_required' => true,
                'context_pack_hash_required' => true,
                'continuation_summary_hash_required' => true,
                'signed_dispatch_receipt_hash_required' => true,
                'adapter_descriptor_hash_projected_by_boundary' => true,
                'boundary_prepares_adapter_invocation_metadata' => true,
                'actual_process_start_allowed_by_contract' => false,
                'boundary_external_process_started_by_contract' => false,
                'boundary_provider_started_by_contract' => false,
                'provider_process_call_allowed_by_contract' => false,
                'adapter_invocation_allowed_by_contract' => false,
                'adapter_execution_allowed_by_contract' => false,
                'token_spend_allowed_by_contract' => false,
                'dispatch_allowed_by_contract' => false,
                'idempotency_key' => 'adapter_invocation_id',
            ],
            'required_input_fields_for_future_invoker' => [
                'run_key',
                'adapter_invocation_boundary_gate_id',
                'adapter_invocation_id',
                'provider_start_driver_gate_id',
                'provider_start_attempt_id',
                'dispatch_executor_handoff_id',
                'signed_dispatch_authorization_id',
                'post_start_evidence_acceptance_bridge_id',
                'signed_dispatch_receipt_hash',
                'command',
                'cwd',
                'context_pack_hash',
                'continuation_summary_hash',
                'actor',
                'session',
                'max_runtime_minutes',
                'max_cost_usd',
                'reason',
            ],
            'allowed_future_mutations' => [
                'prepare_adapter_invocation_metadata_on_provider_start_run',
                'append_adapter_invocation_boundary_evidence_event',
                'record_codex_real_invoker_post_start_adapter_invocation_boundary_metadata_on_observed_run',
            ],
            'forbidden_even_after_contract' => [
                'spawn_codex_process',
                'call_codex_cli_or_codex_app',
                'dispatch_work_to_codex',
                'send_prompt_to_provider',
                'call_provider_process',
                'enable_adapter_execution',
                'spend_provider_tokens',
                'mark_observed_run_running_or_terminal',
                'enable_self_programming',
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_adapter_invocation_boundary_gate_preflight',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_adapter_invocation_boundary_gate_contract.v1',
            'status' => (string) $contract['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_adapter_invocation_boundary_gate_contract',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'post_start_adapter_invocation_boundary_gate_allowed' => false,
            'actual_process_start_allowed' => false,
            'adapter_invocation_allowed' => false,
            'adapter_execution_allowed' => false,
            'provider_start_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_adapter_invocation_boundary_gate_contract' => $contract,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_adapter_invocation_boundary_gate_contract_hash' => $this->stableHash($contract),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_adapter_invocation_boundary_gate_contract_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_adapter_invocation_boundary_gate_contract_does_not_call_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_adapter_invocation_boundary_gate_contract_does_not_execute_adapter',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_adapter_invocation_boundary_gate_contract_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_adapter_invocation_boundary_gate_contract_does_not_dispatch_work',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_adapter_invocation_boundary_gate_contract_does_not_enable_self_programming',
            ],
            'human_summary' => 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start adapter invocation boundary gate contract is ready; it prepares adapter metadata without calling Codex.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProviderExecutionContractGatePreflight(array $options = []): array
    {
        $contractPayload = $this->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProviderExecutionContractGateContract($options);
        $contract = (array) data_get($contractPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_provider_execution_contract_gate_contract', []);
        $contractHash = (string) data_get($contractPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_provider_execution_contract_gate_contract_hash');
        $gateReady = class_exists(AgentCodexRealInvokerPostStartProviderExecutionContractGate::class)
            && method_exists(AgentCodexRealInvokerPostStartProviderExecutionContractGate::class, 'preparePostStartProviderExecutionContract');
        $invokerReady = class_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProviderExecutionContractGateInvoker::class)
            && method_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProviderExecutionContractGateInvoker::class, 'prepareCodexRealInvokerPostStartProviderExecutionContractGate');
        $driverReady = class_exists(AgentCodexProviderExecutionDriver::class)
            && method_exists(AgentCodexProviderExecutionDriver::class, 'prepareCodexExecution');
        $guardGateReady = class_exists(AgentCodexRealInvokerPostStartAdapterExecutionGuardGate::class)
            && method_exists(AgentCodexRealInvokerPostStartAdapterExecutionGuardGate::class, 'blockPostStartAdapterExecution');
        $registryReady = class_exists(AgentProviderAdapterRegistry::class)
            && method_exists(AgentProviderAdapterRegistry::class, 'resolve');
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $sandboxBindingTableReady = Schema::hasTable('atlas_self_construction_agent_sandbox_bindings');
        $ledgerTableReady = Schema::hasTable('atlas_ledger_events');

        $checks = [
            'post_start_provider_execution_contract_gate_contract_ready' => data_get($contractPayload, 'status') === 'one_shot_tick_codex_real_invoker_post_start_provider_execution_contract_gate_contract_ready',
            'post_start_provider_execution_contract_gate_contract_hash_present' => $contractHash !== '',
            'post_start_adapter_execution_guard_gate_status_ready' => data_get($contract, 'source_codex_real_invoker_post_start_adapter_execution_guard_gate_status') === 'one_shot_tick_codex_real_invoker_post_start_adapter_execution_guard_gate_service_ready',
            'generic_post_start_provider_execution_contract_gate_template_ready' => data_get($contract, 'source_codex_real_invoker_post_start_provider_execution_contract_gate_status') === 'codex_real_invoker_post_start_provider_execution_contract_gate_contract_template_ready',
            'codex_real_invoker_post_start_provider_execution_contract_gate_ready' => $gateReady,
            'codex_real_invoker_post_start_provider_execution_contract_gate_invoker_ready' => $invokerReady,
            'codex_provider_execution_driver_ready' => $driverReady,
            'codex_real_invoker_post_start_adapter_execution_guard_gate_ready' => $guardGateReady,
            'provider_adapter_registry_ready' => $registryReady,
            'canonical_post_start_provider_execution_contract_gate_method_ready' => data_get($contract, 'provider_execution_contract.canonical_post_start_provider_execution_contract_gate_method') === 'preparePostStartProviderExecutionContract',
            'scheduler_invoker_method_ready' => data_get($contract, 'provider_execution_contract.scheduler_invoker_method') === 'prepareCodexRealInvokerPostStartProviderExecutionContractGate',
            'contract_requires_adapter_execution_guard' => data_get($contract, 'provider_execution_contract.post_start_adapter_execution_guard_required_before_contract') === true,
            'contract_requires_post_start_evidence_acceptance_bridge' => data_get($contract, 'provider_execution_contract.post_start_evidence_acceptance_bridge_required_before_contract') === true,
            'contract_delegates_to_codex_provider_execution_driver' => data_get($contract, 'provider_execution_contract.gate_delegates_to_codex_provider_execution_driver') === true,
            'contract_requires_process_start_release' => data_get($contract, 'provider_execution_contract.process_start_release_required_before_any_later_process_start') === true,
            'contract_sets_provider_specific_execution_contract_ready' => data_get($contract, 'provider_execution_contract.provider_specific_execution_contract_ready_after_gate') === true,
            'contract_keeps_actual_process_start_disabled' => data_get($contract, 'provider_execution_contract.actual_process_start_allowed_by_contract') === false,
            'contract_keeps_adapter_execution_disabled' => data_get($contract, 'provider_execution_contract.adapter_execution_allowed_by_contract') === false,
            'contract_does_not_dispatch_work' => data_get($contract, 'provider_execution_contract.dispatch_allowed_by_contract') === false,
            'contract_does_not_spend_tokens' => data_get($contract, 'provider_execution_contract.token_spend_allowed_by_contract') === false,
            'agent_runs_table_ready' => $runsTableReady,
            'sandbox_bindings_table_ready' => $sandboxBindingTableReady,
            'ledger_table_ready' => $ledgerTableReady,
            'self_programming_forbidden' => in_array('enable_self_programming', (array) data_get($contract, 'forbidden_even_after_contract', []), true),
        ];
        $blockingReasons = array_values(array_keys(array_filter($checks, static fn (bool $passed): bool => ! $passed)));

        $preflight = [
            'status' => $blockingReasons === [] ? 'one_shot_tick_codex_real_invoker_post_start_provider_execution_contract_gate_preflight_ready' : 'blocked',
            'preflight_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-REAL-INVOKER-POST-START-PROVIDER-EXECUTION-CONTRACT-GATE-PREFLIGHT-SELF-CONSTRUCTION-0001',
            'source_post_start_provider_execution_contract_gate_contract_hash' => $contractHash,
            'preflight_checks' => $checks,
            'blocking_count' => count($blockingReasons),
            'blocking_reasons' => $blockingReasons,
            'implementation_requirements' => [
                'use_one_shot_scheduler_codex_real_invoker_post_start_provider_execution_contract_gate_invoker',
                'delegate_to_agent_codex_real_invoker_post_start_provider_execution_contract_gate',
                'require_codex_real_invoker_post_start_adapter_execution_guard_metadata',
                'require_provider_start_run_with_provider_adapter_execution_guard_metadata',
                'record_codex_provider_execution_contract_without_starting_codex',
                'preserve_process_start_disabled_until_codex_process_start_release_gate',
            ],
            'runtime_policy' => [
                'preflight_is_read_only' => true,
                'post_start_provider_execution_contract_gate_call_allowed_here' => false,
                'provider_execution_contract_allowed_by_future_invoker' => true,
                'codex_provider_execution_driver_allowed_by_future_invoker' => true,
                'provider_specific_execution_contract_ready_after_future_invoker' => true,
                'process_start_release_required_before_process_start' => true,
                'provider_external_process_start_allowed_here' => false,
                'provider_process_call_allowed_here' => false,
                'adapter_invocation_allowed_here' => false,
                'adapter_execution_allowed_here' => false,
                'dispatch_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'self_programming_allowed_here' => false,
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_provider_execution_contract_gate_implementation_packet',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_provider_execution_contract_gate_preflight.v1',
            'status' => (string) $preflight['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_provider_execution_contract_gate_preflight',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'post_start_provider_execution_contract_gate_allowed' => false,
            'actual_process_start_allowed' => false,
            'provider_process_call_allowed' => false,
            'adapter_invocation_allowed' => false,
            'adapter_execution_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_provider_execution_contract_gate_preflight' => $preflight,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_provider_execution_contract_gate_preflight_hash' => $this->stableHash($preflight),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_provider_execution_contract_gate_preflight_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_provider_execution_contract_gate_preflight_does_not_call_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_provider_execution_contract_gate_preflight_does_not_execute_adapter',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_provider_execution_contract_gate_preflight_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_provider_execution_contract_gate_preflight_does_not_dispatch_work',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_provider_execution_contract_gate_preflight_does_not_enable_self_programming',
            ],
            'human_summary' => $blockingReasons === []
                ? 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start provider execution contract gate preflight is ready.'
                : 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start provider execution contract gate preflight is blocked until guard, driver, sandbox and storage prerequisites are ready.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartFinalProcessSpawnExecutorGatePreflight(array $options = []): array
    {
        $contractPayload = $this->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartFinalProcessSpawnExecutorGateContract($options);
        $contract = (array) data_get($contractPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_final_process_spawn_executor_gate_contract', []);
        $contractHash = (string) data_get($contractPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_final_process_spawn_executor_gate_contract_hash');
        $gateReady = class_exists(AgentCodexRealInvokerPostStartFinalProcessSpawnExecutorGate::class)
            && method_exists(AgentCodexRealInvokerPostStartFinalProcessSpawnExecutorGate::class, 'preparePostStartFinalProcessSpawn');
        $invokerReady = class_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartFinalProcessSpawnExecutorGateInvoker::class)
            && method_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartFinalProcessSpawnExecutorGateInvoker::class, 'prepareCodexRealInvokerPostStartFinalProcessSpawnExecutorGate');
        $spawnExecutorReady = class_exists(AgentCodexProcessSpawnExecutor::class)
            && method_exists(AgentCodexProcessSpawnExecutor::class, 'prepareProcessSpawn');
        $spawnEnablementGateReady = class_exists(AgentCodexRealInvokerPostStartProcessSpawnEnablementGate::class)
            && method_exists(AgentCodexRealInvokerPostStartProcessSpawnEnablementGate::class, 'enablePostStartProcessSpawn');
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerTableReady = Schema::hasTable('atlas_ledger_events');

        $checks = [
            'post_start_final_process_spawn_executor_gate_contract_ready' => data_get($contractPayload, 'status') === 'one_shot_tick_codex_real_invoker_post_start_final_process_spawn_executor_gate_contract_ready',
            'post_start_final_process_spawn_executor_gate_contract_hash_present' => $contractHash !== '',
            'post_start_process_spawn_enablement_gate_status_ready' => data_get($contract, 'source_codex_real_invoker_post_start_process_spawn_enablement_gate_status') === 'one_shot_tick_codex_real_invoker_post_start_process_spawn_enablement_gate_service_ready',
            'generic_post_start_final_process_spawn_executor_gate_template_ready' => data_get($contract, 'source_codex_real_invoker_post_start_final_process_spawn_executor_gate_status') === 'codex_real_invoker_post_start_final_process_spawn_executor_gate_contract_template_ready',
            'codex_real_invoker_post_start_final_process_spawn_executor_gate_ready' => $gateReady,
            'codex_real_invoker_post_start_final_process_spawn_executor_gate_invoker_ready' => $invokerReady,
            'codex_process_spawn_executor_ready' => $spawnExecutorReady,
            'codex_real_invoker_post_start_process_spawn_enablement_gate_ready' => $spawnEnablementGateReady,
            'canonical_post_start_final_process_spawn_executor_gate_method_ready' => data_get($contract, 'final_process_spawn_executor.canonical_post_start_final_process_spawn_executor_gate_method') === 'preparePostStartFinalProcessSpawn',
            'scheduler_invoker_method_ready' => data_get($contract, 'final_process_spawn_executor.scheduler_invoker_method') === 'prepareCodexRealInvokerPostStartFinalProcessSpawnExecutorGate',
            'contract_requires_process_spawn_enablement' => data_get($contract, 'final_process_spawn_executor.post_start_process_spawn_enablement_required_before_executor') === true,
            'contract_requires_post_start_evidence_acceptance_bridge' => data_get($contract, 'final_process_spawn_executor.post_start_evidence_acceptance_bridge_required_before_executor') === true,
            'contract_requires_provider_start_run_process_spawn_enablement' => data_get($contract, 'final_process_spawn_executor.provider_start_run_with_codex_process_spawn_enablement_required_before_executor') === true,
            'contract_requires_operator_final_spawn_receipt_hash' => data_get($contract, 'final_process_spawn_executor.operator_final_spawn_receipt_hash_required') === true,
            'contract_requires_runtime_supervision_plan_hash' => data_get($contract, 'final_process_spawn_executor.runtime_supervision_plan_hash_required') === true,
            'contract_requires_stdout_stderr_sink_hash' => data_get($contract, 'final_process_spawn_executor.stdout_stderr_sink_hash_required') === true,
            'contract_requires_liveness_probe_hash' => data_get($contract, 'final_process_spawn_executor.liveness_probe_hash_required') === true,
            'contract_delegates_to_codex_process_spawn_executor' => data_get($contract, 'final_process_spawn_executor.gate_delegates_to_codex_process_spawn_executor') === true,
            'contract_requires_external_process_runtime_after_executor' => data_get($contract, 'final_process_spawn_executor.external_process_runtime_required_after_executor') === true,
            'contract_declares_executor_is_not_external_runtime' => data_get($contract, 'final_process_spawn_executor.final_process_spawn_executor_is_not_external_process_runtime') === true,
            'contract_keeps_actual_process_start_disabled' => data_get($contract, 'final_process_spawn_executor.actual_process_start_allowed_by_contract') === false,
            'contract_keeps_adapter_execution_disabled' => data_get($contract, 'final_process_spawn_executor.adapter_execution_allowed_by_contract') === false,
            'contract_does_not_dispatch_work' => data_get($contract, 'final_process_spawn_executor.dispatch_allowed_by_contract') === false,
            'contract_does_not_spend_tokens' => data_get($contract, 'final_process_spawn_executor.token_spend_allowed_by_contract') === false,
            'agent_runs_table_ready' => $runsTableReady,
            'ledger_table_ready' => $ledgerTableReady,
            'self_programming_forbidden' => in_array('enable_self_programming', (array) data_get($contract, 'forbidden_even_after_contract', []), true),
        ];
        $blockingReasons = array_values(array_keys(array_filter($checks, static fn (bool $passed): bool => ! $passed)));

        $preflight = [
            'status' => $blockingReasons === [] ? 'one_shot_tick_codex_real_invoker_post_start_final_process_spawn_executor_gate_preflight_ready' : 'blocked',
            'preflight_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-REAL-INVOKER-POST-START-FINAL-PROCESS-SPAWN-EXECUTOR-GATE-PREFLIGHT-SELF-CONSTRUCTION-0001',
            'source_post_start_final_process_spawn_executor_gate_contract_hash' => $contractHash,
            'preflight_checks' => $checks,
            'blocking_count' => count($blockingReasons),
            'blocking_reasons' => $blockingReasons,
            'implementation_requirements' => [
                'use_one_shot_scheduler_codex_real_invoker_post_start_final_process_spawn_executor_gate_invoker',
                'delegate_to_agent_codex_real_invoker_post_start_final_process_spawn_executor_gate',
                'require_codex_real_invoker_post_start_process_spawn_enablement_metadata',
                'require_post_start_evidence_acceptance_bridge_metadata',
                'record_final_process_spawn_executor_without_running_external_runtime',
                'preserve_actual_process_start_disabled_until_external_process_runtime_gate',
            ],
            'runtime_policy' => [
                'preflight_is_read_only' => true,
                'post_start_final_process_spawn_executor_gate_call_allowed_here' => false,
                'post_start_final_process_spawn_executor_allowed_by_future_invoker' => true,
                'codex_process_spawn_executor_allowed_by_future_invoker' => true,
                'final_process_spawn_executor_is_not_external_process_runtime' => true,
                'external_process_runtime_required_after_future_invoker' => true,
                'actual_process_start_allowed_here' => false,
                'provider_process_call_allowed_here' => false,
                'adapter_invocation_allowed_here' => false,
                'adapter_execution_allowed_here' => false,
                'dispatch_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'self_programming_allowed_here' => false,
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_final_process_spawn_executor_gate_implementation_packet',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_final_process_spawn_executor_gate_preflight.v1',
            'status' => (string) $preflight['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_final_process_spawn_executor_gate_preflight',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'post_start_final_process_spawn_executor_gate_allowed' => false,
            'actual_process_start_allowed' => false,
            'provider_process_call_allowed' => false,
            'adapter_invocation_allowed' => false,
            'adapter_execution_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_final_process_spawn_executor_gate_preflight' => $preflight,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_final_process_spawn_executor_gate_preflight_hash' => $this->stableHash($preflight),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_final_process_spawn_executor_gate_preflight_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_final_process_spawn_executor_gate_preflight_does_not_call_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_final_process_spawn_executor_gate_preflight_does_not_execute_adapter',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_final_process_spawn_executor_gate_preflight_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_final_process_spawn_executor_gate_preflight_does_not_dispatch_work',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_final_process_spawn_executor_gate_preflight_does_not_enable_self_programming',
            ],
            'human_summary' => $blockingReasons === []
                ? 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start final process spawn executor gate preflight is ready.'
                : 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start final process spawn executor gate preflight is blocked until process spawn enablement, final executor gate and storage prerequisites are ready.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartExternalProcessRuntimeGatePreflight(array $options = []): array
    {
        $contractPayload = $this->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartExternalProcessRuntimeGateContract($options);
        $contract = (array) data_get($contractPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_external_process_runtime_gate_contract', []);
        $contractHash = (string) data_get($contractPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_external_process_runtime_gate_contract_hash');
        $gateReady = class_exists(AgentCodexRealInvokerPostStartExternalProcessRuntimeGate::class)
            && method_exists(AgentCodexRealInvokerPostStartExternalProcessRuntimeGate::class, 'preparePostStartExternalRuntime');
        $invokerReady = class_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartExternalProcessRuntimeGateInvoker::class)
            && method_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartExternalProcessRuntimeGateInvoker::class, 'prepareCodexRealInvokerPostStartExternalProcessRuntimeGate');
        $externalRuntimeDriverReady = class_exists(AgentCodexExternalProcessRuntimeDriver::class)
            && method_exists(AgentCodexExternalProcessRuntimeDriver::class, 'prepareExternalRuntime');
        $finalSpawnGateReady = class_exists(AgentCodexRealInvokerPostStartFinalProcessSpawnExecutorGate::class)
            && method_exists(AgentCodexRealInvokerPostStartFinalProcessSpawnExecutorGate::class, 'preparePostStartFinalProcessSpawn');
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerTableReady = Schema::hasTable('atlas_ledger_events');

        $checks = [
            'post_start_external_process_runtime_gate_contract_ready' => data_get($contractPayload, 'status') === 'one_shot_tick_codex_real_invoker_post_start_external_process_runtime_gate_contract_ready',
            'post_start_external_process_runtime_gate_contract_hash_present' => $contractHash !== '',
            'post_start_final_process_spawn_executor_gate_status_ready' => data_get($contract, 'source_codex_real_invoker_post_start_final_process_spawn_executor_gate_status') === 'one_shot_tick_codex_real_invoker_post_start_final_process_spawn_executor_gate_service_ready',
            'generic_post_start_external_process_runtime_gate_template_ready' => data_get($contract, 'source_codex_real_invoker_post_start_external_process_runtime_gate_status') === 'codex_real_invoker_post_start_external_process_runtime_gate_contract_template_ready',
            'codex_real_invoker_post_start_external_process_runtime_gate_ready' => $gateReady,
            'codex_real_invoker_post_start_external_process_runtime_gate_invoker_ready' => $invokerReady,
            'codex_external_process_runtime_driver_ready' => $externalRuntimeDriverReady,
            'codex_real_invoker_post_start_final_process_spawn_executor_gate_ready' => $finalSpawnGateReady,
            'canonical_post_start_external_process_runtime_gate_method_ready' => data_get($contract, 'external_process_runtime.canonical_post_start_external_process_runtime_gate_method') === 'preparePostStartExternalRuntime',
            'scheduler_invoker_method_ready' => data_get($contract, 'external_process_runtime.scheduler_invoker_method') === 'prepareCodexRealInvokerPostStartExternalProcessRuntimeGate',
            'contract_requires_final_process_spawn_executor' => data_get($contract, 'external_process_runtime.post_start_final_process_spawn_executor_required_before_runtime') === true,
            'contract_requires_post_start_evidence_acceptance_bridge' => data_get($contract, 'external_process_runtime.post_start_evidence_acceptance_bridge_required_before_runtime') === true,
            'contract_requires_provider_start_run_process_spawn_executor' => data_get($contract, 'external_process_runtime.provider_start_run_with_codex_process_spawn_executor_required_before_runtime') === true,
            'contract_requires_operator_runtime_receipt_hash' => data_get($contract, 'external_process_runtime.operator_runtime_receipt_hash_required') === true,
            'contract_requires_process_command_hash' => data_get($contract, 'external_process_runtime.process_command_hash_required') === true,
            'contract_requires_environment_contract_hash' => data_get($contract, 'external_process_runtime.environment_contract_hash_required') === true,
            'contract_requires_termination_policy_hash' => data_get($contract, 'external_process_runtime.termination_policy_hash_required') === true,
            'contract_delegates_to_codex_external_process_runtime_driver' => data_get($contract, 'external_process_runtime.gate_delegates_to_codex_external_process_runtime_driver') === true,
            'contract_requires_process_invocation_authorization_after_runtime' => data_get($contract, 'external_process_runtime.process_invocation_authorization_required_after_runtime') === true,
            'contract_declares_runtime_is_not_process_invocation' => data_get($contract, 'external_process_runtime.external_process_runtime_is_not_process_invocation') === true,
            'contract_keeps_actual_process_start_disabled' => data_get($contract, 'external_process_runtime.actual_process_start_allowed_by_contract') === false,
            'contract_keeps_adapter_execution_disabled' => data_get($contract, 'external_process_runtime.adapter_execution_allowed_by_contract') === false,
            'contract_does_not_dispatch_work' => data_get($contract, 'external_process_runtime.dispatch_allowed_by_contract') === false,
            'contract_does_not_spend_tokens' => data_get($contract, 'external_process_runtime.token_spend_allowed_by_contract') === false,
            'agent_runs_table_ready' => $runsTableReady,
            'ledger_table_ready' => $ledgerTableReady,
            'self_programming_forbidden' => in_array('enable_self_programming', (array) data_get($contract, 'forbidden_even_after_contract', []), true),
        ];
        $blockingReasons = array_values(array_keys(array_filter($checks, static fn (bool $passed): bool => ! $passed)));

        $preflight = [
            'status' => $blockingReasons === [] ? 'one_shot_tick_codex_real_invoker_post_start_external_process_runtime_gate_preflight_ready' : 'blocked',
            'preflight_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-REAL-INVOKER-POST-START-EXTERNAL-PROCESS-RUNTIME-GATE-PREFLIGHT-SELF-CONSTRUCTION-0001',
            'source_post_start_external_process_runtime_gate_contract_hash' => $contractHash,
            'preflight_checks' => $checks,
            'blocking_count' => count($blockingReasons),
            'blocking_reasons' => $blockingReasons,
            'implementation_requirements' => [
                'use_one_shot_scheduler_codex_real_invoker_post_start_external_process_runtime_gate_invoker',
                'delegate_to_agent_codex_real_invoker_post_start_external_process_runtime_gate',
                'require_codex_real_invoker_post_start_final_process_spawn_executor_metadata',
                'require_post_start_evidence_acceptance_bridge_metadata',
                'record_external_process_runtime_without_process_invocation',
                'preserve_actual_process_start_disabled_until_process_invocation_authorization_gate',
            ],
            'runtime_policy' => [
                'preflight_is_read_only' => true,
                'post_start_external_process_runtime_gate_call_allowed_here' => false,
                'post_start_external_process_runtime_allowed_by_future_invoker' => true,
                'codex_external_process_runtime_driver_allowed_by_future_invoker' => true,
                'external_process_runtime_is_not_process_invocation' => true,
                'process_invocation_authorization_required_after_future_invoker' => true,
                'actual_process_start_allowed_here' => false,
                'provider_process_call_allowed_here' => false,
                'adapter_invocation_allowed_here' => false,
                'adapter_execution_allowed_here' => false,
                'dispatch_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'self_programming_allowed_here' => false,
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_external_process_runtime_gate_implementation_packet',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_external_process_runtime_gate_preflight.v1',
            'status' => (string) $preflight['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_external_process_runtime_gate_preflight',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'post_start_external_process_runtime_gate_allowed' => false,
            'actual_process_start_allowed' => false,
            'provider_process_call_allowed' => false,
            'adapter_invocation_allowed' => false,
            'adapter_execution_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_external_process_runtime_gate_preflight' => $preflight,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_external_process_runtime_gate_preflight_hash' => $this->stableHash($preflight),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_external_process_runtime_gate_preflight_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_external_process_runtime_gate_preflight_does_not_call_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_external_process_runtime_gate_preflight_does_not_execute_adapter',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_external_process_runtime_gate_preflight_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_external_process_runtime_gate_preflight_does_not_dispatch_work',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_external_process_runtime_gate_preflight_does_not_enable_self_programming',
            ],
            'human_summary' => $blockingReasons === []
                ? 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start external process runtime gate preflight is ready.'
                : 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start external process runtime gate preflight is blocked until final spawn, external runtime driver and storage prerequisites are ready.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerImplementationBoundaryContract(array $options = []): array
    {
        $signedGateStatusPayload = $this->agentAutomaticDispatchSchedulerOneShotTickCodexSignedRealInvokerReleaseGateStatus($options);
        $signedGateStatus = (array) data_get($signedGateStatusPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_signed_real_invoker_release_gate_status', []);
        $boundaryPayload = $this->agentCodexRealInvokerImplementationBoundaryContractTemplate($options);

        $contract = [
            'status' => 'one_shot_tick_codex_real_invoker_implementation_boundary_contract_ready',
            'contract_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-REAL-INVOKER-IMPLEMENTATION-BOUNDARY-CONTRACT-SELF-CONSTRUCTION-0001',
            'parent_program' => 'Atlas Self-Construction OS',
            'submodule' => 'Atlas Agent Control Plane',
            'provider' => 'codex',
            'adapter' => 'codex',
            'source_codex_signed_real_invoker_release_gate_status' => data_get($signedGateStatus, 'status'),
            'source_codex_signed_real_invoker_release_gate_status_hash' => data_get($signedGateStatusPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_signed_real_invoker_release_gate_status_hash'),
            'source_codex_real_invoker_implementation_boundary_contract_status' => data_get($boundaryPayload, 'status'),
            'source_codex_real_invoker_implementation_boundary_contract_hash' => data_get($boundaryPayload, 'codex_real_invoker_implementation_boundary_contract_template_hash'),
            'release_boundary' => [
                'canonical_implementation_boundary' => AgentCodexRealInvokerImplementationBoundary::class,
                'canonical_implementation_boundary_method' => 'prepareBoundary',
                'scheduler_invoker' => AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerImplementationBoundaryInvoker::class,
                'scheduler_invoker_method' => 'prepareCodexRealInvokerImplementationBoundary',
                'implementation_boundary_effect' => 'prepare_codex_real_invoker_implementation_boundary_metadata_without_real_process_invocation',
                'external_process_started_by_implementation_boundary' => false,
                'provider_started_by_implementation_boundary' => false,
                'adapter_execution_allowed_by_implementation_boundary' => false,
                'token_spend_allowed_by_implementation_boundary' => false,
                'required_signed_release_status_before_boundary' => 'signed_real_invoker_release_authorized_pending_invoker_implementation',
                'prepared_status_after_boundary' => 'real_invoker_implementation_boundary_prepared_pending_executor',
                'idempotency_key' => 'real_invoker_implementation_boundary_id',
            ],
            'required_input_fields_for_future_invoker' => [
                'run_key',
                'codex_execution_id',
                'process_start_release_id',
                'supervised_start_id',
                'spawn_enablement_id',
                'spawn_executor_id',
                'runtime_driver_id',
                'invocation_authorization_id',
                'dry_run_id',
                'real_invoker_release_preflight_id',
                'signed_real_invoker_release_id',
                'real_invoker_implementation_boundary_id',
                'operator_implementation_boundary_receipt_hash',
                'real_invoker_contract_hash',
                'release_policy_hash',
                'implementation_plan_hash',
                'process_command_hash',
                'environment_contract_hash',
                'termination_policy_hash',
                'stdout_stderr_sink_hash',
                'liveness_probe_hash',
                'rollback_plan_hash',
                'max_runtime_policy_hash',
                'actor',
                'session',
                'reason',
            ],
            'allowed_future_mutations' => [
                'write_codex_real_invoker_implementation_boundary_metadata_on_agent_run',
                'append_codex_real_invoker_implementation_boundary_prepared_evidence_event',
            ],
            'forbidden_even_after_contract' => [
                'spawn_codex_process',
                'call_codex_cli_or_codex_app',
                'spawn_shell_or_subprocess',
                'spend_provider_tokens',
                'mark_run_running_or_terminal',
                'mark_packet_completed',
                'merge_work_products',
                'enable_self_programming',
            ],
            'handoff_policy' => [
                'implementation_boundary_is_envelope_not_invocation' => true,
                'executor_plan_requires_separate_contract' => true,
                'operator_implementation_boundary_receipt_hash_required' => true,
                'implementation_plan_hash_required' => true,
                'real_invoker_contract_hash_required' => true,
                'release_policy_hash_required' => true,
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_implementation_boundary_preflight',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_implementation_boundary_contract.v1',
            'status' => (string) $contract['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_implementation_boundary_contract',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_implementation_boundary_allowed' => false,
            'adapter_execution_allowed' => false,
            'provider_start_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_implementation_boundary_contract' => $contract,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_implementation_boundary_contract_hash' => $this->stableHash($contract),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_implementation_boundary_contract_does_not_call_boundary',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_implementation_boundary_contract_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_implementation_boundary_contract_does_not_call_adapters',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_implementation_boundary_contract_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_implementation_boundary_contract_does_not_enable_self_programming',
            ],
            'human_summary' => 'Automatic dispatch scheduler one-shot tick Codex real invoker implementation boundary contract is ready; it prepares a future executor envelope but cannot start Codex.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartSupervisedStartExecutorGatePreflight(array $options = []): array
    {
        $contractPayload = $this->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartSupervisedStartExecutorGateContract($options);
        $contract = (array) data_get($contractPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_supervised_start_executor_gate_contract', []);
        $contractHash = (string) data_get($contractPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_supervised_start_executor_gate_contract_hash');
        $gateReady = class_exists(AgentCodexRealInvokerPostStartSupervisedStartExecutorGate::class)
            && method_exists(AgentCodexRealInvokerPostStartSupervisedStartExecutorGate::class, 'preparePostStartSupervisedStart');
        $invokerReady = class_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartSupervisedStartExecutorGateInvoker::class)
            && method_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartSupervisedStartExecutorGateInvoker::class, 'prepareCodexRealInvokerPostStartSupervisedStartExecutorGate');
        $supervisedStartReady = class_exists(AgentCodexSupervisedStartExecutor::class)
            && method_exists(AgentCodexSupervisedStartExecutor::class, 'prepareSupervisedStart');
        $processStartReleaseGateReady = class_exists(AgentCodexRealInvokerPostStartProcessStartReleaseGate::class)
            && method_exists(AgentCodexRealInvokerPostStartProcessStartReleaseGate::class, 'authorizePostStartProcessStartRelease');
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerTableReady = Schema::hasTable('atlas_ledger_events');

        $checks = [
            'post_start_supervised_start_executor_gate_contract_ready' => data_get($contractPayload, 'status') === 'one_shot_tick_codex_real_invoker_post_start_supervised_start_executor_gate_contract_ready',
            'post_start_supervised_start_executor_gate_contract_hash_present' => $contractHash !== '',
            'post_start_process_start_release_gate_status_ready' => data_get($contract, 'source_codex_real_invoker_post_start_process_start_release_gate_status') === 'one_shot_tick_codex_real_invoker_post_start_process_start_release_gate_service_ready',
            'generic_post_start_supervised_start_executor_gate_template_ready' => data_get($contract, 'source_codex_real_invoker_post_start_supervised_start_executor_gate_status') === 'codex_real_invoker_post_start_supervised_start_executor_gate_contract_template_ready',
            'codex_real_invoker_post_start_supervised_start_executor_gate_ready' => $gateReady,
            'codex_real_invoker_post_start_supervised_start_executor_gate_invoker_ready' => $invokerReady,
            'codex_supervised_start_executor_ready' => $supervisedStartReady,
            'codex_real_invoker_post_start_process_start_release_gate_ready' => $processStartReleaseGateReady,
            'canonical_post_start_supervised_start_executor_gate_method_ready' => data_get($contract, 'supervised_start.canonical_post_start_supervised_start_executor_gate_method') === 'preparePostStartSupervisedStart',
            'scheduler_invoker_method_ready' => data_get($contract, 'supervised_start.scheduler_invoker_method') === 'prepareCodexRealInvokerPostStartSupervisedStartExecutorGate',
            'contract_requires_process_start_release' => data_get($contract, 'supervised_start.post_start_process_start_release_required_before_supervised_start') === true,
            'contract_requires_post_start_evidence_acceptance_bridge' => data_get($contract, 'supervised_start.post_start_evidence_acceptance_bridge_required_before_supervised_start') === true,
            'contract_requires_provider_start_run_release' => data_get($contract, 'supervised_start.provider_start_run_with_codex_process_start_release_required_before_supervised_start') === true,
            'contract_requires_stdout_stderr_sanitizer_hash' => data_get($contract, 'supervised_start.stdout_stderr_sanitizer_hash_required') === true,
            'contract_requires_ready_probe_plan_hash' => data_get($contract, 'supervised_start.ready_probe_plan_hash_required') === true,
            'contract_requires_rollback_plan_hash' => data_get($contract, 'supervised_start.rollback_plan_hash_required') === true,
            'contract_delegates_to_codex_supervised_start_executor' => data_get($contract, 'supervised_start.gate_delegates_to_codex_supervised_start_executor') === true,
            'contract_requires_process_spawn_enablement_after_supervised_start' => data_get($contract, 'supervised_start.process_spawn_enablement_required_after_supervised_start') === true,
            'contract_declares_supervised_start_is_not_process_spawn' => data_get($contract, 'supervised_start.supervised_start_preparation_is_not_process_spawn') === true,
            'contract_keeps_actual_process_start_disabled' => data_get($contract, 'supervised_start.actual_process_start_allowed_by_contract') === false,
            'contract_keeps_adapter_execution_disabled' => data_get($contract, 'supervised_start.adapter_execution_allowed_by_contract') === false,
            'contract_does_not_dispatch_work' => data_get($contract, 'supervised_start.dispatch_allowed_by_contract') === false,
            'contract_does_not_spend_tokens' => data_get($contract, 'supervised_start.token_spend_allowed_by_contract') === false,
            'agent_runs_table_ready' => $runsTableReady,
            'ledger_table_ready' => $ledgerTableReady,
            'self_programming_forbidden' => in_array('enable_self_programming', (array) data_get($contract, 'forbidden_even_after_contract', []), true),
        ];
        $blockingReasons = array_values(array_keys(array_filter($checks, static fn (bool $passed): bool => ! $passed)));

        $preflight = [
            'status' => $blockingReasons === [] ? 'one_shot_tick_codex_real_invoker_post_start_supervised_start_executor_gate_preflight_ready' : 'blocked',
            'preflight_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-REAL-INVOKER-POST-START-SUPERVISED-START-EXECUTOR-GATE-PREFLIGHT-SELF-CONSTRUCTION-0001',
            'source_post_start_supervised_start_executor_gate_contract_hash' => $contractHash,
            'preflight_checks' => $checks,
            'blocking_count' => count($blockingReasons),
            'blocking_reasons' => $blockingReasons,
            'implementation_requirements' => [
                'use_one_shot_scheduler_codex_real_invoker_post_start_supervised_start_executor_gate_invoker',
                'delegate_to_agent_codex_real_invoker_post_start_supervised_start_executor_gate',
                'require_codex_real_invoker_post_start_process_start_release_metadata',
                'require_post_start_evidence_acceptance_bridge_metadata',
                'record_supervised_start_preparation_without_spawning_codex',
                'preserve_actual_process_start_disabled_until_process_spawn_enablement_gate',
            ],
            'runtime_policy' => [
                'preflight_is_read_only' => true,
                'post_start_supervised_start_executor_gate_call_allowed_here' => false,
                'post_start_supervised_start_preparation_allowed_by_future_invoker' => true,
                'codex_supervised_start_executor_allowed_by_future_invoker' => true,
                'supervised_start_preparation_is_not_process_spawn' => true,
                'process_spawn_enablement_required_after_future_invoker' => true,
                'actual_process_start_allowed_here' => false,
                'provider_process_call_allowed_here' => false,
                'adapter_invocation_allowed_here' => false,
                'adapter_execution_allowed_here' => false,
                'dispatch_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'self_programming_allowed_here' => false,
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_supervised_start_executor_gate_implementation_packet',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_supervised_start_executor_gate_preflight.v1',
            'status' => (string) $preflight['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_supervised_start_executor_gate_preflight',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'post_start_supervised_start_executor_gate_allowed' => false,
            'actual_process_start_allowed' => false,
            'provider_process_call_allowed' => false,
            'adapter_invocation_allowed' => false,
            'adapter_execution_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_supervised_start_executor_gate_preflight' => $preflight,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_supervised_start_executor_gate_preflight_hash' => $this->stableHash($preflight),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_supervised_start_executor_gate_preflight_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_supervised_start_executor_gate_preflight_does_not_call_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_supervised_start_executor_gate_preflight_does_not_execute_adapter',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_supervised_start_executor_gate_preflight_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_supervised_start_executor_gate_preflight_does_not_dispatch_work',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_supervised_start_executor_gate_preflight_does_not_enable_self_programming',
            ],
            'human_summary' => $blockingReasons === []
                ? 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start supervised start executor gate preflight is ready.'
                : 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start supervised start executor gate preflight is blocked until release, supervised executor and storage prerequisites are ready.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartSupervisedStartActivationGatePreflight(array $options = []): array
    {
        $contractPayload = $this->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartSupervisedStartActivationGateContract($options);
        $contract = (array) data_get($contractPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_supervised_start_activation_gate_contract', []);
        $contractHash = (string) data_get($contractPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_supervised_start_activation_gate_contract_hash');
        $gateReady = class_exists(AgentCodexRealInvokerPostStartSupervisedStartActivationGate::class)
            && method_exists(AgentCodexRealInvokerPostStartSupervisedStartActivationGate::class, 'preparePostStartSupervisedStartActivation');
        $invokerReady = class_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartSupervisedStartActivationGateInvoker::class)
            && method_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartSupervisedStartActivationGateInvoker::class, 'prepareCodexRealInvokerPostStartSupervisedStartActivationGate');
        $activationReady = class_exists(AgentCodexRealInvokerSupervisedStartActivationGate::class)
            && method_exists(AgentCodexRealInvokerSupervisedStartActivationGate::class, 'prepareActivation');
        $enablementReady = class_exists(AgentCodexRealInvokerPostStartExecutorEnablementGate::class)
            && method_exists(AgentCodexRealInvokerPostStartExecutorEnablementGate::class, 'enablePostStartExecutor');
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerTableReady = Schema::hasTable('atlas_ledger_events');

        $checks = [
            'post_start_supervised_start_activation_gate_contract_ready' => data_get($contractPayload, 'status') === 'one_shot_tick_codex_real_invoker_post_start_supervised_start_activation_gate_contract_ready',
            'post_start_supervised_start_activation_gate_contract_hash_present' => $contractHash !== '',
            'post_start_executor_enablement_gate_status_ready' => data_get($contract, 'source_codex_real_invoker_post_start_executor_enablement_gate_status') === 'one_shot_tick_codex_real_invoker_post_start_executor_enablement_gate_service_ready',
            'generic_post_start_supervised_start_activation_gate_template_ready' => data_get($contract, 'source_codex_real_invoker_post_start_supervised_start_activation_gate_contract_status') === 'codex_real_invoker_post_start_supervised_start_activation_gate_contract_template_ready',
            'generic_supervised_start_activation_gate_template_ready' => data_get($contract, 'source_codex_real_invoker_supervised_start_activation_gate_contract_status') === 'codex_real_invoker_supervised_start_activation_gate_contract_template_ready',
            'codex_real_invoker_post_start_supervised_start_activation_gate_ready' => $gateReady,
            'codex_real_invoker_post_start_supervised_start_activation_gate_invoker_ready' => $invokerReady,
            'codex_real_invoker_supervised_start_activation_gate_ready' => $activationReady,
            'codex_real_invoker_post_start_executor_enablement_gate_ready' => $enablementReady,
            'canonical_post_start_supervised_start_activation_gate_method_ready' => data_get($contract, 'activation.canonical_post_start_supervised_start_activation_gate_method') === 'preparePostStartSupervisedStartActivation',
            'scheduler_invoker_method_ready' => data_get($contract, 'activation.scheduler_invoker_method') === 'prepareCodexRealInvokerPostStartSupervisedStartActivationGate',
            'contract_requires_post_start_executor_enablement' => data_get($contract, 'activation.post_start_executor_enablement_required_before_activation') === true,
            'contract_requires_post_start_evidence_acceptance_bridge' => data_get($contract, 'activation.post_start_evidence_acceptance_bridge_required_before_activation') === true,
            'contract_delegates_to_codex_real_invoker_supervised_start_activation_gate' => data_get($contract, 'activation.gate_delegates_to_codex_real_invoker_supervised_start_activation_gate') === true,
            'contract_declares_activation_is_not_process_start' => data_get($contract, 'activation.activation_is_not_process_start') === true,
            'contract_requires_guarded_process_start_after_activation' => in_array('authorize_guarded_process_start_without_separate_contract', (array) data_get($contract, 'forbidden_even_after_contract', []), true),
            'contract_allows_process_start_armed_by_future_invoker' => data_get($contract, 'activation.process_start_armed_by_contract') === true,
            'contract_keeps_actual_process_start_disabled' => data_get($contract, 'activation.actual_process_start_allowed_by_contract') === false,
            'contract_keeps_adapter_execution_disabled' => data_get($contract, 'activation.adapter_execution_allowed_by_contract') === false,
            'contract_does_not_dispatch_work' => data_get($contract, 'activation.dispatch_allowed_by_contract') === false,
            'contract_does_not_spend_tokens' => data_get($contract, 'activation.token_spend_allowed_by_contract') === false,
            'agent_runs_table_ready' => $runsTableReady,
            'ledger_table_ready' => $ledgerTableReady,
            'self_programming_forbidden' => in_array('enable_self_programming', (array) data_get($contract, 'forbidden_even_after_contract', []), true),
        ];
        $blockingReasons = array_values(array_keys(array_filter($checks, static fn (bool $passed): bool => ! $passed)));

        $preflight = [
            'status' => $blockingReasons === [] ? 'one_shot_tick_codex_real_invoker_post_start_supervised_start_activation_gate_preflight_ready' : 'blocked',
            'preflight_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-REAL-INVOKER-POST-START-SUPERVISED-START-ACTIVATION-GATE-PREFLIGHT-SELF-CONSTRUCTION-0001',
            'source_post_start_supervised_start_activation_gate_contract_hash' => $contractHash,
            'preflight_checks' => $checks,
            'blocking_count' => count($blockingReasons),
            'blocking_reasons' => $blockingReasons,
            'implementation_requirements' => [
                'create_scheduler_specific_post_start_supervised_start_activation_gate_invoker',
                'delegate_to_agent_codex_real_invoker_post_start_supervised_start_activation_gate',
                'require_post_start_executor_enablement_metadata',
                'require_post_start_evidence_acceptance_bridge',
                'require_operator_start_activation_receipt_hash',
                'project_readiness_status_without_invoking_codex',
            ],
            'runtime_policy' => [
                'preflight_is_read_only' => true,
                'post_start_supervised_start_activation_gate_call_allowed_by_future_invoker' => true,
                'process_start_armed_after_future_invoker' => true,
                'guarded_process_start_executor_required_after_activation' => true,
                'actual_process_start_allowed_here' => false,
                'provider_process_call_allowed_here' => false,
                'executor_enabled_here' => false,
                'process_start_armed_here' => false,
                'adapter_invocation_allowed_here' => false,
                'adapter_execution_allowed_here' => false,
                'dispatch_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'self_programming_allowed_here' => false,
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_supervised_start_activation_gate_implementation_packet',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_supervised_start_activation_gate_preflight.v1',
            'status' => (string) $preflight['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_supervised_start_activation_gate_preflight',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'post_start_supervised_start_activation_gate_allowed' => false,
            'actual_process_start_allowed' => false,
            'provider_process_call_allowed' => false,
            'executor_enabled' => false,
            'process_start_armed' => false,
            'adapter_invocation_allowed' => false,
            'adapter_execution_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_supervised_start_activation_gate_preflight' => $preflight,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_supervised_start_activation_gate_preflight_hash' => $this->stableHash($preflight),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_supervised_start_activation_gate_preflight_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_supervised_start_activation_gate_preflight_does_not_call_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_supervised_start_activation_gate_preflight_does_not_execute_adapter',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_supervised_start_activation_gate_preflight_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_supervised_start_activation_gate_preflight_does_not_dispatch_work',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_supervised_start_activation_gate_preflight_does_not_enable_self_programming',
            ],
            'human_summary' => $blockingReasons === []
                ? 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start supervised start activation gate preflight is ready; guarded process start remains separate.'
                : 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start supervised start activation gate preflight is blocked until enablement, activation, storage and no-runtime prerequisites are ready.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerProcessStarterReadinessGateContract(array $options = []): array
    {
        $startGateStatusPayload = $this->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerStartExecutionGateStatus($options);
        $startGateStatus = (array) data_get($startGateStatusPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_start_execution_gate_status', []);
        $readinessPayload = $this->agentCodexRealInvokerProcessStarterReadinessGateContractTemplate($options);

        $contract = [
            'status' => 'one_shot_tick_codex_real_invoker_process_starter_readiness_gate_contract_ready',
            'contract_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-REAL-INVOKER-PROCESS-STARTER-READINESS-GATE-CONTRACT-SELF-CONSTRUCTION-0001',
            'parent_program' => 'Atlas Self-Construction OS',
            'submodule' => 'Atlas Agent Control Plane',
            'provider' => 'codex',
            'adapter' => 'codex',
            'source_codex_real_invoker_start_execution_gate_status' => data_get($startGateStatus, 'status'),
            'source_codex_real_invoker_start_execution_gate_status_hash' => data_get($startGateStatusPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_start_execution_gate_status_hash'),
            'source_codex_real_invoker_process_starter_readiness_gate_contract_status' => data_get($readinessPayload, 'status'),
            'source_codex_real_invoker_process_starter_readiness_gate_contract_hash' => data_get($readinessPayload, 'codex_real_invoker_process_starter_readiness_gate_contract_template_hash'),
            'release_boundary' => [
                'canonical_process_starter_readiness_gate' => AgentCodexRealInvokerProcessStarterReadinessGate::class,
                'canonical_process_starter_readiness_gate_method' => 'prepareProcessStarter',
                'scheduler_invoker' => AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerProcessStarterReadinessGateInvoker::class,
                'scheduler_invoker_method' => 'prepareCodexRealInvokerProcessStarter',
                'gate_effect' => 'record_process_starter_readiness_without_starting_process',
                'start_execution_gate_required_before_process_starter_readiness' => true,
                'process_starter_ready_by_gate' => true,
                'actual_process_start_allowed_by_gate' => false,
                'external_process_started_by_gate' => false,
                'provider_started_by_gate' => false,
                'adapter_execution_allowed_by_gate' => false,
                'token_spend_allowed_by_gate' => false,
                'dispatch_allowed_by_gate' => false,
                'idempotency_key' => 'real_invoker_process_starter_readiness_gate_id',
            ],
            'required_input_fields_for_future_invoker' => [
                'run_key',
                'codex_execution_id',
                'real_invoker_executor_plan_id',
                'real_invoker_executor_fresh_release_id',
                'real_invoker_executor_enablement_id',
                'real_invoker_supervised_start_activation_id',
                'real_invoker_guarded_process_start_id',
                'real_invoker_final_process_start_authorization_id',
                'real_invoker_actual_process_start_rehearsal_id',
                'real_invoker_process_start_envelope_id',
                'real_invoker_start_execution_gate_id',
                'real_invoker_process_starter_readiness_gate_id',
                'operator_execution_gate_receipt_hash',
                'execution_gate_policy_hash',
                'execution_window_hash',
                'preflight_snapshot_hash',
                'rollback_readiness_hash',
                'human_start_signature_hash',
                'process_starter_manifest_hash',
                'supervisor_binding_hash',
                'liveness_monitor_binding_hash',
                'cancellation_contract_hash',
                'output_capture_contract_hash',
                'cost_meter_contract_hash',
                'start_replay_guard_hash',
                'operator_process_starter_signature_hash',
                'actor',
                'session',
                'reason',
            ],
            'allowed_future_mutations' => [
                'write_codex_real_invoker_process_starter_readiness_gate_metadata_on_agent_run',
                'append_codex_real_invoker_process_starter_readiness_gate_evidence_event',
            ],
            'forbidden_even_after_contract' => [
                'spawn_codex_process',
                'call_codex_cli_or_codex_app',
                'spawn_shell_or_subprocess',
                'spend_provider_tokens',
                'mark_run_running_or_terminal',
                'mark_packet_completed',
                'merge_work_products',
                'dispatch_work_to_codex',
                'enable_self_programming',
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_process_starter_readiness_gate_preflight',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_process_starter_readiness_gate_contract.v1',
            'status' => (string) $contract['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_process_starter_readiness_gate_contract',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_process_starter_readiness_gate_allowed' => false,
            'process_starter_ready' => false,
            'actual_process_start_allowed' => false,
            'adapter_execution_allowed' => false,
            'provider_start_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_process_starter_readiness_gate_contract' => $contract,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_process_starter_readiness_gate_contract_hash' => $this->stableHash($contract),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_process_starter_readiness_gate_contract_does_not_prepare_process_starter',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_process_starter_readiness_gate_contract_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_process_starter_readiness_gate_contract_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_process_starter_readiness_gate_contract_does_not_enable_self_programming',
            ],
            'human_summary' => 'Automatic dispatch scheduler one-shot tick Codex real invoker process starter readiness contract is ready; it prepares starter readiness only and still cannot start Codex.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProcessSpawnEnablementGatePreflight(array $options = []): array
    {
        $contractPayload = $this->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProcessSpawnEnablementGateContract($options);
        $contract = (array) data_get($contractPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_spawn_enablement_gate_contract', []);
        $contractHash = (string) data_get($contractPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_spawn_enablement_gate_contract_hash');
        $gateReady = class_exists(AgentCodexRealInvokerPostStartProcessSpawnEnablementGate::class)
            && method_exists(AgentCodexRealInvokerPostStartProcessSpawnEnablementGate::class, 'enablePostStartProcessSpawn');
        $invokerReady = class_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProcessSpawnEnablementGateInvoker::class)
            && method_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProcessSpawnEnablementGateInvoker::class, 'enableCodexRealInvokerPostStartProcessSpawnEnablementGate');
        $spawnEnablementReady = class_exists(AgentCodexProcessSpawnEnablementGate::class)
            && method_exists(AgentCodexProcessSpawnEnablementGate::class, 'enableCodexProcessSpawn');
        $supervisedGateReady = class_exists(AgentCodexRealInvokerPostStartSupervisedStartExecutorGate::class)
            && method_exists(AgentCodexRealInvokerPostStartSupervisedStartExecutorGate::class, 'preparePostStartSupervisedStart');
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerTableReady = Schema::hasTable('atlas_ledger_events');

        $checks = [
            'post_start_process_spawn_enablement_gate_contract_ready' => data_get($contractPayload, 'status') === 'one_shot_tick_codex_real_invoker_post_start_process_spawn_enablement_gate_contract_ready',
            'post_start_process_spawn_enablement_gate_contract_hash_present' => $contractHash !== '',
            'post_start_supervised_start_executor_gate_status_ready' => data_get($contract, 'source_codex_real_invoker_post_start_supervised_start_executor_gate_status') === 'one_shot_tick_codex_real_invoker_post_start_supervised_start_executor_gate_service_ready',
            'generic_post_start_process_spawn_enablement_gate_template_ready' => data_get($contract, 'source_codex_real_invoker_post_start_process_spawn_enablement_gate_status') === 'codex_real_invoker_post_start_process_spawn_enablement_gate_contract_template_ready',
            'codex_real_invoker_post_start_process_spawn_enablement_gate_ready' => $gateReady,
            'codex_real_invoker_post_start_process_spawn_enablement_gate_invoker_ready' => $invokerReady,
            'codex_process_spawn_enablement_gate_ready' => $spawnEnablementReady,
            'codex_real_invoker_post_start_supervised_start_executor_gate_ready' => $supervisedGateReady,
            'canonical_post_start_process_spawn_enablement_gate_method_ready' => data_get($contract, 'process_spawn_enablement.canonical_post_start_process_spawn_enablement_gate_method') === 'enablePostStartProcessSpawn',
            'scheduler_invoker_method_ready' => data_get($contract, 'process_spawn_enablement.scheduler_invoker_method') === 'enableCodexRealInvokerPostStartProcessSpawnEnablementGate',
            'contract_requires_supervised_start' => data_get($contract, 'process_spawn_enablement.post_start_supervised_start_required_before_enablement') === true,
            'contract_requires_post_start_evidence_acceptance_bridge' => data_get($contract, 'process_spawn_enablement.post_start_evidence_acceptance_bridge_required_before_enablement') === true,
            'contract_requires_provider_start_run_supervised_start' => data_get($contract, 'process_spawn_enablement.provider_start_run_with_codex_supervised_start_required_before_enablement') === true,
            'contract_requires_operator_spawn_receipt_hash' => data_get($contract, 'process_spawn_enablement.operator_spawn_receipt_hash_required') === true,
            'contract_requires_supervised_start_contract_hash' => data_get($contract, 'process_spawn_enablement.supervised_start_contract_hash_required') === true,
            'contract_delegates_to_codex_process_spawn_enablement_gate' => data_get($contract, 'process_spawn_enablement.gate_delegates_to_codex_process_spawn_enablement_gate') === true,
            'contract_requires_final_process_spawn_executor_after_enablement' => data_get($contract, 'process_spawn_enablement.final_process_spawn_executor_required_after_enablement') === true,
            'contract_declares_enablement_is_not_process_spawn' => data_get($contract, 'process_spawn_enablement.process_spawn_enablement_is_not_process_spawn') === true,
            'contract_keeps_actual_process_start_disabled' => data_get($contract, 'process_spawn_enablement.actual_process_start_allowed_by_contract') === false,
            'contract_keeps_adapter_execution_disabled' => data_get($contract, 'process_spawn_enablement.adapter_execution_allowed_by_contract') === false,
            'contract_does_not_dispatch_work' => data_get($contract, 'process_spawn_enablement.dispatch_allowed_by_contract') === false,
            'contract_does_not_spend_tokens' => data_get($contract, 'process_spawn_enablement.token_spend_allowed_by_contract') === false,
            'agent_runs_table_ready' => $runsTableReady,
            'ledger_table_ready' => $ledgerTableReady,
            'self_programming_forbidden' => in_array('enable_self_programming', (array) data_get($contract, 'forbidden_even_after_contract', []), true),
        ];
        $blockingReasons = array_values(array_keys(array_filter($checks, static fn (bool $passed): bool => ! $passed)));

        $preflight = [
            'status' => $blockingReasons === [] ? 'one_shot_tick_codex_real_invoker_post_start_process_spawn_enablement_gate_preflight_ready' : 'blocked',
            'preflight_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-REAL-INVOKER-POST-START-PROCESS-SPAWN-ENABLEMENT-GATE-PREFLIGHT-SELF-CONSTRUCTION-0001',
            'source_post_start_process_spawn_enablement_gate_contract_hash' => $contractHash,
            'preflight_checks' => $checks,
            'blocking_count' => count($blockingReasons),
            'blocking_reasons' => $blockingReasons,
            'implementation_requirements' => [
                'use_one_shot_scheduler_codex_real_invoker_post_start_process_spawn_enablement_gate_invoker',
                'delegate_to_agent_codex_real_invoker_post_start_process_spawn_enablement_gate',
                'require_codex_real_invoker_post_start_supervised_start_metadata',
                'require_post_start_evidence_acceptance_bridge_metadata',
                'record_process_spawn_enablement_without_spawning_codex',
                'preserve_actual_process_start_disabled_until_final_process_spawn_executor_gate',
            ],
            'runtime_policy' => [
                'preflight_is_read_only' => true,
                'post_start_process_spawn_enablement_gate_call_allowed_here' => false,
                'post_start_process_spawn_enablement_recording_allowed_by_future_invoker' => true,
                'codex_process_spawn_enablement_gate_allowed_by_future_invoker' => true,
                'process_spawn_enablement_is_not_process_spawn' => true,
                'final_process_spawn_executor_required_after_future_invoker' => true,
                'actual_process_start_allowed_here' => false,
                'provider_process_call_allowed_here' => false,
                'adapter_invocation_allowed_here' => false,
                'adapter_execution_allowed_here' => false,
                'dispatch_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'self_programming_allowed_here' => false,
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_process_spawn_enablement_gate_implementation_packet',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_spawn_enablement_gate_preflight.v1',
            'status' => (string) $preflight['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_spawn_enablement_gate_preflight',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'post_start_process_spawn_enablement_gate_allowed' => false,
            'actual_process_start_allowed' => false,
            'provider_process_call_allowed' => false,
            'adapter_invocation_allowed' => false,
            'adapter_execution_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_spawn_enablement_gate_preflight' => $preflight,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_spawn_enablement_gate_preflight_hash' => $this->stableHash($preflight),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_spawn_enablement_gate_preflight_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_spawn_enablement_gate_preflight_does_not_call_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_spawn_enablement_gate_preflight_does_not_execute_adapter',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_spawn_enablement_gate_preflight_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_spawn_enablement_gate_preflight_does_not_dispatch_work',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_spawn_enablement_gate_preflight_does_not_enable_self_programming',
            ],
            'human_summary' => $blockingReasons === []
                ? 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start process spawn enablement gate preflight is ready.'
                : 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start process spawn enablement gate preflight is blocked until supervised start, spawn enablement gate and storage prerequisites are ready.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexSignedRealInvokerReleaseGateContract(array $options = []): array
    {
        $releasePreflightStatusPayload = $this->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerReleasePreflightStatus($options);
        $releasePreflightStatus = (array) data_get($releasePreflightStatusPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_release_preflight_status', []);
        $signedGatePayload = $this->agentCodexSignedRealInvokerReleaseGateContractTemplate($options);

        $contract = [
            'status' => 'one_shot_tick_codex_signed_real_invoker_release_gate_contract_ready',
            'contract_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-SIGNED-REAL-INVOKER-RELEASE-GATE-CONTRACT-SELF-CONSTRUCTION-0001',
            'parent_program' => 'Atlas Self-Construction OS',
            'submodule' => 'Atlas Agent Control Plane',
            'provider' => 'codex',
            'adapter' => 'codex',
            'source_codex_real_invoker_release_preflight_status' => data_get($releasePreflightStatus, 'status'),
            'source_codex_real_invoker_release_preflight_status_hash' => data_get($releasePreflightStatusPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_release_preflight_status_hash'),
            'source_codex_signed_real_invoker_release_gate_contract_status' => data_get($signedGatePayload, 'status'),
            'source_codex_signed_real_invoker_release_gate_contract_hash' => data_get($signedGatePayload, 'codex_signed_real_invoker_release_gate_contract_template_hash'),
            'release_boundary' => [
                'canonical_signed_gate' => AgentCodexSignedRealInvokerReleaseGate::class,
                'canonical_signed_gate_method' => 'authorizeSignedRelease',
                'scheduler_invoker' => AgentAutomaticDispatchSchedulerOneShotTickCodexSignedRealInvokerReleaseGateInvoker::class,
                'scheduler_invoker_method' => 'authorizeCodexSignedRealInvokerRelease',
                'signed_gate_effect' => 'authorize_codex_signed_real_invoker_release_metadata_without_real_process_invocation',
                'external_process_started_by_signed_gate' => false,
                'provider_started_by_signed_gate' => false,
                'adapter_execution_allowed_by_signed_gate' => false,
                'token_spend_allowed_by_signed_gate' => false,
                'required_release_preflight_status_before_signed_gate' => 'real_invoker_release_preflight_passed_pending_signed_release',
                'prepared_status_after_signed_gate' => 'signed_real_invoker_release_authorized_pending_invoker_implementation',
                'idempotency_key' => 'signed_real_invoker_release_id',
            ],
            'required_input_fields_for_future_invoker' => [
                'run_key',
                'codex_execution_id',
                'process_start_release_id',
                'supervised_start_id',
                'spawn_enablement_id',
                'spawn_executor_id',
                'runtime_driver_id',
                'invocation_authorization_id',
                'dry_run_id',
                'real_invoker_release_preflight_id',
                'signed_real_invoker_release_id',
                'operator_signed_release_receipt_hash',
                'signature_verification_report_hash',
                'real_invoker_contract_hash',
                'release_policy_hash',
                'process_command_hash',
                'environment_contract_hash',
                'termination_policy_hash',
                'stdout_stderr_sink_hash',
                'liveness_probe_hash',
                'rollback_plan_hash',
                'max_runtime_policy_hash',
                'actor',
                'session',
                'reason',
            ],
            'allowed_future_mutations' => [
                'write_codex_signed_real_invoker_release_metadata_on_agent_run',
                'append_codex_signed_real_invoker_release_authorized_evidence_event',
            ],
            'forbidden_even_after_contract' => [
                'spawn_codex_process',
                'call_codex_cli_or_codex_app',
                'spawn_shell_or_subprocess',
                'spend_provider_tokens',
                'mark_run_running_or_terminal',
                'mark_packet_completed',
                'merge_work_products',
                'enable_self_programming',
            ],
            'handoff_policy' => [
                'signed_gate_is_authorization_not_invocation' => true,
                'real_invoker_implementation_boundary_requires_separate_gate' => true,
                'operator_signed_release_receipt_hash_required' => true,
                'signature_verification_report_hash_required' => true,
                'release_policy_hash_required' => true,
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_signed_real_invoker_release_gate_preflight',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_signed_real_invoker_release_gate_contract.v1',
            'status' => (string) $contract['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_signed_real_invoker_release_gate_contract',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_signed_real_invoker_release_gate_allowed' => false,
            'adapter_execution_allowed' => false,
            'provider_start_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_signed_real_invoker_release_gate_contract' => $contract,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_signed_real_invoker_release_gate_contract_hash' => $this->stableHash($contract),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_signed_real_invoker_release_gate_contract_does_not_call_signed_gate',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_signed_real_invoker_release_gate_contract_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_signed_real_invoker_release_gate_contract_does_not_call_adapters',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_signed_real_invoker_release_gate_contract_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_signed_real_invoker_release_gate_contract_does_not_enable_self_programming',
            ],
            'human_summary' => 'Automatic dispatch scheduler one-shot tick Codex signed real invoker release gate contract is ready; it authorizes a future boundary but cannot start Codex.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerStartExecutionGateContract(array $options = []): array
    {
        $envelopeStatusPayload = $this->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerProcessStartEnvelopeBuilderStatus($options);
        $envelopeStatus = (array) data_get($envelopeStatusPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_process_start_envelope_builder_status', []);
        $startGatePayload = $this->agentCodexRealInvokerStartExecutionGateContractTemplate($options);

        $contract = [
            'status' => 'one_shot_tick_codex_real_invoker_start_execution_gate_contract_ready',
            'contract_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-REAL-INVOKER-START-EXECUTION-GATE-CONTRACT-SELF-CONSTRUCTION-0001',
            'parent_program' => 'Atlas Self-Construction OS',
            'submodule' => 'Atlas Agent Control Plane',
            'provider' => 'codex',
            'adapter' => 'codex',
            'source_codex_real_invoker_process_start_envelope_builder_status' => data_get($envelopeStatus, 'status'),
            'source_codex_real_invoker_process_start_envelope_builder_status_hash' => data_get($envelopeStatusPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_process_start_envelope_builder_status_hash'),
            'source_codex_real_invoker_start_execution_gate_contract_status' => data_get($startGatePayload, 'status'),
            'source_codex_real_invoker_start_execution_gate_contract_hash' => data_get($startGatePayload, 'codex_real_invoker_start_execution_gate_contract_template_hash'),
            'release_boundary' => [
                'canonical_start_execution_gate' => AgentCodexRealInvokerStartExecutionGate::class,
                'canonical_start_execution_gate_method' => 'authorizeStartExecution',
                'scheduler_invoker' => AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerStartExecutionGateInvoker::class,
                'scheduler_invoker_method' => 'authorizeCodexRealInvokerStartExecution',
                'gate_effect' => 'record_start_execution_authorization_without_starting_process',
                'process_start_envelope_required_before_gate' => true,
                'start_execution_authorized_by_gate' => true,
                'start_envelope_ready_by_gate' => true,
                'actual_process_start_allowed_by_gate' => false,
                'external_process_started_by_gate' => false,
                'provider_started_by_gate' => false,
                'adapter_execution_allowed_by_gate' => false,
                'token_spend_allowed_by_gate' => false,
                'dispatch_allowed_by_gate' => false,
                'idempotency_key' => 'real_invoker_start_execution_gate_id',
            ],
            'required_input_fields_for_future_invoker' => [
                'run_key',
                'codex_execution_id',
                'real_invoker_executor_plan_id',
                'real_invoker_executor_fresh_release_id',
                'real_invoker_executor_enablement_id',
                'real_invoker_supervised_start_activation_id',
                'real_invoker_guarded_process_start_id',
                'real_invoker_final_process_start_authorization_id',
                'real_invoker_actual_process_start_rehearsal_id',
                'real_invoker_process_start_envelope_id',
                'real_invoker_start_execution_gate_id',
                'process_start_envelope_hash',
                'start_command_hash',
                'start_environment_hash',
                'start_cwd_hash',
                'start_supervisor_hash',
                'start_liveness_contract_hash',
                'operator_execution_gate_receipt_hash',
                'execution_gate_policy_hash',
                'execution_window_hash',
                'preflight_snapshot_hash',
                'rollback_readiness_hash',
                'human_start_signature_hash',
                'actor',
                'session',
                'reason',
            ],
            'allowed_future_mutations' => [
                'write_codex_real_invoker_start_execution_gate_metadata_on_agent_run',
                'append_codex_real_invoker_start_execution_gate_evidence_event',
            ],
            'forbidden_even_after_contract' => [
                'spawn_codex_process',
                'call_codex_cli_or_codex_app',
                'spawn_shell_or_subprocess',
                'spend_provider_tokens',
                'mark_run_running_or_terminal',
                'mark_packet_completed',
                'merge_work_products',
                'dispatch_work_to_codex',
                'enable_self_programming',
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_start_execution_gate_preflight',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_start_execution_gate_contract.v1',
            'status' => (string) $contract['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_start_execution_gate_contract',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_start_execution_gate_allowed' => false,
            'start_execution_authorized' => false,
            'actual_process_start_allowed' => false,
            'adapter_execution_allowed' => false,
            'provider_start_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_start_execution_gate_contract' => $contract,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_start_execution_gate_contract_hash' => $this->stableHash($contract),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_start_execution_gate_contract_does_not_authorize_start_execution',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_start_execution_gate_contract_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_start_execution_gate_contract_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_start_execution_gate_contract_does_not_enable_self_programming',
            ],
            'human_summary' => 'Automatic dispatch scheduler one-shot tick Codex real invoker start execution gate contract is ready; it authorizes the gate only and still cannot start Codex.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProviderStartDriverGatePreflight(array $options = []): array
    {
        $contractPayload = $this->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProviderStartDriverGateContract($options);
        $contract = (array) data_get($contractPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_provider_start_driver_gate_contract', []);
        $contractHash = (string) data_get($contractPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_provider_start_driver_gate_contract_hash');
        $gateReady = class_exists(AgentCodexRealInvokerPostStartProviderStartDriverGate::class)
            && method_exists(AgentCodexRealInvokerPostStartProviderStartDriverGate::class, 'preparePostStartProviderStartDriver');
        $invokerReady = class_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProviderStartDriverGateInvoker::class)
            && method_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProviderStartDriverGateInvoker::class, 'prepareCodexRealInvokerPostStartProviderStartDriverGate');
        $driverReady = class_exists(AgentDispatchExecutorProviderStartDriver::class)
            && method_exists(AgentDispatchExecutorProviderStartDriver::class, 'startProviderOnce');
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $dispatchReceiptsTableReady = Schema::hasTable('atlas_self_construction_agent_dispatch_receipts');
        $releaseAuthorizationsTableReady = Schema::hasTable('atlas_self_construction_agent_dispatch_executor_release_authorizations');
        $sandboxBindingsTableReady = Schema::hasTable('atlas_self_construction_agent_sandbox_bindings');
        $heartbeatsTableReady = Schema::hasTable('atlas_self_construction_agent_heartbeats');
        $ledgerTableReady = Schema::hasTable('atlas_ledger_events');

        $checks = [
            'post_start_provider_start_driver_gate_contract_ready' => data_get($contractPayload, 'status') === 'one_shot_tick_codex_real_invoker_post_start_provider_start_driver_gate_contract_ready',
            'post_start_provider_start_driver_gate_contract_hash_present' => $contractHash !== '',
            'post_start_dispatch_receipt_use_executor_status_ready' => data_get($contract, 'source_codex_real_invoker_post_start_dispatch_receipt_use_executor_status') === 'one_shot_tick_codex_real_invoker_post_start_dispatch_receipt_use_executor_service_ready',
            'generic_post_start_provider_start_driver_gate_template_ready' => data_get($contract, 'source_codex_real_invoker_post_start_provider_start_driver_gate_status') === 'codex_real_invoker_post_start_provider_start_driver_gate_contract_template_ready',
            'codex_real_invoker_post_start_provider_start_driver_gate_ready' => $gateReady,
            'codex_real_invoker_post_start_provider_start_driver_gate_invoker_ready' => $invokerReady,
            'dispatch_executor_provider_start_driver_ready' => $driverReady,
            'canonical_post_start_provider_start_driver_gate_method_ready' => data_get($contract, 'provider_start_driver_boundary.canonical_post_start_provider_start_driver_gate_method') === 'preparePostStartProviderStartDriver',
            'contract_requires_dispatch_receipt_use' => data_get($contract, 'provider_start_driver_boundary.post_start_dispatch_receipt_use_required_before_provider_start_driver') === true,
            'contract_requires_sandbox_binding' => data_get($contract, 'provider_start_driver_boundary.sandbox_binding_required_before_provider_start_driver') === true,
            'contract_allows_pre_start_guarded_run_projection' => data_get($contract, 'provider_start_driver_boundary.pre_start_guarded_run_may_be_created_by_driver') === true,
            'contract_keeps_actual_process_start_disabled' => data_get($contract, 'provider_start_driver_boundary.actual_process_start_allowed_by_contract') === false,
            'contract_keeps_driver_provider_start_disabled' => data_get($contract, 'provider_start_driver_boundary.driver_provider_started_by_contract') === false,
            'contract_keeps_adapter_invocation_disabled' => data_get($contract, 'provider_start_driver_boundary.adapter_invocation_allowed_by_contract') === false,
            'contract_does_not_dispatch_work' => data_get($contract, 'provider_start_driver_boundary.dispatch_allowed_by_contract') === false,
            'contract_does_not_spend_tokens' => data_get($contract, 'provider_start_driver_boundary.token_spend_allowed_by_contract') === false,
            'agent_runs_table_ready' => $runsTableReady,
            'dispatch_receipts_table_ready' => $dispatchReceiptsTableReady,
            'dispatch_executor_release_authorizations_table_ready' => $releaseAuthorizationsTableReady,
            'sandbox_bindings_table_ready' => $sandboxBindingsTableReady,
            'agent_heartbeats_table_ready' => $heartbeatsTableReady,
            'ledger_table_ready' => $ledgerTableReady,
            'self_programming_forbidden' => in_array('enable_self_programming', (array) data_get($contract, 'forbidden_even_after_contract', []), true),
        ];
        $blockingReasons = array_values(array_keys(array_filter($checks, static fn (bool $passed): bool => ! $passed)));

        $preflight = [
            'status' => $blockingReasons === [] ? 'one_shot_tick_codex_real_invoker_post_start_provider_start_driver_gate_preflight_ready' : 'blocked',
            'preflight_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-REAL-INVOKER-POST-START-PROVIDER-START-DRIVER-GATE-PREFLIGHT-SELF-CONSTRUCTION-0001',
            'source_post_start_provider_start_driver_gate_contract_hash' => $contractHash,
            'preflight_checks' => $checks,
            'blocking_count' => count($blockingReasons),
            'blocking_reasons' => $blockingReasons,
            'implementation_requirements' => [
                'use_one_shot_scheduler_codex_real_invoker_post_start_provider_start_driver_gate_invoker',
                'delegate_to_agent_codex_real_invoker_post_start_provider_start_driver_gate',
                'require_codex_real_invoker_post_start_dispatch_receipt_use_metadata',
                'require_active_sandbox_binding_and_release_authorization',
                'record_pre_start_guarded_run_without_spawning_codex',
                'preserve_adapter_invocation_disabled_until_adapter_invocation_boundary_gate',
            ],
            'runtime_policy' => [
                'preflight_is_read_only' => true,
                'post_start_provider_start_driver_gate_call_allowed_here' => false,
                'post_start_provider_start_driver_metadata_allowed_by_future_invoker' => true,
                'pre_start_guarded_run_write_allowed_by_future_invoker' => true,
                'provider_external_process_start_allowed_here' => false,
                'provider_process_call_allowed_here' => false,
                'adapter_invocation_allowed_here' => false,
                'adapter_execution_allowed_here' => false,
                'dispatch_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'self_programming_allowed_here' => false,
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_provider_start_driver_gate_implementation_packet',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_provider_start_driver_gate_preflight.v1',
            'status' => (string) $preflight['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_provider_start_driver_gate_preflight',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'post_start_provider_start_driver_gate_allowed' => false,
            'actual_process_start_allowed' => false,
            'adapter_invocation_allowed' => false,
            'adapter_execution_allowed' => false,
            'provider_start_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_provider_start_driver_gate_preflight' => $preflight,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_provider_start_driver_gate_preflight_hash' => $this->stableHash($preflight),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_provider_start_driver_gate_preflight_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_provider_start_driver_gate_preflight_does_not_call_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_provider_start_driver_gate_preflight_does_not_enable_adapter_invocation',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_provider_start_driver_gate_preflight_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_provider_start_driver_gate_preflight_does_not_dispatch_work',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_provider_start_driver_gate_preflight_does_not_enable_self_programming',
            ],
            'human_summary' => $blockingReasons === []
                ? 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start provider start driver gate preflight is ready.'
                : 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start provider start driver gate preflight is blocked until receipt-use, sandbox, driver and storage prerequisites are ready.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartExecutorPlanGatePreflight(array $options = []): array
    {
        $contractPayload = $this->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartExecutorPlanGateContract($options);
        $contract = (array) data_get($contractPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_plan_gate_contract', []);
        $contractHash = (string) data_get($contractPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_plan_gate_contract_hash');
        $gateReady = class_exists(AgentCodexRealInvokerPostStartExecutorPlanGate::class)
            && method_exists(AgentCodexRealInvokerPostStartExecutorPlanGate::class, 'preparePostStartExecutorPlan');
        $invokerReady = class_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartExecutorPlanGateInvoker::class)
            && method_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartExecutorPlanGateInvoker::class, 'prepareCodexRealInvokerPostStartExecutorPlanGate');
        $executorPlanReady = class_exists(AgentCodexRealInvokerExecutorPlan::class)
            && method_exists(AgentCodexRealInvokerExecutorPlan::class, 'prepareExecutorPlan');
        $implementationBoundaryReady = class_exists(AgentCodexRealInvokerPostStartImplementationBoundaryGate::class)
            && method_exists(AgentCodexRealInvokerPostStartImplementationBoundaryGate::class, 'preparePostStartImplementationBoundary');
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerTableReady = Schema::hasTable('atlas_ledger_events');

        $checks = [
            'post_start_executor_plan_gate_contract_ready' => data_get($contractPayload, 'status') === 'one_shot_tick_codex_real_invoker_post_start_executor_plan_gate_contract_ready',
            'post_start_executor_plan_gate_contract_hash_present' => $contractHash !== '',
            'post_start_implementation_boundary_gate_status_ready' => data_get($contract, 'source_codex_real_invoker_post_start_implementation_boundary_gate_status') === 'one_shot_tick_codex_real_invoker_post_start_implementation_boundary_gate_service_ready',
            'generic_post_start_executor_plan_gate_template_ready' => data_get($contract, 'source_codex_real_invoker_post_start_executor_plan_gate_status') === 'codex_real_invoker_post_start_executor_plan_gate_contract_template_ready',
            'codex_real_invoker_post_start_executor_plan_gate_ready' => $gateReady,
            'codex_real_invoker_post_start_executor_plan_gate_invoker_ready' => $invokerReady,
            'codex_real_invoker_executor_plan_ready' => $executorPlanReady,
            'codex_real_invoker_post_start_implementation_boundary_gate_ready' => $implementationBoundaryReady,
            'canonical_post_start_executor_plan_gate_method_ready' => data_get($contract, 'executor_plan.canonical_post_start_executor_plan_gate_method') === 'preparePostStartExecutorPlan',
            'scheduler_invoker_method_ready' => data_get($contract, 'executor_plan.scheduler_invoker_method') === 'prepareCodexRealInvokerPostStartExecutorPlanGate',
            'contract_requires_post_start_implementation_boundary' => data_get($contract, 'executor_plan.post_start_implementation_boundary_required_before_plan') === true,
            'contract_requires_post_start_evidence_acceptance_bridge' => data_get($contract, 'executor_plan.post_start_evidence_acceptance_bridge_required_before_plan') === true,
            'contract_delegates_to_codex_real_invoker_executor_plan' => data_get($contract, 'executor_plan.gate_delegates_to_codex_real_invoker_executor_plan') === true,
            'contract_declares_executor_plan_is_not_executor_enablement' => data_get($contract, 'executor_plan.executor_plan_is_not_executor_enablement') === true,
            'contract_requires_executor_fresh_release_after_plan' => data_get($contract, 'executor_plan.executor_fresh_release_required_after_plan') === true,
            'contract_keeps_actual_process_start_disabled' => data_get($contract, 'executor_plan.actual_process_start_allowed_by_contract') === false,
            'contract_keeps_executor_disabled' => data_get($contract, 'executor_plan.executor_enabled_by_contract') === false,
            'contract_keeps_adapter_execution_disabled' => data_get($contract, 'executor_plan.adapter_execution_allowed_by_contract') === false,
            'contract_does_not_dispatch_work' => data_get($contract, 'executor_plan.dispatch_allowed_by_contract') === false,
            'contract_does_not_spend_tokens' => data_get($contract, 'executor_plan.token_spend_allowed_by_contract') === false,
            'agent_runs_table_ready' => $runsTableReady,
            'ledger_table_ready' => $ledgerTableReady,
            'self_programming_forbidden' => in_array('enable_self_programming', (array) data_get($contract, 'forbidden_even_after_contract', []), true),
        ];
        $blockingReasons = array_values(array_keys(array_filter($checks, static fn (bool $passed): bool => ! $passed)));

        $preflight = [
            'status' => $blockingReasons === [] ? 'one_shot_tick_codex_real_invoker_post_start_executor_plan_gate_preflight_ready' : 'blocked',
            'preflight_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-REAL-INVOKER-POST-START-EXECUTOR-PLAN-GATE-PREFLIGHT-SELF-CONSTRUCTION-0001',
            'source_post_start_executor_plan_gate_contract_hash' => $contractHash,
            'preflight_checks' => $checks,
            'blocking_count' => count($blockingReasons),
            'blocking_reasons' => $blockingReasons,
            'implementation_requirements' => [
                'create_scheduler_specific_post_start_executor_plan_gate_invoker',
                'delegate_to_agent_codex_real_invoker_post_start_executor_plan_gate',
                'require_post_start_implementation_boundary_metadata',
                'require_operator_executor_plan_receipt_hash',
                'preserve_executor_fresh_release_after_plan',
                'project_readiness_status_without_invoking_codex',
            ],
            'runtime_policy' => [
                'preflight_is_read_only' => true,
                'post_start_executor_plan_gate_call_allowed_by_future_invoker' => true,
                'executor_fresh_release_required_after_plan' => true,
                'actual_process_start_allowed_here' => false,
                'provider_process_call_allowed_here' => false,
                'executor_enabled_here' => false,
                'adapter_invocation_allowed_here' => false,
                'adapter_execution_allowed_here' => false,
                'dispatch_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'self_programming_allowed_here' => false,
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_executor_plan_gate_implementation_packet',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_plan_gate_preflight.v1',
            'status' => (string) $preflight['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_plan_gate_preflight',
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
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_plan_gate_preflight' => $preflight,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_plan_gate_preflight_hash' => $this->stableHash($preflight),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_plan_gate_preflight_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_plan_gate_preflight_does_not_call_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_plan_gate_preflight_does_not_enable_executor',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_plan_gate_preflight_does_not_execute_adapter',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_plan_gate_preflight_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_plan_gate_preflight_does_not_dispatch_work',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_plan_gate_preflight_does_not_enable_self_programming',
            ],
            'human_summary' => $blockingReasons === []
                ? 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start executor plan gate preflight is ready; executor fresh release remains separate.'
                : 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start executor plan gate preflight is blocked until boundary, executor plan and storage prerequisites are ready.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartExecutorFreshReleaseGatePreflight(array $options = []): array
    {
        $contractPayload = $this->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartExecutorFreshReleaseGateContract($options);
        $contract = (array) data_get($contractPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_fresh_release_gate_contract', []);
        $contractHash = (string) data_get($contractPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_fresh_release_gate_contract_hash');
        $gateReady = class_exists(AgentCodexRealInvokerPostStartExecutorFreshReleaseGate::class)
            && method_exists(AgentCodexRealInvokerPostStartExecutorFreshReleaseGate::class, 'authorizePostStartExecutorFreshRelease');
        $invokerReady = class_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartExecutorFreshReleaseGateInvoker::class)
            && method_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartExecutorFreshReleaseGateInvoker::class, 'authorizeCodexRealInvokerPostStartExecutorFreshReleaseGate');
        $freshReleaseReady = class_exists(AgentCodexRealInvokerExecutorFreshReleaseGate::class)
            && method_exists(AgentCodexRealInvokerExecutorFreshReleaseGate::class, 'authorizeFreshRelease');
        $executorPlanReady = class_exists(AgentCodexRealInvokerPostStartExecutorPlanGate::class)
            && method_exists(AgentCodexRealInvokerPostStartExecutorPlanGate::class, 'preparePostStartExecutorPlan');
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerTableReady = Schema::hasTable('atlas_ledger_events');

        $checks = [
            'post_start_executor_fresh_release_gate_contract_ready' => data_get($contractPayload, 'status') === 'one_shot_tick_codex_real_invoker_post_start_executor_fresh_release_gate_contract_ready',
            'post_start_executor_fresh_release_gate_contract_hash_present' => $contractHash !== '',
            'post_start_executor_plan_gate_status_ready' => data_get($contract, 'source_codex_real_invoker_post_start_executor_plan_gate_status') === 'one_shot_tick_codex_real_invoker_post_start_executor_plan_gate_service_ready',
            'generic_post_start_executor_fresh_release_gate_template_ready' => data_get($contract, 'source_codex_real_invoker_post_start_executor_fresh_release_gate_status') === 'codex_real_invoker_post_start_executor_fresh_release_gate_contract_template_ready',
            'codex_real_invoker_post_start_executor_fresh_release_gate_ready' => $gateReady,
            'codex_real_invoker_post_start_executor_fresh_release_gate_invoker_ready' => $invokerReady,
            'codex_real_invoker_executor_fresh_release_gate_ready' => $freshReleaseReady,
            'codex_real_invoker_post_start_executor_plan_gate_ready' => $executorPlanReady,
            'canonical_post_start_executor_fresh_release_gate_method_ready' => data_get($contract, 'fresh_release.canonical_post_start_executor_fresh_release_gate_method') === 'authorizePostStartExecutorFreshRelease',
            'scheduler_invoker_method_ready' => data_get($contract, 'fresh_release.scheduler_invoker_method') === 'authorizeCodexRealInvokerPostStartExecutorFreshReleaseGate',
            'contract_requires_post_start_executor_plan' => data_get($contract, 'fresh_release.post_start_executor_plan_required_before_fresh_release') === true,
            'contract_requires_post_start_evidence_acceptance_bridge' => data_get($contract, 'fresh_release.post_start_evidence_acceptance_bridge_required_before_fresh_release') === true,
            'contract_delegates_to_codex_real_invoker_executor_fresh_release_gate' => data_get($contract, 'fresh_release.gate_delegates_to_codex_real_invoker_executor_fresh_release_gate') === true,
            'contract_declares_fresh_release_is_not_executor_enablement' => data_get($contract, 'fresh_release.fresh_release_is_not_executor_enablement') === true,
            'contract_requires_executor_enablement_after_fresh_release' => data_get($contract, 'fresh_release.executor_enablement_required_after_fresh_release') === true,
            'contract_keeps_actual_process_start_disabled' => data_get($contract, 'fresh_release.actual_process_start_allowed_by_contract') === false,
            'contract_keeps_executor_disabled' => data_get($contract, 'fresh_release.executor_enabled_by_contract') === false,
            'contract_keeps_adapter_execution_disabled' => data_get($contract, 'fresh_release.adapter_execution_allowed_by_contract') === false,
            'contract_does_not_dispatch_work' => data_get($contract, 'fresh_release.dispatch_allowed_by_contract') === false,
            'contract_does_not_spend_tokens' => data_get($contract, 'fresh_release.token_spend_allowed_by_contract') === false,
            'agent_runs_table_ready' => $runsTableReady,
            'ledger_table_ready' => $ledgerTableReady,
            'self_programming_forbidden' => in_array('enable_self_programming', (array) data_get($contract, 'forbidden_even_after_contract', []), true),
        ];
        $blockingReasons = array_values(array_keys(array_filter($checks, static fn (bool $passed): bool => ! $passed)));

        $preflight = [
            'status' => $blockingReasons === [] ? 'one_shot_tick_codex_real_invoker_post_start_executor_fresh_release_gate_preflight_ready' : 'blocked',
            'preflight_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-REAL-INVOKER-POST-START-EXECUTOR-FRESH-RELEASE-GATE-PREFLIGHT-SELF-CONSTRUCTION-0001',
            'source_post_start_executor_fresh_release_gate_contract_hash' => $contractHash,
            'preflight_checks' => $checks,
            'blocking_count' => count($blockingReasons),
            'blocking_reasons' => $blockingReasons,
            'implementation_requirements' => [
                'create_scheduler_specific_post_start_executor_fresh_release_gate_invoker',
                'delegate_to_agent_codex_real_invoker_post_start_executor_fresh_release_gate',
                'require_post_start_executor_plan_metadata',
                'require_operator_fresh_release_receipt_hash',
                'preserve_executor_enablement_after_fresh_release',
                'project_readiness_status_without_invoking_codex',
            ],
            'runtime_policy' => [
                'preflight_is_read_only' => true,
                'post_start_executor_fresh_release_gate_call_allowed_by_future_invoker' => true,
                'executor_enablement_required_after_fresh_release' => true,
                'actual_process_start_allowed_here' => false,
                'provider_process_call_allowed_here' => false,
                'executor_enabled_here' => false,
                'adapter_invocation_allowed_here' => false,
                'adapter_execution_allowed_here' => false,
                'dispatch_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'self_programming_allowed_here' => false,
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_executor_fresh_release_gate_implementation_packet',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_fresh_release_gate_preflight.v1',
            'status' => (string) $preflight['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_fresh_release_gate_preflight',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'post_start_executor_fresh_release_gate_allowed' => false,
            'actual_process_start_allowed' => false,
            'provider_process_call_allowed' => false,
            'executor_enabled' => false,
            'adapter_invocation_allowed' => false,
            'adapter_execution_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_fresh_release_gate_preflight' => $preflight,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_fresh_release_gate_preflight_hash' => $this->stableHash($preflight),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_fresh_release_gate_preflight_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_fresh_release_gate_preflight_does_not_call_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_fresh_release_gate_preflight_does_not_enable_executor',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_fresh_release_gate_preflight_does_not_execute_adapter',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_fresh_release_gate_preflight_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_fresh_release_gate_preflight_does_not_dispatch_work',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_fresh_release_gate_preflight_does_not_enable_self_programming',
            ],
            'human_summary' => $blockingReasons === []
                ? 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start executor fresh release gate preflight is ready; executor enablement remains separate.'
                : 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start executor fresh release gate preflight is blocked until executor plan, fresh release and storage prerequisites are ready.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartExecutorEnablementGatePreflight(array $options = []): array
    {
        $contractPayload = $this->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartExecutorEnablementGateContract($options);
        $contract = (array) data_get($contractPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_enablement_gate_contract', []);
        $contractHash = (string) data_get($contractPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_enablement_gate_contract_hash');
        $gateReady = class_exists(AgentCodexRealInvokerPostStartExecutorEnablementGate::class)
            && method_exists(AgentCodexRealInvokerPostStartExecutorEnablementGate::class, 'enablePostStartExecutor');
        $invokerReady = class_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartExecutorEnablementGateInvoker::class)
            && method_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartExecutorEnablementGateInvoker::class, 'enableCodexRealInvokerPostStartExecutorGate');
        $enablementReady = class_exists(AgentCodexRealInvokerExecutorEnablementGate::class)
            && method_exists(AgentCodexRealInvokerExecutorEnablementGate::class, 'enableExecutor');
        $freshReleaseReady = class_exists(AgentCodexRealInvokerPostStartExecutorFreshReleaseGate::class)
            && method_exists(AgentCodexRealInvokerPostStartExecutorFreshReleaseGate::class, 'authorizePostStartExecutorFreshRelease');
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerTableReady = Schema::hasTable('atlas_ledger_events');

        $checks = [
            'post_start_executor_enablement_gate_contract_ready' => data_get($contractPayload, 'status') === 'one_shot_tick_codex_real_invoker_post_start_executor_enablement_gate_contract_ready',
            'post_start_executor_enablement_gate_contract_hash_present' => $contractHash !== '',
            'post_start_executor_fresh_release_gate_status_ready' => data_get($contract, 'source_codex_real_invoker_post_start_executor_fresh_release_gate_status') === 'one_shot_tick_codex_real_invoker_post_start_executor_fresh_release_gate_service_ready',
            'generic_post_start_executor_enablement_gate_template_ready' => data_get($contract, 'source_codex_real_invoker_post_start_executor_enablement_gate_status') === 'codex_real_invoker_post_start_executor_enablement_gate_contract_template_ready',
            'codex_real_invoker_post_start_executor_enablement_gate_ready' => $gateReady,
            'codex_real_invoker_post_start_executor_enablement_gate_invoker_ready' => $invokerReady,
            'codex_real_invoker_executor_enablement_gate_ready' => $enablementReady,
            'codex_real_invoker_post_start_executor_fresh_release_gate_ready' => $freshReleaseReady,
            'canonical_post_start_executor_enablement_gate_method_ready' => data_get($contract, 'enablement.canonical_post_start_executor_enablement_gate_method') === 'enablePostStartExecutor',
            'scheduler_invoker_method_ready' => data_get($contract, 'enablement.scheduler_invoker_method') === 'enableCodexRealInvokerPostStartExecutorGate',
            'contract_requires_post_start_executor_fresh_release' => data_get($contract, 'enablement.post_start_executor_fresh_release_required_before_enablement') === true,
            'contract_requires_post_start_evidence_acceptance_bridge' => data_get($contract, 'enablement.post_start_evidence_acceptance_bridge_required_before_enablement') === true,
            'contract_delegates_to_codex_real_invoker_executor_enablement_gate' => data_get($contract, 'enablement.gate_delegates_to_codex_real_invoker_executor_enablement_gate') === true,
            'contract_declares_enablement_is_not_process_start' => data_get($contract, 'enablement.enablement_is_not_process_start') === true,
            'contract_requires_supervised_start_after_enablement' => data_get($contract, 'enablement.supervised_start_required_after_enablement') === true,
            'contract_allows_executor_enabled_by_future_invoker' => data_get($contract, 'enablement.executor_enabled_by_contract') === true,
            'contract_keeps_actual_process_start_disabled' => data_get($contract, 'enablement.actual_process_start_allowed_by_contract') === false,
            'contract_keeps_adapter_execution_disabled' => data_get($contract, 'enablement.adapter_execution_allowed_by_contract') === false,
            'contract_does_not_dispatch_work' => data_get($contract, 'enablement.dispatch_allowed_by_contract') === false,
            'contract_does_not_spend_tokens' => data_get($contract, 'enablement.token_spend_allowed_by_contract') === false,
            'agent_runs_table_ready' => $runsTableReady,
            'ledger_table_ready' => $ledgerTableReady,
            'self_programming_forbidden' => in_array('enable_self_programming', (array) data_get($contract, 'forbidden_even_after_contract', []), true),
        ];
        $blockingReasons = array_values(array_keys(array_filter($checks, static fn (bool $passed): bool => ! $passed)));

        $preflight = [
            'status' => $blockingReasons === [] ? 'one_shot_tick_codex_real_invoker_post_start_executor_enablement_gate_preflight_ready' : 'blocked',
            'preflight_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-REAL-INVOKER-POST-START-EXECUTOR-ENABLEMENT-GATE-PREFLIGHT-SELF-CONSTRUCTION-0001',
            'source_post_start_executor_enablement_gate_contract_hash' => $contractHash,
            'preflight_checks' => $checks,
            'blocking_count' => count($blockingReasons),
            'blocking_reasons' => $blockingReasons,
            'implementation_requirements' => [
                'create_scheduler_specific_post_start_executor_enablement_gate_invoker',
                'delegate_to_agent_codex_real_invoker_post_start_executor_enablement_gate',
                'require_post_start_executor_fresh_release_metadata',
                'require_operator_enablement_receipt_hash',
                'preserve_supervised_start_after_enablement',
                'project_readiness_status_without_invoking_codex',
            ],
            'runtime_policy' => [
                'preflight_is_read_only' => true,
                'post_start_executor_enablement_gate_call_allowed_by_future_invoker' => true,
                'executor_enabled_after_future_invoker' => true,
                'supervised_start_required_after_enablement' => true,
                'actual_process_start_allowed_here' => false,
                'provider_process_call_allowed_here' => false,
                'executor_enabled_here' => false,
                'adapter_invocation_allowed_here' => false,
                'adapter_execution_allowed_here' => false,
                'dispatch_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'self_programming_allowed_here' => false,
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_executor_enablement_gate_implementation_packet',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_enablement_gate_preflight.v1',
            'status' => (string) $preflight['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_enablement_gate_preflight',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'post_start_executor_enablement_gate_allowed' => false,
            'actual_process_start_allowed' => false,
            'provider_process_call_allowed' => false,
            'executor_enabled' => false,
            'adapter_invocation_allowed' => false,
            'adapter_execution_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_enablement_gate_preflight' => $preflight,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_enablement_gate_preflight_hash' => $this->stableHash($preflight),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_enablement_gate_preflight_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_enablement_gate_preflight_does_not_call_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_enablement_gate_preflight_does_not_execute_adapter',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_enablement_gate_preflight_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_enablement_gate_preflight_does_not_dispatch_work',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_enablement_gate_preflight_does_not_enable_self_programming',
            ],
            'human_summary' => $blockingReasons === []
                ? 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start executor enablement gate preflight is ready; supervised start remains separate.'
                : 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start executor enablement gate preflight is blocked until fresh release, enablement and storage prerequisites are ready.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerReleasePreflightContract(array $options = []): array
    {
        $dryRunStatusPayload = $this->agentAutomaticDispatchSchedulerOneShotTickCodexExternalProcessInvokerDryRunStatus($options);
        $dryRunStatus = (array) data_get($dryRunStatusPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_external_process_invoker_dry_run_status', []);
        $releasePreflightPayload = $this->agentCodexRealInvokerReleasePreflightContractTemplate($options);

        $contract = [
            'status' => 'one_shot_tick_codex_real_invoker_release_preflight_contract_ready',
            'contract_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-REAL-INVOKER-RELEASE-PREFLIGHT-CONTRACT-SELF-CONSTRUCTION-0001',
            'parent_program' => 'Atlas Self-Construction OS',
            'submodule' => 'Atlas Agent Control Plane',
            'provider' => 'codex',
            'adapter' => 'codex',
            'source_codex_external_process_invoker_dry_run_status' => data_get($dryRunStatus, 'status'),
            'source_codex_external_process_invoker_dry_run_status_hash' => data_get($dryRunStatusPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_external_process_invoker_dry_run_status_hash'),
            'source_codex_real_invoker_release_preflight_contract_status' => data_get($releasePreflightPayload, 'status'),
            'source_codex_real_invoker_release_preflight_contract_hash' => data_get($releasePreflightPayload, 'codex_real_invoker_release_preflight_contract_template_hash'),
            'release_boundary' => [
                'canonical_release_preflight' => AgentCodexRealInvokerReleasePreflight::class,
                'canonical_release_preflight_method' => 'recordPreflight',
                'scheduler_invoker' => AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerReleasePreflightInvoker::class,
                'scheduler_invoker_method' => 'prepareCodexRealInvokerReleasePreflight',
                'release_preflight_effect' => 'record_codex_real_invoker_release_preflight_metadata_without_real_process_invocation',
                'external_process_started_by_release_preflight' => false,
                'provider_started_by_release_preflight' => false,
                'adapter_execution_allowed_by_release_preflight' => false,
                'token_spend_allowed_by_release_preflight' => false,
                'required_dry_run_status_before_release_preflight' => 'dry_run_ready_pending_real_invoker_release',
                'prepared_status_after_release_preflight' => 'real_invoker_release_preflight_passed_pending_signed_release',
                'idempotency_key' => 'real_invoker_release_preflight_id',
            ],
            'required_input_fields_for_future_invoker' => [
                'run_key',
                'codex_execution_id',
                'process_start_release_id',
                'supervised_start_id',
                'spawn_enablement_id',
                'spawn_executor_id',
                'runtime_driver_id',
                'invocation_authorization_id',
                'dry_run_id',
                'real_invoker_release_preflight_id',
                'operator_release_preflight_receipt_hash',
                'real_invoker_contract_hash',
                'process_command_hash',
                'environment_contract_hash',
                'termination_policy_hash',
                'stdout_stderr_sink_hash',
                'liveness_probe_hash',
                'rollback_plan_hash',
                'max_runtime_policy_hash',
                'actor',
                'session',
                'reason',
            ],
            'allowed_future_mutations' => [
                'write_codex_real_invoker_release_preflight_metadata_on_agent_run',
                'append_codex_real_invoker_release_preflight_passed_evidence_event',
            ],
            'forbidden_even_after_contract' => [
                'spawn_codex_process',
                'call_codex_cli_or_codex_app',
                'spawn_shell_or_subprocess',
                'spend_provider_tokens',
                'mark_run_running_or_terminal',
                'mark_packet_completed',
                'merge_work_products',
                'enable_self_programming',
            ],
            'handoff_policy' => [
                'release_preflight_is_release_gate_not_invocation' => true,
                'signed_real_invoker_release_requires_separate_gate' => true,
                'operator_release_preflight_receipt_hash_required' => true,
                'real_invoker_contract_hash_required' => true,
                'rollback_plan_hash_required' => true,
                'max_runtime_policy_hash_required' => true,
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_release_preflight_preflight',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_release_preflight_contract.v1',
            'status' => (string) $contract['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_release_preflight_contract',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_release_preflight_allowed' => false,
            'adapter_execution_allowed' => false,
            'provider_start_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_release_preflight_contract' => $contract,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_release_preflight_contract_hash' => $this->stableHash($contract),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_release_preflight_contract_does_not_call_release_preflight',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_release_preflight_contract_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_release_preflight_contract_does_not_call_adapters',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_release_preflight_contract_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_release_preflight_contract_does_not_enable_self_programming',
            ],
            'human_summary' => 'Automatic dispatch scheduler one-shot tick Codex real invoker release preflight contract is ready; it can only prepare the signed-release preflight boundary, not start Codex.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartAdapterExecutionGuardGateContract(array $options = []): array
    {
        $boundaryStatusPayload = $this->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartAdapterInvocationBoundaryGateStatus($options);
        $boundaryStatus = (array) data_get($boundaryStatusPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_adapter_invocation_boundary_gate_status', []);
        $guardPayload = $this->agentCodexRealInvokerPostStartAdapterExecutionGuardGateContractTemplate($options);

        $contract = [
            'status' => 'one_shot_tick_codex_real_invoker_post_start_adapter_execution_guard_gate_contract_ready',
            'contract_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-REAL-INVOKER-POST-START-ADAPTER-EXECUTION-GUARD-GATE-CONTRACT-SELF-CONSTRUCTION-0001',
            'parent_program' => 'Atlas Self-Construction OS',
            'submodule' => 'Atlas Agent Control Plane',
            'provider' => 'codex',
            'adapter' => 'codex',
            'source_codex_real_invoker_post_start_adapter_invocation_boundary_gate_status' => data_get($boundaryStatus, 'status'),
            'source_codex_real_invoker_post_start_adapter_invocation_boundary_gate_status_hash' => data_get($boundaryStatusPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_adapter_invocation_boundary_gate_status_hash'),
            'source_codex_real_invoker_post_start_adapter_execution_guard_gate_status' => data_get($guardPayload, 'status'),
            'source_codex_real_invoker_post_start_adapter_execution_guard_gate_hash' => data_get($guardPayload, 'codex_real_invoker_post_start_adapter_execution_guard_gate_contract_template_hash'),
            'adapter_execution_guard' => [
                'canonical_post_start_adapter_execution_guard_gate' => AgentCodexRealInvokerPostStartAdapterExecutionGuardGate::class,
                'canonical_post_start_adapter_execution_guard_gate_method' => 'blockPostStartAdapterExecution',
                'scheduler_invoker' => AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartAdapterExecutionGuardGateInvoker::class,
                'scheduler_invoker_method' => 'blockCodexRealInvokerPostStartAdapterExecutionGuardGate',
                'provider_adapter_execution_guard' => AgentProviderAdapterExecutionGuard::class,
                'provider_adapter_execution_guard_method' => 'blockUntilProviderSpecificContract',
                'adapter_execution_guard_effect' => 'record_provider_adapter_execution_guard_block_without_calling_codex',
                'post_start_adapter_invocation_boundary_required_before_guard' => true,
                'post_start_evidence_acceptance_bridge_required_before_guard' => true,
                'provider_start_run_with_adapter_invocation_required_before_guard' => true,
                'provider_adapter_registry_required' => true,
                'provider_specific_execution_contract_required_before_any_later_execution' => true,
                'guard_delegates_to_provider_adapter_execution_guard' => true,
                'guard_records_blocking_metadata_on_provider_start_run' => true,
                'guard_records_bridge_metadata_on_observed_run' => true,
                'actual_process_start_allowed_by_contract' => false,
                'provider_process_call_allowed_by_contract' => false,
                'adapter_invocation_allowed_by_contract' => false,
                'adapter_execution_allowed_by_contract' => false,
                'token_spend_allowed_by_contract' => false,
                'dispatch_allowed_by_contract' => false,
                'idempotency_key' => 'execution_guard_id',
            ],
            'required_input_fields_for_future_invoker' => [
                'run_key',
                'adapter_execution_guard_gate_id',
                'execution_guard_id',
                'adapter_invocation_boundary_gate_id',
                'adapter_invocation_id',
                'provider_start_driver_gate_id',
                'provider_start_attempt_id',
                'dispatch_executor_handoff_id',
                'signed_dispatch_authorization_id',
                'post_start_evidence_acceptance_bridge_id',
                'signed_dispatch_receipt_hash',
                'actor',
                'session',
                'reason',
            ],
            'allowed_future_mutations' => [
                'record_provider_adapter_execution_guard_metadata_on_provider_start_run',
                'append_provider_adapter_execution_guard_evidence_event',
                'record_codex_real_invoker_post_start_adapter_execution_guard_metadata_on_observed_run',
            ],
            'forbidden_even_after_contract' => [
                'spawn_codex_process',
                'call_codex_cli_or_codex_app',
                'dispatch_work_to_codex',
                'send_prompt_to_provider',
                'call_provider_process',
                'enable_adapter_execution',
                'create_provider_specific_execution_contract',
                'spend_provider_tokens',
                'mark_observed_run_running_or_terminal',
                'enable_self_programming',
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_adapter_execution_guard_gate_preflight',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_adapter_execution_guard_gate_contract.v1',
            'status' => (string) $contract['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_adapter_execution_guard_gate_contract',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'post_start_adapter_execution_guard_gate_allowed' => false,
            'actual_process_start_allowed' => false,
            'provider_process_call_allowed' => false,
            'adapter_invocation_allowed' => false,
            'adapter_execution_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_adapter_execution_guard_gate_contract' => $contract,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_adapter_execution_guard_gate_contract_hash' => $this->stableHash($contract),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_adapter_execution_guard_gate_contract_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_adapter_execution_guard_gate_contract_does_not_call_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_adapter_execution_guard_gate_contract_does_not_execute_adapter',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_adapter_execution_guard_gate_contract_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_adapter_execution_guard_gate_contract_does_not_dispatch_work',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_adapter_execution_guard_gate_contract_does_not_enable_self_programming',
            ],
            'human_summary' => 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start adapter execution guard gate contract is ready; it records the execution block without calling Codex.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProviderExecutionContractGateStatus(array $options = []): array
    {
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $sandboxBindingTableReady = Schema::hasTable('atlas_self_construction_agent_sandbox_bindings');
        $ledgerTableReady = Schema::hasTable('atlas_ledger_events');
        $gateReady = class_exists(AgentCodexRealInvokerPostStartProviderExecutionContractGate::class)
            && method_exists(AgentCodexRealInvokerPostStartProviderExecutionContractGate::class, 'preparePostStartProviderExecutionContract');
        $invokerReady = class_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProviderExecutionContractGateInvoker::class)
            && method_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProviderExecutionContractGateInvoker::class, 'prepareCodexRealInvokerPostStartProviderExecutionContractGate');
        $driverReady = class_exists(AgentCodexProviderExecutionDriver::class)
            && method_exists(AgentCodexProviderExecutionDriver::class, 'prepareCodexExecution');
        $guardGateReady = class_exists(AgentCodexRealInvokerPostStartAdapterExecutionGuardGate::class)
            && method_exists(AgentCodexRealInvokerPostStartAdapterExecutionGuardGate::class, 'blockPostStartAdapterExecution');
        $registryReady = class_exists(AgentProviderAdapterRegistry::class)
            && method_exists(AgentProviderAdapterRegistry::class, 'resolve');

        $observedProviderExecutionRunsQuery = $runsTableReady
            ? AtlasSelfConstructionAgentRun::query()
                ->whereNotNull('metadata->codex_real_invoker_post_start_provider_execution_contract->codex_execution_id')
            : null;
        $providerRunsWithCodexExecutionQuery = $runsTableReady
            ? AtlasSelfConstructionAgentRun::query()
                ->where('run_key', 'like', 'provider-start:%')
                ->where('status', 'adapter_invocation_prepared')
                ->whereNotNull('metadata->codex_provider_execution->codex_execution_id')
            : null;

        $statusReady = $runsTableReady
            && $sandboxBindingTableReady
            && $ledgerTableReady
            && $gateReady
            && $invokerReady
            && $driverReady
            && $guardGateReady
            && $registryReady;
        $status = [
            'status' => $statusReady ? 'one_shot_tick_codex_real_invoker_post_start_provider_execution_contract_gate_service_ready' : 'blocked',
            'provider' => 'codex',
            'adapter' => 'codex',
            'invoker_service' => AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProviderExecutionContractGateInvoker::class,
            'invoker_service_ready' => $invokerReady,
            'invoker_canonical_method' => 'prepareCodexRealInvokerPostStartProviderExecutionContractGate',
            'generic_post_start_provider_execution_contract_gate_service' => AgentCodexRealInvokerPostStartProviderExecutionContractGate::class,
            'generic_post_start_provider_execution_contract_gate_service_ready' => $gateReady,
            'generic_post_start_provider_execution_contract_gate_canonical_method' => 'preparePostStartProviderExecutionContract',
            'codex_provider_execution_driver_service' => AgentCodexProviderExecutionDriver::class,
            'codex_provider_execution_driver_ready' => $driverReady,
            'post_start_adapter_execution_guard_gate_service' => AgentCodexRealInvokerPostStartAdapterExecutionGuardGate::class,
            'post_start_adapter_execution_guard_gate_ready' => $guardGateReady,
            'provider_adapter_registry_service' => AgentProviderAdapterRegistry::class,
            'provider_adapter_registry_ready' => $registryReady,
            'agent_runs_table_ready' => $runsTableReady,
            'agent_sandbox_bindings_table_ready' => $sandboxBindingTableReady,
            'ledger_table_ready' => $ledgerTableReady,
            'codex_real_invoker_post_start_provider_execution_contract_recorded_run_count' => $observedProviderExecutionRunsQuery === null ? null : (clone $observedProviderExecutionRunsQuery)->count(),
            'provider_start_runs_with_codex_provider_execution_count' => $providerRunsWithCodexExecutionQuery === null ? null : (clone $providerRunsWithCodexExecutionQuery)->count(),
            'runtime_policy' => [
                'status_projection_is_read_only' => true,
                'invoker_may_record_provider_execution_contract_when_called_with_signed_input' => true,
                'provider_execution_contract_is_not_process_start' => true,
                'process_start_release_required_before_process_start' => true,
                'atlas_process_spawn_allowed_here' => false,
                'provider_external_process_start_allowed_here' => false,
                'provider_process_call_allowed_here' => false,
                'adapter_invocation_allowed_here' => false,
                'adapter_execution_allowed_here' => false,
                'dispatch_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'self_programming_allowed_here' => false,
            ],
            'next_required_slice' => $statusReady
                ? 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_process_start_release_gate_contract'
                : 'repair_one_shot_scheduler_tick_codex_real_invoker_post_start_provider_execution_contract_gate_service_blockers',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_provider_execution_contract_gate_status.v1',
            'status' => (string) $status['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_provider_execution_contract_gate_status',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'post_start_provider_execution_contract_gate_allowed' => false,
            'actual_process_start_allowed' => false,
            'provider_process_call_allowed' => false,
            'adapter_invocation_allowed' => false,
            'adapter_execution_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_provider_execution_contract_gate_status' => $status,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_provider_execution_contract_gate_status_hash' => $this->stableHash($status),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_provider_execution_contract_gate_status_does_not_call_provider_execution_contract_gate',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_provider_execution_contract_gate_status_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_provider_execution_contract_gate_status_does_not_execute_adapter',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_provider_execution_contract_gate_status_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_provider_execution_contract_gate_status_does_not_dispatch_work',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_provider_execution_contract_gate_status_does_not_enable_self_programming',
            ],
            'human_summary' => $statusReady
                ? 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start provider execution contract gate service is ready and inspectable; process start release remains separate.'
                : 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start provider execution contract gate service is blocked until invoker, generic gate, driver, guard, registry and storage are ready.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProcessStartReleaseGatePreflight(array $options = []): array
    {
        $contractPayload = $this->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProcessStartReleaseGateContract($options);
        $contract = (array) data_get($contractPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_start_release_gate_contract', []);
        $contractHash = (string) data_get($contractPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_start_release_gate_contract_hash');
        $gateReady = class_exists(AgentCodexRealInvokerPostStartProcessStartReleaseGate::class)
            && method_exists(AgentCodexRealInvokerPostStartProcessStartReleaseGate::class, 'authorizePostStartProcessStartRelease');
        $invokerReady = class_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProcessStartReleaseGateInvoker::class)
            && method_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProcessStartReleaseGateInvoker::class, 'authorizeCodexRealInvokerPostStartProcessStartReleaseGate');
        $codexReleaseGateReady = class_exists(AgentCodexProcessStartReleaseGate::class)
            && method_exists(AgentCodexProcessStartReleaseGate::class, 'authorizeCodexProcessStart');
        $providerExecutionGateReady = class_exists(AgentCodexRealInvokerPostStartProviderExecutionContractGate::class)
            && method_exists(AgentCodexRealInvokerPostStartProviderExecutionContractGate::class, 'preparePostStartProviderExecutionContract');
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerTableReady = Schema::hasTable('atlas_ledger_events');

        $checks = [
            'post_start_process_start_release_gate_contract_ready' => data_get($contractPayload, 'status') === 'one_shot_tick_codex_real_invoker_post_start_process_start_release_gate_contract_ready',
            'post_start_process_start_release_gate_contract_hash_present' => $contractHash !== '',
            'post_start_provider_execution_contract_gate_status_ready' => data_get($contract, 'source_codex_real_invoker_post_start_provider_execution_contract_gate_status') === 'one_shot_tick_codex_real_invoker_post_start_provider_execution_contract_gate_service_ready',
            'generic_post_start_process_start_release_gate_template_ready' => data_get($contract, 'source_codex_real_invoker_post_start_process_start_release_gate_status') === 'codex_real_invoker_post_start_process_start_release_gate_contract_template_ready',
            'codex_real_invoker_post_start_process_start_release_gate_ready' => $gateReady,
            'codex_real_invoker_post_start_process_start_release_gate_invoker_ready' => $invokerReady,
            'codex_process_start_release_gate_ready' => $codexReleaseGateReady,
            'codex_real_invoker_post_start_provider_execution_contract_gate_ready' => $providerExecutionGateReady,
            'canonical_post_start_process_start_release_gate_method_ready' => data_get($contract, 'process_start_release.canonical_post_start_process_start_release_gate_method') === 'authorizePostStartProcessStartRelease',
            'scheduler_invoker_method_ready' => data_get($contract, 'process_start_release.scheduler_invoker_method') === 'authorizeCodexRealInvokerPostStartProcessStartReleaseGate',
            'contract_requires_provider_execution_contract' => data_get($contract, 'process_start_release.post_start_provider_execution_contract_required_before_release') === true,
            'contract_requires_post_start_evidence_acceptance_bridge' => data_get($contract, 'process_start_release.post_start_evidence_acceptance_bridge_required_before_release') === true,
            'contract_delegates_to_codex_process_start_release_gate' => data_get($contract, 'process_start_release.gate_delegates_to_codex_process_start_release_gate') === true,
            'contract_requires_supervised_start_executor_after_release' => data_get($contract, 'process_start_release.supervised_start_executor_required_after_release') === true,
            'contract_declares_release_is_not_process_start' => data_get($contract, 'process_start_release.release_authorization_is_not_process_start') === true,
            'contract_keeps_actual_process_start_disabled' => data_get($contract, 'process_start_release.actual_process_start_allowed_by_contract') === false,
            'contract_keeps_adapter_execution_disabled' => data_get($contract, 'process_start_release.adapter_execution_allowed_by_contract') === false,
            'contract_does_not_dispatch_work' => data_get($contract, 'process_start_release.dispatch_allowed_by_contract') === false,
            'contract_does_not_spend_tokens' => data_get($contract, 'process_start_release.token_spend_allowed_by_contract') === false,
            'agent_runs_table_ready' => $runsTableReady,
            'ledger_table_ready' => $ledgerTableReady,
            'self_programming_forbidden' => in_array('enable_self_programming', (array) data_get($contract, 'forbidden_even_after_contract', []), true),
        ];
        $blockingReasons = array_values(array_keys(array_filter($checks, static fn (bool $passed): bool => ! $passed)));

        $preflight = [
            'status' => $blockingReasons === [] ? 'one_shot_tick_codex_real_invoker_post_start_process_start_release_gate_preflight_ready' : 'blocked',
            'preflight_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-REAL-INVOKER-POST-START-PROCESS-START-RELEASE-GATE-PREFLIGHT-SELF-CONSTRUCTION-0001',
            'source_post_start_process_start_release_gate_contract_hash' => $contractHash,
            'preflight_checks' => $checks,
            'blocking_count' => count($blockingReasons),
            'blocking_reasons' => $blockingReasons,
            'implementation_requirements' => [
                'use_one_shot_scheduler_codex_real_invoker_post_start_process_start_release_gate_invoker',
                'delegate_to_agent_codex_real_invoker_post_start_process_start_release_gate',
                'require_codex_real_invoker_post_start_provider_execution_contract_metadata',
                'require_post_start_evidence_acceptance_bridge_metadata',
                'record_process_start_release_authorization_without_starting_codex',
                'preserve_actual_process_start_disabled_until_supervised_start_executor_gate',
            ],
            'runtime_policy' => [
                'preflight_is_read_only' => true,
                'post_start_process_start_release_gate_call_allowed_here' => false,
                'process_start_release_authorization_allowed_by_future_invoker' => true,
                'codex_process_start_release_gate_allowed_by_future_invoker' => true,
                'release_authorization_is_not_process_start' => true,
                'supervised_start_executor_required_after_future_invoker' => true,
                'actual_process_start_allowed_here' => false,
                'provider_process_call_allowed_here' => false,
                'adapter_invocation_allowed_here' => false,
                'adapter_execution_allowed_here' => false,
                'dispatch_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'self_programming_allowed_here' => false,
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_process_start_release_gate_implementation_packet',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_start_release_gate_preflight.v1',
            'status' => (string) $preflight['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_start_release_gate_preflight',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'post_start_process_start_release_gate_allowed' => false,
            'actual_process_start_allowed' => false,
            'provider_process_call_allowed' => false,
            'adapter_invocation_allowed' => false,
            'adapter_execution_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_start_release_gate_preflight' => $preflight,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_start_release_gate_preflight_hash' => $this->stableHash($preflight),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_start_release_gate_preflight_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_start_release_gate_preflight_does_not_call_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_start_release_gate_preflight_does_not_execute_adapter',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_start_release_gate_preflight_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_start_release_gate_preflight_does_not_dispatch_work',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_start_release_gate_preflight_does_not_enable_self_programming',
            ],
            'human_summary' => $blockingReasons === []
                ? 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start process start release gate preflight is ready.'
                : 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start process start release gate preflight is blocked until provider execution, release gate and storage prerequisites are ready.',
        ];
    }

}
