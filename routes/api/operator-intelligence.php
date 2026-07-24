<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AtlasOperatorIntelligenceController;

/**
 * Operator Intelligence HTTP surface (full-pass routes split).
 */
return static function (): void {
    Route::prefix('atlas/operator-intelligence')->middleware('atlas.token')->group(function (): void {
        Route::post('/capture', [AtlasOperatorIntelligenceController::class, 'capture']);
        Route::get('/review-queue', [AtlasOperatorIntelligenceController::class, 'reviewQueue']);
        Route::post('/candidates/{candidate}/review', [AtlasOperatorIntelligenceController::class, 'review']);
        Route::get('/profile', [AtlasOperatorIntelligenceController::class, 'profile']);
        Route::get('/context', [AtlasOperatorIntelligenceController::class, 'context']);
        Route::get('/digest', [AtlasOperatorIntelligenceController::class, 'digest']);
        Route::post('/project', [AtlasOperatorIntelligenceController::class, 'project']);
    });
};
