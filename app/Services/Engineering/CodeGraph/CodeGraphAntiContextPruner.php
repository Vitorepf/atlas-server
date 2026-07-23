<?php

declare(strict_types=1);

namespace App\Services\Engineering\CodeGraph;


/**
 * AP-815 · E-8 — Anti-context pruner: drop graph-irrelevant context.
 *
 * When Atlas assembles context for a task it starts from a set of SEED nodes (the
 * files/symbols the task is actually about) and gathers CANDIDATE nodes that might be
 * worth including. Unbounded, that candidate set bloats the context window with code
 * that has no graph relationship to the task — pure noise that costs tokens and dilutes
 * the signal the provider sees.
 *
 * This pruner enforces the efficiency invariant at the context boundary:
 *
 *   A candidate is KEPT iff it is within N hops of ANY seed in the code graph
 *   (undirected reachability, bounded BFS); every other candidate is PRUNED.
 *
 *   - N is the context radius: $opts['max_distance'] overrides
 *     config('atlas.code_graph.max_context_distance', 2).
 *   - Seeds that appear among the candidates are ALWAYS kept (distance 0 to
 *     themselves) — a task's own anchor nodes are never pruned as irrelevant.
 *   - With NO seeds there is nothing for relevance to anchor on, so EVERY candidate
 *     is pruned (the over-pruning-safe direction: never smuggle unanchored context in).
 *
 * The adjacency is read as UNDIRECTED for reachability: an edge a→b makes a and b
 * mutually reachable even if only one direction is present in the map. This matches how
 * code relationships flow both ways for context (a caller is relevant to a callee and
 * vice versa).
 *
 * Determinism & fail-safety (house contract):
 *   - Pure transform. No DB, no clock, no random, no provider. Same input always
 *     yields byte-identical output: `kept` and `pruned` are returned in a stable sorted
 *     order, so the result never depends on hash-map insertion order.
 *   - Never throws on bad data. Non-scalar / empty node ids are skipped; a malformed
 *     adjacency map (non-array neighbour lists, non-scalar neighbours, self-loops) is
 *     tolerated; BFS only ever traverses ids that exist as nodes, and is bounded by the
 *     distance cap AND the finite node set so it always terminates.
 *   - Config is read with an inline default literal so it works without config edits;
 *     a missing / garbage / negative distance falls back to a non-negative integer
 *     (a negative radius is clamped to 0 → only the seeds themselves are reachable).
 *
 * This is [php] by the runtime-language boundary: it GOVERNS context admission
 * (a bounded graph decision), it does not compute heavy graph data.
 */
class CodeGraphAntiContextPruner
{
    public const SCHEMA = 'atlas.code_graph.anti_context_pruner.v1';

    /**
     * The default context radius when neither $opts nor config supplies one. Two hops
     * captures direct neighbours and their neighbours — enough for relevant context
     * without dragging in the whole transitive closure.
     */
    public const DEFAULT_MAX_DISTANCE = 2;

    /**
     * Prune candidate nodes that are not within N hops of any seed.
     *
     * @param  array<int,mixed>  $candidateNodeIds  node ids proposed for the context.
     *   Each SHOULD be a scalar (string|int) node id. Non-scalar or empty ids are
     *   ignored. Duplicates collapse to one entry (a node is kept or pruned once).
     * @param  array<int,mixed>  $seedNodeIds  the anchor node ids the task is about.
     *   Same scalar contract; duplicates and non-scalars are tolerated.
     * @param  array<array-key,mixed>  $adjacency  node id => list of neighbour ids.
     *   Read as UNDIRECTED for reachability. Non-array neighbour lists and non-scalar
     *   neighbours are skipped; self-loops are ignored. Keys are node ids; a neighbour
     *   id that is not itself a key is still a reachable node.
     * @param  array<string,mixed>  $opts  per-call overrides:
     *   - `max_distance` (int): hop radius (default from config
     *     'atlas.code_graph.max_context_distance', else 2). Clamped to >= 0.
     * @return array{
     *   kept: array<int,string>,
     *   pruned: array<int,string>,
     *   stats: array{candidates:int, kept:int, pruned:int, max_distance:int}
     * }
     *   `kept` and `pruned` are the DISTINCT candidate ids (as strings), each sorted
     *   for deterministic output. `stats.candidates` is the distinct candidate count
     *   (kept + pruned). `stats.max_distance` is the effective radius actually used.
     */
    public function prune(array $candidateNodeIds, array $seedNodeIds, array $adjacency, array $opts = []): array
    {
        $maxDistance = $this->resolveMaxDistance($opts);

        // Distinct, normalised candidate ids. A set keyed by id collapses duplicates
        // and gives O(1) membership; the values preserve the canonical string form.
        $candidates = $this->normalizeIdSet($candidateNodeIds);
        $seeds = $this->normalizeIdSet($seedNodeIds);

        // With no seeds there is no relevance anchor → nothing is reachable → prune all.
        // (Computed before BFS so an empty-seed call is trivially cheap.)
        if ($seeds === []) {
            return $this->result($candidates, [], $maxDistance);
        }

        $reachable = $this->reachableWithin($seeds, $adjacency, $maxDistance);

        $kept = [];
        $pruned = [];
        foreach ($candidates as $id) {
            if (isset($reachable[$id])) {
                $kept[$id] = $id;
            } else {
                $pruned[$id] = $id;
            }
        }

        return $this->result($pruned, $kept, $maxDistance, $candidates);
    }

