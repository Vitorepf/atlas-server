<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\TaskFabric;

/**
 * Pure graph compactor. Operates on a task dependency graph (nodes + directed edges)
 * and produces:
 *
 *   compacted_edges           — edges after transitive reduction.
 *   removed_redundant_edges   — edges that were redundant (transitive).
 *   terminal_prerequisites    — nodes that appear as dependencies (to) but have NO
 *                               prerequisites of their own (leaf nodes in the dep tree).
 *   missing_prerequisites     — edge 'to' values referencing nodes absent from the node list.
 *   dead_end_orphans          — nodes present in the node list but connected to NO edges
 *                               (isolated, neither a prerequisite for nor dependent on anything).
 *
 * Edge convention: {from: A, to: B} means "A depends on B" (B must complete before A).
 *
 * Transitive reduction (AC2 graph compaction):
 *   Edge A→C is redundant if C is reachable from A via at least one other path
 *   (i.e., through another direct dep of A).
 *   Uses BFS from each direct dep of A (excluding the candidate edge) to check reachability.
 *
 * AC2 distinction: missing_prerequisites (node absent from graph) are kept separate from
 * terminal_prerequisites (present leaf nodes) and dead_end_orphans (fully isolated nodes).
 *
 * Cycle detection: a DFS over the full node/edge set (independent of transitive reduction, which
 * only ever REMOVES edges and cannot itself detect a cycle) reports cycle_detected + the exact
 * back-edges (cycle_edges) that close the loop. A cyclic graph is NEVER treated as wave-ready:
 * dependency_layers is forced empty rather than silently producing a partial/incorrect ordering.
 *
 * dependency_layers (wave-ready strata, acyclic graphs only): Kahn's algorithm over the REAL node
 * set (edges into a missing/ghost node never block layering — that stays a distinct diagnostic,
 * per missing_prerequisites). Layer 0 = nodes with zero prerequisites; each subsequent layer
 * becomes ready once every prerequisite in an earlier layer is satisfied. Each layer is sorted
 * deterministically.
 *
 * NO process execution, NO filesystem, NO providers. DETERMINISTIC.
 */
final class AtlasTaskFabricDependencyGraphCompactor
{
    public const SCHEMA = 'atlas.task_fabric.dependency_graph_compactor.v1';

    /**
     * @param  array<string,mixed>  $facts
     * @return array<string,mixed>
     */
    public function compact(array $facts): array
    {
        $nodeList = is_array($facts['nodes'] ?? null) ? $facts['nodes'] : [];
        $edgeList = is_array($facts['edges'] ?? null) ? $facts['edges'] : [];
        $criticalPath = array_values(array_filter(
            array_map('strval', is_array($facts['critical_path'] ?? null) ? $facts['critical_path'] : []),
            static fn (string $id): bool => $id !== '',
        ));

        // Build node id set.
        $nodeIds = [];
        foreach ($nodeList as $n) {
            $id = (string) ($n['id'] ?? '');
            if ($id !== '') {
                $nodeIds[$id] = true;
            }
        }

        // Build adjacency list and track which ids appear as from/to.
        $adj           = [];
        $parsedEdges   = [];
        $fromSet       = [];
        $toSet         = [];

        foreach ($edgeList as $e) {
            $from = (string) ($e['from'] ?? '');
            $to   = (string) ($e['to'] ?? '');
            if ($from === '' || $to === '') {
                continue;
            }
            $adj[$from][]  = $to;
            $parsedEdges[] = ['from' => $from, 'to' => $to];
            $fromSet[$from] = true;
            $toSet[$to]     = true;
        }

        // Missing prerequisites: to values not in the node set.
        $missingPrereqs = [];
        foreach ($parsedEdges as $e) {
            if (! isset($nodeIds[$e['to']])) {
                $missingPrereqs[$e['to']] = true;
            }
        }
        $missingPrereqs = array_values(array_keys($missingPrereqs));

        // Terminal prerequisites: in nodeIds, appear as to but NOT as from.
        $terminalPrereqs = [];
        foreach (array_keys($nodeIds) as $id) {
            if (isset($toSet[$id]) && ! isset($fromSet[$id])) {
                $terminalPrereqs[] = $id;
            }
        }

        // Dead-end orphans: in nodeIds, appear in neither from nor to.
        $deadEndOrphans = [];
        foreach (array_keys($nodeIds) as $id) {
            if (! isset($fromSet[$id]) && ! isset($toSet[$id])) {
                $deadEndOrphans[] = $id;
            }
        }

        // Canonical circuit edges (consecutive pairs in critical_path) are NEVER treated as
        // redundant — the critical path must survive compaction even if it is also reachable
        // via another chain.
        $criticalPathEdges = [];
        for ($i = 0; $i < count($criticalPath) - 1; $i++) {
            $criticalPathEdges[$criticalPath[$i].'->'.$criticalPath[$i + 1]] = true;
        }

        // Transitive reduction.
        $compactedEdges = [];
        $removedEdges   = [];

        foreach ($parsedEdges as $e) {
            $from  = $e['from'];
            $to    = $e['to'];

            if (isset($criticalPathEdges[$from.'->'.$to])) {
                $compactedEdges[] = $e;
                continue;
            }

            $other = array_filter($adj[$from] ?? [], static fn (string $d): bool => $d !== $to);
            $reachableViaOthers = $this->reachableByBfs(array_values($other), $adj);

            if (isset($reachableViaOthers[$to])) {
                $removedEdges[] = $e;
            } else {
                $compactedEdges[] = $e;
            }
        }

        // critical_path_nodes: the declared canonical path, filtered to nodes that actually
        // exist in the graph, preserving the input's deterministic order.
        $criticalPathNodes = array_values(array_filter(
            $criticalPath,
            static fn (string $id): bool => isset($nodeIds[$id]),
        ));

        // Cycle detection is independent of transitive reduction (which only ever removes edges
        // and cannot detect a cycle by itself) — a cyclic graph is never wave-ready.
        [$cycleDetected, $cycleEdges] = $this->detectCycle(array_keys($nodeIds), $adj);

        $dependencyLayers = $cycleDetected
            ? []
            : $this->computeDependencyLayers(array_keys($nodeIds), $parsedEdges);

        return [
            'schema_version'           => self::SCHEMA,
            'compacted_edges'          => $compactedEdges,
            'removed_redundant_edges'  => $removedEdges,
            'terminal_prerequisites'   => $terminalPrereqs,
            'missing_prerequisites'    => $missingPrereqs,
            'dead_end_orphans'         => $deadEndOrphans,
            'node_count'               => count($nodeIds),
            'original_edge_count'      => count($parsedEdges),
            'compacted_edge_count'     => count($compactedEdges),
            'critical_path_nodes'      => $criticalPathNodes,
            'cycle_detected'           => $cycleDetected,
            'cycle_edges'              => $cycleEdges,
            'dependency_layers'        => $dependencyLayers,
        ];
    }

