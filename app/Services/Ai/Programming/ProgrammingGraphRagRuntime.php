<?php

namespace App\Services\Ai\Programming;

class ProgrammingGraphRagRuntime
{
    public function __construct(
        private readonly ProgrammingLocalVectorIndex $localVectorIndex,
    ) {}

    /**
     * @param  array<string,mixed>  $graph
     * @param  array<int,array<string,mixed>>  $queries
     * @return array<string,mixed>
     */
    public function retrieve(
        string $workspace,
        string $objective,
        string $flow,
        array $graph,
        array $queries,
        int $maxRefs = 32,
    ): array {
        $canonicalFlow = str_starts_with($flow, 'programming.') ? $flow : 'programming.'.$flow;
        $graphRefs = $this->graphRefs($graph);
        $semanticRefs = $this->localVectorIndex->search(
            workspace: $workspace,
            objective: $objective,
            queries: $queries,
            limit: $maxRefs,
        );

        $refs = collect(array_merge($graphRefs, $semanticRefs))
            ->filter(fn (mixed $ref): bool => is_array($ref) && is_string($ref['ref'] ?? null) && $ref['ref'] !== '')
            ->unique(fn (array $ref): string => (string) ($ref['source'] ?? 'unknown').'|'.(string) ($ref['ref'] ?? ''))
            ->sortByDesc(fn (array $ref): int => (int) ($ref['score'] ?? 0))
            ->take(max(1, $maxRefs))
            ->values()
            ->all();

        $encodedRefs = json_encode($refs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '[]';

        return [
            'schema_version' => 'atlas.programming.graph_rag_runtime.v1',
            'runtime_id' => 'programming_graph_rag',
            'status' => $refs === [] ? 'degraded' : 'promoted',
            'promoted_runtime' => $refs !== [],
            'runtime_scope' => 'programming_only',
            'flow' => $canonicalFlow,
            'workspace_hash' => hash('sha256', realpath($workspace) ?: $workspace),
            'retrieval_strategy' => 'graph_traversal_plus_local_semantic_rerank',
            'execution_policy' => [
                'local_only' => true,
                'provider_calls_allowed' => false,
                'network_calls_allowed' => false,
                'writes_allowed' => false,
                'surface_direct_invocation_allowed' => false,
                'planner_mediated_only' => true,
            ],
            'ap_683_boundary' => [
                'global_python_graph_rag_policy_unchanged' => true,
                'does_not_enable_constelacao_graph_positioning' => true,
                'does_not_create_parallel_memory_core' => true,
                'programming_runtime_is_bounded_by_context_pack_receipts' => true,
            ],
            'graph_summary' => [
                'schema_version' => data_get($graph, 'schema_version'),
                'source' => data_get($graph, 'source'),
                'node_count' => (int) data_get($graph, 'node_count', 0),
                'edge_count' => (int) data_get($graph, 'edge_count', 0),
                'complete' => (bool) data_get($graph, 'complete', false),
            ],
            'evidence_refs' => $refs,
            'evidence_ref_count' => count($refs),
            'artifact_hash' => hash('sha256', $encodedRefs),
        ];
    }

    /**
     * @param  array<string,mixed>  $graph
     * @return array<int,array<string,mixed>>
     */
    private function graphRefs(array $graph): array
    {
        $edgeCounts = collect((array) ($graph['edges'] ?? []))
            ->filter(fn (mixed $edge): bool => is_array($edge))
            ->flatMap(fn (array $edge): array => array_filter([
                is_string($edge['from'] ?? null) ? $edge['from'] : null,
                is_string($edge['to'] ?? null) ? $edge['to'] : null,
            ]))
            ->countBy();

        return collect((array) ($graph['nodes'] ?? []))
            ->filter(fn (mixed $node): bool => is_array($node) && is_string($node['path'] ?? null) && $node['path'] !== '')
            ->map(function (array $node) use ($edgeCounts): array {
                $kind = (string) ($node['kind'] ?? 'code');
                $nodeId = (string) ($node['id'] ?? '');
                $edgeBoost = min(10, (int) ($edgeCounts[$nodeId] ?? 0));

                return [
                    'source' => match ($kind) {
                        'doc' => 'canonical_docs',
                        'test' => 'related_tests',
                        'symbol' => 'code_symbols',
                        default => 'code_graph',
                    },
                    'ref' => (string) $node['path'],
                    'reason' => 'programming_graph_rag_traversal:'.(string) ($node['reason'] ?? $kind),
                    'score' => match ($kind) {
                        'symbol' => 98,
                        'file' => 90,
                        'module' => 88,
                        'test' => 86,
                        'doc' => 84,
                        default => 80,
                    } + $edgeBoost,
                    'graph_node_id' => $nodeId,
                    'graph_kind' => $kind,
                    'hash' => hash('sha256', json_encode($node, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: ''),
                ];
            })
            ->values()
            ->all();
    }
}
