<?php

use App\Http\Controllers\AiInteractionController;
use App\Http\Controllers\AiJobController;
use App\Http\Controllers\AiObservabilityController;
use App\Http\Controllers\AiProviderController;
use App\Http\Controllers\AiQualityActionController;
use App\Http\Controllers\AiTelemetryController;
use App\Http\Controllers\AiTelemetryMetricsController;
use App\Http\Controllers\AiThreadController;
use App\Http\Controllers\AtlasCalendarBlockController;
use App\Http\Controllers\AtlasDomainController;
use App\Http\Controllers\AtlasProjectBlockerController;
use App\Http\Controllers\AtlasProjectController;
use App\Http\Controllers\AtlasProjectPlanProposalController;
use App\Http\Controllers\AtlasRoutineController;
use App\Http\Controllers\AtlasTaskController;
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
use App\Http\Controllers\HealthController;
use App\Http\Controllers\HealthSnapshotController;
use App\Http\Controllers\InboxController;
use App\Http\Controllers\Mobile\MobileDeviceController;
use App\Http\Controllers\Mobile\MobileHealthController;
use App\Http\Controllers\Mobile\MobileInboxController;
use App\Http\Controllers\Mobile\MobilePairingController;
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

Route::prefix('v1/mobile')->group(function (): void {
    Route::post('/pairing/confirm', [MobilePairingController::class, 'confirm']);

    Route::middleware('atlas.token')->group(function (): void {
        Route::post('/pairing/initiate', [MobilePairingController::class, 'initiate']);
    });

    Route::middleware('atlas.mobile.bearer')->group(function (): void {
        Route::get('/health', [MobileHealthController::class, 'show']);
        Route::post('/telemetry/events', [AiTelemetryController::class, 'store']);

        Route::get('/devices', [MobileDeviceController::class, 'index']);
        Route::post('/devices/push-token', [MobileDeviceController::class, 'updatePushToken']);
        Route::delete('/devices/{device}', [MobileDeviceController::class, 'revoke']);

        Route::get('/inbox', [MobileInboxController::class, 'index']);
        Route::get('/inbox/{inboxItem}', [MobileInboxController::class, 'show']);
        Route::post('/inbox/{inboxItem}/read', [MobileInboxController::class, 'markRead']);
        Route::post('/inbox/{inboxItem}/dismiss', [MobileInboxController::class, 'dismiss']);
        Route::post('/inbox/{inboxItem}/snooze', [MobileInboxController::class, 'snooze']);
        Route::post('/inbox/{inboxItem}/respond', [MobileInboxController::class, 'respond']);
        Route::post('/inbox/{inboxItem}/discuss', [MobileInboxController::class, 'discuss']);

        Route::post('/threads/from-inbox/{inboxItem}', [MobileThreadController::class, 'fromInbox']);
        Route::post('/threads/{thread}/reply', [MobileThreadController::class, 'reply']);
        Route::get('/threads/{thread}', [MobileThreadController::class, 'show']);
    });
});

Route::middleware('atlas.token')->group(function (): void {
    Route::apiResource('domains', AtlasDomainController::class)->only(['index', 'store', 'update', 'destroy']);
    Route::get('/inbox', [InboxController::class, 'index']);
    Route::get('/inbox/health', [InboxController::class, 'health']);
    Route::post('/inbox/bulk', [InboxController::class, 'bulk']);
    Route::get('/tasks/agenda', [AtlasTaskController::class, 'agenda']);
    Route::post('/tasks/agenda/plan', [AtlasTaskController::class, 'planAgenda']);
    Route::get('/tasks/agenda/week', [AtlasTaskController::class, 'weekAgenda']);
    Route::post('/tasks/agenda/week/plan', [AtlasTaskController::class, 'planWeekAgenda']);
    Route::get('/tasks/{task}/events', [AtlasTaskController::class, 'events']);
    Route::get('/tasks/{task}/engineering', [AtlasTaskController::class, 'engineering']);
    Route::post('/tasks/{task}/engineering/blueprint/freeze', [AtlasTaskController::class, 'freezeEngineeringBlueprint']);
    Route::post('/tasks/{task}/engineering/evidence', [AtlasTaskController::class, 'engineeringEvidence']);
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

    Route::get('/ai/interactions', [AiInteractionController::class, 'index']);
    Route::post('/ai/interactions', [AiInteractionController::class, 'store']);
    Route::get('/ai/interactions/{trace}', [AiInteractionController::class, 'show']);
    Route::get('/ai/interactions/{trace}/stream', [AiInteractionController::class, 'stream']);
    Route::post('/ai/interactions/{trace}/feedback', [AiInteractionController::class, 'feedback']);
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
    Route::post('/ai/threads', [AiThreadController::class, 'store']);
    Route::get('/ai/threads/{thread}/state', [AiThreadController::class, 'state']);
    Route::post('/ai/threads/{thread}/compact', [AiThreadController::class, 'compact']);
    Route::post('/ai/threads/{thread}/switch-provider', [AiThreadController::class, 'switchProvider']);
    Route::get('/ai/threads/{thread}/snapshots', [AiThreadController::class, 'snapshots']);
    Route::get('/ai/threads/{thread}', [AiThreadController::class, 'show']);
    Route::patch('/ai/threads/{thread}', [AiThreadController::class, 'update']);

    Route::get('/ai/jobs', [AiJobController::class, 'index']);
    Route::get('/ai/jobs/{job}', [AiJobController::class, 'show']);
    Route::post('/ai/jobs/{job}/retry', [AiJobController::class, 'retry']);
    Route::post('/ai/jobs/{job}/cancel', [AiJobController::class, 'cancel']);

    Route::get('/ai/providers/status', [AiProviderController::class, 'status']);
    Route::post('/ai/providers/check', [AiProviderController::class, 'check']);

    Route::get('/audit/suggestions', AuditSuggestionController::class);
    Route::get('/audit/events', [AuditEventController::class, 'index']);

    Route::post('/sync', SyncController::class);
});
