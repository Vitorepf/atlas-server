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
 * AUTOMATIC DISPATCH BATCH2 projection sub-section 04 of 05, sub-split from the
 * god {@see \App\Services\Ai\SelfConstruction\Readiness\ReadinessProjectionAgentAutomaticDispatchBatch2Section}
 * (GOD-DEBULK). Method bodies are byte-identical to the parent Section; the
 * only rewrite is that sibling pipeline calls and mother back-calls route
 * through the injected Section facade (`$this->section->*`), whose __call
 * re-dispatches to the owning sub-section or forwards to the mother verbatim.
 *
 * Stage range: agentAutomaticDispatchSchedulerOneShotTickProviderSpecificExecutionContractRelease
 *           .. agentAutomaticDispatchSchedulerOneShotTickProviderStartDriverReleaseContract
 */
final class DispatchBatch2Part04SubSection
{
    public function __construct(
        private readonly ReadinessProjectionAgentAutomaticDispatchBatch2Section $section,
    ) {}

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickProviderSpecificExecutionContractRelease(array $options = []): array
    {
        $guardStatusPayload = $this->section->agentAutomaticDispatchSchedulerOneShotTickProviderAdapterExecutionGuardStatus($options);
        $guardStatus = (array) data_get($guardStatusPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_provider_adapter_execution_guard_status', []);
        $codexContractPayload = $this->section->agentCodexProviderExecutionContractTemplate($options);
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
            'agent_automatic_dispatch_scheduler_one_shot_tick_provider_specific_execution_contract_release_hash' => $this->section->stableHash($contract),
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
        $contractPayload = $this->section->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartDispatchReceiptUseExecutorContract($options);
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
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_dispatch_receipt_use_executor_preflight_hash' => $this->section->stableHash($preflight),
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
        $preflightPayload = $this->section->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartGuardedProcessStartExecutorGatePreflight($options);
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
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_guarded_process_start_executor_gate_implementation_packet_hash' => $this->section->stableHash($packet),
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
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_plan_status_hash' => $this->section->stableHash($status),
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
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_fresh_release_gate_status_hash' => $this->section->stableHash($status),
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
        $preflightPayload = $this->section->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartSupervisedStartActivationGatePreflight($options);
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
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_supervised_start_activation_gate_implementation_packet_hash' => $this->section->stableHash($packet),
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
            'agent_automatic_dispatch_scheduler_one_shot_tick_release_receipt_persistence_status_hash' => $this->section->stableHash($status),
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
        $releaseStatusPayload = $this->section->agentAutomaticDispatchSchedulerOneShotTickCodexProcessStartReleaseStatus($options);
        $releaseStatus = (array) data_get($releaseStatusPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_process_start_release_status', []);
        $supervisedPayload = $this->section->agentCodexSupervisedStartExecutorContractTemplate($options);
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
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_supervised_start_executor_release_contract_hash' => $this->section->stableHash($contract),
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
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_guarded_process_start_executor_status_hash' => $this->section->stableHash($status),
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
        $receiptUseStatusPayload = $this->section->agentAutomaticDispatchSchedulerOneShotTickDispatchReceiptUseStatus($options);
        $receiptUseStatus = (array) data_get($receiptUseStatusPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_dispatch_receipt_use_status', []);
        $providerStartPayload = $this->section->agentDispatchExecutorProviderStartDriverPreflight($options);
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
            'agent_automatic_dispatch_scheduler_one_shot_tick_provider_start_driver_release_contract_hash' => $this->section->stableHash($contract),
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


}
