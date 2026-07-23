<?php

namespace App\Http\Controllers;

use App\Http\Requests\RunAtlasMemoryMaintenanceRequest;
use App\Services\Ai\Memory\AtlasMemoryMaintenanceService;
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
            'promote_learnings' => (bool) ($data['promote_learnings'] ?? true),
            'auto_promote_candidates' => (bool) ($data['auto_promote_candidates'] ?? false),
            'promotion_limit' => (int) ($data['promotion_limit'] ?? 50),
            'promotion_min_confidence' => (float) ($data['promotion_min_confidence'] ?? 0.86),
            'record_quality_snapshot' => (bool) ($data['record_quality_snapshot'] ?? true),
            'enforce_quality' => (bool) ($data['enforce_quality'] ?? false),
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
