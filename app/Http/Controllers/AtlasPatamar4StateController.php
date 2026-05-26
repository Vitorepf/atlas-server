<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Ai\Patamar4\AtlasPatamar4StateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /atlas/patamar4/state
 *
 * Live aggregator view of the Patamar 4 autonomous loop:
 * Kernel · Admission · CognitiveFunctionAtlas · Reconciliation · TEOS-I4 · Swarm · TDC.
 *
 * Used by desktop/mobile surfaces to render Patamar 4 health honestly.
 */
final class AtlasPatamar4StateController extends Controller
{
    public function __invoke(Request $request, AtlasPatamar4StateService $svc): JsonResponse
    {
        $tail = max(1, min(50, (int) $request->query('tail', 5)));

        return response()->json($svc->snapshot($tail), 200);
    }
}
