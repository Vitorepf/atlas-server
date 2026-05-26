<?php

namespace App\Http\Controllers;

// Gap5.F2 wiring marker — Programming-adjacent controller.
// Canonical route_decision schema: atlas.dual_core.route_decision.v1
// Method-level emission to be wired per AP per family.

use App\Services\Ai\Programming\Sdd\Mcp\SddResourceCatalog;
use Illuminate\Http\JsonResponse;

class AtlasSddMcpResourceController extends Controller
{
    public function __invoke(SddResourceCatalog $catalog): JsonResponse
    {
        return response()->json($catalog->manifest());
    }
}
