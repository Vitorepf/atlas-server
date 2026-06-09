<?php

declare(strict_types=1);

namespace Tests\Unit\CodeGraph;

use App\Services\Engineering\CodeGraph\CodeGraphAntiContextPruner;
use Tests\TestCase;

/**
 * AP-815 · E-8 — contract for the anti-context pruner.
 *
 * Pure (no DB): the pruner is a deterministic bounded-BFS array transform. We prove the
 * efficiency invariant — a candidate survives iff it is within N hops of some seed —
 * across the happy path and the fail-safe edges (no seeds, isolated nodes, distance cap,
 * malformed adjacency, duplicates, determinism).
 */
class CodeGraphAntiContextPrunerTest extends TestCase
{
    private function pruner(): CodeGraphAntiContextPruner
    {
        return new CodeGraphAntiContextPruner;
    }

    /**
     * Happy path at distance 2: a candidate adjacent to a seed is kept; a candidate three
     * hops from the only seed is pruned; an isolated candidate (no edges) is pruned. The
     * seed itself, present among candidates, is always kept.
     */
    public function test_keeps_within_radius_prunes_beyond_radius_and_isolated(): void
    {
        // Graph (undirected):  seed — near — mid — far
        //   isolated has no edges at all.
        $adjacency = [
            'seed' => ['near'],
            'near' => ['mid'],
            'mid' => ['far'],
        ];

        $candidates = ['seed', 'near', 'mid', 'far', 'isolated'];
        $seeds = ['seed'];

        $result = $this->pruner()->prune($candidates, $seeds, $adjacency, ['max_distance' => 2]);

        // seed(0), near(1), mid(2) are within 2 hops → kept. far(3) and isolated(∞) → pruned.
        $this->assertSame(['mid', 'near', 'seed'], $result['kept'], 'Seed + nodes within 2 hops are kept (sorted).');
        $this->assertSame(['far', 'isolated'], $result['pruned'], 'A 3-hop node and an isolated node are pruned.');

        $this->assertSame(5, $result['stats']['candidates']);
        $this->assertSame(3, $result['stats']['kept']);
        $this->assertSame(2, $result['stats']['pruned']);
        $this->assertSame(2, $result['stats']['max_distance']);
    }

    /**
     * The single most explicit spec assertion: a candidate adjacent (1 hop) to a seed is
     * kept regardless of radius.
     */
    public function test_candidate_adjacent_to_seed_is_kept(): void
    {
        $result = $this->pruner()->prune(
            ['neighbour'],
            ['anchor'],
            ['anchor' => ['neighbour']],
            ['max_distance' => 2],
        );

        $this->assertSame(['neighbour'], $result['kept']);
        $this->assertSame([], $result['pruned']);
    }

    /**
     * Edge case: empty seeds → there is no relevance anchor, so EVERY candidate is
     * pruned (nothing is relevant). The over-pruning-safe direction.
     */
    public function test_empty_seeds_prunes_everything(): void
    {
        $adjacency = [
            'a' => ['b'],
            'b' => ['c'],
        ];

        $result = $this->pruner()->prune(['a', 'b', 'c'], [], $adjacency, ['max_distance' => 5]);

        $this->assertSame([], $result['kept'], 'With no seeds nothing is relevant.');
        $this->assertSame(['a', 'b', 'c'], $result['pruned']);
        $this->assertSame(3, $result['stats']['candidates']);
        $this->assertSame(0, $result['stats']['kept']);
        $this->assertSame(3, $result['stats']['pruned']);
    }

    /**
     * Edge case: empty candidates → empty result, no crash, stats all zero. The radius is
     * still reported.
     */
    public function test_empty_candidates_returns_empty_result(): void
    {
        $result = $this->pruner()->prune([], ['seed'], ['seed' => ['x']], ['max_distance' => 3]);

        $this->assertSame([], $result['kept']);
        $this->assertSame([], $result['pruned']);
        $this->assertSame(0, $result['stats']['candidates']);
        $this->assertSame(0, $result['stats']['kept']);
        $this->assertSame(0, $result['stats']['pruned']);
        $this->assertSame(3, $result['stats']['max_distance']);
    }

    /**
     * Reachability is UNDIRECTED: an edge recorded only as child→parent still makes the
     * parent reachable from the (seed) child, and vice versa.
     */
    public function test_reachability_is_undirected(): void
    {
        // Edge recorded one-way only: child → parent. Seed is the parent.
        $adjacency = ['child' => ['parent']];

        $result = $this->pruner()->prune(['child', 'parent'], ['parent'], $adjacency, ['max_distance' => 1]);

        $this->assertSame(['child', 'parent'], $result['kept'], 'A one-way edge is traversable both directions.');
        $this->assertSame([], $result['pruned']);
    }

