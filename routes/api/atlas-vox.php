<?php

declare(strict_types=1);

use App\Http\Controllers\AtlasAiVoxController;
use App\Http\Controllers\AtlasAiVoxDogfoodController;
use App\Http\Controllers\AtlasAiVoxMetricsController;
use App\Http\Controllers\AtlasAiVoxReadinessController;
use Illuminate\Support\Facades\Route;

/**
 * Atlas Vox HTTP surface (full-pass routes density split).
 * Intended to run inside the atlas.token middleware group.
 *
 * @return \Closure(): void
 */
return static function (): void {
    // Atlas Vox V0 — Mac/Desktop local-first dictation surface (Onda 2).
    // Boundary: never executes, never persists audio, never calls a provider.
    // See docs/contracts/vox/ + docs/engineering-knowledge-base/adr/0003-*.
    Route::get('/ai/vox/health', [AtlasAiVoxController::class, 'health']);
    Route::post('/ai/vox/intent', [AtlasAiVoxController::class, 'intent']);
    Route::post('/ai/vox/execute', [AtlasAiVoxController::class, 'execute']);

    // Atlas Vox Wave 7 — read-only metrics + V3 promotion gate (rivals removido no Slice 6 do Rivals 2.0).
    // No execution, no provider, no audio. Gate only recommends; Vitor
    // approves V4 manually.
    Route::get('/ai/vox/metrics', [AtlasAiVoxMetricsController::class, 'metrics']);
    Route::get('/ai/vox/gate-v3', [AtlasAiVoxMetricsController::class, 'gateV3']);
    // Wave 7.6 (Claude R) · V3 Certification Pack + human review.
    // The pack snapshot is a deterministic, hash-verifiable read; review
    // never flips a feature flag (V4 unlock is a separate future wave).
    Route::get('/ai/vox/gate-v3/certification-pack', [AtlasAiVoxMetricsController::class, 'certificationPack']);
    Route::post('/ai/vox/gate-v3/review', [AtlasAiVoxMetricsController::class, 'recordPromotionReview']);

    // Wave 7.6 (Claude T) · V3 hardening audit. Read-only. Independently
    // re-verifies every V3 safety invariant before any V4 work begins.
    // Sits next to — not on top of — the certification pack: the pack
    // declares invariants; the audit *measures* them.
    Route::get('/ai/vox/audit/v3-hardening', [AtlasAiVoxMetricsController::class, 'v3HardeningAudit']);
    // Wave 7.8 (Claude V) · single readiness probe. Aggregates 16 checks
    // (modes, metrics, gate, audit, safety, provider CLIs) into a
    // status: ready|partial|blocked + capabilities + next_actions. Never
    // executes a CLI; provider absence becomes a warning, not a block.
    Route::get('/ai/vox/readiness', [AtlasAiVoxReadinessController::class, 'readiness']);

    // Wave 7.9 (Claude Z) · Dogfood Session Evidence. Distinct from rivals:
    // dogfood é o diário de uso real ("usei Vox hoje, foi assim"), rivals é
    // comparação head-to-head. Endpoint NUNCA executa, nunca chama provider,
    // nunca toca audio. Reporta isolado do gate-v3 (informational only).
    Route::post('/ai/vox/dogfood/session', [AtlasAiVoxDogfoodController::class, 'recordSession']);
    Route::get('/ai/vox/dogfood/report', [AtlasAiVoxDogfoodController::class, 'report']);
    // V6-E · feedback leve (chip "Funcionou bem" / "Marcar como ruim"). Não
    // muda outcome, só vira regret_flag para alimentar o report.
    Route::post(
        '/ai/vox/dogfood/session/{dogfood_session_id}/feedback',
        [AtlasAiVoxDogfoodController::class, 'submitFeedback'],
    )->where('dogfood_session_id', '[A-Za-z0-9_\\-]+');
};