    /**
     * DFS-based cycle detection with a 3-color visited state. Returns every back-edge found (the
     * edges that close a cycle), not just the first one, so all offending edges are reported.
     *
     * @param  list<string>  $nodeIds
     * @param  array<string,list<string>>  $adj
     * @return array{0:bool, 1:list<array{from:string,to:string}>}
     */
    private function detectCycle(array $nodeIds, array $adj): array
    {
        $state = []; // node => 0 unvisited (implicit), 1 visiting, 2 done
        $cycleEdges = [];

        $visit = function (string $node) use (&$visit, &$state, $adj, &$cycleEdges): void {
            $state[$node] = 1;
            foreach ($adj[$node] ?? [] as $next) {
                $nextState = $state[$next] ?? 0;
                if ($nextState === 1) {
                    $cycleEdges[] = ['from' => $node, 'to' => $next];

                    continue;
                }
                if ($nextState === 0) {
                    $visit($next);
                }
            }
            $state[$node] = 2;
        };

        foreach ($nodeIds as $id) {
            if (($state[$id] ?? 0) === 0) {
                $visit($id);
            }
        }

        return [$cycleEdges !== [], $cycleEdges];
    }

    /**
     * Kahn's algorithm over the REAL node set to produce wave-ready dependency strata. An edge
     * whose 'to' target is not a real node (a missing prerequisite) never blocks layering — that
     * stays a distinct diagnostic (missing_prerequisites), not a layering failure.
     *
     * @param  list<string>  $nodeIds
     * @param  list<array{from:string,to:string}>  $parsedEdges
     * @return list<list<string>>
     */
    private function computeDependencyLayers(array $nodeIds, array $parsedEdges): array
    {
        $remaining = array_fill_keys($nodeIds, 0);
        $dependents = [];

        foreach ($parsedEdges as $e) {
            if (! array_key_exists($e['from'], $remaining) || ! array_key_exists($e['to'], $remaining)) {
                continue;
            }
            $remaining[$e['from']]++;
            $dependents[$e['to']][] = $e['from'];
        }

        $currentLayer = array_keys(array_filter($remaining, static fn (int $c): bool => $c === 0));
        sort($currentLayer, SORT_STRING);

        $layers = [];
        while ($currentLayer !== []) {
            $layers[] = $currentLayer;
            $nextLayer = [];
            foreach ($currentLayer as $node) {
                foreach ($dependents[$node] ?? [] as $dep) {
                    $remaining[$dep]--;
                    if ($remaining[$dep] === 0) {
                        $nextLayer[] = $dep;
                    }
                }
            }
            $nextLayer = array_values(array_unique($nextLayer));
            sort($nextLayer, SORT_STRING);
            $currentLayer = $nextLayer;
        }

        return $layers;
    }

    /**
     * BFS from multiple start nodes; returns set of all reachable node ids.
     *
     * @param  list<string>         $startNodes
     * @param  array<string,list<string>>  $adj
     * @return array<string,true>
     */
    private function reachableByBfs(array $startNodes, array $adj): array
    {
        $visited = [];
        $queue   = $startNodes;

        while ($queue !== []) {
            $node = array_shift($queue);
            if (isset($visited[$node])) {
                continue;
            }
            $visited[$node] = true;
            foreach ($adj[$node] ?? [] as $neighbor) {
                if (! isset($visited[$neighbor])) {
                    $queue[] = $neighbor;
                }
            }
        }

        return $visited;
    }
}
