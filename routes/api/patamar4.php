<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AtlasPatamar4StateController;
use App\Http\Controllers\AtlasPatamar4SurfaceController;

/**
 * Patamar4 HTTP surface (full-pass routes split).
 */
return static function (): void {
    Route::get('/atlas/patamar4/state', App\Http\Controllers\AtlasPatamar4StateController::class);

    // Atlas Patamar 4 Surface Facade — 7 endpoints canon consumidos pela UI mobile+desktop (F3).
    Route::get('/atlas/patamar4/scheduler', [App\Http\Controllers\AtlasPatamar4SurfaceController::class, 'scheduler']);
    Route::get('/atlas/patamar4/swarm', [App\Http\Controllers\AtlasPatamar4SurfaceController::class, 'swarm']);
    Route::get('/atlas/patamar4/rebalance', [App\Http\Controllers\AtlasPatamar4SurfaceController::class, 'rebalance']);
    Route::get('/atlas/patamar4/cognitive-function', [App\Http\Controllers\AtlasPatamar4SurfaceController::class, 'cognitiveFunction']);
    Route::get('/atlas/patamar4/governance', [App\Http\Controllers\AtlasPatamar4SurfaceController::class, 'governance']);
    Route::post('/atlas/patamar4/decompose', [App\Http\Controllers\AtlasPatamar4SurfaceController::class, 'decompose']);
    Route::get('/atlas/patamar4/inbox/madrugada', [App\Http\Controllers\AtlasPatamar4SurfaceController::class, 'madrugadaInbox']);

    // Atlas Patamar 4 · governed engineering run — drives AtlasEngineeringRunConductorService from the
    // operator's natural-language surface. SHADOW-default; LIVE needs the server-side production-resolver
    // flag + admission (the conductor allowlist enforces it — request input alone cannot escalate to spend).
    Route::post('/atlas/patamar4/conduct', [App\Http\Controllers\AtlasPatamar4SurfaceController::class, 'conduct']);
};
