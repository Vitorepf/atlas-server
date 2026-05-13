<?php

namespace App\Services\Ai\Programming;

class ProgrammingGapCritic
{
    /**
     * @param  array<int,string>  $requiredSources
     * @param  array<string,mixed>  $contextPack
     * @param  array<string,mixed>  $graph
     * @return array<string,mixed>
     */
    public function critique(array $requiredSources, array $contextPack, array $graph, bool $strict): array
    {
        $sourceCounts = (array) ($contextPack['source_counts'] ?? []);
        $missing = collect($requiredSources)
            ->filter(fn (string $source): bool => ($sourceCounts[$source] ?? 0) < 1)
            ->values()
            ->all();

        $contradictions = [];
        if (($sourceCounts['canonical_docs'] ?? 0) > 0 && ! (bool) data_get($graph, 'complete', false)) {
            $contradictions[] = 'docs_present_but_code_graph_incomplete';
        }

        $status = $missing === [] && $contradictions === []
            ? 'passed'
            : ($strict ? 'blocked' : 'degraded');

        return [
            'schema_version' => 'atlas.programming.agentic_rag.gap_critic.v1',
            'status' => $status,
            'blocks_execution' => $status === 'blocked',
            'missing_sources' => $missing,
            'contradictions' => $contradictions,
            'second_pass_required' => $status !== 'passed',
            'next_queries' => collect($missing)
                ->map(fn (string $source): array => [
                    'source' => $source,
                    'query' => 'repair missing programming context source '.$source,
                    'reason' => 'required_source_not_present_in_ranked_context',
                ])
                ->values()
                ->all(),
        ];
    }
}
