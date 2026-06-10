<?php

declare(strict_types=1);

namespace App\Services\Ai\WorkspaceIntelligence;

use App\Models\AiThread;
use App\Models\AtlasWorkspaceArtifactLakeEntry;
use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\Support\DatabaseTableAvailability;
use App\Services\AtlasCode\AtlasCodeWorkspaceProfileService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * AWIS · Conversation Fusion Workspace.
 *
 * Builds a deterministic, provider-safe context package from multiple Atlas AI
 * conversations scoped to one workspace. It never returns raw full
 * conversations; it returns hashes, short excerpts, decisions/blockers and a
 * fusion hash so Dev/Forge/subagents can receive a compact workspace memory.
 */
final class AtlasWorkspaceConversationFusionService
{
    public const SCHEMA_VERSION = 'atlas.workspace_conversation_fusion.v1';

    public function __construct(
        private readonly AtlasCodeWorkspaceProfileService $profiles,
        private readonly AtlasWorkspaceIntelligenceListNormalizer $listNormalizer = new AtlasWorkspaceIntelligenceListNormalizer,
    ) {}

    /**
     * @param  array<int,string>  $threadIds
     * @return array<string,mixed>
     */
    public function build(?string $workspace = null, int $limit = 12, array $threadIds = [], bool $persist = false): array
    {
        $profile = $this->resolveProfile($workspace);
        if ($profile === null) {
            return $this->blocked('workspace_not_registered', null);
        }
        if (! DatabaseTableAvailability::all(['ai_threads', 'ai_messages'])) {
            return $this->blocked('ai_thread_tables_missing', (string) $profile['slug']);
        }

        $workspaceScope = $this->workspaceScope($profile);
        $requestedThreadIds = $this->trimmedUniqueStrings($threadIds);
        $threads = $this->threads($workspaceScope, max(1, min($limit, 50)), $requestedThreadIds);
        $matchedThreadIds = $threads->pluck('id')->map(static fn ($id): string => (string) $id)->all();
        $rejectedThreadIds = array_values(array_diff($requestedThreadIds, $matchedThreadIds));
        if ($rejectedThreadIds !== []) {
            return $this->blocked(
                'thread_outside_workspace_or_missing',
                (string) $profile['slug'],
                [
                    'requested_thread_ids' => $requestedThreadIds,
                    'rejected_thread_ids' => $rejectedThreadIds,
                    'workspace_scope' => $workspaceScope,
                ],
            );
        }
        $threadPackets = [];
        $allMessages = [];
        foreach ($threads as $thread) {
            $messages = $thread->messages->take(80)->values();
            $messagePackets = [];
            foreach ($messages as $message) {
                $content = (string) $message->content;
                $packet = [
                    'message_id' => (string) $message->id,
                    'role' => (string) $message->role,
                    'status' => (string) $message->status,
                    'content_hash' => hash('sha256', $content),
                    'excerpt' => $this->excerpt($content, (string) $message->role),
                    'token_estimate' => (int) ($message->token_estimate ?? 0),
                    'occurred_at' => optional($message->occurred_at)->toISOString(),
                ];
                $messagePackets[] = $packet;
                $allMessages[] = $packet + ['thread_id' => (string) $thread->id];
            }

            $threadPackets[] = [
                'thread_id' => (string) $thread->id,
                'title' => (string) $thread->title,
                'status' => (string) $thread->status,
                'surface' => (string) $thread->surface,
                'workspace' => (string) ($thread->workspace ?? ''),
                'message_count' => count($messagePackets),
                'thread_hash' => MissionCanonicalHash::sha256([
                    'thread_id' => (string) $thread->id,
                    'title' => (string) $thread->title,
                    'messages' => array_column($messagePackets, 'content_hash'),
                ]),
                'messages' => $messagePackets,
            ];
        }

        $signals = $this->signals($allMessages);
        $fusionPack = [
            'schema_version' => 'atlas.workspace_conversation_fusion_pack.v1',
            'workspace_id' => (string) $profile['slug'],
            'source_thread_ids' => array_values(array_column($threadPackets, 'thread_id')),
            'source_thread_hashes' => array_values(array_column($threadPackets, 'thread_hash')),
            'summary_units' => $this->summaryUnits($threadPackets, $signals),
            'decision_ledger' => $signals['decisions'],
            'blocker_ledger' => $signals['blockers'],
            'risk_ledger' => $signals['risks'],
            'handoff_context' => [
                'raw_conversation_included' => false,
                'allowed_for_provider_prompt' => true,
                'recommended_consumers' => ['atlas_dev', 'atlas_forge', 'subagent_projection'],
                'must_keep' => ['workspace_id', 'source_thread_ids', 'decision_ledger', 'blocker_ledger'],
            ],
        ];
        $fusionPack['fusion_pack_hash'] = MissionCanonicalHash::sha256($fusionPack);

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'generated_at' => Carbon::now()->toISOString(),
            'status' => count($threadPackets) > 0 ? 'ready' : 'empty',
            'workspace_id' => (string) $profile['slug'],
            'workspace_name' => (string) $profile['name'],
            'source_policy' => [
                'raw_conversation_returned' => false,
                'full_message_content_returned' => false,
                'hashes_are_authoritative' => true,
                'workspace_isolation_required' => true,
            ],
            'summary' => [
                'thread_count' => count($threadPackets),
                'message_count' => count($allMessages),
                'decision_count' => count($signals['decisions']),
                'blocker_count' => count($signals['blockers']),
                'risk_count' => count($signals['risks']),
            ],
            'workspace_scope' => $workspaceScope,
            'threads' => $threadPackets,
            'fusion_pack' => $fusionPack,
            'claim_policy' => [
                'read_only' => true,
                'invokes_provider' => false,
                'spends_tokens' => false,
                'cross_workspace_merge_allowed' => false,
                'safe_for_context_pack' => true,
            ],
        ];
        $payload['fusion_hash'] = $this->hashWithoutGeneratedAt($payload);
        if ($persist) {
            $payload['persisted_artifact'] = $this->persistArtifact($payload);
        }

