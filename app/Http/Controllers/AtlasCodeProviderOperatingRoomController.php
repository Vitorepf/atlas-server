<?php

declare(strict_types=1);

namespace App\Http\Controllers;

// Gap5.F2 wiring marker — Programming-adjacent controller.
// Canonical route_decision schema: atlas.dual_core.route_decision.v1
// Method-level emission to be wired per AP per family.

use App\Models\AtlasProject;
use App\Services\AtlasCode\AtlasCodeProviderOperatingRoomService;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/atlas-code/works/{project}/forge/operating-room
 *
 * Canon: docs/engineering-knowledge-base/atlas-code-adaptive-provider-operating-room-v1.md
 *
 * Returns the aggregated provider operating room read-model for an Obra:
 * governance, provider board, work packets, observed sessions, attention,
 * safety summary and allowed actions.
 */
final class AtlasCodeProviderOperatingRoomController extends Controller
{
    public function __construct(private readonly AtlasCodeProviderOperatingRoomService $service) {}

    public function show(AtlasProject $project): JsonResponse
    {
        return response()->json([
            'operating_room' => $this->service->snapshotForObra($project),
        'route_decision' => \App\Services\Ai\DualCore\CanonicalRouteDecisionEnvelope::emit(route: 'programming', reason: 'http_atlas_code_provider_operating_room_controller'),
    ]);
    }
}
