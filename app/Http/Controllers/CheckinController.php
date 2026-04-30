<?php

namespace App\Http\Controllers;

use App\Http\Requests\IndexCheckinRequest;
use App\Http\Requests\StoreCheckinRequest;
use App\Http\Requests\UpdateCheckinRequest;
use App\Http\Resources\CheckinResource;
use App\Models\Checkin;
use App\Support\Metadata;
use Illuminate\Http\JsonResponse;

class CheckinController extends Controller
{
    public function index(IndexCheckinRequest $request): JsonResponse
    {
        $data = $request->validated();
        $limit = (int) ($data['limit'] ?? 50);
        $since = $data['since'] ?? null;

        $query = Checkin::query();

        if ($since) {
            $query->withTrashed()->where('updated_at', '>', $since);
        }

        if (isset($data['state'])) {
            $query->where('state', $data['state']);
        }

        if (isset($data['energy_level'])) {
            $query->where('energy_level', $data['energy_level']);
        }

        if (isset($data['mood_level'])) {
            $query->where('mood_level', $data['mood_level']);
        }

        if (isset($data['cursor'])) {
            $cursor = Checkin::withTrashed()->find($data['cursor']);

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
                            ->where('recorded_at', '<', $cursor->recorded_at)
                            ->orWhere(function ($query) use ($cursor): void {
                                $query->where('recorded_at', $cursor->recorded_at)->where('id', '<', $cursor->id);
                            });
                    });
                }
            }
        }

        $query = $since
            ? $query->orderBy('updated_at')->orderBy('id')
            : $query->orderByDesc('recorded_at')->orderByDesc('id');

        $checkins = $query->limit($limit + 1)->get();
        $hasMore = $checkins->count() > $limit;
        $page = $hasMore ? $checkins->take($limit)->values() : $checkins;

        return response()->json([
            'checkins' => CheckinResource::collection($page)->resolve(),
            'next_cursor' => $hasMore ? $page->last()?->id : null,
            'has_more' => $hasMore,
        ]);
    }

    public function store(StoreCheckinRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['metadata'] = Metadata::forStorage($data['metadata'] ?? []);
        $checkin = Checkin::withTrashed()
            ->where('client_id', $data['client_id'])
            ->first();
        $created = false;

        if (! $checkin) {
            $checkin = Checkin::create($data);
            $created = true;
        } else {
            if ($checkin->trashed()) {
                $checkin->restore();
            }
            $checkin->fill($data);
            if ($checkin->isDirty()) {
                $checkin->save();
            }
        }

        return (new CheckinResource($checkin->refresh()))
            ->response()
            ->setStatusCode($created ? 201 : 200);
    }

    public function update(UpdateCheckinRequest $request, Checkin $checkin): CheckinResource
    {
        $data = $request->validated();

        if (array_key_exists('metadata', $data)) {
            $data['metadata'] = Metadata::forStorage($data['metadata']);
        }

        $checkin->update($data);

        return new CheckinResource($checkin->refresh());
    }

    public function destroy(Checkin $checkin): CheckinResource
    {
        $checkin->delete();

        return new CheckinResource($checkin->refresh());
    }
}
