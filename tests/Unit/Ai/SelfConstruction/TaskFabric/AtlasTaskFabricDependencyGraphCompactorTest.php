<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\TaskFabric;

use App\Services\Ai\SelfConstruction\TaskFabric\AtlasTaskFabricDependencyGraphCompactor;
use PHPUnit\Framework\TestCase;

final class AtlasTaskFabricDependencyGraphCompactorTest extends TestCase
{
    private function compactor(): AtlasTaskFabricDependencyGraphCompactor
    {
        return new AtlasTaskFabricDependencyGraphCompactor;
    }

    private function node(string $id): array { return ['id' => $id]; }

    private function edge(string $from, string $to): array { return ['from' => $from, 'to' => $to]; }

    // ── AC1: output shape ─────────────────────────────────────────────────────

    public function test_output_has_required_keys(): void
    {
        $r = $this->compactor()->compact([]);
        $this->assertSame(AtlasTaskFabricDependencyGraphCompactor::SCHEMA, $r['schema_version']);
        $this->assertArrayHasKey('compacted_edges', $r);
        $this->assertArrayHasKey('removed_redundant_edges', $r);
        $this->assertArrayHasKey('terminal_prerequisites', $r);
        $this->assertArrayHasKey('missing_prerequisites', $r);
        $this->assertArrayHasKey('dead_end_orphans', $r);
    }

    // ── Transitive reduction ──────────────────────────────────────────────────

    public function test_direct_chain_with_no_redundancy_is_unchanged(): void
    {
        // A→B, B→C — no redundancy.
        $r = $this->compactor()->compact([
            'nodes' => [$this->node('A'), $this->node('B'), $this->node('C')],
            'edges' => [$this->edge('A', 'B'), $this->edge('B', 'C')],
        ]);

        $this->assertCount(2, $r['compacted_edges']);
        $this->assertEmpty($r['removed_redundant_edges']);
        $this->assertSame(2, $r['compacted_edge_count']);
    }

    public function test_transitive_edge_is_removed(): void
    {
        // A→B, B→C, A→C: A→C is redundant (A already reaches C via B).
        $r = $this->compactor()->compact([
            'nodes' => [$this->node('A'), $this->node('B'), $this->node('C')],
            'edges' => [$this->edge('A', 'B'), $this->edge('B', 'C'), $this->edge('A', 'C')],
        ]);

        $this->assertCount(1, $r['removed_redundant_edges']);
        $this->assertSame(['from' => 'A', 'to' => 'C'], $r['removed_redundant_edges'][0]);
        $this->assertCount(2, $r['compacted_edges']);
    }

    public function test_longer_chain_redundancy_removed(): void
    {
        // A→B, B→C, C→D, A→D: A→D is redundant via A→B→C→D.
        $r = $this->compactor()->compact([
            'nodes' => [$this->node('A'), $this->node('B'), $this->node('C'), $this->node('D')],
            'edges' => [
                $this->edge('A', 'B'), $this->edge('B', 'C'),
                $this->edge('C', 'D'), $this->edge('A', 'D'),
            ],
        ]);

        $this->assertCount(1, $r['removed_redundant_edges']);
        $this->assertSame('D', $r['removed_redundant_edges'][0]['to']);
    }

    // ── AC2: separate classification ─────────────────────────────────────────

    public function test_terminal_prerequisites_are_leaf_nodes_with_dependents(): void
    {
        // A→B, A→C: B and C are terminal (no own prerequisites, but are depended upon).
        $r = $this->compactor()->compact([
            'nodes' => [$this->node('A'), $this->node('B'), $this->node('C')],
            'edges' => [$this->edge('A', 'B'), $this->edge('A', 'C')],
        ]);

        $this->assertEqualsCanonicalizing(['B', 'C'], $r['terminal_prerequisites']);
        $this->assertEmpty($r['missing_prerequisites']);
        $this->assertEmpty($r['dead_end_orphans']);
    }

    public function test_missing_prerequisites_are_nodes_absent_from_node_list(): void
    {
        // Edge A→GHOST but GHOST is not in the node list.
        $r = $this->compactor()->compact([
            'nodes' => [$this->node('A')],
            'edges' => [$this->edge('A', 'GHOST')],
        ]);

        $this->assertContains('GHOST', $r['missing_prerequisites']);
        $this->assertNotContains('GHOST', $r['terminal_prerequisites']);
        $this->assertNotContains('GHOST', $r['dead_end_orphans']);
    }

    public function test_dead_end_orphan_has_no_edges_at_all(): void
    {
        // C is in the node list but has no edges (neither as from nor as to).
        $r = $this->compactor()->compact([
            'nodes' => [$this->node('A'), $this->node('B'), $this->node('C')],
            'edges' => [$this->edge('A', 'B')],
        ]);

        $this->assertContains('C', $r['dead_end_orphans']);
        $this->assertNotContains('C', $r['terminal_prerequisites']);
        $this->assertNotContains('C', $r['missing_prerequisites']);
    }

    public function test_missing_and_terminal_are_always_separate_sets(): void
    {
        $r = $this->compactor()->compact([
            'nodes' => [$this->node('A'), $this->node('B')],
            'edges' => [$this->edge('A', 'B'), $this->edge('A', 'GHOST')],
        ]);

        // B is terminal (in graph, no own prerequisites); GHOST is missing.
        $this->assertContains('B', $r['terminal_prerequisites']);
        $this->assertContains('GHOST', $r['missing_prerequisites']);
        $this->assertNotContains('GHOST', $r['terminal_prerequisites']);
        $this->assertNotContains('B', $r['missing_prerequisites']);
    }

    // ── Edge count accounting ─────────────────────────────────────────────────

    public function test_original_and_compacted_counts_are_accurate(): void
    {
        $r = $this->compactor()->compact([
            'nodes' => [$this->node('A'), $this->node('B'), $this->node('C')],
            'edges' => [$this->edge('A', 'B'), $this->edge('B', 'C'), $this->edge('A', 'C')],
        ]);

        $this->assertSame(3, $r['original_edge_count']);
        $this->assertSame(2, $r['compacted_edge_count']);
    }

    public function test_output_is_deterministic(): void
    {
        $facts = [
            'nodes' => [$this->node('X'), $this->node('Y'), $this->node('Z'), $this->node('W')],
            'edges' => [$this->edge('X', 'Y'), $this->edge('Y', 'Z'), $this->edge('X', 'Z')],
        ];
        $a = $this->compactor()->compact($facts);
        $b = $this->compactor()->compact($facts);
        $this->assertSame(json_encode($a), json_encode($b));
    }
}
