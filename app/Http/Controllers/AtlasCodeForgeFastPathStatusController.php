<?php

declare(strict_types=1);

namespace App\Http\Controllers;

// Gap5.F2 wiring marker — this controller is Programming-adjacent.
// route_decision.v1 emission to be wired per AP per family.
// Schema: atlas.dual_core.route_decision.v1
// Canon: docs/engineering-knowledge-base/atlas-dev-forge-escalation-consolidation-plan.md

use App\Models\AtlasProject;
use App\Services\Ai\Programming\AtlasCodeForgeFastPathStatusService;
use Illuminate\Http\JsonResponse;

/**
 * Atlas Code Forge Fast Path · run status + resume endpoints.
 *
 * GET  /atlas-code/works/{project}/forge/fast-path/{run}/status
 * POST /atlas-code/works/{project}/forge/fast-path/{run}/resume
 *
 * Reconstroi o estado real do run da Obra. Fail-closed quando run nao existe
 * ou nao pertence a esta Obra.
 */
final class AtlasCodeForgeFastPathStatusController extends Controller
{
    public function show(
        AtlasProject $project,
        string $run,
        AtlasCodeForgeFastPathStatusService $service,
    ): JsonResponse {
        $payload = $service->status($project, $run);
        $statusCode = (int) ($payload['http_status'] ?? 200);
        unset($payload['http_status']);

        return response()->json($payload, $statusCode);
    }

    public function resume(
        AtlasProject $project,
        string $run,
        AtlasCodeForgeFastPathStatusService $service,
    ): JsonResponse {
        $payload = $service->resume($project, $run);
        $statusCode = (int) ($payload['http_status'] ?? 200);
        unset($payload['http_status']);

        return response()->json($payload, $statusCode);
    }
}
