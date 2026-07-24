<?php

namespace App\Services\Ai\SelfConstruction\ControlPlane;

use App\Support\Clamp01;
use Carbon\CarbonImmutable;
use App\Services\Ai\SelfConstruction\Support\HashesPayloadCanonically;
use App\Services\Ai\SelfConstruction\Support\RuntimeFlagsShared;
use App\Services\Ai\Support\AiValueNormalizer;

/**
 * Select claimable task packets and available agents to feed the
 * Agent Dispatch Planner pipeline.
 *
 * Pure projection: never starts processes, never claims, never
 * dispatches, never calls providers, never spends tokens, never writes
 * the evidence ledger. The selector reads the task queue and the agent
 * registry without mutating any state.
 */
final class AgentDispatchPlannerCandidateSelector
{
    use RuntimeFlagsShared;

    use HashesPayloadCanonically;
    public const SCHEMA_VERSION = 'atlas.self_construction.agent_dispatch_planner_candidate_selection.v1';

    public const MODE = 'read_only_agent_dispatch_planner_candidate_selection';

    public const CLAIMABLE_STATUSES = [
        'claimable',
        'queued',
        'released',
        'lease_expired',
    ];

    public const ELIGIBLE_AGENT_STATUSES = [
        'available',
        'registered',
    ];

