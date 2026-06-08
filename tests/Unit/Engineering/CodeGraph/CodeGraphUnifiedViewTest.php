<?php

namespace Tests\Unit\Engineering\CodeGraph;

use App\Services\Engineering\CodeGraph\CodeGraphUnifiedView;
use PHPUnit\Framework\TestCase;

class CodeGraphUnifiedViewTest extends TestCase
{
    /**
     * A code-graph edge in the shape {@see \App\Services\Engineering\CodeGraph\CodeGraphEdgeResolver} emits.
     *
     * @return array<string,mixed>
     */
    private function codeEdge(string $from, string $to, string $type = 'depends_on', ?string $sourceRef = null): array
    {
        return [
            'from_node_id' => $from,
            'to_node_id' => $to,
            'edge_type' => $type,
            'confidence' => 'EXTRACTED',
            'confidence_score' => 1.0,
            'metadata' => array_filter([
                'resolver' => 'atlas.code_graph.resolved_edges.v1',
                'relation' => 'dependency',
                'source_ref' => $sourceRef,
                'occurrences' => 1,
            ], static fn ($v) => $v !== null),
        ];
    }

    /**
     * An AURG reality edge: same {from,to,edge_type} shape, docs/memory/evidence/mission relation.
     *
     * @return array<string,mixed>
     */
    private function realityEdge(string $from, string $to, string $type, ?string $sourceRef = null): array
    {
        return [
            'from_node_id' => $from,
            'to_node_id' => $to,
            'edge_type' => $type,
            'metadata' => array_filter([
                'aurg' => 'atlas.universal_reality_graph',
                'relation' => $type,
                'source_ref' => $sourceRef,
            ], static fn ($v) => $v !== null),
        ];
    }

    public function test_code_and_reality_edges_merge_with_correct_layer_tags(): void
    {
        $codeEdges = [
            $this->codeEdge('node:app/Services/Router', 'node:app/Services/Decide'),
        ];
        // The reality side of the marquee query: the code module is governed by a
        // decision, which is proven by an evidence record, surfaced by a doc.
        $aurgEdges = [
            $this->realityEdge('node:app/Services/Router', 'node:decision/router-policy', 'governed_by'),
            $this->realityEdge('node:decision/router-policy', 'node:evidence/ledger-42', 'proven_by'),
            $this->realityEdge('node:doc/router', 'node:app/Services/Router', 'documents'),
        ];

        $result = (new CodeGraphUnifiedView)->unify($codeEdges, $aurgEdges);

        $this->assertSame(CodeGraphUnifiedView::SCHEMA, $result['schema_version']);
        $this->assertCount(4, $result['edges']);

        $byLayer = [];
        foreach ($result['edges'] as $edge) {
            $this->assertArrayHasKey('layer', $edge['metadata']);
            $byLayer[$edge['metadata']['layer']][] = $edge['edge_type'];
        }

        $this->assertCount(1, $byLayer[CodeGraphUnifiedView::LAYER_CODE]);
        $this->assertSame(['depends_on'], $byLayer[CodeGraphUnifiedView::LAYER_CODE]);
        $this->assertCount(3, $byLayer[CodeGraphUnifiedView::LAYER_REALITY]);
        // Code layer comes first, reality layer after (block ordering is stable).
        $this->assertSame(CodeGraphUnifiedView::LAYER_CODE, $result['edges'][0]['metadata']['layer']);
        $this->assertSame(CodeGraphUnifiedView::LAYER_REALITY, $result['edges'][1]['metadata']['layer']);

        // The schema breadcrumb is stamped so a consumer can attribute the merge.
        $this->assertSame(CodeGraphUnifiedView::SCHEMA, $result['edges'][0]['metadata']['unified_view']);
    }

    public function test_runtime_proven_overlay_applied_across_both_layers(): void
    {
        // One proven code edge (exact path:line) and one proven reality edge
        // (file-grained match); the rest unproven.
        $codeEdges = [
            $this->codeEdge('node:a', 'node:b', sourceRef: 'app/Services/Foo.php:42'),
            $this->codeEdge('node:c', 'node:d', sourceRef: 'app/Services/Bar.php:7'),
        ];
        $aurgEdges = [
            $this->realityEdge('node:a', 'node:dec', 'governed_by', sourceRef: 'docs/router.md:10'),
            $this->realityEdge('node:dec', 'node:ev', 'proven_by', sourceRef: 'docs/other.md:99'),
        ];
        $proven = [
            'app/Services/Foo.php:42',   // exact line match (code layer)
            'docs/router.md',            // file-grained match (reality layer)
        ];

        $result = (new CodeGraphUnifiedView)->unify($codeEdges, $aurgEdges, $proven);

        $provenByNode = [];
        foreach ($result['edges'] as $edge) {
            $this->assertArrayHasKey('runtime_proven', $edge['metadata']);
            $provenByNode[$edge['from_node_id'].'->'.$edge['to_node_id']] = $edge['metadata']['runtime_proven'];
        }

        $this->assertTrue($provenByNode['node:a->node:b']);    // code, exact line
        $this->assertFalse($provenByNode['node:c->node:d']);   // code, not in set
        $this->assertTrue($provenByNode['node:a->node:dec']);  // reality, file-grained
        $this->assertFalse($provenByNode['node:dec->node:ev']); // reality, not in set
    }

