<?php

declare(strict_types=1);

namespace App\Http\Controllers;

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
