<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Lineage;

use App\Models\AtlasMemoryEntry;
use App\Models\AtlasMemoryEntryRelation;
use App\Services\Ai\Memory\AtlasHybridMemoryRetrievalService;
use App\Services\Ai\Memory\AtlasMemoryRecallCache;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * ESP-08 — Rollback com busca negativa em stores e caches.
 *
 * After ASI-11 cascade archive / memory-forget, proves ABSENCE across:
 *   - memory_recall (live hybrid recall)
 *   - recall_cache (MAXB-09 payload at canonical key + optional stale keys)
 *   - pack_cache (MAXE-05 payload at canonical key + optional stale keys)
 *   - consolidation_relations (open MAXH-04 edges touching the entry)
 *
 * Zero hits required for pass. Any hit ⇒ status=fail with named store.
 * Missing searches ⇒ status=incomplete (reversal never `done`).
 */
final class AtlasRollbackNegativeSearchVerifier
{
    public const SCHEMA = 'atlas.rollback.negative_search_receipt.v1';

    public const STATUS_PASS = 'pass';

    public const STATUS_FAIL = 'fail';

    public const STATUS_INCOMPLETE = 'incomplete';

    public const STORE_MEMORY_RECALL = 'memory_recall';

    public const STORE_RECALL_CACHE = 'recall_cache';

    public const STORE_PACK_CACHE = 'pack_cache';

    public const STORE_CONSOLIDATION_RELATIONS = 'consolidation_relations';

    /** @var list<string> */
    public const REQUIRED_STORES = [
        self::STORE_MEMORY_RECALL,
        self::STORE_RECALL_CACHE,
        self::STORE_PACK_CACHE,
        self::STORE_CONSOLIDATION_RELATIONS,
    ];

    public function __construct(
        private readonly ?AtlasHybridMemoryRetrievalService $recall = null,
        private readonly ?AtlasMemoryRecallCache $recallCache = null,
    ) {}

    /**
     * @param  list<string>  $memoryEntryIds
     * @param  array{stale_cache_keys?:list<string>,query?:string}  $options
     * @return array<string,mixed>
     */
    public function verify(array $memoryEntryIds, array $options = []): array
    {
        $startedAt = microtime(true);
        $memoryEntryIds = array_values(array_unique(array_filter(array_map(
            static fn (mixed $id): string => trim((string) $id),
            $memoryEntryIds,
        ))));

        if ($memoryEntryIds === []) {
            return $this->finish(self::STATUS_PASS, [], [], $startedAt);
        }

        $searches = [];
        $failedStores = [];

        foreach ($memoryEntryIds as $entryId) {
            $entry = $this->loadEntry($entryId);
            $query = trim((string) ($options['query'] ?? ''));
            if ($query === '' && $entry !== null) {
                $query = trim((string) ($entry->title ?? $entry->redacted_title ?? ''));
            }
            if ($query === '') {
                $query = $entryId;
            }

            $staleKeys = is_array($options['stale_cache_keys'] ?? null)
                ? array_values(array_filter(array_map('strval', $options['stale_cache_keys'])))
                : [];

            foreach ($this->requiredStoreSearches($entryId, $query, $staleKeys) as $search) {
                $searches[] = $search;
                if (($search['hit'] ?? false) === true) {
                    $failedStores[(string) $search['store']] = (string) $search['store'];
                }
            }
        }

        $failedStores = array_values($failedStores);
        $status = $this->resolveStatus($searches, $failedStores);

        return $this->finish($status, $searches, $failedStores, $startedAt);
    }

