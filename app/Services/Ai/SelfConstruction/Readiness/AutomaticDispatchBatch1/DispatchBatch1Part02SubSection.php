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
 * DISPATCH BATCH 1 projection sub-section 02 of 5, sub-split from the god
 * {@see \App\Services\Ai\SelfConstruction\Readiness\ReadinessProjectionAgentAutomaticDispatchBatch1Section}
 * (GOD-DEBULK). Method bodies are byte-identical to the parent Section; the
 * only rewrite is that sibling/back calls route through the injected Section
 * facade ($this->section->*), which re-dispatches to whichever sub-section
 * owns the target method or forwards to the bound mother via the facade
 * __call.
 */
final class DispatchBatch1Part02SubSection
{
    public function __construct(
        private readonly ReadinessProjectionAgentAutomaticDispatchBatch1Section $section,
    ) {}

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartExternalProcessInvokerDryRunGateStatus(array $options = []): array
    {
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerTableReady = Schema::hasTable('atlas_ledger_events');
        $gateReady = class_exists(AgentCodexRealInvokerPostStartExternalProcessInvokerDryRunGate::class)
            && method_exists(AgentCodexRealInvokerPostStartExternalProcessInvokerDryRunGate::class, 'preparePostStartExternalProcessInvokerDryRun');
        $invokerReady = class_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartExternalProcessInvokerDryRunGateInvoker::class)
            && method_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartExternalProcessInvokerDryRunGateInvoker::class, 'prepareCodexRealInvokerPostStartExternalProcessInvokerDryRunGate');
        $dryRunReady = class_exists(AgentCodexExternalProcessInvokerDryRun::class)
            && method_exists(AgentCodexExternalProcessInvokerDryRun::class, 'prepareDryRun');
        $authorizationGateReady = class_exists(AgentCodexRealInvokerPostStartProcessInvocationAuthorizationGate::class)
            && method_exists(AgentCodexRealInvokerPostStartProcessInvocationAuthorizationGate::class, 'authorizePostStartProcessInvocation');

        $observedDryRunRunsQuery = $runsTableReady
            ? AtlasSelfConstructionAgentRun::query()
                ->whereNotNull('metadata->codex_real_invoker_post_start_external_process_invoker_dry_run->dry_run_id')
            : null;
        $providerRunsWithDryRunQuery = $runsTableReady
            ? AtlasSelfConstructionAgentRun::query()
                ->where('run_key', 'like', 'provider-start:%')
                ->where('status', 'adapter_invocation_prepared')
                ->whereNotNull('metadata->codex_external_process_invoker_dry_run->dry_run_id')
            : null;
        $latestObservedDryRun = $observedDryRunRunsQuery === null
            ? null
            : (clone $observedDryRunRunsQuery)->latest('updated_at')->first();

        $statusReady = $runsTableReady
            && $ledgerTableReady
            && $gateReady
            && $invokerReady
            && $dryRunReady
            && $authorizationGateReady;
        $status = [
            'status' => $statusReady ? 'one_shot_tick_codex_real_invoker_post_start_external_process_invoker_dry_run_gate_service_ready' : 'blocked',
            'provider' => 'codex',
            'adapter' => 'codex',
            'invoker_service' => AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartExternalProcessInvokerDryRunGateInvoker::class,
            'invoker_service_ready' => $invokerReady,
            'invoker_canonical_method' => 'prepareCodexRealInvokerPostStartExternalProcessInvokerDryRunGate',
            'generic_post_start_external_process_invoker_dry_run_gate_service' => AgentCodexRealInvokerPostStartExternalProcessInvokerDryRunGate::class,
            'generic_post_start_external_process_invoker_dry_run_gate_service_ready' => $gateReady,
            'generic_post_start_external_process_invoker_dry_run_gate_canonical_method' => 'preparePostStartExternalProcessInvokerDryRun',
            'codex_external_process_invoker_dry_run_service' => AgentCodexExternalProcessInvokerDryRun::class,
            'codex_external_process_invoker_dry_run_service_ready' => $dryRunReady,
            'codex_external_process_invoker_dry_run_canonical_method' => 'prepareDryRun',
            'post_start_process_invocation_authorization_gate_service' => AgentCodexRealInvokerPostStartProcessInvocationAuthorizationGate::class,
            'post_start_process_invocation_authorization_gate_ready' => $authorizationGateReady,
            'agent_runs_table_ready' => $runsTableReady,
            'ledger_table_ready' => $ledgerTableReady,
            'codex_real_invoker_post_start_external_process_invoker_dry_run_run_count' => $observedDryRunRunsQuery === null ? null : (clone $observedDryRunRunsQuery)->count(),
            'provider_start_runs_with_codex_external_process_invoker_dry_run_count' => $providerRunsWithDryRunQuery === null ? null : (clone $providerRunsWithDryRunQuery)->count(),
            'latest_codex_real_invoker_post_start_external_process_invoker_dry_run' => $latestObservedDryRun instanceof AtlasSelfConstructionAgentRun
                ? [
                    'agent_run_id' => $latestObservedDryRun->id,
                    'run_key' => $latestObservedDryRun->run_key,
                    'packet_id' => $latestObservedDryRun->packet_id,
                    'provider' => $latestObservedDryRun->provider,
                    'status' => $latestObservedDryRun->status,
                    'post_start_external_process_invoker_dry_run_gate_id' => data_get($latestObservedDryRun->metadata, 'codex_real_invoker_post_start_external_process_invoker_dry_run.post_start_external_process_invoker_dry_run_gate_id'),
                    'post_start_process_invocation_authorization_gate_id' => data_get($latestObservedDryRun->metadata, 'codex_real_invoker_post_start_external_process_invoker_dry_run.post_start_process_invocation_authorization_gate_id'),
                    'dry_run_id' => data_get($latestObservedDryRun->metadata, 'codex_real_invoker_post_start_external_process_invoker_dry_run.dry_run_id'),
                    'invocation_authorization_id' => data_get($latestObservedDryRun->metadata, 'codex_real_invoker_post_start_external_process_invoker_dry_run.invocation_authorization_id'),
                    'runtime_driver_id' => data_get($latestObservedDryRun->metadata, 'codex_real_invoker_post_start_external_process_invoker_dry_run.runtime_driver_id'),
                    'post_start_evidence_acceptance_bridge_id' => data_get($latestObservedDryRun->metadata, 'codex_real_invoker_post_start_external_process_invoker_dry_run.post_start_evidence_acceptance_bridge_id'),
                    'external_process_invoker_dry_run_prepared' => data_get($latestObservedDryRun->metadata, 'codex_real_invoker_post_start_external_process_invoker_dry_run.external_process_invoker_dry_run_prepared'),
                    'real_invoker_execution_gate_required' => data_get($latestObservedDryRun->metadata, 'codex_real_invoker_post_start_external_process_invoker_dry_run.real_invoker_execution_gate_required'),
                    'actual_process_start_allowed' => data_get($latestObservedDryRun->metadata, 'codex_real_invoker_post_start_external_process_invoker_dry_run.actual_process_start_allowed'),
                    'token_spend_allowed' => data_get($latestObservedDryRun->metadata, 'codex_real_invoker_post_start_external_process_invoker_dry_run.token_spend_allowed'),
                ]
                : null,
            'runtime_policy' => [
                'status_projection_is_read_only' => true,
                'invoker_may_prepare_dry_run_when_called_with_signed_input' => true,
                'external_process_invoker_dry_run_is_not_real_invoker_execution' => true,
                'real_invoker_release_preflight_required_before_execution' => true,
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
                ? 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_real_invoker_release_preflight_gate_contract'
                : 'repair_one_shot_scheduler_tick_codex_real_invoker_post_start_external_process_invoker_dry_run_gate_service_blockers',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_external_process_invoker_dry_run_gate_status.v1',
            'status' => (string) $status['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_external_process_invoker_dry_run_gate_status',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'post_start_external_process_invoker_dry_run_gate_allowed' => false,
            'actual_process_start_allowed' => false,
            'provider_process_call_allowed' => false,
            'adapter_invocation_allowed' => false,
            'adapter_execution_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_external_process_invoker_dry_run_gate_status' => $status,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_external_process_invoker_dry_run_gate_status_hash' => $this->section->stableHash($status),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_external_process_invoker_dry_run_gate_status_does_not_call_dry_run_gate',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_external_process_invoker_dry_run_gate_status_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_external_process_invoker_dry_run_gate_status_does_not_execute_adapter',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_external_process_invoker_dry_run_gate_status_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_external_process_invoker_dry_run_gate_status_does_not_dispatch_work',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_external_process_invoker_dry_run_gate_status_does_not_enable_self_programming',
            ],
            'human_summary' => $statusReady
                ? 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start external process invoker dry-run gate service is ready and inspectable; real invoker release preflight remains separate.'
                : 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start external process invoker dry-run gate service is blocked until invoker, generic gate, dry-run and storage are ready.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartFinalProcessStartAuthorizationGatePreflight(array $options = []): array
    {
        $contractPayload = $this->section->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartFinalProcessStartAuthorizationGateContract($options);
        $contract = (array) data_get($contractPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_final_process_start_authorization_gate_contract', []);
        $contractHash = (string) data_get($contractPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_final_process_start_authorization_gate_contract_hash');
        $gateReady = class_exists(AgentCodexRealInvokerPostStartFinalProcessStartAuthorizationGate::class)
            && method_exists(AgentCodexRealInvokerPostStartFinalProcessStartAuthorizationGate::class, 'authorizePostStartFinalProcessStart');
        $invokerReady = class_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartFinalProcessStartAuthorizationGateInvoker::class)
            && method_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartFinalProcessStartAuthorizationGateInvoker::class, 'prepareCodexRealInvokerPostStartFinalProcessStartAuthorizationGate');
        $authorizationReady = class_exists(AgentCodexRealInvokerFinalProcessStartAuthorizationGate::class)
            && method_exists(AgentCodexRealInvokerFinalProcessStartAuthorizationGate::class, 'authorizeFinalStart');
        $guardedReady = class_exists(AgentCodexRealInvokerPostStartGuardedProcessStartExecutorGate::class)
            && method_exists(AgentCodexRealInvokerPostStartGuardedProcessStartExecutorGate::class, 'preparePostStartGuardedProcessStart');
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerTableReady = Schema::hasTable('atlas_ledger_events');

        $checks = [
            'post_start_final_process_start_authorization_gate_contract_ready' => data_get($contractPayload, 'status') === 'one_shot_tick_codex_real_invoker_post_start_final_process_start_authorization_gate_contract_ready',
            'post_start_final_process_start_authorization_gate_contract_hash_present' => $contractHash !== '',
            'post_start_guarded_process_start_executor_gate_status_ready' => data_get($contract, 'source_codex_real_invoker_post_start_guarded_process_start_executor_gate_status') === 'one_shot_tick_codex_real_invoker_post_start_guarded_process_start_executor_gate_service_ready',
            'generic_post_start_final_process_start_authorization_gate_template_ready' => data_get($contract, 'source_codex_real_invoker_post_start_final_process_start_authorization_gate_contract_status') === 'codex_real_invoker_post_start_final_process_start_authorization_gate_contract_template_ready',
            'generic_final_process_start_authorization_gate_template_ready' => data_get($contract, 'source_codex_real_invoker_final_process_start_authorization_gate_contract_status') === 'codex_real_invoker_final_process_start_authorization_gate_contract_template_ready',
            'codex_real_invoker_post_start_final_process_start_authorization_gate_ready' => $gateReady,
            'codex_real_invoker_post_start_final_process_start_authorization_gate_invoker_ready' => $invokerReady,
            'codex_real_invoker_final_process_start_authorization_gate_ready' => $authorizationReady,
            'codex_real_invoker_post_start_guarded_process_start_executor_gate_ready' => $guardedReady,
            'canonical_post_start_final_process_start_authorization_gate_method_ready' => data_get($contract, 'final_process_start_authorization.canonical_post_start_final_process_start_authorization_gate_method') === 'authorizePostStartFinalProcessStart',
            'scheduler_invoker_method_ready' => data_get($contract, 'final_process_start_authorization.scheduler_invoker_method') === 'prepareCodexRealInvokerPostStartFinalProcessStartAuthorizationGate',
            'contract_requires_post_start_guarded_process_start' => data_get($contract, 'final_process_start_authorization.post_start_guarded_process_start_required_before_final_authorization') === true,
            'contract_requires_post_start_evidence_acceptance_bridge' => data_get($contract, 'final_process_start_authorization.post_start_evidence_acceptance_bridge_required_before_final_authorization') === true,
            'contract_requires_provider_start_guarded_process_start' => data_get($contract, 'final_process_start_authorization.provider_start_guarded_process_start_required_before_final_authorization') === true,
            'contract_delegates_to_codex_real_invoker_final_process_start_authorization_gate' => data_get($contract, 'final_process_start_authorization.gate_delegates_to_codex_real_invoker_final_process_start_authorization_gate') === true,
            'contract_declares_final_authorization_is_not_actual_process_start' => data_get($contract, 'final_process_start_authorization.final_authorization_is_not_actual_process_start') === true,
            'contract_requires_actual_process_start_rehearsal_after_final_authorization' => in_array('execute_actual_process_start_without_separate_rehearsal_contract', (array) data_get($contract, 'forbidden_even_after_contract', []), true),
            'contract_authorizes_final_process_start' => data_get($contract, 'final_process_start_authorization.final_process_start_authorized_by_contract') === true,
            'contract_keeps_actual_process_start_disabled' => data_get($contract, 'final_process_start_authorization.actual_process_start_allowed_by_contract') === false,
            'contract_keeps_adapter_execution_disabled' => data_get($contract, 'final_process_start_authorization.adapter_execution_allowed_by_contract') === false,
            'contract_does_not_dispatch_work' => data_get($contract, 'final_process_start_authorization.dispatch_allowed_by_contract') === false,
            'contract_does_not_spend_tokens' => data_get($contract, 'final_process_start_authorization.token_spend_allowed_by_contract') === false,
            'contract_lists_runtime_enabling_flags_as_forbidden' => in_array('actual_process_start_allowed', (array) data_get($contract, 'forbidden_true_input_flags', []), true)
                && in_array('dispatch_allowed', (array) data_get($contract, 'forbidden_true_input_flags', []), true)
                && in_array('self_programming_allowed', (array) data_get($contract, 'forbidden_true_input_flags', []), true),
            'agent_runs_table_ready' => $runsTableReady,
            'ledger_table_ready' => $ledgerTableReady,
            'self_programming_forbidden' => in_array('enable_self_programming', (array) data_get($contract, 'forbidden_even_after_contract', []), true),
        ];
        $blockingReasons = array_values(array_keys(array_filter($checks, static fn (bool $passed): bool => ! $passed)));

        $preflight = [
            'status' => $blockingReasons === [] ? 'one_shot_tick_codex_real_invoker_post_start_final_process_start_authorization_gate_preflight_ready' : 'blocked',
            'preflight_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-REAL-INVOKER-POST-START-FINAL-PROCESS-START-AUTHORIZATION-GATE-PREFLIGHT-SELF-CONSTRUCTION-0001',
            'source_post_start_final_process_start_authorization_gate_contract_hash' => $contractHash,
            'preflight_checks' => $checks,
            'blocking_count' => count($blockingReasons),
            'blocking_reasons' => $blockingReasons,
            'implementation_requirements' => [
                'create_scheduler_specific_post_start_final_process_start_authorization_gate_invoker',
                'delegate_to_agent_codex_real_invoker_post_start_final_process_start_authorization_gate',
                'require_post_start_guarded_process_start_metadata',
                'require_post_start_evidence_acceptance_bridge',
                'require_operator_final_start_receipt_hash',
                'require_final_start_signature_hash',
                'require_final_start_policy_hash',
                'require_final_start_window_hash',
                'require_final_start_replay_guard_hash',
                'require_final_start_kill_switch_hash',
                'reject_caller_inputs_that_try_to_enable_runtime_flags',
                'project_readiness_status_without_invoking_codex',
            ],
            'runtime_policy' => [
                'preflight_is_read_only' => true,
                'post_start_final_process_start_authorization_gate_call_allowed_by_future_invoker' => true,
                'final_process_start_authorized_after_future_invoker' => true,
                'actual_process_start_rehearsal_required_after_final_authorization' => true,
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
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_final_process_start_authorization_gate_implementation_packet',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_final_process_start_authorization_gate_preflight.v1',
            'status' => (string) $preflight['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_final_process_start_authorization_gate_preflight',
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
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_final_process_start_authorization_gate_preflight' => $preflight,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_final_process_start_authorization_gate_preflight_hash' => $this->section->stableHash($preflight),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_final_process_start_authorization_gate_preflight_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_final_process_start_authorization_gate_preflight_does_not_call_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_final_process_start_authorization_gate_preflight_does_not_execute_adapter',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_final_process_start_authorization_gate_preflight_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_final_process_start_authorization_gate_preflight_does_not_dispatch_work',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_final_process_start_authorization_gate_preflight_does_not_enable_self_programming',
            ],
            'human_summary' => $blockingReasons === []
                ? 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start final process start authorization gate preflight is ready; actual process start rehearsal remains separate.'
                : 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start final process start authorization gate preflight is blocked until guarded process start, generic final authorization gate, storage and no-runtime prerequisites are ready.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartActualProcessStartRehearsalGatePreflight(array $options = []): array
    {
        $contractPayload = $this->section->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartActualProcessStartRehearsalGateContract($options);
        $contract = (array) data_get($contractPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_actual_process_start_rehearsal_gate_contract', []);
        $contractHash = (string) data_get($contractPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_actual_process_start_rehearsal_gate_contract_hash');
        $gateReady = class_exists(AgentCodexRealInvokerPostStartActualProcessStartRehearsalGate::class)
            && method_exists(AgentCodexRealInvokerPostStartActualProcessStartRehearsalGate::class, 'rehearsePostStartActualProcessStart');
        $invokerReady = class_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartActualProcessStartRehearsalGateInvoker::class)
            && method_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartActualProcessStartRehearsalGateInvoker::class, 'prepareCodexRealInvokerPostStartActualProcessStartRehearsalGate');
        $rehearsalExecutorReady = class_exists(AgentCodexRealInvokerActualProcessStartRehearsalExecutor::class)
            && method_exists(AgentCodexRealInvokerActualProcessStartRehearsalExecutor::class, 'rehearseActualStart');
        $finalAuthorizationReady = class_exists(AgentCodexRealInvokerPostStartFinalProcessStartAuthorizationGate::class)
            && method_exists(AgentCodexRealInvokerPostStartFinalProcessStartAuthorizationGate::class, 'authorizePostStartFinalProcessStart');
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerTableReady = Schema::hasTable('atlas_ledger_events');

        $checks = [
            'post_start_actual_process_start_rehearsal_gate_contract_ready' => data_get($contractPayload, 'status') === 'one_shot_tick_codex_real_invoker_post_start_actual_process_start_rehearsal_gate_contract_ready',
            'post_start_actual_process_start_rehearsal_gate_contract_hash_present' => $contractHash !== '',
            'post_start_final_process_start_authorization_gate_status_ready' => data_get($contract, 'source_codex_real_invoker_post_start_final_process_start_authorization_gate_status') === 'one_shot_tick_codex_real_invoker_post_start_final_process_start_authorization_gate_service_ready',
            'generic_post_start_actual_process_start_rehearsal_gate_template_ready' => data_get($contract, 'source_codex_real_invoker_post_start_actual_process_start_rehearsal_gate_contract_status') === 'codex_real_invoker_post_start_actual_process_start_rehearsal_gate_contract_template_ready',
            'generic_actual_process_start_rehearsal_executor_template_ready' => data_get($contract, 'source_codex_real_invoker_actual_process_start_rehearsal_executor_contract_status') === 'codex_real_invoker_actual_process_start_rehearsal_executor_contract_template_ready',
            'codex_real_invoker_post_start_actual_process_start_rehearsal_gate_ready' => $gateReady,
            'codex_real_invoker_post_start_actual_process_start_rehearsal_gate_invoker_ready' => $invokerReady,
            'codex_real_invoker_actual_process_start_rehearsal_executor_ready' => $rehearsalExecutorReady,
            'codex_real_invoker_post_start_final_process_start_authorization_gate_ready' => $finalAuthorizationReady,
            'canonical_post_start_actual_process_start_rehearsal_gate_method_ready' => data_get($contract, 'actual_process_start_rehearsal.canonical_post_start_actual_process_start_rehearsal_gate_method') === 'rehearsePostStartActualProcessStart',
            'scheduler_invoker_method_ready' => data_get($contract, 'actual_process_start_rehearsal.scheduler_invoker_method') === 'prepareCodexRealInvokerPostStartActualProcessStartRehearsalGate',
            'contract_requires_post_start_final_process_start_authorization' => data_get($contract, 'actual_process_start_rehearsal.post_start_final_process_start_authorization_required_before_rehearsal') === true,
            'contract_requires_post_start_evidence_acceptance_bridge' => data_get($contract, 'actual_process_start_rehearsal.post_start_evidence_acceptance_bridge_required_before_rehearsal') === true,
            'contract_requires_provider_start_final_authorization' => data_get($contract, 'actual_process_start_rehearsal.provider_start_final_authorization_required_before_rehearsal') === true,
            'contract_delegates_to_codex_real_invoker_actual_process_start_rehearsal_executor' => data_get($contract, 'actual_process_start_rehearsal.gate_delegates_to_codex_real_invoker_actual_process_start_rehearsal_executor') === true,
            'contract_declares_rehearsal_is_not_actual_process_start' => data_get($contract, 'actual_process_start_rehearsal.rehearsal_is_not_actual_process_start') === true,
            'contract_requires_process_start_envelope_after_rehearsal' => in_array('start_actual_process_without_separate_envelope_contract', (array) data_get($contract, 'forbidden_even_after_contract', []), true),
            'contract_marks_process_start_rehearsed_by_contract' => data_get($contract, 'actual_process_start_rehearsal.process_start_rehearsed_by_contract') === true,
            'contract_keeps_actual_process_start_disabled' => data_get($contract, 'actual_process_start_rehearsal.actual_process_start_allowed_by_contract') === false,
            'contract_keeps_adapter_execution_disabled' => data_get($contract, 'actual_process_start_rehearsal.adapter_execution_allowed_by_contract') === false,
            'contract_does_not_dispatch_work' => data_get($contract, 'actual_process_start_rehearsal.dispatch_allowed_by_contract') === false,
            'contract_does_not_spend_tokens' => data_get($contract, 'actual_process_start_rehearsal.token_spend_allowed_by_contract') === false,
            'contract_lists_runtime_enabling_flags_as_forbidden' => in_array('actual_process_start_allowed', (array) data_get($contract, 'forbidden_true_input_flags', []), true)
                && in_array('dispatch_allowed', (array) data_get($contract, 'forbidden_true_input_flags', []), true)
                && in_array('self_programming_allowed', (array) data_get($contract, 'forbidden_true_input_flags', []), true),
            'agent_runs_table_ready' => $runsTableReady,
            'ledger_table_ready' => $ledgerTableReady,
            'self_programming_forbidden' => in_array('enable_self_programming', (array) data_get($contract, 'forbidden_even_after_contract', []), true),
        ];
        $blockingReasons = array_values(array_keys(array_filter($checks, static fn (bool $passed): bool => ! $passed)));

        $preflight = [
            'status' => $blockingReasons === [] ? 'one_shot_tick_codex_real_invoker_post_start_actual_process_start_rehearsal_gate_preflight_ready' : 'blocked',
            'preflight_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-REAL-INVOKER-POST-START-ACTUAL-PROCESS-START-REHEARSAL-GATE-PREFLIGHT-SELF-CONSTRUCTION-0001',
            'source_post_start_actual_process_start_rehearsal_gate_contract_hash' => $contractHash,
            'preflight_checks' => $checks,
            'blocking_count' => count($blockingReasons),
            'blocking_reasons' => $blockingReasons,
            'implementation_requirements' => [
                'create_scheduler_specific_post_start_actual_process_start_rehearsal_gate_invoker',
                'delegate_to_agent_codex_real_invoker_post_start_actual_process_start_rehearsal_gate',
                'require_post_start_final_process_start_authorization_metadata',
                'require_post_start_evidence_acceptance_bridge',
                'require_process_start_rehearsal_hash',
                'require_command_resolution_hash',
                'require_environment_resolution_hash',
                'require_cwd_verification_hash',
                'require_supervisor_dry_run_hash',
                'require_liveness_probe_rehearsal_hash',
                'reject_caller_inputs_that_try_to_enable_runtime_flags',
                'project_readiness_status_without_invoking_codex',
            ],
            'runtime_policy' => [
                'preflight_is_read_only' => true,
                'post_start_actual_process_start_rehearsal_gate_call_allowed_by_future_invoker' => true,
                'process_start_rehearsed_after_future_invoker' => true,
                'process_start_envelope_required_after_rehearsal' => true,
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
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_actual_process_start_rehearsal_gate_implementation_packet',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_actual_process_start_rehearsal_gate_preflight.v1',
            'status' => (string) $preflight['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_actual_process_start_rehearsal_gate_preflight',
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
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_actual_process_start_rehearsal_gate_preflight' => $preflight,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_actual_process_start_rehearsal_gate_preflight_hash' => $this->section->stableHash($preflight),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_actual_process_start_rehearsal_gate_preflight_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_actual_process_start_rehearsal_gate_preflight_does_not_call_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_actual_process_start_rehearsal_gate_preflight_does_not_execute_adapter',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_actual_process_start_rehearsal_gate_preflight_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_actual_process_start_rehearsal_gate_preflight_does_not_dispatch_work',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_actual_process_start_rehearsal_gate_preflight_does_not_enable_self_programming',
            ],
            'human_summary' => $blockingReasons === []
                ? 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start actual process start rehearsal gate preflight is ready; process start envelope remains separate.'
                : 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start actual process start rehearsal gate preflight is blocked until final authorization, rehearsal executor, storage and no-runtime prerequisites are ready.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerPolicy(array $options = []): array
    {
        $workProductPolicyPayload = $this->section->agentAutomaticWorkProductCollectionPolicy($options);
        $wakeupSchedulerPayload = $this->section->agentWakeupScheduler($options);
        $runtimeTables = $this->section->agentControlPlaneRuntimeTables();

        $componentReadiness = [
            'automatic_work_product_collection_policy' => data_get($workProductPolicyPayload, 'status') === 'agent_automatic_work_product_collection_policy_ready',
            'wakeup_scheduler_projection' => data_get($wakeupSchedulerPayload, 'status') === 'agent_wakeup_scheduler_ready',
            'agent_runs_table' => $runtimeTables['atlas_self_construction_agent_runs'],
            'wakeup_items_table' => $runtimeTables['atlas_self_construction_agent_wakeup_items'],
            'dispatch_receipts_table' => $runtimeTables['atlas_self_construction_agent_dispatch_receipts'],
            'dispatch_preflight_contract' => $this->section->motherHasMethod('agentDispatchPreflight'),
            'dispatch_receipt_writer' => $this->section->motherHasMethod('agentDispatchReceiptWrite'),
            'dispatch_executor_preflight_contract' => $this->section->motherHasMethod('agentDispatchExecutorPreflight'),
        ];
        $blockingReasons = array_values(array_map(
            static fn (string $component): string => $component.'_not_ready',
            array_keys(array_filter($componentReadiness, static fn (bool $ready): bool => ! $ready))
        ));

        $policy = [
            'status' => $blockingReasons === [] ? 'agent_automatic_dispatch_scheduler_policy_ready' : 'blocked',
            'policy_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-POLICY-SELF-CONSTRUCTION-0001',
            'parent_program' => 'Atlas Self-Construction OS',
            'submodule' => 'Atlas Agent Control Plane',
            'component_readiness' => $componentReadiness,
            'component_preflight_hashes' => [
                'automatic_work_product_collection_policy' => data_get($workProductPolicyPayload, 'agent_automatic_work_product_collection_policy_hash'),
                'wakeup_scheduler' => data_get($wakeupSchedulerPayload, 'wakeup_scheduler_hash'),
            ],
            'blocking_count' => count($blockingReasons),
            'blocking_reasons' => $blockingReasons,
            'scheduler_selection_contract' => [
                'eligible_item_status' => 'queued',
                'required_order' => ['priority', 'scheduled_for', 'created_at'],
                'required_identity' => ['wakeup_key', 'packet_id', 'provider', 'actor', 'reason'],
                'required_guards' => [
                    'one_active_claim_per_wakeup_item',
                    'one_active_packet_reservation_per_packet',
                    'signed_dispatch_receipt_before_provider_start',
                    'dispatch_executor_preflight_before_adapter_invocation',
                    'liveness_heartbeat_before_and_after_dispatch',
                ],
            ],
            'dispatch_runtime_contract' => [
                'required_stage_order' => [
                    'select_ready_wakeup_item',
                    'claim_wakeup_item_atomically',
                    'prepare_dispatch_preflight',
                    'write_signed_dispatch_receipt_or_block_for_human',
                    'validate_receipt',
                    'prepare_executor_preflight',
                    'require_future_runtime_execution_gate',
                ],
                'dispatch_idempotency_key' => 'sha256(wakeup_key|packet_id|provider|signed_receipt_hash)',
                'max_dispatches_per_tick_default' => 1,
            ],
            'scheduler_quality_guards' => [
                'never_dispatch_without_signed_receipt',
                'never_start_provider_from_scheduler_policy',
                'never_claim_packet_outside_packet_scope',
                'never_reuse_raw_chat_history_as_provider_context',
                'stop_on_stale_liveness_or_expired_receipt',
                'record_dispatch_decision_as_evidence_before_runtime_release',
            ],
            'allowed_now' => [
                'automatic_dispatch_scheduler_policy_projection',
                'scheduler_prerequisite_readiness_check',
                'wakeup_queue_projection_reference',
                'dispatch_contract_reference',
            ],
            'forbidden_now' => [
                'claim_wakeup_items_automatically',
                'write_dispatch_receipts_automatically',
                'mark_receipts_used',
                'start_or_supervise_provider_process',
                'invoke_provider_adapters',
                'spend_provider_tokens',
                'self_program_or_self_merge',
            ],
            'activation_policy' => [
                'policy_is_read_only' => true,
                'automatic_dispatch_allowed_here' => false,
                'runtime_write_allowed_here' => false,
                'provider_start_allowed_here' => false,
                'requires_future_signed_dispatch_scheduler_runtime_execution_gate' => true,
            ],
            'next_required_slice' => $blockingReasons === []
                ? 'activate_automatic_dispatch_scheduler_dry_run_tick'
                : 'repair_automatic_dispatch_scheduler_policy_blockers',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_policy.v1',
            'status' => (string) $policy['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_policy',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'automatic_dispatch_allowed' => false,
            'provider_start_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_policy' => $policy,
            'agent_automatic_dispatch_scheduler_policy_hash' => $this->section->stableHash($policy),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_policy_does_not_claim_wakeup_items',
                'agent_automatic_dispatch_scheduler_policy_does_not_write_dispatch_receipts',
                'agent_automatic_dispatch_scheduler_policy_does_not_mark_receipts_used',
                'agent_automatic_dispatch_scheduler_policy_does_not_start_providers',
                'agent_automatic_dispatch_scheduler_policy_does_not_enable_self_programming',
            ],
            'human_summary' => $blockingReasons === []
                ? 'Automatic dispatch scheduler policy is ready: Atlas has the dispatch selection and guard contract, but automatic dispatch remains disabled until a signed runtime execution gate.'
                : 'Automatic dispatch scheduler policy is blocked until every scheduler prerequisite is ready.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerExecutorFreshReleaseGateContract(array $options = []): array
    {
        $executorPlanStatusPayload = $this->section->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerExecutorPlanStatus($options);
        $executorPlanStatus = (array) data_get($executorPlanStatusPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_plan_status', []);
        $freshReleasePayload = $this->section->agentCodexRealInvokerExecutorFreshReleaseGateContractTemplate($options);

        $contract = [
            'status' => 'one_shot_tick_codex_real_invoker_executor_fresh_release_gate_contract_ready',
            'contract_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-REAL-INVOKER-EXECUTOR-FRESH-RELEASE-GATE-CONTRACT-SELF-CONSTRUCTION-0001',
            'parent_program' => 'Atlas Self-Construction OS',
            'submodule' => 'Atlas Agent Control Plane',
            'provider' => 'codex',
            'adapter' => 'codex',
            'source_codex_real_invoker_executor_plan_status' => data_get($executorPlanStatus, 'status'),
            'source_codex_real_invoker_executor_plan_status_hash' => data_get($executorPlanStatusPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_plan_status_hash'),
            'source_codex_real_invoker_executor_fresh_release_gate_contract_status' => data_get($freshReleasePayload, 'status'),
            'source_codex_real_invoker_executor_fresh_release_gate_contract_hash' => data_get($freshReleasePayload, 'codex_real_invoker_executor_fresh_release_gate_contract_template_hash'),
            'release_boundary' => [
                'canonical_executor_fresh_release_gate' => AgentCodexRealInvokerExecutorFreshReleaseGate::class,
                'canonical_executor_fresh_release_gate_method' => 'authorizeFreshRelease',
                'scheduler_invoker' => AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerExecutorFreshReleaseGateInvoker::class,
                'scheduler_invoker_method' => 'authorizeCodexRealInvokerExecutorFreshRelease',
                'fresh_release_effect' => 'authorize_disabled_codex_real_invoker_executor_fresh_release_metadata_without_enabling_executor',
                'executor_enabled_by_fresh_release' => false,
                'external_process_started_by_fresh_release' => false,
                'provider_started_by_fresh_release' => false,
                'adapter_execution_allowed_by_fresh_release' => false,
                'token_spend_allowed_by_fresh_release' => false,
                'required_executor_plan_status_before_fresh_release' => 'real_invoker_executor_plan_prepared_disabled_pending_fresh_release',
                'prepared_status_after_fresh_release' => 'real_invoker_executor_fresh_release_authorized_pending_enablement',
                'idempotency_key' => 'real_invoker_executor_fresh_release_id',
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
                'write_codex_real_invoker_executor_fresh_release_metadata_on_agent_run',
                'append_codex_real_invoker_executor_fresh_release_authorized_evidence_event',
            ],
            'forbidden_even_after_contract' => [
                'enable_executor',
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
                'fresh_release_is_authorization_not_execution' => true,
                'executor_enablement_requires_separate_contract' => true,
                'operator_fresh_release_receipt_hash_required' => true,
                'plan_revalidation_report_hash_required' => true,
                'freshness_window_hash_required' => true,
                'final_human_signature_hash_required' => true,
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_executor_fresh_release_gate_preflight',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_fresh_release_gate_contract.v1',
            'status' => (string) $contract['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_fresh_release_gate_contract',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_executor_fresh_release_gate_allowed' => false,
            'executor_enabled' => false,
            'adapter_execution_allowed' => false,
            'provider_start_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_fresh_release_gate_contract' => $contract,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_fresh_release_gate_contract_hash' => $this->section->stableHash($contract),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_fresh_release_gate_contract_does_not_authorize_fresh_release',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_fresh_release_gate_contract_does_not_enable_executor',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_fresh_release_gate_contract_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_fresh_release_gate_contract_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_fresh_release_gate_contract_does_not_enable_self_programming',
            ],
            'human_summary' => 'Automatic dispatch scheduler one-shot tick Codex real invoker executor fresh release gate contract is ready; it authorizes only the fresh release boundary and still cannot start Codex.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartFinalProcessSpawnExecutorGateStatus(array $options = []): array
    {
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerTableReady = Schema::hasTable('atlas_ledger_events');
        $gateReady = class_exists(AgentCodexRealInvokerPostStartFinalProcessSpawnExecutorGate::class)
            && method_exists(AgentCodexRealInvokerPostStartFinalProcessSpawnExecutorGate::class, 'preparePostStartFinalProcessSpawn');
        $invokerReady = class_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartFinalProcessSpawnExecutorGateInvoker::class)
            && method_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartFinalProcessSpawnExecutorGateInvoker::class, 'prepareCodexRealInvokerPostStartFinalProcessSpawnExecutorGate');
        $spawnExecutorReady = class_exists(AgentCodexProcessSpawnExecutor::class)
            && method_exists(AgentCodexProcessSpawnExecutor::class, 'prepareProcessSpawn');
        $spawnEnablementGateReady = class_exists(AgentCodexRealInvokerPostStartProcessSpawnEnablementGate::class)
            && method_exists(AgentCodexRealInvokerPostStartProcessSpawnEnablementGate::class, 'enablePostStartProcessSpawn');

        $observedFinalSpawnRunsQuery = $runsTableReady
            ? AtlasSelfConstructionAgentRun::query()
                ->whereNotNull('metadata->codex_real_invoker_post_start_final_process_spawn_executor->spawn_executor_id')
            : null;
        $providerRunsWithSpawnExecutorQuery = $runsTableReady
            ? AtlasSelfConstructionAgentRun::query()
                ->where('run_key', 'like', 'provider-start:%')
                ->where('status', 'adapter_invocation_prepared')
                ->whereNotNull('metadata->codex_process_spawn_executor->spawn_executor_id')
            : null;
        $latestObservedFinalSpawnRun = $observedFinalSpawnRunsQuery === null
            ? null
            : (clone $observedFinalSpawnRunsQuery)->latest('updated_at')->first();

        $statusReady = $runsTableReady
            && $ledgerTableReady
            && $gateReady
            && $invokerReady
            && $spawnExecutorReady
            && $spawnEnablementGateReady;
        $status = [
            'status' => $statusReady ? 'one_shot_tick_codex_real_invoker_post_start_final_process_spawn_executor_gate_service_ready' : 'blocked',
            'provider' => 'codex',
            'adapter' => 'codex',
            'invoker_service' => AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartFinalProcessSpawnExecutorGateInvoker::class,
            'invoker_service_ready' => $invokerReady,
            'invoker_canonical_method' => 'prepareCodexRealInvokerPostStartFinalProcessSpawnExecutorGate',
            'generic_post_start_final_process_spawn_executor_gate_service' => AgentCodexRealInvokerPostStartFinalProcessSpawnExecutorGate::class,
            'generic_post_start_final_process_spawn_executor_gate_service_ready' => $gateReady,
            'generic_post_start_final_process_spawn_executor_gate_canonical_method' => 'preparePostStartFinalProcessSpawn',
            'codex_process_spawn_executor_service' => AgentCodexProcessSpawnExecutor::class,
            'codex_process_spawn_executor_ready' => $spawnExecutorReady,
            'codex_process_spawn_executor_canonical_method' => 'prepareProcessSpawn',
            'post_start_process_spawn_enablement_gate_service' => AgentCodexRealInvokerPostStartProcessSpawnEnablementGate::class,
            'post_start_process_spawn_enablement_gate_ready' => $spawnEnablementGateReady,
            'agent_runs_table_ready' => $runsTableReady,
            'ledger_table_ready' => $ledgerTableReady,
            'codex_real_invoker_post_start_final_process_spawn_executor_prepared_run_count' => $observedFinalSpawnRunsQuery === null ? null : (clone $observedFinalSpawnRunsQuery)->count(),
            'provider_start_runs_with_codex_process_spawn_executor_count' => $providerRunsWithSpawnExecutorQuery === null ? null : (clone $providerRunsWithSpawnExecutorQuery)->count(),
            'latest_codex_real_invoker_post_start_final_process_spawn_executor_run' => $latestObservedFinalSpawnRun instanceof AtlasSelfConstructionAgentRun
                ? [
                    'agent_run_id' => $latestObservedFinalSpawnRun->id,
                    'run_key' => $latestObservedFinalSpawnRun->run_key,
                    'packet_id' => $latestObservedFinalSpawnRun->packet_id,
                    'provider' => $latestObservedFinalSpawnRun->provider,
                    'status' => $latestObservedFinalSpawnRun->status,
                    'post_start_final_process_spawn_executor_gate_id' => data_get($latestObservedFinalSpawnRun->metadata, 'codex_real_invoker_post_start_final_process_spawn_executor.post_start_final_process_spawn_executor_gate_id'),
                    'post_start_process_spawn_enablement_gate_id' => data_get($latestObservedFinalSpawnRun->metadata, 'codex_real_invoker_post_start_final_process_spawn_executor.post_start_process_spawn_enablement_gate_id'),
                    'spawn_executor_id' => data_get($latestObservedFinalSpawnRun->metadata, 'codex_real_invoker_post_start_final_process_spawn_executor.spawn_executor_id'),
                    'spawn_enablement_id' => data_get($latestObservedFinalSpawnRun->metadata, 'codex_real_invoker_post_start_final_process_spawn_executor.spawn_enablement_id'),
                    'post_start_evidence_acceptance_bridge_id' => data_get($latestObservedFinalSpawnRun->metadata, 'codex_real_invoker_post_start_final_process_spawn_executor.post_start_evidence_acceptance_bridge_id'),
                    'process_spawn_executor_prepared' => data_get($latestObservedFinalSpawnRun->metadata, 'codex_real_invoker_post_start_final_process_spawn_executor.process_spawn_executor_prepared'),
                    'external_process_runtime_required' => data_get($latestObservedFinalSpawnRun->metadata, 'codex_real_invoker_post_start_final_process_spawn_executor.external_process_runtime_required'),
                    'actual_process_start_allowed' => data_get($latestObservedFinalSpawnRun->metadata, 'codex_real_invoker_post_start_final_process_spawn_executor.actual_process_start_allowed'),
                    'token_spend_allowed' => data_get($latestObservedFinalSpawnRun->metadata, 'codex_real_invoker_post_start_final_process_spawn_executor.token_spend_allowed'),
                ]
                : null,
            'runtime_policy' => [
                'status_projection_is_read_only' => true,
                'invoker_may_prepare_final_process_spawn_executor_when_called_with_signed_input' => true,
                'final_process_spawn_executor_is_not_external_process_runtime' => true,
                'external_process_runtime_required_before_invocation' => true,
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
                ? 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_external_process_runtime_gate_contract'
                : 'repair_one_shot_scheduler_tick_codex_real_invoker_post_start_final_process_spawn_executor_gate_service_blockers',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_final_process_spawn_executor_gate_status.v1',
            'status' => (string) $status['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_final_process_spawn_executor_gate_status',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'post_start_final_process_spawn_executor_gate_allowed' => false,
            'actual_process_start_allowed' => false,
            'provider_process_call_allowed' => false,
            'adapter_invocation_allowed' => false,
            'adapter_execution_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_final_process_spawn_executor_gate_status' => $status,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_final_process_spawn_executor_gate_status_hash' => $this->section->stableHash($status),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_final_process_spawn_executor_gate_status_does_not_call_final_process_spawn_executor_gate',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_final_process_spawn_executor_gate_status_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_final_process_spawn_executor_gate_status_does_not_execute_adapter',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_final_process_spawn_executor_gate_status_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_final_process_spawn_executor_gate_status_does_not_dispatch_work',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_final_process_spawn_executor_gate_status_does_not_enable_self_programming',
            ],
            'human_summary' => $statusReady
                ? 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start final process spawn executor gate service is ready and inspectable; external process runtime remains separate.'
                : 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start final process spawn executor gate service is blocked until invoker, generic gate, process spawn executor and storage are ready.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartExternalProcessRuntimeGateStatus(array $options = []): array
    {
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerTableReady = Schema::hasTable('atlas_ledger_events');
        $gateReady = class_exists(AgentCodexRealInvokerPostStartExternalProcessRuntimeGate::class)
            && method_exists(AgentCodexRealInvokerPostStartExternalProcessRuntimeGate::class, 'preparePostStartExternalRuntime');
        $invokerReady = class_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartExternalProcessRuntimeGateInvoker::class)
            && method_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartExternalProcessRuntimeGateInvoker::class, 'prepareCodexRealInvokerPostStartExternalProcessRuntimeGate');
        $externalRuntimeDriverReady = class_exists(AgentCodexExternalProcessRuntimeDriver::class)
            && method_exists(AgentCodexExternalProcessRuntimeDriver::class, 'prepareExternalRuntime');
        $finalSpawnGateReady = class_exists(AgentCodexRealInvokerPostStartFinalProcessSpawnExecutorGate::class)
            && method_exists(AgentCodexRealInvokerPostStartFinalProcessSpawnExecutorGate::class, 'preparePostStartFinalProcessSpawn');

        $observedRuntimeRunsQuery = $runsTableReady
            ? AtlasSelfConstructionAgentRun::query()
                ->whereNotNull('metadata->codex_real_invoker_post_start_external_process_runtime->runtime_driver_id')
            : null;
        $providerRunsWithRuntimeQuery = $runsTableReady
            ? AtlasSelfConstructionAgentRun::query()
                ->where('run_key', 'like', 'provider-start:%')
                ->where('status', 'adapter_invocation_prepared')
                ->whereNotNull('metadata->codex_external_process_runtime_driver->runtime_driver_id')
            : null;
        $latestObservedRuntimeRun = $observedRuntimeRunsQuery === null
            ? null
            : (clone $observedRuntimeRunsQuery)->latest('updated_at')->first();

        $statusReady = $runsTableReady
            && $ledgerTableReady
            && $gateReady
            && $invokerReady
            && $externalRuntimeDriverReady
            && $finalSpawnGateReady;
        $status = [
            'status' => $statusReady ? 'one_shot_tick_codex_real_invoker_post_start_external_process_runtime_gate_service_ready' : 'blocked',
            'provider' => 'codex',
            'adapter' => 'codex',
            'invoker_service' => AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartExternalProcessRuntimeGateInvoker::class,
            'invoker_service_ready' => $invokerReady,
            'invoker_canonical_method' => 'prepareCodexRealInvokerPostStartExternalProcessRuntimeGate',
            'generic_post_start_external_process_runtime_gate_service' => AgentCodexRealInvokerPostStartExternalProcessRuntimeGate::class,
            'generic_post_start_external_process_runtime_gate_service_ready' => $gateReady,
            'generic_post_start_external_process_runtime_gate_canonical_method' => 'preparePostStartExternalRuntime',
            'codex_external_process_runtime_driver_service' => AgentCodexExternalProcessRuntimeDriver::class,
            'codex_external_process_runtime_driver_ready' => $externalRuntimeDriverReady,
            'codex_external_process_runtime_driver_canonical_method' => 'prepareExternalRuntime',
            'post_start_final_process_spawn_executor_gate_service' => AgentCodexRealInvokerPostStartFinalProcessSpawnExecutorGate::class,
            'post_start_final_process_spawn_executor_gate_ready' => $finalSpawnGateReady,
            'agent_runs_table_ready' => $runsTableReady,
            'ledger_table_ready' => $ledgerTableReady,
            'codex_real_invoker_post_start_external_process_runtime_prepared_run_count' => $observedRuntimeRunsQuery === null ? null : (clone $observedRuntimeRunsQuery)->count(),
            'provider_start_runs_with_codex_external_process_runtime_count' => $providerRunsWithRuntimeQuery === null ? null : (clone $providerRunsWithRuntimeQuery)->count(),
            'latest_codex_real_invoker_post_start_external_process_runtime_run' => $latestObservedRuntimeRun instanceof AtlasSelfConstructionAgentRun
                ? [
                    'agent_run_id' => $latestObservedRuntimeRun->id,
                    'run_key' => $latestObservedRuntimeRun->run_key,
                    'packet_id' => $latestObservedRuntimeRun->packet_id,
                    'provider' => $latestObservedRuntimeRun->provider,
                    'status' => $latestObservedRuntimeRun->status,
                    'post_start_external_process_runtime_gate_id' => data_get($latestObservedRuntimeRun->metadata, 'codex_real_invoker_post_start_external_process_runtime.post_start_external_process_runtime_gate_id'),
                    'post_start_final_process_spawn_executor_gate_id' => data_get($latestObservedRuntimeRun->metadata, 'codex_real_invoker_post_start_external_process_runtime.post_start_final_process_spawn_executor_gate_id'),
                    'runtime_driver_id' => data_get($latestObservedRuntimeRun->metadata, 'codex_real_invoker_post_start_external_process_runtime.runtime_driver_id'),
                    'spawn_executor_id' => data_get($latestObservedRuntimeRun->metadata, 'codex_real_invoker_post_start_external_process_runtime.spawn_executor_id'),
                    'post_start_evidence_acceptance_bridge_id' => data_get($latestObservedRuntimeRun->metadata, 'codex_real_invoker_post_start_external_process_runtime.post_start_evidence_acceptance_bridge_id'),
                    'external_runtime_driver_prepared' => data_get($latestObservedRuntimeRun->metadata, 'codex_real_invoker_post_start_external_process_runtime.external_runtime_driver_prepared'),
                    'process_invocation_required' => data_get($latestObservedRuntimeRun->metadata, 'codex_real_invoker_post_start_external_process_runtime.process_invocation_required'),
                    'actual_process_start_allowed' => data_get($latestObservedRuntimeRun->metadata, 'codex_real_invoker_post_start_external_process_runtime.actual_process_start_allowed'),
                    'token_spend_allowed' => data_get($latestObservedRuntimeRun->metadata, 'codex_real_invoker_post_start_external_process_runtime.token_spend_allowed'),
                ]
                : null,
            'runtime_policy' => [
                'status_projection_is_read_only' => true,
                'invoker_may_prepare_external_process_runtime_when_called_with_signed_input' => true,
                'external_process_runtime_is_not_process_invocation' => true,
                'process_invocation_authorization_required_before_invocation' => true,
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
                ? 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_process_invocation_authorization_gate_contract'
                : 'repair_one_shot_scheduler_tick_codex_real_invoker_post_start_external_process_runtime_gate_service_blockers',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_external_process_runtime_gate_status.v1',
            'status' => (string) $status['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_external_process_runtime_gate_status',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'post_start_external_process_runtime_gate_allowed' => false,
            'actual_process_start_allowed' => false,
            'provider_process_call_allowed' => false,
            'adapter_invocation_allowed' => false,
            'adapter_execution_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_external_process_runtime_gate_status' => $status,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_external_process_runtime_gate_status_hash' => $this->section->stableHash($status),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_external_process_runtime_gate_status_does_not_call_external_process_runtime_gate',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_external_process_runtime_gate_status_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_external_process_runtime_gate_status_does_not_execute_adapter',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_external_process_runtime_gate_status_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_external_process_runtime_gate_status_does_not_dispatch_work',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_external_process_runtime_gate_status_does_not_enable_self_programming',
            ],
            'human_summary' => $statusReady
                ? 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start external process runtime gate service is ready and inspectable; process invocation authorization remains separate.'
                : 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start external process runtime gate service is blocked until invoker, generic gate, runtime driver and storage are ready.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProcessInvocationAuthorizationGateStatus(array $options = []): array
    {
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerTableReady = Schema::hasTable('atlas_ledger_events');
        $gateReady = class_exists(AgentCodexRealInvokerPostStartProcessInvocationAuthorizationGate::class)
            && method_exists(AgentCodexRealInvokerPostStartProcessInvocationAuthorizationGate::class, 'authorizePostStartProcessInvocation');
        $invokerReady = class_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProcessInvocationAuthorizationGateInvoker::class)
            && method_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProcessInvocationAuthorizationGateInvoker::class, 'authorizeCodexRealInvokerPostStartProcessInvocationAuthorizationGate');
        $authorizationGateReady = class_exists(AgentCodexExternalProcessInvocationAuthorizationGate::class)
            && method_exists(AgentCodexExternalProcessInvocationAuthorizationGate::class, 'authorizeExternalProcessInvocation');
        $externalRuntimeGateReady = class_exists(AgentCodexRealInvokerPostStartExternalProcessRuntimeGate::class)
            && method_exists(AgentCodexRealInvokerPostStartExternalProcessRuntimeGate::class, 'preparePostStartExternalRuntime');

        $observedAuthorizationRunsQuery = $runsTableReady
            ? AtlasSelfConstructionAgentRun::query()
                ->whereNotNull('metadata->codex_real_invoker_post_start_process_invocation_authorization->invocation_authorization_id')
            : null;
        $providerRunsWithAuthorizationQuery = $runsTableReady
            ? AtlasSelfConstructionAgentRun::query()
                ->where('run_key', 'like', 'provider-start:%')
                ->where('status', 'adapter_invocation_prepared')
                ->whereNotNull('metadata->codex_external_process_invocation_authorization->invocation_authorization_id')
            : null;
        $latestObservedAuthorizationRun = $observedAuthorizationRunsQuery === null
            ? null
            : (clone $observedAuthorizationRunsQuery)->latest('updated_at')->first();

        $statusReady = $runsTableReady
            && $ledgerTableReady
            && $gateReady
            && $invokerReady
            && $authorizationGateReady
            && $externalRuntimeGateReady;
        $status = [
            'status' => $statusReady ? 'one_shot_tick_codex_real_invoker_post_start_process_invocation_authorization_gate_service_ready' : 'blocked',
            'provider' => 'codex',
            'adapter' => 'codex',
            'invoker_service' => AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProcessInvocationAuthorizationGateInvoker::class,
            'invoker_service_ready' => $invokerReady,
            'invoker_canonical_method' => 'authorizeCodexRealInvokerPostStartProcessInvocationAuthorizationGate',
            'generic_post_start_process_invocation_authorization_gate_service' => AgentCodexRealInvokerPostStartProcessInvocationAuthorizationGate::class,
            'generic_post_start_process_invocation_authorization_gate_service_ready' => $gateReady,
            'generic_post_start_process_invocation_authorization_gate_canonical_method' => 'authorizePostStartProcessInvocation',
            'codex_external_process_invocation_authorization_gate_service' => AgentCodexExternalProcessInvocationAuthorizationGate::class,
            'codex_external_process_invocation_authorization_gate_ready' => $authorizationGateReady,
            'codex_external_process_invocation_authorization_gate_canonical_method' => 'authorizeExternalProcessInvocation',
            'post_start_external_process_runtime_gate_service' => AgentCodexRealInvokerPostStartExternalProcessRuntimeGate::class,
            'post_start_external_process_runtime_gate_ready' => $externalRuntimeGateReady,
            'agent_runs_table_ready' => $runsTableReady,
            'ledger_table_ready' => $ledgerTableReady,
            'codex_real_invoker_post_start_process_invocation_authorized_run_count' => $observedAuthorizationRunsQuery === null ? null : (clone $observedAuthorizationRunsQuery)->count(),
            'provider_start_runs_with_codex_external_process_invocation_authorization_count' => $providerRunsWithAuthorizationQuery === null ? null : (clone $providerRunsWithAuthorizationQuery)->count(),
            'latest_codex_real_invoker_post_start_process_invocation_authorization_run' => $latestObservedAuthorizationRun instanceof AtlasSelfConstructionAgentRun
                ? [
                    'agent_run_id' => $latestObservedAuthorizationRun->id,
                    'run_key' => $latestObservedAuthorizationRun->run_key,
                    'packet_id' => $latestObservedAuthorizationRun->packet_id,
                    'provider' => $latestObservedAuthorizationRun->provider,
                    'status' => $latestObservedAuthorizationRun->status,
                    'post_start_process_invocation_authorization_gate_id' => data_get($latestObservedAuthorizationRun->metadata, 'codex_real_invoker_post_start_process_invocation_authorization.post_start_process_invocation_authorization_gate_id'),
                    'post_start_external_process_runtime_gate_id' => data_get($latestObservedAuthorizationRun->metadata, 'codex_real_invoker_post_start_process_invocation_authorization.post_start_external_process_runtime_gate_id'),
                    'invocation_authorization_id' => data_get($latestObservedAuthorizationRun->metadata, 'codex_real_invoker_post_start_process_invocation_authorization.invocation_authorization_id'),
                    'runtime_driver_id' => data_get($latestObservedAuthorizationRun->metadata, 'codex_real_invoker_post_start_process_invocation_authorization.runtime_driver_id'),
                    'post_start_evidence_acceptance_bridge_id' => data_get($latestObservedAuthorizationRun->metadata, 'codex_real_invoker_post_start_process_invocation_authorization.post_start_evidence_acceptance_bridge_id'),
                    'external_process_invocation_authorized' => data_get($latestObservedAuthorizationRun->metadata, 'codex_real_invoker_post_start_process_invocation_authorization.external_process_invocation_authorized'),
                    'external_process_invoker_dry_run_required' => data_get($latestObservedAuthorizationRun->metadata, 'codex_real_invoker_post_start_process_invocation_authorization.external_process_invoker_dry_run_required'),
                    'actual_process_start_allowed' => data_get($latestObservedAuthorizationRun->metadata, 'codex_real_invoker_post_start_process_invocation_authorization.actual_process_start_allowed'),
                    'token_spend_allowed' => data_get($latestObservedAuthorizationRun->metadata, 'codex_real_invoker_post_start_process_invocation_authorization.token_spend_allowed'),
                ]
                : null,
            'runtime_policy' => [
                'status_projection_is_read_only' => true,
                'invoker_may_authorize_process_invocation_when_called_with_signed_input' => true,
                'process_invocation_authorization_is_not_process_invocation' => true,
                'external_process_invoker_dry_run_required_before_invocation' => true,
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
                ? 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_external_process_invoker_dry_run_gate_contract'
                : 'repair_one_shot_scheduler_tick_codex_real_invoker_post_start_process_invocation_authorization_gate_service_blockers',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_invocation_authorization_gate_status.v1',
            'status' => (string) $status['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_invocation_authorization_gate_status',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'post_start_process_invocation_authorization_gate_allowed' => false,
            'actual_process_start_allowed' => false,
            'provider_process_call_allowed' => false,
            'adapter_invocation_allowed' => false,
            'adapter_execution_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_invocation_authorization_gate_status' => $status,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_invocation_authorization_gate_status_hash' => $this->section->stableHash($status),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_invocation_authorization_gate_status_does_not_call_authorization_gate',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_invocation_authorization_gate_status_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_invocation_authorization_gate_status_does_not_execute_adapter',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_invocation_authorization_gate_status_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_invocation_authorization_gate_status_does_not_dispatch_work',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_invocation_authorization_gate_status_does_not_enable_self_programming',
            ],
            'human_summary' => $statusReady
                ? 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start process invocation authorization gate service is ready and inspectable; external process invoker dry-run remains separate.'
                : 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start process invocation authorization gate service is blocked until invoker, generic gate, authorization gate and storage are ready.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProviderStartDriverGateContract(array $options = []): array
    {
        $receiptUseStatusPayload = $this->section->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartDispatchReceiptUseExecutorStatus($options);
        $receiptUseStatus = (array) data_get($receiptUseStatusPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_dispatch_receipt_use_executor_status', []);
        $providerStartPayload = $this->section->agentCodexRealInvokerPostStartProviderStartDriverGateContractTemplate($options);

        $contract = [
            'status' => 'one_shot_tick_codex_real_invoker_post_start_provider_start_driver_gate_contract_ready',
            'contract_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-REAL-INVOKER-POST-START-PROVIDER-START-DRIVER-GATE-CONTRACT-SELF-CONSTRUCTION-0001',
            'parent_program' => 'Atlas Self-Construction OS',
            'submodule' => 'Atlas Agent Control Plane',
            'provider' => 'codex',
            'adapter' => 'codex',
            'source_codex_real_invoker_post_start_dispatch_receipt_use_executor_status' => data_get($receiptUseStatus, 'status'),
            'source_codex_real_invoker_post_start_dispatch_receipt_use_executor_status_hash' => data_get($receiptUseStatusPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_dispatch_receipt_use_executor_status_hash'),
            'source_codex_real_invoker_post_start_provider_start_driver_gate_status' => data_get($providerStartPayload, 'status'),
            'source_codex_real_invoker_post_start_provider_start_driver_gate_hash' => data_get($providerStartPayload, 'codex_real_invoker_post_start_provider_start_driver_gate_contract_template_hash'),
            'provider_start_driver_boundary' => [
                'canonical_post_start_provider_start_driver_gate' => AgentCodexRealInvokerPostStartProviderStartDriverGate::class,
                'canonical_post_start_provider_start_driver_gate_method' => 'preparePostStartProviderStartDriver',
                'scheduler_invoker' => AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProviderStartDriverGateInvoker::class,
                'scheduler_invoker_method' => 'prepareCodexRealInvokerPostStartProviderStartDriverGate',
                'provider_start_driver_effect' => 'create_or_reuse_pre_start_guarded_provider_start_projection_after_receipt_use_without_spawning_codex',
                'post_start_dispatch_receipt_use_required_before_provider_start_driver' => true,
                'post_start_evidence_acceptance_bridge_required_before_provider_start_driver' => true,
                'signed_dispatch_authorization_required_before_provider_start_driver' => true,
                'sandbox_binding_required_before_provider_start_driver' => true,
                'executor_release_authorization_required_before_provider_start_driver' => true,
                'signed_dispatch_receipt_hash_required' => true,
                'executor_contract_hash_required' => true,
                'executor_release_authorization_hash_required' => true,
                'executor_handoff_packet_hash_required' => true,
                'executor_workspace_hash_required' => true,
                'executor_scope_lock_hash_required' => true,
                'pre_start_guarded_run_may_be_created_by_driver' => true,
                'pre_start_heartbeat_may_be_written_by_driver' => true,
                'observed_external_process_started_required_before_bridge' => true,
                'observed_provider_started_required_before_bridge' => true,
                'actual_process_start_allowed_by_contract' => false,
                'driver_provider_started_by_contract' => false,
                'provider_process_call_allowed_by_contract' => false,
                'adapter_invocation_allowed_by_contract' => false,
                'adapter_execution_allowed_by_contract' => false,
                'token_spend_allowed_by_contract' => false,
                'dispatch_allowed_by_contract' => false,
                'idempotency_key' => 'provider_start_attempt_id',
            ],
            'required_input_fields_for_future_invoker' => [
                'run_key',
                'provider_start_driver_gate_id',
                'provider_start_attempt_id',
                'dispatch_executor_handoff_id',
                'signed_dispatch_authorization_id',
                'post_start_evidence_acceptance_bridge_id',
                'signed_dispatch_receipt_hash',
                'executor_contract_hash',
                'executor_release_authorization_hash',
                'executor_handoff_packet_hash',
                'executor_workspace_hash',
                'executor_scope_lock_hash',
                'sandbox_binding_key',
                'command',
                'cwd',
                'actor',
                'session',
                'max_runtime_minutes',
                'max_cost_usd',
                'reason',
            ],
            'allowed_future_mutations' => [
                'create_or_reuse_pre_start_guarded_provider_start_projection_run',
                'write_pre_start_guard_heartbeat',
                'append_provider_start_driver_bridge_evidence_event',
                'record_codex_real_invoker_post_start_provider_start_driver_metadata_on_observed_run',
            ],
            'forbidden_even_after_contract' => [
                'spawn_codex_process',
                'call_codex_cli_or_codex_app',
                'dispatch_work_to_codex',
                'send_prompt_to_provider',
                'call_provider_process',
                'enable_adapter_invocation',
                'enable_adapter_execution',
                'spend_provider_tokens',
                'mark_observed_run_running_or_terminal',
                'enable_self_programming',
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_provider_start_driver_gate_preflight',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_provider_start_driver_gate_contract.v1',
            'status' => (string) $contract['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_provider_start_driver_gate_contract',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'post_start_provider_start_driver_gate_allowed' => false,
            'actual_process_start_allowed' => false,
            'adapter_invocation_allowed' => false,
            'adapter_execution_allowed' => false,
            'provider_start_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_provider_start_driver_gate_contract' => $contract,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_provider_start_driver_gate_contract_hash' => $this->section->stableHash($contract),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_provider_start_driver_gate_contract_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_provider_start_driver_gate_contract_does_not_call_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_provider_start_driver_gate_contract_does_not_enable_adapter_invocation',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_provider_start_driver_gate_contract_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_provider_start_driver_gate_contract_does_not_dispatch_work',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_provider_start_driver_gate_contract_does_not_enable_self_programming',
            ],
            'human_summary' => 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start provider start driver gate contract is ready; it bridges used receipt metadata to the provider start driver without starting Codex.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartSupervisedStartExecutorGateContract(array $options = []): array
    {
        $processStartReleasePayload = $this->section->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProcessStartReleaseGateStatus($options);
        $processStartReleaseStatus = (array) data_get($processStartReleasePayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_start_release_gate_status', []);
        $supervisedStartPayload = $this->section->agentCodexRealInvokerPostStartSupervisedStartExecutorGateContractTemplate($options);

        $contract = [
            'status' => 'one_shot_tick_codex_real_invoker_post_start_supervised_start_executor_gate_contract_ready',
            'contract_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-REAL-INVOKER-POST-START-SUPERVISED-START-EXECUTOR-GATE-CONTRACT-SELF-CONSTRUCTION-0001',
            'parent_program' => 'Atlas Self-Construction OS',
            'submodule' => 'Atlas Agent Control Plane',
            'provider' => 'codex',
            'adapter' => 'codex',
            'source_codex_real_invoker_post_start_process_start_release_gate_status' => data_get($processStartReleaseStatus, 'status'),
            'source_codex_real_invoker_post_start_process_start_release_gate_status_hash' => data_get($processStartReleasePayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_start_release_gate_status_hash'),
            'source_codex_real_invoker_post_start_supervised_start_executor_gate_status' => data_get($supervisedStartPayload, 'status'),
            'source_codex_real_invoker_post_start_supervised_start_executor_gate_hash' => data_get($supervisedStartPayload, 'codex_real_invoker_post_start_supervised_start_executor_gate_contract_template_hash'),
            'supervised_start' => [
                'canonical_post_start_supervised_start_executor_gate' => AgentCodexRealInvokerPostStartSupervisedStartExecutorGate::class,
                'canonical_post_start_supervised_start_executor_gate_method' => 'preparePostStartSupervisedStart',
                'scheduler_invoker' => AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartSupervisedStartExecutorGateInvoker::class,
                'scheduler_invoker_method' => 'prepareCodexRealInvokerPostStartSupervisedStartExecutorGate',
                'codex_supervised_start_executor' => AgentCodexSupervisedStartExecutor::class,
                'codex_supervised_start_executor_method' => 'prepareSupervisedStart',
                'supervised_start_effect' => 'prepare_codex_supervised_start_without_spawning_codex',
                'post_start_process_start_release_required_before_supervised_start' => true,
                'post_start_evidence_acceptance_bridge_required_before_supervised_start' => true,
                'provider_start_run_with_codex_process_start_release_required_before_supervised_start' => true,
                'stdout_stderr_sanitizer_hash_required' => true,
                'ready_probe_plan_hash_required' => true,
                'rollback_plan_hash_required' => true,
                'gate_delegates_to_codex_supervised_start_executor' => true,
                'gate_records_codex_supervised_start_metadata_on_provider_start_run' => true,
                'gate_records_bridge_metadata_on_observed_run' => true,
                'process_spawn_enablement_required_after_supervised_start' => true,
                'supervised_start_preparation_is_not_process_spawn' => true,
                'actual_process_start_allowed_by_contract' => false,
                'provider_process_call_allowed_by_contract' => false,
                'adapter_invocation_allowed_by_contract' => false,
                'adapter_execution_allowed_by_contract' => false,
                'token_spend_allowed_by_contract' => false,
                'dispatch_allowed_by_contract' => false,
                'idempotency_key' => 'supervised_start_id',
            ],
            'required_input_fields_for_future_invoker' => [
                'run_key',
                'post_start_supervised_start_gate_id',
                'post_start_process_start_release_gate_id',
                'process_start_release_id',
                'provider_execution_contract_gate_id',
                'codex_execution_id',
                'supervised_start_id',
                'adapter_execution_guard_gate_id',
                'execution_guard_id',
                'adapter_invocation_boundary_gate_id',
                'adapter_invocation_id',
                'provider_start_driver_gate_id',
                'provider_start_attempt_id',
                'post_start_evidence_acceptance_bridge_id',
                'signed_dispatch_receipt_hash',
                'operator_release_receipt_hash',
                'codex_execution_contract_hash',
                'stdout_stderr_sanitizer_hash',
                'ready_probe_plan_hash',
                'rollback_plan_hash',
                'actor',
                'session',
                'reason',
            ],
            'allowed_future_mutations' => [
                'record_codex_supervised_start_metadata_on_provider_start_run',
                'append_codex_supervised_start_preparation_evidence_event',
                'record_codex_real_invoker_post_start_supervised_start_metadata_on_observed_run',
            ],
            'forbidden_even_after_contract' => [
                'spawn_codex_process',
                'call_codex_cli_or_codex_app',
                'run_process_spawn_enablement',
                'dispatch_work_to_codex',
                'send_prompt_to_provider',
                'call_provider_process',
                'enable_adapter_execution',
                'spend_provider_tokens',
                'mark_observed_run_running_or_terminal',
                'enable_self_programming',
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_supervised_start_executor_gate_preflight',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_supervised_start_executor_gate_contract.v1',
            'status' => (string) $contract['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_supervised_start_executor_gate_contract',
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
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_supervised_start_executor_gate_contract' => $contract,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_supervised_start_executor_gate_contract_hash' => $this->section->stableHash($contract),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_supervised_start_executor_gate_contract_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_supervised_start_executor_gate_contract_does_not_call_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_supervised_start_executor_gate_contract_does_not_execute_adapter',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_supervised_start_executor_gate_contract_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_supervised_start_executor_gate_contract_does_not_dispatch_work',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_supervised_start_executor_gate_contract_does_not_enable_self_programming',
            ],
            'human_summary' => 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start supervised start executor gate contract is ready; it prepares supervised start and still does not spawn Codex.',
        ];
    }


}
