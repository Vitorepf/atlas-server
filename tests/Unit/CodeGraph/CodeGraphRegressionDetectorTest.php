<?php

declare(strict_types=1);

namespace Tests\Unit\CodeGraph;

use App\Services\Engineering\CodeGraph\CodeGraphRegressionDetector;
use Tests\TestCase;

/**
 * AP-815 · Q-3 — contract for the code-graph regression detector.
 *
 * Pure (no DB): the detector is a deterministic snapshot diff. We prove it catches a
 * graph that got WORSE between index runs — node/edge counts that dropped past the
 * tolerance, a graph that emptied — while never flagging growth, and that it accepts
 * both the FULL (`nodes`/`edges` arrays) and COUNT-only (`node_count`/`edge_count`)
 * snapshot shapes. Malformed/empty input degrades fail-safe to zeros, never throws.
 */
class CodeGraphRegressionDetectorTest extends TestCase
{
    private function detector(): CodeGraphRegressionDetector
    {
        return new CodeGraphRegressionDetector;
    }

    /**
     * @return array<int,array{id:string}>
     */
    private function nodes(int $count, string $prefix = 'n'): array
    {
        $nodes = [];
        for ($i = 0; $i < $count; $i++) {
            $nodes[] = ['id' => $prefix.$i];
        }

        return $nodes;
    }

    /**
     * @return array<int,array{from:string,to:string,type:string}>
     */
    private function edges(int $count, string $prefix = 'e'): array
    {
        $edges = [];
        for ($i = 0; $i < $count; $i++) {
            $edges[] = ['from' => $prefix.$i, 'to' => $prefix.($i + 1), 'type' => 'calls'];
        }

        return $edges;
    }

    /**
     * Required case: 100 nodes / 200 edges shrinking to 50 nodes / 80 edges flags
     * BOTH drops with their exact ratios, and reports the correct churn and sizes.
     */
    public function test_shrinking_graph_flags_both_node_and_edge_drops_with_ratios(): void
    {
        $before = ['nodes' => $this->nodes(100), 'edges' => $this->edges(200)];
        $after = ['nodes' => $this->nodes(50), 'edges' => $this->edges(80)];

        $result = $this->detector()->diff($before, $after);

        $this->assertSame(100, $result['before']['nodes']);
        $this->assertSame(200, $result['before']['edges']);
        $this->assertSame(50, $result['after']['nodes']);
        $this->assertSame(80, $result['after']['edges']);

        // Net deltas are signed and negative (the graph shrank).
        $this->assertSame(-50, $result['node_delta']);
        $this->assertSame(-120, $result['edge_delta']);

        // Both sides are FULL, so churn is an exact set diff. The 50 survivors (n0..n49)
        // are a subset of the original 100 → 0 added, 50 removed. Edges e0..e79 are a
        // subset of e0..e199 → 0 added, 120 removed.
        $this->assertSame(0, $result['added_nodes']);
        $this->assertSame(50, $result['removed_nodes']);
        $this->assertSame(0, $result['added_edges']);
        $this->assertSame(120, $result['removed_edges']);

        // Both drops exceed the 25% default and are flagged, nodes-line before edges-line.
        $this->assertSame(
            [
                'node count dropped 50% (100 -> 50)',
                'edge count dropped 60% (200 -> 80)',
            ],
            $result['regressions'],
        );
    }

    /**
     * Required case: a GROWING graph (after > before on both dimensions) is never a
     * regression — added churn is reported, the regressions list is empty.
     */
    public function test_growth_yields_no_regressions(): void
    {
        $before = ['nodes' => $this->nodes(50), 'edges' => $this->edges(80)];
        $after = ['nodes' => $this->nodes(100), 'edges' => $this->edges(200)];

        $result = $this->detector()->diff($before, $after);

        $this->assertSame(50, $result['added_nodes']);   // n50..n99 are new
        $this->assertSame(0, $result['removed_nodes']);
        $this->assertSame(120, $result['added_edges']);  // e80..e199 are new
        $this->assertSame(0, $result['removed_edges']);
        $this->assertSame(50, $result['node_delta']);
        $this->assertSame(120, $result['edge_delta']);
        $this->assertSame([], $result['regressions'], 'Growth must never be flagged.');
    }

