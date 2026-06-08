<?php

namespace Tests\Unit\Engineering\CodeGraph;

use App\Services\Engineering\CodeGraph\CodeGraphDocReconcileBridge;
use App\Services\Engineering\CodeGraph\CodeGraphEdgeResolver;
use PHPUnit\Framework\TestCase;

class CodeGraphDocReconcileBridgeTest extends TestCase
{
    /**
     * Resolved code-graph edges (shape of CodeGraphEdgeResolver::resolve()['edges']).
     *
     * - A strong EXTRACTED edge Router -> Memory (both endpoints documented).
     * - A strong EXTRACTED edge Orphan -> Hidden (NEITHER endpoint documented)
     *   -> should surface as direction (b).
     * - A weak INFERRED edge Helper -> Memory (must NOT count as a strong edge).
     *
     * @return array<int,array<string,mixed>>
     */
    private function codeEdges(): array
    {
        return [
            [
                'from_node_id' => 'node:app/Services/Ai/Router',
                'to_node_id' => 'node:app/Services/Memory/Core',
                'edge_type' => 'depends_on',
                'confidence' => CodeGraphEdgeResolver::CONFIDENCE_EXTRACTED,
                'confidence_score' => 1.0,
                'metadata' => ['confidence' => 'EXTRACTED'],
            ],
            [
                'from_node_id' => 'node:app/Services/Orphan/Engine',
                'to_node_id' => 'node:app/Services/Hidden/Sink',
                'edge_type' => 'depends_on',
                'confidence' => CodeGraphEdgeResolver::CONFIDENCE_EXTRACTED,
                'confidence_score' => 1.0,
                'metadata' => ['confidence' => 'EXTRACTED'],
            ],
            [
                'from_node_id' => 'node:app/Support/Helper',
                'to_node_id' => 'node:app/Services/Memory/Core',
                'edge_type' => 'depends_on',
                'confidence' => CodeGraphEdgeResolver::CONFIDENCE_INFERRED,
                'confidence_score' => 0.85,
                'metadata' => ['confidence' => 'INFERRED'],
            ],
        ];
    }

    /**
     * Persisted doc_links (shape of atlas_engineering_doc_links rows).
     *
     * - Router + Memory targets ARE documented (cover the strong Router->Memory edge).
     * - A doc claims app/Services/Ghost/Service, which NO edge touches
     *   -> should surface as direction (a).
     * - A module_capability link to app/Services/Orphan claims nothing concrete
     *   -> must NOT surface (capability links don't assert a code edge).
     *
     * @return array<int,array<string,mixed>>
     */
    private function docLinks(): array
    {
        return [
            [
                'id' => 1,
                'status' => 'current',
                'canonical_path' => 'docs/engineering-knowledge-base/router.md',
                'target_path' => 'app/Services/Ai/Router',
                'target_hash' => 'aaa',
                'link_type' => 'module_path',
            ],
            [
                'id' => 2,
                'status' => 'current',
                'canonical_path' => 'docs/engineering-knowledge-base/memory.md',
                'target_path' => 'app/Services/Memory/Core',
                'target_hash' => 'bbb',
                'link_type' => 'module_path',
            ],
            [
                'id' => 3,
                'status' => 'current',
                'canonical_path' => 'docs/engineering-knowledge-base/ghost.md',
                'target_path' => 'app/Services/Ghost/Service',
                'target_hash' => 'ccc',
                'link_type' => 'module_path',
            ],
            [
                'id' => 4,
                'status' => 'current',
                'canonical_path' => 'docs/engineering-knowledge-base/orphan.md',
                'target_path' => 'app/Services/Orphan',
                'target_hash' => 'ddd',
                'link_type' => 'module_capability',
            ],
        ];
    }

    public function test_detects_doc_link_unsupported_by_graph_direction(): void
    {
        $proposals = (new CodeGraphDocReconcileBridge)
            ->proposeReconciliations($this->codeEdges(), $this->docLinks());

        $unsupported = array_values(array_filter(
            $proposals,
            static fn (array $p): bool => $p['direction'] === CodeGraphDocReconcileBridge::DIRECTION_DOC_UNSUPPORTED,
        ));

        // Exactly the Ghost doc_link is uncorroborated. Router/Memory are backed by
        // edges; the Orphan link is a capability link and must be ignored.
        $this->assertCount(1, $unsupported);
        $ghost = $unsupported[0];

        $this->assertSame(CodeGraphDocReconcileBridge::TYPE, $ghost['type']);
        $this->assertSame('doc_drift_vs_graph', $ghost['type']);
        $this->assertStringContainsString('app/Services/Ghost/Service', $ghost['detail']);
        $this->assertStringContainsString('uncorroborated', $ghost['detail']);
        $this->assertSame(3, $ghost['metadata']['signal']['doc_link_id']);
        $this->assertSame('app/Services/Ghost/Service', $ghost['metadata']['signal']['target_path']);
        $this->assertSame(0, $ghost['metadata']['signal']['supporting_edge_count']);

        // Capability link to Orphan must NOT have produced a proposal.
        foreach ($proposals as $proposal) {
            $this->assertNotSame(
                'app/Services/Orphan',
                $proposal['metadata']['signal']['target_path'] ?? null,
            );
        }
    }

