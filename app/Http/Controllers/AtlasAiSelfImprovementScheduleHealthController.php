<?php

namespace App\Http\Controllers;

use App\Services\Ai\SelfImprovement\AtlasSelfImprovementScheduleService;
use Illuminate\Http\JsonResponse;

class AtlasAiSelfImprovementScheduleHealthController extends Controller
{
    public function __invoke(AtlasSelfImprovementScheduleService $schedule): JsonResponse
    {
        return response()->json($schedule->scheduleHealth());
    }
}
