<?php

namespace Tests\Unit\CodeGraph;

use Tests\TestCase;

/**
 * AP-811 P0 — pin the contract of config('atlas.code_graph.*').
 *
 * The keystone resolver (App\Services\Engineering\CodeGraph\CodeGraphEdgeResolver)
 * produces real world-model edges, but `real_edges` MUST default OFF so deploying
 * the code without operator review keeps the fixture-seeded graph (safe default).
 * The other keys exist with safe types so adapters can read them without nullchecks.
 */
class CodeGraphConfigTest extends TestCase
{
    public function test_real_edges_defaults_off(): void
    {
        $realEdges = config('atlas.code_graph.real_edges');

        $this->assertSame(false, $realEdges,
            'Default MUST be false (===) so deploying the resolver without operator review keeps fixtures, not real edges.');
    }

    public function test_bound_and_traversal_keys_exist_with_expected_types_and_defaults(): void
    {
        $maxEdges = config('atlas.code_graph.max_edges');
        $maxDepth = config('atlas.code_graph.traversal_max_depth');
        $maxNodes = config('atlas.code_graph.traversal_max_nodes');

        $this->assertIsInt($maxEdges);
        $this->assertSame(200000, $maxEdges,
            'Hard upper bound on edges persisted per world model.');

        $this->assertIsInt($maxDepth);
        $this->assertSame(4, $maxDepth,
            'Traversal depth cap so graph walks stay bounded on large indexes.');

        $this->assertIsInt($maxNodes);
        $this->assertSame(60, $maxNodes,
            'Traversal node cap so graph walks stay bounded on large indexes.');
    }

    public function test_real_edges_is_a_strict_boolean(): void
    {
        $this->assertIsBool(config('atlas.code_graph.real_edges'));
    }

    public function test_real_edges_can_be_overridden_via_config(): void
    {
        // Sanity check that the flag is readable/overridable (operator flips via .env after review).
        config(['atlas.code_graph.real_edges' => true]);

        $this->assertTrue(config('atlas.code_graph.real_edges'));
    }
}