    /**
     * Bounded undirected BFS: every node reachable from ANY seed within $maxDistance
     * hops. Returns a set keyed by node id (value === id) for O(1) membership.
     *
     * The traversal is bounded twice over — by the distance cap and by the finite,
     * de-duplicated visited set — so it always terminates even on cyclic graphs. Only
     * ids that appear as adjacency keys can expand; a neighbour with no outgoing entry
     * is still marked reachable (it just has no further frontier).
     *
     * @param  array<string,string>  $seeds  distinct seed ids (value === id).
     * @param  array<array-key,mixed>  $adjacency  raw, possibly-malformed adjacency map.
     * @return array<string,string>  reachable ids (value === id).
     */
    private function reachableWithin(array $seeds, array $adjacency, int $maxDistance): array
    {
        $undirected = $this->buildUndirectedAdjacency($adjacency);

        // Seeds are all at distance 0 and are unconditionally reachable.
        $reachable = $seeds;
        $frontier = array_values($seeds);

        for ($depth = 0; $depth < $maxDistance && $frontier !== []; $depth++) {
            $next = [];
            foreach ($frontier as $nodeId) {
                $neighbours = $undirected[$nodeId] ?? [];
                foreach ($neighbours as $neighbourId) {
                    if (isset($reachable[$neighbourId])) {
                        continue;
                    }
                    $reachable[$neighbourId] = $neighbourId;
                    $next[$neighbourId] = $neighbourId;
                }
            }
            $frontier = array_values($next);
        }

        return $reachable;
    }

    /**
     * Normalise a raw adjacency map into a clean undirected one: keys and neighbours are
     * canonical string ids, every edge is mirrored (a in b's list AND b in a's list),
     * self-loops and malformed entries are dropped, neighbour lists are de-duplicated.
     *
     * Mirroring here (rather than during BFS) keeps the traversal a simple lookup and
     * guarantees undirected semantics regardless of which direction the input recorded.
     *
     * @param  array<array-key,mixed>  $adjacency
     * @return array<string,array<int,string>>  node id => distinct neighbour ids.
     */
    private function buildUndirectedAdjacency(array $adjacency): array
    {
        /** @var array<string,array<string,string>> $sets */
        $sets = []; // node id => set of neighbour ids (keyed for dedup)

        foreach ($adjacency as $rawNode => $neighbours) {
            $node = $this->normalizeId($rawNode);
            if ($node === null || ! is_array($neighbours)) {
                continue;
            }

            foreach ($neighbours as $rawNeighbour) {
                $neighbour = $this->normalizeId($rawNeighbour);
                if ($neighbour === null || $neighbour === $node) {
                    // Skip unusable ids and self-loops (they add no reachability).
                    continue;
                }

                $sets[$node][$neighbour] = $neighbour;
                $sets[$neighbour][$node] = $node; // mirror → undirected
            }
        }

        // Collapse each neighbour set to a plain list for cheap iteration in BFS.
        $out = [];
        foreach ($sets as $node => $neighbourSet) {
            $out[$node] = array_values($neighbourSet);
        }

        return $out;
    }

