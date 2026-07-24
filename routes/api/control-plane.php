<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AtlasAiControlPlaneController;

/**
 * Atlas AI control-plane HTTP surface (full-pass routes split).
 */
return static function (): void {
    Route::prefix('atlas/ai/control-plane')->group(function (): void {
        Route::get('/', [AtlasAiControlPlaneController::class, 'index']);
        Route::get('/readiness', [AtlasAiControlPlaneController::class, 'readiness']);
        Route::get('/blockers', [AtlasAiControlPlaneController::class, 'blockers']);
        Route::get('/next-actions', [AtlasAiControlPlaneController::class, 'nextActions']);
        Route::get('/missions/{uuid}', [AtlasAiControlPlaneController::class, 'mission']);
    });
};
