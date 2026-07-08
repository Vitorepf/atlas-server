<?php

namespace App\Services\Ai\SelfConstruction\ControlPlane;

use Carbon\CarbonImmutable;
use App\Services\Ai\SelfConstruction\Support\HashesPayloadCanonically;

/**
 * Compose the Agent Runtime Registry layer (registry, heartbeat,
 * capability catalog, availability, task matcher, load balancing,
 * quarantine, handoff) into a single advisory plan.
 *
 * Pure projection: never dispatches, never claims a real lease, never
 * starts a process, never calls a provider, never spends tokens and
 * never writes the evidence ledger.
 */
final class AgentRuntimeRegistryOrchestrator
{
    use HashesPayloadCanonically;
    public const SCHEMA_VERSION = 'atlas.self_construction.agent_runtime_registry_orchestrator.v1';

    public const MODE = 'read_only_agent_runtime_registry_orchestrator';

    public function __construct(
        private readonly AgentRuntimeRegistryRepository $registry = new AgentRuntimeRegistryRepository,
        private readonly AgentRuntimeRegistryCapabilityCatalog $catalog = new AgentRuntimeRegistryCapabilityCatalog,
        private readonly AgentRuntimeRegistryHeartbeatRepository $heartbeats = new AgentRuntimeRegistryHeartbeatRepository,
        private readonly AgentRuntimeRegistryAvailabilityPlanner $availability = new AgentRuntimeRegistryAvailabilityPlanner,
        private readonly AgentRuntimeRegistryTaskMatcher $matcher = new AgentRuntimeRegistryTaskMatcher,
        private readonly AgentRuntimeRegistryLoadBalancingPolicy $loadBalancer = new AgentRuntimeRegistryLoadBalancingPolicy,
        private readonly AgentRuntimeRegistryQuarantineRepository $quarantine = new AgentRuntimeRegistryQuarantineRepository,
        private readonly AgentRuntimeRegistryHandoffProtocolBuilder $handoff = new AgentRuntimeRegistryHandoffProtocolBuilder,
    ) {}

