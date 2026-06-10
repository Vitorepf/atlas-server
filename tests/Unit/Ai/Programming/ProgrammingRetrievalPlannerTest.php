<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming;

use App\Services\Ai\Programming\ProgrammingContextPackStore;
use App\Services\Ai\Programming\ProgrammingFlowNames;
use App\Services\Ai\Programming\ProgrammingGapCritic;
use App\Services\Ai\Programming\ProgrammingGraphRagRuntime;
use App\Services\Ai\Programming\ProgrammingPythonRuntimeContract;
use App\Services\Ai\Programming\ProgrammingRetrievalEvaluator;
use App\Services\Ai\Programming\ProgrammingRetrievalExecutor;
use App\Services\Ai\Programming\ProgrammingRetrievalPlanner;
use App\Services\Ai\Programming\ProgrammingSemanticCodeGraphService;
use Tests\TestCase;

/**
 * Focused contract tests for ProgrammingRetrievalPlanner (factory-critical runtime).
 */
final class ProgrammingRetrievalPlannerTest extends TestCase
{
    public function test_focused_unit_test_path_is_same_name_coverage(): void
    {
        $this->assertSame(
            'tests/Unit/Ai/Programming/ProgrammingRetrievalPlannerTest.php',
            ProgrammingRetrievalPlanner::focusedUnitTestPath(),
        );
    }

    public function test_schema_version_constant_matches_plan_contract(): void
    {
        $this->assertSame('atlas.programming.agentic_rag.plan.v1', ProgrammingRetrievalPlanner::SCHEMA_VERSION);
    }

    public function test_programming_flow_names_canonicalizes_without_double_prefix(): void
    {
        $this->assertSame('programming.repair', ProgrammingFlowNames::canonical('repair'));
        $this->assertSame('programming.repair', ProgrammingFlowNames::canonical('programming.repair'));
    }

    public function test_plan_canonicalizes_flow_without_programming_prefix(): void
    {
        $planner = $this->plannerWithStubs(
            graph: ['complete' => true, 'related_tests' => ['tests/Unit/ExampleTest.php']],
            professionalContextPack: [
                'status' => 'ready',
                'context_pack_hash' => str_repeat('b', 64),
                'ranked_refs' => [
                    ['ref' => 'app/Example.php', 'source' => 'code_symbols'],
                ],
                'source_counts' => ['related_tests' => 1, 'code_symbols' => 1],
            ],
        );

        $plan = $planner->plan(
            planId: 'plan-canonical-flow',
            workspace: base_path(),
            objective: 'validate flow prefix normalization',
            flow: 'repair',
        );

        $this->assertSame(ProgrammingRetrievalPlanner::SCHEMA_VERSION, $plan['schema_version']);
        $this->assertSame('programming.repair', $plan['flow']);
    }

    public function test_plan_clears_related_tests_unavailable_when_professional_pack_has_related_tests(): void
    {
        $planner = $this->plannerWithStubs(
            graph: ['complete' => true, 'related_tests' => []],
            professionalContextPack: [
                'status' => 'ready',
                'context_pack_hash' => str_repeat('c', 64),
                'ranked_refs' => [
                    ['ref' => 'tests/Unit/Ai/Programming/ExampleTest.php', 'source' => 'related_tests'],
                ],
                'source_counts' => ['related_tests' => 1, 'code_symbols' => 1],
            ],
        );

        $plan = $planner->plan(
            planId: 'plan-related-tests-clear',
            workspace: base_path(),
            objective: 'repair retrieval planner related test gap handling',
            flow: 'programming.repair',
            options: [
                'previous_stage_receipts' => [
                    ['receipt_id' => 'stage-receipt-1', 'schema_version' => 'atlas.programming.stage_receipt.v1'],
                ],
            ],
        );

        $this->assertNotContains('related_tests_unavailable', $plan['missing_required_sources']);
    }

    public function test_plan_excludes_benchmark_prefixed_refs_from_python_runtime_manifest(): void
    {
        $planner = $this->plannerWithStubs(
            graph: ['complete' => true, 'related_tests' => ['tests/Unit/ExampleTest.php']],
            professionalContextPack: [
                'status' => 'ready',
                'context_pack_hash' => str_repeat('d', 64),
                'ranked_refs' => [
                    ['ref' => 'app/Example.php', 'source' => 'code_symbols'],
                    ['ref' => 'benchmark-retrieval-case', 'source' => 'known_failures'],
                ],
                'source_counts' => ['code_symbols' => 1, 'known_failures' => 1],
            ],
        );

        $plan = $planner->plan(
            planId: 'plan-python-runtime-filter',
            workspace: base_path(),
            objective: 'keep benchmark refs out of python runtime manifest',
            flow: 'programming.review',
        );

        $manifestFiles = (array) data_get($plan, 'python_runtime_contract.manifest.files', []);

        $this->assertContains('app/Example.php', $manifestFiles);
        $this->assertNotContains('benchmark-retrieval-case', $manifestFiles);
    }

    /**
     * @param  array<string,mixed>  $graph
     * @param  array<string,mixed>  $professionalContextPack
     */
    private function plannerWithStubs(array $graph, array $professionalContextPack): ProgrammingRetrievalPlanner
    {
        $codeGraph = $this->createMock(ProgrammingSemanticCodeGraphService::class);
        $codeGraph->method('query')->willReturn(array_merge([
            'schema_version' => 'atlas.programming.semantic_code_graph.context.v1',
            'node_count' => 1,
            'edge_count' => 0,
            'related_docs' => [],
            'complete' => false,
        ], $graph));

        $legacyContextPack = [
            'schema_version' => 'atlas.programming.context_pack.v1',
            'status' => 'ready',
            'ranked_refs' => [],
            'provider_safe' => true,
        ];

        $retrievalExecutor = $this->createMock(ProgrammingRetrievalExecutor::class);
        $retrievalExecutor->method('contextPack')->willReturn($legacyContextPack);
        $retrievalExecutor->method('professionalContextPack')->willReturn($professionalContextPack);

        $gapCritic = $this->createMock(ProgrammingGapCritic::class);
        $gapCritic->method('critique')->willReturn([
            'schema_version' => 'atlas.programming.agentic_rag.gap_critic.v1',
            'status' => 'passed',
            'missing_sources' => [],
            'blocks_execution' => false,
        ]);

        $retrievalEvaluator = $this->createMock(ProgrammingRetrievalEvaluator::class);
        $retrievalEvaluator->method('evaluate')->willReturn([
            'schema_version' => 'atlas.programming.retrieval_eval.v1',
            'status' => 'passed',
        ]);

        $contextPackStore = $this->createMock(ProgrammingContextPackStore::class);
        $contextPackStore->method('persist')->willReturnCallback(
            fn (string $planId, ?string $parentPlanId, array $contextPack): array => $contextPack,
        );

        $graphRagRuntime = $this->createMock(ProgrammingGraphRagRuntime::class);
        $graphRagRuntime->method('retrieve')->willReturn([
            'schema_version' => 'atlas.programming.graph_rag_runtime.v1',
            'status' => 'ready',
            'evidence_refs' => [],
            'evidence_ref_count' => 0,
        ]);

        return new ProgrammingRetrievalPlanner(
            codeGraph: $codeGraph,
            retrievalExecutor: $retrievalExecutor,
            gapCritic: $gapCritic,
            retrievalEvaluator: $retrievalEvaluator,
            contextPackStore: $contextPackStore,
            pythonRuntimeContract: app(ProgrammingPythonRuntimeContract::class),
            graphRagRuntime: $graphRagRuntime,
        );
    }
}