        return $payload;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function resolveProfile(?string $workspace): ?array
    {
        return $this->profiles->findByReference($workspace);
    }

    /**
     * @param  array<string,mixed>  $profile
     * @return array{schema_version:string,workspace_id:string,workspace_path:?string,aliases:list<string>}
     */
    private function workspaceScope(array $profile): array
    {
        $slug = (string) $profile['slug'];
        $path = trim((string) ($profile['workspace_path'] ?? ''));
        $repo = trim((string) ($profile['repo_root'] ?? ''));

        return [
            'schema_version' => 'atlas.workspace_conversation_scope.v1',
            'workspace_id' => $slug,
            'workspace_path' => $path !== '' ? $path : null,
            'aliases' => $this->trimmedUniqueStrings([$slug, $path, $repo]),
        ];
    }

    /**
     * @param  array{aliases:list<string>,workspace_id:string,workspace_path:?string}  $scope
     * @param  array<int,string>  $threadIds
     * @return Collection<int,AiThread>
     */
    private function threads(array $scope, int $limit, array $threadIds)
    {
        $query = AiThread::query()
            ->with(['messages' => fn ($messages) => $messages->orderBy('position')->limit(80)])
            ->orderByRaw('last_message_at DESC NULLS LAST')
            ->orderByDesc('created_at')
            ->limit($limit);

        if ($threadIds !== []) {
            $query->whereIn('id', $this->trimmedUniqueStrings($threadIds));
        }
        $query->where(function ($q) use ($scope): void {
            foreach ($scope['aliases'] as $alias) {
                $q->orWhere('workspace', $alias);
            }
            $q->orWhere('metadata->workspace_slug', $scope['workspace_id']);
            if ($scope['workspace_path'] !== null) {
                $q->orWhere('metadata->workspace_path', $scope['workspace_path']);
                $q->orWhere('metadata->repo_root', $scope['workspace_path']);
            }
        });

        return $query->get();
    }

