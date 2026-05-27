<?php

declare(strict_types=1);

namespace App\Http\Controllers\Ai\SoftwareCompanyStewardship;

use App\Http\Controllers\Controller;
use App\Services\Ai\SoftwareCompanyStewardship\AutonomousExecutive\ExecutiveDecisionInboxSurfaceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Autonomous Executive · Decision Inbox HTTP surface (AP-736).
 *
 * Read-only cockpit-ready projection. It never records decisions or executes
 * work; decisions still go through AP-731/AP-735 CLI/service anchors.
 */
final class ExecutiveDecisionInboxController extends Controller
{
    public function __construct(
        private readonly ExecutiveDecisionInboxSurfaceService $surface,
    ) {}

    public function show(Request $request, string $portfolio): JsonResponse
    {
        $input = [];
        if (($packId = $request->query('pack_id')) !== null && $packId !== '') {
            $input['pack_id'] = (string) $packId;
        }

        $body = $this->surface->project($portfolio, $input);

        if (($body['status'] ?? null) === ExecutiveDecisionInboxSurfaceService::STATUS_BLOCKED) {
            return response()->json($body, 409);
        }

        $hash = (string) ($body['surface_hash'] ?? hash('sha256', json_encode($body) ?: ''));
        $etag = '"'.$hash.'"';
        if ((string) $request->header('If-None-Match', '') === $etag) {
            return response()->json(null, 304, ['ETag' => $etag]);
        }

        return response()->json($body, 200, ['ETag' => $etag, 'Cache-Control' => 'private, max-age=5']);
    }
}
