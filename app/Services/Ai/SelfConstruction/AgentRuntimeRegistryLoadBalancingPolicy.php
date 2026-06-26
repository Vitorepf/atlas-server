<?php

namespace App\Services\Ai\SelfConstruction;

/**
 * Rank candidate agents under one of a fixed set of load-balancing
 * policies. Pure projection: never dispatches, never claims, never
 * calls providers, never spends tokens, never writes the evidence
 * ledger. The output is deterministic given (candidates, policy).
 */
final class AgentRuntimeRegistryLoadBalancingPolicy
{
    use HashesPayloadCanonically;
    public const SCHEMA_VERSION = 'atlas.self_construction.agent_runtime_registry_load_balancing_policy.v1';

    public const MODE = 'read_only_agent_runtime_registry_load_balancing_policy';

    public const POLICIES = [
        'least_loaded',
        'capability_score',
        'risk_first_human',
        'dry_run_preferred',
        'stable_order',
    ];

    /**
     * @param  array<int, array<string, mixed>>  $candidates
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function rank(array $candidates, array $options = []): array
    {
        $policy = (string) ($options['policy'] ?? 'capability_score');
        if (! in_array($policy, self::POLICIES, true)) {
            $policy = 'capability_score';
        }

        $normalized = $this->normalizeCandidates($candidates);
        $reasons = [];

        $ranked = $normalized;
        switch ($policy) {
            case 'least_loaded':
                usort($ranked, function (array $a, array $b) use (&$reasons): int {
                    $cmp = ((int) $b['free_slots']) <=> ((int) $a['free_slots']);
                    if ($cmp !== 0) {
                        $reasons[] = 'least_loaded:free_slots';

                        return $cmp;
                    }
                    $cmp = ((int) $a['current_task_count']) <=> ((int) $b['current_task_count']);
                    if ($cmp !== 0) {
                        $reasons[] = 'least_loaded:current_task_count';

                        return $cmp;
                    }

                    return strcmp((string) $a['agent_id'], (string) $b['agent_id']);
                });
                break;
            case 'capability_score':
                usort($ranked, function (array $a, array $b) use (&$reasons): int {
                    $cmp = ((float) $b['capability_score']) <=> ((float) $a['capability_score']);
                    if ($cmp !== 0) {
                        $reasons[] = 'capability_score:score';

                        return $cmp;
                    }
                    $cmp = ((float) $b['load_score']) <=> ((float) $a['load_score']);
                    if ($cmp !== 0) {
                        $reasons[] = 'capability_score:load_tiebreaker';

                        return $cmp;
                    }

                    return strcmp((string) $a['agent_id'], (string) $b['agent_id']);
                });
                break;
            case 'risk_first_human':
                usort($ranked, function (array $a, array $b) use (&$reasons): int {
                    $aHas = (int) ($a['has_human_approval'] ?? 0);
                    $bHas = (int) ($b['has_human_approval'] ?? 0);
                    $cmp = $bHas <=> $aHas;
                    if ($cmp !== 0) {
                        $reasons[] = 'risk_first_human:human_approval';

                        return $cmp;
                    }
                    $cmp = ((float) $b['risk_score']) <=> ((float) $a['risk_score']);
                    if ($cmp !== 0) {
                        $reasons[] = 'risk_first_human:risk_score';

                        return $cmp;
                    }

                    return strcmp((string) $a['agent_id'], (string) $b['agent_id']);
                });
                break;
            case 'dry_run_preferred':
                usort($ranked, function (array $a, array $b) use (&$reasons): int {
                    $aIs = (int) ($a['is_dry_run'] ?? 0);
                    $bIs = (int) ($b['is_dry_run'] ?? 0);
                    $cmp = $bIs <=> $aIs;
                    if ($cmp !== 0) {
                        $reasons[] = 'dry_run_preferred:kind';

                        return $cmp;
                    }
                    $cmp = ((float) $b['capability_score']) <=> ((float) $a['capability_score']);
                    if ($cmp !== 0) {
                        $reasons[] = 'dry_run_preferred:capability_tiebreaker';

                        return $cmp;
                    }

                    return strcmp((string) $a['agent_id'], (string) $b['agent_id']);
                });
                break;
            case 'stable_order':
            default:
                usort($ranked, static fn (array $a, array $b): int => strcmp((string) $a['agent_id'], (string) $b['agent_id']));
                $reasons[] = 'stable_order:agent_id';
                break;
        }

        $rankingReasons = array_values(array_unique($reasons));
        $selectedAgent = $ranked[0]['agent_id'] ?? null;

        $hashPayload = [
            'policy' => $policy,
            'ranked' => array_map(static fn (array $r): string => (string) $r['agent_id'], $ranked),
            'reasons' => $rankingReasons,
        ];

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'policy' => $policy,
            'ranked_agents' => $ranked,
            'selected_agent' => $selectedAgent,
            'ranking_reasons' => $rankingReasons,
            'ranking_hash' => $this->stableHash($hashPayload),
            'runtime_execution_allowed' => false,
            'dispatch_allowed' => false,
            'provider_call_allowed' => false,
            'token_spend_allowed' => false,
            'self_programming_allowed' => false,
            'ledger_write_allowed' => false,
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
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $candidates
     * @return list<array<string, mixed>>
     */
    private function normalizeCandidates(array $candidates): array
    {
        $normalized = [];
        foreach ($candidates as $candidate) {
            $agentId = (string) ($candidate['agent_id'] ?? '');
            if ($agentId === '') {
                continue;
            }
            $capabilities = array_map('strval', (array) ($candidate['capabilities'] ?? []));
            $maxParallel = max(0, (int) ($candidate['max_parallel_tasks'] ?? 0));
            $currentTasks = max(0, (int) ($candidate['current_task_count'] ?? 0));
            $freeSlots = $maxParallel > 0 ? max(0, $maxParallel - $currentTasks) : 0;
            $normalized[] = [
                'agent_id' => $agentId,
                'kind' => (string) ($candidate['kind'] ?? ''),
                'capability_score' => (float) ($candidate['capability_score'] ?? 0.0),
                'load_score' => (float) ($candidate['load_score'] ?? 0.0),
                'risk_score' => (float) ($candidate['risk_score'] ?? 0.0),
                'capabilities' => $capabilities,
                'max_parallel_tasks' => $maxParallel,
                'current_task_count' => $currentTasks,
                'free_slots' => $freeSlots,
                'is_dry_run' => ($candidate['kind'] ?? '') === 'dry_run_agent' ? 1 : 0,
                'has_human_approval' => in_array('human_approval', $capabilities, true) ? 1 : 0,
            ];
        }

        return $normalized;
    }

}
