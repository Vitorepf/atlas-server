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

    // ── AC: producer-consumer edges define boundaries even with dissimilar filenames ──

    public function test_crossing_edge_defines_boundary_between_dissimilarly_named_files(): void
    {
        $graph = $this->fullGraph();
        $graph['crossing_edges'] = [
            ['source' => 'app/Services/Ai/Zorblax.php', 'target' => 'app/Services/Ai/Bar.php', 'contract' => 'BarInterface'],
        ];
        $graph['allowed_files'] = ['app/Services/Ai/Zorblax.php', 'app/Services/Ai/Bar.php'];
        $graph['filename_similarity_candidates'] = [
            ['a' => 'app/Services/Ai/Zorblax.php', 'b' => 'app/Services/Ai/Bar.php'],
        ];

        $result = (new AtlasSelfConstructionCircuitBoundaryExtractor)->extract($graph);

        $this->assertSame([], $result['weak_boundary_evidence'], 'a real crossing edge backs this pair, not just filename similarity');
    }

    // ── AC: filename-only similarity without call edges is weak_boundary_evidence ──

    public function test_filename_similarity_candidate_without_crossing_edge_is_weak_boundary_evidence(): void
    {
        $graph = $this->fullGraph();
        $graph['filename_similarity_candidates'] = [
            ['a' => 'app/Services/Ai/FooService.php', 'b' => 'app/Services/Ai/FooServiceHelper.php'],
        ];

        $result = (new AtlasSelfConstructionCircuitBoundaryExtractor)->extract($graph);

        $this->assertCount(1, $result['weak_boundary_evidence']);
        $this->assertSame('app/Services/Ai/FooService.php', $result['weak_boundary_evidence'][0]['a']);
        $this->assertSame('app/Services/Ai/FooServiceHelper.php', $result['weak_boundary_evidence'][0]['b']);
        $this->assertSame('filename_only_similarity_without_call_edges', $result['weak_boundary_evidence'][0]['reason']);
    }

    public function test_no_filename_similarity_candidates_yields_empty_weak_boundary_evidence(): void
    {
        $result = (new AtlasSelfConstructionCircuitBoundaryExtractor)->extract($this->fullGraph());

        $this->assertSame([], $result['weak_boundary_evidence']);
    }

    // ── AC: extracted boundaries include proof_paths and external_consumers ────

    public function test_proof_paths_include_allowed_files_and_tests(): void
    {
        $result = (new AtlasSelfConstructionCircuitBoundaryExtractor)->extract($this->fixtureWithFullBoundaryProof());

        foreach (['app/Services/Ai/Foo.php', 'app/Services/Ai/Bar.php', 'FooServiceTest::test_handles'] as $path) {
            $this->assertContains($path, $result['proof_paths']);
        }
    }

    public function test_external_consumers_lists_crossing_edge_targets_outside_allowed_files(): void
    {
        $graph = $this->fullGraph();
        $graph['crossing_edges'] = [
            ['source' => 'app/Services/Ai/Foo.php', 'target' => 'app/Services/Ai/OutsideCircuit.php', 'contract' => 'OutsideInterface'],
        ];

        $result = (new AtlasSelfConstructionCircuitBoundaryExtractor)->extract($graph);

        $this->assertSame(['app/Services/Ai/OutsideCircuit.php'], $result['external_consumers']);
    }

    public function test_external_consumers_empty_when_all_crossing_edges_stay_in_scope(): void
    {
        $result = (new AtlasSelfConstructionCircuitBoundaryExtractor)->extract($this->fullGraph());

        $this->assertSame([], $result['external_consumers']);
    }
}
