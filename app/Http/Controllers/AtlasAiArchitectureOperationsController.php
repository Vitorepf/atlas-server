<?php

namespace App\Http\Controllers;

use App\Services\Ai\Kernel\Architecture\AtlasArchitectureOperationsCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AtlasAiArchitectureOperationsController extends Controller
{
    public function __invoke(Request $request, AtlasArchitectureOperationsCatalog $catalog): JsonResponse
    {
        $data = $request->validate([
            'id' => ['nullable', 'string', 'max:120'],
            'kind' => ['nullable', 'string', 'max:120'],
        ]);

        return response()->json([
            'status' => 'ok',
            'architecture_operations' => $catalog->summary($data),
        ]);
    }
}
