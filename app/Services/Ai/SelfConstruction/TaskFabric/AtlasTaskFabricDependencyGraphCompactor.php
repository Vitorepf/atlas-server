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

        // Transitive reduction.
        $compactedEdges = [];
        $removedEdges   = [];

        foreach ($parsedEdges as $e) {
            $from  = $e['from'];
            $to    = $e['to'];
            $other = array_filter($adj[$from] ?? [], static fn (string $d): bool => $d !== $to);
            $reachableViaOthers = $this->reachableByBfs(array_values($other), $adj);

            if (isset($reachableViaOthers[$to])) {
                $removedEdges[] = $e;
            } else {
                $compactedEdges[] = $e;
            }
        }

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
        ];
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
