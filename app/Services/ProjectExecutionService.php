<?php

namespace App\Services;

use App\Models\AtlasProject;
use App\Models\AtlasProjectBlocker;
use App\Models\AtlasProjectEvent;
use App\Models\AtlasProjectStep;
use App\Models\AtlasTask;
use App\Models\Capture;
use App\Services\Ai\Support\DatabaseTableAvailability;
use App\Services\ProjectExecution\DeferRecoverySection;
use App\Services\ProjectExecution\ExecutionHelpersSection;
use App\Services\ProjectExecution\PlanHeuristicsSection;
use App\Services\ProjectExecution\ProjectExecutionSupport;
use App\Support\ProjectExecutionHealth;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ProjectExecutionService
{
    private readonly ProjectExecutionSupport $support;

    private readonly PlanHeuristicsSection $planHeuristics;

    private readonly ExecutionHelpersSection $executionHelpers;

    private readonly DeferRecoverySection $deferRecovery;

    public function __construct(
        private readonly TaskPlanningService $planning,
        private readonly ProjectBlockerService $blockers,
    ) {
        $this->support = new ProjectExecutionSupport;
        $this->planHeuristics = new PlanHeuristicsSection($this->support);
        $this->executionHelpers = new ExecutionHelpersSection($this->support);
        $this->deferRecovery = new DeferRecoverySection($this->support);
    }

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
        $type = $this->planHeuristics->projectType($lower);
        $priority = $this->planHeuristics->priority($data['priority'] ?? null, $lower);
        $nextAction = trim((string) ($data['next_action'] ?? '')) ?: $this->planHeuristics->defaultNextAction($type, $title, $lower);
        $starterStep = $this->planHeuristics->starterStep($type, $nextAction);
        $estimatedMinutes = $this->planHeuristics->estimatedMinutes($type, $lower);
        $energyRequired = $this->planHeuristics->energyRequired($type, $estimatedMinutes, $priority);
        $avoidanceReason = $this->planHeuristics->avoidanceReason($lower, $estimatedMinutes);
        $processSteps = $this->planHeuristics->processSteps($type, $title);

        return [
            'project_type' => $type,
            'desired_outcome' => $data['desired_outcome'] ?? ($data['goal'] ?? $this->planHeuristics->desiredOutcome($type, $title)),
            'minimum_viable_outcome' => $data['minimum_viable_outcome'] ?? $this->planHeuristics->minimumViableOutcome($type, $title),
            'definition_of_done' => $data['definition_of_done'] ?? $this->planHeuristics->definitionOfDone($type, $title),
            'why_now' => $data['why_now'] ?? ($data['reason'] ?? null),
            'deadline_at' => $data['deadline_at'] ?? ($data['due_at'] ?? null),
            'deadline_kind' => $data['deadline_kind'] ?? (($data['due_at'] ?? null) ? 'desired' : 'none'),
            'priority' => $priority,
            'energy_profile' => $this->planHeuristics->energyProfile($energyRequired, $type),
            'avoidance_reason' => $avoidanceReason,
            'next_action' => $nextAction,
            'starter_step' => $starterStep,
            'minimum_viable_action' => $this->planHeuristics->minimumViableAction($type, $nextAction),
            'if_then_plan' => $this->planHeuristics->ifThenPlan($type),
            'reward_hint' => $this->planHeuristics->rewardHint($type),
            'execution_mode' => $this->planHeuristics->executionMode($type, $estimatedMinutes, $lower),
            'estimated_minutes' => $estimatedMinutes,
            'energy_required' => $energyRequired,
            'friction_level' => $this->planHeuristics->frictionLevel($avoidanceReason, $estimatedMinutes),
            'emotional_resistance' => $this->planHeuristics->emotionalResistance($avoidanceReason),
            'clarity_level' => $this->planHeuristics->clarityLevel($lower, $data),
            'process_steps' => $processSteps,
            'project_steps' => $this->planHeuristics->projectStepSpecs($type, $title),
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
        if (DatabaseTableAvailability::has('atlas_project_steps') && $project->steps()->count() === 0) {
            $this->ensureProjectPlan($project, $plan, false, $source);
            $project->refresh();
        }
        $step = $this->currentStepForProject($project);
        $task = $this->activeNextActionTask($project);
        $wasRecentlyCreated = false;

        if (! $task) {
            $task = new AtlasTask;
            $wasRecentlyCreated = true;
        }

        $explicitNextAction = trim((string) ($data['next_action'] ?? $data['title'] ?? ''));
        $title = $this->planHeuristics->taskTitle($explicitNextAction ?: (string) ($step?->title ?? $plan['next_action'] ?? $project->next_action ?? $project->title));
        $description = $step
            ? $this->planHeuristics->stepTaskDescription($project, $step, $capture)
            : $this->planHeuristics->taskDescription($project, $capture, $plan);
        $explicitEstimate = array_key_exists('estimated_minutes', $data);
        $baseEstimate = $this->support->clamp((int) ($data['estimated_minutes'] ?? $step?->estimated_minutes ?? $plan['estimated_minutes'] ?? 25), 5, 480);
        $estimatedMinutes = $explicitEstimate
            ? $baseEstimate
            : $this->executionHelpers->calibratedEstimateForProject($project, $step, $baseEstimate);
        $taskData = [
            ...$data,
            'priority' => $data['priority'] ?? $plan['priority'] ?? $project->priority,
            'estimated_minutes' => $estimatedMinutes,
            'energy_required' => $data['energy_required'] ?? $step?->energy_required ?? $plan['energy_required'] ?? null,
            'due_at' => $data['due_at'] ?? $plan['deadline_at'] ?? null,
        ];
        $planningAttributes = $capture
            ? $this->planning->attributesForCaptureTask($capture, $taskData, $title, $description)
            : $this->planHeuristics->standalonePlanningAttributes([
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
            'execution_mode' => $step ? $this->planHeuristics->executionModeForStep($project, $step) : ($plan['execution_mode'] ?? 'quick_win'),
            'friction_level' => $step?->friction_level ?? $plan['friction_level'] ?? 50,
            'emotional_resistance' => $plan['emotional_resistance'] ?? 50,
            'clarity_level' => $plan['clarity_level'] ?? 60,
            'starter_step' => $step ? $this->planHeuristics->starterForStep($step) : ($plan['starter_step'] ?? null),
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
        if (! DatabaseTableAvailability::has('atlas_project_steps')) {
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
            : $this->planHeuristics->projectStepSpecs((string) ($plan['project_type'] ?? $project->project_type ?? 'personal'), $project->title);

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
                    'estimated_minutes' => $this->support->clamp((int) ($spec['estimated_minutes'] ?? $plan['estimated_minutes'] ?? 25), 5, 480),
                    'energy_required' => $this->support->validEnergy($spec['energy_required'] ?? $plan['energy_required'] ?? 'medium'),
                    'friction_level' => $this->support->clamp((int) ($spec['friction_level'] ?? $plan['friction_level'] ?? 50), 0, 100),
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
        if (! $task->project_id || ! $task->project_step_id || ! DatabaseTableAvailability::has('atlas_project_steps')) {
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

        return $this->ensureNextActionTask($project->refresh(), null, [], $this->planHeuristics->planFromStep($project, $next), $source);
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

        return $this->ensureNextActionTask($project->refresh(), null, [], $this->planHeuristics->planFromStep($project, $step->refresh()), $source);
    }

    public function blockStep(AtlasProject $project, AtlasProjectStep $step, string $source = 'projects.step.block', array $data = []): void
    {
        if ($step->project_id !== $project->id) {
            abort(404);
        }

        $blocker = null;
        if (is_string($data['blocker_id'] ?? null) && DatabaseTableAvailability::has('atlas_project_blockers')) {
            $blocker = AtlasProjectBlocker::query()
                ->where('project_id', $project->id)
                ->whereKey($data['blocker_id'])
                ->first();
        }
        if (! $blocker) {
            $task = null;
            if (is_string($data['task_id'] ?? null)) {
                $task = AtlasTask::query()
                    ->where('project_id', $project->id)
                    ->whereKey($data['task_id'])
                    ->first();
            }
            $blocker = $this->blockers->openForStep($project, $step, $task, $data, $source);
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
                'next_action' => $blocker?->unblock_next_action ?: 'Desbloquear: '.$step->title,
                'last_touched_at' => now(),
            ])->save();
        }

        $this->recordEvent($project, 'step_blocked', [
            'step_id' => $step->id,
            'step_order' => $step->step_order,
            'step_title' => $step->title,
            'blocker_id' => $blocker?->id,
            'reason_code' => $blocker?->reason_code,
            'unblock_next_action' => $blocker?->unblock_next_action,
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
            $this->blockers->cancelOpenForProject($project, 'Projeto concluido.', $source);
        } elseif ($status === 'archived') {
            $updates['paused_until'] = null;
            $updates['active_next_task_id'] = null;
            $updates['current_step_id'] = null;
            $taskUpdateCount = $this->moveOpenProjectTasks($project, 'archived');
            $this->blockers->cancelOpenForProject($project, 'Projeto arquivado.', $source);
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
        $readiness = $this->executionHelpers->projectCompletionReadiness($project);
        $completionMetadata = is_array(data_get($data, 'metadata.completion'))
            ? data_get($data, 'metadata.completion')
            : [];
        $outcome = $this->executionHelpers->completionText($data['completion_outcome'] ?? $completionMetadata['outcome'] ?? null, 1500);
        $evidence = $this->executionHelpers->completionText($data['completion_evidence'] ?? $completionMetadata['evidence'] ?? null, 1500);
        $note = $this->executionHelpers->completionText($data['completion_note'] ?? $completionMetadata['note'] ?? null, 1000);
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
                'proposed_next_action' => $project->next_action ?: $this->planHeuristics->defaultNextAction($project->project_type ?: 'personal', $project->title, Str::lower($project->title.' '.$project->goal)),
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
                'label' => $this->deferRecovery->deferReviewLabel($action),
                'reason' => $this->deferRecovery->deferReviewReason($defer),
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
        $days = $this->support->clamp((int) ($data['review_interval_days'] ?? 3), 1, 30);
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
            ? $this->support->clamp((int) $context['available_minutes'], 5, 240)
            : null;
        $energyLevel = isset($context['energy_level'])
            ? $this->support->clamp((int) $context['energy_level'], 1, 5)
            : null;
        $estimatedMinutes = $this->support->clamp((int) ($task->estimated_minutes ?: $step?->estimated_minutes ?: 25), 5, 480);
        $timebox = $this->executionHelpers->executionTimebox($estimatedMinutes, $availableMinutes, $energyLevel, (int) ($task->friction_level ?? $step?->friction_level ?? 50));
        $starter = trim((string) ($task->starter_step ?: ($step ? $this->planHeuristics->starterForStep($step) : null) ?: $this->planHeuristics->starterStep($project->project_type ?: 'personal', $task->title)));
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
            'if_then_plan' => $task->if_then_plan ?: $this->planHeuristics->ifThenPlan($project->project_type ?: 'personal'),
            'reward_hint' => $task->reward_hint ?: $this->planHeuristics->rewardHint($project->project_type ?: 'personal'),
            'low_energy_action' => $this->executionHelpers->lowEnergyAction($project, $task, $step),
            'avoid_now' => $this->executionHelpers->avoidNow($project, $task),
            'attempt_count' => (int) $task->attempt_count,
            'friction_level' => (int) ($task->friction_level ?? 50),
            'energy_required' => $task->energy_required,
            'next_review_at' => $project->next_review_at?->toJSON(),
            'why' => $this->executionHelpers->executionWhy($project, $task, $step, $estimatedMinutes, $timebox, $availableMinutes, $energyLevel),
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

        $targetMinutes = $this->support->clamp((int) ($data['target_minutes'] ?? min((int) ($data['available_minutes'] ?? 10), 15)), 5, 45);
        $previous = [
            'title' => $task->title,
            'estimated_minutes' => $task->estimated_minutes,
            'energy_required' => $task->energy_required,
            'execution_mode' => $task->execution_mode,
            'failure_reason_last' => $task->failure_reason_last,
        ];
        $title = trim((string) ($data['next_action'] ?? ''));
        if ($title === '') {
            $title = $this->deferRecovery->recoveryActionTitle($project, $task);
        }
        $starter = $this->deferRecovery->recoveryStarterStep($project, $task, $targetMinutes);
        $minimum = $this->deferRecovery->recoveryMinimumAction($project, $task, $targetMinutes);
        $updates = [
            'title' => $this->planHeuristics->taskTitle($title),
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
        $actualMinutes = $this->executionHelpers->actualMinutesForCompletion($task, $data, $completed);
        $estimatedMinutes = $this->support->clamp((int) ($task->estimated_minutes ?: 25), 5, 480);
        $estimateRatio = $actualMinutes ? round($actualMinutes / max(1, $estimatedMinutes), 2) : null;
        $quality = $this->executionHelpers->completionQuality($data['completion_quality'] ?? null);
        $actionCompleted = in_array($quality, ['complete', 'learned'], true);
        $outcome = $this->executionHelpers->completionText($data['outcome'] ?? $data['note'] ?? null, 1000);
        $evidence = $this->executionHelpers->completionText($data['evidence'] ?? null, 1000);
        $blocker = $this->executionHelpers->completionText($data['blocker'] ?? ($quality === 'blocked' ? ($data['outcome'] ?? $data['note'] ?? null) : null), 500);
        $nextHint = $this->executionHelpers->completionText($data['next_hint'] ?? null, 500);
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
            'energy_after' => isset($data['energy_after']) ? $this->support->clamp((int) $data['energy_after'], 1, 5) : null,
            'outcome' => $outcome,
            'evidence' => $evidence,
            'blocker' => $blocker,
            'next_hint' => $nextHint,
            'calibrated_estimate_minutes' => $actualMinutes !== null
                ? $this->support->clamp((int) round($estimatedMinutes * 0.65 + $actualMinutes * 0.35), 5, 480)
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

        $openedBlocker = null;
        if ($quality === 'blocked') {
            $openedBlocker = $this->blockers->openForTask($task->refresh(), [
                ...$data,
                'description' => $blocker ?? $outcome ?? $data['note'] ?? null,
                'blocker_reason_code' => $data['blocker_reason_code'] ?? $data['reason_code'] ?? null,
                'severity' => $data['blocker_severity'] ?? $data['severity'] ?? null,
                'unblock_next_action' => $data['unblock_next_action'] ?? $nextHint,
                'next_hint' => $nextHint,
            ], $source);
            if ($openedBlocker) {
                $summary['blocker_id'] = $openedBlocker->id;
                $summary['blocker_reason_code'] = $openedBlocker->reason_code;
                $summary['blocker_severity'] = $openedBlocker->severity;
                $summary['unblock_next_action'] = $openedBlocker->unblock_next_action;
            }
        }

        if ($task->project_step_id && DatabaseTableAvailability::has('atlas_project_steps')) {
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
                    'estimate_bias' => $this->executionHelpers->estimateBias($projectAverageRatio ?? $estimateRatio),
                ];
                $project->forceFill([
                    'last_touched_at' => now(),
                    'metadata' => $projectMetadata,
                ])->save();
                $this->recordEvent($project->refresh(), $this->executionHelpers->executionResultEventType($quality), $summary, $source);
            }
        }

        $this->planning->recordEvent($task->refresh(), $this->executionHelpers->executionResultEventType($quality), $summary, $source);

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

        $reasonCode = $this->deferRecovery->deferReasonCode($reasonCode, $reason);
        $suggestion = $this->deferRecovery->deferSuggestion($task, $project, $reasonCode, $reason);
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
        if (! DatabaseTableAvailability::has('atlas_project_events')) {
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

    private function moveOpenProjectTasks(AtlasProject $project, string $status): int
    {
        if (! DatabaseTableAvailability::has('atlas_tasks')) {
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
        if (! DatabaseTableAvailability::has('atlas_project_steps')) {
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

}
