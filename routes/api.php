<?php

use App\Http\Controllers\Ai\YoutubePrewarmController;
use App\Http\Controllers\AiAttachmentSearchController;
use App\Http\Controllers\AiChunkedUploadController;
use App\Http\Controllers\AiDecisionController;
use App\Http\Controllers\AiInteractionController;
use App\Http\Controllers\AiJobController;
use App\Http\Controllers\AiObservabilityController;
use App\Http\Controllers\AiProviderController;
use App\Http\Controllers\AiQualityActionController;
use App\Http\Controllers\AiTelemetryController;
use App\Http\Controllers\AiTelemetryMetricsController;
use App\Http\Controllers\AiThreadController;
use App\Http\Controllers\AtlasAiAgentBehaviorReportController;
use App\Http\Controllers\AtlasAiArchitectureOperationsController;
use App\Http\Controllers\AtlasAiArchitectureValidateController;
use App\Http\Controllers\AtlasAiControlPlaneController;
use App\Http\Controllers\AtlasAiDecisionReceiptReportController;
use App\Http\Controllers\AtlasAiDomainCatalogController;
use App\Http\Controllers\AtlasAiDynamicComputeMarketController;
use App\Http\Controllers\AtlasAiExternalGraphHarnessController;
use App\Http\Controllers\AtlasAiGovernanceController;
use App\Http\Controllers\AtlasAiHyperflowCertificationController;
use App\Http\Controllers\AtlasAiHyperflowRivalsBatteryController;
use App\Http\Controllers\AtlasAiInboxActionReportController;
use App\Http\Controllers\AtlasAiKernelPipelineReportController;
use App\Http\Controllers\AtlasAiLedgerController;
use App\Http\Controllers\AtlasAiPipelineController;
use App\Http\Controllers\AtlasAiPolicyController;
use App\Http\Controllers\AtlasAiProviderPerformanceController;
use App\Http\Controllers\AtlasAiProviderReleaseReviewController;
use App\Http\Controllers\AtlasAiProviderReleaseSourcesController;
use App\Http\Controllers\AtlasAiQualitativeLevelsController;
use App\Http\Controllers\AtlasAiRepairController;
use App\Http\Controllers\AtlasAiRepairReportController;
use App\Http\Controllers\AtlasAiRivalsStrategyController;
use App\Http\Controllers\AtlasAiRouterRuntimeBootstrapController;
use App\Http\Controllers\AtlasAiRouterRuntimeReadinessController;
use App\Http\Controllers\AtlasAiRuntimeBoundaryController;
use App\Http\Controllers\AtlasAiRuntimeReadinessController;
use App\Http\Controllers\AtlasAiSelfImprovementScheduleController;
use App\Http\Controllers\AtlasAiSelfImprovementScheduleHealthController;
use App\Http\Controllers\AtlasAiSelfImprovementScheduleReportController;
use App\Http\Controllers\AtlasAiSloController;
use App\Http\Controllers\AtlasAiStrategicDecisionController;
use App\Http\Controllers\AtlasAiStructureMotherAuditController;
use App\Http\Controllers\AtlasAiVoiceRealtimeController;
use App\Http\Controllers\AtlasAiVoxController;
use App\Http\Controllers\AtlasAiVoxDogfoodController;
use App\Http\Controllers\AtlasAiVoxMetricsController;
use App\Http\Controllers\AtlasAiVoxReadinessController;
use App\Http\Controllers\AtlasCalendarBlockController;
use App\Http\Controllers\AtlasCartographyController;
use App\Http\Controllers\AtlasCodeAttentionControlPlaneController;
use App\Http\Controllers\AtlasCodeBootController;
use App\Http\Controllers\AtlasCodeCheckpointController;
use App\Http\Controllers\AtlasCodeDevToForgePromotionController;
use App\Http\Controllers\AtlasCodeDiffController;
use App\Http\Controllers\AtlasCodeEnterpriseCertificationController;
use App\Http\Controllers\AtlasCodeEvidenceController;
use App\Http\Controllers\AtlasCodeForgeExecutionController;
use App\Http\Controllers\AtlasCodeForgeFastPathController;
use App\Http\Controllers\AtlasCodeForgeFastPathStatusController;
use App\Http\Controllers\AtlasCodeForgeProviderCapacityController;
use App\Http\Controllers\AtlasCodeForgeProviderInvocationController;
use App\Http\Controllers\AtlasCodeForgeProviderTopologyController;
use App\Http\Controllers\AtlasCodeForgeReviewCompletionController;
use App\Http\Controllers\AtlasCodeForgeReviewController;
use App\Http\Controllers\AtlasCodeForgeRuntimeDispatchController;
use App\Http\Controllers\AtlasCodeForgeUxOrchestratorController;
use App\Http\Controllers\AtlasCodeForgeWorkIntakeController;
use App\Http\Controllers\AtlasCodeMcpStatusController;
use App\Http\Controllers\AtlasCodeObraCommandCenterController;
use App\Http\Controllers\AtlasCodeObservedSessionController;
use App\Http\Controllers\AtlasCodeProgrammingWorkItemController;
use App\Http\Controllers\AtlasCodeProviderArenaController;
use App\Http\Controllers\AtlasCodeProviderGovernanceController;
use App\Http\Controllers\AtlasCodeProviderOperatingRoomController;
use App\Http\Controllers\AtlasCodeReceiptController;
use App\Http\Controllers\AtlasCodeReceiptShowController;
use App\Http\Controllers\AtlasCodeSelfImprovementActivationCockpitController;
use App\Http\Controllers\AtlasCodeSelfImprovementClosedLoopController;
use App\Http\Controllers\AtlasCodeSelfImprovementForgeActivationController;
use App\Http\Controllers\AtlasCodeSelfImprovementGovernanceController;
use App\Http\Controllers\AtlasCodeSelfImprovementNextCycleController;
use App\Http\Controllers\AtlasCodeSelfImprovementProposalBacklogController;
use App\Http\Controllers\AtlasCodeSelfImprovementResultLedgerController;
use App\Http\Controllers\AtlasCodeSessionController;
use App\Http\Controllers\AtlasCodeThreadController;
use App\Http\Controllers\AtlasCodeWorkController;
use App\Http\Controllers\AtlasCodeWorkPacketController;
use App\Http\Controllers\AtlasCodeWorkspaceController;
use App\Http\Controllers\AtlasConstelacaoController;
use App\Http\Controllers\AtlasDev\CancelController;
use App\Http\Controllers\AtlasDev\IndexController as AtlasDevIndexController;
use App\Http\Controllers\AtlasDev\PlanController;
use App\Http\Controllers\AtlasDev\ReadinessController;
use App\Http\Controllers\AtlasDev\RunController;
use App\Http\Controllers\AtlasDev\ShowController;
use App\Http\Controllers\AtlasDev\StreamController;
use App\Http\Controllers\AtlasDomainController;
use App\Http\Controllers\AtlasFrontendWorkspaceController;
use App\Http\Controllers\AtlasMemoryController;
use App\Http\Controllers\AtlasMemoryMaintenanceController;
use App\Http\Controllers\AtlasMemoryRecallController;
use App\Http\Controllers\AtlasMobilePushReplayController;
use App\Http\Controllers\AtlasOpenBrainController;
use App\Http\Controllers\AtlasOpenBrainMcpController;
use App\Http\Controllers\AtlasProgrammingGovernanceController;
use App\Http\Controllers\AtlasProjectBlockerController;
use App\Http\Controllers\AtlasProjectController;
use App\Http\Controllers\AtlasProjectPlanProposalController;
use App\Http\Controllers\AtlasRoutineController;
use App\Http\Controllers\AtlasSddAgentRoleController;
use App\Http\Controllers\AtlasSddController;
use App\Http\Controllers\AtlasSddMcpResourceController;
use App\Http\Controllers\AtlasTaskController;
use App\Http\Controllers\AtlasToolRuntimeController;
use App\Http\Controllers\AtlasVaultController;
use App\Http\Controllers\AtlasWorkspaceIntelligenceController;
use App\Http\Controllers\AuditEventController;
use App\Http\Controllers\AuditSuggestionController;
use App\Http\Controllers\BehaviorController;
use App\Http\Controllers\BehaviorLogController;
use App\Http\Controllers\BitaculaController;
use App\Http\Controllers\CaptureController;
use App\Http\Controllers\CheckinController;
use App\Http\Controllers\CognitiveGameController;
use App\Http\Controllers\DailyMissionController;
use App\Http\Controllers\DigitalActivitySnapshotController;
use App\Http\Controllers\DigitalCategoryMappingController;
use App\Http\Controllers\DigitalSessionController;
use App\Http\Controllers\EngineeringBenchmarkController;
use App\Http\Controllers\EngineeringKnowledgeController;
use App\Http\Controllers\EngineeringProjectBlueprintController;
use App\Http\Controllers\EngineeringRunController;
use App\Http\Controllers\EngineeringTaskGateController;
use App\Http\Controllers\EngineeringToolScanController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\HealthSnapshotController;
use App\Http\Controllers\InboxController;
use App\Http\Controllers\Mobile\MobileDeviceController;
use App\Http\Controllers\Mobile\MobileHealthController;
use App\Http\Controllers\Mobile\MobileInboxController;
use App\Http\Controllers\Mobile\MobileMacAgentController;
use App\Http\Controllers\Mobile\MobilePairingController;
use App\Http\Controllers\Mobile\MobileRecommendationController;
use App\Http\Controllers\Mobile\MobileThreadController;
use App\Http\Controllers\PassiveSignalController;
use App\Http\Controllers\ProcrastinationEventController;
use App\Http\Controllers\RizeWebhookController;
use App\Http\Controllers\SemanticActivationController;
use App\Http\Controllers\SemanticCurationProposalController;
use App\Http\Controllers\SemanticNoteController;
use App\Http\Controllers\SemanticSearchController;
use App\Http\Controllers\SyncController;
use App\Http\Controllers\VaultHealthController;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class);
Route::post('/integrations/rize/webhook', RizeWebhookController::class);

