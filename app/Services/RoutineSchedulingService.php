<?php

namespace App\Services;

use App\Models\AtlasRoutine;
use App\Models\AtlasRoutineEvent;
use App\Models\AtlasTask;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

class RoutineSchedulingService
{
    public function __construct(
        private readonly TaskPlanningService $planning,
    ) {}

    /**
     * @return array<int, array{routine: AtlasRoutine, task: AtlasTask, created: bool}>
     */
    public function generateDue(CarbonImmutable|string|null $date = null, string $timezone = 'UTC', ?string $domain = null): array
    {
        $targetDate = $this->date($date, $timezone);
        $results = [];

        AtlasRoutine::query()
            ->with('project')
            ->where('status', 'active')
            ->when($domain, fn ($query) => $query->where('domain', $domain))
            ->where(function ($query) use ($targetDate): void {
                $query
                    ->whereNull('next_occurrence_date')
                    ->orWhereDate('next_occurrence_date', '<=', $targetDate->toDateString());
            })
            ->orderBy('next_occurrence_date')
            ->orderBy('created_at')
            ->each(function (AtlasRoutine $routine) use ($targetDate, $timezone, &$results): void {
                if (! $this->occursOn($routine, $targetDate)) {
                    $this->refreshNextOccurrence($routine, $targetDate);

                    return;
                }

                $task = $this->generateOccurrence($routine, $targetDate, $timezone, 'routines.generate_due');
                if ($task) {
                    $results[] = [
                        'routine' => $routine->refresh(),
                        'task' => $task,
                        'created' => (bool) data_get($task->metadata, 'routine_generation.was_created', false),
                    ];
                }
            });

        return $results;
    }

    public function generateOccurrence(
        AtlasRoutine $routine,
        CarbonImmutable|string|null $date = null,
        ?string $timezone = null,
        string $source = 'routines.generate',
    ): ?AtlasTask {
        if ($routine->status !== 'active') {
            throw ValidationException::withMessages([
                'routine' => 'A rotina precisa estar ativa para gerar uma tarefa.',
            ]);
        }

        $tz = $timezone ?: ($routine->timezone ?: config('app.timezone', 'UTC'));
        $targetDate = $this->date($date, $tz);
        if (! $this->occursOn($routine, $targetDate)) {
            throw ValidationException::withMessages([
                'date' => 'Esta rotina não acontece na data informada.',
            ]);
        }

        $task = AtlasTask::query()
            ->where('routine_id', $routine->id)
            ->whereDate('routine_occurrence_date', $targetDate->toDateString())
            ->first();
        $wasCreated = ! $task;
        $task ??= new AtlasTask([
            'routine_id' => $routine->id,
            'routine_occurrence_date' => $targetDate->toDateString(),
        ]);

        if ($task->exists && $task->status === 'done') {
            $this->refreshNextOccurrence($routine, $targetDate);
            $this->recordEvent($routine, 'occurrence_already_completed', [
                'task_id' => $task->id,
                'occurrence_date' => $targetDate->toDateString(),
            ], $source);

            return $task->load(['project', 'projectStep', 'routine']);
        }

        [$plannedStart, $plannedEnd] = $this->plannedWindow($routine, $targetDate, $tz);
        $description = $this->descriptionForTask($routine);
        $metadata = is_array($task->metadata) ? $task->metadata : [];
        $payload = [
            'title' => $routine->title,
            'description' => $description,
            'status' => $task->exists && in_array($task->status, ['next', 'waiting'], true) ? $task->status : 'open',
            'priority' => $routine->priority,
            'domain' => $routine->domain,
            'source_capture_id' => $routine->source_capture_id,
            'project_id' => $routine->project_id,
            'due_at' => null,
            'planned_for_date' => $targetDate->toDateString(),
            'planned_start_at' => $plannedStart,
            'planned_end_at' => $plannedEnd,
            'estimated_minutes' => $routine->estimated_minutes,
            'energy_required' => $routine->energy_required,
            'planning_status' => $plannedStart ? 'scheduled' : 'planned',
            'execution_mode' => $routine->execution_mode,
            'friction_level' => $routine->friction_level,
            'emotional_resistance' => $routine->emotional_resistance,
            'clarity_level' => $routine->clarity_level,
            'starter_step' => $routine->starter_step,
            'minimum_viable_action' => $routine->minimum_viable_action ?: $routine->title,
            'if_then_plan' => $routine->if_then_plan ?: 'Se eu travar, executar a versão mínima por 5 minutos.',
            'reward_hint' => $routine->reward_hint ?: 'Registrar a sequência ao concluir.',
            'routine_id' => $routine->id,
            'routine_occurrence_date' => $targetDate->toDateString(),
            'metadata' => [
                ...$metadata,
                'created_from' => $metadata['created_from'] ?? 'routine',
                'routine_id' => $routine->id,
                'routine_title' => $routine->title,
                'routine_occurrence_date' => $targetDate->toDateString(),
                'routine_generation' => [
                    'was_created' => $wasCreated,
                    'source' => $source,
                    'generated_at' => now()->toJSON(),
                ],
            ],
        ];

        $task->fill([
            ...$payload,
            ...$this->planning->attributesForTaskUpdate($task, $payload),
        ]);
        $task->save();

        $this->planning->recordEvent($task, $wasCreated ? 'generated_from_routine' : 'refreshed_from_routine', [
            'routine_id' => $routine->id,
            'routine_title' => $routine->title,
            'occurrence_date' => $targetDate->toDateString(),
            'priority' => $task->priority,
            'priority_score' => $task->priority_score,
            'estimated_minutes' => $task->estimated_minutes,
            'planned_start_at' => $task->planned_start_at?->toJSON(),
        ], $source);
        $this->recordEvent($routine, $wasCreated ? 'occurrence_generated' : 'occurrence_refreshed', [
            'task_id' => $task->id,
            'task_title' => $task->title,
            'occurrence_date' => $targetDate->toDateString(),
            'planned_start_at' => $task->planned_start_at?->toJSON(),
            'planned_end_at' => $task->planned_end_at?->toJSON(),
        ], $source);

        $this->refreshNextOccurrence($routine, $targetDate);

        return $task->refresh()->load(['project', 'projectStep', 'routine']);
    }

