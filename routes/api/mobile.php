<?php

declare(strict_types=1);

use App\Http\Controllers\AiTelemetryController;
use App\Http\Controllers\AtlasConstelacaoController;
use App\Http\Controllers\Mobile\MobileDeviceController;
use App\Http\Controllers\Mobile\MobileHealthController;
use App\Http\Controllers\Mobile\MobileInboxController;
use App\Http\Controllers\Mobile\MobileMacAgentController;
use App\Http\Controllers\Mobile\MobilePairingController;
use App\Http\Controllers\Mobile\MobileRecommendationController;
use App\Http\Controllers\Mobile\MobileThreadController;
use Illuminate\Support\Facades\Route;

/**
 * Mobile Gateway HTTP surface under /v1/mobile (full-pass routes density split).
 * Reuses the shared Atlas Voice registrar for mobile-bearer voice endpoints.
 *
 * @param  \Closure(): void  $registerAtlasVoiceRoutes
 * @return \Closure(): void
 */
return static function (\Closure $registerAtlasVoiceRoutes): void {
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
};
