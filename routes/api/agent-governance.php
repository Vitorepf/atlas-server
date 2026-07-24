<?php

declare(strict_types=1);

use App\Http\Controllers\AtlasAgentGovernanceController;
use Illuminate\Support\Facades\Route;

/**
 * Agent governance fleet visibility + DESLIGAR surface (full-pass routes split).
 * Read endpoints never start agents; writes only turn OFF. Inside atlas.token.
 *
 * @return \Closure(): void
 */
return static function (): void {
    // AGENT GOVERNANCE — the fleet visibility + DESLIGAR surface the mobile/desktop apps poll. Read endpoints
    // (active/status/history) never start/stop anything; the only writes turn agents OFF (per-agent or the
    // off-all panic) — there is deliberately no turn-ON endpoint, so a tapped app can only reduce spend.
    Route::get('/agents/active', [AtlasAgentGovernanceController::class, 'active']);
    Route::get('/agents/status', [AtlasAgentGovernanceController::class, 'status']);
    Route::get('/agents/history', [AtlasAgentGovernanceController::class, 'history']);
    Route::get('/agents/task-health', [AtlasAgentGovernanceController::class, 'taskHealth']);
    Route::post('/agents/off-all', [AtlasAgentGovernanceController::class, 'offAll']);
    Route::post('/agents/{key}/off', [AtlasAgentGovernanceController::class, 'off']);
};