    /**
     * @param  array<string, mixed>  $agent
     * @param  array<string, mixed>  $heartbeat
     * @return array<string, mixed>
     */
    public function registerAndHeartbeat(array $agent, array $heartbeat = []): array
    {
        $registered = $this->registry->register($agent);
        $heartbeatResult = null;
        if (($registered['status'] ?? '') === 'ok' && $heartbeat !== []) {
            $agentId = (string) ($registered['agent_id'] ?? '');
            $heartbeatResult = $this->heartbeats->record($agentId, $heartbeat);
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'event' => 'register_and_heartbeat',
            'registry' => $registered,
            'heartbeat' => $heartbeatResult,
            'runtime_execution_allowed' => false,
            'dispatch_allowed' => false,
            'provider_call_allowed' => false,
            'token_spend_allowed' => false,
            'self_programming_allowed' => false,
            'ledger_write_allowed' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $taskPacket
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function planAssignment(array $taskPacket, array $options = []): array
    {
        $now = CarbonImmutable::now()->toIso8601String();
        $blockers = [];
        $warnings = [];

        $agents = $this->loadAgents();
        $heartbeats = $this->loadLatestHeartbeats($agents);
        $quarantinedIds = $this->quarantine->activeAgentIds();

        if ($agents === []) {
            $blockers[] = 'no_agents_registered';
        }

        $required = $this->catalog->normalizeCapabilities(
            (array) ($taskPacket['required_capabilities'] ?? [])
        );
        $workspacePolicy = (string) ($taskPacket['workspace_policy'] ?? 'none');
        $requiresIsolation = in_array($workspacePolicy, ['isolated', 'isolated_worktree', 'workspace_required'], true);
        $requiresLease = (bool) ($taskPacket['requires_lease'] ?? false);
        $ttl = (int) ($options['ttl_seconds'] ?? AgentRuntimeRegistryAvailabilityPlanner::DEFAULT_TTL_SECONDS);

        $availabilityPlan = $this->availability->plan($agents, $heartbeats, [
            'required_capabilities' => $required,
            'require_workspace_isolation' => $requiresIsolation,
            'require_lease_support' => $requiresLease,
            'quarantined_agents' => $quarantinedIds,
            'ttl_seconds' => $ttl,
            'reference_time' => (string) ($options['reference_time'] ?? $now),
        ]);

        $availableAgents = $this->materializeAvailableAgents($agents, (array) $availabilityPlan['available_agents']);

        $matchPlan = $this->matcher->match($taskPacket, $availableAgents, [
            'matching_policy' => (string) ($options['matching_policy'] ?? 'capability_first'),
        ]);

        $rankPlan = $this->loadBalancer->rank((array) $matchPlan['candidate_agents'], [
            'policy' => (string) ($options['load_balancing_policy'] ?? 'capability_score'),
        ]);

        if ($matchPlan['best_candidate'] === null) {
            $blockers[] = 'no_matching_candidates';
        }
        if ($availabilityPlan['available_agents'] === []) {
            $blockers[] = 'no_available_agents';
        }
        if ($quarantinedIds !== []) {
            $warnings[] = 'quarantined_agents_present';
        }

        $status = $blockers === [] ? 'planned' : 'blocked';

        // AC4: proof continuity — an assignment carrying continuation_context claims to pick
        // up prior evidence; one without it is a fresh assignment and continuity is simply not
        // applicable. Never invented from data this orchestrator does not have.
        $continuationContext = (array) ($taskPacket['continuation_context'] ?? []);
        $proofContinuityStatus = $continuationContext !== []
            ? 'continuous_from_prior_evidence'
            : 'new_assignment_no_continuity_required';

        // AC4: next_repair_action — the single most useful next step given the current
        // blockers, in fixed priority order (missing registry > no availability > no match).
        $nextRepairAction = match (true) {
            in_array('no_agents_registered', $blockers, true) => 'register_at_least_one_agent',
            in_array('no_available_agents', $blockers, true) => $this->repairActionFromAvailability($availabilityPlan),
            in_array('no_matching_candidates', $blockers, true) => 'broaden_task_capability_requirements_or_register_matching_agent',
            default => 'none_required',
        };

        $hashPayload = [
            'task_packet_id' => (string) ($taskPacket['task_packet_id'] ?? ''),
            'availability_hash' => (string) ($availabilityPlan['availability_hash'] ?? ''),
            'match_hash' => (string) ($matchPlan['match_hash'] ?? ''),
            'ranking_hash' => (string) ($rankPlan['ranking_hash'] ?? ''),
            'blockers' => $blockers,
            'warnings' => $warnings,
        ];

        $dispatchReadiness = $blockers === [] ? 'ready' : 'blocked';
        $selectionReason = $blockers === []
            ? 'fresh_matching_agent_selected'
            : implode(',', array_unique($blockers));

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'event' => 'assignment_plan',
            'status' => $status,
            'dispatch_readiness' => $dispatchReadiness,
            'selection_reason' => $selectionReason,
            'planned_at' => $now,
            'registry_summary' => $this->registry->registry()['status_counts'] ?? [],
            'availability_summary' => $availabilityPlan['capacity_summary'] ?? [],
            'availability_plan' => $availabilityPlan,
            'match_plan' => $matchPlan,
            'ranking_plan' => $rankPlan,
            'best_candidate' => $matchPlan['best_candidate'] ?? null,
            'selected_agent' => $rankPlan['selected_agent'] ?? null,
            'rejected_agents' => $availabilityPlan['unavailable_agents'] ?? [],
            'proof_continuity_status' => $proofContinuityStatus,
            'next_repair_action' => $nextRepairAction,
            'blockers' => array_values(array_unique($blockers)),
            'warnings' => array_values(array_unique($warnings)),
            'assignment_hash' => $this->stableHash($hashPayload),
            'dispatch_allowed' => false,
            'runtime_execution_allowed' => false,
            'provider_call_allowed' => false,
            'token_spend_allowed' => false,
            'self_programming_allowed' => false,
            'ledger_write_allowed' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $taskPacket
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function planHandoff(string $fromAgentId, string $toAgentId, array $taskPacket, array $options = []): array
    {
        $fromAgent = $this->registry->get($fromAgentId) ?? ['agent_id' => $fromAgentId];
        $toAgent = $this->registry->get($toAgentId) ?? ['agent_id' => $toAgentId];

        if ($this->quarantine->isQuarantined($toAgentId)) {
            $toAgent['status'] = 'quarantined';
        }
        if ($this->quarantine->isQuarantined($fromAgentId)) {
            $fromAgent['status'] = $fromAgent['status'] ?? 'quarantined';
        }

        $plan = $this->handoff->build($fromAgent, $toAgent, $taskPacket, $options);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'event' => 'handoff_plan',
            'plan' => $plan,
            'runtime_execution_allowed' => false,
            'dispatch_allowed' => false,
            'provider_call_allowed' => false,
            'token_spend_allowed' => false,
            'self_programming_allowed' => false,
            'ledger_write_allowed' => false,
            'handoff_execution_allowed' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function health(array $options = []): array
    {
        $registrySummary = $this->registry->registry();
        $heartbeatSummary = $this->heartbeats->staleAgents([
            'ttl_seconds' => (int) ($options['ttl_seconds'] ?? AgentRuntimeRegistryHeartbeatRepository::DEFAULT_TTL_SECONDS),
        ]);
        $quarantineActive = $this->quarantine->activeAgentIds();
        $capabilities = $this->catalog->catalog();

        $blockers = [];
        if (! $this->registry->isAvailable()) {
            $blockers[] = 'registry_unavailable';
        }
        if (! $this->heartbeats->isAvailable()) {
            $blockers[] = 'heartbeats_unavailable';
        }
        if (! $this->quarantine->isAvailable()) {
            $blockers[] = 'quarantine_unavailable';
        }

        $orchestratorHashPayload = [
            'registry_summary' => $registrySummary['status_counts'] ?? [],
            'kind_counts' => $registrySummary['kind_counts'] ?? [],
            'heartbeat' => [
                'stale_count' => (int) ($heartbeatSummary['stale_count'] ?? 0),
                'fresh_count' => (int) ($heartbeatSummary['fresh_count'] ?? 0),
            ],
            'quarantine_active' => $quarantineActive,
            'blockers' => $blockers,
        ];

        $registryCount = (int) ($registrySummary['total_count'] ?? 0);
        $heartbeatCount = (int) ($heartbeatSummary['fresh_count'] ?? 0) + (int) ($heartbeatSummary['stale_count'] ?? 0);
        $quarantinedCount = count($quarantineActive);
        $dispatchableCount = max(0, $heartbeatCount - $quarantinedCount);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'status' => $blockers === [] ? 'available' : 'degraded',
            'registry_count' => $registryCount,
            'heartbeat_count' => $heartbeatCount,
            'quarantined_count' => $quarantinedCount,
            'dispatchable_count' => $dispatchableCount,
            'registry_summary' => [
                'total_agents' => (int) ($registrySummary['total_count'] ?? 0),
                'status_counts' => (array) ($registrySummary['status_counts'] ?? []),
                'kind_counts' => (array) ($registrySummary['kind_counts'] ?? []),
            ],
            'heartbeat_summary' => [
                'ttl_seconds' => (int) ($heartbeatSummary['ttl_seconds'] ?? 0),
                'stale_count' => (int) ($heartbeatSummary['stale_count'] ?? 0),
                'fresh_count' => (int) ($heartbeatSummary['fresh_count'] ?? 0),
            ],
            'capability_summary' => [
                'capability_count' => (int) ($capabilities['capability_count'] ?? 0),
            ],
            'quarantine_summary' => [
                'active_agent_count' => count($quarantineActive),
                'active_agent_ids' => $quarantineActive,
            ],
            'assignment_plan' => null,
            'handoff_plan' => null,
            'blockers' => $blockers,
            'warnings' => $quarantineActive === [] ? [] : ['quarantined_agents_present'],
            'orchestrator_hash' => $this->stableHash($orchestratorHashPayload),
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
            'handoff_execution_allowed' => false,
        ];
    }

    /**
     * Translates the availability planner's repair_reasons into one concrete next step,
     * in fixed priority order — the most severe/authoritative blocker first.
     *
     * @param  array<string, mixed>  $availabilityPlan
     */
    private function repairActionFromAvailability(array $availabilityPlan): string
    {
        $repairReasons = (array) ($availabilityPlan['repair_reasons'] ?? []);

        return match (true) {
            in_array('quarantined', $repairReasons, true) || in_array('quarantined_status', $repairReasons, true) => 'unquarantine_or_register_a_non_quarantined_agent',
            in_array('missing_capabilities', $repairReasons, true) => 'register_agent_with_required_capabilities',
            in_array('capacity_full', $repairReasons, true) => 'free_agent_capacity_or_register_additional_agent',
            in_array('stale_heartbeat', $repairReasons, true) || in_array('missing_heartbeat', $repairReasons, true) => 'refresh_agent_heartbeat',
            in_array('no_agents_registered', $repairReasons, true) => 'register_at_least_one_agent',
            default => 'repair:'.($repairReasons[0] ?? 'unknown_availability_blocker'),
        };
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function loadAgents(): array
    {
        return $this->registry->list();
    }

    /**
     * @param  array<int, array<string, mixed>>  $agents
     * @return list<array<string, mixed>>
     */
    private function loadLatestHeartbeats(array $agents): array
    {
        $heartbeats = [];
        foreach ($agents as $agent) {
            $agentId = (string) ($agent['agent_id'] ?? '');
            if ($agentId === '') {
                continue;
            }
            $hb = $this->heartbeats->latest($agentId);
            if ($hb !== null) {
                $heartbeats[] = $hb;
            }
        }

        return $heartbeats;
    }

    /**
     * @param  array<int, array<string, mixed>>  $allAgents
     * @param  array<int, array<string, mixed>>  $availabilityEntries
     * @return list<array<string, mixed>>
     */
    private function materializeAvailableAgents(array $allAgents, array $availabilityEntries): array
    {
        $availableIds = [];
        foreach ($availabilityEntries as $entry) {
            $availableIds[(string) ($entry['agent_id'] ?? '')] = true;
        }

        $available = [];
        foreach ($allAgents as $agent) {
            $agentId = (string) ($agent['agent_id'] ?? '');
            if ($agentId !== '' && isset($availableIds[$agentId])) {
                $available[] = $agent;
            }
        }

        return $available;
    }

}
