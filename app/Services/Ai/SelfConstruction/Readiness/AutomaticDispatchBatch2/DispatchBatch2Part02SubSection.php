<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Readiness\AutomaticDispatchBatch2;

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
use App\Services\Ai\SelfConstruction\Readiness\ReadinessProjectionAgentAutomaticDispatchBatch2Section;

/**
 * AUTOMATIC DISPATCH BATCH2 projection sub-section 02 of 05, sub-split from the
 * god {@see \App\Services\Ai\SelfConstruction\Readiness\ReadinessProjectionAgentAutomaticDispatchBatch2Section}
 * (GOD-DEBULK). Method bodies are byte-identical to the parent Section; the
 * only rewrite is that sibling pipeline calls and mother back-calls route
 * through the injected Section facade (`$this->section->*`), whose __call
 * re-dispatches to the owning sub-section or forwards to the mother verbatim.
 *
 * Stage range: agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartAdapterExecutionGuardGatePreflight
 *           .. agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartAdapterInvocationBoundaryGateStatus
 */
final class DispatchBatch2Part02SubSection
{
    public function __construct(
        private readonly ReadinessProjectionAgentAutomaticDispatchBatch2Section $section,
    ) {}

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartAdapterExecutionGuardGatePreflight(array $options = []): array
    {
        $contractPayload = $this->section->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartAdapterExecutionGuardGateContract($options);
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
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_adapter_execution_guard_gate_preflight_hash' => $this->section->stableHash($preflight),
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
        $preflightPayload = $this->section->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartStartExecutionGatePreflight($options);
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
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_start_execution_gate_implementation_packet_hash' => $this->section->stableHash($packet),
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
            'agent_automatic_dispatch_scheduler_one_shot_tick_guarded_runtime_invocation_status_hash' => $this->section->stableHash($status),
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
        $runtimeStatusPayload = $this->section->agentAutomaticDispatchSchedulerOneShotTickCodexExternalProcessRuntimeDriverStatus($options);
        $runtimeStatus = (array) data_get($runtimeStatusPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_external_process_runtime_driver_status', []);
        $authorizationPayload = $this->section->agentCodexExternalProcessInvocationAuthorizationContractTemplate($options);

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
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_process_invocation_authorization_contract_hash' => $this->section->stableHash($contract),
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
        $handoffStatusPayload = $this->section->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerOperatorStartHandoffStatus($options);
        $handoffStatus = (array) data_get($handoffStatusPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_operator_start_handoff_status', []);
        $receiptPayload = $this->section->agentCodexRealInvokerPostStartReceiptContractBuilderContractTemplate($options);

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
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_receipt_contract_hash' => $this->section->stableHash($contract),
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
        $livenessStatusPayload = $this->section->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartLivenessMonitorStatus($options);
        $livenessStatus = (array) data_get($livenessStatusPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_liveness_monitor_status', []);
        $dispatchReleasePayload = $this->section->agentCodexRealInvokerPostStartDispatchReleaseGateContractTemplate($options);

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
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_dispatch_release_gate_contract_hash' => $this->section->stableHash($contract),
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
        $contractPayload = $this->section->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartRealInvokerReleasePreflightGateContract($options);
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
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_real_invoker_release_preflight_gate_preflight_hash' => $this->section->stableHash($preflight),
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
        $contractPayload = $this->section->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartSignedRealInvokerReleaseGateContract($options);
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
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_signed_real_invoker_release_gate_preflight_hash' => $this->section->stableHash($preflight),
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
        $preflightPayload = $this->section->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProcessStartEnvelopeGatePreflight($options);
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
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_start_envelope_gate_implementation_packet_hash' => $this->section->stableHash($packet),
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
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_adapter_invocation_boundary_gate_status_hash' => $this->section->stableHash($status),
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


}
