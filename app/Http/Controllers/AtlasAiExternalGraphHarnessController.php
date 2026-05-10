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

        if ($request->isMethod('post') && $request->exists('candidate') && (! is_array($candidate) || array_is_list($candidate))) {
            $report = $harness->report();
            $report['status'] = 'blocked';
            $report['next_action'] = 'submit_external_graph_candidate_as_json_object';
            $report['candidate_validation'] = [
                'schema_version' => 'atlas.external_graph_candidate.validation.v1',
                'status' => 'rejected',
                'mode' => 'validation_only_no_writes',
                'error_count' => 1,
                'warning_count' => 0,
                'errors' => ['candidate_must_be_json_object'],
                'warnings' => [],
                'promotion_allowed' => false,
                'promotion_state' => 'blocked_until_candidate_fixed',
            ];

            return response()->json($report, 422);
        }

        return response()->json($harness->report(is_array($candidate) ? $candidate : null));
    }
}