$registerAtlasVoiceRoutes = static function (): void {
    Route::get('/ai/voice/health', [AtlasAiVoiceRealtimeController::class, 'health']);
    Route::get('/ai/voice/readiness', [AtlasAiVoiceRealtimeController::class, 'readiness']);
    Route::get('/ai/voice/rivals', [AtlasAiVoiceRealtimeController::class, 'rivals']);
    Route::get('/ai/voice/eclipse/active', [AtlasAiVoiceRealtimeController::class, 'eclipse']);
    Route::get('/ai/voice/runtime/contract', [AtlasAiVoiceRealtimeController::class, 'contract']);
    Route::get('/ai/voice/runtime/bootstrap', [AtlasAiVoiceRealtimeController::class, 'bootstrap']);
    Route::get('/ai/voice/runtime/dependencies', [AtlasAiVoiceRealtimeController::class, 'dependencies']);
    Route::get('/ai/voice/runtime/dependency-install-plan', [AtlasAiVoiceRealtimeController::class, 'dependencyInstallPlan']);
    Route::get('/ai/voice/runtime/token-issuer-plan', [AtlasAiVoiceRealtimeController::class, 'tokenIssuerPlan']);
    Route::get('/ai/voice/runtime/token-issuer-smoke', [AtlasAiVoiceRealtimeController::class, 'tokenIssuerSmoke']);
    Route::get('/ai/voice/runtime/livekit-server-probe', [AtlasAiVoiceRealtimeController::class, 'liveKitServerProbe']);
    Route::get('/ai/voice/runtime/pre-start-health-checks-smoke', [AtlasAiVoiceRealtimeController::class, 'preStartHealthChecksSmoke']);
    Route::get('/ai/voice/runtime/certification', [AtlasAiVoiceRealtimeController::class, 'runtimeCertification']);
    Route::get('/ai/voice/runtime/product-loop-check', [AtlasAiVoiceRealtimeController::class, 'productLoopCheck']);
    Route::get('/ai/voice/runtime/promotion-review-packet', [AtlasAiVoiceRealtimeController::class, 'promotionReviewPacket']);
    Route::post('/ai/voice/runtime/events/normalize', [AtlasAiVoiceRealtimeController::class, 'normalizeRuntimeEvent']);
    Route::post('/ai/voice/runtime/events/normalize-sequence', [AtlasAiVoiceRealtimeController::class, 'normalizeRuntimeEventSequence']);
    Route::post('/ai/voice/session/start', [AtlasAiVoiceRealtimeController::class, 'start']);
    Route::post('/ai/voice/session/end', [AtlasAiVoiceRealtimeController::class, 'end']);
    Route::post('/ai/voice/wake-word', [AtlasAiVoiceRealtimeController::class, 'wakeWord']);
    Route::post('/ai/voice/turn', [AtlasAiVoiceRealtimeController::class, 'turn']);
    Route::post('/ai/voice/turn/interrupted', [AtlasAiVoiceRealtimeController::class, 'interrupted']);
    Route::post('/ai/voice/tts/synthesize', [AtlasAiVoiceRealtimeController::class, 'synthesizeTts']);
    Route::post('/ai/voice/turn/synthesized', [AtlasAiVoiceRealtimeController::class, 'synthesized']);
    Route::post('/ai/voice/turn/played', [AtlasAiVoiceRealtimeController::class, 'played']);
    Route::post('/ai/voice/runtime/failed', [AtlasAiVoiceRealtimeController::class, 'failed']);
    Route::post('/ai/voice/provider/health-degraded', [AtlasAiVoiceRealtimeController::class, 'providerHealth']);
};

Route::prefix('v1/mobile')->group(function () use ($registerAtlasVoiceRoutes): void {
    Route::post('/pairing/confirm', [MobilePairingController::class, 'confirm']);

    Route::middleware('atlas.token')->group(function (): void {
        Route::post('/pairing/initiate', [MobilePairingController::class, 'initiate']);
    });

    Route::middleware('atlas.mobile.bearer')->group(function () use ($registerAtlasVoiceRoutes): void {
        Route::get('/health', [MobileHealthController::class, 'show']);
        Route::post('/telemetry/events', [AiTelemetryController::class, 'store']);

        Route::get('/devices', [MobileDeviceController::class, 'index']);
        Route::post('/devices/push-token', [MobileDeviceController::class, 'updatePushToken']);
        Route::post('/devices/notification-preferences', [MobileDeviceController::class, 'updateNotificationPreferences']);
        Route::delete('/devices/{device}', [MobileDeviceController::class, 'revoke']);

        Route::get('/mac/status', [MobileMacAgentController::class, 'status']);
        Route::post('/mac/remote-session', [MobileMacAgentController::class, 'startRemoteSession']);
        Route::post('/mac/remote-session/{session}/stop', [MobileMacAgentController::class, 'stopRemoteSession']);
        Route::post('/mac/sleep-now', [MobileMacAgentController::class, 'sleepNow']);
        Route::post('/mac/bootstrap', [MobileMacAgentController::class, 'bootstrap']);
        Route::post('/mac/caffeinate/cleanup', [MobileMacAgentController::class, 'cleanupCaffeinate']);
        Route::post('/mac/maintenance-windows', [MobileMacAgentController::class, 'storeMaintenanceWindow']);
        Route::delete('/mac/maintenance-windows/{window}', [MobileMacAgentController::class, 'deleteMaintenanceWindow']);

        Route::get('/inbox', [MobileInboxController::class, 'index']);
        Route::get('/inbox/critical-review', [MobileInboxController::class, 'criticalReview']);
        Route::get('/inbox/{inboxItem}', [MobileInboxController::class, 'show']);
        Route::post('/inbox/{inboxItem}/read', [MobileInboxController::class, 'markRead']);
        Route::post('/inbox/{inboxItem}/dismiss', [MobileInboxController::class, 'dismiss']);
        Route::post('/inbox/{inboxItem}/snooze', [MobileInboxController::class, 'snooze']);
        Route::post('/inbox/{inboxItem}/respond', [MobileInboxController::class, 'respond']);
        Route::post('/inbox/{inboxItem}/discuss', [MobileInboxController::class, 'discuss']);
        Route::post('/inbox/{inboxItem}/discussion-bootstrap/retry', [MobileInboxController::class, 'retryDiscussionBootstrap']);

        Route::get('/ai/recommendations', [MobileRecommendationController::class, 'index']);
        Route::get('/ai/recommendations/{recommendation}', [MobileRecommendationController::class, 'show']);
        Route::post('/ai/recommendations/{recommendation}/transition', [MobileRecommendationController::class, 'transition']);
        Route::get('/atlas/celestial/positions', [AtlasConstelacaoController::class, 'positions']);
        $registerAtlasVoiceRoutes();

        Route::post('/threads/from-inbox/{inboxItem}', [MobileThreadController::class, 'fromInbox']);
        Route::post('/threads/{thread}/reply', [MobileThreadController::class, 'reply']);
        Route::get('/threads/{thread}', [MobileThreadController::class, 'show']);
    });
});

