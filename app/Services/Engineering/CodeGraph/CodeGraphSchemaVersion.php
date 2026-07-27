<?php

declare(strict_types=1);

namespace App\Services\Engineering\CodeGraph;

/**
 * AP-815 · W-9 — Per-workspace code-graph schema version tracker.
 *
 * The code graph is a read-model whose SHAPE evolves (new node/edge fields, a new
 * adjacency layout, a changed integrity hash). When that shape bumps, every already
 * indexed workspace is silently STALE: its rows were written under the old schema and
 * must be rebuilt, or an agent reading the graph trusts data the new readers can no
 * longer interpret. This tracker records the schema version each workspace was last
 * indexed under and answers the one question the indexer needs: "does this workspace
 * need a re-index?".
 *
 * The contract is intentionally tiny and orthogonal to WHERE the versions live. The
 * canonical store is the durable code-graph metadata (managed by the caller); for tests
 * and ephemeral runs an in-memory array store can be injected. Either way the rule is
 * the same:
 *
 *   needsReindex(ws) ⇔ recordedFor(ws) !== current()
 *
 * which is true both for a never-indexed workspace (recorded = null) and for one indexed
 * under an older version (recorded < CURRENT) — and also, defensively, for one somehow
 * stamped with a FUTURE version (recorded > CURRENT, e.g. after a downgrade), since that
 * data is equally uninterpretable by the running readers.
 *
 * Determinism & fail-safety (house contract):
 *   - Pure of DB + clock + random. State is the injected/in-memory array only; the same
 *     sequence of calls always yields the same answers.
 *   - Never throws on malformed input. A blank/whitespace workspace id is ignored by
 *     mark() (no-op) and reads as "never recorded". A stored value that is not a clean
 *     integer (legacy junk, a float, a numeric string) is coerced when unambiguous and
 *     otherwise treated as "no usable record" → needsReindex true (the safe direction:
 *     when in doubt, re-index rather than trust unreadable data).
 *   - An injected store seeded with garbage shapes (non-string keys, array values) is
 *     tolerated: unusable entries simply do not count as a valid recorded version.
 *
 * This is [php] by the runtime-language boundary: it GOVERNS re-index admission (a
 * version decision), it does not compute or carry heavy graph data.
 */
class CodeGraphSchemaVersion
{
    public const SCHEMA = 'atlas.code_graph.schema_version.v1';

    /**
     * The current code-graph schema version. BUMP THIS whenever the persisted graph
     * shape changes in a way that makes already-indexed workspaces stale. Every
     * workspace not recorded at this exact value will report needsReindex() === true.
     */
    public const CURRENT = 3;

    /**
     * Recorded versions, keyed by normalized workspace id.
     *
     * @var array<string,int>
     */
    private array $versions;

    /**
     * @param  array<mixed,mixed>  $store  optional seed of prior records
     *   (workspaceId => version). Any well-formed `string-key => integer-ish value`
     *   pair is adopted; anything unusable is dropped. The service owns its own copy —
     *   mutations never write back to the caller's array.
     */
    public function __construct(array $store = [])
    {
        $this->versions = $this->normalizeStore($store);
    }

    /**
     * The current schema version the running readers expect.
     */
    public function current(): int
    {
        return self::CURRENT;
    }

    /**
     * The schema version a workspace was last indexed under, or null if it has never
     * been recorded (or was recorded with an unusable value).
     */
    public function recordedFor(string $workspaceId): ?int
    {
        $key = $this->normalizeId($workspaceId);
        if ($key === null) {
            return null;
        }

        return $this->versions[$key] ?? null;
    }

    /**
     * Record that a workspace has been indexed under a given schema version. Defaults
     * to {@see CURRENT} (the common case: "I just finished indexing at the live
     * version"). A blank/whitespace workspace id is a no-op — the tracker never
     * fabricates a record for an unidentifiable workspace. An explicit version is
     * coerced to int; a non-numeric/non-finite version is ignored (no-op) rather than
     * stamping a corrupt value, so a later read stays at the safe "needs re-index".
     */
    public function mark(string $workspaceId, ?int $version = null): void
    {
        $key = $this->normalizeId($workspaceId);
        if ($key === null) {
            return;
        }

        $this->versions[$key] = $version ?? self::CURRENT;
    }

    /**
     * Whether a workspace needs (re-)indexing under the current schema. True when the
     * recorded version differs from {@see current()} for ANY reason: never recorded
     * (null), recorded under an older version, or — defensively — recorded under a
     * future version that the running readers cannot interpret.
     */
    public function needsReindex(string $workspaceId): bool
    {
        return $this->recordedFor($workspaceId) !== $this->current();
    }

    /**
     * A snapshot of every recorded workspace => version. Deterministic order (keys
     * sorted) so the output is stable for assertions and audit. Returns a copy; the
     * caller cannot mutate internal state through it.
     *
     * @return array<string,int>
     */
    public function all(): array
    {
        $snapshot = $this->versions;
        ksort($snapshot);

        return $snapshot;
    }

    /**
     * Normalize a seed store into clean `string-key => int-value` records, discarding
     * anything that cannot be interpreted as a real recorded version.
     *
     * @param  array<mixed,mixed>  $store
     * @return array<string,int>
     */
    private function normalizeStore(array $store): array
    {
        $clean = [];
        foreach ($store as $rawKey => $rawValue) {
            $key = is_string($rawKey) ? $this->normalizeId($rawKey) : null;
            if ($key === null) {
                continue;
            }

            $version = $this->coerceVersion($rawValue);
            if ($version === null) {
                continue;
            }

            $clean[$key] = $version;
        }

        return $clean;
    }

    /**
     * Trim a workspace id to a stable key, or null when blank/whitespace.
     */
    private function normalizeId(string $workspaceId): ?string
    {
        $trimmed = trim($workspaceId);

        return $trimmed !== '' ? $trimmed : null;
    }

    /**
     * Coerce a stored/seed value into a clean integer version, or null when it cannot
     * be trusted as one. Accepts ints and integer-valued numeric strings/floats
     * (e.g. "3", 3.0); rejects fractional, non-numeric, or non-finite values so a
     * corrupt record never masquerades as a valid version.
     */
    private function coerceVersion(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_float($value)) {
            if (is_nan($value) || is_infinite($value) || floor($value) !== $value) {
                return null;
            }

            return (int) $value;
        }

        if (is_string($value)) {
            $trimmed = trim($value);
            // Integer-valued numeric strings only: "3" yes, "3.5"/"v3"/"" no.
            if ($trimmed !== '' && preg_match('/^-?\d+$/', $trimmed) === 1) {
                return (int) $trimmed;
            }
        }

        return null;
    }
}
