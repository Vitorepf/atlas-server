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
 * SC-01 fatia ReadinessProjectionOsEvidenceSection (Obra 4 Residual Elite).
 */
final class ReadinessProjectionOsEvidenceSection
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
            throw new \RuntimeException('ReadinessProjectionOsEvidenceSection mother not bound for '.$name);
        }
        $method = new \ReflectionMethod($this->mother, $name);

        return $method->invokeArgs($this->mother, $arguments);
    }


    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function atlasSelfConstructionOsHandoffStatus(array $options = []): array
    {
        $options = $this->withTerminalLoopOperationalProofPayload($options);
        $handoffRelativePath = 'docs/engineering-knowledge-base/atlas-self-construction-os-handoff.md';
        $rootRelativePath = 'docs/engineering-knowledge-base/atlas-ai-self-construction-os.md';
        $handoffPath = base_path($handoffRelativePath);
        $rootPath = base_path($rootRelativePath);
        $handoffDoc = is_file($handoffPath) ? (string) file_get_contents($handoffPath) : '';
        $rootDoc = is_file($rootPath) ? (string) file_get_contents($rootPath) : '';
        $completionAudit = $this->atlasSelfConstructionOsCompletionAuditStatus($options);
        $completionEvidence = $this->atlasSelfConstructionOsCompletionEvidenceStatus($options);
        $completionAuditStatus = (array) data_get($completionAudit, 'agent_control_plane_atlas_self_construction_os_completion_audit_status', []);
        $completionEvidenceStatus = (array) data_get(
            $completionEvidence,
            'agent_control_plane_atlas_self_construction_os_completion_evidence_status',
            $completionEvidence,
        );
        $releaseDossierStatus = $this->agentControlPlaneReleaseDossierStatus(['skip_simulator' => true]);
        $controlPlane = $this->agentControlPlane($options);
        $controlPlaneBody = (array) data_get($controlPlane, 'control_plane', $controlPlane);
        $chainIntegrity = $this->agentControlPlaneChainIntegrityCertificationStatus($options);
        $chainIntegrityStatus = (array) data_get($chainIntegrity, 'agent_control_plane_chain_integrity_certification_status', []);
        $controlPlaneNextRequiredSlice = (string) data_get($controlPlaneBody, 'persistent_runtime.next_required_slice', '');
        $controlPlaneNextBuildSlices = (array) data_get($controlPlaneBody, 'next_build_slices', []);
        $chainCurrentNextRequiredSlice = (string) data_get($chainIntegrityStatus, 'current_next_required_slice', '');
        $chainExpectedNextRequiredSlice = (string) data_get($chainIntegrityStatus, 'expected_next_required_slice', '');
        $chainPointerAligned = $controlPlaneNextRequiredSlice !== ''
            && $controlPlaneNextRequiredSlice === $chainCurrentNextRequiredSlice
            && $chainCurrentNextRequiredSlice === $chainExpectedNextRequiredSlice
            && in_array($controlPlaneNextRequiredSlice, $controlPlaneNextBuildSlices, true);
        $rawFailedCriteria = (array) data_get($completionAudit, 'agent_control_plane_atlas_self_construction_os_completion_audit_status.failed_criteria', []);
        $releaseDossierGreen = (string) data_get($releaseDossierStatus, 'agent_control_plane_release_dossier_status.status', '') === 'available'
            && ! (bool) data_get($releaseDossierStatus, 'agent_control_plane_release_dossier_status.baseline_snapshot_capture_required', true)
            && (int) data_get($releaseDossierStatus, 'agent_control_plane_release_dossier_status.blocker_count', 0) === 0;
        $failedCriteria = $releaseDossierGreen
            ? array_values(array_diff($rawFailedCriteria, ['release_dossier_green']))
            : $rawFailedCriteria;
        $finalClosureFailedChecks = (array) data_get($completionEvidence, 'failed_checks', []);
        $canonicalFinalBlockers = [
            'runtime_gap_matrix_all_runtime_y',
            'human_signed_os_complete_receipt_present',
            'end_to_end_real_provider_smoke_green',
        ];
        $humanBlockers = array_values(array_intersect($failedCriteria, [
            'runtime_gap_matrix_all_runtime_y',
            'human_signed_os_complete_receipt_present',
        ]));
        $realProviderBlockers = array_values(array_intersect($failedCriteria, [
            'end_to_end_real_provider_smoke_green',
        ]));
        $technicalBlockers = array_values(array_diff($failedCriteria, array_merge($humanBlockers, $realProviderBlockers)));
        $releaseDossierRefreshCommand = 'php artisan atlas:ai:self-construction --agent-control-plane-replay-snapshot-store-capture --json';
        $releaseDossierStatusCommand = 'php artisan atlas:ai:self-construction --agent-control-plane-release-dossier-status --json';
        $certificationStatusBatchCommand = 'php artisan atlas:ai:self-construction --agent-control-plane-certification-status-batch-status --json';
        $completionEvidenceSubmissionPreflightCommand = 'php artisan atlas:ai:self-construction --atlas-self-construction-completion-evidence-submission-preflight-status --json';
        $nextRequiredCommand = (string) data_get($completionEvidence, 'next_required_command', '');
        $nextRequiredPersistCommand = (string) data_get($completionEvidence, 'next_required_persist_command', '');
        $completionAuditWithProofCommand = (string) data_get($completionEvidence, 'completion_audit_with_canonical_terminal_loop_operational_proof_command', '');
        $closureArtifactSequence = (array) data_get($completionEvidence, 'closure_artifact_sequence', []);
        $promptToArtifactChecklist = (array) data_get($completionEvidence, 'prompt_to_artifact_checklist', []);
        $operatorResumeCommandSequence = array_values(array_filter([
            [
                'step' => 'inspect_handoff',
                'command' => 'php artisan atlas:ai:self-construction --atlas-self-construction-os-handoff-status --json',
                'required_before_persist' => true,
            ],
            [
                'step' => 'inspect_release_dossier',
                'command' => $releaseDossierStatusCommand,
                'required_before_persist' => true,
            ],
            [
                'step' => 'refresh_release_dossier_if_stale',
                'command' => $releaseDossierRefreshCommand,
                'required_before_persist' => ! $releaseDossierGreen,
            ],
            [
                'step' => 'verify_certification_status_batch',
                'command' => $certificationStatusBatchCommand,
                'required_before_persist' => true,
            ],
            [
                'step' => 'run_completion_evidence_submission_preflight',
                'command' => $completionEvidenceSubmissionPreflightCommand,
                'required_before_persist' => true,
            ],
            [
                'step' => 'draft_current_operator_artifact',
                'command' => $nextRequiredCommand,
                'required_before_persist' => true,
            ],
            [
                'step' => 'persist_current_operator_artifact',
                'command' => $nextRequiredPersistCommand,
                'required_before_persist' => true,
            ],
            [
                'step' => 'rerun_completion_audit_with_terminal_loop_proof',
                'command' => $completionAuditWithProofCommand,
                'required_before_persist' => false,
            ],
        ], static fn (array $step): bool => (string) ($step['command'] ?? '') !== ''));
        $postEvidenceGuardrailSequence = [
            [
                'step' => 'docs_health',
                'command' => 'php artisan atlas:engineering:knowledge docs-health --json',
                'required_after_persist' => true,
            ],
            [
                'step' => 'architecture_validate',
                'command' => 'php artisan atlas:ai:architecture-validate --json',
                'required_after_persist' => true,
            ],
            [
                'step' => 'diff_check',
                'command' => 'git diff --check',
                'required_after_persist' => true,
            ],
        ];
        preg_match_all('/<[^>]+>|@\/path\/to\/[^\s]+/', $nextRequiredCommand, $handoffResumePlaceholderMatches);
        $handoffResumePlaceholders = array_values(array_unique($handoffResumePlaceholderMatches[0] ?? []));
        $operatorResumePacket = [
            'schema_version' => 'atlas.self_construction.operator_evidence_submission_resume_packet.v1',
            'status' => $nextRequiredCommand === '' ? 'blocked_next_required_command_missing' : 'blocked_operator_action_required',
            'current_required_operator_artifact' => (string) data_get($completionEvidence, 'current_required_operator_artifact', ''),
            'next_required_submission' => (string) data_get($completionEvidence, 'current_required_operator_artifact', ''),
            'command_to_copy' => $nextRequiredCommand,
            'command_to_copy_hash' => $nextRequiredCommand === '' ? '' : hash('sha256', $nextRequiredCommand),
            'copy_safe' => $handoffResumePlaceholders === [] && $nextRequiredCommand !== '',
            'placeholder_count' => count($handoffResumePlaceholders),
            'placeholders' => $handoffResumePlaceholders,
            'expected_persist_command_template' => $nextRequiredPersistCommand,
            'why_not_automatic' => 'requires_operator_signature_or_real_provider_evidence',
            'requires_operator_review' => true,
            'can_persist_from_readiness' => false,
            'terminal_loop_operational_proof_command' => 'php artisan atlas:ai:self-construction --agent-control-plane-terminal-loop-operational-proof-status --json',
            'completion_audit_with_canonical_terminal_loop_operational_proof_command' => $completionAuditWithProofCommand,
            'success_predicate_after_all_actions' => 'completion_audit.status=complete AND completion_allowed=true AND failed_count=0',
            'failure_policy' => [
                'stop_if_placeholder_remains',
                'stop_if_copy_safe_is_false',
                'stop_if_operator_review_missing',
                'stop_if_verifier_status_is_not_passed',
                'stop_if_persist_command_is_run_from_handoff_surface',
                'stop_if_completion_audit_remains_incomplete',
            ],
            'non_execution_guarantees' => [
                'resume_packet_does_not_execute_command',
                'resume_packet_does_not_persist_receipts',
                'resume_packet_does_not_sign_for_operator',
                'resume_packet_does_not_call_provider',
                'resume_packet_does_not_spend_tokens',
                'resume_packet_does_not_dispatch_work',
                'resume_packet_does_not_promote_completion',
            ],
        ];
        $operatorResumePacket['resume_packet_hash'] = $this->stableHash($operatorResumePacket);

        $result = [
            'schema_version' => 'atlas.self_construction.os_handoff.v1',
            'status' => is_file($handoffPath) ? 'available' : 'missing_handoff_doc',
            'mode' => 'read_only_atlas_self_construction_os_handoff',
            'handoff_doc_path' => $handoffRelativePath,
            'handoff_doc_present' => is_file($handoffPath),
            'handoff_doc_hash' => $handoffDoc === '' ? '' : hash('sha256', $handoffDoc),
            'root_doc_path' => $rootRelativePath,
            'root_doc_links_handoff' => str_contains($rootDoc, $handoffRelativePath)
                || str_contains($rootDoc, 'atlas-self-construction-os-handoff.md'),
            'completion_audit_status' => (string) data_get($completionAuditStatus, 'status', ''),
            'completion_audit_raw_failed_count' => count($rawFailedCriteria),
            'completion_audit_raw_failed_criteria' => $rawFailedCriteria,
            'completion_audit_failed_count' => count($failedCriteria),
            'completion_audit_failed_criteria' => $failedCriteria,
            'canonical_final_blockers' => $canonicalFinalBlockers,
            'canonical_final_blocker_count' => count($canonicalFinalBlockers),
            'final_closure_failed_checks' => $finalClosureFailedChecks,
            'final_closure_failed_count' => count($finalClosureFailedChecks),
            'current_required_operator_artifact' => (string) data_get($completionEvidence, 'current_required_operator_artifact', ''),
            'next_required_command' => $nextRequiredCommand,
            'next_required_persist_command' => $nextRequiredPersistCommand,
            'runtime_gap_matrix_hash' => (string) data_get($completionEvidenceStatus, 'runtime_gap_matrix_hash', ''),
            'expected_runtime_gap_matrix_hash_for_promotion_receipt' => (string) data_get($completionEvidenceStatus, 'expected_runtime_gap_matrix_hash_for_promotion_receipt', ''),
            'runtime_promotion_basis_hash' => (string) data_get($completionEvidenceStatus, 'runtime_promotion_basis_hash', ''),
            'runtime_promotion_closure_basis_hash' => (string) data_get($completionEvidenceStatus, 'runtime_promotion_closure_basis_hash', ''),
            'completion_audit_with_canonical_terminal_loop_operational_proof_command' => $completionAuditWithProofCommand,
            'certification_status_batch_command' => $certificationStatusBatchCommand,
            'completion_evidence_submission_preflight_command' => $completionEvidenceSubmissionPreflightCommand,
            'release_dossier_refresh_command' => $releaseDossierRefreshCommand,
            'release_dossier_status_command' => $releaseDossierStatusCommand,
            'operator_resume_command_sequence' => $operatorResumeCommandSequence,
            'operator_resume_command_count' => count($operatorResumeCommandSequence),
            'operator_resume_packet' => $operatorResumePacket,
            'operator_resume_packet_hash' => (string) $operatorResumePacket['resume_packet_hash'],
            'operator_resume_packet_command_to_copy' => (string) $operatorResumePacket['command_to_copy'],
            'operator_resume_packet_copy_safe' => (bool) $operatorResumePacket['copy_safe'],
            'operator_resume_packet_placeholder_count' => (int) $operatorResumePacket['placeholder_count'],
            'operator_resume_packet_requires_operator_review' => (bool) $operatorResumePacket['requires_operator_review'],
            'post_evidence_guardrail_sequence' => $postEvidenceGuardrailSequence,
            'post_evidence_guardrail_count' => count($postEvidenceGuardrailSequence),
            'closure_artifact_sequence' => $closureArtifactSequence,
            'closure_artifact_sequence_count' => count($closureArtifactSequence),
            'closure_artifact_sequence_hash' => (string) data_get($completionEvidence, 'closure_artifact_sequence_hash', ''),
            'prompt_to_artifact_checklist' => $promptToArtifactChecklist,
            'prompt_to_artifact_checklist_count' => count($promptToArtifactChecklist),
            'prompt_to_artifact_checklist_passed_count' => (int) data_get($completionEvidence, 'prompt_to_artifact_checklist_passed_count', 0),
            'prompt_to_artifact_checklist_hash' => (string) data_get($completionEvidence, 'prompt_to_artifact_checklist_hash', ''),
            'release_dossier_status' => (string) data_get($releaseDossierStatus, 'agent_control_plane_release_dossier_status.status', ''),
            'release_dossier_closure_status' => (string) data_get($releaseDossierStatus, 'agent_control_plane_release_dossier_status.closure_status', ''),
            'release_dossier_baseline_snapshot_state' => (string) data_get($releaseDossierStatus, 'agent_control_plane_release_dossier_status.baseline_snapshot_state', ''),
            'release_dossier_baseline_snapshot_capture_required' => (bool) data_get($releaseDossierStatus, 'agent_control_plane_release_dossier_status.baseline_snapshot_capture_required', false),
            'release_dossier_blocker_count' => (int) data_get($releaseDossierStatus, 'agent_control_plane_release_dossier_status.blocker_count', 0),
            'release_dossier_warning_count' => (int) data_get($releaseDossierStatus, 'agent_control_plane_release_dossier_status.warning_count', 0),
            'release_dossier_green_effective' => $releaseDossierGreen,
            'chain_integrity_status' => (string) data_get($chainIntegrityStatus, 'status', ''),
            'chain_integrity_violation_count' => (int) data_get($chainIntegrityStatus, 'violation_count', 0),
            'chain_integrity_warning_count' => (int) data_get($chainIntegrityStatus, 'warning_count', 0),
            'chain_integrity_invariants_all_true' => (bool) data_get($chainIntegrityStatus, 'invariants_all_true', false),
            'chain_integrity_runtime_safety_all_false' => (bool) data_get($chainIntegrityStatus, 'runtime_safety_all_false', false),
            'chain_current_next_required_slice' => $chainCurrentNextRequiredSlice,
            'chain_expected_next_required_slice' => $chainExpectedNextRequiredSlice,
            'control_plane_next_required_slice' => $controlPlaneNextRequiredSlice,
            'control_plane_next_build_slices' => $controlPlaneNextBuildSlices,
            'control_plane_not_yet_runtime_capable' => (array) data_get($controlPlaneBody, 'not_yet_runtime_capable', []),
            'control_plane_not_yet_runtime_capable_count' => count((array) data_get($controlPlaneBody, 'not_yet_runtime_capable', [])),
            'control_plane_pointer_aligned_with_chain_integrity' => $chainPointerAligned,
            'human_blockers' => $humanBlockers,
            'human_blocker_count' => count($humanBlockers),
            'real_provider_blockers' => $realProviderBlockers,
            'real_provider_blocker_count' => count($realProviderBlockers),
            'technical_blockers' => $technicalBlockers,
            'technical_blocker_count' => count($technicalBlockers),
            'terminal_loop_operational_proof_supplied' => (bool) data_get($completionAuditStatus, 'terminal_loop_operational_proof_supplied', false),
            'terminal_loop_operational_proof_passed' => (bool) data_get($completionAuditStatus, 'terminal_loop_operational_proof_passed', false),
            'terminal_loop_operational_proof_required_before_completion_claim' => true,
            'completion_audit_without_terminal_loop_operational_proof_is_diagnostic_only' => true,
            'source_completion_allowed' => (bool) data_get($completionAuditStatus, 'completion_allowed', false),
            'source_completion_claim_allowed' => (bool) data_get($completionAuditStatus, 'completion_claim_allowed', false),
            'completion_claim_requires_completion_audit' => true,
            'completion_claim_authority_verdict_status' => (string) data_get($completionAuditStatus, 'completion_claim_authority_verdict_status', ''),
            'completion_claim_authority' => (string) data_get($completionAuditStatus, 'completion_claim_authority', 'atlas_self_construction_os_completion_audit'),
            'completion_claim_required_completion_predicate' => (string) data_get($completionAuditStatus, 'completion_claim_required_completion_predicate', 'completion_audit.status=complete AND completion_allowed=true AND failed_count=0'),
            'completion_claim_external_agent_claim_accepted' => (bool) data_get($completionAuditStatus, 'completion_claim_external_agent_claim_accepted', false),
            'completion_claim_external_agent_claim_can_override_audit' => (bool) data_get($completionAuditStatus, 'completion_claim_external_agent_claim_can_override_audit', false),
            'completion_claim_external_agent_claim_can_mark_os_complete' => (bool) data_get($completionAuditStatus, 'completion_claim_external_agent_claim_can_mark_os_complete', false),
            'completion_claim_missing_evidence_count' => (int) data_get($completionAuditStatus, 'completion_claim_missing_evidence_count', 0),
            'completion_claim_authority_verdict_hash' => (string) data_get($completionAuditStatus, 'completion_claim_authority_verdict_hash', ''),
            'completion_evidence_claim_authority_status' => (string) data_get($completionEvidenceStatus, 'completion_evidence_claim_authority.status', ''),
            'completion_evidence_claim_allowed_from_evidence_status' => (bool) data_get($completionEvidenceStatus, 'completion_evidence_claim_authority.completion_claim_allowed_from_evidence_status', false),
            'completion_evidence_external_agent_claim_accepted' => (bool) data_get($completionEvidenceStatus, 'completion_evidence_claim_authority.external_agent_claim_accepted', false),
            'completion_evidence_external_agent_claim_can_mark_os_complete' => (bool) data_get($completionEvidenceStatus, 'completion_evidence_claim_authority.external_agent_claim_can_mark_os_complete', false),
            'completion_evidence_claim_authority_hash' => (string) data_get($completionEvidenceStatus, 'completion_evidence_claim_authority.completion_evidence_claim_authority_hash', ''),
            'completion_claim_blocked_until_audit_complete' => true,
            'completion_allowed' => false,
            'completion_claim_allowed' => false,
            'self_programming_allowed' => false,
            'runtime_activation_allowed' => false,
            'provider_call_allowed' => false,
            'token_spend_allowed' => false,
            'non_execution_guarantees' => [
                'os_handoff_status_does_not_start_codex',
                'os_handoff_status_does_not_call_provider',
                'os_handoff_status_does_not_dispatch_work',
                'os_handoff_status_does_not_spend_tokens',
                'os_handoff_status_does_not_enable_self_programming',
                'os_handoff_status_does_not_persist_evidence',
            ],
            'human_summary' => 'Atlas Self-Construction OS handoff is available for operator/provider evidence closure; it does not replace final receipts or real provider smoke.',
        ];
        $result['handoff_status_hash'] = $this->stableHash($result);

        return $this->wrapCertificationWorkbenchStatus(
            keyPrefix: 'atlas_self_construction_os_handoff',
            label: 'Atlas Self-Construction OS Handoff',
            payload: $result,
            statusKey: 'status',
            extraStatusFields: [
                'handoff_doc_present' => (bool) data_get($result, 'handoff_doc_present', false),
                'handoff_doc_hash' => (string) data_get($result, 'handoff_doc_hash', ''),
                'root_doc_links_handoff' => (bool) data_get($result, 'root_doc_links_handoff', false),
                'completion_audit_status' => (string) data_get($result, 'completion_audit_status', ''),
                'completion_audit_raw_failed_count' => (int) data_get($result, 'completion_audit_raw_failed_count', 0),
                'completion_audit_raw_failed_criteria' => (array) data_get($result, 'completion_audit_raw_failed_criteria', []),
                'completion_audit_failed_count' => (int) data_get($result, 'completion_audit_failed_count', 0),
                'failed_criteria' => (array) data_get($result, 'completion_audit_failed_criteria', []),
                'failed_count' => (int) data_get($result, 'completion_audit_failed_count', 0),
                'canonical_final_blockers' => (array) data_get($result, 'canonical_final_blockers', []),
                'canonical_final_blocker_count' => (int) data_get($result, 'canonical_final_blocker_count', 0),
                'final_closure_failed_checks' => (array) data_get($result, 'final_closure_failed_checks', []),
                'final_closure_failed_count' => (int) data_get($result, 'final_closure_failed_count', 0),
                'current_required_operator_artifact' => (string) data_get($result, 'current_required_operator_artifact', ''),
                'next_required_command' => (string) data_get($result, 'next_required_command', ''),
                'next_required_persist_command' => (string) data_get($result, 'next_required_persist_command', ''),
                'runtime_gap_matrix_hash' => (string) data_get($result, 'runtime_gap_matrix_hash', ''),
                'expected_runtime_gap_matrix_hash_for_promotion_receipt' => (string) data_get($result, 'expected_runtime_gap_matrix_hash_for_promotion_receipt', ''),
                'runtime_promotion_basis_hash' => (string) data_get($result, 'runtime_promotion_basis_hash', ''),
                'runtime_promotion_closure_basis_hash' => (string) data_get($result, 'runtime_promotion_closure_basis_hash', ''),
                'completion_audit_with_canonical_terminal_loop_operational_proof_command' => (string) data_get($result, 'completion_audit_with_canonical_terminal_loop_operational_proof_command', ''),
                'certification_status_batch_command' => (string) data_get($result, 'certification_status_batch_command', ''),
                'completion_evidence_submission_preflight_command' => (string) data_get($result, 'completion_evidence_submission_preflight_command', ''),
                'release_dossier_refresh_command' => (string) data_get($result, 'release_dossier_refresh_command', ''),
                'release_dossier_status_command' => (string) data_get($result, 'release_dossier_status_command', ''),
                'operator_resume_command_sequence' => (array) data_get($result, 'operator_resume_command_sequence', []),
                'operator_resume_command_count' => (int) data_get($result, 'operator_resume_command_count', 0),
                'operator_resume_packet' => (array) data_get($result, 'operator_resume_packet', []),
                'operator_resume_packet_hash' => (string) data_get($result, 'operator_resume_packet_hash', ''),
                'operator_resume_packet_command_to_copy' => (string) data_get($result, 'operator_resume_packet_command_to_copy', ''),
                'operator_resume_packet_copy_safe' => (bool) data_get($result, 'operator_resume_packet_copy_safe', false),
                'operator_resume_packet_placeholder_count' => (int) data_get($result, 'operator_resume_packet_placeholder_count', 0),
                'operator_resume_packet_requires_operator_review' => (bool) data_get($result, 'operator_resume_packet_requires_operator_review', false),
                'post_evidence_guardrail_sequence' => (array) data_get($result, 'post_evidence_guardrail_sequence', []),
                'post_evidence_guardrail_count' => (int) data_get($result, 'post_evidence_guardrail_count', 0),
                'closure_artifact_sequence' => (array) data_get($result, 'closure_artifact_sequence', []),
                'closure_artifact_sequence_count' => (int) data_get($result, 'closure_artifact_sequence_count', 0),
                'closure_artifact_sequence_hash' => (string) data_get($result, 'closure_artifact_sequence_hash', ''),
                'prompt_to_artifact_checklist' => (array) data_get($result, 'prompt_to_artifact_checklist', []),
                'prompt_to_artifact_checklist_count' => (int) data_get($result, 'prompt_to_artifact_checklist_count', 0),
                'prompt_to_artifact_checklist_passed_count' => (int) data_get($result, 'prompt_to_artifact_checklist_passed_count', 0),
                'prompt_to_artifact_checklist_hash' => (string) data_get($result, 'prompt_to_artifact_checklist_hash', ''),
                'release_dossier_status' => (string) data_get($result, 'release_dossier_status', ''),
                'release_dossier_closure_status' => (string) data_get($result, 'release_dossier_closure_status', ''),
                'release_dossier_baseline_snapshot_state' => (string) data_get($result, 'release_dossier_baseline_snapshot_state', ''),
                'release_dossier_baseline_snapshot_capture_required' => (bool) data_get($result, 'release_dossier_baseline_snapshot_capture_required', false),
                'release_dossier_blocker_count' => (int) data_get($result, 'release_dossier_blocker_count', 0),
                'release_dossier_warning_count' => (int) data_get($result, 'release_dossier_warning_count', 0),
                'release_dossier_green_effective' => (bool) data_get($result, 'release_dossier_green_effective', false),
                'chain_integrity_status' => (string) data_get($result, 'chain_integrity_status', ''),
                'chain_integrity_violation_count' => (int) data_get($result, 'chain_integrity_violation_count', 0),
                'chain_integrity_warning_count' => (int) data_get($result, 'chain_integrity_warning_count', 0),
                'chain_integrity_invariants_all_true' => (bool) data_get($result, 'chain_integrity_invariants_all_true', false),
                'chain_integrity_runtime_safety_all_false' => (bool) data_get($result, 'chain_integrity_runtime_safety_all_false', false),
                'chain_current_next_required_slice' => (string) data_get($result, 'chain_current_next_required_slice', ''),
                'chain_expected_next_required_slice' => (string) data_get($result, 'chain_expected_next_required_slice', ''),
                'control_plane_next_required_slice' => (string) data_get($result, 'control_plane_next_required_slice', ''),
                'control_plane_next_build_slices' => (array) data_get($result, 'control_plane_next_build_slices', []),
                'control_plane_not_yet_runtime_capable' => (array) data_get($result, 'control_plane_not_yet_runtime_capable', []),
                'control_plane_not_yet_runtime_capable_count' => (int) data_get($result, 'control_plane_not_yet_runtime_capable_count', 0),
                'control_plane_pointer_aligned_with_chain_integrity' => (bool) data_get($result, 'control_plane_pointer_aligned_with_chain_integrity', false),
                'human_blocker_count' => (int) data_get($result, 'human_blocker_count', 0),
                'human_blockers' => (array) data_get($result, 'human_blockers', []),
                'real_provider_blocker_count' => (int) data_get($result, 'real_provider_blocker_count', 0),
                'real_provider_blockers' => (array) data_get($result, 'real_provider_blockers', []),
                'technical_blocker_count' => (int) data_get($result, 'technical_blocker_count', 0),
                'technical_blockers' => (array) data_get($result, 'technical_blockers', []),
                'terminal_loop_operational_proof_supplied' => (bool) data_get($result, 'terminal_loop_operational_proof_supplied', false),
                'terminal_loop_operational_proof_passed' => (bool) data_get($result, 'terminal_loop_operational_proof_passed', false),
                'terminal_loop_operational_proof_required_before_completion_claim' => true,
                'completion_audit_without_terminal_loop_operational_proof_is_diagnostic_only' => true,
                'source_completion_allowed' => (bool) data_get($result, 'source_completion_allowed', false),
                'source_completion_claim_allowed' => (bool) data_get($result, 'source_completion_claim_allowed', false),
                'completion_claim_requires_completion_audit' => (bool) data_get($result, 'completion_claim_requires_completion_audit', true),
                'completion_claim_authority_verdict_status' => (string) data_get($result, 'completion_claim_authority_verdict_status', ''),
                'completion_claim_authority' => (string) data_get($result, 'completion_claim_authority', ''),
                'completion_claim_required_completion_predicate' => (string) data_get($result, 'completion_claim_required_completion_predicate', ''),
                'completion_claim_external_agent_claim_accepted' => (bool) data_get($result, 'completion_claim_external_agent_claim_accepted', false),
                'completion_claim_external_agent_claim_can_override_audit' => (bool) data_get($result, 'completion_claim_external_agent_claim_can_override_audit', false),
                'completion_claim_external_agent_claim_can_mark_os_complete' => (bool) data_get($result, 'completion_claim_external_agent_claim_can_mark_os_complete', false),
                'completion_claim_missing_evidence_count' => (int) data_get($result, 'completion_claim_missing_evidence_count', 0),
                'completion_claim_authority_verdict_hash' => (string) data_get($result, 'completion_claim_authority_verdict_hash', ''),
                'completion_evidence_claim_authority_status' => (string) data_get($result, 'completion_evidence_claim_authority_status', ''),
                'completion_evidence_claim_allowed_from_evidence_status' => (bool) data_get($result, 'completion_evidence_claim_allowed_from_evidence_status', false),
                'completion_evidence_external_agent_claim_accepted' => (bool) data_get($result, 'completion_evidence_external_agent_claim_accepted', false),
                'completion_evidence_external_agent_claim_can_mark_os_complete' => (bool) data_get($result, 'completion_evidence_external_agent_claim_can_mark_os_complete', false),
                'completion_evidence_claim_authority_hash' => (string) data_get($result, 'completion_evidence_claim_authority_hash', ''),
                'completion_claim_blocked_until_audit_complete' => (bool) data_get($result, 'completion_claim_blocked_until_audit_complete', true),
                'completion_allowed' => false,
                'completion_claim_allowed' => false,
                'self_programming_allowed' => false,
                'runtime_activation_allowed' => false,
                'provider_call_allowed' => false,
                'token_spend_allowed' => false,
            ],
        );
    }


    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function atlasSelfConstructionOperatorEvidenceSubmissionReadinessStatus(array $options = []): array
    {
        $options = $this->withTerminalLoopOperationalProofPayload($options);

        $result = (new AtlasSelfConstructionOperatorEvidenceSubmissionReadinessService($this->mother ?? throw new \RuntimeException("mother unbound")))->build([
            'runtime_promotion_receipt' => (array) ($options['runtime_promotion_receipt'] ?? $this->decodeJsonOption($options['runtime_promotion_receipt_json'] ?? null)),
            'real_provider_smoke' => (array) ($options['real_provider_smoke'] ?? $this->decodeJsonOption($options['real_provider_smoke_json'] ?? null)),
            'completion_receipt' => (array) ($options['completion_receipt'] ?? $this->decodeJsonOption($options['completion_receipt_json'] ?? null)),
            'human_completion_receipt_context' => (array) ($options['human_completion_receipt_context'] ?? []),
            'operator_draft_workspace_path' => (string) ($options['operator_draft_workspace_path'] ?? ''),
            'agent_control_plane_terminal_loop_operational_proof' => (array) ($options['agent_control_plane_terminal_loop_operational_proof'] ?? []),
        ]);
        $externalCompletionClaimPolicyViolationFields = $this->externalCompletionClaimPolicyViolationFields($result, [
            'external_agent_claim_accepted',
            'external_agent_claim_can_mark_os_complete',
            'external_agent_claim_can_override_audit',
        ]);
        $diagnosticArtifactIds = [
            'runtime_promotion_receipt',
            'real_provider_smoke',
            'human_completion_receipt',
        ];
        $diagnosticTotalPlaceholderCount = array_sum(array_map(
            fn (string $artifactId): int => count((array) data_get($result, "diagnostics.{$artifactId}.placeholders", [])),
            $diagnosticArtifactIds,
        ));
        $diagnosticTotalErrorCount = array_sum(array_map(
            fn (string $artifactId): int => count((array) data_get($result, "diagnostics.{$artifactId}.errors", [])),
            $diagnosticArtifactIds,
        ));
        $diagnosticAllSupplied = ! in_array(false, array_map(
            fn (string $artifactId): bool => (bool) data_get($result, "diagnostics.{$artifactId}.supplied", false),
            $diagnosticArtifactIds,
        ), true);
        $diagnosticReadyCount = count(array_filter(
            $diagnosticArtifactIds,
            fn (string $artifactId): bool => (bool) data_get($result, "diagnostics.{$artifactId}.ready", false),
        ));
        $currentRequiredOperatorArtifact = (string) data_get(
            $result,
            'operator_evidence_sequence_integrity.current_required_artifact',
            data_get($result, 'next_required', ''),
        );
        $operatorNextActionCommandToCopy = (string) data_get($result, 'operator_next_action.shell_packet.command_to_copy', '');
        $operatorNextActionExactPersistCommand = (string) data_get($result, 'operator_next_action.exact_persist_command', '');
        if ($operatorNextActionExactPersistCommand === '') {
            $operatorNextActionExactPersistCommand = (string) data_get($result, 'operator_next_action.expected_persist_command_template', '');
        }
        $canonicalSubmissionNextStepId = (string) data_get($result, 'canonical_submission_persistence_plan.next_step_id', '');
        $canonicalSubmissionNextStep = [];
        foreach ((array) data_get($result, 'canonical_submission_persistence_plan.steps', []) as $canonicalSubmissionStep) {
            if ((string) data_get($canonicalSubmissionStep, 'id', '') === $canonicalSubmissionNextStepId) {
                $canonicalSubmissionNextStep = (array) $canonicalSubmissionStep;
                break;
            }
        }
        $canonicalSubmissionNextStepReady = (bool) data_get($canonicalSubmissionNextStep, 'ready_for_explicit_operator_persistence', false);
        $canonicalSubmissionNextStepRepairCommand = $canonicalSubmissionNextStepReady
            ? ''
            : $operatorNextActionCommandToCopy;
        $canonicalSubmissionNextStepRepairFileCommand = '';
        $canonicalSubmissionNextStepRepairFileCommandPayloadPath = '';
        if (
            ! $canonicalSubmissionNextStepReady
            && (string) data_get($canonicalSubmissionNextStep, 'artifact', '') === 'runtime_promotion_receipt'
            && $canonicalSubmissionNextStepRepairCommand !== ''
        ) {
            $canonicalSubmissionNextStepRepairFileCommandPayloadPath = '.agent_control_plane_atlas_self_construction_runtime_promotion_receipt_draft.receipt_payload';
            $canonicalSubmissionNextStepRepairFileCommand = 'mkdir -p storage/app/private/atlas/self-construction/operator-submissions && '
                .$canonicalSubmissionNextStepRepairCommand
                .' | jq \''.$canonicalSubmissionNextStepRepairFileCommandPayloadPath.'\''
                .' > storage/app/private/atlas/self-construction/operator-submissions/runtime-promotion.json';
        }
        $canonicalSubmissionNextStepRepairFileCommandPlaceholders = $canonicalSubmissionNextStepRepairFileCommand === ''
            ? []
            : $this->placeholderFieldsFromCommand($canonicalSubmissionNextStepRepairFileCommand);
        $canonicalSubmissionNextStepRepairFileCommandCopySafe = $canonicalSubmissionNextStepRepairFileCommand !== ''
            && $canonicalSubmissionNextStepRepairFileCommandPlaceholders === [];
        $canonicalSubmissionNextStepStaleContextHashes = (array) data_get($canonicalSubmissionNextStep, 'stale_context_hashes', []);
        $canonicalSubmissionNextStepFreshDraftRequired = (bool) data_get($canonicalSubmissionNextStep, 'fresh_operator_draft_required', false);

        $operatorResumePacket = [
            'schema_version' => 'atlas.self_construction.operator_evidence_submission_resume_packet.v1',
            'status' => (string) data_get($result, 'operator_next_action.status', ''),
            'current_required_operator_artifact' => $currentRequiredOperatorArtifact,
            'next_required_submission' => (string) data_get($result, 'next_required', ''),
            'command_to_copy' => $operatorNextActionCommandToCopy,
            'command_to_copy_hash' => $operatorNextActionCommandToCopy === '' ? '' : hash('sha256', $operatorNextActionCommandToCopy),
            'copy_safe' => (bool) data_get($result, 'operator_next_action.shell_packet.copy_safe', false),
            'placeholder_count' => (int) data_get($result, 'operator_next_action.shell_packet.placeholder_count', 0),
            'placeholders' => (array) data_get($result, 'operator_next_action.placeholder_fields_to_replace', []),
            'expected_persist_command_template' => $operatorNextActionExactPersistCommand,
            'canonical_submission_repair_file_command' => $canonicalSubmissionNextStepRepairFileCommand,
            'canonical_submission_repair_file_command_hash' => $canonicalSubmissionNextStepRepairFileCommand === '' ? '' : hash('sha256', $canonicalSubmissionNextStepRepairFileCommand),
            'canonical_submission_repair_file_command_payload_path' => $canonicalSubmissionNextStepRepairFileCommandPayloadPath,
            'canonical_submission_repair_file_command_copy_safe' => $canonicalSubmissionNextStepRepairFileCommandCopySafe,
            'canonical_submission_repair_file_command_placeholder_fields' => $canonicalSubmissionNextStepRepairFileCommandPlaceholders,
            'canonical_submission_stale_context_hashes' => $canonicalSubmissionNextStepStaleContextHashes,
            'canonical_submission_stale_context_hash_count' => count($canonicalSubmissionNextStepStaleContextHashes),
            'fresh_canonical_submission_draft_required' => $canonicalSubmissionNextStepFreshDraftRequired,
            'why_not_automatic' => (string) data_get($result, 'operator_next_action.why_not_automatic', ''),
            'requires_operator_review' => true,
            'can_persist_from_readiness' => false,
            'terminal_loop_operational_proof_command' => (string) data_get($result, 'operator_completion_proof_bundle.proof_commands.terminal_loop_operational_proof', ''),
            'completion_audit_with_canonical_terminal_loop_operational_proof_command' => (string) data_get($result, 'operator_completion_proof_bundle.proof_commands.completion_audit_with_canonical_terminal_loop_operational_proof', ''),
            'success_predicate_after_all_actions' => 'completion_audit.status=complete AND completion_allowed=true AND failed_count=0',
            'failure_policy' => [
                'stop_if_placeholder_remains',
                'stop_if_copy_safe_is_false',
                'stop_if_operator_review_missing',
                'stop_if_verifier_status_is_not_passed',
                'stop_if_stale_context_hashes_detected',
                'stop_if_persist_command_is_run_from_readiness_surface',
                'stop_if_completion_audit_remains_incomplete',
            ],
            'non_execution_guarantees' => [
                'resume_packet_does_not_execute_command',
                'resume_packet_does_not_persist_receipts',
                'resume_packet_does_not_sign_for_operator',
                'resume_packet_does_not_call_provider',
                'resume_packet_does_not_spend_tokens',
                'resume_packet_does_not_dispatch_work',
                'resume_packet_does_not_promote_completion',
            ],
        ];
        $operatorResumePacket['resume_packet_hash'] = $this->stableHash($operatorResumePacket);
        $runtimeGapMatrix = (array) data_get($options, 'runtime_gap_matrix', []);
        if ($runtimeGapMatrix === []) {
            $runtimeGapMatrix = (new AtlasSelfConstructionRuntimeGapMatrixService($this->mother ?? throw new \RuntimeException("mother unbound")))->matrix();
        }

        return $this->wrapCertificationWorkbenchStatus(
            keyPrefix: 'atlas_self_construction_operator_evidence_submission_readiness',
            label: 'Atlas Self-Construction Operator Evidence Submission Readiness',
            payload: $result,
            statusKey: 'status',
            extraStatusFields: [
                'submission_readiness_hash' => (string) data_get($result, 'submission_readiness_hash'),
                'summary_status' => (string) data_get($result, 'status', ''),
                'next_required' => (string) data_get($result, 'next_required'),
                'next_required_submission' => (string) data_get($result, 'next_required'),
                'current_required_operator_artifact' => $currentRequiredOperatorArtifact,
                'next_required_command' => $operatorNextActionCommandToCopy,
                'next_required_persist_command' => $operatorNextActionExactPersistCommand,
                'runtime_gap_matrix_hash' => (string) data_get($runtimeGapMatrix, 'runtime_gap_matrix_hash', ''),
                'expected_runtime_gap_matrix_hash_for_promotion_receipt' => (string) data_get($runtimeGapMatrix, 'expected_runtime_gap_matrix_hash_for_promotion_receipt', ''),
                'runtime_promotion_basis_hash' => (string) data_get($runtimeGapMatrix, 'runtime_promotion_basis_hash', ''),
                'runtime_promotion_closure_basis_hash' => (string) data_get($runtimeGapMatrix, 'runtime_promotion_closure_basis_hash', ''),
                'completion_allowed' => false,
                'completion_claim_allowed' => false,
                'self_programming_allowed' => false,
                'terminal_loop_operational_proof_required_before_completion_claim' => true,
                'completion_audit_without_terminal_loop_operational_proof_is_diagnostic_only' => true,
                'current_required_operator_artifact_source' => 'operator_evidence_sequence_integrity.current_required_artifact',
                'operator_resume_aliases_hash' => $this->stableHash([
                    'current_required_operator_artifact' => $currentRequiredOperatorArtifact,
                    'next_required_command' => $operatorNextActionCommandToCopy,
                    'next_required_persist_command' => $operatorNextActionExactPersistCommand,
                    'next_required_submission' => (string) data_get($result, 'next_required'),
                ]),
                'operator_resume_packet' => $operatorResumePacket,
                'operator_resume_packet_hash' => (string) $operatorResumePacket['resume_packet_hash'],
                'operator_resume_packet_status' => (string) $operatorResumePacket['status'],
                'operator_resume_packet_next_action' => (string) data_get($result, 'operator_next_action.action_step_id', ''),
                'operator_resume_packet_current_required_operator_artifact' => (string) $operatorResumePacket['current_required_operator_artifact'],
                'operator_resume_packet_command_to_copy' => (string) $operatorResumePacket['command_to_copy'],
                'operator_resume_packet_copy_safe' => (bool) $operatorResumePacket['copy_safe'],
                'operator_resume_packet_placeholder_count' => (int) $operatorResumePacket['placeholder_count'],
                'operator_resume_packet_placeholders' => (array) $operatorResumePacket['placeholders'],
                'operator_resume_packet_requires_operator_review' => (bool) $operatorResumePacket['requires_operator_review'],
                'operator_resume_packet_failure_policy' => (array) $operatorResumePacket['failure_policy'],
                'operator_resume_packet_canonical_submission_repair_file_command' => (string) $operatorResumePacket['canonical_submission_repair_file_command'],
                'operator_resume_packet_canonical_submission_repair_file_command_hash' => (string) $operatorResumePacket['canonical_submission_repair_file_command_hash'],
                'operator_resume_packet_canonical_submission_repair_file_command_copy_safe' => (bool) $operatorResumePacket['canonical_submission_repair_file_command_copy_safe'],
                'operator_resume_packet_canonical_submission_repair_file_command_placeholder_fields' => (array) $operatorResumePacket['canonical_submission_repair_file_command_placeholder_fields'],
                'stale_context_hashes_detected' => count($canonicalSubmissionNextStepStaleContextHashes) > 0,
                'stale_context_hash_count' => count($canonicalSubmissionNextStepStaleContextHashes),
                'stale_context_hashes' => $canonicalSubmissionNextStepStaleContextHashes,
                'fresh_operator_draft_required' => $canonicalSubmissionNextStepFreshDraftRequired,
                'missing_operator_inputs' => (array) $operatorResumePacket['placeholders'],
                'failed_prerequisites' => (array) data_get($canonicalSubmissionNextStep, 'errors', []),
                'runtime_promotion_receipt_passed' => (bool) data_get($result, 'runtime_promotion_receipt_passed', false),
                'real_provider_smoke_passed' => (bool) data_get($result, 'real_provider_smoke_passed', false),
                'human_completion_receipt_passed' => (bool) data_get($result, 'human_completion_receipt_passed', false),
                'human_receipt_out_of_order' => (bool) data_get($result, 'human_receipt_out_of_order', false),
                'draft_workspace_input_status' => (string) data_get($result, 'draft_workspace_input.status', ''),
                'draft_workspace_requested_path' => (string) data_get($result, 'draft_workspace_input.requested_path', ''),
                'draft_workspace_manifest_path' => (string) data_get($result, 'draft_workspace_input.manifest_path', ''),
                'draft_workspace_artifact_count' => (int) data_get($result, 'draft_workspace_input.artifact_count', 0),
                'draft_workspace_loaded_artifacts' => (array) data_get($result, 'draft_workspace_input.loaded_artifacts', []),
                'draft_workspace_safe_for_operator_editing' => (bool) data_get($result, 'draft_workspace_input.workspace_safe_for_operator_editing', false),
                'draft_workspace_violation_count' => (int) data_get($result, 'draft_workspace_input.violation_count', 0),
                'draft_workspace_violations' => (array) data_get($result, 'draft_workspace_input.violations', []),
                'draft_workspace_warning_count' => (int) data_get($result, 'draft_workspace_input.warning_count', 0),
                'draft_workspace_warnings' => (array) data_get($result, 'draft_workspace_input.warnings', []),
                'draft_workspace_is_evidence' => (bool) data_get($result, 'draft_workspace_input.draft_is_evidence', true),
                'draft_workspace_can_persist_draft_directly' => (bool) data_get($result, 'draft_workspace_input.can_persist_draft_directly', true),
                'draft_workspace_refresh_required' => (bool) data_get($result, 'draft_workspace_refresh_required', false),
                'draft_workspace_refresh_status' => (string) data_get($result, 'draft_workspace_refresh.status', ''),
                'diagnostic_runtime_promotion_receipt_status' => (string) data_get($result, 'diagnostics.runtime_promotion_receipt.status', ''),
                'diagnostic_runtime_promotion_receipt_ready' => (bool) data_get($result, 'diagnostics.runtime_promotion_receipt.ready', false),
                'diagnostic_runtime_promotion_receipt_placeholder_count' => count((array) data_get($result, 'diagnostics.runtime_promotion_receipt.placeholders', [])),
                'diagnostic_runtime_promotion_receipt_placeholders' => (array) data_get($result, 'diagnostics.runtime_promotion_receipt.placeholders', []),
                'diagnostic_runtime_promotion_receipt_error_count' => count((array) data_get($result, 'diagnostics.runtime_promotion_receipt.errors', [])),
                'diagnostic_runtime_promotion_receipt_errors' => (array) data_get($result, 'diagnostics.runtime_promotion_receipt.errors', []),
                'diagnostic_runtime_promotion_receipt_violation_count' => (int) data_get($result, 'diagnostics.runtime_promotion_receipt.violation_count', 0),
                'diagnostic_real_provider_smoke_status' => (string) data_get($result, 'diagnostics.real_provider_smoke.status', ''),
                'diagnostic_real_provider_smoke_ready' => (bool) data_get($result, 'diagnostics.real_provider_smoke.ready', false),
                'diagnostic_real_provider_smoke_placeholder_count' => count((array) data_get($result, 'diagnostics.real_provider_smoke.placeholders', [])),
                'diagnostic_real_provider_smoke_placeholders' => (array) data_get($result, 'diagnostics.real_provider_smoke.placeholders', []),
                'diagnostic_real_provider_smoke_error_count' => count((array) data_get($result, 'diagnostics.real_provider_smoke.errors', [])),
                'diagnostic_real_provider_smoke_errors' => (array) data_get($result, 'diagnostics.real_provider_smoke.errors', []),
                'diagnostic_real_provider_smoke_violation_count' => (int) data_get($result, 'diagnostics.real_provider_smoke.violation_count', 0),
                'diagnostic_human_completion_receipt_status' => (string) data_get($result, 'diagnostics.human_completion_receipt.status', ''),
                'diagnostic_human_completion_receipt_ready' => (bool) data_get($result, 'diagnostics.human_completion_receipt.ready', false),
                'diagnostic_human_completion_receipt_placeholder_count' => count((array) data_get($result, 'diagnostics.human_completion_receipt.placeholders', [])),
                'diagnostic_human_completion_receipt_placeholders' => (array) data_get($result, 'diagnostics.human_completion_receipt.placeholders', []),
                'diagnostic_human_completion_receipt_error_count' => count((array) data_get($result, 'diagnostics.human_completion_receipt.errors', [])),
                'diagnostic_human_completion_receipt_errors' => (array) data_get($result, 'diagnostics.human_completion_receipt.errors', []),
                'diagnostic_human_completion_receipt_violation_count' => (int) data_get($result, 'diagnostics.human_completion_receipt.violation_count', 0),
                'diagnostic_total_placeholder_count' => $diagnosticTotalPlaceholderCount,
                'placeholder_count' => $diagnosticTotalPlaceholderCount,
                'diagnostic_total_error_count' => $diagnosticTotalErrorCount,
                'error_count' => $diagnosticTotalErrorCount,
                'diagnostic_all_supplied' => $diagnosticAllSupplied,
                'diagnostic_ready_count' => $diagnosticReadyCount,
                'ready_artifact_count' => $diagnosticReadyCount,
                'canonical_submission_input_status' => (string) data_get($result, 'canonical_submission_input.status', ''),
                'canonical_submission_loaded_artifacts' => (array) data_get($result, 'canonical_submission_input.loaded_artifacts', []),
                'canonical_submission_violation_count' => (int) data_get($result, 'canonical_submission_input.violation_count', 0),
                'canonical_submission_persistence_plan_status' => (string) data_get($result, 'canonical_submission_persistence_plan.status', ''),
                'canonical_submission_persistence_plan_canonical_source_authoritative' => (bool) data_get($result, 'canonical_submission_persistence_plan.canonical_source_authoritative', false),
                'canonical_submission_persistence_plan_workspace_payload_supplied' => (bool) data_get($result, 'canonical_submission_persistence_plan.workspace_payload_supplied', false),
                'canonical_submission_persistence_plan_loaded_artifact_count' => (int) data_get($result, 'canonical_submission_persistence_plan.loaded_artifact_count', 0),
                'canonical_submission_persistence_plan_next_step_id' => (string) data_get($result, 'canonical_submission_persistence_plan.next_step_id', ''),
                'canonical_submission_next_step' => $canonicalSubmissionNextStep,
                'canonical_submission_next_step_status' => (string) data_get($canonicalSubmissionNextStep, 'status', ''),
                'canonical_submission_next_step_artifact' => (string) data_get($canonicalSubmissionNextStep, 'artifact', ''),
                'canonical_submission_next_step_blocker' => (string) data_get($canonicalSubmissionNextStep, 'blocker', ''),
                'canonical_submission_next_step_verifier_status' => (string) data_get($canonicalSubmissionNextStep, 'verifier_status', ''),
                'canonical_submission_next_step_error_count' => count((array) data_get($canonicalSubmissionNextStep, 'errors', [])),
                'canonical_submission_next_step_errors' => (array) data_get($canonicalSubmissionNextStep, 'errors', []),
                'canonical_submission_next_step_placeholder_fields' => (array) data_get($canonicalSubmissionNextStep, 'placeholder_fields', []),
                'canonical_submission_next_step_stale_context_hashes' => $canonicalSubmissionNextStepStaleContextHashes,
                'canonical_submission_next_step_stale_context_hash_count' => count($canonicalSubmissionNextStepStaleContextHashes),
                'canonical_submission_next_step_fresh_operator_draft_required' => $canonicalSubmissionNextStepFreshDraftRequired,
                'canonical_submission_next_step_violation_count' => (int) data_get($canonicalSubmissionNextStep, 'violation_count', 0),
                'canonical_submission_next_step_violation_codes' => (array) data_get($canonicalSubmissionNextStep, 'violation_codes', []),
                'canonical_submission_next_step_violations' => (array) data_get($canonicalSubmissionNextStep, 'violations', []),
                'canonical_submission_next_step_command' => (string) data_get($canonicalSubmissionNextStep, 'command', ''),
                'canonical_submission_next_step_private_storage_path' => (string) data_get($canonicalSubmissionNextStep, 'canonical_submission_private_storage_path', ''),
                'canonical_submission_next_step_ready_for_explicit_operator_persistence' => $canonicalSubmissionNextStepReady,
                'canonical_submission_next_step_must_not_persist_until_ready' => ! $canonicalSubmissionNextStepReady && $canonicalSubmissionNextStep !== [],
                'canonical_submission_next_step_recommended_repair_command' => $canonicalSubmissionNextStepRepairCommand,
                'canonical_submission_next_step_recommended_repair_command_hash' => $canonicalSubmissionNextStepRepairCommand === '' ? '' : hash('sha256', $canonicalSubmissionNextStepRepairCommand),
                'canonical_submission_next_step_recommended_repair_file_command' => $canonicalSubmissionNextStepRepairFileCommand,
                'canonical_submission_next_step_recommended_repair_file_command_hash' => $canonicalSubmissionNextStepRepairFileCommand === '' ? '' : hash('sha256', $canonicalSubmissionNextStepRepairFileCommand),
                'canonical_submission_next_step_recommended_repair_file_command_payload_path' => $canonicalSubmissionNextStepRepairFileCommandPayloadPath,
                'canonical_submission_next_step_recommended_repair_file_command_copy_safe' => $canonicalSubmissionNextStepRepairFileCommand !== '' && $this->placeholderFieldsFromCommand($canonicalSubmissionNextStepRepairFileCommand) === [],
                'canonical_submission_next_step_recommended_repair_file_command_placeholder_fields' => $canonicalSubmissionNextStepRepairFileCommand === '' ? [] : $this->placeholderFieldsFromCommand($canonicalSubmissionNextStepRepairFileCommand),
                'canonical_submission_persistence_plan_step_count' => count((array) data_get($result, 'canonical_submission_persistence_plan.steps', [])),
                'canonical_submission_persistence_plan_sequence_ordered' => (bool) data_get($result, 'canonical_submission_persistence_plan.sequence_ordered', false),
                'operator_next_action_status' => (string) data_get($result, 'operator_next_action.status', ''),
                'operator_next_action_next_required' => (string) data_get($result, 'operator_next_action.next_required', ''),
                'operator_next_action_action_step_id' => (string) data_get($result, 'operator_next_action.action_step_id', ''),
                'operator_next_action_action_artifact' => (string) data_get($result, 'operator_next_action.action_artifact', ''),
                'operator_next_action_action_source' => (string) data_get($result, 'operator_next_action.action_source', ''),
                'operator_next_action_exact_command' => (string) data_get($result, 'operator_next_action.exact_command', ''),
                'operator_next_action_exact_persist_command' => (string) data_get($result, 'operator_next_action.exact_persist_command', ''),
                'operator_next_action_expected_persist_command_template' => (string) data_get($result, 'operator_next_action.expected_persist_command_template', ''),
                'operator_next_action_expected_endgame_command_template' => (string) data_get($result, 'operator_next_action.expected_endgame_command_template', ''),
                'operator_next_action_persist_command_template_available' => (bool) data_get($result, 'operator_next_action.persist_command_template_available', false),
                'operator_next_action_ready_for_explicit_operator_persistence' => (bool) data_get($result, 'operator_next_action.ready_for_explicit_operator_persistence', false),
                'operator_next_action_can_persist_from_readiness' => (bool) data_get($result, 'operator_next_action.can_persist_from_readiness', false),
                'operator_next_action_placeholder_fields_to_replace' => (array) data_get($result, 'operator_next_action.placeholder_fields_to_replace', []),
                'operator_next_action_why_not_automatic' => (string) data_get($result, 'operator_next_action.why_not_automatic', ''),
                'operator_next_action_hash' => (string) data_get($result, 'operator_next_action.operator_next_action_hash', ''),
                'operator_next_action_shell_packet' => (array) data_get($result, 'operator_next_action.shell_packet', []),
                'operator_next_action_shell_packet_status' => (string) data_get($result, 'operator_next_action.shell_packet.status', ''),
                'operator_next_action_shell_packet_hash' => (string) data_get($result, 'operator_next_action.shell_packet.shell_packet_hash', ''),
                'operator_next_action_command_to_copy' => $operatorNextActionCommandToCopy,
                'operator_next_action_command_to_copy_hash' => (string) data_get($result, 'operator_next_action.shell_packet.command_to_copy_hash', ''),
                'operator_next_action_shell_packet_placeholder_count' => (int) data_get($result, 'operator_next_action.shell_packet.placeholder_count', 0),
                'operator_next_action_shell_packet_copy_safe' => (bool) data_get($result, 'operator_next_action.shell_packet.copy_safe', false),
                'operator_evidence_sequence_integrity_status' => (string) data_get($result, 'operator_evidence_sequence_integrity.status', ''),
                'operator_evidence_sequence_valid' => (bool) data_get($result, 'operator_evidence_sequence_integrity.sequence_valid', false),
                'operator_evidence_sequence_violation_count' => (int) data_get($result, 'operator_evidence_sequence_integrity.sequence_violation_count', 0),
                'operator_evidence_sequence_violations' => (array) data_get($result, 'operator_evidence_sequence_integrity.sequence_violations', []),
                'operator_evidence_sequence_current_required_artifact' => (string) data_get($result, 'operator_evidence_sequence_integrity.current_required_artifact', ''),
                'operator_evidence_sequence_current_step_index' => (int) data_get($result, 'operator_evidence_sequence_integrity.current_step_index', 0),
                'operator_evidence_sequence_can_skip_steps' => (bool) data_get($result, 'operator_evidence_sequence_integrity.can_skip_steps', false),
                'operator_evidence_sequence_parallel_submission_allowed' => (bool) data_get($result, 'operator_evidence_sequence_integrity.parallel_submission_allowed', false),
                'operator_evidence_sequence_hash' => (string) data_get($result, 'operator_evidence_sequence_integrity.sequence_integrity_hash', ''),
                'operator_completion_proof_bundle_status' => (string) data_get($result, 'operator_completion_proof_bundle.status', ''),
                'operator_completion_proof_bundle_hash' => (string) data_get($result, 'operator_completion_proof_bundle.operator_completion_proof_bundle_hash', ''),
                'operator_completion_missing_proofs' => (array) data_get($result, 'operator_completion_proof_bundle.missing_proofs', []),
                'operator_completion_missing_proof_count' => (int) data_get($result, 'operator_completion_proof_bundle.missing_proof_count', 0),
                'operator_completion_ready_for_final_audit' => (bool) data_get($result, 'operator_completion_proof_bundle.ready_for_final_completion_audit', false),
                'operator_completion_can_persist_from_proof_bundle' => (bool) data_get($result, 'operator_completion_proof_bundle.can_persist_from_proof_bundle', false),
                'operator_completion_terminal_loop_operational_proof_required_before_final_audit' => (bool) data_get($result, 'operator_completion_proof_bundle.terminal_loop_operational_proof_required_before_final_audit', false),
                'terminal_loop_operational_proof_required_before_final_receipt' => (bool) data_get($result, 'operator_completion_proof_bundle.terminal_loop_operational_proof_required_before_final_audit', false),
                'terminal_loop_operational_proof_ready' => ! in_array('terminal_loop_operational_proof_binding', (array) data_get($result, 'operator_completion_proof_bundle.missing_proofs', []), true),
                'operator_completion_terminal_loop_operational_proof_expected_binding_schema' => (string) data_get($result, 'operator_completion_proof_bundle.terminal_loop_operational_proof_expected_binding_schema', ''),
                'operator_completion_terminal_loop_operational_proof_command' => (string) data_get($result, 'operator_completion_proof_bundle.proof_commands.terminal_loop_operational_proof', ''),
                'operator_completion_terminal_loop_operational_proof_binding_persist_command' => (string) data_get($result, 'operator_completion_proof_bundle.proof_commands.persist_terminal_loop_operational_proof_binding', ''),
                'operator_completion_audit_with_terminal_loop_operational_proof_command' => (string) data_get($result, 'operator_completion_proof_bundle.proof_commands.completion_audit_with_terminal_loop_operational_proof', ''),
                'operator_completion_audit_with_canonical_terminal_loop_operational_proof_command' => (string) data_get($result, 'operator_completion_proof_bundle.proof_commands.completion_audit_with_canonical_terminal_loop_operational_proof', ''),
                'operator_completion_effective_audit_with_terminal_loop_operational_proof_command' => (string) data_get($result, 'operator_completion_proof_bundle.proof_commands.effective_completion_audit_with_terminal_loop_operational_proof', ''),
                'operator_completion_terminal_loop_operational_proof_canonical_binding_path' => (string) data_get($result, 'operator_completion_proof_bundle.terminal_loop_operational_proof_canonical_binding_path', ''),
                'operator_completion_terminal_loop_operational_proof_acceptance_criteria_count' => count((array) data_get($result, 'operator_completion_proof_bundle.terminal_loop_operational_proof_acceptance_criteria', [])),
                'operator_completion_terminal_loop_operational_proof_required_end_to_end_contract_capability_count' => count((array) data_get($result, 'operator_completion_proof_bundle.terminal_loop_operational_proof_required_end_to_end_contract_capabilities', [])),
                'operator_completion_terminal_loop_operational_proof_required_end_to_end_contract_capabilities' => (array) data_get($result, 'operator_completion_proof_bundle.terminal_loop_operational_proof_required_end_to_end_contract_capabilities', []),
                'operator_evidence_closure_runbook_status' => (string) data_get($result, 'operator_evidence_closure_runbook.status', ''),
                'operator_evidence_closure_runbook_current_step_id' => (string) data_get($result, 'operator_evidence_closure_runbook.current_operator_step_id', ''),
                'operator_evidence_closure_runbook_failed_criteria' => (array) data_get($result, 'operator_evidence_closure_runbook.failed_criteria', []),
                'operator_evidence_closure_runbook_technical_blocker_count' => (int) data_get($result, 'operator_evidence_closure_runbook.blocker_classification.technical_blocker_count', 0),
                'operator_evidence_closure_runbook_technical_blockers' => (array) data_get($result, 'operator_evidence_closure_runbook.blocker_classification.technical_blockers', []),
                'operator_evidence_closure_runbook_terminal_loop_ready' => (bool) data_get($result, 'operator_evidence_closure_runbook.terminal_loop_preflight.ready', false),
                'operator_evidence_closure_runbook_missing_operator_proof_count' => (int) data_get($result, 'operator_evidence_closure_runbook.missing_operator_proof_count', 0),
                'operator_evidence_closure_runbook_ordered_step_count' => (int) data_get($result, 'operator_evidence_closure_runbook.ordered_step_count', 0),
                'operator_evidence_closure_runbook_terminal_loop_operational_proof_command' => (string) data_get($result, 'operator_evidence_closure_runbook.terminal_loop_operational_proof_command', ''),
                'operator_evidence_closure_runbook_terminal_loop_operational_proof_binding_persist_command' => (string) data_get($result, 'operator_evidence_closure_runbook.terminal_loop_operational_proof_binding_persist_command', ''),
                'operator_evidence_closure_runbook_completion_audit_with_terminal_loop_operational_proof_command' => (string) data_get($result, 'operator_evidence_closure_runbook.completion_audit_with_terminal_loop_operational_proof_command', ''),
                'operator_evidence_closure_runbook_completion_audit_with_canonical_terminal_loop_operational_proof_command' => (string) data_get($result, 'operator_evidence_closure_runbook.completion_audit_with_canonical_terminal_loop_operational_proof_command', ''),
                'operator_evidence_closure_runbook_effective_completion_audit_with_terminal_loop_operational_proof_command' => (string) data_get($result, 'operator_evidence_closure_runbook.effective_completion_audit_with_terminal_loop_operational_proof_command', ''),
                'operator_evidence_closure_runbook_terminal_loop_operational_proof_canonical_binding_path' => (string) data_get($result, 'operator_evidence_closure_runbook.terminal_loop_operational_proof_canonical_binding_path', ''),
                'operator_evidence_closure_runbook_terminal_loop_operational_proof_required_before_final_audit' => (bool) data_get($result, 'operator_evidence_closure_runbook.terminal_loop_operational_proof_required_before_final_audit', false),
                'operator_evidence_closure_runbook_can_execute' => (bool) data_get($result, 'operator_evidence_closure_runbook.can_execute_from_runbook', false),
                'operator_evidence_closure_runbook_can_persist' => (bool) data_get($result, 'operator_evidence_closure_runbook.can_persist_from_runbook', false),
                'operator_evidence_closure_runbook_hash' => (string) data_get($result, 'operator_evidence_closure_runbook.operator_evidence_closure_runbook_hash', ''),
                'external_completion_claim_policy_status' => (string) data_get($result, 'external_completion_claim_policy.status', ''),
                'external_completion_claim_policy_completion_authority' => (string) data_get($result, 'external_completion_claim_policy.completion_authority', ''),
                'completion_claim_authority' => (string) data_get($result, 'external_completion_claim_policy.completion_authority', ''),
                'completion_claim_required_completion_predicate' => (string) data_get($result, 'external_completion_claim_policy.required_completion_predicate', ''),
                'completion_claim_external_agent_claim_accepted' => data_get($result, 'external_completion_claim_policy.external_agent_claim_accepted') === true,
                'completion_claim_external_agent_claim_can_mark_os_complete' => data_get($result, 'external_completion_claim_policy.external_agent_claim_can_mark_os_complete') === true,
                'completion_claim_external_agent_claim_can_override_audit' => data_get($result, 'external_completion_claim_policy.external_agent_claim_can_override_audit') === true,
                'external_completion_claim_policy_external_agent_claim_accepted' => data_get($result, 'external_completion_claim_policy.external_agent_claim_accepted') === true,
                'external_completion_claim_policy_external_agent_claim_can_mark_os_complete' => data_get($result, 'external_completion_claim_policy.external_agent_claim_can_mark_os_complete') === true,
                'external_completion_claim_policy_external_agent_claim_can_override_audit' => data_get($result, 'external_completion_claim_policy.external_agent_claim_can_override_audit') === true,
                'external_completion_claim_policy_schema_valid' => $externalCompletionClaimPolicyViolationFields === [],
                'external_completion_claim_policy_schema_violation' => $externalCompletionClaimPolicyViolationFields === [] ? '' : 'missing_or_malformed_external_completion_claim_policy',
                'external_completion_claim_policy_schema_violation_fields' => $externalCompletionClaimPolicyViolationFields,
                'external_completion_claim_policy_required_completion_predicate' => (string) data_get($result, 'external_completion_claim_policy.required_completion_predicate', ''),
                'external_completion_claim_policy_current_failed_count' => (int) data_get($result, 'external_completion_claim_policy.current_failed_count', 0),
                'external_completion_claim_policy_missing_required_evidence_artifact_count' => (int) data_get($result, 'external_completion_claim_policy.missing_required_evidence_artifact_count', 0),
                'external_completion_claim_policy_hash' => (string) data_get($result, 'external_completion_claim_policy.external_completion_claim_policy_hash', ''),
                'closure_artifact_sequence' => (array) data_get($result, 'closure_artifact_sequence', []),
                'closure_artifact_sequence_count' => (int) data_get($result, 'closure_artifact_sequence_count', 0),
                'closure_artifact_sequence_hash' => (string) data_get($result, 'closure_artifact_sequence_hash', ''),
                'prompt_to_artifact_checklist' => (array) data_get($result, 'prompt_to_artifact_checklist', []),
                'prompt_to_artifact_checklist_count' => (int) data_get($result, 'prompt_to_artifact_checklist_count', 0),
                'prompt_to_artifact_checklist_passed_count' => (int) data_get($result, 'prompt_to_artifact_checklist_passed_count', 0),
                'prompt_to_artifact_checklist_hash' => (string) data_get($result, 'prompt_to_artifact_checklist_hash', ''),
                'operator_command_surface_integrity_status' => (string) data_get($result, 'operator_command_surface_integrity.status', ''),
                'operator_command_surface_integrity_command_count' => (int) data_get($result, 'operator_command_surface_integrity.command_count', 0),
                'operator_command_surface_integrity_missing_option_count' => (int) data_get($result, 'operator_command_surface_integrity.missing_option_count', 0),
                'operator_command_surface_integrity_legacy_alias_count' => (int) data_get($result, 'operator_command_surface_integrity.legacy_alias_count', 0),
                'operator_command_surface_integrity_legacy_alias_free' => (bool) data_get($result, 'operator_command_surface_integrity.legacy_alias_free', false),
                'operator_command_surface_integrity_hash' => (string) data_get($result, 'operator_command_surface_integrity.command_surface_integrity_hash', ''),
                'canonical_submission_runtime_promotion_receipt_persisted_green' => (bool) data_get($result, 'canonical_submission_persistence_plan.persisted_evidence_state.runtime_promotion_receipt.persisted_green', false),
                'canonical_submission_real_provider_smoke_persisted_green' => (bool) data_get($result, 'canonical_submission_persistence_plan.persisted_evidence_state.real_provider_smoke.persisted_green', false),
                'canonical_submission_human_completion_receipt_persisted_green' => (bool) data_get($result, 'canonical_submission_persistence_plan.persisted_evidence_state.human_completion_receipt.persisted_green', false),
                'canonical_submission_human_receipt_requires_prior_persisted_smoke_command' => (bool) data_get($result, 'canonical_submission_persistence_plan.human_receipt_persistence_requires_prior_persisted_smoke_command', false),
                'canonical_submission_can_persist_from_readiness' => (bool) data_get($result, 'canonical_submission_persistence_plan.can_persist_from_readiness', false),
                'completion_allowed' => false,
                'completion_claim_allowed' => false,
                'provider_call_allowed' => (bool) data_get($result, 'provider_call_allowed', false),
                'token_spend_allowed' => (bool) data_get($result, 'token_spend_allowed', false),
                'self_programming_allowed' => (bool) data_get($result, 'self_programming_allowed', false),
            ],
        );
    }


    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function atlasSelfConstructionOsCompletionEvidenceStatus(array $options = []): array
    {
        $persistRuntimePromotionReceiptRequested = (bool) ($options['persist_runtime_promotion_receipt'] ?? false);
        $persistEvidenceRequested = (bool) ($options['persist_completion_evidence'] ?? false);
        $runtimePromotionReceiptInputEnvelope = $this->completionEvidenceSubmissionInput(
            options: $options,
            payloadKey: 'runtime_promotion_receipt',
            jsonKey: 'runtime_promotion_receipt_json',
            canonicalPath: AtlasSelfConstructionReadinessService::CANONICAL_OPERATOR_SUBMISSION_PATHS['runtime_promotion_receipt'],
            canonicalLoadAllowed: $persistRuntimePromotionReceiptRequested,
        );
        $completionReceiptInputEnvelope = $this->completionEvidenceSubmissionInput(
            options: $options,
            payloadKey: 'completion_receipt',
            jsonKey: 'completion_receipt_json',
            canonicalPath: AtlasSelfConstructionReadinessService::CANONICAL_OPERATOR_SUBMISSION_PATHS['completion_receipt'],
            canonicalLoadAllowed: $persistEvidenceRequested,
        );
        $realProviderSmokeInputEnvelope = $this->completionEvidenceSubmissionInput(
            options: $options,
            payloadKey: 'real_provider_smoke',
            jsonKey: 'real_provider_smoke_json',
            canonicalPath: AtlasSelfConstructionReadinessService::CANONICAL_OPERATOR_SUBMISSION_PATHS['real_provider_smoke'],
            canonicalLoadAllowed: $persistEvidenceRequested,
        );

        $runtimePromotionReceiptInput = (array) data_get($runtimePromotionReceiptInputEnvelope, 'payload', []);
        $runtimeGapMatrix = (new AtlasSelfConstructionRuntimeGapMatrixService($this->mother ?? throw new \RuntimeException("mother unbound")))->matrix([
            'runtime_promotion_receipt' => $runtimePromotionReceiptInput,
            'persist_runtime_promotion_receipt' => false,
        ]);
        $receiptInput = (array) data_get($completionReceiptInputEnvelope, 'payload', []);
        $smokeInput = (array) data_get($realProviderSmokeInputEnvelope, 'payload', []);
        $humanReceiptService = new AtlasSelfConstructionHumanCompletionReceiptVerifierService;
        $realProviderSmokeService = new AtlasSelfConstructionRealProviderSmokeCertificationService;
        $realProviderSmokeBeforePersistence = $realProviderSmokeService->certify();
        $realProviderSmoke = array_merge($realProviderSmokeService->certify($smokeInput), [
            'persisted' => false,
        ]);
        $releaseDossier = $this->agentControlPlaneReleaseDossierStatus(['skip_simulator' => true]);
        $replayDiff = $this->agentControlPlaneReplayDiffStatus();
        $certificationStatusBatch = $this->agentControlPlaneCertificationStatusBatchStatus();
        $humanReceiptContext = [
            'release_dossier_hash' => (string) data_get($releaseDossier, 'agent_control_plane_release_dossier_status.release_dossier_hash', data_get($releaseDossier, 'agent_control_plane_release_dossier.release_dossier_hash', '')),
            'replay_diff_hash' => (string) data_get($replayDiff, 'agent_control_plane_replay_diff_status.diff_hash', ''),
            'runtime_gap_matrix_hash' => (string) data_get($runtimeGapMatrix, 'runtime_gap_matrix_hash', ''),
            'runtime_promotion_receipt_hash' => (string) data_get($runtimeGapMatrix, 'runtime_promotion_receipt.receipt_hash', ''),
            'real_provider_smoke_hash' => (string) data_get($realProviderSmoke, 'smoke_hash', ''),
            'certification_status_batch_hash' => (string) data_get($certificationStatusBatch, 'agent_control_plane_certification_status_batch_status.batch_hash', data_get($certificationStatusBatch, 'agent_control_plane_certification_status_batch.batch_hash', '')),
        ];
        $humanReceipt = $humanReceiptService->verify($receiptInput, $humanReceiptContext);
        $humanReceiptPersistencePrerequisites = [
            'runtime_promotion_receipt_present' => (string) data_get($runtimeGapMatrix, 'runtime_promotion_receipt.status') === 'passed',
            'runtime_gap_matrix_all_runtime_y' => (bool) data_get($runtimeGapMatrix, 'all_runtime_y', false),
            'end_to_end_real_provider_smoke_green' => (string) data_get($realProviderSmoke, 'status') === 'passed',
            'real_provider_smoke_persisted_before_human_receipt_command' => (string) data_get($realProviderSmokeBeforePersistence, 'status') === 'passed'
                && (string) data_get($realProviderSmokeBeforePersistence, 'smoke_hash') === (string) data_get($realProviderSmoke, 'smoke_hash')
                && $smokeInput === [],
        ];
        $missingHumanReceiptPrerequisites = array_keys(array_filter(
            $humanReceiptPersistencePrerequisites,
            static fn (bool $passed): bool => ! $passed,
        ));
        if ($persistEvidenceRequested && $receiptInput !== []) {
            $humanReceipt = array_merge($humanReceipt, [
                'persisted' => false,
                'persistence_blocker' => 'persistence_request_rejected_by_status_surface',
                'missing_persistence_prerequisites' => $missingHumanReceiptPrerequisites,
                'completion_claim_allowed' => false,
            ]);
        }
        $forgeSmoke = (new AtlasSelfConstructionForgeSelfImprovementIntegrationSmokeService)->certify($options);
        $operatorActionPacket = (new AtlasSelfConstructionCompletionOperatorActionPacketService($this->mother ?? throw new \RuntimeException("mother unbound")))->build($runtimeGapMatrix, $humanReceipt, $realProviderSmoke, [
            'release_dossier' => $releaseDossier,
            'replay_diff' => $replayDiff,
            'certification_status_batch' => $certificationStatusBatch,
        ]);
        $missingOperatorArtifacts = (array) data_get($operatorActionPacket, 'missing_operator_artifacts', []);
        $currentRequiredOperatorArtifact = match (true) {
            in_array('runtime_promotion_receipt', $missingOperatorArtifacts, true) => 'runtime_promotion_receipt',
            in_array('real_provider_claim_to_completion_smoke', $missingOperatorArtifacts, true) => 'real_provider_smoke',
            in_array('human_signed_os_complete_receipt', $missingOperatorArtifacts, true) => 'human_completion_receipt',
            default => 'none',
        };
        $nextRequiredCommand = match ($currentRequiredOperatorArtifact) {
            'runtime_promotion_receipt' => (string) data_get($operatorActionPacket, 'commands.draft_runtime_promotion_receipt', ''),
            'real_provider_smoke' => (string) data_get($operatorActionPacket, 'commands.draft_real_provider_smoke', ''),
            'human_completion_receipt' => (string) data_get($operatorActionPacket, 'commands.draft_human_completion_receipt', ''),
            default => '',
        };
        $nextRequiredPersistCommand = match ($currentRequiredOperatorArtifact) {
            'runtime_promotion_receipt' => (string) data_get($operatorActionPacket, 'commands.persist_runtime_promotion_receipt', ''),
            'real_provider_smoke' => (string) data_get($operatorActionPacket, 'commands.persist_real_provider_smoke', ''),
            'human_completion_receipt' => (string) data_get($operatorActionPacket, 'commands.persist_human_completion_receipt', ''),
            default => '',
        };
        $checks = [
            'runtime_gap_matrix_all_runtime_y' => (bool) data_get($runtimeGapMatrix, 'all_runtime_y', false),
            'human_signed_os_complete_receipt_present' => (string) data_get($humanReceipt, 'status') === 'passed',
            'end_to_end_real_provider_smoke_green' => (string) data_get($realProviderSmoke, 'status') === 'passed',
            'forge_self_improvement_integration_smoke_green' => (string) data_get($forgeSmoke, 'status') === 'passed',
        ];
        $failed = array_keys(array_filter($checks, static fn (bool $passed): bool => ! $passed));
        $closureArtifactSequence = [
            [
                'order' => 1,
                'artifact' => 'runtime_promotion_receipt',
                'requirement' => 'runtime_gap_matrix_all_runtime_y',
                'blocker_type' => 'human',
                'status' => (bool) data_get($runtimeGapMatrix, 'all_runtime_y', false) ? 'passed' : 'blocked',
                'passed' => (bool) data_get($runtimeGapMatrix, 'all_runtime_y', false),
                'expected_receipt_schema' => (string) data_get($operatorActionPacket, 'expected_receipt_schemas.runtime_promotion_receipt', 'atlas.self_construction.runtime_promotion_receipt.v1'),
                'draft_command' => (string) data_get($operatorActionPacket, 'commands.draft_runtime_promotion_receipt', ''),
                'persist_command' => (string) data_get($operatorActionPacket, 'commands.persist_runtime_promotion_receipt', ''),
                'canonical_submission_private_storage_path' => (string) data_get($operatorActionPacket, 'canonical_submission_private_storage_paths.runtime_promotion_receipt', 'storage/app/private/atlas/self-construction/operator-submissions/runtime-promotion.json'),
                'evidence_source' => 'runtime_gap_matrix',
                'requires_operator_signature' => true,
                'requires_provider_call' => false,
            ],
            [
                'order' => 2,
                'artifact' => 'real_provider_smoke',
                'requirement' => 'end_to_end_real_provider_smoke_green',
                'blocker_type' => 'real_provider',
                'status' => (string) data_get($realProviderSmoke, 'status', 'blocked'),
                'passed' => (string) data_get($realProviderSmoke, 'status') === 'passed',
                'expected_receipt_schema' => (string) data_get($operatorActionPacket, 'expected_receipt_schemas.real_provider_smoke', 'atlas.self_construction.real_provider_smoke_certification.v1'),
                'draft_command' => (string) data_get($operatorActionPacket, 'commands.draft_real_provider_smoke', ''),
                'persist_command' => (string) data_get($operatorActionPacket, 'commands.persist_real_provider_smoke', ''),
                'canonical_submission_private_storage_path' => (string) data_get($operatorActionPacket, 'canonical_submission_private_storage_paths.real_provider_smoke', 'storage/app/private/atlas/self-construction/operator-submissions/real-provider-smoke.json'),
                'evidence_source' => 'real_provider_smoke',
                'requires_operator_signature' => false,
                'requires_provider_call' => true,
            ],
            [
                'order' => 3,
                'artifact' => 'human_completion_receipt',
                'requirement' => 'human_signed_os_complete_receipt_present',
                'blocker_type' => 'human',
                'status' => (string) data_get($humanReceipt, 'status', 'blocked'),
                'passed' => (string) data_get($humanReceipt, 'status') === 'passed',
                'expected_receipt_schema' => (string) data_get($operatorActionPacket, 'expected_receipt_schemas.human_signed_os_complete_receipt', 'atlas.self_construction.human_signed_completion_receipt.v1'),
                'draft_command' => (string) data_get($operatorActionPacket, 'commands.draft_human_completion_receipt', ''),
                'persist_command' => (string) data_get($operatorActionPacket, 'commands.persist_human_completion_receipt', ''),
                'canonical_submission_private_storage_path' => (string) data_get($operatorActionPacket, 'canonical_submission_private_storage_paths.completion_receipt', 'storage/app/private/atlas/self-construction/operator-submissions/completion-receipt.json'),
                'evidence_source' => 'human_signed_completion_receipt',
                'requires_operator_signature' => true,
                'requires_provider_call' => false,
            ],
            [
                'order' => 4,
                'artifact' => 'final_completion_audit',
                'requirement' => 'completion_audit_authorizes_completion_claim',
                'blocker_type' => $failed === [] ? 'none' : 'derived',
                'status' => $failed === [] ? 'ready_for_completion_audit' : 'blocked_until_operator_evidence_green',
                'passed' => $failed === [],
                'expected_receipt_schema' => 'atlas.self_construction.os_completion_audit.v1',
                'draft_command' => (string) data_get($operatorActionPacket, 'commands.run_completion_audit_with_canonical_terminal_loop_operational_proof', ''),
                'persist_command' => '',
                'canonical_submission_private_storage_path' => '',
                'evidence_source' => 'completion_audit',
                'requires_operator_signature' => false,
                'requires_provider_call' => false,
            ],
        ];
        $promptToArtifactChecklist = array_map(
            static fn (array $row): array => [
                'requirement' => $row['requirement'],
                'artifact' => $row['artifact'],
                'status' => $row['status'],
                'passed' => $row['passed'],
                'blocker_type' => $row['blocker_type'],
                'expected_receipt_schema' => $row['expected_receipt_schema'],
                'evidence_source' => $row['evidence_source'],
                'command' => $row['draft_command'],
                'persist_command' => $row['persist_command'],
            ],
            $closureArtifactSequence,
        );
        $humanBlockers = array_values(array_unique(array_filter([
            in_array('runtime_gap_matrix_all_runtime_y', $failed, true) || in_array('runtime_promotion_receipt', $missingOperatorArtifacts, true)
                ? 'runtime_gap_matrix_all_runtime_y'
                : null,
            in_array('human_signed_os_complete_receipt_present', $failed, true) || in_array('human_signed_os_complete_receipt', $missingOperatorArtifacts, true)
                ? 'human_signed_os_complete_receipt_present'
                : null,
        ])));
        $realProviderBlockers = array_values(array_unique(array_filter([
            in_array('end_to_end_real_provider_smoke_green', $failed, true) || in_array('real_provider_claim_to_completion_smoke', $missingOperatorArtifacts, true)
                ? 'end_to_end_real_provider_smoke_green'
                : null,
        ])));
        $technicalBlockers = array_values(array_diff($failed, array_merge($humanBlockers, $realProviderBlockers)));
        $blockerClassification = [
            'human_blocker_count' => count($humanBlockers),
            'human_blockers' => $humanBlockers,
            'real_provider_blocker_count' => count($realProviderBlockers),
            'real_provider_blockers' => $realProviderBlockers,
            'technical_blocker_count' => count($technicalBlockers),
            'technical_blockers' => $technicalBlockers,
        ];
        $completionEvidenceClaimAuthority = [
            'schema_version' => 'atlas.self_construction.completion_evidence_claim_authority.v1',
            'mode' => 'read_only_completion_evidence_claim_authority',
            'status' => $failed === [] ? 'evidence_ready_for_completion_audit' : 'evidence_incomplete_reject_completion_claim',
            'completion_authority' => 'atlas_self_construction_os_completion_audit',
            'required_completion_predicate' => 'completion_audit.status=complete AND completion_allowed=true AND failed_count=0',
            'completion_evidence_complete' => $failed === [],
            'completion_claim_allowed_from_evidence_status' => false,
            'completion_allowed_from_evidence_status' => false,
            'self_programming_allowed_from_evidence_status' => false,
            'terminal_loop_operational_proof_required_before_completion_claim' => true,
            'completion_audit_without_terminal_loop_operational_proof_is_diagnostic_only' => true,
            'external_agent_claim_accepted' => false,
            'external_agent_claim_can_mark_os_complete' => false,
            'external_agent_claim_can_override_completion_audit' => false,
            'failed_checks' => $failed,
            'failed_count' => count($failed),
            'current_required_operator_artifact' => $currentRequiredOperatorArtifact,
            'blocker_classification' => $blockerClassification,
            'operator_next_action' => $failed === []
                ? 'rerun_completion_audit_with_canonical_terminal_loop_operational_proof'
                : 'persist_missing_operator_or_provider_evidence_then_rerun_evidence_status',
            'verification_commands' => [
                'completion_evidence_status' => 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-evidence-status --json',
                'operator_evidence_submission_readiness' => 'php artisan atlas:ai:self-construction --atlas-self-construction-operator-evidence-submission-readiness-status --json',
                'completion_audit_with_canonical_terminal_loop_operational_proof' => (string) data_get($operatorActionPacket, 'commands.run_completion_audit_with_canonical_terminal_loop_operational_proof', ''),
            ],
            'failure_policy' => [
                'reject_completion_claim_from_evidence_status_alone',
                'reject_external_agent_completion_claim',
                'require_completion_audit_after_all_evidence_is_persisted',
                'require_human_signed_completion_receipt',
                'require_real_provider_smoke',
                'require_runtime_promotion_receipt',
            ],
            'non_execution_guarantees' => [
                'completion_evidence_claim_authority_does_not_persist_receipts',
                'completion_evidence_claim_authority_does_not_sign_for_operator',
                'completion_evidence_claim_authority_does_not_call_provider',
                'completion_evidence_claim_authority_does_not_spend_tokens',
                'completion_evidence_claim_authority_does_not_dispatch',
                'completion_evidence_claim_authority_does_not_enable_runtime',
                'completion_evidence_claim_authority_does_not_promote_completion',
            ],
        ];
        $completionEvidenceClaimAuthority['completion_evidence_claim_authority_hash'] = $this->stableHash($completionEvidenceClaimAuthority);
        $payload = [
            'schema_version' => 'atlas.self_construction_agent_control_plane_completion_evidence_status.v1',
            'status' => $failed === [] ? 'passed' : 'blocked',
            'mode' => 'read_only_agent_control_plane_completion_evidence_status',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'completion_allowed' => false,
            'completion_claim_allowed' => false,
            'self_programming_allowed' => false,
            'terminal_loop_operational_proof_required_before_completion_claim' => true,
            'completion_audit_without_terminal_loop_operational_proof_is_diagnostic_only' => true,
            'completion_evidence_complete' => $failed === [],
            'completion_claim_requires_completion_audit' => true,
            'completion_claim_authority' => 'atlas_self_construction_os_completion_audit',
            'completion_claim_blocked_until_audit_complete' => true,
            'completion_evidence_claim_authority' => $completionEvidenceClaimAuthority,
            'checks' => $checks,
            'failed_checks' => $failed,
            'failed_count' => count($failed),
            'blocker_classification' => $blockerClassification,
            'human_blocker_count' => count($humanBlockers),
            'human_blockers' => $humanBlockers,
            'real_provider_blocker_count' => count($realProviderBlockers),
            'real_provider_blockers' => $realProviderBlockers,
            'technical_blocker_count' => count($technicalBlockers),
            'technical_blockers' => $technicalBlockers,
            'persist_completion_evidence_requested' => $persistEvidenceRequested,
            'persist_runtime_promotion_receipt_requested' => $persistRuntimePromotionReceiptRequested,
            'persistence_request_rejected_by_status_surface' => $persistEvidenceRequested || $persistRuntimePromotionReceiptRequested,
            'operator_submission_input' => [
                'runtime_promotion_receipt' => $this->completionEvidenceSubmissionInputSummary($runtimePromotionReceiptInputEnvelope),
                'real_provider_smoke' => $this->completionEvidenceSubmissionInputSummary($realProviderSmokeInputEnvelope),
                'completion_receipt' => $this->completionEvidenceSubmissionInputSummary($completionReceiptInputEnvelope),
            ],
            'runtime_gap_matrix' => $runtimeGapMatrix,
            'human_signed_completion_receipt' => $humanReceipt,
            'real_provider_smoke' => $realProviderSmoke,
            'forge_self_improvement_integration_smoke' => $forgeSmoke,
            'operator_action_packet' => $operatorActionPacket,
            'current_required_operator_artifact' => $currentRequiredOperatorArtifact,
            'next_required_command' => $nextRequiredCommand,
            'next_required_persist_command' => $nextRequiredPersistCommand,
            'runtime_gap_matrix_hash' => (string) data_get($runtimeGapMatrix, 'runtime_gap_matrix_hash', ''),
            'expected_runtime_gap_matrix_hash_for_promotion_receipt' => (string) data_get($runtimeGapMatrix, 'expected_runtime_gap_matrix_hash_for_promotion_receipt', ''),
            'runtime_promotion_basis_hash' => (string) data_get($runtimeGapMatrix, 'runtime_promotion_basis_hash', ''),
            'runtime_promotion_closure_basis_hash' => (string) data_get($runtimeGapMatrix, 'runtime_promotion_closure_basis_hash', ''),
            'closure_artifact_sequence' => $closureArtifactSequence,
            'closure_artifact_sequence_count' => count($closureArtifactSequence),
            'closure_artifact_sequence_hash' => $this->stableHash($closureArtifactSequence),
            'prompt_to_artifact_checklist' => $promptToArtifactChecklist,
            'prompt_to_artifact_checklist_count' => count($promptToArtifactChecklist),
            'prompt_to_artifact_checklist_passed_count' => count(array_filter($promptToArtifactChecklist, static fn (array $row): bool => (bool) $row['passed'])),
            'prompt_to_artifact_checklist_hash' => $this->stableHash($promptToArtifactChecklist),
            'completion_audit_with_canonical_terminal_loop_operational_proof_command' => (string) data_get($operatorActionPacket, 'commands.run_completion_audit_with_canonical_terminal_loop_operational_proof', ''),
            'non_execution_guarantees' => [
                'completion_evidence_status_does_not_start_codex',
                'completion_evidence_status_does_not_call_provider',
                'completion_evidence_status_does_not_dispatch_work',
                'completion_evidence_status_does_not_spend_tokens',
                'completion_evidence_status_does_not_persist_receipts',
                'completion_evidence_status_does_not_enable_self_programming',
                'completion_evidence_status_does_not_accept_external_completion_claims',
            ],
            'human_summary' => $failed === []
                ? 'Atlas Self-Construction OS completion evidence is present, but the full completion audit must still pass before any claim.'
                : 'Atlas Self-Construction OS completion evidence is still missing required artifacts.',
        ];
        $payload['completion_evidence_status_hash'] = $this->stableHash($payload);

        return $payload;
    }


    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function atlasSelfConstructionFinalOperatorEvidenceClosureCorridorStatus(array $options = []): array
    {
        $result = (new AtlasSelfConstructionFinalOperatorEvidenceClosureCorridorService($this->mother ?? throw new \RuntimeException("mother unbound")))->build($options);
        $externalCompletionClaimPolicyViolationFields = $this->externalCompletionClaimPolicyViolationFields($result, [
            'external_agent_claim_accepted',
            'external_agent_claim_can_mark_os_complete',
            'external_agent_claim_can_override_audit',
        ]);

        return $this->wrapCertificationWorkbenchStatus(
            keyPrefix: 'atlas_self_construction_final_operator_evidence_closure_corridor',
            label: 'Atlas Self-Construction Final Operator Evidence Closure Corridor',
            payload: $result,
            statusKey: 'status',
            extraStatusFields: [
                'closure_corridor_hash' => (string) data_get($result, 'closure_corridor_hash'),
                'blocking_artifact_count' => (int) data_get($result, 'blocking_artifact_count', 0),
                'blocking_artifacts' => (array) data_get($result, 'blocking_artifacts', []),
                'ordered_operator_path_step_count' => (int) data_get($result, 'ordered_operator_path_step_count', 0),
                'current_completion_audit_prompt_to_artifact_checklist_count' => (int) data_get($result, 'current_completion_audit.prompt_to_artifact_checklist_count', 0),
                'closure_artifact_sequence' => (array) data_get($result, 'closure_artifact_sequence', []),
                'closure_artifact_sequence_count' => (int) data_get($result, 'closure_artifact_sequence_count', 0),
                'closure_artifact_sequence_hash' => (string) data_get($result, 'closure_artifact_sequence_hash', ''),
                'prompt_to_artifact_checklist' => (array) data_get($result, 'prompt_to_artifact_checklist', []),
                'prompt_to_artifact_checklist_count' => (int) data_get($result, 'prompt_to_artifact_checklist_count', 0),
                'prompt_to_artifact_checklist_passed_count' => (int) data_get($result, 'prompt_to_artifact_checklist_passed_count', 0),
                'prompt_to_artifact_checklist_hash' => (string) data_get($result, 'prompt_to_artifact_checklist_hash', ''),
                'next_required_submission' => (string) data_get($result, 'submission_preflight.next_required_submission'),
                'current_required_artifact' => (string) data_get(
                    $result,
                    'operator_completion_progress_meter.current_required_artifact',
                    data_get($result, 'operator_next_action.next_required_submission', data_get($result, 'submission_preflight.next_required_submission', '')),
                ),
                'current_required_operator_artifact' => (string) data_get(
                    $result,
                    'operator_completion_progress_meter.current_required_artifact',
                    data_get($result, 'operator_next_action.next_required_submission', data_get($result, 'submission_preflight.next_required_submission', '')),
                ),
                'current_required_operator_command' => (string) data_get($result, 'operator_next_action.exact_command', ''),
                'current_required_operator_persist_command' => (string) data_get($result, 'operator_next_action.exact_persist_command', ''),
                'operator_next_action_status' => (string) data_get($result, 'operator_next_action.status'),
                'operator_next_action_step_id' => (string) data_get($result, 'operator_next_action.next_step_id'),
                'operator_next_action_phase' => (string) data_get($result, 'operator_next_action.next_step_phase'),
                'operator_next_action_current_artifact' => (string) data_get($result, 'operator_next_action.next_required_submission', ''),
                'operator_next_action_exact_command' => (string) data_get($result, 'operator_next_action.exact_command'),
                'operator_next_action_command_to_copy' => (string) data_get($result, 'operator_next_action.exact_command'),
                'operator_next_action_exact_persist_command' => (string) data_get($result, 'operator_next_action.exact_persist_command'),
                'next_required_command' => (string) data_get(
                    $result,
                    'operator_next_action.exact_command',
                    data_get($result, 'submission_preflight.next_required_command', ''),
                ),
                'next_required_persist_command' => (string) data_get(
                    $result,
                    'operator_next_action.exact_persist_command',
                    data_get($result, 'operator_closure_handoff.immediate_persist_command', ''),
                ),
                'runtime_gap_matrix_hash' => (string) data_get($result, 'current_completion_evidence_status.runtime_gap_matrix_hash', ''),
                'expected_runtime_gap_matrix_hash_for_promotion_receipt' => (string) data_get($result, 'current_completion_evidence_status.expected_runtime_gap_matrix_hash_for_promotion_receipt', ''),
                'runtime_promotion_basis_hash' => (string) data_get($result, 'current_completion_evidence_status.runtime_promotion_basis_hash', ''),
                'runtime_promotion_closure_basis_hash' => (string) data_get($result, 'current_completion_evidence_status.runtime_promotion_closure_basis_hash', ''),
                'completion_claim_allowed' => false,
                'self_programming_allowed' => false,
                'terminal_loop_operational_proof_required_before_completion_claim' => true,
                'completion_audit_without_terminal_loop_operational_proof_is_diagnostic_only' => true,
                'operator_next_action_can_run_automatically' => (bool) data_get($result, 'operator_next_action.can_run_automatically', false),
                'operator_next_action_placeholder_fields_to_replace' => (array) data_get($result, 'operator_next_action.placeholder_fields_to_replace', []),
                'operator_next_action_why_not_automatic' => (string) data_get($result, 'operator_next_action.why_not_automatic'),
                'operator_next_action_hash' => (string) data_get($result, 'operator_next_action.operator_next_action_hash'),
                'operator_closure_handoff_status' => (string) data_get($result, 'operator_closure_handoff.status', ''),
                'operator_closure_handoff_current_step' => (string) data_get($result, 'operator_closure_handoff.next_step_id', ''),
                'operator_closure_handoff_next_required_submission' => (string) data_get($result, 'operator_closure_handoff.next_required_submission', ''),
                'operator_closure_handoff_immediate_command' => (string) data_get($result, 'operator_closure_handoff.immediate_command', ''),
                'operator_closure_handoff_immediate_persist_command' => (string) data_get($result, 'operator_closure_handoff.immediate_persist_command', ''),
                'operator_closure_handoff_blocking_artifact_count' => (int) data_get($result, 'operator_closure_handoff.blocking_artifact_count', 0),
                'operator_closure_handoff_requires_human_operator' => (bool) data_get($result, 'operator_closure_handoff.requires_human_operator', false),
                'operator_closure_handoff_requires_real_provider_smoke' => (bool) data_get($result, 'operator_closure_handoff.requires_real_provider_smoke', false),
                'operator_closure_handoff_success_predicate' => (string) data_get($result, 'operator_closure_handoff.success_predicate_after_all_actions', ''),
                'operator_closure_handoff_resumption_checkpoint_hash' => (string) data_get($result, 'operator_closure_handoff.resumption_checkpoint_hash', ''),
                'operator_closure_handoff_resumption_checkpoint_current_step' => (string) data_get($result, 'operator_closure_handoff.resumption_checkpoint_current_step', ''),
                'operator_closure_handoff_command_replay_hash' => (string) data_get($result, 'operator_closure_handoff.operator_closure_command_replay_hash', ''),
                'operator_closure_handoff_command_replay_current_step' => (string) data_get($result, 'operator_closure_handoff.operator_closure_command_replay_current_step', ''),
                'operator_closure_handoff_can_resume_without_chat_history' => (bool) data_get($result, 'operator_closure_handoff.can_resume_without_chat_history', false),
                'operator_closure_handoff_requires_fresh_preflight_before_persist' => (bool) data_get($result, 'operator_closure_handoff.requires_fresh_preflight_before_persist', false),
                'operator_closure_handoff_hash' => (string) data_get($result, 'operator_closure_handoff.operator_closure_handoff_hash', ''),
                'operator_workspace_diagnostics_status' => (string) data_get($result, 'operator_workspace_diagnostics.status', ''),
                'operator_workspace_diagnostics_loaded_artifacts' => (array) data_get($result, 'operator_workspace_diagnostics.loaded_artifacts', []),
                'operator_workspace_diagnostics_loaded_artifact_count' => count((array) data_get($result, 'operator_workspace_diagnostics.loaded_artifacts', [])),
                'operator_workspace_diagnostics_violation_count' => (int) data_get($result, 'operator_workspace_diagnostics.violation_count', 0),
                'operator_workspace_diagnostics_warning_count' => (int) data_get($result, 'operator_workspace_diagnostics.warning_count', 0),
                'operator_workspace_diagnostics_draft_hash_finalization_status' => (string) data_get($result, 'operator_workspace_diagnostics.draft_hash_finalization_status', ''),
                'operator_workspace_diagnostics_draft_hash_finalization_ready_artifact_count' => (int) data_get($result, 'operator_workspace_diagnostics.draft_hash_finalization_ready_artifact_count', 0),
                'operator_workspace_diagnostics_draft_hash_finalization_blocked_artifact_count' => (int) data_get($result, 'operator_workspace_diagnostics.draft_hash_finalization_blocked_artifact_count', 0),
                'operator_workspace_diagnostics_draft_workspace_publisher_status' => (string) data_get($result, 'operator_workspace_diagnostics.draft_workspace_publisher_status', ''),
                'operator_workspace_diagnostics_draft_workspace_publishable_artifact_count' => (int) data_get($result, 'operator_workspace_diagnostics.draft_workspace_publishable_artifact_count', 0),
                'operator_workspace_diagnostics_draft_workspace_atomic_bundle_ready' => (bool) data_get($result, 'operator_workspace_diagnostics.draft_workspace_atomic_bundle_ready', false),
                'operator_workspace_diagnostics_canonical_submission_persistence_plan_status' => (string) data_get($result, 'operator_workspace_diagnostics.canonical_submission_persistence_plan_status', ''),
                'operator_workspace_diagnostics_canonical_submission_persistence_plan_next_step_id' => (string) data_get($result, 'operator_workspace_diagnostics.canonical_submission_persistence_plan_next_step_id', ''),
                'operator_workspace_diagnostics_can_write_from_corridor' => (bool) data_get($result, 'operator_workspace_diagnostics.can_write_from_corridor', false),
                'operator_workspace_diagnostics_can_persist_from_corridor' => (bool) data_get($result, 'operator_workspace_diagnostics.can_persist_from_corridor', false),
                'operator_execution_runbook_status' => (string) data_get($result, 'operator_execution_runbook.status', ''),
                'operator_execution_runbook_current_step_id' => (string) data_get($result, 'operator_execution_runbook.current_step_id', ''),
                'operator_execution_runbook_step_count' => (int) data_get($result, 'operator_execution_runbook.step_count', 0),
                'operator_execution_runbook_blocked_artifact_ids' => (array) data_get($result, 'operator_execution_runbook.blocked_artifact_ids', []),
                'operator_execution_runbook_blocked_artifact_count' => (int) data_get($result, 'operator_execution_runbook.blocked_artifact_count', 0),
                'operator_execution_runbook_blocked_artifact_count_includes_final_completion_audit' => (bool) data_get($result, 'operator_execution_runbook.blocked_artifact_count_includes_final_completion_audit', false),
                'operator_execution_runbook_operator_evidence_blocked_artifact_ids' => (array) data_get($result, 'operator_execution_runbook.operator_evidence_blocked_artifact_ids', []),
                'operator_execution_runbook_operator_evidence_blocked_artifact_count' => (int) data_get($result, 'operator_execution_runbook.operator_evidence_blocked_artifact_count', 0),
                'operator_execution_runbook_final_completion_audit_blocked' => (bool) data_get($result, 'operator_execution_runbook.final_completion_audit_blocked', false),
                'operator_execution_runbook_command_replay_hash' => (string) data_get($result, 'operator_execution_runbook.operator_closure_command_replay_hash', ''),
                'operator_execution_runbook_can_resume_without_chat_history' => (bool) data_get($result, 'operator_execution_runbook.resume_without_chat_history.can_resume_without_chat_history', false),
                'operator_execution_runbook_can_persist_from_runbook' => (bool) data_get($result, 'operator_execution_runbook.can_persist_from_runbook', false),
                'operator_execution_runbook_hash' => (string) data_get($result, 'operator_execution_runbook.operator_execution_runbook_hash', ''),
                'operator_next_action_shell_packet_status' => (string) data_get($result, 'operator_next_action_shell_packet.status', ''),
                'operator_next_action_shell_packet_safe_to_copy_after_operator_review' => (bool) data_get($result, 'operator_next_action_shell_packet.safe_to_copy_after_operator_review', false),
                'operator_next_action_shell_packet_ordered_command_count' => (int) data_get($result, 'operator_next_action_shell_packet.ordered_shell_command_count', 0),
                'operator_next_action_shell_packet_placeholder_count' => count((array) data_get($result, 'operator_next_action_shell_packet.placeholder_fields_to_replace', [])),
                'operator_next_action_shell_packet_placeholder_replacement_contract_count' => (int) data_get($result, 'operator_next_action_shell_packet.placeholder_replacement_contract_count', 0),
                'operator_next_action_shell_packet_post_action_success_check_count' => count((array) data_get($result, 'operator_next_action_shell_packet.post_action_success_checks', [])),
                'operator_next_action_shell_packet_post_action_verification_status' => (string) data_get($result, 'operator_next_action_shell_packet.post_action_verification_bundle.status', ''),
                'operator_next_action_shell_packet_post_action_verification_command_count' => (int) data_get($result, 'operator_next_action_shell_packet.post_action_verification_bundle.verification_command_count', 0),
                'operator_next_action_shell_packet_post_action_verification_success_check_count' => (int) data_get($result, 'operator_next_action_shell_packet.post_action_verification_bundle.success_check_count', 0),
                'operator_next_action_shell_packet_post_action_verification_failure_stops_advance' => (bool) data_get($result, 'operator_next_action_shell_packet.post_action_verification_bundle.failure_policy.do_not_advance_to_next_artifact', false),
                'operator_next_action_shell_packet_post_action_verification_hash' => (string) data_get($result, 'operator_next_action_shell_packet.post_action_verification_bundle.verification_bundle_hash', ''),
                'operator_next_action_shell_packet_resume_status' => (string) data_get($result, 'operator_next_action_shell_packet.resume_after_interruption.status', ''),
                'operator_next_action_shell_packet_resume_command' => (string) data_get($result, 'operator_next_action_shell_packet.resume_after_interruption.resume_command', ''),
                'operator_next_action_shell_packet_resume_current_step_id' => (string) data_get($result, 'operator_next_action_shell_packet.resume_after_interruption.current_step_id', ''),
                'operator_next_action_shell_packet_requires_fresh_preflight_before_persist' => (bool) data_get($result, 'operator_next_action_shell_packet.resume_after_interruption.requires_fresh_preflight_before_persist', false),
                'operator_next_action_shell_packet_do_not_persist_until_verifier_green' => (bool) data_get($result, 'operator_next_action_shell_packet.resume_after_interruption.do_not_run_persist_command_until_verifier_green', false),
                'operator_next_action_shell_packet_can_resume_without_chat_history' => (bool) data_get($result, 'operator_next_action_shell_packet.resume_after_interruption.can_resume_without_chat_history', false),
                'operator_next_action_shell_packet_resume_contract_hash' => (string) data_get($result, 'operator_next_action_shell_packet.resume_after_interruption.resume_contract_hash', ''),
                'operator_next_action_shell_packet_hash' => (string) data_get($result, 'operator_next_action_shell_packet.shell_packet_hash', ''),
                'operator_failure_recovery_matrix_status' => (string) data_get($result, 'operator_failure_recovery_matrix.status', ''),
                'operator_failure_recovery_matrix_row_count' => (int) data_get($result, 'operator_failure_recovery_matrix.row_count', 0),
                'operator_failure_recovery_matrix_requires_fresh_corridor_status' => (bool) data_get($result, 'operator_failure_recovery_matrix.recovery_requires_fresh_corridor_status', false),
                'operator_failure_recovery_matrix_can_recover_from_matrix' => (bool) data_get($result, 'operator_failure_recovery_matrix.can_recover_from_matrix', false),
                'operator_failure_recovery_matrix_hash' => (string) data_get($result, 'operator_failure_recovery_matrix.failure_recovery_matrix_hash', ''),
                'operator_next_action_readiness_gate_status' => (string) data_get($result, 'operator_next_action_readiness_gate.status', ''),
                'operator_next_action_readiness_gate_ready_for_operator_review' => (bool) data_get($result, 'operator_next_action_readiness_gate.ready_for_operator_review', false),
                'operator_next_action_readiness_gate_blocking_reason_count' => (int) data_get($result, 'operator_next_action_readiness_gate.blocking_reason_count', 0),
                'operator_next_action_readiness_gate_current_step_id' => (string) data_get($result, 'operator_next_action_readiness_gate.current_step_id', ''),
                'operator_next_action_readiness_gate_hash' => (string) data_get($result, 'operator_next_action_readiness_gate.readiness_gate_hash', ''),
                'external_completion_claim_policy_status' => (string) data_get($result, 'external_completion_claim_policy.status', ''),
                'external_completion_claim_policy_completion_authority' => (string) data_get($result, 'external_completion_claim_policy.completion_authority', ''),
                'external_completion_claim_policy_required_completion_predicate' => (string) data_get($result, 'external_completion_claim_policy.required_completion_predicate', ''),
                'external_completion_claim_policy_external_agent_claim_accepted' => data_get($result, 'external_completion_claim_policy.external_agent_claim_accepted') === true,
                'external_completion_claim_policy_external_agent_claim_can_mark_os_complete' => data_get($result, 'external_completion_claim_policy.external_agent_claim_can_mark_os_complete') === true,
                'external_completion_claim_policy_external_agent_claim_can_override_audit' => data_get($result, 'external_completion_claim_policy.external_agent_claim_can_override_audit') === true,
                'external_completion_claim_policy_schema_valid' => $externalCompletionClaimPolicyViolationFields === [],
                'external_completion_claim_policy_schema_violation' => $externalCompletionClaimPolicyViolationFields === [] ? '' : 'missing_or_malformed_external_completion_claim_policy',
                'external_completion_claim_policy_schema_violation_fields' => $externalCompletionClaimPolicyViolationFields,
                'external_completion_claim_policy_current_failed_count' => (int) data_get($result, 'external_completion_claim_policy.current_failed_count', 0),
                'external_completion_claim_policy_missing_required_evidence_artifact_count' => (int) data_get($result, 'external_completion_claim_policy.missing_required_evidence_artifact_count', 0),
                'external_completion_claim_policy_hash' => (string) data_get($result, 'external_completion_claim_policy.external_completion_claim_policy_hash', ''),
                'operator_command_surface_integrity_status' => (string) data_get($result, 'operator_command_surface_integrity.status', ''),
                'operator_command_surface_integrity_command_count' => (int) data_get($result, 'operator_command_surface_integrity.command_count', 0),
                'operator_command_surface_integrity_missing_option_count' => (int) data_get($result, 'operator_command_surface_integrity.missing_option_count', 0),
                'operator_command_surface_integrity_legacy_alias_count' => (int) data_get($result, 'operator_command_surface_integrity.legacy_alias_count', 0),
                'operator_command_surface_integrity_legacy_alias_free' => (bool) data_get($result, 'operator_command_surface_integrity.legacy_alias_free', false),
                'operator_command_surface_integrity_hash' => (string) data_get($result, 'operator_command_surface_integrity.command_surface_integrity_hash', ''),
                'terminal_loop_closure_proof_status' => (string) data_get($result, 'terminal_loop_closure_proof.status', ''),
                'terminal_loop_closure_proof_required_before_final_receipt' => (bool) data_get($result, 'terminal_loop_closure_proof.required_before_final_completion_receipt', false),
                'terminal_loop_closure_proof_required_before_human_receipt_persist' => (bool) data_get($result, 'terminal_loop_closure_proof.required_before_human_completion_receipt_persist', false),
                'terminal_loop_closure_proof_command' => (string) data_get($result, 'terminal_loop_closure_proof.proof_command', ''),
                'terminal_loop_closure_proof_binding_persist_command' => (string) data_get($result, 'terminal_loop_closure_proof.proof_binding_persist_command', ''),
                'terminal_loop_closure_proof_audit_command_with_binding' => (string) data_get($result, 'terminal_loop_closure_proof.audit_command_with_binding', ''),
                'terminal_loop_closure_proof_canonical_binding_path' => (string) data_get($result, 'terminal_loop_closure_proof.expected_binding_artifact_path', ''),
                'terminal_loop_closure_proof_audit_command_with_canonical_binding' => (string) data_get($result, 'terminal_loop_closure_proof.audit_command_with_canonical_binding', ''),
                'terminal_loop_closure_proof_effective_audit_command_with_binding' => (string) data_get($result, 'terminal_loop_closure_proof.effective_audit_command_with_binding', ''),
                'terminal_loop_closure_proof_expected_binding_schema' => (string) data_get($result, 'terminal_loop_closure_proof.expected_binding_schema', ''),
                'terminal_loop_closure_proof_acceptance_criteria_count' => count((array) data_get($result, 'terminal_loop_closure_proof.acceptance_criteria', [])),
                'terminal_loop_closure_proof_stop_condition_count' => count((array) data_get($result, 'terminal_loop_closure_proof.stop_conditions', [])),
                'terminal_loop_closure_proof_required_end_to_end_contract_capability_count' => count((array) data_get($result, 'terminal_loop_closure_proof.required_end_to_end_contract_capabilities', [])),
                'terminal_loop_closure_proof_required_end_to_end_contract_capabilities' => (array) data_get($result, 'terminal_loop_closure_proof.required_end_to_end_contract_capabilities', []),
                'terminal_loop_closure_proof_packet_hash' => (string) data_get($result, 'terminal_loop_closure_proof.terminal_loop_closure_proof_packet_hash', ''),
                'operator_completion_progress_meter_status' => (string) data_get($result, 'operator_completion_progress_meter.status', ''),
                'operator_completion_progress_meter_progress_percent' => (int) data_get($result, 'operator_completion_progress_meter.progress_percent', 0),
                'operator_completion_progress_meter_green_artifact_count' => (int) data_get($result, 'operator_completion_progress_meter.green_artifact_count', 0),
                'operator_completion_progress_meter_blocked_artifact_ids' => (array) data_get($result, 'operator_completion_progress_meter.blocked_artifact_ids', []),
                'operator_completion_progress_meter_blocked_artifact_count' => (int) data_get($result, 'operator_completion_progress_meter.blocked_artifact_count', 0),
                'operator_completion_progress_meter_blocked_artifact_count_includes_final_completion_audit' => (bool) data_get($result, 'operator_completion_progress_meter.blocked_artifact_count_includes_final_completion_audit', false),
                'operator_completion_progress_meter_operator_evidence_blocked_artifact_ids' => (array) data_get($result, 'operator_completion_progress_meter.operator_evidence_blocked_artifact_ids', []),
                'operator_completion_progress_meter_operator_evidence_blocked_artifact_count' => (int) data_get($result, 'operator_completion_progress_meter.operator_evidence_blocked_artifact_count', 0),
                'operator_completion_progress_meter_final_completion_audit_blocked' => (bool) data_get($result, 'operator_completion_progress_meter.final_completion_audit_blocked', false),
                'operator_completion_progress_meter_current_required_artifact' => (string) data_get($result, 'operator_completion_progress_meter.current_required_artifact', ''),
                'operator_completion_progress_meter_technical_blockers_clear' => (bool) data_get($result, 'operator_completion_progress_meter.technical_blockers_clear', false),
                'operator_completion_progress_meter_hash' => (string) data_get($result, 'operator_completion_progress_meter.progress_meter_hash', ''),
                'closure_readiness_summary_status' => (string) data_get($result, 'closure_readiness_summary.status', ''),
                'closure_readiness_summary_technical_closure_green' => (bool) data_get($result, 'closure_readiness_summary.technical_closure_green', false),
                'closure_readiness_summary_technical_blocker_count' => (int) data_get($result, 'closure_readiness_summary.technical_blocker_count', 0),
                'technical_closure_green' => (bool) data_get($result, 'closure_readiness_summary.technical_closure_green', false),
                'technical_blocker_count' => (int) data_get($result, 'closure_readiness_summary.technical_blocker_count', 0),
                'technical_blockers' => (array) data_get($result, 'closure_readiness_summary.technical_blocker_ids', []),
                'closure_readiness_summary_human_blocker_count' => (int) data_get($result, 'closure_readiness_summary.human_blocker_count', 0),
                'human_blocker_count' => (int) data_get($result, 'closure_readiness_summary.human_blocker_count', 0),
                'human_blockers' => (array) data_get($result, 'closure_readiness_summary.human_blocker_ids', []),
                'closure_readiness_summary_real_provider_blocker_count' => (int) data_get($result, 'closure_readiness_summary.real_provider_blocker_count', 0),
                'real_provider_blocker_count' => (int) data_get($result, 'closure_readiness_summary.real_provider_blocker_count', 0),
                'real_provider_blockers' => (array) data_get($result, 'closure_readiness_summary.real_provider_blocker_ids', []),
                'closure_readiness_summary_operator_evidence_blocking_artifact_count' => (int) data_get($result, 'closure_readiness_summary.operator_evidence_blocking_artifact_count', 0),
                'closure_readiness_summary_prompt_to_artifact_checklist_count' => (int) data_get($result, 'closure_readiness_summary.prompt_to_artifact_checklist_count', 0),
                'closure_readiness_summary_loop_objective_evidence_row_count' => (int) data_get($result, 'closure_readiness_summary.loop_objective_evidence_row_count', 0),
                'closure_readiness_summary_loop_objective_evidence_passed_count' => (int) data_get($result, 'closure_readiness_summary.loop_objective_evidence_passed_count', 0),
                'closure_readiness_summary_loop_objective_evidence_all_passed' => (bool) data_get($result, 'closure_readiness_summary.loop_objective_evidence_all_passed', false),
                'closure_readiness_summary_next_step_id' => (string) data_get($result, 'closure_readiness_summary.next_step_id', ''),
                'closure_readiness_summary_can_self_promote_completion' => (bool) data_get($result, 'closure_readiness_summary.can_self_promote_completion', false),
                'closure_readiness_summary_hash' => (string) data_get($result, 'closure_readiness_summary.closure_readiness_summary_hash', ''),
                'completion_allowed' => false,
                'completion_claim_allowed' => false,
            ],
        );
    }


    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function atlasSelfProgrammingOsTransitionReadinessStatus(array $options = []): array
    {
        $liveStatusProjection = $options === [];
        $options = $this->withTerminalLoopOperationalProofPayload($options);

        $gate = (new AtlasSelfConstructionFinalCompletionReadinessGateService($this->mother ?? throw new \RuntimeException("mother unbound")))->evaluate($options);
        $transition = (array) data_get($gate, 'self_programming_os_transition_readiness', []);
        $transition['source_final_completion_readiness_gate_status'] = (string) data_get($gate, 'status', '');
        $transition['source_final_completion_readiness_gate_hash'] = (string) data_get($gate, 'gate_hash', '');
        $transition['source_completion_audit_hash'] = (string) data_get($gate, 'completion_audit_hash', '');
        $transition['source_completion_audit_failed_criteria'] = (array) data_get($gate, 'completion_audit_failed_criteria', []);
        $transition['completion_allowed'] = false;
        $transition['completion_claim_allowed'] = (bool) data_get($gate, 'completion_claim_allowed', false);
        $transition['next_stage_allowed'] = (bool) data_get($gate, 'next_stage_allowed', false);
        $transition['closure_artifact_sequence'] = (array) data_get($gate, 'closure_artifact_sequence', []);
        $transition['closure_artifact_sequence_count'] = (int) data_get($gate, 'closure_artifact_sequence_count', 0);
        $transition['closure_artifact_sequence_hash'] = (string) data_get($gate, 'closure_artifact_sequence_hash', '');
        $transition['prompt_to_artifact_checklist'] = (array) data_get($gate, 'prompt_to_artifact_checklist', []);
        $transition['prompt_to_artifact_checklist_count'] = (int) data_get($gate, 'prompt_to_artifact_checklist_count', 0);
        $transition['prompt_to_artifact_checklist_passed_count'] = (int) data_get($gate, 'prompt_to_artifact_checklist_passed_count', 0);
        $transition['prompt_to_artifact_checklist_hash'] = (string) data_get($gate, 'prompt_to_artifact_checklist_hash', '');
        $terminalLoopBindingPath = 'storage/app/private/'.AtlasSelfConstructionReadinessService::CANONICAL_OPERATOR_SUBMISSION_PATHS['terminal_loop_operational_proof_binding'];
        $transition['terminal_loop_operational_proof_canonical_binding_path'] = $terminalLoopBindingPath;
        $transition['terminal_loop_operational_proof_required_before_completion_claim'] = true;
        $transition['completion_audit_without_terminal_loop_operational_proof_is_diagnostic_only'] = true;
        $transition['transition_readiness_command_with_canonical_terminal_loop_binding'] = 'php artisan atlas:ai:self-construction --atlas-self-programming-os-transition-readiness-status --agent-control-plane-terminal-loop-operational-proof-json=@'.$terminalLoopBindingPath.' --json';
        $transition['completion_audit_command_with_canonical_terminal_loop_binding'] = 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-audit-status --agent-control-plane-terminal-loop-operational-proof-json=@'.$terminalLoopBindingPath.' --json';
        $transition['terminal_loop_operational_proof_supplied_to_transition_readiness'] = isset($options['agent_control_plane_terminal_loop_operational_proof']);
        $transition['terminal_loop_operational_proof_source'] = (string) ($options['agent_control_plane_terminal_loop_operational_proof_source'] ?? (isset($options['agent_control_plane_terminal_loop_operational_proof_json']) ? 'explicit_json_option' : ''));
        $transition['source_completion_audit_failed_criteria_count'] = count((array) data_get($transition, 'source_completion_audit_failed_criteria', []));
        $transition['external_completion_claim_policy'] = [
            'schema_version' => 'atlas.self_programming.transition_external_completion_claim_policy.v1',
            'mode' => 'read_only_self_programming_transition_external_completion_claim_policy',
            'status' => (bool) data_get($transition, 'self_construction_complete', false) ? 'completion_claim_delegated_to_completion_audit' : 'reject_external_completion_claim',
            'completion_authority' => 'atlas_self_construction_os_completion_audit',
            'required_completion_predicate' => 'completion_audit.status=complete AND completion_allowed=true AND failed_count=0',
            'source_completion_audit_hash' => (string) data_get($transition, 'source_completion_audit_hash', ''),
            'external_agent_claim_accepted' => false,
            'external_agent_claim_can_mark_os_complete' => false,
            'external_agent_claim_can_override_audit' => false,
            'current_failed_count' => (int) data_get($transition, 'source_completion_audit_failed_criteria_count', 0),
            'current_failed_criteria' => (array) data_get($transition, 'source_completion_audit_failed_criteria', []),
            'transition_allowed_from_external_claim' => false,
            'self_programming_allowed_from_external_claim' => false,
            'failure_policy' => [
                'reject_external_agent_completion_claim',
                'require_completion_audit_status_complete',
                'require_completion_allowed_true',
                'require_failed_count_zero',
                'require_final_completion_readiness_gate_green',
                'require_self_programming_transition_readiness_green',
            ],
            'non_execution_guarantees' => [
                'transition_external_completion_claim_policy_does_not_execute_commands',
                'transition_external_completion_claim_policy_does_not_persist_receipts',
                'transition_external_completion_claim_policy_does_not_sign_for_operator',
                'transition_external_completion_claim_policy_does_not_call_provider',
                'transition_external_completion_claim_policy_does_not_spend_tokens',
                'transition_external_completion_claim_policy_does_not_dispatch',
                'transition_external_completion_claim_policy_does_not_enable_self_programming',
            ],
        ];
        $transition['external_completion_claim_policy']['external_completion_claim_policy_hash'] = $this->stableHash($transition['external_completion_claim_policy']);
        $externalCompletionClaimPolicyViolationFields = $this->externalCompletionClaimPolicyViolationFields($transition, [
            'external_agent_claim_accepted',
            'external_agent_claim_can_mark_os_complete',
            'external_agent_claim_can_override_audit',
            'transition_allowed_from_external_claim',
            'self_programming_allowed_from_external_claim',
        ]);
        $transitionBlockers = array_values(array_unique(array_filter(
            (array) data_get($transition, 'blockers', []),
            static fn (mixed $blocker): bool => is_string($blocker) && trim($blocker) !== '',
        )));
        $transition['blockers'] = $transitionBlockers;
        $transition['transition_blocker_count'] = count($transitionBlockers);
        $operatorOnlyHumanClosureBlockers = [
            'runtime_gap_matrix_all_runtime_y',
            'human_signed_os_complete_receipt_present',
        ];
        $operatorOnlyRealProviderClosureBlockers = [
            'end_to_end_real_provider_smoke_green',
        ];
        $transition['operator_only_human_blockers'] = array_values(array_filter(
            $operatorOnlyHumanClosureBlockers,
            static fn (string $blocker): bool => in_array($blocker, $transitionBlockers, true),
        ));
        $transition['operator_only_human_blocker_count'] = count((array) data_get($transition, 'operator_only_human_blockers', []));
        $transition['operator_only_real_provider_blockers'] = array_values(array_filter(
            $operatorOnlyRealProviderClosureBlockers,
            static fn (string $blocker): bool => in_array($blocker, $transitionBlockers, true),
        ));
        $transition['operator_only_real_provider_blocker_count'] = count((array) data_get($transition, 'operator_only_real_provider_blockers', []));
        $transition['technical_blockers'] = array_values(array_diff(
            $transitionBlockers,
            (array) data_get($transition, 'operator_only_human_blockers', []),
            (array) data_get($transition, 'operator_only_real_provider_blockers', []),
        ));
        $transition['technical_blocker_count'] = count((array) data_get($transition, 'technical_blockers', []));
        $transition['current_required_operator_artifact'] = match (true) {
            in_array('runtime_gap_matrix_all_runtime_y', (array) data_get($transition, 'blockers', []), true) => 'runtime_promotion_receipt',
            in_array('end_to_end_real_provider_smoke_green', (array) data_get($transition, 'blockers', []), true) => 'real_provider_smoke',
            in_array('human_signed_os_complete_receipt_present', (array) data_get($transition, 'blockers', []), true) => 'human_completion_receipt',
            default => 'none',
        };
        $transition['operator_evidence_readiness_command'] = 'php artisan atlas:ai:self-construction --atlas-self-construction-operator-evidence-submission-readiness-status --json';
        $transition['final_operator_evidence_closure_corridor_command'] = 'php artisan atlas:ai:self-construction --atlas-self-construction-final-operator-evidence-closure-corridor-status --json';
        $transition['next_stage_first_self_programming_task_allowed'] = false;
        $transition['next_stage_runtime_activation_allowed'] = false;
        $runtimeGapMatrix = (array) data_get($options, 'runtime_gap_matrix', []);
        if ($runtimeGapMatrix === []) {
            $runtimeGapMatrix = (new AtlasSelfConstructionRuntimeGapMatrixService($this->mother ?? throw new \RuntimeException("mother unbound")))->matrix();
        }
        $transition['next_required_command'] = match ((string) data_get($transition, 'current_required_operator_artifact', '')) {
            'runtime_promotion_receipt' => 'php artisan atlas:ai:self-construction --atlas-self-construction-runtime-promotion-receipt-draft-status --signed-by="<operator>" --reason="<operator reason with at least 32 chars>" --json',
            'real_provider_smoke' => 'php artisan atlas:ai:self-construction --atlas-self-construction-real-provider-smoke-draft-status --json',
            'human_completion_receipt' => 'php artisan atlas:ai:self-construction --atlas-self-construction-human-completion-receipt-draft-status --signed-by="<operator>" --reason="<operator reason with at least 32 chars>" --json',
            default => (string) data_get($transition, 'completion_audit_command_with_canonical_terminal_loop_binding', ''),
        };
        $transition['next_required_persist_command'] = match ((string) data_get($transition, 'current_required_operator_artifact', '')) {
            'runtime_promotion_receipt' => 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-evidence-status --runtime-promotion-receipt-json=@/path/to/runtime-promotion.json --persist-runtime-promotion-receipt --json',
            'real_provider_smoke' => 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-evidence-status --real-provider-smoke-json=@/path/to/real-provider-smoke.json --persist-completion-evidence --json',
            'human_completion_receipt' => 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-evidence-status --completion-receipt-json=@/path/to/completion-receipt.json --persist-completion-evidence --json',
            default => '',
        };
        if ($liveStatusProjection) {
            $completionEvidence = $this->atlasSelfConstructionOsCompletionEvidenceStatus($options);
            $transition['current_required_operator_artifact'] = (string) data_get($completionEvidence, 'current_required_operator_artifact', data_get($transition, 'current_required_operator_artifact', ''));
            $transition['next_required_command'] = (string) data_get($completionEvidence, 'next_required_command', data_get($transition, 'next_required_command', ''));
            $transition['next_required_persist_command'] = (string) data_get($completionEvidence, 'next_required_persist_command', data_get($transition, 'next_required_persist_command', ''));
        }
        $transition['runtime_gap_matrix_hash'] = (string) data_get($runtimeGapMatrix, 'runtime_gap_matrix_hash', '');
        $transition['expected_runtime_gap_matrix_hash_for_promotion_receipt'] = (string) data_get($runtimeGapMatrix, 'expected_runtime_gap_matrix_hash_for_promotion_receipt', '');
        $transition['runtime_promotion_basis_hash'] = (string) data_get($runtimeGapMatrix, 'runtime_promotion_basis_hash', '');
        $transition['runtime_promotion_closure_basis_hash'] = (string) data_get($runtimeGapMatrix, 'runtime_promotion_closure_basis_hash', '');

        return $this->wrapCertificationWorkbenchStatus(
            keyPrefix: 'atlas_self_programming_os_transition_readiness',
            label: 'Atlas Self-Programming OS Transition Readiness',
            payload: $transition,
            statusKey: 'status',
            extraStatusFields: [
                'next_stage_name' => (string) data_get($transition, 'next_stage_name', ''),
                'self_construction_complete' => (bool) data_get($transition, 'self_construction_complete', false),
                'contract_design_allowed' => (bool) data_get($transition, 'contract_design_allowed', false),
                'runtime_activation_allowed' => (bool) data_get($transition, 'runtime_activation_allowed', false),
                'self_programming_allowed' => (bool) data_get($transition, 'self_programming_allowed', false),
                'provider_call_allowed' => (bool) data_get($transition, 'provider_call_allowed', false),
                'token_spend_allowed' => (bool) data_get($transition, 'token_spend_allowed', false),
                'blockers' => (array) data_get($transition, 'blockers', []),
                'safety_contract_path' => (string) data_get($transition, 'safety_contract_path', ''),
                'safety_contract_hash' => (string) data_get($transition, 'safety_contract_hash', ''),
                'source_final_completion_readiness_gate_status' => (string) data_get($transition, 'source_final_completion_readiness_gate_status', ''),
                'source_final_completion_readiness_gate_hash' => (string) data_get($transition, 'source_final_completion_readiness_gate_hash', ''),
                'source_completion_audit_hash' => (string) data_get($transition, 'source_completion_audit_hash', ''),
                'source_completion_audit_failed_criteria' => (array) data_get($transition, 'source_completion_audit_failed_criteria', []),
                'source_completion_audit_failed_criteria_count' => (int) data_get($transition, 'source_completion_audit_failed_criteria_count', 0),
                'failed_criteria' => (array) data_get($transition, 'source_completion_audit_failed_criteria', []),
                'failed_count' => (int) data_get($transition, 'source_completion_audit_failed_criteria_count', 0),
                'transition_blocker_count' => (int) data_get($transition, 'transition_blocker_count', 0),
                'human_blocker_count' => (int) data_get($transition, 'operator_only_human_blocker_count', 0),
                'human_blockers' => (array) data_get($transition, 'operator_only_human_blockers', []),
                'real_provider_blocker_count' => (int) data_get($transition, 'operator_only_real_provider_blocker_count', 0),
                'real_provider_blockers' => (array) data_get($transition, 'operator_only_real_provider_blockers', []),
                'technical_blocker_count' => (int) data_get($transition, 'technical_blocker_count', 0),
                'technical_blockers' => (array) data_get($transition, 'technical_blockers', []),
                'operator_only_human_blocker_count' => (int) data_get($transition, 'operator_only_human_blocker_count', 0),
                'operator_only_human_blockers' => (array) data_get($transition, 'operator_only_human_blockers', []),
                'operator_only_real_provider_blocker_count' => (int) data_get($transition, 'operator_only_real_provider_blocker_count', 0),
                'operator_only_real_provider_blockers' => (array) data_get($transition, 'operator_only_real_provider_blockers', []),
                'current_required_operator_artifact' => (string) data_get($transition, 'current_required_operator_artifact', ''),
                'closure_artifact_sequence' => (array) data_get($transition, 'closure_artifact_sequence', []),
                'closure_artifact_sequence_count' => (int) data_get($transition, 'closure_artifact_sequence_count', 0),
                'closure_artifact_sequence_hash' => (string) data_get($transition, 'closure_artifact_sequence_hash', ''),
                'prompt_to_artifact_checklist' => (array) data_get($transition, 'prompt_to_artifact_checklist', []),
                'prompt_to_artifact_checklist_count' => (int) data_get($transition, 'prompt_to_artifact_checklist_count', 0),
                'prompt_to_artifact_checklist_passed_count' => (int) data_get($transition, 'prompt_to_artifact_checklist_passed_count', 0),
                'prompt_to_artifact_checklist_hash' => (string) data_get($transition, 'prompt_to_artifact_checklist_hash', ''),
                'operator_evidence_readiness_command' => (string) data_get($transition, 'operator_evidence_readiness_command', ''),
                'final_operator_evidence_closure_corridor_command' => (string) data_get($transition, 'final_operator_evidence_closure_corridor_command', ''),
                'next_required_command' => (string) data_get($transition, 'next_required_command', ''),
                'next_required_persist_command' => (string) data_get($transition, 'next_required_persist_command', ''),
                'runtime_gap_matrix_hash' => (string) data_get($transition, 'runtime_gap_matrix_hash', ''),
                'expected_runtime_gap_matrix_hash_for_promotion_receipt' => (string) data_get($transition, 'expected_runtime_gap_matrix_hash_for_promotion_receipt', ''),
                'runtime_promotion_basis_hash' => (string) data_get($transition, 'runtime_promotion_basis_hash', ''),
                'runtime_promotion_closure_basis_hash' => (string) data_get($transition, 'runtime_promotion_closure_basis_hash', ''),
                'next_stage_first_self_programming_task_allowed' => (bool) data_get($transition, 'next_stage_first_self_programming_task_allowed', false),
                'next_stage_runtime_activation_allowed' => (bool) data_get($transition, 'next_stage_runtime_activation_allowed', false),
                'completion_allowed' => (bool) data_get($transition, 'completion_allowed', false),
                'completion_claim_allowed' => (bool) data_get($transition, 'completion_claim_allowed', false),
                'next_stage_allowed' => (bool) data_get($transition, 'next_stage_allowed', false),
                'external_completion_claim_policy_status' => (string) data_get($transition, 'external_completion_claim_policy.status', ''),
                'external_completion_claim_policy_completion_authority' => (string) data_get($transition, 'external_completion_claim_policy.completion_authority', ''),
                'external_completion_claim_policy_required_completion_predicate' => (string) data_get($transition, 'external_completion_claim_policy.required_completion_predicate', ''),
                'external_completion_claim_policy_external_agent_claim_accepted' => data_get($transition, 'external_completion_claim_policy.external_agent_claim_accepted') === true,
                'external_completion_claim_policy_external_agent_claim_can_mark_os_complete' => data_get($transition, 'external_completion_claim_policy.external_agent_claim_can_mark_os_complete') === true,
                'external_completion_claim_policy_external_agent_claim_can_override_audit' => data_get($transition, 'external_completion_claim_policy.external_agent_claim_can_override_audit') === true,
                'external_completion_claim_policy_transition_allowed_from_external_claim' => data_get($transition, 'external_completion_claim_policy.transition_allowed_from_external_claim') === true,
                'external_completion_claim_policy_self_programming_allowed_from_external_claim' => data_get($transition, 'external_completion_claim_policy.self_programming_allowed_from_external_claim') === true,
                'external_completion_claim_policy_schema_valid' => $externalCompletionClaimPolicyViolationFields === [],
                'external_completion_claim_policy_schema_violation' => $externalCompletionClaimPolicyViolationFields === [] ? '' : 'missing_or_malformed_external_completion_claim_policy',
                'external_completion_claim_policy_schema_violation_fields' => $externalCompletionClaimPolicyViolationFields,
                'external_completion_claim_policy_current_failed_count' => (int) data_get($transition, 'external_completion_claim_policy.current_failed_count', 0),
                'external_completion_claim_policy_hash' => (string) data_get($transition, 'external_completion_claim_policy.external_completion_claim_policy_hash', ''),
                'terminal_loop_operational_proof_canonical_binding_path' => (string) data_get($transition, 'terminal_loop_operational_proof_canonical_binding_path', ''),
                'terminal_loop_operational_proof_required_before_completion_claim' => (bool) data_get($transition, 'terminal_loop_operational_proof_required_before_completion_claim', false),
                'completion_audit_without_terminal_loop_operational_proof_is_diagnostic_only' => (bool) data_get($transition, 'completion_audit_without_terminal_loop_operational_proof_is_diagnostic_only', false),
                'terminal_loop_operational_proof_supplied_to_transition_readiness' => (bool) data_get($transition, 'terminal_loop_operational_proof_supplied_to_transition_readiness', false),
                'terminal_loop_operational_proof_source' => (string) data_get($transition, 'terminal_loop_operational_proof_source', ''),
                'transition_readiness_command_with_canonical_terminal_loop_binding' => (string) data_get($transition, 'transition_readiness_command_with_canonical_terminal_loop_binding', ''),
                'completion_audit_command_with_canonical_terminal_loop_binding' => (string) data_get($transition, 'completion_audit_command_with_canonical_terminal_loop_binding', ''),
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $requiredFields
     * @return list<string>
     */
    private function externalCompletionClaimPolicyViolationFields(array $payload, array $requiredFields): array
    {
        return array_values(array_filter(
            $requiredFields,
            static fn (string $field): bool => ! is_bool(data_get($payload, "external_completion_claim_policy.{$field}")),
        ));
    }

}
