<?php

declare(strict_types=1);

use App\Http\Controllers\AtlasPatamar4StateController;
use App\Http\Controllers\AtlasPatamar4SurfaceController;
use Illuminate\Support\Facades\Route;

/**
 * Patamar4 HTTP surface (full-pass routes split).
 */
return static function (): void {
    Route::get('/atlas/patamar4/state', AtlasPatamar4StateController::class);

    // Atlas Patamar 4 Surface Facade — 7 endpoints canon consumidos pela UI mobile+desktop (F3).
    Route::get('/atlas/patamar4/scheduler', [AtlasPatamar4SurfaceController::class, 'scheduler']);
    Route::get('/atlas/patamar4/swarm', [AtlasPatamar4SurfaceController::class, 'swarm']);
    Route::get('/atlas/patamar4/rebalance', [AtlasPatamar4SurfaceController::class, 'rebalance']);
    Route::get('/atlas/patamar4/cognitive-function', [AtlasPatamar4SurfaceController::class, 'cognitiveFunction']);
    Route::get('/atlas/patamar4/governance', [AtlasPatamar4SurfaceController::class, 'governance']);
    // Token-gated: these two MUTATE. The comment below used to argue conduct was
    // safe because LIVE needs the server-side production resolver — but that flag
    // (atlas.patamar4.swarm_production_resolver_enabled) defaults to TRUE, and both
    // `mode` and `operator_approved` come straight from the request body. So an
    // unauthenticated POST could reach LIVE whenever admission returns
    // allow_with_approval. The read-only GETs above stay open for the mobile UI,
    // which authenticates on its own path.
    Route::middleware('atlas.token')->group(function (): void {
        Route::post('/atlas/patamar4/decompose', [AtlasPatamar4SurfaceController::class, 'decompose']);
    });
    Route::get('/atlas/patamar4/inbox/madrugada', [AtlasPatamar4SurfaceController::class, 'madrugadaInbox']);

    // Atlas Patamar 4 · governed engineering run — drives AtlasEngineeringRunConductorService from the
    // operator's natural-language surface. SHADOW-default; LIVE needs the server-side production-resolver
    // flag + admission (the conductor allowlist enforces it — request input alone cannot escalate to spend).
    Route::middleware('atlas.token')->group(function (): void {
        Route::post('/atlas/patamar4/conduct', [AtlasPatamar4SurfaceController::class, 'conduct']);
    });
};
