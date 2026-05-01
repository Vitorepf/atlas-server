<?php

namespace App\Http\Controllers;

use App\Http\Resources\AtlasProjectBlockerResource;
use App\Http\Resources\AtlasProjectResource;
use App\Http\Resources\AtlasTaskResource;
use App\Models\AtlasProject;
use App\Models\AtlasProjectBlocker;
use App\Services\ProjectBlockerService;
use App\Support\ProjectExecutionHealth;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AtlasProjectBlockerController extends Controller
{
    public function index(Request $request, AtlasProject $project): JsonResponse
    {
        $data = $request->validate([
            'status' => ['nullable', Rule::in(AtlasProjectBlocker::STATUSES)],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = $project->blockers()
            ->with(['project', 'task', 'projectStep', 'unblockTask'])
            ->orderByRaw("CASE status WHEN 'open' THEN 0 WHEN 'resolved' THEN 1 ELSE 2 END")
            ->orderByDesc('updated_at');

        if (! empty($data['status'])) {
            $query->where('status', $data['status']);
        }

        return response()->json([
            'blockers' => AtlasProjectBlockerResource::collection(
                $query->limit((int) ($data['limit'] ?? 50))->get()
            )->resolve(),
            'summary' => [
                'open_count' => $project->blockers()->where('status', 'open')->count(),
                'resolved_count' => $project->blockers()->where('status', 'resolved')->count(),
                'cancelled_count' => $project->blockers()->where('status', 'cancelled')->count(),
            ],
            'generated_at' => now()->toJSON(),
        ]);
    }

    public function store(Request $request, AtlasProject $project, ProjectBlockerService $blockers): JsonResponse
    {
        $data = $request->validate($this->rules());
        $blocker = $blockers->createManual($project, $data);

        return response()->json($this->payload($project->refresh(), $blocker), 201);
    }

    public function resolve(
        Request $request,
        AtlasProject $project,
        AtlasProjectBlocker $blocker,
        ProjectBlockerService $blockers,
    ): JsonResponse {
        $this->assertProjectBlocker($project, $blocker);
        $data = $request->validate([
            'resolution_note' => ['nullable', 'string', 'max:1000'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);
        $result = $blockers->resolve($blocker, $data);

        return response()->json($this->payload($project->refresh(), $result['blocker'], $result['unblock_task']));
    }

    public function convertToTask(
        Request $request,
        AtlasProject $project,
        AtlasProjectBlocker $blocker,
        ProjectBlockerService $blockers,
    ): JsonResponse {
        $this->assertProjectBlocker($project, $blocker);
        $data = $request->validate([
            'title' => ['nullable', 'string', 'max:180'],
            'description' => ['nullable', 'string', 'max:2000'],
            'priority' => ['nullable', Rule::in(['low', 'normal', 'high', 'urgent'])],
            'estimated_minutes' => ['nullable', 'integer', 'min:5', 'max:480'],
            'energy_required' => ['nullable', Rule::in(['low', 'medium', 'high'])],
            'starter_step' => ['nullable', 'string', 'max:300'],
            'minimum_viable_action' => ['nullable', 'string', 'max:300'],
        ]);
        $result = $blockers->convertToTask($blocker, $data);

        return response()->json($this->payload($project->refresh(), $result['blocker'], $result['unblock_task']));
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(AtlasProject $project, AtlasProjectBlocker $blocker, mixed $unblockTask = null): array
    {
        $project = $project->load(['activeNextTask', 'currentStep', 'openBlockers'])->loadCount(['tasks', 'steps']);

        return [
            'project' => (new AtlasProjectResource($project))->resolve(),
            'blocker' => (new AtlasProjectBlockerResource($blocker->load(['project', 'task', 'projectStep', 'unblockTask'])))->resolve(),
            'unblock_task' => $unblockTask
                ? (new AtlasTaskResource($unblockTask->load(['project', 'projectStep'])))->resolve()
                : null,
            'health' => ProjectExecutionHealth::for($project),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(): array
    {
        return [
            'task_id' => ['nullable', 'uuid', 'exists:atlas_tasks,id'],
            'project_step_id' => ['nullable', 'uuid', 'exists:atlas_project_steps,id'],
            'severity' => ['nullable', Rule::in(AtlasProjectBlocker::SEVERITIES)],
            'reason_code' => ['nullable', Rule::in(AtlasProjectBlocker::REASON_CODES)],
            'blocker_reason_code' => ['nullable', Rule::in(AtlasProjectBlocker::REASON_CODES)],
            'description' => ['nullable', 'string', 'max:1000'],
            'blocker' => ['nullable', 'string', 'max:500'],
            'reason' => ['nullable', 'string', 'max:500'],
            'note' => ['nullable', 'string', 'max:500'],
            'unblock_next_action' => ['nullable', 'string', 'max:500'],
            'next_hint' => ['nullable', 'string', 'max:500'],
            'waiting_on' => ['nullable', 'string', 'max:180'],
            'due_at' => ['nullable', 'date'],
            'metadata' => ['nullable', 'array'],
        ];
    }

    private function assertProjectBlocker(AtlasProject $project, AtlasProjectBlocker $blocker): void
    {
        if ($blocker->project_id !== $project->id) {
            abort(404);
        }
    }
}