    /**
     * Distinct, canonicalised id set from a raw list. Keyed by id (value === id) so
     * duplicates collapse and membership is O(1). Non-scalar / empty ids are dropped.
     *
     * @param  array<int,mixed>  $ids
     * @return array<string,string>
     */
    private function normalizeIdSet(array $ids): array
    {
        $set = [];
        foreach ($ids as $raw) {
            $id = $this->normalizeId($raw);
            if ($id !== null) {
                $set[$id] = $id;
            }
        }

        return $set;
    }

    /**
     * Canonicalise a single node id to a non-empty string, or null if it cannot be a
     * usable id. Accepts string and int (and bool/float coerced predictably); rejects
     * null, arrays, objects, and ids that are empty once trimmed.
     *
     * Trimming guards against whitespace-only ids and incidental padding splitting one
     * logical node into two. Booleans coerce to '1'/'' (the latter → null), which keeps
     * a stray boolean from masquerading as a node.
     */
    private function normalizeId(mixed $value): ?string
    {
        if (is_string($value)) {
            $trimmed = trim($value);

            return $trimmed === '' ? null : $trimmed;
        }

        if (is_int($value)) {
            return (string) $value;
        }

        if (is_float($value)) {
            // Reject non-finite floats; otherwise use a stable scalar rendering.
            if (is_nan($value) || is_infinite($value)) {
                return null;
            }
            $trimmed = trim((string) $value);

            return $trimmed === '' ? null : $trimmed;
        }

        if (is_bool($value)) {
            return $value ? '1' : null;
        }

        // null, array, object, resource → not a usable scalar id.
        return null;
    }

    /**
     * Resolve the effective hop radius. $opts wins over config; a missing / non-numeric
     * / negative value falls back to a non-negative integer (negative → 0, so only the
     * seeds themselves count as reachable — the most conservative non-empty radius).
     *
     * @param  array<string,mixed>  $opts
     */
    private function resolveMaxDistance(array $opts): int
    {
        if (array_key_exists('max_distance', $opts)) {
            $candidate = $this->intOrNull($opts['max_distance']);
            if ($candidate !== null) {
                return $candidate < 0 ? 0 : $candidate;
            }
        }

        $configured = $this->intOrNull(config('atlas.code_graph.max_context_distance', self::DEFAULT_MAX_DISTANCE));
        $resolved = $configured ?? self::DEFAULT_MAX_DISTANCE;

        return $resolved < 0 ? 0 : $resolved;
    }

    /**
     * Coerce a value to an int hop count, or null if it cannot be interpreted as a
     * finite whole number. Accepts ints, integral floats, and numeric strings; a
     * fractional value is floored toward zero so "1.9" reads as 1 hop, never 2.
     */
    private function intOrNull(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_float($value)) {
            if (is_nan($value) || is_infinite($value)) {
                return null;
            }

            return (int) $value;
        }

        if (is_string($value) && is_numeric(trim($value))) {
            return (int) (float) trim($value);
        }

        return null;
    }

    /**
     * Assemble the final, deterministically-sorted result. Both id lists are sorted
     * (natural, case-insensitive) so output never depends on map insertion order.
     *
     * @param  array<string,string>  $pruned  distinct pruned ids (value === id).
     * @param  array<string,string>  $kept  distinct kept ids (value === id).
     * @param  array<string,string>|null  $candidates  distinct candidate set; when
     *   null it is reconstructed as kept ∪ pruned (used by the empty-seed early return).
     * @return array{
     *   kept: array<int,string>,
     *   pruned: array<int,string>,
     *   stats: array{candidates:int, kept:int, pruned:int, max_distance:int}
     * }
     */
    private function result(array $pruned, array $kept, int $maxDistance, ?array $candidates = null): array
    {
        $keptList = $this->sortedIds($kept);
        $prunedList = $this->sortedIds($pruned);

        $candidateCount = $candidates !== null
            ? count($candidates)
            : count($keptList) + count($prunedList);

        return [
            'kept' => $keptList,
            'pruned' => $prunedList,
            'stats' => [
                'candidates' => $candidateCount,
                'kept' => count($keptList),
                'pruned' => count($prunedList),
                'max_distance' => $maxDistance,
            ],
        ];
    }

    /**
     * Sort an id set into a stable, human-readable list. Natural case-insensitive order
     * keeps "node2" before "node10" while staying a total order (so the output is
     * byte-identical run to run).
     *
     * @param  array<string,string>  $set
     * @return array<int,string>
     */
    private function sortedIds(array $set): array
    {
        $list = array_values($set);
        natcasesort($list);

        return array_values($list);
    }
}
