<?php

namespace App\Http\Controllers;

use App\Http\Requests\AcceptSemanticCurationProposalRequest;
use App\Http\Requests\IndexSemanticCurationProposalRequest;
use App\Http\Resources\SemanticCurationProposalResource;
use App\Http\Resources\SemanticNoteResource;
use App\Models\SemanticCurationProposal;
use App\Services\Semantic\CurationProposalService;
use Illuminate\Http\JsonResponse;

class SemanticCurationProposalController extends Controller
{
    public function index(IndexSemanticCurationProposalRequest $request): JsonResponse
    {
        $data = $request->validated();
        $query = SemanticCurationProposal::query()
            ->orderByDesc('score')
            ->orderByDesc('created_at')
            ->limit((int) ($data['limit'] ?? 30));

        if (! empty($data['status'])) {
            $query->where('status', $data['status']);
        } else {
            $query->where('status', 'pending');
        }

        return response()->json([
            'proposals' => SemanticCurationProposalResource::collection($query->get())->resolve(),
        ]);
    }

    public function accept(
        AcceptSemanticCurationProposalRequest $request,
        SemanticCurationProposal $proposal,
        CurationProposalService $service,
    ): JsonResponse {
        $note = $service->accept($proposal, $request->validated());

        return response()->json([
            'proposal' => (new SemanticCurationProposalResource($proposal->refresh()))->resolve(),
            'note' => (new SemanticNoteResource($note))->resolve(),
        ]);
    }

    public function dismiss(SemanticCurationProposal $proposal, CurationProposalService $service): SemanticCurationProposalResource
    {
        $service->dismiss($proposal);

        return new SemanticCurationProposalResource($proposal->refresh());
    }

    public function postpone(SemanticCurationProposal $proposal, CurationProposalService $service): SemanticCurationProposalResource
    {
        $service->postpone($proposal);

        return new SemanticCurationProposalResource($proposal->refresh());
    }
}
