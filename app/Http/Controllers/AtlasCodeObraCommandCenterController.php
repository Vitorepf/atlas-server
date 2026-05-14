<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AtlasProject;
use App\Services\Ai\Programming\AtlasCodeObraCommandCenterService;
use Illuminate\Http\JsonResponse;

/**
 * Atlas Code Obra Command Center endpoint.
 *
 * GET /atlas-code/works/{project}/obra-command-center
 *
 * Read-only. Nunca cria Obra silenciosamente. Nunca chama provider externo.
 * Doc: docs/engineering-knowledge-base/atlas-code-obra-command-center-v1.md
 */
final class AtlasCodeObraCommandCenterController extends Controller
{
    public function show(
        AtlasProject $project,
        AtlasCodeObraCommandCenterService $service,
    ): JsonResponse {
        $payload = $service->snapshot(['obra_id' => (string) $project->getKey()]);

        return response()->json($payload, 200);
    }
}
