<?php

namespace App\Http\Controllers;

use App\Services\Ai\Kernel\Decision\DynamicComputeMarketReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AtlasAiDynamicComputeMarketController extends Controller
{
    public function __invoke(Request $request, DynamicComputeMarketReportService $reports): JsonResponse
    {
        $data = $request->validate([
            'provider' => ['required', 'string', 'max:120'],
            'model' => ['nullable', 'string', 'max:160'],
            'domain' => ['nullable', 'string', 'max:120'],
            'flow' => ['nullable', 'string', 'max:160'],
            'task_type' => ['nullable', 'string', 'max:120'],
            'specialist_profile' => ['nullable', 'string', 'max:160'],
        ]);
        $payload = $reports->report($data);

        return response()->json($payload, ($payload['status'] ?? null) === 'ok' ? 200 : 503);
    }
}
