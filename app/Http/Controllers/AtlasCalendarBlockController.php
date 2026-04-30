<?php

namespace App\Http\Controllers;

use App\Http\Resources\AtlasCalendarBlockResource;
use App\Models\AtlasCalendarBlock;
use App\Models\AtlasTask;
use App\Services\TaskPlanningService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AtlasCalendarBlockController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'date' => ['nullable', 'date'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'timezone' => ['nullable', 'timezone'],
            'source' => ['nullable', Rule::in(['manual', 'external_calendar', 'rize', 'system'])],
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $timezone = (string) ($data['timezone'] ?? config('app.timezone', 'UTC'));
        $date = ! empty($data['date'])
            ? CarbonImmutable::parse($data['date'], $timezone)->toDateString()
            : null;

        $query = AtlasCalendarBlock::query()
            ->when($date, fn ($query) => $query->whereDate('block_date', $date))
            ->when(! $date && ! empty($data['date_from']), fn ($query) => $query->whereDate('block_date', '>=', $data['date_from']))
            ->when(! $date && ! empty($data['date_to']), fn ($query) => $query->whereDate('block_date', '<=', $data['date_to']))
            ->when(! empty($data['source']), fn ($query) => $query->where('source', $data['source']))
            ->orderBy('block_date')
            ->orderBy('starts_at')
            ->limit((int) ($data['limit'] ?? 80));

        return response()->json([
            'blocks' => AtlasCalendarBlockResource::collection($query->get())->resolve(),
        ]);
    }

    public function store(Request $request, TaskPlanningService $planning): JsonResponse
    {
        $data = $this->validatedBlock($request);

        $block = AtlasCalendarBlock::query()->create($this->normalizedBlockPayload($data));
        $this->recordTaskCalendarEvent($block, $planning, 'calendar_block_created');

        return (new AtlasCalendarBlockResource($block))
            ->response()
            ->setStatusCode(201);
    }

    public function update(Request $request, AtlasCalendarBlock $calendarBlock, TaskPlanningService $planning): AtlasCalendarBlockResource
    {
        $data = $this->validatedBlock($request, partial: true);

        $calendarBlock->update($this->normalizedBlockPayload([
            ...$calendarBlock->toArray(),
            ...$data,
        ], partial: true));
        $this->recordTaskCalendarEvent($calendarBlock->refresh(), $planning, 'calendar_block_updated');

        return new AtlasCalendarBlockResource($calendarBlock);
    }

    public function destroy(AtlasCalendarBlock $calendarBlock, TaskPlanningService $planning): AtlasCalendarBlockResource
    {
        $this->recordTaskCalendarEvent($calendarBlock, $planning, 'calendar_block_deleted');
        $calendarBlock->delete();

        return new AtlasCalendarBlockResource($calendarBlock->refresh());
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedBlock(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'block_date' => [$partial ? 'sometimes' : 'nullable', 'date'],
            'timezone' => [$required, 'timezone'],
            'title' => [$required, 'string', 'max:180'],
            'starts_at' => [$required, 'date'],
            'ends_at' => [$required, 'date', 'after:starts_at'],
            'source' => [$partial ? 'sometimes' : 'nullable', Rule::in(['manual', 'external_calendar', 'rize', 'system'])],
            'source_ref' => ['sometimes', 'nullable', 'string', 'max:240'],
            'task_id' => [
                'sometimes',
                'nullable',
                'uuid',
                Rule::exists('atlas_tasks', 'id')->whereNull('deleted_at'),
            ],
            'metadata' => ['sometimes', 'array'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalizedBlockPayload(array $data, bool $partial = false): array
    {
        $timezone = (string) ($data['timezone'] ?? config('app.timezone', 'UTC'));
        $startsAt = isset($data['starts_at']) ? CarbonImmutable::parse((string) $data['starts_at'], $timezone) : null;
        $blockDate = $data['block_date'] ?? $startsAt?->setTimezone($timezone)->toDateString();

        $payload = [
            'block_date' => $blockDate,
            'timezone' => $timezone,
            'title' => $data['title'] ?? null,
            'starts_at' => $data['starts_at'] ?? null,
            'ends_at' => $data['ends_at'] ?? null,
            'source' => $data['source'] ?? 'manual',
            'source_ref' => $data['source_ref'] ?? null,
            'task_id' => $data['task_id'] ?? null,
            'metadata' => $data['metadata'] ?? [],
        ];

        if (! $partial) {
            return $payload;
        }

        return array_filter(
            $payload,
            fn ($value, $key): bool => $value !== null || in_array($key, ['source_ref', 'task_id'], true),
            ARRAY_FILTER_USE_BOTH,
        );
    }

    private function recordTaskCalendarEvent(AtlasCalendarBlock $block, TaskPlanningService $planning, string $eventType): void
    {
        if (! $block->task_id) {
            return;
        }

        $task = AtlasTask::query()->find($block->task_id);
        if (! $task) {
            return;
        }

        $planning->recordEvent($task, $eventType, [
            'calendar_block_id' => $block->id,
            'title' => $block->title,
            'starts_at' => $block->starts_at?->toJSON(),
            'ends_at' => $block->ends_at?->toJSON(),
            'source' => $block->source,
            'source_ref' => $block->source_ref,
            'metadata' => $block->metadata,
        ], 'calendar');
    }
}