    public function test_detects_strong_edge_absent_from_docs_direction(): void
    {
        $proposals = (new CodeGraphDocReconcileBridge)
            ->proposeReconciliations($this->codeEdges(), $this->docLinks());

        $undocumented = array_values(array_filter(
            $proposals,
            static fn (array $p): bool => $p['direction'] === CodeGraphDocReconcileBridge::DIRECTION_EDGE_UNDOCUMENTED,
        ));

        // Only the Orphan -> Hidden strong edge has neither endpoint documented.
        // Router -> Memory is fully documented; Helper -> Memory is INFERRED (not strong).
        $this->assertCount(1, $undocumented);
        $edge = $undocumented[0];

        $this->assertSame(CodeGraphDocReconcileBridge::TYPE, $edge['type']);
        $this->assertStringContainsString('node:app/Services/Orphan/Engine', $edge['detail']);
        $this->assertStringContainsString('node:app/Services/Hidden/Sink', $edge['detail']);
        $this->assertStringContainsString('EXTRACTED', $edge['detail']);
        $this->assertSame('node:app/Services/Orphan/Engine', $edge['metadata']['signal']['from_node_id']);
        $this->assertSame('node:app/Services/Hidden/Sink', $edge['metadata']['signal']['to_node_id']);
        $this->assertSame(CodeGraphEdgeResolver::CONFIDENCE_EXTRACTED, $edge['metadata']['signal']['confidence']);
        $this->assertSame(0, $edge['metadata']['signal']['documented_endpoints']);
    }

    public function test_both_drift_directions_detected_together(): void
    {
        $proposals = (new CodeGraphDocReconcileBridge)
            ->proposeReconciliations($this->codeEdges(), $this->docLinks());

        $directions = array_map(static fn (array $p): string => $p['direction'], $proposals);

        $this->assertContains(CodeGraphDocReconcileBridge::DIRECTION_DOC_UNSUPPORTED, $directions);
        $this->assertContains(CodeGraphDocReconcileBridge::DIRECTION_EDGE_UNDOCUMENTED, $directions);

        // Every proposal is the single reconciliation type.
        foreach ($proposals as $proposal) {
            $this->assertSame('doc_drift_vs_graph', $proposal['type']);
        }
    }

    public function test_every_proposal_is_proposal_only(): void
    {
        $proposals = (new CodeGraphDocReconcileBridge)
            ->proposeReconciliations($this->codeEdges(), $this->docLinks());

        $this->assertNotEmpty($proposals);

        foreach ($proposals as $proposal) {
            // The governance invariant — proposal-only, never auto-reconciled.
            $this->assertArrayHasKey('promotion_allowed', $proposal);
            $this->assertFalse($proposal['promotion_allowed']);
            $this->assertSame(false, $proposal['promotion_allowed']);

            $this->assertArrayHasKey('requires_review', $proposal);
            $this->assertTrue($proposal['requires_review']);
            $this->assertSame(true, $proposal['requires_review']);

            // Restated inside metadata for metadata-only consumers.
            $this->assertFalse($proposal['metadata']['promotion_allowed']);
            $this->assertTrue($proposal['metadata']['requires_review']);

            // Carries a human-readable detail and an auditable bridge schema.
            $this->assertNotEmpty($proposal['detail']);
            $this->assertSame(CodeGraphDocReconcileBridge::SCHEMA, $proposal['metadata']['bridge']);
        }
    }

    public function test_promotion_allowed_is_false_even_when_only_one_direction_fires(): void
    {
        $bridge = new CodeGraphDocReconcileBridge;

        // Only direction (a): a doc claim with zero edges at all.
        $onlyDoc = $bridge->proposeReconciliations([], [[
            'id' => 9,
            'canonical_path' => 'docs/x.md',
            'target_path' => 'app/Services/Nowhere',
            'link_type' => 'module_path',
        ]]);
        $this->assertCount(1, $onlyDoc);
        $this->assertSame(CodeGraphDocReconcileBridge::DIRECTION_DOC_UNSUPPORTED, $onlyDoc[0]['direction']);
        $this->assertFalse($onlyDoc[0]['promotion_allowed']);
        $this->assertTrue($onlyDoc[0]['requires_review']);

        // Only direction (b): a strong edge with no docs at all.
        $onlyEdge = $bridge->proposeReconciliations([[
            'from_node_id' => 'node:app/A',
            'to_node_id' => 'node:app/B',
            'edge_type' => 'depends_on',
            'confidence' => CodeGraphEdgeResolver::CONFIDENCE_EXTRACTED,
            'confidence_score' => 1.0,
        ]], []);
        $this->assertCount(1, $onlyEdge);
        $this->assertSame(CodeGraphDocReconcileBridge::DIRECTION_EDGE_UNDOCUMENTED, $onlyEdge[0]['direction']);
        $this->assertFalse($onlyEdge[0]['promotion_allowed']);
        $this->assertTrue($onlyEdge[0]['requires_review']);
    }