    /**
     * @param  list<string>  $staleCacheKeys
     * @return list<array<string,mixed>>
     */
    private function requiredStoreSearches(string $entryId, string $query, array $staleCacheKeys): array
    {
        return [
            $this->searchMemoryRecall($entryId, $query),
            $this->searchRecallCache($entryId, $query, $staleCacheKeys),
            $this->searchPackCache($entryId, $query, $staleCacheKeys),
            $this->searchConsolidationRelations($entryId),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function searchMemoryRecall(string $entryId, string $query): array
    {
        $base = [
            'store' => self::STORE_MEMORY_RECALL,
            'memory_entry_id' => $entryId,
            'query' => $query,
        ];

        try {
            $recaller = $this->recall ?? app(AtlasHybridMemoryRetrievalService::class);
            $result = $recaller->recall($query, [], [], [
                'record_usage' => false,
                'requester' => 'esp08_negative_search',
            ]);
            $hitCount = $this->countRefHits($result, $entryId);

            return array_merge($base, [
                'hit' => $hitCount > 0,
                'hit_count' => $hitCount,
                'cached' => (bool) ($result['cached'] ?? false),
            ]);
        } catch (Throwable $e) {
            return array_merge($base, [
                'hit' => false,
                'hit_count' => 0,
                'error' => mb_substr($e->getMessage(), 0, 200),
                'incomplete_reason' => 'memory_recall_search_failed',
            ]);
        }
    }

    /**
     * @param  list<string>  $staleCacheKeys
     * @return array<string,mixed>
     */
    private function searchRecallCache(string $entryId, string $query, array $staleCacheKeys): array
    {
        $base = [
            'store' => self::STORE_RECALL_CACHE,
            'memory_entry_id' => $entryId,
            'query' => $query,
        ];

        try {
            $cache = $this->recallCache ?? app(AtlasMemoryRecallCache::class);
            $keys = array_values(array_unique(array_merge(
                [$cache->keyFor($query, [], [], ['record_usage' => false])],
                $staleCacheKeys,
            )));

            $hitCount = 0;
            $keysChecked = [];
            foreach ($keys as $key) {
                if ($key === '') {
                    continue;
                }
                $keysChecked[] = hash('sha256', $key);
                $payload = $cache->get($key);
                if ($payload !== null) {
                    $hitCount += $this->countRefHits($payload['recall'] ?? $payload, $entryId);
                }
            }

            return array_merge($base, [
                'hit' => $hitCount > 0,
                'hit_count' => $hitCount,
                'cache_keys_checked' => count($keysChecked),
            ]);
        } catch (Throwable $e) {
            return array_merge($base, [
                'hit' => false,
                'hit_count' => 0,
                'error' => mb_substr($e->getMessage(), 0, 200),
                'incomplete_reason' => 'recall_cache_search_failed',
            ]);
        }
    }

    /**
     * @param  list<string>  $staleCacheKeys
     * @return array<string,mixed>
     */
    private function searchPackCache(string $entryId, string $query, array $staleCacheKeys): array
    {
        $base = [
            'store' => self::STORE_PACK_CACHE,
            'memory_entry_id' => $entryId,
            'query' => $query,
        ];

        try {
            $workspaceId = (string) config('atlas.aobg.workspace_id', config('app.env', 'atlas'));
            $keys = array_values(array_unique(array_merge(
                [$this->packCacheKey($workspaceId, $query)],
                $staleCacheKeys,
            )));

            $hitCount = 0;
            $keysChecked = [];
            foreach ($keys as $key) {
                if ($key === '') {
                    continue;
                }
                $keysChecked[] = hash('sha256', $key);
                $payload = Cache::get($key);
                if (is_array($payload)) {
                    $hitCount += $this->countRefHits($payload['pack'] ?? $payload, $entryId);
                }
            }

            return array_merge($base, [
                'hit' => $hitCount > 0,
                'hit_count' => $hitCount,
                'cache_keys_checked' => count($keysChecked),
            ]);
        } catch (Throwable $e) {
            return array_merge($base, [
                'hit' => false,
                'hit_count' => 0,
                'error' => mb_substr($e->getMessage(), 0, 200),
                'incomplete_reason' => 'pack_cache_search_failed',
            ]);
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function searchConsolidationRelations(string $entryId): array
    {
        $base = [
            'store' => self::STORE_CONSOLIDATION_RELATIONS,
            'memory_entry_id' => $entryId,
            'query' => 'open_relations',
        ];

        if (! DatabaseTableAvailability::has('atlas_memory_entry_relations')) {
            return array_merge($base, [
                'hit' => false,
                'hit_count' => 0,
                'table' => 'missing',
            ]);
        }

        try {
            $hitCount = (int) AtlasMemoryEntryRelation::query()
                ->where('status', 'open')
                ->where(function ($query) use ($entryId): void {
                    $query->where('source_memory_entry_id', $entryId)
                        ->orWhere('target_memory_entry_id', $entryId);
                })
                ->count();

            return array_merge($base, [
                'hit' => $hitCount > 0,
                'hit_count' => $hitCount,
            ]);
        } catch (Throwable $e) {
            return array_merge($base, [
                'hit' => false,
                'hit_count' => 0,
                'error' => mb_substr($e->getMessage(), 0, 200),
                'incomplete_reason' => 'consolidation_relations_search_failed',
            ]);
        }
    }

    /**
     * MAXE-05 cache key mirror (read-only; does not invoke packFor).
     */
    private function packCacheKey(string $workspaceId, string $task): string
    {
        $queryHash = hash('sha256', $this->normalizePackCacheQuery($task));
        $corpusFingerprint = hash('sha256', (string) json_encode([
            'schema_version' => 'atlas.aobg.pack_cache_corpus_fingerprint.v1',
            'workspace' => $workspaceId,
            'memory_entries' => $this->tableFreshness('atlas_memory_entries'),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return 'atlas:aobg:pack:v1:'.hash('sha256', $workspaceId."\n".$queryHash."\n".$corpusFingerprint);
    }

    private function normalizePackCacheQuery(string $task): string
    {
        $normalized = preg_replace('/\s+/u', ' ', mb_strtolower(trim($task))) ?? trim($task);

        return $normalized;
    }

    /**
     * @return array<string,mixed>
     */
    private function tableFreshness(string $table): array
    {
        if (! DatabaseTableAvailability::has($table)) {
            return ['status' => 'missing'];
        }

        try {
            $query = DB::table($table);
            if (DatabaseTableAvailability::hasColumn($table, 'archived_at')) {
                $query->whereNull('archived_at');
            }

            $stats = [
                'status' => 'ok',
                'rows' => (clone $query)->count(),
            ];
            foreach (['updated_at', 'indexed_at', 'source_hash', 'content_hash'] as $column) {
                if (DatabaseTableAvailability::hasColumn($table, $column)) {
                    $stats['max_'.$column] = (string) ((clone $query)->max($column) ?? '');
                }
            }

            return $stats;
        } catch (Throwable) {
            return ['status' => 'unavailable'];
        }
    }

    private function loadEntry(string $entryId): ?AtlasMemoryEntry
    {
        if ($entryId === '' || ! DatabaseTableAvailability::has('atlas_memory_entries')) {
            return null;
        }

        try {
            return AtlasMemoryEntry::query()->where('id', $entryId)->first();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  list<array<string,mixed>>  $searches
     * @param  list<string>  $failedStores
     */
    private function resolveStatus(array $searches, array $failedStores): string
    {
        if ($failedStores !== []) {
            return self::STATUS_FAIL;
        }

        $storesSeen = [];
        foreach ($searches as $search) {
            $store = (string) ($search['store'] ?? '');
            if ($store !== '') {
                $storesSeen[$store] = true;
            }
            if (isset($search['incomplete_reason'])) {
                return self::STATUS_INCOMPLETE;
            }
        }

        foreach (self::REQUIRED_STORES as $required) {
            if (! isset($storesSeen[$required])) {
                return self::STATUS_INCOMPLETE;
            }
        }

        return self::STATUS_PASS;
    }

    /**
     * @param  list<array<string,mixed>>  $searches
     * @param  list<string>  $failedStores
     * @return array<string,mixed>
     */
    private function finish(string $status, array $searches, array $failedStores, float $startedAt): array
    {
        return [
            'schema' => self::SCHEMA,
            'status' => $status,
            'required_stores' => self::REQUIRED_STORES,
            'searches' => $searches,
            'search_count' => count($searches),
            'failed_stores' => $failedStores,
            'elapsed_ms' => (int) round((microtime(true) - $startedAt) * 1000.0),
        ];
    }

    /**
     * Recursively count occurrences of a memory entry id in a recall/pack/cache payload.
     *
     * @param  array<string,mixed>|list<mixed>|scalar|null  $payload
     */
    private function countRefHits(mixed $payload, string $entryId): int
    {
        if ($entryId === '') {
            return 0;
        }

        if (is_string($payload)) {
            return substr_count($payload, $entryId);
        }

        if (! is_array($payload)) {
            return 0;
        }

        $hits = 0;
        foreach ($payload as $key => $value) {
            if (is_string($key) && in_array($key, ['id', 'memory_entry_id', 'source_memory_entry_id', 'target_memory_entry_id'], true)
                && (string) $value === $entryId) {
                $hits++;
            }
            if (is_string($value) && $value === $entryId) {
                $hits++;
            }
            if (is_array($value)) {
                $hits += $this->countRefHits($value, $entryId);
            }
        }

        return $hits;
    }
}
