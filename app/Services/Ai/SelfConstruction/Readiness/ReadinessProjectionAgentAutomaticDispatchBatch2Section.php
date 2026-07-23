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
 * SC-01 fatia ReadinessProjectionAgentAutomaticDispatchBatch2Section (Obra 4 Residual Elite).
 */
final class ReadinessProjectionAgentAutomaticDispatchBatch2Section
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
            throw new \RuntimeException('ReadinessProjectionAgentAutomaticDispatchBatch2Section mother not bound for '.$name);
        }
        $method = new \ReflectionMethod($this->mother, $name);

        return $method->invokeArgs($this->mother, $arguments);
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProcessInvocationAuthorizationGatePreflight(array $options = []): array
    {
        $contractPayload = $this->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProcessInvocationAuthorizationGateContract($options);
        $contract = (array) data_get($contractPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_invocation_authorization_gate_contract', []);
        $contractHash = (string) data_get($contractPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_invocation_authorization_gate_contract_hash');
        $gateReady = class_exists(AgentCodexRealInvokerPostStartProcessInvocationAuthorizationGate::class)
            && method_exists(AgentCodexRealInvokerPostStartProcessInvocationAuthorizationGate::class, 'authorizePostStartProcessInvocation');
        $invokerReady = class_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProcessInvocationAuthorizationGateInvoker::class)
            && method_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProcessInvocationAuthorizationGateInvoker::class, 'authorizeCodexRealInvokerPostStartProcessInvocationAuthorizationGate');
        $authorizationGateReady = class_exists(AgentCodexExternalProcessInvocationAuthorizationGate::class)
            && method_exists(AgentCodexExternalProcessInvocationAuthorizationGate::class, 'authorizeExternalProcessInvocation');
        $externalRuntimeGateReady = class_exists(AgentCodexRealInvokerPostStartExternalProcessRuntimeGate::class)
            && method_exists(AgentCodexRealInvokerPostStartExternalProcessRuntimeGate::class, 'preparePostStartExternalRuntime');
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerTableReady = Schema::hasTable('atlas_ledger_events');

        $checks = [
            'post_start_process_invocation_authorization_gate_contract_ready' => data_get($contractPayload, 'status') === 'one_shot_tick_codex_real_invoker_post_start_process_invocation_authorization_gate_contract_ready',
            'post_start_process_invocation_authorization_gate_contract_hash_present' => $contractHash !== '',
            'post_start_external_process_runtime_gate_status_ready' => data_get($contract, 'source_codex_real_invoker_post_start_external_process_runtime_gate_status') === 'one_shot_tick_codex_real_invoker_post_start_external_process_runtime_gate_service_ready',
            'generic_post_start_process_invocation_authorization_gate_template_ready' => data_get($contract, 'source_codex_real_invoker_post_start_process_invocation_authorization_gate_status') === 'codex_real_invoker_post_start_process_invocation_authorization_gate_contract_template_ready',
            'codex_real_invoker_post_start_process_invocation_authorization_gate_ready' => $gateReady,
            'codex_real_invoker_post_start_process_invocation_authorization_gate_invoker_ready' => $invokerReady,
            'codex_external_process_invocation_authorization_gate_ready' => $authorizationGateReady,
            'codex_real_invoker_post_start_external_process_runtime_gate_ready' => $externalRuntimeGateReady,
            'canonical_post_start_process_invocation_authorization_gate_method_ready' => data_get($contract, 'process_invocation_authorization.canonical_post_start_process_invocation_authorization_gate_method') === 'authorizePostStartProcessInvocation',
            'scheduler_invoker_method_ready' => data_get($contract, 'process_invocation_authorization.scheduler_invoker_method') === 'authorizeCodexRealInvokerPostStartProcessInvocationAuthorizationGate',
            'contract_requires_external_runtime' => data_get($contract, 'process_invocation_authorization.post_start_external_process_runtime_required_before_authorization') === true,
            'contract_requires_post_start_evidence_acceptance_bridge' => data_get($contract, 'process_invocation_authorization.post_start_evidence_acceptance_bridge_required_before_authorization') === true,
            'contract_delegates_to_codex_external_process_invocation_authorization_gate' => data_get($contract, 'process_invocation_authorization.gate_delegates_to_codex_external_process_invocation_authorization_gate') === true,
            'contract_declares_authorization_is_not_process_invocation' => data_get($contract, 'process_invocation_authorization.process_invocation_authorization_is_not_process_invocation') === true,
            'contract_requires_external_process_invoker_dry_run_after_authorization' => data_get($contract, 'process_invocation_authorization.external_process_invoker_dry_run_required_after_authorization') === true,
            'contract_keeps_actual_process_start_disabled' => data_get($contract, 'process_invocation_authorization.actual_process_start_allowed_by_contract') === false,
            'contract_keeps_adapter_execution_disabled' => data_get($contract, 'process_invocation_authorization.adapter_execution_allowed_by_contract') === false,
            'contract_does_not_dispatch_work' => data_get($contract, 'process_invocation_authorization.dispatch_allowed_by_contract') === false,
            'contract_does_not_spend_tokens' => data_get($contract, 'process_invocation_authorization.token_spend_allowed_by_contract') === false,
            'agent_runs_table_ready' => $runsTableReady,
            'ledger_table_ready' => $ledgerTableReady,
            'self_programming_forbidden' => in_array('enable_self_programming', (array) data_get($contract, 'forbidden_even_after_contract', []), true),
        ];
        $blockingReasons = array_values(array_keys(array_filter($checks, static fn (bool $passed): bool => ! $passed)));

        $preflight = [
            'status' => $blockingReasons === [] ? 'one_shot_tick_codex_real_invoker_post_start_process_invocation_authorization_gate_preflight_ready' : 'blocked',
            'preflight_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-REAL-INVOKER-POST-START-PROCESS-INVOCATION-AUTHORIZATION-GATE-PREFLIGHT-SELF-CONSTRUCTION-0001',
            'source_post_start_process_invocation_authorization_gate_contract_hash' => $contractHash,
            'preflight_checks' => $checks,
            'blocking_count' => count($blockingReasons),
            'blocking_reasons' => $blockingReasons,
            'implementation_requirements' => [
                'use_one_shot_scheduler_codex_real_invoker_post_start_process_invocation_authorization_gate_invoker',
                'delegate_to_agent_codex_real_invoker_post_start_process_invocation_authorization_gate',
                'require_codex_real_invoker_post_start_external_process_runtime_metadata',
                'require_post_start_evidence_acceptance_bridge_metadata',
                'record_process_invocation_authorization_without_invoking_external_process',
                'preserve_actual_process_start_disabled_until_external_process_invoker_dry_run',
            ],
            'runtime_policy' => [
                'preflight_is_read_only' => true,
                'post_start_process_invocation_authorization_gate_call_allowed_here' => false,
                'post_start_process_invocation_authorization_allowed_by_future_invoker' => true,
                'codex_external_process_invocation_authorization_gate_allowed_by_future_invoker' => true,
                'process_invocation_authorization_is_not_process_invocation' => true,
                'external_process_invoker_dry_run_required_after_future_invoker' => true,
                'actual_process_start_allowed_here' => false,
                'provider_process_call_allowed_here' => false,
                'adapter_invocation_allowed_here' => false,
                'adapter_execution_allowed_here' => false,
                'dispatch_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'self_programming_allowed_here' => false,
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_process_invocation_authorization_gate_implementation_packet',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_invocation_authorization_gate_preflight.v1',
            'status' => (string) $preflight['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_invocation_authorization_gate_preflight',
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
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_invocation_authorization_gate_preflight' => $preflight,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_invocation_authorization_gate_preflight_hash' => $this->stableHash($preflight),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_invocation_authorization_gate_preflight_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_invocation_authorization_gate_preflight_does_not_call_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_invocation_authorization_gate_preflight_does_not_execute_adapter',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_invocation_authorization_gate_preflight_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_invocation_authorization_gate_preflight_does_not_dispatch_work',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_invocation_authorization_gate_preflight_does_not_enable_self_programming',
            ],
            'human_summary' => $blockingReasons === []
                ? 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start process invocation authorization gate preflight is ready.'
                : 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start process invocation authorization gate preflight is blocked until external runtime, authorization and storage prerequisites are ready.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartExternalProcessInvokerDryRunGatePreflight(array $options = []): array
    {
        $contractPayload = $this->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartExternalProcessInvokerDryRunGateContract($options);
        $contract = (array) data_get($contractPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_external_process_invoker_dry_run_gate_contract', []);
        $contractHash = (string) data_get($contractPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_external_process_invoker_dry_run_gate_contract_hash');
        $gateReady = class_exists(AgentCodexRealInvokerPostStartExternalProcessInvokerDryRunGate::class)
            && method_exists(AgentCodexRealInvokerPostStartExternalProcessInvokerDryRunGate::class, 'preparePostStartExternalProcessInvokerDryRun');
        $invokerReady = class_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartExternalProcessInvokerDryRunGateInvoker::class)
            && method_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartExternalProcessInvokerDryRunGateInvoker::class, 'prepareCodexRealInvokerPostStartExternalProcessInvokerDryRunGate');
        $dryRunReady = class_exists(AgentCodexExternalProcessInvokerDryRun::class)
            && method_exists(AgentCodexExternalProcessInvokerDryRun::class, 'prepareDryRun');
        $authorizationGateReady = class_exists(AgentCodexRealInvokerPostStartProcessInvocationAuthorizationGate::class)
            && method_exists(AgentCodexRealInvokerPostStartProcessInvocationAuthorizationGate::class, 'authorizePostStartProcessInvocation');
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerTableReady = Schema::hasTable('atlas_ledger_events');

        $checks = [
            'post_start_external_process_invoker_dry_run_gate_contract_ready' => data_get($contractPayload, 'status') === 'one_shot_tick_codex_real_invoker_post_start_external_process_invoker_dry_run_gate_contract_ready',
            'post_start_external_process_invoker_dry_run_gate_contract_hash_present' => $contractHash !== '',
            'post_start_process_invocation_authorization_gate_status_ready' => data_get($contract, 'source_codex_real_invoker_post_start_process_invocation_authorization_gate_status') === 'one_shot_tick_codex_real_invoker_post_start_process_invocation_authorization_gate_service_ready',
            'generic_post_start_external_process_invoker_dry_run_gate_template_ready' => data_get($contract, 'source_codex_real_invoker_post_start_external_process_invoker_dry_run_gate_status') === 'codex_real_invoker_post_start_external_process_invoker_dry_run_gate_contract_template_ready',
            'codex_real_invoker_post_start_external_process_invoker_dry_run_gate_ready' => $gateReady,
            'codex_real_invoker_post_start_external_process_invoker_dry_run_gate_invoker_ready' => $invokerReady,
            'codex_external_process_invoker_dry_run_service_ready' => $dryRunReady,
            'codex_real_invoker_post_start_process_invocation_authorization_gate_ready' => $authorizationGateReady,
            'canonical_post_start_external_process_invoker_dry_run_gate_method_ready' => data_get($contract, 'external_process_invoker_dry_run.canonical_post_start_external_process_invoker_dry_run_gate_method') === 'preparePostStartExternalProcessInvokerDryRun',
            'scheduler_invoker_method_ready' => data_get($contract, 'external_process_invoker_dry_run.scheduler_invoker_method') === 'prepareCodexRealInvokerPostStartExternalProcessInvokerDryRunGate',
            'contract_requires_process_invocation_authorization' => data_get($contract, 'external_process_invoker_dry_run.post_start_process_invocation_authorization_required_before_dry_run') === true,
            'contract_requires_post_start_evidence_acceptance_bridge' => data_get($contract, 'external_process_invoker_dry_run.post_start_evidence_acceptance_bridge_required_before_dry_run') === true,
            'contract_delegates_to_codex_external_process_invoker_dry_run' => data_get($contract, 'external_process_invoker_dry_run.gate_delegates_to_codex_external_process_invoker_dry_run') === true,
            'contract_declares_dry_run_is_not_real_invoker_execution' => data_get($contract, 'external_process_invoker_dry_run.external_process_invoker_dry_run_is_not_real_invoker_execution') === true,
            'contract_requires_real_invoker_release_preflight_after_dry_run' => data_get($contract, 'external_process_invoker_dry_run.real_invoker_release_preflight_required_after_dry_run') === true,
            'contract_keeps_actual_process_start_disabled' => data_get($contract, 'external_process_invoker_dry_run.actual_process_start_allowed_by_contract') === false,
            'contract_keeps_adapter_execution_disabled' => data_get($contract, 'external_process_invoker_dry_run.adapter_execution_allowed_by_contract') === false,
            'contract_does_not_dispatch_work' => data_get($contract, 'external_process_invoker_dry_run.dispatch_allowed_by_contract') === false,
            'contract_does_not_spend_tokens' => data_get($contract, 'external_process_invoker_dry_run.token_spend_allowed_by_contract') === false,
            'agent_runs_table_ready' => $runsTableReady,
            'ledger_table_ready' => $ledgerTableReady,
            'self_programming_forbidden' => in_array('enable_self_programming', (array) data_get($contract, 'forbidden_even_after_contract', []), true),
        ];
        $blockingReasons = array_values(array_keys(array_filter($checks, static fn (bool $passed): bool => ! $passed)));

        $preflight = [
            'status' => $blockingReasons === [] ? 'one_shot_tick_codex_real_invoker_post_start_external_process_invoker_dry_run_gate_preflight_ready' : 'blocked',
            'preflight_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-REAL-INVOKER-POST-START-EXTERNAL-PROCESS-INVOKER-DRY-RUN-GATE-PREFLIGHT-SELF-CONSTRUCTION-0001',
            'source_post_start_external_process_invoker_dry_run_gate_contract_hash' => $contractHash,
            'preflight_checks' => $checks,
            'blocking_count' => count($blockingReasons),
            'blocking_reasons' => $blockingReasons,
            'implementation_requirements' => [
                'use_one_shot_scheduler_codex_real_invoker_post_start_external_process_invoker_dry_run_gate_invoker',
                'delegate_to_agent_codex_real_invoker_post_start_external_process_invoker_dry_run_gate',
                'require_codex_real_invoker_post_start_process_invocation_authorization_metadata',
                'require_post_start_evidence_acceptance_bridge_metadata',
                'prepare_external_process_invoker_dry_run_without_running_real_invoker',
                'preserve_actual_process_start_disabled_until_real_invoker_release_preflight',
            ],
            'runtime_policy' => [
                'preflight_is_read_only' => true,
                'post_start_external_process_invoker_dry_run_gate_call_allowed_here' => false,
                'post_start_external_process_invoker_dry_run_allowed_by_future_invoker' => true,
                'codex_external_process_invoker_dry_run_allowed_by_future_invoker' => true,
                'external_process_invoker_dry_run_is_not_real_invoker_execution' => true,
                'real_invoker_release_preflight_required_after_future_invoker' => true,
                'actual_process_start_allowed_here' => false,
                'provider_process_call_allowed_here' => false,
                'adapter_invocation_allowed_here' => false,
                'adapter_execution_allowed_here' => false,
                'dispatch_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'self_programming_allowed_here' => false,
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_external_process_invoker_dry_run_gate_implementation_packet',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_external_process_invoker_dry_run_gate_preflight.v1',
            'status' => (string) $preflight['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_external_process_invoker_dry_run_gate_preflight',
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
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_external_process_invoker_dry_run_gate_preflight' => $preflight,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_external_process_invoker_dry_run_gate_preflight_hash' => $this->stableHash($preflight),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_external_process_invoker_dry_run_gate_preflight_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_external_process_invoker_dry_run_gate_preflight_does_not_call_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_external_process_invoker_dry_run_gate_preflight_does_not_execute_adapter',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_external_process_invoker_dry_run_gate_preflight_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_external_process_invoker_dry_run_gate_preflight_does_not_dispatch_work',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_external_process_invoker_dry_run_gate_preflight_does_not_enable_self_programming',
            ],
            'human_summary' => $blockingReasons === []
                ? 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start external process invoker dry-run gate preflight is ready; implementation remains scoped to dry-run preparation only.'
                : 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start external process invoker dry-run gate preflight is blocked until authorization, dry-run and storage prerequisites are ready.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerProcessStartEnvelopeBuilderContract(array $options = []): array
    {
        $rehearsalStatusPayload = $this->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerActualProcessStartRehearsalExecutorStatus($options);
        $rehearsalStatus = (array) data_get($rehearsalStatusPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_actual_process_start_rehearsal_executor_status', []);
        $envelopePayload = $this->agentCodexRealInvokerProcessStartEnvelopeBuilderContractTemplate($options);

        $contract = [
            'status' => 'one_shot_tick_codex_real_invoker_process_start_envelope_builder_contract_ready',
            'contract_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-REAL-INVOKER-PROCESS-START-ENVELOPE-BUILDER-CONTRACT-SELF-CONSTRUCTION-0001',
            'parent_program' => 'Atlas Self-Construction OS',
            'submodule' => 'Atlas Agent Control Plane',
            'provider' => 'codex',
            'adapter' => 'codex',
            'source_codex_real_invoker_actual_process_start_rehearsal_executor_status' => data_get($rehearsalStatus, 'status'),
            'source_codex_real_invoker_actual_process_start_rehearsal_executor_status_hash' => data_get($rehearsalStatusPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_actual_process_start_rehearsal_executor_status_hash'),
            'source_codex_real_invoker_process_start_envelope_builder_contract_status' => data_get($envelopePayload, 'status'),
            'source_codex_real_invoker_process_start_envelope_builder_contract_hash' => data_get($envelopePayload, 'codex_real_invoker_process_start_envelope_builder_contract_template_hash'),
            'release_boundary' => [
                'canonical_process_start_envelope_builder' => AgentCodexRealInvokerProcessStartEnvelopeBuilder::class,
                'canonical_process_start_envelope_builder_method' => 'buildStartEnvelope',
                'scheduler_invoker' => AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerProcessStartEnvelopeBuilderInvoker::class,
                'scheduler_invoker_method' => 'buildCodexRealInvokerProcessStartEnvelope',
                'envelope_effect' => 'record_process_start_envelope_without_starting_process',
                'actual_process_start_rehearsal_required_before_envelope' => true,
                'process_start_envelope_built_by_builder' => true,
                'start_envelope_ready_by_builder' => true,
                'actual_process_start_allowed_by_builder' => false,
                'external_process_started_by_builder' => false,
                'provider_started_by_builder' => false,
                'adapter_execution_allowed_by_builder' => false,
                'token_spend_allowed_by_builder' => false,
                'idempotency_key' => 'real_invoker_process_start_envelope_id',
            ],
            'required_input_fields_for_future_invoker' => [
                'run_key',
                'codex_execution_id',
                'real_invoker_final_process_start_authorization_id',
                'real_invoker_actual_process_start_rehearsal_id',
                'real_invoker_process_start_envelope_id',
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
                'process_start_envelope_hash',
                'start_command_hash',
                'start_environment_hash',
                'start_cwd_hash',
                'start_supervisor_hash',
                'start_liveness_contract_hash',
                'actor',
                'session',
                'reason',
            ],
            'allowed_future_mutations' => [
                'write_codex_real_invoker_process_start_envelope_metadata_on_agent_run',
                'append_codex_real_invoker_process_start_envelope_evidence_event',
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
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_process_start_envelope_preflight',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_process_start_envelope_builder_contract.v1',
            'status' => (string) $contract['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_process_start_envelope_builder_contract',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_process_start_envelope_builder_allowed' => false,
            'start_envelope_ready' => false,
            'actual_process_start_allowed' => false,
            'adapter_execution_allowed' => false,
            'provider_start_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_process_start_envelope_builder_contract' => $contract,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_process_start_envelope_builder_contract_hash' => $this->stableHash($contract),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_process_start_envelope_builder_contract_does_not_build_start_envelope',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_process_start_envelope_builder_contract_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_process_start_envelope_builder_contract_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_process_start_envelope_builder_contract_does_not_enable_self_programming',
            ],
            'human_summary' => 'Automatic dispatch scheduler one-shot tick Codex real invoker process start envelope contract is ready; it can build the start envelope metadata later but still cannot start Codex.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartEvidenceReceiptContract(array $options = []): array
    {
        $receiptStatusPayload = $this->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartReceiptContractStatus($options);
        $receiptStatus = (array) data_get($receiptStatusPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_receipt_contract_status', []);
        $writerPayload = $this->agentCodexRealInvokerPostStartEvidenceReceiptWriterContractTemplate($options);

        $contract = [
            'status' => 'one_shot_tick_codex_real_invoker_post_start_evidence_receipt_contract_ready',
            'contract_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-REAL-INVOKER-POST-START-EVIDENCE-RECEIPT-CONTRACT-SELF-CONSTRUCTION-0001',
            'parent_program' => 'Atlas Self-Construction OS',
            'submodule' => 'Atlas Agent Control Plane',
            'provider' => 'codex',
            'adapter' => 'codex',
            'source_codex_real_invoker_post_start_receipt_contract_status' => data_get($receiptStatus, 'status'),
            'source_codex_real_invoker_post_start_receipt_contract_status_hash' => data_get($receiptStatusPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_receipt_contract_status_hash'),
            'source_codex_real_invoker_post_start_evidence_receipt_writer_status' => data_get($writerPayload, 'status'),
            'source_codex_real_invoker_post_start_evidence_receipt_writer_hash' => data_get($writerPayload, 'codex_real_invoker_post_start_evidence_receipt_writer_contract_template_hash'),
            'release_boundary' => [
                'canonical_post_start_evidence_receipt_writer' => AgentCodexRealInvokerPostStartEvidenceReceiptWriter::class,
                'canonical_post_start_evidence_receipt_writer_method' => 'writePostStartEvidenceReceipt',
                'scheduler_invoker' => AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartEvidenceReceiptInvoker::class,
                'scheduler_invoker_method' => 'writeCodexRealInvokerPostStartEvidenceReceipt',
                'gate_effect' => 'record_operator_external_start_evidence_without_atlas_owned_process_spawn',
                'post_start_receipt_contract_required_before_evidence_receipt' => true,
                'post_start_evidence_acceptance_bridge_required' => true,
                'operator_external_start_attestation_required' => true,
                'no_atlas_process_spawn_attestation_required' => true,
                'external_process_evidence_accepted_by_contract' => true,
                'actual_process_start_allowed_by_contract' => false,
                'atlas_process_spawned_by_contract' => false,
                'provider_marked_started_by_contract' => true,
                'adapter_execution_allowed_by_contract' => false,
                'token_spend_allowed_by_contract' => false,
                'dispatch_allowed_by_contract' => false,
                'idempotency_key' => 'post_start_evidence_receipt_id',
            ],
            'required_input_fields_for_future_invoker' => [
                'run_key',
                'codex_execution_id',
                'real_invoker_process_starter_readiness_gate_id',
                'real_invoker_start_execution_gate_id',
                'manual_start_executor_receipt_id',
                'operator_start_handoff_id',
                'post_start_evidence_acceptance_bridge_id',
                'post_start_receipt_contract_id',
                'post_start_evidence_receipt_id',
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
                'write_codex_real_invoker_post_start_evidence_receipt_metadata_on_agent_run',
                'append_codex_real_invoker_post_start_evidence_receipt_evidence_event',
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
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_evidence_receipt_preflight',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_evidence_receipt_contract.v1',
            'status' => (string) $contract['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_evidence_receipt_contract',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_post_start_evidence_receipt_allowed' => false,
            'external_process_evidence_acceptance_allowed' => false,
            'actual_process_start_allowed' => false,
            'adapter_execution_allowed' => false,
            'provider_start_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_evidence_receipt_contract' => $contract,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_evidence_receipt_contract_hash' => $this->stableHash($contract),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_evidence_receipt_contract_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_evidence_receipt_contract_does_not_call_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_evidence_receipt_contract_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_evidence_receipt_contract_does_not_enable_self_programming',
            ],
            'human_summary' => 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start evidence receipt contract is ready; it defines governed external-start evidence acceptance without Atlas spawning Codex.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartAdapterInvocationBoundaryGatePreflight(array $options = []): array
    {
        $contractPayload = $this->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartAdapterInvocationBoundaryGateContract($options);
        $contract = (array) data_get($contractPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_adapter_invocation_boundary_gate_contract', []);
        $contractHash = (string) data_get($contractPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_adapter_invocation_boundary_gate_contract_hash');
        $gateReady = class_exists(AgentCodexRealInvokerPostStartAdapterInvocationBoundaryGate::class)
            && method_exists(AgentCodexRealInvokerPostStartAdapterInvocationBoundaryGate::class, 'preparePostStartAdapterInvocationBoundary');
        $invokerReady = class_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartAdapterInvocationBoundaryGateInvoker::class)
            && method_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartAdapterInvocationBoundaryGateInvoker::class, 'prepareCodexRealInvokerPostStartAdapterInvocationBoundaryGate');
        $boundaryReady = class_exists(AgentDispatchExecutorAdapterInvocationBoundary::class)
            && method_exists(AgentDispatchExecutorAdapterInvocationBoundary::class, 'prepareInvocation');
        $registryReady = class_exists(AgentProviderAdapterRegistry::class)
            && method_exists(AgentProviderAdapterRegistry::class, 'resolve');
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $heartbeatsTableReady = Schema::hasTable('atlas_self_construction_agent_heartbeats');
        $ledgerTableReady = Schema::hasTable('atlas_ledger_events');

        $checks = [
            'post_start_adapter_invocation_boundary_gate_contract_ready' => data_get($contractPayload, 'status') === 'one_shot_tick_codex_real_invoker_post_start_adapter_invocation_boundary_gate_contract_ready',
            'post_start_adapter_invocation_boundary_gate_contract_hash_present' => $contractHash !== '',
            'post_start_provider_start_driver_gate_status_ready' => data_get($contract, 'source_codex_real_invoker_post_start_provider_start_driver_gate_status') === 'one_shot_tick_codex_real_invoker_post_start_provider_start_driver_gate_service_ready',
            'generic_post_start_adapter_invocation_boundary_gate_template_ready' => data_get($contract, 'source_codex_real_invoker_post_start_adapter_invocation_boundary_gate_status') === 'codex_real_invoker_post_start_adapter_invocation_boundary_gate_contract_template_ready',
            'codex_real_invoker_post_start_adapter_invocation_boundary_gate_ready' => $gateReady,
            'codex_real_invoker_post_start_adapter_invocation_boundary_gate_invoker_ready' => $invokerReady,
            'dispatch_executor_adapter_invocation_boundary_ready' => $boundaryReady,
            'provider_adapter_registry_ready' => $registryReady,
            'canonical_post_start_adapter_invocation_boundary_gate_method_ready' => data_get($contract, 'adapter_invocation_boundary.canonical_post_start_adapter_invocation_boundary_gate_method') === 'preparePostStartAdapterInvocationBoundary',
            'contract_requires_provider_start_driver' => data_get($contract, 'adapter_invocation_boundary.post_start_provider_start_driver_required_before_boundary') === true,
            'contract_requires_pre_start_heartbeat' => data_get($contract, 'adapter_invocation_boundary.pre_start_heartbeat_required_before_boundary') === true,
            'contract_requires_context_pack_hash' => data_get($contract, 'adapter_invocation_boundary.context_pack_hash_required') === true,
            'contract_requires_continuation_summary_hash' => data_get($contract, 'adapter_invocation_boundary.continuation_summary_hash_required') === true,
            'contract_projects_adapter_descriptor_hash' => data_get($contract, 'adapter_invocation_boundary.adapter_descriptor_hash_projected_by_boundary') === true,
            'contract_keeps_actual_process_start_disabled' => data_get($contract, 'adapter_invocation_boundary.actual_process_start_allowed_by_contract') === false,
            'contract_keeps_adapter_execution_disabled' => data_get($contract, 'adapter_invocation_boundary.adapter_execution_allowed_by_contract') === false,
            'contract_does_not_dispatch_work' => data_get($contract, 'adapter_invocation_boundary.dispatch_allowed_by_contract') === false,
            'contract_does_not_spend_tokens' => data_get($contract, 'adapter_invocation_boundary.token_spend_allowed_by_contract') === false,
            'agent_runs_table_ready' => $runsTableReady,
            'agent_heartbeats_table_ready' => $heartbeatsTableReady,
            'ledger_table_ready' => $ledgerTableReady,
            'self_programming_forbidden' => in_array('enable_self_programming', (array) data_get($contract, 'forbidden_even_after_contract', []), true),
        ];
        $blockingReasons = array_values(array_keys(array_filter($checks, static fn (bool $passed): bool => ! $passed)));

        $preflight = [
            'status' => $blockingReasons === [] ? 'one_shot_tick_codex_real_invoker_post_start_adapter_invocation_boundary_gate_preflight_ready' : 'blocked',
            'preflight_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-REAL-INVOKER-POST-START-ADAPTER-INVOCATION-BOUNDARY-GATE-PREFLIGHT-SELF-CONSTRUCTION-0001',
            'source_post_start_adapter_invocation_boundary_gate_contract_hash' => $contractHash,
            'preflight_checks' => $checks,
            'blocking_count' => count($blockingReasons),
            'blocking_reasons' => $blockingReasons,
            'implementation_requirements' => [
                'use_one_shot_scheduler_codex_real_invoker_post_start_adapter_invocation_boundary_gate_invoker',
                'delegate_to_agent_codex_real_invoker_post_start_adapter_invocation_boundary_gate',
                'require_codex_real_invoker_post_start_provider_start_driver_metadata',
                'require_pre_start_guard_heartbeat_and_provider_run',
                'prepare_adapter_invocation_metadata_without_calling_codex',
                'preserve_adapter_execution_disabled_until_adapter_execution_guard_gate',
            ],
            'runtime_policy' => [
                'preflight_is_read_only' => true,
                'post_start_adapter_invocation_boundary_gate_call_allowed_here' => false,
                'adapter_invocation_metadata_allowed_by_future_invoker' => true,
                'provider_external_process_start_allowed_here' => false,
                'provider_process_call_allowed_here' => false,
                'adapter_invocation_allowed_here' => false,
                'adapter_execution_allowed_here' => false,
                'dispatch_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'self_programming_allowed_here' => false,
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_adapter_invocation_boundary_gate_implementation_packet',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_adapter_invocation_boundary_gate_preflight.v1',
            'status' => (string) $preflight['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_adapter_invocation_boundary_gate_preflight',
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
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_adapter_invocation_boundary_gate_preflight' => $preflight,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_adapter_invocation_boundary_gate_preflight_hash' => $this->stableHash($preflight),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_adapter_invocation_boundary_gate_preflight_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_adapter_invocation_boundary_gate_preflight_does_not_call_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_adapter_invocation_boundary_gate_preflight_does_not_execute_adapter',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_adapter_invocation_boundary_gate_preflight_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_adapter_invocation_boundary_gate_preflight_does_not_dispatch_work',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_adapter_invocation_boundary_gate_preflight_does_not_enable_self_programming',
            ],
            'human_summary' => $blockingReasons === []
                ? 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start adapter invocation boundary gate preflight is ready.'
                : 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start adapter invocation boundary gate preflight is blocked until provider-start, boundary, registry and storage prerequisites are ready.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickDispatchReceiptUseStatus(array $options = []): array
    {
        $dispatchReceiptTable = 'atlas_self_construction_agent_dispatch_receipts';
        $ledgerTable = 'atlas_ledger_events';
        $dispatchReceiptTableReady = Schema::hasTable($dispatchReceiptTable);
        $ledgerTableReady = Schema::hasTable($ledgerTable);
        $writerReady = class_exists(AgentDispatchExecutorReceiptUseWriter::class)
            && method_exists(AgentDispatchExecutorReceiptUseWriter::class, 'markReceiptUsedAtomically');
        $invokerReady = class_exists(AgentAutomaticDispatchSchedulerOneShotTickDispatchReceiptUseInvoker::class)
            && method_exists(AgentAutomaticDispatchSchedulerOneShotTickDispatchReceiptUseInvoker::class, 'markSignedDispatchReceiptUsed');
        $receiptHash = strtolower(trim((string) ($options['receipt_hash'] ?? '')));
        $receiptHashValid = $receiptHash === '' || preg_match('/^[a-f0-9]{64}$/', $receiptHash) === 1;

        $pendingQuery = $dispatchReceiptTableReady
            ? AtlasSelfConstructionAgentDispatchReceipt::query()
                ->where('decision', 'approve_dispatch_once')
                ->where('status', 'signed_pending_dispatch')
            : null;
        $usedQuery = $dispatchReceiptTableReady
            ? AtlasSelfConstructionAgentDispatchReceipt::query()
                ->where('decision', 'approve_dispatch_once')
                ->where('status', 'used_pending_provider_start')
                ->whereNotNull('used_at')
            : null;

        if ($receiptHash !== '' && $receiptHashValid) {
            $pendingQuery?->where('receipt_hash', $receiptHash);
            $usedQuery?->where('receipt_hash', $receiptHash);
        }

        $latestUsedReceipt = $usedQuery === null
            ? null
            : (clone $usedQuery)->latest('used_at')->first();
        $statusReady = $dispatchReceiptTableReady && $ledgerTableReady && $writerReady && $invokerReady && $receiptHashValid;
        $status = [
            'status' => $statusReady ? 'one_shot_tick_dispatch_receipt_use_service_ready' : 'blocked',
            'invoker_service' => AgentAutomaticDispatchSchedulerOneShotTickDispatchReceiptUseInvoker::class,
            'invoker_service_ready' => $invokerReady,
            'invoker_canonical_method' => 'markSignedDispatchReceiptUsed',
            'writer_service' => AgentDispatchExecutorReceiptUseWriter::class,
            'writer_service_ready' => $writerReady,
            'writer_canonical_method' => 'markReceiptUsedAtomically',
            'dispatch_receipts_table' => $dispatchReceiptTable,
            'dispatch_receipts_table_ready' => $dispatchReceiptTableReady,
            'ledger_table_ready' => $ledgerTableReady,
            'receipt_hash_filter' => $receiptHashValid && $receiptHash !== '' ? $receiptHash : null,
            'receipt_hash_filter_valid' => $receiptHashValid,
            'signed_pending_dispatch_receipt_count' => $pendingQuery === null ? null : (clone $pendingQuery)->count(),
            'used_pending_provider_start_receipt_count' => $usedQuery === null ? null : (clone $usedQuery)->count(),
            'latest_used_pending_provider_start_receipt' => $latestUsedReceipt instanceof AtlasSelfConstructionAgentDispatchReceipt
                ? [
                    'receipt_id' => $latestUsedReceipt->id,
                    'receipt_key' => $latestUsedReceipt->receipt_key,
                    'receipt_hash' => $latestUsedReceipt->receipt_hash,
                    'packet_id' => $latestUsedReceipt->packet_id,
                    'provider' => $latestUsedReceipt->provider,
                    'used_at' => $latestUsedReceipt->used_at?->toIso8601String(),
                    'provider_start_attempt_id' => data_get($latestUsedReceipt->payload, 'receipt_use.provider_start_attempt_id'),
                    'provider_start_side_effect_performed' => data_get($latestUsedReceipt->payload, 'receipt_use.provider_start_side_effect_performed'),
                ]
                : null,
            'runtime_policy' => [
                'status_projection_is_read_only' => true,
                'invoker_may_mark_receipt_used_when_called_with_signed_input' => true,
                'receipt_use_is_not_provider_start' => true,
                'provider_start_allowed_here' => false,
                'adapter_invocation_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'self_programming_allowed_here' => false,
            ],
            'next_required_slice' => $statusReady
                ? 'activate_signed_one_shot_scheduler_tick_provider_start_driver_release_contract'
                : 'repair_one_shot_scheduler_tick_dispatch_receipt_use_service_blockers',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_dispatch_receipt_use_status.v1',
            'status' => (string) $status['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_dispatch_receipt_use_status',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'dispatch_receipt_use_allowed' => false,
            'provider_start_allowed' => false,
            'adapter_invocation_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_dispatch_receipt_use_status' => $status,
            'agent_automatic_dispatch_scheduler_one_shot_tick_dispatch_receipt_use_status_hash' => $this->stableHash($status),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_dispatch_receipt_use_status_does_not_mark_receipt_used',
                'agent_automatic_dispatch_scheduler_one_shot_tick_dispatch_receipt_use_status_does_not_start_providers',
                'agent_automatic_dispatch_scheduler_one_shot_tick_dispatch_receipt_use_status_does_not_call_adapters',
                'agent_automatic_dispatch_scheduler_one_shot_tick_dispatch_receipt_use_status_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_dispatch_receipt_use_status_does_not_enable_self_programming',
            ],
            'human_summary' => $statusReady
                ? 'Automatic dispatch scheduler one-shot tick dispatch receipt-use service is ready and inspectable; status remains read-only and provider start is still forbidden.'
                : 'Automatic dispatch scheduler one-shot tick dispatch receipt-use service is blocked until invoker, writer, storage and receipt filter prerequisites are ready.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexExternalProcessInvokerDryRunContract(array $options = []): array
    {
        $authorizationStatusPayload = $this->agentAutomaticDispatchSchedulerOneShotTickCodexProcessInvocationAuthorizationStatus($options);
        $authorizationStatus = (array) data_get($authorizationStatusPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_process_invocation_authorization_status', []);
        $dryRunPayload = $this->agentCodexExternalProcessInvokerDryRunContractTemplate($options);

        $contract = [
            'status' => 'one_shot_tick_codex_external_process_invoker_dry_run_contract_ready',
            'contract_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-EXTERNAL-PROCESS-INVOKER-DRY-RUN-CONTRACT-SELF-CONSTRUCTION-0001',
            'parent_program' => 'Atlas Self-Construction OS',
            'submodule' => 'Atlas Agent Control Plane',
            'provider' => 'codex',
            'adapter' => 'codex',
            'source_codex_process_invocation_authorization_status' => data_get($authorizationStatus, 'status'),
            'source_codex_process_invocation_authorization_status_hash' => data_get($authorizationStatusPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_process_invocation_authorization_status_hash'),
            'source_codex_external_process_invoker_dry_run_contract_status' => data_get($dryRunPayload, 'status'),
            'source_codex_external_process_invoker_dry_run_contract_hash' => data_get($dryRunPayload, 'codex_external_process_invoker_dry_run_contract_template_hash'),
            'release_boundary' => [
                'canonical_dry_run' => AgentCodexExternalProcessInvokerDryRun::class,
                'canonical_dry_run_method' => 'prepareDryRun',
                'scheduler_invoker' => AgentAutomaticDispatchSchedulerOneShotTickCodexExternalProcessInvokerDryRunInvoker::class,
                'scheduler_invoker_method' => 'prepareCodexExternalProcessInvokerDryRun',
                'dry_run_effect' => 'prepare_codex_external_process_invoker_dry_run_metadata_without_real_process_invocation',
                'external_process_started_by_dry_run' => false,
                'provider_started_by_dry_run' => false,
                'adapter_execution_allowed_by_dry_run' => false,
                'token_spend_allowed_by_dry_run' => false,
                'required_authorization_status_before_dry_run' => 'authorized_pending_external_process_invoker',
                'prepared_status_after_dry_run' => 'dry_run_ready_pending_real_invoker_release',
                'idempotency_key' => 'dry_run_id',
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
                'operator_dry_run_receipt_hash',
                'invoker_contract_hash',
                'process_command_hash',
                'environment_contract_hash',
                'termination_policy_hash',
                'stdout_stderr_sink_hash',
                'liveness_probe_hash',
                'actor',
                'session',
                'reason',
            ],
            'allowed_future_mutations' => [
                'write_codex_external_process_invoker_dry_run_metadata_on_agent_run',
                'append_codex_external_process_invoker_dry_run_prepared_evidence_event',
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
                'external_process_invoker_dry_run_is_rehearsal_not_invocation' => true,
                'real_invoker_release_requires_separate_signed_preflight' => true,
                'operator_dry_run_receipt_hash_required' => true,
                'invoker_contract_hash_required' => true,
                'stdout_stderr_sink_hash_required' => true,
                'liveness_probe_hash_required' => true,
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_external_process_invoker_dry_run_preflight',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_external_process_invoker_dry_run_contract.v1',
            'status' => (string) $contract['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_external_process_invoker_dry_run_contract',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_external_process_invoker_dry_run_allowed' => false,
            'adapter_execution_allowed' => false,
            'provider_start_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_external_process_invoker_dry_run_contract' => $contract,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_external_process_invoker_dry_run_contract_hash' => $this->stableHash($contract),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_external_process_invoker_dry_run_contract_does_not_call_dry_run',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_external_process_invoker_dry_run_contract_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_external_process_invoker_dry_run_contract_does_not_call_adapters',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_external_process_invoker_dry_run_contract_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_external_process_invoker_dry_run_contract_does_not_enable_self_programming',
            ],
            'human_summary' => 'Automatic dispatch scheduler one-shot tick Codex external process invoker dry-run contract is ready; it rehearses invocation metadata but cannot start Codex.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartDispatchReceiptUseExecutorContract(array $options = []): array
    {
        $handoffStatusPayload = $this->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartDispatchExecutorHandoffStatus($options);
        $handoffStatus = (array) data_get($handoffStatusPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_dispatch_executor_handoff_status', []);
        $receiptUsePayload = $this->agentCodexRealInvokerPostStartDispatchReceiptUseExecutorContractTemplate($options);

        $contract = [
            'status' => 'one_shot_tick_codex_real_invoker_post_start_dispatch_receipt_use_executor_contract_ready',
            'contract_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-REAL-INVOKER-POST-START-DISPATCH-RECEIPT-USE-EXECUTOR-CONTRACT-SELF-CONSTRUCTION-0001',
            'parent_program' => 'Atlas Self-Construction OS',
            'submodule' => 'Atlas Agent Control Plane',
            'provider' => 'codex',
            'adapter' => 'codex',
            'source_codex_real_invoker_post_start_dispatch_executor_handoff_status' => data_get($handoffStatus, 'status'),
            'source_codex_real_invoker_post_start_dispatch_executor_handoff_status_hash' => data_get($handoffStatusPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_dispatch_executor_handoff_status_hash'),
            'source_codex_real_invoker_post_start_dispatch_receipt_use_executor_status' => data_get($receiptUsePayload, 'status'),
            'source_codex_real_invoker_post_start_dispatch_receipt_use_executor_hash' => data_get($receiptUsePayload, 'codex_real_invoker_post_start_dispatch_receipt_use_executor_contract_template_hash'),
            'receipt_use_boundary' => [
                'canonical_post_start_dispatch_receipt_use_executor' => AgentCodexRealInvokerPostStartDispatchReceiptUseExecutor::class,
                'canonical_post_start_dispatch_receipt_use_executor_method' => 'executePostStartDispatchReceiptUse',
                'scheduler_invoker' => AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartDispatchReceiptUseExecutorInvoker::class,
                'scheduler_invoker_method' => 'executeCodexRealInvokerPostStartDispatchReceiptUse',
                'receipt_use_effect' => 'mark_signed_dispatch_receipt_used_after_dispatch_executor_handoff_without_starting_provider',
                'post_start_dispatch_executor_handoff_required_before_receipt_use' => true,
                'post_start_evidence_acceptance_bridge_required_before_receipt_use' => true,
                'signed_dispatch_authorization_required_before_receipt_use' => true,
                'signed_dispatch_receipt_hash_required' => true,
                'executor_contract_hash_required' => true,
                'executor_release_authorization_hash_required' => true,
                'executor_handoff_packet_hash_required' => true,
                'executor_workspace_hash_required' => true,
                'executor_scope_lock_hash_required' => true,
                'dispatch_receipt_used_by_contract' => true,
                'provider_start_allowed_after_mark_by_contract' => false,
                'actual_process_start_allowed_by_contract' => false,
                'provider_process_call_allowed_by_contract' => false,
                'adapter_execution_allowed_by_contract' => false,
                'token_spend_allowed_by_contract' => false,
                'dispatch_allowed_by_contract' => false,
                'idempotency_key' => 'provider_start_attempt_id',
            ],
            'required_input_fields_for_future_invoker' => [
                'run_key',
                'dispatch_executor_handoff_id',
                'signed_dispatch_authorization_id',
                'post_start_evidence_acceptance_bridge_id',
                'signed_dispatch_receipt_hash',
                'executor_contract_hash',
                'executor_release_authorization_hash',
                'executor_handoff_packet_hash',
                'executor_workspace_hash',
                'executor_scope_lock_hash',
                'provider_start_attempt_id',
                'actor',
                'session',
                'reason',
            ],
            'allowed_future_mutations' => [
                'mark_one_signed_dispatch_receipt_used_via_atomic_writer',
                'write_codex_real_invoker_post_start_dispatch_receipt_use_metadata_on_agent_run',
                'append_dispatch_receipt_use_evidence_event',
            ],
            'forbidden_even_after_contract' => [
                'spawn_codex_process',
                'call_codex_cli_or_codex_app',
                'dispatch_work_to_codex',
                'send_prompt_to_provider',
                'call_provider_process',
                'enable_adapter_invocation',
                'spend_provider_tokens',
                'mark_run_running_or_terminal',
                'enable_self_programming',
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_dispatch_receipt_use_executor_preflight',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_dispatch_receipt_use_executor_contract.v1',
            'status' => (string) $contract['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_dispatch_receipt_use_executor_contract',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'post_start_dispatch_receipt_use_executor_allowed' => false,
            'actual_process_start_allowed' => false,
            'adapter_execution_allowed' => false,
            'provider_start_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_dispatch_receipt_use_executor_contract' => $contract,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_dispatch_receipt_use_executor_contract_hash' => $this->stableHash($contract),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_dispatch_receipt_use_executor_contract_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_dispatch_receipt_use_executor_contract_does_not_call_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_dispatch_receipt_use_executor_contract_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_dispatch_receipt_use_executor_contract_does_not_dispatch_work',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_dispatch_receipt_use_executor_contract_does_not_enable_self_programming',
            ],
            'human_summary' => 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start dispatch receipt-use executor contract is ready; it marks a signed receipt used after executor handoff without starting providers.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartImplementationBoundaryGatePreflight(array $options = []): array
    {
        $contractPayload = $this->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartImplementationBoundaryGateContract($options);
        $contract = (array) data_get($contractPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_implementation_boundary_gate_contract', []);
        $contractHash = (string) data_get($contractPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_implementation_boundary_gate_contract_hash');
        $gateReady = class_exists(AgentCodexRealInvokerPostStartImplementationBoundaryGate::class)
            && method_exists(AgentCodexRealInvokerPostStartImplementationBoundaryGate::class, 'preparePostStartImplementationBoundary');
        $invokerReady = class_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartImplementationBoundaryGateInvoker::class)
            && method_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartImplementationBoundaryGateInvoker::class, 'prepareCodexRealInvokerPostStartImplementationBoundaryGate');
        $boundaryReady = class_exists(AgentCodexRealInvokerImplementationBoundary::class)
            && method_exists(AgentCodexRealInvokerImplementationBoundary::class, 'prepareBoundary');
        $signedReleaseReady = class_exists(AgentCodexRealInvokerPostStartSignedRealInvokerReleaseGate::class)
            && method_exists(AgentCodexRealInvokerPostStartSignedRealInvokerReleaseGate::class, 'authorizePostStartSignedRealInvokerRelease');
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerTableReady = Schema::hasTable('atlas_ledger_events');

        $checks = [
            'post_start_implementation_boundary_gate_contract_ready' => data_get($contractPayload, 'status') === 'one_shot_tick_codex_real_invoker_post_start_implementation_boundary_gate_contract_ready',
            'post_start_implementation_boundary_gate_contract_hash_present' => $contractHash !== '',
            'post_start_signed_real_invoker_release_gate_status_ready' => data_get($contract, 'source_codex_real_invoker_post_start_signed_real_invoker_release_gate_status') === 'one_shot_tick_codex_real_invoker_post_start_signed_real_invoker_release_gate_service_ready',
            'generic_post_start_implementation_boundary_gate_template_ready' => data_get($contract, 'source_codex_real_invoker_post_start_implementation_boundary_gate_status') === 'codex_real_invoker_post_start_implementation_boundary_gate_contract_template_ready',
            'codex_real_invoker_post_start_implementation_boundary_gate_ready' => $gateReady,
            'codex_real_invoker_post_start_implementation_boundary_gate_invoker_ready' => $invokerReady,
            'codex_real_invoker_implementation_boundary_ready' => $boundaryReady,
            'codex_real_invoker_post_start_signed_real_invoker_release_gate_ready' => $signedReleaseReady,
            'canonical_post_start_implementation_boundary_gate_method_ready' => data_get($contract, 'implementation_boundary.canonical_post_start_implementation_boundary_gate_method') === 'preparePostStartImplementationBoundary',
            'scheduler_invoker_method_ready' => data_get($contract, 'implementation_boundary.scheduler_invoker_method') === 'prepareCodexRealInvokerPostStartImplementationBoundaryGate',
            'contract_requires_post_start_signed_release' => data_get($contract, 'implementation_boundary.post_start_signed_real_invoker_release_required_before_boundary') === true,
            'contract_requires_post_start_evidence_acceptance_bridge' => data_get($contract, 'implementation_boundary.post_start_evidence_acceptance_bridge_required_before_boundary') === true,
            'contract_delegates_to_codex_real_invoker_implementation_boundary' => data_get($contract, 'implementation_boundary.gate_delegates_to_codex_real_invoker_implementation_boundary') === true,
            'contract_declares_boundary_is_not_real_invoker_execution' => data_get($contract, 'implementation_boundary.implementation_boundary_is_not_real_invoker_execution') === true,
            'contract_requires_executor_plan_after_boundary' => data_get($contract, 'implementation_boundary.executor_plan_required_after_boundary') === true,
            'contract_keeps_actual_process_start_disabled' => data_get($contract, 'implementation_boundary.actual_process_start_allowed_by_contract') === false,
            'contract_keeps_adapter_execution_disabled' => data_get($contract, 'implementation_boundary.adapter_execution_allowed_by_contract') === false,
            'contract_does_not_dispatch_work' => data_get($contract, 'implementation_boundary.dispatch_allowed_by_contract') === false,
            'contract_does_not_spend_tokens' => data_get($contract, 'implementation_boundary.token_spend_allowed_by_contract') === false,
            'agent_runs_table_ready' => $runsTableReady,
            'ledger_table_ready' => $ledgerTableReady,
            'self_programming_forbidden' => in_array('enable_self_programming', (array) data_get($contract, 'forbidden_even_after_contract', []), true),
        ];
        $blockingReasons = array_values(array_keys(array_filter($checks, static fn (bool $passed): bool => ! $passed)));

        $preflight = [
            'status' => $blockingReasons === [] ? 'one_shot_tick_codex_real_invoker_post_start_implementation_boundary_gate_preflight_ready' : 'blocked',
            'preflight_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-REAL-INVOKER-POST-START-IMPLEMENTATION-BOUNDARY-GATE-PREFLIGHT-SELF-CONSTRUCTION-0001',
            'source_post_start_implementation_boundary_gate_contract_hash' => $contractHash,
            'preflight_checks' => $checks,
            'blocking_count' => count($blockingReasons),
            'blocking_reasons' => $blockingReasons,
            'implementation_requirements' => [
                'create_scheduler_specific_post_start_implementation_boundary_gate_invoker',
                'delegate_to_agent_codex_real_invoker_post_start_implementation_boundary_gate',
                'require_post_start_signed_real_invoker_release_metadata',
                'require_operator_implementation_boundary_receipt_hash',
                'preserve_executor_plan_after_boundary',
                'project_readiness_status_without_invoking_codex',
            ],
            'runtime_policy' => [
                'preflight_is_read_only' => true,
                'post_start_implementation_boundary_gate_call_allowed_by_future_invoker' => true,
                'executor_plan_required_after_boundary' => true,
                'actual_process_start_allowed_here' => false,
                'provider_process_call_allowed_here' => false,
                'adapter_invocation_allowed_here' => false,
                'adapter_execution_allowed_here' => false,
                'dispatch_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'self_programming_allowed_here' => false,
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_implementation_boundary_gate_implementation_packet',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_implementation_boundary_gate_preflight.v1',
            'status' => (string) $preflight['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_implementation_boundary_gate_preflight',
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
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_implementation_boundary_gate_preflight' => $preflight,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_implementation_boundary_gate_preflight_hash' => $this->stableHash($preflight),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_implementation_boundary_gate_preflight_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_implementation_boundary_gate_preflight_does_not_call_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_implementation_boundary_gate_preflight_does_not_execute_adapter',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_implementation_boundary_gate_preflight_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_implementation_boundary_gate_preflight_does_not_dispatch_work',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_implementation_boundary_gate_preflight_does_not_enable_self_programming',
            ],
            'human_summary' => $blockingReasons === []
                ? 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start implementation boundary gate preflight is ready; implementation remains scoped to boundary preparation only.'
                : 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start implementation boundary gate preflight is blocked until signed release, implementation boundary and storage prerequisites are ready.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickMutatingWriterStatus(array $options = []): array
    {
        $wakeupTable = 'atlas_self_construction_agent_wakeup_items';
        $dispatchReceiptTable = 'atlas_self_construction_agent_dispatch_receipts';
        $ledgerTable = 'atlas_ledger_events';
        $wakeupTableReady = Schema::hasTable($wakeupTable);
        $dispatchReceiptTableReady = Schema::hasTable($dispatchReceiptTable);
        $ledgerTableReady = Schema::hasTable($ledgerTable);
        $writerReady = class_exists(AgentAutomaticDispatchSchedulerOneShotTickMutatingWriter::class);
        $receiptHash = strtolower(trim((string) ($options['receipt_hash'] ?? '')));
        $receiptHashValid = $receiptHash === '' || preg_match('/^[a-f0-9]{64}$/', $receiptHash) === 1;

        $dispatchQuery = $dispatchReceiptTableReady
            ? AtlasSelfConstructionAgentDispatchReceipt::query()
                ->where('decision', 'approve_dispatch_once')
                ->where('status', 'signed_pending_dispatch')
            : null;

        if ($dispatchQuery !== null && $receiptHash !== '' && $receiptHashValid) {
            $dispatchQuery->where('receipt_hash', $receiptHash);
        }

        $latestDispatchReceipt = $dispatchQuery === null
            ? null
            : (clone $dispatchQuery)->latest('created_at')->first();

        $status = [
            'status' => $wakeupTableReady && $dispatchReceiptTableReady && $ledgerTableReady && $writerReady && $receiptHashValid
                ? 'one_shot_tick_mutating_writer_service_ready'
                : 'blocked',
            'writer_service' => AgentAutomaticDispatchSchedulerOneShotTickMutatingWriter::class,
            'writer_service_ready' => $writerReady,
            'wakeup_items_table' => $wakeupTable,
            'wakeup_items_table_ready' => $wakeupTableReady,
            'dispatch_receipts_table' => $dispatchReceiptTable,
            'dispatch_receipts_table_ready' => $dispatchReceiptTableReady,
            'ledger_table_ready' => $ledgerTableReady,
            'receipt_hash_filter' => $receiptHashValid && $receiptHash !== '' ? $receiptHash : null,
            'receipt_hash_filter_valid' => $receiptHashValid,
            'claimed_wakeup_count' => $wakeupTableReady
                ? AtlasSelfConstructionAgentWakeupItem::query()->where('status', 'claimed')->whereNotNull('claimed_at')->count()
                : null,
            'signed_pending_dispatch_receipt_count' => $dispatchQuery === null ? null : (clone $dispatchQuery)->count(),
            'latest_signed_pending_dispatch_receipt' => $latestDispatchReceipt instanceof AtlasSelfConstructionAgentDispatchReceipt
                ? [
                    'receipt_id' => $latestDispatchReceipt->id,
                    'receipt_key' => $latestDispatchReceipt->receipt_key,
                    'receipt_hash' => $latestDispatchReceipt->receipt_hash,
                    'wakeup_item_id' => $latestDispatchReceipt->wakeup_item_id,
                    'packet_id' => $latestDispatchReceipt->packet_id,
                    'provider' => $latestDispatchReceipt->provider,
                    'signed_by' => $latestDispatchReceipt->signed_by,
                    'signed_at' => $latestDispatchReceipt->signed_at?->toIso8601String(),
                    'expires_at' => $latestDispatchReceipt->expires_at?->toIso8601String(),
                    'created_at' => $latestDispatchReceipt->created_at?->toIso8601String(),
                ]
                : null,
            'runtime_policy' => [
                'status_projection_is_read_only' => true,
                'writer_service_may_claim_one_wakeup_after_signed_release' => true,
                'writer_service_may_write_one_signed_pending_dispatch_receipt_after_signed_release' => true,
                'dispatch_receipt_use_allowed_here' => false,
                'provider_start_allowed_here' => false,
                'adapter_invocation_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'self_programming_allowed_here' => false,
            ],
            'next_required_slice' => $wakeupTableReady && $dispatchReceiptTableReady && $ledgerTableReady && $writerReady && $receiptHashValid
                ? 'activate_signed_one_shot_scheduler_tick_guarded_runtime_invocation_contract'
                : 'repair_one_shot_scheduler_tick_mutating_writer_service_blockers',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_mutating_writer_status.v1',
            'status' => (string) $status['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_mutating_writer_status',
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
            'agent_automatic_dispatch_scheduler_one_shot_tick_mutating_writer_status' => $status,
            'agent_automatic_dispatch_scheduler_one_shot_tick_mutating_writer_status_hash' => $this->stableHash($status),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_mutating_writer_status_does_not_claim_wakeup_items',
                'agent_automatic_dispatch_scheduler_one_shot_tick_mutating_writer_status_does_not_write_dispatch_receipts',
                'agent_automatic_dispatch_scheduler_one_shot_tick_mutating_writer_status_does_not_use_dispatch_receipts',
                'agent_automatic_dispatch_scheduler_one_shot_tick_mutating_writer_status_does_not_start_providers',
                'agent_automatic_dispatch_scheduler_one_shot_tick_mutating_writer_status_does_not_enable_self_programming',
            ],
            'human_summary' => $wakeupTableReady && $dispatchReceiptTableReady && $ledgerTableReady && $writerReady && $receiptHashValid
                ? 'Automatic dispatch scheduler one-shot tick mutating writer service is ready and inspectable; status remains read-only and provider start is still forbidden.'
                : 'Automatic dispatch scheduler one-shot tick mutating writer service is blocked until storage, ledger, writer and receipt filter prerequisites are ready.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartAdapterExecutionGuardGatePreflight(array $options = []): array
    {
        $contractPayload = $this->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartAdapterExecutionGuardGateContract($options);
        $contract = (array) data_get($contractPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_adapter_execution_guard_gate_contract', []);
        $contractHash = (string) data_get($contractPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_adapter_execution_guard_gate_contract_hash');
        $gateReady = class_exists(AgentCodexRealInvokerPostStartAdapterExecutionGuardGate::class)
            && method_exists(AgentCodexRealInvokerPostStartAdapterExecutionGuardGate::class, 'blockPostStartAdapterExecution');
        $invokerReady = class_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartAdapterExecutionGuardGateInvoker::class)
            && method_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartAdapterExecutionGuardGateInvoker::class, 'blockCodexRealInvokerPostStartAdapterExecutionGuardGate');
        $guardReady = class_exists(AgentProviderAdapterExecutionGuard::class)
            && method_exists(AgentProviderAdapterExecutionGuard::class, 'blockUntilProviderSpecificContract');
        $registryReady = class_exists(AgentProviderAdapterRegistry::class)
            && method_exists(AgentProviderAdapterRegistry::class, 'resolve');
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerTableReady = Schema::hasTable('atlas_ledger_events');

        $checks = [
            'post_start_adapter_execution_guard_gate_contract_ready' => data_get($contractPayload, 'status') === 'one_shot_tick_codex_real_invoker_post_start_adapter_execution_guard_gate_contract_ready',
            'post_start_adapter_execution_guard_gate_contract_hash_present' => $contractHash !== '',
            'post_start_adapter_invocation_boundary_gate_status_ready' => data_get($contract, 'source_codex_real_invoker_post_start_adapter_invocation_boundary_gate_status') === 'one_shot_tick_codex_real_invoker_post_start_adapter_invocation_boundary_gate_service_ready',
            'generic_post_start_adapter_execution_guard_gate_template_ready' => data_get($contract, 'source_codex_real_invoker_post_start_adapter_execution_guard_gate_status') === 'codex_real_invoker_post_start_adapter_execution_guard_gate_contract_template_ready',
            'codex_real_invoker_post_start_adapter_execution_guard_gate_ready' => $gateReady,
            'codex_real_invoker_post_start_adapter_execution_guard_gate_invoker_ready' => $invokerReady,
            'provider_adapter_execution_guard_ready' => $guardReady,
            'provider_adapter_registry_ready' => $registryReady,
            'canonical_post_start_adapter_execution_guard_gate_method_ready' => data_get($contract, 'adapter_execution_guard.canonical_post_start_adapter_execution_guard_gate_method') === 'blockPostStartAdapterExecution',
            'scheduler_invoker_method_ready' => data_get($contract, 'adapter_execution_guard.scheduler_invoker_method') === 'blockCodexRealInvokerPostStartAdapterExecutionGuardGate',
            'contract_requires_adapter_invocation_boundary' => data_get($contract, 'adapter_execution_guard.post_start_adapter_invocation_boundary_required_before_guard') === true,
            'contract_requires_post_start_evidence_acceptance_bridge' => data_get($contract, 'adapter_execution_guard.post_start_evidence_acceptance_bridge_required_before_guard') === true,
            'contract_delegates_to_provider_adapter_execution_guard' => data_get($contract, 'adapter_execution_guard.guard_delegates_to_provider_adapter_execution_guard') === true,
            'contract_requires_provider_specific_execution_contract' => data_get($contract, 'adapter_execution_guard.provider_specific_execution_contract_required_before_any_later_execution') === true,
            'contract_keeps_actual_process_start_disabled' => data_get($contract, 'adapter_execution_guard.actual_process_start_allowed_by_contract') === false,
            'contract_keeps_adapter_execution_disabled' => data_get($contract, 'adapter_execution_guard.adapter_execution_allowed_by_contract') === false,
            'contract_does_not_dispatch_work' => data_get($contract, 'adapter_execution_guard.dispatch_allowed_by_contract') === false,
            'contract_does_not_spend_tokens' => data_get($contract, 'adapter_execution_guard.token_spend_allowed_by_contract') === false,
            'agent_runs_table_ready' => $runsTableReady,
            'ledger_table_ready' => $ledgerTableReady,
            'self_programming_forbidden' => in_array('enable_self_programming', (array) data_get($contract, 'forbidden_even_after_contract', []), true),
        ];
        $blockingReasons = array_values(array_keys(array_filter($checks, static fn (bool $passed): bool => ! $passed)));

        $preflight = [
            'status' => $blockingReasons === [] ? 'one_shot_tick_codex_real_invoker_post_start_adapter_execution_guard_gate_preflight_ready' : 'blocked',
            'preflight_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-REAL-INVOKER-POST-START-ADAPTER-EXECUTION-GUARD-GATE-PREFLIGHT-SELF-CONSTRUCTION-0001',
            'source_post_start_adapter_execution_guard_gate_contract_hash' => $contractHash,
            'preflight_checks' => $checks,
            'blocking_count' => count($blockingReasons),
            'blocking_reasons' => $blockingReasons,
            'implementation_requirements' => [
                'use_one_shot_scheduler_codex_real_invoker_post_start_adapter_execution_guard_gate_invoker',
                'delegate_to_agent_codex_real_invoker_post_start_adapter_execution_guard_gate',
                'require_codex_real_invoker_post_start_adapter_invocation_boundary_metadata',
                'require_provider_start_run_with_adapter_invocation_metadata',
                'record_provider_adapter_execution_guard_block_without_calling_codex',
                'preserve_adapter_execution_disabled_until_codex_provider_execution_contract_gate',
            ],
            'runtime_policy' => [
                'preflight_is_read_only' => true,
                'post_start_adapter_execution_guard_gate_call_allowed_here' => false,
                'adapter_execution_guard_allowed_by_future_invoker' => true,
                'provider_external_process_start_allowed_here' => false,
                'provider_process_call_allowed_here' => false,
                'adapter_invocation_allowed_here' => false,
                'adapter_execution_allowed_here' => false,
                'dispatch_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'self_programming_allowed_here' => false,
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_adapter_execution_guard_gate_implementation_packet',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_adapter_execution_guard_gate_preflight.v1',
            'status' => (string) $preflight['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_adapter_execution_guard_gate_preflight',
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
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_adapter_execution_guard_gate_preflight' => $preflight,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_adapter_execution_guard_gate_preflight_hash' => $this->stableHash($preflight),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_adapter_execution_guard_gate_preflight_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_adapter_execution_guard_gate_preflight_does_not_call_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_adapter_execution_guard_gate_preflight_does_not_execute_adapter',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_adapter_execution_guard_gate_preflight_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_adapter_execution_guard_gate_preflight_does_not_dispatch_work',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_adapter_execution_guard_gate_preflight_does_not_enable_self_programming',
            ],
            'human_summary' => $blockingReasons === []
                ? 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start adapter execution guard gate preflight is ready.'
                : 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start adapter execution guard gate preflight is blocked until boundary, guard, registry and storage prerequisites are ready.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartStartExecutionGateImplementationPacket(array $options = []): array
    {
        $preflightPayload = $this->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartStartExecutionGatePreflight($options);
        $preflight = (array) data_get($preflightPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_start_execution_gate_preflight', []);
        $preflightHash = (string) data_get($preflightPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_start_execution_gate_preflight_hash');

        $packet = [
            'status' => 'ready_for_scoped_one_shot_tick_codex_real_invoker_post_start_start_execution_gate_invoker_implementation',
            'packet_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-REAL-INVOKER-POST-START-START-EXECUTION-GATE-IMPLEMENTATION-PACKET-SELF-CONSTRUCTION-0001',
            'parent_program' => 'Atlas Self-Construction OS',
            'submodule' => 'Atlas Agent Control Plane',
            'provider' => 'codex',
            'adapter' => 'codex',
            'source_codex_real_invoker_post_start_start_execution_gate_preflight_status' => data_get($preflight, 'status'),
            'source_codex_real_invoker_post_start_start_execution_gate_preflight_hash' => $preflightHash,
            'allowed_files' => [
                'app/Services/Ai/SelfConstruction/ControlPlane/AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartStartExecutionGateInvoker.php',
                'tests/Feature/Ai/AtlasAiSelfConstructionAgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartStartExecutionGateInvokerTest.php',
                'app/Services/Ai/SelfConstruction/Readiness/AtlasSelfConstructionReadinessService.php',
                'app/Console/Commands/AtlasAiSelfConstructionCommand.php',
                'tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php',
                'docs/engineering-knowledge-base/self-construction/agent-control-plane-contract.md',
            ],
            'tasks' => [
                ['id' => 'T1', 'title' => 'Expose one-shot scheduler Codex real invoker post-start start execution invoker', 'type' => 'service', 'acceptance' => 'Invoker validates post-start start execution input and delegates to AgentCodexRealInvokerPostStartStartExecutionGate.'],
                ['id' => 'T2', 'title' => 'Reject runtime-enabling input flags before delegation', 'type' => 'service_logic', 'acceptance' => 'Invoker rejects caller inputs that try to set actual_process_start_allowed, provider_process_call_allowed, adapter_invocation_allowed, adapter_execution_allowed, token_spend_allowed, dispatch_allowed, self_programming_allowed, external_process_started, provider_started or process_started to true.'],
                ['id' => 'T3', 'title' => 'Add Codex real invoker post-start start execution tests', 'type' => 'test', 'acceptance' => 'Tests cover successful authorization, idempotent retry, invalid hashes (operator_execution_gate_receipt_hash, execution_gate_policy_hash, execution_window_hash), missing envelope/evidence bridges, forbidden runtime flags including process_started, missing provider run, ledger rollback and result flags staying false.'],
                ['id' => 'T4', 'title' => 'Expose Codex real invoker post-start start execution status', 'type' => 'command_surface', 'acceptance' => 'Command/readiness reports invoker readiness and next process starter readiness gate without calling the invoker in status mode.'],
            ],
            'task_count' => 4,
            'acceptance_criteria' => [
                'codex_real_invoker_post_start_start_execution_gate_invoker_records_authorization_without_starting_codex',
                'codex_real_invoker_post_start_start_execution_gate_invoker_requires_process_start_envelope_bridge_metadata',
                'codex_real_invoker_post_start_start_execution_gate_invoker_requires_post_start_evidence_acceptance_bridge_metadata',
                'codex_real_invoker_post_start_start_execution_gate_invoker_requires_operator_execution_gate_receipt_hash',
                'codex_real_invoker_post_start_start_execution_gate_invoker_requires_execution_gate_policy_hash',
                'codex_real_invoker_post_start_start_execution_gate_invoker_requires_execution_window_hash',
                'codex_real_invoker_post_start_start_execution_gate_invoker_rejects_runtime_enabling_input_flags',
                'codex_real_invoker_post_start_start_execution_gate_invoker_rejects_process_started_input_flag',
                'codex_real_invoker_post_start_start_execution_gate_invoker_never_dispatches_or_spends_tokens',
            ],
            'required_gates' => [
                'php_lint_codex_real_invoker_post_start_start_execution_gate_invoker',
                'dedicated_codex_real_invoker_post_start_start_execution_gate_invoker_feature_tests',
                'generic_codex_real_invoker_post_start_start_execution_gate_feature_tests',
                'focused_self_construction_command_tests',
                'architecture_validate',
                'docs_health',
                'git_diff_check',
            ],
            'implementation_policy' => [
                'implementation_allowed_by_packet' => true,
                'post_start_start_execution_gate_call_allowed_by_future_invoker' => true,
                'codex_real_invoker_start_execution_gate_allowed_by_future_invoker' => true,
                'post_start_start_execution_gate_authorized_after_future_invoker' => true,
                'start_execution_authorized_after_future_invoker' => true,
                'process_starter_readiness_required_after_future_invoker' => true,
                'actual_process_start_allowed_by_packet' => false,
                'process_started_by_packet' => false,
                'provider_process_call_allowed_by_packet' => false,
                'adapter_invocation_allowed_by_packet' => false,
                'adapter_execution_allowed_by_packet' => false,
                'dispatch_allowed_by_packet' => false,
                'token_spend_allowed_by_packet' => false,
                'self_programming_allowed_by_packet' => false,
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_start_execution_gate_invoker_service',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_start_execution_gate_implementation_packet.v1',
            'status' => (string) $packet['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_start_execution_gate_implementation_packet',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'post_start_start_execution_gate_allowed' => false,
            'actual_process_start_allowed' => false,
            'provider_process_call_allowed' => false,
            'executor_enabled' => false,
            'process_start_armed' => false,
            'process_started' => false,
            'adapter_invocation_allowed' => false,
            'adapter_execution_allowed' => false,
            'token_spend_allowed' => false,
            'self_programming_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_start_execution_gate_implementation_packet' => $packet,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_start_execution_gate_implementation_packet_hash' => $this->stableHash($packet),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_start_execution_gate_implementation_packet_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_start_execution_gate_implementation_packet_does_not_call_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_start_execution_gate_implementation_packet_does_not_execute_adapter',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_start_execution_gate_implementation_packet_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_start_execution_gate_implementation_packet_does_not_dispatch_work',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_start_execution_gate_implementation_packet_does_not_enable_self_programming',
            ],
            'human_summary' => 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start start execution gate implementation packet is ready; the start execution authorization precedes the process starter readiness gate.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickGuardedRuntimeInvocationStatus(array $options = []): array
    {
        $dispatchReceiptTable = 'atlas_self_construction_agent_dispatch_receipts';
        $dispatchReceiptTableReady = Schema::hasTable($dispatchReceiptTable);
        $writerReady = class_exists(AgentAutomaticDispatchSchedulerOneShotTickMutatingWriter::class)
            && method_exists(AgentAutomaticDispatchSchedulerOneShotTickMutatingWriter::class, 'executeOneShotSchedulerTickAfterReleasePreflight');
        $invokerReady = class_exists(AgentAutomaticDispatchSchedulerOneShotTickGuardedRuntimeInvoker::class)
            && method_exists(AgentAutomaticDispatchSchedulerOneShotTickGuardedRuntimeInvoker::class, 'invokeSignedOneShotSchedulerTick');
        $receiptHash = strtolower(trim((string) ($options['receipt_hash'] ?? '')));
        $receiptHashValid = $receiptHash === '' || preg_match('/^[a-f0-9]{64}$/', $receiptHash) === 1;

        $dispatchQuery = $dispatchReceiptTableReady
            ? AtlasSelfConstructionAgentDispatchReceipt::query()
                ->where('decision', 'approve_dispatch_once')
                ->where('status', 'signed_pending_dispatch')
            : null;

        if ($dispatchQuery !== null && $receiptHash !== '' && $receiptHashValid) {
            $dispatchQuery->where('receipt_hash', $receiptHash);
        }

        $latestDispatchReceipt = $dispatchQuery === null
            ? null
            : (clone $dispatchQuery)->latest('created_at')->first();

        $statusReady = $dispatchReceiptTableReady && $writerReady && $invokerReady && $receiptHashValid;
        $status = [
            'status' => $statusReady ? 'one_shot_tick_guarded_runtime_invocation_service_ready' : 'blocked',
            'invoker_service' => AgentAutomaticDispatchSchedulerOneShotTickGuardedRuntimeInvoker::class,
            'invoker_service_ready' => $invokerReady,
            'invoker_canonical_method' => 'invokeSignedOneShotSchedulerTick',
            'writer_service_ready' => $writerReady,
            'writer_canonical_method' => 'executeOneShotSchedulerTickAfterReleasePreflight',
            'dispatch_receipts_table' => $dispatchReceiptTable,
            'dispatch_receipts_table_ready' => $dispatchReceiptTableReady,
            'receipt_hash_filter' => $receiptHashValid && $receiptHash !== '' ? $receiptHash : null,
            'receipt_hash_filter_valid' => $receiptHashValid,
            'signed_pending_dispatch_receipt_count' => $dispatchQuery === null ? null : (clone $dispatchQuery)->count(),
            'latest_signed_pending_dispatch_receipt' => $latestDispatchReceipt instanceof AtlasSelfConstructionAgentDispatchReceipt
                ? [
                    'receipt_id' => $latestDispatchReceipt->id,
                    'receipt_key' => $latestDispatchReceipt->receipt_key,
                    'receipt_hash' => $latestDispatchReceipt->receipt_hash,
                    'wakeup_item_id' => $latestDispatchReceipt->wakeup_item_id,
                    'packet_id' => $latestDispatchReceipt->packet_id,
                    'provider' => $latestDispatchReceipt->provider,
                    'signed_by' => $latestDispatchReceipt->signed_by,
                    'signed_at' => $latestDispatchReceipt->signed_at?->toIso8601String(),
                    'expires_at' => $latestDispatchReceipt->expires_at?->toIso8601String(),
                    'created_at' => $latestDispatchReceipt->created_at?->toIso8601String(),
                    'used_at' => $latestDispatchReceipt->used_at?->toIso8601String(),
                ]
                : null,
            'runtime_policy' => [
                'status_projection_is_read_only' => true,
                'invoker_may_call_mutating_writer_once_when_invoked_with_signed_input' => true,
                'writer_invocation_limit_per_request' => 1,
                'dispatch_receipt_use_allowed_here' => false,
                'mark_dispatch_receipt_used_allowed_here' => false,
                'provider_start_allowed_here' => false,
                'adapter_invocation_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'self_programming_allowed_here' => false,
            ],
            'next_required_slice' => $statusReady
                ? 'activate_signed_one_shot_scheduler_tick_dispatch_receipt_use_release_contract'
                : 'repair_one_shot_scheduler_tick_guarded_runtime_invocation_service_blockers',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_guarded_runtime_invocation_status.v1',
            'status' => (string) $status['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_guarded_runtime_invocation_status',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'claim_allowed' => false,
            'dispatch_receipt_write_allowed' => false,
            'dispatch_receipt_use_allowed' => false,
            'mark_dispatch_receipt_used_allowed' => false,
            'provider_start_allowed' => false,
            'adapter_invocation_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_guarded_runtime_invocation_status' => $status,
            'agent_automatic_dispatch_scheduler_one_shot_tick_guarded_runtime_invocation_status_hash' => $this->stableHash($status),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_guarded_runtime_invocation_status_does_not_call_mutating_writer',
                'agent_automatic_dispatch_scheduler_one_shot_tick_guarded_runtime_invocation_status_does_not_claim_wakeup_items',
                'agent_automatic_dispatch_scheduler_one_shot_tick_guarded_runtime_invocation_status_does_not_write_dispatch_receipts',
                'agent_automatic_dispatch_scheduler_one_shot_tick_guarded_runtime_invocation_status_does_not_use_dispatch_receipts',
                'agent_automatic_dispatch_scheduler_one_shot_tick_guarded_runtime_invocation_status_does_not_start_providers',
                'agent_automatic_dispatch_scheduler_one_shot_tick_guarded_runtime_invocation_status_does_not_enable_self_programming',
            ],
            'human_summary' => $statusReady
                ? 'Automatic dispatch scheduler one-shot tick guarded runtime invocation service is ready and inspectable; status remains read-only and provider start is still forbidden.'
                : 'Automatic dispatch scheduler one-shot tick guarded runtime invocation service is blocked until the invoker, writer, storage and receipt filter prerequisites are ready.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexProcessInvocationAuthorizationContract(array $options = []): array
    {
        $runtimeStatusPayload = $this->agentAutomaticDispatchSchedulerOneShotTickCodexExternalProcessRuntimeDriverStatus($options);
        $runtimeStatus = (array) data_get($runtimeStatusPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_external_process_runtime_driver_status', []);
        $authorizationPayload = $this->agentCodexExternalProcessInvocationAuthorizationContractTemplate($options);

        $contract = [
            'status' => 'one_shot_tick_codex_process_invocation_authorization_contract_ready',
            'contract_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-PROCESS-INVOCATION-AUTHORIZATION-CONTRACT-SELF-CONSTRUCTION-0001',
            'parent_program' => 'Atlas Self-Construction OS',
            'submodule' => 'Atlas Agent Control Plane',
            'provider' => 'codex',
            'adapter' => 'codex',
            'source_codex_external_process_runtime_driver_status' => data_get($runtimeStatus, 'status'),
            'source_codex_external_process_runtime_driver_status_hash' => data_get($runtimeStatusPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_external_process_runtime_driver_status_hash'),
            'source_codex_external_process_invocation_authorization_contract_status' => data_get($authorizationPayload, 'status'),
            'source_codex_external_process_invocation_authorization_contract_hash' => data_get($authorizationPayload, 'codex_external_process_invocation_authorization_contract_template_hash'),
            'release_boundary' => [
                'canonical_authorization_gate' => AgentCodexExternalProcessInvocationAuthorizationGate::class,
                'canonical_authorization_gate_method' => 'authorizeExternalProcessInvocation',
                'scheduler_invoker' => AgentAutomaticDispatchSchedulerOneShotTickCodexProcessInvocationAuthorizationInvoker::class,
                'scheduler_invoker_method' => 'authorizeCodexProcessInvocation',
                'authorization_effect' => 'authorize_codex_process_invocation_without_invoking_external_process',
                'external_process_started_by_authorization' => false,
                'provider_started_by_authorization' => false,
                'adapter_execution_allowed_by_authorization' => false,
                'token_spend_allowed_by_authorization' => false,
                'required_runtime_driver_status_before_authorization' => 'prepared_pending_process_invocation',
                'prepared_status_after_authorization' => 'authorized_pending_external_process_invoker',
                'idempotency_key' => 'invocation_authorization_id',
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
                'operator_invocation_receipt_hash',
                'runtime_driver_contract_hash',
                'process_command_hash',
                'environment_contract_hash',
                'termination_policy_hash',
                'actor',
                'session',
                'reason',
            ],
            'allowed_future_mutations' => [
                'write_codex_external_process_invocation_authorization_metadata_on_agent_run',
                'append_codex_external_process_invocation_authorization_evidence_event',
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
                'process_invocation_authorization_is_permission_not_invocation' => true,
                'external_process_invoker_dry_run_requires_separate_signed_release' => true,
                'operator_invocation_receipt_hash_required' => true,
                'runtime_driver_contract_hash_required' => true,
                'process_command_hash_required' => true,
                'environment_contract_hash_required' => true,
                'termination_policy_hash_required' => true,
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_process_invocation_authorization_preflight',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_process_invocation_authorization_contract.v1',
            'status' => (string) $contract['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_process_invocation_authorization_contract',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_process_invocation_authorization_allowed' => false,
            'adapter_execution_allowed' => false,
            'provider_start_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_process_invocation_authorization_contract' => $contract,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_process_invocation_authorization_contract_hash' => $this->stableHash($contract),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_process_invocation_authorization_contract_does_not_call_authorization_gate',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_process_invocation_authorization_contract_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_process_invocation_authorization_contract_does_not_call_adapters',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_process_invocation_authorization_contract_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_process_invocation_authorization_contract_does_not_enable_self_programming',
            ],
            'human_summary' => 'Automatic dispatch scheduler one-shot tick Codex process invocation authorization contract is ready; it authorizes the next invoker boundary but cannot invoke Codex.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartReceiptContract(array $options = []): array
    {
        $handoffStatusPayload = $this->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerOperatorStartHandoffStatus($options);
        $handoffStatus = (array) data_get($handoffStatusPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_operator_start_handoff_status', []);
        $receiptPayload = $this->agentCodexRealInvokerPostStartReceiptContractBuilderContractTemplate($options);

        $contract = [
            'status' => 'one_shot_tick_codex_real_invoker_post_start_receipt_contract_ready',
            'contract_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-REAL-INVOKER-POST-START-RECEIPT-CONTRACT-SELF-CONSTRUCTION-0001',
            'parent_program' => 'Atlas Self-Construction OS',
            'submodule' => 'Atlas Agent Control Plane',
            'provider' => 'codex',
            'adapter' => 'codex',
            'source_codex_real_invoker_operator_start_handoff_status' => data_get($handoffStatus, 'status'),
            'source_codex_real_invoker_operator_start_handoff_status_hash' => data_get($handoffStatusPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_operator_start_handoff_status_hash'),
            'source_codex_real_invoker_post_start_receipt_contract_builder_status' => data_get($receiptPayload, 'status'),
            'source_codex_real_invoker_post_start_receipt_contract_builder_hash' => data_get($receiptPayload, 'codex_real_invoker_post_start_receipt_contract_builder_contract_template_hash'),
            'release_boundary' => [
                'canonical_post_start_receipt_contract_builder' => AgentCodexRealInvokerPostStartReceiptContractBuilder::class,
                'canonical_post_start_receipt_contract_builder_method' => 'buildPostStartReceiptContract',
                'scheduler_invoker' => AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartReceiptContractInvoker::class,
                'scheduler_invoker_method' => 'buildCodexRealInvokerPostStartReceiptContract',
                'gate_effect' => 'record_post_start_receipt_contract_without_accepting_external_process_evidence',
                'operator_start_handoff_required_before_contract' => true,
                'post_start_evidence_acceptance_bridge_required' => true,
                'actual_process_start_allowed_by_contract' => false,
                'external_process_started_by_contract' => false,
                'external_process_evidence_accepted_by_contract' => false,
                'provider_started_by_contract' => false,
                'adapter_execution_allowed_by_contract' => false,
                'token_spend_allowed_by_contract' => false,
                'dispatch_allowed_by_contract' => false,
                'idempotency_key' => 'post_start_receipt_contract_id',
            ],
            'required_input_fields_for_future_invoker' => [
                'run_key',
                'codex_execution_id',
                'real_invoker_process_starter_readiness_gate_id',
                'real_invoker_start_execution_gate_id',
                'post_start_evidence_acceptance_bridge_id',
                'manual_start_executor_receipt_id',
                'operator_start_handoff_id',
                'post_start_receipt_contract_id',
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
                'actor',
                'session',
                'reason',
            ],
            'allowed_future_mutations' => [
                'write_codex_real_invoker_post_start_receipt_contract_metadata_on_agent_run',
                'append_codex_real_invoker_post_start_receipt_contract_evidence_event',
            ],
            'forbidden_even_after_contract' => [
                'spawn_codex_process',
                'call_codex_cli_or_codex_app',
                'spawn_shell_or_subprocess',
                'spend_provider_tokens',
                'mark_run_running_or_terminal',
                'accept_external_process_started_evidence',
                'dispatch_work_to_codex',
                'enable_self_programming',
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_receipt_contract_preflight',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_receipt_contract.v1',
            'status' => (string) $contract['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_receipt_contract',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_post_start_receipt_contract_allowed' => false,
            'external_process_evidence_acceptance_allowed' => false,
            'actual_process_start_allowed' => false,
            'adapter_execution_allowed' => false,
            'provider_start_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_receipt_contract' => $contract,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_receipt_contract_hash' => $this->stableHash($contract),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_receipt_contract_does_not_accept_external_process_evidence',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_receipt_contract_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_receipt_contract_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_receipt_contract_does_not_enable_self_programming',
            ],
            'human_summary' => 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start receipt contract is ready; it defines future evidence acceptance but still accepts no live process evidence.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartDispatchReleaseGateContract(array $options = []): array
    {
        $livenessStatusPayload = $this->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartLivenessMonitorStatus($options);
        $livenessStatus = (array) data_get($livenessStatusPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_liveness_monitor_status', []);
        $dispatchReleasePayload = $this->agentCodexRealInvokerPostStartDispatchReleaseGateContractTemplate($options);

        $contract = [
            'status' => 'one_shot_tick_codex_real_invoker_post_start_dispatch_release_gate_contract_ready',
            'contract_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-REAL-INVOKER-POST-START-DISPATCH-RELEASE-GATE-CONTRACT-SELF-CONSTRUCTION-0001',
            'parent_program' => 'Atlas Self-Construction OS',
            'submodule' => 'Atlas Agent Control Plane',
            'provider' => 'codex',
            'adapter' => 'codex',
            'source_codex_real_invoker_post_start_liveness_monitor_status' => data_get($livenessStatus, 'status'),
            'source_codex_real_invoker_post_start_liveness_monitor_status_hash' => data_get($livenessStatusPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_liveness_monitor_status_hash'),
            'source_codex_real_invoker_post_start_dispatch_release_gate_status' => data_get($dispatchReleasePayload, 'status'),
            'source_codex_real_invoker_post_start_dispatch_release_gate_hash' => data_get($dispatchReleasePayload, 'codex_real_invoker_post_start_dispatch_release_gate_contract_template_hash'),
            'release_boundary' => [
                'canonical_post_start_dispatch_release_gate' => AgentCodexRealInvokerPostStartDispatchReleaseGate::class,
                'canonical_post_start_dispatch_release_gate_method' => 'preparePostStartDispatchRelease',
                'scheduler_invoker' => AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartDispatchReleaseGateInvoker::class,
                'scheduler_invoker_method' => 'prepareCodexRealInvokerPostStartDispatchRelease',
                'gate_effect' => 'prepare_dispatch_release_candidate_after_alive_liveness_without_dispatching',
                'post_start_liveness_monitor_required_before_dispatch_release' => true,
                'post_start_evidence_acceptance_bridge_required_before_dispatch_release' => true,
                'required_liveness_state' => 'alive',
                'signed_dispatch_policy_hash_required' => true,
                'continuation_summary_hash_required' => true,
                'context_pack_hash_required' => true,
                'no_direct_provider_call_attestation_required' => true,
                'actual_process_start_allowed_by_contract' => false,
                'atlas_process_spawned_by_contract' => false,
                'provider_marked_started_by_contract' => true,
                'adapter_execution_allowed_by_contract' => false,
                'token_spend_allowed_by_contract' => false,
                'dispatch_allowed_by_contract' => false,
                'idempotency_key' => 'dispatch_release_gate_id',
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
                'dispatch_scope_hash',
                'continuation_summary_hash',
                'context_pack_hash',
                'signed_dispatch_policy_hash',
                'no_direct_provider_call_attestation_hash',
                'actor',
                'session',
                'reason',
            ],
            'allowed_future_mutations' => [
                'write_codex_real_invoker_post_start_dispatch_release_gate_metadata_on_agent_run',
                'append_codex_real_invoker_post_start_dispatch_release_gate_evidence_event',
                'mark_run_as_future_dispatch_release_candidate',
            ],
            'forbidden_even_after_contract' => [
                'spawn_codex_process',
                'call_codex_cli_or_codex_app',
                'dispatch_work_to_codex',
                'send_prompt_to_provider',
                'spend_provider_tokens',
                'mark_run_terminal',
                'enable_self_programming',
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_dispatch_release_gate_preflight',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_dispatch_release_gate_contract.v1',
            'status' => (string) $contract['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_dispatch_release_gate_contract',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'post_start_dispatch_release_gate_allowed' => false,
            'actual_process_start_allowed' => false,
            'adapter_execution_allowed' => false,
            'provider_start_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_dispatch_release_gate_contract' => $contract,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_dispatch_release_gate_contract_hash' => $this->stableHash($contract),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_dispatch_release_gate_contract_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_dispatch_release_gate_contract_does_not_call_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_dispatch_release_gate_contract_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_dispatch_release_gate_contract_does_not_dispatch_work',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_dispatch_release_gate_contract_does_not_enable_self_programming',
            ],
            'human_summary' => 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start dispatch release gate contract is ready; it can only prepare a future release candidate after alive liveness.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartRealInvokerReleasePreflightGatePreflight(array $options = []): array
    {
        $contractPayload = $this->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartRealInvokerReleasePreflightGateContract($options);
        $contract = (array) data_get($contractPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_real_invoker_release_preflight_gate_contract', []);
        $contractHash = (string) data_get($contractPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_real_invoker_release_preflight_gate_contract_hash');
        $gateReady = class_exists(AgentCodexRealInvokerPostStartRealInvokerReleasePreflightGate::class)
            && method_exists(AgentCodexRealInvokerPostStartRealInvokerReleasePreflightGate::class, 'recordPostStartRealInvokerReleasePreflight');
        $invokerReady = class_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartRealInvokerReleasePreflightGateInvoker::class)
            && method_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartRealInvokerReleasePreflightGateInvoker::class, 'recordCodexRealInvokerPostStartRealInvokerReleasePreflightGate');
        $releasePreflightReady = class_exists(AgentCodexRealInvokerReleasePreflight::class)
            && method_exists(AgentCodexRealInvokerReleasePreflight::class, 'recordPreflight');
        $dryRunGateReady = class_exists(AgentCodexRealInvokerPostStartExternalProcessInvokerDryRunGate::class)
            && method_exists(AgentCodexRealInvokerPostStartExternalProcessInvokerDryRunGate::class, 'preparePostStartExternalProcessInvokerDryRun');
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerTableReady = Schema::hasTable('atlas_ledger_events');

        $checks = [
            'post_start_real_invoker_release_preflight_gate_contract_ready' => data_get($contractPayload, 'status') === 'one_shot_tick_codex_real_invoker_post_start_real_invoker_release_preflight_gate_contract_ready',
            'post_start_real_invoker_release_preflight_gate_contract_hash_present' => $contractHash !== '',
            'post_start_external_process_invoker_dry_run_gate_status_ready' => data_get($contract, 'source_codex_real_invoker_post_start_external_process_invoker_dry_run_gate_status') === 'one_shot_tick_codex_real_invoker_post_start_external_process_invoker_dry_run_gate_service_ready',
            'generic_post_start_real_invoker_release_preflight_gate_template_ready' => data_get($contract, 'source_codex_real_invoker_post_start_real_invoker_release_preflight_gate_status') === 'codex_real_invoker_post_start_real_invoker_release_preflight_gate_contract_template_ready',
            'codex_real_invoker_post_start_real_invoker_release_preflight_gate_ready' => $gateReady,
            'codex_real_invoker_post_start_real_invoker_release_preflight_gate_invoker_ready' => $invokerReady,
            'codex_real_invoker_release_preflight_service_ready' => $releasePreflightReady,
            'codex_real_invoker_post_start_external_process_invoker_dry_run_gate_ready' => $dryRunGateReady,
            'canonical_post_start_real_invoker_release_preflight_gate_method_ready' => data_get($contract, 'real_invoker_release_preflight.canonical_post_start_real_invoker_release_preflight_gate_method') === 'recordPostStartRealInvokerReleasePreflight',
            'scheduler_invoker_method_ready' => data_get($contract, 'real_invoker_release_preflight.scheduler_invoker_method') === 'recordCodexRealInvokerPostStartRealInvokerReleasePreflightGate',
            'contract_requires_external_process_invoker_dry_run' => data_get($contract, 'real_invoker_release_preflight.post_start_external_process_invoker_dry_run_required_before_preflight') === true,
            'contract_requires_post_start_evidence_acceptance_bridge' => data_get($contract, 'real_invoker_release_preflight.post_start_evidence_acceptance_bridge_required_before_preflight') === true,
            'contract_delegates_to_codex_real_invoker_release_preflight' => data_get($contract, 'real_invoker_release_preflight.gate_delegates_to_codex_real_invoker_release_preflight') === true,
            'contract_declares_release_preflight_is_not_signed_release' => data_get($contract, 'real_invoker_release_preflight.real_invoker_release_preflight_is_not_signed_release') === true,
            'contract_requires_signed_release_gate_after_preflight' => data_get($contract, 'real_invoker_release_preflight.signed_release_gate_required_after_preflight') === true,
            'contract_keeps_actual_process_start_disabled' => data_get($contract, 'real_invoker_release_preflight.actual_process_start_allowed_by_contract') === false,
            'contract_keeps_adapter_execution_disabled' => data_get($contract, 'real_invoker_release_preflight.adapter_execution_allowed_by_contract') === false,
            'contract_does_not_dispatch_work' => data_get($contract, 'real_invoker_release_preflight.dispatch_allowed_by_contract') === false,
            'contract_does_not_spend_tokens' => data_get($contract, 'real_invoker_release_preflight.token_spend_allowed_by_contract') === false,
            'agent_runs_table_ready' => $runsTableReady,
            'ledger_table_ready' => $ledgerTableReady,
            'self_programming_forbidden' => in_array('enable_self_programming', (array) data_get($contract, 'forbidden_even_after_contract', []), true),
        ];
        $blockingReasons = array_values(array_keys(array_filter($checks, static fn (bool $passed): bool => ! $passed)));

        $preflight = [
            'status' => $blockingReasons === [] ? 'one_shot_tick_codex_real_invoker_post_start_real_invoker_release_preflight_gate_preflight_ready' : 'blocked',
            'preflight_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-REAL-INVOKER-POST-START-REAL-INVOKER-RELEASE-PREFLIGHT-GATE-PREFLIGHT-SELF-CONSTRUCTION-0001',
            'source_post_start_real_invoker_release_preflight_gate_contract_hash' => $contractHash,
            'preflight_checks' => $checks,
            'blocking_count' => count($blockingReasons),
            'blocking_reasons' => $blockingReasons,
            'implementation_requirements' => [
                'create_scheduler_specific_post_start_real_invoker_release_preflight_gate_invoker',
                'delegate_to_agent_codex_real_invoker_post_start_real_invoker_release_preflight_gate',
                'preserve_signed_release_gate_boundary',
                'project_readiness_status_without_invoking_codex',
            ],
            'runtime_policy' => [
                'preflight_is_read_only' => true,
                'post_start_real_invoker_release_preflight_gate_call_allowed_by_future_invoker' => true,
                'signed_real_invoker_release_required_after_preflight' => true,
                'actual_process_start_allowed_here' => false,
                'provider_process_call_allowed_here' => false,
                'adapter_invocation_allowed_here' => false,
                'adapter_execution_allowed_here' => false,
                'dispatch_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'self_programming_allowed_here' => false,
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_real_invoker_release_preflight_gate_implementation_packet',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_real_invoker_release_preflight_gate_preflight.v1',
            'status' => (string) $preflight['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_real_invoker_release_preflight_gate_preflight',
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
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_real_invoker_release_preflight_gate_preflight' => $preflight,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_real_invoker_release_preflight_gate_preflight_hash' => $this->stableHash($preflight),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_real_invoker_release_preflight_gate_preflight_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_real_invoker_release_preflight_gate_preflight_does_not_call_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_real_invoker_release_preflight_gate_preflight_does_not_execute_adapter',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_real_invoker_release_preflight_gate_preflight_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_real_invoker_release_preflight_gate_preflight_does_not_dispatch_work',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_real_invoker_release_preflight_gate_preflight_does_not_enable_self_programming',
            ],
            'human_summary' => $blockingReasons === []
                ? 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start release preflight gate preflight is ready; implementation remains scoped to release preflight only.'
                : 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start release preflight gate preflight is blocked until dry-run, release preflight and storage prerequisites are ready.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartSignedRealInvokerReleaseGatePreflight(array $options = []): array
    {
        $contractPayload = $this->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartSignedRealInvokerReleaseGateContract($options);
        $contract = (array) data_get($contractPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_signed_real_invoker_release_gate_contract', []);
        $contractHash = (string) data_get($contractPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_signed_real_invoker_release_gate_contract_hash');
        $gateReady = class_exists(AgentCodexRealInvokerPostStartSignedRealInvokerReleaseGate::class)
            && method_exists(AgentCodexRealInvokerPostStartSignedRealInvokerReleaseGate::class, 'authorizePostStartSignedRealInvokerRelease');
        $invokerReady = class_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartSignedRealInvokerReleaseGateInvoker::class)
            && method_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartSignedRealInvokerReleaseGateInvoker::class, 'authorizeCodexRealInvokerPostStartSignedRealInvokerReleaseGate');
        $signedReleaseReady = class_exists(AgentCodexSignedRealInvokerReleaseGate::class)
            && method_exists(AgentCodexSignedRealInvokerReleaseGate::class, 'authorizeSignedRelease');
        $releasePreflightGateReady = class_exists(AgentCodexRealInvokerPostStartRealInvokerReleasePreflightGate::class)
            && method_exists(AgentCodexRealInvokerPostStartRealInvokerReleasePreflightGate::class, 'recordPostStartRealInvokerReleasePreflight');
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerTableReady = Schema::hasTable('atlas_ledger_events');

        $checks = [
            'post_start_signed_real_invoker_release_gate_contract_ready' => data_get($contractPayload, 'status') === 'one_shot_tick_codex_real_invoker_post_start_signed_real_invoker_release_gate_contract_ready',
            'post_start_signed_real_invoker_release_gate_contract_hash_present' => $contractHash !== '',
            'post_start_real_invoker_release_preflight_gate_status_ready' => data_get($contract, 'source_codex_real_invoker_post_start_real_invoker_release_preflight_gate_status') === 'one_shot_tick_codex_real_invoker_post_start_real_invoker_release_preflight_gate_service_ready',
            'generic_post_start_signed_real_invoker_release_gate_template_ready' => data_get($contract, 'source_codex_real_invoker_post_start_signed_real_invoker_release_gate_status') === 'codex_real_invoker_post_start_signed_real_invoker_release_gate_contract_template_ready',
            'codex_real_invoker_post_start_signed_real_invoker_release_gate_ready' => $gateReady,
            'codex_real_invoker_post_start_signed_real_invoker_release_gate_invoker_ready' => $invokerReady,
            'codex_signed_real_invoker_release_gate_ready' => $signedReleaseReady,
            'codex_real_invoker_post_start_real_invoker_release_preflight_gate_ready' => $releasePreflightGateReady,
            'canonical_post_start_signed_real_invoker_release_gate_method_ready' => data_get($contract, 'signed_real_invoker_release.canonical_post_start_signed_real_invoker_release_gate_method') === 'authorizePostStartSignedRealInvokerRelease',
            'scheduler_invoker_method_ready' => data_get($contract, 'signed_real_invoker_release.scheduler_invoker_method') === 'authorizeCodexRealInvokerPostStartSignedRealInvokerReleaseGate',
            'contract_requires_real_invoker_release_preflight' => data_get($contract, 'signed_real_invoker_release.post_start_real_invoker_release_preflight_required_before_signed_release') === true,
            'contract_requires_post_start_evidence_acceptance_bridge' => data_get($contract, 'signed_real_invoker_release.post_start_evidence_acceptance_bridge_required_before_signed_release') === true,
            'contract_delegates_to_codex_signed_real_invoker_release_gate' => data_get($contract, 'signed_real_invoker_release.gate_delegates_to_codex_signed_real_invoker_release_gate') === true,
            'contract_declares_signed_release_is_not_real_invoker_execution' => data_get($contract, 'signed_real_invoker_release.signed_real_invoker_release_is_not_real_invoker_execution') === true,
            'contract_requires_implementation_boundary_after_signed_release' => data_get($contract, 'signed_real_invoker_release.implementation_boundary_required_after_signed_release') === true,
            'contract_keeps_actual_process_start_disabled' => data_get($contract, 'signed_real_invoker_release.actual_process_start_allowed_by_contract') === false,
            'contract_keeps_adapter_execution_disabled' => data_get($contract, 'signed_real_invoker_release.adapter_execution_allowed_by_contract') === false,
            'contract_does_not_dispatch_work' => data_get($contract, 'signed_real_invoker_release.dispatch_allowed_by_contract') === false,
            'contract_does_not_spend_tokens' => data_get($contract, 'signed_real_invoker_release.token_spend_allowed_by_contract') === false,
            'agent_runs_table_ready' => $runsTableReady,
            'ledger_table_ready' => $ledgerTableReady,
            'self_programming_forbidden' => in_array('enable_self_programming', (array) data_get($contract, 'forbidden_even_after_contract', []), true),
        ];
        $blockingReasons = array_values(array_keys(array_filter($checks, static fn (bool $passed): bool => ! $passed)));

        $preflight = [
            'status' => $blockingReasons === [] ? 'one_shot_tick_codex_real_invoker_post_start_signed_real_invoker_release_gate_preflight_ready' : 'blocked',
            'preflight_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-REAL-INVOKER-POST-START-SIGNED-REAL-INVOKER-RELEASE-GATE-PREFLIGHT-SELF-CONSTRUCTION-0001',
            'source_post_start_signed_real_invoker_release_gate_contract_hash' => $contractHash,
            'preflight_checks' => $checks,
            'blocking_count' => count($blockingReasons),
            'blocking_reasons' => $blockingReasons,
            'implementation_requirements' => [
                'create_scheduler_specific_post_start_signed_real_invoker_release_gate_invoker',
                'delegate_to_agent_codex_real_invoker_post_start_signed_real_invoker_release_gate',
                'preserve_implementation_boundary_after_signed_release',
                'project_readiness_status_without_invoking_codex',
            ],
            'runtime_policy' => [
                'preflight_is_read_only' => true,
                'post_start_signed_real_invoker_release_gate_call_allowed_by_future_invoker' => true,
                'implementation_boundary_required_after_signed_release' => true,
                'actual_process_start_allowed_here' => false,
                'provider_process_call_allowed_here' => false,
                'adapter_invocation_allowed_here' => false,
                'adapter_execution_allowed_here' => false,
                'dispatch_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'self_programming_allowed_here' => false,
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_signed_real_invoker_release_gate_implementation_packet',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_signed_real_invoker_release_gate_preflight.v1',
            'status' => (string) $preflight['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_signed_real_invoker_release_gate_preflight',
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
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_signed_real_invoker_release_gate_preflight' => $preflight,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_signed_real_invoker_release_gate_preflight_hash' => $this->stableHash($preflight),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_signed_real_invoker_release_gate_preflight_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_signed_real_invoker_release_gate_preflight_does_not_call_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_signed_real_invoker_release_gate_preflight_does_not_execute_adapter',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_signed_real_invoker_release_gate_preflight_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_signed_real_invoker_release_gate_preflight_does_not_dispatch_work',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_signed_real_invoker_release_gate_preflight_does_not_enable_self_programming',
            ],
            'human_summary' => $blockingReasons === []
                ? 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start signed release gate preflight is ready; implementation remains scoped to signed release authorization only.'
                : 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start signed release gate preflight is blocked until release preflight, signed release gate and storage prerequisites are ready.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProcessStartEnvelopeGateImplementationPacket(array $options = []): array
    {
        $preflightPayload = $this->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProcessStartEnvelopeGatePreflight($options);
        $preflight = (array) data_get($preflightPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_start_envelope_gate_preflight', []);
        $preflightHash = (string) data_get($preflightPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_start_envelope_gate_preflight_hash');

        $packet = [
            'status' => 'ready_for_scoped_one_shot_tick_codex_real_invoker_post_start_process_start_envelope_gate_invoker_implementation',
            'packet_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-REAL-INVOKER-POST-START-PROCESS-START-ENVELOPE-GATE-IMPLEMENTATION-PACKET-SELF-CONSTRUCTION-0001',
            'parent_program' => 'Atlas Self-Construction OS',
            'submodule' => 'Atlas Agent Control Plane',
            'provider' => 'codex',
            'adapter' => 'codex',
            'source_codex_real_invoker_post_start_process_start_envelope_gate_preflight_status' => data_get($preflight, 'status'),
            'source_codex_real_invoker_post_start_process_start_envelope_gate_preflight_hash' => $preflightHash,
            'allowed_files' => [
                'app/Services/Ai/SelfConstruction/ControlPlane/AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProcessStartEnvelopeGateInvoker.php',
                'tests/Feature/Ai/AtlasAiSelfConstructionAgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProcessStartEnvelopeGateInvokerTest.php',
                'app/Services/Ai/SelfConstruction/Readiness/AtlasSelfConstructionReadinessService.php',
                'app/Console/Commands/AtlasAiSelfConstructionCommand.php',
                'tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php',
                'docs/engineering-knowledge-base/self-construction/agent-control-plane-contract.md',
            ],
            'tasks' => [
                ['id' => 'T1', 'title' => 'Expose one-shot scheduler Codex real invoker post-start process start envelope invoker', 'type' => 'service', 'acceptance' => 'Invoker validates post-start process start envelope input and delegates to AgentCodexRealInvokerPostStartProcessStartEnvelopeGate.'],
                ['id' => 'T2', 'title' => 'Reject runtime-enabling input flags before delegation', 'type' => 'service_logic', 'acceptance' => 'Invoker rejects caller inputs that try to set actual_process_start_allowed, provider_process_call_allowed, adapter_invocation_allowed, adapter_execution_allowed, token_spend_allowed, dispatch_allowed, self_programming_allowed, external_process_started, provider_started or process_started to true.'],
                ['id' => 'T3', 'title' => 'Add Codex real invoker post-start process start envelope tests', 'type' => 'test', 'acceptance' => 'Tests cover successful envelope preparation, idempotent retry, invalid envelope hashes (process_start_envelope_hash, command_resolution_hash, environment_resolution_hash), missing rehearsal bridge, missing evidence bridge, forbidden runtime flags including process_started, missing provider run, rollback on ledger failure and runtime flags remain false in result.'],
                ['id' => 'T4', 'title' => 'Expose Codex real invoker post-start process start envelope status', 'type' => 'command_surface', 'acceptance' => 'Command/readiness reports invoker readiness and next start execution gate slice without calling the invoker in status mode.'],
            ],
            'task_count' => 4,
            'acceptance_criteria' => [
                'codex_real_invoker_post_start_process_start_envelope_gate_invoker_records_envelope_without_starting_codex',
                'codex_real_invoker_post_start_process_start_envelope_gate_invoker_requires_actual_process_start_rehearsal_bridge_metadata',
                'codex_real_invoker_post_start_process_start_envelope_gate_invoker_requires_post_start_evidence_acceptance_bridge_metadata',
                'codex_real_invoker_post_start_process_start_envelope_gate_invoker_requires_process_start_envelope_hash',
                'codex_real_invoker_post_start_process_start_envelope_gate_invoker_requires_command_resolution_hash',
                'codex_real_invoker_post_start_process_start_envelope_gate_invoker_requires_environment_resolution_hash',
                'codex_real_invoker_post_start_process_start_envelope_gate_invoker_rejects_runtime_enabling_input_flags',
                'codex_real_invoker_post_start_process_start_envelope_gate_invoker_rejects_process_started_input_flag',
                'codex_real_invoker_post_start_process_start_envelope_gate_invoker_never_dispatches_or_spends_tokens',
            ],
            'required_gates' => [
                'php_lint_codex_real_invoker_post_start_process_start_envelope_gate_invoker',
                'dedicated_codex_real_invoker_post_start_process_start_envelope_gate_invoker_feature_tests',
                'generic_codex_real_invoker_post_start_process_start_envelope_gate_feature_tests',
                'focused_self_construction_command_tests',
                'architecture_validate',
                'docs_health',
                'git_diff_check',
            ],
            'implementation_policy' => [
                'implementation_allowed_by_packet' => true,
                'post_start_process_start_envelope_gate_call_allowed_by_future_invoker' => true,
                'codex_real_invoker_process_start_envelope_builder_allowed_by_future_invoker' => true,
                'post_start_process_start_envelope_built_after_future_invoker' => true,
                'start_execution_gate_required_after_future_invoker' => true,
                'actual_process_start_allowed_by_packet' => false,
                'process_started_by_packet' => false,
                'provider_process_call_allowed_by_packet' => false,
                'adapter_invocation_allowed_by_packet' => false,
                'adapter_execution_allowed_by_packet' => false,
                'dispatch_allowed_by_packet' => false,
                'token_spend_allowed_by_packet' => false,
                'self_programming_allowed_by_packet' => false,
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_process_start_envelope_gate_invoker_service',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_start_envelope_gate_implementation_packet.v1',
            'status' => (string) $packet['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_start_envelope_gate_implementation_packet',
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
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_start_envelope_gate_implementation_packet' => $packet,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_start_envelope_gate_implementation_packet_hash' => $this->stableHash($packet),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_start_envelope_gate_implementation_packet_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_start_envelope_gate_implementation_packet_does_not_call_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_start_envelope_gate_implementation_packet_does_not_execute_adapter',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_start_envelope_gate_implementation_packet_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_start_envelope_gate_implementation_packet_does_not_dispatch_work',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_start_envelope_gate_implementation_packet_does_not_enable_self_programming',
            ],
            'human_summary' => 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start process start envelope gate implementation packet is ready; the envelope precedes the start execution gate.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartAdapterInvocationBoundaryGateStatus(array $options = []): array
    {
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $heartbeatsTableReady = Schema::hasTable('atlas_self_construction_agent_heartbeats');
        $ledgerTableReady = Schema::hasTable('atlas_ledger_events');
        $gateReady = class_exists(AgentCodexRealInvokerPostStartAdapterInvocationBoundaryGate::class)
            && method_exists(AgentCodexRealInvokerPostStartAdapterInvocationBoundaryGate::class, 'preparePostStartAdapterInvocationBoundary');
        $invokerReady = class_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartAdapterInvocationBoundaryGateInvoker::class)
            && method_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartAdapterInvocationBoundaryGateInvoker::class, 'prepareCodexRealInvokerPostStartAdapterInvocationBoundaryGate');
        $boundaryReady = class_exists(AgentDispatchExecutorAdapterInvocationBoundary::class)
            && method_exists(AgentDispatchExecutorAdapterInvocationBoundary::class, 'prepareInvocation');
        $registryReady = class_exists(AgentProviderAdapterRegistry::class)
            && method_exists(AgentProviderAdapterRegistry::class, 'resolve');

        $boundaryRunsQuery = $runsTableReady
            ? AtlasSelfConstructionAgentRun::query()
                ->whereNotNull('metadata->codex_real_invoker_post_start_adapter_invocation_boundary->adapter_invocation_id')
            : null;
        $providerRunsWithAdapterBoundaryQuery = $runsTableReady
            ? AtlasSelfConstructionAgentRun::query()
                ->where('run_key', 'like', 'provider-start:%')
                ->where('status', 'adapter_invocation_prepared')
                ->whereNotNull('metadata->adapter_invocation->adapter_invocation_id')
            : null;

        $statusReady = $runsTableReady
            && $heartbeatsTableReady
            && $ledgerTableReady
            && $gateReady
            && $invokerReady
            && $boundaryReady
            && $registryReady;
        $status = [
            'status' => $statusReady ? 'one_shot_tick_codex_real_invoker_post_start_adapter_invocation_boundary_gate_service_ready' : 'blocked',
            'provider' => 'codex',
            'adapter' => 'codex',
            'invoker_service' => AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartAdapterInvocationBoundaryGateInvoker::class,
            'invoker_service_ready' => $invokerReady,
            'invoker_canonical_method' => 'prepareCodexRealInvokerPostStartAdapterInvocationBoundaryGate',
            'generic_post_start_adapter_invocation_boundary_gate_service' => AgentCodexRealInvokerPostStartAdapterInvocationBoundaryGate::class,
            'generic_post_start_adapter_invocation_boundary_gate_service_ready' => $gateReady,
            'generic_post_start_adapter_invocation_boundary_gate_canonical_method' => 'preparePostStartAdapterInvocationBoundary',
            'dispatch_executor_adapter_invocation_boundary_service' => AgentDispatchExecutorAdapterInvocationBoundary::class,
            'dispatch_executor_adapter_invocation_boundary_ready' => $boundaryReady,
            'provider_adapter_registry_service' => AgentProviderAdapterRegistry::class,
            'provider_adapter_registry_ready' => $registryReady,
            'agent_runs_table_ready' => $runsTableReady,
            'agent_heartbeats_table_ready' => $heartbeatsTableReady,
            'ledger_table_ready' => $ledgerTableReady,
            'codex_real_invoker_post_start_adapter_invocation_boundary_recorded_run_count' => $boundaryRunsQuery === null ? null : (clone $boundaryRunsQuery)->count(),
            'provider_start_runs_with_adapter_invocation_boundary_count' => $providerRunsWithAdapterBoundaryQuery === null ? null : (clone $providerRunsWithAdapterBoundaryQuery)->count(),
            'runtime_policy' => [
                'status_projection_is_read_only' => true,
                'invoker_may_prepare_adapter_invocation_metadata_when_called_with_signed_input' => true,
                'adapter_invocation_boundary_is_not_adapter_execution' => true,
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
                ? 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_adapter_execution_guard_gate_contract'
                : 'repair_one_shot_scheduler_tick_codex_real_invoker_post_start_adapter_invocation_boundary_gate_service_blockers',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_adapter_invocation_boundary_gate_status.v1',
            'status' => (string) $status['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_adapter_invocation_boundary_gate_status',
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
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_adapter_invocation_boundary_gate_status' => $status,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_adapter_invocation_boundary_gate_status_hash' => $this->stableHash($status),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_adapter_invocation_boundary_gate_status_does_not_call_adapter_invocation_boundary_gate',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_adapter_invocation_boundary_gate_status_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_adapter_invocation_boundary_gate_status_does_not_execute_adapter',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_adapter_invocation_boundary_gate_status_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_adapter_invocation_boundary_gate_status_does_not_dispatch_work',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_adapter_invocation_boundary_gate_status_does_not_enable_self_programming',
            ],
            'human_summary' => $statusReady
                ? 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start adapter invocation boundary gate service is ready and inspectable; adapter execution guard remains separate.'
                : 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start adapter invocation boundary gate service is blocked until invoker, generic gate, boundary, registry and storage are ready.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProcessStartReleaseGateStatus(array $options = []): array
    {
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerTableReady = Schema::hasTable('atlas_ledger_events');
        $gateReady = class_exists(AgentCodexRealInvokerPostStartProcessStartReleaseGate::class)
            && method_exists(AgentCodexRealInvokerPostStartProcessStartReleaseGate::class, 'authorizePostStartProcessStartRelease');
        $invokerReady = class_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProcessStartReleaseGateInvoker::class)
            && method_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProcessStartReleaseGateInvoker::class, 'authorizeCodexRealInvokerPostStartProcessStartReleaseGate');
        $codexReleaseGateReady = class_exists(AgentCodexProcessStartReleaseGate::class)
            && method_exists(AgentCodexProcessStartReleaseGate::class, 'authorizeCodexProcessStart');
        $providerExecutionGateReady = class_exists(AgentCodexRealInvokerPostStartProviderExecutionContractGate::class)
            && method_exists(AgentCodexRealInvokerPostStartProviderExecutionContractGate::class, 'preparePostStartProviderExecutionContract');

        $observedReleaseRunsQuery = $runsTableReady
            ? AtlasSelfConstructionAgentRun::query()
                ->whereNotNull('metadata->codex_real_invoker_post_start_process_start_release->process_start_release_id')
            : null;
        $providerRunsWithProcessReleaseQuery = $runsTableReady
            ? AtlasSelfConstructionAgentRun::query()
                ->where('run_key', 'like', 'provider-start:%')
                ->where('status', 'adapter_invocation_prepared')
                ->whereNotNull('metadata->codex_process_start_release->process_start_release_id')
            : null;

        $statusReady = $runsTableReady
            && $ledgerTableReady
            && $gateReady
            && $invokerReady
            && $codexReleaseGateReady
            && $providerExecutionGateReady;
        $status = [
            'status' => $statusReady ? 'one_shot_tick_codex_real_invoker_post_start_process_start_release_gate_service_ready' : 'blocked',
            'provider' => 'codex',
            'adapter' => 'codex',
            'invoker_service' => AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProcessStartReleaseGateInvoker::class,
            'invoker_service_ready' => $invokerReady,
            'invoker_canonical_method' => 'authorizeCodexRealInvokerPostStartProcessStartReleaseGate',
            'generic_post_start_process_start_release_gate_service' => AgentCodexRealInvokerPostStartProcessStartReleaseGate::class,
            'generic_post_start_process_start_release_gate_service_ready' => $gateReady,
            'generic_post_start_process_start_release_gate_canonical_method' => 'authorizePostStartProcessStartRelease',
            'codex_process_start_release_gate_service' => AgentCodexProcessStartReleaseGate::class,
            'codex_process_start_release_gate_ready' => $codexReleaseGateReady,
            'codex_process_start_release_gate_canonical_method' => 'authorizeCodexProcessStart',
            'post_start_provider_execution_contract_gate_service' => AgentCodexRealInvokerPostStartProviderExecutionContractGate::class,
            'post_start_provider_execution_contract_gate_ready' => $providerExecutionGateReady,
            'agent_runs_table_ready' => $runsTableReady,
            'ledger_table_ready' => $ledgerTableReady,
            'codex_real_invoker_post_start_process_start_release_recorded_run_count' => $observedReleaseRunsQuery === null ? null : (clone $observedReleaseRunsQuery)->count(),
            'provider_start_runs_with_codex_process_start_release_count' => $providerRunsWithProcessReleaseQuery === null ? null : (clone $providerRunsWithProcessReleaseQuery)->count(),
            'runtime_policy' => [
                'status_projection_is_read_only' => true,
                'invoker_may_authorize_process_start_release_when_called_with_signed_input' => true,
                'process_start_release_authorization_is_not_process_start' => true,
                'supervised_start_executor_required_before_process_start' => true,
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
                ? 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_supervised_start_executor_gate_contract'
                : 'repair_one_shot_scheduler_tick_codex_real_invoker_post_start_process_start_release_gate_service_blockers',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_start_release_gate_status.v1',
            'status' => (string) $status['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_start_release_gate_status',
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
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_start_release_gate_status' => $status,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_start_release_gate_status_hash' => $this->stableHash($status),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_start_release_gate_status_does_not_call_process_start_release_gate',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_start_release_gate_status_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_start_release_gate_status_does_not_execute_adapter',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_start_release_gate_status_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_start_release_gate_status_does_not_dispatch_work',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_start_release_gate_status_does_not_enable_self_programming',
            ],
            'human_summary' => $statusReady
                ? 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start process start release gate service is ready and inspectable; supervised start remains separate.'
                : 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start process start release gate service is blocked until invoker, generic gate, release gate and storage are ready.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartSupervisedStartExecutorGateStatus(array $options = []): array
    {
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerTableReady = Schema::hasTable('atlas_ledger_events');
        $gateReady = class_exists(AgentCodexRealInvokerPostStartSupervisedStartExecutorGate::class)
            && method_exists(AgentCodexRealInvokerPostStartSupervisedStartExecutorGate::class, 'preparePostStartSupervisedStart');
        $invokerReady = class_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartSupervisedStartExecutorGateInvoker::class)
            && method_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartSupervisedStartExecutorGateInvoker::class, 'prepareCodexRealInvokerPostStartSupervisedStartExecutorGate');
        $supervisedStartReady = class_exists(AgentCodexSupervisedStartExecutor::class)
            && method_exists(AgentCodexSupervisedStartExecutor::class, 'prepareSupervisedStart');
        $processStartReleaseGateReady = class_exists(AgentCodexRealInvokerPostStartProcessStartReleaseGate::class)
            && method_exists(AgentCodexRealInvokerPostStartProcessStartReleaseGate::class, 'authorizePostStartProcessStartRelease');

        $observedSupervisedRunsQuery = $runsTableReady
            ? AtlasSelfConstructionAgentRun::query()
                ->whereNotNull('metadata->codex_real_invoker_post_start_supervised_start->supervised_start_id')
            : null;
        $providerRunsWithSupervisedStartQuery = $runsTableReady
            ? AtlasSelfConstructionAgentRun::query()
                ->where('run_key', 'like', 'provider-start:%')
                ->where('status', 'adapter_invocation_prepared')
                ->whereNotNull('metadata->codex_supervised_start->supervised_start_id')
            : null;

        $statusReady = $runsTableReady
            && $ledgerTableReady
            && $gateReady
            && $invokerReady
            && $supervisedStartReady
            && $processStartReleaseGateReady;
        $status = [
            'status' => $statusReady ? 'one_shot_tick_codex_real_invoker_post_start_supervised_start_executor_gate_service_ready' : 'blocked',
            'provider' => 'codex',
            'adapter' => 'codex',
            'invoker_service' => AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartSupervisedStartExecutorGateInvoker::class,
            'invoker_service_ready' => $invokerReady,
            'invoker_canonical_method' => 'prepareCodexRealInvokerPostStartSupervisedStartExecutorGate',
            'generic_post_start_supervised_start_executor_gate_service' => AgentCodexRealInvokerPostStartSupervisedStartExecutorGate::class,
            'generic_post_start_supervised_start_executor_gate_service_ready' => $gateReady,
            'generic_post_start_supervised_start_executor_gate_canonical_method' => 'preparePostStartSupervisedStart',
            'codex_supervised_start_executor_service' => AgentCodexSupervisedStartExecutor::class,
            'codex_supervised_start_executor_ready' => $supervisedStartReady,
            'codex_supervised_start_executor_canonical_method' => 'prepareSupervisedStart',
            'post_start_process_start_release_gate_service' => AgentCodexRealInvokerPostStartProcessStartReleaseGate::class,
            'post_start_process_start_release_gate_ready' => $processStartReleaseGateReady,
            'agent_runs_table_ready' => $runsTableReady,
            'ledger_table_ready' => $ledgerTableReady,
            'codex_real_invoker_post_start_supervised_start_recorded_run_count' => $observedSupervisedRunsQuery === null ? null : (clone $observedSupervisedRunsQuery)->count(),
            'provider_start_runs_with_codex_supervised_start_count' => $providerRunsWithSupervisedStartQuery === null ? null : (clone $providerRunsWithSupervisedStartQuery)->count(),
            'runtime_policy' => [
                'status_projection_is_read_only' => true,
                'invoker_may_prepare_supervised_start_when_called_with_signed_input' => true,
                'supervised_start_preparation_is_not_process_spawn' => true,
                'process_spawn_enablement_required_before_process_spawn' => true,
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
                ? 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_process_spawn_enablement_gate_contract'
                : 'repair_one_shot_scheduler_tick_codex_real_invoker_post_start_supervised_start_executor_gate_service_blockers',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_supervised_start_executor_gate_status.v1',
            'status' => (string) $status['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_supervised_start_executor_gate_status',
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
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_supervised_start_executor_gate_status' => $status,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_supervised_start_executor_gate_status_hash' => $this->stableHash($status),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_supervised_start_executor_gate_status_does_not_call_supervised_start_executor_gate',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_supervised_start_executor_gate_status_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_supervised_start_executor_gate_status_does_not_execute_adapter',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_supervised_start_executor_gate_status_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_supervised_start_executor_gate_status_does_not_dispatch_work',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_supervised_start_executor_gate_status_does_not_enable_self_programming',
            ],
            'human_summary' => $statusReady
                ? 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start supervised start executor gate service is ready and inspectable; process spawn enablement remains separate.'
                : 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start supervised start executor gate service is blocked until invoker, generic gate, supervised executor and storage are ready.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProcessSpawnEnablementGateStatus(array $options = []): array
    {
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerTableReady = Schema::hasTable('atlas_ledger_events');
        $gateReady = class_exists(AgentCodexRealInvokerPostStartProcessSpawnEnablementGate::class)
            && method_exists(AgentCodexRealInvokerPostStartProcessSpawnEnablementGate::class, 'enablePostStartProcessSpawn');
        $invokerReady = class_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProcessSpawnEnablementGateInvoker::class)
            && method_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProcessSpawnEnablementGateInvoker::class, 'enableCodexRealInvokerPostStartProcessSpawnEnablementGate');
        $spawnEnablementReady = class_exists(AgentCodexProcessSpawnEnablementGate::class)
            && method_exists(AgentCodexProcessSpawnEnablementGate::class, 'enableCodexProcessSpawn');
        $supervisedGateReady = class_exists(AgentCodexRealInvokerPostStartSupervisedStartExecutorGate::class)
            && method_exists(AgentCodexRealInvokerPostStartSupervisedStartExecutorGate::class, 'preparePostStartSupervisedStart');

        $observedEnablementRunsQuery = $runsTableReady
            ? AtlasSelfConstructionAgentRun::query()
                ->whereNotNull('metadata->codex_real_invoker_post_start_process_spawn_enablement->spawn_enablement_id')
            : null;
        $providerRunsWithSpawnEnablementQuery = $runsTableReady
            ? AtlasSelfConstructionAgentRun::query()
                ->where('run_key', 'like', 'provider-start:%')
                ->where('status', 'adapter_invocation_prepared')
                ->whereNotNull('metadata->codex_process_spawn_enablement->spawn_enablement_id')
            : null;

        $statusReady = $runsTableReady
            && $ledgerTableReady
            && $gateReady
            && $invokerReady
            && $spawnEnablementReady
            && $supervisedGateReady;
        $status = [
            'status' => $statusReady ? 'one_shot_tick_codex_real_invoker_post_start_process_spawn_enablement_gate_service_ready' : 'blocked',
            'provider' => 'codex',
            'adapter' => 'codex',
            'invoker_service' => AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProcessSpawnEnablementGateInvoker::class,
            'invoker_service_ready' => $invokerReady,
            'invoker_canonical_method' => 'enableCodexRealInvokerPostStartProcessSpawnEnablementGate',
            'generic_post_start_process_spawn_enablement_gate_service' => AgentCodexRealInvokerPostStartProcessSpawnEnablementGate::class,
            'generic_post_start_process_spawn_enablement_gate_service_ready' => $gateReady,
            'generic_post_start_process_spawn_enablement_gate_canonical_method' => 'enablePostStartProcessSpawn',
            'codex_process_spawn_enablement_gate_service' => AgentCodexProcessSpawnEnablementGate::class,
            'codex_process_spawn_enablement_gate_ready' => $spawnEnablementReady,
            'codex_process_spawn_enablement_gate_canonical_method' => 'enableCodexProcessSpawn',
            'post_start_supervised_start_executor_gate_service' => AgentCodexRealInvokerPostStartSupervisedStartExecutorGate::class,
            'post_start_supervised_start_executor_gate_ready' => $supervisedGateReady,
            'agent_runs_table_ready' => $runsTableReady,
            'ledger_table_ready' => $ledgerTableReady,
            'codex_real_invoker_post_start_process_spawn_enablement_recorded_run_count' => $observedEnablementRunsQuery === null ? null : (clone $observedEnablementRunsQuery)->count(),
            'provider_start_runs_with_codex_process_spawn_enablement_count' => $providerRunsWithSpawnEnablementQuery === null ? null : (clone $providerRunsWithSpawnEnablementQuery)->count(),
            'runtime_policy' => [
                'status_projection_is_read_only' => true,
                'invoker_may_record_process_spawn_enablement_when_called_with_signed_input' => true,
                'process_spawn_enablement_is_not_process_spawn' => true,
                'final_process_spawn_executor_required_before_process_spawn' => true,
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
                ? 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_final_process_spawn_executor_gate_contract'
                : 'repair_one_shot_scheduler_tick_codex_real_invoker_post_start_process_spawn_enablement_gate_service_blockers',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_spawn_enablement_gate_status.v1',
            'status' => (string) $status['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_spawn_enablement_gate_status',
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
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_spawn_enablement_gate_status' => $status,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_spawn_enablement_gate_status_hash' => $this->stableHash($status),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_spawn_enablement_gate_status_does_not_call_process_spawn_enablement_gate',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_spawn_enablement_gate_status_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_spawn_enablement_gate_status_does_not_execute_adapter',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_spawn_enablement_gate_status_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_spawn_enablement_gate_status_does_not_dispatch_work',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_spawn_enablement_gate_status_does_not_enable_self_programming',
            ],
            'human_summary' => $statusReady
                ? 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start process spawn enablement gate service is ready and inspectable; final process spawn executor remains separate.'
                : 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start process spawn enablement gate service is blocked until invoker, generic gate, spawn enablement gate and storage are ready.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartActualProcessStartRehearsalGateImplementationPacket(array $options = []): array
    {
        $preflightPayload = $this->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartActualProcessStartRehearsalGatePreflight($options);
        $preflight = (array) data_get($preflightPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_actual_process_start_rehearsal_gate_preflight', []);
        $preflightHash = (string) data_get($preflightPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_actual_process_start_rehearsal_gate_preflight_hash');

        $packet = [
            'status' => 'ready_for_scoped_one_shot_tick_codex_real_invoker_post_start_actual_process_start_rehearsal_gate_invoker_implementation',
            'packet_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-REAL-INVOKER-POST-START-ACTUAL-PROCESS-START-REHEARSAL-GATE-IMPLEMENTATION-PACKET-SELF-CONSTRUCTION-0001',
            'parent_program' => 'Atlas Self-Construction OS',
            'submodule' => 'Atlas Agent Control Plane',
            'provider' => 'codex',
            'adapter' => 'codex',
            'source_codex_real_invoker_post_start_actual_process_start_rehearsal_gate_preflight_status' => data_get($preflight, 'status'),
            'source_codex_real_invoker_post_start_actual_process_start_rehearsal_gate_preflight_hash' => $preflightHash,
            'allowed_files' => [
                'app/Services/Ai/SelfConstruction/ControlPlane/AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartActualProcessStartRehearsalGateInvoker.php',
                'tests/Feature/Ai/AtlasAiSelfConstructionAgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartActualProcessStartRehearsalGateInvokerTest.php',
                'app/Services/Ai/SelfConstruction/Readiness/AtlasSelfConstructionReadinessService.php',
                'app/Console/Commands/AtlasAiSelfConstructionCommand.php',
                'tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php',
                'docs/engineering-knowledge-base/self-construction/agent-control-plane-contract.md',
            ],
            'tasks' => [
                ['id' => 'T1', 'title' => 'Expose one-shot scheduler Codex real invoker post-start actual process start rehearsal invoker', 'type' => 'service', 'acceptance' => 'Invoker validates post-start actual process start rehearsal input and delegates to AgentCodexRealInvokerPostStartActualProcessStartRehearsalGate.'],
                ['id' => 'T2', 'title' => 'Reject runtime-enabling input flags before delegation', 'type' => 'service_logic', 'acceptance' => 'Invoker rejects caller inputs that try to set actual_process_start_allowed, provider_process_call_allowed, adapter_invocation_allowed, adapter_execution_allowed, token_spend_allowed, dispatch_allowed, self_programming_allowed, external_process_started or provider_started to true.'],
                ['id' => 'T3', 'title' => 'Add Codex real invoker post-start actual process start rehearsal tests', 'type' => 'test', 'acceptance' => 'Tests cover successful rehearsal, idempotent retry, invalid rehearsal hashes (process_start_rehearsal_hash, command_resolution_hash, environment_resolution_hash), missing final authorization bridge, missing evidence bridge, forbidden runtime flags, missing provider run and rollback on ledger failure.'],
                ['id' => 'T4', 'title' => 'Expose Codex real invoker post-start actual process start rehearsal status', 'type' => 'command_surface', 'acceptance' => 'Command/readiness reports invoker readiness and next process start envelope slice without calling the invoker in status mode.'],
            ],
            'task_count' => 4,
            'acceptance_criteria' => [
                'codex_real_invoker_post_start_actual_process_start_rehearsal_gate_invoker_records_rehearsal_without_starting_codex',
                'codex_real_invoker_post_start_actual_process_start_rehearsal_gate_invoker_requires_final_process_start_authorization_bridge_metadata',
                'codex_real_invoker_post_start_actual_process_start_rehearsal_gate_invoker_requires_post_start_evidence_acceptance_bridge_metadata',
                'codex_real_invoker_post_start_actual_process_start_rehearsal_gate_invoker_requires_process_start_rehearsal_hash',
                'codex_real_invoker_post_start_actual_process_start_rehearsal_gate_invoker_requires_command_resolution_hash',
                'codex_real_invoker_post_start_actual_process_start_rehearsal_gate_invoker_requires_environment_resolution_hash',
                'codex_real_invoker_post_start_actual_process_start_rehearsal_gate_invoker_rejects_runtime_enabling_input_flags',
                'codex_real_invoker_post_start_actual_process_start_rehearsal_gate_invoker_never_dispatches_or_spends_tokens',
            ],
            'required_gates' => [
                'php_lint_codex_real_invoker_post_start_actual_process_start_rehearsal_gate_invoker',
                'dedicated_codex_real_invoker_post_start_actual_process_start_rehearsal_gate_invoker_feature_tests',
                'generic_codex_real_invoker_post_start_actual_process_start_rehearsal_gate_feature_tests',
                'focused_self_construction_command_tests',
                'architecture_validate',
                'docs_health',
                'git_diff_check',
            ],
            'implementation_policy' => [
                'implementation_allowed_by_packet' => true,
                'post_start_actual_process_start_rehearsal_gate_call_allowed_by_future_invoker' => true,
                'codex_real_invoker_actual_process_start_rehearsal_allowed_by_future_invoker' => true,
                'post_start_actual_process_start_rehearsal_recorded_after_future_invoker' => true,
                'process_start_rehearsed_after_future_invoker' => true,
                'process_start_envelope_required_after_future_invoker' => true,
                'actual_process_start_allowed_by_packet' => false,
                'provider_process_call_allowed_by_packet' => false,
                'adapter_invocation_allowed_by_packet' => false,
                'adapter_execution_allowed_by_packet' => false,
                'dispatch_allowed_by_packet' => false,
                'token_spend_allowed_by_packet' => false,
                'self_programming_allowed_by_packet' => false,
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_actual_process_start_rehearsal_gate_invoker_service',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_actual_process_start_rehearsal_gate_implementation_packet.v1',
            'status' => (string) $packet['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_actual_process_start_rehearsal_gate_implementation_packet',
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
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_actual_process_start_rehearsal_gate_implementation_packet' => $packet,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_actual_process_start_rehearsal_gate_implementation_packet_hash' => $this->stableHash($packet),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_actual_process_start_rehearsal_gate_implementation_packet_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_actual_process_start_rehearsal_gate_implementation_packet_does_not_call_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_actual_process_start_rehearsal_gate_implementation_packet_does_not_execute_adapter',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_actual_process_start_rehearsal_gate_implementation_packet_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_actual_process_start_rehearsal_gate_implementation_packet_does_not_dispatch_work',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_actual_process_start_rehearsal_gate_implementation_packet_does_not_enable_self_programming',
            ],
            'human_summary' => 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start actual process start rehearsal gate implementation packet is ready; rehearsal precedes the process start envelope.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexExternalProcessRuntimeDriverContract(array $options = []): array
    {
        $spawnStatusPayload = $this->agentAutomaticDispatchSchedulerOneShotTickCodexFinalProcessSpawnExecutorStatus($options);
        $spawnStatus = (array) data_get($spawnStatusPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_final_process_spawn_executor_status', []);
        $runtimePayload = $this->agentCodexExternalProcessRuntimeDriverContractTemplate($options);

        $contract = [
            'status' => 'one_shot_tick_codex_external_process_runtime_driver_contract_ready',
            'contract_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-EXTERNAL-PROCESS-RUNTIME-DRIVER-CONTRACT-SELF-CONSTRUCTION-0001',
            'parent_program' => 'Atlas Self-Construction OS',
            'submodule' => 'Atlas Agent Control Plane',
            'provider' => 'codex',
            'adapter' => 'codex',
            'source_codex_final_process_spawn_executor_status' => data_get($spawnStatus, 'status'),
            'source_codex_final_process_spawn_executor_status_hash' => data_get($spawnStatusPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_final_process_spawn_executor_status_hash'),
            'source_codex_external_process_runtime_driver_contract_status' => data_get($runtimePayload, 'status'),
            'source_codex_external_process_runtime_driver_contract_hash' => data_get($runtimePayload, 'codex_external_process_runtime_driver_contract_template_hash'),
            'release_boundary' => [
                'canonical_driver' => AgentCodexExternalProcessRuntimeDriver::class,
                'canonical_driver_method' => 'prepareExternalRuntime',
                'scheduler_invoker' => AgentAutomaticDispatchSchedulerOneShotTickCodexExternalProcessRuntimeDriverInvoker::class,
                'scheduler_invoker_method' => 'prepareCodexExternalProcessRuntime',
                'driver_effect' => 'prepare_codex_external_process_runtime_metadata_without_process_invocation',
                'external_process_started_by_driver' => false,
                'provider_started_by_driver' => false,
                'adapter_execution_allowed_by_driver' => false,
                'token_spend_allowed_by_driver' => false,
                'required_spawn_executor_status_before_driver' => 'prepared_pending_external_process_runtime',
                'prepared_status_after_driver' => 'prepared_pending_process_invocation',
                'idempotency_key' => 'runtime_driver_id',
            ],
            'required_input_fields_for_future_invoker' => [
                'run_key',
                'codex_execution_id',
                'process_start_release_id',
                'supervised_start_id',
                'spawn_enablement_id',
                'spawn_executor_id',
                'runtime_driver_id',
                'operator_runtime_receipt_hash',
                'process_command_hash',
                'environment_contract_hash',
                'termination_policy_hash',
                'actor',
                'session',
                'reason',
            ],
            'allowed_future_mutations' => [
                'write_codex_external_process_runtime_driver_metadata_on_agent_run',
                'append_codex_external_process_runtime_driver_prepared_evidence_event',
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
                'external_process_runtime_driver_is_preparation_not_invocation' => true,
                'process_invocation_authorization_requires_separate_signed_release' => true,
                'operator_runtime_receipt_hash_required' => true,
                'process_command_hash_required' => true,
                'environment_contract_hash_required' => true,
                'termination_policy_hash_required' => true,
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_external_process_runtime_driver_preflight',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_external_process_runtime_driver_contract.v1',
            'status' => (string) $contract['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_external_process_runtime_driver_contract',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_external_process_runtime_driver_allowed' => false,
            'adapter_execution_allowed' => false,
            'provider_start_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_external_process_runtime_driver_contract' => $contract,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_external_process_runtime_driver_contract_hash' => $this->stableHash($contract),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_external_process_runtime_driver_contract_does_not_call_driver',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_external_process_runtime_driver_contract_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_external_process_runtime_driver_contract_does_not_call_adapters',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_external_process_runtime_driver_contract_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_external_process_runtime_driver_contract_does_not_enable_self_programming',
            ],
            'human_summary' => 'Automatic dispatch scheduler one-shot tick Codex external process runtime driver contract is ready; it prepares runtime metadata but cannot invoke Codex.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartLivenessMonitorContract(array $options = []): array
    {
        $acceptanceStatusPayload = $this->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartEvidenceAcceptanceBridgeStatus($options);
        $acceptanceStatus = (array) data_get($acceptanceStatusPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_evidence_acceptance_bridge_status', []);
        $livenessPayload = $this->agentCodexRealInvokerPostStartLivenessMonitorContractTemplate($options);

        $contract = [
            'status' => 'one_shot_tick_codex_real_invoker_post_start_liveness_monitor_contract_ready',
            'contract_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-REAL-INVOKER-POST-START-LIVENESS-MONITOR-CONTRACT-SELF-CONSTRUCTION-0001',
            'parent_program' => 'Atlas Self-Construction OS',
            'submodule' => 'Atlas Agent Control Plane',
            'provider' => 'codex',
            'adapter' => 'codex',
            'source_codex_real_invoker_post_start_evidence_acceptance_bridge_status' => data_get($acceptanceStatus, 'status'),
            'source_codex_real_invoker_post_start_evidence_acceptance_bridge_status_hash' => data_get($acceptanceStatusPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_evidence_acceptance_bridge_status_hash'),
            'source_codex_real_invoker_post_start_liveness_monitor_status' => data_get($livenessPayload, 'status'),
            'source_codex_real_invoker_post_start_liveness_monitor_hash' => data_get($livenessPayload, 'codex_real_invoker_post_start_liveness_monitor_contract_template_hash'),
            'release_boundary' => [
                'canonical_post_start_liveness_monitor' => AgentCodexRealInvokerPostStartLivenessMonitor::class,
                'canonical_post_start_liveness_monitor_method' => 'recordPostStartLiveness',
                'scheduler_invoker' => AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartLivenessMonitorInvoker::class,
                'scheduler_invoker_method' => 'recordCodexRealInvokerPostStartLiveness',
                'gate_effect' => 'record_external_liveness_observation_before_dispatch_release',
                'post_start_evidence_acceptance_bridge_required_before_liveness' => true,
                'post_start_evidence_receipt_required_before_liveness' => true,
                'allowed_liveness_states' => ['alive', 'silent', 'stale', 'orphaned'],
                'no_provider_call_attestation_required' => true,
                'actual_process_start_allowed_by_contract' => false,
                'atlas_process_spawned_by_contract' => false,
                'provider_marked_started_by_contract' => true,
                'adapter_execution_allowed_by_contract' => false,
                'token_spend_allowed_by_contract' => false,
                'dispatch_allowed_by_contract' => false,
                'idempotency_key' => 'post_start_liveness_monitor_id',
            ],
            'required_input_fields_for_future_invoker' => [
                'run_key',
                'codex_execution_id',
                'manual_start_executor_receipt_id',
                'operator_start_handoff_id',
                'post_start_receipt_contract_id',
                'post_start_evidence_acceptance_bridge_id',
                'post_start_evidence_receipt_id',
                'post_start_liveness_monitor_id',
                'observed_liveness_state',
                'liveness_observation_hash',
                'heartbeat_observation_hash',
                'progress_observation_hash',
                'operator_visibility_attestation_hash',
                'no_provider_call_attestation_hash',
                'actor',
                'session',
                'reason',
            ],
            'allowed_future_mutations' => [
                'write_codex_real_invoker_post_start_liveness_monitor_metadata_on_agent_run',
                'append_codex_real_invoker_post_start_liveness_evidence_event',
                'update_agent_run_liveness_from_external_observation',
            ],
            'forbidden_even_after_contract' => [
                'spawn_codex_process',
                'call_codex_cli_or_codex_app',
                'probe_provider_process_directly',
                'spawn_shell_or_subprocess',
                'spend_provider_tokens',
                'mark_run_terminal',
                'dispatch_work_to_codex',
                'enable_self_programming',
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_liveness_monitor_preflight',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_liveness_monitor_contract.v1',
            'status' => (string) $contract['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_liveness_monitor_contract',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_post_start_liveness_monitor_allowed' => false,
            'actual_process_start_allowed' => false,
            'adapter_execution_allowed' => false,
            'provider_start_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_liveness_monitor_contract' => $contract,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_liveness_monitor_contract_hash' => $this->stableHash($contract),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_liveness_monitor_contract_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_liveness_monitor_contract_does_not_call_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_liveness_monitor_contract_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_liveness_monitor_contract_does_not_enable_self_programming',
            ],
            'human_summary' => 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start liveness monitor contract is ready; it records external liveness only before dispatch release.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartAdapterExecutionGuardGateStatus(array $options = []): array
    {
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerTableReady = Schema::hasTable('atlas_ledger_events');
        $gateReady = class_exists(AgentCodexRealInvokerPostStartAdapterExecutionGuardGate::class)
            && method_exists(AgentCodexRealInvokerPostStartAdapterExecutionGuardGate::class, 'blockPostStartAdapterExecution');
        $invokerReady = class_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartAdapterExecutionGuardGateInvoker::class)
            && method_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartAdapterExecutionGuardGateInvoker::class, 'blockCodexRealInvokerPostStartAdapterExecutionGuardGate');
        $guardReady = class_exists(AgentProviderAdapterExecutionGuard::class)
            && method_exists(AgentProviderAdapterExecutionGuard::class, 'blockUntilProviderSpecificContract');
        $registryReady = class_exists(AgentProviderAdapterRegistry::class)
            && method_exists(AgentProviderAdapterRegistry::class, 'resolve');

        $observedGuardRunsQuery = $runsTableReady
            ? AtlasSelfConstructionAgentRun::query()
                ->whereNotNull('metadata->codex_real_invoker_post_start_adapter_execution_guard->execution_guard_id')
            : null;
        $providerRunsWithExecutionGuardQuery = $runsTableReady
            ? AtlasSelfConstructionAgentRun::query()
                ->where('run_key', 'like', 'provider-start:%')
                ->where('status', 'adapter_invocation_prepared')
                ->whereNotNull('metadata->provider_adapter_execution_guard->execution_guard_id')
            : null;

        $statusReady = $runsTableReady
            && $ledgerTableReady
            && $gateReady
            && $invokerReady
            && $guardReady
            && $registryReady;
        $status = [
            'status' => $statusReady ? 'one_shot_tick_codex_real_invoker_post_start_adapter_execution_guard_gate_service_ready' : 'blocked',
            'provider' => 'codex',
            'adapter' => 'codex',
            'invoker_service' => AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartAdapterExecutionGuardGateInvoker::class,
            'invoker_service_ready' => $invokerReady,
            'invoker_canonical_method' => 'blockCodexRealInvokerPostStartAdapterExecutionGuardGate',
            'generic_post_start_adapter_execution_guard_gate_service' => AgentCodexRealInvokerPostStartAdapterExecutionGuardGate::class,
            'generic_post_start_adapter_execution_guard_gate_service_ready' => $gateReady,
            'generic_post_start_adapter_execution_guard_gate_canonical_method' => 'blockPostStartAdapterExecution',
            'provider_adapter_execution_guard_service' => AgentProviderAdapterExecutionGuard::class,
            'provider_adapter_execution_guard_ready' => $guardReady,
            'provider_adapter_registry_service' => AgentProviderAdapterRegistry::class,
            'provider_adapter_registry_ready' => $registryReady,
            'agent_runs_table_ready' => $runsTableReady,
            'ledger_table_ready' => $ledgerTableReady,
            'codex_real_invoker_post_start_adapter_execution_guard_recorded_run_count' => $observedGuardRunsQuery === null ? null : (clone $observedGuardRunsQuery)->count(),
            'provider_start_runs_with_provider_adapter_execution_guard_count' => $providerRunsWithExecutionGuardQuery === null ? null : (clone $providerRunsWithExecutionGuardQuery)->count(),
            'runtime_policy' => [
                'status_projection_is_read_only' => true,
                'invoker_may_record_adapter_execution_guard_when_called_with_signed_input' => true,
                'adapter_execution_guard_is_not_adapter_execution' => true,
                'provider_specific_execution_contract_required_before_execution' => true,
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
                ? 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_provider_execution_contract_gate_contract'
                : 'repair_one_shot_scheduler_tick_codex_real_invoker_post_start_adapter_execution_guard_gate_service_blockers',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_adapter_execution_guard_gate_status.v1',
            'status' => (string) $status['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_adapter_execution_guard_gate_status',
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
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_adapter_execution_guard_gate_status' => $status,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_adapter_execution_guard_gate_status_hash' => $this->stableHash($status),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_adapter_execution_guard_gate_status_does_not_call_adapter_execution_guard_gate',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_adapter_execution_guard_gate_status_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_adapter_execution_guard_gate_status_does_not_execute_adapter',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_adapter_execution_guard_gate_status_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_adapter_execution_guard_gate_status_does_not_dispatch_work',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_adapter_execution_guard_gate_status_does_not_enable_self_programming',
            ],
            'human_summary' => $statusReady
                ? 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start adapter execution guard gate service is ready and inspectable; provider execution contract gate remains separate.'
                : 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start adapter execution guard gate service is blocked until invoker, generic gate, guard, registry and storage are ready.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartFinalProcessStartAuthorizationGateImplementationPacket(array $options = []): array
    {
        $preflightPayload = $this->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartFinalProcessStartAuthorizationGatePreflight($options);
        $preflight = (array) data_get($preflightPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_final_process_start_authorization_gate_preflight', []);
        $preflightHash = (string) data_get($preflightPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_final_process_start_authorization_gate_preflight_hash');

        $packet = [
            'status' => 'ready_for_scoped_one_shot_tick_codex_real_invoker_post_start_final_process_start_authorization_gate_invoker_implementation',
            'packet_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-REAL-INVOKER-POST-START-FINAL-PROCESS-START-AUTHORIZATION-GATE-IMPLEMENTATION-PACKET-SELF-CONSTRUCTION-0001',
            'parent_program' => 'Atlas Self-Construction OS',
            'submodule' => 'Atlas Agent Control Plane',
            'provider' => 'codex',
            'adapter' => 'codex',
            'source_codex_real_invoker_post_start_final_process_start_authorization_gate_preflight_status' => data_get($preflight, 'status'),
            'source_codex_real_invoker_post_start_final_process_start_authorization_gate_preflight_hash' => $preflightHash,
            'allowed_files' => [
                'app/Services/Ai/SelfConstruction/ControlPlane/AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartFinalProcessStartAuthorizationGateInvoker.php',
                'tests/Feature/Ai/AtlasAiSelfConstructionAgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartFinalProcessStartAuthorizationGateInvokerTest.php',
                'app/Services/Ai/SelfConstruction/Readiness/AtlasSelfConstructionReadinessService.php',
                'app/Console/Commands/AtlasAiSelfConstructionCommand.php',
                'tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php',
                'docs/engineering-knowledge-base/self-construction/agent-control-plane-contract.md',
            ],
            'tasks' => [
                ['id' => 'T1', 'title' => 'Expose one-shot scheduler Codex real invoker post-start final process start authorization invoker', 'type' => 'service', 'acceptance' => 'Invoker validates post-start final authorization input and delegates to AgentCodexRealInvokerPostStartFinalProcessStartAuthorizationGate.'],
                ['id' => 'T2', 'title' => 'Reject runtime-enabling input flags before delegation', 'type' => 'service_logic', 'acceptance' => 'Invoker rejects caller inputs that try to set actual_process_start_allowed, provider_process_call_allowed, adapter_invocation_allowed, adapter_execution_allowed, token_spend_allowed, dispatch_allowed, self_programming_allowed, external_process_started or provider_started to true.'],
                ['id' => 'T3', 'title' => 'Add Codex real invoker post-start final authorization tests', 'type' => 'test', 'acceptance' => 'Tests cover successful preparation, idempotent retry, invalid operator final-start hash, invalid final-start signature hash, missing guarded process start bridge, missing evidence bridge, forbidden runtime flags, missing provider run and rollback on ledger failure.'],
                ['id' => 'T4', 'title' => 'Expose Codex real invoker post-start final authorization status', 'type' => 'command_surface', 'acceptance' => 'Command/readiness reports invoker readiness and next actual-start rehearsal slice without calling the invoker in status mode.'],
            ],
            'task_count' => 4,
            'acceptance_criteria' => [
                'codex_real_invoker_post_start_final_process_start_authorization_gate_invoker_records_final_authorization_without_starting_codex',
                'codex_real_invoker_post_start_final_process_start_authorization_gate_invoker_requires_guarded_process_start_bridge_metadata',
                'codex_real_invoker_post_start_final_process_start_authorization_gate_invoker_requires_post_start_evidence_acceptance_bridge_metadata',
                'codex_real_invoker_post_start_final_process_start_authorization_gate_invoker_requires_operator_final_start_receipt_hash',
                'codex_real_invoker_post_start_final_process_start_authorization_gate_invoker_requires_final_start_signature_hash',
                'codex_real_invoker_post_start_final_process_start_authorization_gate_invoker_rejects_runtime_enabling_input_flags',
                'codex_real_invoker_post_start_final_process_start_authorization_gate_invoker_never_dispatches_or_spends_tokens',
            ],
            'required_gates' => [
                'php_lint_codex_real_invoker_post_start_final_process_start_authorization_gate_invoker',
                'dedicated_codex_real_invoker_post_start_final_process_start_authorization_gate_invoker_feature_tests',
                'generic_codex_real_invoker_post_start_final_process_start_authorization_gate_feature_tests',
                'focused_self_construction_command_tests',
                'architecture_validate',
                'docs_health',
                'git_diff_check',
            ],
            'implementation_policy' => [
                'implementation_allowed_by_packet' => true,
                'post_start_final_process_start_authorization_gate_call_allowed_by_future_invoker' => true,
                'codex_real_invoker_final_process_start_authorization_allowed_by_future_invoker' => true,
                'post_start_final_process_start_authorization_recorded_after_future_invoker' => true,
                'final_process_start_authorized_after_future_invoker' => true,
                'actual_process_start_rehearsal_required_after_future_invoker' => true,
                'actual_process_start_allowed_by_packet' => false,
                'provider_process_call_allowed_by_packet' => false,
                'adapter_invocation_allowed_by_packet' => false,
                'adapter_execution_allowed_by_packet' => false,
                'dispatch_allowed_by_packet' => false,
                'token_spend_allowed_by_packet' => false,
                'self_programming_allowed_by_packet' => false,
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_final_process_start_authorization_gate_invoker_service',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_final_process_start_authorization_gate_implementation_packet.v1',
            'status' => (string) $packet['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_final_process_start_authorization_gate_implementation_packet',
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
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_final_process_start_authorization_gate_implementation_packet' => $packet,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_final_process_start_authorization_gate_implementation_packet_hash' => $this->stableHash($packet),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_final_process_start_authorization_gate_implementation_packet_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_final_process_start_authorization_gate_implementation_packet_does_not_call_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_final_process_start_authorization_gate_implementation_packet_does_not_execute_adapter',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_final_process_start_authorization_gate_implementation_packet_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_final_process_start_authorization_gate_implementation_packet_does_not_dispatch_work',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_final_process_start_authorization_gate_implementation_packet_does_not_enable_self_programming',
            ],
            'human_summary' => 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start final process start authorization gate implementation packet is ready; it scopes final authorization before the actual process start rehearsal gate.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexFinalProcessSpawnExecutorContract(array $options = []): array
    {
        $enablementStatusPayload = $this->agentAutomaticDispatchSchedulerOneShotTickCodexProcessSpawnEnablementStatus($options);
        $enablementStatus = (array) data_get($enablementStatusPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_process_spawn_enablement_status', []);
        $executorPayload = $this->agentCodexProcessSpawnExecutorContractTemplate($options);

        $contract = [
            'status' => 'one_shot_tick_codex_final_process_spawn_executor_contract_ready',
            'contract_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-FINAL-PROCESS-SPAWN-EXECUTOR-CONTRACT-SELF-CONSTRUCTION-0001',
            'parent_program' => 'Atlas Self-Construction OS',
            'submodule' => 'Atlas Agent Control Plane',
            'provider' => 'codex',
            'adapter' => 'codex',
            'source_codex_process_spawn_enablement_status' => data_get($enablementStatus, 'status'),
            'source_codex_process_spawn_enablement_status_hash' => data_get($enablementStatusPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_process_spawn_enablement_status_hash'),
            'source_codex_process_spawn_executor_contract_status' => data_get($executorPayload, 'status'),
            'source_codex_process_spawn_executor_contract_hash' => data_get($executorPayload, 'codex_process_spawn_executor_contract_template_hash'),
            'release_boundary' => [
                'canonical_executor' => AgentCodexProcessSpawnExecutor::class,
                'canonical_executor_method' => 'prepareProcessSpawn',
                'scheduler_invoker' => AgentAutomaticDispatchSchedulerOneShotTickCodexFinalProcessSpawnExecutorInvoker::class,
                'scheduler_invoker_method' => 'prepareCodexFinalProcessSpawn',
                'executor_effect' => 'prepare_final_codex_process_spawn_executor_without_external_process_runtime',
                'external_process_started_by_executor' => false,
                'provider_started_by_executor' => false,
                'adapter_execution_allowed_by_executor' => false,
                'token_spend_allowed_by_executor' => false,
                'required_spawn_enablement_status_before_executor' => 'enabled_pending_final_process_spawn_executor',
                'prepared_status_after_executor' => 'prepared_pending_external_process_runtime',
                'idempotency_key' => 'spawn_executor_id',
            ],
            'required_input_fields_for_future_invoker' => [
                'run_key',
                'codex_execution_id',
                'process_start_release_id',
                'supervised_start_id',
                'spawn_enablement_id',
                'spawn_executor_id',
                'operator_final_spawn_receipt_hash',
                'runtime_supervision_plan_hash',
                'stdout_stderr_sink_hash',
                'liveness_probe_hash',
                'actor',
                'session',
                'reason',
            ],
            'allowed_future_mutations' => [
                'write_codex_process_spawn_executor_metadata_on_agent_run',
                'append_codex_process_spawn_executor_prepared_evidence_event',
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
                'final_process_spawn_executor_is_preparation_not_runtime' => true,
                'external_process_runtime_driver_requires_separate_signed_release' => true,
                'operator_final_spawn_receipt_hash_required' => true,
                'runtime_supervision_plan_hash_required' => true,
                'stdout_stderr_sink_hash_required' => true,
                'liveness_probe_hash_required' => true,
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_final_process_spawn_executor_preflight',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_final_process_spawn_executor_contract.v1',
            'status' => (string) $contract['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_final_process_spawn_executor_contract',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_final_process_spawn_executor_allowed' => false,
            'adapter_execution_allowed' => false,
            'provider_start_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_final_process_spawn_executor_contract' => $contract,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_final_process_spawn_executor_contract_hash' => $this->stableHash($contract),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_final_process_spawn_executor_contract_does_not_call_executor',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_final_process_spawn_executor_contract_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_final_process_spawn_executor_contract_does_not_call_adapters',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_final_process_spawn_executor_contract_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_final_process_spawn_executor_contract_does_not_enable_self_programming',
            ],
            'human_summary' => 'Automatic dispatch scheduler one-shot tick Codex final process spawn executor contract is ready; it prepares the final executor boundary but cannot start Codex.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerActualProcessStartRehearsalExecutorContract(array $options = []): array
    {
        $authorizationStatusPayload = $this->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerFinalProcessStartAuthorizationGateStatus($options);
        $authorizationStatus = (array) data_get($authorizationStatusPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_final_process_start_authorization_gate_status', []);
        $rehearsalPayload = $this->agentCodexRealInvokerActualProcessStartRehearsalExecutorContractTemplate($options);

        $contract = [
            'status' => 'one_shot_tick_codex_real_invoker_actual_process_start_rehearsal_executor_contract_ready',
            'contract_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-REAL-INVOKER-ACTUAL-PROCESS-START-REHEARSAL-EXECUTOR-CONTRACT-SELF-CONSTRUCTION-0001',
            'parent_program' => 'Atlas Self-Construction OS',
            'submodule' => 'Atlas Agent Control Plane',
            'provider' => 'codex',
            'adapter' => 'codex',
            'source_codex_real_invoker_final_process_start_authorization_gate_status' => data_get($authorizationStatus, 'status'),
            'source_codex_real_invoker_final_process_start_authorization_gate_status_hash' => data_get($authorizationStatusPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_final_process_start_authorization_gate_status_hash'),
            'source_codex_real_invoker_actual_process_start_rehearsal_executor_contract_status' => data_get($rehearsalPayload, 'status'),
            'source_codex_real_invoker_actual_process_start_rehearsal_executor_contract_hash' => data_get($rehearsalPayload, 'codex_real_invoker_actual_process_start_rehearsal_executor_contract_template_hash'),
            'release_boundary' => [
                'canonical_actual_process_start_rehearsal_executor' => AgentCodexRealInvokerActualProcessStartRehearsalExecutor::class,
                'canonical_actual_process_start_rehearsal_executor_method' => 'rehearseActualStart',
                'scheduler_invoker' => AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerActualProcessStartRehearsalExecutorInvoker::class,
                'scheduler_invoker_method' => 'rehearseCodexRealInvokerActualProcessStart',
                'rehearsal_effect' => 'record_actual_process_start_rehearsal_without_starting_process',
                'final_process_start_authorization_required_before_rehearsal' => true,
                'process_start_rehearsed_by_executor' => true,
                'actual_process_start_allowed_by_rehearsal' => false,
                'external_process_started_by_rehearsal' => false,
                'provider_started_by_rehearsal' => false,
                'adapter_execution_allowed_by_rehearsal' => false,
                'token_spend_allowed_by_rehearsal' => false,
                'idempotency_key' => 'real_invoker_actual_process_start_rehearsal_id',
            ],
            'required_input_fields_for_future_invoker' => [
                'run_key',
                'codex_execution_id',
                'real_invoker_final_process_start_authorization_id',
                'real_invoker_actual_process_start_rehearsal_id',
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
            'allowed_future_mutations' => [
                'write_codex_real_invoker_actual_process_start_rehearsal_metadata_on_agent_run',
                'append_codex_real_invoker_actual_process_start_rehearsal_evidence_event',
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
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_actual_process_start_rehearsal_preflight',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_actual_process_start_rehearsal_executor_contract.v1',
            'status' => (string) $contract['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_actual_process_start_rehearsal_executor_contract',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_actual_process_start_rehearsal_executor_allowed' => false,
            'process_start_rehearsed' => false,
            'actual_process_start_allowed' => false,
            'adapter_execution_allowed' => false,
            'provider_start_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_actual_process_start_rehearsal_executor_contract' => $contract,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_actual_process_start_rehearsal_executor_contract_hash' => $this->stableHash($contract),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_actual_process_start_rehearsal_executor_contract_does_not_rehearse_actual_start',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_actual_process_start_rehearsal_executor_contract_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_actual_process_start_rehearsal_executor_contract_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_actual_process_start_rehearsal_executor_contract_does_not_enable_self_programming',
            ],
            'human_summary' => 'Automatic dispatch scheduler one-shot tick Codex real invoker actual process start rehearsal contract is ready; it rehearses process-start inputs but still cannot start Codex.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickProviderSpecificExecutionContractRelease(array $options = []): array
    {
        $guardStatusPayload = $this->agentAutomaticDispatchSchedulerOneShotTickProviderAdapterExecutionGuardStatus($options);
        $guardStatus = (array) data_get($guardStatusPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_provider_adapter_execution_guard_status', []);
        $codexContractPayload = $this->agentCodexProviderExecutionContractTemplate($options);
        $codexContract = (array) data_get($codexContractPayload, 'codex_provider_execution_contract_template', []);

        $contract = [
            'status' => 'one_shot_tick_provider_specific_execution_contract_release_ready',
            'contract_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-PROVIDER-SPECIFIC-EXECUTION-CONTRACT-RELEASE-SELF-CONSTRUCTION-0001',
            'parent_program' => 'Atlas Self-Construction OS',
            'submodule' => 'Atlas Agent Control Plane',
            'provider' => 'codex',
            'adapter' => 'codex',
            'source_provider_adapter_execution_guard_status' => data_get($guardStatus, 'status'),
            'source_provider_adapter_execution_guard_status_hash' => data_get($guardStatusPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_provider_adapter_execution_guard_status_hash'),
            'source_codex_provider_execution_contract_status' => data_get($codexContractPayload, 'status'),
            'source_codex_provider_execution_contract_hash' => data_get($codexContractPayload, 'codex_provider_execution_contract_template_hash'),
            'release_boundary' => [
                'canonical_driver' => AgentCodexProviderExecutionDriver::class,
                'canonical_driver_method' => 'prepareCodexExecution',
                'scheduler_invoker' => AgentAutomaticDispatchSchedulerOneShotTickCodexProviderExecutionContractInvoker::class,
                'scheduler_invoker_method' => 'prepareCodexProviderExecutionContract',
                'driver_effect' => 'prepare_codex_provider_execution_metadata_without_process_start',
                'external_process_started_by_driver' => false,
                'provider_started_by_driver' => false,
                'adapter_execution_allowed_by_driver' => false,
                'token_spend_allowed_by_driver' => false,
                'required_guard_status_before_driver' => 'blocked_pending_provider_specific_execution_contract',
                'prepared_status_after_driver' => 'prepared_pending_explicit_codex_process_release',
                'idempotency_key' => 'codex_execution_id',
            ],
            'required_input_fields_for_future_invoker' => [
                'run_key',
                'codex_execution_id',
                'execution_guard_id',
                'adapter_invocation_id',
                'provider',
                'adapter',
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
                'write_codex_provider_execution_metadata_on_agent_run',
                'append_codex_provider_execution_prepared_evidence_event',
            ],
            'forbidden_even_after_contract' => [
                'spawn_codex_process',
                'call_codex_cli_or_codex_app',
                'spend_provider_tokens',
                'mark_packet_completed',
                'merge_work_products',
                'enable_self_programming',
            ],
            'handoff_policy' => [
                'provider_specific_contract_is_execution_preparation_not_process_start' => true,
                'codex_process_start_requires_separate_signed_release' => true,
                'full_chat_history_is_not_valid_context_pack' => true,
                'sandbox_binding_must_match_cwd' => true,
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_provider_specific_execution_contract_preflight',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_provider_specific_execution_contract_release.v1',
            'status' => (string) $contract['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_provider_specific_execution_contract_release',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'provider_specific_execution_contract_allowed' => false,
            'adapter_execution_allowed' => false,
            'provider_start_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_provider_specific_execution_contract_release' => $contract,
            'agent_automatic_dispatch_scheduler_one_shot_tick_provider_specific_execution_contract_release_hash' => $this->stableHash($contract),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_provider_specific_execution_contract_release_does_not_call_codex_driver',
                'agent_automatic_dispatch_scheduler_one_shot_tick_provider_specific_execution_contract_release_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_provider_specific_execution_contract_release_does_not_call_adapters',
                'agent_automatic_dispatch_scheduler_one_shot_tick_provider_specific_execution_contract_release_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_provider_specific_execution_contract_release_does_not_enable_self_programming',
            ],
            'human_summary' => 'Automatic dispatch scheduler one-shot tick provider-specific execution contract release is ready for Codex preparation only; process start still requires a future signed release.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartDispatchReceiptUseExecutorPreflight(array $options = []): array
    {
        $contractPayload = $this->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartDispatchReceiptUseExecutorContract($options);
        $contract = (array) data_get($contractPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_dispatch_receipt_use_executor_contract', []);
        $contractHash = (string) data_get($contractPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_dispatch_receipt_use_executor_contract_hash');
        $executorReady = class_exists(AgentCodexRealInvokerPostStartDispatchReceiptUseExecutor::class)
            && method_exists(AgentCodexRealInvokerPostStartDispatchReceiptUseExecutor::class, 'executePostStartDispatchReceiptUse');
        $invokerReady = class_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartDispatchReceiptUseExecutorInvoker::class)
            && method_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartDispatchReceiptUseExecutorInvoker::class, 'executeCodexRealInvokerPostStartDispatchReceiptUse');
        $writerReady = class_exists(AgentDispatchExecutorReceiptUseWriter::class)
            && method_exists(AgentDispatchExecutorReceiptUseWriter::class, 'markReceiptUsedAtomically');
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $dispatchReceiptsTableReady = Schema::hasTable('atlas_self_construction_agent_dispatch_receipts');
        $ledgerTableReady = Schema::hasTable('atlas_ledger_events');

        $checks = [
            'post_start_dispatch_receipt_use_executor_contract_ready' => data_get($contractPayload, 'status') === 'one_shot_tick_codex_real_invoker_post_start_dispatch_receipt_use_executor_contract_ready',
            'post_start_dispatch_receipt_use_executor_contract_hash_present' => $contractHash !== '',
            'post_start_dispatch_executor_handoff_status_ready' => data_get($contract, 'source_codex_real_invoker_post_start_dispatch_executor_handoff_status') === 'one_shot_tick_codex_real_invoker_post_start_dispatch_executor_handoff_service_ready',
            'generic_post_start_dispatch_receipt_use_executor_template_ready' => data_get($contract, 'source_codex_real_invoker_post_start_dispatch_receipt_use_executor_status') === 'codex_real_invoker_post_start_dispatch_receipt_use_executor_contract_template_ready',
            'codex_real_invoker_post_start_dispatch_receipt_use_executor_ready' => $executorReady,
            'codex_real_invoker_post_start_dispatch_receipt_use_executor_invoker_ready' => $invokerReady,
            'dispatch_receipt_use_writer_ready' => $writerReady,
            'canonical_post_start_dispatch_receipt_use_executor_method_ready' => data_get($contract, 'receipt_use_boundary.canonical_post_start_dispatch_receipt_use_executor_method') === 'executePostStartDispatchReceiptUse',
            'contract_requires_dispatch_executor_handoff' => data_get($contract, 'receipt_use_boundary.post_start_dispatch_executor_handoff_required_before_receipt_use') === true,
            'contract_requires_signed_dispatch_receipt' => data_get($contract, 'receipt_use_boundary.signed_dispatch_receipt_hash_required') === true,
            'contract_requires_executor_contract_hash' => data_get($contract, 'receipt_use_boundary.executor_contract_hash_required') === true,
            'contract_marks_dispatch_receipt_used' => data_get($contract, 'receipt_use_boundary.dispatch_receipt_used_by_contract') === true,
            'contract_keeps_provider_start_disabled' => data_get($contract, 'receipt_use_boundary.provider_start_allowed_after_mark_by_contract') === false,
            'contract_keeps_adapter_execution_disabled' => data_get($contract, 'receipt_use_boundary.adapter_execution_allowed_by_contract') === false,
            'contract_does_not_dispatch_work' => data_get($contract, 'receipt_use_boundary.dispatch_allowed_by_contract') === false,
            'contract_does_not_spend_tokens' => data_get($contract, 'receipt_use_boundary.token_spend_allowed_by_contract') === false,
            'agent_runs_table_ready' => $runsTableReady,
            'dispatch_receipts_table_ready' => $dispatchReceiptsTableReady,
            'ledger_table_ready' => $ledgerTableReady,
            'self_programming_forbidden' => in_array('enable_self_programming', (array) data_get($contract, 'forbidden_even_after_contract', []), true),
        ];
        $blockingReasons = array_values(array_keys(array_filter($checks, static fn (bool $passed): bool => ! $passed)));

        $preflight = [
            'status' => $blockingReasons === [] ? 'one_shot_tick_codex_real_invoker_post_start_dispatch_receipt_use_executor_preflight_ready' : 'blocked',
            'preflight_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-REAL-INVOKER-POST-START-DISPATCH-RECEIPT-USE-EXECUTOR-PREFLIGHT-SELF-CONSTRUCTION-0001',
            'source_post_start_dispatch_receipt_use_executor_contract_hash' => $contractHash,
            'preflight_checks' => $checks,
            'blocking_count' => count($blockingReasons),
            'blocking_reasons' => $blockingReasons,
            'implementation_requirements' => [
                'use_one_shot_scheduler_codex_real_invoker_post_start_dispatch_receipt_use_executor_invoker',
                'delegate_to_agent_codex_real_invoker_post_start_dispatch_receipt_use_executor',
                'require_codex_real_invoker_post_start_dispatch_executor_handoff_metadata',
                'mark_signed_dispatch_receipt_used_once',
                'preserve_provider_start_disabled_until_provider_start_driver_gate',
            ],
            'runtime_policy' => [
                'preflight_is_read_only' => true,
                'post_start_dispatch_receipt_use_executor_call_allowed_here' => false,
                'post_start_dispatch_receipt_use_metadata_allowed_by_future_invoker' => true,
                'provider_external_process_start_allowed_here' => false,
                'provider_start_allowed_here' => false,
                'adapter_invocation_allowed_here' => false,
                'dispatch_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'self_programming_allowed_here' => false,
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_dispatch_receipt_use_executor_implementation_packet',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_dispatch_receipt_use_executor_preflight.v1',
            'status' => (string) $preflight['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_dispatch_receipt_use_executor_preflight',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'post_start_dispatch_receipt_use_executor_allowed' => false,
            'actual_process_start_allowed' => false,
            'adapter_execution_allowed' => false,
            'provider_start_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_dispatch_receipt_use_executor_preflight' => $preflight,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_dispatch_receipt_use_executor_preflight_hash' => $this->stableHash($preflight),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_dispatch_receipt_use_executor_preflight_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_dispatch_receipt_use_executor_preflight_does_not_call_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_dispatch_receipt_use_executor_preflight_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_dispatch_receipt_use_executor_preflight_does_not_dispatch_work',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_dispatch_receipt_use_executor_preflight_does_not_enable_self_programming',
            ],
            'human_summary' => $blockingReasons === []
                ? 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start dispatch receipt-use executor preflight is ready.'
                : 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start dispatch receipt-use executor preflight is blocked until handoff, receipt-use executor, writer and storage prerequisites are ready.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartGuardedProcessStartExecutorGateImplementationPacket(array $options = []): array
    {
        $preflightPayload = $this->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartGuardedProcessStartExecutorGatePreflight($options);
        $preflight = (array) data_get($preflightPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_guarded_process_start_executor_gate_preflight', []);
        $preflightHash = (string) data_get($preflightPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_guarded_process_start_executor_gate_preflight_hash');

        $packet = [
            'status' => 'ready_for_scoped_one_shot_tick_codex_real_invoker_post_start_guarded_process_start_executor_gate_invoker_implementation',
            'packet_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-REAL-INVOKER-POST-START-GUARDED-PROCESS-START-EXECUTOR-GATE-IMPLEMENTATION-PACKET-SELF-CONSTRUCTION-0001',
            'parent_program' => 'Atlas Self-Construction OS',
            'submodule' => 'Atlas Agent Control Plane',
            'provider' => 'codex',
            'adapter' => 'codex',
            'source_codex_real_invoker_post_start_guarded_process_start_executor_gate_preflight_status' => data_get($preflight, 'status'),
            'source_codex_real_invoker_post_start_guarded_process_start_executor_gate_preflight_hash' => $preflightHash,
            'allowed_files' => [
                'app/Services/Ai/SelfConstruction/ControlPlane/AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartGuardedProcessStartExecutorGateInvoker.php',
                'tests/Feature/Ai/AtlasAiSelfConstructionAgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartGuardedProcessStartExecutorGateInvokerTest.php',
                'app/Services/Ai/SelfConstruction/Readiness/AtlasSelfConstructionReadinessService.php',
                'app/Console/Commands/AtlasAiSelfConstructionCommand.php',
                'tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php',
                'docs/engineering-knowledge-base/self-construction/agent-control-plane-contract.md',
            ],
            'tasks' => [
                ['id' => 'T1', 'title' => 'Expose one-shot scheduler Codex real invoker post-start guarded process start invoker', 'type' => 'service', 'acceptance' => 'Invoker validates post-start guarded process start input and delegates to AgentCodexRealInvokerPostStartGuardedProcessStartExecutorGate.'],
                ['id' => 'T2', 'title' => 'Preserve process-start boundary after post-start guarded process start', 'type' => 'service_logic', 'acceptance' => 'Invoker records guarded process start metadata and arms process-start metadata while actual process start, token spend, adapter execution and dispatch remain false.'],
                ['id' => 'T3', 'title' => 'Add Codex real invoker post-start guarded process start tests', 'type' => 'test', 'acceptance' => 'Tests cover successful preparation, idempotent retry, invalid operator guarded start hash, invalid process runner contract hash, missing supervised activation bridge, forbidden process-start bridge and missing provider run.'],
                ['id' => 'T4', 'title' => 'Expose Codex real invoker post-start guarded process start status', 'type' => 'command_surface', 'acceptance' => 'Command/readiness reports invoker readiness and next final process start authorization without calling the invoker in status mode.'],
            ],
            'task_count' => 4,
            'acceptance_criteria' => [
                'codex_real_invoker_post_start_guarded_process_start_executor_gate_invoker_records_guarded_process_start_without_starting_codex',
                'codex_real_invoker_post_start_guarded_process_start_executor_gate_invoker_requires_supervised_start_activation_bridge_metadata',
                'codex_real_invoker_post_start_guarded_process_start_executor_gate_invoker_requires_post_start_evidence_acceptance_bridge_metadata',
                'codex_real_invoker_post_start_guarded_process_start_executor_gate_invoker_requires_operator_guarded_start_receipt_hash',
                'codex_real_invoker_post_start_guarded_process_start_executor_gate_invoker_requires_process_runner_contract_hash',
                'codex_real_invoker_post_start_guarded_process_start_executor_gate_invoker_never_dispatches_or_spends_tokens',
            ],
            'required_gates' => [
                'php_lint_codex_real_invoker_post_start_guarded_process_start_executor_gate_invoker',
                'dedicated_codex_real_invoker_post_start_guarded_process_start_executor_gate_invoker_feature_tests',
                'generic_codex_real_invoker_post_start_guarded_process_start_executor_gate_feature_tests',
                'focused_self_construction_command_tests',
                'architecture_validate',
                'docs_health',
                'git_diff_check',
            ],
            'implementation_policy' => [
                'implementation_allowed_by_packet' => true,
                'post_start_guarded_process_start_executor_gate_call_allowed_by_future_invoker' => true,
                'codex_real_invoker_guarded_process_start_executor_allowed_by_future_invoker' => true,
                'post_start_guarded_process_start_recorded_after_future_invoker' => true,
                'process_start_armed_after_future_invoker' => true,
                'final_process_start_authorization_required_after_future_invoker' => true,
                'actual_process_start_allowed_by_packet' => false,
                'provider_process_call_allowed_by_packet' => false,
                'adapter_invocation_allowed_by_packet' => false,
                'adapter_execution_allowed_by_packet' => false,
                'dispatch_allowed_by_packet' => false,
                'token_spend_allowed_by_packet' => false,
                'self_programming_allowed_by_packet' => false,
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_guarded_process_start_executor_gate_invoker_service',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_guarded_process_start_executor_gate_implementation_packet.v1',
            'status' => (string) $packet['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_guarded_process_start_executor_gate_implementation_packet',
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
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_guarded_process_start_executor_gate_implementation_packet' => $packet,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_guarded_process_start_executor_gate_implementation_packet_hash' => $this->stableHash($packet),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_guarded_process_start_executor_gate_implementation_packet_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_guarded_process_start_executor_gate_implementation_packet_does_not_call_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_guarded_process_start_executor_gate_implementation_packet_does_not_execute_adapter',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_guarded_process_start_executor_gate_implementation_packet_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_guarded_process_start_executor_gate_implementation_packet_does_not_dispatch_work',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_guarded_process_start_executor_gate_implementation_packet_does_not_enable_self_programming',
            ],
            'human_summary' => 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start guarded process start executor gate implementation packet is ready; it scopes guarded process start before final process start authorization.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerExecutorPlanStatus(array $options = []): array
    {
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerTableReady = Schema::hasTable('atlas_ledger_events');
        $executorPlanReady = class_exists(AgentCodexRealInvokerExecutorPlan::class)
            && method_exists(AgentCodexRealInvokerExecutorPlan::class, 'prepareExecutorPlan');
        $invokerReady = class_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerExecutorPlanInvoker::class)
            && method_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerExecutorPlanInvoker::class, 'prepareCodexRealInvokerExecutorPlan');

        $preparedRunsQuery = $runsTableReady
            ? AtlasSelfConstructionAgentRun::query()
                ->whereNotNull('metadata->codex_real_invoker_executor_plan->real_invoker_executor_plan_id')
            : null;
        $latestPreparedRun = $preparedRunsQuery === null
            ? null
            : (clone $preparedRunsQuery)->latest('updated_at')->first();

        $statusReady = $runsTableReady && $ledgerTableReady && $executorPlanReady && $invokerReady;
        $status = [
            'status' => $statusReady ? 'one_shot_tick_codex_real_invoker_executor_plan_service_ready' : 'blocked',
            'provider' => 'codex',
            'adapter' => 'codex',
            'invoker_service' => AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerExecutorPlanInvoker::class,
            'invoker_service_ready' => $invokerReady,
            'invoker_canonical_method' => 'prepareCodexRealInvokerExecutorPlan',
            'generic_executor_plan_service' => AgentCodexRealInvokerExecutorPlan::class,
            'generic_executor_plan_service_ready' => $executorPlanReady,
            'generic_executor_plan_canonical_method' => 'prepareExecutorPlan',
            'agent_runs_table_ready' => $runsTableReady,
            'ledger_table_ready' => $ledgerTableReady,
            'codex_real_invoker_executor_plan_prepared_run_count' => $preparedRunsQuery === null ? null : (clone $preparedRunsQuery)->count(),
            'latest_codex_real_invoker_executor_plan_prepared_run' => $latestPreparedRun instanceof AtlasSelfConstructionAgentRun
                ? [
                    'agent_run_id' => $latestPreparedRun->id,
                    'run_key' => $latestPreparedRun->run_key,
                    'packet_id' => $latestPreparedRun->packet_id,
                    'provider' => $latestPreparedRun->provider,
                    'status' => $latestPreparedRun->status,
                    'real_invoker_executor_plan_id' => data_get($latestPreparedRun->metadata, 'codex_real_invoker_executor_plan.real_invoker_executor_plan_id'),
                    'real_invoker_implementation_boundary_id' => data_get($latestPreparedRun->metadata, 'codex_real_invoker_executor_plan.real_invoker_implementation_boundary_id'),
                    'signed_real_invoker_release_id' => data_get($latestPreparedRun->metadata, 'codex_real_invoker_executor_plan.signed_real_invoker_release_id'),
                    'real_invoker_release_preflight_id' => data_get($latestPreparedRun->metadata, 'codex_real_invoker_executor_plan.real_invoker_release_preflight_id'),
                    'dry_run_id' => data_get($latestPreparedRun->metadata, 'codex_real_invoker_executor_plan.dry_run_id'),
                    'codex_execution_id' => data_get($latestPreparedRun->metadata, 'codex_real_invoker_executor_plan.codex_execution_id'),
                    'real_invoker_executor_plan_prepared' => data_get($latestPreparedRun->metadata, 'codex_real_invoker_executor_plan.real_invoker_executor_plan_prepared'),
                    'fresh_release_required_before_start' => data_get($latestPreparedRun->metadata, 'codex_real_invoker_executor_plan.fresh_release_required_before_start'),
                    'executor_enabled' => data_get($latestPreparedRun->metadata, 'codex_real_invoker_executor_plan.executor_enabled'),
                    'external_process_started' => data_get($latestPreparedRun->metadata, 'codex_real_invoker_executor_plan.external_process_started'),
                    'token_spend_allowed' => data_get($latestPreparedRun->metadata, 'codex_real_invoker_executor_plan.token_spend_allowed'),
                ]
                : null,
            'runtime_policy' => [
                'status_projection_is_read_only' => true,
                'invoker_may_prepare_codex_real_invoker_executor_plan_when_called_with_boundary_input' => true,
                'real_invoker_executor_plan_is_not_real_process_invocation' => true,
                'real_invoker_executor_plan_keeps_executor_disabled' => true,
                'codex_real_invoker_executor_fresh_release_gate_allowed_here' => false,
                'provider_external_process_start_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'self_programming_allowed_here' => false,
            ],
            'next_required_slice' => $statusReady
                ? 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_executor_fresh_release_gate_contract'
                : 'repair_one_shot_scheduler_tick_codex_real_invoker_executor_plan_service_blockers',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_plan_status.v1',
            'status' => (string) $status['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_plan_status',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_executor_plan_allowed' => false,
            'executor_enabled' => false,
            'adapter_execution_allowed' => false,
            'provider_start_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_plan_status' => $status,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_plan_status_hash' => $this->stableHash($status),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_plan_status_does_not_call_executor_plan',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_plan_status_does_not_enable_executor',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_plan_status_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_plan_status_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_plan_status_does_not_enable_self_programming',
            ],
            'human_summary' => $statusReady
                ? 'Automatic dispatch scheduler one-shot tick Codex real invoker executor plan service is ready and inspectable; status remains read-only and fresh release is still separate.'
                : 'Automatic dispatch scheduler one-shot tick Codex real invoker executor plan service is blocked until invoker, generic executor plan and ledger are ready.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerExecutorFreshReleaseGateStatus(array $options = []): array
    {
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerTableReady = Schema::hasTable('atlas_ledger_events');
        $freshReleaseReady = class_exists(AgentCodexRealInvokerExecutorFreshReleaseGate::class)
            && method_exists(AgentCodexRealInvokerExecutorFreshReleaseGate::class, 'authorizeFreshRelease');
        $invokerReady = class_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerExecutorFreshReleaseGateInvoker::class)
            && method_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerExecutorFreshReleaseGateInvoker::class, 'authorizeCodexRealInvokerExecutorFreshRelease');

        $authorizedRunsQuery = $runsTableReady
            ? AtlasSelfConstructionAgentRun::query()
                ->whereNotNull('metadata->codex_real_invoker_executor_fresh_release->real_invoker_executor_fresh_release_id')
            : null;
        $latestAuthorizedRun = $authorizedRunsQuery === null
            ? null
            : (clone $authorizedRunsQuery)->latest('updated_at')->first();

        $statusReady = $runsTableReady && $ledgerTableReady && $freshReleaseReady && $invokerReady;
        $status = [
            'status' => $statusReady ? 'one_shot_tick_codex_real_invoker_executor_fresh_release_gate_service_ready' : 'blocked',
            'provider' => 'codex',
            'adapter' => 'codex',
            'invoker_service' => AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerExecutorFreshReleaseGateInvoker::class,
            'invoker_service_ready' => $invokerReady,
            'invoker_canonical_method' => 'authorizeCodexRealInvokerExecutorFreshRelease',
            'generic_fresh_release_gate_service' => AgentCodexRealInvokerExecutorFreshReleaseGate::class,
            'generic_fresh_release_gate_service_ready' => $freshReleaseReady,
            'generic_fresh_release_gate_canonical_method' => 'authorizeFreshRelease',
            'agent_runs_table_ready' => $runsTableReady,
            'ledger_table_ready' => $ledgerTableReady,
            'codex_real_invoker_executor_fresh_release_authorized_run_count' => $authorizedRunsQuery === null ? null : (clone $authorizedRunsQuery)->count(),
            'latest_codex_real_invoker_executor_fresh_release_authorized_run' => $latestAuthorizedRun instanceof AtlasSelfConstructionAgentRun
                ? [
                    'agent_run_id' => $latestAuthorizedRun->id,
                    'run_key' => $latestAuthorizedRun->run_key,
                    'packet_id' => $latestAuthorizedRun->packet_id,
                    'provider' => $latestAuthorizedRun->provider,
                    'status' => $latestAuthorizedRun->status,
                    'real_invoker_executor_fresh_release_id' => data_get($latestAuthorizedRun->metadata, 'codex_real_invoker_executor_fresh_release.real_invoker_executor_fresh_release_id'),
                    'real_invoker_executor_plan_id' => data_get($latestAuthorizedRun->metadata, 'codex_real_invoker_executor_fresh_release.real_invoker_executor_plan_id'),
                    'real_invoker_implementation_boundary_id' => data_get($latestAuthorizedRun->metadata, 'codex_real_invoker_executor_fresh_release.real_invoker_implementation_boundary_id'),
                    'signed_real_invoker_release_id' => data_get($latestAuthorizedRun->metadata, 'codex_real_invoker_executor_fresh_release.signed_real_invoker_release_id'),
                    'real_invoker_release_preflight_id' => data_get($latestAuthorizedRun->metadata, 'codex_real_invoker_executor_fresh_release.real_invoker_release_preflight_id'),
                    'dry_run_id' => data_get($latestAuthorizedRun->metadata, 'codex_real_invoker_executor_fresh_release.dry_run_id'),
                    'codex_execution_id' => data_get($latestAuthorizedRun->metadata, 'codex_real_invoker_executor_fresh_release.codex_execution_id'),
                    'real_invoker_executor_fresh_release_authorized' => data_get($latestAuthorizedRun->metadata, 'codex_real_invoker_executor_fresh_release.real_invoker_executor_fresh_release_authorized'),
                    'executor_enabled' => data_get($latestAuthorizedRun->metadata, 'codex_real_invoker_executor_fresh_release.executor_enabled'),
                    'external_process_started' => data_get($latestAuthorizedRun->metadata, 'codex_real_invoker_executor_fresh_release.external_process_started'),
                    'token_spend_allowed' => data_get($latestAuthorizedRun->metadata, 'codex_real_invoker_executor_fresh_release.token_spend_allowed'),
                ]
                : null,
            'runtime_policy' => [
                'status_projection_is_read_only' => true,
                'invoker_may_authorize_codex_real_invoker_executor_fresh_release_when_called_with_plan_input' => true,
                'real_invoker_executor_fresh_release_is_not_real_process_invocation' => true,
                'real_invoker_executor_fresh_release_keeps_executor_disabled' => true,
                'codex_real_invoker_executor_enablement_gate_allowed_here' => false,
                'provider_external_process_start_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'self_programming_allowed_here' => false,
            ],
            'next_required_slice' => $statusReady
                ? 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_executor_enablement_gate_contract'
                : 'repair_one_shot_scheduler_tick_codex_real_invoker_executor_fresh_release_gate_service_blockers',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_fresh_release_gate_status.v1',
            'status' => (string) $status['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_fresh_release_gate_status',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_executor_fresh_release_gate_allowed' => false,
            'executor_enabled' => false,
            'adapter_execution_allowed' => false,
            'provider_start_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_fresh_release_gate_status' => $status,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_fresh_release_gate_status_hash' => $this->stableHash($status),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_fresh_release_gate_status_does_not_call_fresh_release_gate',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_fresh_release_gate_status_does_not_enable_executor',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_fresh_release_gate_status_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_fresh_release_gate_status_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_fresh_release_gate_status_does_not_enable_self_programming',
            ],
            'human_summary' => $statusReady
                ? 'Automatic dispatch scheduler one-shot tick Codex real invoker executor fresh release gate service is ready and inspectable; status remains read-only and executor enablement is still separate.'
                : 'Automatic dispatch scheduler one-shot tick Codex real invoker executor fresh release gate service is blocked until invoker, generic fresh release gate and ledger are ready.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartSupervisedStartActivationGateImplementationPacket(array $options = []): array
    {
        $preflightPayload = $this->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartSupervisedStartActivationGatePreflight($options);
        $preflight = (array) data_get($preflightPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_supervised_start_activation_gate_preflight', []);
        $preflightHash = (string) data_get($preflightPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_supervised_start_activation_gate_preflight_hash');

        $packet = [
            'status' => 'ready_for_scoped_one_shot_tick_codex_real_invoker_post_start_supervised_start_activation_gate_invoker_implementation',
            'packet_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-REAL-INVOKER-POST-START-SUPERVISED-START-ACTIVATION-GATE-IMPLEMENTATION-PACKET-SELF-CONSTRUCTION-0001',
            'parent_program' => 'Atlas Self-Construction OS',
            'submodule' => 'Atlas Agent Control Plane',
            'provider' => 'codex',
            'adapter' => 'codex',
            'source_codex_real_invoker_post_start_supervised_start_activation_gate_preflight_status' => data_get($preflight, 'status'),
            'source_codex_real_invoker_post_start_supervised_start_activation_gate_preflight_hash' => $preflightHash,
            'allowed_files' => [
                'app/Services/Ai/SelfConstruction/ControlPlane/AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartSupervisedStartActivationGateInvoker.php',
                'tests/Feature/Ai/AtlasAiSelfConstructionAgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartSupervisedStartActivationGateInvokerTest.php',
                'app/Services/Ai/SelfConstruction/Readiness/AtlasSelfConstructionReadinessService.php',
                'app/Console/Commands/AtlasAiSelfConstructionCommand.php',
                'tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php',
                'docs/engineering-knowledge-base/self-construction/agent-control-plane-contract.md',
            ],
            'tasks' => [
                ['id' => 'T1', 'title' => 'Expose one-shot scheduler Codex real invoker post-start supervised start activation invoker', 'type' => 'service', 'acceptance' => 'Invoker validates post-start activation input and delegates to AgentCodexRealInvokerPostStartSupervisedStartActivationGate.'],
                ['id' => 'T2', 'title' => 'Preserve process-start boundary after post-start activation', 'type' => 'service_logic', 'acceptance' => 'Invoker records activation metadata and arms process-start metadata while real process start, token spend, adapter execution and dispatch remain false.'],
                ['id' => 'T3', 'title' => 'Add Codex real invoker post-start supervised start activation tests', 'type' => 'test', 'acceptance' => 'Tests cover successful activation, idempotent retry, invalid process guard hash, missing executor enablement bridge, forbidden process-start bridge and missing provider run.'],
                ['id' => 'T4', 'title' => 'Expose Codex real invoker post-start supervised start activation status', 'type' => 'command_surface', 'acceptance' => 'Command/readiness reports invoker readiness and next guarded process start executor without calling the invoker in status mode.'],
            ],
            'task_count' => 4,
            'acceptance_criteria' => [
                'codex_real_invoker_post_start_supervised_start_activation_gate_invoker_records_activation_without_starting_codex',
                'codex_real_invoker_post_start_supervised_start_activation_gate_invoker_requires_executor_enablement_bridge_metadata',
                'codex_real_invoker_post_start_supervised_start_activation_gate_invoker_requires_post_start_evidence_acceptance_bridge_metadata',
                'codex_real_invoker_post_start_supervised_start_activation_gate_invoker_requires_process_start_guard_hash',
                'codex_real_invoker_post_start_supervised_start_activation_gate_invoker_never_dispatches_or_spends_tokens',
            ],
            'required_gates' => [
                'php_lint_codex_real_invoker_post_start_supervised_start_activation_gate_invoker',
                'dedicated_codex_real_invoker_post_start_supervised_start_activation_gate_invoker_feature_tests',
                'generic_codex_real_invoker_post_start_supervised_start_activation_gate_feature_tests',
                'focused_self_construction_command_tests',
                'architecture_validate',
                'docs_health',
                'git_diff_check',
            ],
            'implementation_policy' => [
                'implementation_allowed_by_packet' => true,
                'post_start_supervised_start_activation_gate_call_allowed_by_future_invoker' => true,
                'codex_real_invoker_supervised_start_activation_gate_allowed_by_future_invoker' => true,
                'post_start_supervised_start_activation_recorded_after_future_invoker' => true,
                'process_start_armed_after_future_invoker' => true,
                'guarded_process_start_executor_required_after_future_invoker' => true,
                'actual_process_start_allowed_by_packet' => false,
                'provider_process_call_allowed_by_packet' => false,
                'adapter_invocation_allowed_by_packet' => false,
                'adapter_execution_allowed_by_packet' => false,
                'dispatch_allowed_by_packet' => false,
                'token_spend_allowed_by_packet' => false,
                'self_programming_allowed_by_packet' => false,
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_supervised_start_activation_gate_invoker_service',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_supervised_start_activation_gate_implementation_packet.v1',
            'status' => (string) $packet['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_supervised_start_activation_gate_implementation_packet',
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
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_supervised_start_activation_gate_implementation_packet' => $packet,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_supervised_start_activation_gate_implementation_packet_hash' => $this->stableHash($packet),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_supervised_start_activation_gate_implementation_packet_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_supervised_start_activation_gate_implementation_packet_does_not_call_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_supervised_start_activation_gate_implementation_packet_does_not_execute_adapter',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_supervised_start_activation_gate_implementation_packet_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_supervised_start_activation_gate_implementation_packet_does_not_dispatch_work',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_supervised_start_activation_gate_implementation_packet_does_not_enable_self_programming',
            ],
            'human_summary' => 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start supervised start activation gate implementation packet is ready; it scopes activation before guarded process start.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickReleaseReceiptPersistenceStatus(array $options = []): array
    {
        $table = 'atlas_self_construction_agent_dispatch_receipts';
        $tableReady = Schema::hasTable($table);
        $writerReady = class_exists(AgentAutomaticDispatchSchedulerOneShotTickReleaseReceiptPersistenceWriter::class);
        $ledgerReady = Schema::hasTable('atlas_ledger_events');
        $receiptHash = strtolower(trim((string) ($options['receipt_hash'] ?? '')));
        $receiptHashValid = $receiptHash === '' || preg_match('/^[a-f0-9]{64}$/', $receiptHash) === 1;

        $query = $tableReady
            ? AtlasSelfConstructionAgentDispatchReceipt::query()
                ->where('decision', 'approve_scheduler_claim_and_receipt_once')
                ->where('status', 'release_authorized_pending_one_shot_tick')
            : null;

        if ($query !== null && $receiptHash !== '' && $receiptHashValid) {
            $query->where('receipt_hash', $receiptHash);
        }

        $latestReceipt = $query === null
            ? null
            : (clone $query)->latest('created_at')->first();

        $status = [
            'status' => $tableReady && $writerReady && $ledgerReady && $receiptHashValid
                ? 'release_receipt_persistence_writer_service_ready'
                : 'blocked',
            'writer_service' => AgentAutomaticDispatchSchedulerOneShotTickReleaseReceiptPersistenceWriter::class,
            'writer_service_ready' => $writerReady,
            'dispatch_receipts_table' => $table,
            'dispatch_receipts_table_ready' => $tableReady,
            'ledger_table_ready' => $ledgerReady,
            'receipt_hash_filter' => $receiptHashValid && $receiptHash !== '' ? $receiptHash : null,
            'receipt_hash_filter_valid' => $receiptHashValid,
            'release_receipt_count' => $query === null ? null : (clone $query)->count(),
            'latest_release_receipt' => $latestReceipt instanceof AtlasSelfConstructionAgentDispatchReceipt
                ? [
                    'receipt_id' => $latestReceipt->id,
                    'receipt_key' => $latestReceipt->receipt_key,
                    'receipt_hash' => $latestReceipt->receipt_hash,
                    'packet_id' => $latestReceipt->packet_id,
                    'provider' => $latestReceipt->provider,
                    'signed_by' => $latestReceipt->signed_by,
                    'signed_at' => $latestReceipt->signed_at?->toIso8601String(),
                    'expires_at' => $latestReceipt->expires_at?->toIso8601String(),
                    'created_at' => $latestReceipt->created_at?->toIso8601String(),
                ]
                : null,
            'runtime_policy' => [
                'status_projection_is_read_only' => true,
                'release_receipt_persistence_allowed_here' => false,
                'claim_allowed_here' => false,
                'dispatch_receipt_write_allowed_here' => false,
                'provider_start_allowed_here' => false,
                'adapter_invocation_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'self_programming_allowed_here' => false,
            ],
            'next_required_slice' => $tableReady && $writerReady && $ledgerReady && $receiptHashValid
                ? 'activate_signed_one_shot_scheduler_tick_mutating_writer_release_preflight'
                : 'repair_one_shot_scheduler_tick_release_receipt_persistence_writer_service_blockers',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_release_receipt_persistence_status.v1',
            'status' => (string) $status['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_release_receipt_persistence_status',
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
            'agent_automatic_dispatch_scheduler_one_shot_tick_release_receipt_persistence_status' => $status,
            'agent_automatic_dispatch_scheduler_one_shot_tick_release_receipt_persistence_status_hash' => $this->stableHash($status),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_release_receipt_persistence_status_does_not_accept_signatures',
                'agent_automatic_dispatch_scheduler_one_shot_tick_release_receipt_persistence_status_does_not_persist_release_receipts',
                'agent_automatic_dispatch_scheduler_one_shot_tick_release_receipt_persistence_status_does_not_claim_wakeup_items',
                'agent_automatic_dispatch_scheduler_one_shot_tick_release_receipt_persistence_status_does_not_write_dispatch_receipts',
                'agent_automatic_dispatch_scheduler_one_shot_tick_release_receipt_persistence_status_does_not_start_providers',
                'agent_automatic_dispatch_scheduler_one_shot_tick_release_receipt_persistence_status_does_not_enable_self_programming',
            ],
            'human_summary' => $tableReady && $writerReady && $ledgerReady && $receiptHashValid
                ? 'Automatic dispatch scheduler one-shot tick release receipt persistence writer service is ready and inspectable without mutating runtime.'
                : 'Automatic dispatch scheduler one-shot tick release receipt persistence writer service is blocked until storage, ledger and writer prerequisites are ready.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexSupervisedStartExecutorReleaseContract(array $options = []): array
    {
        $releaseStatusPayload = $this->agentAutomaticDispatchSchedulerOneShotTickCodexProcessStartReleaseStatus($options);
        $releaseStatus = (array) data_get($releaseStatusPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_process_start_release_status', []);
        $supervisedPayload = $this->agentCodexSupervisedStartExecutorContractTemplate($options);
        $supervised = (array) data_get($supervisedPayload, 'codex_supervised_start_executor_contract_template', []);

        $contract = [
            'status' => 'one_shot_tick_codex_supervised_start_executor_release_contract_ready',
            'contract_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-SUPERVISED-START-EXECUTOR-RELEASE-CONTRACT-SELF-CONSTRUCTION-0001',
            'parent_program' => 'Atlas Self-Construction OS',
            'submodule' => 'Atlas Agent Control Plane',
            'provider' => 'codex',
            'adapter' => 'codex',
            'source_codex_process_start_release_status' => data_get($releaseStatus, 'status'),
            'source_codex_process_start_release_status_hash' => data_get($releaseStatusPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_process_start_release_status_hash'),
            'source_codex_supervised_start_executor_contract_status' => data_get($supervisedPayload, 'status'),
            'source_codex_supervised_start_executor_contract_hash' => data_get($supervisedPayload, 'codex_supervised_start_executor_contract_template_hash'),
            'release_boundary' => [
                'canonical_executor' => AgentCodexSupervisedStartExecutor::class,
                'canonical_executor_method' => 'prepareSupervisedStart',
                'scheduler_invoker' => AgentAutomaticDispatchSchedulerOneShotTickCodexSupervisedStartExecutorInvoker::class,
                'scheduler_invoker_method' => 'prepareCodexSupervisedStart',
                'gate_effect' => 'prepare_supervised_codex_start_metadata_without_process_spawn',
                'external_process_started_by_executor' => false,
                'provider_started_by_executor' => false,
                'adapter_execution_allowed_by_executor' => false,
                'token_spend_allowed_by_executor' => false,
                'required_process_start_release_status_before_executor' => 'authorized_pending_supervised_start_executor',
                'prepared_status_after_executor' => 'prepared_pending_process_spawn_enablement_contract',
                'idempotency_key' => 'supervised_start_id',
            ],
            'required_input_fields_for_future_invoker' => [
                'run_key',
                'codex_execution_id',
                'process_start_release_id',
                'supervised_start_id',
                'operator_release_receipt_hash',
                'stdout_stderr_sanitizer_hash',
                'ready_probe_plan_hash',
                'rollback_plan_hash',
                'actor',
                'session',
                'reason',
            ],
            'allowed_future_mutations' => [
                'write_codex_supervised_start_metadata_on_agent_run',
                'append_codex_supervised_start_prepared_evidence_event',
            ],
            'forbidden_even_after_contract' => [
                'spawn_codex_process',
                'call_codex_cli_or_codex_app',
                'spend_provider_tokens',
                'mark_run_running_or_terminal',
                'mark_packet_completed',
                'merge_work_products',
                'enable_self_programming',
            ],
            'handoff_policy' => [
                'supervised_start_executor_is_preparation_not_process_spawn' => true,
                'process_spawn_enablement_requires_separate_signed_release' => true,
                'stdout_stderr_sanitizer_hash_required' => true,
                'ready_probe_plan_hash_required' => true,
                'rollback_plan_hash_required' => true,
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_supervised_start_executor_preflight',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_supervised_start_executor_release_contract.v1',
            'status' => (string) $contract['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_supervised_start_executor_release_contract',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_supervised_start_allowed' => false,
            'adapter_execution_allowed' => false,
            'provider_start_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_supervised_start_executor_release_contract' => $contract,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_supervised_start_executor_release_contract_hash' => $this->stableHash($contract),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_supervised_start_executor_release_contract_does_not_call_supervised_executor',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_supervised_start_executor_release_contract_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_supervised_start_executor_release_contract_does_not_call_adapters',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_supervised_start_executor_release_contract_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_supervised_start_executor_release_contract_does_not_enable_self_programming',
            ],
            'human_summary' => 'Automatic dispatch scheduler one-shot tick Codex supervised start executor release contract is ready; it prepares the supervised shell but still cannot spawn Codex.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerGuardedProcessStartExecutorStatus(array $options = []): array
    {
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerTableReady = Schema::hasTable('atlas_ledger_events');
        $guardedReady = class_exists(AgentCodexRealInvokerGuardedProcessStartExecutor::class)
            && method_exists(AgentCodexRealInvokerGuardedProcessStartExecutor::class, 'prepareGuardedStart');
        $invokerReady = class_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerGuardedProcessStartExecutorInvoker::class)
            && method_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerGuardedProcessStartExecutorInvoker::class, 'prepareCodexRealInvokerGuardedProcessStart');

        $guardedRunsQuery = $runsTableReady
            ? AtlasSelfConstructionAgentRun::query()
                ->whereNotNull('metadata->codex_real_invoker_guarded_process_start->real_invoker_guarded_process_start_id')
            : null;
        $latestGuardedRun = $guardedRunsQuery === null
            ? null
            : (clone $guardedRunsQuery)->latest('updated_at')->first();

        $statusReady = $runsTableReady && $ledgerTableReady && $guardedReady && $invokerReady;
        $status = [
            'status' => $statusReady ? 'one_shot_tick_codex_real_invoker_guarded_process_start_executor_service_ready' : 'blocked',
            'provider' => 'codex',
            'adapter' => 'codex',
            'invoker_service' => AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerGuardedProcessStartExecutorInvoker::class,
            'invoker_service_ready' => $invokerReady,
            'invoker_canonical_method' => 'prepareCodexRealInvokerGuardedProcessStart',
            'generic_guarded_process_start_executor_service' => AgentCodexRealInvokerGuardedProcessStartExecutor::class,
            'generic_guarded_process_start_executor_service_ready' => $guardedReady,
            'generic_guarded_process_start_executor_canonical_method' => 'prepareGuardedStart',
            'agent_runs_table_ready' => $runsTableReady,
            'ledger_table_ready' => $ledgerTableReady,
            'codex_real_invoker_guarded_process_start_prepared_run_count' => $guardedRunsQuery === null ? null : (clone $guardedRunsQuery)->count(),
            'latest_codex_real_invoker_guarded_process_start_run' => $latestGuardedRun instanceof AtlasSelfConstructionAgentRun
                ? [
                    'agent_run_id' => $latestGuardedRun->id,
                    'run_key' => $latestGuardedRun->run_key,
                    'packet_id' => $latestGuardedRun->packet_id,
                    'provider' => $latestGuardedRun->provider,
                    'status' => $latestGuardedRun->status,
                    'real_invoker_guarded_process_start_id' => data_get($latestGuardedRun->metadata, 'codex_real_invoker_guarded_process_start.real_invoker_guarded_process_start_id'),
                    'real_invoker_supervised_start_activation_id' => data_get($latestGuardedRun->metadata, 'codex_real_invoker_guarded_process_start.real_invoker_supervised_start_activation_id'),
                    'codex_execution_id' => data_get($latestGuardedRun->metadata, 'codex_real_invoker_guarded_process_start.codex_execution_id'),
                    'real_invoker_guarded_process_start_prepared' => data_get($latestGuardedRun->metadata, 'codex_real_invoker_guarded_process_start.real_invoker_guarded_process_start_prepared'),
                    'executor_enabled' => data_get($latestGuardedRun->metadata, 'codex_real_invoker_guarded_process_start.executor_enabled'),
                    'process_start_armed' => data_get($latestGuardedRun->metadata, 'codex_real_invoker_guarded_process_start.process_start_armed'),
                    'actual_process_start_allowed' => data_get($latestGuardedRun->metadata, 'codex_real_invoker_guarded_process_start.actual_process_start_allowed'),
                    'external_process_started' => data_get($latestGuardedRun->metadata, 'codex_real_invoker_guarded_process_start.external_process_started'),
                    'token_spend_allowed' => data_get($latestGuardedRun->metadata, 'codex_real_invoker_guarded_process_start.token_spend_allowed'),
                ]
                : null,
            'runtime_policy' => [
                'status_projection_is_read_only' => true,
                'invoker_may_prepare_codex_real_invoker_guarded_process_start_when_called_with_activation_input' => true,
                'real_invoker_guarded_process_start_is_not_actual_process_invocation' => true,
                'real_invoker_guarded_process_start_keeps_external_process_stopped' => true,
                'codex_real_invoker_final_process_start_authorization_allowed_here' => false,
                'provider_external_process_start_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'self_programming_allowed_here' => false,
            ],
            'next_required_slice' => $statusReady
                ? 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_final_process_start_authorization_gate_contract'
                : 'repair_one_shot_scheduler_tick_codex_real_invoker_guarded_process_start_executor_service_blockers',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_guarded_process_start_executor_status.v1',
            'status' => (string) $status['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_guarded_process_start_executor_status',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_guarded_process_start_executor_allowed' => false,
            'executor_enabled' => false,
            'process_start_armed' => false,
            'actual_process_start_allowed' => false,
            'adapter_execution_allowed' => false,
            'provider_start_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_guarded_process_start_executor_status' => $status,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_guarded_process_start_executor_status_hash' => $this->stableHash($status),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_guarded_process_start_executor_status_does_not_call_guarded_process_start_executor',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_guarded_process_start_executor_status_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_guarded_process_start_executor_status_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_guarded_process_start_executor_status_does_not_enable_self_programming',
            ],
            'human_summary' => $statusReady
                ? 'Automatic dispatch scheduler one-shot tick Codex real invoker guarded process start executor service is ready and inspectable; status remains read-only and final process start authorization is still separate.'
                : 'Automatic dispatch scheduler one-shot tick Codex real invoker guarded process start executor service is blocked until invoker, generic guarded executor and ledger are ready.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickProviderStartDriverReleaseContract(array $options = []): array
    {
        $receiptUseStatusPayload = $this->agentAutomaticDispatchSchedulerOneShotTickDispatchReceiptUseStatus($options);
        $receiptUseStatus = (array) data_get($receiptUseStatusPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_dispatch_receipt_use_status', []);
        $providerStartPayload = $this->agentDispatchExecutorProviderStartDriverPreflight($options);
        $providerStart = (array) data_get($providerStartPayload, 'dispatch_executor_provider_start_driver_preflight', []);

        $contract = [
            'status' => 'one_shot_tick_provider_start_driver_release_contract_ready',
            'contract_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-PROVIDER-START-DRIVER-RELEASE-CONTRACT-SELF-CONSTRUCTION-0001',
            'parent_program' => 'Atlas Self-Construction OS',
            'submodule' => 'Atlas Agent Control Plane',
            'workspace_id' => 'FORGE-WORKSPACE-ATLAS-SELF-CONSTRUCTION-0001',
            'source_dispatch_receipt_use_status' => data_get($receiptUseStatus, 'status'),
            'source_dispatch_receipt_use_status_hash' => data_get($receiptUseStatusPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_dispatch_receipt_use_status_hash'),
            'source_provider_start_driver_preflight_status' => data_get($providerStartPayload, 'status'),
            'source_provider_start_driver_preflight_hash' => data_get($providerStartPayload, 'dispatch_executor_provider_start_driver_preflight_hash'),
            'release_boundary' => [
                'canonical_driver' => AgentDispatchExecutorProviderStartDriver::class,
                'canonical_driver_method' => 'startProviderOnce',
                'driver_effect' => 'prepare_pre_start_guarded_agent_run_and_pre_start_heartbeat',
                'provider_external_process_started_by_driver' => false,
                'adapter_invocation_allowed_by_driver' => false,
                'required_receipt_status_before_driver' => 'used_pending_provider_start',
                'required_sandbox_binding_status' => 'active_pending_provider_start',
                'required_run_status_after_driver' => 'pre_start_guarded',
                'idempotency_key' => 'provider_start_attempt_id',
            ],
            'required_input_fields_for_future_invoker' => [
                'receipt_hash',
                'executor_contract_hash',
                'executor_release_authorization_hash',
                'sandbox_binding_key',
                'provider_start_attempt_id',
                'packet_id',
                'provider',
                'adapter',
                'command',
                'cwd',
                'actor',
                'session',
                'max_runtime_minutes',
                'max_cost_usd',
                'reason',
            ],
            'allowed_future_mutations' => [
                'create_or_reuse_pre_start_guarded_agent_run',
                'write_pre_start_heartbeat',
                'append_provider_start_prepared_evidence_event',
            ],
            'forbidden_even_after_driver' => [
                'spawn_provider_process',
                'invoke_provider_adapter',
                'spend_provider_tokens',
                'mark_packet_completed',
                'merge_work_products',
                'enable_self_programming',
            ],
            'handoff_policy' => [
                'provider_start_driver_is_pre_start_guard_not_external_process_start' => true,
                'adapter_invocation_requires_separate_boundary_contract' => true,
                'provider_specific_execution_requires_future_signed_release' => true,
                'post_start_liveness_is_not_trusted_without_evidence_bridge' => true,
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_provider_start_driver_preflight',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_provider_start_driver_release_contract.v1',
            'status' => (string) $contract['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_provider_start_driver_release_contract',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'provider_start_driver_allowed' => false,
            'provider_start_allowed' => false,
            'adapter_invocation_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_provider_start_driver_release_contract' => $contract,
            'agent_automatic_dispatch_scheduler_one_shot_tick_provider_start_driver_release_contract_hash' => $this->stableHash($contract),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_provider_start_driver_release_contract_does_not_call_provider_start_driver',
                'agent_automatic_dispatch_scheduler_one_shot_tick_provider_start_driver_release_contract_does_not_start_providers',
                'agent_automatic_dispatch_scheduler_one_shot_tick_provider_start_driver_release_contract_does_not_call_adapters',
                'agent_automatic_dispatch_scheduler_one_shot_tick_provider_start_driver_release_contract_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_provider_start_driver_release_contract_does_not_enable_self_programming',
            ],
            'human_summary' => 'Automatic dispatch scheduler one-shot tick provider start driver release contract is ready; it defines the future pre-start guarded run boundary while still forbidding external provider start.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerExecutorPlanImplementationPacket(array $options = []): array
    {
        $preflightPayload = $this->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerExecutorPlanPreflight($options);
        $preflight = (array) data_get($preflightPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_plan_preflight', []);
        $preflightHash = (string) data_get($preflightPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_plan_preflight_hash');

        $packet = [
            'status' => 'ready_for_scoped_one_shot_tick_codex_real_invoker_executor_plan_invoker_implementation',
            'packet_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-REAL-INVOKER-EXECUTOR-PLAN-IMPLEMENTATION-PACKET-SELF-CONSTRUCTION-0001',
            'parent_program' => 'Atlas Self-Construction OS',
            'submodule' => 'Atlas Agent Control Plane',
            'provider' => 'codex',
            'adapter' => 'codex',
            'source_codex_real_invoker_executor_plan_preflight_status' => data_get($preflight, 'status'),
            'source_codex_real_invoker_executor_plan_preflight_hash' => $preflightHash,
            'allowed_files' => [
                'app/Services/Ai/SelfConstruction/ControlPlane/AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerExecutorPlanInvoker.php',
                'tests/Feature/Ai/AtlasAiSelfConstructionAgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerExecutorPlanInvokerTest.php',
                'app/Services/Ai/SelfConstruction/Readiness/AtlasSelfConstructionReadinessService.php',
                'app/Console/Commands/AtlasAiSelfConstructionCommand.php',
                'tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php',
                'docs/engineering-knowledge-base/self-construction/agent-control-plane-contract.md',
            ],
            'tasks' => [
                ['id' => 'T1', 'title' => 'Expose one-shot scheduler Codex real invoker executor plan invoker', 'type' => 'service', 'acceptance' => 'Invoker validates executor plan input and delegates to AgentCodexRealInvokerExecutorPlan.'],
                ['id' => 'T2', 'title' => 'Preserve disabled executor boundary', 'type' => 'service_logic', 'acceptance' => 'Invoker records executor plan while executor enablement, actual process start, token spend and self-programming remain false.'],
                ['id' => 'T3', 'title' => 'Add Codex real invoker executor plan invoker tests', 'type' => 'test', 'acceptance' => 'Tests cover successful plan, idempotent retry, invalid executor binary hash rejection, missing boundary metadata and duplicate plan rejection.'],
                ['id' => 'T4', 'title' => 'Expose Codex real invoker executor plan status', 'type' => 'command_surface', 'acceptance' => 'Command/readiness reports invoker readiness and next fresh release gate without calling the invoker in status mode.'],
            ],
            'task_count' => 4,
            'acceptance_criteria' => [
                'codex_real_invoker_executor_plan_invoker_prepares_without_enabling_executor',
                'codex_real_invoker_executor_plan_invoker_requires_implementation_boundary_metadata',
                'codex_real_invoker_executor_plan_invoker_requires_executor_binary_contract_hash',
                'codex_real_invoker_executor_plan_invoker_is_idempotent_by_executor_plan_id',
                'codex_real_invoker_executor_plan_invoker_never_starts_codex_or_spends_tokens',
            ],
            'required_gates' => [
                'php_lint_codex_real_invoker_executor_plan_invoker',
                'dedicated_codex_real_invoker_executor_plan_invoker_feature_tests',
                'generic_codex_real_invoker_executor_plan_feature_tests',
                'focused_self_construction_command_tests',
                'architecture_validate',
                'docs_health',
                'git_diff_check',
            ],
            'implementation_policy' => [
                'implementation_allowed_by_packet' => true,
                'codex_real_invoker_executor_plan_call_allowed_by_future_invoker' => true,
                'codex_real_invoker_executor_fresh_release_gate_allowed_by_packet' => false,
                'executor_enablement_allowed_by_packet' => false,
                'provider_external_process_start_allowed_by_packet' => false,
                'token_spend_allowed_by_packet' => false,
                'self_programming_allowed_by_packet' => false,
            ],
            'stop_conditions' => [
                'need_to_spawn_codex_process',
                'need_to_call_codex_cli_or_codex_app',
                'need_to_enable_executor',
                'need_to_spend_provider_tokens',
                'need_to_mark_run_running_or_terminal',
                'need_to_enable_self_programming',
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_executor_plan_invoker_service',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_plan_implementation_packet.v1',
            'status' => (string) $packet['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_plan_implementation_packet',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_executor_plan_allowed' => false,
            'executor_enabled' => false,
            'adapter_execution_allowed' => false,
            'provider_start_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_plan_implementation_packet' => $packet,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_plan_implementation_packet_hash' => $this->stableHash($packet),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_plan_implementation_packet_does_not_prepare_executor_plan',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_plan_implementation_packet_does_not_enable_executor',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_plan_implementation_packet_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_plan_implementation_packet_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_plan_implementation_packet_does_not_enable_self_programming',
            ],
            'human_summary' => 'Automatic dispatch scheduler one-shot tick Codex real invoker executor plan implementation packet is ready; it scopes disabled executor planning and still stops before fresh release.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickAdapterInvocationBoundaryReleaseContract(array $options = []): array
    {
        $providerStartStatusPayload = $this->agentAutomaticDispatchSchedulerOneShotTickProviderStartDriverStatus($options);
        $providerStartStatus = (array) data_get($providerStartStatusPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_provider_start_driver_status', []);
        $boundaryPayload = $this->agentDispatchExecutorAdapterInvocationBoundaryPreflight($options);
        $boundary = (array) data_get($boundaryPayload, 'dispatch_executor_adapter_invocation_boundary_preflight', []);

        $contract = [
            'status' => 'one_shot_tick_adapter_invocation_boundary_release_contract_ready',
            'contract_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-ADAPTER-INVOCATION-BOUNDARY-RELEASE-CONTRACT-SELF-CONSTRUCTION-0001',
            'parent_program' => 'Atlas Self-Construction OS',
            'submodule' => 'Atlas Agent Control Plane',
            'workspace_id' => 'FORGE-WORKSPACE-ATLAS-SELF-CONSTRUCTION-0001',
            'source_provider_start_driver_status' => data_get($providerStartStatus, 'status'),
            'source_provider_start_driver_status_hash' => data_get($providerStartStatusPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_provider_start_driver_status_hash'),
            'source_adapter_invocation_boundary_preflight_status' => data_get($boundaryPayload, 'status'),
            'source_adapter_invocation_boundary_preflight_hash' => data_get($boundaryPayload, 'dispatch_executor_adapter_invocation_boundary_preflight_hash'),
            'release_boundary' => [
                'canonical_boundary' => AgentDispatchExecutorAdapterInvocationBoundary::class,
                'canonical_boundary_method' => 'prepareInvocation',
                'boundary_effect' => 'prepare_adapter_invocation_metadata_on_pre_start_guarded_run',
                'external_process_started_by_boundary' => false,
                'provider_started_by_boundary' => false,
                'token_spend_allowed_by_boundary' => false,
                'required_run_status_before_boundary' => 'pre_start_guarded',
                'required_run_status_after_boundary' => 'adapter_invocation_prepared',
                'idempotency_key' => 'adapter_invocation_id',
            ],
            'required_input_fields_for_future_invoker' => [
                'run_key',
                'adapter_invocation_id',
                'provider_start_attempt_id',
                'provider',
                'adapter',
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
                'write_adapter_invocation_metadata_on_agent_run',
                'transition_run_to_adapter_invocation_prepared',
                'append_adapter_invocation_prepared_evidence_event',
            ],
            'forbidden_even_after_boundary' => [
                'spawn_provider_process',
                'execute_provider_adapter',
                'spend_provider_tokens',
                'mark_packet_completed',
                'merge_work_products',
                'enable_self_programming',
            ],
            'handoff_policy' => [
                'adapter_invocation_boundary_is_metadata_preparation_not_adapter_execution' => true,
                'provider_adapter_execution_requires_separate_guard_contract' => true,
                'provider_specific_execution_requires_future_signed_release' => true,
                'full_chat_history_is_not_valid_context_pack' => true,
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_adapter_invocation_boundary_preflight',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_adapter_invocation_boundary_release_contract.v1',
            'status' => (string) $contract['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_adapter_invocation_boundary_release_contract',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'adapter_invocation_boundary_allowed' => false,
            'adapter_invocation_allowed' => false,
            'provider_start_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_adapter_invocation_boundary_release_contract' => $contract,
            'agent_automatic_dispatch_scheduler_one_shot_tick_adapter_invocation_boundary_release_contract_hash' => $this->stableHash($contract),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_adapter_invocation_boundary_release_contract_does_not_call_adapter_boundary',
                'agent_automatic_dispatch_scheduler_one_shot_tick_adapter_invocation_boundary_release_contract_does_not_start_providers',
                'agent_automatic_dispatch_scheduler_one_shot_tick_adapter_invocation_boundary_release_contract_does_not_call_adapters',
                'agent_automatic_dispatch_scheduler_one_shot_tick_adapter_invocation_boundary_release_contract_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_adapter_invocation_boundary_release_contract_does_not_enable_self_programming',
            ],
            'human_summary' => 'Automatic dispatch scheduler one-shot tick adapter invocation boundary release contract is ready; it defines the future metadata boundary while still forbidding adapter execution.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerExecutorPlanPreflight(array $options = []): array
    {
        $contractPayload = $this->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerExecutorPlanContract($options);
        $contract = (array) data_get($contractPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_plan_contract', []);
        $contractHash = (string) data_get($contractPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_plan_contract_hash');
        $executorPlanReady = class_exists(AgentCodexRealInvokerExecutorPlan::class)
            && method_exists(AgentCodexRealInvokerExecutorPlan::class, 'prepareExecutorPlan');
        $invokerReady = class_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerExecutorPlanInvoker::class)
            && method_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerExecutorPlanInvoker::class, 'prepareCodexRealInvokerExecutorPlan');
        $boundaryReady = class_exists(AgentCodexRealInvokerImplementationBoundary::class)
            && method_exists(AgentCodexRealInvokerImplementationBoundary::class, 'prepareBoundary');
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerTableReady = Schema::hasTable('atlas_ledger_events');

        $checks = [
            'executor_plan_contract_ready' => data_get($contractPayload, 'status') === 'one_shot_tick_codex_real_invoker_executor_plan_contract_ready',
            'executor_plan_contract_hash_present' => $contractHash !== '',
            'implementation_boundary_status_ready' => data_get($contract, 'source_codex_real_invoker_implementation_boundary_status') === 'one_shot_tick_codex_real_invoker_implementation_boundary_service_ready',
            'generic_executor_plan_contract_template_ready' => data_get($contract, 'source_codex_real_invoker_executor_plan_contract_status') === 'codex_real_invoker_executor_plan_contract_template_ready',
            'codex_real_invoker_executor_plan_ready' => $executorPlanReady,
            'codex_real_invoker_executor_plan_invoker_ready' => $invokerReady,
            'codex_real_invoker_implementation_boundary_ready' => $boundaryReady,
            'canonical_executor_plan_method_ready' => data_get($contract, 'release_boundary.canonical_executor_plan_method') === 'prepareExecutorPlan',
            'executor_plan_does_not_enable_executor' => data_get($contract, 'release_boundary.executor_enabled_by_executor_plan') === false,
            'executor_plan_does_not_start_external_process' => data_get($contract, 'release_boundary.external_process_started_by_executor_plan') === false,
            'executor_plan_does_not_spend_tokens' => data_get($contract, 'release_boundary.token_spend_allowed_by_executor_plan') === false,
            'agent_runs_table_ready' => $runsTableReady,
            'ledger_table_ready' => $ledgerTableReady,
            'self_programming_forbidden' => in_array('enable_self_programming', (array) data_get($contract, 'forbidden_even_after_contract', []), true),
        ];
        $blockingReasons = array_values(array_keys(array_filter($checks, static fn (bool $passed): bool => ! $passed)));

        $preflight = [
            'status' => $blockingReasons === [] ? 'one_shot_tick_codex_real_invoker_executor_plan_preflight_ready' : 'blocked',
            'preflight_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-REAL-INVOKER-EXECUTOR-PLAN-PREFLIGHT-SELF-CONSTRUCTION-0001',
            'parent_program' => 'Atlas Self-Construction OS',
            'submodule' => 'Atlas Agent Control Plane',
            'source_executor_plan_contract_hash' => $contractHash,
            'preflight_checks' => $checks,
            'blocking_count' => count($blockingReasons),
            'blocking_reasons' => $blockingReasons,
            'implementation_requirements' => [
                'use_one_shot_scheduler_codex_real_invoker_executor_plan_invoker',
                'delegate_to_agent_codex_real_invoker_executor_plan',
                'require_codex_real_invoker_implementation_boundary_metadata',
                'require_operator_executor_plan_receipt_hash',
                'require_executor_binary_contract_hash',
                'require_executor_observability_contract_hash',
                'preserve_executor_disabled_until_fresh_release_gate',
                'return_codex_real_invoker_executor_plan_result_without_starting_codex_process',
            ],
            'runtime_policy' => [
                'preflight_is_read_only' => true,
                'codex_real_invoker_executor_plan_call_allowed_here' => false,
                'codex_real_invoker_executor_fresh_release_gate_allowed_here' => false,
                'executor_enablement_allowed_here' => false,
                'provider_external_process_start_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'self_programming_allowed_here' => false,
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_executor_plan_implementation_packet',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_plan_preflight.v1',
            'status' => (string) $preflight['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_plan_preflight',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_executor_plan_allowed' => false,
            'executor_enabled' => false,
            'adapter_execution_allowed' => false,
            'provider_start_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_plan_preflight' => $preflight,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_plan_preflight_hash' => $this->stableHash($preflight),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_plan_preflight_does_not_prepare_executor_plan',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_plan_preflight_does_not_enable_executor',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_plan_preflight_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_plan_preflight_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_plan_preflight_does_not_enable_self_programming',
            ],
            'human_summary' => $blockingReasons === []
                ? 'Automatic dispatch scheduler one-shot tick Codex real invoker executor plan preflight is ready; the next slice can expose the scoped executor plan invoker packet.'
                : 'Automatic dispatch scheduler one-shot tick Codex real invoker executor plan preflight is blocked until boundary, executor plan, storage and no-runtime prerequisites are ready.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerExecutorFreshReleaseGatePreflight(array $options = []): array
    {
        $contractPayload = $this->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerExecutorFreshReleaseGateContract($options);
        $contract = (array) data_get($contractPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_fresh_release_gate_contract', []);
        $contractHash = (string) data_get($contractPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_fresh_release_gate_contract_hash');
        $freshReleaseReady = class_exists(AgentCodexRealInvokerExecutorFreshReleaseGate::class)
            && method_exists(AgentCodexRealInvokerExecutorFreshReleaseGate::class, 'authorizeFreshRelease');
        $invokerReady = class_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerExecutorFreshReleaseGateInvoker::class)
            && method_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerExecutorFreshReleaseGateInvoker::class, 'authorizeCodexRealInvokerExecutorFreshRelease');
        $executorPlanReady = class_exists(AgentCodexRealInvokerExecutorPlan::class)
            && method_exists(AgentCodexRealInvokerExecutorPlan::class, 'prepareExecutorPlan');
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerTableReady = Schema::hasTable('atlas_ledger_events');

        $checks = [
            'fresh_release_gate_contract_ready' => data_get($contractPayload, 'status') === 'one_shot_tick_codex_real_invoker_executor_fresh_release_gate_contract_ready',
            'fresh_release_gate_contract_hash_present' => $contractHash !== '',
            'executor_plan_status_ready' => data_get($contract, 'source_codex_real_invoker_executor_plan_status') === 'one_shot_tick_codex_real_invoker_executor_plan_service_ready',
            'generic_fresh_release_gate_contract_template_ready' => data_get($contract, 'source_codex_real_invoker_executor_fresh_release_gate_contract_status') === 'codex_real_invoker_executor_fresh_release_gate_contract_template_ready',
            'codex_real_invoker_executor_fresh_release_gate_ready' => $freshReleaseReady,
            'codex_real_invoker_executor_fresh_release_gate_invoker_ready' => $invokerReady,
            'codex_real_invoker_executor_plan_ready' => $executorPlanReady,
            'canonical_fresh_release_gate_method_ready' => data_get($contract, 'release_boundary.canonical_executor_fresh_release_gate_method') === 'authorizeFreshRelease',
            'fresh_release_does_not_enable_executor' => data_get($contract, 'release_boundary.executor_enabled_by_fresh_release') === false,
            'fresh_release_does_not_start_external_process' => data_get($contract, 'release_boundary.external_process_started_by_fresh_release') === false,
            'fresh_release_does_not_spend_tokens' => data_get($contract, 'release_boundary.token_spend_allowed_by_fresh_release') === false,
            'agent_runs_table_ready' => $runsTableReady,
            'ledger_table_ready' => $ledgerTableReady,
            'self_programming_forbidden' => in_array('enable_self_programming', (array) data_get($contract, 'forbidden_even_after_contract', []), true),
        ];
        $blockingReasons = array_values(array_keys(array_filter($checks, static fn (bool $passed): bool => ! $passed)));

        $preflight = [
            'status' => $blockingReasons === [] ? 'one_shot_tick_codex_real_invoker_executor_fresh_release_gate_preflight_ready' : 'blocked',
            'preflight_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-REAL-INVOKER-EXECUTOR-FRESH-RELEASE-GATE-PREFLIGHT-SELF-CONSTRUCTION-0001',
            'parent_program' => 'Atlas Self-Construction OS',
            'submodule' => 'Atlas Agent Control Plane',
            'source_executor_fresh_release_gate_contract_hash' => $contractHash,
            'preflight_checks' => $checks,
            'blocking_count' => count($blockingReasons),
            'blocking_reasons' => $blockingReasons,
            'implementation_requirements' => [
                'use_one_shot_scheduler_codex_real_invoker_executor_fresh_release_gate_invoker',
                'delegate_to_agent_codex_real_invoker_executor_fresh_release_gate',
                'require_codex_real_invoker_executor_plan_metadata',
                'require_operator_fresh_release_receipt_hash',
                'require_plan_revalidation_report_hash',
                'require_freshness_window_hash',
                'require_final_human_signature_hash',
                'preserve_executor_disabled_until_enablement_gate',
                'return_codex_real_invoker_executor_fresh_release_result_without_starting_codex_process',
            ],
            'runtime_policy' => [
                'preflight_is_read_only' => true,
                'codex_real_invoker_executor_fresh_release_gate_call_allowed_here' => false,
                'executor_enablement_allowed_here' => false,
                'provider_external_process_start_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'self_programming_allowed_here' => false,
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_executor_fresh_release_gate_implementation_packet',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_fresh_release_gate_preflight.v1',
            'status' => (string) $preflight['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_fresh_release_gate_preflight',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_executor_fresh_release_gate_allowed' => false,
            'executor_enabled' => false,
            'adapter_execution_allowed' => false,
            'provider_start_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_fresh_release_gate_preflight' => $preflight,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_fresh_release_gate_preflight_hash' => $this->stableHash($preflight),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_fresh_release_gate_preflight_does_not_authorize_fresh_release',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_fresh_release_gate_preflight_does_not_enable_executor',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_fresh_release_gate_preflight_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_fresh_release_gate_preflight_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_fresh_release_gate_preflight_does_not_enable_self_programming',
            ],
            'human_summary' => $blockingReasons === []
                ? 'Automatic dispatch scheduler one-shot tick Codex real invoker executor fresh release gate preflight is ready; the next slice can expose the scoped fresh release invoker packet.'
                : 'Automatic dispatch scheduler one-shot tick Codex real invoker executor fresh release gate preflight is blocked until executor plan, fresh release, storage and no-runtime prerequisites are ready.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerExecutorFreshReleaseGateImplementationPacket(array $options = []): array
    {
        $preflightPayload = $this->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerExecutorFreshReleaseGatePreflight($options);
        $preflight = (array) data_get($preflightPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_fresh_release_gate_preflight', []);
        $preflightHash = (string) data_get($preflightPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_fresh_release_gate_preflight_hash');

        $packet = [
            'status' => 'ready_for_scoped_one_shot_tick_codex_real_invoker_executor_fresh_release_gate_invoker_implementation',
            'packet_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-REAL-INVOKER-EXECUTOR-FRESH-RELEASE-GATE-IMPLEMENTATION-PACKET-SELF-CONSTRUCTION-0001',
            'parent_program' => 'Atlas Self-Construction OS',
            'submodule' => 'Atlas Agent Control Plane',
            'provider' => 'codex',
            'adapter' => 'codex',
            'source_codex_real_invoker_executor_fresh_release_gate_preflight_status' => data_get($preflight, 'status'),
            'source_codex_real_invoker_executor_fresh_release_gate_preflight_hash' => $preflightHash,
            'allowed_files' => [
                'app/Services/Ai/SelfConstruction/ControlPlane/AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerExecutorFreshReleaseGateInvoker.php',
                'tests/Feature/Ai/AtlasAiSelfConstructionAgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerExecutorFreshReleaseGateInvokerTest.php',
                'app/Services/Ai/SelfConstruction/Readiness/AtlasSelfConstructionReadinessService.php',
                'app/Console/Commands/AtlasAiSelfConstructionCommand.php',
                'tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php',
                'docs/engineering-knowledge-base/self-construction/agent-control-plane-contract.md',
            ],
            'tasks' => [
                ['id' => 'T1', 'title' => 'Expose one-shot scheduler Codex real invoker executor fresh release invoker', 'type' => 'service', 'acceptance' => 'Invoker validates fresh release input and delegates to AgentCodexRealInvokerExecutorFreshReleaseGate.'],
                ['id' => 'T2', 'title' => 'Preserve disabled executor boundary after fresh release', 'type' => 'service_logic', 'acceptance' => 'Invoker records fresh release authorization while executor enablement, actual process start, token spend and self-programming remain false.'],
                ['id' => 'T3', 'title' => 'Add Codex real invoker executor fresh release invoker tests', 'type' => 'test', 'acceptance' => 'Tests cover successful fresh release, idempotent retry, invalid plan revalidation hash rejection, missing executor plan metadata and duplicate fresh release rejection.'],
                ['id' => 'T4', 'title' => 'Expose Codex real invoker executor fresh release status', 'type' => 'command_surface', 'acceptance' => 'Command/readiness reports invoker readiness and next executor enablement gate without calling the invoker in status mode.'],
            ],
            'task_count' => 4,
            'acceptance_criteria' => [
                'codex_real_invoker_executor_fresh_release_gate_invoker_authorizes_without_enabling_executor',
                'codex_real_invoker_executor_fresh_release_gate_invoker_requires_executor_plan_metadata',
                'codex_real_invoker_executor_fresh_release_gate_invoker_requires_final_human_signature_hash',
                'codex_real_invoker_executor_fresh_release_gate_invoker_is_idempotent_by_fresh_release_id',
                'codex_real_invoker_executor_fresh_release_gate_invoker_never_starts_codex_or_spends_tokens',
            ],
            'required_gates' => [
                'php_lint_codex_real_invoker_executor_fresh_release_gate_invoker',
                'dedicated_codex_real_invoker_executor_fresh_release_gate_invoker_feature_tests',
                'generic_codex_real_invoker_executor_fresh_release_gate_feature_tests',
                'focused_self_construction_command_tests',
                'architecture_validate',
                'docs_health',
                'git_diff_check',
            ],
            'implementation_policy' => [
                'implementation_allowed_by_packet' => true,
                'codex_real_invoker_executor_fresh_release_gate_call_allowed_by_future_invoker' => true,
                'executor_enablement_allowed_by_packet' => false,
                'provider_external_process_start_allowed_by_packet' => false,
                'token_spend_allowed_by_packet' => false,
                'self_programming_allowed_by_packet' => false,
            ],
            'stop_conditions' => [
                'need_to_spawn_codex_process',
                'need_to_call_codex_cli_or_codex_app',
                'need_to_enable_executor',
                'need_to_spend_provider_tokens',
                'need_to_mark_run_running_or_terminal',
                'need_to_enable_self_programming',
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_executor_fresh_release_gate_invoker_service',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_fresh_release_gate_implementation_packet.v1',
            'status' => (string) $packet['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_fresh_release_gate_implementation_packet',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_executor_fresh_release_gate_allowed' => false,
            'executor_enabled' => false,
            'adapter_execution_allowed' => false,
            'provider_start_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_fresh_release_gate_implementation_packet' => $packet,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_fresh_release_gate_implementation_packet_hash' => $this->stableHash($packet),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_fresh_release_gate_implementation_packet_does_not_authorize_fresh_release',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_fresh_release_gate_implementation_packet_does_not_enable_executor',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_fresh_release_gate_implementation_packet_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_fresh_release_gate_implementation_packet_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_fresh_release_gate_implementation_packet_does_not_enable_self_programming',
            ],
            'human_summary' => 'Automatic dispatch scheduler one-shot tick Codex real invoker executor fresh release gate implementation packet is ready; it scopes fresh release authorization and still stops before executor enablement.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerSupervisedStartActivationGateStatus(array $options = []): array
    {
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerTableReady = Schema::hasTable('atlas_ledger_events');
        $activationReady = class_exists(AgentCodexRealInvokerSupervisedStartActivationGate::class)
            && method_exists(AgentCodexRealInvokerSupervisedStartActivationGate::class, 'prepareActivation');
        $invokerReady = class_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerSupervisedStartActivationGateInvoker::class)
            && method_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerSupervisedStartActivationGateInvoker::class, 'prepareCodexRealInvokerSupervisedStartActivation');

        $activationRunsQuery = $runsTableReady
            ? AtlasSelfConstructionAgentRun::query()
                ->whereNotNull('metadata->codex_real_invoker_supervised_start_activation->real_invoker_supervised_start_activation_id')
            : null;
        $latestActivationRun = $activationRunsQuery === null
            ? null
            : (clone $activationRunsQuery)->latest('updated_at')->first();

        $statusReady = $runsTableReady && $ledgerTableReady && $activationReady && $invokerReady;
        $status = [
            'status' => $statusReady ? 'one_shot_tick_codex_real_invoker_supervised_start_activation_gate_service_ready' : 'blocked',
            'provider' => 'codex',
            'adapter' => 'codex',
            'invoker_service' => AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerSupervisedStartActivationGateInvoker::class,
            'invoker_service_ready' => $invokerReady,
            'invoker_canonical_method' => 'prepareCodexRealInvokerSupervisedStartActivation',
            'generic_supervised_start_activation_gate_service' => AgentCodexRealInvokerSupervisedStartActivationGate::class,
            'generic_supervised_start_activation_gate_service_ready' => $activationReady,
            'generic_supervised_start_activation_gate_canonical_method' => 'prepareActivation',
            'agent_runs_table_ready' => $runsTableReady,
            'ledger_table_ready' => $ledgerTableReady,
            'codex_real_invoker_supervised_start_activation_prepared_run_count' => $activationRunsQuery === null ? null : (clone $activationRunsQuery)->count(),
            'latest_codex_real_invoker_supervised_start_activation_run' => $latestActivationRun instanceof AtlasSelfConstructionAgentRun
                ? [
                    'agent_run_id' => $latestActivationRun->id,
                    'run_key' => $latestActivationRun->run_key,
                    'packet_id' => $latestActivationRun->packet_id,
                    'provider' => $latestActivationRun->provider,
                    'status' => $latestActivationRun->status,
                    'real_invoker_supervised_start_activation_id' => data_get($latestActivationRun->metadata, 'codex_real_invoker_supervised_start_activation.real_invoker_supervised_start_activation_id'),
                    'real_invoker_executor_enablement_id' => data_get($latestActivationRun->metadata, 'codex_real_invoker_supervised_start_activation.real_invoker_executor_enablement_id'),
                    'codex_execution_id' => data_get($latestActivationRun->metadata, 'codex_real_invoker_supervised_start_activation.codex_execution_id'),
                    'real_invoker_supervised_start_activation_prepared' => data_get($latestActivationRun->metadata, 'codex_real_invoker_supervised_start_activation.real_invoker_supervised_start_activation_prepared'),
                    'executor_enabled' => data_get($latestActivationRun->metadata, 'codex_real_invoker_supervised_start_activation.executor_enabled'),
                    'process_start_armed' => data_get($latestActivationRun->metadata, 'codex_real_invoker_supervised_start_activation.process_start_armed'),
                    'external_process_started' => data_get($latestActivationRun->metadata, 'codex_real_invoker_supervised_start_activation.external_process_started'),
                    'token_spend_allowed' => data_get($latestActivationRun->metadata, 'codex_real_invoker_supervised_start_activation.token_spend_allowed'),
                ]
                : null,
            'runtime_policy' => [
                'status_projection_is_read_only' => true,
                'invoker_may_prepare_codex_real_invoker_supervised_start_activation_when_called_with_enablement_input' => true,
                'real_invoker_supervised_start_activation_is_not_real_process_invocation' => true,
                'real_invoker_supervised_start_activation_keeps_external_process_stopped' => true,
                'codex_real_invoker_guarded_process_start_executor_allowed_here' => false,
                'provider_external_process_start_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'self_programming_allowed_here' => false,
            ],
            'next_required_slice' => $statusReady
                ? 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_guarded_process_start_executor_contract'
                : 'repair_one_shot_scheduler_tick_codex_real_invoker_supervised_start_activation_gate_service_blockers',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_supervised_start_activation_gate_status.v1',
            'status' => (string) $status['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_supervised_start_activation_gate_status',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_supervised_start_activation_gate_allowed' => false,
            'executor_enabled' => false,
            'process_start_armed' => false,
            'adapter_execution_allowed' => false,
            'provider_start_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_supervised_start_activation_gate_status' => $status,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_supervised_start_activation_gate_status_hash' => $this->stableHash($status),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_supervised_start_activation_gate_status_does_not_call_activation_gate',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_supervised_start_activation_gate_status_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_supervised_start_activation_gate_status_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_supervised_start_activation_gate_status_does_not_enable_self_programming',
            ],
            'human_summary' => $statusReady
                ? 'Automatic dispatch scheduler one-shot tick Codex real invoker supervised start activation gate service is ready and inspectable; status remains read-only and guarded process start is still separate.'
                : 'Automatic dispatch scheduler one-shot tick Codex real invoker supervised start activation gate service is blocked until invoker, generic activation gate and ledger are ready.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerGuardedProcessStartExecutorContract(array $options = []): array
    {
        $activationStatusPayload = $this->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerSupervisedStartActivationGateStatus($options);
        $activationStatus = (array) data_get($activationStatusPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_supervised_start_activation_gate_status', []);
        $guardedPayload = $this->agentCodexRealInvokerGuardedProcessStartExecutorContractTemplate($options);

        $contract = [
            'status' => 'one_shot_tick_codex_real_invoker_guarded_process_start_executor_contract_ready',
            'contract_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-REAL-INVOKER-GUARDED-PROCESS-START-EXECUTOR-CONTRACT-SELF-CONSTRUCTION-0001',
            'parent_program' => 'Atlas Self-Construction OS',
            'submodule' => 'Atlas Agent Control Plane',
            'provider' => 'codex',
            'adapter' => 'codex',
            'source_codex_real_invoker_supervised_start_activation_gate_status' => data_get($activationStatus, 'status'),
            'source_codex_real_invoker_supervised_start_activation_gate_status_hash' => data_get($activationStatusPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_supervised_start_activation_gate_status_hash'),
            'source_codex_real_invoker_guarded_process_start_executor_contract_status' => data_get($guardedPayload, 'status'),
            'source_codex_real_invoker_guarded_process_start_executor_contract_hash' => data_get($guardedPayload, 'codex_real_invoker_guarded_process_start_executor_contract_template_hash'),
            'release_boundary' => [
                'canonical_guarded_process_start_executor' => AgentCodexRealInvokerGuardedProcessStartExecutor::class,
                'canonical_guarded_process_start_executor_method' => 'prepareGuardedStart',
                'scheduler_invoker' => AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerGuardedProcessStartExecutorInvoker::class,
                'scheduler_invoker_method' => 'prepareCodexRealInvokerGuardedProcessStart',
                'guarded_process_start_effect' => 'prepare_disabled_guarded_start_metadata_without_starting_process',
                'supervised_start_activation_required_before_guarded_start' => true,
                'process_start_armed_by_guarded_start' => true,
                'actual_process_start_allowed_by_guarded_start' => false,
                'external_process_started_by_guarded_start' => false,
                'provider_started_by_guarded_start' => false,
                'adapter_execution_allowed_by_guarded_start' => false,
                'token_spend_allowed_by_guarded_start' => false,
                'idempotency_key' => 'real_invoker_guarded_process_start_id',
            ],
            'required_input_fields_for_future_invoker' => [
                'run_key',
                'codex_execution_id',
                'real_invoker_supervised_start_activation_id',
                'real_invoker_guarded_process_start_id',
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
            'allowed_future_mutations' => [
                'write_codex_real_invoker_guarded_process_start_metadata_on_agent_run',
                'append_codex_real_invoker_guarded_process_start_evidence_event',
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
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_guarded_process_start_executor_preflight',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_guarded_process_start_executor_contract.v1',
            'status' => (string) $contract['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_guarded_process_start_executor_contract',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_guarded_process_start_executor_allowed' => false,
            'executor_enabled' => false,
            'process_start_armed' => false,
            'actual_process_start_allowed' => false,
            'adapter_execution_allowed' => false,
            'provider_start_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_guarded_process_start_executor_contract' => $contract,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_guarded_process_start_executor_contract_hash' => $this->stableHash($contract),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_guarded_process_start_executor_contract_does_not_prepare_guarded_start',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_guarded_process_start_executor_contract_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_guarded_process_start_executor_contract_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_guarded_process_start_executor_contract_does_not_enable_self_programming',
            ],
            'human_summary' => 'Automatic dispatch scheduler one-shot tick Codex real invoker guarded process start executor contract is ready; it defines disabled guarded-start preparation and still cannot start Codex.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartEvidenceReceiptPreflight(array $options = []): array
    {
        $contractPayload = $this->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartEvidenceReceiptContract($options);
        $contract = (array) data_get($contractPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_evidence_receipt_contract', []);
        $contractHash = (string) data_get($contractPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_evidence_receipt_contract_hash');
        $writerReady = class_exists(AgentCodexRealInvokerPostStartEvidenceReceiptWriter::class)
            && method_exists(AgentCodexRealInvokerPostStartEvidenceReceiptWriter::class, 'writePostStartEvidenceReceipt');
        $invokerReady = class_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartEvidenceReceiptInvoker::class)
            && method_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartEvidenceReceiptInvoker::class, 'writeCodexRealInvokerPostStartEvidenceReceipt');
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerTableReady = Schema::hasTable('atlas_ledger_events');

        $checks = [
            'post_start_evidence_receipt_contract_ready' => data_get($contractPayload, 'status') === 'one_shot_tick_codex_real_invoker_post_start_evidence_receipt_contract_ready',
            'post_start_evidence_receipt_contract_hash_present' => $contractHash !== '',
            'post_start_receipt_contract_status_ready' => data_get($contract, 'source_codex_real_invoker_post_start_receipt_contract_status') === 'one_shot_tick_codex_real_invoker_post_start_receipt_contract_service_ready',
            'generic_post_start_evidence_receipt_writer_template_ready' => data_get($contract, 'source_codex_real_invoker_post_start_evidence_receipt_writer_status') === 'codex_real_invoker_post_start_evidence_receipt_writer_contract_template_ready',
            'codex_real_invoker_post_start_evidence_receipt_writer_ready' => $writerReady,
            'codex_real_invoker_post_start_evidence_receipt_invoker_ready' => $invokerReady,
            'canonical_post_start_evidence_receipt_writer_method_ready' => data_get($contract, 'release_boundary.canonical_post_start_evidence_receipt_writer_method') === 'writePostStartEvidenceReceipt',
            'contract_accepts_external_process_evidence_only_as_evidence' => data_get($contract, 'release_boundary.external_process_evidence_accepted_by_contract') === true,
            'contract_requires_no_atlas_process_spawn_attestation' => data_get($contract, 'release_boundary.no_atlas_process_spawn_attestation_required') === true,
            'contract_keeps_actual_process_start_disabled' => data_get($contract, 'release_boundary.actual_process_start_allowed_by_contract') === false,
            'contract_keeps_atlas_process_spawn_disabled' => data_get($contract, 'release_boundary.atlas_process_spawned_by_contract') === false,
            'contract_does_not_dispatch_work' => data_get($contract, 'release_boundary.dispatch_allowed_by_contract') === false,
            'contract_does_not_spend_tokens' => data_get($contract, 'release_boundary.token_spend_allowed_by_contract') === false,
            'agent_runs_table_ready' => $runsTableReady,
            'ledger_table_ready' => $ledgerTableReady,
            'self_programming_forbidden' => in_array('enable_self_programming', (array) data_get($contract, 'forbidden_even_after_contract', []), true),
        ];
        $blockingReasons = array_values(array_keys(array_filter($checks, static fn (bool $passed): bool => ! $passed)));

        $preflight = [
            'status' => $blockingReasons === [] ? 'one_shot_tick_codex_real_invoker_post_start_evidence_receipt_preflight_ready' : 'blocked',
            'preflight_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-REAL-INVOKER-POST-START-EVIDENCE-RECEIPT-PREFLIGHT-SELF-CONSTRUCTION-0001',
            'source_post_start_evidence_receipt_contract_hash' => $contractHash,
            'preflight_checks' => $checks,
            'blocking_count' => count($blockingReasons),
            'blocking_reasons' => $blockingReasons,
            'implementation_requirements' => [
                'use_one_shot_scheduler_codex_real_invoker_post_start_evidence_receipt_invoker',
                'delegate_to_agent_codex_real_invoker_post_start_evidence_receipt_writer',
                'require_codex_real_invoker_post_start_receipt_contract_metadata',
                'require_post_start_evidence_acceptance_bridge_from_receipt_contract',
                'require_external_process_identity_startup_terminal_pid_liveness_and_cost_hashes',
                'require_operator_external_start_attestation_hash',
                'require_no_atlas_process_spawn_attestation_hash',
                'preserve_dispatch_disabled_until_liveness_monitor_and_dispatch_release',
            ],
            'runtime_policy' => [
                'preflight_is_read_only' => true,
                'codex_real_invoker_post_start_evidence_receipt_call_allowed_here' => false,
                'post_start_evidence_receipt_metadata_allowed_by_future_invoker' => true,
                'external_process_evidence_acceptance_allowed_by_future_invoker' => true,
                'provider_external_process_start_allowed_here' => false,
                'atlas_process_spawn_allowed_here' => false,
                'dispatch_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'self_programming_allowed_here' => false,
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_evidence_receipt_implementation_packet',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_evidence_receipt_preflight.v1',
            'status' => (string) $preflight['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_evidence_receipt_preflight',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_post_start_evidence_receipt_allowed' => false,
            'external_process_evidence_acceptance_allowed' => false,
            'actual_process_start_allowed' => false,
            'adapter_execution_allowed' => false,
            'provider_start_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_evidence_receipt_preflight' => $preflight,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_evidence_receipt_preflight_hash' => $this->stableHash($preflight),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_evidence_receipt_preflight_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_evidence_receipt_preflight_does_not_call_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_evidence_receipt_preflight_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_evidence_receipt_preflight_does_not_enable_self_programming',
            ],
            'human_summary' => $blockingReasons === []
                ? 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start evidence receipt preflight is ready.'
                : 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start evidence receipt preflight is blocked until receipt contract, writer, invoker, storage and no-spawn prerequisites are ready.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartDispatchExecutorHandoffPreflight(array $options = []): array
    {
        $contractPayload = $this->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartDispatchExecutorHandoffContract($options);
        $contract = (array) data_get($contractPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_dispatch_executor_handoff_contract', []);
        $contractHash = (string) data_get($contractPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_dispatch_executor_handoff_contract_hash');
        $handoffReady = class_exists(AgentCodexRealInvokerPostStartDispatchExecutorHandoff::class)
            && method_exists(AgentCodexRealInvokerPostStartDispatchExecutorHandoff::class, 'preparePostStartDispatchExecutorHandoff');
        $invokerReady = class_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartDispatchExecutorHandoffInvoker::class)
            && method_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartDispatchExecutorHandoffInvoker::class, 'prepareCodexRealInvokerPostStartDispatchExecutorHandoff');
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerTableReady = Schema::hasTable('atlas_ledger_events');

        $checks = [
            'post_start_dispatch_executor_handoff_contract_ready' => data_get($contractPayload, 'status') === 'one_shot_tick_codex_real_invoker_post_start_dispatch_executor_handoff_contract_ready',
            'post_start_dispatch_executor_handoff_contract_hash_present' => $contractHash !== '',
            'post_start_signed_dispatch_authorization_gate_status_ready' => data_get($contract, 'source_codex_real_invoker_post_start_signed_dispatch_authorization_gate_status') === 'one_shot_tick_codex_real_invoker_post_start_signed_dispatch_authorization_gate_service_ready',
            'generic_post_start_dispatch_executor_handoff_template_ready' => data_get($contract, 'source_codex_real_invoker_post_start_dispatch_executor_handoff_status') === 'codex_real_invoker_post_start_dispatch_executor_handoff_contract_template_ready',
            'codex_real_invoker_post_start_dispatch_executor_handoff_ready' => $handoffReady,
            'codex_real_invoker_post_start_dispatch_executor_handoff_invoker_ready' => $invokerReady,
            'canonical_post_start_dispatch_executor_handoff_method_ready' => data_get($contract, 'handoff_boundary.canonical_post_start_dispatch_executor_handoff_method') === 'preparePostStartDispatchExecutorHandoff',
            'contract_requires_post_start_signed_dispatch_authorization' => data_get($contract, 'handoff_boundary.post_start_signed_dispatch_authorization_required_before_handoff') === true,
            'contract_requires_liveness_alive' => data_get($contract, 'handoff_boundary.required_liveness_state') === 'alive',
            'contract_requires_executor_handoff_packet' => data_get($contract, 'handoff_boundary.executor_handoff_packet_hash_required') === true,
            'contract_requires_executor_workspace' => data_get($contract, 'handoff_boundary.executor_workspace_hash_required') === true,
            'contract_requires_executor_scope_lock' => data_get($contract, 'handoff_boundary.executor_scope_lock_hash_required') === true,
            'contract_preserves_future_dispatch_authorization' => data_get($contract, 'handoff_boundary.future_dispatch_authorized_by_contract') === true,
            'contract_keeps_actual_process_start_disabled' => data_get($contract, 'handoff_boundary.actual_process_start_allowed_by_contract') === false,
            'contract_does_not_dispatch_work' => data_get($contract, 'handoff_boundary.dispatch_allowed_by_contract') === false,
            'contract_does_not_spend_tokens' => data_get($contract, 'handoff_boundary.token_spend_allowed_by_contract') === false,
            'agent_runs_table_ready' => $runsTableReady,
            'ledger_table_ready' => $ledgerTableReady,
            'self_programming_forbidden' => in_array('enable_self_programming', (array) data_get($contract, 'forbidden_even_after_contract', []), true),
        ];
        $blockingReasons = array_values(array_keys(array_filter($checks, static fn (bool $passed): bool => ! $passed)));

        $preflight = [
            'status' => $blockingReasons === [] ? 'one_shot_tick_codex_real_invoker_post_start_dispatch_executor_handoff_preflight_ready' : 'blocked',
            'preflight_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-REAL-INVOKER-POST-START-DISPATCH-EXECUTOR-HANDOFF-PREFLIGHT-SELF-CONSTRUCTION-0001',
            'source_post_start_dispatch_executor_handoff_contract_hash' => $contractHash,
            'preflight_checks' => $checks,
            'blocking_count' => count($blockingReasons),
            'blocking_reasons' => $blockingReasons,
            'implementation_requirements' => [
                'use_one_shot_scheduler_codex_real_invoker_post_start_dispatch_executor_handoff_invoker',
                'delegate_to_agent_codex_real_invoker_post_start_dispatch_executor_handoff',
                'require_codex_real_invoker_post_start_signed_dispatch_authorization_metadata',
                'require_liveness_alive',
                'require_executor_handoff_packet_workspace_and_scope_hashes',
                'preserve_dispatch_disabled_until_dispatch_receipt_use_executor',
            ],
            'runtime_policy' => [
                'preflight_is_read_only' => true,
                'post_start_dispatch_executor_handoff_call_allowed_here' => false,
                'post_start_dispatch_executor_handoff_metadata_allowed_by_future_invoker' => true,
                'provider_external_process_start_allowed_here' => false,
                'atlas_process_spawn_allowed_here' => false,
                'dispatch_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'self_programming_allowed_here' => false,
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_dispatch_executor_handoff_implementation_packet',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_dispatch_executor_handoff_preflight.v1',
            'status' => (string) $preflight['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_dispatch_executor_handoff_preflight',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'post_start_dispatch_executor_handoff_allowed' => false,
            'actual_process_start_allowed' => false,
            'adapter_execution_allowed' => false,
            'provider_start_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_dispatch_executor_handoff_preflight' => $preflight,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_dispatch_executor_handoff_preflight_hash' => $this->stableHash($preflight),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_dispatch_executor_handoff_preflight_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_dispatch_executor_handoff_preflight_does_not_call_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_dispatch_executor_handoff_preflight_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_dispatch_executor_handoff_preflight_does_not_dispatch_work',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_dispatch_executor_handoff_preflight_does_not_enable_self_programming',
            ],
            'human_summary' => $blockingReasons === []
                ? 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start dispatch executor handoff preflight is ready.'
                : 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start dispatch executor handoff preflight is blocked until signed authorization, handoff, invoker and storage prerequisites are ready.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickGuardedRuntimeInvocationPreflight(array $options = []): array
    {
        $contractPayload = $this->agentAutomaticDispatchSchedulerOneShotTickGuardedRuntimeInvocationContract($options);
        $contract = (array) data_get($contractPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_guarded_runtime_invocation_contract', []);
        $contractHash = (string) data_get($contractPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_guarded_runtime_invocation_contract_hash');

        $preflightChecks = [
            'guarded_invocation_contract_ready' => data_get($contractPayload, 'status') === 'one_shot_tick_guarded_runtime_invocation_contract_ready',
            'guarded_invocation_contract_hash_present' => $contractHash !== '',
            'mutating_writer_service_exists' => class_exists(AgentAutomaticDispatchSchedulerOneShotTickMutatingWriter::class),
            'mutating_writer_method_exists' => method_exists(AgentAutomaticDispatchSchedulerOneShotTickMutatingWriter::class, 'executeOneShotSchedulerTickAfterReleasePreflight'),
            'writer_status_service_ready' => data_get($contract, 'source_mutating_writer_status') === 'one_shot_tick_mutating_writer_service_ready',
            'max_one_writer_invocation' => data_get($contract, 'invocation_boundary.max_writer_invocations_per_command') === 1,
            'input_contract_requires_release_receipt_hash' => in_array('release_receipt_hash', (array) data_get($contract, 'input_contract', []), true),
            'input_contract_requires_dispatch_receipt_hash' => in_array('receipt_hash', (array) data_get($contract, 'input_contract', []), true),
            'idempotency_is_receipt_hash' => data_get($contract, 'idempotency_policy.idempotency_key') === 'receipt_hash',
            'receipt_use_forbidden' => in_array('use_dispatch_receipt', (array) data_get($contract, 'forbidden_even_after_invocation', []), true),
            'provider_start_forbidden' => in_array('start_provider_process', (array) data_get($contract, 'forbidden_even_after_invocation', []), true),
            'adapter_invocation_forbidden' => in_array('invoke_provider_adapter', (array) data_get($contract, 'forbidden_even_after_invocation', []), true),
            'token_spend_forbidden' => in_array('spend_provider_tokens', (array) data_get($contract, 'forbidden_even_after_invocation', []), true),
            'self_programming_forbidden' => in_array('enable_self_programming', (array) data_get($contract, 'forbidden_even_after_invocation', []), true),
        ];
        $failedChecks = array_values(array_keys(array_filter(
            $preflightChecks,
            static fn (bool $passed): bool => ! $passed,
        )));
        $blockingReasons = array_values(array_unique($failedChecks));

        $preflight = [
            'status' => $blockingReasons === [] ? 'one_shot_tick_guarded_runtime_invocation_preflight_ready' : 'blocked',
            'preflight_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-GUARDED-RUNTIME-INVOCATION-PREFLIGHT-SELF-CONSTRUCTION-0001',
            'parent_program' => 'Atlas Self-Construction OS',
            'submodule' => 'Atlas Agent Control Plane',
            'workspace_id' => 'FORGE-WORKSPACE-ATLAS-SELF-CONSTRUCTION-0001',
            'source_guarded_invocation_contract_hash' => $contractHash,
            'preflight_checks' => $preflightChecks,
            'blocking_count' => count($blockingReasons),
            'blocking_reasons' => $blockingReasons,
            'failed_preflight_checks' => $failedChecks,
            'implementation_requirements' => [
                'create_guarded_invocation_service_or_command_boundary',
                'call_mutating_writer_once_only',
                'return_writer_result_without_using_dispatch_receipt',
                'record_no_provider_start_policy_in_output',
                'keep_provider_start_adapter_invocation_token_spend_and_self_programming_forbidden',
            ],
            'writer_policy' => [
                'preflight_is_read_only' => true,
                'runtime_invocation_allowed_here' => false,
                'claim_allowed_here' => false,
                'dispatch_receipt_write_allowed_here' => false,
                'dispatch_receipt_use_allowed_here' => false,
                'provider_start_allowed_here' => false,
                'adapter_invocation_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'self_programming_allowed_here' => false,
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_guarded_runtime_invocation_implementation_packet',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_guarded_runtime_invocation_preflight.v1',
            'status' => (string) $preflight['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_guarded_runtime_invocation_preflight',
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
            'agent_automatic_dispatch_scheduler_one_shot_tick_guarded_runtime_invocation_preflight' => $preflight,
            'agent_automatic_dispatch_scheduler_one_shot_tick_guarded_runtime_invocation_preflight_hash' => $this->stableHash($preflight),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_guarded_runtime_invocation_preflight_does_not_call_mutating_writer',
                'agent_automatic_dispatch_scheduler_one_shot_tick_guarded_runtime_invocation_preflight_does_not_claim_wakeup_items',
                'agent_automatic_dispatch_scheduler_one_shot_tick_guarded_runtime_invocation_preflight_does_not_write_dispatch_receipts',
                'agent_automatic_dispatch_scheduler_one_shot_tick_guarded_runtime_invocation_preflight_does_not_use_dispatch_receipts',
                'agent_automatic_dispatch_scheduler_one_shot_tick_guarded_runtime_invocation_preflight_does_not_start_providers',
                'agent_automatic_dispatch_scheduler_one_shot_tick_guarded_runtime_invocation_preflight_does_not_enable_self_programming',
            ],
            'human_summary' => $blockingReasons === []
                ? 'Automatic dispatch scheduler one-shot tick guarded runtime invocation preflight is ready; the next slice may generate a scoped implementation packet without invoking the writer.'
                : 'Automatic dispatch scheduler one-shot tick guarded runtime invocation preflight is blocked until the writer, contract and no-provider guard conditions are ready.',
        ];
    }

}
