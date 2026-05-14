<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Ai\SelfImprovement\AtlasSelfImprovementNextCycleRecommendationService;
use App\Services\Ai\SelfImprovement\AtlasSelfImprovementProposalBacklogService;
use App\Services\Ai\SelfImprovement\AtlasSelfImprovementResultLedgerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Atlas Code Self-Improvement Next Cycle Recommendation endpoint (Level 7).
 *
 *   GET /atlas-code/self-improvement/next-cycle-recommendations
 *     · query: proposal_id=<id>  → recommendation for that proposal
 *     · query: latest=true       → recommendation derived from the most
 *                                   recent result entry overall
 *
 * Never creates a proposal automatically. Returns a payload draft the
 * operator must explicitly submit via the backlog `store` endpoint.
 */
final class AtlasCodeSelfImprovementNextCycleController extends Controller
{
    public function index(
        Request $request,
        AtlasSelfImprovementNextCycleRecommendationService $nextCycle,
        AtlasSelfImprovementProposalBacklogService $proposalBacklog,
        AtlasSelfImprovementResultLedgerService $resultLedger,
    ): JsonResponse {
        $proposalId = $request->query('proposal_id');
        $latest = $request->boolean('latest', false);

        $resultEntry = null;
        $proposal = null;

        if (is_string($proposalId) && $proposalId !== '') {
            $entries = $resultLedger->listForProposal($proposalId);
            $resultEntry = $entries[0] ?? null;
            $proposal = $proposalBacklog->getProposal($proposalId);
        } elseif ($latest) {
            $snap = $resultLedger->snapshot([]);
            $resultEntry = ($snap['entries'][0] ?? null);
            if (is_array($resultEntry) && isset($resultEntry['proposal_id'])) {
                $proposal = $proposalBacklog->getProposal((string) $resultEntry['proposal_id']);
            }
        }

        return response()->json($nextCycle->recommend($resultEntry, $proposal), 200);
    }
}
