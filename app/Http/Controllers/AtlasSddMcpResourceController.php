<?php

namespace App\Http\Controllers;

use App\Services\Ai\Programming\Sdd\Mcp\SddResourceCatalog;
use Illuminate\Http\JsonResponse;

class AtlasSddMcpResourceController extends Controller
{
    public function __invoke(SddResourceCatalog $catalog): JsonResponse
    {
        return response()->json($catalog->manifest());
    }
}
