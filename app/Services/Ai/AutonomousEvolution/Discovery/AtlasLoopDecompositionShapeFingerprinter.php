<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery;

/**
 * ACDE Leap 5 — the deterministic STRUCTURAL plan fingerprint.
 *
 * Reduces a decomposition plan to an id-INDEPENDENT structural signature so the outcome corpus can pool
 * "the same shape" across obras. The signature is the DAG's structure only — never node ids, never literal
 * file paths — so two plans that differ only in how nodes/files are NAMED hash identically, while a plan
 * with an extra node (a different shape) hashes differently:
 *   - node_count                  (exact — an extra node changes the degree arrays' length => different hash)
 *   - sorted in-degree multiset   (id-independent: len(depends_on) per node, sorted)
 *   - sorted out-degree multiset  (id-independent: how many nodes depend on each node, sorted)
 *   - max DAG depth               (longest depends_on chain)
 *   - distinct-target bucket      (count of distinct target files, bucketed — never the literal paths)
 *   - seq-0-has-no-deps           (the create-class-root pattern: a leading independent node)
 *
 * objective_kind is deliberately NOT folded into the hash (it is stored as a corpus column for future
 * bucketing) so the recorder and the readiness gate — which both fingerprint the plan they hold — always
 * produce the SAME hash without threading the kind through the planner. Honest coarseness: structurally
 * identical plans across objective kinds pool their outcomes (the prior is advisory + n-guarded, and the
 * escalation decompose tier still produces alternatives, so over-suppression is bounded).
 *
 * Pure: no provider, no DB, no mutation. Same plan => same hash, always.
 */
final class AtlasLoopDecompositionShapeFingerprinter
{
    private const DEPTH_CAP = 64;

    /**
     * @param  array<string,mixed>  $plan  {nodes:list<{id, depends_on?, target_area|allowed_files, ...}>}
     * @return array{hash:string, features:array<string,mixed>, node_count:int}
     */
    public function fingerprint(array $plan): array
    {
        $nodes = array_values(is_array($plan['nodes'] ?? null) ? (array) $plan['nodes'] : []);
        $n = count($nodes);

        // Map ids -> index (id-independent: the labels are used ONLY to resolve depends_on edges, never hashed).
        $indexById = [];
        foreach ($nodes as $i => $node) {
            $id = is_array($node) ? trim((string) ($node['id'] ?? '')) : '';
            $indexById[$id !== '' ? $id : ('#'.$i)] = $i;
        }

        $inDeg = array_fill(0, max(0, $n), 0);
        $outDeg = array_fill(0, max(0, $n), 0);
        $adj = array_fill(0, max(0, $n), []); // dependency -> dependents (edge from prerequisite to node)
        foreach ($nodes as $i => $node) {
            $deps = is_array($node) ? (array) ($node['depends_on'] ?? []) : [];
            foreach ($deps as $d) {
                $d = trim((string) $d);
                if ($d !== '' && isset($indexById[$d]) && $indexById[$d] !== $i) {
                    $j = $indexById[$d];
                    $inDeg[$i]++;
                    $outDeg[$j]++;
                    $adj[$j][] = $i;
                }
            }
        }
        sort($inDeg);
        sort($outDeg);

        $targets = [];
        foreach ($nodes as $node) {
            if (! is_array($node)) {
                continue;
            }
            $t = ltrim(trim((string) ($node['target_area'] ?? '')), '/');
            if ($t !== '') {
                $targets[$t] = true;
            }
            foreach ((array) ($node['allowed_files'] ?? []) as $f) {
                if (is_string($f) && trim($f) !== '') {
                    $targets[ltrim(trim($f), '/')] = true;
                }
            }
        }

        $features = [
            'node_count' => $n,
            'in_degrees' => array_values($inDeg),
            'out_degrees' => array_values($outDeg),
            'max_depth' => $this->longestPath($adj, $n),
            'distinct_target_bucket' => $this->bucket(count($targets)),
            'seq0_no_deps' => $this->seqZeroHasNoDeps($nodes) ? 1 : 0,
        ];

        return [
            'hash' => substr(hash('sha256', json_encode($features, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)), 0, 24),
            'features' => $features,
            'node_count' => $n,
        ];
    }

    /**
     * Longest path in the (assumed-acyclic) DAG via memoized DFS; a visited guard caps any cycle so the
     * fingerprinter never hangs on a malformed plan (the validator rejects cycles upstream regardless).
     *
     * @param  array<int,list<int>>  $adj
     */
    private function longestPath(array $adj, int $n): int
    {
        if ($n <= 0) {
            return 0;
        }
        $memo = array_fill(0, $n, -1);
        $best = 0;
        for ($i = 0; $i < $n; $i++) {
            $best = max($best, $this->depthFrom($i, $adj, $memo, 0));
        }

        return $best;
    }

    /**
     * @param  array<int,list<int>>  $adj
     * @param  array<int,int>  $memo
     */
    private function depthFrom(int $node, array $adj, array &$memo, int $guard): int
    {
        if ($guard > self::DEPTH_CAP) {
            return self::DEPTH_CAP;
        }
        if (($memo[$node] ?? -1) >= 0) {
            return $memo[$node];
        }
        $best = 0;
        foreach ($adj[$node] ?? [] as $next) {
            $best = max($best, 1 + $this->depthFrom($next, $adj, $memo, $guard + 1));
        }

        return $memo[$node] = $best;
    }

    /**
     * Does the seq-0 node carry no dependencies (the create-class-root / leading-independent-node pattern)?
     *
     * @param  list<mixed>  $nodes
     */
    private function seqZeroHasNoDeps(array $nodes): bool
    {
        $seqZero = null;
        $minSeq = PHP_INT_MAX;
        foreach ($nodes as $i => $node) {
            if (! is_array($node)) {
                continue;
            }
            $seq = isset($node['seq']) ? (int) $node['seq'] : $i;
            if ($seq < $minSeq) {
                $minSeq = $seq;
                $seqZero = $node;
            }
        }

        return is_array($seqZero) && (array) ($seqZero['depends_on'] ?? []) === [];
    }

    /** Coarse count bucket: 0,1,2,3,"4-5","6-8","9+" — stable boundaries so the hash is reproducible. */
    private function bucket(int $count): string
    {
        return match (true) {
            $count <= 0 => '0',
            $count <= 3 => (string) $count,
            $count <= 5 => '4-5',
            $count <= 8 => '6-8',
            default => '9+',
        };
    }
}
