<?php

namespace App\Services\Ai\SelfConstruction;

/**
 * Governance precheck for the Agent Dispatch Planner. Validates that a
 * planned dispatch would not violate operator approval, budget gate or
 * kill switch policies under the hard-law safety invariants.
 *
 * Pure projection: never authorizes a real dispatch, never enables
 * provider calls, never spends tokens. The precheck is the projection
 * of what a governance check would assert at real dispatch time.
 */
final class AgentDispatchPlannerGovernancePrecheck
{
    public const SCHEMA_VERSION = 'atlas.self_construction.agent_dispatch_planner_governance_precheck.v1';

    public const MODE = 'read_only_agent_dispatch_planner_governance_precheck';

    public const KILL_SWITCH_STATES = ['armed', 'disarmed', 'tripped', 'unknown'];

    public function __construct(
        private readonly AgentRuntimeRegistryQuarantineRepository $quarantine = new AgentRuntimeRegistryQuarantineRepository,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $tasks
     * @param  array<int, array<string, mixed>>  $agents
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function precheck(array $tasks, array $agents, array $options = []): array
    {
        $killSwitch = (string) ($options['kill_switch_state'] ?? 'armed');
        if (! in_array($killSwitch, self::KILL_SWITCH_STATES, true)) {
            $killSwitch = 'unknown';
        }
        $budgetGate = (string) ($options['budget_gate_state'] ?? 'green');
        $budgetGateOk = $budgetGate === 'green';
        $operatorApprovalSet = $this->normalizeStringList((array) ($options['operator_approved_task_ids'] ?? []));
        $quarantinedIds = (bool) ($options['use_live_quarantine'] ?? true)
            ? $this->quarantine->activeAgentIds()
            : $this->normalizeStringList((array) ($options['quarantined_agents'] ?? []));

        $taskBlockers = [];
        $agentBlockers = [];
        $globalBlockers = [];

        if ($killSwitch === 'tripped') {
            $globalBlockers[] = 'kill_switch_tripped';
        }
        if ($killSwitch === 'disarmed') {
            $globalBlockers[] = 'kill_switch_disarmed';
        }
        if (! $budgetGateOk) {
            $globalBlockers[] = 'budget_gate_not_green:'.$budgetGate;
        }
        if ((bool) ($options['self_programming_requested'] ?? false)) {
            $globalBlockers[] = 'self_programming_requested';
        }

        foreach ($tasks as $task) {
            $taskId = (string) ($task['task_packet_id'] ?? '');
            if ($taskId === '') {
                continue;
            }
            $risk = strtolower((string) ($task['risk_level'] ?? 'low'));
            $reasons = [];
            if (in_array($risk, ['high', 'critical'], true) && ! in_array($taskId, $operatorApprovalSet, true)) {
                $reasons[] = 'operator_approval_required';
            }
            if ((bool) ($task['evidence_required'] ?? true) && empty($task['evidence_refs'])) {
                $reasons[] = 'evidence_refs_missing';
            }
            $taskBlockers[] = [
                'task_packet_id' => $taskId,
                'risk_level' => $risk,
                'operator_approval_required' => in_array($risk, ['high', 'critical'], true),
                'operator_approval_granted' => in_array($taskId, $operatorApprovalSet, true),
                'reasons' => $reasons,
                'is_governance_clear' => $reasons === [],
            ];
        }

        foreach ($agents as $agent) {
            $agentId = (string) ($agent['agent_id'] ?? '');
            if ($agentId === '') {
                continue;
            }
            $reasons = [];
            if (in_array($agentId, $quarantinedIds, true)) {
                $reasons[] = 'agent_quarantined';
            }
            if ((string) ($agent['status'] ?? '') === 'disabled') {
                $reasons[] = 'agent_disabled';
            }
            $agentBlockers[] = [
                'agent_id' => $agentId,
                'is_governance_clear' => $reasons === [],
                'reasons' => $reasons,
            ];
        }

        $clearTaskCount = count(array_filter($taskBlockers, static fn (array $t): bool => (bool) $t['is_governance_clear']));
        $clearAgentCount = count(array_filter($agentBlockers, static fn (array $a): bool => (bool) $a['is_governance_clear']));

        $status = ($globalBlockers === [] && $clearTaskCount > 0 && $clearAgentCount > 0) ? 'ok' : 'blocked';

        $hashPayload = [
            'kill_switch' => $killSwitch,
            'budget_gate' => $budgetGate,
            'global_blockers' => $globalBlockers,
            'tasks' => array_map(static fn (array $t): array => [
                'task_packet_id' => (string) $t['task_packet_id'],
                'reasons' => (array) $t['reasons'],
            ], $taskBlockers),
            'agents' => array_map(static fn (array $a): array => [
                'agent_id' => (string) $a['agent_id'],
                'reasons' => (array) $a['reasons'],
            ], $agentBlockers),
        ];

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'status' => $status,
            'kill_switch_state' => $killSwitch,
            'budget_gate_state' => $budgetGate,
            'budget_gate_ok' => $budgetGateOk,
            'global_blockers' => $globalBlockers,
            'task_blockers' => $taskBlockers,
            'agent_blockers' => $agentBlockers,
            'clear_task_count' => $clearTaskCount,
            'clear_agent_count' => $clearAgentCount,
            'governance_hash' => $this->stableHash($hashPayload),
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
