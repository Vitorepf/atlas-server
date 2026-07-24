<?php

declare(strict_types=1);

use App\Http\Controllers\ArenaRunController;
use Illuminate\Support\Facades\Route;

/**
 * Arena HTTP surface (full-pass routes density split).
 * Intended to run inside the atlas.token middleware group.
 *
 * @return \Closure(): void
 */
return static function (): void {
    Route::get('/arena/composite', [ArenaRunController::class, 'composite']);
    Route::get('/arena/scoreboard', [ArenaRunController::class, 'scoreboard']);
    Route::get('/arena/capabilities', [ArenaRunController::class, 'capabilities']);
    Route::get('/arena/runs/live', [ArenaRunController::class, 'live']);
    Route::get('/arena/engines', [ArenaRunController::class, 'engines']);
    Route::get('/arena/report', [ArenaRunController::class, 'report']);
    Route::post('/arena/runs', [ArenaRunController::class, 'store']);
    Route::post('/arena/measurements/{measurement}/stop', [ArenaRunController::class, 'stop']);
};
