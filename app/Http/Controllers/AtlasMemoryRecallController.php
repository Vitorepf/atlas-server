<?php

namespace App\Http\Controllers;

use App\Http\Requests\RecallAtlasMemoryRequest;
use App\Services\Ai\AtlasHybridMemoryRetrievalService;
use Illuminate\Http\JsonResponse;

class AtlasMemoryRecallController extends Controller
{
    public function __invoke(RecallAtlasMemoryRequest $request, AtlasHybridMemoryRetrievalService $retrieval): JsonResponse
    {
        $data = $request->validated();

        return response()->json([
            'memory_recall' => $retrieval->recall(
                (string) ($data['query'] ?? ''),
                (array) ($data['context'] ?? []),
                (array) ($data['filters'] ?? []),
                (array) ($data['options'] ?? []),
            ),
        ]);
    }
}