Route::middleware('atlas.token')->group(function () use ($registerAtlasVoiceRoutes): void {
    Route::apiResource('domains', AtlasDomainController::class)->only(['index', 'store', 'update', 'destroy']);
    Route::get('/inbox', [InboxController::class, 'index']);
    Route::get('/inbox/health', [InboxController::class, 'health']);
    Route::post('/inbox/bulk', [InboxController::class, 'bulk']);
    Route::get('/tasks/agenda', [AtlasTaskController::class, 'agenda']);
    Route::post('/tasks/agenda/plan', [AtlasTaskController::class, 'planAgenda']);
    Route::get('/tasks/agenda/week', [AtlasTaskController::class, 'weekAgenda']);
    Route::post('/tasks/agenda/week/plan', [AtlasTaskController::class, 'planWeekAgenda']);
    Route::get('/tasks/{task}/events', [AtlasTaskController::class, 'events']);
    Route::get('/tasks/{task}/memory', [AtlasMemoryController::class, 'forTask']);
    Route::get('/tasks/{task}/engineering', [AtlasTaskController::class, 'engineering']);
    Route::get('/tasks/{task}/engineering/runs', [EngineeringRunController::class, 'indexForTask']);
    Route::post('/tasks/{task}/engineering/runs', [EngineeringRunController::class, 'storeForTask']);
    Route::post('/tasks/{task}/engineering/blueprint/freeze', [AtlasTaskController::class, 'freezeEngineeringBlueprint']);
    Route::post('/tasks/{task}/engineering/evidence', [AtlasTaskController::class, 'engineeringEvidence']);
    Route::post('/tasks/{task}/engineering/qa', [EngineeringTaskGateController::class, 'qa']);
    Route::post('/tasks/{task}/engineering/review/deep', [EngineeringTaskGateController::class, 'deepReview']);
    Route::post('/tasks/{task}/engineering/db/review', [EngineeringTaskGateController::class, 'dbReview']);
    Route::post('/tasks/{task}/engineering/db/explain', [EngineeringTaskGateController::class, 'dbExplain']);
    Route::get('/engineering/runs/{run}', [EngineeringRunController::class, 'show']);
    Route::post('/engineering/runs/{run}/replay', [EngineeringRunController::class, 'replay']);
    Route::post('/engineering/runs/{run}/attempts/{attempt}/replay', [EngineeringRunController::class, 'replayAttempt']);
    Route::post('/engineering/runs/{run}/cancel', [EngineeringRunController::class, 'cancel']);
    Route::post('/engineering/runs/{run}/operator-action', [EngineeringRunController::class, 'operatorAction']);
    Route::get('/engineering/runs/{run}/patch-artifacts/{patch}/diff', [EngineeringRunController::class, 'showPatchDiff']);
    Route::get('/engineering/runs/{run}/test-runs/{testRun}/artifacts', [EngineeringRunController::class, 'testRunArtifacts']);
    Route::get('/engineering/runs/{run}/test-runs/{testRun}/artifacts/content', [EngineeringRunController::class, 'showTestRunArtifact']);
    Route::get('/engineering/runs/{run}/memory', [AtlasMemoryController::class, 'forRun']);
    Route::get('/engineering/runs/{run}/review-findings', [EngineeringRunController::class, 'reviewFindings']);
    Route::post('/engineering/runs/{run}/review-findings', [EngineeringRunController::class, 'storeReviewFinding']);
    Route::patch('/engineering/review-findings/{finding}', [EngineeringRunController::class, 'updateReviewFinding']);
    Route::get('/engineering/controls', [EngineeringRunController::class, 'controls']);
    Route::get('/engineering/harnessability', [EngineeringRunController::class, 'harnessability']);
    Route::get('/engineering/harnessability/calibration', [EngineeringRunController::class, 'harnessabilityCalibration']);
    Route::post('/engineering/harnessability/calibrate', [EngineeringRunController::class, 'calibrateHarnessability']);
    Route::post('/engineering/api-contract', [EngineeringToolScanController::class, 'apiContract']);
    Route::post('/engineering/security-scan', [EngineeringToolScanController::class, 'securityScan']);
    Route::post('/engineering/sbom', [EngineeringToolScanController::class, 'sbom']);
    Route::get('/tools', [AtlasToolRuntimeController::class, 'index']);
    Route::get('/tools/doctor', [AtlasToolRuntimeController::class, 'doctor']);
    Route::get('/tools/authority', [AtlasToolRuntimeController::class, 'authority']);
    Route::get('/tools/authority/policies', [AtlasToolRuntimeController::class, 'authorityPolicies']);
    Route::put('/tools/authority/policies/{authorityGroup}', [AtlasToolRuntimeController::class, 'setAuthorityPolicy']);
    Route::delete('/tools/authority/policies/{authorityGroup}', [AtlasToolRuntimeController::class, 'revokeAuthorityPolicy']);
    Route::get('/tools/evidence', [AtlasToolRuntimeController::class, 'evidence']);
    Route::get('/tools/evidence/{run}', [AtlasToolRuntimeController::class, 'evidenceShow']);
    Route::get('/tools/evidence/{run}/export', [AtlasToolRuntimeController::class, 'evidenceExport']);
    Route::get('/tools/gate', [AtlasToolRuntimeController::class, 'gate']);
    Route::get('/tools/release-gate', [AtlasToolRuntimeController::class, 'releaseGate']);
    Route::get('/tools/policies', [AtlasToolRuntimeController::class, 'policies']);
    Route::post('/tools/findings/{finding}/waiver', [AtlasToolRuntimeController::class, 'waiveFinding']);
    Route::delete('/tools/findings/{finding}/waiver', [AtlasToolRuntimeController::class, 'revokeFindingWaiver']);
    Route::get('/tools/{tool}/commands', [AtlasToolRuntimeController::class, 'commands']);
    Route::post('/tools/{tool}/commands/{recipe}/run', [AtlasToolRuntimeController::class, 'runRecipe']);
    Route::get('/tools/{tool}', [AtlasToolRuntimeController::class, 'show']);
    Route::post('/tools/{tool}/run', [AtlasToolRuntimeController::class, 'run']);
    Route::post('/tools/{tool}/approval', [AtlasToolRuntimeController::class, 'approve']);
    Route::delete('/tools/{tool}/approval', [AtlasToolRuntimeController::class, 'revoke']);
    Route::get('/engineering/knowledge', [EngineeringKnowledgeController::class, 'index']);
    Route::get('/engineering/knowledge/context', [EngineeringKnowledgeController::class, 'context']);
    Route::post('/engineering/knowledge/sync', [EngineeringKnowledgeController::class, 'sync']);
    Route::post('/engineering/knowledge/code/index', [EngineeringKnowledgeController::class, 'codeIndex']);
    Route::get('/engineering/knowledge/code/audit', [EngineeringKnowledgeController::class, 'codeAudit']);
    Route::get('/engineering/knowledge/code/modules', [EngineeringKnowledgeController::class, 'codeModules']);
    Route::get('/engineering/knowledge/code/modules/{module}', [EngineeringKnowledgeController::class, 'codeModule']);
    Route::get('/engineering/knowledge/code/symbols', [EngineeringKnowledgeController::class, 'codeSymbols']);
    Route::get('/engineering/knowledge/items/{item}', [EngineeringKnowledgeController::class, 'show']);
    Route::get('/engineering/benchmarks/suites', [EngineeringBenchmarkController::class, 'indexSuites']);
    Route::post('/engineering/benchmarks/suites', [EngineeringBenchmarkController::class, 'storeSuite']);
    Route::post('/engineering/benchmarks/suites/default', [EngineeringBenchmarkController::class, 'ensureDefaultSuite']);
    Route::post('/engineering/benchmarks/fair-claude/prepare', [EngineeringBenchmarkController::class, 'prepareFairClaudeSuite']);
    Route::get('/engineering/benchmarks/suites/{suite}/trends', [EngineeringBenchmarkController::class, 'showTrends']);
    Route::get('/engineering/benchmarks/suites/{suite}/fair-claude-report', [EngineeringBenchmarkController::class, 'showFairClaudeReport']);
    Route::post('/engineering/benchmarks/suites/{suite}/rivals/battery-plan', [EngineeringBenchmarkController::class, 'rivalsBatteryPlan']);
    Route::post('/engineering/benchmarks/suites/{suite}/corpus/refresh', [EngineeringBenchmarkController::class, 'refreshCorpus']);
    Route::post('/engineering/benchmarks/suites/{suite}/calibrate', [EngineeringBenchmarkController::class, 'calibrateSuite']);
    Route::get('/engineering/benchmarks/suites/{suite}', [EngineeringBenchmarkController::class, 'showSuite']);
    Route::post('/engineering/benchmarks/suites/{suite}/cases', [EngineeringBenchmarkController::class, 'storeCase']);
    Route::post('/engineering/benchmarks/suites/{suite}/cases/from-run', [EngineeringBenchmarkController::class, 'promoteRunCase']);
    Route::post('/engineering/benchmarks/suites/{suite}/run', [EngineeringBenchmarkController::class, 'runSuite']);
    Route::get('/engineering/benchmarks/runs/{benchmarkRun}/replay-manifest', [EngineeringBenchmarkController::class, 'replayManifest']);
    Route::get('/engineering/benchmarks/runs/{benchmarkRun}', [EngineeringBenchmarkController::class, 'showRun']);
    Route::patch('/engineering/benchmarks/runs/{benchmarkRun}/outcome', [EngineeringBenchmarkController::class, 'recordOutcome']);
    Route::post('/tasks/{task}/schedule', [AtlasTaskController::class, 'schedule']);
    Route::post('/tasks/{task}/defer', [AtlasTaskController::class, 'defer']);
    Route::post('/tasks/{task}/complete', [AtlasTaskController::class, 'complete']);
    Route::apiResource('tasks', AtlasTaskController::class)->only(['index', 'show', 'update']);
    Route::post('/routines/generate-due', [AtlasRoutineController::class, 'generateDue']);
    Route::get('/routines/{routine}/events', [AtlasRoutineController::class, 'events']);
    Route::post('/routines/{routine}/generate', [AtlasRoutineController::class, 'generate']);
    Route::apiResource('routines', AtlasRoutineController::class)->only(['index', 'store', 'show', 'update']);
    Route::get('/projects/review', [AtlasProjectController::class, 'reviewQueue']);
    Route::get('/projects/{project}/execution', [AtlasProjectController::class, 'execution']);
    Route::get('/projects/{project}/memory', [AtlasMemoryController::class, 'forProject']);
    Route::get('/projects/{project}/engineering/blueprint', [EngineeringProjectBlueprintController::class, 'show']);
    Route::post('/projects/{project}/engineering/blueprint/prepare', [EngineeringProjectBlueprintController::class, 'prepare']);
    Route::post('/projects/{project}/engineering/blueprint/create', [EngineeringProjectBlueprintController::class, 'create']);
    Route::post('/projects/{project}/engineering/blueprint/validate', [EngineeringProjectBlueprintController::class, 'validateBlueprint']);
    Route::post('/projects/{project}/engineering/blueprint/freeze', [EngineeringProjectBlueprintController::class, 'freeze']);
    Route::post('/projects/{project}/engineering/tasks/generate', [EngineeringProjectBlueprintController::class, 'generateTasks']);
    Route::post('/projects/{project}/execution/start', [AtlasProjectController::class, 'startExecution']);
    Route::post('/projects/{project}/recover', [AtlasProjectController::class, 'recover']);
    Route::get('/projects/{project}/events', [AtlasProjectController::class, 'events']);
    Route::get('/projects/{project}/steps', [AtlasProjectController::class, 'steps']);
    Route::get('/projects/{project}/blockers', [AtlasProjectBlockerController::class, 'index']);
    Route::post('/projects/{project}/blockers', [AtlasProjectBlockerController::class, 'store']);
    Route::post('/projects/{project}/blockers/{blocker}/resolve', [AtlasProjectBlockerController::class, 'resolve']);
    Route::post('/projects/{project}/blockers/{blocker}/task', [AtlasProjectBlockerController::class, 'convertToTask']);
    Route::post('/projects/{project}/review', [AtlasProjectController::class, 'reviewAction']);
    Route::patch('/projects/{project}/steps/{step}', [AtlasProjectController::class, 'updateStep']);
    Route::post('/projects/{project}/steps/{step}/activate', [AtlasProjectController::class, 'activateStep']);
    Route::get('/projects/{project}/plan/proposals', [AtlasProjectPlanProposalController::class, 'indexForProject']);
    Route::post('/projects/{project}/plan/propose', [AtlasProjectPlanProposalController::class, 'proposeForProject']);
    Route::post('/projects/{project}/plan/proposals/{proposal}/accept', [AtlasProjectPlanProposalController::class, 'acceptForProject']);
    Route::post('/projects/{project}/plan/proposals/{proposal}/reject', [AtlasProjectPlanProposalController::class, 'rejectForProject']);
    Route::post('/projects/{project}/plan/proposals/{proposal}/regenerate', [AtlasProjectPlanProposalController::class, 'regenerateForProject']);
    Route::post('/projects/{project}/plan', [AtlasProjectController::class, 'plan']);
    Route::post('/projects/{project}/next-action', [AtlasProjectController::class, 'nextAction']);
    Route::apiResource('projects', AtlasProjectController::class)->only(['index', 'store', 'show', 'update']);
    Route::post('/project-plan-proposals/{proposal}/accept', [AtlasProjectPlanProposalController::class, 'accept']);
    Route::post('/project-plan-proposals/{proposal}/reject', [AtlasProjectPlanProposalController::class, 'reject']);
    Route::post('/project-plan-proposals/{proposal}/regenerate', [AtlasProjectPlanProposalController::class, 'regenerate']);
    Route::get('/calendar/blocks', [AtlasCalendarBlockController::class, 'index']);
    Route::post('/calendar/blocks', [AtlasCalendarBlockController::class, 'store']);
    Route::patch('/calendar/blocks/{calendarBlock}', [AtlasCalendarBlockController::class, 'update']);
    Route::delete('/calendar/blocks/{calendarBlock}', [AtlasCalendarBlockController::class, 'destroy']);

    Route::get('/captures/{capture}/file', [CaptureController::class, 'file']);
    Route::get('/captures/{capture}/transcription', [CaptureController::class, 'transcription']);
    Route::post('/captures/{capture}/transcription/retry', [CaptureController::class, 'retryTranscription']);
    Route::post('/captures/{capture}/semantic/clarify', [CaptureController::class, 'clarify']);
    Route::get('/captures/{capture}/project-plan/proposals', [AtlasProjectPlanProposalController::class, 'indexForCapture']);
    Route::post('/captures/{capture}/project-plan/propose', [AtlasProjectPlanProposalController::class, 'proposeForCapture']);
    Route::post('/captures/{capture}/triage', [CaptureController::class, 'triage']);
    Route::apiResource('captures', CaptureController::class)->except(['create', 'edit']);

    Route::apiResource('checkins', CheckinController::class)->except(['create', 'edit', 'show']);
    Route::apiResource('passive-signals', PassiveSignalController::class)->except(['create', 'edit', 'show']);
    Route::apiResource('health-snapshots', HealthSnapshotController::class)->except(['create', 'edit', 'show']);
    Route::get('/bitacula/factors', [BitaculaController::class, 'factors']);
    Route::post('/bitacula/normalize', [BitaculaController::class, 'normalize']);
    Route::get('/bitacula/briefing', [BitaculaController::class, 'briefing']);
    Route::get('/bitacula/analysis', [BitaculaController::class, 'analysis']);
    Route::apiResource('behaviors', BehaviorController::class)->except(['create', 'edit', 'show']);
    Route::apiResource('behavior-logs', BehaviorLogController::class)->except(['create', 'edit', 'show']);
    Route::apiResource('digital-category-mappings', DigitalCategoryMappingController::class)->except(['create', 'edit', 'show']);
    Route::apiResource('digital-sessions', DigitalSessionController::class)->except(['create', 'edit', 'show']);
    Route::post('/digital-activity-snapshots/rebuild', [DigitalActivitySnapshotController::class, 'rebuild']);
    Route::apiResource('digital-activity-snapshots', DigitalActivitySnapshotController::class)->except(['create', 'edit', 'show']);
    Route::apiResource('procrastination-events', ProcrastinationEventController::class)->except(['create', 'edit', 'show']);

    Route::get('/mission/today', [DailyMissionController::class, 'showToday']);
    Route::put('/mission/today', [DailyMissionController::class, 'upsertToday']);

    Route::post('/semantic/notes/reindex', [SemanticNoteController::class, 'reindex']);
    Route::get('/semantic/notes', [SemanticNoteController::class, 'index']);
    Route::get('/semantic/notes/{semanticNote}', [SemanticNoteController::class, 'show']);
    Route::post('/semantic/search', SemanticSearchController::class);

    Route::get('/semantic/curation-proposals', [SemanticCurationProposalController::class, 'index']);
    Route::post('/semantic/curation-proposals/{proposal}/accept', [SemanticCurationProposalController::class, 'accept']);
    Route::post('/semantic/curation-proposals/{proposal}/dismiss', [SemanticCurationProposalController::class, 'dismiss']);
    Route::post('/semantic/curation-proposals/{proposal}/postpone', [SemanticCurationProposalController::class, 'postpone']);

    Route::get('/semantic/activations', [SemanticActivationController::class, 'index']);
    Route::post('/semantic/activations', [SemanticActivationController::class, 'create']);
    Route::post('/semantic/activations/{activation}/shown', [SemanticActivationController::class, 'markShown']);
    Route::post('/semantic/activations/{activation}/feedback', [SemanticActivationController::class, 'feedback']);
    Route::post('/semantic/activations/{activation}/dismiss', [SemanticActivationController::class, 'dismiss']);

    Route::get('/semantic/vault-health', [VaultHealthController::class, 'show']);
    Route::post('/semantic/vault-health/recompute', [VaultHealthController::class, 'recompute']);

    Route::get('/semantic/cognitive-games/today', [CognitiveGameController::class, 'today']);
    Route::post('/semantic/cognitive-games', [CognitiveGameController::class, 'start']);
    Route::post('/semantic/cognitive-games/{game}/answer', [CognitiveGameController::class, 'answer']);
    Route::get('/atlas/celestial/positions', [AtlasConstelacaoController::class, 'positions']);

    Route::get('/ai/interactions', [AiInteractionController::class, 'index']);
    Route::get('/ai/decisions', [AiDecisionController::class, 'index']);
    Route::post('/ai/decisions/preview', [AiDecisionController::class, 'preview']);
    Route::get('/ai/decisions/{decision}', [AiDecisionController::class, 'show']);
    Route::get('/ai/policies/profiles', [AtlasAiPolicyController::class, 'profiles']);
    Route::post('/ai/policies/preview', [AtlasAiPolicyController::class, 'preview']);
    Route::patch('/ai/policies/domains/{domain}', [AtlasAiPolicyController::class, 'updateDomain']);
    Route::patch('/ai/policies/flows/{flow}', [AtlasAiPolicyController::class, 'updateFlow']);
    Route::get('/ai/domains', AtlasAiDomainCatalogController::class);
    Route::get('/ai/dynamic-compute-market', AtlasAiDynamicComputeMarketController::class);
    Route::match(['GET', 'POST'], '/ai/external-graph-harness', AtlasAiExternalGraphHarnessController::class);
    Route::get('/ai/architecture/validate', AtlasAiArchitectureValidateController::class);
    Route::get('/ai/architecture/operations', AtlasAiArchitectureOperationsController::class);
    Route::get('/ai/architecture/readiness', [AtlasAiGovernanceController::class, 'architectureReadiness']);
    Route::get('/ai/router-runtime/bootstrap', AtlasAiRouterRuntimeBootstrapController::class);
    Route::get('/ai/router-runtime/readiness', AtlasAiRouterRuntimeReadinessController::class);
    Route::get('/ai/hyperflow/certification', AtlasAiHyperflowCertificationController::class);
    Route::get('/ai/hyperflow/rivals-battery', [AtlasAiHyperflowRivalsBatteryController::class, 'show']);
    Route::post('/ai/hyperflow/rivals-battery/prepare', [AtlasAiHyperflowRivalsBatteryController::class, 'prepare']);
    Route::post('/ai/hyperflow/rivals-battery/run', [AtlasAiHyperflowRivalsBatteryController::class, 'run']);
    Route::post('/ai/hyperflow/rivals-battery/external-evidence', [AtlasAiHyperflowRivalsBatteryController::class, 'externalEvidence']);
    Route::get('/ai/hyperflow/rivals-battery/external-evidence/template', [AtlasAiHyperflowRivalsBatteryController::class, 'externalEvidenceTemplate']);
    Route::get('/ai/hyperflow/rivals-battery/external-evidence/candidates', [AtlasAiHyperflowRivalsBatteryController::class, 'externalEvidenceCandidates']);
    Route::get('/ai/hyperflow/rivals-battery/external-evidence/runbook', [AtlasAiHyperflowRivalsBatteryController::class, 'externalEvidenceRunbook']);
    Route::post('/ai/hyperflow/rivals-battery/external-evidence/preflight', [AtlasAiHyperflowRivalsBatteryController::class, 'externalEvidencePreflight']);
    Route::post('/ai/hyperflow/rivals-battery/external-evidence/export', [AtlasAiHyperflowRivalsBatteryController::class, 'exportExternalEvidence']);
    Route::post('/ai/hyperflow/rivals-battery/external-evidence/import', [AtlasAiHyperflowRivalsBatteryController::class, 'importExternalEvidence']);
    Route::get('/ai/runtime-boundary', AtlasAiRuntimeBoundaryController::class);
    Route::get('/ai/structure-mother-audit', AtlasAiStructureMotherAuditController::class);
    Route::post('/ai/mobile/push/replay', AtlasMobilePushReplayController::class);
    Route::get('/ai/session-bootstrap', [AtlasAiGovernanceController::class, 'sessionBootstrap']);
    Route::get('/ai/feature-placement', [AtlasAiGovernanceController::class, 'placeFeature']);
    Route::get('/ai/docs-split-plan', [AtlasAiGovernanceController::class, 'docsSplitPlan']);
    Route::get('/ai/agent-behavior/report', AtlasAiAgentBehaviorReportController::class);
    Route::get('/ai/decision-receipts/report', AtlasAiDecisionReceiptReportController::class);
    Route::get('/ai/ledger/{envelope}', [AtlasAiLedgerController::class, 'show']);
    Route::get('/ai/inbox-actions/report', AtlasAiInboxActionReportController::class);
    Route::get('/ai/kernel-pipeline/report', AtlasAiKernelPipelineReportController::class);
    Route::post('/ai/pipeline', AtlasAiPipelineController::class);
    Route::get('/ai/provider-performance', AtlasAiProviderPerformanceController::class);
    Route::get('/ai/provider-release-sources', AtlasAiProviderReleaseSourcesController::class);
    Route::get('/ai/provider-release-review', AtlasAiProviderReleaseReviewController::class);
    Route::get('/ai/qualitative-levels', AtlasAiQualitativeLevelsController::class);
    Route::post('/ai/repair', AtlasAiRepairController::class);
    Route::get('/ai/repair/report', AtlasAiRepairReportController::class);
    Route::get('/ai/rivals-strategy', AtlasAiRivalsStrategyController::class);
    Route::get('/ai/rivals-strategy/due-reviews', [AtlasAiRivalsStrategyController::class, 'dueReviews']);
    Route::post('/ai/rivals-strategy/review', [AtlasAiRivalsStrategyController::class, 'recordReview']);
    Route::get('/ai/self-improvement/schedule', AtlasAiSelfImprovementScheduleController::class);
    Route::get('/ai/self-improvement/schedule/health', AtlasAiSelfImprovementScheduleHealthController::class);
    Route::get('/ai/self-improvement/schedule/report', AtlasAiSelfImprovementScheduleReportController::class);
    Route::get('/ai/slo', AtlasAiSloController::class);
    Route::post('/ai/strategic-decision/review', AtlasAiStrategicDecisionController::class);
    $registerAtlasVoiceRoutes();
    Route::get('/ai/memory', [AtlasMemoryController::class, 'index']);
    Route::post('/ai/memory', [AtlasMemoryController::class, 'store']);
    Route::post('/ai/memory/recall', AtlasMemoryRecallController::class);
    Route::post('/ai/memory/maintain', AtlasMemoryMaintenanceController::class);
    Route::post('/ai/open-brain/context-pack', [AtlasOpenBrainController::class, 'contextPack']);
    Route::get('/ai/open-brain/audits', [AtlasOpenBrainController::class, 'audits']);
    Route::match(['GET', 'POST'], '/ai/open-brain/mcp', AtlasOpenBrainMcpController::class);
    Route::get('/ai/memory/audit/traces/{trace}', [AtlasMemoryController::class, 'auditTrace']);
    Route::get('/ai/memory/deltas', [AtlasMemoryController::class, 'indexDeltas']);
    Route::get('/ai/memory/deltas/{delta}', [AtlasMemoryController::class, 'showDelta']);
    Route::post('/ai/memory/deltas/{delta}/review', [AtlasMemoryController::class, 'reviewDelta']);
    Route::post('/ai/memory/deltas/{delta}/promote', [AtlasMemoryController::class, 'promoteDelta']);
    Route::post('/ai/memory/governance/scan', [AtlasMemoryController::class, 'scanGovernance']);
    Route::post('/ai/memory/privacy/scan', [AtlasMemoryController::class, 'scanPrivacy']);
    Route::get('/ai/memory/review-queue', [AtlasMemoryController::class, 'reviewQueue']);
    Route::get('/ai/memory/provider-projection/status', [AtlasMemoryController::class, 'providerProjectionStatus']);
    Route::get('/ai/memory/provider-projection/review', [AtlasMemoryController::class, 'providerProjectionReview']);
    Route::get('/ai/memory/provider-projection/audits/summary', [AtlasMemoryController::class, 'providerProjectionAuditSummary']);
    Route::post('/ai/memory/provider-projection/audits/purge', [AtlasMemoryController::class, 'providerProjectionAuditPurge']);
    Route::get('/ai/memory/provider-projection/audits', [AtlasMemoryController::class, 'providerProjectionAudits']);
    Route::post('/ai/memory/provider-projection/apply', [AtlasMemoryController::class, 'providerProjectionApply']);
    Route::get('/ai/memory/relations', [AtlasMemoryController::class, 'indexRelations']);
    Route::post('/ai/memory/relations/{relation}/review', [AtlasMemoryController::class, 'reviewRelation']);
    Route::get('/ai/memory/quality/history', [AtlasMemoryController::class, 'qualityHistory']);
    Route::post('/ai/memory/quality/snapshots', [AtlasMemoryController::class, 'qualitySnapshot']);
    Route::get('/ai/memory/quality', [AtlasMemoryController::class, 'quality']);
    Route::get('/ai/memory/verbatim', [AtlasMemoryController::class, 'indexVerbatim']);
    Route::post('/ai/memory/verbatim', [AtlasMemoryController::class, 'storeVerbatim']);
    Route::post('/ai/memory/verbatim/{verbatimMemory}/review', [AtlasMemoryController::class, 'reviewVerbatim']);
    Route::get('/ai/memory/verbatim/{verbatimMemory}', [AtlasMemoryController::class, 'showVerbatim']);
    Route::patch('/ai/memory/verbatim/{verbatimMemory}', [AtlasMemoryController::class, 'updateVerbatim']);
    Route::post('/ai/memory/usages/{usage}/feedback', [AtlasMemoryController::class, 'feedbackUsage']);
    Route::get('/ai/memory/{memoryEntry}', [AtlasMemoryController::class, 'show']);
    Route::post('/ai/memory/{memoryEntry}/privacy', [AtlasMemoryController::class, 'reviewPrivacy']);
    Route::get('/ai/memory/{memoryEntry}/governance', [AtlasMemoryController::class, 'governance']);
    Route::patch('/ai/memory/{memoryEntry}', [AtlasMemoryController::class, 'update']);
    Route::get('/ai/vault/status', [AtlasVaultController::class, 'status']);
    Route::post('/ai/vault/import', [AtlasVaultController::class, 'import']);
    Route::post('/ai/vault/export-semantic', [AtlasVaultController::class, 'exportSemantic']);
    Route::post('/ai/vault/sync', [AtlasVaultController::class, 'sync']);
    Route::get('/ai/vault/conflicts', [AtlasVaultController::class, 'conflicts']);
    Route::get('/ai/vault/conflicts/{item}', [AtlasVaultController::class, 'item']);
    Route::post('/ai/vault/conflicts/{item}/resolve', [AtlasVaultController::class, 'resolve']);
    Route::post('/ai/interactions', [AiInteractionController::class, 'store']);

    // YouTube canonical capability · paste-time prewarm + live status read.
    // Robustez: idempotente por video_id, Redis lock, cache reuse, audit.
    // Doc: atlas-server/docs/rich-input/youtube-canon.md
    Route::post('/ai/youtube/prewarm', [YoutubePrewarmController::class, 'prewarm'])
        ->middleware('throttle:60,1')
        ->name('ai.youtube.prewarm');
    Route::get('/ai/youtube/ingestion/{videoId}', [YoutubePrewarmController::class, 'status'])
        ->where('videoId', '[A-Za-z0-9_-]{11}')
        ->middleware('throttle:120,1')
        ->name('ai.youtube.status');

    Route::get('/ai/interactions/atlas-dev/readiness', ReadinessController::class)
        ->name('atlas-dev.readiness');
    Route::post('/ai/interactions/atlas-dev/plan', PlanController::class)
        ->name('atlas-dev.plan');
    Route::post('/ai/interactions/atlas-dev/run', RunController::class)
        ->name('atlas-dev.run');
    Route::get('/ai/interactions/atlas-dev/runs', AtlasDevIndexController::class)
        ->name('atlas-dev.runs.index');
    Route::post('/ai/interactions/atlas-dev/runs/{runId}/cancel', CancelController::class)
        ->where('runId', '[A-Za-z0-9._-]{1,128}')
        ->name('atlas-dev.runs.cancel');
    Route::get('/ai/interactions/atlas-dev/runs/{runId}/stream', StreamController::class)
        ->where('runId', '[A-Za-z0-9._-]{1,128}')
        ->name('atlas-dev.runs.stream');
    Route::get('/ai/interactions/atlas-dev/runs/{runId}', ShowController::class)
        ->where('runId', '[A-Za-z0-9._-]{1,128}')
        ->name('atlas-dev.runs.show');
    Route::get('/ai/interactions/{trace}/flow-status', [AiInteractionController::class, 'flowStatus']);
    Route::get('/ai/interactions/{trace}', [AiInteractionController::class, 'show']);
    Route::get('/ai/interactions/{trace}/attachments/{attachment}/content', [AiInteractionController::class, 'attachmentContent']);
    Route::get('/ai/interactions/{trace}/attachments/{attachment}/pages/{page}', [AiInteractionController::class, 'attachmentPage']);
    Route::get('/ai/interactions/{trace}/stream', [AiInteractionController::class, 'stream']);
    Route::post('/ai/interactions/{trace}/feedback', [AiInteractionController::class, 'feedback']);
    Route::post('/ai/attachments/search', AiAttachmentSearchController::class);
    Route::post('/ai/uploads/chunks/start', [AiChunkedUploadController::class, 'start']);
    Route::post('/ai/uploads/chunks/{upload}/chunk', [AiChunkedUploadController::class, 'chunk']);
    Route::post('/ai/uploads/chunks/{upload}/complete', [AiChunkedUploadController::class, 'complete']);
    Route::get('/ai/observability', AiObservabilityController::class);
    Route::post('/ai/telemetry/events', [AiTelemetryController::class, 'store']);
    Route::get('/ai/telemetry/scorecard', [AiTelemetryMetricsController::class, 'scorecard']);
    Route::get('/ai/telemetry/health', [AiTelemetryMetricsController::class, 'health']);
    Route::get('/ai/telemetry/summaries', [AiTelemetryMetricsController::class, 'summaries']);
    Route::get('/ai/telemetry/cost-rates/missing', [AiTelemetryMetricsController::class, 'missingCostRates']);
    Route::post('/ai/telemetry/cost-rates/import', [AiTelemetryMetricsController::class, 'importCostRates']);
    Route::get('/ai/telemetry/cost-rates', [AiTelemetryMetricsController::class, 'costRates']);
    Route::post('/ai/telemetry/cost-rates', [AiTelemetryMetricsController::class, 'upsertCostRate']);
    Route::get('/ai/telemetry/outcomes', [AiTelemetryMetricsController::class, 'outcomes']);
    Route::post('/ai/telemetry/outcomes', [AiTelemetryMetricsController::class, 'recordOutcome']);
    Route::get('/ai/quality/actions', [AiQualityActionController::class, 'index']);
    Route::post('/ai/quality/actions/{action}/run', [AiQualityActionController::class, 'run']);

    Route::get('/ai/threads', [AiThreadController::class, 'index']);
    Route::get('/ai/workspaces/{workspace}/conversation-fusion', [AiThreadController::class, 'workspaceConversationFusion']);
    Route::post('/ai/threads', [AiThreadController::class, 'store']);
    Route::get('/ai/threads/{thread}/state', [AiThreadController::class, 'state']);
    Route::get('/ai/threads/{thread}/messages', [AiThreadController::class, 'messages']);
    Route::post('/ai/threads/{thread}/compact', [AiThreadController::class, 'compact']);
    Route::post('/ai/threads/{thread}/switch-provider', [AiThreadController::class, 'switchProvider']);
    Route::get('/ai/threads/{thread}/snapshots', [AiThreadController::class, 'snapshots']);
    Route::get('/ai/threads/{thread}', [AiThreadController::class, 'show']);
    Route::patch('/ai/threads/{thread}', [AiThreadController::class, 'update']);
    Route::delete('/ai/threads/{thread}', [AiThreadController::class, 'destroy']);

    Route::get('/ai/jobs', [AiJobController::class, 'index']);
    Route::get('/ai/jobs/{job}', [AiJobController::class, 'show']);
    Route::post('/ai/jobs/{job}/retry', [AiJobController::class, 'retry']);
    Route::post('/ai/jobs/{job}/cancel', [AiJobController::class, 'cancel']);
    Route::post('/ai/jobs/{job}/resume-choice', [AiJobController::class, 'resumeChoice']);

    Route::get('/ai/providers/status', [AiProviderController::class, 'status']);
    Route::patch('/ai/providers/settings', [AiProviderController::class, 'updateSettings']);
    Route::post('/ai/providers/check', [AiProviderController::class, 'check']);

    Route::get('/audit/suggestions', AuditSuggestionController::class);
    Route::get('/audit/events', [AuditEventController::class, 'index']);

    Route::post('/sync', SyncController::class);

    // Atlas Vox V0 — Mac/Desktop local-first dictation surface (Onda 2).
    // Boundary: never executes, never persists audio, never calls a provider.
    // See docs/contracts/vox/ + docs/engineering-knowledge-base/adr/0003-*.
    Route::get('/ai/vox/health', [AtlasAiVoxController::class, 'health']);
    Route::post('/ai/vox/intent', [AtlasAiVoxController::class, 'intent']);
    Route::post('/ai/vox/execute', [AtlasAiVoxController::class, 'execute']);

    // Atlas Vox Wave 7 — read-only metrics + rivals + V3 promotion gate.
    // No execution, no provider, no audio. Gate only recommends; Vitor
    // approves V4 manually.
    Route::get('/ai/vox/metrics', [AtlasAiVoxMetricsController::class, 'metrics']);
    Route::post('/ai/vox/rivals/case', [AtlasAiVoxMetricsController::class, 'recordRivalsCase']);
    Route::get('/ai/vox/rivals/report', [AtlasAiVoxMetricsController::class, 'rivalsReport']);
    Route::get('/ai/vox/gate-v3', [AtlasAiVoxMetricsController::class, 'gateV3']);
    // Wave 7.6 (Claude R) · V3 Certification Pack + human review.
    // The pack snapshot is a deterministic, hash-verifiable read; review
    // never flips a feature flag (V4 unlock is a separate future wave).
    Route::get('/ai/vox/gate-v3/certification-pack', [AtlasAiVoxMetricsController::class, 'certificationPack']);
    Route::post('/ai/vox/gate-v3/review', [AtlasAiVoxMetricsController::class, 'recordPromotionReview']);

    // Wave 7.6 (Claude T) · V3 hardening audit. Read-only. Independently
    // re-verifies every V3 safety invariant before any V4 work begins.
    // Sits next to — not on top of — the certification pack: the pack
    // declares invariants; the audit *measures* them.
    Route::get('/ai/vox/audit/v3-hardening', [AtlasAiVoxMetricsController::class, 'v3HardeningAudit']);
    // Wave 7.8 (Claude V) · single readiness probe. Aggregates 16 checks
    // (modes, metrics, gate, audit, safety, provider CLIs) into a
    // status: ready|partial|blocked + capabilities + next_actions. Never
    // executes a CLI; provider absence becomes a warning, not a block.
    Route::get('/ai/vox/readiness', [AtlasAiVoxReadinessController::class, 'readiness']);

    // Wave 7.9 (Claude Z) · Dogfood Session Evidence. Distinct from rivals:
    // dogfood é o diário de uso real ("usei Vox hoje, foi assim"), rivals é
    // comparação head-to-head. Endpoint NUNCA executa, nunca chama provider,
    // nunca toca audio. Reporta isolado do gate-v3 (informational only).
    Route::post('/ai/vox/dogfood/session', [AtlasAiVoxDogfoodController::class, 'recordSession']);
    Route::get('/ai/vox/dogfood/report', [AtlasAiVoxDogfoodController::class, 'report']);
    // V6-E · feedback leve (chip "Funcionou bem" / "Marcar como ruim"). Não
    // muda outcome, só vira regret_flag para alimentar o report.
    Route::post(
        '/ai/vox/dogfood/session/{dogfood_session_id}/feedback',
        [AtlasAiVoxDogfoodController::class, 'submitFeedback'],
    )->where('dogfood_session_id', '[A-Za-z0-9_\\-]+');
});

