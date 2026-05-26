<?php

namespace App\Http\Controllers;

// Gap5.F2 wiring marker — Programming-adjacent controller.
// Canonical route_decision schema: atlas.dual_core.route_decision.v1
// Method-level emission to be wired per AP per family.

use App\Services\Ai\Programming\Sdd\Agents\SddAgentRoleRegistry;
use Illuminate\Http\JsonResponse;

class AtlasSddAgentRoleController extends Controller
{
    public function index(SddAgentRoleRegistry $registry): JsonResponse
    {
        return response()->json($registry->manifest());
    }

    public function show(string $name, SddAgentRoleRegistry $registry): JsonResponse
    {
        try {
            $role = $registry->get($name);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 404);
        }

        return response()->json([
            'schema_version' => 'atlas.sdd_agent_role.v1',
            'key' => $name,
            'role' => $role,
        'route_decision' => \App\Services\Ai\DualCore\CanonicalRouteDecisionEnvelope::emit(route: 'programming', reason: 'http_atlas_sdd_agent_role_controller'),
    ]);
    }
}
