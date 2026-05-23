<?php

namespace App\Http\Controllers;

use App\Services\Ai\WorkspaceIntelligence\AtlasWorkspaceIntelligenceExecutionGateService;
use App\Services\Ai\WorkspaceIntelligence\AtlasWorkspacePathResolverService;
use App\Services\Engineering\EngineeringCodeIntelligenceService;
use App\Services\Engineering\EngineeringKnowledgeBaseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class EngineeringKnowledgeController extends Controller
{
    public function index(Request $request, EngineeringKnowledgeBaseService $knowledge): JsonResponse
    {
        $data = $request->validate([
            'category' => ['nullable', 'string', 'max:80'],
            'status' => ['nullable', Rule::in(['active', 'draft', 'archived', 'deprecated'])],
            'q' => ['nullable', 'string', 'max:160'],
            'include_archived' => ['nullable', 'boolean'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $catalog = $knowledge->catalog($data, (int) ($data['limit'] ?? 50));

        return response()->json($catalog);
    }

    public function show(string $item, EngineeringKnowledgeBaseService $knowledge): JsonResponse
    {
        $payload = $knowledge->find($item);
        abort_if($payload === null, 404);

        return response()->json([
            'knowledge_item' => $payload,
        ]);
    }

    public function context(Request $request, EngineeringKnowledgeBaseService $knowledge): JsonResponse
    {
        $data = $request->validate([
            'category' => ['nullable', 'string', 'max:80'],
            'q' => ['nullable', 'string', 'max:160'],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string', 'max:80'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);
        $tags = array_values(array_filter([
            ...((array) ($data['tags'] ?? [])),
            $data['q'] ?? null,
        ], fn (mixed $tag): bool => is_string($tag) && trim($tag) !== ''));

        return response()->json([
            'knowledge_refs' => $knowledge->contextRefs([
                'category' => $data['category'] ?? null,
                'tags' => $tags,
                'contract' => ['tags' => $tags],
            ], (int) ($data['limit'] ?? 8)),
        ]);
    }

    public function sync(Request $request, EngineeringKnowledgeBaseService $knowledge): JsonResponse
    {
        $data = $request->validate([
            'dry_run' => ['nullable', 'boolean'],
            'prune' => ['nullable', 'boolean'],
        ]);

        return response()->json($knowledge->sync($data));
    }

    public function codeIndex(
        Request $request,
        EngineeringCodeIntelligenceService $code,
        AtlasWorkspaceIntelligenceExecutionGateService $workspaceGate,
        AtlasWorkspacePathResolverService $workspacePaths,
    ): JsonResponse {
        $data = $request->validate([
            'workspace' => ['nullable', 'string', 'max:1000'],
            'dry_run' => ['nullable', 'boolean'],
            'prune' => ['nullable', 'boolean'],
            'run_context_type' => ['nullable', 'string', 'max:80'],
            'run_context_id' => ['nullable', 'string', 'max:120'],
        ]);

        $workspaceResolution = $workspacePaths->resolveForExecution(is_string($data['workspace'] ?? null) ? $data['workspace'] : null);
        if (($workspaceResolution['status'] ?? null) !== 'ready') {
            return response()->json([
                'schema_version' => 'atlas.engineering.code_index.awis_gate.v1',
                'ok' => false,
                'status' => 'blocked',
                'error' => 'workspace_required_for_index_code',
                'message' => 'index-code exige workspace resolvivel para um Workspace AWIS registrado.',
                'awis_execution_gate' => $workspaceGate->gate(
                    workspace: '__missing_index_code_workspace__',
                    mode: 'index-code',
                    task: 'engineering knowledge index-code',
                ),
            ], 422);
        }

        $gate = $workspaceGate->gate(
            workspace: (string) $workspaceResolution['workspace_slug'],
            mode: 'index-code',
            task: 'engineering knowledge index-code',
        );
        if (($gate['allowed'] ?? false) !== true) {
            return response()->json([
                'schema_version' => 'atlas.engineering.code_index.awis_gate.v1',
                'ok' => false,
                'status' => 'blocked',
                'error' => 'awis_execution_gate_blocked',
                'awis_execution_gate' => $gate,
            ], 422);
        }

        $data['workspace'] = (string) $workspaceResolution['workspace_path'];
        $payload = $code->index($data);
        $payload['awis_execution_gate'] = $gate;

        return response()->json($payload);
    }

    public function codeAudit(Request $request, EngineeringCodeIntelligenceService $code): JsonResponse
    {
        $data = $request->validate([
            'workspace' => ['nullable', 'string', 'max:1000'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
            'run_context_type' => ['nullable', 'string', 'max:80'],
            'run_context_id' => ['nullable', 'string', 'max:120'],
        ]);

        return response()->json($code->audit($data));
    }

    public function codeModules(Request $request, EngineeringCodeIntelligenceService $code): JsonResponse
    {
        $data = $request->validate([
            'layer' => ['nullable', 'string', 'max:80'],
            'docs_status' => ['nullable', 'string', 'max:40'],
            'q' => ['nullable', 'string', 'max:160'],
            'include_archived' => ['nullable', 'boolean'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        return response()->json($code->catalog($data, (int) ($data['limit'] ?? 50)));
    }

    public function codeSymbols(Request $request, EngineeringCodeIntelligenceService $code): JsonResponse
    {
        $data = $request->validate([
            'module' => ['nullable', 'string', 'max:160'],
            'symbol_type' => ['nullable', 'string', 'max:60'],
            'language' => ['nullable', 'string', 'max:40'],
            'docs_status' => ['nullable', 'string', 'max:40'],
            'q' => ['nullable', 'string', 'max:160'],
            'include_archived' => ['nullable', 'boolean'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:500'],
        ]);

        return response()->json($code->symbols($data, (int) ($data['limit'] ?? 100)));
    }

    public function codeModule(string $module, EngineeringCodeIntelligenceService $code): JsonResponse
    {
        $payload = $code->module($module);
        abort_if($payload === null, 404);

        return response()->json($payload);
    }
}
