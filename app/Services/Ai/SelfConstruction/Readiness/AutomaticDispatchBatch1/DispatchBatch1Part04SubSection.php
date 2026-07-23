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
 * DISPATCH BATCH 1 projection sub-section 04 of 5, sub-split from the god
 * {@see \App\Services\Ai\SelfConstruction\Readiness\ReadinessProjectionAgentAutomaticDispatchBatch1Section}
 * (GOD-DEBULK). Method bodies are byte-identical to the parent Section; the
 * only rewrite is that sibling/back calls route through the injected Section
 * facade ($this->section->*), which re-dispatches to whichever sub-section
 * owns the target method or forwards to the bound mother via the facade
 * __call.
 */
final class DispatchBatch1Part04SubSection
{
    public function __construct(
        private readonly ReadinessProjectionAgentAutomaticDispatchBatch1Section $section,
    ) {}

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProcessStartReleaseGateContract(array $options = []): array
    {
        $providerExecutionPayload = $this->section->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProviderExecutionContractGateStatus($options);
        $providerExecutionStatus = (array) data_get($providerExecutionPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_provider_execution_contract_gate_status', []);
        $processStartReleasePayload = $this->section->agentCodexRealInvokerPostStartProcessStartReleaseGateContractTemplate($options);

        $contract = [
            'status' => 'one_shot_tick_codex_real_invoker_post_start_process_start_release_gate_contract_ready',
            'contract_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-REAL-INVOKER-POST-START-PROCESS-START-RELEASE-GATE-CONTRACT-SELF-CONSTRUCTION-0001',
            'parent_program' => 'Atlas Self-Construction OS',
            'submodule' => 'Atlas Agent Control Plane',
            'provider' => 'codex',
            'adapter' => 'codex',
            'source_codex_real_invoker_post_start_provider_execution_contract_gate_status' => data_get($providerExecutionStatus, 'status'),
            'source_codex_real_invoker_post_start_provider_execution_contract_gate_status_hash' => data_get($providerExecutionPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_provider_execution_contract_gate_status_hash'),
            'source_codex_real_invoker_post_start_process_start_release_gate_status' => data_get($processStartReleasePayload, 'status'),
            'source_codex_real_invoker_post_start_process_start_release_gate_hash' => data_get($processStartReleasePayload, 'codex_real_invoker_post_start_process_start_release_gate_contract_template_hash'),
            'process_start_release' => [
                'canonical_post_start_process_start_release_gate' => AgentCodexRealInvokerPostStartProcessStartReleaseGate::class,
                'canonical_post_start_process_start_release_gate_method' => 'authorizePostStartProcessStartRelease',
                'scheduler_invoker' => AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProcessStartReleaseGateInvoker::class,
                'scheduler_invoker_method' => 'authorizeCodexRealInvokerPostStartProcessStartReleaseGate',
                'codex_process_start_release_gate' => AgentCodexProcessStartReleaseGate::class,
                'codex_process_start_release_gate_method' => 'authorizeCodexProcessStart',
                'process_start_release_effect' => 'authorize_codex_process_start_release_without_starting_codex',
                'post_start_provider_execution_contract_required_before_release' => true,
                'post_start_evidence_acceptance_bridge_required_before_release' => true,
                'provider_start_run_with_codex_provider_execution_required_before_release' => true,
                'operator_release_receipt_hash_required' => true,
                'codex_execution_contract_hash_required' => true,
                'gate_delegates_to_codex_process_start_release_gate' => true,
                'gate_records_codex_process_start_release_metadata_on_provider_start_run' => true,
                'gate_records_bridge_metadata_on_observed_run' => true,
                'supervised_start_executor_required_after_release' => true,
                'release_authorization_is_not_process_start' => true,
                'actual_process_start_allowed_by_contract' => false,
                'provider_process_call_allowed_by_contract' => false,
                'adapter_invocation_allowed_by_contract' => false,
                'adapter_execution_allowed_by_contract' => false,
                'token_spend_allowed_by_contract' => false,
                'dispatch_allowed_by_contract' => false,
                'idempotency_key' => 'process_start_release_id',
            ],
            'required_input_fields_for_future_invoker' => [
                'run_key',
                'post_start_process_start_release_gate_id',
                'process_start_release_id',
                'provider_execution_contract_gate_id',
                'codex_execution_id',
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
                'actor',
                'session',
                'reason',
            ],
            'allowed_future_mutations' => [
                'record_codex_process_start_release_metadata_on_provider_start_run',
                'append_codex_process_start_release_authorization_evidence_event',
                'record_codex_real_invoker_post_start_process_start_release_metadata_on_observed_run',
            ],
            'forbidden_even_after_contract' => [
                'spawn_codex_process',
                'call_codex_cli_or_codex_app',
                'run_supervised_start_executor',
                'dispatch_work_to_codex',
                'send_prompt_to_provider',
                'call_provider_process',
                'enable_adapter_execution',
                'spend_provider_tokens',
                'mark_observed_run_running_or_terminal',
                'enable_self_programming',
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_process_start_release_gate_preflight',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_start_release_gate_contract.v1',
            'status' => (string) $contract['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_start_release_gate_contract',
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
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_start_release_gate_contract' => $contract,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_start_release_gate_contract_hash' => $this->section->stableHash($contract),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_start_release_gate_contract_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_start_release_gate_contract_does_not_call_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_start_release_gate_contract_does_not_execute_adapter',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_start_release_gate_contract_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_start_release_gate_contract_does_not_dispatch_work',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_start_release_gate_contract_does_not_enable_self_programming',
            ],
            'human_summary' => 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start process start release gate contract is ready; it authorizes only a later supervised path and does not start Codex.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartGuardedProcessStartExecutorGatePreflight(array $options = []): array
    {
        $contractPayload = $this->section->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartGuardedProcessStartExecutorGateContract($options);
        $contract = (array) data_get($contractPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_guarded_process_start_executor_gate_contract', []);
        $contractHash = (string) data_get($contractPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_guarded_process_start_executor_gate_contract_hash');
        $gateReady = class_exists(AgentCodexRealInvokerPostStartGuardedProcessStartExecutorGate::class)
            && method_exists(AgentCodexRealInvokerPostStartGuardedProcessStartExecutorGate::class, 'preparePostStartGuardedProcessStart');
        $invokerReady = class_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartGuardedProcessStartExecutorGateInvoker::class)
            && method_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartGuardedProcessStartExecutorGateInvoker::class, 'prepareCodexRealInvokerPostStartGuardedProcessStartExecutorGate');
        $guardedReady = class_exists(AgentCodexRealInvokerGuardedProcessStartExecutor::class)
            && method_exists(AgentCodexRealInvokerGuardedProcessStartExecutor::class, 'prepareGuardedStart');
        $activationReady = class_exists(AgentCodexRealInvokerPostStartSupervisedStartActivationGate::class)
            && method_exists(AgentCodexRealInvokerPostStartSupervisedStartActivationGate::class, 'preparePostStartSupervisedStartActivation');
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerTableReady = Schema::hasTable('atlas_ledger_events');

        $checks = [
            'post_start_guarded_process_start_executor_gate_contract_ready' => data_get($contractPayload, 'status') === 'one_shot_tick_codex_real_invoker_post_start_guarded_process_start_executor_gate_contract_ready',
            'post_start_guarded_process_start_executor_gate_contract_hash_present' => $contractHash !== '',
            'post_start_supervised_start_activation_gate_status_ready' => data_get($contract, 'source_codex_real_invoker_post_start_supervised_start_activation_gate_status') === 'one_shot_tick_codex_real_invoker_post_start_supervised_start_activation_gate_service_ready',
            'generic_post_start_guarded_process_start_executor_gate_template_ready' => data_get($contract, 'source_codex_real_invoker_post_start_guarded_process_start_executor_gate_contract_status') === 'codex_real_invoker_post_start_guarded_process_start_executor_gate_contract_template_ready',
            'generic_guarded_process_start_executor_template_ready' => data_get($contract, 'source_codex_real_invoker_guarded_process_start_executor_contract_status') === 'codex_real_invoker_guarded_process_start_executor_contract_template_ready',
            'codex_real_invoker_post_start_guarded_process_start_executor_gate_ready' => $gateReady,
            'codex_real_invoker_post_start_guarded_process_start_executor_gate_invoker_ready' => $invokerReady,
            'codex_real_invoker_guarded_process_start_executor_ready' => $guardedReady,
            'codex_real_invoker_post_start_supervised_start_activation_gate_ready' => $activationReady,
            'canonical_post_start_guarded_process_start_executor_gate_method_ready' => data_get($contract, 'guarded_process_start.canonical_post_start_guarded_process_start_executor_gate_method') === 'preparePostStartGuardedProcessStart',
            'scheduler_invoker_method_ready' => data_get($contract, 'guarded_process_start.scheduler_invoker_method') === 'prepareCodexRealInvokerPostStartGuardedProcessStartExecutorGate',
            'contract_requires_post_start_supervised_start_activation' => data_get($contract, 'guarded_process_start.post_start_supervised_start_activation_required_before_guarded_process_start') === true,
            'contract_requires_post_start_evidence_acceptance_bridge' => data_get($contract, 'guarded_process_start.post_start_evidence_acceptance_bridge_required_before_guarded_process_start') === true,
            'contract_requires_provider_start_supervised_activation' => data_get($contract, 'guarded_process_start.provider_start_supervised_activation_required_before_guarded_process_start') === true,
            'contract_delegates_to_codex_real_invoker_guarded_process_start_executor' => data_get($contract, 'guarded_process_start.gate_delegates_to_codex_real_invoker_guarded_process_start_executor') === true,
            'contract_declares_guarded_process_start_is_not_process_start' => data_get($contract, 'guarded_process_start.guarded_process_start_is_not_process_start') === true,
            'contract_requires_final_process_start_authorization_after_guarded_process_start' => in_array('authorize_final_process_start_without_separate_contract', (array) data_get($contract, 'forbidden_even_after_contract', []), true),
            'contract_allows_process_start_armed_by_future_invoker' => data_get($contract, 'guarded_process_start.process_start_armed_by_contract') === true,
            'contract_keeps_actual_process_start_disabled' => data_get($contract, 'guarded_process_start.actual_process_start_allowed_by_contract') === false,
            'contract_keeps_adapter_execution_disabled' => data_get($contract, 'guarded_process_start.adapter_execution_allowed_by_contract') === false,
            'contract_does_not_dispatch_work' => data_get($contract, 'guarded_process_start.dispatch_allowed_by_contract') === false,
            'contract_does_not_spend_tokens' => data_get($contract, 'guarded_process_start.token_spend_allowed_by_contract') === false,
            'agent_runs_table_ready' => $runsTableReady,
            'ledger_table_ready' => $ledgerTableReady,
            'self_programming_forbidden' => in_array('enable_self_programming', (array) data_get($contract, 'forbidden_even_after_contract', []), true),
        ];
        $blockingReasons = array_values(array_keys(array_filter($checks, static fn (bool $passed): bool => ! $passed)));

        $preflight = [
            'status' => $blockingReasons === [] ? 'one_shot_tick_codex_real_invoker_post_start_guarded_process_start_executor_gate_preflight_ready' : 'blocked',
            'preflight_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-REAL-INVOKER-POST-START-GUARDED-PROCESS-START-EXECUTOR-GATE-PREFLIGHT-SELF-CONSTRUCTION-0001',
            'source_post_start_guarded_process_start_executor_gate_contract_hash' => $contractHash,
            'preflight_checks' => $checks,
            'blocking_count' => count($blockingReasons),
            'blocking_reasons' => $blockingReasons,
            'implementation_requirements' => [
                'create_scheduler_specific_post_start_guarded_process_start_executor_gate_invoker',
                'delegate_to_agent_codex_real_invoker_post_start_guarded_process_start_executor_gate',
                'require_post_start_supervised_start_activation_metadata',
                'require_post_start_evidence_acceptance_bridge',
                'require_operator_guarded_start_receipt_hash',
                'require_process_runner_contract_hash',
                'project_readiness_status_without_invoking_codex',
            ],
            'runtime_policy' => [
                'preflight_is_read_only' => true,
                'post_start_guarded_process_start_executor_gate_call_allowed_by_future_invoker' => true,
                'process_start_armed_after_future_invoker' => true,
                'final_process_start_authorization_required_after_guarded_process_start' => true,
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
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_guarded_process_start_executor_gate_implementation_packet',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_guarded_process_start_executor_gate_preflight.v1',
            'status' => (string) $preflight['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_guarded_process_start_executor_gate_preflight',
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
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_guarded_process_start_executor_gate_preflight' => $preflight,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_guarded_process_start_executor_gate_preflight_hash' => $this->section->stableHash($preflight),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_guarded_process_start_executor_gate_preflight_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_guarded_process_start_executor_gate_preflight_does_not_call_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_guarded_process_start_executor_gate_preflight_does_not_execute_adapter',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_guarded_process_start_executor_gate_preflight_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_guarded_process_start_executor_gate_preflight_does_not_dispatch_work',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_guarded_process_start_executor_gate_preflight_does_not_enable_self_programming',
            ],
            'human_summary' => $blockingReasons === []
                ? 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start guarded process start executor gate preflight is ready; final process start authorization remains separate.'
                : 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start guarded process start executor gate preflight is blocked until activation, guarded executor, storage and no-runtime prerequisites are ready.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartAdapterInvocationBoundaryGateContract(array $options = []): array
    {
        $providerStartStatusPayload = $this->section->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProviderStartDriverGateStatus($options);
        $providerStartStatus = (array) data_get($providerStartStatusPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_provider_start_driver_gate_status', []);
        $boundaryPayload = $this->section->agentCodexRealInvokerPostStartAdapterInvocationBoundaryGateContractTemplate($options);

        $contract = [
            'status' => 'one_shot_tick_codex_real_invoker_post_start_adapter_invocation_boundary_gate_contract_ready',
            'contract_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-REAL-INVOKER-POST-START-ADAPTER-INVOCATION-BOUNDARY-GATE-CONTRACT-SELF-CONSTRUCTION-0001',
            'parent_program' => 'Atlas Self-Construction OS',
            'submodule' => 'Atlas Agent Control Plane',
            'provider' => 'codex',
            'adapter' => 'codex',
            'source_codex_real_invoker_post_start_provider_start_driver_gate_status' => data_get($providerStartStatus, 'status'),
            'source_codex_real_invoker_post_start_provider_start_driver_gate_status_hash' => data_get($providerStartStatusPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_provider_start_driver_gate_status_hash'),
            'source_codex_real_invoker_post_start_adapter_invocation_boundary_gate_status' => data_get($boundaryPayload, 'status'),
            'source_codex_real_invoker_post_start_adapter_invocation_boundary_gate_hash' => data_get($boundaryPayload, 'codex_real_invoker_post_start_adapter_invocation_boundary_gate_contract_template_hash'),
            'adapter_invocation_boundary' => [
                'canonical_post_start_adapter_invocation_boundary_gate' => AgentCodexRealInvokerPostStartAdapterInvocationBoundaryGate::class,
                'canonical_post_start_adapter_invocation_boundary_gate_method' => 'preparePostStartAdapterInvocationBoundary',
                'scheduler_invoker' => AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartAdapterInvocationBoundaryGateInvoker::class,
                'scheduler_invoker_method' => 'prepareCodexRealInvokerPostStartAdapterInvocationBoundaryGate',
                'adapter_invocation_boundary_effect' => 'prepare_adapter_invocation_metadata_on_the_pre_start_guarded_provider_run_without_calling_codex',
                'post_start_provider_start_driver_required_before_boundary' => true,
                'post_start_evidence_acceptance_bridge_required_before_boundary' => true,
                'pre_start_guarded_provider_start_run_required_before_boundary' => true,
                'pre_start_heartbeat_required_before_boundary' => true,
                'provider_adapter_registry_required' => true,
                'context_pack_hash_required' => true,
                'continuation_summary_hash_required' => true,
                'signed_dispatch_receipt_hash_required' => true,
                'adapter_descriptor_hash_projected_by_boundary' => true,
                'boundary_prepares_adapter_invocation_metadata' => true,
                'actual_process_start_allowed_by_contract' => false,
                'boundary_external_process_started_by_contract' => false,
                'boundary_provider_started_by_contract' => false,
                'provider_process_call_allowed_by_contract' => false,
                'adapter_invocation_allowed_by_contract' => false,
                'adapter_execution_allowed_by_contract' => false,
                'token_spend_allowed_by_contract' => false,
                'dispatch_allowed_by_contract' => false,
                'idempotency_key' => 'adapter_invocation_id',
            ],
            'required_input_fields_for_future_invoker' => [
                'run_key',
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
                'prepare_adapter_invocation_metadata_on_provider_start_run',
                'append_adapter_invocation_boundary_evidence_event',
                'record_codex_real_invoker_post_start_adapter_invocation_boundary_metadata_on_observed_run',
            ],
            'forbidden_even_after_contract' => [
                'spawn_codex_process',
                'call_codex_cli_or_codex_app',
                'dispatch_work_to_codex',
                'send_prompt_to_provider',
                'call_provider_process',
                'enable_adapter_execution',
                'spend_provider_tokens',
                'mark_observed_run_running_or_terminal',
                'enable_self_programming',
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_adapter_invocation_boundary_gate_preflight',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_adapter_invocation_boundary_gate_contract.v1',
            'status' => (string) $contract['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_adapter_invocation_boundary_gate_contract',
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
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_adapter_invocation_boundary_gate_contract' => $contract,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_adapter_invocation_boundary_gate_contract_hash' => $this->section->stableHash($contract),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_adapter_invocation_boundary_gate_contract_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_adapter_invocation_boundary_gate_contract_does_not_call_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_adapter_invocation_boundary_gate_contract_does_not_execute_adapter',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_adapter_invocation_boundary_gate_contract_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_adapter_invocation_boundary_gate_contract_does_not_dispatch_work',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_adapter_invocation_boundary_gate_contract_does_not_enable_self_programming',
            ],
            'human_summary' => 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start adapter invocation boundary gate contract is ready; it prepares adapter metadata without calling Codex.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProviderExecutionContractGatePreflight(array $options = []): array
    {
        $contractPayload = $this->section->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProviderExecutionContractGateContract($options);
        $contract = (array) data_get($contractPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_provider_execution_contract_gate_contract', []);
        $contractHash = (string) data_get($contractPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_provider_execution_contract_gate_contract_hash');
        $gateReady = class_exists(AgentCodexRealInvokerPostStartProviderExecutionContractGate::class)
            && method_exists(AgentCodexRealInvokerPostStartProviderExecutionContractGate::class, 'preparePostStartProviderExecutionContract');
        $invokerReady = class_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProviderExecutionContractGateInvoker::class)
            && method_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProviderExecutionContractGateInvoker::class, 'prepareCodexRealInvokerPostStartProviderExecutionContractGate');
        $driverReady = class_exists(AgentCodexProviderExecutionDriver::class)
            && method_exists(AgentCodexProviderExecutionDriver::class, 'prepareCodexExecution');
        $guardGateReady = class_exists(AgentCodexRealInvokerPostStartAdapterExecutionGuardGate::class)
            && method_exists(AgentCodexRealInvokerPostStartAdapterExecutionGuardGate::class, 'blockPostStartAdapterExecution');
        $registryReady = class_exists(AgentProviderAdapterRegistry::class)
            && method_exists(AgentProviderAdapterRegistry::class, 'resolve');
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $sandboxBindingTableReady = Schema::hasTable('atlas_self_construction_agent_sandbox_bindings');
        $ledgerTableReady = Schema::hasTable('atlas_ledger_events');

        $checks = [
            'post_start_provider_execution_contract_gate_contract_ready' => data_get($contractPayload, 'status') === 'one_shot_tick_codex_real_invoker_post_start_provider_execution_contract_gate_contract_ready',
            'post_start_provider_execution_contract_gate_contract_hash_present' => $contractHash !== '',
            'post_start_adapter_execution_guard_gate_status_ready' => data_get($contract, 'source_codex_real_invoker_post_start_adapter_execution_guard_gate_status') === 'one_shot_tick_codex_real_invoker_post_start_adapter_execution_guard_gate_service_ready',
            'generic_post_start_provider_execution_contract_gate_template_ready' => data_get($contract, 'source_codex_real_invoker_post_start_provider_execution_contract_gate_status') === 'codex_real_invoker_post_start_provider_execution_contract_gate_contract_template_ready',
            'codex_real_invoker_post_start_provider_execution_contract_gate_ready' => $gateReady,
            'codex_real_invoker_post_start_provider_execution_contract_gate_invoker_ready' => $invokerReady,
            'codex_provider_execution_driver_ready' => $driverReady,
            'codex_real_invoker_post_start_adapter_execution_guard_gate_ready' => $guardGateReady,
            'provider_adapter_registry_ready' => $registryReady,
            'canonical_post_start_provider_execution_contract_gate_method_ready' => data_get($contract, 'provider_execution_contract.canonical_post_start_provider_execution_contract_gate_method') === 'preparePostStartProviderExecutionContract',
            'scheduler_invoker_method_ready' => data_get($contract, 'provider_execution_contract.scheduler_invoker_method') === 'prepareCodexRealInvokerPostStartProviderExecutionContractGate',
            'contract_requires_adapter_execution_guard' => data_get($contract, 'provider_execution_contract.post_start_adapter_execution_guard_required_before_contract') === true,
            'contract_requires_post_start_evidence_acceptance_bridge' => data_get($contract, 'provider_execution_contract.post_start_evidence_acceptance_bridge_required_before_contract') === true,
            'contract_delegates_to_codex_provider_execution_driver' => data_get($contract, 'provider_execution_contract.gate_delegates_to_codex_provider_execution_driver') === true,
            'contract_requires_process_start_release' => data_get($contract, 'provider_execution_contract.process_start_release_required_before_any_later_process_start') === true,
            'contract_sets_provider_specific_execution_contract_ready' => data_get($contract, 'provider_execution_contract.provider_specific_execution_contract_ready_after_gate') === true,
            'contract_keeps_actual_process_start_disabled' => data_get($contract, 'provider_execution_contract.actual_process_start_allowed_by_contract') === false,
            'contract_keeps_adapter_execution_disabled' => data_get($contract, 'provider_execution_contract.adapter_execution_allowed_by_contract') === false,
            'contract_does_not_dispatch_work' => data_get($contract, 'provider_execution_contract.dispatch_allowed_by_contract') === false,
            'contract_does_not_spend_tokens' => data_get($contract, 'provider_execution_contract.token_spend_allowed_by_contract') === false,
            'agent_runs_table_ready' => $runsTableReady,
            'sandbox_bindings_table_ready' => $sandboxBindingTableReady,
            'ledger_table_ready' => $ledgerTableReady,
            'self_programming_forbidden' => in_array('enable_self_programming', (array) data_get($contract, 'forbidden_even_after_contract', []), true),
        ];
        $blockingReasons = array_values(array_keys(array_filter($checks, static fn (bool $passed): bool => ! $passed)));

        $preflight = [
            'status' => $blockingReasons === [] ? 'one_shot_tick_codex_real_invoker_post_start_provider_execution_contract_gate_preflight_ready' : 'blocked',
            'preflight_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-REAL-INVOKER-POST-START-PROVIDER-EXECUTION-CONTRACT-GATE-PREFLIGHT-SELF-CONSTRUCTION-0001',
            'source_post_start_provider_execution_contract_gate_contract_hash' => $contractHash,
            'preflight_checks' => $checks,
            'blocking_count' => count($blockingReasons),
            'blocking_reasons' => $blockingReasons,
            'implementation_requirements' => [
                'use_one_shot_scheduler_codex_real_invoker_post_start_provider_execution_contract_gate_invoker',
                'delegate_to_agent_codex_real_invoker_post_start_provider_execution_contract_gate',
                'require_codex_real_invoker_post_start_adapter_execution_guard_metadata',
                'require_provider_start_run_with_provider_adapter_execution_guard_metadata',
                'record_codex_provider_execution_contract_without_starting_codex',
                'preserve_process_start_disabled_until_codex_process_start_release_gate',
            ],
            'runtime_policy' => [
                'preflight_is_read_only' => true,
                'post_start_provider_execution_contract_gate_call_allowed_here' => false,
                'provider_execution_contract_allowed_by_future_invoker' => true,
                'codex_provider_execution_driver_allowed_by_future_invoker' => true,
                'provider_specific_execution_contract_ready_after_future_invoker' => true,
                'process_start_release_required_before_process_start' => true,
                'provider_external_process_start_allowed_here' => false,
                'provider_process_call_allowed_here' => false,
                'adapter_invocation_allowed_here' => false,
                'adapter_execution_allowed_here' => false,
                'dispatch_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'self_programming_allowed_here' => false,
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_provider_execution_contract_gate_implementation_packet',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_provider_execution_contract_gate_preflight.v1',
            'status' => (string) $preflight['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_provider_execution_contract_gate_preflight',
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
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_provider_execution_contract_gate_preflight' => $preflight,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_provider_execution_contract_gate_preflight_hash' => $this->section->stableHash($preflight),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_provider_execution_contract_gate_preflight_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_provider_execution_contract_gate_preflight_does_not_call_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_provider_execution_contract_gate_preflight_does_not_execute_adapter',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_provider_execution_contract_gate_preflight_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_provider_execution_contract_gate_preflight_does_not_dispatch_work',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_provider_execution_contract_gate_preflight_does_not_enable_self_programming',
            ],
            'human_summary' => $blockingReasons === []
                ? 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start provider execution contract gate preflight is ready.'
                : 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start provider execution contract gate preflight is blocked until guard, driver, sandbox and storage prerequisites are ready.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartFinalProcessSpawnExecutorGatePreflight(array $options = []): array
    {
        $contractPayload = $this->section->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartFinalProcessSpawnExecutorGateContract($options);
        $contract = (array) data_get($contractPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_final_process_spawn_executor_gate_contract', []);
        $contractHash = (string) data_get($contractPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_final_process_spawn_executor_gate_contract_hash');
        $gateReady = class_exists(AgentCodexRealInvokerPostStartFinalProcessSpawnExecutorGate::class)
            && method_exists(AgentCodexRealInvokerPostStartFinalProcessSpawnExecutorGate::class, 'preparePostStartFinalProcessSpawn');
        $invokerReady = class_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartFinalProcessSpawnExecutorGateInvoker::class)
            && method_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartFinalProcessSpawnExecutorGateInvoker::class, 'prepareCodexRealInvokerPostStartFinalProcessSpawnExecutorGate');
        $spawnExecutorReady = class_exists(AgentCodexProcessSpawnExecutor::class)
            && method_exists(AgentCodexProcessSpawnExecutor::class, 'prepareProcessSpawn');
        $spawnEnablementGateReady = class_exists(AgentCodexRealInvokerPostStartProcessSpawnEnablementGate::class)
            && method_exists(AgentCodexRealInvokerPostStartProcessSpawnEnablementGate::class, 'enablePostStartProcessSpawn');
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerTableReady = Schema::hasTable('atlas_ledger_events');

        $checks = [
            'post_start_final_process_spawn_executor_gate_contract_ready' => data_get($contractPayload, 'status') === 'one_shot_tick_codex_real_invoker_post_start_final_process_spawn_executor_gate_contract_ready',
            'post_start_final_process_spawn_executor_gate_contract_hash_present' => $contractHash !== '',
            'post_start_process_spawn_enablement_gate_status_ready' => data_get($contract, 'source_codex_real_invoker_post_start_process_spawn_enablement_gate_status') === 'one_shot_tick_codex_real_invoker_post_start_process_spawn_enablement_gate_service_ready',
            'generic_post_start_final_process_spawn_executor_gate_template_ready' => data_get($contract, 'source_codex_real_invoker_post_start_final_process_spawn_executor_gate_status') === 'codex_real_invoker_post_start_final_process_spawn_executor_gate_contract_template_ready',
            'codex_real_invoker_post_start_final_process_spawn_executor_gate_ready' => $gateReady,
            'codex_real_invoker_post_start_final_process_spawn_executor_gate_invoker_ready' => $invokerReady,
            'codex_process_spawn_executor_ready' => $spawnExecutorReady,
            'codex_real_invoker_post_start_process_spawn_enablement_gate_ready' => $spawnEnablementGateReady,
            'canonical_post_start_final_process_spawn_executor_gate_method_ready' => data_get($contract, 'final_process_spawn_executor.canonical_post_start_final_process_spawn_executor_gate_method') === 'preparePostStartFinalProcessSpawn',
            'scheduler_invoker_method_ready' => data_get($contract, 'final_process_spawn_executor.scheduler_invoker_method') === 'prepareCodexRealInvokerPostStartFinalProcessSpawnExecutorGate',
            'contract_requires_process_spawn_enablement' => data_get($contract, 'final_process_spawn_executor.post_start_process_spawn_enablement_required_before_executor') === true,
            'contract_requires_post_start_evidence_acceptance_bridge' => data_get($contract, 'final_process_spawn_executor.post_start_evidence_acceptance_bridge_required_before_executor') === true,
            'contract_requires_provider_start_run_process_spawn_enablement' => data_get($contract, 'final_process_spawn_executor.provider_start_run_with_codex_process_spawn_enablement_required_before_executor') === true,
            'contract_requires_operator_final_spawn_receipt_hash' => data_get($contract, 'final_process_spawn_executor.operator_final_spawn_receipt_hash_required') === true,
            'contract_requires_runtime_supervision_plan_hash' => data_get($contract, 'final_process_spawn_executor.runtime_supervision_plan_hash_required') === true,
            'contract_requires_stdout_stderr_sink_hash' => data_get($contract, 'final_process_spawn_executor.stdout_stderr_sink_hash_required') === true,
            'contract_requires_liveness_probe_hash' => data_get($contract, 'final_process_spawn_executor.liveness_probe_hash_required') === true,
            'contract_delegates_to_codex_process_spawn_executor' => data_get($contract, 'final_process_spawn_executor.gate_delegates_to_codex_process_spawn_executor') === true,
            'contract_requires_external_process_runtime_after_executor' => data_get($contract, 'final_process_spawn_executor.external_process_runtime_required_after_executor') === true,
            'contract_declares_executor_is_not_external_runtime' => data_get($contract, 'final_process_spawn_executor.final_process_spawn_executor_is_not_external_process_runtime') === true,
            'contract_keeps_actual_process_start_disabled' => data_get($contract, 'final_process_spawn_executor.actual_process_start_allowed_by_contract') === false,
            'contract_keeps_adapter_execution_disabled' => data_get($contract, 'final_process_spawn_executor.adapter_execution_allowed_by_contract') === false,
            'contract_does_not_dispatch_work' => data_get($contract, 'final_process_spawn_executor.dispatch_allowed_by_contract') === false,
            'contract_does_not_spend_tokens' => data_get($contract, 'final_process_spawn_executor.token_spend_allowed_by_contract') === false,
            'agent_runs_table_ready' => $runsTableReady,
            'ledger_table_ready' => $ledgerTableReady,
            'self_programming_forbidden' => in_array('enable_self_programming', (array) data_get($contract, 'forbidden_even_after_contract', []), true),
        ];
        $blockingReasons = array_values(array_keys(array_filter($checks, static fn (bool $passed): bool => ! $passed)));

        $preflight = [
            'status' => $blockingReasons === [] ? 'one_shot_tick_codex_real_invoker_post_start_final_process_spawn_executor_gate_preflight_ready' : 'blocked',
            'preflight_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-REAL-INVOKER-POST-START-FINAL-PROCESS-SPAWN-EXECUTOR-GATE-PREFLIGHT-SELF-CONSTRUCTION-0001',
            'source_post_start_final_process_spawn_executor_gate_contract_hash' => $contractHash,
            'preflight_checks' => $checks,
            'blocking_count' => count($blockingReasons),
            'blocking_reasons' => $blockingReasons,
            'implementation_requirements' => [
                'use_one_shot_scheduler_codex_real_invoker_post_start_final_process_spawn_executor_gate_invoker',
                'delegate_to_agent_codex_real_invoker_post_start_final_process_spawn_executor_gate',
                'require_codex_real_invoker_post_start_process_spawn_enablement_metadata',
                'require_post_start_evidence_acceptance_bridge_metadata',
                'record_final_process_spawn_executor_without_running_external_runtime',
                'preserve_actual_process_start_disabled_until_external_process_runtime_gate',
            ],
            'runtime_policy' => [
                'preflight_is_read_only' => true,
                'post_start_final_process_spawn_executor_gate_call_allowed_here' => false,
                'post_start_final_process_spawn_executor_allowed_by_future_invoker' => true,
                'codex_process_spawn_executor_allowed_by_future_invoker' => true,
                'final_process_spawn_executor_is_not_external_process_runtime' => true,
                'external_process_runtime_required_after_future_invoker' => true,
                'actual_process_start_allowed_here' => false,
                'provider_process_call_allowed_here' => false,
                'adapter_invocation_allowed_here' => false,
                'adapter_execution_allowed_here' => false,
                'dispatch_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'self_programming_allowed_here' => false,
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_final_process_spawn_executor_gate_implementation_packet',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_final_process_spawn_executor_gate_preflight.v1',
            'status' => (string) $preflight['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_final_process_spawn_executor_gate_preflight',
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
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_final_process_spawn_executor_gate_preflight' => $preflight,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_final_process_spawn_executor_gate_preflight_hash' => $this->section->stableHash($preflight),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_final_process_spawn_executor_gate_preflight_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_final_process_spawn_executor_gate_preflight_does_not_call_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_final_process_spawn_executor_gate_preflight_does_not_execute_adapter',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_final_process_spawn_executor_gate_preflight_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_final_process_spawn_executor_gate_preflight_does_not_dispatch_work',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_final_process_spawn_executor_gate_preflight_does_not_enable_self_programming',
            ],
            'human_summary' => $blockingReasons === []
                ? 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start final process spawn executor gate preflight is ready.'
                : 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start final process spawn executor gate preflight is blocked until process spawn enablement, final executor gate and storage prerequisites are ready.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartExternalProcessRuntimeGatePreflight(array $options = []): array
    {
        $contractPayload = $this->section->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartExternalProcessRuntimeGateContract($options);
        $contract = (array) data_get($contractPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_external_process_runtime_gate_contract', []);
        $contractHash = (string) data_get($contractPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_external_process_runtime_gate_contract_hash');
        $gateReady = class_exists(AgentCodexRealInvokerPostStartExternalProcessRuntimeGate::class)
            && method_exists(AgentCodexRealInvokerPostStartExternalProcessRuntimeGate::class, 'preparePostStartExternalRuntime');
        $invokerReady = class_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartExternalProcessRuntimeGateInvoker::class)
            && method_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartExternalProcessRuntimeGateInvoker::class, 'prepareCodexRealInvokerPostStartExternalProcessRuntimeGate');
        $externalRuntimeDriverReady = class_exists(AgentCodexExternalProcessRuntimeDriver::class)
            && method_exists(AgentCodexExternalProcessRuntimeDriver::class, 'prepareExternalRuntime');
        $finalSpawnGateReady = class_exists(AgentCodexRealInvokerPostStartFinalProcessSpawnExecutorGate::class)
            && method_exists(AgentCodexRealInvokerPostStartFinalProcessSpawnExecutorGate::class, 'preparePostStartFinalProcessSpawn');
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerTableReady = Schema::hasTable('atlas_ledger_events');

        $checks = [
            'post_start_external_process_runtime_gate_contract_ready' => data_get($contractPayload, 'status') === 'one_shot_tick_codex_real_invoker_post_start_external_process_runtime_gate_contract_ready',
            'post_start_external_process_runtime_gate_contract_hash_present' => $contractHash !== '',
            'post_start_final_process_spawn_executor_gate_status_ready' => data_get($contract, 'source_codex_real_invoker_post_start_final_process_spawn_executor_gate_status') === 'one_shot_tick_codex_real_invoker_post_start_final_process_spawn_executor_gate_service_ready',
            'generic_post_start_external_process_runtime_gate_template_ready' => data_get($contract, 'source_codex_real_invoker_post_start_external_process_runtime_gate_status') === 'codex_real_invoker_post_start_external_process_runtime_gate_contract_template_ready',
            'codex_real_invoker_post_start_external_process_runtime_gate_ready' => $gateReady,
            'codex_real_invoker_post_start_external_process_runtime_gate_invoker_ready' => $invokerReady,
            'codex_external_process_runtime_driver_ready' => $externalRuntimeDriverReady,
            'codex_real_invoker_post_start_final_process_spawn_executor_gate_ready' => $finalSpawnGateReady,
            'canonical_post_start_external_process_runtime_gate_method_ready' => data_get($contract, 'external_process_runtime.canonical_post_start_external_process_runtime_gate_method') === 'preparePostStartExternalRuntime',
            'scheduler_invoker_method_ready' => data_get($contract, 'external_process_runtime.scheduler_invoker_method') === 'prepareCodexRealInvokerPostStartExternalProcessRuntimeGate',
            'contract_requires_final_process_spawn_executor' => data_get($contract, 'external_process_runtime.post_start_final_process_spawn_executor_required_before_runtime') === true,
            'contract_requires_post_start_evidence_acceptance_bridge' => data_get($contract, 'external_process_runtime.post_start_evidence_acceptance_bridge_required_before_runtime') === true,
            'contract_requires_provider_start_run_process_spawn_executor' => data_get($contract, 'external_process_runtime.provider_start_run_with_codex_process_spawn_executor_required_before_runtime') === true,
            'contract_requires_operator_runtime_receipt_hash' => data_get($contract, 'external_process_runtime.operator_runtime_receipt_hash_required') === true,
            'contract_requires_process_command_hash' => data_get($contract, 'external_process_runtime.process_command_hash_required') === true,
            'contract_requires_environment_contract_hash' => data_get($contract, 'external_process_runtime.environment_contract_hash_required') === true,
            'contract_requires_termination_policy_hash' => data_get($contract, 'external_process_runtime.termination_policy_hash_required') === true,
            'contract_delegates_to_codex_external_process_runtime_driver' => data_get($contract, 'external_process_runtime.gate_delegates_to_codex_external_process_runtime_driver') === true,
            'contract_requires_process_invocation_authorization_after_runtime' => data_get($contract, 'external_process_runtime.process_invocation_authorization_required_after_runtime') === true,
            'contract_declares_runtime_is_not_process_invocation' => data_get($contract, 'external_process_runtime.external_process_runtime_is_not_process_invocation') === true,
            'contract_keeps_actual_process_start_disabled' => data_get($contract, 'external_process_runtime.actual_process_start_allowed_by_contract') === false,
            'contract_keeps_adapter_execution_disabled' => data_get($contract, 'external_process_runtime.adapter_execution_allowed_by_contract') === false,
            'contract_does_not_dispatch_work' => data_get($contract, 'external_process_runtime.dispatch_allowed_by_contract') === false,
            'contract_does_not_spend_tokens' => data_get($contract, 'external_process_runtime.token_spend_allowed_by_contract') === false,
            'agent_runs_table_ready' => $runsTableReady,
            'ledger_table_ready' => $ledgerTableReady,
            'self_programming_forbidden' => in_array('enable_self_programming', (array) data_get($contract, 'forbidden_even_after_contract', []), true),
        ];
        $blockingReasons = array_values(array_keys(array_filter($checks, static fn (bool $passed): bool => ! $passed)));

        $preflight = [
            'status' => $blockingReasons === [] ? 'one_shot_tick_codex_real_invoker_post_start_external_process_runtime_gate_preflight_ready' : 'blocked',
            'preflight_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-REAL-INVOKER-POST-START-EXTERNAL-PROCESS-RUNTIME-GATE-PREFLIGHT-SELF-CONSTRUCTION-0001',
            'source_post_start_external_process_runtime_gate_contract_hash' => $contractHash,
            'preflight_checks' => $checks,
            'blocking_count' => count($blockingReasons),
            'blocking_reasons' => $blockingReasons,
            'implementation_requirements' => [
                'use_one_shot_scheduler_codex_real_invoker_post_start_external_process_runtime_gate_invoker',
                'delegate_to_agent_codex_real_invoker_post_start_external_process_runtime_gate',
                'require_codex_real_invoker_post_start_final_process_spawn_executor_metadata',
                'require_post_start_evidence_acceptance_bridge_metadata',
                'record_external_process_runtime_without_process_invocation',
                'preserve_actual_process_start_disabled_until_process_invocation_authorization_gate',
            ],
            'runtime_policy' => [
                'preflight_is_read_only' => true,
                'post_start_external_process_runtime_gate_call_allowed_here' => false,
                'post_start_external_process_runtime_allowed_by_future_invoker' => true,
                'codex_external_process_runtime_driver_allowed_by_future_invoker' => true,
                'external_process_runtime_is_not_process_invocation' => true,
                'process_invocation_authorization_required_after_future_invoker' => true,
                'actual_process_start_allowed_here' => false,
                'provider_process_call_allowed_here' => false,
                'adapter_invocation_allowed_here' => false,
                'adapter_execution_allowed_here' => false,
                'dispatch_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'self_programming_allowed_here' => false,
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_external_process_runtime_gate_implementation_packet',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_external_process_runtime_gate_preflight.v1',
            'status' => (string) $preflight['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_external_process_runtime_gate_preflight',
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
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_external_process_runtime_gate_preflight' => $preflight,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_external_process_runtime_gate_preflight_hash' => $this->section->stableHash($preflight),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_external_process_runtime_gate_preflight_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_external_process_runtime_gate_preflight_does_not_call_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_external_process_runtime_gate_preflight_does_not_execute_adapter',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_external_process_runtime_gate_preflight_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_external_process_runtime_gate_preflight_does_not_dispatch_work',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_external_process_runtime_gate_preflight_does_not_enable_self_programming',
            ],
            'human_summary' => $blockingReasons === []
                ? 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start external process runtime gate preflight is ready.'
                : 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start external process runtime gate preflight is blocked until final spawn, external runtime driver and storage prerequisites are ready.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerImplementationBoundaryContract(array $options = []): array
    {
        $signedGateStatusPayload = $this->section->agentAutomaticDispatchSchedulerOneShotTickCodexSignedRealInvokerReleaseGateStatus($options);
        $signedGateStatus = (array) data_get($signedGateStatusPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_signed_real_invoker_release_gate_status', []);
        $boundaryPayload = $this->section->agentCodexRealInvokerImplementationBoundaryContractTemplate($options);

        $contract = [
            'status' => 'one_shot_tick_codex_real_invoker_implementation_boundary_contract_ready',
            'contract_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-REAL-INVOKER-IMPLEMENTATION-BOUNDARY-CONTRACT-SELF-CONSTRUCTION-0001',
            'parent_program' => 'Atlas Self-Construction OS',
            'submodule' => 'Atlas Agent Control Plane',
            'provider' => 'codex',
            'adapter' => 'codex',
            'source_codex_signed_real_invoker_release_gate_status' => data_get($signedGateStatus, 'status'),
            'source_codex_signed_real_invoker_release_gate_status_hash' => data_get($signedGateStatusPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_signed_real_invoker_release_gate_status_hash'),
            'source_codex_real_invoker_implementation_boundary_contract_status' => data_get($boundaryPayload, 'status'),
            'source_codex_real_invoker_implementation_boundary_contract_hash' => data_get($boundaryPayload, 'codex_real_invoker_implementation_boundary_contract_template_hash'),
            'release_boundary' => [
                'canonical_implementation_boundary' => AgentCodexRealInvokerImplementationBoundary::class,
                'canonical_implementation_boundary_method' => 'prepareBoundary',
                'scheduler_invoker' => AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerImplementationBoundaryInvoker::class,
                'scheduler_invoker_method' => 'prepareCodexRealInvokerImplementationBoundary',
                'implementation_boundary_effect' => 'prepare_codex_real_invoker_implementation_boundary_metadata_without_real_process_invocation',
                'external_process_started_by_implementation_boundary' => false,
                'provider_started_by_implementation_boundary' => false,
                'adapter_execution_allowed_by_implementation_boundary' => false,
                'token_spend_allowed_by_implementation_boundary' => false,
                'required_signed_release_status_before_boundary' => 'signed_real_invoker_release_authorized_pending_invoker_implementation',
                'prepared_status_after_boundary' => 'real_invoker_implementation_boundary_prepared_pending_executor',
                'idempotency_key' => 'real_invoker_implementation_boundary_id',
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
                'operator_implementation_boundary_receipt_hash',
                'real_invoker_contract_hash',
                'release_policy_hash',
                'implementation_plan_hash',
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
                'write_codex_real_invoker_implementation_boundary_metadata_on_agent_run',
                'append_codex_real_invoker_implementation_boundary_prepared_evidence_event',
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
                'implementation_boundary_is_envelope_not_invocation' => true,
                'executor_plan_requires_separate_contract' => true,
                'operator_implementation_boundary_receipt_hash_required' => true,
                'implementation_plan_hash_required' => true,
                'real_invoker_contract_hash_required' => true,
                'release_policy_hash_required' => true,
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_implementation_boundary_preflight',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_implementation_boundary_contract.v1',
            'status' => (string) $contract['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_implementation_boundary_contract',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_implementation_boundary_allowed' => false,
            'adapter_execution_allowed' => false,
            'provider_start_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_implementation_boundary_contract' => $contract,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_implementation_boundary_contract_hash' => $this->section->stableHash($contract),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_implementation_boundary_contract_does_not_call_boundary',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_implementation_boundary_contract_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_implementation_boundary_contract_does_not_call_adapters',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_implementation_boundary_contract_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_implementation_boundary_contract_does_not_enable_self_programming',
            ],
            'human_summary' => 'Automatic dispatch scheduler one-shot tick Codex real invoker implementation boundary contract is ready; it prepares a future executor envelope but cannot start Codex.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartSupervisedStartExecutorGatePreflight(array $options = []): array
    {
        $contractPayload = $this->section->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartSupervisedStartExecutorGateContract($options);
        $contract = (array) data_get($contractPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_supervised_start_executor_gate_contract', []);
        $contractHash = (string) data_get($contractPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_supervised_start_executor_gate_contract_hash');
        $gateReady = class_exists(AgentCodexRealInvokerPostStartSupervisedStartExecutorGate::class)
            && method_exists(AgentCodexRealInvokerPostStartSupervisedStartExecutorGate::class, 'preparePostStartSupervisedStart');
        $invokerReady = class_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartSupervisedStartExecutorGateInvoker::class)
            && method_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartSupervisedStartExecutorGateInvoker::class, 'prepareCodexRealInvokerPostStartSupervisedStartExecutorGate');
        $supervisedStartReady = class_exists(AgentCodexSupervisedStartExecutor::class)
            && method_exists(AgentCodexSupervisedStartExecutor::class, 'prepareSupervisedStart');
        $processStartReleaseGateReady = class_exists(AgentCodexRealInvokerPostStartProcessStartReleaseGate::class)
            && method_exists(AgentCodexRealInvokerPostStartProcessStartReleaseGate::class, 'authorizePostStartProcessStartRelease');
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerTableReady = Schema::hasTable('atlas_ledger_events');

        $checks = [
            'post_start_supervised_start_executor_gate_contract_ready' => data_get($contractPayload, 'status') === 'one_shot_tick_codex_real_invoker_post_start_supervised_start_executor_gate_contract_ready',
            'post_start_supervised_start_executor_gate_contract_hash_present' => $contractHash !== '',
            'post_start_process_start_release_gate_status_ready' => data_get($contract, 'source_codex_real_invoker_post_start_process_start_release_gate_status') === 'one_shot_tick_codex_real_invoker_post_start_process_start_release_gate_service_ready',
            'generic_post_start_supervised_start_executor_gate_template_ready' => data_get($contract, 'source_codex_real_invoker_post_start_supervised_start_executor_gate_status') === 'codex_real_invoker_post_start_supervised_start_executor_gate_contract_template_ready',
            'codex_real_invoker_post_start_supervised_start_executor_gate_ready' => $gateReady,
            'codex_real_invoker_post_start_supervised_start_executor_gate_invoker_ready' => $invokerReady,
            'codex_supervised_start_executor_ready' => $supervisedStartReady,
            'codex_real_invoker_post_start_process_start_release_gate_ready' => $processStartReleaseGateReady,
            'canonical_post_start_supervised_start_executor_gate_method_ready' => data_get($contract, 'supervised_start.canonical_post_start_supervised_start_executor_gate_method') === 'preparePostStartSupervisedStart',
            'scheduler_invoker_method_ready' => data_get($contract, 'supervised_start.scheduler_invoker_method') === 'prepareCodexRealInvokerPostStartSupervisedStartExecutorGate',
            'contract_requires_process_start_release' => data_get($contract, 'supervised_start.post_start_process_start_release_required_before_supervised_start') === true,
            'contract_requires_post_start_evidence_acceptance_bridge' => data_get($contract, 'supervised_start.post_start_evidence_acceptance_bridge_required_before_supervised_start') === true,
            'contract_requires_provider_start_run_release' => data_get($contract, 'supervised_start.provider_start_run_with_codex_process_start_release_required_before_supervised_start') === true,
            'contract_requires_stdout_stderr_sanitizer_hash' => data_get($contract, 'supervised_start.stdout_stderr_sanitizer_hash_required') === true,
            'contract_requires_ready_probe_plan_hash' => data_get($contract, 'supervised_start.ready_probe_plan_hash_required') === true,
            'contract_requires_rollback_plan_hash' => data_get($contract, 'supervised_start.rollback_plan_hash_required') === true,
            'contract_delegates_to_codex_supervised_start_executor' => data_get($contract, 'supervised_start.gate_delegates_to_codex_supervised_start_executor') === true,
            'contract_requires_process_spawn_enablement_after_supervised_start' => data_get($contract, 'supervised_start.process_spawn_enablement_required_after_supervised_start') === true,
            'contract_declares_supervised_start_is_not_process_spawn' => data_get($contract, 'supervised_start.supervised_start_preparation_is_not_process_spawn') === true,
            'contract_keeps_actual_process_start_disabled' => data_get($contract, 'supervised_start.actual_process_start_allowed_by_contract') === false,
            'contract_keeps_adapter_execution_disabled' => data_get($contract, 'supervised_start.adapter_execution_allowed_by_contract') === false,
            'contract_does_not_dispatch_work' => data_get($contract, 'supervised_start.dispatch_allowed_by_contract') === false,
            'contract_does_not_spend_tokens' => data_get($contract, 'supervised_start.token_spend_allowed_by_contract') === false,
            'agent_runs_table_ready' => $runsTableReady,
            'ledger_table_ready' => $ledgerTableReady,
            'self_programming_forbidden' => in_array('enable_self_programming', (array) data_get($contract, 'forbidden_even_after_contract', []), true),
        ];
        $blockingReasons = array_values(array_keys(array_filter($checks, static fn (bool $passed): bool => ! $passed)));

        $preflight = [
            'status' => $blockingReasons === [] ? 'one_shot_tick_codex_real_invoker_post_start_supervised_start_executor_gate_preflight_ready' : 'blocked',
            'preflight_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-REAL-INVOKER-POST-START-SUPERVISED-START-EXECUTOR-GATE-PREFLIGHT-SELF-CONSTRUCTION-0001',
            'source_post_start_supervised_start_executor_gate_contract_hash' => $contractHash,
            'preflight_checks' => $checks,
            'blocking_count' => count($blockingReasons),
            'blocking_reasons' => $blockingReasons,
            'implementation_requirements' => [
                'use_one_shot_scheduler_codex_real_invoker_post_start_supervised_start_executor_gate_invoker',
                'delegate_to_agent_codex_real_invoker_post_start_supervised_start_executor_gate',
                'require_codex_real_invoker_post_start_process_start_release_metadata',
                'require_post_start_evidence_acceptance_bridge_metadata',
                'record_supervised_start_preparation_without_spawning_codex',
                'preserve_actual_process_start_disabled_until_process_spawn_enablement_gate',
            ],
            'runtime_policy' => [
                'preflight_is_read_only' => true,
                'post_start_supervised_start_executor_gate_call_allowed_here' => false,
                'post_start_supervised_start_preparation_allowed_by_future_invoker' => true,
                'codex_supervised_start_executor_allowed_by_future_invoker' => true,
                'supervised_start_preparation_is_not_process_spawn' => true,
                'process_spawn_enablement_required_after_future_invoker' => true,
                'actual_process_start_allowed_here' => false,
                'provider_process_call_allowed_here' => false,
                'adapter_invocation_allowed_here' => false,
                'adapter_execution_allowed_here' => false,
                'dispatch_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'self_programming_allowed_here' => false,
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_supervised_start_executor_gate_implementation_packet',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_supervised_start_executor_gate_preflight.v1',
            'status' => (string) $preflight['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_supervised_start_executor_gate_preflight',
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
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_supervised_start_executor_gate_preflight' => $preflight,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_supervised_start_executor_gate_preflight_hash' => $this->section->stableHash($preflight),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_supervised_start_executor_gate_preflight_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_supervised_start_executor_gate_preflight_does_not_call_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_supervised_start_executor_gate_preflight_does_not_execute_adapter',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_supervised_start_executor_gate_preflight_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_supervised_start_executor_gate_preflight_does_not_dispatch_work',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_supervised_start_executor_gate_preflight_does_not_enable_self_programming',
            ],
            'human_summary' => $blockingReasons === []
                ? 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start supervised start executor gate preflight is ready.'
                : 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start supervised start executor gate preflight is blocked until release, supervised executor and storage prerequisites are ready.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartSupervisedStartActivationGatePreflight(array $options = []): array
    {
        $contractPayload = $this->section->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartSupervisedStartActivationGateContract($options);
        $contract = (array) data_get($contractPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_supervised_start_activation_gate_contract', []);
        $contractHash = (string) data_get($contractPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_supervised_start_activation_gate_contract_hash');
        $gateReady = class_exists(AgentCodexRealInvokerPostStartSupervisedStartActivationGate::class)
            && method_exists(AgentCodexRealInvokerPostStartSupervisedStartActivationGate::class, 'preparePostStartSupervisedStartActivation');
        $invokerReady = class_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartSupervisedStartActivationGateInvoker::class)
            && method_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartSupervisedStartActivationGateInvoker::class, 'prepareCodexRealInvokerPostStartSupervisedStartActivationGate');
        $activationReady = class_exists(AgentCodexRealInvokerSupervisedStartActivationGate::class)
            && method_exists(AgentCodexRealInvokerSupervisedStartActivationGate::class, 'prepareActivation');
        $enablementReady = class_exists(AgentCodexRealInvokerPostStartExecutorEnablementGate::class)
            && method_exists(AgentCodexRealInvokerPostStartExecutorEnablementGate::class, 'enablePostStartExecutor');
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerTableReady = Schema::hasTable('atlas_ledger_events');

        $checks = [
            'post_start_supervised_start_activation_gate_contract_ready' => data_get($contractPayload, 'status') === 'one_shot_tick_codex_real_invoker_post_start_supervised_start_activation_gate_contract_ready',
            'post_start_supervised_start_activation_gate_contract_hash_present' => $contractHash !== '',
            'post_start_executor_enablement_gate_status_ready' => data_get($contract, 'source_codex_real_invoker_post_start_executor_enablement_gate_status') === 'one_shot_tick_codex_real_invoker_post_start_executor_enablement_gate_service_ready',
            'generic_post_start_supervised_start_activation_gate_template_ready' => data_get($contract, 'source_codex_real_invoker_post_start_supervised_start_activation_gate_contract_status') === 'codex_real_invoker_post_start_supervised_start_activation_gate_contract_template_ready',
            'generic_supervised_start_activation_gate_template_ready' => data_get($contract, 'source_codex_real_invoker_supervised_start_activation_gate_contract_status') === 'codex_real_invoker_supervised_start_activation_gate_contract_template_ready',
            'codex_real_invoker_post_start_supervised_start_activation_gate_ready' => $gateReady,
            'codex_real_invoker_post_start_supervised_start_activation_gate_invoker_ready' => $invokerReady,
            'codex_real_invoker_supervised_start_activation_gate_ready' => $activationReady,
            'codex_real_invoker_post_start_executor_enablement_gate_ready' => $enablementReady,
            'canonical_post_start_supervised_start_activation_gate_method_ready' => data_get($contract, 'activation.canonical_post_start_supervised_start_activation_gate_method') === 'preparePostStartSupervisedStartActivation',
            'scheduler_invoker_method_ready' => data_get($contract, 'activation.scheduler_invoker_method') === 'prepareCodexRealInvokerPostStartSupervisedStartActivationGate',
            'contract_requires_post_start_executor_enablement' => data_get($contract, 'activation.post_start_executor_enablement_required_before_activation') === true,
            'contract_requires_post_start_evidence_acceptance_bridge' => data_get($contract, 'activation.post_start_evidence_acceptance_bridge_required_before_activation') === true,
            'contract_delegates_to_codex_real_invoker_supervised_start_activation_gate' => data_get($contract, 'activation.gate_delegates_to_codex_real_invoker_supervised_start_activation_gate') === true,
            'contract_declares_activation_is_not_process_start' => data_get($contract, 'activation.activation_is_not_process_start') === true,
            'contract_requires_guarded_process_start_after_activation' => in_array('authorize_guarded_process_start_without_separate_contract', (array) data_get($contract, 'forbidden_even_after_contract', []), true),
            'contract_allows_process_start_armed_by_future_invoker' => data_get($contract, 'activation.process_start_armed_by_contract') === true,
            'contract_keeps_actual_process_start_disabled' => data_get($contract, 'activation.actual_process_start_allowed_by_contract') === false,
            'contract_keeps_adapter_execution_disabled' => data_get($contract, 'activation.adapter_execution_allowed_by_contract') === false,
            'contract_does_not_dispatch_work' => data_get($contract, 'activation.dispatch_allowed_by_contract') === false,
            'contract_does_not_spend_tokens' => data_get($contract, 'activation.token_spend_allowed_by_contract') === false,
            'agent_runs_table_ready' => $runsTableReady,
            'ledger_table_ready' => $ledgerTableReady,
            'self_programming_forbidden' => in_array('enable_self_programming', (array) data_get($contract, 'forbidden_even_after_contract', []), true),
        ];
        $blockingReasons = array_values(array_keys(array_filter($checks, static fn (bool $passed): bool => ! $passed)));

        $preflight = [
            'status' => $blockingReasons === [] ? 'one_shot_tick_codex_real_invoker_post_start_supervised_start_activation_gate_preflight_ready' : 'blocked',
            'preflight_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-REAL-INVOKER-POST-START-SUPERVISED-START-ACTIVATION-GATE-PREFLIGHT-SELF-CONSTRUCTION-0001',
            'source_post_start_supervised_start_activation_gate_contract_hash' => $contractHash,
            'preflight_checks' => $checks,
            'blocking_count' => count($blockingReasons),
            'blocking_reasons' => $blockingReasons,
            'implementation_requirements' => [
                'create_scheduler_specific_post_start_supervised_start_activation_gate_invoker',
                'delegate_to_agent_codex_real_invoker_post_start_supervised_start_activation_gate',
                'require_post_start_executor_enablement_metadata',
                'require_post_start_evidence_acceptance_bridge',
                'require_operator_start_activation_receipt_hash',
                'project_readiness_status_without_invoking_codex',
            ],
            'runtime_policy' => [
                'preflight_is_read_only' => true,
                'post_start_supervised_start_activation_gate_call_allowed_by_future_invoker' => true,
                'process_start_armed_after_future_invoker' => true,
                'guarded_process_start_executor_required_after_activation' => true,
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
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_supervised_start_activation_gate_implementation_packet',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_supervised_start_activation_gate_preflight.v1',
            'status' => (string) $preflight['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_supervised_start_activation_gate_preflight',
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
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_supervised_start_activation_gate_preflight' => $preflight,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_supervised_start_activation_gate_preflight_hash' => $this->section->stableHash($preflight),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_supervised_start_activation_gate_preflight_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_supervised_start_activation_gate_preflight_does_not_call_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_supervised_start_activation_gate_preflight_does_not_execute_adapter',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_supervised_start_activation_gate_preflight_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_supervised_start_activation_gate_preflight_does_not_dispatch_work',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_supervised_start_activation_gate_preflight_does_not_enable_self_programming',
            ],
            'human_summary' => $blockingReasons === []
                ? 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start supervised start activation gate preflight is ready; guarded process start remains separate.'
                : 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start supervised start activation gate preflight is blocked until enablement, activation, storage and no-runtime prerequisites are ready.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerProcessStarterReadinessGateContract(array $options = []): array
    {
        $startGateStatusPayload = $this->section->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerStartExecutionGateStatus($options);
        $startGateStatus = (array) data_get($startGateStatusPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_start_execution_gate_status', []);
        $readinessPayload = $this->section->agentCodexRealInvokerProcessStarterReadinessGateContractTemplate($options);

        $contract = [
            'status' => 'one_shot_tick_codex_real_invoker_process_starter_readiness_gate_contract_ready',
            'contract_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-REAL-INVOKER-PROCESS-STARTER-READINESS-GATE-CONTRACT-SELF-CONSTRUCTION-0001',
            'parent_program' => 'Atlas Self-Construction OS',
            'submodule' => 'Atlas Agent Control Plane',
            'provider' => 'codex',
            'adapter' => 'codex',
            'source_codex_real_invoker_start_execution_gate_status' => data_get($startGateStatus, 'status'),
            'source_codex_real_invoker_start_execution_gate_status_hash' => data_get($startGateStatusPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_start_execution_gate_status_hash'),
            'source_codex_real_invoker_process_starter_readiness_gate_contract_status' => data_get($readinessPayload, 'status'),
            'source_codex_real_invoker_process_starter_readiness_gate_contract_hash' => data_get($readinessPayload, 'codex_real_invoker_process_starter_readiness_gate_contract_template_hash'),
            'release_boundary' => [
                'canonical_process_starter_readiness_gate' => AgentCodexRealInvokerProcessStarterReadinessGate::class,
                'canonical_process_starter_readiness_gate_method' => 'prepareProcessStarter',
                'scheduler_invoker' => AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerProcessStarterReadinessGateInvoker::class,
                'scheduler_invoker_method' => 'prepareCodexRealInvokerProcessStarter',
                'gate_effect' => 'record_process_starter_readiness_without_starting_process',
                'start_execution_gate_required_before_process_starter_readiness' => true,
                'process_starter_ready_by_gate' => true,
                'actual_process_start_allowed_by_gate' => false,
                'external_process_started_by_gate' => false,
                'provider_started_by_gate' => false,
                'adapter_execution_allowed_by_gate' => false,
                'token_spend_allowed_by_gate' => false,
                'dispatch_allowed_by_gate' => false,
                'idempotency_key' => 'real_invoker_process_starter_readiness_gate_id',
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
                'operator_execution_gate_receipt_hash',
                'execution_gate_policy_hash',
                'execution_window_hash',
                'preflight_snapshot_hash',
                'rollback_readiness_hash',
                'human_start_signature_hash',
                'process_starter_manifest_hash',
                'supervisor_binding_hash',
                'liveness_monitor_binding_hash',
                'cancellation_contract_hash',
                'output_capture_contract_hash',
                'cost_meter_contract_hash',
                'start_replay_guard_hash',
                'operator_process_starter_signature_hash',
                'actor',
                'session',
                'reason',
            ],
            'allowed_future_mutations' => [
                'write_codex_real_invoker_process_starter_readiness_gate_metadata_on_agent_run',
                'append_codex_real_invoker_process_starter_readiness_gate_evidence_event',
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
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_process_starter_readiness_gate_preflight',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_process_starter_readiness_gate_contract.v1',
            'status' => (string) $contract['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_process_starter_readiness_gate_contract',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_process_starter_readiness_gate_allowed' => false,
            'process_starter_ready' => false,
            'actual_process_start_allowed' => false,
            'adapter_execution_allowed' => false,
            'provider_start_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_process_starter_readiness_gate_contract' => $contract,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_process_starter_readiness_gate_contract_hash' => $this->section->stableHash($contract),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_process_starter_readiness_gate_contract_does_not_prepare_process_starter',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_process_starter_readiness_gate_contract_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_process_starter_readiness_gate_contract_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_process_starter_readiness_gate_contract_does_not_enable_self_programming',
            ],
            'human_summary' => 'Automatic dispatch scheduler one-shot tick Codex real invoker process starter readiness contract is ready; it prepares starter readiness only and still cannot start Codex.',
        ];
    }


}
