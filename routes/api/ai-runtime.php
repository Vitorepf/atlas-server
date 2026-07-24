<?php

declare(strict_types=1);

use App\Http\Controllers\AiAttachmentSearchController;
use App\Http\Controllers\AiChunkedUploadController;
use App\Http\Controllers\AiInteractionController;
use App\Http\Controllers\Ai\AiInteractionSteerController;
use App\Http\Controllers\AiJobController;
use App\Http\Controllers\AiObservabilityController;
use App\Http\Controllers\AiProviderController;
use App\Http\Controllers\AiQualityActionController;
use App\Http\Controllers\AiTelemetryController;
use App\Http\Controllers\AiTelemetryMetricsController;
use App\Http\Controllers\AiThreadController;
use App\Http\Controllers\Ai\YoutubePrewarmController;
use App\Http\Controllers\AtlasDev\CancelController;
use App\Http\Controllers\AtlasDev\IndexController as AtlasDevIndexController;
use App\Http\Controllers\AtlasDev\PlanController;
use App\Http\Controllers\AtlasDev\ReadinessController;
use App\Http\Controllers\AtlasDev\RunController;
use App\Http\Controllers\AtlasDev\ShowController;
use App\Http\Controllers\AtlasDev\StreamController;
use Illuminate\Support\Facades\Route;

/**
 * AI runtime: interactions, YouTube, atlas-dev, threads, jobs, providers, telemetry (full-pass). Inside atlas.token.
 *
 * @return \Closure(): void
 */
return static function (): void {
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

};
