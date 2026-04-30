<?php

namespace App\Http\Controllers;

use App\Http\Resources\AiCompactionResource;
use App\Http\Resources\AiContextSnapshotResource;
use App\Http\Resources\AiProviderHandoffResource;
use App\Http\Resources\AiSessionStateResource;
use App\Http\Resources\AiThreadResource;
use App\Models\AiContextSnapshot;
use App\Models\AiThread;
use App\Services\Ai\AiCompactionService;
use App\Services\Ai\AiProviderHandoffService;
use App\Services\Ai\AiSessionManager;
use App\Services\Ai\AiSessionStateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AiThreadController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status' => ['nullable', 'string', 'in:active,archived,closed,all'],
            'surface' => ['nullable', 'string', 'max:80'],
            'workspace' => ['nullable', 'string', 'max:500'],
            'limit' => ['nullable', 'integer', 'between:1,100'],
            'include_messages' => ['nullable', 'boolean'],
        ]);

        $threads = AiThread::query()
            ->when(($data['status'] ?? null) && $data['status'] !== 'all', fn ($query) => $query->where('status', $data['status']))
            ->when($data['surface'] ?? null, fn ($query, $surface) => $query->where('surface', $surface))
            ->when($data['workspace'] ?? null, fn ($query, $workspace) => $query->where('workspace', $workspace))
            ->when($request->boolean('include_messages'), fn ($query) => $query->with(['messages' => fn ($messages) => $messages->latest('position')->limit(30)]))
            ->with(['activeSession', 'activeState', 'latestCompaction', 'latestProviderHandoff'])
            ->orderByRaw('last_message_at DESC NULLS LAST')
            ->orderByDesc('created_at')
            ->limit(min((int) ($data['limit'] ?? 30), 100))
            ->get();

        return response()->json([
            'threads' => AiThreadResource::collection($threads)->resolve(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => ['nullable', 'string', 'max:180'],
            'summary' => ['nullable', 'string', 'max:20000'],
            'surface' => ['nullable', 'string', 'max:80'],
            'workspace' => ['nullable', 'string', 'max:500'],
            'source_type' => ['nullable', 'string', 'max:80'],
            'source_id' => ['nullable', 'uuid'],
            'metadata' => ['nullable', 'array'],
        ]);

        $thread = AiThread::query()->create([
            'title' => trim((string) ($data['title'] ?? 'Nova conversa Atlas')) ?: 'Nova conversa Atlas',
            'summary' => $data['summary'] ?? null,
            'status' => 'active',
            'surface' => $data['surface'] ?? 'app',
            'workspace' => $data['workspace'] ?? null,
            'source_type' => $data['source_type'] ?? null,
            'source_id' => $data['source_id'] ?? null,
            'metadata' => $data['metadata'] ?? [],
        ]);

        return response()->json([
            'thread' => (new AiThreadResource($thread))->resolve(),
        ], 201);
    }

    public function show(AiThread $thread): JsonResponse
    {
        return response()->json([
            'thread' => (new AiThreadResource($thread->load(['messages', 'lastTrace', 'activeSession', 'activeState', 'latestCompaction', 'latestProviderHandoff'])))->resolve(),
        ]);
    }

    public function update(Request $request, AiThread $thread): JsonResponse
    {
        $data = $request->validate([
            'title' => ['nullable', 'string', 'max:180'],
            'summary' => ['nullable', 'string', 'max:20000'],
            'status' => ['nullable', 'string', 'in:active,archived,closed'],
            'metadata' => ['nullable', 'array'],
        ]);

        $thread->update(array_filter([
            'title' => isset($data['title']) ? trim((string) $data['title']) : null,
            'summary' => $data['summary'] ?? null,
            'status' => $data['status'] ?? null,
            'metadata' => array_key_exists('metadata', $data)
                ? array_merge($thread->metadata ?? [], $data['metadata'] ?? [])
                : null,
        ], fn ($value) => $value !== null));

        return response()->json([
            'thread' => (new AiThreadResource($thread->refresh()))->resolve(),
        ]);
    }

    public function state(AiThread $thread, AiSessionStateService $states): JsonResponse
    {
        $session = $thread->activeSession()->first();

        return response()->json([
            'state' => (new AiSessionStateResource($states->activeState($thread, $session)))->resolve(),
        ]);
    }

    public function compact(Request $request, AiThread $thread, AiSessionManager $sessions, AiCompactionService $compactions): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['nullable', 'string', 'in:manual,auto,provider_switch,phase_change,session_resume,session_close'],
            'provider' => ['nullable', 'string', 'max:80'],
            'model' => ['nullable', 'string', 'max:120'],
            'metadata' => ['nullable', 'array'],
        ]);

        $session = $thread->activeSession()->first()
            ?: $sessions->ensureActive($thread, $data['provider'] ?? null, $thread->title, ['payload' => ['app_surface' => $thread->surface]]);

        $compaction = $compactions->compact($thread, $session, $data['reason'] ?? 'manual', array_merge($data['metadata'] ?? [], [
            'provider' => $data['provider'] ?? null,
            'model' => $data['model'] ?? null,
            'triggered_by' => 'api',
        ]));

        return response()->json([
            'compaction' => (new AiCompactionResource($compaction))->resolve(),
            'thread' => (new AiThreadResource($thread->refresh()->load(['activeState', 'latestCompaction'])))->resolve(),
        ]);
    }

    public function switchProvider(Request $request, AiThread $thread, AiSessionManager $sessions, AiProviderHandoffService $handoffs): JsonResponse
    {
        $data = $request->validate([
            'to_provider' => ['required', 'string', 'in:claude_cli,codex_cli,claude_codex'],
            'from_provider' => ['nullable', 'string', 'max:80'],
            'reason' => ['nullable', 'string', 'max:120'],
            'metadata' => ['nullable', 'array'],
        ]);

        $session = $thread->activeSession()->first()
            ?: $sessions->ensureActive($thread, $data['to_provider'], $thread->title, ['payload' => ['app_surface' => $thread->surface]]);

        $handoff = $handoffs->create(
            thread: $thread,
            session: $session,
            toProvider: $data['to_provider'],
            fromProvider: $data['from_provider'] ?? $thread->last_provider,
            reason: $data['reason'] ?? 'provider_switch',
            metadata: array_merge($data['metadata'] ?? [], ['triggered_by' => 'api']),
        );

        return response()->json([
            'handoff' => (new AiProviderHandoffResource($handoff))->resolve(),
        ]);
    }

    public function snapshots(Request $request, AiThread $thread): JsonResponse
    {
        $data = $request->validate([
            'limit' => ['nullable', 'integer', 'between:1,50'],
        ]);

        $snapshots = AiContextSnapshot::query()
            ->where('thread_id', $thread->id)
            ->latest('created_at')
            ->limit((int) ($data['limit'] ?? 10))
            ->get();

        return response()->json([
            'snapshots' => AiContextSnapshotResource::collection($snapshots)->resolve(),
        ]);
    }
}
