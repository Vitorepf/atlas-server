<?php

namespace App\Http\Controllers;

use App\Services\Ai\Router\AtlasAiHyperflowCertificationService;
use Illuminate\Http\JsonResponse;

class AtlasAiHyperflowCertificationController extends Controller
{
    public function __invoke(AtlasAiHyperflowCertificationService $certification): JsonResponse
    {
        $payload = $certification->certify();

        return response()->json($payload, ($payload['status'] ?? null) === 'passed' ? 200 : 503);
    }
}
