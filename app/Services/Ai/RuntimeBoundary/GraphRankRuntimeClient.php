<?php

declare(strict_types=1);

namespace App\Services\Ai\RuntimeBoundary;

/**
 * PHP adapter to the REAL Python world-model graph-ranking data runtime
 * (runtimes/python/graph_rank). By the runtime_language_boundary canon — and the
 * operator thesis "Python é o melhor para dados; nunca faça em PHP o que deveria
 * ser Python" — the PHP kernel must NOT hand-roll the node-centrality /
 * edge-weight propagation / incoming-outgoing scoring; it invokes this Python
 * runtime (networkx + numpy) instead. This client is the governed bridge: it
 * writes a manifest, runs the runtime under its own venv (where networkx/numpy
 * live), parses the receipt, and REFUSES any result that is not real, in-Python
 * networkx+numpy (boundary.graph_rank_in_python !== true) — so a PHP stand-in can
 * never pass back through the boundary.
 *
 * Mirrors StatsEngineRuntimeClient / NearDuplicateRuntimeClient EXACTLY (manifest
 * temp-file, single Process invocation of .venv/bin/python main.py, receipt
 * enforcement). There is NO PHP fallback math by design: if the runtime is not
 * set up the client raises explicitly (run scripts/setup-graph-rank-runtime.sh).
 * That is the canon: a real Python engine or an honest failure, never a
 * hand-rolled stand-in.
 *
 * WorldModelGraphRanker is read-only and runs off the synchronous request hot
 * path (retrieval / planner / MCP / CLI), so a per-invocation subprocess is
 * acceptable per the create-path perf memory; the whole ranking is computed in
 * ONE subprocess.
 */
final class GraphRankRuntimeClient
{
    use PythonManifestRuntimeMechanics;

    private const RUNTIME_ROOT = 'runtimes/python/graph_rank';

    private const MANIFEST_PREFIX = 'atlas-graph-rank';

    private const SETUP_MESSAGE = 'graph_rank Python runtime is not set up — run scripts/setup-graph-rank-runtime.sh. '
        .'The canon forbids a PHP graph-ranking fallback; this is an explicit failure, not a silent hand-rolled stand-in.';

    private const RUNTIME_LABEL = 'graph_rank';

    private const BOUNDARY_REQUIRED_TRUE = ['graph_rank_in_python', 'real_graph_math'];

    private const BOUNDARY_REQUIRED_FALSE = ['fabricated'];

    private const BOUNDARY_REFUSAL = 'graph_rank returned a non-real-engine boundary receipt — refusing (anti-fake guard).';

    /**
     * Rank world-model nodes by combining textual match with graph evidence
     * (node centrality, edge-weight propagation, incoming/outgoing scoring),
     * computed entirely in networkx + numpy.
     *
     * Each node is ['node_id' => string, 'node_type' => string, 'path' =>
     * string|null, 'flow_id' => string|null, 'capabilities' => list<string>,
     * 'risks' => list<string>]. Each edge is ['from_node_id' => string,
     * 'to_node_id' => string, 'edge_type' => string]. The query carries the
     * (already-lower-cased) anchors. Returns the per-node math + structured boost
     * decisions in final ranked order plus the ranked node-id order — minus the
     * boundary receipt (verified and stripped here).
     *
     * @param  list<array<string,mixed>>  $nodes
     * @param  list<array<string,mixed>>  $edges
     * @param  array<string,mixed>  $query
     * @return array<string,mixed>
     */
    public function rank(array $nodes, array $edges, array $query): array
    {
        $result = $this->run([
            'operation' => 'rank',
            'nodes' => array_values($nodes),
            'edges' => array_values($edges),
            'query' => $query,
        ]);

        // The boundary receipt has been verified in run(); strip it so callers see
        // a clean ranking shape (no boundary key).
        unset($result['boundary']);

        return $result;
    }

    /**
     * Compute a shadow Personalized PageRank ordering for a traversed graph and
     * compare it with the caller's BFS baseline. This is a dual-read operation:
     * callers must not use it to replace live ordering without an explicit flip.
     *
     * @param  list<array<string,mixed>>  $nodes
     * @param  list<array<string,mixed>>  $edges
     * @param  array<string,mixed>  $query
     * @param  list<string>  $baselineOrder
     * @param  list<string>  $targets
     * @return array<string,mixed>
     */
    public function pprShadow(array $nodes, array $edges, array $query, array $baselineOrder, array $targets = []): array
    {
        $result = $this->run([
            'operation' => 'ppr_shadow',
            'nodes' => array_values($nodes),
            'edges' => array_values($edges),
            'query' => $query,
            'baseline_order' => array_values($baselineOrder),
            'targets' => array_values($targets),
        ]);

        unset($result['boundary']);

        return $result;
    }
}
