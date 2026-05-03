<?php

namespace App\Http\Controllers;

use App\Http\Requests\RunAtlasMemoryMaintenanceRequest;
use App\Services\Ai\AtlasMemoryMaintenanceService;
use Illuminate\Http\JsonResponse;

class AtlasMemoryMaintenanceController extends Controller
{
    public function __invoke(
        RunAtlasMemoryMaintenanceRequest $request,
        AtlasMemoryMaintenanceService $maintenance,
    ): JsonResponse {
        $data = $request->validated();
        $payload = $maintenance->run([
            'workspace' => $data['workspace'] ?? null,
            'dry_run' => (bool) ($data['dry_run'] ?? false),
            'sync' => (bool) ($data['sync'] ?? true),
            'index_code' => (bool) ($data['index_code'] ?? true),
            'prune' => (bool) ($data['prune'] ?? true),
            'include_drift_audit' => (bool) ($data['include_drift_audit'] ?? false),
            'apply_projection' => (bool) ($data['apply_projection'] ?? false),
            'confirm' => (bool) ($data['confirm'] ?? false),
            'initiator' => 'api',
        ]);

        return response()->json([
            'memory_maintenance' => $payload,
        ], (bool) ($payload['ok'] ?? false) ? 200 : 409);
    }
}
