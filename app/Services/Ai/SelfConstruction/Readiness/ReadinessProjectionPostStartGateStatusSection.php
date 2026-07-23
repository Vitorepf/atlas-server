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
 * SC-01 fatia ReadinessProjectionPostStartGateStatusSection (Obra 4 Residual Elite).
 */
final class ReadinessProjectionPostStartGateStatusSection
{
    private ?AtlasSelfConstructionReadinessService $mother = null;

    public function setMother(AtlasSelfConstructionReadinessService $mother): self
    {
        $this->mother = $mother;

        return $this;
    }

    public function __call(string $name, array $arguments): mixed
    {
        throw new \BadMethodCallException('ReadinessProjectionPostStartGateStatusSection does not expose '.$name);
    }

    /** @param array<string, mixed> $payload */
    private function stableHash(array $payload): string
    {
        return ReadinessHash::stable($payload);
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<AtlasSelfConstructionAgentRun>  $query
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return \Illuminate\Database\Eloquent\Builder<AtlasSelfConstructionAgentRun>
     */
    private function scopePostStartRunsQuery(\Illuminate\Database\Eloquent\Builder $query, array $options): \Illuminate\Database\Eloquent\Builder
    {
        $columnScopes = [
            'workspace' => 'workspace_id',
            'actor' => 'actor',
            'session' => 'session_id',
            'packet' => 'packet_id',
            'receipt_hash' => 'completion_evidence_hash',
        ];

        foreach ($columnScopes as $option => $column) {
            $value = trim((string) ($options[$option] ?? ''));
            if ($value !== '') {
                $query->where($column, $value);
            }
        }

        $target = trim((string) ($options['target'] ?? ''));
        if ($target !== '') {
            $query->where('metadata->target', $target);
        }

        return $query;
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    private function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartStartExecutionGateContract(array $options = []): array
    {
        if ($this->mother === null) {
            throw new \RuntimeException('ReadinessProjectionPostStartGateStatusSection mother not bound for post-start start-execution contract');
        }

        return $this->mother->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartStartExecutionGateContract($options);
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartExecutorPlanGateStatus(array $options = []): array
    {
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerTableReady = Schema::hasTable('atlas_ledger_events');
        $gateReady = class_exists(AgentCodexRealInvokerPostStartExecutorPlanGate::class)
            && method_exists(AgentCodexRealInvokerPostStartExecutorPlanGate::class, 'preparePostStartExecutorPlan');
        $invokerReady = class_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartExecutorPlanGateInvoker::class)
            && method_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartExecutorPlanGateInvoker::class, 'prepareCodexRealInvokerPostStartExecutorPlanGate');
        $executorPlanReady = class_exists(AgentCodexRealInvokerExecutorPlan::class)
            && method_exists(AgentCodexRealInvokerExecutorPlan::class, 'prepareExecutorPlan');
        $implementationBoundaryReady = class_exists(AgentCodexRealInvokerPostStartImplementationBoundaryGate::class)
            && method_exists(AgentCodexRealInvokerPostStartImplementationBoundaryGate::class, 'preparePostStartImplementationBoundary');

        $observedPlanRunsQuery = $runsTableReady
            ? $this->scopePostStartRunsQuery(
                AtlasSelfConstructionAgentRun::query()
                    ->whereNotNull('metadata->codex_real_invoker_post_start_executor_plan->real_invoker_executor_plan_id'),
                $options
            )
            : null;
        $providerRunsWithPlanQuery = $runsTableReady
            ? $this->scopePostStartRunsQuery(
                AtlasSelfConstructionAgentRun::query()
                    ->where('run_key', 'like', 'provider-start:%')
                    ->where('status', 'adapter_invocation_prepared')
                    ->whereNotNull('metadata->codex_real_invoker_executor_plan->real_invoker_executor_plan_id'),
                $options
            )
            : null;
        $latestObservedPlan = $observedPlanRunsQuery === null
            ? null
            : (clone $observedPlanRunsQuery)->latest('updated_at')->first();

        $statusReady = $runsTableReady
            && $ledgerTableReady
            && $gateReady
            && $invokerReady
            && $executorPlanReady
            && $implementationBoundaryReady;
        $status = [
            'status' => $statusReady ? 'one_shot_tick_codex_real_invoker_post_start_executor_plan_gate_service_ready' : 'blocked',
            'provider' => 'codex',
            'adapter' => 'codex',
            'invoker_service' => AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartExecutorPlanGateInvoker::class,
            'invoker_service_ready' => $invokerReady,
            'invoker_canonical_method' => 'prepareCodexRealInvokerPostStartExecutorPlanGate',
            'generic_post_start_executor_plan_gate_service' => AgentCodexRealInvokerPostStartExecutorPlanGate::class,
            'generic_post_start_executor_plan_gate_service_ready' => $gateReady,
            'generic_post_start_executor_plan_gate_canonical_method' => 'preparePostStartExecutorPlan',
            'codex_real_invoker_executor_plan_service' => AgentCodexRealInvokerExecutorPlan::class,
            'codex_real_invoker_executor_plan_ready' => $executorPlanReady,
            'codex_real_invoker_executor_plan_canonical_method' => 'prepareExecutorPlan',
            'post_start_implementation_boundary_gate_service' => AgentCodexRealInvokerPostStartImplementationBoundaryGate::class,
            'post_start_implementation_boundary_gate_ready' => $implementationBoundaryReady,
            'agent_runs_table_ready' => $runsTableReady,
            'ledger_table_ready' => $ledgerTableReady,
            'codex_real_invoker_post_start_executor_plan_recorded_run_count' => $observedPlanRunsQuery === null ? null : (clone $observedPlanRunsQuery)->count(),
            'provider_start_runs_with_codex_real_invoker_executor_plan_count' => $providerRunsWithPlanQuery === null ? null : (clone $providerRunsWithPlanQuery)->count(),
            'latest_codex_real_invoker_post_start_executor_plan' => $latestObservedPlan instanceof AtlasSelfConstructionAgentRun
                ? [
                    'agent_run_id' => $latestObservedPlan->id,
                    'run_key' => $latestObservedPlan->run_key,
                    'packet_id' => $latestObservedPlan->packet_id,
                    'provider' => $latestObservedPlan->provider,
                    'status' => $latestObservedPlan->status,
                    'post_start_executor_plan_gate_id' => data_get($latestObservedPlan->metadata, 'codex_real_invoker_post_start_executor_plan.post_start_executor_plan_gate_id'),
                    'post_start_evidence_acceptance_bridge_id' => data_get($latestObservedPlan->metadata, 'codex_real_invoker_post_start_executor_plan.post_start_evidence_acceptance_bridge_id'),
                    'post_start_implementation_boundary_gate_id' => data_get($latestObservedPlan->metadata, 'codex_real_invoker_post_start_executor_plan.post_start_implementation_boundary_gate_id'),
                    'real_invoker_implementation_boundary_id' => data_get($latestObservedPlan->metadata, 'codex_real_invoker_post_start_executor_plan.real_invoker_implementation_boundary_id'),
                    'real_invoker_executor_plan_id' => data_get($latestObservedPlan->metadata, 'codex_real_invoker_post_start_executor_plan.real_invoker_executor_plan_id'),
                    'signed_real_invoker_release_id' => data_get($latestObservedPlan->metadata, 'codex_real_invoker_post_start_executor_plan.signed_real_invoker_release_id'),
                    'real_invoker_release_preflight_id' => data_get($latestObservedPlan->metadata, 'codex_real_invoker_post_start_executor_plan.real_invoker_release_preflight_id'),
                    'dry_run_id' => data_get($latestObservedPlan->metadata, 'codex_real_invoker_post_start_executor_plan.dry_run_id'),
                    'codex_execution_id' => data_get($latestObservedPlan->metadata, 'codex_real_invoker_post_start_executor_plan.codex_execution_id'),
                    'real_invoker_executor_plan_prepared' => data_get($latestObservedPlan->metadata, 'codex_real_invoker_post_start_executor_plan.real_invoker_executor_plan_prepared'),
                    'executor_fresh_release_required' => data_get($latestObservedPlan->metadata, 'codex_real_invoker_post_start_executor_plan.executor_fresh_release_required'),
                    'executor_enabled' => data_get($latestObservedPlan->metadata, 'codex_real_invoker_post_start_executor_plan.executor_enabled'),
                    'actual_process_start_allowed' => data_get($latestObservedPlan->metadata, 'codex_real_invoker_post_start_executor_plan.actual_process_start_allowed'),
                    'token_spend_allowed' => data_get($latestObservedPlan->metadata, 'codex_real_invoker_post_start_executor_plan.token_spend_allowed'),
                ]
                : null,
            'runtime_policy' => [
                'status_projection_is_read_only' => true,
                'invoker_may_prepare_executor_plan_when_called_with_boundary_input' => true,
                'executor_plan_is_not_executor_enablement' => true,
                'executor_fresh_release_required_before_execution' => true,
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
                ? 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_executor_fresh_release_gate_contract'
                : 'repair_one_shot_scheduler_tick_codex_real_invoker_post_start_executor_plan_gate_service_blockers',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_plan_gate_status.v1',
            'status' => (string) $status['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_plan_gate_status',
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
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_plan_gate_status' => $status,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_plan_gate_status_hash' => $this->stableHash($status),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_plan_gate_status_does_not_call_executor_plan_gate',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_plan_gate_status_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_plan_gate_status_does_not_enable_executor',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_plan_gate_status_does_not_execute_adapter',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_plan_gate_status_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_plan_gate_status_does_not_dispatch_work',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_plan_gate_status_does_not_enable_self_programming',
            ],
            'human_summary' => $statusReady
                ? 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start executor plan gate service is ready and inspectable; executor fresh release remains separate.'
                : 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start executor plan gate service is blocked until invoker, generic executor plan gate, boundary and storage are ready.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProcessStartEnvelopeGateStatus(array $options = []): array
    {
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerTableReady = Schema::hasTable('atlas_ledger_events');
        $gateReady = class_exists(AgentCodexRealInvokerPostStartProcessStartEnvelopeGate::class)
            && method_exists(AgentCodexRealInvokerPostStartProcessStartEnvelopeGate::class, 'buildPostStartProcessStartEnvelope');
        $invokerReady = class_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProcessStartEnvelopeGateInvoker::class)
            && method_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProcessStartEnvelopeGateInvoker::class, 'prepareCodexRealInvokerPostStartProcessStartEnvelopeGate');
        $envelopeBuilderReady = class_exists(AgentCodexRealInvokerProcessStartEnvelopeBuilder::class)
            && method_exists(AgentCodexRealInvokerProcessStartEnvelopeBuilder::class, 'buildStartEnvelope');
        $rehearsalReady = class_exists(AgentCodexRealInvokerPostStartActualProcessStartRehearsalGate::class)
            && method_exists(AgentCodexRealInvokerPostStartActualProcessStartRehearsalGate::class, 'rehearsePostStartActualProcessStart');

        $observedEnvelopeRunsQuery = $runsTableReady
            ? $this->scopePostStartRunsQuery(
                AtlasSelfConstructionAgentRun::query()
                    ->whereNotNull('metadata->codex_real_invoker_post_start_process_start_envelope->real_invoker_process_start_envelope_id'),
                $options
            )
            : null;
        $providerRunsWithEnvelopeQuery = $runsTableReady
            ? $this->scopePostStartRunsQuery(
                AtlasSelfConstructionAgentRun::query()
                    ->where('run_key', 'like', 'provider-start:%')
                    ->where('status', 'adapter_invocation_prepared')
                    ->whereNotNull('metadata->codex_real_invoker_process_start_envelope->real_invoker_process_start_envelope_id'),
                $options
            )
            : null;
        $latestObservedEnvelope = $observedEnvelopeRunsQuery === null
            ? null
            : (clone $observedEnvelopeRunsQuery)->latest('updated_at')->first();

        $statusReady = $runsTableReady
            && $ledgerTableReady
            && $gateReady
            && $invokerReady
            && $envelopeBuilderReady
            && $rehearsalReady;
        $status = [
            'status' => $statusReady ? 'one_shot_tick_codex_real_invoker_post_start_process_start_envelope_gate_service_ready' : 'blocked',
            'provider' => 'codex',
            'adapter' => 'codex',
            'invoker_service' => AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProcessStartEnvelopeGateInvoker::class,
            'invoker_service_ready' => $invokerReady,
            'invoker_canonical_method' => 'prepareCodexRealInvokerPostStartProcessStartEnvelopeGate',
            'generic_post_start_process_start_envelope_gate_service' => AgentCodexRealInvokerPostStartProcessStartEnvelopeGate::class,
            'generic_post_start_process_start_envelope_gate_service_ready' => $gateReady,
            'generic_post_start_process_start_envelope_gate_canonical_method' => 'buildPostStartProcessStartEnvelope',
            'codex_real_invoker_process_start_envelope_builder_service' => AgentCodexRealInvokerProcessStartEnvelopeBuilder::class,
            'codex_real_invoker_process_start_envelope_builder_ready' => $envelopeBuilderReady,
            'codex_real_invoker_process_start_envelope_builder_canonical_method' => 'buildStartEnvelope',
            'post_start_actual_process_start_rehearsal_gate_service' => AgentCodexRealInvokerPostStartActualProcessStartRehearsalGate::class,
            'post_start_actual_process_start_rehearsal_gate_ready' => $rehearsalReady,
            'agent_runs_table_ready' => $runsTableReady,
            'ledger_table_ready' => $ledgerTableReady,
            'codex_real_invoker_post_start_process_start_envelope_recorded_run_count' => $observedEnvelopeRunsQuery === null ? null : (clone $observedEnvelopeRunsQuery)->count(),
            'provider_start_runs_with_codex_real_invoker_process_start_envelope_count' => $providerRunsWithEnvelopeQuery === null ? null : (clone $providerRunsWithEnvelopeQuery)->count(),
            'latest_codex_real_invoker_post_start_process_start_envelope' => $latestObservedEnvelope instanceof AtlasSelfConstructionAgentRun
                ? [
                    'agent_run_id' => $latestObservedEnvelope->id,
                    'run_key' => $latestObservedEnvelope->run_key,
                    'packet_id' => $latestObservedEnvelope->packet_id,
                    'provider' => $latestObservedEnvelope->provider,
                    'status' => $latestObservedEnvelope->status,
                    'post_start_process_start_envelope_gate_id' => data_get($latestObservedEnvelope->metadata, 'codex_real_invoker_post_start_process_start_envelope.post_start_process_start_envelope_gate_id'),
                    'post_start_actual_process_start_rehearsal_gate_id' => data_get($latestObservedEnvelope->metadata, 'codex_real_invoker_post_start_process_start_envelope.post_start_actual_process_start_rehearsal_gate_id'),
                    'post_start_evidence_acceptance_bridge_id' => data_get($latestObservedEnvelope->metadata, 'codex_real_invoker_post_start_process_start_envelope.post_start_evidence_acceptance_bridge_id'),
                    'real_invoker_actual_process_start_rehearsal_id' => data_get($latestObservedEnvelope->metadata, 'codex_real_invoker_post_start_process_start_envelope.real_invoker_actual_process_start_rehearsal_id'),
                    'real_invoker_process_start_envelope_id' => data_get($latestObservedEnvelope->metadata, 'codex_real_invoker_post_start_process_start_envelope.real_invoker_process_start_envelope_id'),
                    'codex_execution_id' => data_get($latestObservedEnvelope->metadata, 'codex_real_invoker_post_start_process_start_envelope.codex_execution_id'),
                    'real_invoker_process_start_envelope_built' => data_get($latestObservedEnvelope->metadata, 'codex_real_invoker_post_start_process_start_envelope.real_invoker_process_start_envelope_built'),
                    'start_envelope_ready' => data_get($latestObservedEnvelope->metadata, 'codex_real_invoker_post_start_process_start_envelope.start_envelope_ready'),
                    'process_start_rehearsed' => data_get($latestObservedEnvelope->metadata, 'codex_real_invoker_post_start_process_start_envelope.process_start_rehearsed'),
                    'actual_process_start_allowed' => data_get($latestObservedEnvelope->metadata, 'codex_real_invoker_post_start_process_start_envelope.actual_process_start_allowed'),
                    'token_spend_allowed' => data_get($latestObservedEnvelope->metadata, 'codex_real_invoker_post_start_process_start_envelope.token_spend_allowed'),
                ]
                : null,
            'runtime_policy' => [
                'status_projection_is_read_only' => true,
                'invoker_may_prepare_codex_real_invoker_post_start_process_start_envelope_when_called_with_rehearsal_input' => true,
                'process_start_envelope_is_not_real_process_invocation' => true,
                'start_execution_gate_required_before_actual_process' => true,
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
            'next_required_slice' => $statusReady
                ? 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_start_execution_gate_contract'
                : 'repair_one_shot_scheduler_tick_codex_real_invoker_post_start_process_start_envelope_gate_service_blockers',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_start_envelope_gate_status.v1',
            'status' => (string) $status['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_start_envelope_gate_status',
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
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_start_envelope_gate_status' => $status,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_start_envelope_gate_status_hash' => $this->stableHash($status),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_start_envelope_gate_status_does_not_call_envelope_gate',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_start_envelope_gate_status_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_start_envelope_gate_status_does_not_execute_adapter',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_start_envelope_gate_status_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_start_envelope_gate_status_does_not_dispatch_work',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_start_envelope_gate_status_does_not_enable_self_programming',
            ],
            'human_summary' => $statusReady
                ? 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start process start envelope gate service is ready and inspectable; start execution gate remains separate.'
                : 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start process start envelope gate service is blocked until invoker, generic envelope gates and storage are ready.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartStartExecutionGateStatus(array $options = []): array
    {
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerTableReady = Schema::hasTable('atlas_ledger_events');
        $gateReady = class_exists(AgentCodexRealInvokerPostStartStartExecutionGate::class)
            && method_exists(AgentCodexRealInvokerPostStartStartExecutionGate::class, 'authorizePostStartStartExecution');
        $invokerReady = class_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartStartExecutionGateInvoker::class)
            && method_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartStartExecutionGateInvoker::class, 'prepareCodexRealInvokerPostStartStartExecutionGate');
        $startExecutionReady = class_exists(AgentCodexRealInvokerStartExecutionGate::class)
            && method_exists(AgentCodexRealInvokerStartExecutionGate::class, 'authorizeStartExecution');
        $envelopeReady = class_exists(AgentCodexRealInvokerPostStartProcessStartEnvelopeGate::class)
            && method_exists(AgentCodexRealInvokerPostStartProcessStartEnvelopeGate::class, 'buildPostStartProcessStartEnvelope');

        $observedStartExecutionRunsQuery = $runsTableReady
            ? $this->scopePostStartRunsQuery(
                AtlasSelfConstructionAgentRun::query()
                    ->whereNotNull('metadata->codex_real_invoker_post_start_start_execution_gate->real_invoker_start_execution_gate_id'),
                $options
            )
            : null;
        $providerRunsWithStartExecutionQuery = $runsTableReady
            ? $this->scopePostStartRunsQuery(
                AtlasSelfConstructionAgentRun::query()
                    ->where('run_key', 'like', 'provider-start:%')
                    ->where('status', 'adapter_invocation_prepared')
                    ->whereNotNull('metadata->codex_real_invoker_start_execution_gate->real_invoker_start_execution_gate_id'),
                $options
            )
            : null;
        $latestObservedStartExecution = $observedStartExecutionRunsQuery === null
            ? null
            : (clone $observedStartExecutionRunsQuery)->latest('updated_at')->first();

        $statusReady = $runsTableReady
            && $ledgerTableReady
            && $gateReady
            && $invokerReady
            && $startExecutionReady
            && $envelopeReady;
        $status = [
            'status' => $statusReady ? 'one_shot_tick_codex_real_invoker_post_start_start_execution_gate_service_ready' : 'blocked',
            'provider' => 'codex',
            'adapter' => 'codex',
            'invoker_service' => AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartStartExecutionGateInvoker::class,
            'invoker_service_ready' => $invokerReady,
            'invoker_canonical_method' => 'prepareCodexRealInvokerPostStartStartExecutionGate',
            'generic_post_start_start_execution_gate_service' => AgentCodexRealInvokerPostStartStartExecutionGate::class,
            'generic_post_start_start_execution_gate_service_ready' => $gateReady,
            'generic_post_start_start_execution_gate_canonical_method' => 'authorizePostStartStartExecution',
            'codex_real_invoker_start_execution_gate_service' => AgentCodexRealInvokerStartExecutionGate::class,
            'codex_real_invoker_start_execution_gate_ready' => $startExecutionReady,
            'codex_real_invoker_start_execution_gate_canonical_method' => 'authorizeStartExecution',
            'post_start_process_start_envelope_gate_service' => AgentCodexRealInvokerPostStartProcessStartEnvelopeGate::class,
            'post_start_process_start_envelope_gate_ready' => $envelopeReady,
            'agent_runs_table_ready' => $runsTableReady,
            'ledger_table_ready' => $ledgerTableReady,
            'codex_real_invoker_post_start_start_execution_gate_recorded_run_count' => $observedStartExecutionRunsQuery === null ? null : (clone $observedStartExecutionRunsQuery)->count(),
            'provider_start_runs_with_codex_real_invoker_start_execution_gate_count' => $providerRunsWithStartExecutionQuery === null ? null : (clone $providerRunsWithStartExecutionQuery)->count(),
            'latest_codex_real_invoker_post_start_start_execution_gate' => $latestObservedStartExecution instanceof AtlasSelfConstructionAgentRun
                ? [
                    'agent_run_id' => $latestObservedStartExecution->id,
                    'run_key' => $latestObservedStartExecution->run_key,
                    'packet_id' => $latestObservedStartExecution->packet_id,
                    'provider' => $latestObservedStartExecution->provider,
                    'status' => $latestObservedStartExecution->status,
                    'post_start_start_execution_gate_id' => data_get($latestObservedStartExecution->metadata, 'codex_real_invoker_post_start_start_execution_gate.post_start_start_execution_gate_id'),
                    'post_start_process_start_envelope_gate_id' => data_get($latestObservedStartExecution->metadata, 'codex_real_invoker_post_start_start_execution_gate.post_start_process_start_envelope_gate_id'),
                    'post_start_evidence_acceptance_bridge_id' => data_get($latestObservedStartExecution->metadata, 'codex_real_invoker_post_start_start_execution_gate.post_start_evidence_acceptance_bridge_id'),
                    'real_invoker_process_start_envelope_id' => data_get($latestObservedStartExecution->metadata, 'codex_real_invoker_post_start_start_execution_gate.real_invoker_process_start_envelope_id'),
                    'real_invoker_start_execution_gate_id' => data_get($latestObservedStartExecution->metadata, 'codex_real_invoker_post_start_start_execution_gate.real_invoker_start_execution_gate_id'),
                    'codex_execution_id' => data_get($latestObservedStartExecution->metadata, 'codex_real_invoker_post_start_start_execution_gate.codex_execution_id'),
                    'real_invoker_start_execution_gate_authorized' => data_get($latestObservedStartExecution->metadata, 'codex_real_invoker_post_start_start_execution_gate.real_invoker_start_execution_gate_authorized'),
                    'start_execution_authorized' => data_get($latestObservedStartExecution->metadata, 'codex_real_invoker_post_start_start_execution_gate.start_execution_authorized'),
                    'start_envelope_ready' => data_get($latestObservedStartExecution->metadata, 'codex_real_invoker_post_start_start_execution_gate.start_envelope_ready'),
                    'actual_process_start_allowed' => data_get($latestObservedStartExecution->metadata, 'codex_real_invoker_post_start_start_execution_gate.actual_process_start_allowed'),
                    'token_spend_allowed' => data_get($latestObservedStartExecution->metadata, 'codex_real_invoker_post_start_start_execution_gate.token_spend_allowed'),
                ]
                : null,
            'runtime_policy' => [
                'status_projection_is_read_only' => true,
                'invoker_may_prepare_codex_real_invoker_post_start_start_execution_when_called_with_envelope_input' => true,
                'start_execution_authorization_is_not_real_process_invocation' => true,
                'process_starter_readiness_required_before_actual_process' => true,
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
            'next_required_slice' => $statusReady
                ? 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_process_starter_readiness_gate_contract'
                : 'repair_one_shot_scheduler_tick_codex_real_invoker_post_start_start_execution_gate_service_blockers',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_start_execution_gate_status.v1',
            'status' => (string) $status['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_start_execution_gate_status',
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
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_start_execution_gate_status' => $status,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_start_execution_gate_status_hash' => $this->stableHash($status),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_start_execution_gate_status_does_not_call_start_execution_gate',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_start_execution_gate_status_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_start_execution_gate_status_does_not_execute_adapter',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_start_execution_gate_status_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_start_execution_gate_status_does_not_dispatch_work',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_start_execution_gate_status_does_not_enable_self_programming',
            ],
            'human_summary' => $statusReady
                ? 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start start execution gate service is ready and inspectable; process starter readiness gate remains separate.'
                : 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start start execution gate service is blocked until invoker, generic start execution gates and storage are ready.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartFinalProcessStartAuthorizationGateStatus(array $options = []): array
    {
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerTableReady = Schema::hasTable('atlas_ledger_events');
        $gateReady = class_exists(AgentCodexRealInvokerPostStartFinalProcessStartAuthorizationGate::class)
            && method_exists(AgentCodexRealInvokerPostStartFinalProcessStartAuthorizationGate::class, 'authorizePostStartFinalProcessStart');
        $invokerReady = class_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartFinalProcessStartAuthorizationGateInvoker::class)
            && method_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartFinalProcessStartAuthorizationGateInvoker::class, 'prepareCodexRealInvokerPostStartFinalProcessStartAuthorizationGate');
        $authorizationReady = class_exists(AgentCodexRealInvokerFinalProcessStartAuthorizationGate::class)
            && method_exists(AgentCodexRealInvokerFinalProcessStartAuthorizationGate::class, 'authorizeFinalStart');
        $guardedReady = class_exists(AgentCodexRealInvokerPostStartGuardedProcessStartExecutorGate::class)
            && method_exists(AgentCodexRealInvokerPostStartGuardedProcessStartExecutorGate::class, 'preparePostStartGuardedProcessStart');

        $observedFinalAuthRunsQuery = $runsTableReady
            ? $this->scopePostStartRunsQuery(
                AtlasSelfConstructionAgentRun::query()
                    ->whereNotNull('metadata->codex_real_invoker_post_start_final_process_start_authorization->real_invoker_final_process_start_authorization_id'),
                $options
            )
            : null;
        $providerRunsWithFinalAuthQuery = $runsTableReady
            ? $this->scopePostStartRunsQuery(
                AtlasSelfConstructionAgentRun::query()
                    ->where('run_key', 'like', 'provider-start:%')
                    ->where('status', 'adapter_invocation_prepared')
                    ->whereNotNull('metadata->codex_real_invoker_final_process_start_authorization->real_invoker_final_process_start_authorization_id'),
                $options
            )
            : null;
        $latestObservedFinalAuth = $observedFinalAuthRunsQuery === null
            ? null
            : (clone $observedFinalAuthRunsQuery)->latest('updated_at')->first();

        $statusReady = $runsTableReady
            && $ledgerTableReady
            && $gateReady
            && $invokerReady
            && $authorizationReady
            && $guardedReady;
        $status = [
            'status' => $statusReady ? 'one_shot_tick_codex_real_invoker_post_start_final_process_start_authorization_gate_service_ready' : 'blocked',
            'provider' => 'codex',
            'adapter' => 'codex',
            'invoker_service' => AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartFinalProcessStartAuthorizationGateInvoker::class,
            'invoker_service_ready' => $invokerReady,
            'invoker_canonical_method' => 'prepareCodexRealInvokerPostStartFinalProcessStartAuthorizationGate',
            'generic_post_start_final_process_start_authorization_gate_service' => AgentCodexRealInvokerPostStartFinalProcessStartAuthorizationGate::class,
            'generic_post_start_final_process_start_authorization_gate_service_ready' => $gateReady,
            'generic_post_start_final_process_start_authorization_gate_canonical_method' => 'authorizePostStartFinalProcessStart',
            'codex_real_invoker_final_process_start_authorization_gate_service' => AgentCodexRealInvokerFinalProcessStartAuthorizationGate::class,
            'codex_real_invoker_final_process_start_authorization_gate_ready' => $authorizationReady,
            'codex_real_invoker_final_process_start_authorization_gate_canonical_method' => 'authorizeFinalStart',
            'post_start_guarded_process_start_executor_gate_service' => AgentCodexRealInvokerPostStartGuardedProcessStartExecutorGate::class,
            'post_start_guarded_process_start_executor_gate_ready' => $guardedReady,
            'agent_runs_table_ready' => $runsTableReady,
            'ledger_table_ready' => $ledgerTableReady,
            'codex_real_invoker_post_start_final_process_start_authorization_recorded_run_count' => $observedFinalAuthRunsQuery === null ? null : (clone $observedFinalAuthRunsQuery)->count(),
            'provider_start_runs_with_codex_real_invoker_final_process_start_authorization_count' => $providerRunsWithFinalAuthQuery === null ? null : (clone $providerRunsWithFinalAuthQuery)->count(),
            'latest_codex_real_invoker_post_start_final_process_start_authorization' => $latestObservedFinalAuth instanceof AtlasSelfConstructionAgentRun
                ? [
                    'agent_run_id' => $latestObservedFinalAuth->id,
                    'run_key' => $latestObservedFinalAuth->run_key,
                    'packet_id' => $latestObservedFinalAuth->packet_id,
                    'provider' => $latestObservedFinalAuth->provider,
                    'status' => $latestObservedFinalAuth->status,
                    'post_start_final_process_start_authorization_gate_id' => data_get($latestObservedFinalAuth->metadata, 'codex_real_invoker_post_start_final_process_start_authorization.post_start_final_process_start_authorization_gate_id'),
                    'post_start_guarded_process_start_gate_id' => data_get($latestObservedFinalAuth->metadata, 'codex_real_invoker_post_start_final_process_start_authorization.post_start_guarded_process_start_gate_id'),
                    'post_start_evidence_acceptance_bridge_id' => data_get($latestObservedFinalAuth->metadata, 'codex_real_invoker_post_start_final_process_start_authorization.post_start_evidence_acceptance_bridge_id'),
                    'real_invoker_guarded_process_start_id' => data_get($latestObservedFinalAuth->metadata, 'codex_real_invoker_post_start_final_process_start_authorization.real_invoker_guarded_process_start_id'),
                    'real_invoker_final_process_start_authorization_id' => data_get($latestObservedFinalAuth->metadata, 'codex_real_invoker_post_start_final_process_start_authorization.real_invoker_final_process_start_authorization_id'),
                    'codex_execution_id' => data_get($latestObservedFinalAuth->metadata, 'codex_real_invoker_post_start_final_process_start_authorization.codex_execution_id'),
                    'final_process_start_authorized' => data_get($latestObservedFinalAuth->metadata, 'codex_real_invoker_post_start_final_process_start_authorization.final_process_start_authorized'),
                    'real_invoker_final_process_start_authorization_prepared' => data_get($latestObservedFinalAuth->metadata, 'codex_real_invoker_post_start_final_process_start_authorization.real_invoker_final_process_start_authorization_prepared'),
                    'executor_enabled' => data_get($latestObservedFinalAuth->metadata, 'codex_real_invoker_post_start_final_process_start_authorization.executor_enabled'),
                    'process_start_armed' => data_get($latestObservedFinalAuth->metadata, 'codex_real_invoker_post_start_final_process_start_authorization.process_start_armed'),
                    'actual_process_start_allowed' => data_get($latestObservedFinalAuth->metadata, 'codex_real_invoker_post_start_final_process_start_authorization.actual_process_start_allowed'),
                    'token_spend_allowed' => data_get($latestObservedFinalAuth->metadata, 'codex_real_invoker_post_start_final_process_start_authorization.token_spend_allowed'),
                ]
                : null,
            'runtime_policy' => [
                'status_projection_is_read_only' => true,
                'invoker_may_prepare_codex_real_invoker_post_start_final_process_start_authorization_when_called_with_guarded_input' => true,
                'post_start_final_process_start_authorization_is_not_real_process_invocation' => true,
                'actual_process_start_rehearsal_required_before_actual_process' => true,
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
            'next_required_slice' => $statusReady
                ? 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_actual_process_start_rehearsal_gate_contract'
                : 'repair_one_shot_scheduler_tick_codex_real_invoker_post_start_final_process_start_authorization_gate_service_blockers',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_final_process_start_authorization_gate_status.v1',
            'status' => (string) $status['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_final_process_start_authorization_gate_status',
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
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_final_process_start_authorization_gate_status' => $status,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_final_process_start_authorization_gate_status_hash' => $this->stableHash($status),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_final_process_start_authorization_gate_status_does_not_call_final_authorization_gate',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_final_process_start_authorization_gate_status_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_final_process_start_authorization_gate_status_does_not_execute_adapter',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_final_process_start_authorization_gate_status_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_final_process_start_authorization_gate_status_does_not_dispatch_work',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_final_process_start_authorization_gate_status_does_not_enable_self_programming',
            ],
            'human_summary' => $statusReady
                ? 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start final process start authorization gate service is ready and inspectable; actual process start rehearsal remains separate.'
                : 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start final process start authorization gate service is blocked until invoker, generic final authorization gates and storage are ready.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartActualProcessStartRehearsalGateStatus(array $options = []): array
    {
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerTableReady = Schema::hasTable('atlas_ledger_events');
        $gateReady = class_exists(AgentCodexRealInvokerPostStartActualProcessStartRehearsalGate::class)
            && method_exists(AgentCodexRealInvokerPostStartActualProcessStartRehearsalGate::class, 'rehearsePostStartActualProcessStart');
        $invokerReady = class_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartActualProcessStartRehearsalGateInvoker::class)
            && method_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartActualProcessStartRehearsalGateInvoker::class, 'prepareCodexRealInvokerPostStartActualProcessStartRehearsalGate');
        $rehearsalExecutorReady = class_exists(AgentCodexRealInvokerActualProcessStartRehearsalExecutor::class)
            && method_exists(AgentCodexRealInvokerActualProcessStartRehearsalExecutor::class, 'rehearseActualStart');
        $finalAuthorizationReady = class_exists(AgentCodexRealInvokerPostStartFinalProcessStartAuthorizationGate::class)
            && method_exists(AgentCodexRealInvokerPostStartFinalProcessStartAuthorizationGate::class, 'authorizePostStartFinalProcessStart');

        $observedRehearsalRunsQuery = $runsTableReady
            ? $this->scopePostStartRunsQuery(
                AtlasSelfConstructionAgentRun::query()
                    ->whereNotNull('metadata->codex_real_invoker_post_start_actual_process_start_rehearsal->real_invoker_actual_process_start_rehearsal_id'),
                $options
            )
            : null;
        $providerRunsWithRehearsalQuery = $runsTableReady
            ? $this->scopePostStartRunsQuery(
                AtlasSelfConstructionAgentRun::query()
                    ->where('run_key', 'like', 'provider-start:%')
                    ->where('status', 'adapter_invocation_prepared')
                    ->whereNotNull('metadata->codex_real_invoker_actual_process_start_rehearsal->real_invoker_actual_process_start_rehearsal_id'),
                $options
            )
            : null;
        $latestObservedRehearsal = $observedRehearsalRunsQuery === null
            ? null
            : (clone $observedRehearsalRunsQuery)->latest('updated_at')->first();

        $statusReady = $runsTableReady
            && $ledgerTableReady
            && $gateReady
            && $invokerReady
            && $rehearsalExecutorReady
            && $finalAuthorizationReady;
        $status = [
            'status' => $statusReady ? 'one_shot_tick_codex_real_invoker_post_start_actual_process_start_rehearsal_gate_service_ready' : 'blocked',
            'provider' => 'codex',
            'adapter' => 'codex',
            'invoker_service' => AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartActualProcessStartRehearsalGateInvoker::class,
            'invoker_service_ready' => $invokerReady,
            'invoker_canonical_method' => 'prepareCodexRealInvokerPostStartActualProcessStartRehearsalGate',
            'generic_post_start_actual_process_start_rehearsal_gate_service' => AgentCodexRealInvokerPostStartActualProcessStartRehearsalGate::class,
            'generic_post_start_actual_process_start_rehearsal_gate_service_ready' => $gateReady,
            'generic_post_start_actual_process_start_rehearsal_gate_canonical_method' => 'rehearsePostStartActualProcessStart',
            'codex_real_invoker_actual_process_start_rehearsal_executor_service' => AgentCodexRealInvokerActualProcessStartRehearsalExecutor::class,
            'codex_real_invoker_actual_process_start_rehearsal_executor_ready' => $rehearsalExecutorReady,
            'codex_real_invoker_actual_process_start_rehearsal_executor_canonical_method' => 'rehearseActualStart',
            'post_start_final_process_start_authorization_gate_service' => AgentCodexRealInvokerPostStartFinalProcessStartAuthorizationGate::class,
            'post_start_final_process_start_authorization_gate_ready' => $finalAuthorizationReady,
            'agent_runs_table_ready' => $runsTableReady,
            'ledger_table_ready' => $ledgerTableReady,
            'codex_real_invoker_post_start_actual_process_start_rehearsal_recorded_run_count' => $observedRehearsalRunsQuery === null ? null : (clone $observedRehearsalRunsQuery)->count(),
            'provider_start_runs_with_codex_real_invoker_actual_process_start_rehearsal_count' => $providerRunsWithRehearsalQuery === null ? null : (clone $providerRunsWithRehearsalQuery)->count(),
            'latest_codex_real_invoker_post_start_actual_process_start_rehearsal' => $latestObservedRehearsal instanceof AtlasSelfConstructionAgentRun
                ? [
                    'agent_run_id' => $latestObservedRehearsal->id,
                    'run_key' => $latestObservedRehearsal->run_key,
                    'packet_id' => $latestObservedRehearsal->packet_id,
                    'provider' => $latestObservedRehearsal->provider,
                    'status' => $latestObservedRehearsal->status,
                    'post_start_actual_process_start_rehearsal_gate_id' => data_get($latestObservedRehearsal->metadata, 'codex_real_invoker_post_start_actual_process_start_rehearsal.post_start_actual_process_start_rehearsal_gate_id'),
                    'post_start_final_process_start_authorization_gate_id' => data_get($latestObservedRehearsal->metadata, 'codex_real_invoker_post_start_actual_process_start_rehearsal.post_start_final_process_start_authorization_gate_id'),
                    'post_start_evidence_acceptance_bridge_id' => data_get($latestObservedRehearsal->metadata, 'codex_real_invoker_post_start_actual_process_start_rehearsal.post_start_evidence_acceptance_bridge_id'),
                    'real_invoker_final_process_start_authorization_id' => data_get($latestObservedRehearsal->metadata, 'codex_real_invoker_post_start_actual_process_start_rehearsal.real_invoker_final_process_start_authorization_id'),
                    'real_invoker_actual_process_start_rehearsal_id' => data_get($latestObservedRehearsal->metadata, 'codex_real_invoker_post_start_actual_process_start_rehearsal.real_invoker_actual_process_start_rehearsal_id'),
                    'codex_execution_id' => data_get($latestObservedRehearsal->metadata, 'codex_real_invoker_post_start_actual_process_start_rehearsal.codex_execution_id'),
                    'process_start_rehearsed' => data_get($latestObservedRehearsal->metadata, 'codex_real_invoker_post_start_actual_process_start_rehearsal.process_start_rehearsed'),
                    'real_invoker_actual_process_start_rehearsal_prepared' => data_get($latestObservedRehearsal->metadata, 'codex_real_invoker_post_start_actual_process_start_rehearsal.real_invoker_actual_process_start_rehearsal_prepared'),
                    'final_process_start_authorized' => data_get($latestObservedRehearsal->metadata, 'codex_real_invoker_post_start_actual_process_start_rehearsal.final_process_start_authorized'),
                    'actual_process_start_allowed' => data_get($latestObservedRehearsal->metadata, 'codex_real_invoker_post_start_actual_process_start_rehearsal.actual_process_start_allowed'),
                    'token_spend_allowed' => data_get($latestObservedRehearsal->metadata, 'codex_real_invoker_post_start_actual_process_start_rehearsal.token_spend_allowed'),
                ]
                : null,
            'runtime_policy' => [
                'status_projection_is_read_only' => true,
                'invoker_may_prepare_codex_real_invoker_post_start_actual_process_start_rehearsal_when_called_with_final_authorization_input' => true,
                'rehearsal_is_not_real_process_invocation' => true,
                'process_start_envelope_required_before_actual_process' => true,
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
            'next_required_slice' => $statusReady
                ? 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_process_start_envelope_gate_contract'
                : 'repair_one_shot_scheduler_tick_codex_real_invoker_post_start_actual_process_start_rehearsal_gate_service_blockers',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_actual_process_start_rehearsal_gate_status.v1',
            'status' => (string) $status['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_actual_process_start_rehearsal_gate_status',
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
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_actual_process_start_rehearsal_gate_status' => $status,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_actual_process_start_rehearsal_gate_status_hash' => $this->stableHash($status),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_actual_process_start_rehearsal_gate_status_does_not_call_rehearsal_gate',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_actual_process_start_rehearsal_gate_status_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_actual_process_start_rehearsal_gate_status_does_not_execute_adapter',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_actual_process_start_rehearsal_gate_status_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_actual_process_start_rehearsal_gate_status_does_not_dispatch_work',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_actual_process_start_rehearsal_gate_status_does_not_enable_self_programming',
            ],
            'human_summary' => $statusReady
                ? 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start actual process start rehearsal gate service is ready and inspectable; process start envelope remains separate.'
                : 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start actual process start rehearsal gate service is blocked until invoker, generic rehearsal gates and storage are ready.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartStartExecutionGatePreflight(array $options = []): array
    {
        $contractPayload = $this->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartStartExecutionGateContract($options);
        $contract = (array) data_get($contractPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_start_execution_gate_contract', []);
        $contractHash = (string) data_get($contractPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_start_execution_gate_contract_hash');
        $gateReady = class_exists(AgentCodexRealInvokerPostStartStartExecutionGate::class)
            && method_exists(AgentCodexRealInvokerPostStartStartExecutionGate::class, 'authorizePostStartStartExecution');
        $invokerReady = class_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartStartExecutionGateInvoker::class)
            && method_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartStartExecutionGateInvoker::class, 'prepareCodexRealInvokerPostStartStartExecutionGate');
        $startExecutionReady = class_exists(AgentCodexRealInvokerStartExecutionGate::class)
            && method_exists(AgentCodexRealInvokerStartExecutionGate::class, 'authorizeStartExecution');
        $envelopeReady = class_exists(AgentCodexRealInvokerPostStartProcessStartEnvelopeGate::class)
            && method_exists(AgentCodexRealInvokerPostStartProcessStartEnvelopeGate::class, 'buildPostStartProcessStartEnvelope');
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerTableReady = Schema::hasTable('atlas_ledger_events');

        $checks = [
            'post_start_start_execution_gate_contract_ready' => data_get($contractPayload, 'status') === 'one_shot_tick_codex_real_invoker_post_start_start_execution_gate_contract_ready',
            'post_start_start_execution_gate_contract_hash_present' => $contractHash !== '',
            'post_start_process_start_envelope_gate_status_ready' => data_get($contract, 'source_codex_real_invoker_post_start_process_start_envelope_gate_status') === 'one_shot_tick_codex_real_invoker_post_start_process_start_envelope_gate_service_ready',
            'generic_post_start_start_execution_gate_template_ready' => data_get($contract, 'source_codex_real_invoker_post_start_start_execution_gate_contract_status') === 'codex_real_invoker_post_start_start_execution_gate_contract_template_ready',
            'generic_start_execution_gate_template_ready' => data_get($contract, 'source_codex_real_invoker_start_execution_gate_contract_status') === 'codex_real_invoker_start_execution_gate_contract_template_ready',
            'codex_real_invoker_post_start_start_execution_gate_ready' => $gateReady,
            'codex_real_invoker_post_start_start_execution_gate_invoker_ready' => $invokerReady,
            'codex_real_invoker_start_execution_gate_ready' => $startExecutionReady,
            'codex_real_invoker_post_start_process_start_envelope_gate_ready' => $envelopeReady,
            'canonical_post_start_start_execution_gate_method_ready' => data_get($contract, 'start_execution_gate.canonical_post_start_start_execution_gate_method') === 'authorizePostStartStartExecution',
            'scheduler_invoker_method_ready' => data_get($contract, 'start_execution_gate.scheduler_invoker_method') === 'prepareCodexRealInvokerPostStartStartExecutionGate',
            'contract_requires_post_start_process_start_envelope' => data_get($contract, 'start_execution_gate.post_start_process_start_envelope_required_before_start_execution') === true,
            'contract_requires_post_start_evidence_acceptance_bridge' => data_get($contract, 'start_execution_gate.post_start_evidence_acceptance_bridge_required_before_start_execution') === true,
            'contract_requires_provider_start_envelope' => data_get($contract, 'start_execution_gate.provider_start_envelope_required_before_start_execution') === true,
            'contract_delegates_to_codex_real_invoker_start_execution_gate' => data_get($contract, 'start_execution_gate.gate_delegates_to_codex_real_invoker_start_execution_gate') === true,
            'contract_declares_authorization_is_not_actual_process_start' => data_get($contract, 'start_execution_gate.start_execution_authorization_is_not_actual_process_start') === true,
            'contract_requires_process_starter_readiness_after_start_execution' => in_array('start_actual_process_without_separate_process_starter_readiness_contract', (array) data_get($contract, 'forbidden_even_after_contract', []), true),
            'contract_authorizes_start_execution' => data_get($contract, 'start_execution_gate.start_execution_authorized_by_contract') === true,
            'contract_keeps_actual_process_start_disabled' => data_get($contract, 'start_execution_gate.actual_process_start_allowed_by_contract') === false,
            'contract_keeps_process_started_false' => data_get($contract, 'start_execution_gate.process_started_by_contract') === false,
            'contract_keeps_adapter_execution_disabled' => data_get($contract, 'start_execution_gate.adapter_execution_allowed_by_contract') === false,
            'contract_does_not_dispatch_work' => data_get($contract, 'start_execution_gate.dispatch_allowed_by_contract') === false,
            'contract_does_not_spend_tokens' => data_get($contract, 'start_execution_gate.token_spend_allowed_by_contract') === false,
            'contract_lists_runtime_enabling_flags_as_forbidden' => in_array('actual_process_start_allowed', (array) data_get($contract, 'forbidden_true_input_flags', []), true)
                && in_array('dispatch_allowed', (array) data_get($contract, 'forbidden_true_input_flags', []), true)
                && in_array('process_started', (array) data_get($contract, 'forbidden_true_input_flags', []), true)
                && in_array('adapter_execution_allowed', (array) data_get($contract, 'forbidden_true_input_flags', []), true)
                && in_array('self_programming_allowed', (array) data_get($contract, 'forbidden_true_input_flags', []), true),
            'agent_runs_table_ready' => $runsTableReady,
            'ledger_table_ready' => $ledgerTableReady,
            'self_programming_forbidden' => in_array('enable_self_programming', (array) data_get($contract, 'forbidden_even_after_contract', []), true),
        ];
        $blockingReasons = array_values(array_keys(array_filter($checks, static fn (bool $passed): bool => ! $passed)));

        $preflight = [
            'status' => $blockingReasons === [] ? 'one_shot_tick_codex_real_invoker_post_start_start_execution_gate_preflight_ready' : 'blocked',
            'preflight_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-REAL-INVOKER-POST-START-START-EXECUTION-GATE-PREFLIGHT-SELF-CONSTRUCTION-0001',
            'source_post_start_start_execution_gate_contract_hash' => $contractHash,
            'preflight_checks' => $checks,
            'blocking_count' => count($blockingReasons),
            'blocking_reasons' => $blockingReasons,
            'implementation_requirements' => [
                'create_scheduler_specific_post_start_start_execution_gate_invoker',
                'delegate_to_agent_codex_real_invoker_post_start_start_execution_gate',
                'require_post_start_process_start_envelope_metadata',
                'require_post_start_evidence_acceptance_bridge',
                'require_operator_execution_gate_receipt_hash',
                'require_execution_gate_policy_hash',
                'require_execution_window_hash',
                'require_preflight_snapshot_hash',
                'require_rollback_readiness_hash',
                'require_human_start_signature_hash',
                'reject_caller_inputs_that_try_to_enable_runtime_flags_or_mark_process_started',
                'project_readiness_status_without_invoking_codex',
            ],
            'runtime_policy' => [
                'preflight_is_read_only' => true,
                'post_start_start_execution_gate_call_allowed_by_future_invoker' => true,
                'start_execution_authorized_after_future_invoker' => true,
                'process_starter_readiness_required_after_start_execution_gate' => true,
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
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_start_execution_gate_implementation_packet',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_start_execution_gate_preflight.v1',
            'status' => (string) $preflight['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_start_execution_gate_preflight',
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
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_start_execution_gate_preflight' => $preflight,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_start_execution_gate_preflight_hash' => $this->stableHash($preflight),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_start_execution_gate_preflight_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_start_execution_gate_preflight_does_not_call_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_start_execution_gate_preflight_does_not_execute_adapter',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_start_execution_gate_preflight_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_start_execution_gate_preflight_does_not_dispatch_work',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_start_execution_gate_preflight_does_not_enable_self_programming',
            ],
            'human_summary' => $blockingReasons === []
                ? 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start start execution gate preflight is ready; process starter readiness gate remains separate.'
                : 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start start execution gate preflight is blocked until envelope, start execution gate, storage and no-runtime prerequisites are ready.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartExecutorFreshReleaseGateStatus(array $options = []): array
    {
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerTableReady = Schema::hasTable('atlas_ledger_events');
        $gateReady = class_exists(AgentCodexRealInvokerPostStartExecutorFreshReleaseGate::class)
            && method_exists(AgentCodexRealInvokerPostStartExecutorFreshReleaseGate::class, 'authorizePostStartExecutorFreshRelease');
        $invokerReady = class_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartExecutorFreshReleaseGateInvoker::class)
            && method_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartExecutorFreshReleaseGateInvoker::class, 'authorizeCodexRealInvokerPostStartExecutorFreshReleaseGate');
        $freshReleaseReady = class_exists(AgentCodexRealInvokerExecutorFreshReleaseGate::class)
            && method_exists(AgentCodexRealInvokerExecutorFreshReleaseGate::class, 'authorizeFreshRelease');
        $executorPlanReady = class_exists(AgentCodexRealInvokerPostStartExecutorPlanGate::class)
            && method_exists(AgentCodexRealInvokerPostStartExecutorPlanGate::class, 'preparePostStartExecutorPlan');

        $observedFreshReleaseRunsQuery = $runsTableReady
            ? $this->scopePostStartRunsQuery(
                AtlasSelfConstructionAgentRun::query()
                    ->whereNotNull('metadata->codex_real_invoker_post_start_executor_fresh_release->real_invoker_executor_fresh_release_id'),
                $options
            )
            : null;
        $providerRunsWithFreshReleaseQuery = $runsTableReady
            ? $this->scopePostStartRunsQuery(
                AtlasSelfConstructionAgentRun::query()
                    ->where('run_key', 'like', 'provider-start:%')
                    ->where('status', 'adapter_invocation_prepared')
                    ->whereNotNull('metadata->codex_real_invoker_executor_fresh_release->real_invoker_executor_fresh_release_id'),
                $options
            )
            : null;
        $latestObservedFreshRelease = $observedFreshReleaseRunsQuery === null
            ? null
            : (clone $observedFreshReleaseRunsQuery)->latest('updated_at')->first();

        $statusReady = $runsTableReady
            && $ledgerTableReady
            && $gateReady
            && $invokerReady
            && $freshReleaseReady
            && $executorPlanReady;
        $status = [
            'status' => $statusReady ? 'one_shot_tick_codex_real_invoker_post_start_executor_fresh_release_gate_service_ready' : 'blocked',
            'provider' => 'codex',
            'adapter' => 'codex',
            'invoker_service' => AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartExecutorFreshReleaseGateInvoker::class,
            'invoker_service_ready' => $invokerReady,
            'invoker_canonical_method' => 'authorizeCodexRealInvokerPostStartExecutorFreshReleaseGate',
            'generic_post_start_executor_fresh_release_gate_service' => AgentCodexRealInvokerPostStartExecutorFreshReleaseGate::class,
            'generic_post_start_executor_fresh_release_gate_service_ready' => $gateReady,
            'generic_post_start_executor_fresh_release_gate_canonical_method' => 'authorizePostStartExecutorFreshRelease',
            'codex_real_invoker_executor_fresh_release_gate_service' => AgentCodexRealInvokerExecutorFreshReleaseGate::class,
            'codex_real_invoker_executor_fresh_release_gate_ready' => $freshReleaseReady,
            'codex_real_invoker_executor_fresh_release_gate_canonical_method' => 'authorizeFreshRelease',
            'post_start_executor_plan_gate_service' => AgentCodexRealInvokerPostStartExecutorPlanGate::class,
            'post_start_executor_plan_gate_ready' => $executorPlanReady,
            'agent_runs_table_ready' => $runsTableReady,
            'ledger_table_ready' => $ledgerTableReady,
            'codex_real_invoker_post_start_executor_fresh_release_recorded_run_count' => $observedFreshReleaseRunsQuery === null ? null : (clone $observedFreshReleaseRunsQuery)->count(),
            'provider_start_runs_with_codex_real_invoker_executor_fresh_release_count' => $providerRunsWithFreshReleaseQuery === null ? null : (clone $providerRunsWithFreshReleaseQuery)->count(),
            'latest_codex_real_invoker_post_start_executor_fresh_release' => $latestObservedFreshRelease instanceof AtlasSelfConstructionAgentRun
                ? [
                    'agent_run_id' => $latestObservedFreshRelease->id,
                    'run_key' => $latestObservedFreshRelease->run_key,
                    'packet_id' => $latestObservedFreshRelease->packet_id,
                    'provider' => $latestObservedFreshRelease->provider,
                    'status' => $latestObservedFreshRelease->status,
                    'post_start_executor_fresh_release_gate_id' => data_get($latestObservedFreshRelease->metadata, 'codex_real_invoker_post_start_executor_fresh_release.post_start_executor_fresh_release_gate_id'),
                    'post_start_evidence_acceptance_bridge_id' => data_get($latestObservedFreshRelease->metadata, 'codex_real_invoker_post_start_executor_fresh_release.post_start_evidence_acceptance_bridge_id'),
                    'post_start_executor_plan_gate_id' => data_get($latestObservedFreshRelease->metadata, 'codex_real_invoker_post_start_executor_fresh_release.post_start_executor_plan_gate_id'),
                    'real_invoker_executor_plan_id' => data_get($latestObservedFreshRelease->metadata, 'codex_real_invoker_post_start_executor_fresh_release.real_invoker_executor_plan_id'),
                    'real_invoker_executor_fresh_release_id' => data_get($latestObservedFreshRelease->metadata, 'codex_real_invoker_post_start_executor_fresh_release.real_invoker_executor_fresh_release_id'),
                    'codex_execution_id' => data_get($latestObservedFreshRelease->metadata, 'codex_real_invoker_post_start_executor_fresh_release.codex_execution_id'),
                    'real_invoker_executor_fresh_release_authorized' => data_get($latestObservedFreshRelease->metadata, 'codex_real_invoker_post_start_executor_fresh_release.real_invoker_executor_fresh_release_authorized'),
                    'executor_enablement_required' => data_get($latestObservedFreshRelease->metadata, 'codex_real_invoker_post_start_executor_fresh_release.executor_enablement_required'),
                    'executor_enabled' => data_get($latestObservedFreshRelease->metadata, 'codex_real_invoker_post_start_executor_fresh_release.executor_enabled'),
                    'actual_process_start_allowed' => data_get($latestObservedFreshRelease->metadata, 'codex_real_invoker_post_start_executor_fresh_release.actual_process_start_allowed'),
                    'token_spend_allowed' => data_get($latestObservedFreshRelease->metadata, 'codex_real_invoker_post_start_executor_fresh_release.token_spend_allowed'),
                ]
                : null,
            'runtime_policy' => [
                'status_projection_is_read_only' => true,
                'invoker_may_authorize_fresh_release_when_called_with_executor_plan_input' => true,
                'fresh_release_is_not_executor_enablement' => true,
                'executor_enablement_required_before_execution' => true,
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
                ? 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_executor_enablement_gate_contract'
                : 'repair_one_shot_scheduler_tick_codex_real_invoker_post_start_executor_fresh_release_gate_service_blockers',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_fresh_release_gate_status.v1',
            'status' => (string) $status['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_fresh_release_gate_status',
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
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_fresh_release_gate_status' => $status,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_fresh_release_gate_status_hash' => $this->stableHash($status),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_fresh_release_gate_status_does_not_call_fresh_release_gate',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_fresh_release_gate_status_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_fresh_release_gate_status_does_not_enable_executor',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_fresh_release_gate_status_does_not_execute_adapter',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_fresh_release_gate_status_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_fresh_release_gate_status_does_not_dispatch_work',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_fresh_release_gate_status_does_not_enable_self_programming',
            ],
            'human_summary' => $statusReady
                ? 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start executor fresh release gate service is ready and inspectable; executor enablement remains separate.'
                : 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start executor fresh release gate service is blocked until invoker, generic fresh release gate, executor plan and storage are ready.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartSupervisedStartActivationGateStatus(array $options = []): array
    {
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerTableReady = Schema::hasTable('atlas_ledger_events');
        $gateReady = class_exists(AgentCodexRealInvokerPostStartSupervisedStartActivationGate::class)
            && method_exists(AgentCodexRealInvokerPostStartSupervisedStartActivationGate::class, 'preparePostStartSupervisedStartActivation');
        $invokerReady = class_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartSupervisedStartActivationGateInvoker::class)
            && method_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartSupervisedStartActivationGateInvoker::class, 'prepareCodexRealInvokerPostStartSupervisedStartActivationGate');
        $activationReady = class_exists(AgentCodexRealInvokerSupervisedStartActivationGate::class)
            && method_exists(AgentCodexRealInvokerSupervisedStartActivationGate::class, 'prepareActivation');
        $enablementReady = class_exists(AgentCodexRealInvokerPostStartExecutorEnablementGate::class)
            && method_exists(AgentCodexRealInvokerPostStartExecutorEnablementGate::class, 'enablePostStartExecutor');

        $observedActivationRunsQuery = $runsTableReady
            ? $this->scopePostStartRunsQuery(
                AtlasSelfConstructionAgentRun::query()
                    ->whereNotNull('metadata->codex_real_invoker_post_start_supervised_start_activation->real_invoker_supervised_start_activation_id'),
                $options
            )
            : null;
        $providerRunsWithActivationQuery = $runsTableReady
            ? $this->scopePostStartRunsQuery(
                AtlasSelfConstructionAgentRun::query()
                    ->where('run_key', 'like', 'provider-start:%')
                    ->where('status', 'adapter_invocation_prepared')
                    ->whereNotNull('metadata->codex_real_invoker_supervised_start_activation->real_invoker_supervised_start_activation_id'),
                $options
            )
            : null;
        $latestObservedActivation = $observedActivationRunsQuery === null
            ? null
            : (clone $observedActivationRunsQuery)->latest('updated_at')->first();

        $statusReady = $runsTableReady
            && $ledgerTableReady
            && $gateReady
            && $invokerReady
            && $activationReady
            && $enablementReady;
        $status = [
            'status' => $statusReady ? 'one_shot_tick_codex_real_invoker_post_start_supervised_start_activation_gate_service_ready' : 'blocked',
            'provider' => 'codex',
            'adapter' => 'codex',
            'invoker_service' => AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartSupervisedStartActivationGateInvoker::class,
            'invoker_service_ready' => $invokerReady,
            'invoker_canonical_method' => 'prepareCodexRealInvokerPostStartSupervisedStartActivationGate',
            'generic_post_start_supervised_start_activation_gate_service' => AgentCodexRealInvokerPostStartSupervisedStartActivationGate::class,
            'generic_post_start_supervised_start_activation_gate_service_ready' => $gateReady,
            'generic_post_start_supervised_start_activation_gate_canonical_method' => 'preparePostStartSupervisedStartActivation',
            'codex_real_invoker_supervised_start_activation_gate_service' => AgentCodexRealInvokerSupervisedStartActivationGate::class,
            'codex_real_invoker_supervised_start_activation_gate_ready' => $activationReady,
            'codex_real_invoker_supervised_start_activation_gate_canonical_method' => 'prepareActivation',
            'post_start_executor_enablement_gate_service' => AgentCodexRealInvokerPostStartExecutorEnablementGate::class,
            'post_start_executor_enablement_gate_ready' => $enablementReady,
            'agent_runs_table_ready' => $runsTableReady,
            'ledger_table_ready' => $ledgerTableReady,
            'codex_real_invoker_post_start_supervised_start_activation_recorded_run_count' => $observedActivationRunsQuery === null ? null : (clone $observedActivationRunsQuery)->count(),
            'provider_start_runs_with_codex_real_invoker_supervised_start_activation_count' => $providerRunsWithActivationQuery === null ? null : (clone $providerRunsWithActivationQuery)->count(),
            'latest_codex_real_invoker_post_start_supervised_start_activation' => $latestObservedActivation instanceof AtlasSelfConstructionAgentRun
                ? [
                    'agent_run_id' => $latestObservedActivation->id,
                    'run_key' => $latestObservedActivation->run_key,
                    'packet_id' => $latestObservedActivation->packet_id,
                    'provider' => $latestObservedActivation->provider,
                    'status' => $latestObservedActivation->status,
                    'post_start_supervised_start_activation_gate_id' => data_get($latestObservedActivation->metadata, 'codex_real_invoker_post_start_supervised_start_activation.post_start_supervised_start_activation_gate_id'),
                    'post_start_executor_enablement_gate_id' => data_get($latestObservedActivation->metadata, 'codex_real_invoker_post_start_supervised_start_activation.post_start_executor_enablement_gate_id'),
                    'post_start_evidence_acceptance_bridge_id' => data_get($latestObservedActivation->metadata, 'codex_real_invoker_post_start_supervised_start_activation.post_start_evidence_acceptance_bridge_id'),
                    'real_invoker_executor_enablement_id' => data_get($latestObservedActivation->metadata, 'codex_real_invoker_post_start_supervised_start_activation.real_invoker_executor_enablement_id'),
                    'real_invoker_supervised_start_activation_id' => data_get($latestObservedActivation->metadata, 'codex_real_invoker_post_start_supervised_start_activation.real_invoker_supervised_start_activation_id'),
                    'codex_execution_id' => data_get($latestObservedActivation->metadata, 'codex_real_invoker_post_start_supervised_start_activation.codex_execution_id'),
                    'real_invoker_supervised_start_activation_prepared' => data_get($latestObservedActivation->metadata, 'codex_real_invoker_post_start_supervised_start_activation.real_invoker_supervised_start_activation_prepared'),
                    'executor_enabled' => data_get($latestObservedActivation->metadata, 'codex_real_invoker_post_start_supervised_start_activation.executor_enabled'),
                    'process_start_armed' => data_get($latestObservedActivation->metadata, 'codex_real_invoker_post_start_supervised_start_activation.process_start_armed'),
                    'actual_process_start_allowed' => data_get($latestObservedActivation->metadata, 'codex_real_invoker_post_start_supervised_start_activation.actual_process_start_allowed'),
                    'token_spend_allowed' => data_get($latestObservedActivation->metadata, 'codex_real_invoker_post_start_supervised_start_activation.token_spend_allowed'),
                ]
                : null,
            'runtime_policy' => [
                'status_projection_is_read_only' => true,
                'invoker_may_prepare_codex_real_invoker_post_start_supervised_start_activation_when_called_with_enablement_input' => true,
                'post_start_supervised_start_activation_is_not_real_process_invocation' => true,
                'guarded_process_start_required_before_actual_process' => true,
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
            'next_required_slice' => $statusReady
                ? 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_guarded_process_start_executor_gate_contract'
                : 'repair_one_shot_scheduler_tick_codex_real_invoker_post_start_supervised_start_activation_gate_service_blockers',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_supervised_start_activation_gate_status.v1',
            'status' => (string) $status['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_supervised_start_activation_gate_status',
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
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_supervised_start_activation_gate_status' => $status,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_supervised_start_activation_gate_status_hash' => $this->stableHash($status),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_supervised_start_activation_gate_status_does_not_call_activation_gate',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_supervised_start_activation_gate_status_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_supervised_start_activation_gate_status_does_not_execute_adapter',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_supervised_start_activation_gate_status_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_supervised_start_activation_gate_status_does_not_dispatch_work',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_supervised_start_activation_gate_status_does_not_enable_self_programming',
            ],
            'human_summary' => $statusReady
                ? 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start supervised start activation gate service is ready and inspectable; guarded process start remains separate.'
                : 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start supervised start activation gate service is blocked until invoker, generic activation gates and storage are ready.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartGuardedProcessStartExecutorGateStatus(array $options = []): array
    {
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerTableReady = Schema::hasTable('atlas_ledger_events');
        $gateReady = class_exists(AgentCodexRealInvokerPostStartGuardedProcessStartExecutorGate::class)
            && method_exists(AgentCodexRealInvokerPostStartGuardedProcessStartExecutorGate::class, 'preparePostStartGuardedProcessStart');
        $invokerReady = class_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartGuardedProcessStartExecutorGateInvoker::class)
            && method_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartGuardedProcessStartExecutorGateInvoker::class, 'prepareCodexRealInvokerPostStartGuardedProcessStartExecutorGate');
        $guardedReady = class_exists(AgentCodexRealInvokerGuardedProcessStartExecutor::class)
            && method_exists(AgentCodexRealInvokerGuardedProcessStartExecutor::class, 'prepareGuardedStart');
        $activationReady = class_exists(AgentCodexRealInvokerPostStartSupervisedStartActivationGate::class)
            && method_exists(AgentCodexRealInvokerPostStartSupervisedStartActivationGate::class, 'preparePostStartSupervisedStartActivation');

        $observedGuardedRunsQuery = $runsTableReady
            ? $this->scopePostStartRunsQuery(
                AtlasSelfConstructionAgentRun::query()
                    ->whereNotNull('metadata->codex_real_invoker_post_start_guarded_process_start->real_invoker_guarded_process_start_id'),
                $options
            )
            : null;
        $providerRunsWithGuardedQuery = $runsTableReady
            ? $this->scopePostStartRunsQuery(
                AtlasSelfConstructionAgentRun::query()
                    ->where('run_key', 'like', 'provider-start:%')
                    ->where('status', 'adapter_invocation_prepared')
                    ->whereNotNull('metadata->codex_real_invoker_guarded_process_start->real_invoker_guarded_process_start_id'),
                $options
            )
            : null;
        $latestObservedGuarded = $observedGuardedRunsQuery === null
            ? null
            : (clone $observedGuardedRunsQuery)->latest('updated_at')->first();

        $statusReady = $runsTableReady
            && $ledgerTableReady
            && $gateReady
            && $invokerReady
            && $guardedReady
            && $activationReady;
        $status = [
            'status' => $statusReady ? 'one_shot_tick_codex_real_invoker_post_start_guarded_process_start_executor_gate_service_ready' : 'blocked',
            'provider' => 'codex',
            'adapter' => 'codex',
            'invoker_service' => AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartGuardedProcessStartExecutorGateInvoker::class,
            'invoker_service_ready' => $invokerReady,
            'invoker_canonical_method' => 'prepareCodexRealInvokerPostStartGuardedProcessStartExecutorGate',
            'generic_post_start_guarded_process_start_executor_gate_service' => AgentCodexRealInvokerPostStartGuardedProcessStartExecutorGate::class,
            'generic_post_start_guarded_process_start_executor_gate_service_ready' => $gateReady,
            'generic_post_start_guarded_process_start_executor_gate_canonical_method' => 'preparePostStartGuardedProcessStart',
            'codex_real_invoker_guarded_process_start_executor_service' => AgentCodexRealInvokerGuardedProcessStartExecutor::class,
            'codex_real_invoker_guarded_process_start_executor_ready' => $guardedReady,
            'codex_real_invoker_guarded_process_start_executor_canonical_method' => 'prepareGuardedStart',
            'post_start_supervised_start_activation_gate_service' => AgentCodexRealInvokerPostStartSupervisedStartActivationGate::class,
            'post_start_supervised_start_activation_gate_ready' => $activationReady,
            'agent_runs_table_ready' => $runsTableReady,
            'ledger_table_ready' => $ledgerTableReady,
            'codex_real_invoker_post_start_guarded_process_start_recorded_run_count' => $observedGuardedRunsQuery === null ? null : (clone $observedGuardedRunsQuery)->count(),
            'provider_start_runs_with_codex_real_invoker_guarded_process_start_count' => $providerRunsWithGuardedQuery === null ? null : (clone $providerRunsWithGuardedQuery)->count(),
            'latest_codex_real_invoker_post_start_guarded_process_start' => $latestObservedGuarded instanceof AtlasSelfConstructionAgentRun
                ? [
                    'agent_run_id' => $latestObservedGuarded->id,
                    'run_key' => $latestObservedGuarded->run_key,
                    'packet_id' => $latestObservedGuarded->packet_id,
                    'provider' => $latestObservedGuarded->provider,
                    'status' => $latestObservedGuarded->status,
                    'post_start_guarded_process_start_gate_id' => data_get($latestObservedGuarded->metadata, 'codex_real_invoker_post_start_guarded_process_start.post_start_guarded_process_start_gate_id'),
                    'post_start_supervised_start_activation_gate_id' => data_get($latestObservedGuarded->metadata, 'codex_real_invoker_post_start_guarded_process_start.post_start_supervised_start_activation_gate_id'),
                    'post_start_evidence_acceptance_bridge_id' => data_get($latestObservedGuarded->metadata, 'codex_real_invoker_post_start_guarded_process_start.post_start_evidence_acceptance_bridge_id'),
                    'real_invoker_supervised_start_activation_id' => data_get($latestObservedGuarded->metadata, 'codex_real_invoker_post_start_guarded_process_start.real_invoker_supervised_start_activation_id'),
                    'real_invoker_guarded_process_start_id' => data_get($latestObservedGuarded->metadata, 'codex_real_invoker_post_start_guarded_process_start.real_invoker_guarded_process_start_id'),
                    'codex_execution_id' => data_get($latestObservedGuarded->metadata, 'codex_real_invoker_post_start_guarded_process_start.codex_execution_id'),
                    'real_invoker_guarded_process_start_prepared' => data_get($latestObservedGuarded->metadata, 'codex_real_invoker_post_start_guarded_process_start.real_invoker_guarded_process_start_prepared'),
                    'executor_enabled' => data_get($latestObservedGuarded->metadata, 'codex_real_invoker_post_start_guarded_process_start.executor_enabled'),
                    'process_start_armed' => data_get($latestObservedGuarded->metadata, 'codex_real_invoker_post_start_guarded_process_start.process_start_armed'),
                    'actual_process_start_allowed' => data_get($latestObservedGuarded->metadata, 'codex_real_invoker_post_start_guarded_process_start.actual_process_start_allowed'),
                    'token_spend_allowed' => data_get($latestObservedGuarded->metadata, 'codex_real_invoker_post_start_guarded_process_start.token_spend_allowed'),
                ]
                : null,
            'runtime_policy' => [
                'status_projection_is_read_only' => true,
                'invoker_may_prepare_codex_real_invoker_post_start_guarded_process_start_when_called_with_activation_input' => true,
                'post_start_guarded_process_start_is_not_real_process_invocation' => true,
                'final_process_start_authorization_required_before_actual_process' => true,
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
            'next_required_slice' => $statusReady
                ? 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_final_process_start_authorization_gate_contract'
                : 'repair_one_shot_scheduler_tick_codex_real_invoker_post_start_guarded_process_start_executor_gate_service_blockers',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_guarded_process_start_executor_gate_status.v1',
            'status' => (string) $status['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_guarded_process_start_executor_gate_status',
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
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_guarded_process_start_executor_gate_status' => $status,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_guarded_process_start_executor_gate_status_hash' => $this->stableHash($status),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_guarded_process_start_executor_gate_status_does_not_call_guarded_process_start_gate',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_guarded_process_start_executor_gate_status_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_guarded_process_start_executor_gate_status_does_not_execute_adapter',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_guarded_process_start_executor_gate_status_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_guarded_process_start_executor_gate_status_does_not_dispatch_work',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_guarded_process_start_executor_gate_status_does_not_enable_self_programming',
            ],
            'human_summary' => $statusReady
                ? 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start guarded process start executor gate service is ready and inspectable; final process start authorization remains separate.'
                : 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start guarded process start executor gate service is blocked until invoker, generic guarded executor gates and storage are ready.',
        ];
    }

}
