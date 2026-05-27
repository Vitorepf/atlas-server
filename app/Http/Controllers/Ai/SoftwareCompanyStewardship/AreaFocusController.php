<?php

declare(strict_types=1);

namespace App\Http\Controllers\Ai\SoftwareCompanyStewardship;

use App\Http\Controllers\Controller;
use App\Services\Ai\NightShift\AreaFocusLoopReadModelService;
use App\Services\Ai\SoftwareCompany\AreaFocusProductModeSurfaceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Area Focus · Product Mode HTTP read surface (AP-721).
 *
 * Atlas Software Company Stewardship Stack is a stack/capability family inside
 * the Atlas Autonomous Software Company Runtime, not a new OS.
 *
 * Read-only. The GET runs no mutating cycle: no execution, no branch, no
 * provider, no merge/deploy/secrets. ETag based on the deterministic surface
 * hash. Unknown area returns a stable 404.
 */
final class AreaFocusController extends Controller
{
    public function __construct(
        private readonly AreaFocusProductModeSurfaceService $surface,
    ) {}

    public function show(Request $request, string $area): JsonResponse
    {
        $input = [];
        if (($hours = $request->query('hours')) !== null && $hours !== '') {
            $input['hours'] = (int) $hours;
        }
        if (($limit = $request->query('limit')) !== null && $limit !== '') {
            $input['limit'] = (int) $limit;
        }

        $body = $this->surface->project($area, $input);

        if (($body['status'] ?? null) === AreaFocusLoopReadModelService::STATUS_BLOCKED
            && ($body['reason'] ?? null) === 'unknown_area') {
            return response()->json([
                'error' => [
                    'code' => 'unknown_area',
                    'message' => (string) ($body['detail'] ?? "Area '{$area}' is not supported."),
                    'supported_areas' => array_values((array) ($body['supported_areas'] ?? [])),
                ],
            ], 404);
        }

        $hash = (string) ($body['surface_hash'] ?? hash('sha256', json_encode($body) ?: ''));
        $etag = '"'.$hash.'"';
        if ((string) $request->header('If-None-Match', '') === $etag) {
            return response()->json(null, 304, ['ETag' => $etag]);
        }

        return response()->json($body, 200, ['ETag' => $etag, 'Cache-Control' => 'private, max-age=5']);
    }
}
