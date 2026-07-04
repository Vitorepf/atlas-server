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

    // ── critical_path preservation (AC) ──────────────────────────────────────

    public function test_critical_path_edges_survive_compaction_even_when_transitively_reachable(): void
    {
        // Brain->TaskFabric->Maestro is the canonical circuit; Brain->Maestro is a shortcut
        // that would normally make Brain->TaskFabric (or TaskFabric->Maestro) redundant, but
        // circuit edges must never be removed.
        $r = $this->compactor()->compact([
            'nodes' => [$this->node('Brain'), $this->node('TaskFabric'), $this->node('Maestro')],
            'edges' => [$this->edge('Brain', 'TaskFabric'), $this->edge('TaskFabric', 'Maestro'), $this->edge('Brain', 'Maestro')],
            'critical_path' => ['Brain', 'TaskFabric', 'Maestro'],
        ]);

        $this->assertContains(['from' => 'Brain', 'to' => 'TaskFabric'], $r['compacted_edges']);
        $this->assertContains(['from' => 'TaskFabric', 'to' => 'Maestro'], $r['compacted_edges']);
        $this->assertNotContains(['from' => 'Brain', 'to' => 'TaskFabric'], $r['removed_redundant_edges']);
        $this->assertNotContains(['from' => 'TaskFabric', 'to' => 'Maestro'], $r['removed_redundant_edges']);
    }

    public function test_critical_path_nodes_reported_in_deterministic_declared_order(): void
    {
        $r = $this->compactor()->compact([
            'nodes' => [$this->node('Brain'), $this->node('TaskFabric'), $this->node('Maestro'), $this->node('Proof'), $this->node('Learning')],
            'edges' => [
                $this->edge('Brain', 'TaskFabric'),
                $this->edge('TaskFabric', 'Maestro'),
                $this->edge('Maestro', 'Proof'),
                $this->edge('Proof', 'Learning'),
            ],
            'critical_path' => ['Brain', 'TaskFabric', 'Maestro', 'Proof', 'Learning'],
        ]);

        $this->assertSame(['Brain', 'TaskFabric', 'Maestro', 'Proof', 'Learning'], $r['critical_path_nodes']);
    }

    public function test_critical_path_nodes_excludes_nodes_absent_from_graph(): void
    {
        $r = $this->compactor()->compact([
            'nodes' => [$this->node('Brain'), $this->node('TaskFabric')],
            'edges' => [$this->edge('Brain', 'TaskFabric')],
            'critical_path' => ['Brain', 'TaskFabric', 'GhostNode'],
        ]);

        $this->assertSame(['Brain', 'TaskFabric'], $r['critical_path_nodes']);
    }

    public function test_no_critical_path_supplied_yields_empty_critical_path_nodes(): void
    {
        $r = $this->compactor()->compact([
            'nodes' => [$this->node('A'), $this->node('B')],
            'edges' => [$this->edge('A', 'B')],
        ]);

        $this->assertSame([], $r['critical_path_nodes']);
    }

    // ── cycle detection ──────────────────────────────────────────────────────

    public function test_acyclic_graph_reports_cycle_detected_false(): void
    {
        $r = $this->compactor()->compact([
            'nodes' => [$this->node('A'), $this->node('B'), $this->node('C')],
            'edges' => [$this->edge('A', 'B'), $this->edge('B', 'C')],
        ]);

        $this->assertFalse($r['cycle_detected']);
        $this->assertSame([], $r['cycle_edges']);
    }

    public function test_direct_two_node_cycle_is_detected(): void
    {
        $r = $this->compactor()->compact([
            'nodes' => [$this->node('A'), $this->node('B')],
            'edges' => [$this->edge('A', 'B'), $this->edge('B', 'A')],
        ]);

        $this->assertTrue($r['cycle_detected']);
        $this->assertNotEmpty($r['cycle_edges']);
    }

    public function test_longer_cycle_is_detected_with_back_edge(): void
    {
        // A -> B -> C -> A
        $r = $this->compactor()->compact([
            'nodes' => [$this->node('A'), $this->node('B'), $this->node('C')],
            'edges' => [$this->edge('A', 'B'), $this->edge('B', 'C'), $this->edge('C', 'A')],
        ]);

        $this->assertTrue($r['cycle_detected']);
        $this->assertContains(['from' => 'C', 'to' => 'A'], $r['cycle_edges']);
    }

    public function test_cyclic_graph_never_reports_wave_ready_dependency_layers(): void
    {
        $r = $this->compactor()->compact([
            'nodes' => [$this->node('A'), $this->node('B')],
            'edges' => [$this->edge('A', 'B'), $this->edge('B', 'A')],
        ]);

        $this->assertTrue($r['cycle_detected']);
        $this->assertSame([], $r['dependency_layers']);
    }

    public function test_self_loop_is_detected_as_a_cycle(): void
    {
        $r = $this->compactor()->compact([
            'nodes' => [$this->node('A')],
            'edges' => [$this->edge('A', 'A')],
        ]);

        $this->assertTrue($r['cycle_detected']);
        $this->assertContains(['from' => 'A', 'to' => 'A'], $r['cycle_edges']);
    }

    // ── wave-ready dependency_layers (acyclic) ───────────────────────────────

    public function test_dependency_layers_order_leaf_nodes_first(): void
    {
        // A depends on B and C (both leaves) -> layer0=[B,C], layer1=[A].
        $r = $this->compactor()->compact([
            'nodes' => [$this->node('A'), $this->node('B'), $this->node('C')],
            'edges' => [$this->edge('A', 'B'), $this->edge('A', 'C')],
        ]);

        $this->assertFalse($r['cycle_detected']);
        $this->assertSame([['B', 'C'], ['A']], $r['dependency_layers']);
    }

    public function test_dependency_layers_for_linear_chain(): void
    {
        // A -> B -> C: C has no deps (layer0), B depends only on C (layer1), A depends only on B (layer2).
        $r = $this->compactor()->compact([
            'nodes' => [$this->node('A'), $this->node('B'), $this->node('C')],
            'edges' => [$this->edge('A', 'B'), $this->edge('B', 'C')],
        ]);

        $this->assertSame([['C'], ['B'], ['A']], $r['dependency_layers']);
    }

    public function test_dead_end_orphan_is_included_in_layer_zero(): void
    {
        $r = $this->compactor()->compact([
            'nodes' => [$this->node('A'), $this->node('B'), $this->node('Orphan')],
            'edges' => [$this->edge('A', 'B')],
        ]);

        $this->assertSame(['B', 'Orphan'], $r['dependency_layers'][0]);
    }

    public function test_missing_prerequisite_edges_never_block_layering(): void
    {
        // A depends on B (real) and GHOST (missing) — GHOST must not prevent A from ever
        // reaching a layer; the missing dependency stays a distinct diagnostic.
        $r = $this->compactor()->compact([
            'nodes' => [$this->node('A'), $this->node('B')],
            'edges' => [$this->edge('A', 'B'), $this->edge('A', 'GHOST')],
        ]);

        $this->assertContains('GHOST', $r['missing_prerequisites']);
        $this->assertSame([['B'], ['A']], $r['dependency_layers']);
    }

    public function test_critical_path_chain_is_fully_represented_in_dependency_layers(): void
    {
        $r = $this->compactor()->compact([
            'nodes' => [$this->node('Brain'), $this->node('TaskFabric'), $this->node('Maestro')],
            'edges' => [$this->edge('Brain', 'TaskFabric'), $this->edge('TaskFabric', 'Maestro'), $this->edge('Brain', 'Maestro')],
            'critical_path' => ['Brain', 'TaskFabric', 'Maestro'],
        ]);

        $flatLayers = array_merge(...$r['dependency_layers']);
        foreach ($r['critical_path_nodes'] as $node) {
            $this->assertContains($node, $flatLayers, "critical path node '{$node}' must appear in dependency_layers");
        }
        $this->assertSame([['Maestro'], ['TaskFabric'], ['Brain']], $r['dependency_layers']);
    }

    public function test_empty_graph_yields_empty_dependency_layers(): void
    {
        $r = $this->compactor()->compact([]);

        $this->assertFalse($r['cycle_detected']);
        $this->assertSame([], $r['dependency_layers']);
    }

    // ── AC2: chain compaction for overlapping prerequisites ───────────────────

    public function test_single_prerequisite_no_chain_compaction(): void
    {
        // A→B, A→C: A has two outgoing edges but each is a unique chain — no compaction needed.
        $r = $this->compactor()->compact([
            'nodes' => [
                ['id' => 'A', 'capability_family' => 'fam', 'allowed_files' => ['src/A.php']],
                ['id' => 'B', 'capability_family' => 'fam', 'allowed_files' => ['src/B.php']],
                ['id' => 'C', 'capability_family' => 'fam', 'allowed_files' => ['src/C.php']],
            ],
            'edges' => [$this->edge('A', 'B'), $this->edge('A', 'C')],
        ]);

        $this->assertNotEmpty($r['chain_compaction_groups']);
        $group = $r['chain_compaction_groups'][0];
        $this->assertSame('A', $group['shared_prerequisite']);
        $this->assertSame(2, $group['count']);
        $this->assertContains('B', $group['member_ids']);
        $this->assertContains('C', $group['member_ids']);
    }

    public function test_chain_compaction_blocked_by_mismatched_capability_family(): void
    {
        $r = $this->compactor()->compact([
            'nodes' => [
                ['id' => 'A', 'capability_family' => 'fam1', 'allowed_files' => ['src/A.php']],
                ['id' => 'B', 'capability_family' => 'fam1', 'allowed_files' => ['src/B.php']],
                ['id' => 'C', 'capability_family' => 'fam2', 'allowed_files' => ['src/C.php']],
            ],
            'edges' => [$this->edge('A', 'B'), $this->edge('A', 'C')],
        ]);

        $this->assertNotEmpty($r['preserved_blockers']);
        $this->assertStringContainsString('unsafe_to_compact', implode(' ', $r['preserved_blockers']));
        $this->assertNotEmpty($r['unsafe_to_compact']);
    }

    // ── AC4: preserved_blockers and counts ───────────────────────────────────

    public function test_output_includes_preserved_blockers_and_counts(): void
    {
        $r = $this->compactor()->compact([
            'nodes' => [
                ['id' => 'A', 'capability_family' => 'f', 'allowed_files' => ['src/A.php']],
                ['id' => 'B', 'capability_family' => 'f', 'allowed_files' => ['src/B.php']],
            ],
            'edges' => [$this->edge('A', 'B')],
        ]);

        $this->assertArrayHasKey('preserved_blockers', $r);
        $this->assertArrayHasKey('unsafe_to_compact', $r);
        $this->assertArrayHasKey('chain_compaction_groups', $r);
        $this->assertArrayHasKey('compacted_node_count', $r);
    }

    public function test_no_chain_compaction_for_linear_chain(): void
    {
        // A→B, B→C: each node has one outgoing edge (no overlapping chains).
        $r = $this->compactor()->compact([
            'nodes' => [
                ['id' => 'A', 'capability_family' => 'f', 'allowed_files' => ['src/A.php']],
                ['id' => 'B', 'capability_family' => 'f', 'allowed_files' => ['src/B.php']],
                ['id' => 'C', 'capability_family' => 'f', 'allowed_files' => ['src/C.php']],
            ],
            'edges' => [$this->edge('A', 'B'), $this->edge('B', 'C')],
        ]);

        $this->assertSame([], $r['chain_compaction_groups']);
    }
}
