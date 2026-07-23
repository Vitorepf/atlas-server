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
 * DISPATCH BATCH 1 projection sub-section 03 of 5, sub-split from the god
 * {@see \App\Services\Ai\SelfConstruction\Readiness\ReadinessProjectionAgentAutomaticDispatchBatch1Section}
 * (GOD-DEBULK). Method bodies are byte-identical to the parent Section; the
 * only rewrite is that sibling/back calls route through the injected Section
 * facade ($this->section->*), which re-dispatches to whichever sub-section
 * owns the target method or forwards to the bound mother via the facade
 * __call.
 */
final class DispatchBatch1Part03SubSection
{
    public function __construct(
        private readonly ReadinessProjectionAgentAutomaticDispatchBatch1Section $section,
    ) {}

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProcessSpawnEnablementGateContract(array $options = []): array
    {
        $supervisedPayload = $this->section->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartSupervisedStartExecutorGateStatus($options);
        $supervisedStatus = (array) data_get($supervisedPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_supervised_start_executor_gate_status', []);
        $processSpawnEnablementPayload = $this->section->agentCodexRealInvokerPostStartProcessSpawnEnablementGateContractTemplate($options);

        $contract = [
            'status' => 'one_shot_tick_codex_real_invoker_post_start_process_spawn_enablement_gate_contract_ready',
            'contract_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-REAL-INVOKER-POST-START-PROCESS-SPAWN-ENABLEMENT-GATE-CONTRACT-SELF-CONSTRUCTION-0001',
            'parent_program' => 'Atlas Self-Construction OS',
            'submodule' => 'Atlas Agent Control Plane',
            'provider' => 'codex',
            'adapter' => 'codex',
            'source_codex_real_invoker_post_start_supervised_start_executor_gate_status' => data_get($supervisedStatus, 'status'),
            'source_codex_real_invoker_post_start_supervised_start_executor_gate_status_hash' => data_get($supervisedPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_supervised_start_executor_gate_status_hash'),
            'source_codex_real_invoker_post_start_process_spawn_enablement_gate_status' => data_get($processSpawnEnablementPayload, 'status'),
            'source_codex_real_invoker_post_start_process_spawn_enablement_gate_hash' => data_get($processSpawnEnablementPayload, 'codex_real_invoker_post_start_process_spawn_enablement_gate_contract_template_hash'),
            'process_spawn_enablement' => [
                'canonical_post_start_process_spawn_enablement_gate' => AgentCodexRealInvokerPostStartProcessSpawnEnablementGate::class,
                'canonical_post_start_process_spawn_enablement_gate_method' => 'enablePostStartProcessSpawn',
                'scheduler_invoker' => AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProcessSpawnEnablementGateInvoker::class,
                'scheduler_invoker_method' => 'enableCodexRealInvokerPostStartProcessSpawnEnablementGate',
                'codex_process_spawn_enablement_gate' => AgentCodexProcessSpawnEnablementGate::class,
                'codex_process_spawn_enablement_gate_method' => 'enableCodexProcessSpawn',
                'process_spawn_enablement_effect' => 'record_process_spawn_enablement_without_spawning_codex',
                'post_start_supervised_start_required_before_enablement' => true,
                'post_start_evidence_acceptance_bridge_required_before_enablement' => true,
                'provider_start_run_with_codex_supervised_start_required_before_enablement' => true,
                'operator_spawn_receipt_hash_required' => true,
                'supervised_start_contract_hash_required' => true,
                'gate_delegates_to_codex_process_spawn_enablement_gate' => true,
                'gate_records_codex_process_spawn_enablement_metadata_on_provider_start_run' => true,
                'gate_records_bridge_metadata_on_observed_run' => true,
                'final_process_spawn_executor_required_after_enablement' => true,
                'process_spawn_enablement_is_not_process_spawn' => true,
                'actual_process_start_allowed_by_contract' => false,
                'provider_process_call_allowed_by_contract' => false,
                'adapter_invocation_allowed_by_contract' => false,
                'adapter_execution_allowed_by_contract' => false,
                'token_spend_allowed_by_contract' => false,
                'dispatch_allowed_by_contract' => false,
                'idempotency_key' => 'spawn_enablement_id',
            ],
            'required_input_fields_for_future_invoker' => [
                'run_key',
                'post_start_process_spawn_enablement_gate_id',
                'post_start_supervised_start_gate_id',
                'post_start_process_start_release_gate_id',
                'process_start_release_id',
                'provider_execution_contract_gate_id',
                'codex_execution_id',
                'supervised_start_id',
                'spawn_enablement_id',
                'adapter_execution_guard_gate_id',
                'execution_guard_id',
                'adapter_invocation_boundary_gate_id',
                'adapter_invocation_id',
                'provider_start_driver_gate_id',
                'provider_start_attempt_id',
                'post_start_evidence_acceptance_bridge_id',
                'signed_dispatch_receipt_hash',
                'operator_release_receipt_hash',
                'operator_spawn_receipt_hash',
                'codex_execution_contract_hash',
                'supervised_start_contract_hash',
                'actor',
                'session',
                'reason',
            ],
            'allowed_future_mutations' => [
                'record_codex_process_spawn_enablement_metadata_on_provider_start_run',
                'append_codex_process_spawn_enablement_recorded_evidence_event',
                'record_codex_real_invoker_post_start_process_spawn_enablement_metadata_on_observed_run',
            ],
            'forbidden_even_after_contract' => [
                'spawn_codex_process',
                'call_codex_cli_or_codex_app',
                'run_final_process_spawn_executor',
                'dispatch_work_to_codex',
                'send_prompt_to_provider',
                'call_provider_process',
                'enable_adapter_execution',
                'spend_provider_tokens',
                'mark_observed_run_running_or_terminal',
                'enable_self_programming',
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_process_spawn_enablement_gate_preflight',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_spawn_enablement_gate_contract.v1',
            'status' => (string) $contract['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_spawn_enablement_gate_contract',
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
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_spawn_enablement_gate_contract' => $contract,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_spawn_enablement_gate_contract_hash' => $this->section->stableHash($contract),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_spawn_enablement_gate_contract_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_spawn_enablement_gate_contract_does_not_call_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_spawn_enablement_gate_contract_does_not_execute_adapter',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_spawn_enablement_gate_contract_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_spawn_enablement_gate_contract_does_not_dispatch_work',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_spawn_enablement_gate_contract_does_not_enable_self_programming',
            ],
            'human_summary' => 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start process spawn enablement gate contract is ready; it records enablement and still does not spawn Codex.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickGuardedRuntimeInvocationImplementationPacket(array $options = []): array
    {
        $preflightPayload = $this->section->agentAutomaticDispatchSchedulerOneShotTickGuardedRuntimeInvocationPreflight($options);
        $preflight = (array) data_get($preflightPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_guarded_runtime_invocation_preflight', []);
        $preflightHash = (string) data_get($preflightPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_guarded_runtime_invocation_preflight_hash');

        $allowedFiles = [
            'app/Services/Ai/SelfConstruction/ControlPlane/AgentAutomaticDispatchSchedulerOneShotTickGuardedRuntimeInvoker.php',
            'tests/Feature/Ai/AtlasAiSelfConstructionAgentAutomaticDispatchSchedulerOneShotTickGuardedRuntimeInvokerTest.php',
            'app/Services/Ai/SelfConstruction/Readiness/AtlasSelfConstructionReadinessService.php',
            'app/Console/Commands/AtlasAiSelfConstructionCommand.php',
            'tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php',
            'docs/engineering-knowledge-base/self-construction/agent-control-plane-contract.md',
        ];

        $packet = [
            'status' => 'ready_for_scoped_one_shot_tick_guarded_runtime_invocation_implementation',
            'packet_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-GUARDED-RUNTIME-INVOCATION-IMPLEMENTATION-PACKET-SELF-CONSTRUCTION-0001',
            'parent_program' => 'Atlas Self-Construction OS',
            'submodule' => 'Atlas Agent Control Plane',
            'workspace_id' => 'FORGE-WORKSPACE-ATLAS-SELF-CONSTRUCTION-0001',
            'source_guarded_invocation_preflight_status' => data_get($preflight, 'status'),
            'source_guarded_invocation_preflight_hash' => $preflightHash,
            'allowed_files' => $allowedFiles,
            'tasks' => [
                [
                    'id' => 'T1',
                    'title' => 'Create guarded runtime invoker boundary',
                    'type' => 'service',
                    'acceptance' => 'Invoker validates input shape, calls AgentAutomaticDispatchSchedulerOneShotTickMutatingWriter exactly once and returns its result without using dispatch receipts.',
                ],
                [
                    'id' => 'T2',
                    'title' => 'Expose guarded invocation status/run surface without provider start',
                    'type' => 'projection_or_command',
                    'acceptance' => 'Surface can inspect or run the guarded invocation only with signed release inputs, and output still states provider_start_allowed=false.',
                ],
                [
                    'id' => 'T3',
                    'title' => 'Add focused guarded invoker tests',
                    'type' => 'test',
                    'acceptance' => 'Tests cover one successful invocation, idempotent retry, missing signature rejection and proof that receipt use/provider start/adapter invocation/token spend remain forbidden.',
                ],
                [
                    'id' => 'T4',
                    'title' => 'Update Agent Control Plane documentation',
                    'type' => 'documentation',
                    'acceptance' => 'Documentation describes the guarded invocation boundary as the only official runtime path into the one-shot mutating writer.',
                ],
            ],
            'task_count' => 4,
            'acceptance_criteria' => [
                'guarded_invoker_calls_mutating_writer_once_only',
                'guarded_invoker_returns_signed_pending_dispatch_receipt_result',
                'guarded_invoker_is_idempotent_by_receipt_hash',
                'guarded_invoker_never_uses_dispatch_receipt',
                'guarded_invoker_never_starts_provider_or_invokes_adapter',
                'guarded_invoker_never_spends_tokens_or_enables_self_programming',
            ],
            'required_gates' => [
                'php_lint_guarded_invoker',
                'dedicated_guarded_invoker_feature_tests',
                'mutating_writer_feature_tests',
                'focused_self_construction_command_tests',
                'architecture_validate',
                'docs_health',
                'git_diff_check',
            ],
            'implementation_policy' => [
                'implementation_allowed_by_packet' => true,
                'runtime_invocation_allowed_by_future_invoker' => true,
                'mutating_writer_call_allowed_by_future_invoker' => true,
                'dispatch_receipt_use_allowed_by_packet' => false,
                'provider_start_allowed_by_packet' => false,
                'adapter_invocation_allowed_by_packet' => false,
                'token_spend_allowed_by_packet' => false,
                'self_programming_allowed_by_packet' => false,
            ],
            'stop_conditions' => [
                'need_to_use_dispatch_receipt',
                'need_to_start_provider_or_call_adapter',
                'need_to_spend_provider_tokens',
                'need_to_mark_packet_completed',
                'need_to_enable_self_programming',
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_guarded_runtime_invocation_service',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_guarded_runtime_invocation_implementation_packet.v1',
            'status' => (string) $packet['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_guarded_runtime_invocation_implementation_packet',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'claim_allowed' => false,
            'dispatch_receipt_write_allowed' => false,
            'dispatch_receipt_use_allowed' => false,
            'provider_start_allowed' => false,
            'adapter_invocation_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_guarded_runtime_invocation_implementation_packet' => $packet,
            'agent_automatic_dispatch_scheduler_one_shot_tick_guarded_runtime_invocation_implementation_packet_hash' => $this->section->stableHash($packet),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_guarded_runtime_invocation_implementation_packet_does_not_call_mutating_writer',
                'agent_automatic_dispatch_scheduler_one_shot_tick_guarded_runtime_invocation_implementation_packet_does_not_claim_wakeup_items',
                'agent_automatic_dispatch_scheduler_one_shot_tick_guarded_runtime_invocation_implementation_packet_does_not_write_dispatch_receipts',
                'agent_automatic_dispatch_scheduler_one_shot_tick_guarded_runtime_invocation_implementation_packet_does_not_use_dispatch_receipts',
                'agent_automatic_dispatch_scheduler_one_shot_tick_guarded_runtime_invocation_implementation_packet_does_not_start_providers',
                'agent_automatic_dispatch_scheduler_one_shot_tick_guarded_runtime_invocation_implementation_packet_does_not_enable_self_programming',
            ],
            'human_summary' => 'Automatic dispatch scheduler one-shot tick guarded runtime invocation implementation packet is ready; it scopes the future official invoker that may call the mutating writer once and then stop before provider start.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartSignedDispatchAuthorizationGateContract(array $options = []): array
    {
        $dispatchReleaseStatusPayload = $this->section->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartDispatchReleaseGateStatus($options);
        $dispatchReleaseStatus = (array) data_get($dispatchReleaseStatusPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_dispatch_release_gate_status', []);
        $authorizationPayload = $this->section->agentCodexRealInvokerPostStartSignedDispatchAuthorizationGateContractTemplate($options);

        $contract = [
            'status' => 'one_shot_tick_codex_real_invoker_post_start_signed_dispatch_authorization_gate_contract_ready',
            'contract_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-REAL-INVOKER-POST-START-SIGNED-DISPATCH-AUTHORIZATION-GATE-CONTRACT-SELF-CONSTRUCTION-0001',
            'parent_program' => 'Atlas Self-Construction OS',
            'submodule' => 'Atlas Agent Control Plane',
            'provider' => 'codex',
            'adapter' => 'codex',
            'source_codex_real_invoker_post_start_dispatch_release_gate_status' => data_get($dispatchReleaseStatus, 'status'),
            'source_codex_real_invoker_post_start_dispatch_release_gate_status_hash' => data_get($dispatchReleaseStatusPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_dispatch_release_gate_status_hash'),
            'source_codex_real_invoker_post_start_signed_dispatch_authorization_gate_status' => data_get($authorizationPayload, 'status'),
            'source_codex_real_invoker_post_start_signed_dispatch_authorization_gate_hash' => data_get($authorizationPayload, 'codex_real_invoker_post_start_signed_dispatch_authorization_gate_contract_template_hash'),
            'release_boundary' => [
                'canonical_post_start_signed_dispatch_authorization_gate' => AgentCodexRealInvokerPostStartSignedDispatchAuthorizationGate::class,
                'canonical_post_start_signed_dispatch_authorization_gate_method' => 'authorizePostStartSignedDispatch',
                'scheduler_invoker' => AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartSignedDispatchAuthorizationGateInvoker::class,
                'scheduler_invoker_method' => 'authorizeCodexRealInvokerPostStartSignedDispatch',
                'gate_effect' => 'record_signed_dispatch_authorization_after_dispatch_release_without_dispatching',
                'post_start_dispatch_release_gate_required_before_authorization' => true,
                'post_start_evidence_acceptance_bridge_required_before_authorization' => true,
                'required_liveness_state' => 'alive',
                'signed_dispatch_receipt_hash_required' => true,
                'human_dispatch_signature_hash_required' => true,
                'signed_dispatch_policy_hash_required' => true,
                'dispatch_window_hash_required' => true,
                'dispatch_scope_hash_required' => true,
                'continuation_summary_hash_required' => true,
                'context_pack_hash_required' => true,
                'dispatch_replay_guard_hash_required' => true,
                'dispatch_kill_switch_hash_required' => true,
                'no_direct_provider_call_attestation_required' => true,
                'future_dispatch_authorized_by_contract' => true,
                'actual_process_start_allowed_by_contract' => false,
                'atlas_process_spawned_by_contract' => false,
                'provider_marked_started_by_contract' => true,
                'provider_process_call_allowed_by_contract' => false,
                'adapter_execution_allowed_by_contract' => false,
                'token_spend_allowed_by_contract' => false,
                'dispatch_allowed_by_contract' => false,
                'idempotency_key' => 'signed_dispatch_authorization_id',
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
                'signed_dispatch_authorization_id',
                'signed_dispatch_receipt_hash',
                'human_dispatch_signature_hash',
                'signed_dispatch_policy_hash',
                'dispatch_window_hash',
                'dispatch_scope_hash',
                'continuation_summary_hash',
                'context_pack_hash',
                'dispatch_replay_guard_hash',
                'dispatch_kill_switch_hash',
                'no_direct_provider_call_attestation_hash',
                'actor',
                'session',
                'reason',
            ],
            'allowed_future_mutations' => [
                'write_codex_real_invoker_post_start_signed_dispatch_authorization_metadata_on_agent_run',
                'append_codex_real_invoker_post_start_signed_dispatch_authorization_evidence_event',
                'mark_run_as_future_dispatch_authorized',
            ],
            'forbidden_even_after_contract' => [
                'spawn_codex_process',
                'call_codex_cli_or_codex_app',
                'dispatch_work_to_codex',
                'send_prompt_to_provider',
                'call_provider_process',
                'spend_provider_tokens',
                'mark_run_running_or_terminal',
                'use_signed_dispatch_receipt',
                'enable_self_programming',
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_signed_dispatch_authorization_gate_preflight',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_signed_dispatch_authorization_gate_contract.v1',
            'status' => (string) $contract['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_signed_dispatch_authorization_gate_contract',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'post_start_signed_dispatch_authorization_gate_allowed' => false,
            'actual_process_start_allowed' => false,
            'adapter_execution_allowed' => false,
            'provider_start_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_signed_dispatch_authorization_gate_contract' => $contract,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_signed_dispatch_authorization_gate_contract_hash' => $this->section->stableHash($contract),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_signed_dispatch_authorization_gate_contract_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_signed_dispatch_authorization_gate_contract_does_not_call_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_signed_dispatch_authorization_gate_contract_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_signed_dispatch_authorization_gate_contract_does_not_dispatch_work',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_signed_dispatch_authorization_gate_contract_does_not_enable_self_programming',
            ],
            'human_summary' => 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start signed dispatch authorization gate contract is ready; it records a signed future authorization without dispatching work.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerExecutorPlanContract(array $options = []): array
    {
        $boundaryStatusPayload = $this->section->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerImplementationBoundaryStatus($options);
        $boundaryStatus = (array) data_get($boundaryStatusPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_implementation_boundary_status', []);
        $executorPlanPayload = $this->section->agentCodexRealInvokerExecutorPlanContractTemplate($options);

        $contract = [
            'status' => 'one_shot_tick_codex_real_invoker_executor_plan_contract_ready',
            'contract_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-REAL-INVOKER-EXECUTOR-PLAN-CONTRACT-SELF-CONSTRUCTION-0001',
            'parent_program' => 'Atlas Self-Construction OS',
            'submodule' => 'Atlas Agent Control Plane',
            'provider' => 'codex',
            'adapter' => 'codex',
            'source_codex_real_invoker_implementation_boundary_status' => data_get($boundaryStatus, 'status'),
            'source_codex_real_invoker_implementation_boundary_status_hash' => data_get($boundaryStatusPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_implementation_boundary_status_hash'),
            'source_codex_real_invoker_executor_plan_contract_status' => data_get($executorPlanPayload, 'status'),
            'source_codex_real_invoker_executor_plan_contract_hash' => data_get($executorPlanPayload, 'codex_real_invoker_executor_plan_contract_template_hash'),
            'release_boundary' => [
                'canonical_executor_plan' => AgentCodexRealInvokerExecutorPlan::class,
                'canonical_executor_plan_method' => 'prepareExecutorPlan',
                'scheduler_invoker' => AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerExecutorPlanInvoker::class,
                'scheduler_invoker_method' => 'prepareCodexRealInvokerExecutorPlan',
                'executor_plan_effect' => 'prepare_disabled_codex_real_invoker_executor_plan_metadata_without_enabling_executor',
                'executor_enabled_by_executor_plan' => false,
                'external_process_started_by_executor_plan' => false,
                'provider_started_by_executor_plan' => false,
                'adapter_execution_allowed_by_executor_plan' => false,
                'token_spend_allowed_by_executor_plan' => false,
                'required_implementation_boundary_status_before_plan' => 'real_invoker_implementation_boundary_prepared_pending_executor',
                'prepared_status_after_executor_plan' => 'real_invoker_executor_plan_prepared_disabled_pending_fresh_release',
                'idempotency_key' => 'real_invoker_executor_plan_id',
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
                'operator_executor_plan_receipt_hash',
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
                'write_codex_real_invoker_executor_plan_metadata_on_agent_run',
                'append_codex_real_invoker_executor_plan_prepared_evidence_event',
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
                'executor_plan_is_disabled_plan_not_execution' => true,
                'executor_fresh_release_requires_separate_contract' => true,
                'operator_executor_plan_receipt_hash_required' => true,
                'executor_binary_contract_hash_required' => true,
                'executor_observability_contract_hash_required' => true,
                'fresh_release_required_before_start' => true,
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_executor_plan_preflight',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_plan_contract.v1',
            'status' => (string) $contract['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_plan_contract',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_executor_plan_allowed' => false,
            'executor_enabled' => false,
            'adapter_execution_allowed' => false,
            'provider_start_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_plan_contract' => $contract,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_plan_contract_hash' => $this->section->stableHash($contract),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_plan_contract_does_not_prepare_executor_plan',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_plan_contract_does_not_enable_executor',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_plan_contract_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_plan_contract_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_plan_contract_does_not_enable_self_programming',
            ],
            'human_summary' => 'Automatic dispatch scheduler one-shot tick Codex real invoker executor plan contract is ready; it defines a disabled executor plan and still cannot start Codex.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProviderExecutionContractGateContract(array $options = []): array
    {
        $guardStatusPayload = $this->section->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartAdapterExecutionGuardGateStatus($options);
        $guardStatus = (array) data_get($guardStatusPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_adapter_execution_guard_gate_status', []);
        $providerExecutionPayload = $this->section->agentCodexRealInvokerPostStartProviderExecutionContractGateContractTemplate($options);

        $contract = [
            'status' => 'one_shot_tick_codex_real_invoker_post_start_provider_execution_contract_gate_contract_ready',
            'contract_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-REAL-INVOKER-POST-START-PROVIDER-EXECUTION-CONTRACT-GATE-CONTRACT-SELF-CONSTRUCTION-0001',
            'parent_program' => 'Atlas Self-Construction OS',
            'submodule' => 'Atlas Agent Control Plane',
            'provider' => 'codex',
            'adapter' => 'codex',
            'source_codex_real_invoker_post_start_adapter_execution_guard_gate_status' => data_get($guardStatus, 'status'),
            'source_codex_real_invoker_post_start_adapter_execution_guard_gate_status_hash' => data_get($guardStatusPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_adapter_execution_guard_gate_status_hash'),
            'source_codex_real_invoker_post_start_provider_execution_contract_gate_status' => data_get($providerExecutionPayload, 'status'),
            'source_codex_real_invoker_post_start_provider_execution_contract_gate_hash' => data_get($providerExecutionPayload, 'codex_real_invoker_post_start_provider_execution_contract_gate_contract_template_hash'),
            'provider_execution_contract' => [
                'canonical_post_start_provider_execution_contract_gate' => AgentCodexRealInvokerPostStartProviderExecutionContractGate::class,
                'canonical_post_start_provider_execution_contract_gate_method' => 'preparePostStartProviderExecutionContract',
                'scheduler_invoker' => AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProviderExecutionContractGateInvoker::class,
                'scheduler_invoker_method' => 'prepareCodexRealInvokerPostStartProviderExecutionContractGate',
                'codex_provider_execution_driver' => AgentCodexProviderExecutionDriver::class,
                'codex_provider_execution_driver_method' => 'prepareCodexExecution',
                'provider_execution_contract_effect' => 'record_codex_provider_execution_contract_without_starting_codex',
                'post_start_adapter_execution_guard_required_before_contract' => true,
                'post_start_evidence_acceptance_bridge_required_before_contract' => true,
                'provider_start_run_with_provider_adapter_execution_guard_required_before_contract' => true,
                'active_sandbox_binding_required_by_codex_driver' => true,
                'context_pack_and_continuation_summary_hashes_required_from_boundary' => true,
                'process_start_release_required_before_any_later_process_start' => true,
                'gate_delegates_to_codex_provider_execution_driver' => true,
                'gate_records_codex_provider_execution_metadata_on_provider_start_run' => true,
                'gate_records_bridge_metadata_on_observed_run' => true,
                'provider_specific_execution_contract_ready_after_gate' => true,
                'actual_process_start_allowed_by_contract' => false,
                'provider_process_call_allowed_by_contract' => false,
                'adapter_invocation_allowed_by_contract' => false,
                'adapter_execution_allowed_by_contract' => false,
                'token_spend_allowed_by_contract' => false,
                'dispatch_allowed_by_contract' => false,
                'idempotency_key' => 'codex_execution_id',
            ],
            'required_input_fields_for_future_invoker' => [
                'run_key',
                'provider_execution_contract_gate_id',
                'codex_execution_id',
                'adapter_execution_guard_gate_id',
                'execution_guard_id',
                'adapter_invocation_boundary_gate_id',
                'adapter_invocation_id',
                'provider_start_driver_gate_id',
                'provider_start_attempt_id',
                'dispatch_executor_handoff_id',
                'signed_dispatch_authorization_id',
                'post_start_evidence_acceptance_bridge_id',
                'signed_dispatch_receipt_hash',
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
                'record_codex_provider_execution_metadata_on_provider_start_run',
                'append_codex_provider_execution_contract_evidence_event',
                'record_codex_real_invoker_post_start_provider_execution_contract_metadata_on_observed_run',
            ],
            'forbidden_even_after_contract' => [
                'spawn_codex_process',
                'call_codex_cli_or_codex_app',
                'dispatch_work_to_codex',
                'send_prompt_to_provider',
                'call_provider_process',
                'enable_adapter_execution',
                'authorize_process_start_release',
                'spend_provider_tokens',
                'mark_observed_run_running_or_terminal',
                'enable_self_programming',
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_provider_execution_contract_gate_preflight',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_provider_execution_contract_gate_contract.v1',
            'status' => (string) $contract['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_provider_execution_contract_gate_contract',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'post_start_provider_execution_contract_gate_allowed' => false,
            'actual_process_start_allowed' => false,
            'provider_process_call_allowed' => false,
            'adapter_invocation_allowed' => false,
            'adapter_execution_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_provider_execution_contract_gate_contract' => $contract,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_provider_execution_contract_gate_contract_hash' => $this->section->stableHash($contract),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_provider_execution_contract_gate_contract_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_provider_execution_contract_gate_contract_does_not_call_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_provider_execution_contract_gate_contract_does_not_execute_adapter',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_provider_execution_contract_gate_contract_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_provider_execution_contract_gate_contract_does_not_dispatch_work',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_provider_execution_contract_gate_contract_does_not_enable_self_programming',
            ],
            'human_summary' => 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start provider execution contract gate contract is ready; it prepares the Codex-specific execution contract without starting Codex.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartEvidenceAcceptanceBridgeContract(array $options = []): array
    {
        $receiptStatusPayload = $this->section->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartEvidenceReceiptStatus($options);
        $receiptStatus = (array) data_get($receiptStatusPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_evidence_receipt_status', []);
        $bridgePayload = $this->section->agentCodexRealInvokerPostStartEvidenceAcceptanceBridgeContractTemplate($options);

        $contract = [
            'status' => 'one_shot_tick_codex_real_invoker_post_start_evidence_acceptance_bridge_contract_ready',
            'contract_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-REAL-INVOKER-POST-START-EVIDENCE-ACCEPTANCE-BRIDGE-CONTRACT-SELF-CONSTRUCTION-0001',
            'parent_program' => 'Atlas Self-Construction OS',
            'submodule' => 'Atlas Agent Control Plane',
            'provider' => 'codex',
            'adapter' => 'codex',
            'source_codex_real_invoker_post_start_evidence_receipt_status' => data_get($receiptStatus, 'status'),
            'source_codex_real_invoker_post_start_evidence_receipt_status_hash' => data_get($receiptStatusPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_evidence_receipt_status_hash'),
            'source_codex_real_invoker_post_start_evidence_acceptance_bridge_status' => data_get($bridgePayload, 'status'),
            'source_codex_real_invoker_post_start_evidence_acceptance_bridge_hash' => data_get($bridgePayload, 'codex_real_invoker_post_start_evidence_acceptance_bridge_contract_template_hash'),
            'release_boundary' => [
                'canonical_post_start_evidence_acceptance_bridge' => AgentCodexRealInvokerPostStartEvidenceAcceptanceBridge::class,
                'canonical_post_start_evidence_acceptance_bridge_method' => 'acceptPostStartEvidence',
                'scheduler_invoker' => AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartEvidenceAcceptanceBridgeInvoker::class,
                'scheduler_invoker_method' => 'acceptCodexRealInvokerPostStartEvidence',
                'gate_effect' => 'accept_attested_external_start_evidence_before_liveness_monitoring',
                'post_start_operator_start_handoff_required_before_acceptance' => true,
                'post_start_receipt_contract_required_by_bridge' => true,
                'post_start_evidence_receipt_required_by_bridge' => true,
                'operator_external_start_attestation_required' => true,
                'no_atlas_process_spawn_attestation_required' => true,
                'external_process_evidence_accepted_by_contract' => true,
                'actual_process_start_allowed_by_contract' => false,
                'atlas_process_spawned_by_contract' => false,
                'provider_marked_started_by_contract' => true,
                'adapter_execution_allowed_by_contract' => false,
                'token_spend_allowed_by_contract' => false,
                'dispatch_allowed_by_contract' => false,
                'idempotency_key' => 'post_start_evidence_acceptance_bridge_id',
            ],
            'required_input_fields_for_future_invoker' => [
                'run_key',
                'post_start_evidence_acceptance_bridge_id',
                'post_start_operator_start_handoff_id',
                'post_start_receipt_contract_id',
                'post_start_evidence_receipt_id',
                'codex_execution_id',
                'real_invoker_process_starter_readiness_gate_id',
                'real_invoker_start_execution_gate_id',
                'manual_start_executor_receipt_id',
                'operator_start_handoff_id',
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
                'external_process_identity_evidence_hash',
                'startup_evidence_hash',
                'terminal_pid_capture_hash',
                'post_start_liveness_probe_hash',
                'post_start_cost_meter_evidence_hash',
                'operator_external_start_attestation_hash',
                'no_atlas_process_spawn_attestation_hash',
                'actor',
                'session',
                'reason',
            ],
            'allowed_future_mutations' => [
                'write_codex_real_invoker_post_start_receipt_contract_metadata_on_agent_run',
                'write_codex_real_invoker_post_start_evidence_receipt_metadata_on_agent_run',
                'write_codex_real_invoker_post_start_evidence_acceptance_bridge_metadata_on_agent_run',
                'append_codex_real_invoker_post_start_evidence_acceptance_bridge_evidence_event',
            ],
            'forbidden_even_after_contract' => [
                'spawn_codex_process',
                'call_codex_cli_or_codex_app',
                'spawn_shell_or_subprocess',
                'spend_provider_tokens',
                'mark_run_terminal',
                'dispatch_work_to_codex',
                'enable_self_programming',
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_evidence_acceptance_bridge_preflight',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_evidence_acceptance_bridge_contract.v1',
            'status' => (string) $contract['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_evidence_acceptance_bridge_contract',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_post_start_evidence_acceptance_bridge_allowed' => false,
            'external_process_evidence_acceptance_allowed' => false,
            'actual_process_start_allowed' => false,
            'adapter_execution_allowed' => false,
            'provider_start_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_evidence_acceptance_bridge_contract' => $contract,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_evidence_acceptance_bridge_contract_hash' => $this->section->stableHash($contract),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_evidence_acceptance_bridge_contract_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_evidence_acceptance_bridge_contract_does_not_call_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_evidence_acceptance_bridge_contract_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_evidence_acceptance_bridge_contract_does_not_enable_self_programming',
            ],
            'human_summary' => 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start evidence acceptance bridge contract is ready; it records acceptance only as governed evidence before liveness monitoring.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerManualStartExecutorReceiptContract(array $options = []): array
    {
        $starterStatusPayload = $this->section->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerProcessStarterReadinessGateStatus($options);
        $starterStatus = (array) data_get($starterStatusPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_process_starter_readiness_gate_status', []);
        $manualReceiptPayload = $this->section->agentCodexRealInvokerManualStartExecutorReceiptWriterContractTemplate($options);

        $contract = [
            'status' => 'one_shot_tick_codex_real_invoker_manual_start_executor_receipt_contract_ready',
            'contract_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-REAL-INVOKER-MANUAL-START-EXECUTOR-RECEIPT-CONTRACT-SELF-CONSTRUCTION-0001',
            'parent_program' => 'Atlas Self-Construction OS',
            'submodule' => 'Atlas Agent Control Plane',
            'provider' => 'codex',
            'adapter' => 'codex',
            'source_codex_real_invoker_process_starter_readiness_gate_status' => data_get($starterStatus, 'status'),
            'source_codex_real_invoker_process_starter_readiness_gate_status_hash' => data_get($starterStatusPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_process_starter_readiness_gate_status_hash'),
            'source_codex_real_invoker_manual_start_executor_receipt_contract_status' => data_get($manualReceiptPayload, 'status'),
            'source_codex_real_invoker_manual_start_executor_receipt_contract_hash' => data_get($manualReceiptPayload, 'codex_real_invoker_manual_start_executor_receipt_writer_contract_template_hash'),
            'release_boundary' => [
                'canonical_manual_start_executor_receipt_writer' => AgentCodexRealInvokerManualStartExecutorReceiptWriter::class,
                'canonical_manual_start_executor_receipt_writer_method' => 'writeManualStartExecutorReceipt',
                'scheduler_invoker' => AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerManualStartExecutorReceiptInvoker::class,
                'scheduler_invoker_method' => 'writeCodexRealInvokerManualStartExecutorReceipt',
                'gate_effect' => 'record_manual_start_executor_receipt_without_starting_process',
                'process_starter_readiness_required_before_manual_receipt' => true,
                'manual_operator_start_required_by_receipt' => true,
                'actual_process_start_allowed_by_receipt' => false,
                'external_process_started_by_receipt' => false,
                'provider_started_by_receipt' => false,
                'adapter_execution_allowed_by_receipt' => false,
                'token_spend_allowed_by_receipt' => false,
                'dispatch_allowed_by_receipt' => false,
                'idempotency_key' => 'manual_start_executor_receipt_id',
            ],
            'required_input_fields_for_future_invoker' => [
                'run_key',
                'codex_execution_id',
                'real_invoker_executor_plan_id',
                'real_invoker_executor_fresh_release_id',
                'real_invoker_executor_enablement_id',
                'real_invoker_supervised_start_activation_id',
                'real_invoker_guarded_process_start_id',
                'real_invoker_final_process_start_authorization_id',
                'real_invoker_actual_process_start_rehearsal_id',
                'real_invoker_process_start_envelope_id',
                'real_invoker_start_execution_gate_id',
                'real_invoker_process_starter_readiness_gate_id',
                'manual_start_executor_receipt_id',
                'process_starter_manifest_hash',
                'supervisor_binding_hash',
                'liveness_monitor_binding_hash',
                'cancellation_contract_hash',
                'output_capture_contract_hash',
                'cost_meter_contract_hash',
                'start_replay_guard_hash',
                'operator_process_starter_signature_hash',
                'manual_start_command_hash',
                'terminal_session_binding_hash',
                'operator_presence_hash',
                'live_supervisor_ack_hash',
                'initial_liveness_probe_hash',
                'kill_switch_ack_hash',
                'output_stream_capture_hash',
                'cost_meter_initial_hash',
                'no_autostart_attestation_hash',
                'actor',
                'session',
                'reason',
            ],
            'allowed_future_mutations' => [
                'write_codex_real_invoker_manual_start_executor_receipt_metadata_on_agent_run',
                'append_codex_real_invoker_manual_start_executor_receipt_evidence_event',
            ],
            'forbidden_even_after_contract' => [
                'spawn_codex_process',
                'call_codex_cli_or_codex_app',
                'spawn_shell_or_subprocess',
                'spend_provider_tokens',
                'mark_run_running_or_terminal',
                'mark_packet_completed',
                'merge_work_products',
                'dispatch_work_to_codex',
                'enable_self_programming',
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_manual_start_executor_receipt_preflight',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_manual_start_executor_receipt_contract.v1',
            'status' => (string) $contract['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_manual_start_executor_receipt_contract',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_manual_start_executor_receipt_allowed' => false,
            'manual_operator_start_required' => false,
            'actual_process_start_allowed' => false,
            'adapter_execution_allowed' => false,
            'provider_start_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_manual_start_executor_receipt_contract' => $contract,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_manual_start_executor_receipt_contract_hash' => $this->section->stableHash($contract),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_manual_start_executor_receipt_contract_does_not_write_manual_receipt',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_manual_start_executor_receipt_contract_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_manual_start_executor_receipt_contract_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_manual_start_executor_receipt_contract_does_not_enable_self_programming',
            ],
            'human_summary' => 'Automatic dispatch scheduler one-shot tick Codex real invoker manual start executor receipt contract is ready; it records only the operator manual-start receipt boundary and still cannot start Codex.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProviderStartDriverGateStatus(array $options = []): array
    {
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $dispatchReceiptsTableReady = Schema::hasTable('atlas_self_construction_agent_dispatch_receipts');
        $releaseAuthorizationsTableReady = Schema::hasTable('atlas_self_construction_agent_dispatch_executor_release_authorizations');
        $sandboxBindingsTableReady = Schema::hasTable('atlas_self_construction_agent_sandbox_bindings');
        $heartbeatsTableReady = Schema::hasTable('atlas_self_construction_agent_heartbeats');
        $ledgerTableReady = Schema::hasTable('atlas_ledger_events');
        $gateReady = class_exists(AgentCodexRealInvokerPostStartProviderStartDriverGate::class)
            && method_exists(AgentCodexRealInvokerPostStartProviderStartDriverGate::class, 'preparePostStartProviderStartDriver');
        $invokerReady = class_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProviderStartDriverGateInvoker::class)
            && method_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProviderStartDriverGateInvoker::class, 'prepareCodexRealInvokerPostStartProviderStartDriverGate');
        $driverReady = class_exists(AgentDispatchExecutorProviderStartDriver::class)
            && method_exists(AgentDispatchExecutorProviderStartDriver::class, 'startProviderOnce');

        $providerStartGateRunsQuery = $runsTableReady
            ? AtlasSelfConstructionAgentRun::query()
                ->whereNotNull('metadata->codex_real_invoker_post_start_provider_start_driver->provider_start_attempt_id')
            : null;
        $preStartRunsQuery = $runsTableReady
            ? AtlasSelfConstructionAgentRun::query()
                ->where('run_key', 'like', 'provider-start:%')
                ->where('status', 'pre_start_guarded')
            : null;
        $preStartHeartbeatsQuery = $heartbeatsTableReady
            ? AtlasSelfConstructionAgentHeartbeat::query()
                ->where('signal', 'pre_start_guard')
            : null;
        $activeSandboxBindingsQuery = $sandboxBindingsTableReady
            ? AtlasSelfConstructionAgentSandboxBinding::query()
                ->where('status', 'active_pending_provider_start')
            : null;

        $statusReady = $runsTableReady
            && $dispatchReceiptsTableReady
            && $releaseAuthorizationsTableReady
            && $sandboxBindingsTableReady
            && $heartbeatsTableReady
            && $ledgerTableReady
            && $gateReady
            && $invokerReady
            && $driverReady;
        $status = [
            'status' => $statusReady ? 'one_shot_tick_codex_real_invoker_post_start_provider_start_driver_gate_service_ready' : 'blocked',
            'provider' => 'codex',
            'adapter' => 'codex',
            'invoker_service' => AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProviderStartDriverGateInvoker::class,
            'invoker_service_ready' => $invokerReady,
            'invoker_canonical_method' => 'prepareCodexRealInvokerPostStartProviderStartDriverGate',
            'generic_post_start_provider_start_driver_gate_service' => AgentCodexRealInvokerPostStartProviderStartDriverGate::class,
            'generic_post_start_provider_start_driver_gate_service_ready' => $gateReady,
            'generic_post_start_provider_start_driver_gate_canonical_method' => 'preparePostStartProviderStartDriver',
            'dispatch_executor_provider_start_driver_service' => AgentDispatchExecutorProviderStartDriver::class,
            'dispatch_executor_provider_start_driver_ready' => $driverReady,
            'agent_runs_table_ready' => $runsTableReady,
            'dispatch_receipts_table_ready' => $dispatchReceiptsTableReady,
            'dispatch_executor_release_authorizations_table_ready' => $releaseAuthorizationsTableReady,
            'sandbox_bindings_table_ready' => $sandboxBindingsTableReady,
            'agent_heartbeats_table_ready' => $heartbeatsTableReady,
            'ledger_table_ready' => $ledgerTableReady,
            'codex_real_invoker_post_start_provider_start_driver_recorded_run_count' => $providerStartGateRunsQuery === null ? null : (clone $providerStartGateRunsQuery)->count(),
            'pre_start_guarded_provider_start_run_count' => $preStartRunsQuery === null ? null : (clone $preStartRunsQuery)->count(),
            'pre_start_guard_heartbeat_count' => $preStartHeartbeatsQuery === null ? null : (clone $preStartHeartbeatsQuery)->count(),
            'active_pending_provider_start_sandbox_binding_count' => $activeSandboxBindingsQuery === null ? null : (clone $activeSandboxBindingsQuery)->count(),
            'runtime_policy' => [
                'status_projection_is_read_only' => true,
                'invoker_may_prepare_pre_start_guarded_provider_start_projection_when_called_with_signed_input' => true,
                'provider_start_driver_bridge_is_not_process_start' => true,
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
                ? 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_adapter_invocation_boundary_gate_contract'
                : 'repair_one_shot_scheduler_tick_codex_real_invoker_post_start_provider_start_driver_gate_service_blockers',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_provider_start_driver_gate_status.v1',
            'status' => (string) $status['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_provider_start_driver_gate_status',
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
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_provider_start_driver_gate_status' => $status,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_provider_start_driver_gate_status_hash' => $this->section->stableHash($status),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_provider_start_driver_gate_status_does_not_call_provider_start_driver_gate',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_provider_start_driver_gate_status_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_provider_start_driver_gate_status_does_not_enable_adapter_invocation',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_provider_start_driver_gate_status_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_provider_start_driver_gate_status_does_not_dispatch_work',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_provider_start_driver_gate_status_does_not_enable_self_programming',
            ],
            'human_summary' => $statusReady
                ? 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start provider start driver gate service is ready and inspectable; adapter invocation boundary remains separate.'
                : 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start provider start driver gate service is blocked until invoker, generic gate, sandbox, driver and storage are ready.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickGuardedRuntimeInvocationContract(array $options = []): array
    {
        $writerStatusPayload = $this->section->agentAutomaticDispatchSchedulerOneShotTickMutatingWriterStatus($options);
        $writerStatus = (array) data_get($writerStatusPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_mutating_writer_status', []);

        $contract = [
            'status' => 'one_shot_tick_guarded_runtime_invocation_contract_ready',
            'contract_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-GUARDED-RUNTIME-INVOCATION-CONTRACT-SELF-CONSTRUCTION-0001',
            'parent_program' => 'Atlas Self-Construction OS',
            'submodule' => 'Atlas Agent Control Plane',
            'workspace_id' => 'FORGE-WORKSPACE-ATLAS-SELF-CONSTRUCTION-0001',
            'source_mutating_writer_status' => data_get($writerStatus, 'status'),
            'source_mutating_writer_status_hash' => data_get($writerStatusPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_mutating_writer_status_hash'),
            'invocation_boundary' => [
                'canonical_method' => 'invokeSignedOneShotSchedulerTick',
                'allowed_runtime_service' => AgentAutomaticDispatchSchedulerOneShotTickMutatingWriter::class,
                'allowed_runtime_method' => 'executeOneShotSchedulerTickAfterReleasePreflight',
                'max_writer_invocations_per_command' => 1,
                'requires_signed_release_receipt_hash' => true,
                'requires_signed_dispatch_receipt_hash' => true,
                'requires_operator_supplied_signature_metadata' => true,
                'requires_append_only_evidence' => true,
            ],
            'input_contract' => [
                'release_receipt_hash',
                'selected_wakeup_key',
                'dispatch_envelope_hash',
                'source_release_preflight_hash',
                'source_mutating_writer_contract_hash',
                'source_mutating_writer_preflight_hash',
                'receipt_hash',
                'signed_by',
                'signed_at',
                'expires_at',
                'payload',
            ],
            'allowed_mutations' => [
                'call_mutating_writer_once',
                'claim_one_queued_wakeup_via_mutating_writer',
                'write_one_signed_pending_dispatch_receipt_via_mutating_writer',
                'append_one_scheduler_tick_evidence_event_via_mutating_writer',
            ],
            'forbidden_even_after_invocation' => [
                'use_dispatch_receipt',
                'mark_dispatch_receipt_used',
                'start_provider_process',
                'invoke_provider_adapter',
                'spend_provider_tokens',
                'merge_work_products',
                'mark_packet_completed',
                'enable_self_programming',
            ],
            'idempotency_policy' => [
                'idempotency_key' => 'receipt_hash',
                'same_receipt_hash_must_return_existing_signed_pending_dispatch_receipt' => true,
                'duplicate_receipt_key_with_different_hash_must_fail' => true,
                'invocation_must_be_safe_to_retry_after_client_timeout' => true,
            ],
            'output_contract' => [
                'status',
                'created',
                'wakeup_claimed',
                'dispatch_receipt_written',
                'receipt_key',
                'receipt_hash',
                'wakeup_item_id',
                'ledger_event_id',
                'provider_start_allowed=false',
                'adapter_invocation_allowed=false',
                'token_spend_allowed=false',
                'self_programming_allowed=false',
            ],
            'required_gates' => [
                'dedicated_guarded_invocation_contract_tests',
                'mutating_writer_feature_tests',
                'focused_self_construction_command_tests',
                'architecture_validate',
                'docs_health',
                'git_diff_check',
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_guarded_runtime_invocation_preflight',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_guarded_runtime_invocation_contract.v1',
            'status' => (string) $contract['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_guarded_runtime_invocation_contract',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'claim_allowed' => false,
            'dispatch_receipt_write_allowed' => false,
            'dispatch_receipt_use_allowed' => false,
            'provider_start_allowed' => false,
            'adapter_invocation_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_guarded_runtime_invocation_contract' => $contract,
            'agent_automatic_dispatch_scheduler_one_shot_tick_guarded_runtime_invocation_contract_hash' => $this->section->stableHash($contract),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_guarded_runtime_invocation_contract_does_not_call_mutating_writer',
                'agent_automatic_dispatch_scheduler_one_shot_tick_guarded_runtime_invocation_contract_does_not_claim_wakeup_items',
                'agent_automatic_dispatch_scheduler_one_shot_tick_guarded_runtime_invocation_contract_does_not_write_dispatch_receipts',
                'agent_automatic_dispatch_scheduler_one_shot_tick_guarded_runtime_invocation_contract_does_not_use_dispatch_receipts',
                'agent_automatic_dispatch_scheduler_one_shot_tick_guarded_runtime_invocation_contract_does_not_start_providers',
                'agent_automatic_dispatch_scheduler_one_shot_tick_guarded_runtime_invocation_contract_does_not_enable_self_programming',
            ],
            'human_summary' => 'Automatic dispatch scheduler one-shot tick guarded runtime invocation contract is ready; it defines the only future path that may call the mutating writer once, while still stopping before receipt use and provider start.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerOperatorStartHandoffContract(array $options = []): array
    {
        $manualReceiptStatusPayload = $this->section->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerManualStartExecutorReceiptStatus($options);
        $manualReceiptStatus = (array) data_get($manualReceiptStatusPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_manual_start_executor_receipt_status', []);
        $handoffPayload = $this->section->agentCodexRealInvokerOperatorStartHandoffBuilderContractTemplate($options);

        $contract = [
            'status' => 'one_shot_tick_codex_real_invoker_operator_start_handoff_contract_ready',
            'contract_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-REAL-INVOKER-OPERATOR-START-HANDOFF-CONTRACT-SELF-CONSTRUCTION-0001',
            'parent_program' => 'Atlas Self-Construction OS',
            'submodule' => 'Atlas Agent Control Plane',
            'provider' => 'codex',
            'adapter' => 'codex',
            'source_codex_real_invoker_manual_start_executor_receipt_status' => data_get($manualReceiptStatus, 'status'),
            'source_codex_real_invoker_manual_start_executor_receipt_status_hash' => data_get($manualReceiptStatusPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_manual_start_executor_receipt_status_hash'),
            'source_codex_real_invoker_operator_start_handoff_contract_status' => data_get($handoffPayload, 'status'),
            'source_codex_real_invoker_operator_start_handoff_contract_hash' => data_get($handoffPayload, 'codex_real_invoker_operator_start_handoff_builder_contract_template_hash'),
            'release_boundary' => [
                'canonical_operator_start_handoff_builder' => AgentCodexRealInvokerOperatorStartHandoffBuilder::class,
                'canonical_operator_start_handoff_builder_method' => 'buildOperatorStartHandoff',
                'scheduler_invoker' => AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerOperatorStartHandoffInvoker::class,
                'scheduler_invoker_method' => 'buildCodexRealInvokerOperatorStartHandoff',
                'gate_effect' => 'record_operator_start_handoff_without_starting_process',
                'manual_start_executor_receipt_required_before_handoff' => true,
                'manual_operator_start_required_by_handoff' => true,
                'actual_process_start_allowed_by_handoff' => false,
                'external_process_started_by_handoff' => false,
                'provider_started_by_handoff' => false,
                'adapter_execution_allowed_by_handoff' => false,
                'token_spend_allowed_by_handoff' => false,
                'dispatch_allowed_by_handoff' => false,
                'idempotency_key' => 'operator_start_handoff_id',
            ],
            'required_input_fields_for_future_invoker' => [
                'run_key',
                'codex_execution_id',
                'real_invoker_executor_plan_id',
                'real_invoker_executor_fresh_release_id',
                'real_invoker_executor_enablement_id',
                'real_invoker_supervised_start_activation_id',
                'real_invoker_guarded_process_start_id',
                'real_invoker_final_process_start_authorization_id',
                'real_invoker_actual_process_start_rehearsal_id',
                'real_invoker_process_start_envelope_id',
                'real_invoker_start_execution_gate_id',
                'real_invoker_process_starter_readiness_gate_id',
                'manual_start_executor_receipt_id',
                'operator_start_handoff_id',
                'manual_start_command_hash',
                'terminal_session_binding_hash',
                'operator_presence_hash',
                'live_supervisor_ack_hash',
                'initial_liveness_probe_hash',
                'kill_switch_ack_hash',
                'output_stream_capture_hash',
                'cost_meter_initial_hash',
                'no_autostart_attestation_hash',
                'handoff_packet_hash',
                'operator_runbook_hash',
                'external_terminal_handoff_hash',
                'post_start_liveness_probe_contract_hash',
                'post_start_receipt_contract_hash',
                'failure_escalation_contract_hash',
                'actor',
                'session',
                'reason',
            ],
            'allowed_future_mutations' => [
                'write_codex_real_invoker_operator_start_handoff_metadata_on_agent_run',
                'append_codex_real_invoker_operator_start_handoff_evidence_event',
            ],
            'forbidden_even_after_contract' => [
                'spawn_codex_process',
                'call_codex_cli_or_codex_app',
                'spawn_shell_or_subprocess',
                'spend_provider_tokens',
                'mark_run_running_or_terminal',
                'mark_packet_completed',
                'merge_work_products',
                'dispatch_work_to_codex',
                'enable_self_programming',
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_operator_start_handoff_preflight',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_operator_start_handoff_contract.v1',
            'status' => (string) $contract['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_operator_start_handoff_contract',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_operator_start_handoff_allowed' => false,
            'manual_operator_start_required' => false,
            'actual_process_start_allowed' => false,
            'adapter_execution_allowed' => false,
            'provider_start_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_operator_start_handoff_contract' => $contract,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_operator_start_handoff_contract_hash' => $this->section->stableHash($contract),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_operator_start_handoff_contract_does_not_build_handoff',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_operator_start_handoff_contract_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_operator_start_handoff_contract_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_operator_start_handoff_contract_does_not_enable_self_programming',
            ],
            'human_summary' => 'Automatic dispatch scheduler one-shot tick Codex real invoker operator start handoff contract is ready; it prepares only a manual external-start handoff and still cannot start Codex.',
        ];
    }


}
