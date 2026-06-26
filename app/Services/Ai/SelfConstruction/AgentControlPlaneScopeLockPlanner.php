<?php

namespace App\Services\Ai\SelfConstruction;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Plans a future scope lock for a task packet. Computes read/write sets,
 * cross-axis blockers, unsafe path blockers, rollback boundary and
 * conflict policy. Never persists a lock; never claims a packet; never
 * dispatches work.
 *
 * Read-only: never starts processes, never calls Codex CLI/app, never
 * spawns subprocesses, never invokes adapters, never dispatches work,
 * never spends tokens, never advances the next required slice, never
 * enables self-programming, never writes the ledger.
 */
final class AgentControlPlaneScopeLockPlanner
{
    public const SCHEMA_VERSION = 'atlas.self_construction.agent_control_plane_scope_lock_plan.v1';

    public const MODE = 'read_only_agent_control_plane_scope_lock_plan';

    public const CROSS_AXIS_BLOCKERS = [
        'self_improvement' => 'app/Services/Ai/SelfImprovement/',
        'programming' => 'app/Services/Ai/Programming/',
        'atlas_code_controllers' => 'app/Http/Controllers/AtlasCode',
        'routes_api' => 'routes/api.php',
        'atlas_desktop' => 'atlas-desktop/',
        'forge' => 'forge/',
        'rivals' => 'rivals/',
        'cartografia' => 'cartografia/',
        'voice' => 'voice/',
    ];

    public const UNSAFE_PATH_PREFIXES = [
        '../',
        '/etc/',
        '/var/log/',
        '~/',
        '.git/',
        '.env',
        'storage/framework/',
        'vendor/',
        'node_modules/',
    ];

