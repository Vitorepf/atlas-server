<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AtlasProject;
use App\Services\Ai\Programming\AtlasForgeProviderInvocationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Atlas Code Forge Governed Provider Invocation endpoint.
 *
 * POST /atlas-code/works/{project}/forge/provider-invocations
 * GET  /atlas-code/works/{project}/forge/provider-invocations/latest
 *
 * Fail-closed sem Obra. Nunca chama provider externo sem `confirm_provider_call`
 * + `confirm_budget` + `confirm_runtime_dispatch` + driver configurado.
 */
final class AtlasCodeForgeProviderInvocationController extends Controller
{
    public function store(
        Request $request,
        AtlasProject $project,
        AtlasForgeProviderInvocationService $service,
    ): JsonResponse {
        $data = $request->validate([
            'role' => ['nullable', 'string', 'max:60'],
            'mode' => ['nullable', Rule::in([
                AtlasForgeProviderInvocationService::MODE_DRY_RUN,
                AtlasForgeProviderInvocationService::MODE_EXECUTE,
            ])],
            'dispatch_id' => ['nullable', 'string', 'max:60'],
            'confirm_provider_call' => ['nullable', 'boolean'],
            'confirm_budget' => ['nullable', 'boolean'],
            'confirm_runtime_dispatch' => ['nullable', 'boolean'],
            'timeout_seconds' => ['nullable', 'integer', 'min:1', 'max:3600'],
            'max_output_chars' => ['nullable', 'integer', 'min:200', 'max:120000'],
        ]);

        $payload = $service->invoke([
            'obra_id' => (string) $project->getKey(),
            'role' => $data['role'] ?? null,
            'mode' => $data['mode'] ?? AtlasForgeProviderInvocationService::MODE_DRY_RUN,
            'dispatch_id' => $data['dispatch_id'] ?? null,
            'confirm_provider_call' => $data['confirm_provider_call'] ?? false,
            'confirm_budget' => $data['confirm_budget'] ?? false,
            'confirm_runtime_dispatch' => $data['confirm_runtime_dispatch'] ?? false,
            'timeout_seconds' => $data['timeout_seconds'] ?? 120,
            'max_output_chars' => $data['max_output_chars'] ?? 12000,
        ]);

        return response()->json($payload, $this->statusCodeFor($payload));
    }

    public function latest(
        AtlasProject $project,
        AtlasForgeProviderInvocationService $service,
    ): JsonResponse {
        $latest = $service->latest($project);
        if ($latest === null) {
            return response()->json([
                'schema_version' => AtlasForgeProviderInvocationService::SCHEMA_VERSION,
                'status' => 'no_invocation_history',
                'obra_id' => (string) $project->getKey(),
                'obra_present' => true,
                'note' => 'Nenhuma invocation registrada. Use POST para preparar (dry_run) ou executar (com confirmacoes).',
            ], 200);
        }

        return response()->json($latest, 200);
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function statusCodeFor(array $payload): int
    {
        return match ((string) ($payload['status'] ?? '')) {
            AtlasForgeProviderInvocationService::STATUS_PLANNED => 200,
            AtlasForgeProviderInvocationService::STATUS_EXECUTED => 200,
            AtlasForgeProviderInvocationService::STATUS_BLOCKED => 409,
            AtlasForgeProviderInvocationService::STATUS_FAILED => 409,
            AtlasForgeProviderInvocationService::STATUS_TIMED_OUT => 409,
            AtlasForgeProviderInvocationService::STATUS_CANCELLED => 409,
            default => 200,
        };
    }
}
