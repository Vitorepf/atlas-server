<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Ai\SoftwareCompanyStewardship\AreaFocusController;
use App\Http\Controllers\Ai\SoftwareCompanyStewardship\AreaFocusLoopCommandController;
use App\Http\Controllers\Ai\SoftwareCompanyStewardship\AutonomosDigestController;
use App\Http\Controllers\Ai\SoftwareCompanyStewardship\ExecutiveDecisionInboxController;
use App\Http\Controllers\Ai\SoftwareCompanyStewardship\ProductModeCockpitController;
use App\Http\Controllers\Ai\SoftwareCompanyStewardship\ProductModeOperationalInboxController;

/**
 * Software Company Stewardship HTTP surface (full-pass routes split).
 */
return static function (): void {
    Route::prefix('ai/software-company-stewardship')->middleware('atlas.token')->group(function (): void {
        Route::get('/area-focus/{area}', [AreaFocusController::class, 'show']);
        Route::get('/executive-decision-inbox/{portfolio}', [ExecutiveDecisionInboxController::class, 'show']);
        Route::get('/product-mode-cockpit/{portfolio}', [ProductModeCockpitController::class, 'show']);
        Route::get('/operational-inbox/{portfolio}', [ProductModeOperationalInboxController::class, 'show']);
        // Atlas Loop Command Surface (mobile READ live state + WRITE human commands). Read-only GETs compose
        // existing read models; POSTs wrap existing owner services (AP-724 decision) or write the runner's own
        // signal files atomically. No new selection/execution/merge logic; never invokes a provider.
        // NOTE: register the static `/loop/areas` before `/loop/{area}/...` so the picker route is never
        // captured as an area id. DEPLOY: after editing this file run `docker exec atlas-backend php
        // artisan route:clear` — route:cache is baked into the bootstrap/cache volume at boot, so new
        // routes 404 over HTTP until cleared.
        // NOTE (god-debulk step 5): the POST /loop/{area}/start-run write-surface (which enqueued the
        // second-engine SoftwareCompanyLoopRunJob) was RETIRED. The runner read-model still backs the GETs.
        Route::get('/loop/areas', [AreaFocusLoopCommandController::class, 'areas']);
        Route::get('/loop/{area}/live', [AreaFocusLoopCommandController::class, 'live']);
        Route::get('/loop/{area}/cycles', [AreaFocusLoopCommandController::class, 'cycles']);
        Route::get('/loop/{area}/backlog', [AreaFocusLoopCommandController::class, 'backlog']);
        Route::get('/loop/{area}/done', [AreaFocusLoopCommandController::class, 'done']);
        Route::get('/loop/{area}/transfer/{handoffId}', [AreaFocusLoopCommandController::class, 'transferStatus']);
        Route::get('/autonomos/digest', AutonomosDigestController::class);
        Route::post('/autonomos/{area}/cycles/{cycle}/revert', [AreaFocusLoopCommandController::class, 'revertCycle']);
        Route::post('/loop/{area}/transfer', [AreaFocusLoopCommandController::class, 'transfer']);
        Route::post('/loop/{area}/operator-decision', [AreaFocusLoopCommandController::class, 'operatorDecision']);
        Route::post('/loop/{area}/run-control', [AreaFocusLoopCommandController::class, 'runControl']);
        Route::post('/loop/{area}/directive', [AreaFocusLoopCommandController::class, 'directive']);
    });
};
