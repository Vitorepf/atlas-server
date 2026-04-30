<?php

namespace App\Http\Controllers;

use App\Http\Requests\IndexBehaviorRequest;
use App\Http\Requests\StoreBehaviorRequest;
use App\Http\Requests\UpdateBehaviorRequest;
use App\Http\Resources\BehaviorResource;
use App\Models\Behavior;
use App\Services\BitaculaService;
use App\Support\BehaviorCategories;
use App\Support\Metadata;
use Illuminate\Http\JsonResponse;

class BehaviorController extends Controller
{
    public function index(IndexBehaviorRequest $request): JsonResponse
    {
        $data = $request->validated();
        $limit = (int) ($data['limit'] ?? 50);
        $since = $data['since'] ?? null;

        $query = Behavior::query();

        if ($since) {
            $query->withTrashed()->where('updated_at', '>', $since);
        }

        if (isset($data['category'])) {
            $query->where('category', $data['category']);
        }

        if (isset($data['lifecycle_status'])) {
            $query->where('lifecycle_status', $data['lifecycle_status']);
        }

        if (isset($data['active'])) {
            $data['active']
                ? $query->whereNull('archived_at')
                : $query->whereNotNull('archived_at');
        }

        if (isset($data['briefing'])) {
            $query->where('show_in_morning_briefing', (bool) $data['briefing']);
        }

        if (isset($data['cursor'])) {
            $cursor = Behavior::withTrashed()->find($data['cursor']);

            if ($cursor) {
                $query->where(function ($query) use ($cursor, $since): void {
                    $column = $since ? 'updated_at' : 'activated_at';
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
            : $query->orderByDesc('priority_score')->orderByDesc('activated_at')->orderByDesc('id');

        $behaviors = $query->limit($limit + 1)->get();
        $hasMore = $behaviors->count() > $limit;
        $page = $hasMore ? $behaviors->take($limit)->values() : $behaviors;

        return response()->json([
            'behaviors' => BehaviorResource::collection($page)->resolve(),
            'next_cursor' => $hasMore ? $page->last()?->id : null,
            'has_more' => $hasMore,
        ]);
    }

    public function store(StoreBehaviorRequest $request, BitaculaService $bitacula): JsonResponse
    {
        $behavior = $bitacula->upsertBehavior($request->validated());

        return (new BehaviorResource($behavior['model']))
            ->response()
            ->setStatusCode($behavior['created'] ? 201 : 200);
    }

    public function update(UpdateBehaviorRequest $request, Behavior $behavior): BehaviorResource
    {
        $data = $request->validated();

        if (array_key_exists('category', $data)) {
            $data['category'] = BehaviorCategories::canonicalize($data['category']);
        }

        if (($data['lifecycle_status'] ?? null) === 'archived') {
            $data['archived_at'] = $data['archived_at'] ?? now();
            unset($data['lifecycle_status']);
        }

        foreach (['source_capture_ids', 'activation_rules', 'derived_from', 'metadata'] as $key) {
            if (array_key_exists($key, $data)) {
                $data[$key] = Metadata::forStorage($data[$key]);
            }
        }

        $behavior->update($data);

        return new BehaviorResource($behavior->refresh());
    }

    public function destroy(Behavior $behavior): BehaviorResource
    {
        $behavior->delete();

        return new BehaviorResource($behavior->refresh());
    }
}
