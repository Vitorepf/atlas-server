<?php

declare(strict_types=1);

use App\Http\Controllers\AtlasAiVoiceRealtimeController;
use Illuminate\Support\Facades\Route;

/**
 * Atlas Voice Realtime shared route registrar (full-pass routes density split).
 *
 * Registered under both atlas.token (desktop) and atlas.mobile.bearer (mobile).
 *
 * @return \Closure(): void
 */
return static function (): void {
    Route::get('/ai/voice/health', [AtlasAiVoiceRealtimeController::class, 'health']);
    Route::get('/ai/voice/readiness', [AtlasAiVoiceRealtimeController::class, 'readiness']);
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
