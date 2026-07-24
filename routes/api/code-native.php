<?php

declare(strict_types=1);

use App\Http\Controllers\AtlasCodeAskController;
use App\Http\Controllers\AtlasCodeGraphController;
use App\Http\Controllers\AtlasCodeHealController;
use App\Http\Controllers\AtlasCodeMirrorController;
use App\Http\Controllers\AtlasCodePreflightController;
use App\Http\Controllers\AtlasCodeProvenanceController;
use App\Http\Controllers\AtlasCodeReposController;
use App\Http\Controllers\AtlasCodeReviewController;
use App\Http\Controllers\AtlasCodeViolationController;
use App\Http\Controllers\AtlasCodeWeekController;
use App\Http\Controllers\AtlasCodeWhyController;
use Illuminate\Support\Facades\Route;

/**
 * Native /code/* topology surface for the app (distinct from /atlas-code/*).
 * Full-pass routes density split. Intended inside atlas.token group.
 *
 * @return \Closure(): void
 */
return static function (): void {
    // Atlas Código C22 · local-first, read-only Git topology for the native app.
    Route::get('/code/graph', AtlasCodeGraphController::class);
    // M3 radar · a frota de repositórios por exceção (read-only).
    Route::get('/code/repos', AtlasCodeReposController::class);
    // M5 espelho · estado do envio + varredura de segredos (read-only).
    Route::get('/code/mirror', AtlasCodeMirrorController::class);
    Route::match(['get', 'post'], '/code/preflight', AtlasCodePreflightController::class);
    Route::match(['get', 'post'], '/code/heals/tick', [AtlasCodeHealController::class, 'tick']);
    Route::post('/code/heals/{healId}/undo', [AtlasCodeHealController::class, 'undo'])
        ->where('healId', '[0-9A-Z]{20,32}');
    Route::get('/code/provenance/{hash}', AtlasCodeProvenanceController::class)
        ->where('hash', '[0-9a-fA-F]{7,64}');
    // H1 · biografia do arquivo: Git --follow + proveniência C23.
    Route::get('/code/why', AtlasCodeWhyController::class);
    Route::get('/code/violations', AtlasCodeViolationController::class);
    Route::get('/code/week', AtlasCodeWeekController::class);
    // H6 pílula · pergunta em linguagem humana sobre o grafo (read-only).
    Route::post('/code/ask', AtlasCodeAskController::class);
    // H6 · estado dos agentes que revisam, por commit (read-only; quem manda
    // revisar é a pílula, porque mandar é uma frase, não um botão).
    Route::get('/code/review', AtlasCodeReviewController::class);
};
