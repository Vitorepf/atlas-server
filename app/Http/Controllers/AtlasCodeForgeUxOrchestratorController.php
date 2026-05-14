<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AtlasProject;
use App\Services\Ai\Programming\AtlasCodeForgeUxOrchestratorService;
use Illuminate\Http\JsonResponse;

/**
 * Atlas Code Forge UX Orchestrator endpoint.
 *
 * GET /atlas-code/works/{project}/forge/ux-orchestrator
 *
 * Read-only. Nunca cria Obra silenciosamente. Nunca chama provider externo.
 */
final class AtlasCodeForgeUxOrchestratorController extends Controller
{
    public function show(
        AtlasProject $project,
        AtlasCodeForgeUxOrchestratorService $service,
    ): JsonResponse {
        $payload = $service->snapshot(['obra_id' => (string) $project->getKey()]);

        return response()->json($payload, 200);
    }
}
