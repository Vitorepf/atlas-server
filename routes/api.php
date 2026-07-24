<?php

use App\Http\Controllers\Ai\AiInteractionSteerController;
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
use App\Http\Controllers\AtlasAiDecisionReceiptReportController;
use App\Http\Controllers\AtlasAiDomainCatalogController;
use App\Http\Controllers\AtlasAiDynamicComputeMarketController;
use App\Http\Controllers\AtlasAiExternalGraphHarnessController;
use App\Http\Controllers\AtlasAiGovernanceController;
use App\Http\Controllers\AtlasAiHyperflowCertificationController;
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
use App\Http\Controllers\AtlasBlogEditorialController;
use App\Http\Controllers\AtlasDev\CancelController;
use App\Http\Controllers\AtlasDev\IndexController as AtlasDevIndexController;
use App\Http\Controllers\AtlasDev\PlanController;
use App\Http\Controllers\AtlasDev\ReadinessController;
use App\Http\Controllers\AtlasDev\RunController;
use App\Http\Controllers\AtlasDev\ShowController;
use App\Http\Controllers\AtlasDev\StreamController;
use App\Http\Controllers\AtlasDomainController;
use App\Http\Controllers\AtlasMemoryController;
use App\Http\Controllers\AtlasMobilePushReplayController;
use App\Http\Controllers\AtlasTaskController;
use App\Http\Controllers\AuditEventController;
use App\Http\Controllers\AuditSuggestionController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\InboxController;
use App\Http\Controllers\RizeWebhookController;
use App\Http\Controllers\SyncController;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class);
Route::post('/integrations/rize/webhook', RizeWebhookController::class);

// Voice Realtime shared registrar (desktop token + mobile bearer).
$registerAtlasVoiceRoutes = require __DIR__.'/api/atlas-voice.php';

// Mobile Gateway · /v1/mobile (pairing, devices, inbox, mac agent, voice via registrar).
(require __DIR__.'/api/mobile.php')($registerAtlasVoiceRoutes);

Route::middleware('atlas.token')->group(function () use ($registerAtlasVoiceRoutes): void {
    Route::apiResource('domains', AtlasDomainController::class)->only(['index', 'store', 'update', 'destroy']);

    (require __DIR__.'/api/arena.php')();
    (require __DIR__.'/api/code-native.php')();
    (require __DIR__.'/api/agent-governance.php')();
    Route::get('/inbox', [InboxController::class, 'index']);
    Route::get('/inbox/health', [InboxController::class, 'health']);
    Route::post('/inbox/bulk', [InboxController::class, 'bulk']);
    Route::get('/tasks/agenda', [AtlasTaskController::class, 'agenda']);
    Route::post('/tasks/agenda/plan', [AtlasTaskController::class, 'planAgenda']);
    Route::get('/tasks/agenda/week', [AtlasTaskController::class, 'weekAgenda']);
    Route::post('/tasks/agenda/week/plan', [AtlasTaskController::class, 'planWeekAgenda']);
    Route::get('/tasks/{task}/events', [AtlasTaskController::class, 'events']);
    Route::get('/tasks/{task}/memory', [AtlasMemoryController::class, 'forTask']);
    (require __DIR__.'/api/engineering.php')();
    Route::get('/blog/editorial/state', [AtlasBlogEditorialController::class, 'state']);
    (require __DIR__.'/api/projects-routines.php')();
    (require __DIR__.'/api/semantic-and-lifestyle.php')();
    Route::get('/ai/interactions', [AiInteractionController::class, 'index']);
    Route::get('/ai/sessions/live', [\App\Http\Controllers\Ai\AtlasLiveActivityController::class, 'liveSessions']);
    Route::post('/ai/live-activities', [\App\Http\Controllers\Ai\AtlasLiveActivityController::class, 'store']);
    Route::post('/ai/live-activities/start-tokens', [\App\Http\Controllers\Ai\AtlasLiveActivityController::class, 'storeStartToken']);
    Route::post('/ai/live-activities/{activityId}/invalidate', [\App\Http\Controllers\Ai\AtlasLiveActivityController::class, 'invalidate']);
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
    Route::get('/ai/kernel-pipeline/report', AtlasAiKernelPipelineReportController::class)
        ->middleware('throttle:60,1');
    Route::post('/ai/pipeline', AtlasAiPipelineController::class);
    Route::get('/ai/provider-performance', AtlasAiProviderPerformanceController::class);
    Route::get('/ai/provider-release-sources', AtlasAiProviderReleaseSourcesController::class);
    Route::get('/ai/provider-release-review', AtlasAiProviderReleaseReviewController::class);
    Route::get('/ai/qualitative-levels', AtlasAiQualitativeLevelsController::class);
    Route::post('/ai/repair', AtlasAiRepairController::class);
    Route::get('/ai/repair/report', AtlasAiRepairReportController::class);
    Route::get('/ai/self-improvement/schedule', AtlasAiSelfImprovementScheduleController::class);
    Route::get('/ai/self-improvement/schedule/health', AtlasAiSelfImprovementScheduleHealthController::class);
    Route::get('/ai/self-improvement/schedule/report', AtlasAiSelfImprovementScheduleReportController::class);
    Route::get('/ai/slo', AtlasAiSloController::class);
    Route::post('/ai/strategic-decision/review', AtlasAiStrategicDecisionController::class);
    $registerAtlasVoiceRoutes();
    (require __DIR__.'/api/memory-vault.php')();
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
    Route::get('/ai/interactions/{trace}/change-review', [\App\Http\Controllers\Ai\AiTraceChangeReviewController::class, 'show']);
    Route::post('/ai/interactions/{trace}/change-review/action', [\App\Http\Controllers\Ai\AiTraceChangeReviewController::class, 'action']);
    Route::post('/ai/interactions/{trace}/change-review/file-action', [\App\Http\Controllers\Ai\AiTraceChangeReviewController::class, 'fileAction']);
    Route::get('/ai/interactions/{trace}/change-review/patches/{patch}/diff', [\App\Http\Controllers\Ai\AiTraceChangeReviewController::class, 'diff']);
    Route::get('/ai/interactions/{trace}/artifacts', [\App\Http\Controllers\Ai\AiTraceArtifactsController::class, 'show']);
    Route::get('/ai/interactions/{trace}/artifacts/{artifactId}/content', [\App\Http\Controllers\Ai\AiTraceArtifactsController::class, 'content']);
    Route::get('/ai/interactions/{trace}/flow-status', [AiInteractionController::class, 'flowStatus']);
    Route::post('/ai/interactions/{trace}/steer', AiInteractionSteerController::class);
    Route::get('/ai/interactions/{trace}', [AiInteractionController::class, 'show']);
    Route::get('/ai/interactions/{trace}/attachments/{attachment}/content', [AiInteractionController::class, 'attachmentContent']);
    Route::get('/ai/interactions/{trace}/attachments/{attachment}/pages/{page}', [AiInteractionController::class, 'attachmentPage']);
    Route::get('/ai/interactions/{trace}/stream', [AiInteractionController::class, 'stream']);
    Route::post('/ai/interactions/{trace}/feedback', [AiInteractionController::class, 'feedback']);

    // G7 — ponte síncrona texto→resultado (mesmo pipeline create + worker, inline).
    Route::post('/ai/interactions/sync', [\App\Http\Controllers\AtlasChatSyncController::class, 'store']);

    // AP-819 — painel do Self-Harness para o app (transparência do autopilot).
    Route::get('/ai/harness', [\App\Http\Controllers\AtlasHarnessStatusController::class, 'show']);

    // G3 — mission request→exec→cert via HTTP: enfileira a cadeia completa do
    // AtlasMissionService (certificação + branch, nunca main) e expõe polling.
    Route::post('/ai/missions', [\App\Http\Controllers\AtlasMissionDeliveryController::class, 'store'])
        ->middleware('throttle:60,1');
    Route::get('/ai/missions', [\App\Http\Controllers\AtlasMissionDeliveryController::class, 'index'])
        ->middleware('throttle:60,1');
    Route::get('/ai/missions/{delivery}', [\App\Http\Controllers\AtlasMissionDeliveryController::class, 'show'])
        ->middleware('throttle:60,1');
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
    Route::post('/ai/threads/{thread}/handoff-surface', [AiThreadController::class, 'handoffSurface']);
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

    // Atlas Vox surface (inside atlas.token group).
    (require __DIR__.'/api/atlas-vox.php')();
});