    public function test_inferred_edges_are_never_flagged_as_undocumented(): void
    {
        // A lone INFERRED edge whose endpoints are totally undocumented must NOT
        // produce a "strong edge absent from docs" proposal — only EXTRACTED qualifies.
        $proposals = (new CodeGraphDocReconcileBridge)->proposeReconciliations([
            [
                'from_node_id' => 'node:app/Weak/From',
                'to_node_id' => 'node:app/Weak/To',
                'edge_type' => 'depends_on',
                'confidence' => CodeGraphEdgeResolver::CONFIDENCE_INFERRED,
                'confidence_score' => 0.85,
            ],
            [
                'from_node_id' => 'node:app/Ambig/From',
                'to_node_id' => 'node:app/Ambig/To',
                'edge_type' => 'depends_on',
                'confidence' => CodeGraphEdgeResolver::CONFIDENCE_AMBIGUOUS,
                'confidence_score' => 0.5,
            ],
        ], []);

        $undocumented = array_filter(
            $proposals,
            static fn (array $p): bool => $p['direction'] === CodeGraphDocReconcileBridge::DIRECTION_EDGE_UNDOCUMENTED,
        );
        $this->assertSame([], $undocumented);
    }

    public function test_is_deterministic(): void
    {
        $bridge = new CodeGraphDocReconcileBridge;

        $first = $bridge->proposeReconciliations($this->codeEdges(), $this->docLinks());
        $second = $bridge->proposeReconciliations($this->codeEdges(), $this->docLinks());

        $this->assertSame($first, $second);

        // Stable across a fresh instance too (no hidden instance state).
        $third = (new CodeGraphDocReconcileBridge)
            ->proposeReconciliations($this->codeEdges(), $this->docLinks());
        $this->assertSame($first, $third);
    }

    public function test_malformed_rows_are_skipped_safely(): void
    {
        $bridge = new CodeGraphDocReconcileBridge;

        $proposals = $bridge->proposeReconciliations(
            [
                'not-an-array',
                ['from_node_id' => 'node:a'], // missing to -> skipped
                ['to_node_id' => 'node:b'],   // missing from -> skipped
                [
                    'from_node_id' => 'node:app/Real/From',
                    'to_node_id' => 'node:app/Real/To',
                    'edge_type' => 'depends_on',
                    'confidence' => CodeGraphEdgeResolver::CONFIDENCE_EXTRACTED,
                    'confidence_score' => 1.0,
                ],
            ],
            [
                'not-an-array',
                ['id' => 1, 'canonical_path' => 'docs/x.md'], // no target_path -> skipped
                ['id' => 2, 'target_path' => '   '],          // blank target -> skipped
            ],
        );

        // Only the valid strong, undocumented edge survives.
        $this->assertCount(1, $proposals);
        $this->assertSame(
            CodeGraphDocReconcileBridge::DIRECTION_EDGE_UNDOCUMENTED,
            $proposals[0]['direction'],
        );
        $this->assertFalse($proposals[0]['promotion_allowed']);
        $this->assertTrue($proposals[0]['requires_review']);
    }

    public function test_empty_inputs_produce_no_proposals(): void
    {
        $this->assertSame([], (new CodeGraphDocReconcileBridge)->proposeReconciliations([], []));
    }

    public function test_max_per_direction_caps_each_direction(): void
    {
        $bridge = new CodeGraphDocReconcileBridge;

        $edges = [];
        $docs = [];
        for ($i = 0; $i < 20; $i++) {
            // 20 strong edges, all undocumented -> 20 candidates for direction (b).
            $edges[] = [
                'from_node_id' => "node:app/E{$i}/From",
                'to_node_id' => "node:app/E{$i}/To",
                'edge_type' => 'depends_on',
                'confidence' => CodeGraphEdgeResolver::CONFIDENCE_EXTRACTED,
                'confidence_score' => 1.0,
            ];
            // 20 doc claims, none corroborated -> 20 candidates for direction (a).
            $docs[] = [
                'id' => $i,
                'canonical_path' => "docs/d{$i}.md",
                'target_path' => "app/Ghost/G{$i}",
                'link_type' => 'module_path',
            ];
        }

        $proposals = $bridge->proposeReconciliations($edges, $docs, maxPerDirection: 5);

        $counts = array_count_values(array_map(
            static fn (array $p): string => $p['direction'],
            $proposals,
        ));
        $this->assertSame(5, $counts[CodeGraphDocReconcileBridge::DIRECTION_DOC_UNSUPPORTED]);
        $this->assertSame(5, $counts[CodeGraphDocReconcileBridge::DIRECTION_EDGE_UNDOCUMENTED]);
        $this->assertCount(10, $proposals);
    }
}
