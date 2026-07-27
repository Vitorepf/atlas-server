<?php

declare(strict_types=1);

namespace App\Services\Engineering\CodeGraph;

/**
 * AP-815 · W-8 — Retention policy for cross-project code graphs.
 *
 * As Atlas indexes a SECOND, THIRD … project (each keyed by its own stable
 * `workspace_id`, see {@see CodeGraphWorkspaceIdentity}), the code-intelligence
 * read-model accumulates graphs for workspaces the operator touched once and never
 * returned to. Left unbounded that is dead weight: stale graphs cost storage, slow
 * cross-workspace queries, and (worse) let an agent surface context from a project
 * that has long since drifted from what the graph still claims.
 *
 * This class is the PURE DECISION half of garbage collection: given a snapshot of
 * workspaces and the current time, it decides WHICH workspace graphs are stale and
 * eligible to be reclaimed. It does NOT delete anything — the actual deletion is a
 * separate executor that consumes this verdict. Splitting the decision from the
 * effect makes the dangerous part (what gets reclaimed) trivially testable with no
 * DB, no filesystem and no clock.
 *
 * STALENESS RULES (in plain terms):
 *
 *   1. A workspace with a known `last_indexed_at` is stale when it has not been
 *      indexed within the retention window:
 *          nowTs − last_indexed_at  >  retention_days · 86400
 *      (strict `>`: a graph exactly at the boundary is still fresh — fail-safe keeps).
 *   2. A workspace that was NEVER indexed (`last_indexed_at === null`) is stale ONLY
 *      when the caller explicitly opts in via `$opts['gc_never_indexed'] === true`.
 *      By default a never-indexed entry is KEPT — we never reclaim something whose
 *      age we cannot even measure unless told to.
 *   3. The PRIMARY workspace (the running app — `default_workspace_id`, default
 *      'atlas-server') is ALWAYS protected and can NEVER be reported stale, no matter
 *      how old. Callers may protect additional workspaces via `$opts['protect']`
 *      (a single id or a list of ids). The whole point of cross-project retention is
 *      to prune *other* projects without ever endangering the operator's home graph.
 *
 * Determinism & fail-safety (house contract):
 *   - Pure transform. No DB, no clock (the caller passes `nowTs`), no random, no
 *     provider. Same input always yields byte-identical output: the result is sorted
 *     by `workspace_id` (a total order; ties are impossible after de-duplication), so
 *     ordering never depends on PHP's unstable sort or on input order.
 *   - Never throws on bad data. A non-array row, a row with a missing/blank/non-string
 *     `workspace_id`, or a `last_indexed_at` that is neither null nor an integer-like
 *     value is SKIPPED (it can never be reported stale — the keep-safe direction; we
 *     do not invent a deletion candidate from a row we cannot reason about). NaN/INF
 *     timestamps are treated as unreadable and skipped.
 *   - A protected id is filtered FIRST, before any age math, so a protected workspace
 *     with a garbage timestamp is still simply protected (never errors, never stale).
 *   - Config is read with inline default literals so it works without config edits;
 *     `$opts` overrides config per call. A non-positive / non-numeric / NaN retention
 *     resolves to the safe default (90 days) rather than reclaiming everything — a
 *     misconfigured `0` can never silently turn this into "GC all".
 *
 * This is [php] by the runtime-language boundary: it GOVERNS reclamation (a
 * decision), it does not compute heavy graph data.
 */
class CodeGraphRetentionPolicy
{
    public const SCHEMA = 'atlas.code_graph.retention_policy.v1';

    /** Seconds in a day — the retention window is expressed in whole days. */
    private const SECONDS_PER_DAY = 86400;

    /** Safe default retention window when config/opts are absent or invalid. */
    private const DEFAULT_RETENTION_DAYS = 90;

    /**
     * `age_days` reported for a never-indexed workspace that is reclaimed via
     * `gc_never_indexed`. Its true age is unmeasurable (it was never indexed), so we
     * emit this sentinel rather than a misleading number. It can never collide with a
     * real stale age, which is always ≥ 0 (a row is only stale-by-age when its age
     * strictly exceeds a non-negative retention window).
     */
    public const AGE_NEVER_INDEXED = -1;

    /** Reason tag: stale because it aged past the retention window. */
    public const REASON_EXPIRED = 'expired';

