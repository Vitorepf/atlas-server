<?php

declare(strict_types=1);

namespace App\Http\Controllers\Ai\SoftwareCompanyStewardship;

use App\Http\Controllers\Controller;
use App\Services\Ai\SoftwareCompanyStewardship\ProductMode\ProductModeCockpitSurfaceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Software Company Stewardship · Product Mode cockpit HTTP surface (AP-739).
 *
 * Read-only aggregate for Product Mode/Cockpit. It composes AP-721, AP-736,
 * AP-737, AP-738, AP-740 and AP-741 without recording decisions or executing
 * work.
 */
final class ProductModeCockpitController extends Controller
{
    public function __construct(
        private readonly ProductModeCockpitSurfaceService $surface,
    ) {}

    public function show(Request $request, string $portfolio): JsonResponse
    {
        $input = [];
        foreach (['area', 'area_id', 'pack_id', 'proposal_id'] as $key) {
            $value = $request->query($key);
            if ($value !== null && $value !== '') {
                $input[$key] = (string) $value;
            }
        }

        $body = $this->surface->project($portfolio, $input);

        if (($body['status'] ?? null) === ProductModeCockpitSurfaceService::STATUS_BLOCKED) {
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
