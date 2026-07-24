<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\HermesHookSinkController;

/**
 * Hermes internal hook sink (full-pass routes split).
 */
return static function (): void {
    Route::post('/internal/hermes/hooks/{trace}', App\Http\Controllers\HermesHookSinkController::class);
};
