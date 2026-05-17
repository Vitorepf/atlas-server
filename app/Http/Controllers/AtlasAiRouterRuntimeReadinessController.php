<?php

namespace App\Http\Controllers;

use App\Services\Ai\Router\AtlasAiRouterRuntimeReadinessService;
use Illuminate\Http\JsonResponse;

class AtlasAiRouterRuntimeReadinessController extends Controller
{
    public function __invoke(AtlasAiRouterRuntimeReadinessService $readiness): JsonResponse
    {
        $payload = $readiness->inspect();

        return response()->json($payload, ($payload['status'] ?? null) === 'passed' ? 200 : 503);
    }
}