    /**
     * Required case: COUNT-only snapshots (no identity arrays) are supported. Sizes
     * come from node_count/edge_count; churn falls back to the net delta; a drop past
     * tolerance is still flagged.
     */
    public function test_count_only_inputs_are_supported(): void
    {
        $before = ['node_count' => 1000, 'edge_count' => 1000];
        $after = ['node_count' => 1000, 'edge_count' => 600]; // edges -40%, nodes flat

        $result = $this->detector()->diff($before, $after);

        $this->assertSame(1000, $result['before']['nodes']);
        $this->assertSame(1000, $result['before']['edges']);
        $this->assertSame(600, $result['after']['edges']);

        // No identities → churn is the net delta. Nodes unchanged; edges shed 400.
        $this->assertSame(0, $result['added_nodes']);
        $this->assertSame(0, $result['removed_nodes']);
        $this->assertSame(0, $result['added_edges']);
        $this->assertSame(400, $result['removed_edges']);
        $this->assertSame(0, $result['node_delta']);
        $this->assertSame(-400, $result['edge_delta']);

        // Only the edge dimension dropped (40% ≥ 25%); nodes were flat.
        $this->assertSame(['edge count dropped 40% (1000 -> 600)'], $result['regressions']);
    }

    /**
     * Required case: two identical snapshots → zero deltas, zero churn, no regressions.
     */
    public function test_identical_snapshots_have_zero_deltas_and_no_regressions(): void
    {
        $snapshot = ['nodes' => $this->nodes(30), 'edges' => $this->edges(45)];

        $result = $this->detector()->diff($snapshot, $snapshot);

        $this->assertSame(0, $result['added_nodes']);
        $this->assertSame(0, $result['removed_nodes']);
        $this->assertSame(0, $result['added_edges']);
        $this->assertSame(0, $result['removed_edges']);
        $this->assertSame(0, $result['node_delta']);
        $this->assertSame(0, $result['edge_delta']);
        $this->assertSame(30, $result['after']['nodes']);
        $this->assertSame(45, $result['after']['edges']);
        $this->assertSame([], $result['regressions']);
    }

    /**
     * Edge case: a graph that EMPTIES (a real count before, zero after) is flagged
     * with the dedicated "graph emptied" line — and the ratio rule does NOT also fire
     * (no duplicate for the same dimension).
     */
    public function test_emptied_graph_is_flagged_once_per_dimension(): void
    {
        $before = ['node_count' => 500, 'edge_count' => 900];
        $after = ['node_count' => 0, 'edge_count' => 0];

        $result = $this->detector()->diff($before, $after);

        $this->assertSame(
            [
                'graph emptied: nodes fell to 0 (was 500)',
                'graph emptied: edges fell to 0 (was 900)',
            ],
            $result['regressions'],
        );
        $this->assertSame(500, $result['removed_nodes']);
        $this->assertSame(900, $result['removed_edges']);
        $this->assertSame(-500, $result['node_delta']);
        $this->assertSame(-900, $result['edge_delta']);
    }

    /**
     * Edge case: with BOTH sides FULL, churn is a true set diff — visible even when
     * the totals are unchanged. Swapping 10 nodes for 10 different ones reports
     * added 10 / removed 10, a net delta of 0, and no regression (size held).
     */
    public function test_full_set_diff_detects_churn_even_when_totals_match(): void
    {
        // before: a0..a19 (20). after: a0..a9 kept, a10..a19 replaced by b0..b9.
        $beforeNodes = $this->nodes(20, 'a');
        $afterNodes = array_merge($this->nodes(10, 'a'), $this->nodes(10, 'b'));

        $before = ['nodes' => $beforeNodes, 'edges' => []];
        $after = ['nodes' => $afterNodes, 'edges' => []];

        $result = $this->detector()->diff($before, $after);

        $this->assertSame(20, $result['before']['nodes']);
        $this->assertSame(20, $result['after']['nodes']);
        $this->assertSame(10, $result['added_nodes']);    // b0..b9
        $this->assertSame(10, $result['removed_nodes']);  // a10..a19
        $this->assertSame(0, $result['node_delta']);
        $this->assertSame([], $result['regressions'], 'Same size = no regression despite churn.');
    }

    /**
     * Edge case: a drop that stays WITHIN tolerance (below the ratio) is not flagged,
     * but a drop AT the threshold is. Default ratio is 25%.
     */
    public function test_drop_within_tolerance_is_not_flagged_but_at_threshold_is(): void
    {
        // 10% edge drop — under 25% → no regression.
        $mild = $this->detector()->diff(
            ['node_count' => 100, 'edge_count' => 100],
            ['node_count' => 100, 'edge_count' => 90],
        );
        $this->assertSame([], $mild['regressions']);

        // Exactly 25% edge drop — meets the threshold → flagged.
        $atThreshold = $this->detector()->diff(
            ['node_count' => 100, 'edge_count' => 100],
            ['node_count' => 100, 'edge_count' => 75],
        );
        $this->assertSame(['edge count dropped 25% (100 -> 75)'], $atThreshold['regressions']);
    }

