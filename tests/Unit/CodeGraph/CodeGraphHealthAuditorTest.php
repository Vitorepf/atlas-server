<?php

declare(strict_types=1);

namespace Tests\Unit\CodeGraph;

use App\Services\Engineering\CodeGraph\CodeGraphHealthAuditor;
use Tests\TestCase;

/**
 * AP-815 · Q-1 — contract for the basic code-graph health auditor.
 *
 * Pure (no DB): the auditor is a deterministic array transform. We prove the
 * health primitives the canon needs — orphans (degree-0 nodes) and dangling edges
 * (references to unknown nodes) are detected, coverage/degree stats are computed
 * exactly, and malformed/empty input is handled fail-safe with zeros and no throw.
 */
class CodeGraphHealthAuditorTest extends TestCase
{
    private function auditor(): CodeGraphHealthAuditor
    {
        return new CodeGraphHealthAuditor;
    }

    /**
     * Required case: a graph with one orphan node and one dangling edge — both must
     * be detected, with the coverage ratio and degree stats computed correctly.
     */
    public function test_detects_orphan_node_and_dangling_edge_with_correct_stats(): void
    {
        // Nodes A, B, C, D. Edges: A->B, B->C (so A,B,C connected), and C->Z where
        // Z is NOT a node (dangling). D is touched by nothing → orphan.
        $nodes = ['A', 'B', 'C', 'D'];
        $edges = [
            ['from_node_id' => 'A', 'to_node_id' => 'B'],
            ['from_node_id' => 'B', 'to_node_id' => 'C'],
            ['from_node_id' => 'C', 'to_node_id' => 'Z'], // Z unknown → dangling
        ];

        $result = $this->auditor()->audit($nodes, $edges);

        $this->assertSame(4, $result['node_count']);
        $this->assertSame(3, $result['edge_count']);

        // Exactly one orphan: D.
        $this->assertSame(['D'], $result['orphan_nodes']);

        // Exactly one dangling edge: the C->Z edge (returned as the original value).
        $this->assertCount(1, $result['dangling_edges']);
        $this->assertSame(
            ['from_node_id' => 'C', 'to_node_id' => 'Z'],
            $result['dangling_edges'][0],
        );

        // Coverage: A,B,C connected (3 of 4) → 0.75.
        $this->assertSame(0.75, $result['coverage_ratio']);

        // Degrees: A=1 (out A->B), B=2 (in+out), C=2 (in B->C, out C->Z), D=0.
        // max_degree = 2; avg_degree = (1+2+2+0)/4 = 1.25.
        $this->assertSame(2, $result['max_degree']);
        $this->assertSame(1.25, $result['avg_degree']);

        // Issues: one dangling edge + one orphan node (deterministic order).
        $this->assertContains('1 dangling edge', $result['issues']);
        $this->assertContains('1 orphan node', $result['issues']);
    }

    /**
     * Edge case 1: a completely empty graph must return all zeros, an empty coverage
     * ratio, empty arrays, and no issues — and must NOT throw or divide by zero.
     */
    public function test_empty_graph_returns_zeros_and_does_not_throw(): void
    {
        $result = $this->auditor()->audit([], []);

        $this->assertSame(0, $result['node_count']);
        $this->assertSame(0, $result['edge_count']);
        $this->assertSame([], $result['orphan_nodes']);
        $this->assertSame([], $result['dangling_edges']);
        $this->assertSame(0.0, $result['coverage_ratio']);
        $this->assertSame(0, $result['max_degree']);
        $this->assertSame(0.0, $result['avg_degree']);
        $this->assertSame([], $result['issues'], 'An empty graph is not an issue to flag.');
    }