/*
 * Atlas Truth Cartography — read-only HTTP surface for the live cartography UI.
 * The cartography never writes; these endpoints are GET-only by design.
 */
(require __DIR__.'/api/atlas-cartography.php')();


/*
 * Atlas Code · MVP endpoints consumed by the atlas-desktop bridge.
 *
 * The desktop never decides; it commands, shows, signs, observes.
 * These endpoints add the 3 NEW slots identified in the audit. The other 9
 * needs reuse pre-existing routes (/api/projects, /api/ai/threads, etc.).
 *
 * See: atlas-desktop/docs/architecture/0001-atlas-desktop-boundaries.md
 */
    (require __DIR__.'/api/atlas-code.php')();


// Atlas AI Control Plane (Meta 9) · read-only aggregate snapshots / readiness / blockers
(require __DIR__.'/api/control-plane.php')();


// Atlas AI Runtime Readiness & Release Gate · single-call aggregator
Route::get('/atlas/ai/runtime-readiness', AtlasAiRuntimeReadinessController::class);

// Atlas Software Company Stewardship Stack · Area Focus Product Mode read surface (AP-721, AP-712).
// Read-only Desktop-ready read model. No mutation, no execution, no merge/deploy/secrets.
// canon: docs/engineering-knowledge-base/atlas-autonomous-software-company-night-shift-product-mode.md
(require __DIR__.'/api/software-company-stewardship.php')();


// Atlas Patamar 4 · live aggregator (Kernel · Admission · CFA · Reconciliation · TEOS-I4 · Swarm · TDC)
(require __DIR__.'/api/patamar4.php')();


// Hermes hook bridge loopback sink — the controller enforces 127.0.0.1 + X-Atlas-Hook-Token (hash_equals)
// and fails closed (403, no record) on any non-loopback origin or token mismatch. Default-off: no hook is
// registered unless hook_policy=atlas_adapter, so this endpoint stays dormant until an operator opts in.
(require __DIR__.'/api/hermes-hooks.php')();


// Operator Intelligence Layer · profile learning, review, context and private projection.
(require __DIR__.'/api/operator-intelligence.php')();

