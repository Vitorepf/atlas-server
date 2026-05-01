<?php

namespace App\Http\Controllers;

use App\Http\Resources\AtlasTaskEventResource;
use App\Http\Resources\AtlasTaskResource;
use App\Models\AtlasProjectBlocker;
use App\Models\AtlasProjectStep;
use App\Models\AtlasTask;
use App\Services\AtlasDomainRegistry;
use App\Services\Engineering\EngineeringBlueprintService;
use App\Services\Engineering\EngineeringBlueprintSnapshotService;
use App\Services\Engineering\EngineeringRunArtifactService;
use App\Services\Engineering\EngineeringTaskContractService;
use App\Services\ProjectBlockerService;
use App\Services\ProjectExecutionService;
use App\Services\RoutineSchedulingService;
use App\Services\TaskPlanningService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AtlasTaskController extends Controller
{
    public function index(Request $request, AtlasDomainRegistry $domains): JsonResponse
    {
        $data = $request->validate([
            'domain' => ['nullable', 'string', 'max:80', Rule::in($domains->activeSlugs())],
            'status' => ['nullable', 'string', 'max:40'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $query = AtlasTask::query()
            ->with(['project', 'projectStep', 'routine'])
            ->orderByDesc('priority_score')
            ->orderByRaw('due_at IS NULL')
            ->orderBy('due_at')
            ->orderByDesc('created_at');
        if (! empty($data['domain'])) {
            $query->where('domain', $data['domain']);
        }
        if (! empty($data['status'])) {
            $query->where('status', $data['status']);
        }

        return response()->json([
            'tasks' => AtlasTaskResource::collection($query->limit((int) ($data['limit'] ?? 80))->get())->resolve(),
        ]);
    }

    public function agenda(Request $request, AtlasDomainRegistry $domains, TaskPlanningService $planning): JsonResponse
    {
        $data = $this->validatedAgenda($request, $domains);

        return response()->json($planning->agenda($data));
    }

    public function planAgenda(Request $request, AtlasDomainRegistry $domains, TaskPlanningService $planning): JsonResponse
    {
        $data = [
            ...$this->validatedAgenda($request, $domains),
            ...$request->validate([
                'force' => ['nullable', 'boolean'],
                'create_blocks' => ['nullable', 'boolean'],
            ]),
        ];

        return response()->json($planning->planAgenda($data));
    }

    public function weekAgenda(Request $request, AtlasDomainRegistry $domains, TaskPlanningService $planning): JsonResponse
    {
        return response()->json($planning->weekAgenda($this->validatedWeekAgenda($request, $domains)));
    }

    public function planWeekAgenda(Request $request, AtlasDomainRegistry $domains, TaskPlanningService $planning): JsonResponse
    {
        $data = [
            ...$this->validatedWeekAgenda($request, $domains),
            ...$request->validate([
                'force' => ['nullable', 'boolean'],
                'create_blocks' => ['nullable', 'boolean'],
            ]),
        ];

        return response()->json($planning->planWeekAgenda($data));
    }

    public function show(AtlasTask $task): AtlasTaskResource
    {
        return new AtlasTaskResource($task->load(['project', 'projectStep', 'routine']));
    }

    public function events(Request $request, AtlasTask $task): JsonResponse
    {
        $data = $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return response()->json([
            'events' => AtlasTaskEventResource::collection(
                $task->events()
                    ->latest('occurred_at')
                    ->limit((int) ($data['limit'] ?? 30))
                    ->get()
            )->resolve(),
        ]);
    }

    public function engineering(
        Request $request,
        AtlasTask $task,
        EngineeringTaskContractService $contracts,
        EngineeringBlueprintService $blueprints,
        EngineeringBlueprintSnapshotService $snapshots,
        EngineeringRunArtifactService $artifacts,
    ): JsonResponse {
        $data = $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $task->loadMissing(['project', 'projectStep']);
        $contract = $contracts->forTask($task);
        $blueprint = $blueprints->forTask($task, $contract);
        $metadata = is_array($task->metadata) ? $task->metadata : [];
        $evidenceHistory = $artifacts->evidenceHistory($task);

        $events = [];
        if (Schema::hasTable('atlas_task_events')) {
            $events = AtlasTaskEventResource::collection(
                $task->events()
                    ->whereIn('event_type', ['engineering_dev_run_completed', 'engineering_evidence_recorded'])
                    ->latest('occurred_at')
                    ->limit((int) ($data['limit'] ?? 10))
                    ->get()
            )->resolve();
        }

        return response()->json([
            'task_id' => $task->id,
            'contract' => $contract,
            'blueprint' => $blueprint,
            'blueprint_snapshot' => $snapshots->currentForTask($task, $contract, $blueprint),
            'status_snapshot' => $artifacts->statusSnapshot($task, $contract, $blueprint),
            'latest_run' => $metadata['latest_engineering_run'] ?? null,
            'run_history' => array_values((array) ($metadata['engineering_run_history'] ?? [])),
            'latest_evidence' => $evidenceHistory[0] ?? null,
            'evidence_history' => $evidenceHistory,
            'events' => $events,
        ]);
    }

    public function engineeringEvidence(
        Request $request,
        AtlasTask $task,
        EngineeringTaskContractService $contracts,
        EngineeringBlueprintService $blueprints,
        EngineeringBlueprintSnapshotService $snapshots,
        EngineeringRunArtifactService $artifacts,
    ): JsonResponse {
        $data = $request->validate([
            'evidence_type' => ['required', Rule::in(['acceptance', 'scenario', 'validation_evidence', 'manual_qa', 'deep_code_review', 'database_review'])],
            'target_id' => ['nullable', 'string', 'max:120'],
            'status' => ['required', Rule::in(['passed', 'failed', 'needs_review', 'not_applicable'])],
            'confidence' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'summary' => ['required', 'string', 'max:2000'],
            'trace_id' => ['nullable', 'uuid'],
            'command' => ['nullable', 'string', 'max:500'],
            'artifact_url' => ['nullable', 'string', 'max:1000'],
            'output_excerpt' => ['nullable', 'string', 'max:4000'],
            'files' => ['nullable', 'array', 'max:100'],
            'files.*' => ['string', 'max:500'],
            'metadata' => ['nullable', 'array'],
        ]);

        $entry = $artifacts->recordEvidence($task, $data);
        $task = $task->refresh()->load(['project', 'projectStep']);
        $contract = $contracts->forTask($task);
        $blueprint = $blueprints->forTask($task, $contract);
        $metadata = is_array($task->metadata) ? $task->metadata : [];
        $evidenceHistory = $artifacts->evidenceHistory($task);

        return response()->json([
            'task_id' => $task->id,
            'evidence' => $entry,
            'contract' => $contract,
            'blueprint' => $blueprint,
            'blueprint_snapshot' => $snapshots->currentForTask($task, $contract, $blueprint),
            'status_snapshot' => $artifacts->statusSnapshot($task, $contract, $blueprint),
            'latest_run' => $metadata['latest_engineering_run'] ?? null,
            'evidence_history' => $evidenceHistory,
        ], 201);
    }

    public function freezeEngineeringBlueprint(
        AtlasTask $task,
        EngineeringTaskContractService $contracts,
        EngineeringBlueprintService $blueprints,
        EngineeringBlueprintSnapshotService $snapshots,
        EngineeringRunArtifactService $artifacts,
    ): JsonResponse {
        $task->loadMissing(['project', 'projectStep']);
        $contract = $contracts->forTask($task);
        $blueprint = $blueprints->forTask($task, $contract);
        $snapshot = $snapshots->freezeForTask($task, $contract, $blueprint);
        $metadata = is_array($task->metadata) ? $task->metadata : [];
        $evidenceHistory = $artifacts->evidenceHistory($task);

        return response()->json([
            'task_id' => $task->id,
            'contract' => $contract,
            'blueprint' => $blueprint,
            'blueprint_snapshot' => $snapshot,
            'status_snapshot' => $artifacts->statusSnapshot($task, $contract, $blueprint),
            'latest_run' => $metadata['latest_engineering_run'] ?? null,
            'run_history' => array_values((array) ($metadata['engineering_run_history'] ?? [])),
            'latest_evidence' => $evidenceHistory[0] ?? null,
            'evidence_history' => $evidenceHistory,
            'events' => [],
        ], $snapshot === null ? 202 : 201);
    }

    public function update(Request $request, AtlasTask $task, TaskPlanningService $planning): AtlasTaskResource
    {
        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:180'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'status' => ['sometimes', Rule::in(['open', 'next', 'waiting', 'done', 'archived'])],
            'priority' => ['sometimes', Rule::in(['low', 'normal', 'high', 'urgent'])],
            'project_id' => ['sometimes', 'nullable', 'uuid', 'exists:atlas_projects,id'],
            'project_step_id' => ['sometimes', 'nullable', 'uuid', 'exists:atlas_project_steps,id'],
            'due_at' => ['sometimes', 'nullable', 'date'],
            'planned_for_date' => ['sometimes', 'nullable', 'date'],
            'planned_start_at' => ['sometimes', 'nullable', 'date'],
            'planned_end_at' => ['sometimes', 'nullable', 'date'],
            'estimated_minutes' => ['sometimes', 'integer', 'min:5', 'max:480'],
            'energy_required' => ['sometimes', Rule::in(['low', 'medium', 'high'])],
            'urgency_score' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'impact_score' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'effort_score' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'priority_score' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'planning_status' => ['sometimes', Rule::in(['unscheduled', 'suggested', 'planned', 'scheduled', 'deferred'])],
            'completed_at' => ['sometimes', 'nullable', 'date'],
            'execution_mode' => ['sometimes', Rule::in(['quick_win', 'deep_work', 'admin', 'study', 'tedious', 'creative', 'decision', 'maintenance', 'recovery'])],
            'friction_level' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'emotional_resistance' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'clarity_level' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'starter_step' => ['sometimes', 'nullable', 'string', 'max:300'],
            'minimum_viable_action' => ['sometimes', 'nullable', 'string', 'max:300'],
            'if_then_plan' => ['sometimes', 'nullable', 'string', 'max:500'],
            'reward_hint' => ['sometimes', 'nullable', 'string', 'max:300'],
            'failure_reason_last' => ['sometimes', 'nullable', 'string', 'max:500'],
            'attempt_count' => ['sometimes', 'integer', 'min:0'],
            'recovery_count' => ['sometimes', 'integer', 'min:0'],
            'metadata' => ['sometimes', 'array'],
        ]);

        if (array_key_exists('project_step_id', $data) && $data['project_step_id']) {
            $step = AtlasProjectStep::query()->find($data['project_step_id']);
            $targetProjectId = $data['project_id'] ?? $task->project_id;
            if (! $step || ! $targetProjectId || $step->project_id !== $targetProjectId) {
                throw ValidationException::withMessages([
                    'project_step_id' => 'A etapa precisa pertencer ao projeto da tarefa.',
                ]);
            }

            $data['project_id'] = $step->project_id;
        }

        if (
            array_key_exists('project_id', $data)
            && $data['project_id'] !== $task->project_id
            && ! array_key_exists('project_step_id', $data)
        ) {
            $data['project_step_id'] = null;
        }

        $shouldReplan = array_intersect(array_keys($data), [
            'title',
            'description',
            'priority',
            'due_at',
            'estimated_minutes',
            'energy_required',
            'urgency_score',
            'impact_score',
            'effort_score',
            'priority_score',
        ]);
        if ($shouldReplan !== []) {
            $data = [
                ...$data,
                ...$planning->attributesForTaskUpdate($task, $data),
            ];
        }

        if (($data['status'] ?? null) === 'done' && empty($data['completed_at'])) {
            $data['completed_at'] = now();
        }

        $task->update($data);
        $changes = $task->getChanges();
        unset($changes['updated_at']);
        if ($changes !== []) {
            $planning->recordEvent($task, 'updated', [
                'changes' => $changes,
                'source' => 'tasks.update',
            ]);
        }

        return new AtlasTaskResource($task->refresh()->load(['project', 'projectStep', 'routine']));
    }

    public function schedule(Request $request, AtlasTask $task, TaskPlanningService $planning): AtlasTaskResource
    {
        $data = $request->validate([
            'planned_for_date' => ['nullable', 'date'],
            'planned_start_at' => ['nullable', 'date'],
            'planned_end_at' => ['nullable', 'date', 'after:planned_start_at'],
            'estimated_minutes' => ['nullable', 'integer', 'min:5', 'max:480'],
            'timezone' => ['nullable', 'timezone'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $timezone = (string) ($data['timezone'] ?? config('app.timezone', 'UTC'));
        $estimatedMinutes = (int) ($data['estimated_minutes'] ?? $task->estimated_minutes ?? 25);
        $plannedStart = isset($data['planned_start_at'])
            ? CarbonImmutable::parse($data['planned_start_at'], $timezone)
            : null;
        $plannedEnd = isset($data['planned_end_at'])
            ? CarbonImmutable::parse($data['planned_end_at'], $timezone)
            : ($plannedStart?->addMinutes($estimatedMinutes));
        $plannedDate = $data['planned_for_date']
            ?? $plannedStart?->setTimezone($timezone)->toDateString()
            ?? now($timezone)->toDateString();

        $updates = [
            'planned_for_date' => $plannedDate,
            'planned_start_at' => $plannedStart,
            'planned_end_at' => $plannedEnd,
            'estimated_minutes' => $estimatedMinutes,
            'planning_status' => $plannedStart ? 'scheduled' : 'planned',
        ];

        $task->update([
            ...$updates,
            ...$planning->attributesForTaskUpdate($task, $updates),
        ]);
        $planning->recordEvent($task, 'scheduled', [
            'planned_for_date' => $plannedDate,
            'planned_start_at' => $plannedStart?->toJSON(),
            'planned_end_at' => $plannedEnd?->toJSON(),
            'estimated_minutes' => $estimatedMinutes,
            'note' => $data['note'] ?? null,
        ]);

        return new AtlasTaskResource($task->refresh()->load(['project', 'projectStep', 'routine']));
    }

    public function defer(
        Request $request,
        AtlasTask $task,
        TaskPlanningService $planning,
        ProjectExecutionService $projects,
    ): AtlasTaskResource {
        $data = $request->validate([
            'defer_until' => ['nullable', 'date'],
            'timezone' => ['nullable', 'timezone'],
            'reason' => ['nullable', 'string', 'max:500'],
            'reason_code' => ['nullable', Rule::in(['low_energy', 'too_big', 'unclear', 'blocked', 'waiting', 'calendar', 'avoidance', 'not_now'])],
        ]);

        $timezone = (string) ($data['timezone'] ?? config('app.timezone', 'UTC'));
        $deferUntil = CarbonImmutable::parse($data['defer_until'] ?? now($timezone)->addDay()->toDateString(), $timezone)
            ->startOfDay();

        $task->update([
            'status' => 'open',
            'planning_status' => 'deferred',
            'planned_for_date' => $deferUntil->toDateString(),
            'planned_start_at' => null,
            'planned_end_at' => null,
        ]);
        $deferSignal = $projects->recordTaskDeferred($task->refresh(), $data['reason'] ?? null, $deferUntil, 'tasks.defer', $data['reason_code'] ?? null);
        $planning->recordEvent($task->refresh(), 'deferred', [
            'defer_until' => $deferUntil->toDateString(),
            'reason' => $data['reason'] ?? null,
            'reason_code' => $deferSignal['reason_code'] ?? null,
            'recommended_action' => $deferSignal['recommended_action'] ?? null,
            'suggested_recovery_minutes' => $deferSignal['suggested_recovery_minutes'] ?? null,
        ]);

        return new AtlasTaskResource($task->refresh()->load(['project', 'projectStep', 'routine']));
    }

    public function complete(
        Request $request,
        AtlasTask $task,
        TaskPlanningService $planning,
        ProjectExecutionService $projects,
        ProjectBlockerService $blockers,
        RoutineSchedulingService $routines,
    ): AtlasTaskResource {
        $data = $request->validate([
            'completed_at' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:500'],
            'actual_minutes' => ['nullable', 'integer', 'min:1', 'max:1440'],
            'completion_quality' => ['nullable', Rule::in(['complete', 'partial', 'learned', 'blocked'])],
            'energy_after' => ['nullable', 'integer', 'min:1', 'max:5'],
            'outcome' => ['nullable', 'string', 'max:1000'],
            'evidence' => ['nullable', 'string', 'max:1000'],
            'blocker' => ['nullable', 'string', 'max:500'],
            'blocker_reason_code' => ['nullable', Rule::in(['unclear', 'too_large', 'boring', 'waiting_external', 'missing_resource', 'fear', 'energy', 'technical_unknown', 'decision_needed', 'other'])],
            'blocker_severity' => ['nullable', Rule::in(['low', 'medium', 'high'])],
            'unblock_next_action' => ['nullable', 'string', 'max:500'],
            'waiting_on' => ['nullable', 'string', 'max:180'],
            'next_hint' => ['nullable', 'string', 'max:500'],
        ]);

        $completedAt = isset($data['completed_at'])
            ? CarbonImmutable::parse($data['completed_at'])
            : now();
        $quality = $data['completion_quality'] ?? 'complete';
        $actionCompleted = in_array($quality, ['complete', 'learned'], true);
        $blocked = $quality === 'blocked';

        if ($actionCompleted) {
            $task->update([
                'status' => 'done',
                'completed_at' => $completedAt,
            ]);
        } elseif ($blocked) {
            $task->update([
                'status' => 'waiting',
                'planning_status' => 'deferred',
                'completed_at' => null,
                'failure_reason_last' => $data['blocker'] ?? $data['outcome'] ?? $data['note'] ?? $task->failure_reason_last,
            ]);
        } else {
            $task->update([
                'status' => 'open',
                'planning_status' => in_array($task->planning_status, ['unscheduled', 'suggested'], true)
                    ? 'planned'
                    : $task->planning_status,
                'completed_at' => null,
            ]);
        }
        $task->refresh();
        $completion = $projects->recordTaskCompleted($task, $data, $completedAt, 'tasks.complete');
        $planningEvent = $actionCompleted ? 'completed' : ($blocked ? 'blocked' : 'progress_recorded');
        $planning->recordEvent($task, $planningEvent, [
            'completed_at' => $completedAt->toJSON(),
            'note' => $data['note'] ?? null,
            'actual_minutes' => $completion['actual_minutes'] ?? null,
            'estimated_minutes' => $completion['estimated_minutes'] ?? null,
            'estimate_ratio' => $completion['estimate_ratio'] ?? null,
            'completion_quality' => $completion['completion_quality'] ?? null,
            'action_completed' => $completion['action_completed'] ?? null,
            'outcome' => $completion['outcome'] ?? null,
            'evidence' => $completion['evidence'] ?? null,
            'blocker' => $completion['blocker'] ?? null,
            'blocker_id' => $completion['blocker_id'] ?? null,
            'blocker_reason_code' => $completion['blocker_reason_code'] ?? null,
            'blocker_severity' => $completion['blocker_severity'] ?? null,
            'unblock_next_action' => $completion['unblock_next_action'] ?? null,
            'next_hint' => $completion['next_hint'] ?? null,
        ]);
        $task->refresh();
        $taskMetadata = is_array($task->metadata) ? $task->metadata : [];
        $isUnblockTask = (string) ($taskMetadata['role'] ?? '') === 'unblock_action';
        $blockerId = is_string($taskMetadata['blocker_id'] ?? null) ? $taskMetadata['blocker_id'] : null;
        if ($actionCompleted && $isUnblockTask && $blockerId) {
            $blocker = AtlasProjectBlocker::query()->find($blockerId);
            if ($blocker && $blocker->status === 'open') {
                $blockers->resolve($blocker, [
                    'resolution_note' => $data['outcome'] ?? $data['note'] ?? 'Tarefa de desbloqueio concluida.',
                ], 'tasks.complete');
            }
        } elseif ($actionCompleted) {
            $projects->advanceAfterTaskCompletion($task, 'tasks.complete', $completion);
            $routines->recordCompletionFromTask($task, 'tasks.complete');
        } elseif ($blocked && $task->project_step_id) {
            $step = AtlasProjectStep::query()->with('project')->find($task->project_step_id);
            if ($step && $step->project) {
                $projects->blockStep($step->project, $step, 'tasks.complete', $completion);
            }
        }

        return new AtlasTaskResource($task->refresh()->load(['project', 'projectStep', 'routine']));
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedAgenda(Request $request, AtlasDomainRegistry $domains): array
    {
        return $request->validate([
            'date' => ['nullable', 'date'],
            'timezone' => ['nullable', 'timezone'],
            'domain' => ['nullable', 'string', 'max:80', Rule::in($domains->activeSlugs())],
            'energy_level' => ['nullable', 'integer', 'min:1', 'max:5'],
            'capacity_minutes' => ['nullable', 'integer', 'min:30', 'max:720'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedWeekAgenda(Request $request, AtlasDomainRegistry $domains): array
    {
        return $request->validate([
            'start_date' => ['nullable', 'date'],
            'timezone' => ['nullable', 'timezone'],
            'domain' => ['nullable', 'string', 'max:80', Rule::in($domains->activeSlugs())],
            'energy_level' => ['nullable', 'integer', 'min:1', 'max:5'],
            'days' => ['nullable', 'integer', 'min:1', 'max:14'],
            'weekday_capacity_minutes' => ['nullable', 'integer', 'min:30', 'max:720'],
            'weekend_capacity_minutes' => ['nullable', 'integer', 'min:0', 'max:360'],
            'capacity_minutes' => ['nullable', 'integer', 'min:30', 'max:720'],
            'daily_limit' => ['nullable', 'integer', 'min:1', 'max:30'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
            'include_weekends' => ['nullable', 'boolean'],
        ]);
    }
}