    public function test_stats_are_correct(): void
    {
        $codeEdges = [
            $this->codeEdge('node:a', 'node:b', sourceRef: 'app/A.php:1'),
            $this->codeEdge('node:b', 'node:c', sourceRef: 'app/B.php:2'),
        ];
        $aurgEdges = [
            $this->realityEdge('node:a', 'node:d', 'governed_by', sourceRef: 'docs/d.md:1'),
            $this->realityEdge('node:d', 'node:e', 'proven_by'),
            $this->realityEdge('node:e', 'node:f', 'documents'),
        ];
        // One code ref proven + one reality ref proven => proven = 2.
        $proven = ['app/A.php:1', 'docs/d.md:1'];

        $result = (new CodeGraphUnifiedView)->unify($codeEdges, $aurgEdges, $proven);

        $this->assertSame(2, $result['stats']['code']);
        $this->assertSame(3, $result['stats']['reality']);
        $this->assertSame(2, $result['stats']['proven']);
        $this->assertSame(5, $result['stats']['total']);
        $this->assertSame($result['stats']['code'] + $result['stats']['reality'], $result['stats']['total']);
    }

    public function test_no_cross_layer_dedup_same_nodes_distinct_facts(): void
    {
        // A code depends_on and a reality depends_on between the SAME two nodes are
        // distinct facts (structure vs governance) and must both survive.
        $codeEdges = [$this->codeEdge('node:x', 'node:y', 'depends_on')];
        $aurgEdges = [$this->realityEdge('node:x', 'node:y', 'depends_on')];

        $result = (new CodeGraphUnifiedView)->unify($codeEdges, $aurgEdges);

        $this->assertCount(2, $result['edges']);
        $layers = array_map(static fn ($e) => $e['metadata']['layer'], $result['edges']);
        $this->assertContains(CodeGraphUnifiedView::LAYER_CODE, $layers);
        $this->assertContains(CodeGraphUnifiedView::LAYER_REALITY, $layers);
    }

    public function test_deterministic_regardless_of_input_order(): void
    {
        $codeEdges = [
            $this->codeEdge('node:c', 'node:d'),
            $this->codeEdge('node:a', 'node:b'),
        ];
        $aurgEdges = [
            $this->realityEdge('node:z', 'node:w', 'governed_by'),
            $this->realityEdge('node:m', 'node:n', 'proven_by'),
        ];

        $first = (new CodeGraphUnifiedView)->unify($codeEdges, $aurgEdges);
        // Shuffle both inputs; output must be byte-identical.
        $second = (new CodeGraphUnifiedView)->unify(
            array_reverse($codeEdges),
            array_reverse($aurgEdges),
        );

        $this->assertSame($first, $second);
        // And the within-layer ordering is the sorted (from,to,type) order.
        $codeOrder = [];
        foreach ($first['edges'] as $edge) {
            if ($edge['metadata']['layer'] === CodeGraphUnifiedView::LAYER_CODE) {
                $codeOrder[] = $edge['from_node_id'];
            }
        }
        $this->assertSame(['node:a', 'node:c'], $codeOrder);
    }

    public function test_keyed_proven_set_form_is_accepted(): void
    {
        $codeEdges = [$this->codeEdge('node:a', 'node:b', sourceRef: 'app/A.php:1')];
        $aurgEdges = [$this->realityEdge('node:a', 'node:d', 'governed_by', sourceRef: 'docs/d.md:1')];
        // Keyed set: truthy proves, falsy does not.
        $proven = ['app/A.php:1' => true, 'docs/d.md:1' => false];

        $result = (new CodeGraphUnifiedView)->unify($codeEdges, $aurgEdges, $proven);

        $byNode = [];
        foreach ($result['edges'] as $edge) {
            $byNode[$edge['from_node_id'].'->'.$edge['to_node_id']] = $edge['metadata']['runtime_proven'];
        }
        $this->assertTrue($byNode['node:a->node:b']);
        $this->assertFalse($byNode['node:a->node:d']);
        $this->assertSame(1, $result['stats']['proven']);
    }

    public function test_empty_inputs_yield_empty_view(): void
    {
        $result = (new CodeGraphUnifiedView)->unify([], [], []);

        $this->assertSame(CodeGraphUnifiedView::SCHEMA, $result['schema_version']);
        $this->assertSame([], $result['edges']);
        $this->assertSame(['code' => 0, 'reality' => 0, 'proven' => 0, 'total' => 0], $result['stats']);
    }

    public function test_non_array_edges_are_skipped(): void
    {
        $codeEdges = [$this->codeEdge('node:a', 'node:b'), 'garbage', 42];
        $aurgEdges = [null, $this->realityEdge('node:a', 'node:d', 'governed_by')];

        $result = (new CodeGraphUnifiedView)->unify($codeEdges, $aurgEdges);

        $this->assertSame(1, $result['stats']['code']);
        $this->assertSame(1, $result['stats']['reality']);
        $this->assertSame(2, $result['stats']['total']);
    }

    public function test_proven_via_keyed_set_does_not_match_falsy_and_symbol_colon_safe(): void
    {
        // A symbol-style ref (FQCN::method) must not be split on the method `:`.
        $codeEdges = [$this->codeEdge('node:a', 'node:b', sourceRef: 'App\\Services\\Foo::handle')];
        $aurgEdges = [];

        // File-grained prefix must NOT match a symbol ref (no digit line part).
        $miss = (new CodeGraphUnifiedView)->unify($codeEdges, $aurgEdges, ['App\\Services\\Foo']);
        $this->assertFalse($miss['edges'][0]['metadata']['runtime_proven']);

        // Exact symbol match DOES prove it.
        $hit = (new CodeGraphUnifiedView)->unify($codeEdges, $aurgEdges, ['App\\Services\\Foo::handle']);
        $this->assertTrue($hit['edges'][0]['metadata']['runtime_proven']);
    }
}
