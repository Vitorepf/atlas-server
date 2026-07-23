<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Readiness\AutomaticDispatchBatch1;

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
use App\Services\Ai\SelfConstruction\Readiness\ReadinessProjectionAgentAutomaticDispatchBatch1Section;

/**
 * DISPATCH BATCH 1 projection sub-section 01 of 5, sub-split from the god
 * {@see \App\Services\Ai\SelfConstruction\Readiness\ReadinessProjectionAgentAutomaticDispatchBatch1Section}
 * (GOD-DEBULK). Method bodies are byte-identical to the parent Section; the
 * only rewrite is that sibling/back calls route through the injected Section
 * facade ($this->section->*), which re-dispatches to whichever sub-section
 * owns the target method or forwards to the bound mother via the facade
 * __call.
 */
final class DispatchBatch1Part01SubSection
{
    public function __construct(
        private readonly ReadinessProjectionAgentAutomaticDispatchBatch1Section $section,
    ) {}

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProcessStartEnvelopeGatePreflight(array $options = []): array
    {
        $contractPayload = $this->section->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProcessStartEnvelopeGateContract($options);
        $contract = (array) data_get($contractPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_start_envelope_gate_contract', []);
        $contractHash = (string) data_get($contractPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_start_envelope_gate_contract_hash');
        $gateReady = class_exists(AgentCodexRealInvokerPostStartProcessStartEnvelopeGate::class)
            && method_exists(AgentCodexRealInvokerPostStartProcessStartEnvelopeGate::class, 'buildPostStartProcessStartEnvelope');
        $invokerReady = class_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProcessStartEnvelopeGateInvoker::class)
            && method_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProcessStartEnvelopeGateInvoker::class, 'prepareCodexRealInvokerPostStartProcessStartEnvelopeGate');
        $envelopeBuilderReady = class_exists(AgentCodexRealInvokerProcessStartEnvelopeBuilder::class)
            && method_exists(AgentCodexRealInvokerProcessStartEnvelopeBuilder::class, 'buildStartEnvelope');
        $rehearsalReady = class_exists(AgentCodexRealInvokerPostStartActualProcessStartRehearsalGate::class)
            && method_exists(AgentCodexRealInvokerPostStartActualProcessStartRehearsalGate::class, 'rehearsePostStartActualProcessStart');
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerTableReady = Schema::hasTable('atlas_ledger_events');

        $checks = [
            'post_start_process_start_envelope_gate_contract_ready' => data_get($contractPayload, 'status') === 'one_shot_tick_codex_real_invoker_post_start_process_start_envelope_gate_contract_ready',
            'post_start_process_start_envelope_gate_contract_hash_present' => $contractHash !== '',
            'post_start_actual_process_start_rehearsal_gate_status_ready' => data_get($contract, 'source_codex_real_invoker_post_start_actual_process_start_rehearsal_gate_status') === 'one_shot_tick_codex_real_invoker_post_start_actual_process_start_rehearsal_gate_service_ready',
            'generic_post_start_process_start_envelope_gate_template_ready' => data_get($contract, 'source_codex_real_invoker_post_start_process_start_envelope_gate_contract_status') === 'codex_real_invoker_post_start_process_start_envelope_gate_contract_template_ready',
            'generic_process_start_envelope_builder_template_ready' => data_get($contract, 'source_codex_real_invoker_process_start_envelope_builder_contract_status') === 'codex_real_invoker_process_start_envelope_builder_contract_template_ready',
            'codex_real_invoker_post_start_process_start_envelope_gate_ready' => $gateReady,
            'codex_real_invoker_post_start_process_start_envelope_gate_invoker_ready' => $invokerReady,
            'codex_real_invoker_process_start_envelope_builder_ready' => $envelopeBuilderReady,
            'codex_real_invoker_post_start_actual_process_start_rehearsal_gate_ready' => $rehearsalReady,
            'canonical_post_start_process_start_envelope_gate_method_ready' => data_get($contract, 'process_start_envelope.canonical_post_start_process_start_envelope_gate_method') === 'buildPostStartProcessStartEnvelope',
            'scheduler_invoker_method_ready' => data_get($contract, 'process_start_envelope.scheduler_invoker_method') === 'prepareCodexRealInvokerPostStartProcessStartEnvelopeGate',
            'contract_requires_post_start_actual_process_start_rehearsal' => data_get($contract, 'process_start_envelope.post_start_actual_process_start_rehearsal_required_before_envelope') === true,
            'contract_requires_post_start_evidence_acceptance_bridge' => data_get($contract, 'process_start_envelope.post_start_evidence_acceptance_bridge_required_before_envelope') === true,
            'contract_requires_provider_start_rehearsal' => data_get($contract, 'process_start_envelope.provider_start_rehearsal_required_before_envelope') === true,
            'contract_delegates_to_codex_real_invoker_process_start_envelope_builder' => data_get($contract, 'process_start_envelope.gate_delegates_to_codex_real_invoker_process_start_envelope_builder') === true,
            'contract_declares_envelope_is_not_actual_process_start' => data_get($contract, 'process_start_envelope.envelope_is_not_actual_process_start') === true,
            'contract_requires_start_execution_gate_after_envelope' => in_array('execute_start_without_separate_start_execution_gate_contract', (array) data_get($contract, 'forbidden_even_after_contract', []), true),
            'contract_builds_envelope' => data_get($contract, 'process_start_envelope.process_start_envelope_built_by_contract') === true,
            'contract_keeps_actual_process_start_disabled' => data_get($contract, 'process_start_envelope.actual_process_start_allowed_by_contract') === false,
            'contract_keeps_process_started_false' => data_get($contract, 'process_start_envelope.process_started_by_contract') === false,
            'contract_keeps_adapter_execution_disabled' => data_get($contract, 'process_start_envelope.adapter_execution_allowed_by_contract') === false,
            'contract_does_not_dispatch_work' => data_get($contract, 'process_start_envelope.dispatch_allowed_by_contract') === false,
            'contract_does_not_spend_tokens' => data_get($contract, 'process_start_envelope.token_spend_allowed_by_contract') === false,
            'contract_lists_runtime_enabling_flags_as_forbidden' => in_array('actual_process_start_allowed', (array) data_get($contract, 'forbidden_true_input_flags', []), true)
                && in_array('dispatch_allowed', (array) data_get($contract, 'forbidden_true_input_flags', []), true)
                && in_array('process_started', (array) data_get($contract, 'forbidden_true_input_flags', []), true)
                && in_array('self_programming_allowed', (array) data_get($contract, 'forbidden_true_input_flags', []), true),
            'agent_runs_table_ready' => $runsTableReady,
            'ledger_table_ready' => $ledgerTableReady,
            'self_programming_forbidden' => in_array('enable_self_programming', (array) data_get($contract, 'forbidden_even_after_contract', []), true),
        ];
        $blockingReasons = array_values(array_keys(array_filter($checks, static fn (bool $passed): bool => ! $passed)));

        $preflight = [
            'status' => $blockingReasons === [] ? 'one_shot_tick_codex_real_invoker_post_start_process_start_envelope_gate_preflight_ready' : 'blocked',
            'preflight_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-REAL-INVOKER-POST-START-PROCESS-START-ENVELOPE-GATE-PREFLIGHT-SELF-CONSTRUCTION-0001',
            'source_post_start_process_start_envelope_gate_contract_hash' => $contractHash,
            'preflight_checks' => $checks,
            'blocking_count' => count($blockingReasons),
            'blocking_reasons' => $blockingReasons,
            'implementation_requirements' => [
                'create_scheduler_specific_post_start_process_start_envelope_gate_invoker',
                'delegate_to_agent_codex_real_invoker_post_start_process_start_envelope_gate',
                'require_post_start_actual_process_start_rehearsal_metadata',
                'require_post_start_evidence_acceptance_bridge',
                'require_process_start_envelope_hash',
                'require_start_command_hash',
                'require_start_environment_hash',
                'require_start_cwd_hash',
                'require_start_supervisor_hash',
                'require_start_liveness_contract_hash',
                'reject_caller_inputs_that_try_to_enable_runtime_flags',
                'project_readiness_status_without_invoking_codex',
            ],
            'runtime_policy' => [
                'preflight_is_read_only' => true,
                'post_start_process_start_envelope_gate_call_allowed_by_future_invoker' => true,
                'process_start_envelope_built_after_future_invoker' => true,
                'start_execution_gate_required_after_envelope' => true,
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
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_process_start_envelope_gate_implementation_packet',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_start_envelope_gate_preflight.v1',
            'status' => (string) $preflight['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_start_envelope_gate_preflight',
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
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_start_envelope_gate_preflight' => $preflight,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_start_envelope_gate_preflight_hash' => $this->section->stableHash($preflight),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_start_envelope_gate_preflight_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_start_envelope_gate_preflight_does_not_call_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_start_envelope_gate_preflight_does_not_execute_adapter',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_start_envelope_gate_preflight_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_start_envelope_gate_preflight_does_not_dispatch_work',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_start_envelope_gate_preflight_does_not_enable_self_programming',
            ],
            'human_summary' => $blockingReasons === []
                ? 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start process start envelope gate preflight is ready; start execution gate remains separate.'
                : 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start process start envelope gate preflight is blocked until rehearsal, envelope builder, storage and no-runtime prerequisites are ready.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickWriterContract(array $options = []): array
    {
        $dryRunTickPayload = $this->section->agentAutomaticDispatchSchedulerDryRunTick($options);
        $dryRunTick = (array) data_get($dryRunTickPayload, 'agent_automatic_dispatch_scheduler_dry_run_tick', []);
        $runtimeTables = $this->section->agentControlPlaneRuntimeTables();

        $componentReadiness = [
            'dry_run_tick' => data_get($dryRunTickPayload, 'status') === 'agent_automatic_dispatch_scheduler_dry_run_tick_ready',
            'wakeup_items_table' => $runtimeTables['atlas_self_construction_agent_wakeup_items'],
            'dispatch_receipts_table' => $runtimeTables['atlas_self_construction_agent_dispatch_receipts'],
            'agent_runs_table' => $runtimeTables['atlas_self_construction_agent_runs'],
            'candidate_selection_contract_available' => data_get($dryRunTick, 'queue_projection.selection_order') !== null,
            'dispatch_envelope_preview_contract_available' => array_key_exists('dispatch_envelope_preview', $dryRunTick),
        ];
        $blockingReasons = array_values(array_map(
            static fn (string $component): string => $component.'_not_ready',
            array_keys(array_filter($componentReadiness, static fn (bool $ready): bool => ! $ready))
        ));

        $contract = [
            'status' => $blockingReasons === [] ? 'agent_automatic_dispatch_scheduler_one_shot_tick_writer_contract_ready' : 'blocked',
            'contract_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-WRITER-CONTRACT-SELF-CONSTRUCTION-0001',
            'parent_program' => 'Atlas Self-Construction OS',
            'submodule' => 'Atlas Agent Control Plane',
            'source_dry_run_tick_hash' => data_get($dryRunTickPayload, 'agent_automatic_dispatch_scheduler_dry_run_tick_hash'),
            'component_readiness' => $componentReadiness,
            'blocking_count' => count($blockingReasons),
            'blocking_reasons' => $blockingReasons,
            'writer_scope' => [
                'max_wakeup_items_per_invocation' => 1,
                'max_dispatch_receipts_per_invocation' => 1,
                'allowed_wakeup_source_status' => 'queued',
                'new_wakeup_status_after_claim' => 'claimed',
                'new_dispatch_receipt_status' => 'signed_pending_dispatch',
                'provider_start_after_write' => false,
                'receipt_use_after_write' => false,
            ],
            'required_signed_release' => [
                'decision' => 'approve_scheduler_claim_and_receipt_once',
                'required_fields' => [
                    'signed_by',
                    'signed_at',
                    'expires_at',
                    'dry_run_tick_hash',
                    'selected_wakeup_key',
                    'dispatch_envelope_hash',
                    'max_wakeup_items',
                    'max_dispatch_receipts',
                    'reason',
                ],
                'idempotency_key' => 'sha256(selected_wakeup_key|packet_id|provider|dry_run_tick_hash|release_receipt_hash)',
                'expires_required' => true,
                'human_or_kernel_signature_required' => true,
            ],
            'allowed_mutations_after_future_release' => [
                'claim_selected_wakeup_item_atomically',
                'write_one_signed_pending_dispatch_receipt',
                'append_scheduler_tick_evidence',
                'stop_before_receipt_use',
                'stop_before_provider_start',
            ],
            'forbidden_mutations_even_after_release' => [
                'mark_dispatch_receipt_used',
                'start_provider_process',
                'invoke_provider_adapter',
                'mark_packet_completed',
                'merge_or_apply_work_products',
                'self_program_or_self_merge',
            ],
            'preflight_must_verify' => [
                'dry_run_tick_hash_matches_latest_projection',
                'selected_wakeup_item_is_still_queued',
                'selected_wakeup_item_is_still_ready',
                'packet_scope_has_no_active_conflicting_writer',
                'release_receipt_is_fresh_and_unexpired',
                'dispatch_envelope_hash_matches_candidate',
                'dispatch_receipt_dedupe_key_is_unused',
            ],
            'allowed_now' => [
                'one_shot_tick_writer_contract_projection',
                'dry_run_tick_contract_reference',
                'future_mutation_scope_projection',
            ],
            'forbidden_now' => [
                'claim_wakeup_item',
                'write_dispatch_receipt',
                'mark_dispatch_receipt_used',
                'start_provider_process',
                'invoke_provider_adapter',
                'spend_provider_tokens',
            ],
            'next_required_slice' => $blockingReasons === []
                ? 'activate_signed_one_shot_scheduler_tick_writer_preflight'
                : 'repair_signed_one_shot_scheduler_tick_writer_contract_blockers',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_writer_contract.v1',
            'status' => (string) $contract['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_writer_contract',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'claim_allowed' => false,
            'dispatch_receipt_write_allowed' => false,
            'provider_start_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_writer_contract' => $contract,
            'agent_automatic_dispatch_scheduler_one_shot_tick_writer_contract_hash' => $this->section->stableHash($contract),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_writer_contract_does_not_claim_wakeup_items',
                'agent_automatic_dispatch_scheduler_one_shot_tick_writer_contract_does_not_write_dispatch_receipts',
                'agent_automatic_dispatch_scheduler_one_shot_tick_writer_contract_does_not_mark_receipts_used',
                'agent_automatic_dispatch_scheduler_one_shot_tick_writer_contract_does_not_start_providers',
                'agent_automatic_dispatch_scheduler_one_shot_tick_writer_contract_does_not_enable_self_programming',
            ],
            'human_summary' => $blockingReasons === []
                ? 'Automatic dispatch scheduler one-shot tick writer contract is ready: Atlas has the bounded mutation contract for one wakeup claim and one signed pending receipt, but this command does not mutate runtime or start providers.'
                : 'Automatic dispatch scheduler one-shot tick writer contract is blocked until every dry-run and runtime prerequisite is ready.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartExecutorEnablementGateStatus(array $options = []): array
    {
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerTableReady = Schema::hasTable('atlas_ledger_events');
        $gateReady = class_exists(AgentCodexRealInvokerPostStartExecutorEnablementGate::class)
            && method_exists(AgentCodexRealInvokerPostStartExecutorEnablementGate::class, 'enablePostStartExecutor');
        $invokerReady = class_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartExecutorEnablementGateInvoker::class)
            && method_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartExecutorEnablementGateInvoker::class, 'enableCodexRealInvokerPostStartExecutorGate');
        $enablementReady = class_exists(AgentCodexRealInvokerExecutorEnablementGate::class)
            && method_exists(AgentCodexRealInvokerExecutorEnablementGate::class, 'enableExecutor');
        $freshReleaseReady = class_exists(AgentCodexRealInvokerPostStartExecutorFreshReleaseGate::class)
            && method_exists(AgentCodexRealInvokerPostStartExecutorFreshReleaseGate::class, 'authorizePostStartExecutorFreshRelease');

        $observedEnablementRunsQuery = $runsTableReady
            ? AtlasSelfConstructionAgentRun::query()
                ->whereNotNull('metadata->codex_real_invoker_post_start_executor_enablement->real_invoker_executor_enablement_id')
            : null;
        $providerRunsWithEnablementQuery = $runsTableReady
            ? AtlasSelfConstructionAgentRun::query()
                ->where('run_key', 'like', 'provider-start:%')
                ->where('status', 'adapter_invocation_prepared')
                ->whereNotNull('metadata->codex_real_invoker_executor_enablement->real_invoker_executor_enablement_id')
            : null;
        $latestObservedEnablement = $observedEnablementRunsQuery === null
            ? null
            : (clone $observedEnablementRunsQuery)->latest('updated_at')->first();

        $statusReady = $runsTableReady
            && $ledgerTableReady
            && $gateReady
            && $invokerReady
            && $enablementReady
            && $freshReleaseReady;
        $status = [
            'status' => $statusReady ? 'one_shot_tick_codex_real_invoker_post_start_executor_enablement_gate_service_ready' : 'blocked',
            'provider' => 'codex',
            'adapter' => 'codex',
            'invoker_service' => AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartExecutorEnablementGateInvoker::class,
            'invoker_service_ready' => $invokerReady,
            'invoker_canonical_method' => 'enableCodexRealInvokerPostStartExecutorGate',
            'generic_post_start_executor_enablement_gate_service' => AgentCodexRealInvokerPostStartExecutorEnablementGate::class,
            'generic_post_start_executor_enablement_gate_service_ready' => $gateReady,
            'generic_post_start_executor_enablement_gate_canonical_method' => 'enablePostStartExecutor',
            'codex_real_invoker_executor_enablement_gate_service' => AgentCodexRealInvokerExecutorEnablementGate::class,
            'codex_real_invoker_executor_enablement_gate_ready' => $enablementReady,
            'codex_real_invoker_executor_enablement_gate_canonical_method' => 'enableExecutor',
            'post_start_executor_fresh_release_gate_service' => AgentCodexRealInvokerPostStartExecutorFreshReleaseGate::class,
            'post_start_executor_fresh_release_gate_ready' => $freshReleaseReady,
            'agent_runs_table_ready' => $runsTableReady,
            'ledger_table_ready' => $ledgerTableReady,
            'codex_real_invoker_post_start_executor_enablement_recorded_run_count' => $observedEnablementRunsQuery === null ? null : (clone $observedEnablementRunsQuery)->count(),
            'provider_start_runs_with_codex_real_invoker_executor_enablement_count' => $providerRunsWithEnablementQuery === null ? null : (clone $providerRunsWithEnablementQuery)->count(),
            'latest_codex_real_invoker_post_start_executor_enablement' => $latestObservedEnablement instanceof AtlasSelfConstructionAgentRun
                ? [
                    'agent_run_id' => $latestObservedEnablement->id,
                    'run_key' => $latestObservedEnablement->run_key,
                    'packet_id' => $latestObservedEnablement->packet_id,
                    'provider' => $latestObservedEnablement->provider,
                    'status' => $latestObservedEnablement->status,
                    'post_start_executor_enablement_gate_id' => data_get($latestObservedEnablement->metadata, 'codex_real_invoker_post_start_executor_enablement.post_start_executor_enablement_gate_id'),
                    'post_start_executor_fresh_release_gate_id' => data_get($latestObservedEnablement->metadata, 'codex_real_invoker_post_start_executor_enablement.post_start_executor_fresh_release_gate_id'),
                    'post_start_evidence_acceptance_bridge_id' => data_get($latestObservedEnablement->metadata, 'codex_real_invoker_post_start_executor_enablement.post_start_evidence_acceptance_bridge_id'),
                    'real_invoker_executor_fresh_release_id' => data_get($latestObservedEnablement->metadata, 'codex_real_invoker_post_start_executor_enablement.real_invoker_executor_fresh_release_id'),
                    'real_invoker_executor_enablement_id' => data_get($latestObservedEnablement->metadata, 'codex_real_invoker_post_start_executor_enablement.real_invoker_executor_enablement_id'),
                    'codex_execution_id' => data_get($latestObservedEnablement->metadata, 'codex_real_invoker_post_start_executor_enablement.codex_execution_id'),
                    'real_invoker_executor_enabled' => data_get($latestObservedEnablement->metadata, 'codex_real_invoker_post_start_executor_enablement.real_invoker_executor_enabled'),
                    'executor_enabled' => data_get($latestObservedEnablement->metadata, 'codex_real_invoker_post_start_executor_enablement.executor_enabled'),
                    'supervised_start_required' => data_get($latestObservedEnablement->metadata, 'codex_real_invoker_post_start_executor_enablement.supervised_start_required'),
                    'actual_process_start_allowed' => data_get($latestObservedEnablement->metadata, 'codex_real_invoker_post_start_executor_enablement.actual_process_start_allowed'),
                    'token_spend_allowed' => data_get($latestObservedEnablement->metadata, 'codex_real_invoker_post_start_executor_enablement.token_spend_allowed'),
                ]
                : null,
            'runtime_policy' => [
                'status_projection_is_read_only' => true,
                'invoker_may_enable_executor_when_called_with_fresh_release_input' => true,
                'executor_enablement_is_not_real_process_invocation' => true,
                'supervised_start_required_before_process_start' => true,
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
                ? 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_supervised_start_activation_gate_contract'
                : 'repair_one_shot_scheduler_tick_codex_real_invoker_post_start_executor_enablement_gate_service_blockers',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_enablement_gate_status.v1',
            'status' => (string) $status['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_enablement_gate_status',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'post_start_executor_enablement_gate_allowed' => false,
            'actual_process_start_allowed' => false,
            'provider_process_call_allowed' => false,
            'executor_enabled' => false,
            'adapter_invocation_allowed' => false,
            'adapter_execution_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_enablement_gate_status' => $status,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_enablement_gate_status_hash' => $this->section->stableHash($status),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_enablement_gate_status_does_not_call_enablement_gate',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_enablement_gate_status_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_enablement_gate_status_does_not_execute_adapter',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_enablement_gate_status_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_enablement_gate_status_does_not_dispatch_work',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_enablement_gate_status_does_not_enable_self_programming',
            ],
            'human_summary' => $statusReady
                ? 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start executor enablement gate service is ready and inspectable; supervised start activation remains separate.'
                : 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start executor enablement gate service is blocked until invoker, generic enablement gate, fresh release gate and storage are ready.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerExecutorEnablementGateContract(array $options = []): array
    {
        $freshReleaseStatusPayload = $this->section->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerExecutorFreshReleaseGateStatus($options);
        $freshReleaseStatus = (array) data_get($freshReleaseStatusPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_fresh_release_gate_status', []);
        $enablementPayload = $this->section->agentCodexRealInvokerExecutorEnablementGateContractTemplate($options);

        $contract = [
            'status' => 'one_shot_tick_codex_real_invoker_executor_enablement_gate_contract_ready',
            'contract_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-REAL-INVOKER-EXECUTOR-ENABLEMENT-GATE-CONTRACT-SELF-CONSTRUCTION-0001',
            'parent_program' => 'Atlas Self-Construction OS',
            'submodule' => 'Atlas Agent Control Plane',
            'provider' => 'codex',
            'adapter' => 'codex',
            'source_codex_real_invoker_executor_fresh_release_gate_status' => data_get($freshReleaseStatus, 'status'),
            'source_codex_real_invoker_executor_fresh_release_gate_status_hash' => data_get($freshReleaseStatusPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_fresh_release_gate_status_hash'),
            'source_codex_real_invoker_executor_enablement_gate_contract_status' => data_get($enablementPayload, 'status'),
            'source_codex_real_invoker_executor_enablement_gate_contract_hash' => data_get($enablementPayload, 'codex_real_invoker_executor_enablement_gate_contract_template_hash'),
            'release_boundary' => [
                'canonical_executor_enablement_gate' => AgentCodexRealInvokerExecutorEnablementGate::class,
                'canonical_executor_enablement_gate_method' => 'enableExecutor',
                'scheduler_invoker' => AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerExecutorEnablementGateInvoker::class,
                'scheduler_invoker_method' => 'enableCodexRealInvokerExecutor',
                'enablement_effect' => 'enable_codex_real_invoker_executor_metadata_without_starting_process',
                'executor_enabled_by_enablement' => true,
                'external_process_started_by_enablement' => false,
                'provider_started_by_enablement' => false,
                'adapter_execution_allowed_by_enablement' => false,
                'token_spend_allowed_by_enablement' => false,
                'required_fresh_release_status_before_enablement' => 'real_invoker_executor_fresh_release_authorized_pending_enablement',
                'prepared_status_after_enablement' => 'real_invoker_executor_enabled_pending_supervised_start',
                'idempotency_key' => 'real_invoker_executor_enablement_id',
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
                'real_invoker_release_preflight_id',
                'signed_real_invoker_release_id',
                'real_invoker_implementation_boundary_id',
                'real_invoker_executor_plan_id',
                'real_invoker_executor_fresh_release_id',
                'real_invoker_executor_enablement_id',
                'operator_enablement_receipt_hash',
                'enablement_policy_hash',
                'pre_start_checklist_hash',
                'disable_switch_hash',
                'operator_fresh_release_receipt_hash',
                'plan_revalidation_report_hash',
                'freshness_window_hash',
                'final_human_signature_hash',
                'real_invoker_contract_hash',
                'release_policy_hash',
                'implementation_plan_hash',
                'executor_binary_contract_hash',
                'executor_observability_contract_hash',
                'process_command_hash',
                'environment_contract_hash',
                'termination_policy_hash',
                'stdout_stderr_sink_hash',
                'liveness_probe_hash',
                'rollback_plan_hash',
                'max_runtime_policy_hash',
                'actor',
                'session',
                'reason',
            ],
            'allowed_future_mutations' => [
                'write_codex_real_invoker_executor_enablement_metadata_on_agent_run',
                'append_codex_real_invoker_executor_enabled_evidence_event',
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
                'enablement_is_executor_metadata_not_process_start' => true,
                'supervised_start_activation_requires_separate_contract' => true,
                'operator_enablement_receipt_hash_required' => true,
                'enablement_policy_hash_required' => true,
                'pre_start_checklist_hash_required' => true,
                'disable_switch_hash_required' => true,
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_executor_enablement_gate_preflight',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_enablement_gate_contract.v1',
            'status' => (string) $contract['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_enablement_gate_contract',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_executor_enablement_gate_allowed' => false,
            'executor_enabled' => false,
            'adapter_execution_allowed' => false,
            'provider_start_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_enablement_gate_contract' => $contract,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_enablement_gate_contract_hash' => $this->section->stableHash($contract),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_enablement_gate_contract_does_not_enable_executor',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_enablement_gate_contract_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_enablement_gate_contract_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_enablement_gate_contract_does_not_enable_self_programming',
            ],
            'human_summary' => 'Automatic dispatch scheduler one-shot tick Codex real invoker executor enablement gate contract is ready; it defines executor enablement metadata but still cannot start Codex.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartImplementationBoundaryGateStatus(array $options = []): array
    {
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerTableReady = Schema::hasTable('atlas_ledger_events');
        $gateReady = class_exists(AgentCodexRealInvokerPostStartImplementationBoundaryGate::class)
            && method_exists(AgentCodexRealInvokerPostStartImplementationBoundaryGate::class, 'preparePostStartImplementationBoundary');
        $invokerReady = class_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartImplementationBoundaryGateInvoker::class)
            && method_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartImplementationBoundaryGateInvoker::class, 'prepareCodexRealInvokerPostStartImplementationBoundaryGate');
        $boundaryReady = class_exists(AgentCodexRealInvokerImplementationBoundary::class)
            && method_exists(AgentCodexRealInvokerImplementationBoundary::class, 'prepareBoundary');
        $signedReleaseReady = class_exists(AgentCodexRealInvokerPostStartSignedRealInvokerReleaseGate::class)
            && method_exists(AgentCodexRealInvokerPostStartSignedRealInvokerReleaseGate::class, 'authorizePostStartSignedRealInvokerRelease');

        $observedBoundaryRunsQuery = $runsTableReady
            ? AtlasSelfConstructionAgentRun::query()
                ->whereNotNull('metadata->codex_real_invoker_post_start_implementation_boundary->real_invoker_implementation_boundary_id')
            : null;
        $providerRunsWithBoundaryQuery = $runsTableReady
            ? AtlasSelfConstructionAgentRun::query()
                ->where('run_key', 'like', 'provider-start:%')
                ->where('status', 'adapter_invocation_prepared')
                ->whereNotNull('metadata->codex_real_invoker_implementation_boundary->real_invoker_implementation_boundary_id')
            : null;
        $latestObservedBoundary = $observedBoundaryRunsQuery === null
            ? null
            : (clone $observedBoundaryRunsQuery)->latest('updated_at')->first();

        $statusReady = $runsTableReady
            && $ledgerTableReady
            && $gateReady
            && $invokerReady
            && $boundaryReady
            && $signedReleaseReady;
        $status = [
            'status' => $statusReady ? 'one_shot_tick_codex_real_invoker_post_start_implementation_boundary_gate_service_ready' : 'blocked',
            'provider' => 'codex',
            'adapter' => 'codex',
            'invoker_service' => AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartImplementationBoundaryGateInvoker::class,
            'invoker_service_ready' => $invokerReady,
            'invoker_canonical_method' => 'prepareCodexRealInvokerPostStartImplementationBoundaryGate',
            'generic_post_start_implementation_boundary_gate_service' => AgentCodexRealInvokerPostStartImplementationBoundaryGate::class,
            'generic_post_start_implementation_boundary_gate_service_ready' => $gateReady,
            'generic_post_start_implementation_boundary_gate_canonical_method' => 'preparePostStartImplementationBoundary',
            'codex_real_invoker_implementation_boundary_service' => AgentCodexRealInvokerImplementationBoundary::class,
            'codex_real_invoker_implementation_boundary_ready' => $boundaryReady,
            'codex_real_invoker_implementation_boundary_canonical_method' => 'prepareBoundary',
            'post_start_signed_real_invoker_release_gate_service' => AgentCodexRealInvokerPostStartSignedRealInvokerReleaseGate::class,
            'post_start_signed_real_invoker_release_gate_ready' => $signedReleaseReady,
            'agent_runs_table_ready' => $runsTableReady,
            'ledger_table_ready' => $ledgerTableReady,
            'codex_real_invoker_post_start_implementation_boundary_recorded_run_count' => $observedBoundaryRunsQuery === null ? null : (clone $observedBoundaryRunsQuery)->count(),
            'provider_start_runs_with_codex_real_invoker_implementation_boundary_count' => $providerRunsWithBoundaryQuery === null ? null : (clone $providerRunsWithBoundaryQuery)->count(),
            'latest_codex_real_invoker_post_start_implementation_boundary' => $latestObservedBoundary instanceof AtlasSelfConstructionAgentRun
                ? [
                    'agent_run_id' => $latestObservedBoundary->id,
                    'run_key' => $latestObservedBoundary->run_key,
                    'packet_id' => $latestObservedBoundary->packet_id,
                    'provider' => $latestObservedBoundary->provider,
                    'status' => $latestObservedBoundary->status,
                    'post_start_implementation_boundary_gate_id' => data_get($latestObservedBoundary->metadata, 'codex_real_invoker_post_start_implementation_boundary.post_start_implementation_boundary_gate_id'),
                    'post_start_evidence_acceptance_bridge_id' => data_get($latestObservedBoundary->metadata, 'codex_real_invoker_post_start_implementation_boundary.post_start_evidence_acceptance_bridge_id'),
                    'post_start_signed_real_invoker_release_gate_id' => data_get($latestObservedBoundary->metadata, 'codex_real_invoker_post_start_implementation_boundary.post_start_signed_real_invoker_release_gate_id'),
                    'real_invoker_implementation_boundary_id' => data_get($latestObservedBoundary->metadata, 'codex_real_invoker_post_start_implementation_boundary.real_invoker_implementation_boundary_id'),
                    'signed_real_invoker_release_id' => data_get($latestObservedBoundary->metadata, 'codex_real_invoker_post_start_implementation_boundary.signed_real_invoker_release_id'),
                    'real_invoker_release_preflight_id' => data_get($latestObservedBoundary->metadata, 'codex_real_invoker_post_start_implementation_boundary.real_invoker_release_preflight_id'),
                    'dry_run_id' => data_get($latestObservedBoundary->metadata, 'codex_real_invoker_post_start_implementation_boundary.dry_run_id'),
                    'codex_execution_id' => data_get($latestObservedBoundary->metadata, 'codex_real_invoker_post_start_implementation_boundary.codex_execution_id'),
                    'real_invoker_implementation_boundary_prepared' => data_get($latestObservedBoundary->metadata, 'codex_real_invoker_post_start_implementation_boundary.real_invoker_implementation_boundary_prepared'),
                    'executor_plan_required' => data_get($latestObservedBoundary->metadata, 'codex_real_invoker_post_start_implementation_boundary.executor_plan_required'),
                    'actual_process_start_allowed' => data_get($latestObservedBoundary->metadata, 'codex_real_invoker_post_start_implementation_boundary.actual_process_start_allowed'),
                    'token_spend_allowed' => data_get($latestObservedBoundary->metadata, 'codex_real_invoker_post_start_implementation_boundary.token_spend_allowed'),
                ]
                : null,
            'runtime_policy' => [
                'status_projection_is_read_only' => true,
                'invoker_may_prepare_implementation_boundary_when_called_with_signed_input' => true,
                'implementation_boundary_is_not_real_invoker_execution' => true,
                'executor_plan_required_before_execution' => true,
                'atlas_process_invocation_allowed_here' => false,
                'actual_process_start_allowed_here' => false,
                'provider_process_call_allowed_here' => false,
                'adapter_invocation_allowed_here' => false,
                'adapter_execution_allowed_here' => false,
                'dispatch_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'self_programming_allowed_here' => false,
            ],
            'next_required_slice' => $statusReady
                ? 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_executor_plan_gate_contract'
                : 'repair_one_shot_scheduler_tick_codex_real_invoker_post_start_implementation_boundary_gate_service_blockers',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_implementation_boundary_gate_status.v1',
            'status' => (string) $status['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_implementation_boundary_gate_status',
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
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_implementation_boundary_gate_status' => $status,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_implementation_boundary_gate_status_hash' => $this->section->stableHash($status),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_implementation_boundary_gate_status_does_not_call_implementation_boundary_gate',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_implementation_boundary_gate_status_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_implementation_boundary_gate_status_does_not_execute_adapter',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_implementation_boundary_gate_status_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_implementation_boundary_gate_status_does_not_dispatch_work',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_implementation_boundary_gate_status_does_not_enable_self_programming',
            ],
            'human_summary' => $statusReady
                ? 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start implementation boundary gate service is ready and inspectable; executor plan remains separate.'
                : 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start implementation boundary gate service is blocked until invoker, generic boundary, signed release and storage are ready.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerRuntimeExecutionGate(array $options = []): array
    {
        $schedulerPolicyPayload = $this->section->agentAutomaticDispatchSchedulerPolicy($options);
        $dispatchPreflightPayload = $this->section->agentDispatchPreflight($options);
        $executorPreflightPayload = $this->section->agentDispatchExecutorPreflight($options);
        $runtimeTables = $this->section->agentControlPlaneRuntimeTables();

        $componentReadiness = [
            'automatic_dispatch_scheduler_policy' => data_get($schedulerPolicyPayload, 'status') === 'agent_automatic_dispatch_scheduler_policy_ready',
            'wakeup_items_table' => $runtimeTables['atlas_self_construction_agent_wakeup_items'],
            'dispatch_receipts_table' => $runtimeTables['atlas_self_construction_agent_dispatch_receipts'],
            'agent_runs_table' => $runtimeTables['atlas_self_construction_agent_runs'],
            'dispatch_preflight_contract_available' => in_array(data_get($dispatchPreflightPayload, 'status'), ['agent_dispatch_preflight_ready', 'blocked'], true),
            'dispatch_executor_preflight_contract_available' => in_array(data_get($executorPreflightPayload, 'status'), ['agent_dispatch_executor_preflight_ready', 'blocked'], true),
        ];
        $blockingReasons = array_values(array_map(
            static fn (string $component): string => $component.'_not_ready',
            array_keys(array_filter($componentReadiness, static fn (bool $ready): bool => ! $ready))
        ));

        $gate = [
            'status' => $blockingReasons === [] ? 'agent_automatic_dispatch_scheduler_runtime_execution_gate_ready' : 'blocked',
            'gate_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-RUNTIME-EXECUTION-GATE-SELF-CONSTRUCTION-0001',
            'parent_program' => 'Atlas Self-Construction OS',
            'submodule' => 'Atlas Agent Control Plane',
            'source_policy_hash' => data_get($schedulerPolicyPayload, 'agent_automatic_dispatch_scheduler_policy_hash'),
            'component_readiness' => $componentReadiness,
            'component_preflight_hashes' => [
                'automatic_dispatch_scheduler_policy' => data_get($schedulerPolicyPayload, 'agent_automatic_dispatch_scheduler_policy_hash'),
                'dispatch_preflight' => data_get($dispatchPreflightPayload, 'dispatch_preflight_hash'),
                'dispatch_executor_preflight' => data_get($executorPreflightPayload, 'dispatch_executor_preflight_hash'),
            ],
            'blocking_count' => count($blockingReasons),
            'blocking_reasons' => $blockingReasons,
            'required_runtime_release_receipt' => [
                'decision' => 'approve_scheduler_dry_run_tick_once',
                'signed_by' => 'human_or_atlas_kernel_after_policy_review',
                'scope' => 'one_tick_max_one_wakeup_item_no_provider_start',
                'expires_required' => true,
                'receipt_hash_required' => true,
            ],
            'runtime_tick_contract' => [
                'max_wakeup_items_per_tick' => 1,
                'allowed_tick_mode_after_future_release' => 'dry_run_only',
                'allowed_mutations_after_future_release' => [
                    'claim_one_ready_wakeup_item_atomically',
                    'prepare_dispatch_preflight',
                    'create_or_request_signed_dispatch_receipt',
                    'append_scheduler_decision_evidence',
                ],
                'forbidden_mutations_even_after_dry_run_release' => [
                    'start_provider_process',
                    'invoke_provider_adapter',
                    'mark_dispatch_receipt_used',
                    'mark_packet_completed',
                    'merge_or_apply_work_products',
                ],
            ],
            'gate_quality_guards' => [
                'runtime_release_must_be_one_shot',
                'scheduler_tick_must_be_idempotent',
                'tick_must_stop_before_provider_start',
                'tick_must_emit_evidence_before_any_future_dispatch',
                'tick_must_refuse_stale_wakeup_or_expired_receipt',
                'human_override_required_for_provider_start',
            ],
            'allowed_now' => [
                'runtime_execution_gate_projection',
                'scheduler_policy_readiness_check',
                'dispatch_preflight_contract_reference',
                'executor_preflight_contract_reference',
            ],
            'forbidden_now' => [
                'run_scheduler_tick',
                'claim_wakeup_items',
                'write_or_sign_dispatch_receipts',
                'mark_receipts_used',
                'start_or_supervise_provider_process',
                'invoke_provider_adapters',
                'self_program_or_self_merge',
            ],
            'activation_policy' => [
                'gate_is_read_only' => true,
                'scheduler_runtime_execution_allowed_here' => false,
                'dry_run_tick_allowed_here' => false,
                'runtime_write_allowed_here' => false,
                'provider_start_allowed_here' => false,
                'requires_future_signed_one_shot_dry_run_tick_release' => true,
            ],
            'next_required_slice' => $blockingReasons === []
                ? 'activate_automatic_dispatch_scheduler_dry_run_tick'
                : 'repair_automatic_dispatch_scheduler_runtime_execution_gate_blockers',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_runtime_execution_gate.v1',
            'status' => (string) $gate['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_runtime_execution_gate',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'scheduler_runtime_execution_allowed' => false,
            'dry_run_tick_allowed' => false,
            'provider_start_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_runtime_execution_gate' => $gate,
            'agent_automatic_dispatch_scheduler_runtime_execution_gate_hash' => $this->section->stableHash($gate),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_runtime_execution_gate_does_not_run_scheduler_tick',
                'agent_automatic_dispatch_scheduler_runtime_execution_gate_does_not_claim_wakeup_items',
                'agent_automatic_dispatch_scheduler_runtime_execution_gate_does_not_write_dispatch_receipts',
                'agent_automatic_dispatch_scheduler_runtime_execution_gate_does_not_start_providers',
                'agent_automatic_dispatch_scheduler_runtime_execution_gate_does_not_enable_self_programming',
            ],
            'human_summary' => $blockingReasons === []
                ? 'Automatic dispatch scheduler runtime execution gate is ready: Atlas has the one-shot dry-run release contract, but scheduler runtime remains disabled until a signed dry-run tick release.'
                : 'Automatic dispatch scheduler runtime execution gate is blocked until every runtime prerequisite is ready.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickReleaseReceiptValidationPreflight(array $options = []): array
    {
        $draftPayload = $this->section->agentAutomaticDispatchSchedulerOneShotTickReleaseReceiptDraft($options);
        $draft = (array) data_get($draftPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_release_receipt_draft', []);
        $receiptSubject = (array) data_get($draft, 'receipt_subject', []);
        $unsignedReceiptPayload = (array) data_get($draft, 'unsigned_receipt_payload', []);
        $draftHash = (string) data_get($draftPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_release_receipt_draft_hash');
        $signatureProvided = (string) data_get($unsignedReceiptPayload, 'signed_by', '') !== ''
            && (string) data_get($unsignedReceiptPayload, 'signed_at', '') !== ''
            && (string) data_get($unsignedReceiptPayload, 'expires_at', '') !== '';
        $selectedWakeupKeyPresent = (string) data_get($receiptSubject, 'selected_wakeup_key', '') !== '';
        $dispatchEnvelopeHashPresent = (string) data_get($receiptSubject, 'dispatch_envelope_hash', '') !== '';
        $scopeHashPresent = (string) data_get($unsignedReceiptPayload, 'scope_hash', '') !== '';
        $sourceReleaseTemplateHashPresent = (string) data_get($draft, 'source_release_template_hash', '') !== '';
        $sourcePreflightReady = data_get($draft, 'source_preflight_status') === 'agent_automatic_dispatch_scheduler_one_shot_tick_writer_preflight_ready';

        $preflightChecks = [
            'draft_available' => data_get($draftPayload, 'status') === 'agent_automatic_dispatch_scheduler_one_shot_tick_release_receipt_draft_ready',
            'draft_hash_present' => $draftHash !== '',
            'source_release_template_hash_present' => $sourceReleaseTemplateHashPresent,
            'source_preflight_ready' => $sourcePreflightReady,
            'signature_present_and_fresh' => $signatureProvided,
            'selected_wakeup_key_present' => $selectedWakeupKeyPresent,
            'dispatch_envelope_hash_present' => $dispatchEnvelopeHashPresent,
            'scope_hash_present' => $scopeHashPresent,
            'decision_matches_one_shot_scheduler_release' => data_get($draft, 'receipt_decision.decision') === 'approve_scheduler_claim_and_receipt_once',
            'max_wakeup_items_equals_one' => data_get($draft, 'receipt_decision.max_wakeup_items') === 1,
            'max_dispatch_receipts_equals_one' => data_get($draft, 'receipt_decision.max_dispatch_receipts') === 1,
            'provider_start_forbidden' => data_get($draft, 'receipt_decision.provider_start_allowed') === false,
            'receipt_use_forbidden' => data_get($draft, 'receipt_decision.receipt_use_allowed') === false,
            'adapter_invocation_forbidden' => data_get($draft, 'receipt_decision.adapter_invocation_allowed') === false,
            'token_spend_forbidden' => data_get($draft, 'receipt_decision.token_spend_allowed') === false,
        ];
        $failedChecks = array_values(array_keys(array_filter(
            $preflightChecks,
            fn (bool $passed): bool => ! $passed,
        )));
        $blockingReasons = array_values(array_unique($failedChecks));

        $preflight = [
            'status' => $blockingReasons === [] ? 'release_receipt_validation_preflight_ready' : 'blocked',
            'blocking_count' => count($blockingReasons),
            'blocking_reasons' => $blockingReasons,
            'failed_preflight_checks' => $failedChecks,
            'source_release_receipt_draft_hash' => $draftHash,
            'source_release_template_hash' => data_get($draft, 'source_release_template_hash'),
            'source_preflight_status' => data_get($draft, 'source_preflight_status'),
            'preflight_checks' => $preflightChecks,
            'validation_decision' => [
                'ready_for_persistence_contract' => $blockingReasons === [],
                'persistence_allowed_here' => false,
                'signature_acceptance_allowed_here' => false,
                'runtime_mutation_allowed_here' => false,
                'claim_allowed_here' => false,
                'dispatch_receipt_write_allowed_here' => false,
                'provider_start_allowed_here' => false,
                'requires_future_signed_receipt' => true,
            ],
            'validated_subject' => [
                'selected_wakeup_key' => data_get($receiptSubject, 'selected_wakeup_key'),
                'packet_id' => data_get($receiptSubject, 'packet_id'),
                'provider' => data_get($receiptSubject, 'provider'),
                'dispatch_envelope_hash' => data_get($receiptSubject, 'dispatch_envelope_hash'),
            ],
            'required_before_persistence' => [
                'fresh_human_or_operator_signature',
                'selected_wakeup_key_from_current_wakeup_queue',
                'dispatch_envelope_hash_from_current_dry_run_tick',
                'source_release_template_hash_match',
                'source_release_receipt_draft_hash_match',
                'scope_hash_match',
                'decision_approve_scheduler_claim_and_receipt_once',
                'max_one_wakeup_claim',
                'max_one_dispatch_receipt',
                'provider_start_still_forbidden',
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
            'next_required_slice' => $blockingReasons === []
                ? 'activate_signed_one_shot_scheduler_tick_release_receipt_persistence_contract'
                : 'repair_signed_one_shot_scheduler_tick_release_receipt_validation_preflight_blockers',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_release_receipt_validation_preflight.v1',
            'status' => (string) $preflight['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_release_receipt_validation_preflight',
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
            'agent_automatic_dispatch_scheduler_one_shot_tick_release_receipt_validation_preflight' => $preflight,
            'agent_automatic_dispatch_scheduler_one_shot_tick_release_receipt_validation_preflight_hash' => $this->section->stableHash($preflight),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_release_receipt_validation_preflight_does_not_accept_signatures',
                'agent_automatic_dispatch_scheduler_one_shot_tick_release_receipt_validation_preflight_does_not_persist_release_receipts',
                'agent_automatic_dispatch_scheduler_one_shot_tick_release_receipt_validation_preflight_does_not_claim_wakeup_items',
                'agent_automatic_dispatch_scheduler_one_shot_tick_release_receipt_validation_preflight_does_not_write_dispatch_receipts',
                'agent_automatic_dispatch_scheduler_one_shot_tick_release_receipt_validation_preflight_does_not_start_providers',
                'agent_automatic_dispatch_scheduler_one_shot_tick_release_receipt_validation_preflight_does_not_enable_self_programming',
            ],
            'human_summary' => $blockingReasons === []
                ? 'Automatic dispatch scheduler one-shot tick release receipt validation preflight is ready; Atlas can define the persistence contract without mutating runtime.'
                : 'Automatic dispatch scheduler one-shot tick release receipt validation preflight is blocked until signature, candidate and envelope evidence are complete.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartRealInvokerReleasePreflightGateStatus(array $options = []): array
    {
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerTableReady = Schema::hasTable('atlas_ledger_events');
        $gateReady = class_exists(AgentCodexRealInvokerPostStartRealInvokerReleasePreflightGate::class)
            && method_exists(AgentCodexRealInvokerPostStartRealInvokerReleasePreflightGate::class, 'recordPostStartRealInvokerReleasePreflight');
        $invokerReady = class_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartRealInvokerReleasePreflightGateInvoker::class)
            && method_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartRealInvokerReleasePreflightGateInvoker::class, 'recordCodexRealInvokerPostStartRealInvokerReleasePreflightGate');
        $releasePreflightReady = class_exists(AgentCodexRealInvokerReleasePreflight::class)
            && method_exists(AgentCodexRealInvokerReleasePreflight::class, 'recordPreflight');
        $dryRunGateReady = class_exists(AgentCodexRealInvokerPostStartExternalProcessInvokerDryRunGate::class)
            && method_exists(AgentCodexRealInvokerPostStartExternalProcessInvokerDryRunGate::class, 'preparePostStartExternalProcessInvokerDryRun');

        $observedPreflightRunsQuery = $runsTableReady
            ? AtlasSelfConstructionAgentRun::query()
                ->whereNotNull('metadata->codex_real_invoker_post_start_real_invoker_release_preflight->real_invoker_release_preflight_id')
            : null;
        $providerRunsWithPreflightQuery = $runsTableReady
            ? AtlasSelfConstructionAgentRun::query()
                ->where('run_key', 'like', 'provider-start:%')
                ->where('status', 'adapter_invocation_prepared')
                ->whereNotNull('metadata->codex_real_invoker_release_preflight->real_invoker_release_preflight_id')
            : null;
        $latestObservedPreflight = $observedPreflightRunsQuery === null
            ? null
            : (clone $observedPreflightRunsQuery)->latest('updated_at')->first();

        $statusReady = $runsTableReady
            && $ledgerTableReady
            && $gateReady
            && $invokerReady
            && $releasePreflightReady
            && $dryRunGateReady;
        $status = [
            'status' => $statusReady ? 'one_shot_tick_codex_real_invoker_post_start_real_invoker_release_preflight_gate_service_ready' : 'blocked',
            'provider' => 'codex',
            'adapter' => 'codex',
            'invoker_service' => AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartRealInvokerReleasePreflightGateInvoker::class,
            'invoker_service_ready' => $invokerReady,
            'invoker_canonical_method' => 'recordCodexRealInvokerPostStartRealInvokerReleasePreflightGate',
            'generic_post_start_real_invoker_release_preflight_gate_service' => AgentCodexRealInvokerPostStartRealInvokerReleasePreflightGate::class,
            'generic_post_start_real_invoker_release_preflight_gate_service_ready' => $gateReady,
            'generic_post_start_real_invoker_release_preflight_gate_canonical_method' => 'recordPostStartRealInvokerReleasePreflight',
            'codex_real_invoker_release_preflight_service' => AgentCodexRealInvokerReleasePreflight::class,
            'codex_real_invoker_release_preflight_service_ready' => $releasePreflightReady,
            'codex_real_invoker_release_preflight_canonical_method' => 'recordPreflight',
            'post_start_external_process_invoker_dry_run_gate_service' => AgentCodexRealInvokerPostStartExternalProcessInvokerDryRunGate::class,
            'post_start_external_process_invoker_dry_run_gate_ready' => $dryRunGateReady,
            'agent_runs_table_ready' => $runsTableReady,
            'ledger_table_ready' => $ledgerTableReady,
            'codex_real_invoker_post_start_real_invoker_release_preflight_run_count' => $observedPreflightRunsQuery === null ? null : (clone $observedPreflightRunsQuery)->count(),
            'provider_start_runs_with_codex_real_invoker_release_preflight_count' => $providerRunsWithPreflightQuery === null ? null : (clone $providerRunsWithPreflightQuery)->count(),
            'latest_codex_real_invoker_post_start_real_invoker_release_preflight' => $latestObservedPreflight instanceof AtlasSelfConstructionAgentRun
                ? [
                    'agent_run_id' => $latestObservedPreflight->id,
                    'run_key' => $latestObservedPreflight->run_key,
                    'packet_id' => $latestObservedPreflight->packet_id,
                    'provider' => $latestObservedPreflight->provider,
                    'status' => $latestObservedPreflight->status,
                    'post_start_real_invoker_release_preflight_gate_id' => data_get($latestObservedPreflight->metadata, 'codex_real_invoker_post_start_real_invoker_release_preflight.post_start_real_invoker_release_preflight_gate_id'),
                    'post_start_external_process_invoker_dry_run_gate_id' => data_get($latestObservedPreflight->metadata, 'codex_real_invoker_post_start_real_invoker_release_preflight.post_start_external_process_invoker_dry_run_gate_id'),
                    'real_invoker_release_preflight_id' => data_get($latestObservedPreflight->metadata, 'codex_real_invoker_post_start_real_invoker_release_preflight.real_invoker_release_preflight_id'),
                    'dry_run_id' => data_get($latestObservedPreflight->metadata, 'codex_real_invoker_post_start_real_invoker_release_preflight.dry_run_id'),
                    'invocation_authorization_id' => data_get($latestObservedPreflight->metadata, 'codex_real_invoker_post_start_real_invoker_release_preflight.invocation_authorization_id'),
                    'runtime_driver_id' => data_get($latestObservedPreflight->metadata, 'codex_real_invoker_post_start_real_invoker_release_preflight.runtime_driver_id'),
                    'post_start_evidence_acceptance_bridge_id' => data_get($latestObservedPreflight->metadata, 'codex_real_invoker_post_start_real_invoker_release_preflight.post_start_evidence_acceptance_bridge_id'),
                    'real_invoker_release_preflight_passed' => data_get($latestObservedPreflight->metadata, 'codex_real_invoker_post_start_real_invoker_release_preflight.real_invoker_release_preflight_passed'),
                    'signed_release_gate_required' => data_get($latestObservedPreflight->metadata, 'codex_real_invoker_post_start_real_invoker_release_preflight.signed_release_gate_required'),
                    'actual_process_start_allowed' => data_get($latestObservedPreflight->metadata, 'codex_real_invoker_post_start_real_invoker_release_preflight.actual_process_start_allowed'),
                    'token_spend_allowed' => data_get($latestObservedPreflight->metadata, 'codex_real_invoker_post_start_real_invoker_release_preflight.token_spend_allowed'),
                ]
                : null,
            'runtime_policy' => [
                'status_projection_is_read_only' => true,
                'invoker_may_record_release_preflight_when_called_with_signed_input' => true,
                'real_invoker_release_preflight_is_not_signed_release' => true,
                'signed_real_invoker_release_required_before_execution' => true,
                'atlas_process_invocation_allowed_here' => false,
                'actual_process_start_allowed_here' => false,
                'provider_process_call_allowed_here' => false,
                'adapter_invocation_allowed_here' => false,
                'adapter_execution_allowed_here' => false,
                'dispatch_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'self_programming_allowed_here' => false,
            ],
            'next_required_slice' => $statusReady
                ? 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_signed_real_invoker_release_gate_contract'
                : 'repair_one_shot_scheduler_tick_codex_real_invoker_post_start_real_invoker_release_preflight_gate_service_blockers',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_real_invoker_release_preflight_gate_status.v1',
            'status' => (string) $status['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_real_invoker_release_preflight_gate_status',
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
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_real_invoker_release_preflight_gate_status' => $status,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_real_invoker_release_preflight_gate_status_hash' => $this->section->stableHash($status),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_real_invoker_release_preflight_gate_status_does_not_call_release_preflight_gate',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_real_invoker_release_preflight_gate_status_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_real_invoker_release_preflight_gate_status_does_not_execute_adapter',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_real_invoker_release_preflight_gate_status_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_real_invoker_release_preflight_gate_status_does_not_dispatch_work',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_real_invoker_release_preflight_gate_status_does_not_enable_self_programming',
            ],
            'human_summary' => $statusReady
                ? 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start release preflight gate service is ready and inspectable; signed real invoker release remains separate.'
                : 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start release preflight gate service is blocked until invoker, generic gate, release preflight and storage are ready.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartSignedRealInvokerReleaseGateStatus(array $options = []): array
    {
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerTableReady = Schema::hasTable('atlas_ledger_events');
        $gateReady = class_exists(AgentCodexRealInvokerPostStartSignedRealInvokerReleaseGate::class)
            && method_exists(AgentCodexRealInvokerPostStartSignedRealInvokerReleaseGate::class, 'authorizePostStartSignedRealInvokerRelease');
        $invokerReady = class_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartSignedRealInvokerReleaseGateInvoker::class)
            && method_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartSignedRealInvokerReleaseGateInvoker::class, 'authorizeCodexRealInvokerPostStartSignedRealInvokerReleaseGate');
        $signedReleaseReady = class_exists(AgentCodexSignedRealInvokerReleaseGate::class)
            && method_exists(AgentCodexSignedRealInvokerReleaseGate::class, 'authorizeSignedRelease');
        $releasePreflightGateReady = class_exists(AgentCodexRealInvokerPostStartRealInvokerReleasePreflightGate::class)
            && method_exists(AgentCodexRealInvokerPostStartRealInvokerReleasePreflightGate::class, 'recordPostStartRealInvokerReleasePreflight');

        $observedSignedReleaseRunsQuery = $runsTableReady
            ? AtlasSelfConstructionAgentRun::query()
                ->whereNotNull('metadata->codex_real_invoker_post_start_signed_real_invoker_release->signed_real_invoker_release_id')
            : null;
        $providerRunsWithSignedReleaseQuery = $runsTableReady
            ? AtlasSelfConstructionAgentRun::query()
                ->where('run_key', 'like', 'provider-start:%')
                ->where('status', 'adapter_invocation_prepared')
                ->whereNotNull('metadata->codex_signed_real_invoker_release->signed_real_invoker_release_id')
            : null;
        $latestObservedSignedRelease = $observedSignedReleaseRunsQuery === null
            ? null
            : (clone $observedSignedReleaseRunsQuery)->latest('updated_at')->first();

        $statusReady = $runsTableReady
            && $ledgerTableReady
            && $gateReady
            && $invokerReady
            && $signedReleaseReady
            && $releasePreflightGateReady;
        $status = [
            'status' => $statusReady ? 'one_shot_tick_codex_real_invoker_post_start_signed_real_invoker_release_gate_service_ready' : 'blocked',
            'provider' => 'codex',
            'adapter' => 'codex',
            'invoker_service' => AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartSignedRealInvokerReleaseGateInvoker::class,
            'invoker_service_ready' => $invokerReady,
            'invoker_canonical_method' => 'authorizeCodexRealInvokerPostStartSignedRealInvokerReleaseGate',
            'generic_post_start_signed_real_invoker_release_gate_service' => AgentCodexRealInvokerPostStartSignedRealInvokerReleaseGate::class,
            'generic_post_start_signed_real_invoker_release_gate_service_ready' => $gateReady,
            'generic_post_start_signed_real_invoker_release_gate_canonical_method' => 'authorizePostStartSignedRealInvokerRelease',
            'codex_signed_real_invoker_release_gate_service' => AgentCodexSignedRealInvokerReleaseGate::class,
            'codex_signed_real_invoker_release_gate_ready' => $signedReleaseReady,
            'codex_signed_real_invoker_release_gate_canonical_method' => 'authorizeSignedRelease',
            'post_start_real_invoker_release_preflight_gate_service' => AgentCodexRealInvokerPostStartRealInvokerReleasePreflightGate::class,
            'post_start_real_invoker_release_preflight_gate_ready' => $releasePreflightGateReady,
            'agent_runs_table_ready' => $runsTableReady,
            'ledger_table_ready' => $ledgerTableReady,
            'codex_real_invoker_post_start_signed_real_invoker_release_run_count' => $observedSignedReleaseRunsQuery === null ? null : (clone $observedSignedReleaseRunsQuery)->count(),
            'provider_start_runs_with_codex_signed_real_invoker_release_count' => $providerRunsWithSignedReleaseQuery === null ? null : (clone $providerRunsWithSignedReleaseQuery)->count(),
            'latest_codex_real_invoker_post_start_signed_real_invoker_release' => $latestObservedSignedRelease instanceof AtlasSelfConstructionAgentRun
                ? [
                    'agent_run_id' => $latestObservedSignedRelease->id,
                    'run_key' => $latestObservedSignedRelease->run_key,
                    'packet_id' => $latestObservedSignedRelease->packet_id,
                    'provider' => $latestObservedSignedRelease->provider,
                    'status' => $latestObservedSignedRelease->status,
                    'post_start_signed_real_invoker_release_gate_id' => data_get($latestObservedSignedRelease->metadata, 'codex_real_invoker_post_start_signed_real_invoker_release.post_start_signed_real_invoker_release_gate_id'),
                    'post_start_real_invoker_release_preflight_gate_id' => data_get($latestObservedSignedRelease->metadata, 'codex_real_invoker_post_start_signed_real_invoker_release.post_start_real_invoker_release_preflight_gate_id'),
                    'signed_real_invoker_release_id' => data_get($latestObservedSignedRelease->metadata, 'codex_real_invoker_post_start_signed_real_invoker_release.signed_real_invoker_release_id'),
                    'real_invoker_release_preflight_id' => data_get($latestObservedSignedRelease->metadata, 'codex_real_invoker_post_start_signed_real_invoker_release.real_invoker_release_preflight_id'),
                    'dry_run_id' => data_get($latestObservedSignedRelease->metadata, 'codex_real_invoker_post_start_signed_real_invoker_release.dry_run_id'),
                    'codex_execution_id' => data_get($latestObservedSignedRelease->metadata, 'codex_real_invoker_post_start_signed_real_invoker_release.codex_execution_id'),
                    'post_start_evidence_acceptance_bridge_id' => data_get($latestObservedSignedRelease->metadata, 'codex_real_invoker_post_start_signed_real_invoker_release.post_start_evidence_acceptance_bridge_id'),
                    'signed_real_invoker_release_authorized' => data_get($latestObservedSignedRelease->metadata, 'codex_real_invoker_post_start_signed_real_invoker_release.signed_real_invoker_release_authorized'),
                    'implementation_boundary_required' => data_get($latestObservedSignedRelease->metadata, 'codex_real_invoker_post_start_signed_real_invoker_release.implementation_boundary_required'),
                    'actual_process_start_allowed' => data_get($latestObservedSignedRelease->metadata, 'codex_real_invoker_post_start_signed_real_invoker_release.actual_process_start_allowed'),
                    'token_spend_allowed' => data_get($latestObservedSignedRelease->metadata, 'codex_real_invoker_post_start_signed_real_invoker_release.token_spend_allowed'),
                ]
                : null,
            'runtime_policy' => [
                'status_projection_is_read_only' => true,
                'invoker_may_authorize_signed_release_when_called_with_signed_input' => true,
                'signed_real_invoker_release_gate_is_not_real_invoker_execution' => true,
                'implementation_boundary_required_before_execution' => true,
                'atlas_process_invocation_allowed_here' => false,
                'actual_process_start_allowed_here' => false,
                'provider_process_call_allowed_here' => false,
                'adapter_invocation_allowed_here' => false,
                'adapter_execution_allowed_here' => false,
                'dispatch_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'self_programming_allowed_here' => false,
            ],
            'next_required_slice' => $statusReady
                ? 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_implementation_boundary_gate_contract'
                : 'repair_one_shot_scheduler_tick_codex_real_invoker_post_start_signed_real_invoker_release_gate_service_blockers',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_signed_real_invoker_release_gate_status.v1',
            'status' => (string) $status['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_signed_real_invoker_release_gate_status',
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
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_signed_real_invoker_release_gate_status' => $status,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_signed_real_invoker_release_gate_status_hash' => $this->section->stableHash($status),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_signed_real_invoker_release_gate_status_does_not_call_signed_release_gate',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_signed_real_invoker_release_gate_status_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_signed_real_invoker_release_gate_status_does_not_execute_adapter',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_signed_real_invoker_release_gate_status_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_signed_real_invoker_release_gate_status_does_not_dispatch_work',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_signed_real_invoker_release_gate_status_does_not_enable_self_programming',
            ],
            'human_summary' => $statusReady
                ? 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start signed release gate service is ready and inspectable; implementation boundary remains separate.'
                : 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start signed release gate service is blocked until invoker, generic gate, signed release and storage are ready.',
        ];
    }


}
