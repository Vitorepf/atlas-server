<?php

declare(strict_types=1);

namespace Tests\Unit\CodeGraph;

use App\Services\Engineering\CodeGraph\CodeGraphAdjacencyIndex;
use Tests\TestCase;

/**
 * AP-815 · D-1 — contract for the in-memory adjacency index.
 *
 * Pure (no DB): the index is a deterministic read model over a flat edge list. We
 * prove the forward/reverse adjacency is correct, degree = out + in, the accessors
 * are fail-safe on unknown/malformed input, and output is deduped + deterministically
 * sorted regardless of input order.
 */
class CodeGraphAdjacencyIndexTest extends TestCase
{
    /**
     * Happy path: a small directed graph
     *
     *   a -> b,  a -> c,  b -> c
     *
     * neighbors() must give forward out-neighbors, incoming() the reverse, degree()
     * the sum, and has()/nodes() the membership — all deterministic.
     */
    public function test_builds_forward_reverse_degree_and_membership(): void
    {
        $index = CodeGraphAdjacencyIndex::fromEdges([
            ['from_node_id' => 'a', 'to_node_id' => 'b'],
            ['from_node_id' => 'a', 'to_node_id' => 'c'],
            ['from_node_id' => 'b', 'to_node_id' => 'c'],
        ]);

        // forward out-neighbors (sorted)
        $this->assertSame(['b', 'c'], $index->neighbors('a'));
        $this->assertSame(['c'], $index->neighbors('b'));
        $this->assertSame([], $index->neighbors('c'), 'c is a pure sink: no out-neighbors');

        // reverse in-neighbors (sorted)
        $this->assertSame([], $index->incoming('a'), 'a is a pure source: no in-neighbors');
        $this->assertSame(['a'], $index->incoming('b'));
        $this->assertSame(['a', 'b'], $index->incoming('c'));

        // degree = out + in
        $this->assertSame(2, $index->degree('a'), 'a: out 2 (b,c) + in 0');
        $this->assertSame(2, $index->degree('b'), 'b: out 1 (c) + in 1 (a)');
        $this->assertSame(2, $index->degree('c'), 'c: out 0 + in 2 (a,b)');

        // membership
        $this->assertTrue($index->has('a'));
        $this->assertTrue($index->has('b'));
        $this->assertTrue($index->has('c'));
        $this->assertFalse($index->has('zzz'), 'never-seen node is absent');

        $this->assertSame(['a', 'b', 'c'], $index->nodes());
        $this->assertSame(3, $index->count());
    }

    /**
     * Edge case 1 — malformed / partial edges are skipped fail-safe, while the `from`
     * and `to` short aliases (and scalar int ids) are still accepted. The index must
     * neither throw nor admit any phantom node from the bad rows.
     */
    public function test_skips_malformed_edges_and_accepts_aliases_and_scalar_ids(): void
    {
        $index = CodeGraphAdjacencyIndex::fromEdges([
            'not-an-array',                              // non-array → skip
            ['from_node_id' => 'a'],                     // missing `to` → skip
            ['to_node_id' => 'b'],                       // missing `from` → skip
            ['from_node_id' => '   ', 'to_node_id' => 'b'], // blank from → skip
            ['from_node_id' => 'a', 'to_node_id' => null], // null to → skip
            ['from_node_id' => ['x'], 'to_node_id' => 'b'], // non-scalar from → skip
            ['from' => 'a', 'to' => 'b'],                // short aliases → KEPT
            ['from_node_id' => 1, 'to_node_id' => 2],    // scalar int ids → KEPT as "1"->"2"
        ]);

        // Only the two well-formed edges contributed.
        $this->assertSame(['1', '2', 'a', 'b'], $index->nodes());
        $this->assertSame(4, $index->count());

        $this->assertSame(['b'], $index->neighbors('a'));
        $this->assertSame(['a'], $index->incoming('b'));
        $this->assertSame(['2'], $index->neighbors('1'));
        $this->assertSame(['1'], $index->incoming('2'));

        // None of the malformed rows leaked a node.
        $this->assertFalse($index->has('x'));
        $this->assertSame([], $index->neighbors('   '));
    }

    /**
     * Edge case 2 — empty / fully-malformed input yields an empty-but-valid index:
     * every accessor returns its safe default and nothing throws.
     */
    public function test_empty_and_all_malformed_input_is_safe(): void
    {
        $empty = new CodeGraphAdjacencyIndex();
        $this->assertSame(0, $empty->count());
        $this->assertSame([], $empty->nodes());
        $this->assertSame([], $empty->neighbors('anything'));
        $this->assertSame([], $empty->incoming('anything'));
        $this->assertSame(0, $empty->degree('anything'));
        $this->assertFalse($empty->has('anything'));

        $garbage = CodeGraphAdjacencyIndex::fromEdges([null, 42, 'str', ['nope' => 1], ['from' => '']]);
        $this->assertSame(0, $garbage->count());
        $this->assertSame([], $garbage->nodes());
    }

