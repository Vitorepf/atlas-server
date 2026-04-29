<?php

namespace App\Http\Controllers;

use App\Http\Requests\IndexDigitalActivitySnapshotRequest;
use App\Http\Requests\StoreDigitalActivitySnapshotRequest;
use App\Http\Requests\UpdateDigitalActivitySnapshotRequest;
use App\Http\Resources\DigitalActivitySnapshotResource;
use App\Models\DigitalActivitySnapshot;
use App\Services\Digital\DigitalActivitySnapshotBuilder;
use App\Support\Metadata;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DigitalActivitySnapshotController extends Controller
{
    public function rebuild(Request $request, DigitalActivitySnapshotBuilder $builder): JsonResponse
    {
        $data = $request->validate([
            'date_from' => ['sometimes', 'date'],
            'date_to' => ['sometimes', 'date', 'after_or_equal:date_from'],
            'timezone' => ['sometimes', 'string', 'max:128'],
        ]);

        $timezone = $data['timezone'] ?? config('services.rize.timezone', config('app.timezone', 'UTC'));
        $from = CarbonImmutable::parse($data['date_from'] ?? now($timezone)->toDateString(), $timezone)->startOfDay();
        $to = CarbonImmutable::parse($data['date_to'] ?? $from->toDateString(), $timezone)->startOfDay();
        $snapshots = [];

        for ($date = $from; $date->lessThanOrEqualTo($to); $date = $date->addDay()) {
            $snapshots[] = $builder->rebuild($date, $timezone);
        }

        return response()->json([
            'digital_activity_snapshots' => DigitalActivitySnapshotResource::collection(collect($snapshots))->resolve(),
            'count' => count($snapshots),
        ]);
    }

    public function index(IndexDigitalActivitySnapshotRequest $request): JsonResponse
    {
        $data = $request->validated();
        $limit = (int) ($data['limit'] ?? 50);
        $since = $data['since'] ?? null;

        $query = DigitalActivitySnapshot::query();

        if ($since) {
            $query->withTrashed()->where('updated_at', '>', $since);
        }

        if (isset($data['source'])) {
            $query->where('source', $data['source']);
        }

        if (isset($data['date_from'])) {
            $query->where('snapshot_date', '>=', $data['date_from']);
        }

        if (isset($data['date_to'])) {
            $query->where('snapshot_date', '<=', $data['date_to']);
        }

        if (isset($data['cursor'])) {
            $cursor = DigitalActivitySnapshot::withTrashed()->find($data['cursor']);

            if ($cursor) {
                if ($since) {
                    $query->where(function ($query) use ($cursor): void {
                        $query
                            ->where('updated_at', '>', $cursor->updated_at)
                            ->orWhere(function ($query) use ($cursor): void {
                                $query->where('updated_at', $cursor->updated_at)->where('id', '>', $cursor->id);
                            });
                    });
                } else {
                    $query->where(function ($query) use ($cursor): void {
                        $query
                            ->where('snapshot_date', '<', $cursor->snapshot_date)
                            ->orWhere(function ($query) use ($cursor): void {
                                $query->where('snapshot_date', $cursor->snapshot_date)->where('id', '<', $cursor->id);
                            });
                    });
                }
            }
        }

        $query = $since
            ? $query->orderBy('updated_at')->orderBy('id')
            : $query->orderByDesc('snapshot_date')->orderByDesc('id');

        $snapshots = $query->limit($limit + 1)->get();
        $hasMore = $snapshots->count() > $limit;
        $page = $hasMore ? $snapshots->take($limit)->values() : $snapshots;

        return response()->json([
            'digital_activity_snapshots' => DigitalActivitySnapshotResource::collection($page)->resolve(),
            'next_cursor' => $hasMore ? $page->last()?->id : null,
            'has_more' => $hasMore,
        ]);
    }

    public function store(StoreDigitalActivitySnapshotRequest $request): JsonResponse
    {
        $data = $this->preparePayload($request->validated());
        $snapshot = DigitalActivitySnapshot::withTrashed()->where('client_id', $data['client_id'])->first();
        $created = false;

        if (! $snapshot) {
            $snapshot = DigitalActivitySnapshot::create($data);
            $created = true;
        } else {
            $snapshot->fill($data)->save();
        }

        return (new DigitalActivitySnapshotResource($snapshot->refresh()))
            ->response()
            ->setStatusCode($created ? 201 : 200);
    }

    public function update(UpdateDigitalActivitySnapshotRequest $request, DigitalActivitySnapshot $digitalActivitySnapshot): DigitalActivitySnapshotResource
    {
        $digitalActivitySnapshot->update($this->preparePayload($request->validated()));

        return new DigitalActivitySnapshotResource($digitalActivitySnapshot->refresh());
    }

    public function destroy(DigitalActivitySnapshot $digitalActivitySnapshot): DigitalActivitySnapshotResource
    {
        $digitalActivitySnapshot->delete();

        return new DigitalActivitySnapshotResource($digitalActivitySnapshot->refresh());
    }

    private function preparePayload(array $data): array
    {
        foreach (['focus_mode_active_min', 'category_breakdown', 'source_breakdown', 'raw_rize_data', 'raw_screentime_data', 'metadata'] as $field) {
            if (array_key_exists($field, $data)) {
                $data[$field] = Metadata::forStorage($data[$field]);
            }
        }

        return $data;
    }
}
