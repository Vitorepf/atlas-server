<?php

namespace App\Services\Ai\SelfConstruction;

use Carbon\CarbonImmutable;

/**
 * Derive which Agent Control Plane agents are currently available to
 * receive a task packet.
 *
 * Pure projection: never starts processes, never pings real agents,
 * never spawns subprocesses, never invokes adapters, never dispatches
 * work, never spends tokens and never writes the evidence ledger.
 *
 * The planner is deterministic given (agents, heartbeats, options).
 */
final class AgentRuntimeRegistryAvailabilityPlanner
{
    public const SCHEMA_VERSION = 'atlas.self_construction.agent_runtime_registry_availability_plan.v1';

    public const MODE = 'read_only_agent_runtime_registry_availability_plan';

    public const DEFAULT_TTL_SECONDS = 90;

    public function __construct(
        private readonly AgentRuntimeRegistryCapabilityCatalog $catalog = new AgentRuntimeRegistryCapabilityCatalog,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $agents
     * @param  array<int, array<string, mixed>>  $heartbeats
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function plan(array $agents, array $heartbeats = [], array $options = []): array
    {
        $ttl = max(1, (int) ($options['ttl_seconds'] ?? self::DEFAULT_TTL_SECONDS));
        $referenceIso = (string) ($options['reference_time'] ?? CarbonImmutable::now()->toIso8601String());
        $referenceTs = strtotime($referenceIso);
        if ($referenceTs === false) {
            $referenceTs = time();
        }

        $requiredCapabilities = $this->catalog->normalizeCapabilities(
            (array) ($options['required_capabilities'] ?? [])
        );
        $requireWorkspaceIsolation = (bool) ($options['require_workspace_isolation'] ?? false);
        $requireLeaseSupport = (bool) ($options['require_lease_support'] ?? false);
        $quarantinedAgents = $this->normalizeStringList((array) ($options['quarantined_agents'] ?? []));

        $heartbeatByAgent = [];
        foreach ($heartbeats as $hb) {
            $agentId = (string) ($hb['agent_id'] ?? '');
            if ($agentId === '') {
                continue;
            }
            $existing = $heartbeatByAgent[$agentId] ?? null;
            if ($existing === null) {
                $heartbeatByAgent[$agentId] = $hb;

                continue;
            }
            $existingTs = strtotime((string) ($existing['observed_at'] ?? ''));
            $candidateTs = strtotime((string) ($hb['observed_at'] ?? ''));
            if ($candidateTs !== false && ($existingTs === false || $candidateTs > $existingTs)) {
                $heartbeatByAgent[$agentId] = $hb;
            }
        }

        $available = [];
        $unavailable = [];
        $stale = [];

        foreach ($agents as $agent) {
            $agentId = (string) ($agent['agent_id'] ?? '');
            if ($agentId === '') {
                continue;
            }
            $status = (string) ($agent['status'] ?? '');
            $kind = (string) ($agent['kind'] ?? '');
            $capabilities = $this->catalog->normalizeCapabilities((array) ($agent['capabilities'] ?? []));
            $maxParallel = max(0, (int) ($agent['max_parallel_tasks'] ?? 0));
            $currentTasks = max(0, (int) ($agent['current_task_count'] ?? 0));

            $reasons = [];

            if (in_array($agentId, $quarantinedAgents, true)) {
                $reasons[] = 'quarantined';
            }
            if ($status === 'quarantined') {
                $reasons[] = 'quarantined_status';
            }
            if ($status === 'disabled') {
                $reasons[] = 'disabled';
            }
            if ($status === 'unregistered') {
                $reasons[] = 'unregistered';
            }
            if ($status === 'busy') {
                $reasons[] = 'busy_status';
            }
            if ($status === 'stale') {
                $reasons[] = 'stale_status';
            }

            $hb = $heartbeatByAgent[$agentId] ?? null;
            $heartbeatRequired = (bool) ($agent['heartbeat_required'] ?? true);
            $heartbeatStatus = 'not_required';
            $heartbeatAge = null;

            if ($heartbeatRequired) {
                if ($hb === null) {
                    $reasons[] = 'missing_heartbeat';
                    $heartbeatStatus = 'missing';
                    $stale[] = [
                        'agent_id' => $agentId,
                        'reason' => 'missing_heartbeat',
                    ];
                } else {
                    $observed = (string) ($hb['observed_at'] ?? '');
                    $ts = strtotime($observed);
                    if ($ts === false) {
                        $reasons[] = 'invalid_heartbeat_timestamp';
                        $heartbeatStatus = 'invalid';
                        $stale[] = [
                            'agent_id' => $agentId,
                            'reason' => 'invalid_heartbeat_timestamp',
                        ];
                    } else {
                        $heartbeatAge = $referenceTs - $ts;
                        if ($heartbeatAge > $ttl) {
                            $reasons[] = 'stale_heartbeat';
                            $heartbeatStatus = 'stale';
                            $stale[] = [
                                'agent_id' => $agentId,
                                'reason' => 'stale_heartbeat',
                                'age_seconds' => $heartbeatAge,
                            ];
                        } else {
                            $heartbeatStatus = 'fresh';
                        }
                    }
                }
            }

            if ($maxParallel > 0 && $currentTasks >= $maxParallel) {
                $reasons[] = 'capacity_full';
            }

            $missingCapabilities = [];
            if ($requiredCapabilities !== []) {
                $missingCapabilities = array_values(array_diff($requiredCapabilities, $capabilities));
                if ($missingCapabilities !== []) {
                    $reasons[] = 'missing_capabilities';
                }
            }

            if ($requireWorkspaceIsolation && ! (bool) ($agent['workspace_isolation_supported'] ?? false)) {
                $reasons[] = 'workspace_isolation_missing';
            }
            if ($requireLeaseSupport && ! (bool) ($agent['lease_supported'] ?? false)) {
                $reasons[] = 'lease_support_missing';
            }

            $eligibleStatus = in_array($status, ['available', 'registered'], true);
            if (! $eligibleStatus && ! in_array('quarantined_status', $reasons, true)) {
                $reasons[] = 'status_not_eligible:'.$status;
            }

            $reasons = array_values(array_unique($reasons));
            $payload = [
                'agent_id' => $agentId,
                'kind' => $kind,
                'status' => $status,
                'capabilities' => $capabilities,
                'missing_capabilities' => $missingCapabilities,
                'max_parallel_tasks' => $maxParallel,
                'current_task_count' => $currentTasks,
                'heartbeat_status' => $heartbeatStatus,
                'heartbeat_age_seconds' => $heartbeatAge,
                'reasons' => $reasons,
            ];

            if ($reasons === []) {
                $available[] = $payload;
            } else {
                $unavailable[] = $payload;
            }
        }

        usort($available, static fn (array $a, array $b): int => strcmp((string) $a['agent_id'], (string) $b['agent_id']));
        usort($unavailable, static fn (array $a, array $b): int => strcmp((string) $a['agent_id'], (string) $b['agent_id']));
        usort($stale, static fn (array $a, array $b): int => strcmp((string) $a['agent_id'], (string) $b['agent_id']));

        $capacitySummary = [
            'total_agents' => count($agents),
            'available_count' => count($available),
            'unavailable_count' => count($unavailable),
            'stale_count' => count($stale),
            'total_slots' => array_sum(array_map(static fn (array $a): int => max(0, (int) ($a['max_parallel_tasks'] ?? 0)), $agents)),
            'used_slots' => array_sum(array_map(static fn (array $a): int => max(0, (int) ($a['current_task_count'] ?? 0)), $agents)),
            'free_slots_available' => array_sum(array_map(
                static fn (array $a): int => max(
                    0,
                    max(0, (int) ($a['max_parallel_tasks'] ?? 0)) - max(0, (int) ($a['current_task_count'] ?? 0)),
                ),
                $available,
            )),
        ];

        $hashPayload = [
            'available' => array_map(static fn (array $a): string => (string) $a['agent_id'], $available),
            'unavailable' => array_map(static fn (array $a): array => [
                'agent_id' => (string) $a['agent_id'],
                'reasons' => (array) $a['reasons'],
            ], $unavailable),
            'capacity' => $capacitySummary,
            'options' => [
                'ttl_seconds' => $ttl,
                'required_capabilities' => $requiredCapabilities,
                'require_workspace_isolation' => $requireWorkspaceIsolation,
                'require_lease_support' => $requireLeaseSupport,
                'quarantined_agents' => $quarantinedAgents,
            ],
        ];

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'reference_time' => $referenceIso,
            'ttl_seconds' => $ttl,
            'required_capabilities' => $requiredCapabilities,
            'require_workspace_isolation' => $requireWorkspaceIsolation,
            'require_lease_support' => $requireLeaseSupport,
            'available_agents' => $available,
            'unavailable_agents' => $unavailable,
            'stale_agents' => $stale,
            'capacity_summary' => $capacitySummary,
            'availability_hash' => $this->stableHash($hashPayload),
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