    /**
     * @param  array<int,array<string,mixed>>  $messages
     * @return array{decisions:list<array<string,string>>,blockers:list<array<string,string>>,risks:list<array<string,string>>}
     */
    private function signals(array $messages): array
    {
        $out = ['decisions' => [], 'blockers' => [], 'risks' => []];
        foreach ($messages as $message) {
            $excerpt = (string) ($message['excerpt'] ?? '');
            $lower = mb_strtolower($excerpt);
            foreach ([
                'decisions' => ['decidido', 'decisão', 'decision', 'vamos seguir', 'escolhemos'],
                'blockers' => ['bloqueio', 'blocker', 'travado', 'falhou', 'erro'],
                'risks' => ['risco', 'cuidado', 'quebra', 'regressão', 'security', 'auth'],
            ] as $bucket => $needles) {
                foreach ($needles as $needle) {
                    if (str_contains($lower, $needle)) {
                        $out[$bucket][] = [
                            'thread_id' => (string) ($message['thread_id'] ?? ''),
                            'message_id' => (string) ($message['message_id'] ?? ''),
                            'excerpt' => $excerpt,
                        ];
                        break;
                    }
                }
            }
        }

        return [
            'decisions' => $this->limitedUniqueSignals($out['decisions']),
            'blockers' => $this->limitedUniqueSignals($out['blockers']),
            'risks' => $this->limitedUniqueSignals($out['risks']),
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $signals
     * @return list<array<string,string>>
     */
    private function limitedUniqueSignals(array $signals, int $limit = 20): array
    {
        return array_slice($this->uniqueSignals($signals), 0, $limit);
    }

    /**
     * @param  array<int,array<string,mixed>>  $signals
     * @return list<array<string,string>>
     */
    private function uniqueSignals(array $signals): array
    {
        $seen = [];
        $out = [];
        foreach ($signals as $signal) {
            $key = hash('sha256', (string) ($signal['thread_id'] ?? '').'|'.(string) ($signal['excerpt'] ?? ''));
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = [
                'thread_id' => (string) ($signal['thread_id'] ?? ''),
                'message_id' => (string) ($signal['message_id'] ?? ''),
                'excerpt' => (string) ($signal['excerpt'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * @param  array<int,mixed>  $values
     * @return list<string>
     */
    private function trimmedUniqueStrings(array $values): array
    {
        return $this->listNormalizer->uniqueStrings($values);
    }

    /**
     * @param  array<int,array<string,mixed>>  $threads
     * @param  array<string,mixed>  $signals
     * @return list<array<string,mixed>>
     */
    private function summaryUnits(array $threads, array $signals): array
    {
        return [
            [
                'id' => 'workspace_threads',
                'label' => 'Conversas do workspace',
                'value' => count($threads).' threads fundidas por hash/excerpt',
            ],
            [
                'id' => 'decisions',
                'label' => 'Decisões recuperadas',
                'value' => count((array) ($signals['decisions'] ?? [])).' sinais',
            ],
            [
                'id' => 'blockers',
                'label' => 'Bloqueios recuperados',
                'value' => count((array) ($signals['blockers'] ?? [])).' sinais',
            ],
        ];
    }

    private function excerpt(string $content, string $role): string
    {
        $clean = trim((string) preg_replace('/\s+/', ' ', $content));
        $limit = $role === 'assistant' ? 220 : 180;

        return mb_substr($clean, 0, $limit);
    }

    /**
     * @return array<string,mixed>
     */
    private function blocked(string $reason, ?string $workspaceId, array $extra = []): array
    {
        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'generated_at' => Carbon::now()->toISOString(),
            'status' => 'blocked',
            'workspace_id' => $workspaceId,
            'blockers' => [$reason],
            'source_policy' => [
                'raw_conversation_returned' => false,
                'full_message_content_returned' => false,
            ],
            'claim_policy' => [
                'read_only' => true,
                'invokes_provider' => false,
                'spends_tokens' => false,
                'cross_workspace_merge_allowed' => false,
            ],
        ] + $extra;
        $payload['fusion_hash'] = $this->hashWithoutGeneratedAt($payload);

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>|null
     */
    private function persistArtifact(array $payload): ?array
    {
        if (! DatabaseTableAvailability::has('atlas_workspace_artifact_lake_entries')) {
            return null;
        }

        $fusionPack = (array) ($payload['fusion_pack'] ?? []);
        $artifactHash = (string) ($fusionPack['fusion_pack_hash'] ?? $payload['fusion_hash'] ?? '');
        if ($artifactHash === '') {
            return null;
        }

        $entry = AtlasWorkspaceArtifactLakeEntry::query()->updateOrCreate(
            [
                'workspace_id' => (string) ($payload['workspace_id'] ?? 'unknown'),
                'runtime_hash' => (string) ($payload['fusion_hash'] ?? $artifactHash),
                'artifact_hash' => $artifactHash,
            ],
            [
                'artifact_type' => 'conversation_fusion_pack',
                'status' => (string) ($payload['status'] ?? 'ready'),
                'consumer' => 'atlas_workspace_intelligence',
                'source_hashes' => (array) ($fusionPack['source_thread_hashes'] ?? []),
                'body' => [
                    'schema_version' => 'atlas.workspace_conversation_fusion_artifact.v1',
                    'summary' => (array) ($payload['summary'] ?? []),
                    'fusion_pack' => $fusionPack,
                    'source_policy' => (array) ($payload['source_policy'] ?? []),
                ],
                'quality_score' => (string) ($payload['status'] ?? '') === 'ready' ? 0.92 : 0.0,
                'captured_at' => Carbon::now(),
            ],
        );

        return [
            'artifact_id' => (string) $entry->id,
            'artifact_type' => 'conversation_fusion_pack',
            'artifact_hash' => $artifactHash,
            'runtime_hash' => (string) ($payload['fusion_hash'] ?? $artifactHash),
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function hashWithoutGeneratedAt(array $payload): string
    {
        unset($payload['generated_at'], $payload['fusion_hash']);

        return MissionCanonicalHash::sha256($payload);
    }
}
