<?php

use App\Http\Controllers\AtlasAiRuntimeReadinessController;
use App\Http\Controllers\AtlasBlogEditorialController;
use App\Http\Controllers\AtlasDomainController;
use App\Http\Controllers\AuditEventController;
use App\Http\Controllers\AuditSuggestionController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\RizeWebhookController;
use App\Http\Controllers\SyncController;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class);
Route::post('/integrations/rize/webhook', RizeWebhookController::class);

// Voice Realtime shared registrar (desktop token + mobile bearer).
$registerAtlasVoiceRoutes = require __DIR__.'/api/atlas-voice.php';

// Mobile Gateway · /v1/mobile (pairing, devices, inbox, mac agent, voice via registrar).
(require __DIR__.'/api/mobile.php')($registerAtlasVoiceRoutes);

Route::middleware('atlas.token')->group(function () use ($registerAtlasVoiceRoutes): void {
    Route::apiResource('domains', AtlasDomainController::class)->only(['index', 'store', 'update', 'destroy']);

    (require __DIR__.'/api/arena.php')();
    (require __DIR__.'/api/code-native.php')();
    (require __DIR__.'/api/agent-governance.php')();
    (require __DIR__.'/api/inbox-tasks.php')();
    (require __DIR__.'/api/engineering.php')();
    Route::get('/blog/editorial/state', [AtlasBlogEditorialController::class, 'state']);
    (require __DIR__.'/api/projects-routines.php')();
    (require __DIR__.'/api/semantic-and-lifestyle.php')();
    (require __DIR__.'/api/ai-platform.php')();
    $registerAtlasVoiceRoutes();
    (require __DIR__.'/api/memory-vault.php')();
    (require __DIR__.'/api/ai-runtime.php')();
    Route::get('/audit/suggestions', AuditSuggestionController::class);
    Route::get('/audit/events', [AuditEventController::class, 'index']);

    Route::post('/sync', SyncController::class);

    // Atlas Vox surface (inside atlas.token group).
    (require __DIR__.'/api/atlas-vox.php')();
});

/*
 * Atlas Truth Cartography — read-only HTTP surface for the live cartography UI.
 * The cartography never writes; these endpoints are GET-only by design.
 */
(require __DIR__.'/api/atlas-cartography.php')();


/*
 * Atlas Code · MVP endpoints consumed by the atlas-desktop bridge.
 *
 * The desktop never decides; it commands, shows, signs, observes.
 * These endpoints add the 3 NEW slots identified in the audit. The other 9
 * needs reuse pre-existing routes (/api/projects, /api/ai/threads, etc.).
 *
 * See: atlas-desktop/docs/architecture/0001-atlas-desktop-boundaries.md
 */
    (require __DIR__.'/api/atlas-code.php')();


// Atlas AI Control Plane (Meta 9) · read-only aggregate snapshots / readiness / blockers
(require __DIR__.'/api/control-plane.php')();


// Atlas AI Runtime Readiness & Release Gate · single-call aggregator
Route::get('/atlas/ai/runtime-readiness', AtlasAiRuntimeReadinessController::class);

// Atlas Software Company Stewardship Stack · Area Focus Product Mode read surface (AP-721, AP-712).
// Read-only Desktop-ready read model. No mutation, no execution, no merge/deploy/secrets.
// canon: docs/engineering-knowledge-base/atlas-autonomous-software-company-night-shift-product-mode.md
(require __DIR__.'/api/software-company-stewardship.php')();


// Atlas Patamar 4 · live aggregator (Kernel · Admission · CFA · Reconciliation · TEOS-I4 · Swarm · TDC)
(require __DIR__.'/api/patamar4.php')();


// Hermes hook bridge loopback sink — the controller enforces 127.0.0.1 + X-Atlas-Hook-Token (hash_equals)
// and fails closed (403, no record) on any non-loopback origin or token mismatch. Default-off: no hook is
// registered unless hook_policy=atlas_adapter, so this endpoint stays dormant until an operator opts in.
(require __DIR__.'/api/hermes-hooks.php')();


// Operator Intelligence Layer · profile learning, review, context and private projection.
(require __DIR__.'/api/operator-intelligence.php')();

