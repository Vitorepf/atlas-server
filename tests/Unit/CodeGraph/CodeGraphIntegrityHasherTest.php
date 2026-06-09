<?php

declare(strict_types=1);

namespace Tests\Unit\CodeGraph;

use App\Services\Engineering\CodeGraph\CodeGraphIntegrityHasher;
use Tests\TestCase;

/**
 * AP-815 · G-8 — Prove the integrity hash is a deterministic, order-independent,
 * set-based content hash: the same graph hashes equal in any order; any real
 * change (add/remove an edge or node) flips it; an empty graph is still stable.
 *
 * Pure unit test — no DB, no clock, no randomness.
 */
class CodeGraphIntegrityHasherTest extends TestCase
{
    private function hasher(): CodeGraphIntegrityHasher
    {
        return new CodeGraphIntegrityHasher();
    }

    /** A small but representative graph (array nodes + array edges). */
    private function sampleGraph(): array
    {
        $nodes = [
            ['node_id' => 'A'],
            ['node_id' => 'B'],
            ['node_id' => 'C'],
        ];
        $edges = [
            ['from_node_id' => 'A', 'to_node_id' => 'B', 'edge_type' => 'calls'],
            ['from_node_id' => 'B', 'to_node_id' => 'C', 'edge_type' => 'calls'],
            ['from_node_id' => 'A', 'to_node_id' => 'C', 'edge_type' => 'imports'],
        ];

        return [$nodes, $edges];
    }

    // ---- Happy path: order independence -------------------------------------

