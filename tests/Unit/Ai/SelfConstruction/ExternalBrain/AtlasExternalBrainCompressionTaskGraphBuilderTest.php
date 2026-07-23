<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainCompressionTaskGraphBuilder;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainCompressionTaskGraphBuilderTest extends TestCase
{
    private function builder(): AtlasExternalBrainCompressionTaskGraphBuilder
    {
        return new AtlasExternalBrainCompressionTaskGraphBuilder;
    }

    public function test_ordered_graph_case_all_proof_present_admits_compress(): void
    {
        $r = $this->builder()->build([
            'candidates' => [
                [
                    'candidate_id'          => 'delete:organ_a',
                    'action'                => 'delete',
                    'contracts_extracted'   => true,
                    'equivalence_proven'    => true,
                    'mutation_guards_added' => true,
                ],
            ],
        ]);

        $stages = array_column($r['nodes'], 'stage');
        $this->assertSame([
            'extract_contracts',
            'prove_equivalence',
            'add_mutation_guards',
            'compress',
            'knowledge_sync',
        ], $stages);

        $nodesById = [];
        foreach ($r['nodes'] as $node) {
            $nodesById[$node['node_id']] = $node;
        }

        $this->assertSame([], $nodesById['extract_contracts:delete:organ_a']['depends_on']);
        $this->assertSame(['extract_contracts:delete:organ_a'], $nodesById['prove_equivalence:delete:organ_a']['depends_on']);
        $this->assertSame(['prove_equivalence:delete:organ_a'], $nodesById['add_mutation_guards:delete:organ_a']['depends_on']);
        $this->assertSame(['add_mutation_guards:delete:organ_a'], $nodesById['compress:delete:organ_a']['depends_on']);
        $this->assertSame(['compress:delete:organ_a'], $nodesById['knowledge_sync:delete:organ_a']['depends_on']);

        $this->assertTrue($nodesById['compress:delete:organ_a']['admitted']);
        $this->assertSame([], $r['blocked_compress_nodes']);
    }

    public function test_missing_prereq_block_case(): void
    {
        $r = $this->builder()->build([
            'candidates' => [
                [
                    'candidate_id'        => 'merge:group_1',
                    'action'              => 'merge',
                    'contracts_extracted' => true,
                    // equivalence_proven absent entirely — missing evidence
                    'mutation_guards_added' => true,
                ],
            ],
        ]);

        $nodesById = [];
        foreach ($r['nodes'] as $node) {
            $nodesById[$node['node_id']] = $node;
        }

        $this->assertFalse($nodesById['compress:merge:group_1']['admitted']);
        $this->assertContains('prove_equivalence_missing', $nodesById['compress:merge:group_1']['blocking_reasons']);
        $this->assertContains('compress:merge:group_1', $r['blocked_compress_nodes']);
        $this->assertSame('missing', $nodesById['prove_equivalence:merge:group_1']['status']);
    }

    public function test_failed_proof_blocks_compress_with_failed_reason(): void
    {
        $r = $this->builder()->build([
            'candidates' => [
                [
                    'candidate_id'          => 'simplify:organ_b',
                    'action'                => 'simplify',
                    'contracts_extracted'   => true,
                    'equivalence_proven'    => false,
                    'mutation_guards_added' => true,
                ],
            ],
        ]);

        $nodesById = [];
        foreach ($r['nodes'] as $node) {
            $nodesById[$node['node_id']] = $node;
        }

        $this->assertFalse($nodesById['compress:simplify:organ_b']['admitted']);
        $this->assertContains('prove_equivalence_failed', $nodesById['compress:simplify:organ_b']['blocking_reasons']);
        $this->assertSame('failed', $nodesById['prove_equivalence:simplify:organ_b']['status']);
    }

    public function test_keep_action_candidate_is_never_graphed(): void
    {
        $r = $this->builder()->build([
            'candidates' => [
                ['candidate_id' => 'keep:organ_c', 'action' => 'keep'],
            ],
        ]);

        $this->assertSame([], $r['nodes']);
        $this->assertSame([], $r['blocked_compress_nodes']);
    }

    public function test_empty_candidates_produces_empty_graph(): void
    {
        $r = $this->builder()->build([]);

        $this->assertSame([], $r['nodes']);
        $this->assertSame([], $r['blocked_compress_nodes']);
    }

    public function test_schema_present(): void
    {
        $r = $this->builder()->build([]);

        $this->assertSame(AtlasExternalBrainCompressionTaskGraphBuilder::SCHEMA, $r['schema']);
    }
}
