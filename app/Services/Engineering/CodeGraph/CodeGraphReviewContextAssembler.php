<?php

declare(strict_types=1);

namespace App\Services\Engineering\CodeGraph;

/**
 * AP-815 · I-3 — diff/PR → review-context assembler.
 *
 * Given the symbol nodes a change TOUCHES, this composes the precise review context:
 * the changed nodes themselves + their BLAST-RADIUS (reverse-reachability — "who depends
 * on what I changed", i.e. what could break), then hands the ranked set to the E-3
 * token-budget assembler (CodeGraphContextPackAssembler) so a reviewer/agent gets exactly
 * the right context for the diff, nothing more. Composes already-green AP-815 blocks; pure
 * + deterministic + fail-safe. [php] Kernel orchestration.
 */
class CodeGraphReviewContextAssembler
{
    public const SCHEMA = 'atlas.code_graph.review_context.v1';

    public function __construct(private readonly CodeGraphContextPackAssembler $assembler) {}

    /**
     * @param  array<int,string>            $changedNodeIds  symbol node ids the diff touches
     * @param  array<int,array<string,mixed>>  $edges        graph edges (from_node_id/from, to_node_id/to)
     * @param  array<string,array<string,mixed>>  $nodeMeta   nodeId => ['tokens'=>int,'signature'=>string,...]
     * @param  array<string,mixed>          $opts
     * @return array{schema_version:string,changed:array<int,string>,blast_radius:array<int,string>,pack:array<string,mixed>,stats:array<string,int>}
     */
    public function assemble(array $changedNodeIds, array $edges, array $nodeMeta, int $tokenBudget, array $opts = []): array
    {
        $changed = $this->cleanIds($changedNodeIds);
        $depth = $this->depth($opts);
        $incoming = $this->incomingMap($edges);

        $blast = $this->blastRadius($changed, $incoming, $depth);

        // Rank: changed nodes first (highest relevance to the review), then blast-radius.
        $rankedIds = array_values(array_unique(array_merge($changed, $blast)));
        $ranked = array_map(fn (string $id): array => $this->node($id, $nodeMeta), $rankedIds);

        $pack = $this->assembler->assemble($ranked, $tokenBudget, $opts);

        return [
            'schema_version' => self::SCHEMA,
            'changed' => $changed,
            'blast_radius' => $blast,
            'pack' => $pack,
            'stats' => [
                'changed' => count($changed),
                'blast_radius' => count($blast),
                'candidates' => count($rankedIds),
                'included' => is_array($pack['included'] ?? null) ? count($pack['included']) : 0,
                'depth' => $depth,
            ],
        ];
    }

    /**
     * Reverse-reachability: starting from changed nodes, walk INCOMING edges up to $depth
     * hops — these are the nodes that depend on the change (the things that can break).
     *
     * @param  array<int,string>  $changed
     * @param  array<string,array<int,string>>  $incoming
     * @return array<int,string>
     */
    private function blastRadius(array $changed, array $incoming, int $depth): array
    {
        $changedSet = array_fill_keys($changed, true);
        $seen = $changedSet;
        $frontier = $changed;

        for ($hop = 0; $hop < $depth && $frontier !== []; $hop++) {
            $next = [];
            foreach ($frontier as $node) {
                foreach ($incoming[$node] ?? [] as $dependent) {
                    if (! isset($seen[$dependent])) {
                        $seen[$dependent] = true;
                        $next[] = $dependent;
                    }
                }
            }
            $frontier = $next;
        }

        // blast = everything reached MINUS the changed nodes themselves.
        $blast = array_values(array_filter(array_keys($seen), static fn (string $id): bool => ! isset($changedSet[$id])));
        sort($blast);

        return $blast;
    }

    /**
     * @param  array<int,array<string,mixed>>  $edges
     * @return array<string,array<int,string>>  toNode => [fromNode, ...]
     */
    private function incomingMap(array $edges): array
    {
        $incoming = [];
        foreach ($edges as $edge) {
            if (! is_array($edge)) {
                continue;
            }
            $from = $this->edgeEnd($edge, ['from_node_id', 'from']);
            $to = $this->edgeEnd($edge, ['to_node_id', 'to']);
            if ($from === '' || $to === '' || $from === $to) {
                continue;
            }
            $incoming[$to][$from] = $from; // dedup
        }

        return array_map('array_values', $incoming);
    }

    /**
     * @param  array<string,mixed>  $edge
     * @param  array<int,string>  $keys
     */
    private function edgeEnd(array $edge, array $keys): string
    {
        foreach ($keys as $k) {
            if (isset($edge[$k]) && is_scalar($edge[$k]) && trim((string) $edge[$k]) !== '') {
                return trim((string) $edge[$k]);
            }
        }

        return '';
    }

    /**
     * @param  array<string,array<string,mixed>>  $nodeMeta
     * @return array<string,mixed>
     */
    private function node(string $id, array $nodeMeta): array
    {
        $meta = isset($nodeMeta[$id]) && is_array($nodeMeta[$id]) ? $nodeMeta[$id] : [];
        $meta['id'] = $id;

        return $meta;
    }

    /**
     * @param  array<int,string>  $ids
     * @return array<int,string>
     */
    private function cleanIds(array $ids): array
    {
        $out = [];
        foreach ($ids as $id) {
            if (is_scalar($id) && trim((string) $id) !== '') {
                $out[trim((string) $id)] = true;
            }
        }

        return array_keys($out);
    }

    /**
     * @param  array<string,mixed>  $opts
     */
    private function depth(array $opts): int
    {
        $d = $opts['blast_depth'] ?? config('atlas.code_graph.blast_depth', 1);
        $d = is_numeric($d) ? (int) $d : 1;

        return max(0, $d);
    }
}
