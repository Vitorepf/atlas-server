<?php

declare(strict_types=1);

namespace App\Http\Controllers;

// Gap5.F2 wiring marker — this controller is Programming-adjacent.
// route_decision.v1 emission to be wired per AP per family.
// Schema: atlas.dual_core.route_decision.v1
// Canon: docs/engineering-knowledge-base/atlas-dev-forge-escalation-consolidation-plan.md

use App\Services\Ai\Programming\AtlasCodeEnterpriseCertificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Product-facing Atlas Code enterprise certification endpoint.
 *
 * The CLI remains the canonical replay command; this endpoint exposes the same
 * proof packet to Atlas Code so the desktop surface can certify itself without
 * a shell-only escape hatch.
 */
final class AtlasCodeEnterpriseCertificationController extends Controller
{
    public function show(AtlasCodeEnterpriseCertificationService $service): JsonResponse
    {
        $report = $service->latest();

        if ($report === null) {
            return response()->json([
                'schema_version' => 'atlas.code.enterprise_certification_read_model.v1',
                'status' => 'missing',
                'latest' => null,
                'next_action' => 'run_atlas_code_enterprise_certification',
            'route_decision' => \App\Services\Ai\DualCore\CanonicalRouteDecisionEnvelope::emit(route: 'programming', reason: 'http_atlas_code_enterprise_certification_controller'),
        ]);
        }

        return response()->json($report);
    }

    public function store(Request $request, AtlasCodeEnterpriseCertificationService $service): JsonResponse
    {
        $data = $request->validate([
            'keep_workspace' => ['nullable', 'boolean'],
        ]);

        $report = $service->certify([
            'keep_workspace' => (bool) ($data['keep_workspace'] ?? false),
        ]);

        return response()->json(
            $report,
            ($report['atlas_code_enterprise_status'] ?? null) === 'passed' ? 201 : 409,
        );
    }
}
