<?php

namespace App\Services\Ai\Programming;

class ProgrammingRetrievalBenchmarkService
{
    /**
     * @var array<string,array<string,mixed>>
     */
    private static array $runtimeCache = [];

    public function __construct(
        private readonly ProgrammingRetrievalPlanner $retrievalPlanner,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function run(string $workspace, bool $refresh = false): array
    {
        $cacheKey = hash('sha256', $workspace.'|programming_retrieval_golden_set_v1');
        if (! $refresh && isset(self::$runtimeCache[$cacheKey])) {
            return array_merge(self::$runtimeCache[$cacheKey], [
                'runtime_cache' => $this->runtimeCachePacket(hit: true, refresh: false),
                'created_at' => now()->toJSON(),
            ]);
        }

        $cases = $this->goldenCases();
        $results = collect($cases)
            ->map(fn (array $case): array => $this->runCase($workspace, $case))
            ->values()
            ->all();

        $recall = collect($results)->avg('recall_at_k') ?? 0.0;
        $precision = collect($results)->avg('precision_at_k') ?? 0.0;
        $blocked = collect($results)->where('status', 'failed')->count();
        $graphRagPromoted = collect($results)->every(fn (array $result): bool => data_get($result, 'graph_rag_runtime.status') === 'promoted'
            && data_get($result, 'graph_rag_runtime.runtime_scope') === 'programming_only'
            && (int) data_get($result, 'graph_rag_runtime.evidence_ref_count', 0) > 0);
        $minimumRecall = 0.9;
        $minimumPrecision = 0.2;
        $status = $blocked === 0 && $recall >= $minimumRecall && $precision >= $minimumPrecision && $graphRagPromoted ? 'passed' : 'failed';

        $report = [
            'schema_version' => 'atlas.programming.retrieval_benchmark.v1',
            'status' => $status,
            'benchmark_id' => hash('sha256', $workspace.'|programming_retrieval_golden_set_v1'),
            'golden_set' => [
                'name' => 'programming_retrieval_golden_set_v1',
                'case_count' => count($cases),
                'source' => 'repo_canonical_programming_cases',
            ],
            'metrics' => [
                'recall_at_k' => round((float) $recall, 4),
                'precision_at_k' => round((float) $precision, 4),
                'minimum_recall_at_k' => $minimumRecall,
                'minimum_precision_at_k' => $minimumPrecision,
                'failed_case_count' => $blocked,
            ],
            'promotion_gate' => [
                'professional_promotion_allowed' => $status === 'passed',
                'graph_rag_runtime_promoted' => $graphRagPromoted,
                'graph_rag_runtime_scope' => 'programming_only',
                'requires_rivals_programming' => true,
                'reason' => $status === 'passed'
                    ? 'local_retrieval_and_programming_graph_rag_runtime_passed_rivals_programming_still_required'
                    : ($graphRagPromoted ? 'local_retrieval_golden_set_failed' : 'programming_graph_rag_runtime_not_promoted'),
            ],
            'cases' => $results,
            'runtime_cache' => $this->runtimeCachePacket(hit: false, refresh: $refresh),
            'created_at' => now()->toJSON(),
        ];

        self::$runtimeCache[$cacheKey] = $report;

        return $report;
    }

    /**
     * @return array<string,mixed>
     */
    private function runtimeCachePacket(bool $hit, bool $refresh): array
    {
        return [
            'schema_version' => 'atlas.programming.retrieval_benchmark_runtime_cache.v1',
            'scope' => 'process_memory_only',
            'hit' => $hit,
            'refresh_requested' => $refresh,
            'persistent_cache' => false,
            'provider_state_cached' => false,
        ];
    }

    /**
     * @param  array<string,mixed>  $case
     * @return array<string,mixed>
     */
    private function runCase(string $workspace, array $case): array
    {
        $plan = $this->retrievalPlanner->plan(
            planId: 'benchmark-'.$case['id'],
            workspace: $workspace,
            objective: (string) $case['objective'],
            flow: (string) $case['flow'],
            options: [
                'quality_required' => false,
                'max_refs' => 24,
                'max_chars' => 20000,
                'previous_stage_receipts' => $case['previous_stage_receipts'] ?? [],
            ],
        );

        $rankedRefs = collect((array) data_get($plan, 'professional_context_pack.ranked_refs', []));
        $rankedPaths = $rankedRefs->pluck('ref')->map(fn ($ref): string => (string) $ref)->all();
        $expectedRefs = (array) $case['expected_refs'];
        $hits = collect($expectedRefs)
            ->filter(fn (string $expected): bool => in_array($expected, $rankedPaths, true))
            ->values()
            ->all();

        $recall = count($expectedRefs) > 0 ? count($hits) / count($expectedRefs) : 1.0;
        $precision = $rankedRefs->isEmpty() ? 0.0 : count($hits) / $rankedRefs->count();

        return [
            'case_id' => $case['id'],
            'status' => $recall >= (float) ($case['min_recall'] ?? 0.5) ? 'passed' : 'failed',
            'flow' => $case['flow'],
            'objective_hash' => hash('sha256', (string) $case['objective']),
            'expected_refs' => $expectedRefs,
            'hit_refs' => $hits,
            'missed_refs' => array_values(array_diff($expectedRefs, $hits)),
            'recall_at_k' => round($recall, 4),
            'precision_at_k' => round($precision, 4),
            'context_pack_hash' => data_get($plan, 'professional_context_pack.context_pack_hash'),
            'gap_critic_status' => data_get($plan, 'gap_critic.status'),
            'graph_rag_runtime' => [
                'schema_version' => data_get($plan, 'graph_rag_runtime.schema_version'),
                'status' => data_get($plan, 'graph_rag_runtime.status'),
                'runtime_scope' => data_get($plan, 'graph_rag_runtime.runtime_scope'),
                'evidence_ref_count' => data_get($plan, 'graph_rag_runtime.evidence_ref_count'),
                'artifact_hash' => data_get($plan, 'graph_rag_runtime.artifact_hash'),
            ],
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function goldenCases(): array
    {
        return [
            [
                'id' => 'agentic-rag-planner-contract',
                'flow' => 'programming.review',
                'objective' => 'review ProgrammingRetrievalPlanner professional agentic rag context pack contract',
                'expected_refs' => [
                    'docs/engineering-knowledge-base/domains/programming-agentic-rag-professional-spec.md',
                    'app/Services/Ai/Programming/ProgrammingRetrievalPlanner.php',
                    'app/Services/Ai/Programming/ProgrammingRetrievalExecutor.php',
                    'docs/engineering-knowledge-base/domains/programming-professional-completion-audit.md',
                    'app/Services/Ai/Programming/ProgrammingProfessionalReranker.php',
                    'app/Services/Ai/Programming/ProgrammingContextPackStore.php',
                    'app/Services/Ai/Programming/ProgrammingRetrievalEvaluator.php',
                ],
                'min_recall' => 0.7,
            ],
            [
                'id' => 'enterprise-runtime-test-coverage',
                'flow' => 'programming.qa',
                'objective' => 'validate ProgrammingEnterpriseRuntimeTest covers stage receipts resume retrieval and learning contracts',
                'expected_refs' => [
                    'tests/Unit/Ai/Programming/ProgrammingEnterpriseRuntimeTest.php',
                    'docs/engineering-knowledge-base/domains/programming-enterprise-implementation-plan.md',
                    'app/Services/Ai/Programming/ProgrammingStageReceiptStore.php',
                    'app/Services/Ai/Programming/ProgrammingResumeService.php',
                    'app/Services/Ai/Programming/ProgrammingLearningCandidateProjector.php',
                ],
                'min_recall' => 0.6,
            ],
            [
                'id' => 'programming-orchestrator-repair-flow',
                'flow' => 'programming.repair',
                'objective' => 'repair AtlasProgrammingOrchestrator execution contract and related unit tests',
                'previous_stage_receipts' => [
                    ['receipt_id' => 'benchmark-repair-stage', 'schema_version' => 'atlas.programming.stage_receipt.v1'],
                ],
                'expected_refs' => [
                    'app/Services/Ai/Programming/AtlasProgrammingOrchestrator.php',
                    'tests/Unit/Ai/AtlasProgrammingOrchestratorTest.php',
                    'docs/engineering-knowledge-base/domains/programming-repair-contract.md',
                    'app/Services/Ai/Programming/ProgrammingRepairExecutor.php',
                ],
                'min_recall' => 0.5,
            ],
        ];
    }
}
