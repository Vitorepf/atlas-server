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
use Illuminate\Support\Facades\DB;
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
 * SC-01 fatia ReadinessProjectionReleaseWriterSection (Obra 4 Residual Elite).
 */
final class ReadinessProjectionReleaseWriterSection
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
            throw new \RuntimeException('ReadinessProjectionReleaseWriterSection mother not bound for '.$name);
        }
        if (! is_callable([$this->mother, $name])) {
            throw new \BadMethodCallException('ReadinessProjectionReleaseWriterSection does not expose '.$name);
        }

        return $this->mother->{$name}(...$arguments);
    }

    /** @param array<string, mixed> $payload */
    private function stableHash(array $payload): string
    {
        return ReadinessHash::stable($payload);
    }

    /** @return array<string, bool> */
    private function agentControlPlaneRuntimeTables(): array
    {
        return ReadinessAgentControlPlaneSchemaProbe::tables();
    }

    /** @return list<string> */
    private function hotForbiddenFiles(): array
    {
        return ReadinessCatalog::hotForbiddenFiles();
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickMutatingWriterContract(array $options = []): array
    {
        $releasePreflightPayload = $this->agentAutomaticDispatchSchedulerOneShotTickMutatingWriterReleasePreflight($options);
        $releasePreflight = (array) data_get($releasePreflightPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_mutating_writer_release_preflight', []);
        $releasePreflightReady = data_get($releasePreflight, 'status') === 'one_shot_tick_mutating_writer_release_preflight_ready';
        $releasePreflightBlockingReasons = (array) data_get($releasePreflight, 'blocking_reasons', []);

        $contract = [
            'status' => $releasePreflightReady ? 'one_shot_tick_mutating_writer_contract_ready' : 'blocked',
            'contract_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-MUTATING-WRITER-CONTRACT-SELF-CONSTRUCTION-0001',
            'parent_program' => 'Atlas Self-Construction OS',
            'submodule' => 'Atlas Agent Control Plane',
            'workspace_id' => 'FORGE-WORKSPACE-ATLAS-SELF-CONSTRUCTION-0001',
            'source_release_preflight_status' => data_get($releasePreflight, 'status'),
            'source_release_preflight_hash' => data_get($releasePreflightPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_mutating_writer_release_preflight_hash'),
            'contract_method' => 'executeOneShotSchedulerTickAfterReleasePreflight',
            'readiness_dependency' => [
                'release_preflight_must_be_ready_before_future_mutation' => true,
                'current_release_preflight_ready' => $releasePreflightReady,
                'current_release_preflight_blocking_reasons' => $releasePreflightBlockingReasons,
            ],
            'writer_scope' => [
                'max_wakeup_claims_per_invocation' => 1,
                'max_dispatch_receipts_per_invocation' => 1,
                'allowed_source_wakeup_status' => 'queued',
                'new_wakeup_status_after_claim' => 'claimed',
                'new_dispatch_receipt_status' => 'signed_pending_dispatch',
                'provider_start_allowed' => false,
                'receipt_use_allowed' => false,
                'adapter_invocation_allowed' => false,
                'token_spend_allowed' => false,
                'self_programming_allowed' => false,
            ],
            'required_preconditions' => [
                'release_preflight_must_be_ready',
                'persisted_release_receipt_exists',
                'persisted_release_receipt_is_unexpired',
                'persisted_release_receipt_matches_current_dry_run_candidate',
                'selected_wakeup_item_is_still_queued',
                'selected_wakeup_item_is_not_claimed',
                'dispatch_envelope_hash_matches_release_receipt',
                'provider_start_is_forbidden_by_release_receipt_payload',
                'dispatch_receipt_write_was_forbidden_by_persistence_writer_payload',
                'self_programming_is_forbidden_by_release_receipt_payload',
                'tick_idempotency_key_is_available',
            ],
            'atomic_mutation_sequence' => [
                'open_database_transaction',
                'recompute_release_preflight_inside_transaction',
                'lock_selected_wakeup_row_for_update',
                'verify_release_receipt_still_unexpired',
                'verify_selected_wakeup_is_still_queued_and_unclaimed',
                'claim_selected_wakeup_item_atomically',
                'write_one_signed_pending_dispatch_receipt',
                'append_scheduler_tick_evidence_event',
                'commit_transaction_and_stop_before_receipt_use',
            ],
            'allowed_future_mutations_after_all_preconditions' => [
                'claim_selected_wakeup_item_atomically',
                'write_one_signed_pending_dispatch_receipt',
                'append_scheduler_tick_evidence_event',
            ],
            'forbidden_even_after_contract' => [
                'mark_dispatch_receipt_used',
                'start_provider_process',
                'invoke_provider_adapter',
                'spend_provider_tokens',
                'mark_packet_completed',
                'merge_or_apply_work_products',
                'enable_self_programming',
            ],
            'idempotency_policy' => [
                'tick_idempotency_key_fields' => [
                    'release_receipt_hash',
                    'selected_wakeup_key',
                    'dispatch_envelope_hash',
                    'source_dry_run_tick_hash',
                ],
                'duplicate_tick_idempotency_key_returns_existing_tick_result' => true,
                'conflicting_wakeup_claim_is_rejected' => true,
                'conflicting_dispatch_receipt_hash_is_rejected' => true,
            ],
            'rollback_policy' => [
                'transaction_must_rollback_if_wakeup_claim_fails',
                'transaction_must_rollback_if_dispatch_receipt_write_fails',
                'transaction_must_rollback_if_evidence_write_fails',
                'no_partial_claim_without_dispatch_receipt',
                'no_partial_dispatch_receipt_without_evidence',
            ],
            'required_gates' => [
                'php_lint_readiness_service',
                'php_lint_command',
                'focused_self_construction_command_tests',
                'dedicated_mutating_writer_service_tests_before_activation',
                'architecture_validate',
                'docs_health',
                'git_diff_check',
            ],
            'forbidden_now' => [
                'accept_release_signature',
                'persist_release_receipt',
                'claim_wakeup_item',
                'write_dispatch_receipt',
                'mark_dispatch_receipt_used',
                'start_provider_process',
                'invoke_provider_adapter',
                'spend_provider_tokens',
                'enable_self_programming',
            ],
            'next_required_slice' => $releasePreflightReady
                ? 'activate_signed_one_shot_scheduler_tick_mutating_writer_preflight'
                : 'repair_signed_one_shot_scheduler_tick_mutating_writer_release_preflight_blockers',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_mutating_writer_contract.v1',
            'status' => (string) $contract['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_mutating_writer_contract',
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
            'agent_automatic_dispatch_scheduler_one_shot_tick_mutating_writer_contract' => $contract,
            'agent_automatic_dispatch_scheduler_one_shot_tick_mutating_writer_contract_hash' => $this->stableHash($contract),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_mutating_writer_contract_does_not_accept_signatures',
                'agent_automatic_dispatch_scheduler_one_shot_tick_mutating_writer_contract_does_not_persist_release_receipts',
                'agent_automatic_dispatch_scheduler_one_shot_tick_mutating_writer_contract_does_not_claim_wakeup_items',
                'agent_automatic_dispatch_scheduler_one_shot_tick_mutating_writer_contract_does_not_write_dispatch_receipts',
                'agent_automatic_dispatch_scheduler_one_shot_tick_mutating_writer_contract_does_not_start_providers',
                'agent_automatic_dispatch_scheduler_one_shot_tick_mutating_writer_contract_does_not_enable_self_programming',
            ],
            'human_summary' => $releasePreflightReady
                ? 'Automatic dispatch scheduler one-shot tick mutating writer contract is ready as a read-only contract: future code may claim exactly one wakeup and write exactly one dispatch receipt only after release preflight is ready, then must stop before provider start.'
                : 'Automatic dispatch scheduler one-shot tick mutating writer contract is blocked until release preflight proves the selected wakeup and persisted release receipt are current.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickWriterPreflight(array $options = []): array
    {
        $contractPayload = $this->agentAutomaticDispatchSchedulerOneShotTickWriterContract($options);
        $contract = (array) data_get($contractPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_writer_contract', []);
        $dryRunTickPayload = $this->agentAutomaticDispatchSchedulerDryRunTick($options);
        $dryRunTick = (array) data_get($dryRunTickPayload, 'agent_automatic_dispatch_scheduler_dry_run_tick', []);
        $selectedWakeupItem = data_get($dryRunTick, 'queue_projection.selected_wakeup_item');
        $dispatchEnvelopePreview = data_get($dryRunTick, 'dispatch_envelope_preview');
        $releaseReceiptHash = trim((string) ($options['receipt_hash'] ?? ''));
        $runtimeTables = $this->agentControlPlaneRuntimeTables();

        $dispatchEnvelopeHash = is_array($dispatchEnvelopePreview)
            ? $this->stableHash($dispatchEnvelopePreview)
            : null;
        $selectedWakeupKey = is_array($selectedWakeupItem)
            ? (string) data_get($selectedWakeupItem, 'wakeup_key')
            : null;

        $componentReadiness = [
            'one_shot_tick_writer_contract' => data_get($contractPayload, 'status') === 'agent_automatic_dispatch_scheduler_one_shot_tick_writer_contract_ready',
            'dry_run_tick' => data_get($dryRunTickPayload, 'status') === 'agent_automatic_dispatch_scheduler_dry_run_tick_ready',
            'wakeup_items_table' => $runtimeTables['atlas_self_construction_agent_wakeup_items'],
            'dispatch_receipts_table' => $runtimeTables['atlas_self_construction_agent_dispatch_receipts'],
            'agent_runs_table' => $runtimeTables['atlas_self_construction_agent_runs'],
            'selected_wakeup_item_available' => is_array($selectedWakeupItem),
            'dispatch_envelope_preview_available' => is_array($dispatchEnvelopePreview),
        ];
        $blockingReasons = array_values(array_map(
            static fn (string $component): string => $component.'_not_ready',
            array_keys(array_filter($componentReadiness, static fn (bool $ready): bool => ! $ready))
        ));

        $releaseReadiness = [
            'release_receipt_hash_provided' => $releaseReceiptHash !== '',
            'release_receipt_hash_shape_valid' => $releaseReceiptHash === '' || preg_match('/^[a-f0-9]{64}$/', $releaseReceiptHash) === 1,
            'release_receipt_persistence_checked_here' => false,
            'release_receipt_signature_checked_here' => false,
            'release_receipt_expiry_checked_here' => false,
            'release_receipt_required_decision' => data_get($contract, 'required_signed_release.decision'),
        ];

        $preflightChecks = [
            'dry_run_tick_hash_matches_contract' => data_get($contract, 'source_dry_run_tick_hash') === data_get($dryRunTickPayload, 'agent_automatic_dispatch_scheduler_dry_run_tick_hash'),
            'selected_wakeup_item_is_present' => is_array($selectedWakeupItem),
            'selected_wakeup_item_is_queued' => data_get($selectedWakeupItem, 'status') === 'queued',
            'selected_wakeup_item_has_not_been_claimed' => data_get($selectedWakeupItem, 'claimed_at') === null,
            'dispatch_envelope_preview_is_present' => is_array($dispatchEnvelopePreview),
            'dispatch_envelope_hash_is_available' => is_string($dispatchEnvelopeHash) && $dispatchEnvelopeHash !== '',
            'release_receipt_hash_provided' => $releaseReadiness['release_receipt_hash_provided'],
            'release_receipt_hash_shape_valid' => $releaseReadiness['release_receipt_hash_shape_valid'],
        ];
        $failedPreflightChecks = array_values(array_keys(array_filter(
            $preflightChecks,
            static fn (bool $passed): bool => ! $passed
        )));
        $blockingReasons = array_values([
            ...$blockingReasons,
            ...array_map(
                static fn (string $check): string => $check.'_not_ready',
                $failedPreflightChecks
            ),
        ]);

        $preflight = [
            'status' => $blockingReasons === [] ? 'agent_automatic_dispatch_scheduler_one_shot_tick_writer_preflight_ready' : 'blocked',
            'preflight_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-WRITER-PREFLIGHT-SELF-CONSTRUCTION-0001',
            'parent_program' => 'Atlas Self-Construction OS',
            'submodule' => 'Atlas Agent Control Plane',
            'source_contract_hash' => data_get($contractPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_writer_contract_hash'),
            'source_dry_run_tick_hash' => data_get($dryRunTickPayload, 'agent_automatic_dispatch_scheduler_dry_run_tick_hash'),
            'component_readiness' => $componentReadiness,
            'blocking_count' => count($blockingReasons),
            'blocking_reasons' => $blockingReasons,
            'selected_candidate' => [
                'selected_wakeup_key' => $selectedWakeupKey,
                'packet_id' => data_get($selectedWakeupItem, 'packet_id'),
                'provider' => data_get($selectedWakeupItem, 'provider'),
                'actor' => data_get($selectedWakeupItem, 'actor'),
                'reason' => data_get($selectedWakeupItem, 'reason'),
                'candidate_available' => is_array($selectedWakeupItem),
            ],
            'dispatch_envelope' => [
                'preview_available' => is_array($dispatchEnvelopePreview),
                'dispatch_envelope_hash' => $dispatchEnvelopeHash,
                'idempotency_key' => data_get($dispatchEnvelopePreview, 'idempotency_key'),
            ],
            'release_readiness' => $releaseReadiness,
            'preflight_checks' => $preflightChecks,
            'failed_preflight_checks' => $failedPreflightChecks,
            'mutation_decision' => [
                'mutation_allowed_here' => false,
                'would_be_blocked_without_failed_checks' => $failedPreflightChecks !== [],
                'requires_future_signed_release_template' => true,
                'requires_future_release_receipt_persistence' => true,
                'requires_future_atomic_writer' => true,
            ],
            'allowed_now' => [
                'one_shot_tick_writer_preflight_projection',
                'candidate_freshness_projection',
                'release_receipt_shape_projection',
                'dispatch_envelope_hash_projection',
            ],
            'forbidden_now' => [
                'claim_wakeup_item',
                'write_dispatch_receipt',
                'persist_release_receipt',
                'mark_dispatch_receipt_used',
                'start_provider_process',
                'invoke_provider_adapter',
                'spend_provider_tokens',
            ],
            'next_required_slice' => $blockingReasons === []
                ? 'activate_signed_one_shot_scheduler_tick_release_template'
                : 'repair_signed_one_shot_scheduler_tick_writer_preflight_blockers',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_writer_preflight.v1',
            'status' => (string) $preflight['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_writer_preflight',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'claim_allowed' => false,
            'dispatch_receipt_write_allowed' => false,
            'release_receipt_persistence_allowed' => false,
            'provider_start_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_writer_preflight' => $preflight,
            'agent_automatic_dispatch_scheduler_one_shot_tick_writer_preflight_hash' => $this->stableHash($preflight),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_writer_preflight_does_not_claim_wakeup_items',
                'agent_automatic_dispatch_scheduler_one_shot_tick_writer_preflight_does_not_write_dispatch_receipts',
                'agent_automatic_dispatch_scheduler_one_shot_tick_writer_preflight_does_not_persist_release_receipts',
                'agent_automatic_dispatch_scheduler_one_shot_tick_writer_preflight_does_not_start_providers',
                'agent_automatic_dispatch_scheduler_one_shot_tick_writer_preflight_does_not_enable_self_programming',
            ],
            'human_summary' => $blockingReasons === []
                ? 'Automatic dispatch scheduler one-shot tick writer preflight is ready: Atlas can evaluate candidate freshness, release hash shape and dispatch envelope integrity without mutating runtime.'
                : 'Automatic dispatch scheduler one-shot tick writer preflight is blocked until every one-shot writer prerequisite is ready.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickReleaseReceiptPersistenceWriterPreflight(array $options = []): array
    {
        $contractPayload = $this->agentAutomaticDispatchSchedulerOneShotTickReleaseReceiptPersistenceContract($options);
        $contract = (array) data_get($contractPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_release_receipt_persistence_contract', []);
        $contractHash = (string) data_get($contractPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_release_receipt_persistence_contract_hash');
        $table = 'atlas_self_construction_agent_dispatch_receipts';
        $wakeupTable = 'atlas_self_construction_agent_wakeup_items';
        $tableReady = Schema::hasTable($table);
        $wakeupTableReady = Schema::hasTable($wakeupTable);
        $requiredColumns = [
            'id',
            'wakeup_item_id',
            'receipt_key',
            'packet_id',
            'provider',
            'decision',
            'status',
            'signed_by',
            'signed_at',
            'expires_at',
            'dispatch_envelope_hash',
            'receipt_hash',
            'payload',
        ];
        $columns = array_fill_keys($requiredColumns, false);

        if ($tableReady) {
            foreach ($requiredColumns as $column) {
                $columns[$column] = Schema::hasColumn($table, $column);
            }
        }

        $databaseDriver = null;
        $receiptIndexes = [];
        $wakeupIndexes = [];
        $wakeupLockColumnsReady = false;

        try {
            $databaseDriver = DB::connection()->getDriverName();
            $receiptIndexes = $tableReady ? Schema::getIndexes($table) : [];
            $wakeupIndexes = $wakeupTableReady ? Schema::getIndexes($wakeupTable) : [];
            $wakeupLockColumnsReady = $wakeupTableReady
                && Schema::hasColumn($wakeupTable, 'id')
                && Schema::hasColumn($wakeupTable, 'wakeup_key');
        } catch (\Throwable) {
            // Schema evidence remains absent and blocks the implementation packet.
        }

        $receiptHashUniqueIndexReady = collect($receiptIndexes)->contains(
            static fn (array $index): bool => ($index['unique'] ?? false) === true
                && ($index['columns'] ?? []) === ['receipt_hash'],
        );
        $receiptKeyUniqueIndexReady = collect($receiptIndexes)->contains(
            static fn (array $index): bool => ($index['unique'] ?? false) === true
                && ($index['columns'] ?? []) === ['receipt_key'],
        );
        $wakeupPrimaryKeyReady = collect($wakeupIndexes)->contains(
            static fn (array $index): bool => ($index['primary'] ?? false) === true
                && ($index['columns'] ?? []) === ['id'],
        );
        $transactionSupported = in_array($databaseDriver, ['mysql', 'pgsql', 'sqlite', 'sqlsrv'], true);
        $wakeupRowLockSupported = in_array($databaseDriver, ['mysql', 'pgsql', 'sqlsrv'], true)
            && $wakeupTableReady
            && $wakeupLockColumnsReady
            && $wakeupPrimaryKeyReady;

        $preflightChecks = [
            'persistence_contract_available' => data_get($contractPayload, 'status') === 'agent_automatic_dispatch_scheduler_one_shot_tick_release_receipt_persistence_contract_ready',
            'persistence_contract_hash_present' => $contractHash !== '',
            'source_validation_preflight_ready' => data_get($contract, 'source_validation_preflight_status') === 'release_receipt_validation_preflight_ready',
            'dispatch_receipts_table_ready' => $tableReady,
            'dispatch_receipt_model_exists' => class_exists(AtlasSelfConstructionAgentDispatchReceipt::class),
            'required_columns_ready' => $columns !== [] && ! in_array(false, $columns, true),
            'storage_target_matches_contract' => data_get($contract, 'storage_target.table') === $table,
            'receipt_kind_defined' => (string) data_get($contract, 'storage_target.receipt_kind', '') !== '',
            'status_on_write_defined' => (string) data_get($contract, 'storage_target.status_on_write', '') !== '',
            'idempotency_key_fields_defined' => data_get($contract, 'storage_target.idempotency_key_fields') === ['receipt_hash'],
            'database_transaction_supported' => $transactionSupported,
            'wakeup_row_lock_supported' => $wakeupRowLockSupported,
            'receipt_hash_unique_index_ready' => $receiptHashUniqueIndexReady,
            'receipt_key_unique_index_ready' => $receiptKeyUniqueIndexReady,
            'provider_start_forbidden' => data_get($contract, 'contract_scope.provider_start_allowed') === false,
            'self_programming_forbidden' => data_get($contract, 'contract_scope.self_programming_allowed') === false,
        ];
        $failedChecks = array_values(array_keys(array_filter(
            $preflightChecks,
            fn (bool $passed): bool => ! $passed,
        )));
        $blockingReasons = array_values(array_unique($failedChecks));

        $preflight = [
            'status' => $blockingReasons === [] ? 'release_receipt_persistence_writer_preflight_ready' : 'blocked',
            'blocking_count' => count($blockingReasons),
            'blocking_reasons' => $blockingReasons,
            'failed_preflight_checks' => $failedChecks,
            'source_persistence_contract_hash' => $contractHash,
            'source_validation_preflight_status' => data_get($contract, 'source_validation_preflight_status'),
            'preflight_checks' => $preflightChecks,
            'storage_readiness' => [
                'table' => $table,
                'table_ready' => $tableReady,
                'model' => AtlasSelfConstructionAgentDispatchReceipt::class,
                'model_exists' => class_exists(AtlasSelfConstructionAgentDispatchReceipt::class),
                'required_columns' => $columns,
                'database_driver' => $databaseDriver,
                'transaction_supported' => $transactionSupported,
                'unique_indexes' => [
                    'receipt_hash' => $receiptHashUniqueIndexReady,
                    'receipt_key' => $receiptKeyUniqueIndexReady,
                ],
                'wakeup_row_lock' => [
                    'table' => $wakeupTable,
                    'table_ready' => $wakeupTableReady,
                    'primary_key_ready' => $wakeupPrimaryKeyReady,
                    'driver_supported' => in_array($databaseDriver, ['mysql', 'pgsql', 'sqlsrv'], true),
                    'supported' => $wakeupRowLockSupported,
                ],
            ],
            'writer_policy' => [
                'writer_allowed_here' => false,
                'signature_acceptance_allowed_here' => false,
                'release_receipt_persistence_allowed_here' => false,
                'runtime_mutation_allowed_here' => false,
                'claim_allowed_here' => false,
                'dispatch_receipt_write_allowed_here' => false,
                'provider_start_allowed_here' => false,
                'adapter_invocation_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'self_programming_allowed_here' => false,
            ],
            'implementation_requirements' => [
                'recompute_validation_preflight_inside_transaction',
                'validate_signature_before_insert',
                'validate_signature_expiry_before_insert',
                'lock_selected_wakeup_item_before_insert',
                'write_release_receipt_idempotently_by_contract_key',
                'append_evidence_event_after_successful_insert',
                'return_existing_receipt_on_idempotent_replay',
                'never_claim_wakeup_in_release_receipt_writer',
                'never_write_dispatch_receipt_in_release_receipt_writer',
                'never_start_provider_in_release_receipt_writer',
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
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_release_receipt_persistence_writer_implementation_packet',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_release_receipt_persistence_writer_preflight.v1',
            'status' => (string) $preflight['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_release_receipt_persistence_writer_preflight',
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
            'agent_automatic_dispatch_scheduler_one_shot_tick_release_receipt_persistence_writer_preflight' => $preflight,
            'agent_automatic_dispatch_scheduler_one_shot_tick_release_receipt_persistence_writer_preflight_hash' => $this->stableHash($preflight),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_release_receipt_persistence_writer_preflight_does_not_accept_signatures',
                'agent_automatic_dispatch_scheduler_one_shot_tick_release_receipt_persistence_writer_preflight_does_not_persist_release_receipts',
                'agent_automatic_dispatch_scheduler_one_shot_tick_release_receipt_persistence_writer_preflight_does_not_claim_wakeup_items',
                'agent_automatic_dispatch_scheduler_one_shot_tick_release_receipt_persistence_writer_preflight_does_not_write_dispatch_receipts',
                'agent_automatic_dispatch_scheduler_one_shot_tick_release_receipt_persistence_writer_preflight_does_not_start_providers',
                'agent_automatic_dispatch_scheduler_one_shot_tick_release_receipt_persistence_writer_preflight_does_not_enable_self_programming',
            ],
            'human_summary' => $blockingReasons === []
                ? 'Automatic dispatch scheduler one-shot tick release receipt persistence writer preflight is ready; implementation packet can be generated without mutating runtime.'
                : 'Automatic dispatch scheduler one-shot tick release receipt persistence writer preflight is blocked for runtime use, but it exposes the implementation requirements without mutating runtime.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickReleaseReceiptDraft(array $options = []): array
    {
        $templatePayload = $this->agentAutomaticDispatchSchedulerOneShotTickReleaseTemplate($options);
        $template = (array) data_get($templatePayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_release_template', []);
        $releaseScope = (array) data_get($template, 'release_scope', []);
        $templateHash = (string) data_get($templatePayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_release_template_hash');
        $templateReady = data_get($templatePayload, 'status') === 'agent_automatic_dispatch_scheduler_one_shot_tick_release_template_ready';
        $templateBlockingReasons = (array) data_get($template, 'blocking_reasons', []);

        $receiptDraft = [
            'status' => $templateReady ? 'unsigned_release_receipt_draft_ready' : 'blocked',
            'release_receipt_draft_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-RELEASE-RECEIPT-DRAFT-SELF-CONSTRUCTION-0001',
            'parent_program' => 'Atlas Self-Construction OS',
            'submodule' => 'Atlas Agent Control Plane',
            'source_release_template_hash' => $templateHash,
            'source_preflight_status' => data_get($template, 'source_preflight_status'),
            'blocking_reasons' => $templateBlockingReasons,
            'receipt_decision' => [
                'decision' => 'approve_scheduler_claim_and_receipt_once',
                'scope_hash' => $this->stableHash($releaseScope),
                'max_wakeup_items' => 1,
                'max_dispatch_receipts' => 1,
                'provider_start_allowed' => false,
                'receipt_use_allowed' => false,
                'adapter_invocation_allowed' => false,
                'token_spend_allowed' => false,
            ],
            'receipt_subject' => [
                'selected_wakeup_key' => data_get($releaseScope, 'selected_wakeup_key'),
                'packet_id' => data_get($releaseScope, 'packet_id'),
                'provider' => data_get($releaseScope, 'provider'),
                'dispatch_envelope_hash' => data_get($releaseScope, 'dispatch_envelope_hash'),
            ],
            'required_signature_fields' => [
                'release_receipt_draft_id',
                'decision',
                'signed_by',
                'signed_at',
                'expires_at',
                'source_release_template_hash',
                'source_preflight_hash',
                'source_contract_hash',
                'source_dry_run_tick_hash',
                'selected_wakeup_key',
                'dispatch_envelope_hash',
                'scope_hash',
                'operator_reason',
            ],
            'required_validation_before_persistence' => [
                'signature_present_and_fresh',
                'source_release_template_hash_matches_current_projection',
                'source_preflight_hash_matches_current_projection',
                'selected_wakeup_key_present',
                'dispatch_envelope_hash_present',
                'scope_hash_matches_release_scope',
                'decision_equals_approve_scheduler_claim_and_receipt_once',
                'max_wakeup_items_equals_one',
                'max_dispatch_receipts_equals_one',
                'provider_start_remains_forbidden',
            ],
            'unsigned_receipt_payload' => [
                'release_receipt_draft_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-RELEASE-RECEIPT-DRAFT-SELF-CONSTRUCTION-0001',
                'decision' => 'approve_scheduler_claim_and_receipt_once',
                'signed_by' => null,
                'signed_at' => null,
                'expires_at' => null,
                'source_release_template_hash' => $templateHash,
                'source_preflight_hash' => data_get($template, 'source_preflight_hash'),
                'source_contract_hash' => data_get($template, 'source_contract_hash'),
                'source_dry_run_tick_hash' => data_get($template, 'source_dry_run_tick_hash'),
                'selected_wakeup_key' => data_get($releaseScope, 'selected_wakeup_key'),
                'dispatch_envelope_hash' => data_get($releaseScope, 'dispatch_envelope_hash'),
                'scope_hash' => $this->stableHash($releaseScope),
                'operator_reason' => null,
            ],
            'draft_policy' => [
                'draft_is_unsigned' => true,
                'draft_is_not_persisted' => true,
                'signature_acceptance_allowed_here' => false,
                'release_receipt_persistence_allowed_here' => false,
                'runtime_mutation_allowed_here' => false,
                'claim_allowed_here' => false,
                'dispatch_receipt_write_allowed_here' => false,
                'provider_start_allowed_here' => false,
                'self_programming_allowed_here' => false,
            ],
            'denial_conditions' => [
                'missing_future_signature',
                'expired_future_signature',
                'preflight_blocked',
                'missing_selected_wakeup_key',
                'missing_dispatch_envelope_hash',
                'scope_hash_mismatch',
                'attempt_to_start_provider',
                'attempt_to_enable_self_programming',
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
            'next_required_slice' => $templateReady
                ? 'activate_signed_one_shot_scheduler_tick_release_receipt_validation_preflight'
                : 'repair_signed_one_shot_scheduler_tick_release_receipt_draft_preflight_blockers',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_release_receipt_draft.v1',
            'status' => (string) $receiptDraft['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_release_receipt_draft',
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
            'agent_automatic_dispatch_scheduler_one_shot_tick_release_receipt_draft' => $receiptDraft,
            'agent_automatic_dispatch_scheduler_one_shot_tick_release_receipt_draft_hash' => $this->stableHash($receiptDraft),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_release_receipt_draft_does_not_accept_signatures',
                'agent_automatic_dispatch_scheduler_one_shot_tick_release_receipt_draft_does_not_persist_release_receipts',
                'agent_automatic_dispatch_scheduler_one_shot_tick_release_receipt_draft_does_not_claim_wakeup_items',
                'agent_automatic_dispatch_scheduler_one_shot_tick_release_receipt_draft_does_not_write_dispatch_receipts',
                'agent_automatic_dispatch_scheduler_one_shot_tick_release_receipt_draft_does_not_start_providers',
                'agent_automatic_dispatch_scheduler_one_shot_tick_release_receipt_draft_does_not_enable_self_programming',
            ],
            'human_summary' => $templateReady
                ? 'Automatic dispatch scheduler one-shot tick release receipt draft is ready as an unsigned, non-persisted receipt draft; it does not accept signatures, mutate runtime or start providers.'
                : 'Automatic dispatch scheduler one-shot tick release receipt draft is blocked until the source release template preflight is ready.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickReleaseTemplate(array $options = []): array
    {
        $preflightPayload = $this->agentAutomaticDispatchSchedulerOneShotTickWriterPreflight($options);
        $preflight = (array) data_get($preflightPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_writer_preflight', []);
        $selectedWakeupKey = (string) data_get($preflight, 'selected_candidate.selected_wakeup_key', '');
        $packetId = (string) data_get($preflight, 'selected_candidate.packet_id', '');
        $provider = (string) data_get($preflight, 'selected_candidate.provider', '');
        $dispatchEnvelopeHash = (string) data_get($preflight, 'dispatch_envelope.dispatch_envelope_hash', '');
        $sourcePreflightHash = (string) data_get($preflightPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_writer_preflight_hash');
        $preflightReady = data_get($preflightPayload, 'status') === 'agent_automatic_dispatch_scheduler_one_shot_tick_writer_preflight_ready';
        $preflightBlockingReasons = (array) data_get($preflight, 'blocking_reasons', []);

        $requiredEvidence = [
            'one_shot_tick_writer_contract_hash',
            'one_shot_tick_writer_preflight_hash',
            'dry_run_tick_hash',
            'selected_wakeup_key',
            'dispatch_envelope_hash',
            'candidate_freshness_report',
            'operator_reason',
        ];

        $template = [
            'status' => $preflightReady ? 'unsigned_release_template_ready' : 'blocked',
            'release_template_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-RELEASE-TEMPLATE-SELF-CONSTRUCTION-0001',
            'parent_program' => 'Atlas Self-Construction OS',
            'submodule' => 'Atlas Agent Control Plane',
            'source_preflight_status' => data_get($preflightPayload, 'status'),
            'source_preflight_hash' => $sourcePreflightHash,
            'blocking_reasons' => $preflightBlockingReasons,
            'source_contract_hash' => data_get($preflight, 'source_contract_hash'),
            'source_dry_run_tick_hash' => data_get($preflight, 'source_dry_run_tick_hash'),
            'release_scope' => [
                'decision' => 'approve_scheduler_claim_and_receipt_once',
                'selected_wakeup_key' => $selectedWakeupKey === '' ? null : $selectedWakeupKey,
                'packet_id' => $packetId === '' ? null : $packetId,
                'provider' => $provider === '' ? null : $provider,
                'dispatch_envelope_hash' => $dispatchEnvelopeHash === '' ? null : $dispatchEnvelopeHash,
                'max_wakeup_items' => 1,
                'max_dispatch_receipts' => 1,
                'provider_start_allowed' => false,
                'receipt_use_allowed' => false,
                'adapter_invocation_allowed' => false,
            ],
            'required_signed_fields' => [
                'decision',
                'signed_by',
                'signed_at',
                'expires_at',
                'source_preflight_hash',
                'source_contract_hash',
                'source_dry_run_tick_hash',
                'selected_wakeup_key',
                'dispatch_envelope_hash',
                'max_wakeup_items',
                'max_dispatch_receipts',
                'operator_reason',
            ],
            'required_evidence' => $requiredEvidence,
            'unsigned_payload_template' => [
                'decision' => null,
                'signed_by' => null,
                'signed_at' => null,
                'expires_at' => null,
                'source_preflight_hash' => $sourcePreflightHash,
                'source_contract_hash' => data_get($preflight, 'source_contract_hash'),
                'source_dry_run_tick_hash' => data_get($preflight, 'source_dry_run_tick_hash'),
                'selected_wakeup_key' => $selectedWakeupKey === '' ? null : $selectedWakeupKey,
                'dispatch_envelope_hash' => $dispatchEnvelopeHash === '' ? null : $dispatchEnvelopeHash,
                'max_wakeup_items' => 1,
                'max_dispatch_receipts' => 1,
                'operator_reason' => null,
            ],
            'template_policy' => [
                'template_is_unsigned' => true,
                'template_is_not_persisted' => true,
                'signature_acceptance_allowed_here' => false,
                'release_receipt_persistence_allowed_here' => false,
                'runtime_mutation_allowed_here' => false,
                'claim_allowed_here' => false,
                'dispatch_receipt_write_allowed_here' => false,
                'provider_start_allowed_here' => false,
            ],
            'denial_conditions' => [
                'preflight_blocked',
                'missing_selected_wakeup_key',
                'missing_dispatch_envelope_hash',
                'missing_source_preflight_hash',
                'expired_or_missing_future_signature',
                'decision_not_approve_scheduler_claim_and_receipt_once',
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
            'next_required_slice' => $preflightReady
                ? 'activate_signed_one_shot_scheduler_tick_release_receipt_draft'
                : 'repair_signed_one_shot_scheduler_tick_release_template_preflight_blockers',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_release_template.v1',
            'status' => (string) $template['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_release_template',
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
            'agent_automatic_dispatch_scheduler_one_shot_tick_release_template' => $template,
            'agent_automatic_dispatch_scheduler_one_shot_tick_release_template_hash' => $this->stableHash($template),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_release_template_does_not_accept_signatures',
                'agent_automatic_dispatch_scheduler_one_shot_tick_release_template_does_not_persist_release_receipts',
                'agent_automatic_dispatch_scheduler_one_shot_tick_release_template_does_not_claim_wakeup_items',
                'agent_automatic_dispatch_scheduler_one_shot_tick_release_template_does_not_write_dispatch_receipts',
                'agent_automatic_dispatch_scheduler_one_shot_tick_release_template_does_not_start_providers',
                'agent_automatic_dispatch_scheduler_one_shot_tick_release_template_does_not_enable_self_programming',
            ],
            'human_summary' => $preflightReady
                ? 'Automatic dispatch scheduler one-shot tick release template is ready as an unsigned, non-persisted template; it does not accept signatures, mutate runtime or start providers.'
                : 'Automatic dispatch scheduler one-shot tick release template is blocked until the writer preflight is ready.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickReleaseReceiptPersistenceContract(array $options = []): array
    {
        $preflightPayload = $this->agentAutomaticDispatchSchedulerOneShotTickReleaseReceiptValidationPreflight($options);
        $preflight = (array) data_get($preflightPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_release_receipt_validation_preflight', []);
        $preflightHash = (string) data_get($preflightPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_release_receipt_validation_preflight_hash');
        $preflightReady = data_get($preflightPayload, 'status') === 'release_receipt_validation_preflight_ready';
        $preflightBlockingReasons = (array) data_get($preflight, 'blocking_reasons', []);

        $contract = [
            'status' => $preflightReady ? 'release_receipt_persistence_contract_ready' : 'blocked',
            'contract_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-RELEASE-RECEIPT-PERSISTENCE-CONTRACT-SELF-CONSTRUCTION-0001',
            'parent_program' => 'Atlas Self-Construction OS',
            'submodule' => 'Atlas Agent Control Plane',
            'source_validation_preflight_status' => data_get($preflightPayload, 'status'),
            'source_validation_preflight_hash' => $preflightHash,
            'blocking_reasons' => $preflightBlockingReasons,
            'contract_method' => 'persistOneShotSchedulerTickReleaseReceiptAfterValidation',
            'contract_scope' => [
                'max_release_receipts_per_tick' => 1,
                'max_wakeup_items_releasable' => 1,
                'max_dispatch_receipts_releasable' => 1,
                'allowed_receipt_decision' => 'approve_scheduler_claim_and_receipt_once',
                'provider_start_allowed' => false,
                'receipt_use_allowed' => false,
                'adapter_invocation_allowed' => false,
                'token_spend_allowed' => false,
                'self_programming_allowed' => false,
            ],
            'input_contract' => [
                'signed_release_receipt_payload',
                'source_release_template_hash',
                'source_release_receipt_draft_hash',
                'source_validation_preflight_hash',
                'selected_wakeup_key',
                'dispatch_envelope_hash',
                'scope_hash',
                'operator_reason',
            ],
            'persistence_requirements' => [
                'validate_signature_before_write',
                'validate_signature_expiry_before_write',
                'validate_source_hashes_against_current_projection',
                'validate_selected_wakeup_is_still_pending',
                'validate_dispatch_envelope_hash_matches_current_dry_run',
                'validate_scope_hash_before_write',
                'write_release_receipt_once_idempotently',
                'record_append_only_evidence_event',
                'do_not_claim_wakeup_in_this_contract',
                'do_not_write_dispatch_receipt_in_this_contract',
                'do_not_mark_dispatch_receipt_used',
                'do_not_start_provider',
            ],
            'storage_target' => [
                'table' => 'atlas_self_construction_agent_dispatch_receipts',
                'receipt_kind' => 'scheduler_one_shot_tick_release',
                'status_on_write' => 'release_authorized_pending_one_shot_tick',
                'idempotency_key_fields' => [
                    'receipt_hash',
                ],
            ],
            'lock_policy' => [
                'requires_database_transaction' => true,
                'requires_wakeup_row_lock' => true,
                'requires_dispatch_receipt_unique_key' => true,
                'requires_stale_retry_safe_exit' => true,
            ],
            'blocking_preconditions' => [
                'validation_preflight_must_be_ready',
                'signature_must_be_present_and_fresh',
                'selected_wakeup_key_must_be_present',
                'dispatch_envelope_hash_must_be_present',
                'source_hashes_must_match_current_projection',
                'scope_hash_must_match',
            ],
            'allowed_future_mutations_after_all_preconditions' => [
                'persist_one_scheduler_tick_release_receipt',
                'append_release_receipt_evidence_event',
            ],
            'forbidden_even_after_persistence' => [
                'claim_wakeup_item',
                'write_dispatch_receipt',
                'mark_dispatch_receipt_used',
                'start_provider_process',
                'invoke_provider_adapter',
                'spend_provider_tokens',
                'enable_self_programming',
            ],
            'forbidden_now' => [
                'persist_release_receipt',
                'claim_wakeup_item',
                'write_dispatch_receipt',
                'mark_dispatch_receipt_used',
                'start_provider_process',
                'invoke_provider_adapter',
                'spend_provider_tokens',
            ],
            'next_required_slice' => $preflightReady
                ? 'activate_signed_one_shot_scheduler_tick_release_receipt_persistence_writer_preflight'
                : 'repair_signed_one_shot_scheduler_tick_release_receipt_persistence_contract_preflight_blockers',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_release_receipt_persistence_contract.v1',
            'status' => (string) $contract['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_release_receipt_persistence_contract',
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
            'agent_automatic_dispatch_scheduler_one_shot_tick_release_receipt_persistence_contract' => $contract,
            'agent_automatic_dispatch_scheduler_one_shot_tick_release_receipt_persistence_contract_hash' => $this->stableHash($contract),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_release_receipt_persistence_contract_does_not_persist_release_receipts',
                'agent_automatic_dispatch_scheduler_one_shot_tick_release_receipt_persistence_contract_does_not_claim_wakeup_items',
                'agent_automatic_dispatch_scheduler_one_shot_tick_release_receipt_persistence_contract_does_not_write_dispatch_receipts',
                'agent_automatic_dispatch_scheduler_one_shot_tick_release_receipt_persistence_contract_does_not_start_providers',
                'agent_automatic_dispatch_scheduler_one_shot_tick_release_receipt_persistence_contract_does_not_enable_self_programming',
            ],
            'human_summary' => $preflightReady
                ? 'Automatic dispatch scheduler one-shot tick release receipt persistence contract is ready as a read-only contract; it defines future persistence without writing release receipts or starting providers.'
                : 'Automatic dispatch scheduler one-shot tick release receipt persistence contract is blocked until validation preflight is ready.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function codexIntegrationReport(array $options = []): array
    {
        $queuePayload = $this->packetQueue($options);
        $statusPayload = $this->codexExecutionStatus($options);
        $reservationPayload = $this->reservationStatus($options);
        $sourceQueueHash = (string) data_get($queuePayload, 'queue_hash');
        $executionStatusQueueHash = (string) data_get($statusPayload, 'monitor.source_queue_hash');
        $sourceReservationHash = (string) data_get($reservationPayload, 'ledger_hash');
        $executionStatusReservationHash = (string) data_get($statusPayload, 'monitor.source_reservation_hash');
        $queueSnapshotMatchesExecutionStatus = $sourceQueueHash !== ''
            && hash_equals($sourceQueueHash, $executionStatusQueueHash);
        $reservationSnapshotMatchesExecutionStatus = $sourceReservationHash !== ''
            && hash_equals($sourceReservationHash, $executionStatusReservationHash);
        $sourceSnapshotConsistent = $queueSnapshotMatchesExecutionStatus
            && $reservationSnapshotMatchesExecutionStatus;
        $entries = (array) data_get($queuePayload, 'queue.entries', []);

        $completed = array_values(array_filter($entries, fn (array $entry): bool => data_get($entry, 'queue_state') === 'completed'));
        $claimed = array_values(array_filter($entries, fn (array $entry): bool => data_get($entry, 'queue_state') === 'claimed'));
        $available = array_values(array_filter($entries, fn (array $entry): bool => data_get($entry, 'queue_state') === 'available'));
        $blocked = array_values(array_filter($entries, fn (array $entry): bool => data_get($entry, 'queue_state') === 'blocked_by_dependency'));
        $withheld = array_values(array_filter($entries, fn (array $entry): bool => data_get($entry, 'queue_state') === 'withheld'));
        $completedReservations = collect((array) data_get($reservationPayload, 'ledger.completed_reservations', []))
            ->keyBy('packet_id');

        $completedReviewEntries = array_map(function (array $entry) use ($completedReservations): array {
            $packetId = (string) data_get($entry, 'packet_id');
            $reservation = (array) $completedReservations->get($packetId, []);
            $evidenceHash = data_get($reservation, 'completion_evidence_hash');
            $reservationMatchesPacket = $packetId !== '' && $reservation !== [];
            $evidenceHashValid = is_string($evidenceHash)
                && preg_match('/^[a-f0-9]{64}$/', $evidenceHash) === 1;

            return [
                'packet_id' => $packetId,
                'lane' => data_get($entry, 'lane'),
                'objective' => data_get($entry, 'objective'),
                'completed_at' => data_get($entry, 'completed_at'),
                'actor' => data_get($entry, 'completion_actor'),
                'reservation_id' => data_get($entry, 'completed_reservation_id'),
                'evidence_hash' => $evidenceHash,
                'evidence_status' => ! $reservationMatchesPacket
                    ? 'completion_reservation_missing_or_mismatched'
                    : ($evidenceHashValid ? 'completion_evidence_hash_valid' : 'completion_evidence_hash_invalid'),
                'allowed_files' => (array) data_get($entry, 'allowed_files', []),
                'review_expectations' => [
                    'inspect_diff_for_allowed_files_only',
                    'verify_reported_gates_against_final_response_contract',
                    'run_scope_validator_for_packet_if_files_changed',
                    'do_not_merge_or_approve_from_completion_state_alone',
                ],
            ];
        }, $completed);
        $readyToReview = array_values(array_filter(
            $completedReviewEntries,
            fn (array $entry): bool => data_get($entry, 'evidence_status') === 'completion_evidence_hash_valid',
        ));
        $completedWithInvalidEvidence = array_values(array_filter(
            $completedReviewEntries,
            fn (array $entry): bool => data_get($entry, 'evidence_status') !== 'completion_evidence_hash_valid',
        ));

        $missingPackets = array_merge(array_map(fn (array $entry): array => [
            'packet_id' => data_get($entry, 'packet_id'),
            'lane' => data_get($entry, 'lane'),
            'queue_state' => data_get($entry, 'queue_state'),
            'next_action' => match ((string) data_get($entry, 'queue_state')) {
                'claimed' => 'wait_for_owner_to_complete_or_release',
                'available' => 'claim_with_codex_start_packet',
                'blocked_by_dependency' => 'complete_dependencies_first',
                default => 'review_state',
            },
        ], array_values(array_filter(
            array_merge($claimed, $available, $blocked),
            fn (array $entry): bool => in_array(data_get($entry, 'queue_state'), ['claimed', 'available', 'blocked_by_dependency'], true)
        ))), array_map(fn (array $entry): array => [
            'packet_id' => data_get($entry, 'packet_id'),
            'lane' => data_get($entry, 'lane'),
            'queue_state' => 'completed',
            'evidence_status' => data_get($entry, 'evidence_status'),
            'next_action' => 'repair_completed_packet_evidence_before_review',
        ], $completedWithInvalidEvidence));

        $integrationStatus = match (true) {
            $claimed !== [] => 'waiting_for_active_sessions',
            $completedWithInvalidEvidence !== [] => 'completed_evidence_requires_repair',
            $completed !== [] && ($available !== [] || $blocked !== []) => 'partial_completion_review_available',
            $completed !== [] => 'ready_for_human_integration_review',
            default => 'nothing_completed_yet',
        };
        if (! $sourceSnapshotConsistent) {
            $integrationStatus = 'source_snapshot_changed';
            $readyToReview = [];
        }

        $report = [
            'report_id' => 'CODEX-INTEGRATION-REPORT-SELF-CONSTRUCTION-0001',
            'source_queue_hash' => $sourceQueueHash,
            'source_execution_status_hash' => data_get($statusPayload, 'monitor_hash'),
            'source_reservation_hash' => $sourceReservationHash,
            'snapshot_consistency' => [
                'status' => $sourceSnapshotConsistent ? 'consistent' : 'source_snapshot_changed',
                'actionable' => $sourceSnapshotConsistent,
                'queue_hash_matches_execution_status' => $queueSnapshotMatchesExecutionStatus,
                'reservation_hash_matches_execution_status' => $reservationSnapshotMatchesExecutionStatus,
            ],
            'integration_status' => $integrationStatus,
            'counts' => [
                'ready_to_review' => count($readyToReview),
                'active_sessions' => count($claimed),
                'missing_packets' => count($missingPackets),
                'completed_evidence_requiring_repair' => count($completedWithInvalidEvidence),
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
                'refresh_codex_execution_status',
                'review_each_completed_session_final_response_contract',
                'verify_each_completed_packet_evidence_hash',
                'run_packet_scope_validator_for_relevant_completed_packets',
                'run_focused_self_construction_tests',
                'run_docs_health_and_architecture_validate',
                'prepare_human_summary_before_any_merge_or_approval',
            ],
            'operator_commands' => [
                'refresh_status' => 'php artisan atlas:ai:self-construction --codex-execution-status --json',
                'integration_report' => 'php artisan atlas:ai:self-construction --codex-integration-report --json',
                'packet_queue' => 'php artisan atlas:ai:self-construction --packet-queue --json',
                'scope_validator_template' => 'php artisan atlas:ai:self-construction --scope-validator --packet=<packet-id> --json',
                'focused_tests' => 'php artisan test tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php',
                'docs_health' => 'php artisan atlas:engineering:knowledge docs-health --json',
                'architecture_validate' => 'php artisan atlas:ai:architecture-validate --json',
                'diff_check' => 'git diff --check',
            ],
            'approval_boundaries' => [
                'packet_completion_is_not_code_approval',
                'integration_report_is_not_merge_authority',
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
            'schema_version' => 'atlas.self_construction_codex_integration_report.v1',
            'status' => $sourceSnapshotConsistent
                ? 'codex_integration_report_ready'
                : 'codex_integration_report_snapshot_changed',
            'mode' => 'read_only_codex_integration_report',
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
                'codex_integration_report_does_not_claim_packets',
                'codex_integration_report_does_not_complete_packets',
                'codex_integration_report_does_not_approve_code',
                'codex_integration_report_does_not_auto_merge',
                'codex_integration_report_does_not_dispatch_work',
            ],
            'human_summary' => $sourceSnapshotConsistent
                ? 'Codex integration report is ready: completed packet evidence is consolidated for human review without approving code, merging or dispatching work.'
                : 'Codex integration report is not actionable because its queue or reservation source changed while the report was being composed; refresh the report before review.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null}  $options
     * @return array<string, mixed>
     */
    public function workSplitter(array $options = []): array
    {
        $basePacket = $this->implementationPacket($options);
        $forbidden = $this->hotForbiddenFiles();

        $packets = [
            [
                'packet_id' => 'AIP-SPLIT-SELF-CONSTRUCTION-DOCS-0001',
                'lane' => 'docs',
                'objective' => 'Keep the Self-Construction root contract and structural governance docs synchronized.',
                'allowed_files' => [
                    'docs/engineering-knowledge-base/atlas-ai-self-construction-os.md',
                    'docs/engineering-knowledge-base/self-construction/constitution.md',
                    'docs/engineering-knowledge-base/self-construction/structural-contract-gate.md',
                    'docs/ap/AP-691-atlas-self-construction-os-contract.md',
                ],
                'forbidden_files' => $forbidden,
                'depends_on' => [],
                'collision_risk' => 'low',
                'claim_policy' => 'single_owner',
                'status' => 'available',
            ],
            [
                'packet_id' => 'AIP-SPLIT-SELF-CONSTRUCTION-PACKET-CONTRACTS-0002',
                'lane' => 'packet_contracts',
                'objective' => 'Maintain packet, splitter, validator, runbook and completion contracts for AI sessions.',
                'allowed_files' => [
                    'docs/engineering-knowledge-base/self-construction/ai-implementation-packet-contract.md',
                    'docs/engineering-knowledge-base/self-construction/work-splitter-contract.md',
                    'docs/engineering-knowledge-base/self-construction/scope-validator-contract.md',
                    'docs/engineering-knowledge-base/self-construction/assignment-and-claim-contract.md',
                    'docs/engineering-knowledge-base/self-construction/packet-consumption-runbook-contract.md',
                    'docs/engineering-knowledge-base/self-construction/packet-evidence-report-contract.md',
                    'docs/engineering-knowledge-base/self-construction/packet-completion-gate-contract.md',
                ],
                'forbidden_files' => $forbidden,
                'depends_on' => [],
                'collision_risk' => 'low',
                'claim_policy' => 'single_owner',
                'status' => 'available',
            ],
            [
                'packet_id' => 'AIP-SPLIT-SELF-CONSTRUCTION-COMMAND-0003',
                'lane' => 'command_surface',
                'objective' => 'Expose and preserve the read-only Self-Construction CLI command surface.',
                'allowed_files' => [
                    'app/Console/Commands/AtlasAiSelfConstructionCommand.php',
                ],
                'forbidden_files' => $forbidden,
                'depends_on' => [],
                'collision_risk' => 'low',
                'claim_policy' => 'single_owner',
                'status' => 'available',
            ],
            [
                'packet_id' => 'AIP-SPLIT-SELF-CONSTRUCTION-SERVICE-0004',
                'lane' => 'readiness_service',
                'objective' => 'Implement read-only packet orchestration, scoped validation and readiness payloads.',
                'allowed_files' => [
                    'app/Services/Ai/SelfConstruction/Readiness/AtlasSelfConstructionReadinessService.php',
                ],
                'forbidden_files' => $forbidden,
                'depends_on' => [],
                'collision_risk' => 'low',
                'claim_policy' => 'single_owner',
                'status' => 'available',
            ],
            [
                'packet_id' => 'AIP-SPLIT-SELF-CONSTRUCTION-EVIDENCE-0005',
                'lane' => 'tests',
                'objective' => 'Add focused evidence assertions for packet, split and validator read-only invariants.',
                'allowed_files' => [
                    'tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php',
                ],
                'forbidden_files' => $forbidden,
                'depends_on' => [],
                'collision_risk' => 'low',
                'claim_policy' => 'single_owner',
                'status' => 'available',
            ],
        ];

        $withheld = [
            [
                'id' => 'voice_runtime_packet_withheld',
                'reason' => 'Voice runtime is hot external work and must not be assigned by Self-Construction Work Splitter.',
                'forbidden_scope' => 'runtimes/python/voice_realtime/**',
            ],
            [
                'id' => 'kernel_scanner_packet_withheld',
                'reason' => 'Kernel scanner ownership is outside this cold lane.',
                'forbidden_scope' => 'app/Services/Ai/Kernel/Architecture/KernelArchitectureStaticScanner.php',
            ],
        ];

        $split = [
            'split_id' => 'SPLIT-SELF-CONSTRUCTION-READ-ONLY-0001',
            'max_packets' => 5,
            'packet_count' => count($packets),
            'packets' => $packets,
            'withheld_work' => $withheld,
            'source_packet_hash' => data_get($basePacket, 'packet_hash'),
            'required_validator' => 'php artisan atlas:ai:self-construction --scope-validator --json',
        ];

        return [
            'schema_version' => 'atlas.self_construction_work_splitter.v1',
            'status' => 'split_ready',
            'mode' => 'read_only_work_splitter',
            'execution_allowed' => false,
            'packet_count' => count($packets),
            'withheld_count' => count($withheld),
            'split' => $split,
            'split_hash' => $this->stableHash($split),
            'non_execution_guarantees' => [
                'work_splitter_does_not_claim_packets',
                'work_splitter_does_not_apply_patch',
                'work_splitter_does_not_edit_hot_files',
                'work_splitter_does_not_enable_execution',
            ],
            'human_summary' => 'Work Splitter emits disjoint read-only packets and withholds hot Voice/Kernel work from parallel assignment.',
        ];
    }

}
