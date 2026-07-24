<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AtlasCartographyController;

/**
 * Atlas cartography HTTP surface (full-pass routes split).
 */
return static function (): void {
    Route::prefix('atlas-cartography')->group(function () {
        Route::get('/graph', [AtlasCartographyController::class, 'graph']);
        Route::get('/human-clarity', [AtlasCartographyController::class, 'humanClarity']);
        Route::get('/note/{graph_id}', [AtlasCartographyController::class, 'note'])->where('graph_id', '.*');
        Route::get('/recent-changes', [AtlasCartographyController::class, 'recentChanges']);
        // SSE · live-doc stream. Heartbeat every 15s + emit `graph_changed` when
        // the assembler's checksum moves. Connections close after 25s so the
        // `php artisan serve` single-thread worker recycles; client reconnects.
        Route::get('/stream', [AtlasCartographyController::class, 'stream']);
    });
};
