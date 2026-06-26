<?php

namespace App\Services\Ai\SelfConstruction;


use App\Services\Ai\SelfConstruction\Support\KsortsArraysByReference;
/**
 * Builds a hashable continuity index from local runtime evidence records.
 */
final class AgentRuntimeEvidenceContinuityIndexer
{
    use KsortsArraysByReference;


    /**
     * @param  array<string,mixed>  $value
     * @return array<string,mixed>
     */
    private function ksortRecursive(array $value): array
    {
        $this->ksortRecursiveByReference($value);

        return $value;
    }
    public const SCHEMA_VERSION = 'atlas.self_construction.agent_runtime_evidence_continuity_index.v1';

    public const MODE = 'read_only_agent_runtime_evidence_continuity_indexer';

    public const REQUIRED_TYPES = [
        'dispatch_plan',
        'claim_lease',
        'scope_lock',
        'validation_result',
        'continuation_summary',
    ];

    /**
     * @param  list<array<string, mixed>>  $entries
     * @return array<string, mixed>
     */
    public function build(array $entries): array
    {
        $types = [];
        $tasks = [];
        $agents = [];
        foreach ($entries as $entry) {
            $type = (string) ($entry['evidence_type'] ?? 'unknown');
            $task = (string) ($entry['task_packet_id'] ?? 'unknown');
            $agent = (string) ($entry['agent_id'] ?? 'unknown');
            $types[$type] = ($types[$type] ?? 0) + 1;
            $tasks[$task] = ($tasks[$task] ?? 0) + 1;
            $agents[$agent] = ($agents[$agent] ?? 0) + 1;
        }
        ksort($types);
        ksort($tasks);
        ksort($agents);

        $missing = array_values(array_diff(self::REQUIRED_TYPES, array_keys($types)));
        $index = [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'status' => $missing === [] ? 'continuity_index_complete' : 'continuity_index_incomplete',
            'entry_count' => count($entries),
            'task_packet_count' => count($tasks),
            'agent_count' => count($agents),
            'evidence_type_counts' => $types,
            'task_packet_counts' => $tasks,
            'agent_counts' => $agents,
            'required_evidence_types' => self::REQUIRED_TYPES,
            'missing_required_evidence_types' => $missing,
            'continuation_summary_ready' => in_array('continuation_summary', array_keys($types), true),
            'runtime_safety' => [
                'runtime_safety_all_false' => true,
                'runtime_execution_allowed' => false,
                'dispatch_allowed' => false,
                'provider_call_allowed' => false,
                'token_spend_allowed' => false,
                'self_programming_allowed' => false,
                'ledger_write_allowed' => false,
                'completion_claim_allowed' => false,
            ],
        ];
        $index['continuity_index_hash'] = $this->stableHash($index);

        return $index;
    }

    private function stableHash(array $payload): string
    {
        return hash('sha256', (string) json_encode($this->ksortRecursive($payload), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

}
