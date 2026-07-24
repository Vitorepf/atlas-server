<?php

declare(strict_types=1);

use App\Http\Controllers\AtlasConstelacaoController;
use App\Http\Controllers\AtlasProjectPlanProposalController;
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
use App\Http\Controllers\HealthSnapshotController;
use App\Http\Controllers\PassiveSignalController;
use App\Http\Controllers\ProcrastinationEventController;
use App\Http\Controllers\SemanticActivationController;
use App\Http\Controllers\SemanticCurationProposalController;
use App\Http\Controllers\SemanticNoteController;
use App\Http\Controllers\SemanticSearchController;
use App\Http\Controllers\VaultHealthController;
use Illuminate\Support\Facades\Route;

/**
 * Captures, lifestyle resources, semantic vault/games, celestial (full-pass routes split). Inside atlas.token.
 *
 * @return \Closure(): void
 */
return static function (): void {
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

};
