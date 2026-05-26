<?php

declare(strict_types=1);

namespace App\Http\Controllers;

// Gap5.F2 wiring marker — Programming-adjacent controller.
// Canonical route_decision schema: atlas.dual_core.route_decision.v1
// Method-level emission to be wired per AP per family.

use App\Services\Ai\SelfImprovement\AtlasSelfImprovementProposalBacklogService;
use App\Services\Ai\SelfImprovement\AtlasSelfImprovementResultLedgerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Atlas Code Self-Improvement Result Ledger endpoints (Level 7).
 *
 *   GET  /atlas-code/self-improvement/result-ledger                              · index
 *   POST /atlas-code/self-improvement/proposals/{proposal}/measure-result        · grava result
 *
 * `measure-result` requires reviewer + reason + before/after snapshots.
 * NEVER promotes completion claim, NEVER runs Fast Path, NEVER calls
 * provider. Updates trust ledger with the canonical self_improvement_*
 * outcome corresponding to the delta grade.
 */
final class AtlasCodeSelfImprovementResultLedgerController extends Controller
{
    public function index(Request $request, AtlasSelfImprovementResultLedgerService $service): JsonResponse
    {
        return response()->json($service->snapshot([
            'grade' => $request->query('grade'),
            'proposal_id' => $request->query('proposal_id'),
        ]), 200);
    }

    public function measureResult(
        Request $request,
        string $proposal,
        AtlasSelfImprovementResultLedgerService $resultLedger,
        AtlasSelfImprovementProposalBacklogService $proposalBacklog,
    ): JsonResponse {
        $payload = $request->input();
        if (! is_array($payload)) {
            $payload = [];
        }
        $payload['proposal_id'] = $proposal;

        $entry = $resultLedger->record($payload);

        // Best-effort: when the entry materialised, mirror the delta grade
        // back into the proposal backlog so the closed-loop view shows the
        // linked result_entry_id without an extra round-trip.
        if (($entry['status'] ?? null) !== 'blocked'
            && isset($entry['result_entry_id'], $entry['delta_grade'])) {
            $proposalBacklog->markDeltaMeasured(
                $proposal,
                (string) $entry['result_entry_id'],
                (string) $entry['delta_grade'],
            );
        }

        $code = ($entry['status'] ?? null) === 'blocked' ? 422 : 201;

        return response()->json($entry, $code);
    }
}
