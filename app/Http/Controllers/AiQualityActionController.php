<?php

namespace App\Http\Controllers;

use App\Http\Resources\AiQualityActionResource;
use App\Models\AiQualityAction;
use App\Services\Ai\AiQualityActionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AiQualityActionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status' => ['nullable', 'string', 'in:queued,running,succeeded,failed,skipped,blocked'],
            'action_type' => ['nullable', 'string', 'max:80'],
            'trace_id' => ['nullable', 'uuid'],
            'thread_id' => ['nullable', 'uuid'],
            'limit' => ['nullable', 'integer', 'between:1,200'],
        ]);

        $actions = AiQualityAction::query()
            ->with(['remediationTrace'])
            ->when($data['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($data['action_type'] ?? null, fn ($query, $type) => $query->where('action_type', $type))
            ->when($data['trace_id'] ?? null, fn ($query, $traceId) => $query->where('trace_id', $traceId))
            ->when($data['thread_id'] ?? null, fn ($query, $threadId) => $query->where('thread_id', $threadId))
            ->orderBy('status')
            ->orderBy('priority')
            ->orderByDesc('created_at')
            ->limit((int) ($data['limit'] ?? 50))
            ->get();

        return response()->json([
            'actions' => AiQualityActionResource::collection($actions)->resolve(),
        ]);
    }

    public function run(AiQualityAction $action, AiQualityActionService $actions): JsonResponse
    {
        return response()->json([
            'action' => (new AiQualityActionResource($actions->run($action)->load('remediationTrace')))->resolve(),
        ]);
    }
}
