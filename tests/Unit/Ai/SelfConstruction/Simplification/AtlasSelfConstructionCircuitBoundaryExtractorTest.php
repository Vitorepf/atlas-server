<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Simplification;

use App\Services\Ai\SelfConstruction\Simplification\AtlasSelfConstructionCircuitBoundaryExtractor;
use PHPUnit\Framework\TestCase;

final class AtlasSelfConstructionCircuitBoundaryExtractorTest extends TestCase
{
    private function fullGraph(): array
    {
        return [
            'entrypoints' => ['AtlasFooCommand::handle'],
            'outputs' => ['payload.status'],
            'ledgers' => ['atlas_foo_ledger'],
            'consumers' => ['AtlasBarService'],
            'allowed_files' => ['app/Services/Ai/Foo.php', 'app/Services/Ai/Bar.php'],
            'crossing_edges' => [
                ['source' => 'app/Services/Ai/Foo.php', 'target' => 'app/Services/Ai/Bar.php', 'contract' => 'BarInterface'],
            ],
        ];
    }

    public function test_extracts_entrypoints_outputs_ledgers_consumers_and_crossing_edges(): void
    {
        $result = (new AtlasSelfConstructionCircuitBoundaryExtractor)->extract($this->fullGraph());

        $this->assertSame(['AtlasFooCommand::handle'], $result['entrypoints']);
        $this->assertSame(['payload.status'], $result['outputs']);
        $this->assertSame(['atlas_foo_ledger'], $result['ledgers']);
        $this->assertSame(['AtlasBarService'], $result['consumers']);
        $this->assertSame(
            [['source' => 'app/Services/Ai/Foo.php', 'target' => 'app/Services/Ai/Bar.php', 'contract' => 'BarInterface']],
            $result['crossing_edges'],
        );
        $this->assertTrue($result['boundary_confident']);
        $this->assertSame([], $result['consolidation_blockers']);
    }

    public function test_boundary_confident_false_when_entrypoints_unknown(): void
    {
        $graph = $this->fullGraph();
        unset($graph['entrypoints']);

        $result = (new AtlasSelfConstructionCircuitBoundaryExtractor)->extract($graph);

        $this->assertFalse($result['boundary_confident']);
        $this->assertContains('entrypoints', $result['unknown_facts']);
    }

    public function test_boundary_confident_false_when_outputs_unknown(): void
    {
        $graph = $this->fullGraph();
        unset($graph['outputs']);

        $result = (new AtlasSelfConstructionCircuitBoundaryExtractor)->extract($graph);

        $this->assertFalse($result['boundary_confident']);
        $this->assertContains('outputs', $result['unknown_facts']);
    }

    public function test_boundary_confident_false_when_ledgers_unknown(): void
    {
        $graph = $this->fullGraph();
        unset($graph['ledgers']);

        $result = (new AtlasSelfConstructionCircuitBoundaryExtractor)->extract($graph);

        $this->assertFalse($result['boundary_confident']);
        $this->assertContains('ledgers', $result['unknown_facts']);
    }

    public function test_empty_ledgers_array_is_still_confident(): void
    {
        $graph = $this->fullGraph();
        $graph['ledgers'] = [];

        $result = (new AtlasSelfConstructionCircuitBoundaryExtractor)->extract($graph);

        $this->assertTrue($result['boundary_confident'], 'explicit empty array means known-no-ledgers, not unknown');
    }

    public function test_crossing_edge_outside_allowed_files_is_consolidation_blocker(): void
    {
        $graph = $this->fullGraph();
        $graph['crossing_edges'] = [
            ['source' => 'app/Services/Ai/Foo.php', 'target' => 'app/Services/Ai/OutsideCircuit.php', 'contract' => 'OutsideInterface'],
        ];

        $result = (new AtlasSelfConstructionCircuitBoundaryExtractor)->extract($graph);

        $this->assertSame(
            ['crossing_edge_outside_allowed_files:app/Services/Ai/OutsideCircuit.php'],
            $result['consolidation_blockers'],
        );
    }

    // ── AC: malformed crossing edges are explicit consolidation blockers ───────

    public function test_crossing_edge_without_target_is_blocked_as_missing_target(): void
    {
        $graph = $this->fullGraph();
        $graph['crossing_edges'] = [
            ['source' => 'app/Services/Ai/Foo.php', 'contract' => 'BarInterface'],
        ];

        $result = (new AtlasSelfConstructionCircuitBoundaryExtractor)->extract($graph);

        $this->assertContains('malformed_crossing_edge_missing_target', $result['consolidation_blockers']);
    }

    public function test_crossing_edge_without_source_or_contract_is_blocked_with_explicit_reasons(): void
    {
        $graph = $this->fullGraph();
        $graph['crossing_edges'] = [
            ['target' => 'app/Services/Ai/Bar.php'],
        ];

        $result = (new AtlasSelfConstructionCircuitBoundaryExtractor)->extract($graph);

        $this->assertContains('malformed_crossing_edge_missing_source', $result['consolidation_blockers']);
        $this->assertContains('malformed_crossing_edge_missing_contract', $result['consolidation_blockers']);
    }

    public function test_well_formed_in_scope_crossing_edges_do_not_block_and_preserve_safe_to_collapse(): void
    {
        $result = (new AtlasSelfConstructionCircuitBoundaryExtractor)->extract($this->fixtureWithFullBoundaryProof());

        $this->assertSame([], $result['consolidation_blockers']);
        $this->assertTrue($result['safe_to_collapse']);
    }

    private function fixtureWithFullBoundaryProof(): array
    {
        return array_merge($this->fullGraph(), [
            'public_methods' => ['handle', 'invoke'],
            'private_helpers' => ['normalize'],
            'consumers' => ['AtlasBarCommand'],
            'side_effects' => [
                ['type' => 'db', 'description' => 'writes atlas_foo_ledger'],
                ['type' => 'file', 'description' => 'writes storage/foo.json'],
            ],
            'tests' => ['FooServiceTest::test_handles'],
        ]);
    }

    public function test_fixture_with_full_proof_returns_all_five_boundary_sections(): void
    {
        $result = (new AtlasSelfConstructionCircuitBoundaryExtractor)->extract($this->fixtureWithFullBoundaryProof());

        $this->assertSame(['handle', 'invoke'], $result['public_methods']);
        $this->assertSame(['normalize'], $result['private_helpers']);
        $this->assertSame(['AtlasBarCommand'], $result['consumers']);
        $this->assertCount(2, $result['side_effects']);
        $this->assertSame(['FooServiceTest::test_handles'], $result['tests']);
        $this->assertTrue($result['safe_to_collapse']);
        $this->assertFalse($result['unsafe_to_collapse']);
        $this->assertSame([], $result['missing_boundary_proof']);
    }

    public function test_missing_public_methods_consumers_side_effects_or_tests_blocks_collapse(): void
    {
        $result = (new AtlasSelfConstructionCircuitBoundaryExtractor)->extract($this->fullGraph());

        $this->assertTrue($result['unsafe_to_collapse']);
        $this->assertFalse($result['safe_to_collapse']);
        $this->assertContains('missing_public_methods', $result['missing_boundary_proof']);
        $this->assertContains('missing_side_effect_inventory', $result['missing_boundary_proof']);
        $this->assertContains('missing_test_anchors', $result['missing_boundary_proof']);
    }

    public function test_output_is_deterministic_for_same_input(): void
    {
        $graph = $this->fixtureWithFullBoundaryProof();
        $extractor = new AtlasSelfConstructionCircuitBoundaryExtractor;

        $this->assertSame($extractor->extract($graph), $extractor->extract($graph));
    }
}