    /**
     * Edge case: malformed / heterogeneous input is fail-safe. Non-array snapshots
     * collapse to size 0; identity is read from id/node_id and the (from,to,type)
     * tuple; duplicate rows collapse; rows with no id are still counted positionally.
     */
    public function test_malformed_and_mixed_input_is_fail_safe(): void
    {
        $detector = $this->detector();

        // A non-array "after" (passed through a tolerant caller) must not throw; the
        // method signature is array, so simulate the documented garbage-side behavior
        // with a side whose collections are the wrong type → treated as empty.
        $before = [
            'nodes' => [
                ['id' => 'A'],
                ['node_id' => 'B'],   // fallback identity field
                ['id' => 'A'],        // duplicate → collapses
                'C',                  // scalar node id
                ['name' => 'x'],      // no id → counted positionally (distinct)
            ],
            'edges' => 'not-an-array', // wrong type → 0 edges
        ];
        $after = [
            'nodes' => null,           // wrong type → 0 nodes
            'edges' => [
                ['from' => 'A', 'to' => 'B', 'type' => 'calls'],
                ['from' => 'A', 'to' => 'B', 'type' => 'calls'], // dup tuple → collapses
                ['from' => 'A', 'to' => 'B', 'type' => 'imports'], // different type → distinct
                42,                    // malformed row → counted positionally
            ],
        ];

        $result = $detector->diff($before, $after);

        // before nodes: A, B, C, +1 positional (no-id) = 4 distinct.
        $this->assertSame(4, $result['before']['nodes']);
        // before edges: collection is a string → 0.
        $this->assertSame(0, $result['before']['edges']);
        // after nodes: null collection → 0.
        $this->assertSame(0, $result['after']['nodes']);
        // after edges: 2 distinct tuples + 1 positional malformed = 3.
        $this->assertSame(3, $result['after']['edges']);

        // Nodes went 4 → 0: graph emptied. Edges went 0 → 3: growth, never flagged.
        $this->assertSame(['graph emptied: nodes fell to 0 (was 4)'], $result['regressions']);
        $this->assertSame(3, $result['added_edges']);
        $this->assertSame(0, $result['removed_edges']);
    }

    /**
     * Edge case: empty snapshots on both sides → all zeros, no regressions, no throw.
     * (An empty graph following an empty graph has not regressed.)
     */
    public function test_empty_snapshots_yield_zeros_and_no_regressions(): void
    {
        $result = $this->detector()->diff([], []);

        $this->assertSame(0, $result['added_nodes']);
        $this->assertSame(0, $result['removed_nodes']);
        $this->assertSame(0, $result['added_edges']);
        $this->assertSame(0, $result['removed_edges']);
        $this->assertSame(0, $result['node_delta']);
        $this->assertSame(0, $result['edge_delta']);
        $this->assertSame(['nodes' => 0, 'edges' => 0], $result['before']);
        $this->assertSame(['nodes' => 0, 'edges' => 0], $result['after']);
        $this->assertSame([], $result['regressions']);
    }

    /**
     * The `drop_ratio` option overrides the default tolerance and is clamped to (0,1].
     * A stricter ratio flags a drop the default would ignore; an out-of-band 0 is
     * clamped (never disables the gate); growth still never flags.
     */
    public function test_drop_ratio_option_overrides_and_is_clamped(): void
    {
        $before = ['node_count' => 100, 'edge_count' => 100];
        $after = ['node_count' => 100, 'edge_count' => 90]; // 10% edge drop

        // Default (25%): not flagged.
        $this->assertSame([], $this->detector()->diff($before, $after)['regressions']);

        // Stricter 5% ratio: the 10% drop now trips.
        $strict = $this->detector()->diff($before, $after, ['drop_ratio' => 0.05]);
        $this->assertSame(['edge count dropped 10% (100 -> 90)'], $strict['regressions']);

        // A 0 ratio is clamped to a tiny epsilon, so it flags any real drop but NOT a
        // flat/growing dimension (nodes here are unchanged → not flagged).
        $zero = $this->detector()->diff($before, $after, ['drop_ratio' => 0]);
        $this->assertSame(['edge count dropped 10% (100 -> 90)'], $zero['regressions']);

        // A NaN ratio falls back to the default → 10% is again within tolerance.
        $nan = $this->detector()->diff($before, $after, ['drop_ratio' => NAN]);
        $this->assertSame([], $nan['regressions']);
    }

    /**
     * Fractional percentages render with a single decimal (no trailing-zero noise);
     * whole percentages render without one. Output is deterministic.
     */
    public function test_fractional_percent_formatting_is_clean_and_deterministic(): void
    {
        // 1000 -> 667 = 33.3% drop (one decimal).
        $frac = $this->detector()->diff(
            ['edge_count' => 1000],
            ['edge_count' => 667],
        );
        $this->assertSame(['edge count dropped 33.3% (1000 -> 667)'], $frac['regressions']);

        // Determinism: identical inputs → byte-identical output.
        $again = $this->detector()->diff(['edge_count' => 1000], ['edge_count' => 667]);
        $this->assertSame($frac, $again);
    }
}
