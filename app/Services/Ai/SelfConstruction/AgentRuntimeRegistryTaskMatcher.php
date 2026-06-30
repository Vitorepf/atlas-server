<?php

namespace App\Services\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\Support\HashesPayloadCanonically;

/**
 * Match a task packet against available agents and produce candidate
 * dispatch plans without claiming a lease or dispatching anything.
 *
 * Pure projection: never starts processes, never claims, never dispatches,
 * never invokes adapters, never calls providers, never spends tokens,
 * never writes the evidence ledger. The match is advisory only and the
 * orchestrator/operator decides whether the runtime path is enabled.
 */
final class AgentRuntimeRegistryTaskMatcher
{
    use HashesPayloadCanonically;
    public const SCHEMA_VERSION = 'atlas.self_construction.agent_runtime_registry_task_match.v1';

    public const MODE = 'read_only_agent_runtime_registry_task_match';

    public const RISK_LEVELS = ['low', 'medium', 'high', 'critical'];

    public function __construct(
        private readonly AgentRuntimeRegistryCapabilityCatalog $catalog = new AgentRuntimeRegistryCapabilityCatalog,
    ) {}

    /**
     * @param  array<string, mixed>  $taskPacket
     * @param  array<int, array<string, mixed>>  $agents
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function match(array $taskPacket, array $agents, array $options = []): array
    {
        $taskPacketId = (string) ($taskPacket['task_packet_id'] ?? '');
        $taskPacketHash = (string) ($taskPacket['task_packet_hash'] ?? '');
        $required = $this->catalog->normalizeCapabilities((array) ($taskPacket['required_capabilities'] ?? []));
        $risk = strtolower((string) ($taskPacket['risk_level'] ?? 'low'));
        if (! in_array($risk, self::RISK_LEVELS, true)) {
            $risk = 'low';
        }
        $workspacePolicy = (string) ($taskPacket['workspace_policy'] ?? 'none');
        $requiresWorkspaceIsolation = in_array($workspacePolicy, ['isolated', 'isolated_worktree', 'workspace_required'], true);
        $requiresLeaseSupport = (bool) ($taskPacket['requires_lease'] ?? false);
        $isDryRunOnly = (bool) ($taskPacket['dry_run_only'] ?? false);
        $matchingPolicy = (string) ($options['matching_policy'] ?? 'capability_first');

        $candidates = [];
        $rejected = [];

        foreach ($agents as $agent) {
            $agentId = (string) ($agent['agent_id'] ?? '');
            if ($agentId === '') {
                continue;
            }
            $kind = (string) ($agent['kind'] ?? '');
            $status = (string) ($agent['status'] ?? '');
            $capabilities = $this->catalog->normalizeCapabilities((array) ($agent['capabilities'] ?? []));
            $maxParallel = max(0, (int) ($agent['max_parallel_tasks'] ?? 0));
            $currentTasks = max(0, (int) ($agent['current_task_count'] ?? 0));

            $rejections = [];

            if (! in_array($status, ['available', 'registered'], true)) {
                $rejections[] = 'status_not_eligible:'.$status;
            }
            if ($maxParallel > 0 && $currentTasks >= $maxParallel) {
                $rejections[] = 'capacity_full';
            }

            $capabilityMatch = $this->catalog->match($required, $capabilities);
            if ($required !== [] && $capabilityMatch['match_status'] !== 'matched') {
                $rejections[] = 'missing_capabilities';
            }

            if ($requiresWorkspaceIsolation) {
                $supportsIsolation = (bool) ($agent['workspace_isolation_supported'] ?? false);
                $hasWorkspaceCapability = in_array('workspace_isolation', $capabilities, true) || in_array('workspace_planning', $capabilities, true);
                if (! $supportsIsolation || ! $hasWorkspaceCapability) {
                    $rejections[] = 'workspace_isolation_required';
                }
            }
            if ($requiresLeaseSupport && ! (bool) ($agent['lease_supported'] ?? false)) {
                $rejections[] = 'lease_support_required';
            }
            if (in_array($risk, ['high', 'critical'], true) && ! in_array('human_approval', $capabilities, true)) {
                $rejections[] = 'human_approval_required_for_high_risk';
            }
            if ($kind === 'dry_run_agent' && ! $isDryRunOnly) {
                $rejections[] = 'dry_run_agent_requires_dry_run_only_task';
            }
            if (in_array('dry_run_only', $capabilities, true) && ! $isDryRunOnly) {
                $rejections[] = 'dry_run_only_capability_requires_dry_run_only_task';
            }

            $freeSlots = $maxParallel > 0 ? max(0, $maxParallel - $currentTasks) : 0;
            $capabilityScore = (float) $capabilityMatch['match_score'];
            $loadScore = $maxParallel > 0 ? round(1 - ($currentTasks / $maxParallel), 4) : 1.0;
            $riskScore = $this->riskScore($risk, $capabilities);

            $payload = [
                'agent_id' => $agentId,
                'kind' => $kind,
                'status' => $status,
                'capabilities' => $capabilities,
                'capability_score' => $capabilityScore,
                'load_score' => $loadScore,
                'risk_score' => $riskScore,
                'free_slots' => $freeSlots,
                'current_task_count' => $currentTasks,
                'max_parallel_tasks' => $maxParallel,
                'capability_match' => $capabilityMatch,
                'rejections' => array_values(array_unique($rejections)),
            ];

            if ($rejections === []) {
                $candidates[] = $payload;
            } else {
                $rejected[] = $payload;
            }
        }

        $sortedCandidates = $this->sortCandidates($candidates, $matchingPolicy);
        $best = $sortedCandidates[0] ?? null;

        $bestCandidate = null;
        if ($best !== null) {
            $bestCandidate = [
                'agent_id' => (string) $best['agent_id'],
                'kind' => (string) $best['kind'],
                'capability_score' => (float) $best['capability_score'],
                'load_score' => (float) $best['load_score'],
                'risk_score' => (float) $best['risk_score'],
                'free_slots' => (int) $best['free_slots'],
            ];
        }

        $loadMatch = [
            'considered' => count($candidates),
            'rejected' => count($rejected),
            'best_load_score' => $best['load_score'] ?? null,
            'best_free_slots' => $best['free_slots'] ?? null,
        ];

        $riskMatch = [
            'risk_level' => $risk,
            'requires_human_approval' => in_array($risk, ['high', 'critical'], true),
            'best_risk_score' => $best['risk_score'] ?? null,
        ];

        $hashPayload = [
            'task_packet_id' => $taskPacketId,
            'task_packet_hash' => $taskPacketHash,
            'matching_policy' => $matchingPolicy,
            'candidates' => array_map(static fn (array $c): string => (string) $c['agent_id'], $sortedCandidates),
            'rejected' => array_map(static fn (array $r): array => [
                'agent_id' => (string) $r['agent_id'],
                'rejections' => (array) $r['rejections'],
            ], $rejected),
            'risk' => $risk,
            'workspace_policy' => $workspacePolicy,
            'dry_run_only' => $isDryRunOnly,
        ];

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'task_packet_id' => $taskPacketId,
            'task_packet_hash' => $taskPacketHash,
            'candidate_agents' => $sortedCandidates,
            'rejected_agents' => $rejected,
            'best_candidate' => $bestCandidate,
            'matching_policy' => $matchingPolicy,
            'capability_match' => [
                'required' => $required,
                'matched_agents' => array_values(array_map(static fn (array $c): string => (string) $c['agent_id'], $sortedCandidates)),
            ],
            'load_match' => $loadMatch,
            'risk_match' => $riskMatch,
            'match_hash' => $this->stableHash($hashPayload),
            'dispatch_allowed' => false,
            'runtime_execution_allowed' => false,
            'provider_call_allowed' => false,
            'token_spend_allowed' => false,
            'self_programming_allowed' => false,
            'ledger_write_allowed' => false,
        ];
    }

    private const POOR_FIT_THRESHOLD = 0.30;

    /**
     * Ranks available agents by overall fit for a task rather than mere
     * availability: capability fit, recent task-family experience, recent
     * outcome quality, give_back risk, and active load. Pure, advisory
     * only — never dispatches.
     *
     * fit_score = task_family_experience * 0.35
     *           + recent_success_rate    * 0.35
     *           + (1 - give_back_rate)   * 0.20
     *           + load_score             * 0.10
     *
     * An agent that is otherwise status-eligible but scores below
     * POOR_FIT_THRESHOLD (0.30) is suppressed from selection even though it
     * is available — fit beats raw availability.
     *
     * @param  array<string, mixed>  $taskPacket  { task_family?: string }
     * @param  array<int, array<string, mixed>>  $agents  each may carry
     *   task_family_experience (map family=>score 0..1) or
     *   family_experience_score (flat 0..1), recent_success_rate (0..1),
     *   give_back_rate (0..1), current_task_count, max_parallel_tasks.
     * @return array<string, mixed>
     */
    public function selectBestFit(array $taskPacket, array $agents): array
    {
        $taskFamily = (string) ($taskPacket['task_family'] ?? '');

        $eligible = [];
        $rejectedWorkers = [];

        foreach ($agents as $agent) {
            $agentId = (string) ($agent['agent_id'] ?? '');
            if ($agentId === '') {
                continue;
            }
            $status = (string) ($agent['status'] ?? '');

            if (! in_array($status, ['available', 'registered'], true)) {
                $rejectedWorkers[] = ['agent_id' => $agentId, 'reason' => 'status_not_eligible:'.$status];

                continue;
            }

            $familyExperience = isset($agent['task_family_experience'][$taskFamily])
                ? (float) $agent['task_family_experience'][$taskFamily]
                : (float) ($agent['family_experience_score'] ?? 0.0);
            $familyExperience = max(0.0, min(1.0, $familyExperience));

            $recentSuccessRate = max(0.0, min(1.0, (float) ($agent['recent_success_rate'] ?? 0.5)));
            $giveBackRate = max(0.0, min(1.0, (float) ($agent['give_back_rate'] ?? 0.0)));

            $maxParallel = max(0, (int) ($agent['max_parallel_tasks'] ?? 0));
            $currentTasks = max(0, (int) ($agent['current_task_count'] ?? 0));
            $loadScore = $maxParallel > 0 ? round(max(0.0, 1 - ($currentTasks / $maxParallel)), 4) : 1.0;

            $fitScore = round(
                $familyExperience * 0.35
                + $recentSuccessRate * 0.35
                + (1 - $giveBackRate) * 0.20
                + $loadScore * 0.10,
                4,
            );

            if ($fitScore < self::POOR_FIT_THRESHOLD) {
                $rejectedWorkers[] = ['agent_id' => $agentId, 'reason' => 'poor_fit_for_task_family', 'fit_score' => $fitScore];

                continue;
            }

            $eligible[] = [
                'agent_id' => $agentId,
                'task_family_experience' => $familyExperience,
                'recent_success_rate' => $recentSuccessRate,
                'give_back_rate' => $giveBackRate,
                'load_score' => $loadScore,
                'fit_score' => $fitScore,
            ];
        }

        usort($eligible, static fn (array $a, array $b): int => $b['fit_score'] <=> $a['fit_score'] ?: strcmp((string) $a['agent_id'], (string) $b['agent_id']));

        $selectedWorker = $eligible[0] ?? null;
        $rationale = $selectedWorker !== null
            ? sprintf('selected_%s_for_highest_fit_score_%.4f_in_family_%s', $selectedWorker['agent_id'], $selectedWorker['fit_score'], $taskFamily !== '' ? $taskFamily : 'unspecified')
            : 'no_eligible_worker_meets_fit_threshold';

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'task_family' => $taskFamily,
            'selected_worker' => $selectedWorker,
            'rejected_workers' => $rejectedWorkers,
            'ranked_workers' => array_values($eligible),
            'rationale' => $rationale,
            'dispatch_allowed' => false,
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
     * @param  list<array<string, mixed>>  $candidates
     * @return list<array<string, mixed>>
     */
    private function sortCandidates(array $candidates, string $matchingPolicy): array
    {
        $sorted = $candidates;
        usort($sorted, function (array $a, array $b) use ($matchingPolicy): int {
            return match ($matchingPolicy) {
                'least_loaded' => $this->compareLoadFirst($a, $b),
                'risk_first_human' => $this->compareRiskFirst($a, $b),
                'dry_run_preferred' => $this->compareDryRunFirst($a, $b),
                default => $this->compareCapabilityFirst($a, $b),
            };
        });

        return array_values($sorted);
    }

    /**
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     */
    private function compareCapabilityFirst(array $a, array $b): int
    {
        $diff = ((float) $b['capability_score']) <=> ((float) $a['capability_score']);
        if ($diff !== 0) {
            return $diff;
        }
        $diff = ((float) $b['load_score']) <=> ((float) $a['load_score']);
        if ($diff !== 0) {
            return $diff;
        }

        return strcmp((string) $a['agent_id'], (string) $b['agent_id']);
    }

    /**
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     */
    private function compareLoadFirst(array $a, array $b): int
    {
        $diff = ((int) $b['free_slots']) <=> ((int) $a['free_slots']);
        if ($diff !== 0) {
            return $diff;
        }
        $diff = ((float) $b['capability_score']) <=> ((float) $a['capability_score']);
        if ($diff !== 0) {
            return $diff;
        }

        return strcmp((string) $a['agent_id'], (string) $b['agent_id']);
    }

    /**
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     */
    private function compareRiskFirst(array $a, array $b): int
    {
        $diff = ((float) $b['risk_score']) <=> ((float) $a['risk_score']);
        if ($diff !== 0) {
            return $diff;
        }

        return $this->compareCapabilityFirst($a, $b);
    }

    /**
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     */
    private function compareDryRunFirst(array $a, array $b): int
    {
        $aIsDry = ($a['kind'] ?? '') === 'dry_run_agent' ? 1 : 0;
        $bIsDry = ($b['kind'] ?? '') === 'dry_run_agent' ? 1 : 0;
        $diff = $bIsDry <=> $aIsDry;
        if ($diff !== 0) {
            return $diff;
        }

        return $this->compareCapabilityFirst($a, $b);
    }

    /**
     * @param  array<int, string>  $capabilities
     */
    private function riskScore(string $risk, array $capabilities): float
    {
        $score = match ($risk) {
            'critical' => 1.0,
            'high' => 0.8,
            'medium' => 0.5,
            default => 0.2,
        };
        if (in_array($risk, ['high', 'critical'], true) && in_array('human_approval', $capabilities, true)) {
            $score += 0.1;
        }

        return round(min(1.5, $score), 4);
    }

}
