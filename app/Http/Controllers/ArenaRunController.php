<?php

namespace App\Http\Controllers;

use App\Services\Ai\Arena\ArenaCapabilityProfileService;
use App\Services\Ai\Arena\ArenaCompositeService;
use App\Services\Ai\Arena\ArenaMeasurementControlService;
use App\Services\Ai\Arena\ArenaReportService;
use App\Services\Ai\Arena\ArenaRunsLiveService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ArenaRunController extends Controller
{
    public function composite(ArenaCompositeService $service): JsonResponse
    {
        return response()->json($service->composite());
    }

    public function scoreboard(ArenaCompositeService $service): JsonResponse
    {
        return response()->json($service->scoreboard());
    }

    public function capabilities(Request $request, ArenaCapabilityProfileService $service): JsonResponse
    {
        $engine = $request->query('engine');

        return response()->json($service->profile(is_string($engine) ? $engine : null));
    }

    public function live(ArenaRunsLiveService $service): JsonResponse
    {
        return response()->json($service->live());
    }

    public function engines(ArenaRunsLiveService $service): JsonResponse
    {
        return response()->json($service->engines());
    }

    public function report(ArenaReportService $service): JsonResponse
    {
        $result = $service->report();

        return response()->json($result['payload'], $result['status_code']);
    }

    public function store(Request $request, ArenaRunsLiveService $service): JsonResponse
    {
        $result = $service->start($request->all());

        return response()->json($result['payload'], $result['status_code']);
    }

    public function stop(
        string $measurement,
        Request $request,
        ArenaMeasurementControlService $service
    ): JsonResponse {
        $result = $service->stop($measurement, $request->all());

        return response()->json($result['payload'], $result['status_code']);
    }
}
