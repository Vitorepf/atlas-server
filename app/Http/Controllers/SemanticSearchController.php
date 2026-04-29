<?php

namespace App\Http\Controllers;

use App\Http\Requests\SemanticSearchRequest;
use App\Http\Resources\SemanticNoteResource;
use App\Services\Semantic\SemanticSearchService;
use Illuminate\Http\JsonResponse;

class SemanticSearchController extends Controller
{
    public function __invoke(SemanticSearchRequest $request, SemanticSearchService $search): JsonResponse
    {
        $data = $request->validated();
        $notes = $search->search(
            query: (string) ($data['query'] ?? ''),
            filters: $data['filters'] ?? [],
            limit: (int) ($data['limit'] ?? 10),
        );

        return response()->json([
            'notes' => SemanticNoteResource::collection($notes)->resolve(),
        ]);
    }
}
