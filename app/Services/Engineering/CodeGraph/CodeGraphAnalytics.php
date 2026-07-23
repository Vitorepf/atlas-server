<?php

namespace App\Services\Engineering\CodeGraph;


/**
 * Read-only analytics over resolved code-graph edges (AP-811 P-7 / P-9).
 *
 * Pure transforms — no DB, no IO, no provider, no Python runtime. These are the
 * Kernel/PHP graph operations that do NOT require the python_ai_data runtime
 * (which is reserved, behind human review, for heavy graph: Leiden communities,
 * centrality at scale, ML). Degree-based god-nodes and reverse-BFS blast-radius
 * run cheaply in PHP over the existing ai_codebase_world_model_edges set.
 *
 * @phpstan-type Edge array{from_node_id:string, to_node_id:string, edge_type?:string}
 */
class CodeGraphAnalytics
{
    public const SCHEMA = 'atlas.code_graph.analytics.v1';

    /**
     * God nodes = degree centrality. The most-connected nodes are architectural
     * hubs; changing them has the widest blast radius. Pure degree (in+out),
     * not betweenness — matching the honest P-7 scope (Leiden/centrality-at-scale
     * stay in the gated python_ai_data runtime).
     *
     * @param  array<int,array<string,mixed>>  $edges
     * @return array{schema_version:string, god_nodes:array<int,array{node_id:string,degree:int,in_degree:int,out_degree:int}>}
     */
    public function godNodes(array $edges, int $limit = 20): array
    {
        $in = [];
        $out = [];
        $seen = [];

        foreach ($edges as $edge) {
            $from = $this->nodeId($edge['from_node_id'] ?? null);
            $to = $this->nodeId($edge['to_node_id'] ?? null);
            if ($from === null || $to === null) {
                continue;
            }
            $out[$from] = ($out[$from] ?? 0) + 1;
            $in[$to] = ($in[$to] ?? 0) + 1;
            $seen[$from] = true;
            $seen[$to] = true;
        }

        $ranked = [];
        foreach (array_keys($seen) as $node) {
            $inDeg = $in[$node] ?? 0;
            $outDeg = $out[$node] ?? 0;
            $ranked[] = [
                'node_id' => $node,
                'degree' => $inDeg + $outDeg,
                'in_degree' => $inDeg,
                'out_degree' => $outDeg,
            ];
        }

        usort(
            $ranked,
            static fn (array $a, array $b): int => $b['degree'] <=> $a['degree']
                ?: strcmp($a['node_id'], $b['node_id']),
        );

        return [
            'schema_version' => self::SCHEMA,
            'god_nodes' => array_slice($ranked, 0, max(1, $limit)),
        ];
    }

    /**
     * Blast radius = "if I change <seed>, what breaks?". Reverse BFS following
     * INCOMING edges (dependents), bounded by depth and node budget. Each hit
     * carries the depth and the edge_type it was reached through.
     *
     * @param  array<int,array<string,mixed>>  $edges
     * @return array{schema_version:string, seed:string, truncated:bool, affected:array<int,array{node_id:string,depth:int,via:string}>}
     */
    public function blastRadius(array $edges, string $seed, int $maxDepth = 4, int $maxNodes = 60): array
    {
        // Reverse adjacency: target -> [{from, edge_type}] (who points AT a node).
        $incoming = [];
        foreach ($edges as $edge) {
            $from = $this->nodeId($edge['from_node_id'] ?? null);
            $to = $this->nodeId($edge['to_node_id'] ?? null);
            if ($from === null || $to === null) {
                continue;
            }
            $incoming[$to][] = ['from' => $from, 'via' => $this->edgeType($edge['edge_type'] ?? null)];
        }

        $seed = trim($seed);
        $visited = [$seed => true];
        $affected = [];
        $truncated = false;

        /** @var array<int,array{node:string,depth:int}> $queue */
        $queue = [['node' => $seed, 'depth' => 0]];
        while ($queue !== []) {
            $current = array_shift($queue);
            if ($current['depth'] >= $maxDepth) {
                continue;
            }
            foreach ($incoming[$current['node']] ?? [] as $dependent) {
                $node = $dependent['from'];
                if (isset($visited[$node])) {
                    continue;
                }
                if (count($affected) >= $maxNodes) {
                    $truncated = true;
                    break 2;
                }
                $visited[$node] = true;
                $depth = $current['depth'] + 1;
                $affected[] = ['node_id' => $node, 'depth' => $depth, 'via' => $dependent['via']];
                $queue[] = ['node' => $node, 'depth' => $depth];
            }
        }

        usort(
            $affected,
            static fn (array $a, array $b): int => $a['depth'] <=> $b['depth']
                ?: strcmp($a['node_id'], $b['node_id']),
        );

        return [
            'schema_version' => self::SCHEMA,
            'seed' => $seed,
            'truncated' => $truncated,
            'affected' => $affected,
        ];
    }

    private function nodeId(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function edgeType(mixed $value): string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : 'depends_on';
    }
}
