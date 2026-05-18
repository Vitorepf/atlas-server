<?php

namespace App\Http\Controllers;

use App\Services\Ai\ControlPlane\AtlasControlPlaneBlockerService;
use App\Services\Ai\ControlPlane\AtlasControlPlaneMissionService;
use App\Services\Ai\ControlPlane\AtlasControlPlaneNextActionService;
use App\Services\Ai\ControlPlane\AtlasControlPlaneReadinessService;
use App\Services\Ai\ControlPlane\AtlasControlPlaneSnapshotService;
use App\Services\Ai\ControlPlane\AtlasControlPlaneStatus;
use Illuminate\Http\JsonResponse;

final class AtlasAiControlPlaneController extends Controller
{
    public function __construct(
        private readonly AtlasControlPlaneSnapshotService $snapshot,
        private readonly AtlasControlPlaneReadinessService $readiness,
        private readonly AtlasControlPlaneBlockerService $blockers,
        private readonly AtlasControlPlaneNextActionService $nextActions,
        private readonly AtlasControlPlaneMissionService $mission,
    ) {}

    public function index(): JsonResponse
    {
        $payload = $this->snapshot->snapshot();
        $status = (string) ($payload['status'] ?? AtlasControlPlaneStatus::DEGRADED);

        return response()->json($payload, $this->httpStatusFor($status));
    }

    public function readiness(): JsonResponse
    {
        $payload = $this->readiness->report();
        $status = (string) ($payload['status'] ?? AtlasControlPlaneStatus::DEGRADED);

        return response()->json($payload, $this->httpStatusFor($status));
    }

    public function blockers(): JsonResponse
    {
        $payload = $this->blockers->snapshot(50);

        return response()->json($payload);
    }

    public function nextActions(): JsonResponse
    {
        $payload = $this->nextActions->actions();

        return response()->json($payload);
    }

    public function mission(string $uuid): JsonResponse
    {
        $payload = $this->mission->snapshot($uuid);
        if ($payload === null) {
            return response()->json([
                'ok' => false,
                'error' => 'not_found',
                'message' => "mission not found for uuid [{$uuid}]",
            ], 404);
        }
        $status = (string) ($payload['status'] ?? AtlasControlPlaneStatus::DEGRADED);

        return response()->json($payload, $this->httpStatusFor($status));
    }

    private function httpStatusFor(string $componentStatus): int
    {
        return match ($componentStatus) {
            AtlasControlPlaneStatus::READY => 200,
            AtlasControlPlaneStatus::DEGRADED => 200,
            AtlasControlPlaneStatus::MISSING => 200,
            AtlasControlPlaneStatus::BLOCKED => 200,
            default => 200,
        };
    }
}
