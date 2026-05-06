<?php

namespace App\Http\Controllers;

use App\Services\Ai\Kernel\Pipeline\KernelPipelineAuditService;
use App\Services\Ai\Kernel\Pipeline\PipelineInput;
use App\Services\Ai\Kernel\Pipeline\ScaffoldAtlasKernelPipeline;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AtlasAiPipelineController extends Controller
{
    public function __invoke(Request $request, ScaffoldAtlasKernelPipeline $pipeline, KernelPipelineAuditService $audit): JsonResponse
    {
        $data = $request->validate([
            'text' => ['nullable', 'string', 'max:20000'],
            'primary_text' => ['nullable', 'string', 'max:20000'],
            'input_type' => ['nullable', 'string', 'max:80'],
            'surface_id' => ['nullable', 'string', 'max:120'],
            'tenant_id' => ['nullable', 'string', 'max:120'],
            'operator_id' => ['nullable', 'string', 'max:120'],
            'locale' => ['nullable', 'string', 'max:20'],
            'hints' => ['nullable', 'array'],
            'metadata' => ['nullable', 'array'],
            'execute' => ['nullable', 'boolean'],
        ]);

        $input = PipelineInput::fromArray([
            'text' => $data['text'] ?? $data['primary_text'] ?? 'atlas kernel pipeline inspection',
            'input_type' => $data['input_type'] ?? 'text',
            'surface_id' => $data['surface_id'] ?? 'atlas_api',
            'tenant_id' => $data['tenant_id'] ?? 'atlas-single-tenant',
            'operator_id' => $data['operator_id'] ?? 'api',
            'locale' => $data['locale'] ?? 'pt-BR',
            'hints' => is_array($data['hints'] ?? null) ? $data['hints'] : [],
            'metadata' => is_array($data['metadata'] ?? null) ? $data['metadata'] : [],
            'dry_run' => true,
        ]);

        if ((bool) ($data['execute'] ?? false)) {
            $result = $pipeline->execute($input);
            $ledgerEvent = $audit->recordScaffoldExecution($result);

            $payload = [
                'schema_version' => 1,
                'status' => 'executed_scaffold',
                'pipeline' => $result->toArray(),
                'ledger_event' => $audit->eventPayload($ledgerEvent),
            ];
        } else {
            $payload = [
                'schema_version' => 1,
                'status' => 'planned_scaffold',
                'pipeline' => $pipeline->plan($input),
                'compliance' => $pipeline->complianceReport(),
            ];
        }

        return response()->json($payload);
    }
}
