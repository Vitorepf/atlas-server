<?php

namespace App\Http\Controllers;

use App\Http\Resources\AiCompactionResource;
use App\Http\Resources\AiContextSnapshotResource;
use App\Http\Resources\AiMessageResource;
use App\Http\Resources\AiProviderHandoffResource;
use App\Http\Resources\AiSessionStateResource;
use App\Http\Resources\AiThreadResource;
use App\Models\AiContextSnapshot;
use App\Models\AiThread;
use App\Services\Ai\AiCompactionService;
use App\Services\Ai\AiProviderHandoffService;
use App\Services\Ai\AiSessionManager;
use App\Services\Ai\AiSessionStateService;
use App\Services\Ai\AiThreadDeletionService;
use App\Services\Ai\WorkspaceIntelligence\AtlasWorkspaceConversationFusionService;
use App\Services\AtlasCode\AtlasCodeWorkspaceProfileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AiThreadController extends Controller
{
    public function index(Request $request, AtlasCodeWorkspaceProfileService $workspaces): JsonResponse
    {
        // ROUND 3.5 (atlas-app) · TODO cursor pagination
        // -----------------------------------------------
        // Hoje só aceitamos `limit` (max 100). Mobile carrega 50 e fim.
        // Para usuários com 200+ threads históricos, precisamos:
        //   1. Adicionar param `before_id: ?string` (uuid) e/ou
        //      `before_last_message_at: ?datetime`
        //   2. Aplicar keyset pagination:
        //        ->when($beforeTs, fn($q) => $q->where('last_message_at', '<', $beforeTs))
        //   3. Retornar `next_cursor` no response quando count == limit
        //   4. Frontend migra pra useInfiniteQuery + getNextPageParam
        // Skip por enquanto · 50 threads cobre 99% dos casos de uso atuais.
        $data = $request->validate([
            'status' => ['nullable', 'string', 'in:active,archived,closed,all'],
            'surface' => ['nullable', 'string', 'max:80'],
            'workspace' => ['nullable', 'string', 'max:500'],
            'limit' => ['nullable', 'integer', 'between:1,100'],
            'include_messages' => ['nullable', 'boolean'],
            // 2026-05 · light=1 omite relations pesadas (activeSession,
            // activeState, latestCompaction, latestProviderHandoff, lastTrace).
            // Payload cai ~80× (1.2MB → ~15KB pra 10 threads). Histórico
            // mobile usa light=1 · só precisa de id/title/meta pra listar.
            // Quando user abre uma thread específica, show() carrega tudo.
            'light' => ['nullable', 'boolean'],
        ]);

        $light = $request->boolean('light');
        $workspaceScope = $this->workspaceScope($data['workspace'] ?? null, $workspaces);

        $threads = AiThread::query()
            ->when(($data['status'] ?? null) && $data['status'] !== 'all', fn ($query) => $query->where('status', $data['status']))
            ->when($data['surface'] ?? null, fn ($query, $surface) => $query->where('surface', $surface))
            ->when($workspaceScope !== null, function ($query) use ($workspaceScope): void {
                $query->where(function ($workspaceQuery) use ($workspaceScope): void {
                    foreach ($workspaceScope['aliases'] as $alias) {
                        $workspaceQuery->orWhere('workspace', $alias);
                    }
                    if ($workspaceScope['slug'] !== null) {
                        $workspaceQuery->orWhere('metadata->workspace_slug', $workspaceScope['slug']);
                    }
                    if ($workspaceScope['path'] !== null) {
                        $workspaceQuery->orWhere('metadata->workspace_path', $workspaceScope['path']);
                        $workspaceQuery->orWhere('metadata->repo_root', $workspaceScope['path']);
                    }
                });
            })
            ->when($request->boolean('include_messages'), fn ($query) => $query->with(['messages' => fn ($messages) => $messages->latest('position')->limit(30)]))
            ->when(! $light, fn ($query) => $query->with(['activeSession', 'activeState', 'latestCompaction', 'latestProviderHandoff', 'lastTrace']))
            ->orderByRaw('last_message_at DESC NULLS LAST')
            ->orderByDesc('created_at')
            ->limit(min((int) ($data['limit'] ?? 30), 100))
            ->get();

        return response()->json([
            'threads' => AiThreadResource::collection($threads)->resolve(),
        ]);
    }

    public function workspaceConversationFusion(
        Request $request,
        string $workspace,
        AtlasWorkspaceConversationFusionService $fusion,
    ): JsonResponse {
        $data = $request->validate([
            'limit' => ['nullable', 'integer', 'between:1,50'],
            'thread' => ['nullable', 'array'],
            'thread.*' => ['string', 'uuid'],
            'persist' => ['nullable', 'boolean'],
        ]);

        return response()->json($fusion->build(
            workspace: $workspace,
            limit: (int) ($data['limit'] ?? 12),
            threadIds: (array) ($data['thread'] ?? []),
            persist: (bool) ($data['persist'] ?? false),
        ));
    }

    public function store(Request $request, AtlasCodeWorkspaceProfileService $workspaces): JsonResponse
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
        $workspaceScope = $this->workspaceScope($data['workspace'] ?? null, $workspaces);
        $metadata = $this->metadataWithWorkspaceScope($data['metadata'] ?? [], $workspaceScope);

        $thread = AiThread::query()->create([
            'title' => trim((string) ($data['title'] ?? 'Nova conversa Atlas')) ?: 'Nova conversa Atlas',
            'summary' => $data['summary'] ?? null,
            'status' => 'active',
            'surface' => $data['surface'] ?? 'app',
            'workspace' => $this->workspaceStorageValue($data['workspace'] ?? null, $workspaceScope),
            'source_type' => $data['source_type'] ?? null,
            'source_id' => $data['source_id'] ?? null,
            'metadata' => $metadata,
        ]);

        return response()->json([
            'thread' => (new AiThreadResource($thread))->resolve(),
        ], 201);
    }

    public function show(Request $request, AiThread $thread): JsonResponse
    {
        $data = $request->validate([
            'lean' => ['nullable', 'boolean'],
            'include_trace' => ['nullable', 'boolean'],
            'message_limit' => ['nullable', 'integer', 'between:1,200'],
        ]);
        $messageLimit = (int) ($data['message_limit'] ?? 6);
        $lean = $request->boolean('lean');
        $includeTrace = $request->boolean('include_trace');

        $relations = [
            'messages' => fn ($messages) => $messages
                ->reorder()
                ->orderByDesc('position')
                ->limit($messageLimit),
        ];
        if (! $lean || $includeTrace) {
            $relations[] = 'lastTrace';
        }
        if (! $lean) {
            $relations[] = 'activeSession';
            $relations[] = 'activeState';
            $relations[] = 'latestCompaction';
            $relations[] = 'latestProviderHandoff';
        }

        return response()->json([
            'thread' => (new AiThreadResource($thread->load($relations)))->resolve(),
        ]);
    }

    public function messages(Request $request, AiThread $thread): JsonResponse
    {
        $data = $request->validate([
            'before_position' => ['nullable', 'integer', 'min:0'],
            'limit' => ['nullable', 'integer', 'between:1,100'],
        ]);
        $limit = (int) ($data['limit'] ?? 12);
        $beforePosition = $data['before_position'] ?? null;

        $messages = $thread->messages()
            ->reorder()
            ->when($beforePosition !== null, fn ($query) => $query->where('position', '<', $beforePosition))
            ->orderByDesc('position')
            ->limit($limit + 1)
            ->get();

        $hasMore = $messages->count() > $limit;
        $window = $messages->take($limit)->sortBy('position')->values();
        $oldest = $window->first();

        return response()->json([
            'messages' => AiMessageResource::collection($window)->resolve(),
            'pagination' => [
                'limit' => $limit,
                'has_more_before' => $hasMore,
                'oldest_position' => $oldest?->position,
                'next_before_position' => $hasMore ? $oldest?->position : null,
            ],
        ]);
    }

    public function update(Request $request, AiThread $thread, AtlasCodeWorkspaceProfileService $workspaces): JsonResponse
    {
        $data = $request->validate([
            'title' => ['nullable', 'string', 'max:180'],
            'summary' => ['nullable', 'string', 'max:20000'],
            'status' => ['nullable', 'string', 'in:active,archived,closed'],
            'workspace' => ['nullable', 'string', 'max:500'],
            'metadata' => ['nullable', 'array'],
        ]);
        $workspaceScope = array_key_exists('workspace', $data)
            ? $this->workspaceScope($data['workspace'] ?? null, $workspaces)
            : null;
        $metadata = array_key_exists('metadata', $data)
            ? array_merge($thread->metadata ?? [], $data['metadata'] ?? [])
            : null;
        if (array_key_exists('workspace', $data)) {
            $metadata = $this->metadataWithWorkspaceScope($metadata ?? ($thread->metadata ?? []), $workspaceScope);
        }

        $thread->update(array_filter([
            'title' => isset($data['title']) ? trim((string) $data['title']) : null,
            'summary' => $data['summary'] ?? null,
            'status' => $data['status'] ?? null,
            'workspace' => array_key_exists('workspace', $data)
                ? $this->workspaceStorageValue($data['workspace'] ?? null, $workspaceScope)
                : null,
            'metadata' => $metadata,
        ], fn ($value) => $value !== null));

        return response()->json([
            'thread' => (new AiThreadResource($thread->refresh()))->resolve(),
        ]);
    }

    /**
     * @return array{slug:?string,path:?string,aliases:list<string>}|null
     */
    private function workspaceScope(mixed $workspace, AtlasCodeWorkspaceProfileService $workspaces): ?array
    {
        if (! is_scalar($workspace)) {
            return null;
        }
        $raw = trim((string) $workspace);
        if ($raw === '') {
            return null;
        }

        $profile = $workspaces->findBySlug($raw);
        $slug = is_array($profile) ? (string) ($profile['slug'] ?? $raw) : null;
        $path = is_array($profile) ? trim((string) ($profile['workspace_path'] ?? '')) : '';
        if ($path === '' && is_dir($raw)) {
            $path = realpath($raw) ?: $raw;
        }
        $aliases = array_values(array_unique(array_filter([
            $raw,
            $slug,
            $path !== '' ? $path : null,
        ], fn ($value): bool => is_string($value) && trim($value) !== '')));

        return [
            'slug' => $slug ?? $raw,
            'path' => $path !== '' ? $path : null,
            'aliases' => $aliases,
        ];
    }

    /**
     * @param  array<string,mixed>  $metadata
     * @param  array{slug:?string,path:?string,aliases:list<string>}|null  $workspaceScope
     * @return array<string,mixed>
     */
    private function metadataWithWorkspaceScope(array $metadata, ?array $workspaceScope): array
    {
        if ($workspaceScope === null) {
            return $metadata;
        }

        $metadata['workspace_slug'] = $workspaceScope['slug'];
        if ($workspaceScope['path'] !== null) {
            $metadata['workspace_path'] = $workspaceScope['path'];
            $metadata['repo_root'] = $metadata['repo_root'] ?? $workspaceScope['path'];
        }
        $metadata['awis_workspace_scope'] = [
            'schema_version' => 'atlas.ai_thread.workspace_scope.v1',
            'workspace_slug' => $workspaceScope['slug'],
            'workspace_path_hash' => $workspaceScope['path'] !== null ? hash('sha256', $workspaceScope['path']) : null,
            'aliases' => $workspaceScope['aliases'],
        ];

        return $metadata;
    }

    /**
     * @param  array{slug:?string,path:?string,aliases:list<string>}|null  $workspaceScope
     */
    private function workspaceStorageValue(mixed $workspace, ?array $workspaceScope): ?string
    {
        if ($workspaceScope !== null) {
            return $workspaceScope['slug'] ?? $workspaceScope['path'];
        }
        if (! is_scalar($workspace)) {
            return null;
        }
        $raw = trim((string) $workspace);

        return $raw !== '' ? $raw : null;
    }

    public function destroy(AiThread $thread, AiThreadDeletionService $deletion): JsonResponse
    {
        $summary = $deletion->delete($thread);

        return response()->json([
            'ok' => true,
            'deleted_thread_id' => $summary['thread_id'],
            'deletion' => [
                'content_purged' => $summary['content_purged'],
                'traces_tombstoned' => $summary['traces_tombstoned'],
                'counts' => $summary['counts'],
                'deleted_at' => $summary['deleted_at'],
            ],
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
            'to_provider' => ['required', 'string', 'in:claude_cli,codex_cli,gemini_cli,claude_codex'],
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
