<?php

namespace App\Services\Ai\Programming;

use Illuminate\Support\Str;

class ProgrammingRetrievalPlanner
{
    public const SCHEMA_VERSION = 'atlas.programming.agentic_rag.plan.v1';

    public static function focusedUnitTestPath(): string
    {
        return 'tests/Unit/Ai/Programming/ProgrammingRetrievalPlannerTest.php';
    }

    public function __construct(
        private readonly ProgrammingSemanticCodeGraphService $codeGraph,
        private readonly ProgrammingRetrievalExecutor $retrievalExecutor,
        private readonly ProgrammingGapCritic $gapCritic,
        private readonly ProgrammingRetrievalEvaluator $retrievalEvaluator,
        private readonly ProgrammingContextPackStore $contextPackStore,
        private readonly ProgrammingPythonRuntimeContract $pythonRuntimeContract,
        private readonly ProgrammingGraphRagRuntime $graphRagRuntime,
    ) {}

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function plan(string $planId, string $workspace, string $objective, string $flow, array $options = []): array
    {
        $canonicalFlow = str_starts_with($flow, 'programming.') ? $flow : 'programming.'.$flow;
        $strict = in_array($canonicalFlow, ['programming.repair', 'programming.forge', 'programming.frontend', 'programming.security', 'programming.database'], true)
            || (bool) ($options['quality_required'] ?? false);
        $sources = $this->requiredSources($canonicalFlow);
        $queries = $this->queries($objective, $canonicalFlow, $sources);
        $graph = $this->codeGraph->query($workspace, $objective, $canonicalFlow);
        $graphRagRuntime = $this->graphRagRuntime->retrieve(
            workspace: $workspace,
            objective: $objective,
            flow: $canonicalFlow,
            graph: $graph,
            queries: $queries,
            maxRefs: (int) ($options['max_refs'] ?? 40),
        );
        $previousReceipts = is_array($options['previous_stage_receipts'] ?? null)
            ? $options['previous_stage_receipts']
            : [];
        $contextPack = $this->retrievalExecutor->contextPack(
            graph: $graph,
            previousReceipts: $previousReceipts,
            maxRefs: (int) ($options['max_refs'] ?? 40),
            maxChars: (int) ($options['max_chars'] ?? 20000),
        );
        $professionalContextPack = $this->retrievalExecutor->professionalContextPack(
            workspace: $workspace,
            objective: $objective,
            flow: $canonicalFlow,
            graph: $graph,
            queries: $queries,
            previousReceipts: $previousReceipts,
            requiredSources: $sources,
            maxRefs: (int) ($options['max_refs'] ?? 40),
            maxChars: (int) ($options['max_chars'] ?? 20000),
            graphRagRefs: (array) data_get($graphRagRuntime, 'evidence_refs', []),
        );
        $professionalContextPack = $this->contextPackStore->persist(
            planId: $planId,
            parentPlanId: is_string($options['parent_plan_id'] ?? null) ? $options['parent_plan_id'] : null,
            contextPack: $professionalContextPack,
        );
        $gapCritic = $this->gapCritic->critique($sources, $professionalContextPack, $graph, $strict);
        $retrievalEval = $this->retrievalEvaluator->evaluate($sources, $professionalContextPack, $gapCritic);
        $missing = $this->missingSources($sources, $graph, $options);
        if ((int) data_get($professionalContextPack, 'source_counts.related_tests', 0) > 0) {
            $missing = array_values(array_diff($missing, ['related_tests_unavailable']));
        }
        if (($contextPack['status'] ?? null) === 'empty') {
            $missing[] = 'context_pack_empty';
        }
        foreach ((array) ($gapCritic['missing_sources'] ?? []) as $missingSource) {
            $missing[] = $missingSource.'_unavailable_in_professional_context_pack';
        }
        $missing = array_values(array_unique($missing));
        $status = $missing === [] ? 'ready' : ($strict ? 'failed_closed' : 'degraded');
        $retrievalReceiptId = hash('sha256', $planId.'|agentic_rag|'.$canonicalFlow);
        $professionalPlan = $this->professionalPlan(
            planId: $planId,
            flow: $canonicalFlow,
            objective: $objective,
            sources: $sources,
            queries: $queries,
            graph: $graph,
            contextPack: $professionalContextPack,
            gapCritic: $gapCritic,
            retrievalEval: $retrievalEval,
            retrievalReceiptId: $retrievalReceiptId,
            graphRagRuntime: $graphRagRuntime,
        );
        $pythonRuntime = $this->pythonRuntimeContract->manifest(
            workspace: $workspace,
            files: collect((array) ($professionalContextPack['ranked_refs'] ?? []))
                ->pluck('ref')
                ->filter(fn ($ref): bool => is_string($ref) && ! str_starts_with($ref, 'benchmark-'))
                ->values()
                ->all(),
            maxFiles: min(40, (int) ($options['max_refs'] ?? 40)),
        );

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'plan_id' => $planId,
            'flow' => $canonicalFlow,
            'objective_hash' => hash('sha256', $objective),
            'status' => $status,
            'strict_required_sources' => $strict,
            'required_sources' => $sources,
            'missing_required_sources' => $missing,
            'queries' => $queries,
            'budget' => [
                'max_refs' => (int) ($options['max_refs'] ?? 40),
                'max_chars' => (int) ($options['max_chars'] ?? 20000),
            ],
            'fail_closed_when_missing' => [
                'required_source_unavailable',
                'context_pack_empty',
                'contradictory_sources',
            ],
            'semantic_code_graph' => [
                'schema_version' => data_get($graph, 'schema_version'),
                'node_count' => data_get($graph, 'node_count', 0),
                'edge_count' => data_get($graph, 'edge_count', 0),
                'related_tests' => data_get($graph, 'related_tests', []),
                'related_docs' => data_get($graph, 'related_docs', []),
                'complete' => (bool) data_get($graph, 'complete', false),
            ],
            'graph_rag_runtime' => $graphRagRuntime,
            'context_pack' => $contextPack,
            'professional_plan' => $professionalPlan,
            'professional_context_pack' => $professionalContextPack,
            'gap_critic' => $gapCritic,
            'retrieval_eval' => $retrievalEval,
            'python_runtime_contract' => $pythonRuntime,
            'context_sufficiency_gate' => [
                'schema_version' => 'atlas.programming.context_sufficiency_gate.v1',
                'status' => data_get($gapCritic, 'status') === 'blocked' || $status === 'failed_closed' ? 'blocked' : ($missing === [] ? 'passed' : 'degraded'),
                'blocks_execution' => data_get($gapCritic, 'blocks_execution') === true || $status === 'failed_closed',
                'reasons' => $missing,
            ],
            'retrieval_receipt' => [
                'schema_version' => 'atlas.programming.retrieval_receipt.v1',
                'receipt_id' => $retrievalReceiptId,
                'source_count' => count($sources),
                'query_count' => count($queries),
                'graph_hash' => hash('sha256', json_encode($graph, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: ''),
                'context_pack_hash' => $professionalContextPack['context_pack_hash'] ?? null,
                'created_at' => now()->toJSON(),
            ],
        ];
    }

