<?php

namespace App\Http\Controllers;

use App\Services\Ai\Router\AtlasAiRouterRuntimeBootstrapService;
use Illuminate\Http\JsonResponse;

class AtlasAiRouterRuntimeBootstrapController extends Controller
{
    public function __invoke(AtlasAiRouterRuntimeBootstrapService $bootstrap): JsonResponse
    {
        $payload = $bootstrap->bootstrap();

        return response()->json($payload, ($payload['status'] ?? null) === 'ready' ? 200 : 503);
    }
}