    public function __construct(
        private readonly AgentControlPlaneTaskPacketQueueRepository $taskQueue = new AgentControlPlaneTaskPacketQueueRepository,
        private readonly AgentRuntimeRegistryRepository $registry = new AgentRuntimeRegistryRepository,
    ) {}

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function select(array $options = []): array
    {
        $now = CarbonImmutable::now()->toIso8601String();
        $taskLimit = max(0, (int) ($options['task_limit'] ?? 50));
        $agentLimit = max(0, (int) ($options['agent_limit'] ?? 50));
        $minPriority = isset($options['min_priority']) ? (int) $options['min_priority'] : null;
        $statusFilter = isset($options['task_status']) ? (string) $options['task_status'] : '';
        $capabilityFilter = isset($options['capability']) ? (string) $options['capability'] : '';
        $kindFilter = isset($options['agent_kind']) ? (string) $options['agent_kind'] : '';

        $candidateTasks = $this->selectCandidateTasks($statusFilter, $minPriority, $taskLimit);
        $candidateAgents = $this->selectCandidateAgents($capabilityFilter, $kindFilter, $agentLimit);

        $taskSummary = [
            'candidate_task_count' => count($candidateTasks),
            'task_status_counts' => $this->statusCounts($candidateTasks, 'status'),
        ];

        $agentSummary = [
            'candidate_agent_count' => count($candidateAgents),
            'agent_kind_counts' => $this->statusCounts($candidateAgents, 'kind'),
            'agent_status_counts' => $this->statusCounts($candidateAgents, 'status'),
        ];

        $hashPayload = [
            'tasks' => array_map(static fn (array $t): string => (string) ($t['task_packet_id'] ?? ''), $candidateTasks),
            'agents' => array_map(static fn (array $a): string => (string) ($a['agent_id'] ?? ''), $candidateAgents),
            'options' => [
                'task_limit' => $taskLimit,
                'agent_limit' => $agentLimit,
                'min_priority' => $minPriority,
                'task_status' => $statusFilter,
                'capability' => $capabilityFilter,
                'agent_kind' => $kindFilter,
            ],
        ];

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'selected_at' => $now,
            'candidate_tasks' => $candidateTasks,
            'candidate_agents' => $candidateAgents,
            'task_summary' => $taskSummary,
            'agent_summary' => $agentSummary,
            'allowed_task_statuses' => self::CLAIMABLE_STATUSES,
            'allowed_agent_statuses' => self::ELIGIBLE_AGENT_STATUSES,
            'selection_hash' => $this->stableHash($hashPayload),
            'runtime_execution_allowed' => false,
            'dispatch_allowed' => false,
            'provider_call_allowed' => false,
            'token_spend_allowed' => false,
            'self_programming_allowed' => false,
            'ledger_write_allowed' => false,
            'claim_real_allowed' => false,
        ];
    }

    private const VALUE_WEIGHT_DENSITY        = 0.35;
    private const VALUE_WEIGHT_IMPLEMENTABILITY = 0.25;
    private const VALUE_WEIGHT_FRESHNESS      = 0.20;
    private const VALUE_WEIGHT_WORKER_FIT     = 0.10;
    private const VALUE_WEIGHT_RISK           = 0.10;

    private const RISK_SCORE = ['low' => 0.1, 'medium' => 0.5, 'high' => 0.9];

    /**
     * Rank candidate tasks by value density, implementability, freshness, worker fit
     * and risk instead of simple FIFO/priority ordering, and — when the queue is
     * saturated relative to capacity — defer (never delete) the lowest-value valid
     * tasks so they remain claimable later.
     *
     * @param  list<array<string,mixed>>  $candidateTasks  output of selectCandidateTasks()/select()
     * @param  array{capacity?: int, worker_capabilities?: list<string>}  $context
     * @return array{schema_version:string, selected:list<array<string,mixed>>, deferred:list<array<string,mixed>>, rationale:array<string,string>}
     */
    public function rankByValue(array $candidateTasks, array $context = []): array
    {
        $capacity = isset($context['capacity']) ? max(0, (int) $context['capacity']) : null;
        $workerCapabilities = is_array($context['worker_capabilities'] ?? null) ? $context['worker_capabilities'] : [];

        $scored = [];
        foreach ($candidateTasks as $task) {
            $id              = $this->scalarString($task['task_packet_id'] ?? '');
            $valueDensity    = Clamp01::of((float) ($task['value_density']    ?? 0.5));
            $implementability = Clamp01::of((float) ($task['implementability'] ?? 0.5));
            $freshness       = Clamp01::of((float) ($task['freshness']        ?? 0.5));
            $requiredCaps    = (array) ($task['required_capabilities'] ?? []);
            $workerFit       = $workerCapabilities === [] || $requiredCaps === []
                ? 0.5
                : (count(array_intersect($requiredCaps, $workerCapabilities)) / max(1, count($requiredCaps)));
            $riskLevel       = $this->scalarString($task['risk_level'] ?? 'low');
            $riskScore       = self::RISK_SCORE[$riskLevel] ?? 0.5;

            $score = $valueDensity * self::VALUE_WEIGHT_DENSITY
                + $implementability * self::VALUE_WEIGHT_IMPLEMENTABILITY
                + $freshness * self::VALUE_WEIGHT_FRESHNESS
                + $workerFit * self::VALUE_WEIGHT_WORKER_FIT
                + (1.0 - $riskScore) * self::VALUE_WEIGHT_RISK;

            $scored[] = ['task' => $task, 'id' => $id, 'value_score' => round($score, 4)];
        }

        usort($scored, static function (array $a, array $b): int {
            return $a['value_score'] !== $b['value_score']
                ? $b['value_score'] <=> $a['value_score']
                : strcmp($a['id'], $b['id']);
        });

        $isSaturated = $capacity !== null && count($scored) > $capacity;

        $selected  = [];
        $deferred  = [];
        $rationale = [];

        foreach ($scored as $i => $entry) {
            $withinCapacity = ! $isSaturated || $i < $capacity;
            if ($withinCapacity) {
                $selected[] = $entry['task'];
                $rationale[$entry['id']] = sprintf('selected: value_score=%.4f (rank %d)', $entry['value_score'], $i + 1);
            } else {
                // Deferred, never deleted — remains claimable in a future, less saturated cycle.
                $deferred[] = $entry['task'];
                $rationale[$entry['id']] = sprintf('deferred (queue saturated): value_score=%.4f (rank %d, capacity=%d)', $entry['value_score'], $i + 1, $capacity);
            }
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'selected'       => $selected,
            'deferred'       => $deferred,
            'rationale'      => $rationale,
        ];
    }


    /**
     * @return list<array<string, mixed>>
     */
    private function selectCandidateTasks(string $statusFilter, ?int $minPriority, int $limit): array
    {
        $entries = [];
        if ($statusFilter !== '') {
            if (in_array($statusFilter, self::CLAIMABLE_STATUSES, true)) {
                $entries = $this->taskQueue->list(['status' => $statusFilter]);
            }
        } else {
            foreach (self::CLAIMABLE_STATUSES as $status) {
                foreach ($this->taskQueue->list(['status' => $status]) as $task) {
                    $entries[] = $task;
                }
            }
        }

        $filtered = [];
        foreach ($entries as $entry) {
            if ($minPriority !== null && (int) ($entry['priority'] ?? 0) < $minPriority) {
                continue;
            }
            $payload = $this->candidateTaskPayload($entry);
            $filtered[] = $payload;
        }

        usort($filtered, function (array $a, array $b): int {
            $pCmp = ((int) ($b['priority'] ?? 0)) <=> ((int) ($a['priority'] ?? 0));
            if ($pCmp !== 0) {
                return $pCmp;
            }

            return strcmp($this->scalarString($a['task_packet_id'] ?? ''), $this->scalarString($b['task_packet_id'] ?? ''));
        });

        if ($limit > 0 && count($filtered) > $limit) {
            $filtered = array_slice($filtered, 0, $limit);
        }

        return $filtered;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function selectCandidateAgents(string $capabilityFilter, string $kindFilter, int $limit): array
    {
        $filters = [];
        if ($capabilityFilter !== '') {
            $filters['capability'] = $capabilityFilter;
        }
        if ($kindFilter !== '') {
            $filters['kind'] = $kindFilter;
        }

        $agents = $this->registry->list($filters);
        $filtered = [];
        foreach ($agents as $agent) {
            $status = $this->scalarString($agent['status'] ?? '');
            if (! in_array($status, self::ELIGIBLE_AGENT_STATUSES, true)) {
                continue;
            }
            $filtered[] = $this->candidateAgentPayload($agent);
        }

        usort($filtered, fn (array $a, array $b): int => strcmp($this->scalarString($a['agent_id'] ?? ''), $this->scalarString($b['agent_id'] ?? '')));

        if ($limit > 0 && count($filtered) > $limit) {
            $filtered = array_slice($filtered, 0, $limit);
        }

        return $filtered;
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>
     */
    private function candidateTaskPayload(array $entry): array
    {
        $packet = (array) ($entry['task_packet'] ?? []);

        return [
            'task_packet_id' => $this->scalarString($entry['task_packet_id'] ?? ($packet['task_packet_id'] ?? '')),
            'task_packet_hash' => $this->scalarString($entry['task_packet_hash'] ?? ($packet['task_packet_hash'] ?? '')),
            'status' => $this->scalarString($entry['status'] ?? ''),
            'priority' => (int) ($entry['priority'] ?? 0),
            'tags' => (array) ($entry['tags'] ?? []),
            'required_capabilities' => $this->stringList((array) ($packet['required_capabilities'] ?? [])),
            'risk_level' => $this->scalarString($packet['risk_level'] ?? 'low'),
            'workspace_policy' => $this->scalarString($packet['workspace_policy'] ?? 'none'),
            'requires_lease' => (bool) ($packet['requires_lease'] ?? false),
            'dry_run_only' => (bool) ($packet['dry_run_only'] ?? false),
            'evidence_required' => (bool) ($packet['evidence_required'] ?? true),
            'evidence_refs' => $this->stringList((array) ($packet['evidence_refs'] ?? [])),
            'scope_lock' => (array) ($packet['scope_lock'] ?? []),
            'metadata' => (array) ($entry['metadata'] ?? []),
        ];
    }

    /**
     * @param  array<string, mixed>  $agent
     * @return array<string, mixed>
     */
    private function candidateAgentPayload(array $agent): array
    {
        return [
            'agent_id' => $this->scalarString($agent['agent_id'] ?? ''),
            'label' => $this->scalarString($agent['label'] ?? ''),
            'kind' => $this->scalarString($agent['kind'] ?? ''),
            'status' => $this->scalarString($agent['status'] ?? ''),
            'capabilities' => $this->stringList((array) ($agent['capabilities'] ?? [])),
            'max_parallel_tasks' => (int) ($agent['max_parallel_tasks'] ?? 0),
            'current_task_count' => (int) ($agent['current_task_count'] ?? 0),
            'heartbeat_required' => (bool) ($agent['heartbeat_required'] ?? true),
            'lease_supported' => (bool) ($agent['lease_supported'] ?? false),
            'workspace_isolation_supported' => (bool) ($agent['workspace_isolation_supported'] ?? false),
            'continuation_summary_supported' => (bool) ($agent['continuation_summary_supported'] ?? false),
            'cost_meter_supported' => (bool) ($agent['cost_meter_supported'] ?? false),
            'evidence_required' => (bool) ($agent['evidence_required'] ?? true),
            'tags' => (array) ($agent['tags'] ?? []),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $entries
     * @return array<string, int>
     */
    private function statusCounts(array $entries, string $field): array
    {
        $counts = [];
        foreach ($entries as $entry) {
            $key = $this->scalarString($entry[$field] ?? 'unknown');
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }
        ksort($counts);

        return $counts;
    }

    private function scalarString(mixed $value): string
    {
        if (is_scalar($value) || $value === null) {
            return trim((string) $value);
        }

        return hash('sha256', (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * @return list<string>
     */
    private function stringList(array $values): array
    {
        $out = [];
        foreach ($values as $value) {
            $string = $this->scalarString($value);
            if ($string !== '') {
                $out[] = $string;
            }
        }

        return array_values($out);
    }
}