    /** Reason tag: stale because it was never indexed (with gc_never_indexed on). */
    public const REASON_NEVER_INDEXED = 'never_indexed';

    /**
     * Decide which workspace graphs are stale and eligible for GC.
     *
     * @param  array<int,mixed>  $workspaces  snapshot rows. Each row SHOULD be an
     *   array shaped `['workspace_id' => string, 'last_indexed_at' => int|null]`
     *   where `last_indexed_at` is a unix timestamp (seconds) or null for a
     *   never-indexed workspace. Malformed rows are skipped (see fail-safety above).
     * @param  int  $nowTs  current unix timestamp (seconds), supplied by the caller so
     *   the decision is pure of the clock.
     * @param  array<string,mixed>  $opts  per-call overrides:
     *   - `retention_days` (int|numeric): window in days; overrides config
     *     'atlas.code_graph.retention_days' (default 90). Non-positive/invalid → default.
     *   - `gc_never_indexed` (bool): when strictly true, never-indexed workspaces are
     *     reported stale; otherwise they are kept. Default false.
     *   - `protect` (string|array<int,string>): additional workspace_id(s) that must
     *     never be reported stale, on top of the always-protected primary workspace.
     * @return array<int,array{workspace_id:string,reason:string,age_days:int}>
     *   the stale workspaces, sorted ascending by `workspace_id`. Each entry names the
     *   workspace, why it is stale (`expired` | `never_indexed`) and its age in whole
     *   days ({@see AGE_NEVER_INDEXED} for never-indexed). De-duplicated by
     *   workspace_id (first readable occurrence wins).
     */
    public function stale(array $workspaces, int $nowTs, array $opts = []): array
    {
        $retentionDays = $this->resolveRetentionDays($opts);
        $retentionSeconds = $retentionDays * self::SECONDS_PER_DAY;
        $gcNeverIndexed = ($opts['gc_never_indexed'] ?? false) === true;
        $protected = $this->protectedIds($opts);

        $stale = [];   // map<workspace_id, array{workspace_id,reason,age_days}>

        foreach ($workspaces as $row) {
            if (! is_array($row)) {
                // Unreadable row: cannot be classified → never a deletion candidate.
                continue;
            }

            $workspaceId = $this->workspaceId($row);
            if ($workspaceId === null) {
                continue;
            }

            // Already decided (deduped): first readable occurrence wins, deterministic.
            if (array_key_exists($workspaceId, $stale)) {
                continue;
            }

            // Protected ids are filtered before any age math: a protected workspace is
            // never stale, even with a garbage/missing timestamp.
            if (isset($protected[$workspaceId])) {
                continue;
            }

            $lastIndexedAt = $this->lastIndexedAt($row);

            if ($lastIndexedAt === null) {
                // Never indexed — stale only when explicitly opted in.
                if ($this->rowHasUnreadableTimestamp($row)) {
                    // A present-but-garbage timestamp is not a clean "never indexed";
                    // we cannot reason about it, so skip (keep-safe).
                    continue;
                }
                if ($gcNeverIndexed) {
                    $stale[$workspaceId] = [
                        'workspace_id' => $workspaceId,
                        'reason' => self::REASON_NEVER_INDEXED,
                        'age_days' => self::AGE_NEVER_INDEXED,
                    ];
                }

                continue;
            }

            $ageSeconds = $nowTs - $lastIndexedAt;

            // Strict `>`: exactly at the boundary is still fresh (keep-safe). A
            // negative age (future timestamp / clock skew) is never stale.
            if ($ageSeconds > $retentionSeconds) {
                $stale[$workspaceId] = [
                    'workspace_id' => $workspaceId,
                    'reason' => self::REASON_EXPIRED,
                    'age_days' => intdiv($ageSeconds, self::SECONDS_PER_DAY),
                ];
            }
        }

        // Deterministic output: sort by workspace_id (keys are unique → total order).
        $result = array_values($stale);
        usort($result, static fn (array $a, array $b): int => strcmp($a['workspace_id'], $b['workspace_id']));

        return $result;
    }