    /**
     * Distance cap of 0: only the seeds themselves are reachable; even direct neighbours
     * are pruned.
     */
    public function test_distance_zero_keeps_only_seeds(): void
    {
        $result = $this->pruner()->prune(
            ['seed', 'neighbour'],
            ['seed'],
            ['seed' => ['neighbour']],
            ['max_distance' => 0],
        );

        $this->assertSame(['seed'], $result['kept']);
        $this->assertSame(['neighbour'], $result['pruned']);
        $this->assertSame(0, $result['stats']['max_distance']);
    }

    /**
     * A negative radius is clamped to 0 rather than throwing or pruning the seeds.
     */
    public function test_negative_distance_is_clamped_to_zero(): void
    {
        $result = $this->pruner()->prune(
            ['seed', 'neighbour'],
            ['seed'],
            ['seed' => ['neighbour']],
            ['max_distance' => -5],
        );

        $this->assertSame(['seed'], $result['kept']);
        $this->assertSame(['neighbour'], $result['pruned']);
        $this->assertSame(0, $result['stats']['max_distance']);
    }

    /**
     * Fail-safe on malformed input: non-array neighbour lists, non-scalar / empty ids,
     * self-loops, and duplicate candidates are all tolerated without throwing. Distinct
     * survivors are still computed correctly.
     */
    public function test_malformed_input_is_tolerated_and_deduped(): void
    {
        $adjacency = [
            'seed' => ['near', 'seed', null, ['nested'], '  '], // self-loop + junk neighbours ignored
            'near' => 'not-an-array',                            // malformed list → skipped
            'orphan' => ['ghost'],                               // unrelated component
        ];

        $candidates = [
            'seed', 'seed',          // duplicate → one entry
            'near',
            'orphan',                // not reachable from seed
            '',                      // empty id → dropped entirely
            ['array-id'],            // non-scalar → dropped entirely
            null,                    // null → dropped entirely
        ];

        $result = $this->pruner()->prune($candidates, ['seed'], $adjacency, ['max_distance' => 2]);

        // Distinct usable candidates: seed, near, orphan → 3.
        $this->assertSame(3, $result['stats']['candidates']);
        $this->assertSame(['near', 'seed'], $result['kept'], 'seed + its 1-hop neighbour, deduped.');
        $this->assertSame(['orphan'], $result['pruned'], 'Disconnected component is pruned.');
    }

    /**
     * Determinism: output is byte-identical regardless of candidate / seed / adjacency
     * insertion order. The two calls describe the SAME graph with shuffled keys.
     */
    public function test_output_is_deterministic_regardless_of_input_order(): void
    {
        $adjacencyA = [
            'a' => ['b'],
            'b' => ['c'],
            'c' => ['d'],
        ];
        $adjacencyB = [
            'c' => ['b', 'd'],
            'a' => ['b'],
        ];

        $resultA = $this->pruner()->prune(['d', 'c', 'b', 'a'], ['a'], $adjacencyA, ['max_distance' => 2]);
        $resultB = $this->pruner()->prune(['a', 'b', 'c', 'd'], ['a'], $adjacencyB, ['max_distance' => 2]);

        $this->assertSame($resultA, $resultB, 'Same graph + radius ⇒ identical sorted result.');
        // a(0), b(1), c(2) within 2 hops; d(3) pruned.
        $this->assertSame(['a', 'b', 'c'], $resultA['kept']);
        $this->assertSame(['d'], $resultA['pruned']);
    }

    /**
     * Integer node ids are supported and canonicalised to strings; reachability works the
     * same as for string ids.
     */
    public function test_integer_node_ids_are_supported(): void
    {
        $result = $this->pruner()->prune(
            [1, 2, 3],
            [1],
            [1 => [2], 2 => [3]],
            ['max_distance' => 1],
        );

        $this->assertSame(['1', '2'], $result['kept'], 'Int ids canonicalise to strings; 1-hop reach kept.');
        $this->assertSame(['3'], $result['pruned']);
    }

    /**
     * Multiple seeds: a candidate within range of ANY seed is kept, even if far from the
     * others.
     */
    public function test_reachable_from_any_seed_is_kept(): void
    {
        // Two disconnected stars: seedA—x   and   seedB—y
        $adjacency = [
            'seedA' => ['x'],
            'seedB' => ['y'],
        ];

        $result = $this->pruner()->prune(
            ['x', 'y', 'z'],
            ['seedA', 'seedB'],
            $adjacency,
            ['max_distance' => 1],
        );

        $this->assertSame(['x', 'y'], $result['kept'], 'Each candidate is kept via its own seed.');
        $this->assertSame(['z'], $result['pruned']);
    }
}