    public function test_same_graph_in_different_order_yields_identical_hash(): void
    {
        $hasher = $this->hasher();
        [$nodes, $edges] = $this->sampleGraph();

        $a = $hasher->hash($nodes, $edges);

        // Shuffle BOTH collections into a different order.
        $b = $hasher->hash(
            [$nodes[2], $nodes[0], $nodes[1]],
            [$edges[1], $edges[2], $edges[0]],
        );

        $this->assertSame($a, $b,
            'A graph fed in a different order MUST hash identically (order-independent).');
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $a,
            'hash() must be a lowercase 64-char sha256 hex digest.');
    }

    public function test_string_nodes_and_array_nodes_are_interchangeable(): void
    {
        $hasher = $this->hasher();

        $edges = [['from' => 'A', 'to' => 'B', 'type' => 'calls']];

        $withArrayNodes = $hasher->hash([['node_id' => 'A'], ['id' => 'B']], $edges);
        $withStringNodes = $hasher->hash(['A', 'B'], $edges);

        $this->assertSame($withArrayNodes, $withStringNodes,
            'String nodes and {node_id|id} array nodes describing the same set must hash equal.');
    }

    public function test_edge_key_aliases_are_equivalent(): void
    {
        $hasher = $this->hasher();
        $nodes = ['A', 'B'];

        $canonical = $hasher->hash($nodes, [
            ['from_node_id' => 'A', 'to_node_id' => 'B', 'edge_type' => 'calls'],
        ]);
        $aliased = $hasher->hash($nodes, [
            ['from' => 'A', 'to' => 'B', 'type' => 'calls'],
        ]);

        $this->assertSame($canonical, $aliased,
            'from/to/type aliases must be equivalent to from_node_id/to_node_id/edge_type.');
    }

    public function test_duplicate_nodes_and_edges_do_not_change_the_hash(): void
    {
        $hasher = $this->hasher();
        [$nodes, $edges] = $this->sampleGraph();

        $base = $hasher->hash($nodes, $edges);

        // A graph is a SET: feeding the same rows twice must not perturb identity.
        $withDupes = $hasher->hash(
            array_merge($nodes, $nodes, [$nodes[0]]),
            array_merge($edges, [$edges[0], $edges[1]]),
        );

        $this->assertSame($base, $withDupes,
            'Duplicate nodes/edges must be de-duplicated and not affect the hash.');
    }

    // ---- Edge case 1: adding / removing one edge flips the hash --------------

    public function test_adding_one_edge_changes_the_hash(): void
    {
        $hasher = $this->hasher();
        [$nodes, $edges] = $this->sampleGraph();

        $before = $hasher->hash($nodes, $edges);

        $edgesPlus = $edges;
        $edgesPlus[] = ['from_node_id' => 'C', 'to_node_id' => 'A', 'edge_type' => 'calls'];
        $after = $hasher->hash($nodes, $edgesPlus);

        $this->assertNotSame($before, $after,
            'Adding a genuinely new edge MUST change the content hash.');
    }

    public function test_removing_one_edge_changes_the_hash(): void
    {
        $hasher = $this->hasher();
        [$nodes, $edges] = $this->sampleGraph();

        $full = $hasher->hash($nodes, $edges);

        array_pop($edges); // drop one edge
        $fewer = $hasher->hash($nodes, $edges);

        $this->assertNotSame($full, $fewer,
            'Removing an edge MUST change the content hash.');
    }

    public function test_changing_only_edge_type_changes_the_hash(): void
    {
        $hasher = $this->hasher();
        $nodes = ['A', 'B'];

        $calls = $hasher->hash($nodes, [['from' => 'A', 'to' => 'B', 'type' => 'calls']]);
        $imports = $hasher->hash($nodes, [['from' => 'A', 'to' => 'B', 'type' => 'imports']]);

        $this->assertNotSame($calls, $imports,
            'Edge type is part of identity — changing only the type must flip the hash.');
    }

    public function test_edge_direction_is_significant(): void
    {
        $hasher = $this->hasher();
        $nodes = ['A', 'B'];

        $forward = $hasher->hash($nodes, [['from' => 'A', 'to' => 'B', 'type' => 'calls']]);
        $reverse = $hasher->hash($nodes, [['from' => 'B', 'to' => 'A', 'type' => 'calls']]);

        $this->assertNotSame($forward, $reverse,
            'A→B and B→A are different edges; the hash must distinguish direction.');
    }

    // ---- Edge case 2: empty graph is stable & non-empty ---------------------

    public function test_empty_graph_produces_stable_non_empty_hash(): void
    {
        $hasher = $this->hasher();

        $h1 = $hasher->hash([], []);
        $h2 = $this->hasher()->hash([], []);

        $this->assertNotSame('', $h1, 'Empty graph hash must be non-empty.');
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $h1,
            'Empty graph hash must still be a valid sha256 digest.');
        $this->assertSame($h1, $h2,
            'Empty graph hash must be stable across instances/runs (deterministic).');
    }

    public function test_nodes_only_and_edges_only_graphs_differ_from_empty(): void
    {
        $hasher = $this->hasher();

        $empty = $hasher->hash([], []);
        $nodesOnly = $hasher->hash(['A'], []);
        $edgesOnly = $hasher->hash([], [['from' => 'A', 'to' => 'B', 'type' => 'calls']]);

        $this->assertNotSame($empty, $nodesOnly);
        $this->assertNotSame($empty, $edgesOnly);
        $this->assertNotSame($nodesOnly, $edgesOnly);
    }

    // ---- Edge case 3: malformed input is fail-safe (never throws) ------------

    public function test_malformed_input_is_skipped_and_never_throws(): void
    {
        $hasher = $this->hasher();

        // Junk nodes: empty string, blank, null, an array with no id key, an
        // object without Stringable. Junk edges: non-array, missing endpoint,
        // blank endpoint. None must throw; all junk is skipped.
        $nodes = ['', '   ', null, ['label' => 'x'], new \stdClass(), ['node_id' => '  D  ']];
        $edges = [
            'not-an-array',
            ['from' => 'A'],                       // missing 'to'
            ['from' => '', 'to' => 'B'],            // blank endpoint
            ['from_node_id' => 'A', 'to_node_id' => 'B'], // valid, type omitted
        ];

        $hash = $hasher->hash($nodes, $edges);

        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $hash);

        // Equivalent to the cleaned graph: one node 'D' (trimmed) + one A→B edge
        // with an empty type. Proves junk was dropped, not hashed.
        $clean = $hasher->hash(['D'], [['from' => 'A', 'to' => 'B', 'type' => '']]);
        $this->assertSame($clean, $hash,
            'Malformed entries must be skipped, leaving only the well-formed graph.');
    }

    public function test_whitespace_in_ids_is_trimmed(): void
    {
        $hasher = $this->hasher();

        $padded = $hasher->hash(['  A  ', "B\n"], [['from' => ' A', 'to' => 'B ', 'type' => ' calls ']]);
        $tight = $hasher->hash(['A', 'B'], [['from' => 'A', 'to' => 'B', 'type' => 'calls']]);

        $this->assertSame($tight, $padded,
            'Surrounding whitespace in ids/types must be trimmed before hashing.');
    }

    public function test_missing_type_normalizes_to_a_stable_empty_type(): void
    {
        $hasher = $this->hasher();
        $nodes = ['A', 'B'];

        $omitted = $hasher->hash($nodes, [['from' => 'A', 'to' => 'B']]);
        $explicitEmpty = $hasher->hash($nodes, [['from' => 'A', 'to' => 'B', 'type' => '']]);

        $this->assertSame($explicitEmpty, $omitted,
            'A missing type and an empty type must both normalize to the same triple.');
    }

    // ---- Snapshot contract --------------------------------------------------

    public function test_snapshot_has_the_expected_shape_and_counts(): void
    {
        $hasher = $this->hasher();
        [$nodes, $edges] = $this->sampleGraph();

        $snap = $hasher->snapshot('atlas-server', $nodes, $edges);

        $this->assertSame([
            'workspace_id',
            'graph_hash',
            'node_count',
            'edge_count',
            'algo',
            'schema_version',
        ], array_keys($snap), 'Snapshot must expose exactly the contracted keys in order.');

        $this->assertSame('atlas-server', $snap['workspace_id']);
        $this->assertSame('sha256', $snap['algo']);
        $this->assertSame('atlas.code_graph.integrity.v1', $snap['schema_version']);
        $this->assertSame(3, $snap['node_count']);
        $this->assertSame(3, $snap['edge_count']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $snap['graph_hash']);
        $this->assertSame($hasher->hash($nodes, $edges), $snap['graph_hash'],
            'snapshot graph_hash must equal hash() over the same graph.');
    }

    public function test_snapshot_counts_reflect_deduplicated_graph(): void
    {
        $hasher = $this->hasher();

        $snap = $hasher->snapshot('ws', ['A', 'A', 'B'], [
            ['from' => 'A', 'to' => 'B', 'type' => 'calls'],
            ['from' => 'A', 'to' => 'B', 'type' => 'calls'], // duplicate
        ]);

        $this->assertSame(2, $snap['node_count'], 'Counts must reflect the de-duplicated node set.');
        $this->assertSame(1, $snap['edge_count'], 'Counts must reflect the de-duplicated edge set.');
    }

    public function test_snapshot_is_order_independent_and_workspace_falls_back(): void
    {
        $hasher = $this->hasher();
        [$nodes, $edges] = $this->sampleGraph();

        $a = $hasher->snapshot('ws', $nodes, $edges);
        $b = $hasher->snapshot('ws', array_reverse($nodes), array_reverse($edges));
        $this->assertSame($a['graph_hash'], $b['graph_hash'],
            'Snapshot hash must be order-independent like hash().');

        $blank = $hasher->snapshot('   ', [], []);
        $this->assertSame('unknown-workspace', $blank['workspace_id'],
            'A blank workspace id must fall back to a stable placeholder.');
        $this->assertSame(0, $blank['node_count']);
        $this->assertSame(0, $blank['edge_count']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $blank['graph_hash']);
    }
}