    /**
     * Edge case 3 — duplicate edges are collapsed and self-loops are preserved as real
     * structure (out +1 AND in +1 → degree 2). Output stays deduped.
     */
    public function test_dedupes_duplicate_edges_and_preserves_self_loops(): void
    {
        $index = CodeGraphAdjacencyIndex::fromEdges([
            ['from_node_id' => 'a', 'to_node_id' => 'b'],
            ['from_node_id' => 'a', 'to_node_id' => 'b'], // exact duplicate
            ['from' => 'a', 'to' => 'b'],                 // duplicate via alias
            ['from_node_id' => 's', 'to_node_id' => 's'], // self-loop
        ]);

        $this->assertSame(['b'], $index->neighbors('a'), 'duplicate edge counted once');
        $this->assertSame(['a'], $index->incoming('b'));
        $this->assertSame(1, $index->degree('a'));
        $this->assertSame(1, $index->degree('b'));

        // Self-loop: s is its own out- and in-neighbor → degree 2.
        $this->assertSame(['s'], $index->neighbors('s'));
        $this->assertSame(['s'], $index->incoming('s'));
        $this->assertSame(2, $index->degree('s'));
    }

    /**
     * Edge case 4 — output is deterministically sorted and identical no matter what
     * order the edges arrive in (the core "deterministic" contract). Numeric-string
     * ids must sort naturally, not by PHP's int-key casting.
     */
    public function test_output_is_deterministic_regardless_of_input_order(): void
    {
        $forward = CodeGraphAdjacencyIndex::fromEdges([
            ['from' => 'hub', 'to' => 'n10'],
            ['from' => 'hub', 'to' => 'n2'],
            ['from' => 'hub', 'to' => 'n1'],
        ]);
        $reverse = CodeGraphAdjacencyIndex::fromEdges([
            ['from' => 'hub', 'to' => 'n1'],
            ['from' => 'hub', 'to' => 'n2'],
            ['from' => 'hub', 'to' => 'n10'],
        ]);

        $expected = ['n1', 'n2', 'n10']; // natural order, not lexical "n1,n10,n2"
        $this->assertSame($expected, $forward->neighbors('hub'));
        $this->assertSame($expected, $reverse->neighbors('hub'), 'order-independent');
        $this->assertSame($forward->nodes(), $reverse->nodes());
    }

    /**
     * Edge case 5 — the node ceiling bounds memory: with the cap set to 2, only the
     * first two distinct nodes are admitted and edges introducing a third node are
     * skipped, while edges between the two admitted nodes still index. Default cap is
     * exposed and large.
     */
    public function test_bounds_distinct_nodes_by_config_cap(): void
    {
        config()->set('atlas.code_graph.max_adjacency_nodes', 2);

        $index = CodeGraphAdjacencyIndex::fromEdges([
            ['from' => 'a', 'to' => 'b'],  // admits a, b (cap full)
            ['from' => 'a', 'to' => 'c'],  // c is new → whole edge skipped
            ['from' => 'b', 'to' => 'a'],  // both already admitted → indexed
        ]);

        $this->assertSame(2, $index->count());
        $this->assertSame(['a', 'b'], $index->nodes());
        $this->assertFalse($index->has('c'), 'third distinct node rejected by cap');

        // a<->b still fully indexed despite the cap.
        $this->assertSame(['b'], $index->neighbors('a'));
        $this->assertSame(['a'], $index->neighbors('b'));
        $this->assertSame(2, $index->degree('a'), 'out 1 (b) + in 1 (b)');

        $this->assertSame(CodeGraphAdjacencyIndex::DEFAULT_MAX_NODES, 200000);
    }

    /**
     * Edge case 6 — query-time normalization matches build-time: an untrimmed lookup
     * id resolves to the trimmed node, and the canonical `*_node_id` key wins when an
     * edge carries both it and the short alias.
     */
    public function test_query_normalization_and_canonical_key_precedence(): void
    {
        $index = CodeGraphAdjacencyIndex::fromEdges([
            // canonical keys must win over the conflicting short aliases.
            ['from_node_id' => 'a', 'from' => 'WRONG', 'to_node_id' => 'b', 'to' => 'WRONG'],
        ]);

        $this->assertSame(['a', 'b'], $index->nodes());
        $this->assertFalse($index->has('WRONG'), 'alias ignored when canonical key present');

        // Untrimmed query resolves to the trimmed stored id.
        $this->assertTrue($index->has('  a  '));
        $this->assertSame(['b'], $index->neighbors('  a  '));
        $this->assertSame(1, $index->degree('  b  '));
    }
}
