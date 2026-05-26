<?php

declare(strict_types=1);

namespace App\Http\Controllers;

// Gap5.F2 wiring marker — Programming-adjacent controller.
// Canonical route_decision schema: atlas.dual_core.route_decision.v1
// Method-level emission to be wired per AP per family.

use App\Services\Ai\SelfImprovement\AtlasSelfImprovementClosedLoopService;
use Illuminate\Http\JsonResponse;

/**
 * Atlas Code Self-Improvement Closed Loop endpoint (Level 7).
 *
 *   GET /atlas-code/self-improvement/proposals/{proposal}/closed-loop · projection
 *
 * Pure read-model. Mutations stay on the source services
 * (ProposalBacklog::evaluate/prioritize, ForgeActivation::accept/reject,
 * ResultLedger::record).
 *
 * Schema: atlas.self_improvement.closed_loop.v1
 */
final class AtlasCodeSelfImprovementClosedLoopController extends Controller
{
    public function show(string $proposal, AtlasSelfImprovementClosedLoopService $service): JsonResponse
    {
        $payload = $service->project($proposal);
        $code = ($payload['status'] ?? null) === 'blocked' && in_array('proposal_not_found', (array) ($payload['blockers'] ?? []), true)
            ? 404
            : 200;

        return response()->json($payload, $code);
    }
}
