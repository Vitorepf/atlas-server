<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Active;

use InvalidArgumentException;

/**
 * CORTEX · ACTIVE — the SINGLE deterministic call-graph primitive. Given an entry symbol and a max depth N,
 * it projects the caller closure (or callee closure when `reverse=false`) by traversing a fixture/index of the
 * code-intelligence graph and emits a FROZEN DAG record:
 *
 *   { schema, entry, direction, depth, nodes:[{symbol_id,file_line,role,depth}],
 *     edges:[{from,to,edge_kind}], truncated:[{at_symbol,depth_reached}] }
 *
 * Determinism is the contract — same fixture + same {entry, depth, direction} ⇒ byte-identical record across
 * two runs (lists sorted by primary key, BFS visits neighbors in lexicographic order). The depth cap is HARD
 * (from $maxDepthFromConfig if provided, else config(`atlas.cortex.active.call_graph.max_depth`, 8)); the
 * boundary frontier emits DEPTH_TRUNCATED sentinels so consumers (cortex-active-01/02/04) know the projection
 * was clipped — never a silent narrowing. A negative or non-integer depth is rejected fail-closed.
 *
 * The index is a plain array adjacency map (so the projector is testable without a live index):
 *   index = { 'callers' => [symbol => list<symbol>], 'callees' => [symbol => list<symbol>], 'symbols' => [symbol => ['file_line'=>'path:line','role'=>'class'|'method'|'function']] }
 */
final class AtlasCortexCallGraphProjector
{
    public const SCHEMA = 'atlas.cortex.active.call_graph.v1';

    public const DEPTH_TRUNCATED = 'DEPTH_TRUNCATED';

    public const DEFAULT_MAX_DEPTH = 8;

    public function __construct(private readonly ?int $maxDepthFromConfig = null) {}

    /**
     * Project the caller (reverse=true) OR callee (reverse=false) closure from $entry, BFS-bounded by $depth.
     *
     * @param  array{callers?:array<string,list<string>>, callees?:array<string,list<string>>, symbols?:array<string,array{file_line?:string,role?:string}>}  $index
     * @return array{schema:string, entry:string, direction:string, depth:int, nodes:list<array{symbol_id:string,file_line:string,role:string,depth:int}>, edges:list<array{from:string,to:string,edge_kind:string}>, truncated:list<array{at_symbol:string,depth_reached:int,sentinel:string}>}
     */
    public function project(string $entry, int $depth, bool $reverse, array $index): array
    {
        $this->assertNonNegativeIntDepth($depth);
        $cap = $this->resolveMaxDepth();
        $effectiveDepth = min($depth, $cap);

        $direction = $reverse ? 'callers' : 'callees';
        $adjacency = (array) ($index[$direction] ?? []);
        $symbols = (array) ($index['symbols'] ?? []);

        $entry = trim($entry);

        $nodes = [];
        $edges = [];
        $truncated = [];
        $visited = [$entry => 0];
        $nodes[$entry] = $this->node($entry, $symbols, 0);

        if ($effectiveDepth === 0) {
            return $this->freeze($entry, $direction, $effectiveDepth, $nodes, $edges, $truncated);
        }

        // BFS layer by layer so depth bookkeeping is exact and the truncation frontier is precise.
        $frontier = [$entry];
        for ($layer = 1; $layer <= $effectiveDepth; $layer++) {
            $next = [];
            sort($frontier, SORT_STRING);
            foreach ($frontier as $from) {
                $neighbors = array_values(array_unique(array_map('strval', (array) ($adjacency[$from] ?? []))));
                sort($neighbors, SORT_STRING);
                foreach ($neighbors as $to) {
                    $to = trim($to);
                    if ($to === '') {
                        continue;
                    }
                    if (! isset($visited[$to])) {
                        $visited[$to] = $layer;
                        $nodes[$to] = $this->node($to, $symbols, $layer);
                        $next[] = $to;
                    }
                    $edgeKey = $from.'==>'.$to;
                    if (! isset($edges[$edgeKey])) {
                        $edges[$edgeKey] = ['from' => $from, 'to' => $to, 'edge_kind' => $direction];
                    }
                }
            }
            $frontier = $next;
        }

        // Anything ONE STEP beyond the cap becomes a DEPTH_TRUNCATED sentinel — the boundary the caller asked
        // about but we refused to walk.
        sort($frontier, SORT_STRING);
        foreach ($frontier as $boundary) {
            $beyond = array_values(array_unique(array_map('strval', (array) ($adjacency[$boundary] ?? []))));
            sort($beyond, SORT_STRING);
            foreach ($beyond as $symbol) {
                $symbol = trim($symbol);
                if ($symbol === '' || isset($visited[$symbol])) {
                    continue;
                }
                $truncated[$boundary.'@'.$symbol] = [
                    'at_symbol' => $symbol,
                    'depth_reached' => $effectiveDepth + 1,
                    'sentinel' => self::DEPTH_TRUNCATED,
                ];
            }
        }

        return $this->freeze($entry, $direction, $effectiveDepth, $nodes, $edges, $truncated);
    }

    private function assertNonNegativeIntDepth(int $depth): void
    {
        if ($depth < 0) {
            throw new InvalidArgumentException('AtlasCortexCallGraphProjector::project depth must be >= 0, got '.$depth);
        }
    }

    private function resolveMaxDepth(): int
    {
        if ($this->maxDepthFromConfig !== null) {
            return max(0, $this->maxDepthFromConfig);
        }

        return max(0, (int) config('atlas.cortex.active.call_graph.max_depth', self::DEFAULT_MAX_DEPTH));
    }

    /**
     * @param  array<string,array{file_line?:string,role?:string}>  $symbols
     * @return array{symbol_id:string,file_line:string,role:string,depth:int}
     */
    private function node(string $symbol, array $symbols, int $depth): array
    {
        $meta = (array) ($symbols[$symbol] ?? []);

        return [
            'symbol_id' => $symbol,
            'file_line' => (string) ($meta['file_line'] ?? ''),
            'role' => (string) ($meta['role'] ?? 'unknown'),
            'depth' => $depth,
        ];
    }

    /**
     * Topologically frozen output: nodes sorted by (depth, symbol_id); edges by (from, to); truncated by
     * (at_symbol). The same {entry, depth, direction, fixture} always serializes to the same bytes.
     *
     * @param  array<string,array<string,mixed>>  $nodes
     * @param  array<string,array<string,mixed>>  $edges
     * @param  array<string,array<string,mixed>>  $truncated
     * @return array<string,mixed>
     */
    private function freeze(string $entry, string $direction, int $depth, array $nodes, array $edges, array $truncated): array
    {
        $nodeList = array_values($nodes);
        usort($nodeList, static function (array $a, array $b): int {
            return [$a['depth'], $a['symbol_id']] <=> [$b['depth'], $b['symbol_id']];
        });

        $edgeList = array_values($edges);
        usort($edgeList, static fn (array $a, array $b): int => [$a['from'], $a['to']] <=> [$b['from'], $b['to']]);

        $truncatedList = array_values($truncated);
        usort($truncatedList, static fn (array $a, array $b): int => $a['at_symbol'] <=> $b['at_symbol']);

        return [
            'schema' => self::SCHEMA,
            'entry' => $entry,
            'direction' => $direction,
            'depth' => $depth,
            'nodes' => $nodeList,
            'edges' => $edgeList,
            'truncated' => $truncatedList,
        ];
    }
}