    public function refreshNextOccurrence(AtlasRoutine $routine, CarbonImmutable|string|null $afterDate = null): AtlasRoutine
    {
        $timezone = $routine->timezone ?: config('app.timezone', 'UTC');
        $date = $this->date($afterDate, $timezone);
        $routine->forceFill([
            'last_generated_for_date' => $this->occursOn($routine, $date) ? $date->toDateString() : $routine->last_generated_for_date,
            'next_occurrence_date' => $this->nextOccurrenceAfter($routine, $date)?->toDateString(),
        ])->save();

        return $routine;
    }

    public function recordCompletionFromTask(AtlasTask $task, string $source = 'tasks.complete'): ?AtlasRoutine
    {
        if (! $task->routine_id || ! DatabaseTableAvailability::has('atlas_routines')) {
            return null;
        }

        $routine = AtlasRoutine::query()->find($task->routine_id);
        if (! $routine) {
            return null;
        }

        $timezone = $routine->timezone ?: config('app.timezone', 'UTC');
        $occurrenceDate = $this->date(
            $task->routine_occurrence_date?->toDateString()
                ?? $task->planned_for_date?->toDateString()
                ?? $task->completed_at?->setTimezone($timezone)->toDateString()
                ?? now($timezone)->toDateString(),
            $timezone,
        );
        $metadata = is_array($routine->metadata) ? $routine->metadata : [];
        $streak = is_array($metadata['streak'] ?? null) ? $metadata['streak'] : [];
        $previousDate = isset($streak['last_completed_date']) && is_string($streak['last_completed_date'])
            ? CarbonImmutable::parse($streak['last_completed_date'], $timezone)->startOfDay()
            : null;
        $current = (int) ($streak['current'] ?? 0);
        if (! $previousDate || $previousDate->lt($occurrenceDate->subDay())) {
            $current = 1;
        } elseif ($previousDate->isSameDay($occurrenceDate->subDay())) {
            $current += 1;
        }

        $best = max((int) ($streak['best'] ?? 0), $current);
        $completedCount = (int) ($streak['completed_count'] ?? 0);
        if (! $previousDate || ! $previousDate->isSameDay($occurrenceDate)) {
            $completedCount += 1;
        }

        $routine->forceFill([
            'last_generated_for_date' => $routine->last_generated_for_date ?: $occurrenceDate->toDateString(),
            'metadata' => [
                ...$metadata,
                'streak' => [
                    ...$streak,
                    'current' => $current,
                    'best' => $best,
                    'completed_count' => $completedCount,
                    'last_completed_date' => $occurrenceDate->toDateString(),
                    'updated_at' => now()->toJSON(),
                ],
            ],
        ])->save();

        $this->recordEvent($routine, 'occurrence_completed', [
            'task_id' => $task->id,
            'task_title' => $task->title,
            'occurrence_date' => $occurrenceDate->toDateString(),
            'streak_current' => $current,
            'streak_best' => $best,
            'completed_count' => $completedCount,
        ], $source);

        return $routine->refresh();
    }

