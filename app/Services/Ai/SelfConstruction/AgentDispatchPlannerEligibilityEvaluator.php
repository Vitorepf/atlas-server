<?php

namespace App\Services\Ai\SelfConstruction;

/**
 * Evaluate eligibility of each (task, agent) pair for a planned
 * dispatch under hard-law constraints.
 *
 * Pure projection: never starts processes, never claims, never
 * dispatches, never calls providers, never spends tokens, never writes
 * the evidence ledger. The output is advisory only.
 */
final class AgentDispatchPlannerEligibilityEvaluator
{
    public const SCHEMA_VERSION = 'atlas.self_construction.agent_dispatch_planner_eligibility.v1';

    public const MODE = 'read_only_agent_dispatch_planner_eligibility';

    public const RISK_LEVELS = ['low', 'medium', 'high', 'critical'];

    public function __construct(
        private readonly AgentRuntimeRegistryCapabilityCatalog $catalog = new AgentRuntimeRegistryCapabilityCatalog,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $tasks
     * @param  array<int, array<string, mixed>>  $agents
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function evaluate(array $tasks, array $agents, array $options = []): array
    {
        $quarantinedIds = $this->normalizeStringList((array) ($options['quarantined_agents'] ?? []));

        $matrix = [];
        $eligibleCount = 0;
        $ineligibleCount = 0;

        foreach ($tasks as $task) {
            $taskId = (string) ($task['task_packet_id'] ?? '');
            if ($taskId === '') {
                continue;
            }
            $taskRow = [
                'task_packet_id' => $taskId,
                'task_packet_hash' => (string) ($task['task_packet_hash'] ?? ''),
                'risk_level' => $this->riskLevel($task),
                'evaluations' => [],
            ];

            foreach ($agents as $agent) {
                $agentId = (string) ($agent['agent_id'] ?? '');
                if ($agentId === '') {
                    continue;
                }
                $evaluation = $this->evaluatePair($task, $agent, $quarantinedIds);
                $taskRow['evaluations'][] = $evaluation;
                if ($evaluation['is_eligible']) {
                    $eligibleCount++;
                } else {
                    $ineligibleCount++;
                }
            }

            $matrix[] = $taskRow;
        }

        $hashPayload = [
            'tasks' => array_map(static fn (array $t): string => (string) $t['task_packet_id'], $matrix),
            'pairs' => $this->flattenForHash($matrix),
            'quarantined_agents' => $quarantinedIds,
        ];

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'evaluation_matrix' => $matrix,
            'eligible_pair_count' => $eligibleCount,
            'ineligible_pair_count' => $ineligibleCount,
            'pair_total' => $eligibleCount + $ineligibleCount,
            'eligibility_hash' => $this->stableHash($hashPayload),
            'runtime_execution_allowed' => false,
            'dispatch_allowed' => false,
            'provider_call_allowed' => false,
            'token_spend_allowed' => false,
            'self_programming_allowed' => false,
            'ledger_write_allowed' => false,
            'claim_real_allowed' => false,
        ];
    }

