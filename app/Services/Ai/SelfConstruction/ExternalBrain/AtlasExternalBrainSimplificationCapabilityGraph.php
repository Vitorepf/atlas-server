<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Capability dependency graph: compression order should follow what actually depends on what,
 * not file path or line count. A capability that lists another as `depends_on` needs that other
 * capability preserved until it is itself handled — so the dependent is ordered BEFORE the
 * capability it depends on, preventing a support capability from being deleted or simplified
 * while a dependent still needs it. A graph with a dependency cycle, or any capability missing
 * an owner, is never silently ordered anyway — it holds with the exact blockers named.
 *
 * Input contract:
 *   capabilities: list<array{
 *     id?:          string,
 *     owner?:       string,
 *     depends_on?:  list<string>,   (ids of OTHER capabilities this one needs preserved first)
 *   }>
 *
 * Pure PHP, deterministic, no I/O.
 */
final class AtlasExternalBrainSimplificationCapabilityGraph
{
    public const SCHEMA = 'atlas.external_brain.simplification_capability_graph.v1';

    public const DECISION_READY = 'ready';
    public const DECISION_HOLD  = 'hold';

    /**
     * @param  array{capabilities?: list<array<string,mixed>>}  $facts
     * @return array{schema:string, decision:string, nodes:list<string>, edges:list<array{from:string,to:string}>, safe_order:list<string>, blockers:list<string>}
     */
    public function build(array $facts): array
    {
        $capabilities = is_array($facts['capabilities'] ?? null) ? $facts['capabilities'] : [];

        $nodes = [];
        $owners = [];
        $dependsOnById = [];
        $edges = [];
        $blockers = [];

        foreach ($capabilities as $capability) {
            if (! is_array($capability)) {
                continue;
            }
            $id = trim((string) ($capability['id'] ?? ''));
            if ($id === '') {
                continue;
            }
            $nodes[] = $id;
            $owners[$id] = trim((string) ($capability['owner'] ?? ''));
            $dependsOnById[$id] = array_values(array_filter(array_map('strval', (array) ($capability['depends_on'] ?? []))));
        }

        foreach ($nodes as $id) {
            if ($owners[$id] === '') {
                $blockers[] = "missing_owner:{$id}";
            }
        }

        foreach ($dependsOnById as $from => $dependsOn) {
            foreach ($dependsOn as $to) {
                if (! in_array($to, $nodes, true)) {
                    continue; // unknown target — not a graph edge we can order
                }
                $edges[] = ['from' => $from, 'to' => $to];
            }
        }

        [$safeOrder, $cycleNodes] = $this->topologicalOrder($nodes, $edges);

        if ($cycleNodes !== []) {
            sort($cycleNodes);
            $blockers[] = 'cycle_involving:'.implode(',', $cycleNodes);
        }

        return [
            'schema'      => self::SCHEMA,
            'decision'    => $blockers === [] ? self::DECISION_READY : self::DECISION_HOLD,
            'nodes'       => $nodes,
            'edges'       => $edges,
            'safe_order'  => $blockers === [] ? $safeOrder : [],
            'blockers'    => $blockers,
        ];
    }

    /**
     * Kahn's algorithm. Edge {from, to} means `from` must precede `to` in the output order.
     *
     * @param  list<string>  $nodes
     * @param  list<array{from:string,to:string}>  $edges
     * @return array{0:list<string>, 1:list<string>} [safe_order, unresolved_cycle_nodes]
     */
    private function topologicalOrder(array $nodes, array $edges): array
    {
        $inDegree = array_fill_keys($nodes, 0);
        $adjacency = array_fill_keys($nodes, []);

        foreach ($edges as $edge) {
            $adjacency[$edge['from']][] = $edge['to'];
            $inDegree[$edge['to']]++;
        }

        $queue = array_values(array_filter($nodes, static fn (string $n): bool => $inDegree[$n] === 0));
        sort($queue);

        $order = [];
        while ($queue !== []) {
            $current = array_shift($queue);
            $order[] = $current;

            $freed = [];
            foreach ($adjacency[$current] as $neighbor) {
                $inDegree[$neighbor]--;
                if ($inDegree[$neighbor] === 0) {
                    $freed[] = $neighbor;
                }
            }
            sort($freed);
            $queue = array_merge($queue, $freed);
        }

        $unresolved = array_values(array_diff($nodes, $order));

        return [$order, $unresolved];
    }
}
