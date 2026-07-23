<?php

namespace App\Http\Controllers\Ai;

use App\Http\Controllers\Controller;
use App\Models\AiTrace;
use App\Services\Ai\ControlPlane\AiInteractionSteeringService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AiInteractionSteerController extends Controller
{
    public function __invoke(
        AiTrace $trace,
        Request $request,
        AiInteractionSteeringService $steering,
    ): JsonResponse {
        $result = $steering->steer(
            $trace,
            $request->input('instruction'),
            $request->input('scope'),
        );

        return response()->json($result['body'], $result['status']);
    }
}
