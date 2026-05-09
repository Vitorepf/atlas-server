<?php

namespace App\Http\Controllers;

use App\Services\Ai\Kernel\Architecture\AtlasExternalGraphHarnessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AtlasAiExternalGraphHarnessController extends Controller
{
    public function __invoke(Request $request, AtlasExternalGraphHarnessService $harness): JsonResponse
    {
        $candidate = $request->isMethod('post') ? $request->input('candidate') : null;

        return response()->json($harness->report(is_array($candidate) ? $candidate : null));
    }
}
