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
 * AUTOMATIC DISPATCH BATCH2 projection sub-section 03 of 05, sub-split from the
 * god {@see \App\Services\Ai\SelfConstruction\Readiness\ReadinessProjectionAgentAutomaticDispatchBatch2Section}
 * (GOD-DEBULK). Method bodies are byte-identical to the parent Section; the
 * only rewrite is that sibling pipeline calls and mother back-calls route
 * through the injected Section facade (`$this->section->*`), whose __call
 * re-dispatches to the owning sub-section or forwards to the mother verbatim.
 *
 * Stage range: agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProcessStartReleaseGateStatus
 *           .. agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerActualProcessStartRehearsalExecutorContract
 */
final class DispatchBatch2Part03SubSection
{
    public function __construct(
        private readonly ReadinessProjectionAgentAutomaticDispatchBatch2Section $section,
    ) {}

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
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_start_release_gate_status_hash' => $this->section->stableHash($status),
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
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_supervised_start_executor_gate_status_hash' => $this->section->stableHash($status),
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
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_spawn_enablement_gate_status_hash' => $this->section->stableHash($status),
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
        $preflightPayload = $this->section->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartActualProcessStartRehearsalGatePreflight($options);
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
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_actual_process_start_rehearsal_gate_implementation_packet_hash' => $this->section->stableHash($packet),
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
        $spawnStatusPayload = $this->section->agentAutomaticDispatchSchedulerOneShotTickCodexFinalProcessSpawnExecutorStatus($options);
        $spawnStatus = (array) data_get($spawnStatusPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_final_process_spawn_executor_status', []);
        $runtimePayload = $this->section->agentCodexExternalProcessRuntimeDriverContractTemplate($options);

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
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_external_process_runtime_driver_contract_hash' => $this->section->stableHash($contract),
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
        $acceptanceStatusPayload = $this->section->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartEvidenceAcceptanceBridgeStatus($options);
        $acceptanceStatus = (array) data_get($acceptanceStatusPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_evidence_acceptance_bridge_status', []);
        $livenessPayload = $this->section->agentCodexRealInvokerPostStartLivenessMonitorContractTemplate($options);

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
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_liveness_monitor_contract_hash' => $this->section->stableHash($contract),
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
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_adapter_execution_guard_gate_status_hash' => $this->section->stableHash($status),
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
        $preflightPayload = $this->section->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartFinalProcessStartAuthorizationGatePreflight($options);
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
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_final_process_start_authorization_gate_implementation_packet_hash' => $this->section->stableHash($packet),
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
        $enablementStatusPayload = $this->section->agentAutomaticDispatchSchedulerOneShotTickCodexProcessSpawnEnablementStatus($options);
        $enablementStatus = (array) data_get($enablementStatusPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_process_spawn_enablement_status', []);
        $executorPayload = $this->section->agentCodexProcessSpawnExecutorContractTemplate($options);

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
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_final_process_spawn_executor_contract_hash' => $this->section->stableHash($contract),
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
        $authorizationStatusPayload = $this->section->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerFinalProcessStartAuthorizationGateStatus($options);
        $authorizationStatus = (array) data_get($authorizationStatusPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_final_process_start_authorization_gate_status', []);
        $rehearsalPayload = $this->section->agentCodexRealInvokerActualProcessStartRehearsalExecutorContractTemplate($options);

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
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_actual_process_start_rehearsal_executor_contract_hash' => $this->section->stableHash($contract),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_actual_process_start_rehearsal_executor_contract_does_not_rehearse_actual_start',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_actual_process_start_rehearsal_executor_contract_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_actual_process_start_rehearsal_executor_contract_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_actual_process_start_rehearsal_executor_contract_does_not_enable_self_programming',
            ],
            'human_summary' => 'Automatic dispatch scheduler one-shot tick Codex real invoker actual process start rehearsal contract is ready; it rehearses process-start inputs but still cannot start Codex.',
        ];
    }


}
