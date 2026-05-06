<?php

namespace App\Http\Controllers;

use App\Services\Ai\Kernel\Architecture\AtlasAiArchitectureValidationService;
use Illuminate\Http\JsonResponse;

class AtlasAiArchitectureValidateController extends Controller
{
    public function __invoke(AtlasAiArchitectureValidationService $validation): JsonResponse
    {
        $payload = $validation->payload();

        return response()->json($payload, $payload['status'] === 'ok' ? 200 : 503);
    }
}
