<?php

declare(strict_types=1);

namespace App\Http\Controllers;

// Gap5.F2 wiring marker — Programming-adjacent controller.
// Canonical route_decision schema: atlas.dual_core.route_decision.v1
// Method-level emission to be wired per AP per family.

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
