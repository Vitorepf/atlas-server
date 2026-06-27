<?php

namespace App\Services\Ai\SelfConstruction;


use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use App\Services\Ai\SelfConstruction\Concerns\RecursivelyKsortsArrays;

/**
 * Plans whether multiple task packets can run in parallel, computing the
 * conflict matrix, blocked pairs, lease plan and a serial / parallel
 * scheduling plan. Overlap on write_set blocks parallelism; overlap on
 * read_set is permitted. Forbidden axes block any pair.
 *
 * Read-only: never starts processes, never calls Codex CLI/app, never
 * spawns subprocesses, never invokes adapters, never dispatches work,
 * never spends tokens, never advances the next required slice, never
 * enables self-programming, never writes the ledger.
 */
final class AgentControlPlaneMultiAgentParallelismPlanner
{
    use RecursivelyKsortsArrays;
    public const SCHEMA_VERSION = 'atlas.self_construction.agent_control_plane_multi_agent_parallelism_plan.v1';

    public const MODE = 'read_only_agent_control_plane_multi_agent_parallelism_plan';

    /**
     * @param  array<int, array<string, mixed>>  $taskPackets
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function plan(array $taskPackets, array $options = []): array
    {
        $maxParallel = max(1, (int) ($options['max_parallel_agents'] ?? 4));
        $packets = array_values($taskPackets);
        $packetCount = count($packets);

        $conflictMatrix = [];
        $blockedPairs = [];
        $leasePlan = [];

        for ($i = 0; $i < $packetCount; $i++) {
            $left = $packets[$i];
            $leftId = (string) ($left['task_packet_id'] ?? 'packet-'.$i);
            $leftAllowed = (array) data_get($left, 'normalized_scope.allowed_files', []);
            $leftAxes = (array) data_get($left, 'normalized_scope.forbidden_axis_hits', []);

            $leasePlan[] = [
                'task_packet_id' => $leftId,
                'lease_id_simulated' => (string) Str::uuid(),
                'allowed_file_count' => count($leftAllowed),
                'lease_runtime_enabled' => false,
                'isolation_strategy' => 'simulated_worktree',
            ];

            for ($j = $i + 1; $j < $packetCount; $j++) {
                $right = $packets[$j];
                $rightId = (string) ($right['task_packet_id'] ?? 'packet-'.$j);
                $rightAllowed = (array) data_get($right, 'normalized_scope.allowed_files', []);
                $rightAxes = (array) data_get($right, 'normalized_scope.forbidden_axis_hits', []);

                $writeOverlap = WriteSetOverlap::collidingPaths($leftAllowed, $rightAllowed); // A5/MF-12: prefix-aware dir-vs-file
                $axisOverlap = $leftAxes !== [] && $rightAxes !== [];

                $entry = [
                    'left' => $leftId,
                    'right' => $rightId,
                    'write_overlap' => $writeOverlap,
                    'write_overlap_count' => count($writeOverlap),
                    'axis_overlap' => $axisOverlap,
                    'parallel_allowed' => $writeOverlap === [] && ! $axisOverlap,
                ];
                $conflictMatrix[] = $entry;
                if (! $entry['parallel_allowed']) {
                    $blockedPairs[] = $entry;
                }
            }
        }

        $schedulingPlan = $this->scheduleParallelism($packets, $blockedPairs, $maxParallel);
        $isolationPlan = [
            'isolation_strategy' => 'simulated_worktree_per_packet',
            'shared_workspace_id' => 'FORGE-WORKSPACE-ATLAS-SELF-CONSTRUCTION-0001',
            'auto_apply' => false,
            'runtime_enabled' => false,
        ];

        $mergePolicy = [
            'strategy' => 'serialized_merge_via_operator_review',
            'auto_merge_runtime_enabled' => false,
            'block_on_axis_overlap' => true,
        ];

        $parallelismAllowed = $blockedPairs === [] && $packetCount >= 2;

        $blockingReasons = [];
        if ($packetCount === 0) {
            $blockingReasons[] = 'no_task_packets';
        }
        foreach ($packets as $idx => $packet) {
            if ((string) ($packet['status'] ?? 'unknown') !== 'planned') {
                $blockingReasons[] = 'packet_'.$idx.'_not_planned';
            }
        }
        if ($blockedPairs !== []) {
            $blockingReasons[] = 'write_or_axis_overlap_detected';
        }

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'parallelism_plan_id' => (string) Str::uuid(),
            'generated_at' => CarbonImmutable::now()->toIso8601String(),
            'status' => $blockingReasons === [] ? 'planned' : 'planned_blocked',
            'agent_count' => $packetCount,
            'task_packet_count' => $packetCount,
            'parallelism_allowed' => $parallelismAllowed,
            'conflict_matrix' => $conflictMatrix,
            'lease_plan' => $leasePlan,
            'scheduling_plan' => $schedulingPlan,
            'isolation_plan' => $isolationPlan,
            'merge_policy' => $mergePolicy,
            'max_parallel_agents' => $maxParallel,
            'blocked_pairs' => $blockedPairs,
            'blocked_pair_count' => count($blockedPairs),
            'blocking_reasons' => $blockingReasons,
            'read_only' => true,
            'runtime_disabled' => true,
            'dispatch_allowed' => false,
            'provider_call_allowed' => false,
            'token_spend_allowed' => false,
            'self_programming_allowed' => false,
            'ledger_write_allowed' => false,
            'persistence_allowed' => false,
            'non_execution_guarantees' => [
                'multi_agent_parallelism_planner_does_not_start_codex',
                'multi_agent_parallelism_planner_does_not_call_codex_cli_or_app',
                'multi_agent_parallelism_planner_does_not_spawn_subprocess',
                'multi_agent_parallelism_planner_does_not_invoke_adapter',
                'multi_agent_parallelism_planner_does_not_call_provider',
                'multi_agent_parallelism_planner_does_not_dispatch_work',
                'multi_agent_parallelism_planner_does_not_spend_tokens',
                'multi_agent_parallelism_planner_does_not_enable_self_programming',
                'multi_agent_parallelism_planner_does_not_write_ledger',
                'multi_agent_parallelism_planner_does_not_persist_leases',
                'multi_agent_parallelism_planner_does_not_mutate_pointer',
            ],
            'human_summary' => sprintf(
                'Multi-agent parallelism: %d packets, %d blocked pairs, parallelism %s.',
                $packetCount,
                count($blockedPairs),
                $parallelismAllowed ? 'allowed' : 'blocked',
            ),
        ];

        $payload['parallelism_hash'] = $this->stableHash($this->normalizeForHash($payload));

        return $payload;
    }

    /**
     * @param  array<int, array<string, mixed>>  $packets
     * @param  array<int, array<string, mixed>>  $blockedPairs
     * @return array<string, mixed>
     */
    private function scheduleParallelism(array $packets, array $blockedPairs, int $maxParallel): array
    {
        $waves = [];
        $remaining = array_values(array_filter(array_map(
            fn (array $p): string => (string) ($p['task_packet_id'] ?? ''),
            $packets,
        )));

        $blockedSet = [];
        foreach ($blockedPairs as $pair) {
            $leftId = (string) ($pair['left'] ?? '');
            $rightId = (string) ($pair['right'] ?? '');
            $blockedSet[$leftId][$rightId] = true;
            $blockedSet[$rightId][$leftId] = true;
        }

        while ($remaining !== []) {
            $wave = [];
            foreach ($remaining as $candidate) {
                if (count($wave) >= $maxParallel) {
                    break;
                }
                $canJoin = true;
                foreach ($wave as $member) {
                    if (isset($blockedSet[$candidate][$member])) {
                        $canJoin = false;
                        break;
                    }
                }
                if ($canJoin) {
                    $wave[] = $candidate;
                }
            }
            if ($wave === []) {
                $wave[] = array_shift($remaining);
            } else {
                $remaining = array_values(array_diff($remaining, $wave));
            }
            $waves[] = [
                'wave' => count($waves) + 1,
                'packets' => $wave,
                'size' => count($wave),
            ];
        }

        return [
            'strategy' => 'greedy_conflict_free_waves',
            'max_parallel_agents' => $maxParallel,
            'waves' => $waves,
            'wave_count' => count($waves),
            'runtime_enabled' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function normalizeForHash(array $payload): array
    {
        $clone = $payload;
        unset($clone['parallelism_plan_id'], $clone['generated_at'], $clone['parallelism_hash'], $clone['human_summary']);
        if (isset($clone['lease_plan']) && is_array($clone['lease_plan'])) {
            $clone['lease_plan'] = array_map(static function ($entry) {
                if (is_array($entry)) {
                    unset($entry['lease_id_simulated'], $entry['task_packet_id']);
                }

                return $entry;
            }, $clone['lease_plan']);
        }
        foreach (['conflict_matrix', 'blocked_pairs'] as $matrixKey) {
            if (isset($clone[$matrixKey]) && is_array($clone[$matrixKey])) {
                $clone[$matrixKey] = array_map(static function ($entry) {
                    if (is_array($entry)) {
                        unset($entry['left'], $entry['right']);
                    }

                    return $entry;
                }, $clone[$matrixKey]);
            }
        }
        if (isset($clone['scheduling_plan']['waves']) && is_array($clone['scheduling_plan']['waves'])) {
            $clone['scheduling_plan']['waves'] = array_map(static function ($wave) {
                if (is_array($wave)) {
                    unset($wave['packets']);
                }

                return $wave;
            }, $clone['scheduling_plan']['waves']);
        }

        return $this->recursivelyKsort($clone);
    }


    /**
     * @param  array<mixed, mixed>  $payload
     */
    private function stableHash(array $payload): string
    {
        $payload = $this->recursivelyKsort($payload);

        return hash('sha256', (string) json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
