<?php

declare(strict_types=1);

namespace App\Http\Controllers;

// Gap5.F2 wiring marker — Programming-adjacent controller.
// Canonical route_decision schema: atlas.dual_core.route_decision.v1
// Method-level emission to be wired per AP per family.

use App\Models\AtlasProject;
use App\Services\Ai\Programming\AtlasForgeRuntimeDispatchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Atlas Code Forge Runtime Dispatch endpoint.
 *
 * POST /atlas-code/works/{project}/forge/runtime-dispatch
 * GET  /atlas-code/works/{project}/forge/runtime-dispatch
 *
 * Fail-closed sem Obra. Nunca cria Obra silenciosamente. Nunca chama provider
 * externo. Sempre devolve o plano canonico `atlas.forge.runtime_dispatch_plan.v1`.
 */
final class AtlasCodeForgeRuntimeDispatchController extends Controller
{
    public function store(
        Request $request,
        AtlasProject $project,
        AtlasForgeRuntimeDispatchService $service,
    ): JsonResponse {
        $data = $request->validate([
            'role' => ['nullable', 'string', 'max:60'],
            'simulate_provider_failure' => ['nullable', 'string', 'max:60'],
            'create_child_receipt' => ['nullable', 'boolean'],
            'fast_path_run_id' => ['nullable', 'string', 'max:60'],
            'execution_mode' => ['nullable', Rule::in(['prepare_dispatch_plan'])],
        ]);

        $plan = $service->dispatch([
            'obra_id' => (string) $project->getKey(),
            'role' => $data['role'] ?? null,
            'simulate_provider_failure' => $data['simulate_provider_failure'] ?? null,
            'create_child_receipt' => $data['create_child_receipt'] ?? false,
            'fast_path_run_id' => $data['fast_path_run_id'] ?? null,
            'execution_mode' => $data['execution_mode'] ?? 'prepare_dispatch_plan',
        ]);

        return response()->json($plan, $this->statusCodeFor($plan));
    }

    public function show(
        AtlasProject $project,
        AtlasForgeRuntimeDispatchService $service,
    ): JsonResponse {
        $latest = $service->latest($project);
        if ($latest === null) {
            return response()->json([
                'schema_version' => AtlasForgeRuntimeDispatchService::SCHEMA_VERSION,
                'status' => 'no_dispatch_history',
                'obra_id' => (string) $project->getKey(),
                'obra_present' => true,
                'note' => 'Nenhum dispatch plan registrado para esta Obra. Use POST para preparar um novo.',
            'route_decision' => \App\Services\Ai\DualCore\CanonicalRouteDecisionEnvelope::emit(route: 'programming', reason: 'http_atlas_code_forge_runtime_dispatch_controller'),
        ], 200);
        }

        // GET read-model sempre 200 — o status semantico vive em $latest['status'].
        return response()->json($latest, 200);
    }

    /**
     * @param  array<string,mixed>  $plan
     */
    private function statusCodeFor(array $plan): int
    {
        return match ((string) ($plan['status'] ?? '')) {
            AtlasForgeRuntimeDispatchService::STATUS_DISPATCH_PLANNED => 201,
            AtlasForgeRuntimeDispatchService::STATUS_FALLBACK_CHILD_RECEIPT_REQUIRED => 409,
            AtlasForgeRuntimeDispatchService::STATUS_CAPACITY_EXHAUSTED => 409,
            AtlasForgeRuntimeDispatchService::STATUS_BLOCKED => 409,
            default => 200,
        };
    }
}