    /**
     * @param  array<string, mixed>  $taskPacket
     * @param  array<string, mixed>  $claimLease
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function plan(array $taskPacket, array $claimLease = [], array $options = []): array
    {
        $packetId = (string) ($taskPacket['task_packet_id'] ?? 'task-packet-unknown');
        $packetStatus = (string) ($taskPacket['status'] ?? 'unknown');
        $allowed = $this->paths((array) data_get($taskPacket, 'normalized_scope.allowed_files', []));
        $forbidden = $this->paths((array) data_get($taskPacket, 'normalized_scope.forbidden_files', []));
        $scopeIn = $this->paths((array) data_get($taskPacket, 'normalized_scope.scope_in', []));
        $scopeOut = $this->paths((array) data_get($taskPacket, 'normalized_scope.scope_out', []));

        $readSetOverrides = $this->paths((array) ($options['read_set'] ?? []));
        $writeSetOverrides = $this->paths((array) ($options['write_set'] ?? []));

        $writeSet = $writeSetOverrides !== [] ? $writeSetOverrides : $allowed;
        $readSet = $readSetOverrides !== [] ? $readSetOverrides : array_values(array_unique(array_merge($allowed, $scopeIn)));
        sort($writeSet);
        sort($readSet);

        $crossAxisBlockers = [];
        foreach ($writeSet as $path) {
            foreach (self::CROSS_AXIS_BLOCKERS as $axis => $prefix) {
                if (str_starts_with($path, $prefix)) {
                    $crossAxisBlockers[] = ['axis' => $axis, 'path' => $path];
                }
            }
        }

        $unsafePathBlockers = [];
        foreach ($writeSet as $path) {
            foreach (self::UNSAFE_PATH_PREFIXES as $prefix) {
                if ($path === '' || str_starts_with($path, $prefix) || str_contains($path, '/'.$prefix)) {
                    $unsafePathBlockers[] = ['path' => $path, 'prefix' => $prefix];
                    break;
                }
            }
        }

        $forbiddenInWrite = WriteSetOverlap::collidingPaths($writeSet, $forbidden); // A5/MF-12: prefix-aware dir-vs-file

        $blockingReasons = [];
        if ($packetStatus !== 'planned') {
            $blockingReasons[] = 'task_packet_not_planned';
        }
        if ($writeSet === []) {
            $blockingReasons[] = 'write_set_empty';
        }
        if ($crossAxisBlockers !== []) {
            $blockingReasons[] = 'cross_axis_blocker';
        }
        if ($unsafePathBlockers !== []) {
            $blockingReasons[] = 'unsafe_path_blocker';
        }
        if ($forbiddenInWrite !== []) {
            $blockingReasons[] = 'forbidden_files_in_write_set';
        }

        $leaseStatus = (string) ($claimLease['lease_status'] ?? '');
        if ($leaseStatus !== '' && $leaseStatus !== 'simulated_granted') {
            $blockingReasons[] = 'lease_not_granted';
        }

        $status = $blockingReasons === [] ? 'planned_safe' : 'planned_blocked';

        $rollbackBoundary = [
            'strategy' => (string) ($options['rollback_strategy'] ?? 'git_worktree_discard'),
            'rollback_runtime_enabled' => false,
            'rollback_paths' => $writeSet,
        ];

        $conflictPolicy = [
            'on_write_overlap' => 'block',
            'on_read_overlap' => 'allow',
            'on_axis_overlap' => 'block',
            'on_forbidden_overlap' => 'block',
        ];

        $plan = [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'scope_lock_plan_id' => (string) Str::uuid(),
            'task_packet_id' => $packetId,
            'generated_at' => CarbonImmutable::now()->toIso8601String(),
            'status' => $status,
            'allowed_files' => $allowed,
            'forbidden_files' => $forbidden,
            'scope_in' => $scopeIn,
            'scope_out' => $scopeOut,
            'write_set' => $writeSet,
            'read_set' => $readSet,
            'conflict_policy' => $conflictPolicy,
            'rollback_boundary' => $rollbackBoundary,
            'unsafe_path_blockers' => $unsafePathBlockers,
            'cross_axis_blockers' => $crossAxisBlockers,
            'forbidden_in_write_set' => $forbiddenInWrite,
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
                'scope_lock_planner_does_not_start_codex',
                'scope_lock_planner_does_not_call_codex_cli_or_app',
                'scope_lock_planner_does_not_spawn_subprocess',
                'scope_lock_planner_does_not_invoke_adapter',
                'scope_lock_planner_does_not_call_provider',
                'scope_lock_planner_does_not_dispatch_work',
                'scope_lock_planner_does_not_spend_tokens',
                'scope_lock_planner_does_not_enable_self_programming',
                'scope_lock_planner_does_not_write_ledger',
                'scope_lock_planner_does_not_persist_lock',
                'scope_lock_planner_does_not_mutate_pointer',
            ],
            'human_summary' => $status === 'planned_safe'
                ? sprintf('Scope lock plan safe for packet %s (write_set=%d, read_set=%d).', $packetId, count($writeSet), count($readSet))
                : sprintf('Scope lock plan blocked for packet %s: %s.', $packetId, implode(', ', $blockingReasons)),
        ];

        $plan['scope_lock_plan_hash'] = $this->stableHash($this->normalizeForHash($plan));

        return $plan;
    }

    /**
     * @param  array<int|string, mixed>  $paths
     * @return list<string>
     */
    private function paths(array $paths): array
    {
        $out = [];
        foreach ($paths as $path) {
            $value = trim((string) $path);
            if ($value === '') {
                continue;
            }
            $out[] = str_replace('\\', '/', $value);
        }
        $out = array_values(array_unique($out));
        sort($out);

        return $out;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function normalizeForHash(array $payload): array
    {
        $clone = $payload;
        unset($clone['scope_lock_plan_id'], $clone['generated_at'], $clone['scope_lock_plan_hash'], $clone['human_summary'], $clone['task_packet_id']);

        return $this->recursivelyKsort($clone);
    }

    /**
     * @param  array<mixed, mixed>  $value
     * @return array<mixed, mixed>
     */
    private function recursivelyKsort(array $value): array
    {
        return ReadinessHash::ksortRecursive($value);
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
