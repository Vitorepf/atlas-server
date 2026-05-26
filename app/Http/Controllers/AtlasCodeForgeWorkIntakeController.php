<?php

declare(strict_types=1);

namespace App\Http\Controllers;

// Gap5.F2 wiring marker — Programming-adjacent controller.
// Canonical route_decision schema: atlas.dual_core.route_decision.v1
// Method-level emission to be wired per AP per family.

use App\Models\AtlasProject;
use App\Services\Ai\Programming\AtlasCodeForgeWorkIntakeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Atlas Code Forge Work Intake & Spec Governance v1.
 *
 * GET  /atlas-code/works/{project}/forge/intake
 * POST /atlas-code/works/{project}/forge/intake
 *
 * Sem auto-execucao de Forge. Sem provider externo.
 */
final class AtlasCodeForgeWorkIntakeController extends Controller
{
    public function show(AtlasProject $project, AtlasCodeForgeWorkIntakeService $service): JsonResponse
    {
        $intake = $service->get($project);

        return response()->json([
            'schema_version' => 'atlas.code.forge_work_intake_response.v1',
            'work_id' => (string) $project->getKey(),
            'forge_work_intake' => $intake,
        ]);
    }

    public function store(
        Request $request,
        AtlasProject $project,
        AtlasCodeForgeWorkIntakeService $service,
    ): JsonResponse {
        $data = $request->validate([
            'objective' => ['nullable', 'string', 'max:2000'],
            'business_rule' => ['nullable', 'string', 'max:2000'],
            'scope_in' => ['nullable', 'array', 'max:50'],
            'scope_in.*' => ['string', 'max:500'],
            'scope_out' => ['nullable', 'array', 'max:50'],
            'scope_out.*' => ['string', 'max:500'],
            'acceptance_criteria' => ['nullable', 'array', 'max:50'],
            'acceptance_criteria.*' => ['string', 'max:500'],
            'canonical_docs' => ['nullable', 'array', 'max:50'],
            'canonical_docs.*' => ['string', 'max:500'],
            'risk_level' => ['nullable', Rule::in(['low', 'medium', 'high', 'critical'])],
            'expected_outputs' => ['nullable', 'array', 'max:50'],
            'expected_outputs.*' => ['string', 'max:500'],
            'constraints' => ['nullable', 'array', 'max:50'],
            'constraints.*' => ['string', 'max:500'],
            'operator_notes' => ['nullable', 'string', 'max:4000'],
        ]);

        $intake = $service->save($project, $data);
        $statusCode = $intake['readiness_status'] === 'ready' ? 201 : 200;

        return response()->json([
            'schema_version' => 'atlas.code.forge_work_intake_response.v1',
            'work_id' => (string) $project->getKey(),
            'forge_work_intake' => $intake,
        'route_decision' => \App\Services\Ai\DualCore\CanonicalRouteDecisionEnvelope::emit(route: 'programming', reason: 'http_atlas_code_forge_work_intake_controller'),
    ], $statusCode);
    }
}
