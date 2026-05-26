<?php

declare(strict_types=1);

namespace App\Http\Controllers;

// Gap5.F2 wiring marker — Programming-adjacent controller.
// Canonical route_decision schema: atlas.dual_core.route_decision.v1
// Method-level emission to be wired per AP per family.

use App\Services\AtlasCode\AtlasCodeProviderGovernanceService;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/atlas-code/providers/governance
 *
 * Canon: docs/engineering-knowledge-base/atlas-claude-code-subscription-governance-v1.md
 *
 * Read-only. Exposes the subscription-only contract so the desktop UI can
 * render the safety strip and gate any external-provider invocation client-
 * side. Backend services consult AtlasCodeProviderGovernanceService directly.
 */
final class AtlasCodeProviderGovernanceController extends Controller
{
    public function __construct(private readonly AtlasCodeProviderGovernanceService $governance) {}

    public function show(): JsonResponse
    {
        return response()->json([
            'governance' => $this->governance->snapshot(),
        'route_decision' => \App\Services\Ai\DualCore\CanonicalRouteDecisionEnvelope::emit(route: 'programming', reason: 'http_atlas_code_provider_governance_controller'),
    ]);
    }
}
