<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AtlasProject;
use App\Services\Ai\Programming\AtlasForgeContinuumCertificationService;
use App\Services\Ai\Programming\AtlasForgeProviderTopologyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Atlas Code Forge Provider Topology read-model endpoint.
 *
 * GET /atlas-code/works/{project}/forge/provider-topology
 *
 * Fail-closed sem Obra. Nunca cria Obra silenciosamente. Nunca chama
 * provider externo. Sempre devolve o read-model canonico
 * `atlas.forge.provider_topology.v1`.
 *
 * Doc: docs/engineering-knowledge-base/atlas-forge-provider-topology-and-fallback-v1.md
 */
final class AtlasCodeForgeProviderTopologyController extends Controller
{
    public function show(
        Request $request,
        AtlasProject $project,
        AtlasForgeProviderTopologyService $service,
    ): JsonResponse {
        $simulate = $this->stringOrNull($request->query('simulate_provider_failure'));
        $strategy = $this->stringOrNull($request->query('strategy'));
        $fastPathRunId = $this->stringOrNull($request->query('fast_path_run_id'));
        $decisionReceiptId = $this->stringOrNull($request->query('decision_receipt_id'));

        $topology = $service->topology([
            'obra_id' => (string) $project->getKey(),
            'simulate_provider_failure' => $simulate,
            'strategy' => $strategy,
            'fast_path_run_id' => $fastPathRunId,
            'decision_receipt_id' => $decisionReceiptId,
        ]);

        $statusCode = match (true) {
            in_array((string) ($topology['status'] ?? ''), [
                'available',
                'rerouted',
                'retry_later',
            ], true) => 200,
            (string) ($topology['status'] ?? '') === 'provider_capacity_exhausted' => 409,
            (string) ($topology['status'] ?? '') === 'blocked' => 409,
            (string) ($topology['status'] ?? '') === 'blocked_obra_required' => 409,
            default => 200,
        };

        return response()->json($topology, $statusCode);
    }

    /**
     * GET /atlas-code/works/{project}/forge/continuum-certification
     *
     * Re-runs the continuum certification for the bound Obra without creating
     * Obra silently. Same fail-closed contract as the CLI: simulation honored
     * via query string, capacity_exhausted answered with 409.
     */
    public function certification(
        Request $request,
        AtlasProject $project,
        AtlasForgeContinuumCertificationService $service,
    ): JsonResponse {
        $simulate = $this->stringOrNull($request->query('simulate_provider_failure'));
        $strategy = $this->stringOrNull($request->query('strategy'));
        $strict = filter_var($request->query('strict'), FILTER_VALIDATE_BOOL);

        $payload = $service->certify([
            'obra_id' => (string) $project->getKey(),
            'simulate_provider_failure' => $simulate,
            'strategy' => $strategy,
            'strict' => $strict,
        ]);

        $statusCode = match ((string) ($payload['status'] ?? '')) {
            AtlasForgeContinuumCertificationService::STATUS_AVAILABLE,
            AtlasForgeContinuumCertificationService::STATUS_BACKEND_AVAILABLE_UI_PENDING,
            AtlasForgeContinuumCertificationService::STATUS_AVAILABLE_WITHOUT_OBRA_CONTEXT => 200,
            AtlasForgeContinuumCertificationService::STATUS_MISSING_ARTIFACTS => 409,
            AtlasForgeContinuumCertificationService::STATUS_BLOCKED,
            AtlasForgeContinuumCertificationService::STATUS_BLOCKED_OBRA_REQUIRED => 409,
            default => 200,
        };

        return response()->json($payload, $statusCode);
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
