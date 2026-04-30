<?php

namespace App\Services;

use App\Models\AtlasProject;
use App\Models\AtlasProjectEvent;
use App\Models\AtlasProjectStep;
use App\Models\AtlasTask;
use App\Models\Capture;
use App\Support\ProjectExecutionHealth;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ProjectExecutionService
{
    public function __construct(
        private readonly TaskPlanningService $planning,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function inferPlan(?Capture $capture, array $data, string $title): array
    {
        $text = trim(implode(' ', array_filter([
            $title,
            $data['goal'] ?? null,
            $data['description'] ?? null,
            $data['reason'] ?? null,
            $data['next_action'] ?? null,
            $capture?->content_text,
        ], fn ($part) => is_string($part) && trim($part) !== '')));
        $lower = Str::lower($text);
        $type = $this->projectType($lower);
        $priority = $this->priority($data['priority'] ?? null, $lower);
        $nextAction = trim((string) ($data['next_action'] ?? '')) ?: $this->defaultNextAction($type, $title, $lower);
        $starterStep = $this->starterStep($type, $nextAction);
        $estimatedMinutes = $this->estimatedMinutes($type, $lower);
        $energyRequired = $this->energyRequired($type, $estimatedMinutes, $priority);
        $avoidanceReason = $this->avoidanceReason($lower, $estimatedMinutes);
        $processSteps = $this->processSteps($type, $title);

        return [
            'project_type' => $type,
            'desired_outcome' => $data['desired_outcome'] ?? ($data['goal'] ?? $this->desiredOutcome($type, $title)),
            'minimum_viable_outcome' => $data['minimum_viable_outcome'] ?? $this->minimumViableOutcome($type, $title),
            'definition_of_done' => $data['definition_of_done'] ?? $this->definitionOfDone($type, $title),
            'why_now' => $data['why_now'] ?? ($data['reason'] ?? null),
            'deadline_at' => $data['deadline_at'] ?? ($data['due_at'] ?? null),
            'deadline_kind' => $data['deadline_kind'] ?? (($data['due_at'] ?? null) ? 'desired' : 'none'),
            'priority' => $priority,
            'energy_profile' => $this->energyProfile($energyRequired, $type),
            'avoidance_reason' => $avoidanceReason,
            'next_action' => $nextAction,
            'starter_step' => $starterStep,
            'minimum_viable_action' => $this->minimumViableAction($type, $nextAction),
            'if_then_plan' => $this->ifThenPlan($type),
            'reward_hint' => $this->rewardHint($type),
            'execution_mode' => $this->executionMode($type, $estimatedMinutes, $lower),
            'estimated_minutes' => $estimatedMinutes,
            'energy_required' => $energyRequired,
            'friction_level' => $this->frictionLevel($avoidanceReason, $estimatedMinutes),
            'emotional_resistance' => $this->emotionalResistance($avoidanceReason),
            'clarity_level' => $this->clarityLevel($lower, $data),
            'process_steps' => $processSteps,
            'project_steps' => $this->projectStepSpecs($type, $title),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $plan
     */
    public function ensureNextActionTask(
        AtlasProject $project,
        ?Capture $capture,
        array $data = [],
        array $plan = [],
        string $source = 'project',
    ): AtlasTask {
        $plan = $plan ?: $this->inferPlan($capture, $data, $project->title);
        if (Schema::hasTable('atlas_project_steps') && $project->steps()->count() === 0) {
            $this->ensureProjectPlan($project, $plan, false, $source);
            $project->refresh();
        }
        $step = $this->currentStepForProject($project);
        $task = $this->activeNextActionTask($project);
        $wasRecentlyCreated = false;

        if (! $task) {
            $task = new AtlasTask();
            $wasRecentlyCreated = true;
        }

        $explicitNextAction = trim((string) ($data['next_action'] ?? $data['title'] ?? ''));
        $title = $this->taskTitle($explicitNextAction ?: (string) ($step?->title ?? $plan['next_action'] ?? $project->next_action ?? $project->title));
        $description = $step
            ? $this->stepTaskDescription($project, $step, $capture)
            : $this->taskDescription($project, $capture, $plan);
        $explicitEstimate = array_key_exists('estimated_minutes', $data);
        $baseEstimate = $this->clamp((int) ($data['estimated_minutes'] ?? $step?->estimated_minutes ?? $plan['estimated_minutes'] ?? 25), 5, 480);
        $estimatedMinutes = $explicitEstimate
            ? $baseEstimate
            : $this->calibratedEstimateForProject($project, $step, $baseEstimate);
        $taskData = [
            ...$data,
            'priority' => $data['priority'] ?? $plan['priority'] ?? $project->priority,
            'estimated_minutes' => $estimatedMinutes,
            'energy_required' => $data['energy_required'] ?? $step?->energy_required ?? $plan['energy_required'] ?? null,
            'due_at' => $data['due_at'] ?? $plan['deadline_at'] ?? null,
        ];
        $planningAttributes = $capture
            ? $this->planning->attributesForCaptureTask($capture, $taskData, $title, $description)
            : $this->standalonePlanningAttributes([
                ...$plan,
                'estimated_minutes' => $estimatedMinutes,
                'energy_required' => $step?->energy_required ?? $plan['energy_required'] ?? null,
            ]);

        $metadata = is_array($task->metadata) ? $task->metadata : [];
        $task->fill([
            'title' => $title,
            'description' => $description,
            'status' => $task->status === 'done' ? 'done' : 'open',
            'priority' => $data['priority'] ?? $plan['priority'] ?? $project->priority ?? 'normal',
            'domain' => $project->domain,
            'source_capture_id' => $capture?->id ?? $project->source_capture_id,
            'project_id' => $project->id,
            'project_step_id' => $step?->id,
            'due_at' => $data['due_at'] ?? $plan['deadline_at'] ?? $project->deadline_at,
            ...$planningAttributes,
            'execution_mode' => $step ? $this->executionModeForStep($project, $step) : ($plan['execution_mode'] ?? 'quick_win'),
            'friction_level' => $step?->friction_level ?? $plan['friction_level'] ?? 50,
            'emotional_resistance' => $plan['emotional_resistance'] ?? 50,
            'clarity_level' => $plan['clarity_level'] ?? 60,
            'starter_step' => $step ? $this->starterForStep($step) : ($plan['starter_step'] ?? null),
            'minimum_viable_action' => $step?->expected_output ?? $plan['minimum_viable_action'] ?? null,
            'if_then_plan' => $plan['if_then_plan'] ?? null,
            'reward_hint' => $plan['reward_hint'] ?? null,
            'metadata' => [
                ...$metadata,
                'role' => 'active_next_action',
                'created_from' => $metadata['created_from'] ?? 'project_execution',
                'project_type' => $plan['project_type'] ?? $project->project_type,
                'process_steps' => $plan['process_steps'] ?? [],
                'project_step_id' => $step?->id,
                'project_step_order' => $step?->step_order,
                'project_step_type' => $step?->step_type,
                'estimate_calibration' => [
                    'base_minutes' => $baseEstimate,
                    'calibrated_minutes' => $estimatedMinutes,
                    'applied' => $estimatedMinutes !== $baseEstimate,
                    'source' => $explicitEstimate ? 'explicit' : 'project_execution_learning',
                ],
                'source' => $source,
                'last_project_sync_at' => now()->toJSON(),
            ],
        ]);
        $task->save();

        if ($step) {
            $step->forceFill([
                'status' => 'active',
                'active_task_id' => $task->id,
                'started_at' => $step->started_at ?? now(),
            ])->save();
        }

        $project->forceFill([
            'next_action' => $title,
            'active_next_task_id' => $task->id,
            'current_step_id' => $step?->id ?? $project->current_step_id,
            'last_touched_at' => now(),
            'next_review_at' => $project->next_review_at ?? now()->addDays(3),
        ])->save();

        $eventType = $wasRecentlyCreated || $task->wasRecentlyCreated ? 'next_action_created' : 'next_action_updated';
        $this->planning->recordEvent($task, $wasRecentlyCreated ? 'created_from_project' : 'updated_from_project', [
            'project_id' => $project->id,
            'project_title' => $project->title,
            'project_type' => $project->project_type,
            'project_step_id' => $step?->id,
            'project_step_order' => $step?->step_order,
            'starter_step' => $task->starter_step,
            'minimum_viable_action' => $task->minimum_viable_action,
        ], $source);
        $this->recordEvent($project, $eventType, [
            'task_id' => $task->id,
            'task_title' => $task->title,
            'priority' => $task->priority,
            'estimated_minutes' => $task->estimated_minutes,
            'energy_required' => $task->energy_required,
            'project_step_id' => $step?->id,
            'project_step_order' => $step?->step_order,
            'starter_step' => $task->starter_step,
        ], $source);

        return $task->refresh();
    }

    /**
     * @return Collection<int, AtlasProjectStep>
     */
    public function ensureProjectPlan(AtlasProject $project, array $plan = [], bool $replace = false, string $source = 'project'): Collection
    {
        if (! Schema::hasTable('atlas_project_steps')) {
            return collect();
        }

        $existing = $project->steps()->orderBy('step_order')->get();
        if ($existing->isNotEmpty() && ! $replace) {
            $this->ensureCurrentStep($project, $existing, $source);

            return $project->steps()->with('activeTask')->orderBy('step_order')->get();
        }

        $plan = $plan ?: $this->inferPlan(null, [], $project->title);
        $specs = is_array($plan['project_steps'] ?? null)
            ? $plan['project_steps']
            : $this->projectStepSpecs((string) ($plan['project_type'] ?? $project->project_type ?? 'personal'), $project->title);

        $steps = collect();
        foreach (array_values($specs) as $index => $spec) {
            if (! is_array($spec)) {
                continue;
            }

            $order = $index + 1;
            $step = AtlasProjectStep::query()->updateOrCreate(
                [
                    'project_id' => $project->id,
                    'step_order' => $order,
                ],
                [
                    'title' => mb_substr((string) ($spec['title'] ?? "Etapa {$order}"), 0, 180),
                    'description' => $spec['description'] ?? null,
                    'status' => ($existing->firstWhere('step_order', $order)?->status === 'done') ? 'done' : ($order === 1 ? 'active' : 'pending'),
                    'step_type' => $spec['step_type'] ?? 'action',
                    'expected_output' => $spec['expected_output'] ?? null,
                    'acceptance_criteria' => $spec['acceptance_criteria'] ?? null,
                    'estimated_minutes' => $this->clamp((int) ($spec['estimated_minutes'] ?? $plan['estimated_minutes'] ?? 25), 5, 480),
                    'energy_required' => $this->validEnergy($spec['energy_required'] ?? $plan['energy_required'] ?? 'medium'),
                    'friction_level' => $this->clamp((int) ($spec['friction_level'] ?? $plan['friction_level'] ?? 50), 0, 100),
                    'metadata' => [
                        'generated_by' => 'project_execution_service',
                        'project_type' => $plan['project_type'] ?? $project->project_type,
                    ],
                    'started_at' => $order === 1 ? now() : null,
                    'completed_at' => null,
                ],
            );
            $steps->push($step);
        }

        $this->ensureCurrentStep($project, $steps, $source);
        $this->recordEvent($project, $replace ? 'plan_rebuilt' : 'plan_created', [
            'step_count' => $steps->count(),
            'steps' => $steps->map(fn (AtlasProjectStep $step): array => [
                'id' => $step->id,
                'title' => $step->title,
                'step_order' => $step->step_order,
            ])->values()->all(),
        ], $source);

        return $project->steps()->with('activeTask')->orderBy('step_order')->get();
    }

    public function advanceAfterTaskCompletion(AtlasTask $task, string $source = 'tasks.complete', array $completion = []): ?AtlasTask
    {
        if (! $task->project_id || ! $task->project_step_id || ! Schema::hasTable('atlas_project_steps')) {
            return null;
        }

        $step = AtlasProjectStep::query()->with('project')->find($task->project_step_id);
        $project = $step?->project;
        if (! $step || ! $project) {
            return null;
        }

        $step->forceFill([
            'status' => 'done',
            'active_task_id' => $task->id,
            'completed_at' => now(),
        ])->save();
        $this->recordEvent($project, 'step_completed', [
            'step_id' => $step->id,
            'step_order' => $step->step_order,
            'step_title' => $step->title,
            'task_id' => $task->id,
            'task_title' => $task->title,
            'actual_minutes' => $completion['actual_minutes'] ?? null,
            'estimate_ratio' => $completion['estimate_ratio'] ?? null,
            'completion_quality' => $completion['completion_quality'] ?? null,
        ], $source);

        $next = AtlasProjectStep::query()
            ->where('project_id', $project->id)
            ->where('step_order', '>', $step->step_order)
            ->whereIn('status', ['pending', 'blocked'])
            ->orderBy('step_order')
            ->first();

        if (! $next) {
            $project->forceFill([
                'status' => 'completed',
                'active_next_task_id' => null,
                'current_step_id' => null,
                'next_action' => null,
                'completed_at' => now(),
                'last_touched_at' => now(),
            ])->save();
            $this->recordEvent($project, 'completed', [
                'completed_by_task_id' => $task->id,
                'completed_by_step_id' => $step->id,
                'actual_minutes' => $completion['actual_minutes'] ?? null,
                'estimate_ratio' => $completion['estimate_ratio'] ?? null,
            ], $source);

            return null;
        }

        $next->forceFill([
            'status' => 'active',
            'started_at' => $next->started_at ?? now(),
        ])->save();
        $project->forceFill([
            'current_step_id' => $next->id,
            'active_next_task_id' => null,
            'next_action' => $next->title,
            'last_touched_at' => now(),
            'next_review_at' => now()->addDays(3),
        ])->save();
        $this->recordEvent($project, 'step_activated', [
            'step_id' => $next->id,
            'step_order' => $next->step_order,
            'step_title' => $next->title,
        ], $source);

        return $this->ensureNextActionTask($project->refresh(), null, [], $this->planFromStep($project, $next), $source);
    }

    public function activateStep(AtlasProject $project, AtlasProjectStep $step, string $source = 'projects.step.activate'): AtlasTask
    {
        if ($step->project_id !== $project->id) {
            abort(404);
        }

        $previousActiveStepIds = AtlasProjectStep::query()
            ->where('project_id', $project->id)
            ->whereKeyNot($step->id)
            ->where('status', 'active')
            ->pluck('id');

        if ($previousActiveStepIds->isNotEmpty()) {
            AtlasTask::query()
                ->where('project_id', $project->id)
                ->whereIn('project_step_id', $previousActiveStepIds)
                ->whereIn('status', ['open', 'next'])
                ->update([
                    'status' => 'waiting',
                    'updated_at' => now(),
                ]);
        }

        AtlasProjectStep::query()
            ->where('project_id', $project->id)
            ->whereKeyNot($step->id)
            ->where('status', 'active')
            ->update([
                'status' => 'pending',
                'active_task_id' => null,
                'updated_at' => now(),
            ]);

        $step->forceFill([
            'status' => 'active',
            'started_at' => $step->started_at ?? now(),
            'completed_at' => null,
        ])->save();

        $project->forceFill([
            'status' => 'active',
            'current_step_id' => $step->id,
            'active_next_task_id' => null,
            'next_action' => $step->title,
            'last_touched_at' => now(),
            'next_review_at' => now()->addDays(3),
        ])->save();

        $this->recordEvent($project, 'step_activated', [
            'step_id' => $step->id,
            'step_order' => $step->step_order,
            'step_title' => $step->title,
            'manual' => true,
        ], $source);

        return $this->ensureNextActionTask($project->refresh(), null, [], $this->planFromStep($project, $step->refresh()), $source);
    }

    public function blockStep(AtlasProject $project, AtlasProjectStep $step, string $source = 'projects.step.block'): void
    {
        if ($step->project_id !== $project->id) {
            abort(404);
        }

        $step->forceFill([
            'status' => 'blocked',
            'active_task_id' => null,
        ])->save();

        AtlasTask::query()
            ->where('project_id', $project->id)
            ->where('project_step_id', $step->id)
            ->whereIn('status', ['open', 'next'])
            ->update([
                'status' => 'waiting',
                'updated_at' => now(),
            ]);

        if ($project->current_step_id === $step->id) {
            $project->forceFill([
                'status' => 'blocked',
                'active_next_task_id' => null,
                'next_action' => 'Desbloquear: '.$step->title,
                'last_touched_at' => now(),
            ])->save();
        }

        $this->recordEvent($project, 'step_blocked', [
            'step_id' => $step->id,
            'step_order' => $step->step_order,
            'step_title' => $step->title,
        ], $source);
    }

    public function reopenStep(AtlasProject $project, AtlasProjectStep $step, string $source = 'projects.step.reopen'): void
    {
        if ($step->project_id !== $project->id) {
            abort(404);
        }

        $step->forceFill([
            'status' => 'pending',
            'active_task_id' => null,
            'completed_at' => null,
        ])->save();

        if ($project->status === 'blocked' && $project->current_step_id === $step->id) {
            $project->forceFill([
                'status' => 'active',
                'current_step_id' => null,
                'next_action' => null,
                'last_touched_at' => now(),
            ])->save();
        }

        $this->recordEvent($project, 'step_reopened', [
            'step_id' => $step->id,
            'step_order' => $step->step_order,
            'step_title' => $step->title,
        ], $source);
    }

    public function closeStepAndAdvance(
        AtlasProject $project,
        AtlasProjectStep $step,
        string $status = 'skipped',
        string $source = 'projects.step.close',
    ): ?AtlasTask {
        if ($step->project_id !== $project->id || ! in_array($status, ['done', 'skipped'], true)) {
            abort(404);
        }

        $step->forceFill([
            'status' => $status,
            'completed_at' => now(),
        ])->save();

        AtlasTask::query()
            ->where('project_id', $project->id)
            ->where('project_step_id', $step->id)
            ->whereIn('status', ['open', 'next', 'waiting'])
            ->update([
                'status' => $status === 'done' ? 'done' : 'archived',
                'completed_at' => $status === 'done' ? now() : null,
                'updated_at' => now(),
            ]);

        $this->recordEvent($project, $status === 'done' ? 'step_completed' : 'step_skipped', [
            'step_id' => $step->id,
            'step_order' => $step->step_order,
            'step_title' => $step->title,
            'manual' => true,
        ], $source);

        $next = AtlasProjectStep::query()
            ->where('project_id', $project->id)
            ->where('step_order', '>', $step->step_order)
            ->whereIn('status', ['pending', 'blocked'])
            ->orderBy('step_order')
            ->first();

        if (! $next) {
            $project->forceFill([
                'status' => 'completed',
                'active_next_task_id' => null,
                'current_step_id' => null,
                'next_action' => null,
                'completed_at' => now(),
                'last_touched_at' => now(),
            ])->save();
            $this->recordEvent($project, 'completed', [
                'completed_by_step_id' => $step->id,
                'manual' => true,
            ], $source);

            return null;
        }

        return $this->activateStep($project->refresh(), $next, $source);
    }

    public function transitionStatus(
        AtlasProject $project,
        string $status,
        array $data = [],
        string $source = 'projects.status',
    ): ?AtlasTask {
        $previousStatus = $project->status;
        $taskUpdateCount = 0;
        $updates = [
            'status' => $status,
            'last_touched_at' => now(),
        ];

        if (array_key_exists('paused_until', $data)) {
            $updates['paused_until'] = $data['paused_until'];
        }

        if ($status === 'active') {
            $updates['paused_until'] = null;
            $updates['completed_at'] = null;
            $project->forceFill($updates)->save();
            $task = $this->ensureNextActionTask($project->refresh(), null, $data, $this->inferPlan(null, $data, $project->title), $source);
            $this->recordEvent($project->refresh(), 'status_changed', [
                'from' => $previousStatus,
                'to' => $status,
                'active_next_task_id' => $task->id,
            ], $source);

            return $task;
        }

        $completion = null;
        if (in_array($status, ['paused', 'waiting', 'blocked'], true)) {
            $updates['active_next_task_id'] = null;
            $taskUpdateCount = $this->moveOpenProjectTasks($project, 'waiting');
        } elseif ($status === 'completed') {
            $completion = $this->validatedProjectCompletion($project, $data);
            $updates['completed_at'] = $completion['completed_at'];
            $updates['paused_until'] = null;
            $updates['active_next_task_id'] = null;
            $updates['current_step_id'] = null;
            $updates['next_action'] = null;
            $updates['metadata'] = [
                ...(is_array($project->metadata) ? $project->metadata : []),
                'completion' => $completion,
            ];
            $taskUpdateCount = $this->moveOpenProjectTasks($project, 'archived');
        } elseif ($status === 'archived') {
            $updates['paused_until'] = null;
            $updates['active_next_task_id'] = null;
            $updates['current_step_id'] = null;
            $taskUpdateCount = $this->moveOpenProjectTasks($project, 'archived');
        }

        $project->forceFill($updates)->save();
        $this->recordEvent($project->refresh(), 'status_changed', [
            'from' => $previousStatus,
            'to' => $status,
            'affected_open_tasks' => $taskUpdateCount,
            'completion' => $completion,
        ], $source);

        return null;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function validatedProjectCompletion(AtlasProject $project, array $data): array
    {
        $readiness = $this->projectCompletionReadiness($project);
        $completionMetadata = is_array(data_get($data, 'metadata.completion'))
            ? data_get($data, 'metadata.completion')
            : [];
        $outcome = $this->completionText($data['completion_outcome'] ?? $completionMetadata['outcome'] ?? null, 1500);
        $evidence = $this->completionText($data['completion_evidence'] ?? $completionMetadata['evidence'] ?? null, 1500);
        $note = $this->completionText($data['completion_note'] ?? $completionMetadata['note'] ?? null, 1000);
        $force = (bool) ($data['force_completion'] ?? $completionMetadata['force'] ?? false);

        $errors = [];
        if ($outcome === null) {
            $errors['completion_outcome'] = 'Informe o resultado real antes de concluir o projeto.';
        }
        if (! $force && ! (bool) $readiness['ready']) {
            $errors['force_completion'] = 'O projeto ainda tem etapas ou tarefas abertas. Confirme a conclusão forçada ou feche o trabalho pendente.';
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return [
            'status' => $force && ! (bool) $readiness['ready'] ? 'force_completed' : 'completed',
            'force' => $force,
            'outcome' => $outcome,
            'evidence' => $evidence,
            'note' => $note,
            'readiness' => $readiness,
            'completed_at' => isset($data['completed_at'])
                ? CarbonImmutable::parse($data['completed_at'])->toJSON()
                : now()->toJSON(),
            'completed_by' => 'human',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function projectCompletionReadiness(AtlasProject $project): array
    {
        $stepCounts = [
            'total' => 0,
            'done' => 0,
            'skipped' => 0,
            'active' => 0,
            'pending' => 0,
            'blocked' => 0,
        ];
        if (Schema::hasTable('atlas_project_steps')) {
            foreach ($project->steps()->get(['status']) as $step) {
                $status = (string) $step->status;
                $stepCounts['total']++;
                if (array_key_exists($status, $stepCounts)) {
                    $stepCounts[$status]++;
                }
            }
        }

        $openTaskCount = Schema::hasTable('atlas_tasks')
            ? $project->tasks()
                ->whereIn('status', ['open', 'next', 'waiting'])
                ->whereNull('completed_at')
                ->count()
            : 0;
        $openStepCount = $stepCounts['active'] + $stepCounts['pending'] + $stepCounts['blocked'];
        $ready = $openStepCount === 0 && $openTaskCount === 0;

        return [
            'ready' => $ready,
            'step_counts' => $stepCounts,
            'open_step_count' => $openStepCount,
            'blocked_step_count' => $stepCounts['blocked'],
            'open_task_count' => $openTaskCount,
            'active_next_task_id' => $project->active_next_task_id,
            'current_step_id' => $project->current_step_id,
            'definition_of_done' => $project->definition_of_done,
            'minimum_viable_outcome' => $project->minimum_viable_outcome,
        ];
    }

    /**
     * @param  array<string, mixed>  $health
     * @return array<string, mixed>
     */
    public function reviewSuggestion(AtlasProject $project, array $health = []): array
    {
        $health = $health !== [] ? $health : ProjectExecutionHealth::for($project);
        $reasons = array_values((array) ($health['reasons'] ?? []));

        if (in_array('missing_next_action', $reasons, true)) {
            return [
                'action' => 'ensure_next_action',
                'label' => 'Criar próxima ação',
                'reason' => 'Projeto ativo sem tarefa executável na agenda.',
                'proposed_next_action' => $project->next_action ?: $this->defaultNextAction($project->project_type ?: 'personal', $project->title, Str::lower($project->title.' '.$project->goal)),
                'review_interval_days' => 3,
            ];
        }

        if (in_array('blocked', $reasons, true)) {
            return [
                'action' => 'review_blocker',
                'label' => 'Revisar bloqueio',
                'reason' => 'Projeto bloqueado precisa de decisão, dependência ou replanejamento.',
                'proposed_next_action' => 'Escrever o bloqueio real e a menor ação para remover dependência',
                'review_interval_days' => 1,
            ];
        }

        if (in_array('overdue', $reasons, true)) {
            return [
                'action' => 'rebuild_plan',
                'label' => 'Replanejar prazo',
                'reason' => 'Prazo passou; o plano precisa ser recalibrado.',
                'proposed_next_action' => 'Redefinir escopo mínimo e nova próxima ação',
                'review_interval_days' => 1,
            ];
        }

        if (in_array('deferred_ready', $reasons, true)) {
            $activeTask = $project->activeNextTask;
            $metadata = is_array($activeTask?->metadata) ? $activeTask->metadata : [];
            $defer = is_array($metadata['defer'] ?? null) ? $metadata['defer'] : [];
            $action = is_string($defer['recommended_action'] ?? null)
                ? (string) $defer['recommended_action']
                : 'recover';

            return [
                'action' => in_array($action, ['recover', 'rebuild_plan', 'ensure_next_action', 'postpone'], true) ? $action : 'recover',
                'label' => $this->deferReviewLabel($action),
                'reason' => $this->deferReviewReason($defer),
                'proposed_next_action' => is_string($defer['recommended_next_action'] ?? null)
                    ? $defer['recommended_next_action']
                    : ($project->next_action ?: 'Retomar por 10 minutos'),
                'review_interval_days' => 2,
                'target_minutes' => $defer['suggested_recovery_minutes'] ?? 10,
                'defer_reason_code' => $defer['last_reason_code'] ?? null,
            ];
        }

        if (($health['status'] ?? null) === 'paused') {
            return [
                'action' => 'postpone',
                'label' => 'Manter pausado',
                'reason' => 'Projeto pausado deve ter uma data explícita para voltar à revisão.',
                'proposed_next_action' => null,
                'review_interval_days' => 7,
            ];
        }

        if (in_array('stale', $reasons, true)) {
            return [
                'action' => 'recover',
                'label' => 'Retomar com micro-ação',
                'reason' => 'Projeto ativo ficou parado por muitos dias.',
                'proposed_next_action' => $project->next_action ?: 'Retomar por 10 minutos a menor parte visível',
                'review_interval_days' => 2,
            ];
        }

        return [
            'action' => 'mark_reviewed',
            'label' => 'Marcar revisado',
            'reason' => 'Projeto precisa apenas de revisão periódica.',
            'proposed_next_action' => $project->next_action,
            'review_interval_days' => 3,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{project: AtlasProject, task: AtlasTask|null}
     */
    public function reviewProject(AtlasProject $project, array $data, string $source = 'projects.review'): array
    {
        $action = (string) ($data['action'] ?? 'mark_reviewed');
        $days = $this->clamp((int) ($data['review_interval_days'] ?? 3), 1, 30);
        $task = null;

        if ($action === 'reactivate') {
            $task = $this->transitionStatus($project->refresh(), 'active', $data, $source);
        } elseif ($action === 'ensure_next_action') {
            if ($project->status !== 'active') {
                $task = $this->transitionStatus($project->refresh(), 'active', $data, $source);
            } else {
                $nextAction = trim((string) ($data['next_action'] ?? ''));
                if ($nextAction !== '') {
                    $data['next_action'] = $nextAction;
                }
                $plan = $this->inferPlan(null, $data, $project->title);
                $task = $this->ensureNextActionTask($project->refresh(), null, $data, $plan, $source);
            }
        } elseif ($action === 'rebuild_plan') {
            $plan = $this->inferPlan(null, $data, $project->title);
            $this->ensureProjectPlan($project->refresh(), $plan, true, $source);
            $task = $this->ensureNextActionTask($project->refresh(), null, $data, $plan, $source);
        } elseif ($action === 'recover') {
            $result = $this->recoverProject($project->refresh(), [
                ...$data,
                'target_minutes' => $data['target_minutes'] ?? 10,
            ], $source);
            $task = $result['task'];
        }

        $project->refresh()->forceFill([
            'last_touched_at' => now(),
            'next_review_at' => now()->addDays($days),
        ])->save();

        $this->recordEvent($project->refresh(), 'reviewed', [
            'action' => $action,
            'review_interval_days' => $days,
            'next_review_at' => $project->next_review_at?->toJSON(),
            'task_id' => $task?->id,
            'note' => $data['note'] ?? null,
        ], $source);

        return [
            'project' => $project->refresh(),
            'task' => $task,
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function executionPacket(AtlasProject $project, ?AtlasTask $task = null, array $context = []): array
    {
        $project->loadMissing(['activeNextTask', 'currentStep']);
        $task = $task ?: $project->activeNextTask ?: $this->activeNextActionTask($project);
        $step = $project->currentStep;

        if (! $task && $project->status === 'active') {
            $plan = $this->inferPlan(null, $context, $project->title);
            $task = $this->ensureNextActionTask($project->refresh(), null, $context, $plan, 'projects.execution');
            $project->refresh()->loadMissing(['activeNextTask', 'currentStep']);
            $step = $project->currentStep;
        }

        if (! $task) {
            return [
                'status' => 'no_active_action',
                'project_id' => $project->id,
                'task_id' => null,
                'step_id' => $step?->id,
                'title' => $project->next_action ?: $project->title,
                'mode' => $project->status,
                'timebox_minutes' => null,
                'starter_step' => 'Reativar ou revisar o projeto antes de tentar executar.',
                'minimum_viable_action' => null,
                'done_when' => null,
                'if_then_plan' => 'Se isso ainda importa, reative o projeto e peça uma próxima ação.',
                'reward_hint' => null,
                'low_energy_action' => 'Abrir o projeto e decidir se volta, pausa ou arquiva.',
                'avoid_now' => 'Tentar executar sem uma ação concreta.',
                'attempt_count' => 0,
                'why' => ['Projeto sem ação ativa. Primeiro precisa revisar, reativar ou criar uma próxima ação.'],
                'generated_at' => now()->toJSON(),
            ];
        }

        $availableMinutes = isset($context['available_minutes'])
            ? $this->clamp((int) $context['available_minutes'], 5, 240)
            : null;
        $energyLevel = isset($context['energy_level'])
            ? $this->clamp((int) $context['energy_level'], 1, 5)
            : null;
        $estimatedMinutes = $this->clamp((int) ($task->estimated_minutes ?: $step?->estimated_minutes ?: 25), 5, 480);
        $timebox = $this->executionTimebox($estimatedMinutes, $availableMinutes, $energyLevel, (int) ($task->friction_level ?? $step?->friction_level ?? 50));
        $starter = trim((string) ($task->starter_step ?: ($step ? $this->starterForStep($step) : null) ?: $this->starterStep($project->project_type ?: 'personal', $task->title)));
        $minimum = trim((string) ($task->minimum_viable_action ?: $step?->expected_output ?: $task->title));

        return [
            'status' => 'ready',
            'project_id' => $project->id,
            'task_id' => $task->id,
            'step_id' => $step?->id ?? $task->project_step_id,
            'title' => $task->title,
            'mode' => $task->execution_mode,
            'timebox_minutes' => $timebox,
            'starter_step' => $starter,
            'minimum_viable_action' => $minimum,
            'done_when' => $step?->acceptance_criteria ?: $task->minimum_viable_action ?: 'O avanço ficou registrado e existe uma próxima ação clara.',
            'if_then_plan' => $task->if_then_plan ?: $this->ifThenPlan($project->project_type ?: 'personal'),
            'reward_hint' => $task->reward_hint ?: $this->rewardHint($project->project_type ?: 'personal'),
            'low_energy_action' => $this->lowEnergyAction($project, $task, $step),
            'avoid_now' => $this->avoidNow($project, $task),
            'attempt_count' => (int) $task->attempt_count,
            'friction_level' => (int) ($task->friction_level ?? 50),
            'energy_required' => $task->energy_required,
            'next_review_at' => $project->next_review_at?->toJSON(),
            'why' => $this->executionWhy($project, $task, $step, $estimatedMinutes, $timebox, $availableMinutes, $energyLevel),
            'generated_at' => now()->toJSON(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{project: AtlasProject, task: AtlasTask|null, packet: array<string, mixed>}
     */
    public function startExecution(AtlasProject $project, array $data = [], string $source = 'projects.execution.start'): array
    {
        $project->loadMissing(['activeNextTask', 'currentStep']);
        $task = $project->activeNextTask ?: $this->activeNextActionTask($project);

        if (! $task && $project->status === 'active') {
            $plan = $this->inferPlan(null, $data, $project->title);
            $task = $this->ensureNextActionTask($project->refresh(), null, $data, $plan, $source);
        }

        if (! $task || $project->status !== 'active') {
            return [
                'project' => $project->refresh(),
                'task' => null,
                'packet' => $this->executionPacket($project->refresh(), null, $data),
            ];
        }

        $metadata = is_array($task->metadata) ? $task->metadata : [];
        $execution = is_array($metadata['execution'] ?? null) ? $metadata['execution'] : [];
        $metadata['execution'] = [
            ...$execution,
            'started_count' => (int) ($execution['started_count'] ?? 0) + 1,
            'last_started_at' => now()->toJSON(),
            'last_context' => [
                'available_minutes' => $data['available_minutes'] ?? null,
                'energy_level' => $data['energy_level'] ?? null,
                'environment' => $data['environment'] ?? null,
            ],
        ];

        $task->forceFill([
            'attempt_count' => max(0, (int) $task->attempt_count) + 1,
            'planning_status' => in_array($task->planning_status, ['unscheduled', 'suggested'], true) ? 'planned' : $task->planning_status,
            'metadata' => $metadata,
        ])->save();

        $project->forceFill([
            'last_touched_at' => now(),
            'next_review_at' => $project->next_review_at ?? now()->addDays(3),
        ])->save();

        $packet = $this->executionPacket($project->refresh(), $task->refresh(), $data);
        $this->planning->recordEvent($task, 'execution_started', [
            'project_id' => $project->id,
            'project_step_id' => $task->project_step_id,
            'timebox_minutes' => $packet['timebox_minutes'] ?? null,
            'starter_step' => $packet['starter_step'] ?? null,
            'attempt_count' => $task->attempt_count,
            'note' => $data['note'] ?? null,
        ], $source);
        $this->recordEvent($project->refresh(), 'execution_started', [
            'task_id' => $task->id,
            'project_step_id' => $task->project_step_id,
            'timebox_minutes' => $packet['timebox_minutes'] ?? null,
            'attempt_count' => $task->attempt_count,
        ], $source);

        return [
            'project' => $project->refresh(),
            'task' => $task->refresh(),
            'packet' => $packet,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{project: AtlasProject, task: AtlasTask, packet: array<string, mixed>}
     */
    public function recoverProject(AtlasProject $project, array $data = [], string $source = 'projects.recover'): array
    {
        $project->loadMissing(['activeNextTask', 'currentStep']);
        if ($project->status !== 'active') {
            $this->transitionStatus($project, 'active', $data, $source);
            $project->refresh()->loadMissing(['activeNextTask', 'currentStep']);
        }

        $task = $project->activeNextTask ?: $this->activeNextActionTask($project);
        if (! $task) {
            $plan = $this->inferPlan(null, $data, $project->title);
            $task = $this->ensureNextActionTask($project->refresh(), null, $data, $plan, $source);
        }

        $targetMinutes = $this->clamp((int) ($data['target_minutes'] ?? min((int) ($data['available_minutes'] ?? 10), 15)), 5, 45);
        $previous = [
            'title' => $task->title,
            'estimated_minutes' => $task->estimated_minutes,
            'energy_required' => $task->energy_required,
            'execution_mode' => $task->execution_mode,
            'failure_reason_last' => $task->failure_reason_last,
        ];
        $title = trim((string) ($data['next_action'] ?? ''));
        if ($title === '') {
            $title = $this->recoveryActionTitle($project, $task);
        }
        $starter = $this->recoveryStarterStep($project, $task, $targetMinutes);
        $minimum = $this->recoveryMinimumAction($project, $task, $targetMinutes);
        $updates = [
            'title' => $this->taskTitle($title),
            'status' => 'open',
            'estimated_minutes' => $targetMinutes,
            'energy_required' => 'low',
            'planning_status' => 'suggested',
            'execution_mode' => 'recovery',
            'friction_level' => min((int) ($task->friction_level ?: 50), 45),
            'emotional_resistance' => min((int) ($task->emotional_resistance ?: 50), 40),
            'clarity_level' => max((int) ($task->clarity_level ?: 60), 85),
            'starter_step' => $starter,
            'minimum_viable_action' => $minimum,
            'if_then_plan' => 'Se eu travar, faço dois minutos, registro onde parei e não replanejo o projeto inteiro.',
            'reward_hint' => 'Marcar a retomada como vitória e deixar a próxima ação escrita.',
            'failure_reason_last' => null,
            'recovery_count' => max(0, (int) $task->recovery_count) + 1,
        ];
        $metadata = is_array($task->metadata) ? $task->metadata : [];
        $recovery = is_array($metadata['recovery'] ?? null) ? $metadata['recovery'] : [];
        $metadata['recovery'] = [
            ...$recovery,
            'last_recovered_at' => now()->toJSON(),
            'last_reason' => $data['reason'] ?? null,
            'previous' => $previous,
            'target_minutes' => $targetMinutes,
            'source' => $source,
        ];

        $task->forceFill([
            ...$updates,
            ...$this->planning->attributesForTaskUpdate($task, $updates),
            'metadata' => $metadata,
        ])->save();

        $project->forceFill([
            'status' => 'active',
            'active_next_task_id' => $task->id,
            'next_action' => $task->title,
            'paused_until' => null,
            'last_touched_at' => now(),
            'next_review_at' => now()->addDays(2),
        ])->save();

        $this->planning->recordEvent($task->refresh(), 'recovery_action_created', [
            'project_id' => $project->id,
            'target_minutes' => $targetMinutes,
            'previous' => $previous,
            'reason' => $data['reason'] ?? null,
        ], $source);
        $this->recordEvent($project->refresh(), 'recovery_action_created', [
            'task_id' => $task->id,
            'target_minutes' => $targetMinutes,
            'previous' => $previous,
            'reason' => $data['reason'] ?? null,
        ], $source);

        return [
            'project' => $project->refresh(),
            'task' => $task->refresh(),
            'packet' => $this->executionPacket($project->refresh(), $task->refresh(), [
                ...$data,
                'available_minutes' => $targetMinutes,
            ]),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function recordTaskCompleted(AtlasTask $task, array $data = [], mixed $completedAt = null, string $source = 'tasks.complete'): array
    {
        $completed = $completedAt instanceof CarbonImmutable
            ? $completedAt
            : CarbonImmutable::parse($completedAt ?? now());
        $metadata = is_array($task->metadata) ? $task->metadata : [];
        $execution = is_array($metadata['execution'] ?? null) ? $metadata['execution'] : [];
        $actualMinutes = $this->actualMinutesForCompletion($task, $data, $completed);
        $estimatedMinutes = $this->clamp((int) ($task->estimated_minutes ?: 25), 5, 480);
        $estimateRatio = $actualMinutes ? round($actualMinutes / max(1, $estimatedMinutes), 2) : null;
        $quality = $this->completionQuality($data['completion_quality'] ?? null);
        $actionCompleted = in_array($quality, ['complete', 'learned'], true);
        $outcome = $this->completionText($data['outcome'] ?? $data['note'] ?? null, 1000);
        $evidence = $this->completionText($data['evidence'] ?? null, 1000);
        $blocker = $this->completionText($data['blocker'] ?? ($quality === 'blocked' ? ($data['outcome'] ?? $data['note'] ?? null) : null), 500);
        $nextHint = $this->completionText($data['next_hint'] ?? null, 500);
        $actualCount = (int) ($execution['actual_minutes_count'] ?? 0);
        $averageActual = is_numeric($execution['average_actual_minutes'] ?? null)
            ? (float) $execution['average_actual_minutes']
            : null;
        $ratioCount = (int) ($execution['estimate_ratio_count'] ?? 0);
        $averageRatio = is_numeric($execution['average_estimate_ratio'] ?? null)
            ? (float) $execution['average_estimate_ratio']
            : null;

        if ($actualMinutes !== null) {
            $averageActual = $averageActual === null
                ? $actualMinutes
                : (($averageActual * $actualCount) + $actualMinutes) / ($actualCount + 1);
            $actualCount += 1;
        }
        if ($estimateRatio !== null) {
            $averageRatio = $averageRatio === null
                ? $estimateRatio
                : (($averageRatio * $ratioCount) + $estimateRatio) / ($ratioCount + 1);
            $ratioCount += 1;
        }

        $summary = [
            'task_id' => $task->id,
            'project_id' => $task->project_id,
            'project_step_id' => $task->project_step_id,
            'completed_at' => $completed->toJSON(),
            'actual_minutes' => $actualMinutes,
            'estimated_minutes' => $estimatedMinutes,
            'estimate_ratio' => $estimateRatio,
            'completion_quality' => $quality,
            'action_completed' => $actionCompleted,
            'energy_after' => isset($data['energy_after']) ? $this->clamp((int) $data['energy_after'], 1, 5) : null,
            'outcome' => $outcome,
            'evidence' => $evidence,
            'blocker' => $blocker,
            'next_hint' => $nextHint,
            'calibrated_estimate_minutes' => $actualMinutes !== null
                ? $this->clamp((int) round($estimatedMinutes * 0.65 + $actualMinutes * 0.35), 5, 480)
                : null,
        ];

        $metadata['execution'] = [
            ...$execution,
            'execution_result_count' => (int) ($execution['execution_result_count'] ?? $execution['completion_count'] ?? 0) + 1,
            'completion_count' => (int) ($execution['completion_count'] ?? 0) + ($actionCompleted ? 1 : 0),
            'actual_minutes_count' => $actualCount,
            'average_actual_minutes' => $averageActual === null ? null : round($averageActual, 1),
            'estimate_ratio_count' => $ratioCount,
            'average_estimate_ratio' => $averageRatio === null ? null : round($averageRatio, 2),
            'last_result_at' => $completed->toJSON(),
            'last_completed_at' => $actionCompleted ? $completed->toJSON() : ($execution['last_completed_at'] ?? null),
            'last_actual_minutes' => $actualMinutes,
            'last_estimated_minutes' => $estimatedMinutes,
            'last_estimate_ratio' => $estimateRatio,
            'last_completion_quality' => $quality,
            'last_action_completed' => $actionCompleted,
            'last_energy_after' => $summary['energy_after'],
            'last_outcome' => $outcome,
            'last_evidence' => $evidence,
            'last_blocker' => $blocker,
            'last_next_hint' => $nextHint,
            'calibrated_estimate_minutes' => $summary['calibrated_estimate_minutes'],
        ];
        $task->forceFill([
            'failure_reason_last' => $quality === 'blocked'
                ? ($blocker ?? $outcome ?? $task->failure_reason_last)
                : ($actionCompleted ? null : $task->failure_reason_last),
            'metadata' => $metadata,
        ])->save();

        if ($task->project_step_id && Schema::hasTable('atlas_project_steps')) {
            $step = AtlasProjectStep::query()->find($task->project_step_id);
            if ($step) {
                $stepMetadata = is_array($step->metadata) ? $step->metadata : [];
                $stepLearning = is_array($stepMetadata['execution_learning'] ?? null) ? $stepMetadata['execution_learning'] : [];
                $stepMetadata['execution_learning'] = [
                    ...$stepLearning,
                    'last_task_id' => $task->id,
                    'last_actual_minutes' => $actualMinutes,
                    'last_estimated_minutes' => $estimatedMinutes,
                    'last_estimate_ratio' => $estimateRatio,
                    'average_estimate_ratio' => $averageRatio === null ? null : round($averageRatio, 2),
                    'last_result_at' => $completed->toJSON(),
                    'last_completed_at' => $actionCompleted ? $completed->toJSON() : ($stepLearning['last_completed_at'] ?? null),
                    'completion_quality' => $quality,
                    'action_completed' => $actionCompleted,
                    'outcome' => $outcome,
                    'evidence' => $evidence,
                    'blocker' => $blocker,
                ];
                $step->forceFill(['metadata' => $stepMetadata])->save();
            }
        }

        if ($task->project_id) {
            $project = AtlasProject::query()->find($task->project_id);
            if ($project) {
                $projectMetadata = is_array($project->metadata) ? $project->metadata : [];
                $learning = is_array($projectMetadata['execution_learning'] ?? null) ? $projectMetadata['execution_learning'] : [];
                $completedCount = (int) ($learning['completed_actions_count'] ?? 0) + ($actionCompleted ? 1 : 0);
                $projectActualCount = (int) ($learning['actual_minutes_count'] ?? 0);
                $projectRatioCount = (int) ($learning['estimate_ratio_count'] ?? 0);
                $projectAverage = is_numeric($learning['average_actual_minutes'] ?? null) ? (float) $learning['average_actual_minutes'] : null;
                $projectAverageRatio = is_numeric($learning['average_estimate_ratio'] ?? null) ? (float) $learning['average_estimate_ratio'] : null;
                if ($actualMinutes !== null) {
                    $projectAverage = $projectAverage === null
                        ? $actualMinutes
                        : (($projectAverage * $projectActualCount) + $actualMinutes) / ($projectActualCount + 1);
                    $projectActualCount += 1;
                }
                if ($estimateRatio !== null) {
                    $projectAverageRatio = $projectAverageRatio === null
                        ? $estimateRatio
                        : (($projectAverageRatio * $projectRatioCount) + $estimateRatio) / ($projectRatioCount + 1);
                    $projectRatioCount += 1;
                }
                $projectMetadata['execution_learning'] = [
                    ...$learning,
                    'execution_results_count' => (int) ($learning['execution_results_count'] ?? $learning['completed_actions_count'] ?? 0) + 1,
                    'completed_actions_count' => $completedCount,
                    'actual_minutes_count' => $projectActualCount,
                    'average_actual_minutes' => $projectAverage === null ? null : round($projectAverage, 1),
                    'estimate_ratio_count' => $projectRatioCount,
                    'average_estimate_ratio' => $projectAverageRatio === null ? null : round($projectAverageRatio, 2),
                    'last_completed_task_id' => $task->id,
                    'last_project_step_id' => $task->project_step_id,
                    'last_actual_minutes' => $actualMinutes,
                    'last_estimated_minutes' => $estimatedMinutes,
                    'last_estimate_ratio' => $estimateRatio,
                    'last_completion_quality' => $quality,
                    'last_action_completed' => $actionCompleted,
                    'last_result_at' => $completed->toJSON(),
                    'last_completed_at' => $actionCompleted ? $completed->toJSON() : ($learning['last_completed_at'] ?? null),
                    'last_outcome' => $outcome,
                    'last_evidence' => $evidence,
                    'last_blocker' => $blocker,
                    'last_next_hint' => $nextHint,
                    'estimate_bias' => $this->estimateBias($projectAverageRatio ?? $estimateRatio),
                ];
                $project->forceFill([
                    'last_touched_at' => now(),
                    'metadata' => $projectMetadata,
                ])->save();
                $this->recordEvent($project->refresh(), $this->executionResultEventType($quality), $summary, $source);
            }
        }

        $this->planning->recordEvent($task->refresh(), $this->executionResultEventType($quality), $summary, $source);

        return $summary;
    }

    /**
     * @return array<string, mixed>
     */
    public function recordTaskDeferred(AtlasTask $task, ?string $reason = null, mixed $deferUntil = null, string $source = 'tasks.defer', ?string $reasonCode = null): array
    {
        if (! $task->project_id) {
            return [];
        }

        $project = AtlasProject::query()->find($task->project_id);
        if (! $project) {
            return [];
        }

        $reasonCode = $this->deferReasonCode($reasonCode, $reason);
        $suggestion = $this->deferSuggestion($task, $project, $reasonCode, $reason);
        $metadata = is_array($task->metadata) ? $task->metadata : [];
        $defer = is_array($metadata['defer'] ?? null) ? $metadata['defer'] : [];
        $deferCount = (int) ($defer['count'] ?? 0) + 1;
        $metadata['defer'] = [
            ...$defer,
            'count' => $deferCount,
            'last_deferred_at' => now()->toJSON(),
            'last_defer_until' => method_exists($deferUntil, 'toJSON') ? $deferUntil->toJSON() : $deferUntil,
            'last_reason' => $reason,
            'last_reason_code' => $reasonCode,
            'recommended_action' => $suggestion['recommended_action'],
            'recommended_next_action' => $suggestion['recommended_next_action'],
            'suggested_recovery_minutes' => $suggestion['suggested_recovery_minutes'],
        ];
        $task->forceFill([
            'failure_reason_last' => $reason ?: $task->failure_reason_last,
            'recovery_count' => max(0, (int) $task->recovery_count) + 1,
            'metadata' => $metadata,
        ])->save();

        $projectMetadata = is_array($project->metadata) ? $project->metadata : [];
        $deferLearning = is_array($projectMetadata['defer_learning'] ?? null) ? $projectMetadata['defer_learning'] : [];
        $projectMetadata['defer_learning'] = [
            ...$deferLearning,
            'count' => (int) ($deferLearning['count'] ?? 0) + 1,
            'last_deferred_at' => now()->toJSON(),
            'last_task_id' => $task->id,
            'last_project_step_id' => $task->project_step_id,
            'last_reason' => $reason,
            'last_reason_code' => $reasonCode,
            'recommended_action' => $suggestion['recommended_action'],
            'recommended_next_action' => $suggestion['recommended_next_action'],
            'suggested_recovery_minutes' => $suggestion['suggested_recovery_minutes'],
        ];

        $updates = [
            'last_touched_at' => now(),
            'metadata' => $projectMetadata,
        ];
        if ($deferUntil) {
            $updates['next_review_at'] = $deferUntil;
        }
        $project->forceFill($updates)->save();

        $this->recordEvent($project->refresh(), 'active_action_deferred', [
            'task_id' => $task->id,
            'project_step_id' => $task->project_step_id,
            'defer_until' => method_exists($deferUntil, 'toJSON') ? $deferUntil->toJSON() : $deferUntil,
            'reason' => $reason,
            'reason_code' => $reasonCode,
            'recommended_action' => $suggestion['recommended_action'],
            'recommended_next_action' => $suggestion['recommended_next_action'],
            'suggested_recovery_minutes' => $suggestion['suggested_recovery_minutes'],
            'recovery_count' => $task->recovery_count,
        ], $source);

        return [
            'reason_code' => $reasonCode,
            ...$suggestion,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function recordEvent(AtlasProject $project, string $eventType, array $payload = [], string $source = 'app'): ?AtlasProjectEvent
    {
        if (! Schema::hasTable('atlas_project_events')) {
            return null;
        }

        return AtlasProjectEvent::query()->create([
            'project_id' => $project->id,
            'event_type' => $eventType,
            'source' => $source,
            'payload' => $payload,
            'occurred_at' => now(),
        ]);
    }

    private function deferReasonCode(?string $reasonCode, ?string $reason): string
    {
        if (in_array($reasonCode, ['low_energy', 'too_big', 'unclear', 'blocked', 'waiting', 'calendar', 'avoidance', 'not_now'], true)) {
            return (string) $reasonCode;
        }

        $lower = Str::lower((string) $reason);
        if (preg_match('/\b(energia|cansad|sono|exaust|sem energia)\b/u', $lower)) {
            return 'low_energy';
        }
        if (preg_match('/\b(grande|muito|complex|escopo|longo)\b/u', $lower)) {
            return 'too_big';
        }
        if (preg_match('/\b(confus|claro|clareza|nao sei|não sei)\b/u', $lower)) {
            return 'unclear';
        }
        if (preg_match('/\b(bloque|depend|trav|imped)\b/u', $lower)) {
            return 'blocked';
        }
        if (preg_match('/\b(esper|aguard|retorno|resposta)\b/u', $lower)) {
            return 'waiting';
        }
        if (preg_match('/\b(reuni[aã]o|agenda|hor[aá]rio|calend[aá]rio|compromisso)\b/u', $lower)) {
            return 'calendar';
        }
        if (preg_match('/\b(procrast|resist|evit|chato|tedioso)\b/u', $lower)) {
            return 'avoidance';
        }

        return 'not_now';
    }

    /**
     * @return array{recommended_action: string, recommended_next_action: string, suggested_recovery_minutes: int}
     */
    private function deferSuggestion(AtlasTask $task, AtlasProject $project, string $reasonCode, ?string $reason): array
    {
        $minutes = match ($reasonCode) {
            'low_energy', 'avoidance' => 10,
            'too_big', 'unclear' => 15,
            'blocked', 'waiting' => 10,
            default => min(20, max(10, (int) ($task->estimated_minutes ?: 10))),
        };
        $action = match ($reasonCode) {
            'too_big', 'unclear' => 'rebuild_plan',
            'blocked', 'waiting' => 'ensure_next_action',
            'calendar', 'not_now' => 'postpone',
            default => 'recover',
        };
        $nextAction = match ($reasonCode) {
            'too_big' => 'Quebrar a ação atual em uma versão de 10 a 15 minutos',
            'unclear' => 'Escrever o próximo passo físico antes de executar',
            'blocked' => 'Registrar o bloqueio e a menor ação para destravar',
            'waiting' => 'Registrar quem ou o que está sendo aguardado',
            'calendar' => $task->title,
            'not_now' => $task->title,
            'low_energy' => 'Retomar com uma versão de baixa energia por 10 minutos',
            'avoidance' => 'Começar por 2 minutos e manter só o primeiro bloco',
            default => $task->title,
        };

        return [
            'recommended_action' => $action,
            'recommended_next_action' => $nextAction,
            'suggested_recovery_minutes' => $this->clamp($minutes, 5, 45),
        ];
    }

    private function deferReviewLabel(string $action): string
    {
        return match ($action) {
            'rebuild_plan' => 'Replanejar ação adiada',
            'ensure_next_action' => 'Destravar próxima ação',
            'postpone' => 'Manter adiado',
            default => 'Retomar com micro-ação',
        };
    }

    /**
     * @param  array<string, mixed>  $defer
     */
    private function deferReviewReason(array $defer): string
    {
        $reasonCode = (string) ($defer['last_reason_code'] ?? 'not_now');

        return match ($reasonCode) {
            'low_energy' => 'A ação foi adiada por energia baixa; retomar pequeno evita perder o projeto.',
            'too_big' => 'A ação parece grande demais; precisa quebrar antes de voltar para a agenda.',
            'unclear' => 'A ação foi adiada por falta de clareza; precisa virar próximo passo físico.',
            'blocked' => 'A ação foi adiada por bloqueio; precisa explicitar dependência ou destravamento.',
            'waiting' => 'A ação depende de espera; confirme se ainda está aguardando ou se há alternativa.',
            'calendar' => 'A ação foi adiada por agenda; confirme nova janela real.',
            'avoidance' => 'A ação foi adiada por resistência; retomar com micro-ação é melhor que replanejar tudo.',
            default => 'A ação adiada voltou para revisão; escolha retomar, manter adiada ou replanejar.',
        };
    }

    private function recoveryActionTitle(AtlasProject $project, AtlasTask $task): string
    {
        $source = trim((string) ($task->starter_step ?: $task->minimum_viable_action ?: $task->title ?: $project->next_action ?: $project->title));
        $source = preg_replace('/\s+/u', ' ', $source) ?: $source;

        return mb_substr('Retomar: '.$source, 0, 180);
    }

    private function recoveryStarterStep(AtlasProject $project, AtlasTask $task, int $targetMinutes): string
    {
        $starter = trim((string) ($task->starter_step ?: 'abrir o contexto do projeto'));
        $starter = mb_strtolower(mb_substr($starter, 0, 140));

        return "Abrir {$project->title}, {$starter} e trabalhar {$targetMinutes} minutos sem replanejar tudo.";
    }

    private function recoveryMinimumAction(AtlasProject $project, AtlasTask $task, int $targetMinutes): string
    {
        $minimum = trim((string) ($task->minimum_viable_action ?: $task->title));
        $minimum = $minimum !== '' ? mb_substr($minimum, 0, 150) : "um microavanço em {$project->title}";

        return "{$targetMinutes} minutos de retomada ou {$minimum}.";
    }

    /**
     * @return array<int, string>
     */
    private function executionWhy(
        AtlasProject $project,
        AtlasTask $task,
        ?AtlasProjectStep $step,
        int $estimatedMinutes,
        int $timebox,
        ?int $availableMinutes,
        ?int $energyLevel,
    ): array {
        $why = [];
        $why[] = $step
            ? "É a etapa ativa {$step->step_order}: {$step->title}."
            : 'É a próxima ação ativa do projeto.';

        if ($availableMinutes !== null && $availableMinutes < $estimatedMinutes) {
            $why[] = "O bloco foi limitado a {$timebox}min porque você informou {$availableMinutes}min disponíveis.";
        } elseif ($energyLevel !== null && $energyLevel <= 2 && $timebox < $estimatedMinutes) {
            $why[] = "O bloco foi reduzido para {$timebox}min por energia baixa.";
        } elseif ($energyLevel !== null && $energyLevel <= 2) {
            $why[] = "Energia baixa foi considerada; a ação já cabe em {$timebox}min.";
        } elseif ((int) $task->friction_level >= 75 && $timebox < $estimatedMinutes) {
            $why[] = "O bloco foi reduzido para {$timebox}min porque a fricção está alta.";
        } else {
            $why[] = "A duração vem da estimativa atual de {$estimatedMinutes}min.";
        }

        $metadata = is_array($task->metadata) ? $task->metadata : [];
        $calibration = is_array($metadata['estimate_calibration'] ?? null) ? $metadata['estimate_calibration'] : [];
        if (($calibration['applied'] ?? false) && isset($calibration['base_minutes'], $calibration['calibrated_minutes'])) {
            $why[] = "A estimativa foi ajustada de {$calibration['base_minutes']}min para {$calibration['calibrated_minutes']}min pelo seu histórico de execução.";
        }

        if ($task->execution_mode === 'recovery') {
            $why[] = 'Modo retomada: a ação foi encurtada para vencer inércia sem replanejar tudo.';
        } elseif ((int) $task->recovery_count > 0) {
            $why[] = "Esta ação já teve {$task->recovery_count} retomada(s), então o Atlas deve manter o próximo passo pequeno.";
        }

        if ($project->next_review_at) {
            $why[] = 'O projeto fica marcado para revisão curta depois da execução.';
        }

        return array_values(array_filter($why));
    }

    private function calibratedEstimateForProject(AtlasProject $project, ?AtlasProjectStep $step, int $baseMinutes): int
    {
        $ratio = null;
        if ($step) {
            $stepMetadata = is_array($step->metadata) ? $step->metadata : [];
            $stepLearning = is_array($stepMetadata['execution_learning'] ?? null) ? $stepMetadata['execution_learning'] : [];
            $ratio = is_numeric($stepLearning['average_estimate_ratio'] ?? null)
                ? (float) $stepLearning['average_estimate_ratio']
                : (is_numeric($stepLearning['last_estimate_ratio'] ?? null) ? (float) $stepLearning['last_estimate_ratio'] : null);
        }

        if ($ratio === null) {
            $projectMetadata = is_array($project->metadata) ? $project->metadata : [];
            $learning = is_array($projectMetadata['execution_learning'] ?? null) ? $projectMetadata['execution_learning'] : [];
            $ratio = is_numeric($learning['average_estimate_ratio'] ?? null)
                ? (float) $learning['average_estimate_ratio']
                : (is_numeric($learning['last_estimate_ratio'] ?? null) ? (float) $learning['last_estimate_ratio'] : null);
        }

        if ($ratio === null || ($ratio > 0.85 && $ratio < 1.15)) {
            return $baseMinutes;
        }

        $factor = $ratio >= 1.15
            ? min(1.65, 1 + (($ratio - 1) * 0.45))
            : max(0.65, 1 - ((1 - $ratio) * 0.35));

        return $this->clamp((int) round($baseMinutes * $factor), 5, 480);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function actualMinutesForCompletion(AtlasTask $task, array $data, CarbonImmutable $completedAt): ?int
    {
        if (isset($data['actual_minutes']) && is_numeric($data['actual_minutes'])) {
            return $this->clamp((int) $data['actual_minutes'], 1, 1440);
        }

        $metadata = is_array($task->metadata) ? $task->metadata : [];
        $execution = is_array($metadata['execution'] ?? null) ? $metadata['execution'] : [];
        $startedAt = isset($execution['last_started_at']) && is_string($execution['last_started_at'])
            ? CarbonImmutable::parse($execution['last_started_at'])
            : null;
        if (! $startedAt || $completedAt->lte($startedAt)) {
            return null;
        }

        return $this->clamp((int) max(1, round($startedAt->diffInMinutes($completedAt))), 1, 1440);
    }

    private function completionQuality(mixed $quality): string
    {
        return in_array($quality, ['complete', 'partial', 'learned', 'blocked'], true)
            ? (string) $quality
            : 'complete';
    }

    private function executionResultEventType(string $quality): string
    {
        return match ($quality) {
            'partial' => 'execution_progress_recorded',
            'blocked' => 'execution_blocked',
            default => 'execution_completed',
        };
    }

    private function completionText(mixed $value, int $limit): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $text = trim(preg_replace('/\s+/u', ' ', $value) ?? '');
        if ($text === '') {
            return null;
        }

        return mb_substr($text, 0, $limit);
    }

    private function estimateBias(?float $ratio): ?string
    {
        if ($ratio === null) {
            return null;
        }
        if ($ratio >= 1.35) {
            return 'underestimated';
        }
        if ($ratio <= 0.7) {
            return 'overestimated';
        }

        return 'calibrated';
    }

    private function executionTimebox(int $estimatedMinutes, ?int $availableMinutes, ?int $energyLevel, int $frictionLevel): int
    {
        $base = $availableMinutes ? min($estimatedMinutes, $availableMinutes) : $estimatedMinutes;

        if ($energyLevel !== null && $energyLevel <= 2) {
            $base = min($base, 15);
        } elseif ($frictionLevel >= 75) {
            $base = min($base, 20);
        } elseif ($estimatedMinutes >= 60) {
            $base = min($base, 45);
        }

        return $this->clamp($base, 5, 90);
    }

    private function lowEnergyAction(AtlasProject $project, AtlasTask $task, ?AtlasProjectStep $step): string
    {
        $type = $project->project_type ?: 'personal';

        return match ($type) {
            'study' => 'Abrir a fonte e escrever uma pergunta ou três bullets, sem tentar estudar tudo.',
            'technical_build' => 'Abrir o repositório e escrever o próximo commit pequeno antes de codar.',
            'writing' => 'Escrever cinco linhas ruins e parar com a próxima frase indicada.',
            'tedious', 'admin' => 'Fazer só dois minutos do primeiro pedaço e registrar onde parou.',
            default => $task->starter_step ?: $step?->expected_output ?: 'Abrir o material e deixar a próxima ação visível.',
        };
    }

    private function avoidNow(AtlasProject $project, AtlasTask $task): string
    {
        if ((int) $task->friction_level >= 75) {
            return 'Replanejar o projeto inteiro antes de iniciar a menor ação.';
        }

        return match ($project->project_type ?: 'personal') {
            'study' => 'Colecionar mais fontes antes de responder uma pergunta simples.',
            'technical_build' => 'Aumentar escopo, trocar stack ou refatorar antes do primeiro incremento.',
            'writing' => 'Editar a primeira frase antes de existir rascunho suficiente.',
            'tedious', 'admin' => 'Organizar o ambiente inteiro para evitar começar.',
            default => 'Transformar a próxima ação em uma lista grande demais.',
        };
    }

    private function moveOpenProjectTasks(AtlasProject $project, string $status): int
    {
        if (! Schema::hasTable('atlas_tasks')) {
            return 0;
        }

        return AtlasTask::query()
            ->where('project_id', $project->id)
            ->whereIn('status', ['open', 'next', 'waiting'])
            ->update([
                'status' => $status,
                'updated_at' => now(),
            ]);
    }

    private function activeNextActionTask(AtlasProject $project): ?AtlasTask
    {
        if ($project->active_next_task_id) {
            $task = AtlasTask::query()
                ->whereKey($project->active_next_task_id)
                ->where('project_id', $project->id)
                ->whereIn('status', ['open', 'next', 'waiting'])
                ->first();

            if ($task) {
                return $task;
            }
        }

        $step = $this->currentStepForProject($project);
        if ($step?->active_task_id) {
            $task = AtlasTask::query()
                ->whereKey($step->active_task_id)
                ->where('project_id', $project->id)
                ->where('project_step_id', $step->id)
                ->whereIn('status', ['open', 'next', 'waiting'])
                ->first();

            if ($task) {
                return $task;
            }
        }

        if ($step) {
            return AtlasTask::query()
                ->where('project_id', $project->id)
                ->where('project_step_id', $step->id)
                ->whereIn('status', ['open', 'next', 'waiting'])
                ->orderByDesc('priority_score')
                ->orderByDesc('created_at')
                ->first();
        }

        return AtlasTask::query()
            ->where('project_id', $project->id)
            ->whereNull('project_step_id')
            ->whereIn('status', ['open', 'next', 'waiting'])
            ->orderByDesc('priority_score')
            ->orderByDesc('created_at')
            ->first();
    }

    private function currentStepForProject(AtlasProject $project): ?AtlasProjectStep
    {
        if (! Schema::hasTable('atlas_project_steps')) {
            return null;
        }

        if ($project->current_step_id) {
            $step = AtlasProjectStep::query()
                ->whereKey($project->current_step_id)
                ->where('project_id', $project->id)
                ->whereIn('status', ['active', 'pending', 'blocked'])
                ->first();

            if ($step) {
                return $step;
            }
        }

        return AtlasProjectStep::query()
            ->where('project_id', $project->id)
            ->where('status', 'active')
            ->orderBy('step_order')
            ->first()
            ?? AtlasProjectStep::query()
                ->where('project_id', $project->id)
                ->whereIn('status', ['pending', 'blocked'])
                ->orderBy('step_order')
                ->first();
    }

    /**
     * @param  Collection<int, AtlasProjectStep>  $steps
     */
    private function ensureCurrentStep(AtlasProject $project, Collection $steps, string $source): ?AtlasProjectStep
    {
        $current = $steps->first(fn (AtlasProjectStep $step): bool => $step->status === 'active')
            ?? $steps->first(fn (AtlasProjectStep $step): bool => in_array($step->status, ['pending', 'blocked'], true));

        if (! $current) {
            return null;
        }

        if ($current->status !== 'active') {
            $current->forceFill([
                'status' => 'active',
                'started_at' => $current->started_at ?? now(),
            ])->save();
            $this->recordEvent($project, 'step_activated', [
                'step_id' => $current->id,
                'step_order' => $current->step_order,
                'step_title' => $current->title,
            ], $source);
        }

        if ($project->current_step_id !== $current->id) {
            $project->forceFill([
                'current_step_id' => $current->id,
                'next_action' => $current->title,
                'last_touched_at' => now(),
            ])->save();
        }

        return $current->refresh();
    }

    /**
     * @return array<string, mixed>
     */
    private function standalonePlanningAttributes(array $plan): array
    {
        $estimatedMinutes = $this->clamp((int) ($plan['estimated_minutes'] ?? 25), 5, 480);
        $priorityScore = match ($plan['priority'] ?? 'normal') {
            'urgent' => 92,
            'high' => 76,
            'low' => 25,
            default => 50,
        };

        return [
            'planned_for_date' => null,
            'planned_start_at' => null,
            'planned_end_at' => null,
            'estimated_minutes' => $estimatedMinutes,
            'energy_required' => $plan['energy_required'] ?? 'medium',
            'urgency_score' => $priorityScore,
            'impact_score' => $this->clamp($priorityScore + 8, 0, 100),
            'effort_score' => $this->clamp((int) round(($estimatedMinutes / 120) * 70), 0, 100),
            'priority_score' => $priorityScore,
            'planning_status' => 'suggested',
        ];
    }

    private function projectType(string $lower): string
    {
        if (preg_match('/\b(estudar|estudo|aprender|curso|aula|prova|investimento|livro|mat[ée]ria)\b/u', $lower)) {
            return 'study';
        }
        if (preg_match('/\b(app|aplicativo|backend|front|frontend|servidor|api|c[oó]digo|implementar|deploy|stack|mac|ios)\b/u', $lower)) {
            return 'technical_build';
        }
        if (preg_match('/\b(escrever|texto|artigo|roteiro|conte[úu]do|documenta[cç][aã]o)\b/u', $lower)) {
            return 'writing';
        }
        if (preg_match('/\b(cliente|receita|venda|produto|neg[oó]cio|black ink|lan[cç]ar)\b/u', $lower)) {
            return 'business';
        }
        if (preg_match('/\b(sa[úu]de|m[eé]dico|exame|treino|sono|acne|terapia)\b/u', $lower)) {
            return 'health';
        }
        if (preg_match('/\b(chat[oa]s?|tedios[oa]s?|arrumar|organizar|limpar|pagar|burocracia)\b/u', $lower)) {
            return 'tedious';
        }

        return 'personal';
    }

    private function priority(mixed $priority, string $lower): string
    {
        if (in_array($priority, ['low', 'normal', 'high', 'urgent'], true)) {
            return (string) $priority;
        }
        if (preg_match('/\b(hoje|urgente|prazo|deadline|bloqueando|cr[ií]tico)\b/u', $lower)) {
            return 'urgent';
        }
        if (preg_match('/\b(importante|cliente|sa[úu]de|receita|decis[aã]o|atlas|black ink)\b/u', $lower)) {
            return 'high';
        }

        return 'normal';
    }

    private function defaultNextAction(string $type, string $title, string $lower): string
    {
        return match ($type) {
            'study' => 'Definir a primeira pergunta de estudo e estudar por 25 minutos',
            'technical_build' => 'Definir o escopo mínimo e a próxima entrega executável',
            'writing' => 'Criar um rascunho de 10 linhas com a tese principal',
            'business' => 'Escrever a hipótese de valor e a próxima validação',
            'health' => 'Registrar o estado atual e escolher a menor ação segura',
            'tedious' => 'Abrir o material e executar 10 minutos sem otimizar',
            default => str_contains($lower, 'decidir') ? 'Listar as opções e escolher o próximo teste' : 'Definir a menor próxima ação executável',
        };
    }

    private function desiredOutcome(string $type, string $title): string
    {
        return match ($type) {
            'study' => "Entender {$title} a ponto de explicar e aplicar sem travar.",
            'technical_build' => "Transformar {$title} em uma entrega funcional e testável.",
            'business' => "Converter {$title} em uma decisão ou validação prática.",
            default => "Dar forma executável a {$title}.",
        };
    }

    private function minimumViableOutcome(string $type, string $title): string
    {
        return match ($type) {
            'study' => 'Uma página de síntese com 3 conceitos, 3 exemplos e 1 próxima pergunta.',
            'technical_build' => 'Um MVP pequeno, rodando, com próximo passo evidente.',
            'writing' => 'Um rascunho bruto que possa ser editado.',
            default => "Um avanço visível em {$title}, pequeno o suficiente para começar hoje.",
        };
    }

    private function definitionOfDone(string $type, string $title): string
    {
        return match ($type) {
            'study' => 'A síntese está registrada e existe uma ação de revisão.',
            'technical_build' => 'O incremento foi implementado, testado e documentado.',
            'business' => 'A hipótese foi validada, descartada ou virou próxima decisão.',
            default => "{$title} tem resultado, evidência e próximo destino definidos.",
        };
    }

    private function starterStep(string $type, string $nextAction): string
    {
        return match ($type) {
            'study' => 'Abrir a fonte principal e escrever a pergunta no topo.',
            'technical_build' => 'Abrir o repositório e escrever o menor escopo em 3 bullets.',
            'writing' => 'Abrir uma nota vazia e escrever a primeira frase ruim.',
            'tedious' => 'Começar por 2 minutos sem organizar o ambiente inteiro.',
            default => 'Abrir o lugar de trabalho e escrever a primeira micro-ação.',
        };
    }

    private function minimumViableAction(string $type, string $nextAction): string
    {
        return match ($type) {
            'study' => 'Ler por 10 minutos e registrar 3 bullets.',
            'technical_build' => 'Criar ou revisar um checklist técnico com o próximo commit.',
            'tedious' => 'Executar apenas o primeiro bloco de 10 minutos.',
            default => mb_substr($nextAction, 0, 180),
        };
    }

    private function ifThenPlan(string $type): string
    {
        return match ($type) {
            'study' => 'Se eu travar, reduzo para uma pergunta e um exemplo.',
            'technical_build' => 'Se o escopo crescer, volto para o menor incremento testável.',
            'tedious' => 'Se eu resistir, faço só 2 minutos e encerro com próximo passo escrito.',
            default => 'Se eu travar, escrevo a menor ação física possível e faço por 5 minutos.',
        };
    }

    private function rewardHint(string $type): string
    {
        return match ($type) {
            'study' => 'Marcar uma síntese visível depois do bloco.',
            'technical_build' => 'Fechar com um commit, teste ou checklist verde.',
            'tedious' => 'Parar no tempo combinado e registrar que começou.',
            default => 'Registrar progresso concreto antes de abrir outro ciclo.',
        };
    }

    private function processSteps(string $type, string $title): array
    {
        return array_map(fn (array $step): string => (string) $step['title'], $this->projectStepSpecs($type, $title));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function projectStepSpecs(string $type, string $title): array
    {
        return match ($type) {
            'study' => [
                [
                    'title' => 'Definir pergunta de estudo',
                    'description' => 'Escolher uma pergunta única para evitar estudo aberto demais.',
                    'expected_output' => 'Uma pergunta de estudo escrita em linguagem simples.',
                    'acceptance_criteria' => 'Existe uma pergunta que pode ser respondida em um bloco curto.',
                    'estimated_minutes' => 10,
                    'energy_required' => 'low',
                    'friction_level' => 35,
                ],
                [
                    'title' => 'Estudar bloco curto',
                    'description' => 'Fazer um bloco de estudo com escopo fechado.',
                    'expected_output' => 'Três bullets com conceitos ou dúvidas úteis.',
                    'acceptance_criteria' => 'O bloco foi concluído sem tentar cobrir o assunto inteiro.',
                    'estimated_minutes' => 25,
                    'energy_required' => 'medium',
                    'friction_level' => 55,
                ],
                [
                    'title' => 'Registrar síntese atômica',
                    'description' => 'Transformar o bloco em uma nota curta e reutilizável.',
                    'expected_output' => 'Uma síntese com tese, exemplo e dúvida aberta.',
                    'acceptance_criteria' => 'A síntese explica algo sem depender da fonte original.',
                    'estimated_minutes' => 20,
                    'energy_required' => 'medium',
                    'friction_level' => 50,
                ],
                [
                    'title' => 'Aplicar em exemplo',
                    'description' => 'Usar o que foi estudado em um exemplo concreto.',
                    'expected_output' => 'Um exemplo resolvido ou uma decisão prática.',
                    'acceptance_criteria' => 'O conhecimento saiu do resumo e virou uso.',
                    'estimated_minutes' => 25,
                    'energy_required' => 'medium',
                    'friction_level' => 58,
                ],
                [
                    'title' => 'Agendar revisão',
                    'description' => 'Criar uma revisão curta para consolidar o aprendizado.',
                    'expected_output' => 'Próxima revisão registrada.',
                    'acceptance_criteria' => 'Existe uma data ou captura de revisão.',
                    'estimated_minutes' => 10,
                    'energy_required' => 'low',
                    'friction_level' => 30,
                ],
            ],
            'technical_build' => [
                [
                    'title' => 'Definir resultado mínimo',
                    'description' => 'Escrever o menor comportamento que prova valor.',
                    'expected_output' => 'Escopo mínimo em até cinco bullets.',
                    'acceptance_criteria' => 'É possível testar o resultado sem construir o sistema inteiro.',
                    'estimated_minutes' => 20,
                    'energy_required' => 'medium',
                    'friction_level' => 45,
                ],
                [
                    'title' => 'Mapear stack e restrições',
                    'description' => 'Listar tecnologia, dados, APIs e riscos antes de codar.',
                    'expected_output' => 'Mapa curto de stack, restrições e riscos.',
                    'acceptance_criteria' => 'As decisões técnicas críticas estão explícitas.',
                    'estimated_minutes' => 30,
                    'energy_required' => 'medium',
                    'friction_level' => 52,
                ],
                [
                    'title' => 'Implementar backend mínimo',
                    'description' => 'Criar a menor base persistente/API necessária.',
                    'expected_output' => 'Endpoint, serviço ou persistência mínima funcionando.',
                    'acceptance_criteria' => 'Há teste ou verificação objetiva do backend.',
                    'estimated_minutes' => 60,
                    'energy_required' => 'high',
                    'friction_level' => 72,
                ],
                [
                    'title' => 'Implementar frontend mínimo',
                    'description' => 'Construir a menor interface que usa o backend.',
                    'expected_output' => 'Tela ou fluxo principal funcionando.',
                    'acceptance_criteria' => 'O usuário consegue executar o fluxo sem instrução externa.',
                    'estimated_minutes' => 60,
                    'energy_required' => 'high',
                    'friction_level' => 72,
                ],
                [
                    'title' => 'Testar fluxo principal',
                    'description' => 'Validar ponta a ponta e corrigir regressões óbvias.',
                    'expected_output' => 'Fluxo testado com resultado registrado.',
                    'acceptance_criteria' => 'A verificação cobre o caminho principal.',
                    'estimated_minutes' => 35,
                    'energy_required' => 'medium',
                    'friction_level' => 50,
                ],
                [
                    'title' => 'Documentar próxima decisão',
                    'description' => 'Registrar o que foi feito e a próxima escolha técnica.',
                    'expected_output' => 'Nota curta com decisão, evidência e próximo passo.',
                    'acceptance_criteria' => 'O projeto pode ser retomado sem reconstruir contexto.',
                    'estimated_minutes' => 15,
                    'energy_required' => 'low',
                    'friction_level' => 35,
                ],
            ],
            'tedious' => [
                [
                    'title' => 'Reduzir escopo para 10 minutos',
                    'description' => 'Cortar a tarefa para uma versão quase ridiculamente pequena.',
                    'expected_output' => 'Um primeiro bloco que cabe em 10 minutos.',
                    'acceptance_criteria' => 'A ação não exige motivação alta.',
                    'estimated_minutes' => 10,
                    'energy_required' => 'low',
                    'friction_level' => 40,
                ],
                [
                    'title' => 'Preparar ambiente mínimo',
                    'description' => 'Abrir só o necessário para começar.',
                    'expected_output' => 'Ambiente aberto no ponto exato de execução.',
                    'acceptance_criteria' => 'Não houve organização paralela desnecessária.',
                    'estimated_minutes' => 10,
                    'energy_required' => 'low',
                    'friction_level' => 45,
                ],
                [
                    'title' => 'Executar sem otimizar',
                    'description' => 'Fazer o bloco combinado sem procurar a forma perfeita.',
                    'expected_output' => 'Primeiro avanço concreto produzido.',
                    'acceptance_criteria' => 'Existe evidência de começo, mesmo imperfeita.',
                    'estimated_minutes' => 15,
                    'energy_required' => 'low',
                    'friction_level' => 65,
                ],
                [
                    'title' => 'Registrar próximo passo',
                    'description' => 'Fechar o ciclo deixando a continuação clara.',
                    'expected_output' => 'Próxima ação registrada.',
                    'acceptance_criteria' => 'Retomar não exige decidir tudo de novo.',
                    'estimated_minutes' => 5,
                    'energy_required' => 'low',
                    'friction_level' => 25,
                ],
            ],
            default => [
                [
                    'title' => "Clarificar {$title}",
                    'description' => 'Definir resultado, limites e motivo.',
                    'expected_output' => 'Projeto explicado em poucas linhas.',
                    'acceptance_criteria' => 'O próximo passo não depende de pensar no projeto inteiro.',
                    'estimated_minutes' => 15,
                    'energy_required' => 'low',
                    'friction_level' => 40,
                ],
                [
                    'title' => 'Escolher próxima ação',
                    'description' => 'Converter intenção em ação física e observável.',
                    'expected_output' => 'Uma ação única, rápida e sem ambiguidade.',
                    'acceptance_criteria' => 'A ação pode entrar na agenda.',
                    'estimated_minutes' => 10,
                    'energy_required' => 'low',
                    'friction_level' => 35,
                ],
                [
                    'title' => 'Executar bloco curto',
                    'description' => 'Avançar sem tentar concluir tudo.',
                    'expected_output' => 'Um incremento concreto.',
                    'acceptance_criteria' => 'Há evidência de avanço.',
                    'estimated_minutes' => 25,
                    'energy_required' => 'medium',
                    'friction_level' => 55,
                ],
                [
                    'title' => 'Registrar evidência',
                    'description' => 'Fechar o ciclo com resultado e próximo passo.',
                    'expected_output' => 'Registro do que mudou e do que vem depois.',
                    'acceptance_criteria' => 'O projeto pode ser retomado rapidamente.',
                    'estimated_minutes' => 10,
                    'energy_required' => 'low',
                    'friction_level' => 30,
                ],
            ],
        };
    }

    private function executionMode(string $type, int $estimatedMinutes, string $lower): string
    {
        if ($type === 'study') {
            return 'study';
        }
        if ($type === 'technical_build' || $estimatedMinutes >= 50) {
            return 'deep_work';
        }
        if ($type === 'tedious') {
            return 'tedious';
        }
        if (preg_match('/\b(decidir|escolher|priorizar)\b/u', $lower)) {
            return 'decision';
        }

        return 'quick_win';
    }

    private function estimatedMinutes(string $type, string $lower): int
    {
        if ($type === 'technical_build') {
            return 60;
        }
        if ($type === 'study') {
            return 25;
        }
        if ($type === 'tedious') {
            return 15;
        }
        if (preg_match('/\b(ligar|enviar|pagar|colocar|marcar|responder|comprar)\b/u', $lower)) {
            return 15;
        }

        return 25;
    }

    private function energyRequired(string $type, int $estimatedMinutes, string $priority): string
    {
        if ($type === 'technical_build' || $estimatedMinutes >= 50 || $priority === 'urgent') {
            return 'high';
        }
        if ($type === 'tedious' || $estimatedMinutes <= 15) {
            return 'low';
        }

        return 'medium';
    }

    private function energyProfile(string $energyRequired, string $type): string
    {
        if ($type === 'technical_build') {
            return 'mixed';
        }

        return in_array($energyRequired, ['low', 'medium', 'high'], true) ? $energyRequired : 'mixed';
    }

    private function avoidanceReason(string $lower, int $estimatedMinutes): string
    {
        if (preg_match('/\b(chat[oa]s?|tedios[oa]s?|burocracia|pregui[cç]a)\b/u', $lower)) {
            return 'boring';
        }
        if (preg_match('/\b(medo|dif[ií]cil|assusta|ansiedade|complexo)\b/u', $lower)) {
            return 'scary';
        }
        if (preg_match('/\b(perfeito|perfeccionismo|polir|premium)\b/u', $lower)) {
            return 'perfectionism';
        }
        if ($estimatedMinutes >= 50) {
            return 'too_large';
        }

        return 'unknown';
    }

    private function frictionLevel(string $avoidanceReason, int $estimatedMinutes): int
    {
        $base = match ($avoidanceReason) {
            'boring' => 70,
            'scary', 'perfectionism', 'too_large' => 78,
            default => 45,
        };

        return $this->clamp($base + ($estimatedMinutes >= 50 ? 8 : 0), 0, 100);
    }

    private function emotionalResistance(string $avoidanceReason): int
    {
        return match ($avoidanceReason) {
            'scary', 'perfectionism' => 78,
            'boring', 'too_large' => 64,
            default => 42,
        };
    }

    private function clarityLevel(string $lower, array $data): int
    {
        if (! empty($data['next_action']) || ! empty($data['goal'])) {
            return 76;
        }
        if (preg_match('/\b(algum|coisa|ideia|talvez|pensar|ver)\b/u', $lower)) {
            return 48;
        }

        return 62;
    }

    private function taskTitle(string $title): string
    {
        return mb_substr(trim($title) ?: 'Definir próxima ação do projeto', 0, 180);
    }

    private function taskDescription(AtlasProject $project, ?Capture $capture, array $plan): ?string
    {
        $parts = array_filter([
            $project->goal ? "Objetivo: {$project->goal}" : null,
            isset($plan['minimum_viable_action']) ? "Ação mínima: {$plan['minimum_viable_action']}" : null,
            isset($plan['starter_step']) ? "Passo inicial: {$plan['starter_step']}" : null,
            $capture?->content_text ? "Fonte: {$capture->content_text}" : null,
        ]);

        return $parts ? implode("\n", $parts) : null;
    }

    private function stepTaskDescription(AtlasProject $project, AtlasProjectStep $step, ?Capture $capture): ?string
    {
        $parts = array_filter([
            $project->goal ? "Objetivo: {$project->goal}" : null,
            $step->description ? "Etapa: {$step->description}" : null,
            $step->expected_output ? "Saída esperada: {$step->expected_output}" : null,
            $step->acceptance_criteria ? "Critério: {$step->acceptance_criteria}" : null,
            $capture?->content_text ? "Fonte: {$capture->content_text}" : null,
        ]);

        return $parts ? implode("\n", $parts) : null;
    }

    private function executionModeForStep(AtlasProject $project, AtlasProjectStep $step): string
    {
        if ($project->project_type === 'study') {
            return 'study';
        }
        if ($project->project_type === 'technical_build' || $step->estimated_minutes >= 50) {
            return 'deep_work';
        }
        if ($project->project_type === 'tedious') {
            return 'tedious';
        }
        if ($step->step_type === 'review') {
            return 'maintenance';
        }

        return 'quick_win';
    }

    private function starterForStep(AtlasProjectStep $step): string
    {
        return match ($step->step_type) {
            'review' => 'Abrir o registro do projeto e revisar apenas a etapa atual.',
            'milestone' => 'Conferir a saída esperada e marcar evidência.',
            default => 'Abrir o contexto do projeto e fazer somente esta etapa.',
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function planFromStep(AtlasProject $project, AtlasProjectStep $step): array
    {
        return [
            'project_type' => $project->project_type,
            'priority' => $project->priority,
            'deadline_at' => $project->deadline_at,
            'next_action' => $step->title,
            'estimated_minutes' => $step->estimated_minutes,
            'energy_required' => $step->energy_required,
            'friction_level' => $step->friction_level,
            'emotional_resistance' => $project->avoidance_reason === 'unknown' ? 45 : 65,
            'clarity_level' => 80,
            'starter_step' => $this->starterForStep($step),
            'minimum_viable_action' => $step->expected_output,
            'if_then_plan' => $this->ifThenPlan((string) $project->project_type),
            'reward_hint' => $this->rewardHint((string) $project->project_type),
            'process_steps' => $this->processSteps((string) $project->project_type, $project->title),
        ];
    }

    private function clamp(int $value, int $min, int $max): int
    {
        return max($min, min($max, $value));
    }

    private function validEnergy(mixed $energy): string
    {
        return in_array($energy, ['low', 'medium', 'high'], true) ? (string) $energy : 'medium';
    }
}