/*
 * Atlas Truth Cartography — read-only HTTP surface for the live cartography UI.
 * The cartography never writes; these endpoints are GET-only by design.
 */
Route::prefix('atlas-cartography')->group(function () {
    Route::get('/graph', [AtlasCartographyController::class, 'graph']);
    Route::get('/human-clarity', [AtlasCartographyController::class, 'humanClarity']);
    Route::get('/note/{graph_id}', [AtlasCartographyController::class, 'note'])->where('graph_id', '.*');
    Route::get('/recent-changes', [AtlasCartographyController::class, 'recentChanges']);
    // SSE · live-doc stream. Heartbeat every 15s + emit `graph_changed` when
    // the assembler's checksum moves. Connections close after 25s so the
    // `php artisan serve` single-thread worker recycles; client reconnects.
    Route::get('/stream', [AtlasCartographyController::class, 'stream']);
});

/*
 * Atlas Code · MVP endpoints consumed by the atlas-desktop bridge.
 *
 * The desktop never decides; it commands, shows, signs, observes.
 * These endpoints add the 3 NEW slots identified in the audit. The other 9
 * needs reuse pre-existing routes (/api/projects, /api/ai/threads, etc.).
 *
 * See: atlas-desktop/docs/architecture/0001-atlas-desktop-boundaries.md
 */