    /**
     * Resolve the retention window in whole days. `$opts['retention_days']` wins over
     * config 'atlas.code_graph.retention_days'; both fall back to the safe default.
     * A non-positive, non-numeric or non-finite value resolves to the default rather
     * than reclaiming everything — the keep-safe direction.
     *
     * @param  array<string,mixed>  $opts
     */
    private function resolveRetentionDays(array $opts): int
    {
        if (array_key_exists('retention_days', $opts)) {
            $candidate = $this->positiveIntOrNull($opts['retention_days']);
            if ($candidate !== null) {
                return $candidate;
            }
        }

        $configured = $this->positiveIntOrNull(config('atlas.code_graph.retention_days', self::DEFAULT_RETENTION_DAYS));

        return $configured ?? self::DEFAULT_RETENTION_DAYS;
    }

    /**
     * Build the protected-id set: the always-protected primary workspace plus any
     * caller-supplied `$opts['protect']` (a single id or a list of ids). Returned as a
     * map for O(1) membership tests. Ids are normalised (trimmed); blanks are ignored.
     *
     * @param  array<string,mixed>  $opts
     * @return array<string,true>
     */
    private function protectedIds(array $opts): array
    {
        $protected = [];

        $primary = config('atlas.code_graph.default_workspace_id', 'atlas-server');
        if (is_string($primary) && trim($primary) !== '') {
            $protected[trim($primary)] = true;
        } else {
            // Config blanked/garbled: never lose the structural protection on the home graph.
            $protected['atlas-server'] = true;
        }

        $extra = $opts['protect'] ?? null;
        if (is_string($extra)) {
            $extra = [$extra];
        }
        if (is_array($extra)) {
            foreach ($extra as $id) {
                if (is_string($id) && trim($id) !== '') {
                    $protected[trim($id)] = true;
                }
            }
        }

        return $protected;
    }

    /**
     * Extract a usable workspace_id from a row, or null when it is missing/blank/not a
     * string. The id is trimmed so it matches the (trimmed) protected set and dedupes
     * cleanly.
     *
     * @param  array<string,mixed>  $row
     */
    private function workspaceId(array $row): ?string
    {
        if (! array_key_exists('workspace_id', $row)) {
            return null;
        }
        $value = $row['workspace_id'];
        if (! is_string($value)) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }

    /**
     * Extract `last_indexed_at` as a unix timestamp, or null when the row carries no
     * usable timestamp (key absent, value null, or value unreadable). An int, a float
     * with no fractional surprise, or a numeric string are all accepted and floored to
     * an integer second. NaN/INF and non-numeric values yield null (treated as "no
     * usable timestamp").
     *
     * @param  array<string,mixed>  $row
     */
    private function lastIndexedAt(array $row): ?int
    {
        if (! array_key_exists('last_indexed_at', $row)) {
            return null;
        }

        $raw = $row['last_indexed_at'];
        if ($raw === null) {
            return null;
        }

        if (is_int($raw)) {
            return $raw;
        }
        if (is_float($raw)) {
            if (is_nan($raw) || is_infinite($raw)) {
                return null;
            }

            return (int) $raw;
        }
        if (is_string($raw) && is_numeric(trim($raw))) {
            $float = (float) trim($raw);
            if (is_nan($float) || is_infinite($float)) {
                return null;
            }

            return (int) $float;
        }

        return null;
    }

    /**
     * Whether the row carries a `last_indexed_at` that is PRESENT but UNREADABLE
     * (not null, yet not a usable timestamp). Distinguishes a clean "never indexed"
     * (key absent or explicit null) from a garbage value we must skip rather than
     * treat as never-indexed.
     *
     * @param  array<string,mixed>  $row
     */
    private function rowHasUnreadableTimestamp(array $row): bool
    {
        if (! array_key_exists('last_indexed_at', $row)) {
            return false;
        }
        $raw = $row['last_indexed_at'];
        if ($raw === null) {
            return false;
        }

        // Present and non-null but {@see lastIndexedAt} could not parse it → garbage.
        return $this->lastIndexedAt($row) === null;
    }

    /**
     * Coerce a value to a strictly-positive integer, or null when it is not a finite
     * positive number. Used for retention days, where a non-positive window is a
     * misconfiguration we refuse (fall back to the safe default).
     */
    private function positiveIntOrNull(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }
        if (is_float($value)) {
            if (is_nan($value) || is_infinite($value)) {
                return null;
            }
            $int = (int) $value;

            return $int > 0 ? $int : null;
        }
        if (is_string($value) && is_numeric(trim($value))) {
            $float = (float) trim($value);
            if (is_nan($float) || is_infinite($float)) {
                return null;
            }
            $int = (int) $float;

            return $int > 0 ? $int : null;
        }

        return null;
    }
}