    /**
     * @return array<int,string>
     */
    private function requiredSources(string $flow): array
    {
        $sources = ['code_symbols', 'canonical_docs', 'prior_decisions'];
        if (in_array($flow, ['programming.repair', 'programming.forge'], true)) {
            $sources[] = 'stage_receipts';
            $sources[] = 'known_failures';
            $sources[] = 'related_tests';
        } elseif (in_array($flow, ['programming.qa', 'programming.security', 'programming.database'], true)) {
            $sources[] = 'related_tests';
        } elseif ($flow === 'programming.frontend') {
            $sources[] = 'related_tests';
            $sources[] = 'visual_evidence';
            $sources[] = 'asset_provenance';
        }

        return array_values(array_unique($sources));
    }

    /**
     * @param  array<int,string>  $sources
     * @return array<int,array<string,mixed>>
     */
    private function queries(string $objective, string $flow, array $sources): array
    {
        $base = Str::limit(trim($objective), 180, '');

        return collect($sources)
            ->map(fn (string $source): array => [
                'source' => $source,
                'query' => $source.' '.$flow.' '.$base,
                'reason' => match ($source) {
                    'code_symbols' => 'identify touched modules and symbols',
                    'related_tests' => 'select proportional validation tests',
                    'canonical_docs' => 'load architecture and domain rules',
                    'stage_receipts' => 'resume without session memory',
                    'known_failures' => 'avoid repeated repair loops',
                    'visual_evidence' => 'validate frontend visual state',
                    'asset_provenance' => 'avoid untraceable UI assets',
                    default => 'support programming context',
                },
                'required' => true,
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<int,string>  $sources
     * @param  array<string,mixed>  $graph
     * @param  array<string,mixed>  $options
     * @return array<int,string>
     */
    private function missingSources(array $sources, array $graph, array $options): array
    {
        $missing = [];
        if (in_array('code_symbols', $sources, true) && ! (bool) data_get($graph, 'complete', false)) {
            $missing[] = 'code_symbols_unavailable';
        }
        if (in_array('related_tests', $sources, true) && data_get($graph, 'related_tests', []) === []) {
            $missing[] = 'related_tests_unavailable';
        }
        if (in_array('stage_receipts', $sources, true) && empty($options['previous_stage_receipts'])) {
            $missing[] = 'stage_receipts_unavailable';
        }

        return $missing;
    }

    /**
     * @param  array<int,string>  $sources
     * @param  array<int,array<string,mixed>>  $queries
     * @param  array<string,mixed>  $graph
     * @param  array<string,mixed>  $contextPack
     * @param  array<string,mixed>  $gapCritic
     * @param  array<string,mixed>  $retrievalEval
     * @return array<string,mixed>
     */
    private function professionalPlan(
        string $planId,
        string $flow,
        string $objective,
        array $sources,
        array $queries,
        array $graph,
        array $contextPack,
        array $gapCritic,
        array $retrievalEval,
        string $retrievalReceiptId,
        array $graphRagRuntime,
    ): array {
        $iterations = [
            [
                'step' => 1,
                'goal' => 'find touched symbols and module boundaries',
                'sources' => ['code_symbols'],
                'status' => data_get($graph, 'complete') ? 'passed' : 'degraded',
                'gap_after_step' => data_get($graph, 'complete') ? [] : ['code_graph_incomplete'],
            ],
            [
                'step' => 2,
                'goal' => 'retrieve canonical docs, tests, receipts and memory-safe references',
                'sources' => $sources,
                'status' => data_get($contextPack, 'status') === 'ready' ? 'passed' : 'degraded',
                'gap_after_step' => data_get($contextPack, 'status') === 'ready' ? [] : ['context_pack_not_ready'],
            ],
            [
                'step' => 3,
                'goal' => 'critic verifies context sufficiency before execution',
                'sources' => ['professional_context_pack', 'semantic_code_graph'],
                'status' => (string) data_get($gapCritic, 'status', 'blocked'),
                'gap_after_step' => array_values(array_merge(
                    (array) data_get($gapCritic, 'missing_sources', []),
                    (array) data_get($gapCritic, 'contradictions', []),
                )),
            ],
        ];

        return [
            'schema_version' => 'atlas.programming.agentic_rag.professional_plan.v1',
            'plan_id' => $planId,
            'flow' => $flow,
            'objective_hash' => hash('sha256', $objective),
            'retrieval_strategy' => data_get($graphRagRuntime, 'promoted_runtime') === true
                ? 'promoted_programming_graph_rag_lexical'
                : 'hybrid_graph_lexical',
            'required_sources' => $sources,
            'source_queries' => $queries,
            'graph_rag_runtime' => [
                'schema_version' => data_get($graphRagRuntime, 'schema_version'),
                'status' => data_get($graphRagRuntime, 'status'),
                'runtime_scope' => data_get($graphRagRuntime, 'runtime_scope'),
                'evidence_ref_count' => data_get($graphRagRuntime, 'evidence_ref_count'),
                'artifact_hash' => data_get($graphRagRuntime, 'artifact_hash'),
            ],
            'iterations' => $iterations,
            'context_pack_hash' => $contextPack['context_pack_hash'] ?? null,
            'context_sufficiency_gate' => [
                'status' => data_get($gapCritic, 'status') === 'passed' ? 'passed' : data_get($gapCritic, 'status'),
                'reasons' => array_values(array_merge(
                    (array) data_get($gapCritic, 'missing_sources', []),
                    (array) data_get($gapCritic, 'contradictions', []),
                )),
            ],
            'retrieval_eval' => $retrievalEval,
            'retrieval_receipt_id' => $retrievalReceiptId,
        ];
    }
}
