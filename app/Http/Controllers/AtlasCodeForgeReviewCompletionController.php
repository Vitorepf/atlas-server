<?php

declare(strict_types=1);

namespace App\Http\Controllers;

// Gap5.F2 wiring marker — Programming-adjacent controller.
// Canonical route_decision schema: atlas.dual_core.route_decision.v1

use App\Models\AtlasProject;
use App\Services\Ai\Programming\AtlasCodeForgeReviewCompletionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Atlas Code Forge Review & Completion Gate v1.
 *
 * GET    /atlas-code/works/{project}/forge/fast-path/{run}/review
 * POST   /atlas-code/works/{project}/forge/fast-path/{run}/review/approve
 * POST   /atlas-code/works/{project}/forge/fast-path/{run}/review/reject
 * POST   /atlas-code/works/{project}/forge/fast-path/{run}/review/rollback
 *
 * Fail-closed em run mismatch. Sem auto-completion.
 */
final class AtlasCodeForgeReviewCompletionController extends Controller
{
    public function show(
        AtlasProject $project,
        string $run,
        AtlasCodeForgeReviewCompletionService $service,
    ): JsonResponse {
        $packet = $service->packet($project, $run);
        $claim = $service->completionClaim($project, $run);
        $statusCode = (int) ($packet['http_status'] ?? 200);
        unset($packet['http_status']);

        return response()->json([
            'schema_version' => 'atlas.code.forge_review_completion_response.v1',
            'work_id' => (string) $project->getKey(),
            'review_packet' => $packet,
            'completion_claim' => $claim,
        ], $statusCode);
    }

    public function approve(
        Request $request,
        AtlasProject $project,
        string $run,
        AtlasCodeForgeReviewCompletionService $service,
    ): JsonResponse {
        $data = $request->validate([
            'reviewer' => ['nullable', 'string', 'max:120'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $result = $service->approve($project, $run, $data);

        return $this->reviewResponse($project, $result);
    }

    public function reject(
        Request $request,
        AtlasProject $project,
        string $run,
        AtlasCodeForgeReviewCompletionService $service,
    ): JsonResponse {
        $data = $request->validate([
            'reviewer' => ['nullable', 'string', 'max:120'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $result = $service->reject($project, $run, $data);

        return $this->reviewResponse($project, $result);
    }

    public function rollback(
        Request $request,
        AtlasProject $project,
        string $run,
        AtlasCodeForgeReviewCompletionService $service,
    ): JsonResponse {
        $data = $request->validate([
            'reviewer' => ['nullable', 'string', 'max:120'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $result = $service->rollback($project, $run, $data);

        return $this->reviewResponse($project, $result);
    }

    /**
     * @param  array<string,mixed>  $result
     */
    private function reviewResponse(AtlasProject $project, array $result): JsonResponse
    {
        $status = (string) ($result['status'] ?? 'blocked');
        $statusCode = match ($status) {
            'approved' => 201,
            'rejected' => 201,
            'rolled_back' => 200,
            default => 409,
        };

        return response()->json([
            'schema_version' => 'atlas.code.forge_review_completion_response.v1',
            'work_id' => (string) $project->getKey(),
            'status' => $status,
            'blocker' => $result['blocker'] ?? null,
            'reason' => $result['reason'] ?? null,
            'review_response' => $result['review_response'] ?? null,
            'rollback' => $result['rollback'] ?? null,
            'review_packet' => $result['packet'] ?? null,
            'completion_claim' => $result['completion_claim'] ?? null,
        'route_decision' => \App\Services\Ai\DualCore\CanonicalRouteDecisionEnvelope::emit(route: 'programming', reason: 'http_atlas_code_forge_review_completion_controller'),
    ], $statusCode);
    }
}