    /**
     * Edge case 2: malformed and heterogeneous input must be handled fail-safe.
     * Non-array edges are skipped (counted as malformed); array nodes with
     * `node_id`/`id`, the short `from`/`to` keys, duplicate ids, blank ids, and a
     * self-loop are all tolerated deterministically.
     */
    public function test_malformed_and_mixed_input_is_fail_safe(): void
    {
        $nodes = [
            ['node_id' => 'A'],     // array form, node_id
            ['id' => 'B'],          // array form, fallback id
            'A',                    // duplicate of A → collapses
            '   ',                  // blank string → ignored
            ['name' => 'no-id'],    // no resolvable id → ignored
            'C',                    // plain string node
        ];
        $edges = [
            ['from' => 'A', 'to' => 'B'],   // short keys, both known
            'not-an-edge',                  // malformed → skipped
            42,                             // malformed → skipped
            ['from' => 'A', 'to' => 'A'],   // self-loop on A (A degree += 2)
            ['from' => 'B'],                // half-specified (no target) → dangling
        ];

        $result = $this->auditor()->audit($nodes, $edges);

        // De-dup: A, B, C → 3 distinct nodes (blank + no-id rows dropped).
        $this->assertSame(3, $result['node_count']);

        // Well-formed edges considered: A->B, A->A, B->(none) = 3. The two
        // non-array entries are malformed and excluded from edge_count.
        $this->assertSame(3, $result['edge_count']);

        // C is touched by no edge → the only orphan.
        $this->assertSame(['C'], $result['orphan_nodes']);

        // Dangling: the half-specified B->(none) edge (missing target).
        $this->assertCount(1, $result['dangling_edges']);
        $this->assertSame(['from' => 'B'], $result['dangling_edges'][0]);

        // Degrees: A = 1 (A->B out) + 2 (self-loop) = 3; B = 1 (A->B in) + 1
        // (B->none out) = 2; C = 0. max_degree = 3.
        $this->assertSame(3, $result['max_degree']);

        // avg_degree = (3 + 2 + 0) / 3 = 1.666667 (rounded to 6 dp).
        $this->assertSame(round(5 / 3, 6), $result['avg_degree']);

        // Coverage: A,B connected (2 of 3) → 0.666667.
        $this->assertSame(round(2 / 3, 6), $result['coverage_ratio']);

        // Issues mention malformed edges, the dangling edge, and the orphan.
        $this->assertContains('2 malformed edges skipped', $result['issues']);
        $this->assertContains('1 dangling edge', $result['issues']);
        $this->assertContains('1 orphan node', $result['issues']);
    }

    /**
     * A perfectly healthy, fully-connected graph yields zero orphans, zero dangling
     * edges, coverage 1.0, and an empty issues list.
     */
    public function test_clean_fully_connected_graph_has_no_issues(): void
    {
        $nodes = ['A', 'B', 'C'];
        $edges = [
            ['from_node_id' => 'A', 'to_node_id' => 'B'],
            ['from_node_id' => 'B', 'to_node_id' => 'C'],
            ['from_node_id' => 'C', 'to_node_id' => 'A'],
        ];

        $result = $this->auditor()->audit($nodes, $edges);

        $this->assertSame([], $result['orphan_nodes']);
        $this->assertSame([], $result['dangling_edges']);
        $this->assertSame(1.0, $result['coverage_ratio']);
        $this->assertSame(2, $result['max_degree']); // each node: 1 in + 1 out
        $this->assertSame(2.0, $result['avg_degree']);
        $this->assertSame([], $result['issues']);
    }

    /**
     * Determinism: the same input audited twice yields byte-identical output, and
     * orphan ids come back in node first-seen order regardless of edge order.
     */
    public function test_output_is_deterministic_and_orphans_keep_first_seen_order(): void
    {
        $nodes = ['Z', 'Y', 'X', 'W']; // none connected → all orphans, this order
        $edges = [];

        $first = $this->auditor()->audit($nodes, $edges);
        $second = $this->auditor()->audit($nodes, $edges);

        $this->assertSame($first, $second, 'Audit must be deterministic.');
        $this->assertSame(['Z', 'Y', 'X', 'W'], $first['orphan_nodes']);
        $this->assertSame(0.0, $first['coverage_ratio']);
        $this->assertContains('4 orphan nodes', $first['issues']);
    }

    /**
     * The `precision` option (and its config fallback) controls float rounding and is
     * clamped to a safe band, proving config is read with an inline default.
     */
    public function test_precision_option_controls_rounding(): void
    {
        $nodes = ['A', 'B', 'C']; // 2 of 3 connected → coverage 0.6666...
        $edges = [['from' => 'A', 'to' => 'B']];

        $coarse = $this->auditor()->audit($nodes, $edges, ['precision' => 2]);
        $this->assertSame(0.67, $coarse['coverage_ratio']);

        // Out-of-band precision is clamped (negative → 0 dp), never throws.
        $clamped = $this->auditor()->audit($nodes, $edges, ['precision' => -5]);
        $this->assertSame(1.0, $clamped['coverage_ratio']); // round(0.666.., 0) = 1.0
    }
}
