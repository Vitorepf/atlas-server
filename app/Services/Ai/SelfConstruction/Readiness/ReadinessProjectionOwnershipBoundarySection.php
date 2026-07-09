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
 * SC-01 fatia ReadinessProjectionOwnershipBoundarySection (Obra 4 Residual Elite).
 */
final class ReadinessProjectionOwnershipBoundarySection
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
            throw new \RuntimeException('ReadinessProjectionOwnershipBoundarySection mother not bound for '.$name);
        }
        $method = new \ReflectionMethod($this->mother, $name);

        return $method->invokeArgs($this->mother, $arguments);
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null}  $options
     * @return array<string, mixed>
     */
    public function ownershipBoundary(array $options = []): array
    {
        $externalBlockers = $this->externalBlockers($options);

        $forbiddenScopes = [
            [
                'id' => 'voice_realtime_python_runtime',
                'path' => 'runtimes/python/voice_realtime/**',
                'owner' => 'codex_principal_voice_realtime',
                'reason' => 'Voice/LiveKit runtime is active and outside Self-Construction cold lane.',
            ],
            [
                'id' => 'voice_realtime_php_surface',
                'path' => 'app/Services/Ai/Voice/**',
                'owner' => 'codex_principal_voice_realtime',
                'reason' => 'Voice PHP service/certification surface is active and hot.',
            ],
            [
                'id' => 'kernel_static_scanner',
                'path' => 'app/Services/Ai/Kernel/Architecture/KernelArchitectureStaticScanner.php',
                'owner' => 'codex_principal_kernel_scanner',
                'reason' => 'Kernel scanner owns architecture validation changes.',
            ],
            [
                'id' => 'voice_realtime_owner_docs',
                'path' => 'docs/engineering-knowledge-base/atlas-ai-voice-realtime-surface.md',
                'owner' => 'codex_principal_voice_realtime',
                'reason' => 'Voice owner documentation is coupled to AP-179/AP-185/AP-687 gates.',
            ],
        ];

        $boundary = [
            'id' => 'OWNERSHIP-BOUNDARY-SELF-CONSTRUCTION-COLD-LANE',
            'allowed_files' => $this->receiptAllowedFiles(),
            'forbidden_scopes' => $forbiddenScopes,
            'external_blockers' => data_get($externalBlockers, 'blockers'),
            'required_behavior' => [
                'report_hot_blockers_without_editing',
                'prefer_tests_or_reports_inside_allowed_files',
                'run_focused_self_construction_tests_after_changes',
                'run_git_diff_check_after_changes',
            ],
            'execution_allowed' => false,
            'promotion_allowed' => false,
            'completion_allowed' => false,
        ];

        return [
            'schema_version' => 'atlas.self_construction_ownership_boundary.v1',
            'status' => 'ownership_boundary_ready',
            'mode' => 'read_only_ownership_boundary',
            'execution_allowed' => false,
            'promotion_allowed' => false,
            'completion_allowed' => false,
            'allowed_file_count' => count($boundary['allowed_files']),
            'forbidden_scope_count' => count($forbiddenScopes),
            'boundary' => $boundary,
            'boundary_hash' => $this->stableHash($boundary),
            'non_execution_guarantees' => [
                'ownership_boundary_does_not_edit_hot_files',
                'ownership_boundary_does_not_apply_patch',
                'ownership_boundary_does_not_sign_receipt',
                'ownership_boundary_does_not_enable_execution',
            ],
            'human_summary' => 'Ownership boundary is ready: cold allowed files and hot forbidden scopes are explicit for the next operator.',
        ];
    }


    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneMultiAgentLoopCertificationStatus(array $options = []): array
    {
        $orchestrator = $this->buildTaskQueueOrchestrator();
        $queue = new AgentControlPlaneTaskPacketQueueRepository;
        $leases = new AgentControlPlaneClaimLeaseRepository;
        $svc = new AgentControlPlaneMultiAgentLoopCertificationService($orchestrator, $queue, $leases);
        $agentCount = isset($options['agent_count']) && $options['agent_count'] !== null
            ? (int) $options['agent_count']
            : AgentControlPlaneMultiAgentLoopCertificationService::DEFAULT_AGENT_COUNT;
        $cycles = isset($options['cycles']) && $options['cycles'] !== null
            ? (int) $options['cycles']
            : AgentControlPlaneMultiAgentLoopCertificationService::DEFAULT_CYCLES;
        $targetMin = isset($options['target_min_claimable_tasks']) && $options['target_min_claimable_tasks'] !== null
            ? (int) $options['target_min_claimable_tasks']
            : $agentCount;
        $normalizedOptions = [
            'agent_count' => $agentCount,
            'cycles' => $cycles,
            'target_min_claimable_tasks' => $targetMin,
            'dry_run_only' => (bool) ($options['dry_run_only'] ?? true),
            'simulate_overlap' => (bool) ($options['simulate_overlap'] ?? false),
        ];
        $result = $svc->certify($normalizedOptions);

        return $this->wrapCertificationWorkbenchStatus(
            keyPrefix: 'multi_agent_loop_certification',
            label: 'Multi-Agent Loop Certification',
            payload: $result,
            statusKey: 'status',
            extraStatusFields: [
                'certification_id' => (string) data_get($result, 'certification_id'),
                'certification_hash' => (string) data_get($result, 'certification_hash'),
                'invariants_all_true' => (bool) data_get($result, 'invariants_all_true'),
                'violation_count' => (int) data_get($result, 'violation_count'),
                'warning_count' => (int) data_get($result, 'warning_count'),
                'agent_count' => (int) data_get($result, 'agent_count'),
                'cycles' => (int) data_get($result, 'cycles'),
                'distinct_task_total' => (int) data_get($result, 'distinct_task_total'),
                'distinct_lease_total' => (int) data_get($result, 'distinct_lease_total'),
                'completed_total' => (int) data_get($result, 'completed_total'),
                'evidence_receipt_count' => (int) data_get($result, 'evidence_receipt_count'),
                'continuation_summary_count' => (int) data_get($result, 'continuation_summary_count'),
                'terminal_loop_fleet_launch_runbook_present' => (bool) data_get($result, 'invariants.terminal_loop_fleet_launch_runbook_present'),
                'terminal_loop_fleet_launch_runbook_ready_path_verified' => (bool) data_get($result, 'invariants.terminal_loop_fleet_launch_runbook_ready_path_verified'),
                'terminal_loop_fleet_launch_runbook_status' => (string) data_get($result, 'terminal_loop_fleet_launch_plan_probe.fleet_launch_runbook_status'),
                'terminal_loop_fleet_launch_runbook_terminal_count' => (int) data_get($result, 'terminal_loop_fleet_launch_plan_probe.fleet_launch_runbook_terminal_count'),
                'terminal_loop_fleet_launch_runbook_hash' => (string) data_get($result, 'terminal_loop_fleet_launch_plan_probe.fleet_launch_runbook_hash'),
                'safe_for_parallel_terminal_loop' => (bool) data_get($result, 'canonical_invariant_matrix.invariants.safe_for_parallel_terminal_loop.value'),
                'legacy_reservation_used' => (bool) data_get($result, 'legacy_reservation_used', false),
            ],
        );
    }


    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneOneShotWorkerPacketStatus(array $options = []): array
    {
        $service = new AgentControlPlaneOneShotWorkerPacketService(
            new AgentControlPlaneClaimLeaseRepository,
            new AgentControlPlaneTaskPacketQueueRepository,
        );
        $payload = $service->generate([
            'task_packet_id' => (string) ($options['packet'] ?? $options['task_packet_id'] ?? ''),
            'lease_id' => (string) ($options['lease_id'] ?? ''),
            'actor' => (string) ($options['actor'] ?? ''),
            'mode' => (string) ($options['mode'] ?? AgentControlPlaneOneShotWorkerPacketService::MODE_CLAIMED_TASK),
        ]);

        return $this->wrapCertificationWorkbenchStatus(
            keyPrefix: 'one_shot_worker_packet',
            label: 'One-Shot Worker Packet',
            payload: $payload,
            statusKey: 'status',
            extraStatusFields: [
                'mode' => (string) data_get($payload, 'mode', AgentControlPlaneOneShotWorkerPacketService::MODE_CLAIMED_TASK),
                'task_packet_id' => (string) data_get($payload, 'task_packet_id', ''),
                'lease_id' => (string) data_get($payload, 'lease_id', ''),
                'actor' => (string) data_get($payload, 'actor', ''),
                'one_shot_packet_hash' => (string) data_get($payload, 'one_shot_packet_hash', ''),
                'allowed_files_count' => count((array) data_get($payload, 'allowed_files', [])),
                'forbidden_files_count' => count((array) data_get($payload, 'forbidden_files', [])),
                'acceptance_criteria_count' => count((array) data_get($payload, 'acceptance_criteria', [])),
                'required_tests_count' => count((array) data_get($payload, 'required_tests', [])),
            ],
        );
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, artifact_type?: string|null, artifact_path?: string|null, artifact_hash?: string|null, summary?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentWorkProduct(array $options = []): array
    {
        if (! $this->agentControlPlaneRuntimeSchemaReady()
            || ! Schema::hasTable('atlas_self_construction_agent_work_products')) {
            $workProduct = [
                'status' => 'blocked',
                'blocking_reasons' => ['agent_control_plane_work_product_schema_missing'],
                'required_migration' => 'database/migrations/2026_05_12_010000_create_atlas_self_construction_agent_control_plane_tables.php',
                'agent_runs_table_ready' => Schema::hasTable('atlas_self_construction_agent_runs'),
                'heartbeats_table_ready' => Schema::hasTable('atlas_self_construction_agent_heartbeats'),
                'work_products_table_ready' => Schema::hasTable('atlas_self_construction_agent_work_products'),
            ];

            return [
                'schema_version' => 'atlas.self_construction_agent_work_product.v1',
                'status' => 'blocked',
                'mode' => 'controlled_agent_work_product_registry',
                'execution_allowed' => false,
                'dispatch_allowed' => false,
                'ledger_write_allowed' => false,
                'runtime_write_allowed' => false,
                'work_product' => $workProduct,
                'work_product_hash' => $this->stableHash($workProduct),
                'human_summary' => 'Agent work product is blocked until the Agent Control Plane work product runtime table exists.',
            ];
        }

        $this->agentRunSync($options);

        $actor = $this->reservationActor($options);
        $session = $this->reservationSession($options);
        $packetId = trim((string) ($options['packet'] ?? ''));
        $query = AtlasSelfConstructionAgentRun::query()
            ->where('actor', $actor)
            ->where('session_id', $session);

        if ($packetId !== '') {
            $query->where('packet_id', $packetId);
        }

        $run = $query->latest('updated_at')->first();
        if (! $run instanceof AtlasSelfConstructionAgentRun) {
            $workProduct = [
                'status' => 'blocked',
                'blocking_reasons' => ['agent_run_not_found_for_actor_session'],
                'actor' => $actor,
                'session' => $session,
                'packet_id' => $packetId ?: null,
            ];

            return [
                'schema_version' => 'atlas.self_construction_agent_work_product.v1',
                'status' => 'blocked',
                'mode' => 'controlled_agent_work_product_registry',
                'execution_allowed' => false,
                'dispatch_allowed' => false,
                'ledger_write_allowed' => false,
                'runtime_write_allowed' => false,
                'work_product' => $workProduct,
                'work_product_hash' => $this->stableHash($workProduct),
                'human_summary' => 'Agent work product is blocked because no synced run exists for this actor/session.',
            ];
        }

        $artifactType = trim((string) ($options['artifact_type'] ?? ''));
        $artifactPath = trim((string) ($options['artifact_path'] ?? ''));
        $artifactHash = strtolower(trim((string) ($options['artifact_hash'] ?? '')));
        if ($artifactType === '') {
            $artifactType = 'implementation_artifact';
        }

        $workProductModel = $run->workProducts()->create([
            'work_product_key' => 'WORK-'.strtoupper(substr(hash('sha256', $run->id.'|'.$artifactType.'|'.$artifactPath.'|'.now()->toIso8601String()), 0, 24)),
            'packet_id' => $run->packet_id,
            'actor' => $run->actor,
            'provider' => $run->provider,
            'artifact_type' => $artifactType,
            'artifact_path' => $artifactPath ?: null,
            'artifact_hash' => preg_match('/^[a-f0-9]{64}$/', $artifactHash) === 1 ? $artifactHash : null,
            'status' => 'recorded',
            'summary' => trim((string) ($options['summary'] ?? '')) ?: null,
            'metadata' => [
                'session' => $run->session_id,
                'source' => 'agent_control_plane_work_product_registry',
                'hash_was_valid_sha256' => preg_match('/^[a-f0-9]{64}$/', $artifactHash) === 1,
            ],
        ]);

        $workProduct = [
            'status' => 'recorded',
            'run_id' => $run->id,
            'run_key' => $run->run_key,
            'packet_id' => $run->packet_id,
            'actor' => $run->actor,
            'provider' => $run->provider,
            'session' => $run->session_id,
            'work_product_id' => $workProductModel->id,
            'work_product_key' => $workProductModel->work_product_key,
            'artifact_type' => $workProductModel->artifact_type,
            'artifact_path' => $workProductModel->artifact_path,
            'artifact_hash' => $workProductModel->artifact_hash,
            'summary' => $workProductModel->summary,
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_work_product.v1',
            'status' => 'agent_work_product_recorded',
            'mode' => 'controlled_agent_work_product_registry',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => true,
            'work_product' => $workProduct,
            'work_product_hash' => $this->stableHash($workProduct),
            'non_execution_guarantees' => [
                'agent_work_product_does_not_start_providers',
                'agent_work_product_does_not_claim_packets',
                'agent_work_product_does_not_dispatch_work',
                'agent_work_product_does_not_merge_artifacts',
            ],
            'human_summary' => 'Agent work product was recorded for an existing synced run without dispatching, merging or executing provider work.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewPostSignatureRunbook(array $options = []): array
    {
        $signaturePayload = $this->agentReviewSignatureRequest($options);
        $signatureRequest = (array) data_get($signaturePayload, 'signature_request', []);
        $signablePayload = (array) data_get($signaturePayload, 'signable_payload', []);
        $requestReady = data_get($signaturePayload, 'status') === 'review_signature_pending';

        $steps = [
            [
                'id' => 'verify_signature_payload',
                'title' => 'Verify the signable payload hash matches the future signed governed receipt.',
                'required_evidence' => ['signable_payload_hash', 'signed_receipt_hash'],
            ],
            [
                'id' => 'verify_forge_workspace_decision',
                'title' => 'Verify selected decision, rationale, workspace artifact result, scope result and evidence result are present.',
                'required_evidence' => ['selected_decision', 'decision_rationale', 'workspace_artifact_integrity_result', 'scope_integrity_result', 'evidence_integrity_result'],
            ],
            [
                'id' => 'rerun_review_gates',
                'title' => 'Rerun required review gates before any merge action.',
                'required_evidence' => (array) data_get($signablePayload, 'verification_commands', []),
            ],
            [
                'id' => 'prepare_explicit_merge_action',
                'title' => 'Prepare a separate explicit merge action only if decision is approve_for_merge and all gates pass.',
                'required_evidence' => ['explicit_merge_action_or_manual_merge_record'],
            ],
        ];

        $runbook = [
            'runbook_id' => 'AGENT-REVIEW-POST-SIGNATURE-RUNBOOK-SELF-CONSTRUCTION-0001',
            'workspace' => [
                'workspace_id' => 'FORGE-WORKSPACE-ATLAS-SELF-CONSTRUCTION-0001',
                'canonical_name' => 'Obras Shared Workspace',
                'specialization' => 'Forge Workspace',
                'obra_id' => 'OBRA-ATLAS-SELF-CONSTRUCTION-OS',
            ],
            'status' => $requestReady ? 'waiting_for_future_valid_signature' : 'blocked_before_signature_request',
            'source_signature_request_hash' => data_get($signaturePayload, 'request_hash'),
            'source_signable_payload_hash' => data_get($signaturePayload, 'signable_payload_hash'),
            'source_signature_request_status' => data_get($signatureRequest, 'status'),
            'required_signer_roles' => (array) data_get($signatureRequest, 'required_signer_roles', []),
            'signature_required' => true,
            'signature_present' => false,
            'signature_valid' => false,
            'decision_recorded' => false,
            'approval_granted' => false,
            'merge_allowed' => false,
            'auto_merge_allowed' => false,
            'step_count' => count($steps),
            'steps' => $steps,
            'required_before_any_merge_action' => [
                'valid_signature_against_signable_payload_hash',
                'selected_decision_is_approve_for_merge',
                'all_required_review_gates_passed_after_signature',
                'workspace_artifact_integrity_result_is_artifacts_verified',
                'scope_integrity_result_is_scope_clean',
                'evidence_integrity_result_is_evidence_verified',
                'explicit_manual_or_governed_merge_action_created',
            ],
            'still_forbidden' => array_values(array_unique(array_merge(
                (array) data_get($signablePayload, 'still_forbidden_after_signature', []),
                [
                    'auto_merge_from_runbook',
                    'merge_without_explicit_action',
                    'dispatch_from_runbook',
                    'hot_voice_or_kernel_scope_changes',
                ]
            ))),
            'operator_commands' => [
                'signature_request' => 'php artisan atlas:ai:self-construction --agent-review-signature-request --json',
                'receipt_draft' => 'php artisan atlas:ai:self-construction --agent-review-receipt-draft --json',
                'final_review_packet' => 'php artisan atlas:ai:self-construction --agent-final-review-packet --json',
                'merge_readiness' => 'php artisan atlas:ai:self-construction --agent-merge-readiness --json',
                'integration_report' => 'php artisan atlas:ai:self-construction --agent-integration-report --json',
                'focused_tests' => 'php artisan test tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php',
                'docs_health' => 'php artisan atlas:engineering:knowledge docs-health --json',
                'architecture_validate' => 'php artisan atlas:ai:architecture-validate --json',
                'diff_check' => 'git diff --check',
            ],
            'non_authorizing_invariants' => [
                'post_signature_runbook_does_not_accept_signature',
                'post_signature_runbook_does_not_validate_signature',
                'post_signature_runbook_does_not_record_decision',
                'post_signature_runbook_does_not_grant_approval',
                'post_signature_runbook_does_not_merge',
                'post_signature_runbook_does_not_dispatch_work',
            ],
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_review_post_signature_runbook.v1',
            'status' => $requestReady ? 'review_post_signature_runbook_ready' : 'blocked_before_review_post_signature_runbook',
            'mode' => 'read_only_provider_neutral_agent_review_post_signature_runbook',
            'execution_allowed' => false,
            'completion_allowed' => false,
            'claim_persisted' => false,
            'completion_persisted' => false,
            'ledger_write_allowed' => false,
            'dispatch_allowed' => false,
            'approval_granted' => false,
            'merge_allowed' => false,
            'decision_recorded' => false,
            'signature_valid' => false,
            'runbook' => $runbook,
            'runbook_hash' => $this->stableHash($runbook),
            'non_execution_guarantees' => [
                'agent_review_post_signature_runbook_does_not_claim_packets',
                'agent_review_post_signature_runbook_does_not_complete_packets',
                'agent_review_post_signature_runbook_does_not_accept_signature',
                'agent_review_post_signature_runbook_does_not_validate_signature',
                'agent_review_post_signature_runbook_does_not_record_decision',
                'agent_review_post_signature_runbook_does_not_approve_code',
                'agent_review_post_signature_runbook_does_not_merge',
                'agent_review_post_signature_runbook_does_not_dispatch_work',
            ],
            'human_summary' => $requestReady
                ? 'Agent review post-signature runbook is ready as a conditional Forge Workspace checklist. It does not accept or validate signatures, approve, merge or dispatch.'
                : 'Agent review post-signature runbook is blocked until an agent review signature request is ready.',
        ];
    }

}
