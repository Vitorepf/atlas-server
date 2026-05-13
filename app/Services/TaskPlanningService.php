<?php

namespace App\Services;

use App\Models\AtlasCalendarBlock;
use App\Models\AtlasTask;
use App\Models\AtlasTaskEvent;
use App\Models\Capture;
use App\Models\Checkin;
use App\Models\DigitalActivitySnapshot;
use App\Models\HealthSnapshot;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class TaskPlanningService
{
    private const PRIORITY_BASE = [
        'low' => 25,
        'normal' => 50,
        'high' => 76,
        'urgent' => 92,
    ];

    /**
     * @return array<string, mixed>
     */
    public function attributesForCaptureTask(Capture $capture, array $data, string $title, ?string $description): array
    {
        $text = trim($title.' '.($description ?? '').' '.(string) $capture->content_text);
        $priority = $this->priority($data['priority'] ?? null);
        $estimatedMinutes = $this->intFromData($data, 'estimated_minutes')
            ?? $this->metadataInt($data, 'planning.estimated_minutes')
            ?? $this->estimateMinutes($text);
        $energy = $this->metadataString($data, 'planning.energy_required')
            ?? $this->energyRequired($text, $estimatedMinutes, $priority);
        $urgency = $this->intFromData($data, 'urgency_score')
            ?? $this->metadataInt($data, 'planning.urgency_score')
            ?? $this->urgencyScore($data['due_at'] ?? null, $priority);
        $impact = $this->intFromData($data, 'impact_score')
            ?? $this->metadataInt($data, 'planning.impact_score')
            ?? $this->impactScore($text, $priority);
        $effort = $this->intFromData($data, 'effort_score')
            ?? $this->metadataInt($data, 'planning.effort_score')
            ?? $this->effortScore($estimatedMinutes, $energy);
        $priorityScore = $this->intFromData($data, 'priority_score')
            ?? $this->score($priority, $urgency, $impact, $effort);
        $plannedStart = $data['planned_start_at'] ?? null;
        $plannedEnd = $data['planned_end_at'] ?? null;
        $plannedForDate = $data['planned_for_date']
            ?? ($plannedStart ? CarbonImmutable::parse($plannedStart)->toDateString() : null);

        return [
            'planned_for_date' => $plannedForDate,
            'planned_start_at' => $plannedStart,
            'planned_end_at' => $plannedEnd,
            'estimated_minutes' => $this->clamp($estimatedMinutes, 5, 480),
            'energy_required' => $this->validEnergy($energy),
            'urgency_score' => $this->clamp($urgency, 0, 100),
            'impact_score' => $this->clamp($impact, 0, 100),
            'effort_score' => $this->clamp($effort, 0, 100),
            'priority_score' => $this->clamp($priorityScore, 0, 100),
            'planning_status' => $plannedStart
                ? 'scheduled'
                : ($plannedForDate ? 'planned' : 'suggested'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function attributesForTaskUpdate(AtlasTask $task, array $data): array
    {
        $title = (string) ($data['title'] ?? $task->title);
        $description = (string) ($data['description'] ?? $task->description ?? '');
        $priority = $this->priority($data['priority'] ?? $task->priority);
        $estimatedMinutes = $this->intFromData($data, 'estimated_minutes') ?? (int) $task->estimated_minutes ?: 25;
        $energy = $this->validEnergy($data['energy_required'] ?? $task->energy_required ?? 'medium');
        $urgency = $this->intFromData($data, 'urgency_score') ?? $this->urgencyScore($data['due_at'] ?? $task->due_at, $priority);
        $impact = $this->intFromData($data, 'impact_score') ?? $this->impactScore($title.' '.$description, $priority);
        $effort = $this->intFromData($data, 'effort_score') ?? $this->effortScore($estimatedMinutes, $energy);

        return [
            'estimated_minutes' => $this->clamp($estimatedMinutes, 5, 480),
            'energy_required' => $energy,
            'urgency_score' => $this->clamp($urgency, 0, 100),
            'impact_score' => $this->clamp($impact, 0, 100),
            'effort_score' => $this->clamp($effort, 0, 100),
            'priority_score' => $this->clamp(
                $this->intFromData($data, 'priority_score') ?? $this->score($priority, $urgency, $impact, $effort),
                0,
                100,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function agenda(array $data): array
    {
        $timezone = (string) ($data['timezone'] ?? config('app.timezone', 'UTC'));
        $date = CarbonImmutable::parse($data['date'] ?? now($timezone)->toDateString(), $timezone)->startOfDay();
        $requestedEnergyLevel = isset($data['energy_level']) ? $this->clamp((int) $data['energy_level'], 1, 5) : null;
        $context = $this->contextForAgenda($date, $timezone, $requestedEnergyLevel);
        $energyLevel = $context['energy_level'];
        $capacityMinutes = $this->clamp((int) ($data['capacity_minutes'] ?? $this->capacityForEnergy($energyLevel, $context)), 30, 720);
        $limit = $this->clamp((int) ($data['limit'] ?? 30), 1, 100);
        $domain = $data['domain'] ?? null;
        $excludedTaskIds = collect($data['exclude_task_ids'] ?? [])
            ->filter(fn (mixed $id): bool => is_string($id) && $id !== '')
            ->values()
            ->all();
        $blocks = $this->blocksForDate($date, $timezone);

        $tasks = AtlasTask::query()
            ->with($this->taskAgendaRelations())
            ->when($domain, fn ($query) => $query->where('domain', $domain))
            ->when($excludedTaskIds !== [], fn ($query) => $query->whereNotIn('id', $excludedTaskIds))
            ->whereIn('status', ['open', 'next'])
            ->whereNull('completed_at')
            ->where(function ($query) use ($date): void {
                $query
                    ->whereNull('planned_for_date')
                    ->orWhereDate('planned_for_date', '<=', $date->toDateString())
                    ->orWhereDate('due_at', '<=', $date->endOfDay()->toDateTimeString());
            })
            ->orderByDesc('priority_score')
            ->orderByRaw('due_at IS NULL')
            ->orderBy('due_at')
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();

        $ranked = $tasks
            ->map(fn (AtlasTask $task): array => $this->agendaItem($task, $date, $timezone, $energyLevel))
            ->sortByDesc('agenda_score')
            ->values();

        return $this->scheduleAgenda($ranked, $date, $timezone, $capacityMinutes, $energyLevel, $blocks, $context);
    }

    /**
     * @return array<string, mixed>
     */
    public function weekAgenda(array $data): array
    {
        $timezone = (string) ($data['timezone'] ?? config('app.timezone', 'UTC'));
        $startDate = CarbonImmutable::parse($data['start_date'] ?? now($timezone)->toDateString(), $timezone)->startOfDay();
        $days = $this->clamp((int) ($data['days'] ?? 7), 1, 14);
        $includeWeekends = (bool) ($data['include_weekends'] ?? true);
        $dailyLimit = $this->clamp((int) ($data['daily_limit'] ?? $data['limit'] ?? 8), 1, 30);
        $excludedTaskIds = [];
        $plannedDays = [];
        $totalTasks = 0;
        $totalScheduledMinutes = 0;
        $focusDays = 0;

        for ($i = 0; $i < $days; $i++) {
            $date = $startDate->addDays($i);
            $isWeekend = in_array((int) $date->dayOfWeekIso, [6, 7], true);
            $capacity = $this->capacityForWeekDay($data, $date, $isWeekend);

            if ($isWeekend && ! $includeWeekends) {
                $agenda = $this->emptyAgenda($date, $timezone, 'fim de semana sem planejamento automático');
            } elseif ($capacity <= 0) {
                $agenda = $this->emptyAgenda($date, $timezone, 'capacidade zerada para este dia');
            } else {
                $agenda = $this->agenda([
                    ...$data,
                    'date' => $date->toDateString(),
                    'timezone' => $timezone,
                    'capacity_minutes' => $capacity,
                    'limit' => $dailyLimit,
                    'exclude_task_ids' => $excludedTaskIds,
                ]);
                foreach ($agenda['tasks'] as $task) {
                    if (isset($task['id']) && is_string($task['id'])) {
                        $excludedTaskIds[] = $task['id'];
                    }
                }
            }

            $taskCount = count($agenda['tasks']);
            $totalTasks += $taskCount;
            $totalScheduledMinutes += (int) ($agenda['scheduled_minutes'] ?? 0);
            if ($taskCount > 0) {
                $focusDays++;
            }

            $plannedDays[] = [
                'date' => $date->toDateString(),
                'weekday' => $date->format('D'),
                'is_weekend' => $isWeekend,
                'capacity_minutes' => $capacity,
                'agenda' => $agenda,
            ];
        }

        return [
            'start_date' => $startDate->toDateString(),
            'end_date' => $startDate->addDays($days - 1)->toDateString(),
            'timezone' => $timezone,
            'days_count' => $days,
            'summary' => [
                'task_count' => $totalTasks,
                'scheduled_minutes' => $totalScheduledMinutes,
                'focus_days' => $focusDays,
                'strategy' => $this->weekStrategy($totalTasks, $focusDays, $totalScheduledMinutes),
            ],
            'days' => $plannedDays,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function planAgenda(array $data): array
    {
        $timezone = (string) ($data['timezone'] ?? config('app.timezone', 'UTC'));
        $date = CarbonImmutable::parse($data['date'] ?? now($timezone)->toDateString(), $timezone)->startOfDay();
        $force = (bool) ($data['force'] ?? false);
        $createBlocks = (bool) ($data['create_blocks'] ?? true);
        $agenda = $this->agenda($data);
        $planned = [];
        $skipped = [];

        foreach ($agenda['tasks'] as $item) {
            $result = $this->persistAgendaItem($item, $date, $timezone, $force, $createBlocks, 'agenda_planner');
            if ($result['planned']) {
                $planned[] = $result['planned'];
            } elseif ($result['skipped']) {
                $skipped[] = $result['skipped'];
            }
        }

        return [
            'date' => $date->toDateString(),
            'timezone' => $timezone,
            'planned_count' => count($planned),
            'skipped_count' => count($skipped),
            'planned_tasks' => $planned,
            'skipped_tasks' => $skipped,
            'agenda' => $this->agenda($data),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function planWeekAgenda(array $data): array
    {
        $timezone = (string) ($data['timezone'] ?? config('app.timezone', 'UTC'));
        $force = (bool) ($data['force'] ?? false);
        $createBlocks = (bool) ($data['create_blocks'] ?? true);
        $week = $this->weekAgenda($data);
        $planned = [];
        $skipped = [];

        foreach ($week['days'] as $day) {
            $date = CarbonImmutable::parse((string) $day['date'], $timezone)->startOfDay();
            foreach (($day['agenda']['tasks'] ?? []) as $item) {
                if (! is_array($item)) {
                    continue;
                }

                $result = $this->persistAgendaItem($item, $date, $timezone, $force, $createBlocks, 'week_agenda_planner');
                if ($result['planned']) {
                    $planned[] = [
                        ...$result['planned'],
                        'date' => $date->toDateString(),
                    ];
                } elseif ($result['skipped']) {
                    $skipped[] = [
                        ...$result['skipped'],
                        'date' => $date->toDateString(),
                    ];
                }
            }
        }

        return [
            'start_date' => $week['start_date'],
            'end_date' => $week['end_date'],
            'timezone' => $timezone,
            'planned_count' => count($planned),
            'skipped_count' => count($skipped),
            'planned_tasks' => $planned,
            'skipped_tasks' => $skipped,
            'week' => $this->weekAgenda($data),
        ];
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array{planned: array<string, mixed>|null, skipped: array<string, mixed>|null}
     */
    private function persistAgendaItem(
        array $item,
        CarbonImmutable $date,
        string $timezone,
        bool $force,
        bool $createBlocks,
        string $source,
    ): array {
        $taskId = isset($item['id']) && is_string($item['id']) ? $item['id'] : null;
        $task = $taskId ? AtlasTask::query()->find($taskId) : null;
        if (! $task || $task->completed_at || ! in_array($task->status, ['open', 'next'], true)) {
            return [
                'planned' => null,
                'skipped' => [
                    'id' => $taskId,
                    'reason' => 'not_active',
                ],
            ];
        }

        $start = ! empty($item['recommended_start_at'])
            ? CarbonImmutable::parse((string) $item['recommended_start_at'], $timezone)
            : null;
        $end = ! empty($item['recommended_end_at'])
            ? CarbonImmutable::parse((string) $item['recommended_end_at'], $timezone)
            : null;
        if (! $start || ! $end || $end->lte($start)) {
            return [
                'planned' => null,
                'skipped' => [
                    'id' => $task->id,
                    'reason' => 'missing_window',
                ],
            ];
        }

        if (! $force && $task->planned_start_at && ! $task->planned_start_at->isSameDay($date)) {
            return [
                'planned' => null,
                'skipped' => [
                    'id' => $task->id,
                    'reason' => 'already_planned_for_other_day',
                    'planned_start_at' => $task->planned_start_at->toJSON(),
                ],
            ];
        }

        $updates = [
            'planned_for_date' => $date->toDateString(),
            'planned_start_at' => $start,
            'planned_end_at' => $end,
            'estimated_minutes' => (int) ($item['estimated_minutes'] ?? $task->estimated_minutes ?? 25),
            'planning_status' => 'scheduled',
        ];
        $task->update([
            ...$updates,
            ...$this->attributesForTaskUpdate($task, $updates),
        ]);

        $block = $createBlocks ? $this->upsertAgendaBlock($task->refresh(), $date, $timezone, $start, $end, $item) : null;
        $this->recordEvent($task, 'agenda_planned', [
            'date' => $date->toDateString(),
            'timezone' => $timezone,
            'planned_start_at' => $start->toJSON(),
            'planned_end_at' => $end->toJSON(),
            'agenda_score' => $item['agenda_score'] ?? null,
            'bucket' => $item['bucket'] ?? null,
            'calendar_block_id' => $block?->id,
            'force' => $force,
            'source' => $source,
        ], $source);

        return [
            'planned' => [
                ...$item,
                'planned_start_at' => $start->toJSON(),
                'planned_end_at' => $end->toJSON(),
                'calendar_block_id' => $block?->id,
            ],
            'skipped' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function recordEvent(AtlasTask $task, string $eventType, array $payload = [], string $source = 'app'): ?AtlasTaskEvent
    {
        if (! Schema::hasTable('atlas_task_events')) {
            return null;
        }

        $payload = $this->taskEventPayload($task, $eventType, $payload, $source);

        return AtlasTaskEvent::query()->create([
            'task_id' => $task->id,
            'event_type' => $eventType,
            'source' => $source,
            'payload' => $payload,
            'occurred_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function taskEventPayload(AtlasTask $task, string $eventType, array $payload, string $source): array
    {
        $previousEvent = AtlasTaskEvent::query()
            ->where('task_id', $task->id)
            ->latest('occurred_at')
            ->latest('created_at')
            ->first();
        $eventSequence = AtlasTaskEvent::query()
            ->where('task_id', $task->id)
            ->count() + 1;

        $eventPayload = [
            ...$payload,
            'schema_version' => 'atlas.task_orchestration.event.v1',
            'event_sequence' => $eventSequence,
            'previous_event_id' => $previousEvent?->id,
            'previous_event_hash' => data_get($previousEvent?->payload, 'event_hash'),
            'orchestration_receipt' => [
                'schema_version' => 'atlas.task_orchestration.local_event_receipt.v1',
                'task_id' => $task->id,
                'event_type' => $eventType,
                'source' => $source,
                'event_sequence' => $eventSequence,
                'hash_algorithm' => 'sha256',
                'provider_dispatch_allowed' => false,
                'runtime_execution_allowed' => false,
                'policy_mutation_allowed' => false,
                'auto_completion_allowed' => false,
                'payload_api_only' => true,
            ],
        ];
        $eventPayload['event_hash'] = $this->stableTaskEventHash($task, $eventType, $source, $eventPayload);

        return $eventPayload;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function stableTaskEventHash(AtlasTask $task, string $eventType, string $source, array $payload): string
    {
        unset($payload['event_hash']);

        return hash('sha256', $this->stableJson([
            'task_id' => $task->id,
            'event_type' => $eventType,
            'source' => $source,
            'payload' => $payload,
        ]));
    }

    private function stableJson(mixed $value): string
    {
        if (is_array($value)) {
            if (! array_is_list($value)) {
                ksort($value);
            }

            foreach ($value as $key => $nested) {
                $value[$key] = $this->stableJsonValue($nested);
            }
        }

        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private function stableJsonValue(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        foreach ($value as $key => $nested) {
            $value[$key] = $this->stableJsonValue($nested);
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function upsertAgendaBlock(
        AtlasTask $task,
        CarbonImmutable $date,
        string $timezone,
        CarbonImmutable $start,
        CarbonImmutable $end,
        array $item,
    ): ?AtlasCalendarBlock {
        if (! Schema::hasTable('atlas_calendar_blocks')) {
            return null;
        }

        return AtlasCalendarBlock::query()->updateOrCreate([
            'source' => 'system',
            'source_ref' => 'agenda_plan:'.$date->toDateString().':'.$task->id,
        ], [
            'block_date' => $date->toDateString(),
            'timezone' => $timezone,
            'title' => $task->title,
            'starts_at' => $start,
            'ends_at' => $end,
            'task_id' => $task->id,
            'metadata' => [
                'created_from' => 'agenda_plan',
                'task_id' => $task->id,
                'domain' => $task->domain,
                'agenda_score' => $item['agenda_score'] ?? null,
                'bucket' => $item['bucket'] ?? null,
                'agenda_intent' => $item['agenda_intent'] ?? null,
                'agenda_risk' => $item['agenda_risk'] ?? null,
                'project_signal' => $item['project_signal'] ?? null,
                'why' => $item['why'] ?? [],
                'planned_at' => now()->toJSON(),
            ],
        ]);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $ranked
     * @param  array<int, array<string, mixed>>  $blocks
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function scheduleAgenda(
        Collection $ranked,
        CarbonImmutable $date,
        string $timezone,
        int $capacityMinutes,
        ?int $energyLevel,
        array $blocks,
        array $context,
    ): array {
        $cursor = $date->setTimezone($timezone)->setTime(9, 0);
        $usedMinutes = 0;
        $selected = [];
        $backlog = [];

        foreach ($ranked as $item) {
            $minutes = (int) $item['estimated_minutes'];
            if ($usedMinutes + $minutes > $capacityMinutes && $selected !== []) {
                $backlog[] = $item;

                continue;
            }

            $start = $item['planned_start_at']
                ? CarbonImmutable::parse((string) $item['planned_start_at'], $timezone)
                : $this->advancePastBlocks($cursor, $minutes, $blocks, $timezone);
            $end = $item['planned_end_at']
                ? CarbonImmutable::parse((string) $item['planned_end_at'], $timezone)
                : $start->addMinutes($minutes);

            $selected[] = [
                ...$item,
                'recommended_start_at' => $start->toJSON(),
                'recommended_end_at' => $end->toJSON(),
            ];
            $usedMinutes += $minutes;
            $cursor = $end->addMinutes($minutes <= 20 ? 5 : 10);
        }

        return [
            'date' => $date->toDateString(),
            'timezone' => $timezone,
            'capacity_minutes' => $capacityMinutes,
            'scheduled_minutes' => $usedMinutes,
            'energy_level' => $energyLevel,
            'summary' => [
                'task_count' => count($selected),
                'backlog_count' => count($backlog),
                'focus_task' => $selected[0]['title'] ?? null,
                'strategy' => $this->strategyLabel($energyLevel),
            ],
            'context' => $context,
            'blocks' => $blocks,
            'tasks' => $selected,
            'backlog' => array_slice($backlog, 0, 12),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function contextForAgenda(CarbonImmutable $date, string $timezone, ?int $requestedEnergyLevel): array
    {
        $start = $date->startOfDay()->setTimezone('UTC');
        $end = $date->endOfDay()->setTimezone('UTC');

        $checkin = Schema::hasTable('checkins')
            ? Checkin::query()
                ->whereBetween('recorded_at', [$start, $end])
                ->latest('recorded_at')
                ->first()
            : null;
        $health = Schema::hasTable('health_snapshots')
            ? HealthSnapshot::query()
                ->whereDate('snapshot_date', $date->toDateString())
                ->orderByDesc('computed_at')
                ->first()
            : null;
        $digital = Schema::hasTable('digital_activity_snapshots')
            ? DigitalActivitySnapshot::query()
                ->whereDate('snapshot_date', $date->toDateString())
                ->orderByDesc('computed_at')
                ->first()
            : null;

        $energyLevel = $requestedEnergyLevel
            ?? $checkin?->energy_level
            ?? $health?->energy_level;

        return [
            'energy_level' => $energyLevel ? $this->clamp((int) $energyLevel, 1, 5) : null,
            'energy_source' => $requestedEnergyLevel
                ? 'request'
                : ($checkin?->energy_level ? 'checkin' : ($health?->energy_level ? 'health_snapshot' : null)),
            'checkin_state' => $checkin?->state,
            'mood_level' => $checkin?->mood_level ?? $health?->mood_level,
            'readiness_score' => $health?->readiness_score,
            'current_score' => $health?->current_score,
            'sleep_duration_hours' => $health?->sleep_duration_hours,
            'deep_work_minutes' => $digital?->deep_work_total_min,
            'algorithmic_input_minutes' => $digital?->algorithmic_input_min,
            'digital_load' => $this->digitalLoadLabel($digital?->algorithmic_input_min),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function blocksForDate(CarbonImmutable $date, string $timezone): array
    {
        if (! Schema::hasTable('atlas_calendar_blocks')) {
            return [];
        }

        return AtlasCalendarBlock::query()
            ->whereDate('block_date', $date->toDateString())
            ->orderBy('starts_at')
            ->get()
            ->map(fn (AtlasCalendarBlock $block): array => [
                'id' => $block->id,
                'title' => $block->title,
                'block_date' => $block->block_date?->toDateString(),
                'timezone' => $block->timezone ?: $timezone,
                'starts_at' => $block->starts_at?->toJSON(),
                'ends_at' => $block->ends_at?->toJSON(),
                'source' => $block->source,
                'source_ref' => $block->source_ref,
                'task_id' => $block->task_id,
            ])
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $blocks
     */
    private function advancePastBlocks(CarbonImmutable $start, int $minutes, array $blocks, string $timezone): CarbonImmutable
    {
        $candidate = $start;
        $guard = 0;

        do {
            $moved = false;
            $end = $candidate->addMinutes($minutes);

            foreach ($blocks as $block) {
                if (empty($block['starts_at']) || empty($block['ends_at'])) {
                    continue;
                }

                $blockStart = CarbonImmutable::parse((string) $block['starts_at'], $timezone);
                $blockEnd = CarbonImmutable::parse((string) $block['ends_at'], $timezone);
                if ($candidate->lt($blockEnd) && $end->gt($blockStart)) {
                    $candidate = $blockEnd->addMinutes(5);
                    $moved = true;
                    break;
                }
            }

            $guard++;
        } while ($moved && $guard < 20);

        return $candidate;
    }

    /**
     * @return array<string, mixed>
     */
    private function agendaItem(AtlasTask $task, CarbonImmutable $date, string $timezone, ?int $energyLevel): array
    {
        $dueAt = $task->due_at ? CarbonImmutable::parse($task->due_at)->setTimezone($timezone) : null;
        $dueBoost = $dueAt ? match (true) {
            $dueAt->lt($date) => 18,
            $dueAt->isSameDay($date) => 14,
            $dueAt->diffInDays($date, false) >= -2 => 7,
            default => 0,
        } : 0;
        $energyMatch = $this->energyMatch((string) $task->energy_required, $energyLevel);
        $quickWin = ((int) $task->estimated_minutes <= 20 && (int) $task->priority_score >= 45) ? 6 : 0;
        $projectSignal = $this->projectAgendaSignal($task, $energyLevel);
        $agendaScore = $this->clamp((int) $task->priority_score + $dueBoost + $energyMatch + $quickWin + (int) $projectSignal['score_adjustment'], 0, 100);

        return [
            'id' => $task->id,
            'title' => $task->title,
            'description' => $task->description,
            'status' => $task->status,
            'priority' => $task->priority,
            'domain' => $task->domain,
            'source_capture_id' => $task->source_capture_id,
            'project_id' => $task->project_id,
            'project_step_id' => $task->project_step_id,
            'routine_id' => $task->routine_id,
            'routine_occurrence_date' => $task->routine_occurrence_date?->toDateString(),
            'project_title' => $task->project?->title,
            'project_type' => $task->project?->project_type,
            'project_step_title' => $task->projectStep?->title,
            'project_step_order' => $task->projectStep?->step_order,
            'routine_title' => $task->routine?->title,
            'routine_frequency' => $task->routine?->frequency,
            'due_at' => $task->due_at?->toJSON(),
            'planned_for_date' => $task->planned_for_date?->toDateString(),
            'planned_start_at' => $task->planned_start_at?->toJSON(),
            'planned_end_at' => $task->planned_end_at?->toJSON(),
            'estimated_minutes' => (int) $task->estimated_minutes,
            'energy_required' => $task->energy_required,
            'priority_score' => (int) $task->priority_score,
            'execution_mode' => $task->execution_mode,
            'friction_level' => (int) $task->friction_level,
            'emotional_resistance' => (int) $task->emotional_resistance,
            'clarity_level' => (int) $task->clarity_level,
            'starter_step' => $task->starter_step,
            'minimum_viable_action' => $task->minimum_viable_action,
            'if_then_plan' => $task->if_then_plan,
            'reward_hint' => $task->reward_hint,
            'failure_reason_last' => $task->failure_reason_last,
            'attempt_count' => (int) $task->attempt_count,
            'recovery_count' => (int) $task->recovery_count,
            'agenda_score' => $agendaScore,
            'bucket' => $this->bucket($task, $dueBoost, $projectSignal),
            'agenda_intent' => $projectSignal['intent'],
            'agenda_risk' => $projectSignal['risk'],
            'project_signal' => $projectSignal,
            'why' => $this->why($task, $dueBoost, $energyMatch, $quickWin, $projectSignal),
        ];
    }

    private function priority(?string $priority): string
    {
        return array_key_exists((string) $priority, self::PRIORITY_BASE) ? (string) $priority : 'normal';
    }

    private function estimateMinutes(string $text): int
    {
        $lower = mb_strtolower($text);
        if (preg_match('/\b(implementar|estruturar|desenvolver|arquitetar|planejar|escrever|analisar)\b/u', $lower)) {
            return 60;
        }
        if (preg_match('/\b(ligar|enviar|pagar|colocar|marcar|responder|comprar)\b/u', $lower)) {
            return 15;
        }
        if (mb_strlen($text) > 220) {
            return 45;
        }

        return 25;
    }

    private function energyRequired(string $text, int $estimatedMinutes, string $priority): string
    {
        $lower = mb_strtolower($text);
        if ($estimatedMinutes >= 50 || preg_match('/\b(decidir|estratégia|estrategia|implementar|criar|profundo|arquitetar)\b/u', $lower)) {
            return 'high';
        }
        if ($priority === 'low' || $estimatedMinutes <= 15) {
            return 'low';
        }

        return 'medium';
    }

    private function urgencyScore(mixed $dueAt, string $priority): int
    {
        $score = self::PRIORITY_BASE[$priority] ?? 50;
        if (! $dueAt) {
            return $score;
        }

        $days = now()->diffInDays(CarbonImmutable::parse($dueAt), false);
        if ($days < 0) {
            return 100;
        }
        if ($days === 0) {
            return max($score, 86);
        }
        if ($days <= 2) {
            return max($score, 76);
        }
        if ($days <= 7) {
            return max($score, 64);
        }

        return $score;
    }

    private function impactScore(string $text, string $priority): int
    {
        $score = self::PRIORITY_BASE[$priority] ?? 50;
        $lower = mb_strtolower($text);
        if (preg_match('/\b(projeto|cliente|receita|saúde|saude|decisão|decisao|estratégia|estrategia|black ink|atlas)\b/u', $lower)) {
            $score += 12;
        }
        if (preg_match('/\b(teste|ideia|talvez|ver depois)\b/u', $lower)) {
            $score -= 6;
        }

        return $this->clamp($score, 0, 100);
    }

    private function effortScore(int $estimatedMinutes, string $energy): int
    {
        $score = min(100, (int) round(($estimatedMinutes / 120) * 70));

        return $this->clamp($score + ($energy === 'high' ? 20 : ($energy === 'medium' ? 10 : 0)), 0, 100);
    }

    private function score(string $priority, int $urgency, int $impact, int $effort): int
    {
        $base = self::PRIORITY_BASE[$priority] ?? 50;

        return $this->clamp((int) round(($base * 0.42) + ($urgency * 0.28) + ($impact * 0.24) - ($effort * 0.06)), 0, 100);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function capacityForEnergy(?int $energyLevel, array $context = []): int
    {
        $base = match ($energyLevel) {
            1 => 120,
            2 => 180,
            3 => 240,
            4 => 330,
            5 => 420,
            default => 300,
        };

        $current = is_numeric($context['current_score'] ?? null) ? (int) $context['current_score'] : null;
        $sleep = is_numeric($context['sleep_duration_hours'] ?? null) ? (float) $context['sleep_duration_hours'] : null;
        $algorithmicInput = is_numeric($context['algorithmic_input_minutes'] ?? null) ? (int) $context['algorithmic_input_minutes'] : null;

        if ($current !== null && $current < 45) {
            $base -= 45;
        }
        if ($sleep !== null && $sleep < 6) {
            $base -= 30;
        }
        if ($algorithmicInput !== null && $algorithmicInput > 180) {
            $base -= 30;
        }

        return $this->clamp($base, 90, 420);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function capacityForWeekDay(array $data, CarbonImmutable $date, bool $isWeekend): int
    {
        if (isset($data['capacity_minutes']) && is_numeric($data['capacity_minutes'])) {
            return $this->clamp((int) $data['capacity_minutes'], 30, 720);
        }

        if ($isWeekend) {
            return isset($data['weekend_capacity_minutes']) && is_numeric($data['weekend_capacity_minutes'])
                ? $this->clamp((int) $data['weekend_capacity_minutes'], 0, 360)
                : 90;
        }

        if (isset($data['weekday_capacity_minutes']) && is_numeric($data['weekday_capacity_minutes'])) {
            return $this->clamp((int) $data['weekday_capacity_minutes'], 30, 720);
        }

        $context = $this->contextForAgenda($date, (string) ($data['timezone'] ?? config('app.timezone', 'UTC')), isset($data['energy_level']) ? (int) $data['energy_level'] : null);

        return $this->capacityForEnergy($context['energy_level'], $context);
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyAgenda(CarbonImmutable $date, string $timezone, string $strategy): array
    {
        return [
            'date' => $date->toDateString(),
            'timezone' => $timezone,
            'capacity_minutes' => 0,
            'scheduled_minutes' => 0,
            'energy_level' => null,
            'summary' => [
                'task_count' => 0,
                'backlog_count' => 0,
                'focus_task' => null,
                'strategy' => $strategy,
            ],
            'context' => [
                'energy_level' => null,
                'energy_source' => null,
                'checkin_state' => null,
                'mood_level' => null,
                'readiness_score' => null,
                'current_score' => null,
                'sleep_duration_hours' => null,
                'deep_work_minutes' => null,
                'algorithmic_input_minutes' => null,
                'digital_load' => null,
            ],
            'blocks' => [],
            'tasks' => [],
            'backlog' => [],
        ];
    }

    private function weekStrategy(int $tasks, int $focusDays, int $scheduledMinutes): string
    {
        if ($tasks === 0) {
            return 'sem tarefas executáveis para distribuir';
        }
        if ($focusDays <= 2) {
            return 'semana leve: poucos dias com execução concentrada';
        }
        if ($scheduledMinutes >= 900) {
            return 'semana carregada: proteger foco e evitar excesso de compromissos';
        }

        return 'semana balanceada: distribuir prioridade sem lotar todos os dias';
    }

    private function energyMatch(string $required, ?int $energyLevel): int
    {
        if (! $energyLevel) {
            return 0;
        }
        if ($energyLevel <= 2) {
            return $required === 'low' ? 8 : ($required === 'high' ? -12 : 0);
        }
        if ($energyLevel >= 4) {
            return $required === 'high' ? 8 : 0;
        }

        return $required === 'medium' ? 4 : 0;
    }

    /**
     * @return array<int, string>
     */
    private function taskAgendaRelations(): array
    {
        return Schema::hasTable('atlas_project_blockers')
            ? ['project.openBlockers', 'projectStep', 'routine']
            : ['project', 'projectStep', 'routine'];
    }

    /**
     * @return array{intent: string, risk: string|null, score_adjustment: int, bucket: string|null, why: array<int, string>}
     */
    private function projectAgendaSignal(AtlasTask $task, ?int $energyLevel): array
    {
        $score = 0;
        $intent = 'execute';
        $risk = null;
        $bucket = null;
        $why = [];

        $taskMetadata = is_array($task->metadata) ? $task->metadata : [];
        $execution = is_array($taskMetadata['execution'] ?? null) ? $taskMetadata['execution'] : [];
        $defer = is_array($taskMetadata['defer'] ?? null) ? $taskMetadata['defer'] : [];
        $projectMetadata = is_array($task->project?->metadata) ? $task->project->metadata : [];
        $learning = is_array($projectMetadata['execution_learning'] ?? null) ? $projectMetadata['execution_learning'] : [];
        $deferLearning = is_array($projectMetadata['defer_learning'] ?? null) ? $projectMetadata['defer_learning'] : [];
        $role = (string) ($taskMetadata['role'] ?? '');
        $openBlockersCount = $task->project && $task->project->relationLoaded('openBlockers')
            ? $task->project->openBlockers->count()
            : 0;

        if ($role === 'unblock_action') {
            $score += 18;
            $intent = 'unblock';
            $risk = 'open_blocker';
            $bucket = 'destravamento';
            $why[] = 'ação criada especificamente para destravar um projeto bloqueado';
        } elseif ($openBlockersCount > 0) {
            $score -= 4;
            $risk = $risk ?? 'project_has_open_blocker';
            $why[] = 'projeto tem bloqueio aberto; prefira uma ação de destravamento se existir';
        }

        if ($task->execution_mode === 'recovery') {
            $score += 14;
            if ($intent !== 'unblock') {
                $intent = 'recover';
                $bucket = 'retomada';
            }
            $why[] = 'ação de retomada criada para reduzir inércia';
        } elseif ((int) $task->recovery_count > 0) {
            $score += 6;
            $intent = 'recover';
            $bucket = 'retomada';
            $why[] = 'ação já teve retomadas; manter pequena ajuda continuidade';
        }

        $lastQuality = (string) ($execution['last_completion_quality'] ?? $learning['last_completion_quality'] ?? '');
        $lastTaskId = (string) ($learning['last_completed_task_id'] ?? '');
        if ($lastQuality === 'partial' && ($lastTaskId === '' || $lastTaskId === $task->id)) {
            $score += 10;
            $intent = $intent === 'execute' ? 'continue' : $intent;
            $bucket = $bucket ?? 'continuação';
            $why[] = 'progresso parcial recente; manter continuidade';
        }

        $recommendedAction = (string) ($defer['recommended_action'] ?? $deferLearning['recommended_action'] ?? '');
        if ($recommendedAction === 'rebuild_plan') {
            $score += 8;
            $intent = 'replan';
            $risk = 'too_big_or_unclear';
            $bucket = 'destravamento';
            $why[] = 'ação adiada pede replanejamento menor antes de executar';
        } elseif ($recommendedAction === 'ensure_next_action') {
            $score += 7;
            $intent = 'unblock';
            $risk = 'blocked_or_waiting';
            $bucket = 'destravamento';
            $why[] = 'ação adiada pede destravamento ou próxima ação física';
        } elseif ($recommendedAction === 'recover' && $intent === 'execute') {
            $score += 6;
            $intent = 'recover';
            $bucket = 'retomada';
            $why[] = 'ação adiada pode voltar como bloco curto';
        }

        if ($task->failure_reason_last) {
            $score += 2;
            $risk = $risk ?? 'friction';
            $why[] = 'há atrito registrado para considerar antes de começar';
        }

        if ($energyLevel !== null && $energyLevel <= 2 && $task->energy_required === 'high' && ! in_array($intent, ['recover', 'replan', 'unblock'], true)) {
            $score -= 8;
            $risk = $risk ?? 'energy_mismatch';
            $why[] = 'energia baixa torna essa ação mais arriscada agora';
        }

        return [
            'intent' => $intent,
            'risk' => $risk,
            'score_adjustment' => $this->clamp($score, -20, 24),
            'bucket' => $bucket,
            'why' => $why,
        ];
    }

    /**
     * @param  array{bucket: string|null}  $projectSignal
     */
    private function bucket(AtlasTask $task, int $dueBoost, array $projectSignal): string
    {
        if ($dueBoost > 0) {
            return 'prazo';
        }
        if (is_string($projectSignal['bucket'] ?? null) && $projectSignal['bucket'] !== '') {
            return $projectSignal['bucket'];
        }
        if ($task->energy_required === 'high') {
            return 'foco profundo';
        }
        if ((int) $task->estimated_minutes <= 20) {
            return 'rápida';
        }

        return 'execução';
    }

    /**
     * @param  array{why: array<int, string>, score_adjustment: int}  $projectSignal
     * @return array<int, string>
     */
    private function why(AtlasTask $task, int $dueBoost, int $energyMatch, int $quickWin, array $projectSignal): array
    {
        return array_values(array_filter([
            'prioridade '.$task->priority,
            $dueBoost > 0 ? 'prazo influencia a ordem' : null,
            $energyMatch > 0 ? 'combina com sua energia atual' : null,
            $energyMatch < 0 ? 'exige mais energia do que o ideal agora' : null,
            $quickWin > 0 ? 'ganho rápido' : null,
            ...$projectSignal['why'],
            (int) $projectSignal['score_adjustment'] !== 0 ? 'sinal de projeto '.(((int) $projectSignal['score_adjustment'] > 0 ? '+' : '').$projectSignal['score_adjustment']) : null,
            'score '.$task->priority_score,
        ]));
    }

    private function strategyLabel(?int $energyLevel): string
    {
        return match (true) {
            $energyLevel !== null && $energyLevel <= 2 => 'energia baixa: priorizar tarefas curtas e inevitáveis',
            $energyLevel !== null && $energyLevel >= 4 => 'energia alta: colocar trabalho profundo no começo',
            default => 'balancear urgência, impacto e esforço',
        };
    }

    private function digitalLoadLabel(mixed $algorithmicInputMinutes): ?string
    {
        if (! is_numeric($algorithmicInputMinutes)) {
            return null;
        }

        $minutes = (int) $algorithmicInputMinutes;
        if ($minutes >= 240) {
            return 'alto';
        }
        if ($minutes >= 90) {
            return 'moderado';
        }

        return 'baixo';
    }

    private function validEnergy(mixed $value): string
    {
        return in_array($value, ['low', 'medium', 'high'], true) ? $value : 'medium';
    }

    private function intFromData(array $data, string $key): ?int
    {
        return array_key_exists($key, $data) && is_numeric($data[$key]) ? (int) $data[$key] : null;
    }

    private function metadataInt(array $data, string $path): ?int
    {
        $value = data_get($data, 'metadata.'.$path);

        return is_numeric($value) ? (int) $value : null;
    }

    private function metadataString(array $data, string $path): ?string
    {
        $value = data_get($data, 'metadata.'.$path);

        return is_string($value) ? $value : null;
    }

    private function clamp(int $value, int $min, int $max): int
    {
        return max($min, min($max, $value));
    }
}
