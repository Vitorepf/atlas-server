<?php

use App\Http\Controllers\BehaviorController;
use App\Http\Controllers\BehaviorLogController;
use App\Http\Controllers\CaptureController;
use App\Http\Controllers\CheckinController;
use App\Http\Controllers\CognitiveGameController;
use App\Http\Controllers\DailyMissionController;
use App\Http\Controllers\DigitalActivitySnapshotController;
use App\Http\Controllers\DigitalCategoryMappingController;
use App\Http\Controllers\DigitalSessionController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\HealthSnapshotController;
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

Route::middleware('atlas.token')->group(function (): void {
    Route::get('/captures/{capture}/file', [CaptureController::class, 'file']);
    Route::get('/captures/{capture}/transcription', [CaptureController::class, 'transcription']);
    Route::apiResource('captures', CaptureController::class)->except(['create', 'edit']);

    Route::apiResource('checkins', CheckinController::class)->except(['create', 'edit', 'show']);
    Route::apiResource('passive-signals', PassiveSignalController::class)->except(['create', 'edit', 'show']);
    Route::apiResource('health-snapshots', HealthSnapshotController::class)->except(['create', 'edit', 'show']);
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

    Route::post('/sync', SyncController::class);
});
