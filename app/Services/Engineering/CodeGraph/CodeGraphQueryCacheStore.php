<?php

declare(strict_types=1);

namespace App\Services\Engineering\CodeGraph;

/**
 * AP-815 · E-9 — Plain array-backed store for {@see CodeGraphQueryCache}.
 *
 * A deliberately trivial, DB-free, driver-free backing store. It exists as a
 * named seam so {@see CodeGraphQueryCache} can be constructed with an injected
 * / shared / pre-seeded store and remain fully testable in pure memory.
 *
 * Layout is namespaced FIRST by workspace:
 *
 *     [ workspace_id => [ query_key => value, ... ], ... ]
 *
 * so per-workspace invalidation is a single `unset` of one sub-array and can
 * never reach into a sibling workspace. Presence is decided with
 * `array_key_exists`, so a cached `null` is a real, retained entry — not a miss.
 *
 * Fail-safe: every method tolerates absent workspaces / keys without throwing.
 *
 * [php] orchestration plumbing — no heavy data, no clock, no randomness.
 */
class CodeGraphQueryCacheStore
{
    /**
     * @var array<string, array<string, mixed>>
     */
    private array $entries = [];

    /**
     * @param  array<string, array<string, mixed>>  $seed  optional pre-seeded entries
     */
    public function __construct(array $seed = [])
    {
        // Keep only well-formed sub-arrays; ignore malformed seed rows rather
        // than throwing, so a hand-built or deserialized seed can never crash
        // the cache.
        foreach ($seed as $workspace => $bucket) {
            if (is_string($workspace) && is_array($bucket)) {
                $this->entries[$workspace] = $bucket;
            }
        }
    }

    /**
     * Whether a value (including a stored `null`) exists for the key.
     */
    public function has(string $workspaceId, string $queryKey): bool
    {
        return isset($this->entries[$workspaceId])
            && array_key_exists($queryKey, $this->entries[$workspaceId]);
    }

    /**
     * Retrieve the stored value, or `null` when absent.
     *
     * The `null` return is ambiguous between "absent" and "stored null"; pair
     * with {@see has()} when the caller must distinguish the two.
     */
    public function get(string $workspaceId, string $queryKey): mixed
    {
        return $this->entries[$workspaceId][$queryKey] ?? null;
    }

    /**
     * Store (or overwrite) a value for the key.
     */
    public function put(string $workspaceId, string $queryKey, mixed $value): void
    {
        $this->entries[$workspaceId][$queryKey] = $value;
    }

    /**
     * Drop a single key. No-op when absent. Prunes an emptied workspace bucket
     * so {@see count()} stays exact and invalidation counts never drift.
     */
    public function forget(string $workspaceId, string $queryKey): void
    {
        if (! isset($this->entries[$workspaceId])) {
            return;
        }

        unset($this->entries[$workspaceId][$queryKey]);

        if ($this->entries[$workspaceId] === []) {
            unset($this->entries[$workspaceId]);
        }
    }

    /**
     * Remove every entry for a workspace and return the count removed.
     *
     * Scoped to exactly one workspace bucket — sibling workspaces are untouched.
     */
    public function invalidateWorkspace(string $workspaceId): int
    {
        if (! isset($this->entries[$workspaceId])) {
            return 0;
        }

        $removed = count($this->entries[$workspaceId]);
        unset($this->entries[$workspaceId]);

        return $removed;
    }

    /**
     * Remove everything and return the total number of entries removed.
     */
    public function flush(): int
    {
        $total = $this->count();
        $this->entries = [];

        return $total;
    }

    /**
     * Total number of cached entries across all workspaces.
     */
    public function count(): int
    {
        $total = 0;
        foreach ($this->entries as $bucket) {
            $total += count($bucket);
        }

        return $total;
    }
}
