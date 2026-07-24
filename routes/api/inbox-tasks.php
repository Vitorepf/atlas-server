<?php

declare(strict_types=1);

use App\Http\Controllers\AtlasMemoryController;
use App\Http\Controllers\AtlasTaskController;
use App\Http\Controllers\InboxController;
use Illuminate\Support\Facades\Route;

/**
 * Inbox + tasks agenda HTTP surface (full-pass routes density split).
 * Inside atlas.token. Task engineering lives in engineering.php.
 *
 * @return \Closure(): void
 */
return static function (): void {
    Route::get('/inbox', [InboxController::class, 'index']);
    Route::get('/inbox/health', [InboxController::class, 'health']);
    Route::post('/inbox/bulk', [InboxController::class, 'bulk']);
    Route::get('/tasks/agenda', [AtlasTaskController::class, 'agenda']);
    Route::post('/tasks/agenda/plan', [AtlasTaskController::class, 'planAgenda']);
    Route::get('/tasks/agenda/week', [AtlasTaskController::class, 'weekAgenda']);
    Route::post('/tasks/agenda/week/plan', [AtlasTaskController::class, 'planWeekAgenda']);
    Route::get('/tasks/{task}/events', [AtlasTaskController::class, 'events']);
    Route::get('/tasks/{task}/memory', [AtlasMemoryController::class, 'forTask']);
};
