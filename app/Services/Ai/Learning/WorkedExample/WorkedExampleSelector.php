<?php

namespace App\Services\Ai\Cognitive\WorkedExample;

class WorkedExampleSelector
{
    public function __construct(private readonly WorkedExampleRepository $examples) {}

    /**
     * @return array<string,mixed>
     */
    public function select(string $knowledgeNodeId, string $domain, string $sourcePreference = 'any'): array
    {
        $candidates = $this->examples->candidates($knowledgeNodeId, $domain, $sourcePreference);
        $selected = $candidates[0] ?? null;

        return [
            'schema_version' => 'atlas.cognitive.worked_example_selection.v1',
            'status' => $selected ? 'selected' : 'missing',
            'knowledge_node_id' => $knowledgeNodeId,
            'domain' => $domain,
            'source_preference' => $sourcePreference,
            'candidate_count' => count($candidates),
            'selected' => $selected,
            'reason' => $selected ? 'best_available_candidate' : 'no_active_worked_example_for_node_domain',
        ];
    }
}
