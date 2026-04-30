<?php

namespace App\Http\Controllers;

use App\Http\Resources\AtlasRoutineEventResource;
use App\Http\Resources\AtlasRoutineResource;
use App\Http\Resources\AtlasTaskResource;
use App\Models\AtlasRoutine;
use App\Services\AtlasDomainRegistry;
use App\Services\RoutineSchedulingService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AtlasRoutineController extends Controller
{
    public function index(Request $request, AtlasDomainRegistry $domains): JsonResponse
    {
        $data = $request->validate([
            'domain' => ['nullable', 'string', 'max:80', Rule::in($domains->activeSlugs())],
            'status' => ['nullable', 'string', Rule::in(['active', 'paused', 'archived', 'all'])],
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $query = AtlasRoutine::query()
            ->with('project')
            ->withCount('tasks')
            ->orderByRaw('next_occurrence_date IS NULL')
            ->orderBy('next_occurrence_date')
            ->orderBy('created_at');
        if (! empty($data['domain'])) {
            $query->where('domain', $data['domain']);
        }
        if (($data['status'] ?? 'active') !== 'all') {
            $query->where('status', $data['status'] ?? 'active');
        }

        return response()->json([
            'routines' => AtlasRoutineResource::collection($query->limit((int) ($data['limit'] ?? 80))->get())->resolve(),
        ]);
    }

    public function store(Request $request, AtlasDomainRegistry $domains, RoutineSchedulingService $scheduling): JsonResponse
    {
        $data = $this->validatedRoutine($request, $domains);
        $routine = new AtlasRoutine($this->normalizedRoutinePayload($data));

        if (! $routine->next_occurrence_date && $routine->status === 'active') {
            $routine->next_occurrence_date = $this->firstOccurrence($routine, $scheduling)?->toDateString();
        }

        $routine->save();
        $scheduling->recordEvent($routine, 'created', [
            'title' => $routine->title,
            'frequency' => $routine->frequency,
            'weekdays' => $routine->weekdays,
            'next_occurrence_date' => $routine->next_occurrence_date?->toDateString(),
        ], 'routines.store');

        return (new AtlasRoutineResource($routine->refresh()->load('project')->loadCount('tasks')))
            ->response()
            ->setStatusCode(201);
    }

    public function show(AtlasRoutine $routine): AtlasRoutineResource
    {
        return new AtlasRoutineResource($routine->load('project')->loadCount('tasks'));
    }

    public function update(
        Request $request,
        AtlasRoutine $routine,
        AtlasDomainRegistry $domains,
        RoutineSchedulingService $scheduling,
    ): AtlasRoutineResource {
        $data = $this->validatedRoutine($request, $domains, partial: true);
        $scheduleChanged = array_intersect(array_keys($data), [
            'status',
            'frequency',
            'weekdays',
            'timezone',
            'preferred_time',
        ]) !== [];

        $routine->fill($this->normalizedRoutinePayload([
            ...$routine->toArray(),
            ...$data,
        ], partial: true));

        if ($routine->status === 'active' && ($scheduleChanged || ! $routine->next_occurrence_date)) {
            $routine->next_occurrence_date = $this->firstOccurrence($routine, $scheduling)?->toDateString();
        }
        if ($routine->status !== 'active') {
            $routine->next_occurrence_date = null;
        }

        $routine->save();
        $changes = $routine->getChanges();
        unset($changes['updated_at']);
        if ($changes !== []) {
            $scheduling->recordEvent($routine, 'updated', [
                'changes' => $changes,
            ], 'routines.update');
        }

        return new AtlasRoutineResource($routine->refresh()->load('project')->loadCount('tasks'));
    }

    public function generate(Request $request, AtlasRoutine $routine, RoutineSchedulingService $scheduling): JsonResponse
    {
        $data = $request->validate([
            'date' => ['nullable', 'date'],
            'timezone' => ['nullable', 'timezone'],
        ]);

        $task = $scheduling->generateOccurrence(
            $routine,
            $data['date'] ?? null,
            $data['timezone'] ?? null,
            'routines.generate',
        );

        return response()->json([
            'routine' => (new AtlasRoutineResource($routine->refresh()->load('project')->loadCount('tasks')))->resolve(),
            'task' => $task ? (new AtlasTaskResource($task->load(['project', 'projectStep', 'routine'])))->resolve() : null,
        ]);
    }

    public function generateDue(Request $request, AtlasDomainRegistry $domains, RoutineSchedulingService $scheduling): JsonResponse
    {
        $data = $request->validate([
            'date' => ['nullable', 'date'],
            'timezone' => ['nullable', 'timezone'],
            'domain' => ['nullable', 'string', 'max:80', Rule::in($domains->activeSlugs())],
        ]);

        $timezone = (string) ($data['timezone'] ?? config('app.timezone', 'UTC'));
        $date = CarbonImmutable::parse($data['date'] ?? now($timezone)->toDateString(), $timezone);
        $results = $scheduling->generateDue($date, $timezone, $data['domain'] ?? null);

        return response()->json([
            'date' => $date->toDateString(),
            'timezone' => $timezone,
            'generated_count' => count($results),
            'created_count' => collect($results)->where('created', true)->count(),
            'routines' => AtlasRoutineResource::collection(collect($results)->pluck('routine'))->resolve(),
            'tasks' => AtlasTaskResource::collection(collect($results)->pluck('task'))->resolve(),
        ]);
    }

    public function events(Request $request, AtlasRoutine $routine): JsonResponse
    {
        $data = $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return response()->json([
            'events' => AtlasRoutineEventResource::collection(
                $routine->events()
                    ->latest('occurred_at')
                    ->limit((int) ($data['limit'] ?? 30))
                    ->get()
            )->resolve(),
        ]);
    }

    private function validatedRoutine(Request $request, AtlasDomainRegistry $domains, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'title' => [$required, 'string', 'max:180'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'status' => ['sometimes', Rule::in(['active', 'paused', 'archived'])],
            'domain' => [$required, 'string', 'max:80', Rule::in($domains->activeSlugs())],
            'source_capture_id' => ['sometimes', 'nullable', 'uuid', 'exists:captures,id'],
            'project_id' => ['sometimes', 'nullable', 'uuid', 'exists:atlas_projects,id'],
            'frequency' => ['sometimes', Rule::in(['daily', 'weekdays', 'weekly', 'custom'])],
            'weekdays' => ['sometimes', 'array', 'max:7'],
            'weekdays.*' => ['integer', 'min:1', 'max:7'],
            'timezone' => ['sometimes', 'timezone'],
            'preferred_time' => ['sometimes', 'nullable', 'date_format:H:i'],
            'estimated_minutes' => ['sometimes', 'integer', 'min:5', 'max:480'],
            'energy_required' => ['sometimes', Rule::in(['low', 'medium', 'high'])],
            'priority' => ['sometimes', Rule::in(['low', 'normal', 'high', 'urgent'])],
            'execution_mode' => ['sometimes', Rule::in(['quick_win', 'deep_work', 'admin', 'study', 'tedious', 'creative', 'decision', 'maintenance', 'recovery'])],
            'friction_level' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'emotional_resistance' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'clarity_level' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'starter_step' => ['sometimes', 'nullable', 'string', 'max:300'],
            'minimum_viable_action' => ['sometimes', 'nullable', 'string', 'max:300'],
            'if_then_plan' => ['sometimes', 'nullable', 'string', 'max:500'],
            'reward_hint' => ['sometimes', 'nullable', 'string', 'max:300'],
            'next_occurrence_date' => ['sometimes', 'nullable', 'date'],
            'metadata' => ['sometimes', 'array'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalizedRoutinePayload(array $data, bool $partial = false): array
    {
        $frequency = (string) ($data['frequency'] ?? 'daily');
        $timezone = (string) ($data['timezone'] ?? config('app.timezone', 'UTC'));
        $weekdays = $this->normalizedWeekdays($data['weekdays'] ?? [], $frequency, $timezone);

        $payload = [
            'title' => $data['title'] ?? null,
            'description' => $data['description'] ?? null,
            'status' => $data['status'] ?? 'active',
            'domain' => $data['domain'] ?? null,
            'source_capture_id' => $data['source_capture_id'] ?? null,
            'project_id' => $data['project_id'] ?? null,
            'frequency' => $frequency,
            'weekdays' => $weekdays,
            'timezone' => $timezone,
            'preferred_time' => $data['preferred_time'] ?? null,
            'estimated_minutes' => $data['estimated_minutes'] ?? 25,
            'energy_required' => $data['energy_required'] ?? 'medium',
            'priority' => $data['priority'] ?? 'normal',
            'execution_mode' => $data['execution_mode'] ?? 'maintenance',
            'friction_level' => $data['friction_level'] ?? 45,
            'emotional_resistance' => $data['emotional_resistance'] ?? 35,
            'clarity_level' => $data['clarity_level'] ?? 75,
            'starter_step' => $data['starter_step'] ?? null,
            'minimum_viable_action' => $data['minimum_viable_action'] ?? null,
            'if_then_plan' => $data['if_then_plan'] ?? null,
            'reward_hint' => $data['reward_hint'] ?? null,
            'next_occurrence_date' => $data['next_occurrence_date'] ?? null,
            'metadata' => $data['metadata'] ?? [],
        ];

        if (! $partial) {
            return $payload;
        }

        return array_filter(
            $payload,
            fn ($value, $key): bool => array_key_exists($key, $data) || in_array($key, ['weekdays'], true),
            ARRAY_FILTER_USE_BOTH,
        );
    }

    /**
     * @return array<int, int>
     */
    private function normalizedWeekdays(mixed $weekdays, string $frequency, string $timezone): array
    {
        if ($frequency === 'daily') {
            return [];
        }
        if ($frequency === 'weekdays') {
            return [1, 2, 3, 4, 5];
        }

        $days = collect(is_array($weekdays) ? $weekdays : [])
            ->map(fn (mixed $day): int => (int) $day)
            ->filter(fn (int $day): bool => $day >= 1 && $day <= 7)
            ->unique()
            ->sort()
            ->values()
            ->all();

        return $days !== [] ? $days : [now($timezone)->isoWeekday()];
    }

    private function firstOccurrence(AtlasRoutine $routine, RoutineSchedulingService $scheduling): ?CarbonImmutable
    {
        $timezone = $routine->timezone ?: config('app.timezone', 'UTC');
        $today = CarbonImmutable::parse(now($timezone)->toDateString(), $timezone);

        return $scheduling->occursOn($routine, $today)
            ? $today
            : $scheduling->nextOccurrenceAfter($routine, $today);
    }
}
