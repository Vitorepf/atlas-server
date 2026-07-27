<?php

declare(strict_types=1);

namespace App\Services\Engineering\CodeGraph;

/**
 * AP-815 · E-9 — In-memory memoizer for assembled code-graph query / context results.
 *
 * The cross-project context engine recomputes the same assembled query (graph
 * traversal + context-pack rendering) many times within a single request /
 * command run. This cache memoizes a result keyed by `(workspace_id, query_key)`
 * so a repeated query is served from memory instead of re-traversing the graph.
 *
 * Design goals (matching the house style of CodeGraphWorkspaceIdentity):
 *   - PURE of DB, cache drivers and clock — testable with no infrastructure.
 *     The backing store is a plain `array<string, array<string, mixed>>`
 *     namespaced FIRST by workspace, so per-workspace invalidation is O(1) and
 *     can never touch another workspace's slot.
 *   - An optional store may be injected (a {@see CodeGraphQueryCacheStore}) so a
 *     caller can share / inspect / pre-seed the backing array. When omitted, a
 *     private in-memory store is used.
 *   - FAIL-SAFE: never throws on a bad key or a producer that returns `null`.
 *     A blank workspace / query key is normalized to a stable bucket so the
 *     cache degrades to a single safe slot instead of erroring.
 *   - `null` is a FIRST-CLASS cached value. Presence is decided with
 *     `array_key_exists`, NOT `isset`, so a producer that legitimately returns
 *     `null` is cached once and never re-run (a common subtle cache bug).
 *
 * This is [php] by the runtime-language boundary: orchestration / efficiency
 * plumbing, not heavy data or ML.
 */
class CodeGraphQueryCache
{
    private CodeGraphQueryCacheStore $store;

    private int $hits = 0;

    private int $misses = 0;

    /**
     * @param  CodeGraphQueryCacheStore|null  $store  optional shared/injected backing store
     */
    public function __construct(?CodeGraphQueryCacheStore $store = null)
    {
        $this->store = $store ?? new CodeGraphQueryCacheStore();
    }

    /**
     * Return the cached value for the key, or run `$producer` exactly once,
     * cache its result and return it.
     *
     * A cached `null` counts as a HIT (the producer is not re-run). If the
     * producer itself throws, the exception propagates UN-cached (a failed
     * computation is never memoized) — the only path on which this method
     * surfaces a throwable, and it is the caller's own producer doing so.
     *
     * @template T
     *
     * @param  callable():T  $producer
     * @return T
     */
    public function remember(string $workspaceId, string $queryKey, callable $producer): mixed
    {
        $workspace = $this->normalizeKey($workspaceId);
        $query = $this->normalizeKey($queryKey);

        if ($this->store->has($workspace, $query)) {
            $this->hits++;

            return $this->store->get($workspace, $query);
        }

        $this->misses++;

        // Run OUTSIDE any try/catch: a throwing producer must not be memoized,
        // and we must not swallow the caller's error. A successful run is cached.
        $value = $producer();
        $this->store->put($workspace, $query, $value);

        return $value;
    }

    /**
     * Return the cached value, or `null` on a miss.
     *
     * NOTE: because `null` is a valid cached value, a `null` return is
     * ambiguous between "miss" and "cached null". Use {@see has()} when the
     * distinction matters. This method updates hit/miss stats.
     */
    public function get(string $workspaceId, string $queryKey): mixed
    {
        $workspace = $this->normalizeKey($workspaceId);
        $query = $this->normalizeKey($queryKey);

        if ($this->store->has($workspace, $query)) {
            $this->hits++;

            return $this->store->get($workspace, $query);
        }

        $this->misses++;

        return null;
    }

    /**
     * Whether a value (including a cached `null`) exists for the key.
     *
     * This is a pure lookup and does NOT affect hit/miss stats.
     */
    public function has(string $workspaceId, string $queryKey): bool
    {
        return $this->store->has(
            $this->normalizeKey($workspaceId),
            $this->normalizeKey($queryKey),
        );
    }

    /**
     * Store (or overwrite) a value for the key.
     */
    public function put(string $workspaceId, string $queryKey, mixed $value): void
    {
        $this->store->put(
            $this->normalizeKey($workspaceId),
            $this->normalizeKey($queryKey),
            $value,
        );
    }

    /**
     * Drop a single key. No-op (and never throws) if absent.
     */
    public function forget(string $workspaceId, string $queryKey): void
    {
        $this->store->forget(
            $this->normalizeKey($workspaceId),
            $this->normalizeKey($queryKey),
        );
    }

    /**
     * Clear every entry for a workspace and return how many were removed.
     *
     * Strictly scoped: workspace B is never touched when invalidating A.
     */
    public function invalidateWorkspace(string $workspaceId): int
    {
        return $this->store->invalidateWorkspace($this->normalizeKey($workspaceId));
    }

    /**
     * Clear the entire cache (all workspaces). Returns total entries removed.
     *
     * Does NOT reset the hit/miss counters — use {@see resetStats()} for that.
     */
    public function flush(): int
    {
        return $this->store->flush();
    }

    /**
     * Cache telemetry.
     *
     * @return array{hits:int,misses:int,entries:int}
     */
    public function stats(): array
    {
        return [
            'hits' => $this->hits,
            'misses' => $this->misses,
            'entries' => $this->store->count(),
        ];
    }

    /**
     * Reset hit/miss counters to zero (leaves cached entries intact).
     */
    public function resetStats(): void
    {
        $this->hits = 0;
        $this->misses = 0;
    }

    /**
     * Normalize a key segment so blank / whitespace-only input degrades to a
     * single stable bucket instead of producing an unkeyable entry. Trimming
     * also makes "ws " and "ws" address the same slot, avoiding silent misses
     * from stray whitespace.
     */
    private function normalizeKey(string $key): string
    {
        $trimmed = trim($key);

        return $trimmed !== '' ? $trimmed : '__default__';
    }
}
