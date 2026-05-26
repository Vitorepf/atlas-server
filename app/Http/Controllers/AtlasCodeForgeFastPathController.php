<?php

declare(strict_types=1);

namespace App\Http\Controllers;

// Gap5.F2 wiring marker — this controller is Programming-adjacent.
// route_decision.v1 emission to be wired per AP per family.
// Schema: atlas.dual_core.route_decision.v1
// Canon: docs/engineering-knowledge-base/atlas-dev-forge-escalation-consolidation-plan.md

use App\Models\AtlasProject;
use App\Services\Ai\Programming\AtlasCodeForgeFastPathService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Atlas Code Forge Fast Path endpoint.
 *
 * POST /atlas-code/works/{project}/forge/fast-path
 * Doc: docs/engineering-knowledge-base/atlas-code-forge-fast-path-v1.md
 */
final class AtlasCodeForgeFastPathController extends Controller
{
    public function store(
        Request $request,
        AtlasProject $project,
        AtlasCodeForgeFastPathService $service,
    ): JsonResponse {
        $data = $request->validate([
            'mode' => ['nullable', Rule::in([
                AtlasCodeForgeFastPathService::MODE_PREPARE_ONLY,
                AtlasCodeForgeFastPathService::MODE_EXECUTE_ASYNC,
                AtlasCodeForgeFastPathService::MODE_EXECUTE_SYNC,
            ])],
            'intent' => ['nullable', 'string', 'max:2000'],
            'auto_create_work_item' => ['nullable', 'boolean'],
            'auto_compile_spec_plan' => ['nullable', 'boolean'],
            'start_execution' => ['nullable', 'boolean'],
            'create_checkpoint' => ['nullable', 'boolean'],
            'operator_id' => ['nullable', 'string', 'max:120'],
        ]);

        $report = $service->run($project, [
            'obra_id' => (string) $project->getKey(),
            'mode' => $data['mode'] ?? AtlasCodeForgeFastPathService::MODE_EXECUTE_ASYNC,
            'intent' => $data['intent'] ?? null,
            'auto_create_work_item' => $data['auto_create_work_item'] ?? true,
            'auto_compile_spec_plan' => $data['auto_compile_spec_plan'] ?? true,
            'start_execution' => $data['start_execution'] ?? true,
            'create_checkpoint' => $data['create_checkpoint'] ?? false,
            'operator_id' => $data['operator_id'] ?? null,
        ]);

        $statusCode = match ((string) ($report['status'] ?? '')) {
            'blocked' => 409,
            'queued' => 202,
            'passed', 'prepared', 'degraded' => 201,
            default => 200,
        };

        return response()->json($report, $statusCode);
    }
}
