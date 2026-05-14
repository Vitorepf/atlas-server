<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Ai\SelfImprovement\AtlasSelfImprovementProposalBacklogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Atlas Code Self-Improvement Proposal Backlog endpoints (Level 7).
 *
 *   GET  /atlas-code/self-improvement/proposals                       · index
 *   POST /atlas-code/self-improvement/proposals                       · create
 *   GET  /atlas-code/self-improvement/proposals/{proposal}            · show
 *   POST /atlas-code/self-improvement/proposals/{proposal}/evaluate   · power gate evaluation
 *   POST /atlas-code/self-improvement/proposals/{proposal}/prioritize · strategy portfolio bucket
 *
 * Backlog never creates an Obra. The activation endpoints in
 * AtlasCodeSelfImprovementForgeActivationController remain the only
 * source of materialised Obras.
 */
final class AtlasCodeSelfImprovementProposalBacklogController extends Controller
{
    public function index(Request $request, AtlasSelfImprovementProposalBacklogService $service): JsonResponse
    {
        return response()->json($service->listBacklog([
            'status' => $request->query('status'),
            'source' => $request->query('source'),
            'bucket' => $request->query('bucket'),
            'linked_obra' => $this->boolFromQuery($request->query('linked_obra')),
        ]), 200);
    }

    public function store(Request $request, AtlasSelfImprovementProposalBacklogService $service): JsonResponse
    {
        $payload = $request->input();
        if (! is_array($payload)) {
            $payload = [];
        }
        $item = $service->createProposal($payload);
        $statusCode = ($item['blockers'] ?? []) !== [] ? 422 : 201;

        return response()->json($item, $statusCode);
    }

    public function show(string $proposal, AtlasSelfImprovementProposalBacklogService $service): JsonResponse
    {
        $item = $service->getProposal($proposal);
        if ($item === null) {
            return response()->json([
                'schema_version' => AtlasSelfImprovementProposalBacklogService::ITEM_SCHEMA_VERSION,
                'proposal_id' => $proposal,
                'status' => 'blocked',
                'blockers' => ['proposal_not_found'],
            ], 404);
        }

        return response()->json($item, 200);
    }

    public function evaluate(string $proposal, AtlasSelfImprovementProposalBacklogService $service): JsonResponse
    {
        $item = $service->evaluateProposal($proposal);
        if (($item['blockers'] ?? null) === ['proposal_not_found']) {
            return response()->json($item, 404);
        }

        return response()->json($item, 200);
    }

    public function prioritize(
        Request $request,
        string $proposal,
        AtlasSelfImprovementProposalBacklogService $service,
    ): JsonResponse {
        $payload = $request->input();
        if (! is_array($payload)) {
            $payload = [];
        }
        $item = $service->prioritize($proposal, $payload);
        if (($item['blockers'] ?? null) === ['proposal_not_found']) {
            return response()->json($item, 404);
        }

        return response()->json($item, 200);
    }

    private function boolFromQuery(mixed $value): ?bool
    {
        if (! is_string($value)) {
            return null;
        }
        $value = strtolower(trim($value));
        if ($value === '') {
            return null;
        }
        if (in_array($value, ['1', 'true', 'yes'], true)) {
            return true;
        }
        if (in_array($value, ['0', 'false', 'no'], true)) {
            return false;
        }

        return null;
    }
}
