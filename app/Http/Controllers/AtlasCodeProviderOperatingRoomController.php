<?php

declare(strict_types=1);

namespace App\Http\Controllers;

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
    public function __construct(private readonly AtlasCodeProviderOperatingRoomService $service)
    {
    }

    public function show(AtlasProject $project): JsonResponse
    {
        return response()->json([
            'operating_room' => $this->service->snapshotForObra($project),
        ]);
    }
}