Route::prefix('atlas-code')->group(function () {
    // BOOT · unified contract for the Desktop topbar
    Route::get('/boot', AtlasCodeBootController::class);

    // MCP · pill status
    Route::get('/mcp/status', AtlasCodeMcpStatusController::class);

    // PROJECT / WORKSPACE · multi-project read-model
    // canon: docs/engineering-knowledge-base/atlas-code-multi-project-workspace-os.md
    Route::get('/projects/workspaces', [AtlasCodeWorkspaceController::class, 'index']);
    Route::post('/projects/workspaces', [AtlasCodeWorkspaceController::class, 'store']);
    Route::get('/projects/workspaces/{slug}', [AtlasCodeWorkspaceController::class, 'show']);
    Route::patch('/projects/workspaces/{slug}', [AtlasCodeWorkspaceController::class, 'update']);
    Route::delete('/projects/workspaces/{slug}', [AtlasCodeWorkspaceController::class, 'destroy']);
    Route::get('/frontend/portfolio', [AtlasFrontendWorkspaceController::class, 'portfolio']);
    Route::post('/frontend/selected-workspace', [AtlasFrontendWorkspaceController::class, 'selected']);
    Route::post('/frontend/selection-receipt', [AtlasFrontendWorkspaceController::class, 'selectionReceipt']);
    Route::post('/frontend/project-activation', [AtlasFrontendWorkspaceController::class, 'activateProjectWorkspace']);
    Route::post('/frontend/runtime-projection', [AtlasFrontendWorkspaceController::class, 'runtimeProjection']);
    Route::post('/frontend/control-plane', [AtlasFrontendWorkspaceController::class, 'controlPlane']);
    Route::post('/frontend/competitive-benchmark-plan', [AtlasFrontendWorkspaceController::class, 'competitiveBenchmarkPlan']);
    Route::post('/frontend/live-source-patch', [AtlasFrontendWorkspaceController::class, 'liveSourcePatch']);
    Route::post('/frontend/live-visual-selection', [AtlasFrontendWorkspaceController::class, 'liveVisualSelection']);
    Route::post('/frontend/live-target-suggestions', [AtlasFrontendWorkspaceController::class, 'liveTargetSuggestions']);
    Route::post('/frontend/prepare-evidence', [AtlasFrontendWorkspaceController::class, 'prepareEvidence']);
    Route::post('/frontend/prepare-rival-replay', [AtlasFrontendWorkspaceController::class, 'prepareRivalReplay']);
    Route::post('/frontend/inspect-rival-replay', [AtlasFrontendWorkspaceController::class, 'inspectRivalReplay']);
    Route::post('/frontend/replay-external-receipt-template', [AtlasFrontendWorkspaceController::class, 'replayExternalReceiptTemplate']);
    Route::post('/frontend/replay-score-template', [AtlasFrontendWorkspaceController::class, 'replayScoreTemplate']);
    Route::post('/frontend/replay-apply-patch', [AtlasFrontendWorkspaceController::class, 'applyReplayPatch']);
    Route::post('/frontend/proof-bundle', [AtlasFrontendWorkspaceController::class, 'proofBundle']);
    Route::post('/frontend/publication-receipt-template', [AtlasFrontendWorkspaceController::class, 'publicationReceiptTemplate']);
    Route::post('/frontend/publication-verify', [AtlasFrontendWorkspaceController::class, 'verifyPublication']);
    Route::post('/frontend/run-certification', [AtlasFrontendWorkspaceController::class, 'runCertification']);
    Route::post('/frontend/handoff', [AtlasFrontendWorkspaceController::class, 'handoff']);
    Route::get('/workspace-intelligence', [AtlasWorkspaceIntelligenceController::class, 'show']);
    Route::get('/workspace-intelligence/twin', [AtlasWorkspaceIntelligenceController::class, 'twin']);
    Route::get('/workspace-intelligence/artifacts', [AtlasWorkspaceIntelligenceController::class, 'artifacts']);
    Route::get('/workspace-intelligence/contracts', [AtlasWorkspaceIntelligenceController::class, 'contracts']);
    Route::get('/workspace-intelligence/evolution', [AtlasWorkspaceIntelligenceController::class, 'evolution']);
    Route::get('/workspace-intelligence/learning-loop', [AtlasWorkspaceIntelligenceController::class, 'learningLoop']);
    Route::get('/workspace-intelligence/live-execution-memory', [AtlasWorkspaceIntelligenceController::class, 'liveExecutionMemory']);
    Route::get('/workspace-intelligence/next-session-brain', [AtlasWorkspaceIntelligenceController::class, 'nextSessionBrain']);
    Route::get('/workspace-intelligence/artifact-intelligence', [AtlasWorkspaceIntelligenceController::class, 'artifactIntelligence']);
    Route::get('/workspace-intelligence/artifact-lake', [AtlasWorkspaceIntelligenceController::class, 'artifactLake']);
    Route::get('/workspace-intelligence/artifact-lake/{artifact}', [AtlasWorkspaceIntelligenceController::class, 'artifactLakeShow']);
    Route::get('/workspace-intelligence/artifact-workroom', [AtlasWorkspaceIntelligenceController::class, 'artifactWorkroom']);
    Route::get('/workspace-intelligence/artifact-timeline', [AtlasWorkspaceIntelligenceController::class, 'artifactTimeline']);
    Route::post('/workspace-intelligence/artifact-outcome', [AtlasWorkspaceIntelligenceController::class, 'artifactOutcome']);
    Route::post('/workspace-intelligence/artifact-retirement', [AtlasWorkspaceIntelligenceController::class, 'artifactRetirement']);
    Route::get('/workspace-intelligence/artifact-retirement-queue', [AtlasWorkspaceIntelligenceController::class, 'artifactRetirementQueue']);
    Route::post('/workspace-intelligence/artifact-retirement-apply', [AtlasWorkspaceIntelligenceController::class, 'artifactRetirementApply']);
    Route::get('/workspace-intelligence/handoff-pack', [AtlasWorkspaceIntelligenceController::class, 'handoffPack']);
    Route::get('/workspace-intelligence/gate', [AtlasWorkspaceIntelligenceController::class, 'gate']);

    // PROVIDER GOVERNANCE · subscription-only contract
    // canon: docs/engineering-knowledge-base/atlas-claude-code-subscription-governance-v1.md
    Route::get('/providers/governance', [AtlasCodeProviderGovernanceController::class, 'show']);

    // ATTENTION CONTROL PLANE · routes the next human decision across Obras
    // canon: docs/engineering-knowledge-base/atlas-code-attention-control-plane-v1.md
    Route::get('/attention', [AtlasCodeAttentionControlPlaneController::class, 'index']);
    Route::post('/attention/{project}/decision', [AtlasCodeAttentionControlPlaneController::class, 'decide']);

    // DEV → FORGE PROMOTION · bridges Atlas Dev threads to Obras/Forge
    // canon: docs/engineering-knowledge-base/atlas-ai-conversation-surface-and-atlas-dev-v1.md
    Route::get('/dev-to-forge/threads/{thread}/promotion-preview', [AtlasCodeDevToForgePromotionController::class, 'preview']);
    Route::post('/dev-to-forge/threads/{thread}/promote', [AtlasCodeDevToForgePromotionController::class, 'promote']);
    Route::get('/dev-to-forge/candidates', [AtlasCodeDevToForgePromotionController::class, 'index']);
    Route::get('/dev-to-forge/candidates/{candidate}', [AtlasCodeDevToForgePromotionController::class, 'show']);
    Route::post('/dev-to-forge/candidates/{candidate}/dismiss', [AtlasCodeDevToForgePromotionController::class, 'dismiss']);

    // ATTENTION CONTROL PLANE · serializes human decisions across Obras
    // canon: docs/engineering-knowledge-base/atlas-code-attention-control-plane-v1.md
    Route::get('/attention', [AtlasCodeAttentionControlPlaneController::class, 'index']);
    Route::post('/attention/{project}/decision', [AtlasCodeAttentionControlPlaneController::class, 'decide']);

    // Meta 8.5 · Canonical Dev-to-Forge Promotion routes live at
    // /atlas-code/dev-to-forge/* above. The earlier `/atlas-code/promotion/*`
    // mount was removed during reconciliation — single schema, single
    // controller, single signal detector.

    // PROVIDER OPERATING ROOM (per-Obra read-model)
    // canon: docs/engineering-knowledge-base/atlas-code-adaptive-provider-operating-room-v1.md
    Route::get('/works/{project}/forge/operating-room', [AtlasCodeProviderOperatingRoomController::class, 'show']);

    // WORK PACKETS (per-Obra)
    // canon: docs/engineering-knowledge-base/atlas-code-interactive-observed-provider-workflow-v1.md
    Route::get('/works/{project}/work-packets', [AtlasCodeWorkPacketController::class, 'index']);
    Route::post('/works/{project}/work-packets', [AtlasCodeWorkPacketController::class, 'store']);
    Route::get('/works/{project}/work-packets/{packet}', [AtlasCodeWorkPacketController::class, 'show']);
    Route::post('/works/{project}/work-packets/{packet}/export', [AtlasCodeWorkPacketController::class, 'exportPreview']);

    // OBSERVED SESSIONS (Interactive Observed Provider Workflow)
    Route::get('/works/{project}/observed-sessions', [AtlasCodeObservedSessionController::class, 'index']);
    Route::post('/works/{project}/observed-sessions', [AtlasCodeObservedSessionController::class, 'store']);
    // One-shot "Abrir Claude Code observado" — creates packet + opens session.
    Route::post('/works/{project}/observed-sessions/claude-code', [AtlasCodeObservedSessionController::class, 'claudeCodeOneShot']);
    Route::get('/works/{project}/observed-sessions/{session}', [AtlasCodeObservedSessionController::class, 'show']);
    Route::post('/works/{project}/observed-sessions/{session}/state', [AtlasCodeObservedSessionController::class, 'state']);
    Route::post('/works/{project}/observed-sessions/{session}/mark-running', [AtlasCodeObservedSessionController::class, 'markRunning']);
    Route::post('/works/{project}/observed-sessions/{session}/import', [AtlasCodeObservedSessionController::class, 'import']);
    Route::post('/works/{project}/observed-sessions/{session}/import-result', [AtlasCodeObservedSessionController::class, 'import']);
    Route::post('/works/{project}/observed-sessions/{session}/run-gates', [AtlasCodeObservedSessionController::class, 'runGates']);
    Route::get('/works/{project}/observed-sessions/{session}/verification-runs', [AtlasCodeObservedSessionController::class, 'verificationRunIndex']);
    Route::post('/works/{project}/observed-sessions/{session}/verification-runs', [AtlasCodeObservedSessionController::class, 'verificationRun']);
    Route::post('/works/{project}/observed-sessions/{session}/decide', [AtlasCodeObservedSessionController::class, 'decide']);

    // WORKS · normalized Obra surface
    Route::get('/works', [AtlasCodeWorkController::class, 'index']);
    Route::post('/works', [AtlasCodeWorkController::class, 'store']);
    // Gap3 F2 — Engineering Company Runtime HTTP entry.
    // Flag-gated via ATLAS_HTTP_COMPANY_RUNTIME / config(atlas.http_company_runtime.mode).
    // Canon: docs/engineering-knowledge-base/atlas-engineering-company-runtime-http-promotion.md
    Route::post('/work/company', [\App\Http\Controllers\AtlasCodeWorkCompanyController::class, 'store']);
    Route::get('/certification', [AtlasCodeEnterpriseCertificationController::class, 'show']);
    Route::post('/certification', [AtlasCodeEnterpriseCertificationController::class, 'store']);
    Route::get('/works/{project}', [AtlasCodeWorkController::class, 'show']);
    Route::get('/works/{project}/state', [AtlasCodeWorkController::class, 'state']);
    Route::post('/works/{project}/forge/live-executions', [AtlasCodeForgeExecutionController::class, 'store']);
    Route::post('/works/{project}/forge/live-executions/async', [AtlasCodeForgeExecutionController::class, 'startAsync']);
    Route::get('/works/{project}/forge/live-executions/history/{historyId}', [AtlasCodeForgeExecutionController::class, 'showHistory']);
    Route::get('/works/{project}/forge/live-executions/{executionId}', [AtlasCodeForgeExecutionController::class, 'showAsync']);
    Route::post('/works/{project}/forge/reviews', [AtlasCodeForgeReviewController::class, 'store']);
    Route::post('/works/{project}/forge/promotions/{promotionId}/rollback', [AtlasCodeForgeReviewController::class, 'rollback']);
    Route::post('/works/{project}/forge/fast-path', [AtlasCodeForgeFastPathController::class, 'store']);
    Route::get('/works/{project}/forge/fast-path/{run}/status', [AtlasCodeForgeFastPathStatusController::class, 'show']);
    Route::post('/works/{project}/forge/fast-path/{run}/resume', [AtlasCodeForgeFastPathStatusController::class, 'resume']);
    Route::get('/works/{project}/forge/fast-path/{run}/review', [AtlasCodeForgeReviewCompletionController::class, 'show']);
    Route::post('/works/{project}/forge/fast-path/{run}/review/approve', [AtlasCodeForgeReviewCompletionController::class, 'approve']);
    Route::post('/works/{project}/forge/fast-path/{run}/review/reject', [AtlasCodeForgeReviewCompletionController::class, 'reject']);
    Route::post('/works/{project}/forge/fast-path/{run}/review/rollback', [AtlasCodeForgeReviewCompletionController::class, 'rollback']);
    Route::get('/works/{project}/forge/intake', [AtlasCodeForgeWorkIntakeController::class, 'show']);
    Route::post('/works/{project}/forge/intake', [AtlasCodeForgeWorkIntakeController::class, 'store']);
    Route::get('/works/{project}/forge/provider-topology', [AtlasCodeForgeProviderTopologyController::class, 'show']);
    Route::get('/works/{project}/forge/continuum-certification', [AtlasCodeForgeProviderTopologyController::class, 'certification']);
    Route::get('/works/{project}/forge/runtime-dispatch', [AtlasCodeForgeRuntimeDispatchController::class, 'show']);
    Route::post('/works/{project}/forge/runtime-dispatch', [AtlasCodeForgeRuntimeDispatchController::class, 'store']);
    Route::get('/works/{project}/forge/ux-orchestrator', [AtlasCodeForgeUxOrchestratorController::class, 'show']);
    Route::get('/works/{project}/obra-command-center', [AtlasCodeObraCommandCenterController::class, 'show']);
    Route::get('/works/{project}/forge/provider-invocations/latest', [AtlasCodeForgeProviderInvocationController::class, 'latest']);
    Route::get('/works/{project}/forge/provider-invocations/drivers', [AtlasCodeForgeProviderInvocationController::class, 'drivers']);
    Route::post('/works/{project}/forge/provider-invocations/plan-driver', [AtlasCodeForgeProviderInvocationController::class, 'planDriver']);
    Route::post('/works/{project}/forge/provider-invocations', [AtlasCodeForgeProviderInvocationController::class, 'store']);
    Route::get('/forge/provider-capacity', [AtlasCodeForgeProviderCapacityController::class, 'global']);
    Route::get('/forge/provider-arena/snapshot', [AtlasCodeProviderArenaController::class, 'show']);
    Route::post('/forge/provider-arena/run', [AtlasCodeProviderArenaController::class, 'run']);
    Route::get('/works/{project}/forge/provider-capacity', [AtlasCodeForgeProviderCapacityController::class, 'show']);
    Route::post('/works/{project}/forge/provider-failures', [AtlasCodeForgeProviderCapacityController::class, 'recordFailure']);
    Route::get('/self-improvement/strategy-portfolio', [AtlasCodeSelfImprovementGovernanceController::class, 'strategyPortfolio']);
    Route::post('/self-improvement/proposal-gate', [AtlasCodeSelfImprovementGovernanceController::class, 'proposalGate']);
    Route::post('/self-improvement/before-after', [AtlasCodeSelfImprovementGovernanceController::class, 'beforeAfter']);
    Route::post('/self-improvement/invariant-lock', [AtlasCodeSelfImprovementGovernanceController::class, 'invariantLock']);
    Route::post('/self-improvement/regression-sentinel', [AtlasCodeSelfImprovementGovernanceController::class, 'regressionSentinel']);
    Route::post('/self-improvement/maturity-score', [AtlasCodeSelfImprovementGovernanceController::class, 'maturityScore']);
    Route::get('/works/{project}/self-improvement/trust-ledger', [AtlasCodeSelfImprovementGovernanceController::class, 'trustLedgerShow']);
    Route::post('/works/{project}/self-improvement/trust-ledger', [AtlasCodeSelfImprovementGovernanceController::class, 'trustLedgerRecord']);
    Route::get('/self-improvement/forge-activations', [AtlasCodeSelfImprovementForgeActivationController::class, 'index']);
    Route::post('/self-improvement/forge-activations', [AtlasCodeSelfImprovementForgeActivationController::class, 'store']);
    Route::get('/self-improvement/forge-activations/{activation}', [AtlasCodeSelfImprovementForgeActivationController::class, 'show']);
    Route::post('/self-improvement/forge-activations/{activation}/accept', [AtlasCodeSelfImprovementForgeActivationController::class, 'accept']);
    Route::post('/self-improvement/forge-activations/{activation}/reject', [AtlasCodeSelfImprovementForgeActivationController::class, 'reject']);
    Route::get('/self-improvement/activation-cockpit', [AtlasCodeSelfImprovementActivationCockpitController::class, 'index']);
    Route::get('/self-improvement/activation-cockpit/{activation}', [AtlasCodeSelfImprovementActivationCockpitController::class, 'show']);
    Route::get('/self-improvement/proposals', [AtlasCodeSelfImprovementProposalBacklogController::class, 'index']);
    Route::post('/self-improvement/proposals', [AtlasCodeSelfImprovementProposalBacklogController::class, 'store']);
    Route::get('/self-improvement/proposals/{proposal}', [AtlasCodeSelfImprovementProposalBacklogController::class, 'show']);
    Route::post('/self-improvement/proposals/{proposal}/evaluate', [AtlasCodeSelfImprovementProposalBacklogController::class, 'evaluate']);
    Route::post('/self-improvement/proposals/{proposal}/prioritize', [AtlasCodeSelfImprovementProposalBacklogController::class, 'prioritize']);
    Route::get('/self-improvement/proposals/{proposal}/closed-loop', [AtlasCodeSelfImprovementClosedLoopController::class, 'show']);
    Route::post('/self-improvement/proposals/{proposal}/measure-result', [AtlasCodeSelfImprovementResultLedgerController::class, 'measureResult']);
    Route::get('/self-improvement/result-ledger', [AtlasCodeSelfImprovementResultLedgerController::class, 'index']);
    Route::get('/self-improvement/next-cycle-recommendations', [AtlasCodeSelfImprovementNextCycleController::class, 'index']);
    Route::post('/works/{project}/checkpoints', [AtlasCodeCheckpointController::class, 'store']);
    Route::post('/works/{project}/programming/work-items', [AtlasCodeProgrammingWorkItemController::class, 'store']);
    Route::post('/works/{project}/programming/work-items/{workItem}/spec', [AtlasCodeProgrammingWorkItemController::class, 'compileSpecPlan']);

    // 4 · sessions for an obra (project) · NEW
    Route::get('/works/{project}/sessions', [AtlasCodeSessionController::class, 'indexForWork']);

    // 10 · evidence aggregator per obra · NEW (wraps tools/evidence + engineering/runs)
    Route::get('/works/{project}/evidence', [AtlasCodeEvidenceController::class, 'indexForWork']);

    // THREAD · normalized thread+messages contract
    Route::get('/threads/{thread}', [AtlasCodeThreadController::class, 'show']);

    // RECEIPT · Receipt v2 direct
    Route::get('/decisions/{decision}/receipt', [AtlasCodeReceiptShowController::class, 'show']);

    // 9 · sign decision receipt · NEW
    Route::post('/decisions/{decision}/sign', [AtlasCodeReceiptController::class, 'sign']);

    // 12 · apply diff · NEW
    Route::post('/diffs/{patch}/apply', [AtlasCodeDiffController::class, 'apply']);

    // PROGRAMMING GOVERNANCE · WorkItem timeline as live objects (SCOR-1 cockpit feed)
    Route::middleware('atlas.token')->group(function (): void {
        Route::get('/programming/work-items', [AtlasProgrammingGovernanceController::class, 'index']);
        Route::get('/programming/work-items/{code}', [AtlasProgrammingGovernanceController::class, 'show']);
        Route::get('/programming/work-items/{code}/gate-runs', [AtlasProgrammingGovernanceController::class, 'gateRuns']);
        Route::get('/programming/work-items/{code}/spec-compile', [AtlasProgrammingGovernanceController::class, 'compileSpec']);

        // SDD · read-only surface for specs, requirements, decision receipts, traceability, drift, learning
        Route::get('/sdd/operations', [AtlasSddController::class, 'operations']);
        Route::get('/sdd/specs', [AtlasSddController::class, 'specs']);
        Route::get('/sdd/specs/{id}', [AtlasSddController::class, 'showSpec']);
        Route::get('/sdd/specs/{id}/traceability', [AtlasSddController::class, 'traceability']);
        Route::get('/sdd/decision-receipts', [AtlasSddController::class, 'decisionReceipts']);
        Route::get('/sdd/decision-receipts/{receiptId}', [AtlasSddController::class, 'showDecisionReceipt']);
        Route::get('/sdd/drift-reports', [AtlasSddController::class, 'driftReports']);
        Route::get('/sdd/learning-proposals', [AtlasSddController::class, 'learningProposals']);
        Route::get('/sdd/mcp/resources', AtlasSddMcpResourceController::class);
        Route::get('/sdd/agent-roles', [AtlasSddAgentRoleController::class, 'index']);
        Route::get('/sdd/agent-roles/{name}', [AtlasSddAgentRoleController::class, 'show']);
    });
});

