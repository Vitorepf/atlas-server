<?php

namespace App\Http\Controllers;

use App\Services\Ai\RuntimeReadiness\AtlasAiRuntimeReadinessService;
use Illuminate\Http\JsonResponse;

/**
 * HTTP endpoint para o Atlas AI Runtime Readiness & Release Gate.
 *
 *   GET /atlas/ai/runtime-readiness
 *
 * Retorna o mesmo payload de `AtlasAiRuntimeReadinessService::report()`
 * exposto via CLI, agora consumível por superfícies Desktop/Mobile.
 *
 * Status code: 200 em todos os casos (ready|partial|blocked). O consumer
 * decide como reagir; o gate `blocked` é informativo para a UI exibir o
 * estado real, não um erro HTTP.
 */
final class AtlasAiRuntimeReadinessController extends Controller
{
    public function __invoke(AtlasAiRuntimeReadinessService $readiness): JsonResponse
    {
        $payload = $readiness->report();

        return response()->json($payload, 200);
    }
}
