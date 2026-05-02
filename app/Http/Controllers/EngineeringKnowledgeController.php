<?php

namespace App\Http\Controllers;

use App\Services\Engineering\EngineeringKnowledgeBaseService;
use App\Services\Engineering\EngineeringCodeIntelligenceService;
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

    public function codeIndex(Request $request, EngineeringCodeIntelligenceService $code): JsonResponse
    {
        $data = $request->validate([
            'workspace' => ['nullable', 'string', 'max:1000'],
            'dry_run' => ['nullable', 'boolean'],
            'prune' => ['nullable', 'boolean'],
        ]);

        return response()->json($code->index($data));
    }

    public function codeAudit(Request $request, EngineeringCodeIntelligenceService $code): JsonResponse
    {
        $data = $request->validate([
            'workspace' => ['nullable', 'string', 'max:1000'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
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