    /**
     * @return array<string, bool>
     */
    public function runtimeFlags(): array
    {
        return [
            'runtime_execution_allowed' => false,
            'dispatch_allowed' => false,
            'provider_call_allowed' => false,
            'token_spend_allowed' => false,
            'self_programming_allowed' => false,
            'ledger_write_allowed' => false,
            'claim_real_allowed' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $task
     * @param  array<string, mixed>  $agent
     * @param  list<string>  $quarantinedIds
     * @return array<string, mixed>
     */
    private function evaluatePair(array $task, array $agent, array $quarantinedIds): array
    {
        $agentId = (string) ($agent['agent_id'] ?? '');
        $required = $this->catalog->normalizeCapabilities((array) ($task['required_capabilities'] ?? []));
        $available = $this->catalog->normalizeCapabilities((array) ($agent['capabilities'] ?? []));
        $capabilityMatch = $this->catalog->match($required, $available);

        $risk = $this->riskLevel($task);
        $workspacePolicy = (string) ($task['workspace_policy'] ?? 'none');
        $workspaceRequired = in_array($workspacePolicy, ['isolated', 'isolated_worktree', 'workspace_required'], true);
        $requiresLease = (bool) ($task['requires_lease'] ?? false);
        $dryRunOnly = (bool) ($task['dry_run_only'] ?? false);

        $reasons = [];

        if ($required !== [] && ($capabilityMatch['match_status'] ?? '') !== 'matched') {
            $reasons[] = 'capability_mismatch';
        }
        $maxParallel = max(0, (int) ($agent['max_parallel_tasks'] ?? 0));
        $currentTasks = max(0, (int) ($agent['current_task_count'] ?? 0));
        if ($maxParallel > 0 && $currentTasks >= $maxParallel) {
            $reasons[] = 'capacity_full';
        }
        if (in_array($agentId, $quarantinedIds, true)) {
            $reasons[] = 'agent_quarantined';
        }
        $status = (string) ($agent['status'] ?? '');
        if (! in_array($status, ['available', 'registered'], true)) {
            $reasons[] = 'agent_status_ineligible:'.$status;
        }
        if ($workspaceRequired) {
            $supports = (bool) ($agent['workspace_isolation_supported'] ?? false);
            $hasCap = in_array('workspace_isolation', $available, true) || in_array('workspace_planning', $available, true);
            if (! $supports || ! $hasCap) {
                $reasons[] = 'workspace_isolation_required';
            }
        }
        if ($requiresLease && ! (bool) ($agent['lease_supported'] ?? false)) {
            $reasons[] = 'lease_support_required';
        }
        if (in_array($risk, ['high', 'critical'], true) && ! in_array('human_approval', $available, true)) {
            $reasons[] = 'human_approval_required_for_risk:'.$risk;
        }
        if ((string) ($agent['kind'] ?? '') === 'dry_run_agent' && ! $dryRunOnly) {
            $reasons[] = 'dry_run_agent_requires_dry_run_only_task';
        }
        if (in_array('dry_run_only', $available, true) && ! $dryRunOnly) {
            $reasons[] = 'dry_run_only_capability_requires_dry_run_only_task';
        }
        if ((bool) ($task['evidence_required'] ?? true) && ! (bool) ($agent['evidence_required'] ?? true)) {
            $reasons[] = 'evidence_required_but_agent_not_evidence_ready';
        }

        $eligibleFlags = [
            'capability_match' => $capabilityMatch['match_status'] === 'matched' || $required === [],
            'capacity_available' => ! in_array('capacity_full', $reasons, true),
            'not_quarantined' => ! in_array('agent_quarantined', $reasons, true),
            'workspace_ok' => ! in_array('workspace_isolation_required', $reasons, true),
            'lease_ok' => ! in_array('lease_support_required', $reasons, true),
            'risk_ok' => ! str_starts_with(implode('|', $reasons), 'human_approval_required_for_risk:')
                && ! in_array('human_approval_required_for_risk:high', $reasons, true)
                && ! in_array('human_approval_required_for_risk:critical', $reasons, true),
            'dry_run_ok' => ! in_array('dry_run_agent_requires_dry_run_only_task', $reasons, true)
                && ! in_array('dry_run_only_capability_requires_dry_run_only_task', $reasons, true),
            'evidence_ok' => ! in_array('evidence_required_but_agent_not_evidence_ready', $reasons, true),
        ];

        return [
            'agent_id' => $agentId,
            'kind' => (string) ($agent['kind'] ?? ''),
            'is_eligible' => $reasons === [],
            'reasons' => array_values(array_unique($reasons)),
            'capability_match' => $capabilityMatch,
            'flags' => $eligibleFlags,
            'risk_level' => $risk,
            'free_slots' => $maxParallel > 0 ? max(0, $maxParallel - $currentTasks) : 0,
        ];
    }

    /**
     * @param  array<string, mixed>  $task
     */
    private function riskLevel(array $task): string
    {
        $risk = strtolower((string) ($task['risk_level'] ?? 'low'));

        return in_array($risk, self::RISK_LEVELS, true) ? $risk : 'low';
    }

    /**
     * @param  array<int, array<string, mixed>>  $matrix
     * @return list<array<string, mixed>>
     */
    private function flattenForHash(array $matrix): array
    {
        $flat = [];
        foreach ($matrix as $row) {
            foreach ((array) $row['evaluations'] as $evaluation) {
                $flat[] = [
                    'task_packet_id' => (string) ($row['task_packet_id'] ?? ''),
                    'agent_id' => (string) ($evaluation['agent_id'] ?? ''),
                    'is_eligible' => (bool) ($evaluation['is_eligible'] ?? false),
                    'reasons' => (array) ($evaluation['reasons'] ?? []),
                ];
            }
        }

        return $flat;
    }

    /**
     * @param  array<int, mixed>  $values
     * @return list<string>
     */
    private function normalizeStringList(array $values): array
    {
        $normalized = [];
        foreach ($values as $value) {
            $clean = trim((string) $value);
            if ($clean === '') {
                continue;
            }
            $normalized[$clean] = true;
        }
        $keys = array_keys($normalized);
        sort($keys);

        return array_values($keys);
    }

    /**
     * @param  array<mixed, mixed>  $payload
     */
    private function stableHash(array $payload): string
    {
        return hash('sha256', (string) json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
