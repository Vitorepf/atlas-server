<?php

namespace App\Http\Controllers;

use App\Http\Requests\IndexProcrastinationEventRequest;
use App\Http\Requests\StoreProcrastinationEventRequest;
use App\Http\Requests\UpdateProcrastinationEventRequest;
use App\Http\Resources\ProcrastinationEventResource;
use App\Models\ProcrastinationEvent;
use App\Support\Metadata;
use Illuminate\Http\JsonResponse;

class ProcrastinationEventController extends Controller
{
    public function index(IndexProcrastinationEventRequest $request): JsonResponse
    {
        $data = $request->validated();
        $limit = (int) ($data['limit'] ?? 50);
        $since = $data['since'] ?? null;

        $query = ProcrastinationEvent::query();

        if ($since) {
            $query->withTrashed()->where('updated_at', '>', $since);
        }

        if (isset($data['date_from'])) {
            $query->where('detected_at', '>=', $data['date_from']);
        }

        if (isset($data['date_to'])) {
            $query->where('detected_at', '<=', $data['date_to']);
        }

        if (isset($data['confronted'])) {
            $query->where('confronted', $data['confronted']);
        }

        if (isset($data['cursor'])) {
            $cursor = ProcrastinationEvent::withTrashed()->find($data['cursor']);

            if ($cursor) {
                $query->where(function ($query) use ($cursor, $since): void {
                    $column = $since ? 'updated_at' : 'detected_at';
                    $operator = $since ? '>' : '<';
                    $idOperator = $since ? '>' : '<';

                    $query
                        ->where($column, $operator, $cursor->{$column})
                        ->orWhere(function ($query) use ($cursor, $column, $idOperator): void {
                            $query->where($column, $cursor->{$column})->where('id', $idOperator, $cursor->id);
                        });
                });
            }
        }

        $query = $since
            ? $query->orderBy('updated_at')->orderBy('id')
            : $query->orderByDesc('detected_at')->orderByDesc('id');

        $events = $query->limit($limit + 1)->get();
        $hasMore = $events->count() > $limit;
        $page = $hasMore ? $events->take($limit)->values() : $events;

        return response()->json([
            'procrastination_events' => ProcrastinationEventResource::collection($page)->resolve(),
            'next_cursor' => $hasMore ? $page->last()?->id : null,
            'has_more' => $hasMore,
        ]);
    }

    public function store(StoreProcrastinationEventRequest $request): JsonResponse
    {
        $data = $this->preparePayload($request->validated());
        $event = ProcrastinationEvent::withTrashed()->where('client_id', $data['client_id'])->first();
        $created = false;

        if (! $event) {
            $event = ProcrastinationEvent::create($data);
            $created = true;
        } else {
            $event->fill($data)->save();
        }

        return (new ProcrastinationEventResource($event->refresh()))
            ->response()
            ->setStatusCode($created ? 201 : 200);
    }

    public function update(UpdateProcrastinationEventRequest $request, ProcrastinationEvent $procrastinationEvent): ProcrastinationEventResource
    {
        $procrastinationEvent->update($this->preparePayload($request->validated()));

        return new ProcrastinationEventResource($procrastinationEvent->refresh());
    }

    public function destroy(ProcrastinationEvent $procrastinationEvent): ProcrastinationEventResource
    {
        $procrastinationEvent->delete();

        return new ProcrastinationEventResource($procrastinationEvent->refresh());
    }

    private function preparePayload(array $data): array
    {
        foreach (['mission_context', 'physiological_state', 'subjective_state', 'digital_context', 'metadata'] as $field) {
            if (array_key_exists($field, $data)) {
                $data[$field] = Metadata::forStorage($data[$field]);
            }
        }

        return $data;
    }
}
