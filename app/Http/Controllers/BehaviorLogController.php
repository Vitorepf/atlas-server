<?php

namespace App\Http\Controllers;

use App\Http\Requests\IndexBehaviorLogRequest;
use App\Http\Requests\StoreBehaviorLogRequest;
use App\Http\Requests\UpdateBehaviorLogRequest;
use App\Http\Resources\BehaviorLogResource;
use App\Models\BehaviorLog;
use App\Services\BitaculaService;
use App\Support\Metadata;
use Illuminate\Http\JsonResponse;

class BehaviorLogController extends Controller
{
    public function index(IndexBehaviorLogRequest $request): JsonResponse
    {
        $data = $request->validated();
        $limit = (int) ($data['limit'] ?? 50);
        $since = $data['since'] ?? null;

        $query = BehaviorLog::query();

        if ($since) {
            $query->withTrashed()->where('updated_at', '>', $since);
        }

        if (isset($data['behavior_client_id'])) {
            $query->where('behavior_client_id', $data['behavior_client_id']);
        }

        if (isset($data['source'])) {
            $query->where('source', $data['source']);
        }

        if (isset($data['date_from'])) {
            $query->whereDate('log_date', '>=', $data['date_from']);
        }

        if (isset($data['date_to'])) {
            $query->whereDate('log_date', '<=', $data['date_to']);
        }

        if (isset($data['cursor'])) {
            $cursor = BehaviorLog::withTrashed()->find($data['cursor']);

            if ($cursor) {
                $query->where(function ($query) use ($cursor, $since): void {
                    $column = $since ? 'updated_at' : 'log_date';
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
            : $query->orderByDesc('log_date')->orderByDesc('recorded_at')->orderByDesc('id');

        $logs = $query->limit($limit + 1)->get();
        $hasMore = $logs->count() > $limit;
        $page = $hasMore ? $logs->take($limit)->values() : $logs;

        return response()->json([
            'behavior_logs' => BehaviorLogResource::collection($page)->resolve(),
            'next_cursor' => $hasMore ? $page->last()?->id : null,
            'has_more' => $hasMore,
        ]);
    }

    public function store(StoreBehaviorLogRequest $request, BitaculaService $bitacula): JsonResponse
    {
        $log = $bitacula->upsertBehaviorLog($request->validated());

        return (new BehaviorLogResource($log['model']))
            ->response()
            ->setStatusCode($log['created'] ? 201 : 200);
    }

    public function update(UpdateBehaviorLogRequest $request, BehaviorLog $behaviorLog, BitaculaService $bitacula): BehaviorLogResource
    {
        $data = $request->validated();

        if (array_key_exists('metadata', $data)) {
            $data['metadata'] = Metadata::forStorage($data['metadata']);
        }

        $behaviorLog->update($data);
        $bitacula->recomputeBehaviorCounters($behaviorLog->behavior_client_id);

        return new BehaviorLogResource($behaviorLog->refresh());
    }

    public function destroy(BehaviorLog $behaviorLog, BitaculaService $bitacula): BehaviorLogResource
    {
        $behaviorClientId = $behaviorLog->behavior_client_id;
        $behaviorLog->delete();
        $bitacula->recomputeBehaviorCounters($behaviorClientId);

        return new BehaviorLogResource($behaviorLog->refresh());
    }
}