    public function nextOccurrenceAfter(AtlasRoutine $routine, CarbonImmutable|string|null $afterDate = null): ?CarbonImmutable
    {
        $timezone = $routine->timezone ?: config('app.timezone', 'UTC');
        $candidate = $this->date($afterDate, $timezone)->addDay();

        for ($i = 0; $i < 370; $i++) {
            if ($this->occursOn($routine, $candidate)) {
                return $candidate;
            }

            $candidate = $candidate->addDay();
        }

        return null;
    }

    public function occursOn(AtlasRoutine $routine, CarbonImmutable|string|null $date = null): bool
    {
        $targetDate = $this->date($date, $routine->timezone ?: config('app.timezone', 'UTC'));

        return match ($routine->frequency) {
            'daily' => true,
            'weekdays' => $targetDate->isWeekday(),
            'weekly', 'custom' => in_array($targetDate->isoWeekday(), $this->weekdays($routine), true),
            default => false,
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function recordEvent(AtlasRoutine $routine, string $eventType, array $payload = [], string $source = 'app'): ?AtlasRoutineEvent
    {
        if (! DatabaseTableAvailability::has('atlas_routine_events')) {
            return null;
        }

        return AtlasRoutineEvent::query()->create([
            'routine_id' => $routine->id,
            'event_type' => $eventType,
            'source' => $source,
            'payload' => $payload,
            'occurred_at' => now(),
        ]);
    }

    private function date(CarbonImmutable|string|null $date, string $timezone): CarbonImmutable
    {
        return $date instanceof CarbonImmutable
            ? $date->setTimezone($timezone)->startOfDay()
            : CarbonImmutable::parse($date ?? now($timezone)->toDateString(), $timezone)->startOfDay();
    }

    /**
     * @return array<int, int>
     */
    private function weekdays(AtlasRoutine $routine): array
    {
        $weekdays = is_array($routine->weekdays) ? $routine->weekdays : [];

        return collect($weekdays)
            ->map(fn (mixed $day): int => (int) $day)
            ->filter(fn (int $day): bool => $day >= 1 && $day <= 7)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array{CarbonImmutable|null, CarbonImmutable|null}
     */
    private function plannedWindow(AtlasRoutine $routine, CarbonImmutable $date, string $timezone): array
    {
        if (! $routine->preferred_time) {
            return [null, null];
        }

        $start = CarbonImmutable::parse($date->toDateString().' '.$routine->preferred_time, $timezone);

        return [$start->setTimezone('UTC'), $start->addMinutes((int) $routine->estimated_minutes)->setTimezone('UTC')];
    }

    private function descriptionForTask(AtlasRoutine $routine): ?string
    {
        return trim(implode("\n\n", array_filter([
            $routine->description,
            $routine->starter_step ? 'Começar por: '.$routine->starter_step : null,
            $routine->minimum_viable_action ? 'Mínimo viável: '.$routine->minimum_viable_action : null,
            $routine->if_then_plan ? 'Plano se-então: '.$routine->if_then_plan : null,
        ]))) ?: null;
    }
}
