<?php

namespace App\Http\Controllers;

use App\Http\Requests\IndexPassiveSignalRequest;
use App\Http\Requests\StorePassiveSignalRequest;
use App\Http\Requests\UpdatePassiveSignalRequest;
use App\Http\Resources\PassiveSignalResource;
use App\Models\PassiveSignal;
use App\Support\Metadata;
use Illuminate\Http\JsonResponse;

class PassiveSignalController extends Controller
{
    public function index(IndexPassiveSignalRequest $request): JsonResponse
    {
        $data = $request->validated();
        $limit = (int) ($data['limit'] ?? 50);
        $since = $data['since'] ?? null;

        $query = PassiveSignal::query();

        if ($since) {
            $query->withTrashed()->where('updated_at', '>', $since);
        }

        if (isset($data['source'])) {
            $query->where('source', $data['source']);
        }

        if (isset($data['signal_type'])) {
            $query->where('signal_type', $data['signal_type']);
        }

        if (isset($data['cursor'])) {
            $cursor = PassiveSignal::withTrashed()->find($data['cursor']);

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
                            ->where('started_at', '<', $cursor->started_at)
                            ->orWhere(function ($query) use ($cursor): void {
                                $query->where('started_at', $cursor->started_at)->where('id', '<', $cursor->id);
                            });
                    });
                }
            }
        }

        $query = $since
            ? $query->orderBy('updated_at')->orderBy('id')
            : $query->orderByDesc('started_at')->orderByDesc('id');

        $signals = $query->limit($limit + 1)->get();
        $hasMore = $signals->count() > $limit;
        $page = $hasMore ? $signals->take($limit)->values() : $signals;

        return response()->json([
            'passive_signals' => PassiveSignalResource::collection($page)->resolve(),
            'next_cursor' => $hasMore ? $page->last()?->id : null,
            'has_more' => $hasMore,
        ]);
    }

    public function store(StorePassiveSignalRequest $request): JsonResponse
    {
        $data = $request->validated();
        $deletedAt = $data['deleted_at'] ?? null;
        unset($data['deleted_at']);

        $payload = [
            ...$data,
            'metadata' => Metadata::forStorage($data['metadata'] ?? []),
        ];
        $signal = PassiveSignal::withTrashed()
            ->where('client_id', $data['client_id'])
            ->first();
        $created = false;

        if (! $signal) {
            $signal = PassiveSignal::create($payload);
            $created = true;
        } else {
            if (! $deletedAt) {
                $signal->fill($payload);
                if ($signal->isDirty()) {
                    $signal->save();
                }
            }
        }

        if ($deletedAt) {
            if (! $signal->trashed()) {
                $signal->delete();
            }
        } elseif ($signal->trashed()) {
            $signal->restore();
        }

        return (new PassiveSignalResource($signal))
            ->response()
            ->setStatusCode($created ? 201 : 200);
    }

    public function update(UpdatePassiveSignalRequest $request, PassiveSignal $passiveSignal): PassiveSignalResource
    {
        $data = $request->validated();

        if (array_key_exists('metadata', $data)) {
            $data['metadata'] = Metadata::forStorage($data['metadata']);
        }

        $passiveSignal->update($data);

        return new PassiveSignalResource($passiveSignal->refresh());
    }

    public function destroy(PassiveSignal $passiveSignal): PassiveSignalResource
    {
        $passiveSignal->delete();

        return new PassiveSignalResource($passiveSignal->refresh());
    }
}
