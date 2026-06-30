<?php

namespace App\Services\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\Support\HashesPayloadCanonically;

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

    public const DECISION_ASSIGN              = 'assign';
    public const DECISION_WAIT_OR_ROUTE_ELSEWHERE = 'wait_or_route_elsewhere';

    private const MAX_RECENT_FAILURE_RATE = 0.30;

    /**
     * Assign one task to one candidate worker, balancing throughput AND quality —
     * never sends a hard task to a weak or overloaded worker just because it's idle.
     *
     * @param  array{difficulty?: float, required_capabilities?: list<string>}  $task
     * @param  array<int, array<string, mixed>>  $candidates  each may include recent_failure_rate
     * @return array{schema_version:string, decision:string, selected_agent:?string, quality_reason:string, capacity_reason:string}
     */
    public function assignForTask(array $task, array $candidates): array
    {
        $difficulty = max(0.0, min(1.0, (float) ($task['difficulty'] ?? 0.5)));
        $requiredCapabilities = (array) ($task['required_capabilities'] ?? []);

        $normalized = $this->normalizeCandidates($candidates);
        foreach ($normalized as &$c) {
            $c['recent_failure_rate'] = $this->failureRateFor($candidates, $c['agent_id']);
            $c['family_fit'] = $requiredCapabilities === []
                || array_intersect($requiredCapabilities, $c['capabilities']) !== [];
        }
        unset($c);

        $qualified = array_values(array_filter($normalized, function (array $c) use ($difficulty): bool {
            return $c['capability_score'] >= $difficulty
                && $c['recent_failure_rate'] <= self::MAX_RECENT_FAILURE_RATE
                && $c['free_slots'] > 0
                && $c['family_fit'];
        }));

        if ($qualified !== []) {
            usort($qualified, static function (array $a, array $b): int {
                $cmp = $b['capability_score'] <=> $a['capability_score'];
                if ($cmp !== 0) {
                    return $cmp;
                }
                $cmp = $b['free_slots'] <=> $a['free_slots'];

                return $cmp !== 0 ? $cmp : strcmp($a['agent_id'], $b['agent_id']);
            });

            $winner = $qualified[0];

            return [
                'schema_version'  => self::SCHEMA_VERSION,
                'decision'        => self::DECISION_ASSIGN,
                'selected_agent'  => $winner['agent_id'],
                'quality_reason'  => sprintf('capability_score=%.2f meets difficulty=%.2f with failure_rate=%.2f below ceiling', $winner['capability_score'], $difficulty, $winner['recent_failure_rate']),
                'capacity_reason' => sprintf('free_slots=%d available', $winner['free_slots']),
            ];
        }

        // No qualified candidate: never silently assign to a weak/overloaded idle worker.
        $anyHasCapacity   = array_filter($normalized, static fn (array $c): bool => $c['free_slots'] > 0);
        $anyMeetsQuality  = array_filter($normalized, static fn (array $c): bool => $c['capability_score'] >= $difficulty && $c['recent_failure_rate'] <= self::MAX_RECENT_FAILURE_RATE);

        $qualityReason = $anyHasCapacity !== [] && $anyMeetsQuality === []
            ? sprintf('idle workers exist but none meet the quality bar for difficulty=%.2f (capability_score too low or recent_failure_rate too high)', $difficulty)
            : ($anyMeetsQuality === [] ? 'no candidates meet the quality bar' : 'quality bar is met by some candidates');

        $capacityReason = $anyHasCapacity === []
            ? 'no candidates have free capacity'
            : 'qualified-and-capable candidates lack family fit or free capacity simultaneously';

        return [
            'schema_version'  => self::SCHEMA_VERSION,
            'decision'        => self::DECISION_WAIT_OR_ROUTE_ELSEWHERE,
            'selected_agent'  => null,
            'quality_reason'  => $qualityReason,
            'capacity_reason' => $capacityReason,
        ];
    }

    /** @param  array<int, array<string,mixed>>  $candidates */
    private function failureRateFor(array $candidates, string $agentId): float
    {
        foreach ($candidates as $c) {
            if ((string) ($c['agent_id'] ?? '') === $agentId) {
                return max(0.0, min(1.0, (float) ($c['recent_failure_rate'] ?? 0.0)));
            }
        }

        return 0.0;
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
