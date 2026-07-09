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
 * SC-01 fatia ReadinessProjectionTerminalLoopSection (Obra 4 Residual Elite).
 */
final class ReadinessProjectionTerminalLoopSection
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
            throw new \RuntimeException('ReadinessProjectionTerminalLoopSection mother not bound for '.$name);
        }
        $method = new \ReflectionMethod($this->mother, $name);

        return $method->invokeArgs($this->mother, $arguments);
    }


    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneTerminalLoopHealthDigestStatus(array $options = []): array
    {
        $targetMin = max(1, min(25, (int) ($options['target_min_claimable_tasks'] ?? 6)));
        $maxNew = max(0, min(25, (int) ($options['max_new_tasks'] ?? $targetMin)));
        $service = new AgentControlPlaneTerminalLoopHealthDigestService;
        $result = $service->digest([
            'actor' => $this->reservationActor($options),
            'target_min_claimable_tasks' => $targetMin,
            'max_new_tasks' => $maxNew,
            'queue_tags' => (array) ($options['queue_tags'] ?? []),
        ]);

        return $this->wrapCertificationWorkbenchStatus(
            keyPrefix: 'terminal_loop_health_digest',
            label: 'Terminal Loop Health Digest',
            payload: $result,
            statusKey: 'status',
            extraStatusFields: [
                'actor' => (string) data_get($result, 'actor'),
                'queue_tags' => (array) data_get($result, 'queue_tags', []),
                'target_min_claimable_tasks' => (int) data_get($result, 'target_min_claimable_tasks'),
                'claimable_task_count' => (int) data_get($result, 'queue_health.claimable_task_count'),
                'claimed_task_count' => (int) data_get($result, 'queue_health.claimed_task_count'),
                'unfiltered_claimable_task_count' => (int) data_get($result, 'queue_health.unfiltered_claimable_task_count'),
                'unfiltered_claimed_task_count' => (int) data_get($result, 'queue_health.unfiltered_claimed_task_count'),
                'tag_filter_active' => (bool) data_get($result, 'queue_health.tag_filter_active'),
                'hidden_claimable_outside_requested_tags' => (int) data_get($result, 'queue_health.hidden_claimable_outside_requested_tags'),
                'tag_filtered_supply_gap' => (bool) data_get($result, 'queue_health.tag_filtered_supply_gap'),
                'tag_filter_explainer' => (string) data_get($result, 'queue_health.tag_filter_explainer'),
                'active_lease_count' => (int) data_get($result, 'lease_health.active_lease_count'),
                'recoverable_lease_count' => (int) data_get($result, 'lease_health.recoverable_lease_count'),
                'recommended_action' => (string) data_get($result, 'loop_decision.recommended_action'),
                'loop_decision_recommended_action' => (string) data_get($result, 'loop_decision.recommended_action'),
                'safe_to_start_new_worker' => (bool) data_get($result, 'loop_decision.safe_to_start_new_worker'),
                'loop_decision_safe_to_start_new_worker' => (bool) data_get($result, 'loop_decision.safe_to_start_new_worker'),
                'worker_task_eligibility_status' => (string) data_get($result, 'loop_decision.worker_task_eligibility_status'),
                'worker_task_eligibility_required_before_worker_launch' => (bool) data_get($result, 'loop_decision.worker_task_eligibility_required_before_worker_launch'),
                'worker_task_eligibility_violation_count' => (int) data_get($result, 'loop_decision.worker_task_eligibility_violation_count'),
                'worker_task_eligibility_hash' => (string) data_get($result, 'worker_task_eligibility.worker_task_eligibility_hash'),
                'should_replenish_before_next_claim' => (bool) data_get($result, 'loop_decision.should_replenish_before_next_claim'),
                'loop_decision_should_replenish_before_next_claim' => (bool) data_get($result, 'loop_decision.should_replenish_before_next_claim'),
                'should_recover_before_next_claim' => (bool) data_get($result, 'loop_decision.should_recover_before_next_claim'),
                'loop_decision_should_recover_before_next_claim' => (bool) data_get($result, 'loop_decision.should_recover_before_next_claim'),
                'can_loop_without_chat_history' => (bool) data_get($result, 'loop_decision.can_loop_without_chat_history'),
                'loop_decision_can_loop_without_chat_history' => (bool) data_get($result, 'loop_decision.can_loop_without_chat_history'),
                'terminal_loop_fleet_launch_plan_schema' => (string) data_get($result, 'terminal_loop_fleet_launch_plan.schema_version'),
                'terminal_loop_fleet_launch_plan_status' => (string) data_get($result, 'terminal_loop_fleet_launch_plan.status'),
                'terminal_loop_fleet_recommended_terminal_count' => (int) data_get($result, 'terminal_loop_fleet_launch_plan.recommended_terminal_count'),
                'terminal_loop_fleet_safe_to_start_now' => (bool) data_get($result, 'terminal_loop_fleet_launch_plan.safe_to_start_now'),
                'terminal_loop_fleet_can_execute_from_digest' => (bool) data_get($result, 'terminal_loop_fleet_launch_plan.can_execute_from_digest'),
                'terminal_loop_fleet_can_claim_from_digest' => (bool) data_get($result, 'terminal_loop_fleet_launch_plan.can_claim_from_digest'),
                'terminal_loop_fleet_blocked_reasons' => (array) data_get($result, 'terminal_loop_fleet_launch_plan.blocked_reasons', []),
                'terminal_loop_fleet_start_policy' => (array) data_get($result, 'terminal_loop_fleet_launch_plan.start_policy', []),
                'terminal_loop_fleet_terminal_assignments' => (array) data_get($result, 'terminal_loop_fleet_launch_plan.terminal_assignments', []),
                'terminal_loop_fleet_copy_paste_terminal_commands' => (array) data_get($result, 'terminal_loop_fleet_launch_plan.copy_paste_terminal_commands', []),
                'terminal_loop_fleet_post_launch_observability_commands' => (array) data_get($result, 'terminal_loop_fleet_launch_plan.post_launch_observability_commands', []),
                'terminal_loop_fleet_forbidden_shortcuts' => (array) data_get($result, 'terminal_loop_fleet_launch_plan.forbidden_shortcuts', []),
                'terminal_loop_fleet_plan_hash' => (string) data_get($result, 'terminal_loop_fleet_launch_plan.terminal_loop_fleet_launch_plan_hash'),
                'terminal_loop_fleet_replenishment_plan_schema' => (string) data_get($result, 'terminal_loop_fleet_replenishment_plan.schema_version'),
                'terminal_loop_fleet_replenishment_plan_status' => (string) data_get($result, 'terminal_loop_fleet_replenishment_plan.status'),
                'terminal_loop_fleet_replenishment_required_new_task_count' => (int) data_get($result, 'terminal_loop_fleet_replenishment_plan.required_new_task_count'),
                'terminal_loop_fleet_replenishment_bounded_new_task_count' => (int) data_get($result, 'terminal_loop_fleet_replenishment_plan.bounded_new_task_count'),
                'terminal_loop_fleet_replenishment_should_replenish_now' => (bool) data_get($result, 'terminal_loop_fleet_replenishment_plan.should_replenish_now'),
                'terminal_loop_fleet_replenishment_can_replenish_from_digest' => (bool) data_get($result, 'terminal_loop_fleet_replenishment_plan.can_replenish_from_digest'),
                'terminal_loop_fleet_replenishment_plan_hash' => (string) data_get($result, 'terminal_loop_fleet_replenishment_plan.terminal_loop_fleet_replenishment_plan_hash'),
                'terminal_loop_fleet_resume_rollup_schema' => (string) data_get($result, 'terminal_loop_fleet_resume_rollup.schema_version'),
                'terminal_loop_fleet_resume_rollup_status' => (string) data_get($result, 'terminal_loop_fleet_resume_rollup.status'),
                'terminal_loop_fleet_resume_active_lease_count' => (int) data_get($result, 'terminal_loop_fleet_resume_rollup.active_lease_count'),
                'terminal_loop_fleet_resume_recoverable_task_count' => (int) data_get($result, 'terminal_loop_fleet_resume_rollup.recoverable_task_count'),
                'terminal_loop_fleet_resume_attention_required' => (bool) data_get($result, 'terminal_loop_fleet_resume_rollup.resume_attention_required'),
                'terminal_loop_fleet_resume_can_recover_from_rollup' => (bool) data_get($result, 'terminal_loop_fleet_resume_rollup.can_recover_from_rollup'),
                'terminal_loop_fleet_resume_can_claim_from_rollup' => (bool) data_get($result, 'terminal_loop_fleet_resume_rollup.can_claim_from_rollup'),
                'terminal_loop_fleet_resume_rollup_hash' => (string) data_get($result, 'terminal_loop_fleet_resume_rollup.terminal_loop_fleet_resume_rollup_hash'),
                'terminal_loop_fleet_evidence_rollup_schema' => (string) data_get($result, 'terminal_loop_fleet_evidence_rollup.schema_version'),
                'terminal_loop_fleet_evidence_rollup_status' => (string) data_get($result, 'terminal_loop_fleet_evidence_rollup.status'),
                'terminal_loop_fleet_completed_dry_run_task_count' => (int) data_get($result, 'terminal_loop_fleet_evidence_rollup.completed_dry_run_task_count'),
                'terminal_loop_fleet_valid_completion_evidence_count' => (int) data_get($result, 'terminal_loop_fleet_evidence_rollup.valid_completion_evidence_count'),
                'terminal_loop_fleet_evidence_attention_required_count' => (int) data_get($result, 'terminal_loop_fleet_evidence_rollup.attention_required_count'),
                'terminal_loop_fleet_evidence_ready_for_operator_review' => (bool) data_get($result, 'terminal_loop_fleet_evidence_rollup.ready_for_operator_review'),
                'terminal_loop_fleet_evidence_rollup_hash' => (string) data_get($result, 'terminal_loop_fleet_evidence_rollup.terminal_loop_fleet_evidence_rollup_hash'),
                'terminal_loop_fleet_operator_handoff_schema' => (string) data_get($result, 'terminal_loop_fleet_operator_handoff.schema_version'),
                'terminal_loop_fleet_operator_handoff_status' => (string) data_get($result, 'terminal_loop_fleet_operator_handoff.status'),
                'terminal_loop_fleet_operator_handoff_next_action' => (string) data_get($result, 'terminal_loop_fleet_operator_handoff.next_operator_action'),
                'terminal_loop_fleet_operator_handoff_primary_command' => (string) data_get($result, 'terminal_loop_fleet_operator_handoff.primary_command'),
                'terminal_loop_fleet_operator_handoff_ordered_sequence' => (array) data_get($result, 'terminal_loop_fleet_operator_handoff.ordered_operator_sequence', []),
                'terminal_loop_fleet_operator_handoff_copy_paste_commands' => (array) data_get($result, 'terminal_loop_fleet_operator_handoff.copy_paste_commands', []),
                'terminal_loop_fleet_operator_handoff_operator_checks' => (array) data_get($result, 'terminal_loop_fleet_operator_handoff.operator_checks_before_running_primary_command', []),
                'terminal_loop_fleet_operator_handoff_can_execute' => (bool) data_get($result, 'terminal_loop_fleet_operator_handoff.can_execute_from_handoff'),
                'terminal_loop_fleet_operator_handoff_hash' => (string) data_get($result, 'terminal_loop_fleet_operator_handoff.terminal_loop_fleet_operator_handoff_hash'),
                'terminal_loop_fleet_lane_isolation_schema' => (string) data_get($result, 'terminal_loop_fleet_lane_isolation.schema_version'),
                'terminal_loop_fleet_lane_isolation_status' => (string) data_get($result, 'terminal_loop_fleet_lane_isolation.status'),
                'terminal_loop_fleet_lane_all_commands_bound' => (bool) data_get($result, 'terminal_loop_fleet_lane_isolation.all_commands_lane_bound'),
                'terminal_loop_fleet_all_commands_lane_bound' => (bool) data_get($result, 'terminal_loop_fleet_lane_isolation.all_commands_lane_bound'),
                'terminal_loop_fleet_lane_required_tag_args' => (array) data_get($result, 'terminal_loop_fleet_lane_isolation.required_tag_args', []),
                'terminal_loop_fleet_lane_command_checks' => (array) data_get($result, 'terminal_loop_fleet_lane_isolation.command_lane_checks', []),
                'terminal_loop_fleet_lane_command_check_count' => count((array) data_get($result, 'terminal_loop_fleet_lane_isolation.command_lane_checks', [])),
                'terminal_loop_fleet_lane_can_change_tags' => (bool) data_get($result, 'terminal_loop_fleet_lane_isolation.can_change_tags_from_digest'),
                'terminal_loop_fleet_lane_isolation_hash' => (string) data_get($result, 'terminal_loop_fleet_lane_isolation.terminal_loop_fleet_lane_isolation_hash'),
                'terminal_loop_cycle_supervisor_schema' => (string) data_get($result, 'terminal_loop_cycle_supervisor.schema_version'),
                'terminal_loop_cycle_supervisor_status' => (string) data_get($result, 'terminal_loop_cycle_supervisor.status'),
                'terminal_loop_cycle_supervisor_cycle_state' => (string) data_get($result, 'terminal_loop_cycle_supervisor.cycle_state'),
                'terminal_loop_cycle_supervisor_next_command_purpose' => (string) data_get($result, 'terminal_loop_cycle_supervisor.next_command_purpose'),
                'terminal_loop_cycle_supervisor_next_command' => (string) data_get($result, 'terminal_loop_cycle_supervisor.next_command'),
                'terminal_loop_cycle_supervisor_operator_loop_contract' => (array) data_get($result, 'terminal_loop_cycle_supervisor.operator_loop_contract', []),
                'terminal_loop_cycle_supervisor_next_command_is_lane_bound' => (bool) data_get($result, 'terminal_loop_cycle_supervisor.next_command_is_lane_bound'),
                'terminal_loop_cycle_supervisor_can_execute' => (bool) data_get($result, 'terminal_loop_cycle_supervisor.can_execute_next_command'),
                'terminal_loop_cycle_supervisor_hash' => (string) data_get($result, 'terminal_loop_cycle_supervisor.terminal_loop_cycle_supervisor_hash'),
                'terminal_loop_fleet_launch_runbook_schema' => (string) data_get($result, 'terminal_loop_fleet_launch_runbook.schema_version'),
                'terminal_loop_fleet_launch_runbook_status' => (string) data_get($result, 'terminal_loop_fleet_launch_runbook.status'),
                'terminal_loop_fleet_launch_runbook_safe_to_copy_after_operator_review' => (bool) data_get($result, 'terminal_loop_fleet_launch_runbook.safe_to_copy_after_operator_review'),
                'terminal_loop_fleet_launch_runbook_can_resume_without_chat_history' => (bool) data_get($result, 'terminal_loop_fleet_launch_runbook.can_resume_without_chat_history'),
                'terminal_loop_fleet_launch_runbook_terminal_count' => (int) data_get($result, 'terminal_loop_fleet_launch_runbook.terminal_count'),
                'terminal_loop_fleet_launch_runbook_terminal_steps' => (array) data_get($result, 'terminal_loop_fleet_launch_runbook.terminal_steps', []),
                'terminal_loop_fleet_launch_runbook_ordered_sequence' => (array) data_get($result, 'terminal_loop_fleet_launch_runbook.ordered_operator_sequence', []),
                'terminal_loop_fleet_launch_runbook_resume_command' => (string) data_get($result, 'terminal_loop_fleet_launch_runbook.resume_after_interruption.resume_command'),
                'terminal_loop_fleet_launch_runbook_requires_fresh_digest_before_more_terminals' => (bool) data_get($result, 'terminal_loop_fleet_launch_runbook.resume_after_interruption.requires_fresh_health_digest_before_starting_more_terminals'),
                'terminal_loop_fleet_launch_runbook_operator_checks' => (array) data_get($result, 'terminal_loop_fleet_launch_runbook.operator_checks_before_starting', []),
                'terminal_loop_fleet_launch_runbook_stop_conditions' => (array) data_get($result, 'terminal_loop_fleet_launch_runbook.stop_conditions', []),
                'terminal_loop_fleet_launch_runbook_can_start_terminals' => (bool) data_get($result, 'terminal_loop_fleet_launch_runbook.can_start_terminals_from_runbook'),
                'terminal_loop_fleet_launch_runbook_can_execute_commands' => (bool) data_get($result, 'terminal_loop_fleet_launch_runbook.can_execute_commands_from_runbook'),
                'terminal_loop_fleet_launch_runbook_hash' => (string) data_get($result, 'terminal_loop_fleet_launch_runbook.terminal_loop_fleet_launch_runbook_hash'),
                'terminal_loop_end_to_end_contract_schema' => (string) data_get($result, 'terminal_loop_end_to_end_contract.schema_version'),
                'terminal_loop_end_to_end_contract_status' => (string) data_get($result, 'terminal_loop_end_to_end_contract.status'),
                'terminal_loop_end_to_end_contract_covered_capabilities' => (array) data_get($result, 'terminal_loop_end_to_end_contract.covered_capabilities', []),
                'terminal_loop_end_to_end_contract_all_required_surfaces_present' => (bool) data_get($result, 'terminal_loop_end_to_end_contract.all_required_surfaces_present'),
                'terminal_loop_end_to_end_contract_failed_check_ids' => (array) data_get($result, 'terminal_loop_end_to_end_contract.failed_check_ids', []),
                'terminal_loop_end_to_end_contract_check_count' => (int) data_get($result, 'terminal_loop_end_to_end_contract.check_count'),
                'terminal_loop_end_to_end_contract_passed_check_count' => (int) data_get($result, 'terminal_loop_end_to_end_contract.passed_check_count'),
                'terminal_loop_end_to_end_contract_next_safe_command' => (string) data_get($result, 'terminal_loop_end_to_end_contract.next_safe_command'),
                'terminal_loop_end_to_end_contract_next_safe_command_purpose' => (string) data_get($result, 'terminal_loop_end_to_end_contract.next_safe_command_purpose'),
                'terminal_loop_end_to_end_contract_resume_without_chat_history_command' => (string) data_get($result, 'terminal_loop_end_to_end_contract.resume_without_chat_history_command'),
                'terminal_loop_end_to_end_contract_can_execute' => (bool) data_get($result, 'terminal_loop_end_to_end_contract.can_execute_from_contract'),
                'terminal_loop_end_to_end_contract_can_replenish' => (bool) data_get($result, 'terminal_loop_end_to_end_contract.can_replenish_from_contract'),
                'terminal_loop_end_to_end_contract_can_recover' => (bool) data_get($result, 'terminal_loop_end_to_end_contract.can_recover_from_contract'),
                'terminal_loop_end_to_end_contract_can_claim' => (bool) data_get($result, 'terminal_loop_end_to_end_contract.can_claim_from_contract'),
                'terminal_loop_end_to_end_contract_can_complete' => (bool) data_get($result, 'terminal_loop_end_to_end_contract.can_complete_from_contract'),
                'terminal_loop_end_to_end_contract_can_call_provider' => (bool) data_get($result, 'terminal_loop_end_to_end_contract.can_call_provider_from_contract'),
                'terminal_loop_end_to_end_contract_can_spend_tokens' => (bool) data_get($result, 'terminal_loop_end_to_end_contract.can_spend_tokens_from_contract'),
                'terminal_loop_end_to_end_contract_hash' => (string) data_get($result, 'terminal_loop_end_to_end_contract.terminal_loop_end_to_end_contract_hash'),
                'terminal_loop_health_digest_hash' => (string) data_get($result, 'terminal_loop_health_digest_hash'),
            ],
        );
    }


    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneTerminalLoopOperationalProofStatus(array $options = []): array
    {
        $result = (new AgentControlPlaneTerminalLoopOperationalProofService)->prove([
            'actor' => $this->reservationActor($options),
            'proof_id' => (string) ($options['proof_id'] ?? ''),
        ]);
        $bindingExport = $this->persistTerminalLoopOperationalProofBinding($result, (bool) ($options['persist_terminal_loop_operational_proof_binding'] ?? false));

        return $this->wrapCertificationWorkbenchStatus(
            keyPrefix: 'terminal_loop_operational_proof',
            label: 'Terminal Loop Operational Proof',
            payload: $result,
            statusKey: 'status',
            extraStatusFields: [
                'actor' => (string) data_get($result, 'actor'),
                'proof_id' => (string) data_get($result, 'proof_id'),
                'task_packet_id' => (string) data_get($result, 'task_packet_id'),
                'lease_id' => (string) data_get($result, 'lease_id'),
                'prepare_event' => (string) data_get($result, 'prepare_event'),
                'claim_event' => (string) data_get($result, 'claim_event'),
                'completion_event' => (string) data_get($result, 'completion_event'),
                'auto_replenishment_status' => (string) data_get($result, 'auto_replenishment_status'),
                'auto_replenishment_generated_task_count' => (int) data_get($result, 'auto_replenishment_generated_task_count'),
                'auto_replenishment_hash' => (string) data_get($result, 'auto_replenishment_hash'),
                'bootstrap_status' => (string) data_get($result, 'bootstrap_status'),
                'one_shot_worker_packet_ready' => (bool) data_get($result, 'one_shot_worker_packet_ready'),
                'one_shot_packet_hash' => (string) data_get($result, 'one_shot_packet_hash'),
                'invariants_all_true' => (bool) data_get($result, 'invariants_all_true'),
                'violation_count' => (int) data_get($result, 'violation_count'),
                'completion_evidence_hash' => (string) data_get($result, 'completion_evidence_hash'),
                'completion_evidence_validation_hash' => (string) data_get($result, 'completion_evidence_validation_hash'),
                'post_cycle_evidence_rollup_status' => (string) data_get($result, 'post_cycle_evidence_rollup_status'),
                'post_cycle_completed_dry_run_task_count' => (int) data_get($result, 'post_cycle_completed_dry_run_task_count'),
                'post_cycle_valid_completion_evidence_count' => (int) data_get($result, 'post_cycle_valid_completion_evidence_count'),
                'post_cycle_claimed_task_count' => (int) data_get($result, 'post_cycle_claimed_task_count'),
                'post_cycle_active_lease_count' => (int) data_get($result, 'post_cycle_active_lease_count'),
                'post_cycle_recoverable_lease_count' => (int) data_get($result, 'post_cycle_recoverable_lease_count'),
                'post_cycle_cleanup_state' => (array) data_get($result, 'post_cycle_cleanup_state', []),
                'post_cycle_cycle_supervisor_status' => (string) data_get($result, 'post_cycle_cycle_supervisor_status'),
                'post_cycle_cycle_supervisor_cycle_state' => (string) data_get($result, 'post_cycle_cycle_supervisor_cycle_state'),
                'post_cycle_cycle_supervisor_next_command_purpose' => (string) data_get($result, 'post_cycle_cycle_supervisor_next_command_purpose'),
                'post_cycle_cycle_supervisor_hash' => (string) data_get($result, 'post_cycle_cycle_supervisor_hash'),
                'post_cycle_end_to_end_contract_status' => (string) data_get($result, 'post_cycle_end_to_end_contract_status'),
                'post_cycle_end_to_end_contract_all_required_surfaces_present' => (bool) data_get($result, 'post_cycle_end_to_end_contract_all_required_surfaces_present'),
                'post_cycle_end_to_end_contract_covered_capabilities' => (array) data_get($result, 'post_cycle_end_to_end_contract_covered_capabilities', []),
                'post_cycle_end_to_end_contract_failed_check_ids' => (array) data_get($result, 'post_cycle_end_to_end_contract_failed_check_ids', []),
                'post_cycle_end_to_end_contract_next_safe_command_purpose' => (string) data_get($result, 'post_cycle_end_to_end_contract_next_safe_command_purpose'),
                'post_cycle_end_to_end_contract_hash' => (string) data_get($result, 'post_cycle_end_to_end_contract_hash'),
                'resume_packet_schema' => (string) data_get($result, 'resume_packet.schema_version'),
                'resume_packet_mode' => (string) data_get($result, 'resume_packet.mode'),
                'resume_packet_proof_id' => (string) data_get($result, 'resume_packet.proof_id'),
                'resume_packet_queue_tags' => (array) data_get($result, 'resume_packet.queue_tags', []),
                'resume_packet_can_resume_without_chat_history' => (bool) data_get($result, 'resume_packet.can_resume_without_chat_history'),
                'resume_packet_safe_to_start_new_worker' => (bool) data_get($result, 'resume_packet.safe_to_start_new_worker'),
                'resume_packet_recommended_action' => (string) data_get($result, 'resume_packet.recommended_action'),
                'resume_packet_resume_rollup_status' => (string) data_get($result, 'resume_packet.resume_rollup_status'),
                'resume_packet_resume_attention_required' => (bool) data_get($result, 'resume_packet.resume_attention_required'),
                'resume_packet_resume_can_claim_from_rollup' => (bool) data_get($result, 'resume_packet.resume_can_claim_from_rollup'),
                'resume_packet_resume_can_recover_from_rollup' => (bool) data_get($result, 'resume_packet.resume_can_recover_from_rollup'),
                'resume_packet_resume_rollup_hash' => (string) data_get($result, 'resume_packet.resume_rollup_hash'),
                'resume_packet_operator_handoff_status' => (string) data_get($result, 'resume_packet.operator_handoff_status'),
                'resume_packet_operator_handoff_next_action' => (string) data_get($result, 'resume_packet.operator_handoff_next_action'),
                'resume_packet_operator_handoff_hash' => (string) data_get($result, 'resume_packet.operator_handoff_hash'),
                'resume_packet_health_digest_hash' => (string) data_get($result, 'resume_packet.health_digest_hash'),
                'resume_packet_commands' => (array) data_get($result, 'resume_packet.resume_commands', []),
                'resume_packet_command_keys' => array_keys((array) data_get($result, 'resume_packet.resume_commands', [])),
                'resume_packet_next_cycle_certificate' => (array) data_get($result, 'resume_packet.next_cycle_certificate', []),
                'resume_packet_next_cycle_status' => (string) data_get($result, 'resume_packet.next_cycle_certificate.status'),
                'resume_packet_next_safe_command_key' => (string) data_get($result, 'resume_packet.next_cycle_certificate.next_safe_command_key'),
                'resume_packet_all_commands_lane_bound' => (bool) data_get($result, 'resume_packet.next_cycle_certificate.all_commands_lane_bound'),
                'resume_packet_forbidden_actions' => (array) data_get($result, 'resume_packet.forbidden_actions', []),
                'resume_packet_forbidden_action_count' => count((array) data_get($result, 'resume_packet.forbidden_actions', [])),
                'resume_packet_hash' => (string) data_get($result, 'resume_packet_hash'),
                'recovery_resume_proof_status' => (string) data_get($result, 'recovery_resume_proof.status'),
                'recovery_resume_safe_next_action' => (string) data_get($result, 'recovery_resume_proof.resume_safe_next_action'),
                'recovery_resume_recoverability_before_recovery' => (string) data_get($result, 'recovery_resume_proof.recoverability_before_recovery'),
                'recovery_resume_claim_event' => (string) data_get($result, 'recovery_resume_proof.resume_claim_event'),
                'recovery_resume_completion_event' => (string) data_get($result, 'recovery_resume_proof.resume_completion_event'),
                'recovery_resume_proof_hash' => (string) data_get($result, 'recovery_resume_proof_hash'),
                'validation_rejection_proof_status' => (string) data_get($result, 'validation_rejection_proof.status'),
                'validation_rejection_hash_mismatch_event' => (string) data_get($result, 'validation_rejection_proof.hash_mismatch_completion_event'),
                'validation_rejection_hash_mismatch_reason' => (string) data_get($result, 'validation_rejection_proof.hash_mismatch_completion_reason'),
                'validation_rejection_hash_mismatch_blocked_by_hash' => (bool) data_get($result, 'validation_rejection_proof.hash_mismatch_blocked_by_hash'),
                'validation_rejection_invalid_completion_event' => (string) data_get($result, 'validation_rejection_proof.invalid_completion_event'),
                'validation_rejection_invalid_completion_reason' => (string) data_get($result, 'validation_rejection_proof.invalid_completion_reason'),
                'validation_rejection_blocked_by_scope' => (bool) data_get($result, 'validation_rejection_proof.invalid_completion_blocked_by_scope'),
                'validation_rejection_valid_completion_event' => (string) data_get($result, 'validation_rejection_proof.valid_completion_event'),
                'validation_rejection_proof_hash' => (string) data_get($result, 'validation_rejection_proof_hash'),
                'fleet_concurrency_proof_status' => (string) data_get($result, 'fleet_concurrency_proof.status'),
                'fleet_concurrency_agent_count' => (int) data_get($result, 'fleet_concurrency_proof.agent_count'),
                'fleet_concurrency_distinct_task_total' => (int) data_get($result, 'fleet_concurrency_proof.distinct_task_total'),
                'fleet_concurrency_distinct_lease_total' => (int) data_get($result, 'fleet_concurrency_proof.distinct_lease_total'),
                'fleet_concurrency_write_set_collision_count' => (int) data_get($result, 'fleet_concurrency_proof.write_set_collision_count'),
                'fleet_concurrency_cleanup_green' => (bool) data_get($result, 'fleet_concurrency_proof.cleanup_left_no_recoverable_artifacts'),
                'fleet_concurrency_proof_hash' => (string) data_get($result, 'fleet_concurrency_proof_hash'),
                'partial_supply_launch_gate_proof_status' => (string) data_get($result, 'partial_supply_launch_gate_proof.status'),
                'partial_supply_launch_blocked' => (bool) data_get($result, 'partial_supply_launch_gate_proof.partial_supply_launch_blocked'),
                'partial_supply_replenishment_plan_ready' => (bool) data_get($result, 'partial_supply_launch_gate_proof.replenishment_plan_ready'),
                'partial_supply_cycle_supervisor_replenishes_before_launch' => (bool) data_get($result, 'partial_supply_launch_gate_proof.cycle_supervisor_replenishes_before_launch'),
                'partial_supply_post_cleanup_claimable_count' => (int) data_get($result, 'partial_supply_launch_gate_proof.post_cleanup_claimable_count'),
                'partial_supply_launch_gate_proof_hash' => (string) data_get($result, 'partial_supply_launch_gate_proof_hash'),
                'partial_supply_bootstrap_gate_proof_status' => (string) data_get($result, 'partial_supply_bootstrap_gate_proof.status'),
                'partial_supply_bootstrap_status' => (string) data_get($result, 'partial_supply_bootstrap_gate_proof.bootstrap_status'),
                'partial_supply_bootstrap_claim_event' => (string) data_get($result, 'partial_supply_bootstrap_gate_proof.claim_event'),
                'partial_supply_bootstrap_runtime_claim_persisted' => (bool) data_get($result, 'partial_supply_bootstrap_gate_proof.runtime_claim_persisted'),
                'partial_supply_bootstrap_active_lease_count_after_bootstrap' => (int) data_get($result, 'partial_supply_bootstrap_gate_proof.active_lease_count_after_bootstrap'),
                'partial_supply_bootstrap_post_cleanup_claimable_count' => (int) data_get($result, 'partial_supply_bootstrap_gate_proof.post_cleanup_claimable_count'),
                'partial_supply_bootstrap_gate_proof_hash' => (string) data_get($result, 'partial_supply_bootstrap_gate_proof_hash'),
                'operational_readiness_matrix_all_true' => (bool) data_get($result, 'operational_readiness_matrix.all_true'),
                'operational_readiness_matrix_row_count' => (int) data_get($result, 'operational_readiness_matrix.row_count'),
                'operational_readiness_matrix_failed_row_count' => (int) data_get($result, 'operational_readiness_matrix.failed_row_count'),
                'operational_readiness_matrix_hash' => (string) data_get($result, 'operational_readiness_matrix_hash'),
                'completion_real_allowed' => (bool) data_get($result, 'completion_real_allowed'),
                'runtime_execution_allowed' => (bool) data_get($result, 'runtime_execution_allowed'),
                'dispatch_allowed' => (bool) data_get($result, 'dispatch_allowed'),
                'provider_call_allowed' => (bool) data_get($result, 'provider_call_allowed'),
                'token_spend_allowed' => (bool) data_get($result, 'token_spend_allowed'),
                'self_programming_allowed' => (bool) data_get($result, 'self_programming_allowed'),
                'completion_audit_binding_packet_status' => (string) data_get($result, 'completion_audit_binding_packet.status'),
                'completion_audit_binding_packet_audit_option_key' => (string) data_get($result, 'completion_audit_binding_packet.audit_option_key'),
                'completion_audit_binding_packet_expected_completion_audit_command' => (string) data_get($result, 'completion_audit_binding_packet.expected_completion_audit_command'),
                'completion_audit_binding_packet_diagnostic_completion_audit_command' => (string) data_get($result, 'completion_audit_binding_packet.diagnostic_completion_audit_command'),
                'completion_audit_binding_packet_proof_payload_hash' => (string) data_get($result, 'completion_audit_binding_packet.proof_payload_hash'),
                'completion_audit_binding_packet_hash' => (string) data_get($result, 'completion_audit_binding_packet_hash'),
                'completion_audit_binding_export_requested' => (bool) data_get($bindingExport, 'requested', false),
                'completion_audit_binding_export_status' => (string) data_get($bindingExport, 'status', ''),
                'completion_audit_binding_export_path' => (string) data_get($bindingExport, 'path', ''),
                'completion_audit_binding_export_absolute_path' => (string) data_get($bindingExport, 'absolute_path', ''),
                'completion_audit_binding_export_hash' => (string) data_get($bindingExport, 'hash', ''),
                'completion_audit_binding_export_write_performed' => (bool) data_get($bindingExport, 'write_performed', false),
                'completion_audit_binding_export_audit_command' => (string) data_get($bindingExport, 'audit_command', ''),
                'terminal_loop_operational_proof_hash' => (string) data_get($result, 'terminal_loop_operational_proof_hash'),
            ],
        );
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, model?: string|null, input_tokens?: int|string|null, output_tokens?: int|string|null, cost_usd?: float|string|null}  $options
     * @return array<string, mixed>
     */
    public function agentCostEvent(array $options = []): array
    {
        if (! $this->agentControlPlaneRuntimeSchemaReady()
            || ! Schema::hasTable('atlas_self_construction_agent_cost_events')) {
            $costEvent = [
                'status' => 'blocked',
                'blocking_reasons' => ['agent_control_plane_cost_event_schema_missing'],
                'required_migration' => 'database/migrations/2026_05_12_010000_create_atlas_self_construction_agent_control_plane_tables.php',
                'agent_runs_table_ready' => Schema::hasTable('atlas_self_construction_agent_runs'),
                'heartbeats_table_ready' => Schema::hasTable('atlas_self_construction_agent_heartbeats'),
                'cost_events_table_ready' => Schema::hasTable('atlas_self_construction_agent_cost_events'),
            ];

            return [
                'schema_version' => 'atlas.self_construction_agent_cost_event.v1',
                'status' => 'blocked',
                'mode' => 'controlled_agent_cost_event_writer',
                'execution_allowed' => false,
                'dispatch_allowed' => false,
                'ledger_write_allowed' => false,
                'runtime_write_allowed' => false,
                'cost_event' => $costEvent,
                'cost_event_hash' => $this->stableHash($costEvent),
                'human_summary' => 'Agent cost event is blocked until the Agent Control Plane cost event runtime table exists.',
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
            $costEvent = [
                'status' => 'blocked',
                'blocking_reasons' => ['agent_run_not_found_for_actor_session'],
                'actor' => $actor,
                'session' => $session,
                'packet_id' => $packetId ?: null,
            ];

            return [
                'schema_version' => 'atlas.self_construction_agent_cost_event.v1',
                'status' => 'blocked',
                'mode' => 'controlled_agent_cost_event_writer',
                'execution_allowed' => false,
                'dispatch_allowed' => false,
                'ledger_write_allowed' => false,
                'runtime_write_allowed' => false,
                'cost_event' => $costEvent,
                'cost_event_hash' => $this->stableHash($costEvent),
                'human_summary' => 'Agent cost event is blocked because no synced run exists for this actor/session.',
            ];
        }

        $inputTokens = max(0, (int) ($options['input_tokens'] ?? 0));
        $outputTokens = max(0, (int) ($options['output_tokens'] ?? 0));
        $costUsd = max(0.0, (float) ($options['cost_usd'] ?? 0));
        $occurredAt = now();
        $costModel = $run->costEvents()->create([
            'cost_event_key' => 'COST-'.strtoupper(substr(hash('sha256', $run->id.'|'.$occurredAt->toIso8601String().'|'.$inputTokens.'|'.$outputTokens.'|'.$costUsd), 0, 24)),
            'provider' => (string) $run->provider,
            'model' => trim((string) ($options['model'] ?? '')) ?: null,
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'cost_usd' => $costUsd,
            'occurred_at' => $occurredAt,
            'metadata' => [
                'packet_id' => $run->packet_id,
                'actor' => $run->actor,
                'session' => $run->session_id,
                'source' => 'agent_control_plane_cost_event_writer',
            ],
        ]);
        $run->forceFill([
            'input_tokens' => ((int) ($run->input_tokens ?? 0)) + $inputTokens,
            'output_tokens' => ((int) ($run->output_tokens ?? 0)) + $outputTokens,
            'cost_usd' => ((float) ($run->cost_usd ?? 0)) + $costUsd,
        ])->save();

        $costEvent = [
            'status' => 'recorded',
            'run_id' => $run->id,
            'run_key' => $run->run_key,
            'packet_id' => $run->packet_id,
            'actor' => $run->actor,
            'provider' => $run->provider,
            'model' => $costModel->model,
            'session' => $run->session_id,
            'cost_event_id' => $costModel->id,
            'cost_event_key' => $costModel->cost_event_key,
            'input_tokens' => $costModel->input_tokens,
            'output_tokens' => $costModel->output_tokens,
            'cost_usd' => $costModel->cost_usd,
            'run_input_tokens_total' => $run->input_tokens,
            'run_output_tokens_total' => $run->output_tokens,
            'run_cost_usd_total' => $run->cost_usd,
            'occurred_at' => $costModel->occurred_at?->toIso8601String(),
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_cost_event.v1',
            'status' => 'agent_cost_event_recorded',
            'mode' => 'controlled_agent_cost_event_writer',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => true,
            'cost_event' => $costEvent,
            'cost_event_hash' => $this->stableHash($costEvent),
            'non_execution_guarantees' => [
                'agent_cost_event_does_not_start_providers',
                'agent_cost_event_does_not_claim_packets',
                'agent_cost_event_does_not_dispatch_work',
            ],
            'human_summary' => 'Agent cost event was recorded for an existing synced run without dispatching or executing provider work.',
        ];
    }

}
