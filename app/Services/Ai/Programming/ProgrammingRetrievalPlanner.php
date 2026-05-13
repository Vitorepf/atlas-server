<?php

namespace App\Services\Ai\Programming;

use Illuminate\Support\Str;

class ProgrammingRetrievalPlanner
{
    public function __construct(
        private readonly ProgrammingSemanticCodeGraphService $codeGraph,
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
        $missing = $this->missingSources($sources, $graph, $options);
        $status = $missing === [] ? 'ready' : ($strict ? 'failed_closed' : 'degraded');

        return [
            'schema_version' => 'atlas.programming.agentic_rag.plan.v1',
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
            'context_sufficiency_gate' => [
                'schema_version' => 'atlas.programming.context_sufficiency_gate.v1',
                'status' => $status === 'failed_closed' ? 'blocked' : ($missing === [] ? 'passed' : 'degraded'),
                'blocks_execution' => $status === 'failed_closed',
                'reasons' => $missing,
            ],
            'retrieval_receipt' => [
                'schema_version' => 'atlas.programming.retrieval_receipt.v1',
                'receipt_id' => hash('sha256', $planId.'|agentic_rag|'.$canonicalFlow),
                'source_count' => count($sources),
                'query_count' => count($queries),
                'graph_hash' => hash('sha256', json_encode($graph, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: ''),
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
}
