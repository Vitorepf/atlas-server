<?php

namespace App\Http\Controllers;

// Gap5.F2 wiring marker — Programming-adjacent controller.
// Canonical route_decision schema: atlas.dual_core.route_decision.v1

use App\Http\Resources\AtlasProjectEventResource;
use App\Http\Resources\AtlasProjectResource;
use App\Http\Resources\AtlasProjectStepResource;
use App\Http\Resources\AtlasTaskResource;
use App\Models\AtlasProject;
use App\Models\AtlasProjectStep;
use App\Services\AtlasDomainRegistry;
use App\Services\ProjectExecutionService;
use App\Support\ProjectExecutionHealth;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Validation\Rule;

class AtlasProjectController extends Controller
{
    public function index(Request $request, AtlasDomainRegistry $domains): JsonResponse
    {
        $data = $request->validate([
            'domain' => ['nullable', 'string', 'max:80', Rule::in($domains->activeSlugs())],
            'status' => ['nullable', 'string', 'max:40'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $query = AtlasProject::query()
            ->with($this->projectRelations())
            ->withCount('tasks')
            ->withCount('steps')
            ->orderByDesc('updated_at');
        if (! empty($data['domain'])) {
            $query->where('domain', $data['domain']);
        }
        if (! empty($data['status'])) {
            $query->where('status', $data['status']);
        }

        return response()->json([
            'projects' => AtlasProjectResource::collection($query->limit((int) ($data['limit'] ?? 80))->get())->resolve(),
        ]);
    }

    public function reviewQueue(Request $request, AtlasDomainRegistry $domains, ProjectExecutionService $execution): JsonResponse
    {
        $data = $request->validate([
            'domain' => ['nullable', 'string', 'max:80', Rule::in($domains->activeSlugs())],
            'mode' => ['nullable', Rule::in(['attention', 'all'])],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $mode = $data['mode'] ?? 'attention';
        $limit = (int) ($data['limit'] ?? 20);

        $query = AtlasProject::query()
            ->with($this->projectRelations())
            ->withCount(['tasks', 'steps'])
            ->whereNotIn('status', ['completed', 'archived'])
            ->orderByDesc('updated_at')
            ->limit(200);
        if (! empty($data['domain'])) {
            $query->where('domain', $data['domain']);
        }

        $items = $query->get()
            ->map(function (AtlasProject $project) use ($execution): array {
                $health = ProjectExecutionHealth::for($project);

                return [
                    'project' => $project,
                    'health' => $health,
                    'suggestion' => $execution->reviewSuggestion($project, $health),
                    'review_score' => $this->reviewScore($project, $health),
                ];
            })
            ->filter(fn (array $item): bool => $mode === 'all'
                || ($item['health']['status'] ?? null) === 'attention'
                || (bool) ($item['health']['review_due'] ?? false)
            )
            ->sortByDesc('review_score')
            ->take($limit)
            ->values();

        return response()->json([
            'items' => $items->map(fn (array $item): array => [
                'project' => (new AtlasProjectResource($item['project']))->resolve(),
                'health' => $item['health'],
                'suggestion' => $item['suggestion'],
                'review_score' => $item['review_score'],
            ])->all(),
            'summary' => [
                'count' => $items->count(),
                'blocked_count' => $items->filter(fn (array $item): bool => in_array('blocked', (array) ($item['health']['reasons'] ?? []), true))->count(),
                'missing_next_action_count' => $items->filter(fn (array $item): bool => in_array('missing_next_action', (array) ($item['health']['reasons'] ?? []), true))->count(),
                'review_due_count' => $items->filter(fn (array $item): bool => (bool) ($item['health']['review_due'] ?? false))->count(),
            ],
            'generated_at' => now()->toJSON(),
        ]);
    }

    public function store(Request $request, AtlasDomainRegistry $domains, ProjectExecutionService $execution): JsonResponse
    {
        $data = $request->validate($this->rules($domains, true));

        // Atlas Code MVP wrap (passo-3): atlas-desktop posts {intent, objective}
        // instead of {title, description}. Coerce so existing projects.store
        // logic stays the same shape.
        $intent = trim((string) ($data['intent'] ?? ''));
        $objective = trim((string) ($data['objective'] ?? ''));
        $title = trim((string) ($data['title'] ?? $objective));
        if ($title === '') {
            $title = 'Projeto Atlas';
        }
        $description = $data['description'] ?? ($intent !== '' ? $intent : null);

        $plan = $execution->inferPlan(null, $data, $title);

        $project = AtlasProject::query()->create([
            'title' => $title,
            'description' => $description,
            'status' => $data['status'] ?? 'active',
            'domain' => $data['domain'] ?? $domains->defaultSlug(),
            'source_capture_id' => $data['source_capture_id'] ?? null,
            'goal' => $data['goal'] ?? $plan['desired_outcome'],
            'next_action' => $plan['next_action'],
            'project_type' => $data['project_type'] ?? $plan['project_type'],
            'desired_outcome' => $data['desired_outcome'] ?? $plan['desired_outcome'],
            'minimum_viable_outcome' => $data['minimum_viable_outcome'] ?? $plan['minimum_viable_outcome'],
            'definition_of_done' => $data['definition_of_done'] ?? $plan['definition_of_done'],
            'why_now' => $data['why_now'] ?? $plan['why_now'],
            'deadline_at' => $data['deadline_at'] ?? $plan['deadline_at'],
            'deadline_kind' => $data['deadline_kind'] ?? $plan['deadline_kind'],
            'priority' => $data['priority'] ?? $plan['priority'],
            'energy_profile' => $data['energy_profile'] ?? $plan['energy_profile'],
            'avoidance_reason' => $data['avoidance_reason'] ?? $plan['avoidance_reason'],
            'last_touched_at' => now(),
            'next_review_at' => $data['next_review_at'] ?? now()->addDays(3),
            'metadata' => [
                ...($data['metadata'] ?? []),
                'created_from' => $intent !== '' || $objective !== '' ? 'atlas-code.projects.store' : 'projects.store',
                'process_steps' => $plan['process_steps'],
                'atlas_code' => array_filter([
                    'intent' => $intent !== '' ? $intent : null,
                    'objective' => $objective !== '' ? $objective : null,
                ]),
            ],
        ]);

        $execution->recordEvent($project, 'created', [
            'title' => $project->title,
            'project_type' => $project->project_type,
            'priority' => $project->priority,
        ], 'projects.store');
        $task = $execution->ensureNextActionTask($project, null, $data, $plan, 'projects.store');

        return response()->json([
            'project' => (new AtlasProjectResource($project->refresh()->load($this->projectRelations())->loadCount(['tasks', 'steps'])))->resolve(),
            'active_next_task' => (new AtlasTaskResource($task->load(['project', 'projectStep'])))->resolve(),
            // Atlas Code MVP wrap: surface receipt_id slot so the desktop can
            // immediately request the receipt for this obra. The Kernel mints
            // the actual decision asynchronously; this is the placeholder ID
            // the UI uses until the real receipt arrives via stream.
            'receipt_id' => 'pending:'.$project->getKey(),
        ], 201);
    }

    public function show(AtlasProject $project): AtlasProjectResource
    {
        return new AtlasProjectResource($project->load($this->projectRelations())->loadCount(['tasks', 'steps']));
    }

    public function update(Request $request, AtlasProject $project, AtlasDomainRegistry $domains, ProjectExecutionService $execution): AtlasProjectResource
    {
        $data = $request->validate($this->rules($domains, false));
        $status = $data['status'] ?? null;
        unset($data['status']);
        $transitionData = $data;
        $virtualKeys = ['completion_outcome', 'completion_evidence', 'completion_note', 'force_completion'];
        foreach ($virtualKeys as $key) {
            unset($data[$key]);
        }
        if ($status === 'completed') {
            unset($data['completed_at']);
            unset($data['metadata']);
        }

        if ($data !== []) {
            $project->update($data);
            $changes = $project->getChanges();
            unset($changes['updated_at']);
        } else {
            $changes = [];
        }

        if ($changes !== []) {
            $execution->recordEvent($project, 'updated', [
                'changes' => $changes,
                'source' => 'projects.update',
            ]);
        }

        if (is_string($status) && $status !== '') {
            $execution->transitionStatus($project->refresh(), $status, $transitionData, 'projects.update');
        }

        if (array_key_exists('next_action', $data) && trim((string) $data['next_action']) !== '') {
            $plan = $execution->inferPlan(null, $data, $project->title);
            $execution->ensureNextActionTask($project->refresh(), null, $data, $plan, 'projects.update');
        }

        return new AtlasProjectResource($project->refresh()->load($this->projectRelations())->loadCount(['tasks', 'steps']));
    }

    public function events(Request $request, AtlasProject $project): JsonResponse
    {
        $data = $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return response()->json([
            'events' => AtlasProjectEventResource::collection(
                $project->events()
                    ->latest('occurred_at')
                    ->limit((int) ($data['limit'] ?? 30))
                    ->get()
            )->resolve(),
        ]);
    }

    public function execution(Request $request, AtlasProject $project, ProjectExecutionService $execution): JsonResponse
    {
        $data = $request->validate([
            'available_minutes' => ['nullable', 'integer', 'min:5', 'max:240'],
            'energy_level' => ['nullable', 'integer', 'min:1', 'max:5'],
            'environment' => ['nullable', 'string', 'max:120'],
        ]);
        $project = $project->load($this->projectRelations())->loadCount(['tasks', 'steps']);

        return response()->json([
            'project' => (new AtlasProjectResource($project))->resolve(),
            'active_next_task' => $project->activeNextTask
                ? (new AtlasTaskResource($project->activeNextTask->load(['project', 'projectStep'])))->resolve()
                : null,
            'packet' => $execution->executionPacket($project, null, $data),
        ]);
    }

    public function startExecution(Request $request, AtlasProject $project, ProjectExecutionService $execution): JsonResponse
    {
        $data = $request->validate([
            'available_minutes' => ['nullable', 'integer', 'min:5', 'max:240'],
            'energy_level' => ['nullable', 'integer', 'min:1', 'max:5'],
            'environment' => ['nullable', 'string', 'max:120'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);
        $result = $execution->startExecution($project->load(['activeNextTask', 'currentStep']), $data, 'projects.execution.start');
        $project = $result['project']->load($this->projectRelations())->loadCount(['tasks', 'steps']);

        return response()->json([
            'project' => (new AtlasProjectResource($project))->resolve(),
            'active_next_task' => $result['task']
                ? (new AtlasTaskResource($result['task']->load(['project', 'projectStep'])))->resolve()
                : ($project->activeNextTask ? (new AtlasTaskResource($project->activeNextTask->load(['project', 'projectStep'])))->resolve() : null),
            'packet' => $result['packet'],
        ]);
    }

    public function recover(Request $request, AtlasProject $project, ProjectExecutionService $execution): JsonResponse
    {
        $data = $request->validate([
            'available_minutes' => ['nullable', 'integer', 'min:5', 'max:240'],
            'target_minutes' => ['nullable', 'integer', 'min:5', 'max:45'],
            'next_action' => ['nullable', 'string', 'max:180'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);
        $result = $execution->recoverProject($project->load(['activeNextTask', 'currentStep']), $data, 'projects.recover');
        $project = $result['project']->load($this->projectRelations())->loadCount(['tasks', 'steps']);

        return response()->json([
            'project' => (new AtlasProjectResource($project))->resolve(),
            'active_next_task' => (new AtlasTaskResource($result['task']->load(['project', 'projectStep'])))->resolve(),
            'packet' => $result['packet'],
        ]);
    }

    public function reviewAction(Request $request, AtlasProject $project, ProjectExecutionService $execution): JsonResponse
    {
        $data = $request->validate([
            'action' => ['nullable', Rule::in(['mark_reviewed', 'postpone', 'ensure_next_action', 'rebuild_plan', 'reactivate', 'recover'])],
            'review_interval_days' => ['nullable', 'integer', 'min:1', 'max:30'],
            'next_action' => ['nullable', 'string', 'max:300'],
            'goal' => ['nullable', 'string', 'max:1000'],
            'priority' => ['nullable', Rule::in(['low', 'normal', 'high', 'urgent'])],
            'estimated_minutes' => ['nullable', 'integer', 'min:5', 'max:480'],
            'target_minutes' => ['nullable', 'integer', 'min:5', 'max:45'],
            'energy_required' => ['nullable', Rule::in(['low', 'medium', 'high'])],
            'note' => ['nullable', 'string', 'max:500'],
            'metadata' => ['nullable', 'array'],
        ]);

        $result = $execution->reviewProject($project->load(['activeNextTask', 'currentStep']), $data, 'projects.review');
        $project = $result['project']->load($this->projectRelations())->loadCount(['tasks', 'steps']);

        return response()->json([
            'project' => (new AtlasProjectResource($project))->resolve(),
            'active_next_task' => $result['task']
                ? (new AtlasTaskResource($result['task']->load(['project', 'projectStep'])))->resolve()
                : ($project->activeNextTask ? (new AtlasTaskResource($project->activeNextTask->load(['project', 'projectStep'])))->resolve() : null),
            'health' => ProjectExecutionHealth::for($project),
            'suggestion' => $execution->reviewSuggestion($project, ProjectExecutionHealth::for($project)),
        ]);
    }

    public function steps(Request $request, AtlasProject $project): JsonResponse
    {
        $data = $request->validate([
            'status' => ['nullable', Rule::in(['pending', 'active', 'done', 'skipped', 'blocked'])],
        ]);

        $query = $project->steps()
            ->with('activeTask')
            ->orderBy('step_order');
        if (! empty($data['status'])) {
            $query->where('status', $data['status']);
        }

        return response()->json([
            'steps' => AtlasProjectStepResource::collection($query->get())->resolve(),
        ]);
    }

    public function plan(Request $request, AtlasProject $project, ProjectExecutionService $execution): JsonResponse
    {
        $data = $request->validate([
            'replace' => ['nullable', 'boolean'],
            'next_action' => ['nullable', 'string', 'max:300'],
            'goal' => ['nullable', 'string', 'max:1000'],
            'priority' => ['nullable', Rule::in(['low', 'normal', 'high', 'urgent'])],
            'metadata' => ['nullable', 'array'],
        ]);
        $plan = $execution->inferPlan(null, $data, $project->title);
        $steps = $execution->ensureProjectPlan($project, $plan, (bool) ($data['replace'] ?? false), 'projects.plan');
        $task = $execution->ensureNextActionTask($project->refresh(), null, $data, $plan, 'projects.plan');

        return response()->json([
            'project' => (new AtlasProjectResource($project->refresh()->load($this->projectRelations())->loadCount(['tasks', 'steps'])))->resolve(),
            'steps' => AtlasProjectStepResource::collection($steps)->resolve(),
            'active_next_task' => (new AtlasTaskResource($task->load(['project', 'projectStep'])))->resolve(),
        ]);
    }

    public function updateStep(Request $request, AtlasProject $project, AtlasProjectStep $step, ProjectExecutionService $execution): JsonResponse
    {
        $this->assertProjectStep($project, $step);

        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:180'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'status' => ['sometimes', Rule::in(['pending', 'active', 'done', 'skipped', 'blocked'])],
            'step_type' => ['sometimes', Rule::in(['phase', 'action', 'milestone', 'review'])],
            'expected_output' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'acceptance_criteria' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'estimated_minutes' => ['sometimes', 'integer', 'min:5', 'max:480'],
            'energy_required' => ['sometimes', Rule::in(['low', 'medium', 'high'])],
            'friction_level' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'metadata' => ['sometimes', 'array'],
        ]);

        $status = $data['status'] ?? null;
        unset($data['status']);

        if ($data !== []) {
            $step->update($data);
        }

        $task = null;
        if ($status === 'active') {
            $task = $execution->activateStep($project->refresh(), $step->refresh(), 'projects.steps.update');
        } elseif ($status === 'blocked') {
            $execution->blockStep($project->refresh(), $step->refresh(), 'projects.steps.update');
        } elseif ($status === 'pending') {
            $execution->reopenStep($project->refresh(), $step->refresh(), 'projects.steps.update');
        } elseif (in_array($status, ['done', 'skipped'], true)) {
            $task = $execution->closeStepAndAdvance($project->refresh(), $step->refresh(), $status, 'projects.steps.update');
        }

        return response()->json([
            'project' => (new AtlasProjectResource($project->refresh()->load($this->projectRelations())->loadCount(['tasks', 'steps'])))->resolve(),
            'step' => (new AtlasProjectStepResource($step->refresh()->load('activeTask')))->resolve(),
            'active_next_task' => $task
                ? (new AtlasTaskResource($task->load(['project', 'projectStep'])))->resolve()
                : ($project->activeNextTask ? (new AtlasTaskResource($project->activeNextTask->load(['project', 'projectStep'])))->resolve() : null),
        ]);
    }

    public function activateStep(AtlasProject $project, AtlasProjectStep $step, ProjectExecutionService $execution): JsonResponse
    {
        $this->assertProjectStep($project, $step);

        $task = $execution->activateStep($project->refresh(), $step->refresh(), 'projects.steps.activate');

        return response()->json([
            'project' => (new AtlasProjectResource($project->refresh()->load($this->projectRelations())->loadCount(['tasks', 'steps'])))->resolve(),
            'step' => (new AtlasProjectStepResource($step->refresh()->load('activeTask')))->resolve(),
            'active_next_task' => (new AtlasTaskResource($task->load(['project', 'projectStep'])))->resolve(),
        ]);
    }

    public function nextAction(Request $request, AtlasProject $project, ProjectExecutionService $execution): JsonResponse
    {
        $data = $request->validate([
            'title' => ['nullable', 'string', 'max:180'],
            'next_action' => ['nullable', 'string', 'max:300'],
            'priority' => ['nullable', Rule::in(['low', 'normal', 'high', 'urgent'])],
            'due_at' => ['nullable', 'date'],
            'estimated_minutes' => ['nullable', 'integer', 'min:5', 'max:480'],
            'energy_required' => ['nullable', Rule::in(['low', 'medium', 'high'])],
            'metadata' => ['nullable', 'array'],
        ]);
        $nextAction = trim((string) ($data['next_action'] ?? $data['title'] ?? ''));
        if ($nextAction !== '') {
            $data['next_action'] = $nextAction;
        }

        $plan = $execution->inferPlan(null, $data, $project->title);
        $task = $execution->ensureNextActionTask($project, null, $data, $plan, 'projects.next_action');

        return response()->json([
            'project' => (new AtlasProjectResource($project->refresh()->load($this->projectRelations())->loadCount(['tasks', 'steps'])))->resolve(),
            'task' => (new AtlasTaskResource($task->load(['project', 'projectStep'])))->resolve(),
        'route_decision' => \App\Services\Ai\DualCore\CanonicalRouteDecisionEnvelope::emit(route: 'programming', reason: 'http_atlas_project_controller'),
    ]);
    }

    private function rules(AtlasDomainRegistry $domains, bool $creating): array
    {
        return [
            'title' => [$creating ? 'sometimes' : 'sometimes', 'string', 'max:180'],
            // Atlas Code MVP wrap (passo-3): atlas-desktop posts intent+objective.
            // Either trio (title) or duo (intent+objective) is required when creating.
            'intent' => [$creating ? 'sometimes' : 'sometimes', 'string', 'max:1000'],
            'objective' => [$creating ? 'sometimes' : 'sometimes', 'string', 'max:300'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'status' => ['sometimes', Rule::in(['active', 'paused', 'blocked', 'waiting', 'completed', 'archived'])],
            'domain' => [$creating ? 'required' : 'sometimes', 'string', 'max:80', Rule::in($domains->activeSlugs())],
            'source_capture_id' => ['sometimes', 'nullable', 'uuid', 'exists:captures,id'],
            'goal' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'next_action' => ['sometimes', 'nullable', 'string', 'max:300'],
            'project_type' => ['sometimes', Rule::in(['study', 'technical_build', 'creative', 'business', 'research', 'writing', 'health', 'admin', 'personal', 'tedious', 'routine_candidate'])],
            'desired_outcome' => ['sometimes', 'nullable', 'string', 'max:1500'],
            'minimum_viable_outcome' => ['sometimes', 'nullable', 'string', 'max:1500'],
            'definition_of_done' => ['sometimes', 'nullable', 'string', 'max:1500'],
            'why_now' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'deadline_at' => ['sometimes', 'nullable', 'date'],
            'deadline_kind' => ['sometimes', Rule::in(['real', 'desired', 'artificial', 'none'])],
            'priority' => ['sometimes', Rule::in(['low', 'normal', 'high', 'urgent'])],
            'energy_profile' => ['sometimes', Rule::in(['low', 'medium', 'high', 'mixed'])],
            'avoidance_reason' => ['sometimes', Rule::in(['unclear', 'boring', 'too_large', 'scary', 'perfectionism', 'no_reward', 'low_energy', 'dependency', 'unknown'])],
            'last_touched_at' => ['sometimes', 'nullable', 'date'],
            'next_review_at' => ['sometimes', 'nullable', 'date'],
            'completed_at' => ['sometimes', 'nullable', 'date'],
            'paused_until' => ['sometimes', 'nullable', 'date'],
            'metadata' => ['sometimes', 'array'],
            'completion_outcome' => ['sometimes', 'nullable', 'string', 'max:1500'],
            'completion_evidence' => ['sometimes', 'nullable', 'string', 'max:1500'],
            'completion_note' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'force_completion' => ['sometimes', 'boolean'],
        ];
    }

    private function assertProjectStep(AtlasProject $project, AtlasProjectStep $step): void
    {
        if ($step->project_id !== $project->id) {
            abort(404);
        }
    }

    /**
     * @return array<int, string>
     */
    private function projectRelations(): array
    {
        return DatabaseTableAvailability::has('atlas_project_blockers')
            ? ['activeNextTask', 'currentStep', 'openBlockers']
            : ['activeNextTask', 'currentStep'];
    }

    /**
     * @param  array<string, mixed>  $health
     */
    private function reviewScore(AtlasProject $project, array $health): int
    {
        $score = match ($project->priority) {
            'urgent' => 35,
            'high' => 25,
            'low' => 5,
            default => 15,
        };
        $reasons = (array) ($health['reasons'] ?? []);
        if (in_array('blocked', $reasons, true)) {
            $score += 45;
        }
        if (in_array('missing_next_action', $reasons, true)) {
            $score += 35;
        }
        if (in_array('overdue', $reasons, true)) {
            $score += 30;
        }
        if (in_array('review_due', $reasons, true)) {
            $score += 20;
        }
        if (in_array('stale', $reasons, true)) {
            $score += 15;
        }

        return min(100, $score);
    }
}
