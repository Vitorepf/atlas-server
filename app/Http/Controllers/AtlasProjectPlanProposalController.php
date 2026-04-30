<?php

namespace App\Http\Controllers;

use App\Http\Resources\AtlasProjectPlanProposalResource;
use App\Http\Resources\AtlasProjectResource;
use App\Http\Resources\AtlasTaskResource;
use App\Models\AtlasProject;
use App\Models\AtlasProjectPlanProposal;
use App\Models\Capture;
use App\Services\AtlasDomainRegistry;
use App\Services\ProjectPlanningService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AtlasProjectPlanProposalController extends Controller
{
    public function indexForProject(AtlasProject $project, ProjectPlanningService $planning): JsonResponse
    {
        return response()->json([
            'proposals' => AtlasProjectPlanProposalResource::collection($planning->pendingForProject($project))->resolve(),
        ]);
    }

    public function indexForCapture(Capture $capture, ProjectPlanningService $planning): JsonResponse
    {
        return response()->json([
            'proposals' => AtlasProjectPlanProposalResource::collection($planning->pendingForCapture($capture))->resolve(),
        ]);
    }

    public function proposeForProject(
        Request $request,
        AtlasProject $project,
        AtlasDomainRegistry $domains,
        ProjectPlanningService $planning,
    ): JsonResponse {
        $proposal = $planning->proposeForProject($project->load('sourceCapture'), $this->proposalData($request, $domains), 'projects.plan.propose');

        return (new AtlasProjectPlanProposalResource($proposal))->response()->setStatusCode(201);
    }

    public function proposeForCapture(
        Request $request,
        Capture $capture,
        AtlasDomainRegistry $domains,
        ProjectPlanningService $planning,
    ): JsonResponse {
        $proposal = $planning->proposeForCapture($capture, $this->proposalData($request, $domains), 'captures.project_plan.propose');

        return (new AtlasProjectPlanProposalResource($proposal))->response()->setStatusCode(201);
    }

    public function acceptForProject(
        Request $request,
        AtlasProject $project,
        AtlasProjectPlanProposal $proposal,
        AtlasDomainRegistry $domains,
        ProjectPlanningService $planning,
    ): JsonResponse {
        $this->assertProjectProposal($project, $proposal);

        return $this->acceptedResponse($planning->accept($proposal, $this->acceptData($request, $domains), 'projects.plan.proposal.accept'));
    }

    public function rejectForProject(
        Request $request,
        AtlasProject $project,
        AtlasProjectPlanProposal $proposal,
        ProjectPlanningService $planning,
    ): JsonResponse {
        $this->assertProjectProposal($project, $proposal);
        $proposal = $planning->reject($proposal, $this->decisionData($request), 'projects.plan.proposal.reject');

        return response()->json([
            'proposal' => (new AtlasProjectPlanProposalResource($proposal->load(['project', 'sourceCapture'])))->resolve(),
        ]);
    }

    public function regenerateForProject(
        Request $request,
        AtlasProject $project,
        AtlasProjectPlanProposal $proposal,
        AtlasDomainRegistry $domains,
        ProjectPlanningService $planning,
    ): JsonResponse {
        $this->assertProjectProposal($project, $proposal);
        $proposal = $planning->regenerate($proposal, $this->proposalData($request, $domains), 'projects.plan.proposal.regenerate');

        return (new AtlasProjectPlanProposalResource($proposal))->response()->setStatusCode(201);
    }

    public function accept(
        Request $request,
        AtlasProjectPlanProposal $proposal,
        AtlasDomainRegistry $domains,
        ProjectPlanningService $planning,
    ): JsonResponse {
        return $this->acceptedResponse($planning->accept($proposal, $this->acceptData($request, $domains), 'project_plan_proposals.accept'));
    }

    public function reject(
        Request $request,
        AtlasProjectPlanProposal $proposal,
        ProjectPlanningService $planning,
    ): JsonResponse {
        $proposal = $planning->reject($proposal, $this->decisionData($request), 'project_plan_proposals.reject');

        return response()->json([
            'proposal' => (new AtlasProjectPlanProposalResource($proposal->load(['project', 'sourceCapture'])))->resolve(),
        ]);
    }

    public function regenerate(
        Request $request,
        AtlasProjectPlanProposal $proposal,
        AtlasDomainRegistry $domains,
        ProjectPlanningService $planning,
    ): JsonResponse {
        $proposal = $planning->regenerate($proposal, $this->proposalData($request, $domains), 'project_plan_proposals.regenerate');

        return (new AtlasProjectPlanProposalResource($proposal))->response()->setStatusCode(201);
    }

    private function acceptedResponse(array $result): JsonResponse
    {
        return response()->json([
            'proposal' => (new AtlasProjectPlanProposalResource($result['proposal']->load(['project', 'sourceCapture'])))->resolve(),
            'project' => (new AtlasProjectResource($result['project']->load(['activeNextTask', 'currentStep'])->loadCount(['tasks', 'steps'])))->resolve(),
            'active_next_task' => (new AtlasTaskResource($result['active_next_task']->load(['project', 'projectStep'])))->resolve(),
        ]);
    }

    private function proposalData(Request $request, AtlasDomainRegistry $domains): array
    {
        return $request->validate([
            'title' => ['nullable', 'string', 'max:180'],
            'description' => ['nullable', 'string', 'max:2000'],
            'domain' => ['nullable', 'string', 'max:80', Rule::in($domains->activeSlugs())],
            'goal' => ['nullable', 'string', 'max:1000'],
            'next_action' => ['nullable', 'string', 'max:300'],
            'project_type' => ['nullable', Rule::in(['study', 'technical_build', 'creative', 'business', 'research', 'writing', 'health', 'admin', 'personal', 'tedious', 'routine_candidate'])],
            'desired_outcome' => ['nullable', 'string', 'max:1500'],
            'minimum_viable_outcome' => ['nullable', 'string', 'max:1500'],
            'definition_of_done' => ['nullable', 'string', 'max:1500'],
            'why_now' => ['nullable', 'string', 'max:1000'],
            'deadline_at' => ['nullable', 'date'],
            'deadline_kind' => ['nullable', Rule::in(['real', 'desired', 'artificial', 'none'])],
            'priority' => ['nullable', Rule::in(['low', 'normal', 'high', 'urgent'])],
            'estimated_minutes' => ['nullable', 'integer', 'min:5', 'max:480'],
            'energy_required' => ['nullable', Rule::in(['low', 'medium', 'high'])],
            'instruction' => ['nullable', 'string', 'max:1000'],
            'regeneration_instruction' => ['nullable', 'string', 'max:1000'],
            'metadata' => ['nullable', 'array'],
        ]);
    }

    private function acceptData(Request $request, AtlasDomainRegistry $domains): array
    {
        return $request->validate([
            'title' => ['nullable', 'string', 'max:180'],
            'description' => ['nullable', 'string', 'max:2000'],
            'domain' => ['nullable', 'string', 'max:80', Rule::in($domains->activeSlugs())],
            'goal' => ['nullable', 'string', 'max:1000'],
            'next_action' => ['nullable', 'string', 'max:300'],
            'project_type' => ['nullable', Rule::in(['study', 'technical_build', 'creative', 'business', 'research', 'writing', 'health', 'admin', 'personal', 'tedious', 'routine_candidate'])],
            'desired_outcome' => ['nullable', 'string', 'max:1500'],
            'minimum_viable_outcome' => ['nullable', 'string', 'max:1500'],
            'definition_of_done' => ['nullable', 'string', 'max:1500'],
            'why_now' => ['nullable', 'string', 'max:1000'],
            'deadline_at' => ['nullable', 'date'],
            'deadline_kind' => ['nullable', Rule::in(['real', 'desired', 'artificial', 'none'])],
            'priority' => ['nullable', Rule::in(['low', 'normal', 'high', 'urgent'])],
            'estimated_minutes' => ['nullable', 'integer', 'min:5', 'max:480'],
            'energy_required' => ['nullable', Rule::in(['low', 'medium', 'high'])],
            'metadata' => ['nullable', 'array'],
        ]);
    }

    private function decisionData(Request $request): array
    {
        return $request->validate([
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);
    }

    private function assertProjectProposal(AtlasProject $project, AtlasProjectPlanProposal $proposal): void
    {
        if ($proposal->project_id !== $project->id) {
            abort(404);
        }
    }
}
