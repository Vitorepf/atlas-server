<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming;

use App\Services\Ai\Programming\ProgrammingLocalVectorIndex;
use App\Services\Ai\Programming\ProgrammingProfessionalReranker;
use App\Services\Ai\Programming\ProgrammingRetrievalExecutor;
use Tests\TestCase;

/**
 * Focused contract tests for ProgrammingRetrievalExecutor (factory-critical runtime).
 */
final class ProgrammingRetrievalExecutorTest extends TestCase
{
    public function test_focused_unit_test_path_is_same_name_coverage(): void
    {
        $this->assertSame(
            'tests/Unit/Ai/Programming/ProgrammingRetrievalExecutorTest.php',
            ProgrammingRetrievalExecutor::focusedUnitTestPath(),
        );
    }

    public function test_context_pack_schema_version_constant_matches_contract(): void
    {
        $this->assertSame(
            'atlas.programming.context_pack.v1',
            ProgrammingRetrievalExecutor::CONTEXT_PACK_SCHEMA_VERSION,
        );
    }

    public function test_professional_context_pack_schema_version_constant_matches_contract(): void
    {
        $this->assertSame(
            'atlas.programming.context_pack.professional.v1',
            ProgrammingRetrievalExecutor::PROFESSIONAL_CONTEXT_PACK_SCHEMA_VERSION,
        );
    }

    public function test_context_pack_returns_empty_status_for_empty_graph(): void
    {
        $executor = app(ProgrammingRetrievalExecutor::class);

        $pack = $executor->contextPack([]);

        $this->assertSame(ProgrammingRetrievalExecutor::CONTEXT_PACK_SCHEMA_VERSION, $pack['schema_version']);
        $this->assertSame('empty', $pack['status']);
        $this->assertSame([], $pack['ranked_refs']);
        $this->assertTrue($pack['provider_safe']);
    }

    public function test_context_pack_maps_node_kinds_to_expected_sources_and_scores(): void
    {
        $executor = app(ProgrammingRetrievalExecutor::class);

        $pack = $executor->contextPack([
            'nodes' => [
                ['path' => 'app/Foo.php', 'kind' => 'symbol'],
                ['path' => 'docs/bar.md', 'kind' => 'doc'],
                ['path' => 'tests/FooTest.php', 'kind' => 'test'],
            ],
        ]);

        $this->assertSame('ready', $pack['status']);
        $refs = $pack['ranked_refs'];
        $this->assertSame('code_symbols', $refs[0]['source']);
        $this->assertSame('app/Foo.php', $refs[0]['ref']);
        $this->assertSame(95, $refs[0]['score']);
        $this->assertSame('related_tests', $refs[1]['source']);
        $this->assertSame(80, $refs[1]['score']);
        $this->assertSame('canonical_docs', $refs[2]['source']);
        $this->assertSame(75, $refs[2]['score']);
    }

    public function test_context_pack_deduplicates_refs_by_source_and_ref(): void
    {
        $executor = app(ProgrammingRetrievalExecutor::class);

        $pack = $executor->contextPack([
            'nodes' => [
                ['path' => 'app/Foo.php', 'kind' => 'file', 'reason' => 'first'],
                ['path' => 'app/Foo.php', 'kind' => 'file', 'reason' => 'duplicate'],
            ],
        ]);

        $this->assertSame(1, $pack['ranked_ref_count']);
        $this->assertSame('app/Foo.php', $pack['ranked_refs'][0]['ref']);
    }

    public function test_context_pack_includes_stage_receipts_from_previous_receipts(): void
    {
        $executor = app(ProgrammingRetrievalExecutor::class);

        $pack = $executor->contextPack(
            graph: [],
            previousReceipts: [
                ['receipt_id' => 'stage-receipt-1'],
            ],
        );

        $this->assertSame('ready', $pack['status']);
        $this->assertSame('stage_receipts', $pack['ranked_refs'][0]['source']);
        $this->assertSame('stage-receipt-1', $pack['ranked_refs'][0]['ref']);
        $this->assertSame(90, $pack['ranked_refs'][0]['score']);
    }

    public function test_professional_context_pack_emits_professional_contract_with_stubs(): void
    {
        $executor = $this->executorWithStubs(
            semanticRefs: [
                [
                    'source' => 'code_symbols',
                    'ref' => 'app/Example.php',
                    'reason' => 'local_semantic_vector_match',
                    'score' => 0.91,
                    'privacy' => 'provider_safe',
                    'retrieval_channel' => 'local_semantic_vector',
                ],
            ],
            reranked: [
                'ranked_refs' => [
                    [
                        'source' => 'code_symbols',
                        'ref' => 'app/Example.php',
                        'reason' => 'local_semantic_vector_match',
                        'score' => 0.91,
                        'privacy' => 'provider_safe',
                        'retrieval_channel' => 'local_semantic_vector',
                    ],
                ],
                'excluded_refs' => [],
                'metrics' => ['reranker' => 'deterministic_professional_v1'],
            ],
        );

        $pack = $executor->professionalContextPack(
            workspace: base_path(),
            objective: 'agentic retrieval repair',
            flow: 'programming.repair',
            graph: ['nodes' => []],
            queries: [['query' => 'retrieval executor']],
            previousReceipts: [],
            requiredSources: ['prior_decisions', 'known_failures'],
        );

        $this->assertSame(ProgrammingRetrievalExecutor::PROFESSIONAL_CONTEXT_PACK_SCHEMA_VERSION, $pack['schema_version']);
        $this->assertSame('hybrid_graph_semantic', $pack['retrieval_strategy']);
        $this->assertSame('ready', $pack['status']);
        $this->assertTrue($pack['provider_safe']);
        $this->assertNotEmpty($pack['context_pack_hash']);
        $this->assertSame(1, $pack['metrics']['semantic_ref_count']);
        $this->assertSame('code_symbols', $pack['ranked_refs'][0]['source']);
    }

    public function test_professional_context_pack_uses_promoted_graph_rag_strategy_when_refs_present(): void
    {
        $executor = $this->executorWithStubs();

        $pack = $executor->professionalContextPack(
            workspace: base_path(),
            objective: 'review context pack',
            flow: 'programming.review',
            graph: ['nodes' => []],
            queries: [],
            previousReceipts: [],
            requiredSources: [],
            graphRagRefs: [
                [
                    'source' => 'code_symbols',
                    'ref' => 'app/GraphRag.php',
                    'reason' => 'graph_rag_runtime',
                    'score' => 88,
                    'privacy' => 'provider_safe',
                ],
            ],
        );

        $this->assertSame('promoted_programming_graph_rag_semantic', $pack['retrieval_strategy']);
        $this->assertSame(1, $pack['metrics']['graph_rag_ref_count']);
    }

    /**
     * @param  array<int,array<string,mixed>>  $semanticRefs
     * @param  array<string,mixed>|null  $reranked
     */
    private function executorWithStubs(array $semanticRefs = [], ?array $reranked = null): ProgrammingRetrievalExecutor
    {
        $localVectorIndex = $this->createMock(ProgrammingLocalVectorIndex::class);
        $localVectorIndex->method('search')->willReturn($semanticRefs);

        $professionalReranker = $this->createMock(ProgrammingProfessionalReranker::class);
        $professionalReranker->method('rerank')->willReturn($reranked ?? [
            'ranked_refs' => $semanticRefs,
            'excluded_refs' => [],
            'metrics' => ['reranker' => 'deterministic_professional_v1'],
        ]);

        return new ProgrammingRetrievalExecutor($localVectorIndex, $professionalReranker);
    }
}
