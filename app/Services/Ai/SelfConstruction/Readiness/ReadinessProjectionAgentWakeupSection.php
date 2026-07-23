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
 * SC-01 fatia ReadinessProjectionAgentWakeupSection (Obra 4 Residual Elite).
 */
final class ReadinessProjectionAgentWakeupSection
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
            throw new \RuntimeException('ReadinessProjectionAgentWakeupSection mother not bound for '.$name);
        }
        $method = new \ReflectionMethod($this->mother, $name);

        return $method->invokeArgs($this->mother, $arguments);
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentWakeupClaim(array $options = []): array
    {
        if (! Schema::hasTable('atlas_self_construction_agent_wakeup_items')) {
            $claim = [
                'status' => 'blocked',
                'blocking_reasons' => ['agent_control_plane_wakeup_queue_schema_missing'],
                'required_migration' => 'database/migrations/2026_05_12_010000_create_atlas_self_construction_agent_control_plane_tables.php',
                'wakeup_items_table_ready' => false,
            ];

            return [
                'schema_version' => 'atlas.self_construction_agent_wakeup_claim.v1',
                'status' => 'blocked',
                'mode' => 'controlled_agent_wakeup_claim',
                'execution_allowed' => false,
                'dispatch_allowed' => false,
                'ledger_write_allowed' => false,
                'runtime_write_allowed' => false,
                'wakeup_claim' => $claim,
                'wakeup_claim_hash' => $this->stableHash($claim),
                'human_summary' => 'Agent wakeup claim is blocked until the Agent Control Plane wakeup queue table exists.',
            ];
        }

        $actor = $this->reservationActor($options);
        $session = $this->reservationSession($options);
        $packetId = trim((string) ($options['packet'] ?? ''));
        $now = now();

        $query = AtlasSelfConstructionAgentWakeupItem::query()
            ->where('status', 'queued')
            ->where(function ($query) use ($now): void {
                $query->whereNull('scheduled_for')
                    ->orWhere('scheduled_for', '<=', $now);
            });

        if ($packetId !== '') {
            $query->where('packet_id', $packetId);
        }

        $candidate = $query
            ->orderByRaw("case priority when 'high' then 0 when 'medium' then 1 when 'normal' then 2 else 3 end")
            ->orderBy('scheduled_for')
            ->first();

        if (! $candidate instanceof AtlasSelfConstructionAgentWakeupItem) {
            $claim = [
                'status' => 'blocked',
                'blocking_reasons' => ['no_ready_wakeup_item'],
                'requested_packet_id' => $packetId === '' ? null : $packetId,
                'claimed_by_actor' => $actor,
                'claimed_by_session' => $session,
            ];

            return [
                'schema_version' => 'atlas.self_construction_agent_wakeup_claim.v1',
                'status' => 'blocked',
                'mode' => 'controlled_agent_wakeup_claim',
                'execution_allowed' => false,
                'dispatch_allowed' => false,
                'ledger_write_allowed' => false,
                'runtime_write_allowed' => false,
                'wakeup_claim' => $claim,
                'wakeup_claim_hash' => $this->stableHash($claim),
                'non_execution_guarantees' => [
                    'agent_wakeup_claim_does_not_start_providers',
                    'agent_wakeup_claim_does_not_claim_packets',
                    'agent_wakeup_claim_does_not_release_packets',
                    'agent_wakeup_claim_does_not_dispatch_work',
                ],
                'human_summary' => 'Agent wakeup claim found no ready wakeup item to claim.',
            ];
        }

        $payload = is_array($candidate->payload) ? $candidate->payload : [];
        $updated = AtlasSelfConstructionAgentWakeupItem::query()
            ->whereKey($candidate->id)
            ->where('status', 'queued')
            ->update([
                'status' => 'claimed',
                'claimed_at' => $now,
                'payload' => array_merge($payload, [
                    'claim_source' => 'agent_wakeup_claim',
                    'claimed_by_actor' => $actor,
                    'claimed_by_session' => $session,
                    'claimed_at' => $now->toIso8601String(),
                    'dispatch_allowed' => false,
                ]),
            ]);

        if ($updated !== 1) {
            $claim = [
                'status' => 'blocked',
                'blocking_reasons' => ['wakeup_item_already_claimed'],
                'wakeup_item_id' => $candidate->id,
                'wakeup_key' => $candidate->wakeup_key,
                'claimed_by_actor' => $actor,
                'claimed_by_session' => $session,
            ];

            return [
                'schema_version' => 'atlas.self_construction_agent_wakeup_claim.v1',
                'status' => 'blocked',
                'mode' => 'controlled_agent_wakeup_claim',
                'execution_allowed' => false,
                'dispatch_allowed' => false,
                'ledger_write_allowed' => false,
                'runtime_write_allowed' => false,
                'wakeup_claim' => $claim,
                'wakeup_claim_hash' => $this->stableHash($claim),
                'human_summary' => 'Agent wakeup claim lost the race because the wakeup item was already claimed.',
            ];
        }

        $candidate->refresh();

        $claim = [
            'status' => 'claimed',
            'claimed_by_actor' => $actor,
            'claimed_by_session' => $session,
            'item' => [
                'wakeup_item_id' => $candidate->id,
                'wakeup_key' => $candidate->wakeup_key,
                'run_id' => $candidate->agent_run_id,
                'packet_id' => $candidate->packet_id,
                'actor' => $candidate->actor,
                'provider' => $candidate->provider,
                'reason' => $candidate->reason,
                'priority' => $candidate->priority,
                'status' => $candidate->status,
                'scheduled_for' => $candidate->scheduled_for?->toIso8601String(),
                'claimed_at' => $candidate->claimed_at?->toIso8601String(),
            ],
            'resume_envelope' => [
                'wakeup_key' => $candidate->wakeup_key,
                'run_id' => $candidate->agent_run_id,
                'packet_id' => $candidate->packet_id,
                'actor' => $candidate->actor,
                'provider' => $candidate->provider,
                'reason' => $candidate->reason,
                'claimed_by_actor' => $actor,
                'claimed_by_session' => $session,
                'required_first_command' => 'php artisan atlas:ai:self-construction --agent-control-plane --json',
                'required_follow_up_command' => $candidate->packet_id
                    ? 'php artisan atlas:ai:self-construction --agent-start-packet --actor='.($candidate->actor ?: '<actor>').' --session=<session> --packet='.$candidate->packet_id.' --json'
                    : 'php artisan atlas:ai:self-construction --packet-queue --json',
            ],
            'policy' => [
                'claim_is_runtime_write_only' => true,
                'does_not_start_providers' => true,
                'does_not_claim_or_release_packets' => true,
                'provider_dispatch_requires_future_signed_receipt' => true,
            ],
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_wakeup_claim.v1',
            'status' => 'agent_wakeup_claimed',
            'mode' => 'controlled_agent_wakeup_claim',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => true,
            'wakeup_claim' => $claim,
            'wakeup_claim_hash' => $this->stableHash($claim),
            'non_execution_guarantees' => [
                'agent_wakeup_claim_does_not_start_providers',
                'agent_wakeup_claim_does_not_claim_packets',
                'agent_wakeup_claim_does_not_release_packets',
                'agent_wakeup_claim_does_not_dispatch_work',
            ],
            'human_summary' => 'Agent wakeup claim reserved one ready wakeup item for governed resume without dispatching providers or mutating packet state.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAdapterContract(array $options = []): array
    {
        $controlPlane = $this->agentControlPlane($options);
        $providers = [
            [
                'provider' => 'codex',
                'adapter_id' => 'ADAPTER-CODEX-SELF-CONSTRUCTION-0001',
                'role' => 'implementation_worker',
                'receives' => 'packet_scope_bootstrap',
                'allowed_invocation_modes' => ['manual_codex_app_session', 'future_codex_cli_or_api_runtime'],
                'required_inputs' => [
                    'packet_id',
                    'reservation_id',
                    'actor',
                    'session_id',
                    'allowed_files',
                    'forbidden_files',
                    'required_gates',
                    'required_evidence',
                    'continuation_summary',
                ],
                'required_outputs' => [
                    'heartbeat_events',
                    'work_products',
                    'cost_events_when_available',
                    'test_output',
                    'completion_or_release_receipt',
                ],
                'forbidden_actions' => [
                    'start_without_packet_claim',
                    'touch_forbidden_files',
                    'merge_without_review_receipt',
                    'reuse_raw_chat_history_as_context',
                ],
            ],
            [
                'provider' => 'claude',
                'adapter_id' => 'ADAPTER-CLAUDE-SELF-CONSTRUCTION-0001',
                'role' => 'planner_or_reviewer',
                'receives' => 'architecture_and_acceptance_packet',
                'allowed_invocation_modes' => ['manual_claude_session', 'future_claude_cli_or_api_runtime'],
                'required_inputs' => [
                    'objective',
                    'architecture_context',
                    'acceptance_criteria',
                    'diff_or_work_product_summary',
                    'review_questions',
                ],
                'required_outputs' => [
                    'review_findings',
                    'risk_assessment',
                    'approval_recommendation',
                    'work_product_or_feedback_artifact',
                ],
                'forbidden_actions' => [
                    'edit_without_assigned_write_scope',
                    'approve_sensitive_action_without_human_receipt',
                    'bypass_quality_gates',
                ],
            ],
            [
                'provider' => 'gemini',
                'adapter_id' => 'ADAPTER-GEMINI-SELF-CONSTRUCTION-0001',
                'role' => 'scout_or_long_context_mapper',
                'receives' => 'source_map_and_research_packet',
                'allowed_invocation_modes' => ['manual_gemini_session', 'future_gemini_cli_or_api_runtime'],
                'required_inputs' => [
                    'repo_map',
                    'docs_map',
                    'search_questions',
                    'known_hot_scopes',
                    'output_schema',
                ],
                'required_outputs' => [
                    'source_inventory',
                    'context_map',
                    'implementation_risks',
                    'candidate_files',
                ],
                'forbidden_actions' => [
                    'write_code_without_explicit_packet',
                    'invent_source_paths',
                    'replace_primary_codebase_inspection_with_summary_only',
                ],
            ],
            [
                'provider' => 'local_runtime',
                'adapter_id' => 'ADAPTER-LOCAL-RUNTIME-SELF-CONSTRUCTION-0001',
                'role' => 'deterministic_gate_runner',
                'receives' => 'commands_and_expected_outputs',
                'allowed_invocation_modes' => ['local_shell', 'future_worker_runtime'],
                'required_inputs' => [
                    'command',
                    'cwd',
                    'timeout_seconds',
                    'expected_exit_policy',
                    'evidence_capture_policy',
                ],
                'required_outputs' => [
                    'exit_code',
                    'stdout_summary',
                    'stderr_summary',
                    'evidence_hash',
                    'gate_result',
                ],
                'forbidden_actions' => [
                    'destructive_command_without_signed_receipt',
                    'network_or_secret_access_without_policy',
                    'background_process_without_liveness_tracking',
                ],
            ],
        ];

        $contract = [
            'contract_id' => 'AGENT-ADAPTER-CONTRACT-SELF-CONSTRUCTION-0001',
            'parent_control_plane' => data_get($controlPlane, 'control_plane.control_plane_id'),
            'status' => 'read_only_contract_ready',
            'provider_count' => count($providers),
            'providers' => $providers,
            'shared_invocation_envelope' => [
                'operation_id',
                'packet_id',
                'reservation_id',
                'workspace_id',
                'obra_id',
                'actor',
                'session_id',
                'provider',
                'provider_role',
                'allowed_files_hash',
                'decision_receipt_hash',
                'context_pack_hash',
                'required_gates',
                'required_evidence',
            ],
            'shared_return_envelope' => [
                'run_id',
                'status',
                'heartbeat_events',
                'cost_events',
                'work_products',
                'gate_results',
                'evidence_hash',
                'completion_or_release_reason',
            ],
            'dispatch_policy' => [
                'dispatch_allowed_now' => false,
                'manual_sessions_allowed_now' => true,
                'runtime_invocation_requires_future_signed_receipt' => true,
                'provider_output_is_not_source_of_truth_until_recorded_as_work_product' => true,
                'cost_is_not_source_of_truth_until_recorded_as_cost_event' => true,
            ],
            'promotion_requirements' => [
                'wakeup_queue',
                'adapter_invocation_runtime',
                'provider_process_supervision',
                'automatic_work_product_collection',
                'automatic_cost_import',
                'signed_dispatch_receipt',
            ],
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_adapter_contract.v1',
            'status' => 'agent_adapter_contract_ready',
            'mode' => 'read_only_agent_adapter_invocation_contract',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'adapter_contract' => $contract,
            'adapter_contract_hash' => $this->stableHash($contract),
            'non_execution_guarantees' => [
                'agent_adapter_contract_does_not_start_providers',
                'agent_adapter_contract_does_not_claim_packets',
                'agent_adapter_contract_does_not_dispatch_work',
                'agent_adapter_contract_does_not_enable_self_programming',
            ],
            'human_summary' => 'Agent adapter contract is ready: Atlas now has a provider-neutral invocation contract for future Codex, Claude, Gemini and local runtime dispatch without starting providers.',
        ];
    }


    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneTaskQueueCompleteDryRunStatus(array $options = []): array
    {
        $taskPacketId = (string) ($options['packet'] ?? '');
        $leaseId = (string) ($options['lease_id'] ?? '');
        if ($taskPacketId === '' || $leaseId === '') {
            $payload = [
                'schema_version' => AgentControlPlaneTaskQueueOrchestrator::SCHEMA_VERSION,
                'status' => 'blocked',
                'event' => 'complete_dry_run_blocked',
                'reason' => $taskPacketId === '' ? 'task_packet_id_missing' : 'lease_id_missing',
                'task_packet_id' => $taskPacketId,
                'lease_id' => $leaseId,
                'runtime_completion_persisted' => false,
                'completion_real_allowed' => false,
                'dispatch_allowed' => false,
                'provider_call_allowed' => false,
                'token_spend_allowed' => false,
                'self_programming_allowed' => false,
                'ledger_write_allowed' => false,
            ];

            return $this->wrapCertificationWorkbenchStatus(
                keyPrefix: 'task_queue_complete_dry_run',
                label: 'Task Queue Complete Dry-Run',
                payload: $payload,
                statusKey: 'status',
                extraStatusFields: [
                    'event' => 'complete_dry_run_blocked',
                    'task_packet_id' => $taskPacketId,
                    'lease_id' => $leaseId,
                    'runtime_completion_persisted' => false,
                    'completion_real_allowed' => false,
                ],
            );
        }

        $orchestrator = $this->buildTaskQueueOrchestrator();
        $evidenceHash = (string) ($options['evidence_hash'] ?? '');
        if ($evidenceHash === '') {
            $payload = [
                'schema_version' => AgentControlPlaneTaskQueueOrchestrator::SCHEMA_VERSION,
                'status' => 'blocked',
                'event' => 'complete_dry_run_blocked',
                'reason' => 'evidence_hash_missing',
                'task_packet_id' => $taskPacketId,
                'lease_id' => $leaseId,
                'runtime_completion_persisted' => false,
                'completion_real_allowed' => false,
                'legacy_reservation_completion_used' => false,
                'safe_for_parallel_terminal_loop' => false,
                'operator_evidence_hash_valid' => false,
            ];

            return $this->wrapCertificationWorkbenchStatus(
                keyPrefix: 'task_queue_complete_dry_run',
                label: 'Task Queue Complete Dry-Run',
                payload: $payload,
                statusKey: 'status',
                extraStatusFields: [
                    'event' => 'complete_dry_run_blocked',
                    'task_packet_id' => $taskPacketId,
                    'lease_id' => $leaseId,
                    'runtime_completion_persisted' => false,
                    'legacy_reservation_completion_used' => false,
                    'completion_real_allowed' => false,
                    'safe_for_parallel_terminal_loop' => false,
                ],
            );
        }
        if ($evidenceHash !== '' && preg_match('/^[a-f0-9]{64}$/', $evidenceHash) !== 1) {
            $payload = [
                'schema_version' => AgentControlPlaneTaskQueueOrchestrator::SCHEMA_VERSION,
                'status' => 'blocked',
                'event' => 'complete_dry_run_blocked',
                'reason' => 'evidence_hash_invalid',
                'task_packet_id' => $taskPacketId,
                'lease_id' => $leaseId,
                'runtime_completion_persisted' => false,
                'completion_real_allowed' => false,
                'legacy_reservation_completion_used' => false,
                'safe_for_parallel_terminal_loop' => false,
            ];

            return $this->wrapCertificationWorkbenchStatus(
                keyPrefix: 'task_queue_complete_dry_run',
                label: 'Task Queue Complete Dry-Run',
                payload: $payload,
                statusKey: 'status',
                extraStatusFields: [
                    'event' => 'complete_dry_run_blocked',
                    'task_packet_id' => $taskPacketId,
                    'lease_id' => $leaseId,
                    'runtime_completion_persisted' => false,
                    'legacy_reservation_completion_used' => false,
                    'completion_real_allowed' => false,
                    'safe_for_parallel_terminal_loop' => false,
                ],
            );
        }

        $evidence = [
            'actor' => $this->reservationActor($options),
            'session' => $this->reservationSession($options),
            'reason' => (string) ($options['reason'] ?? 'packet_scope_finished'),
            'operator_supplied_evidence_hash' => $evidenceHash,
            'operator_supplied_evidence_hash_valid' => true,
            'completion_evidence' => $this->decodeJsonOption($options['completion_evidence_json'] ?? null),
        ];
        $result = $orchestrator->completeDryRun($taskPacketId, $leaseId, $evidence);
        $event = (string) ($result['event'] ?? 'unknown');
        $status = $event === 'completed_dry_run' ? 'completed_dry_run' : 'blocked';
        $evidenceValidation = (array) ($result['evidence_validation'] ?? []);
        $payload = array_merge($result, [
            'status' => $status,
            'runtime_completion_persisted' => $event === 'completed_dry_run',
            'completion_real_allowed' => false,
            'legacy_reservation_completion_used' => false,
            'safe_for_parallel_terminal_loop' => $event === 'completed_dry_run',
            'next_agent_command' => 'php artisan atlas:ai:self-construction --agent-control-plane-task-queue-claim-next-status --actor=<agent-id> --json',
            'operator_evidence_hash_valid' => (bool) $evidence['operator_supplied_evidence_hash_valid'],
            'structured_completion_evidence_required' => true,
            'structured_completion_evidence_valid' => (bool) ($evidenceValidation['structured_completion_evidence_valid'] ?? false),
            'completion_evidence_validation_status' => (string) ($evidenceValidation['status'] ?? 'missing'),
            'completion_evidence_validation_hash' => (string) ($evidenceValidation['evidence_validation_hash'] ?? ''),
            'completion_evidence_hash_matches_payload' => (bool) ($evidenceValidation['evidence_hash_matches_payload'] ?? false),
            'completion_evidence_computed_hash' => (string) ($evidenceValidation['computed_evidence_hash'] ?? ''),
            'completion_evidence_missing_fields' => (array) ($evidenceValidation['missing_fields'] ?? []),
            'completion_evidence_missing_field_count' => (int) ($evidenceValidation['missing_field_count'] ?? 0),
            'completion_evidence_blockers' => (array) ($evidenceValidation['blockers'] ?? []),
            'completion_evidence_blocker_count' => (int) ($evidenceValidation['blocker_count'] ?? 0),
            'files_changed_within_allowed_scope' => (bool) ($evidenceValidation['files_changed_within_allowed_scope'] ?? false),
            'files_changed_outside_allowed_scope' => (array) ($evidenceValidation['files_changed_outside_allowed_scope'] ?? []),
        ]);

        return $this->wrapCertificationWorkbenchStatus(
            keyPrefix: 'task_queue_complete_dry_run',
            label: 'Task Queue Complete Dry-Run',
            payload: $payload,
            statusKey: 'status',
            extraStatusFields: [
                'event' => $event,
                'task_packet_id' => $taskPacketId,
                'lease_id' => $leaseId,
                'runtime_completion_persisted' => $event === 'completed_dry_run',
                'legacy_reservation_completion_used' => false,
                'completion_real_allowed' => false,
                'safe_for_parallel_terminal_loop' => $event === 'completed_dry_run',
                'structured_completion_evidence_valid' => (bool) ($evidenceValidation['structured_completion_evidence_valid'] ?? false),
                'completion_evidence_validation_status' => (string) ($evidenceValidation['status'] ?? 'missing'),
                'completion_evidence_validation_hash' => (string) ($evidenceValidation['evidence_validation_hash'] ?? ''),
                'completion_evidence_hash_matches_payload' => (bool) ($evidenceValidation['evidence_hash_matches_payload'] ?? false),
                'completion_evidence_computed_hash' => (string) ($evidenceValidation['computed_evidence_hash'] ?? ''),
                'completion_evidence_missing_field_count' => (int) ($evidenceValidation['missing_field_count'] ?? 0),
                'completion_evidence_blocker_count' => (int) ($evidenceValidation['blocker_count'] ?? 0),
                'files_changed_within_allowed_scope' => (bool) ($evidenceValidation['files_changed_within_allowed_scope'] ?? false),
                'files_changed_outside_allowed_scope' => (array) ($evidenceValidation['files_changed_outside_allowed_scope'] ?? []),
            ],
            // A1-SC-0003: a persisted dry-run completion is a durable write — say so.
            runtimeWritePerformed: $event === 'completed_dry_run',
        );
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentStartPacket(array $options = []): array
    {
        $claimNext = $this->claimNextPacket($options);
        $packetId = data_get($claimNext, 'packet_id');
        $actor = $this->reservationActor($options);
        $session = $this->reservationSession($options);
        $providerRole = $this->providerRoleForActor($actor);

        if (! is_string($packetId) || $packetId === '') {
            $contract = [
                'status' => 'blocked',
                'workspace' => [
                    'workspace_id' => 'FORGE-WORKSPACE-ATLAS-SELF-CONSTRUCTION-0001',
                    'canonical_name' => 'Obras Shared Workspace',
                    'specialization' => 'Forge Workspace',
                    'obra_id' => 'OBRA-ATLAS-SELF-CONSTRUCTION-OS',
                ],
                'provider' => $providerRole,
                'blocking_reasons' => (array) data_get($claimNext, 'start.blocking_reasons', ['no_available_packet']),
                'queue_state' => [
                    'available_count' => data_get($claimNext, 'start.available_count'),
                    'claimed_count' => data_get($claimNext, 'start.claimed_count'),
                    'withheld_count' => data_get($claimNext, 'start.withheld_count'),
                ],
            ];

            return [
                'schema_version' => 'atlas.self_construction_agent_start_packet.v1',
                'status' => 'blocked',
                'mode' => 'durable_local_agent_start_packet',
                'execution_allowed' => false,
                'completion_allowed' => false,
                'claim_persisted' => false,
                'dispatch_allowed' => false,
                'packet_id' => null,
                'contract' => $contract,
                'contract_hash' => $this->stableHash($contract),
                'human_summary' => 'Agent start packet is blocked because no available Self-Construction packet remains.',
            ];
        }

        $contract = [
            'contract_id' => 'AGENT-START-SELF-CONSTRUCTION-0001',
            'workspace' => [
                'workspace_id' => 'FORGE-WORKSPACE-ATLAS-SELF-CONSTRUCTION-0001',
                'canonical_name' => 'Obras Shared Workspace',
                'portuguese_label' => 'Workspace Compartilhado de Obras',
                'specialization' => 'Forge Workspace',
                'obra_id' => 'OBRA-ATLAS-SELF-CONSTRUCTION-OS',
                'obra_title' => 'Atlas Self-Construction OS',
                'workspace_type' => 'programming_forge_workspace',
                'artifact_exchange_rule' => 'Return structured artifacts to the Obras Shared Workspace; loose provider-to-provider chat is not source of truth.',
            ],
            'packet_id' => $packetId,
            'actor' => $actor,
            'session' => $session,
            'provider' => $providerRole,
            'one_line_user_prompt' => 'continua a implementação da forma mais profissional e completa possível',
            'operator_prompt' => 'Continue Atlas Self-Construction from this claimed packet inside the Forge Workspace. Read the bootstrap, validate scope before edits, touch only allowed files, run gates, return artifacts to the shared workspace, and stop on blockers.',
            'mission' => 'Implement exactly the claimed Self-Construction packet as a workspace participant, preserving governance, evidence, scope isolation and final response traceability.',
            'claim' => [
                'persisted' => (bool) data_get($claimNext, 'claim_persisted'),
                'reservation_id' => data_get($claimNext, 'claim.reservation.reservation_id'),
                'lease_expires_at' => data_get($claimNext, 'claim.reservation.lease_expires_at'),
                'claim_hash' => data_get($claimNext, 'start.claim_hash'),
            ],
            'scope' => [
                'allowed_files' => (array) data_get($claimNext, 'start.allowed_files', []),
                'forbidden_files' => (array) data_get($claimNext, 'start.forbidden_files', []),
                'hot_scopes' => $this->hotForbiddenFiles(),
            ],
            'bootstrap_command' => $this->packetCommand('ai-session-bootstrap', $packetId),
            'scope_validator_command' => $this->packetCommand('scope-validator', $packetId),
            'required_first_commands' => (array) data_get($claimNext, 'start.required_first_commands', []),
            'required_gates' => (array) data_get($claimNext, 'start.required_gates', []),
            'artifact_return_contract' => [
                'return_to' => 'Obras Shared Workspace / Forge Workspace',
                'required_artifact_types' => [
                    'scope_validator_report',
                    'implementation_diff',
                    'test_output',
                    'evidence_report',
                    'integration_note',
                ],
                'normalization_required' => true,
                'source_of_truth_rule' => 'Workspace artifacts and evidence events override provider chat summaries.',
            ],
            'required_evidence' => array_values(array_unique(array_merge(
                (array) data_get($claimNext, 'start.required_evidence', []),
                [
                    'workspace_id',
                    'claimed_packet_id',
                    'provider_role',
                    'reservation_id',
                    'changed_files_by_this_session',
                    'scope_validator_output',
                    'focused_test_output',
                    'docs_health_output',
                    'architecture_validate_output',
                    'git_diff_check_output',
                    'workspace_artifacts_returned',
                ]
            ))),
            'implementation_rules' => [
                'touch_only_allowed_files',
                'do_not_edit_voice_or_kernel_hot_scopes',
                'do_not_revert_user_or_other_session_changes',
                'no_provider_to_provider_loose_handoff',
                'return_workspace_artifacts',
                'run_scope_validator_before_and_after',
                'prefer_small_scoped_patch',
                'update_docs_when_contract_changes',
                'add_or_update_focused_tests_for_runtime_changes',
                'stop_if_scope_validator_blocks',
            ],
            'final_response_contract' => [
                'state_workspace_id',
                'state_packet_id',
                'state_provider_role',
                'state_reservation_id',
                'state_files_changed_by_this_session',
                'state_gates_run_with_results',
                'state_scope_validator_status',
                'state_artifacts_returned',
                'state_evidence_paths_or_outputs',
                'state_remaining_blockers',
                'state_next_recommended_packet_or_action',
            ],
            'release_command' => 'php artisan atlas:ai:self-construction --release-packet --packet='.$packetId
                .' --actor='.$actor
                .' --session='.$session
                .' --reason=finished_or_blocked --json',
            'completion_command' => 'php artisan atlas:ai:self-construction --complete-packet --packet='.$packetId
                .' --actor='.$actor
                .' --session='.$session
                .' --reason=packet_scope_finished --evidence-hash=<sha256-of-final-evidence> --json',
            'stop_conditions' => (array) data_get($claimNext, 'start.stop_conditions', []),
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'completion_allowed' => false,
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_start_packet.v1',
            'status' => 'agent_start_packet_ready',
            'mode' => 'durable_local_agent_start_packet',
            'execution_allowed' => false,
            'completion_allowed' => false,
            'claim_persisted' => (bool) data_get($claimNext, 'claim_persisted'),
            'dispatch_allowed' => false,
            'packet_id' => $packetId,
            'claim_next_hash' => data_get($claimNext, 'start_hash'),
            'contract' => $contract,
            'contract_hash' => $this->stableHash($contract),
            'human_summary' => 'Agent start packet is ready: a provider-neutral AI session has a claimed packet, Forge Workspace context, role, scope, gates, artifacts and final response contract.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentIntegrationReport(array $options = []): array
    {
        $queuePayload = $this->packetQueue($options);
        $statusPayload = $this->agentExecutionStatus($options);
        $reservationPayload = $this->reservationStatus($options);
        $entries = (array) data_get($queuePayload, 'queue.entries', []);

        $completed = array_values(array_filter($entries, fn (array $entry): bool => data_get($entry, 'queue_state') === 'completed'));
        $claimed = array_values(array_filter($entries, fn (array $entry): bool => data_get($entry, 'queue_state') === 'claimed'));
        $available = array_values(array_filter($entries, fn (array $entry): bool => data_get($entry, 'queue_state') === 'available'));
        $blocked = array_values(array_filter($entries, fn (array $entry): bool => data_get($entry, 'queue_state') === 'blocked_by_dependency'));
        $withheld = array_values(array_filter($entries, fn (array $entry): bool => data_get($entry, 'queue_state') === 'withheld'));
        $completedReservations = collect((array) data_get($reservationPayload, 'ledger.completed_reservations', []))
            ->keyBy('packet_id');

        $readyToReview = array_map(function (array $entry) use ($completedReservations): array {
            $packetId = (string) data_get($entry, 'packet_id');
            $reservation = (array) $completedReservations->get($packetId, []);
            $actor = (string) data_get($entry, 'completion_actor', 'generic');
            $providerRole = $this->providerRoleForActor($actor);

            return [
                'packet_id' => $packetId,
                'lane' => data_get($entry, 'lane'),
                'objective' => data_get($entry, 'objective'),
                'completed_at' => data_get($entry, 'completed_at'),
                'actor' => data_get($entry, 'completion_actor'),
                'provider' => $providerRole['provider'],
                'role' => $providerRole['role'],
                'reservation_id' => data_get($entry, 'completed_reservation_id'),
                'evidence_hash' => data_get($reservation, 'completion_evidence_hash'),
                'allowed_files' => (array) data_get($entry, 'allowed_files', []),
                'review_expectations' => [
                    'inspect_diff_for_allowed_files_only',
                    'verify_reported_gates_against_final_response_contract',
                    'verify_workspace_artifacts_returned',
                    'run_scope_validator_for_packet_if_files_changed',
                    'do_not_merge_or_approve_from_completion_state_alone',
                ],
            ];
        }, $completed);

        $missingPackets = array_map(fn (array $entry): array => [
            'packet_id' => data_get($entry, 'packet_id'),
            'lane' => data_get($entry, 'lane'),
            'queue_state' => data_get($entry, 'queue_state'),
            'next_action' => match ((string) data_get($entry, 'queue_state')) {
                'claimed' => 'wait_for_owner_to_complete_or_release',
                'available' => 'claim_with_agent_start_packet',
                'blocked_by_dependency' => 'complete_dependencies_first',
                default => 'review_state',
            },
        ], array_values(array_filter(
            array_merge($claimed, $available, $blocked),
            fn (array $entry): bool => in_array(data_get($entry, 'queue_state'), ['claimed', 'available', 'blocked_by_dependency'], true)
        )));

        $integrationStatus = match (true) {
            $claimed !== [] => 'waiting_for_active_agents',
            $completed !== [] && ($available !== [] || $blocked !== []) => 'partial_completion_review_available',
            $completed !== [] => 'ready_for_human_integration_review',
            default => 'nothing_completed_yet',
        };

        $report = [
            'report_id' => 'AGENT-INTEGRATION-REPORT-SELF-CONSTRUCTION-0001',
            'workspace' => [
                'workspace_id' => 'FORGE-WORKSPACE-ATLAS-SELF-CONSTRUCTION-0001',
                'canonical_name' => 'Obras Shared Workspace',
                'specialization' => 'Forge Workspace',
                'obra_id' => 'OBRA-ATLAS-SELF-CONSTRUCTION-OS',
            ],
            'source_queue_hash' => data_get($queuePayload, 'queue_hash'),
            'source_execution_status_hash' => data_get($statusPayload, 'monitor_hash'),
            'source_reservation_hash' => data_get($reservationPayload, 'ledger_hash'),
            'integration_status' => $integrationStatus,
            'counts' => [
                'ready_to_review' => count($readyToReview),
                'active_agents' => count($claimed),
                'missing_packets' => count($missingPackets),
                'withheld_packets' => count($withheld),
                'ledger_events' => data_get($reservationPayload, 'ledger.event_count'),
            ],
            'ready_to_review_packets' => $readyToReview,
            'missing_packets' => $missingPackets,
            'withheld_packets' => array_map(fn (array $entry): array => [
                'packet_id' => data_get($entry, 'packet_id'),
                'lane' => data_get($entry, 'lane'),
                'reason' => data_get($entry, 'objective'),
                'forbidden_files' => (array) data_get($entry, 'forbidden_files', []),
            ], $withheld),
            'required_integrator_sequence' => [
                'refresh_agent_execution_status',
                'review_each_completed_agent_final_response_contract',
                'verify_each_completed_packet_evidence_hash',
                'verify_workspace_artifacts_returned',
                'run_packet_scope_validator_for_relevant_completed_packets',
                'run_focused_self_construction_tests',
                'run_docs_health_and_architecture_validate',
                'prepare_human_summary_before_any_merge_or_approval',
            ],
            'operator_commands' => [
                'refresh_status' => 'php artisan atlas:ai:self-construction --agent-execution-status --json',
                'integration_report' => 'php artisan atlas:ai:self-construction --agent-integration-report --json',
                'launch_plan' => 'php artisan atlas:ai:self-construction --agent-launch-plan --json',
                'packet_queue' => 'php artisan atlas:ai:self-construction --packet-queue --json',
                'scope_validator_template' => 'php artisan atlas:ai:self-construction --scope-validator --packet=<packet-id> --json',
                'focused_tests' => 'php artisan test tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php',
                'docs_health' => 'php artisan atlas:engineering:knowledge docs-health --json',
                'architecture_validate' => 'php artisan atlas:ai:architecture-validate --json',
                'diff_check' => 'git diff --check',
                'codex_compat_report' => 'php artisan atlas:ai:self-construction --codex-integration-report --json',
            ],
            'approval_boundaries' => [
                'packet_completion_is_not_code_approval',
                'agent_integration_report_is_not_merge_authority',
                'human_or_governed_receipt_must_review_before_merge',
                'hot_voice_and_kernel_work_remain_withheld',
            ],
            'execution_allowed' => false,
            'claim_persisted' => false,
            'completion_persisted' => false,
            'dispatch_allowed' => false,
            'approval_granted' => false,
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_integration_report.v1',
            'status' => 'agent_integration_report_ready',
            'mode' => 'read_only_provider_neutral_agent_integration_report',
            'execution_allowed' => false,
            'completion_allowed' => false,
            'claim_persisted' => false,
            'completion_persisted' => false,
            'ledger_write_allowed' => false,
            'dispatch_allowed' => false,
            'approval_granted' => false,
            'report' => $report,
            'report_hash' => $this->stableHash($report),
            'non_execution_guarantees' => [
                'agent_integration_report_does_not_claim_packets',
                'agent_integration_report_does_not_complete_packets',
                'agent_integration_report_does_not_approve_code',
                'agent_integration_report_does_not_auto_merge',
                'agent_integration_report_does_not_dispatch_work',
            ],
            'human_summary' => 'Agent integration report is ready: Forge Workspace completed packet evidence is consolidated for human review without approving code, merging or dispatching work.',
        ];
    }

}