// Atlas AI Control Plane (Meta 9) · read-only aggregate snapshots / readiness / blockers
Route::prefix('atlas/ai/control-plane')->group(function (): void {
    Route::get('/', [AtlasAiControlPlaneController::class, 'index']);
    Route::get('/readiness', [AtlasAiControlPlaneController::class, 'readiness']);
    Route::get('/blockers', [AtlasAiControlPlaneController::class, 'blockers']);
    Route::get('/next-actions', [AtlasAiControlPlaneController::class, 'nextActions']);
    Route::get('/missions/{uuid}', [AtlasAiControlPlaneController::class, 'mission']);
});

// Atlas AI Runtime Readiness & Release Gate · single-call aggregator
Route::get('/atlas/ai/runtime-readiness', AtlasAiRuntimeReadinessController::class);

// Atlas Patamar 4 · live aggregator (Kernel · Admission · CFA · Reconciliation · TEOS-I4 · Swarm · TDC)
Route::get('/atlas/patamar4/state', App\Http\Controllers\AtlasPatamar4StateController::class);

// Atlas Patamar 4 Surface Facade — 7 endpoints canon consumidos pela UI mobile+desktop (F3).
Route::get('/atlas/patamar4/scheduler', [App\Http\Controllers\AtlasPatamar4SurfaceController::class, 'scheduler']);
Route::get('/atlas/patamar4/swarm', [App\Http\Controllers\AtlasPatamar4SurfaceController::class, 'swarm']);
Route::get('/atlas/patamar4/rebalance', [App\Http\Controllers\AtlasPatamar4SurfaceController::class, 'rebalance']);
Route::get('/atlas/patamar4/cognitive-function', [App\Http\Controllers\AtlasPatamar4SurfaceController::class, 'cognitiveFunction']);
Route::get('/atlas/patamar4/governance', [App\Http\Controllers\AtlasPatamar4SurfaceController::class, 'governance']);
Route::post('/atlas/patamar4/decompose', [App\Http\Controllers\AtlasPatamar4SurfaceController::class, 'decompose']);
Route::get('/atlas/patamar4/inbox/madrugada', [App\Http\Controllers\AtlasPatamar4SurfaceController::class, 'madrugadaInbox']);
