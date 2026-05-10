<?php

namespace App\Http\Controllers;

use App\Services\Ai\Kernel\Architecture\AtlasRuntimeLanguageBoundaryReportService;
use Illuminate\Http\JsonResponse;

class AtlasAiRuntimeBoundaryController extends Controller
{
    public function __invoke(AtlasRuntimeLanguageBoundaryReportService $reporter): JsonResponse
    {
        $payload = $reporter->report();

        return response()->json($payload, ($payload['status'] ?? null) === 'ok' ? 200 : 503);
    }
}
