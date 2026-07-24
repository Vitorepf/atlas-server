<?php

declare(strict_types=1);

use App\Http\Controllers\AtlasMemoryController;
use App\Http\Controllers\AtlasMemoryMaintenanceController;
use App\Http\Controllers\AtlasMemoryRecallController;
use App\Http\Controllers\AtlasOpenBrainController;
use App\Http\Controllers\AtlasOpenBrainMcpController;
use App\Http\Controllers\AtlasVaultController;
use Illuminate\Support\Facades\Route;

/**
 * AI memory, open-brain, and vault HTTP surface (full-pass routes density split).
 * Intended to run inside the atlas.token middleware group.
 *
 * @return \Closure(): void
 */
return static function (): void {
    Route::get('/ai/memory', [AtlasMemoryController::class, 'index']);
    Route::post('/ai/memory', [AtlasMemoryController::class, 'store']);
    Route::post('/ai/memory/recall', AtlasMemoryRecallController::class);
    Route::post('/ai/memory/maintain', AtlasMemoryMaintenanceController::class);
    Route::post('/ai/open-brain/context-pack', [AtlasOpenBrainController::class, 'contextPack']);
    Route::get('/ai/open-brain/audits', [AtlasOpenBrainController::class, 'audits']);
    Route::match(['GET', 'POST'], '/ai/open-brain/mcp', AtlasOpenBrainMcpController::class);
    Route::get('/ai/memory/audit/traces/{trace}', [AtlasMemoryController::class, 'auditTrace']);
    Route::get('/ai/memory/deltas', [AtlasMemoryController::class, 'indexDeltas']);
    Route::get('/ai/memory/deltas/{delta}', [AtlasMemoryController::class, 'showDelta']);
    Route::post('/ai/memory/deltas/{delta}/review', [AtlasMemoryController::class, 'reviewDelta']);
    Route::post('/ai/memory/deltas/{delta}/promote', [AtlasMemoryController::class, 'promoteDelta']);
    Route::post('/ai/memory/governance/scan', [AtlasMemoryController::class, 'scanGovernance']);
    Route::post('/ai/memory/privacy/scan', [AtlasMemoryController::class, 'scanPrivacy']);
    Route::get('/ai/memory/review-queue', [AtlasMemoryController::class, 'reviewQueue']);
    Route::get('/ai/memory/provider-projection/status', [AtlasMemoryController::class, 'providerProjectionStatus']);
    Route::get('/ai/memory/provider-projection/review', [AtlasMemoryController::class, 'providerProjectionReview']);
    Route::get('/ai/memory/provider-projection/audits/summary', [AtlasMemoryController::class, 'providerProjectionAuditSummary']);
    Route::post('/ai/memory/provider-projection/audits/purge', [AtlasMemoryController::class, 'providerProjectionAuditPurge']);
    Route::get('/ai/memory/provider-projection/audits', [AtlasMemoryController::class, 'providerProjectionAudits']);
    Route::post('/ai/memory/provider-projection/apply', [AtlasMemoryController::class, 'providerProjectionApply']);
    Route::get('/ai/memory/relations', [AtlasMemoryController::class, 'indexRelations']);
    Route::post('/ai/memory/relations/{relation}/review', [AtlasMemoryController::class, 'reviewRelation']);
    Route::get('/ai/memory/quality/history', [AtlasMemoryController::class, 'qualityHistory']);
    Route::post('/ai/memory/quality/snapshots', [AtlasMemoryController::class, 'qualitySnapshot']);
    Route::get('/ai/memory/quality', [AtlasMemoryController::class, 'quality']);
    Route::get('/ai/memory/verbatim', [AtlasMemoryController::class, 'indexVerbatim']);
    Route::post('/ai/memory/verbatim', [AtlasMemoryController::class, 'storeVerbatim']);
    Route::post('/ai/memory/verbatim/{verbatimMemory}/review', [AtlasMemoryController::class, 'reviewVerbatim']);
    Route::get('/ai/memory/verbatim/{verbatimMemory}', [AtlasMemoryController::class, 'showVerbatim']);
    Route::patch('/ai/memory/verbatim/{verbatimMemory}', [AtlasMemoryController::class, 'updateVerbatim']);
    Route::post('/ai/memory/usages/{usage}/feedback', [AtlasMemoryController::class, 'feedbackUsage']);
    Route::get('/ai/memory/{memoryEntry}', [AtlasMemoryController::class, 'show']);
    Route::post('/ai/memory/{memoryEntry}/privacy', [AtlasMemoryController::class, 'reviewPrivacy']);
    Route::get('/ai/memory/{memoryEntry}/governance', [AtlasMemoryController::class, 'governance']);
    Route::patch('/ai/memory/{memoryEntry}', [AtlasMemoryController::class, 'update']);
    Route::get('/ai/vault/status', [AtlasVaultController::class, 'status']);
    Route::post('/ai/vault/import', [AtlasVaultController::class, 'import']);
    Route::post('/ai/vault/export-semantic', [AtlasVaultController::class, 'exportSemantic']);
    Route::post('/ai/vault/sync', [AtlasVaultController::class, 'sync']);
    Route::get('/ai/vault/conflicts', [AtlasVaultController::class, 'conflicts']);
    Route::get('/ai/vault/conflicts/{item}', [AtlasVaultController::class, 'item']);
    Route::post('/ai/vault/conflicts/{item}/resolve', [AtlasVaultController::class, 'resolve']);
};
