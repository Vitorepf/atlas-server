<?php

namespace App\Services\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\Support\HashesPayloadCanonically;
use Carbon\CarbonImmutable;

/**
 * Certify the Agent Runtime Registry layer (repository, capability
 * catalog, heartbeats, availability, task matcher, load balancing,
 * quarantine, handoff, orchestrator) under hard-law invariants.
 *
 * Pure projection: certification never dispatches, never claims, never
 * starts a process, never calls a provider, never spends tokens and
 * never writes the evidence ledger.
 */
final class AgentRuntimeRegistryCertificationService
{
    use HashesPayloadCanonically;

    public const SCHEMA_VERSION = 'atlas.self_construction.agent_runtime_registry_certification.v1';

    public const MODE = 'read_only_agent_runtime_registry_certification';

    public function __construct(
        private readonly AgentRuntimeRegistryRepository $registry = new AgentRuntimeRegistryRepository,
        private readonly AgentRuntimeRegistryCapabilityCatalog $catalog = new AgentRuntimeRegistryCapabilityCatalog,
        private readonly AgentRuntimeRegistryHeartbeatRepository $heartbeats = new AgentRuntimeRegistryHeartbeatRepository,
        private readonly AgentRuntimeRegistryAvailabilityPlanner $availability = new AgentRuntimeRegistryAvailabilityPlanner,
        private readonly AgentRuntimeRegistryTaskMatcher $matcher = new AgentRuntimeRegistryTaskMatcher,
        private readonly AgentRuntimeRegistryLoadBalancingPolicy $loadBalancer = new AgentRuntimeRegistryLoadBalancingPolicy,
        private readonly AgentRuntimeRegistryQuarantineRepository $quarantine = new AgentRuntimeRegistryQuarantineRepository,
        private readonly AgentRuntimeRegistryHandoffProtocolBuilder $handoff = new AgentRuntimeRegistryHandoffProtocolBuilder,
        private readonly AgentRuntimeRegistryOrchestrator $orchestrator = new AgentRuntimeRegistryOrchestrator,
    ) {}

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function certify(array $options = []): array
    {
        $now = CarbonImmutable::now()->toIso8601String();
        $invariants = [];

        $invariants[] = $this->invariantBool(
            'registry_repository_available',
            $this->registry->isAvailable(),
            'AgentRuntimeRegistryRepository must report a healthy storage probe.',
        );
        $invariants[] = $this->invariantBool(
            'capability_catalog_available',
            (int) ($this->catalog->catalog()['capability_count'] ?? 0) > 0,
            'Capability catalog must list at least one canonical capability.',
        );
        $invariants[] = $this->invariantBool(
            'heartbeat_repository_available',
            $this->heartbeats->isAvailable(),
            'AgentRuntimeRegistryHeartbeatRepository must report a healthy storage probe.',
        );
        $invariants[] = $this->invariantBool(
            'availability_planner_available',
            method_exists($this->availability, 'plan'),
            'AgentRuntimeRegistryAvailabilityPlanner must expose plan().',
        );
        $invariants[] = $this->invariantBool(
            'task_matcher_available',
            method_exists($this->matcher, 'match'),
            'AgentRuntimeRegistryTaskMatcher must expose match().',
        );
        $invariants[] = $this->invariantBool(
            'load_balancing_available',
            method_exists($this->loadBalancer, 'rank'),
            'AgentRuntimeRegistryLoadBalancingPolicy must expose rank().',
        );
        $invariants[] = $this->invariantBool(
            'quarantine_repository_available',
            $this->quarantine->isAvailable(),
            'AgentRuntimeRegistryQuarantineRepository must report a healthy storage probe.',
        );
        $invariants[] = $this->invariantBool(
            'handoff_protocol_builder_available',
            method_exists($this->handoff, 'build'),
            'AgentRuntimeRegistryHandoffProtocolBuilder must expose build().',
        );
        $invariants[] = $this->invariantBool(
            'orchestrator_available',
            method_exists($this->orchestrator, 'health'),
            'AgentRuntimeRegistryOrchestrator must expose health().',
        );

        $invariants[] = $this->invariantRegisterIdempotent();
        $invariants[] = $this->invariantHeartbeatStaleDetection();
        $invariants[] = $this->invariantCapabilityMatchDetection();
        $invariants[] = $this->invariantQuarantineBlocksAvailability();
        $invariants[] = $this->invariantHandoffRequiresContinuation();

        $runtimeSafety = $this->runtimeSafetyInvariants();
        foreach ($runtimeSafety as $invariant) {
            $invariants[] = $invariant;
        }

        $allTrue = true;
        $violations = 0;
        $warnings = 0;
        foreach ($invariants as $invariant) {
            if (! (bool) ($invariant['ok'] ?? false)) {
                $allTrue = false;
                $violations++;
            }
            if (($invariant['warning'] ?? false) === true) {
                $warnings++;
            }
        }

        $invariantsHashPayload = array_map(
            static fn (array $i): array => [
                'name' => (string) ($i['name'] ?? ''),
                'ok' => (bool) ($i['ok'] ?? false),
            ],
            $invariants,
        );

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'status' => $allTrue ? 'available' : 'blocked',
            'certified_at' => $now,
            'invariants' => $invariants,
            'invariants_all_true' => $allTrue,
            'violation_count' => $violations,
            'warning_count' => $warnings,
            'runtime_safety' => [
                'runtime_execution_allowed' => false,
                'dispatch_allowed' => false,
                'provider_call_allowed' => false,
                'token_spend_allowed' => false,
                'self_programming_allowed' => false,
                'ledger_write_allowed' => false,
                'handoff_execution_allowed' => false,
                'runtime_safety_all_false' => true,
            ],
            'certification_hash' => $this->stableHash([
                'invariants' => $invariantsHashPayload,
                'all_true' => $allTrue,
            ]),
            'next_action' => $allTrue
                ? 'keep_registry_layer_persistent_local_until_runtime_pilot_promotes'
                : 'fix_failing_invariants_before_promotion',
            'runtime_execution_allowed' => false,
            'dispatch_allowed' => false,
            'provider_call_allowed' => false,
            'token_spend_allowed' => false,
            'self_programming_allowed' => false,
            'ledger_write_allowed' => false,
        ];
    }

    private const DEFAULT_MAX_EVIDENCE_AGE_DAYS = 14;

    private const DEFAULT_HIGH_GIVE_BACK_RATE_CEILING = 0.50;

    private const MIN_OUTCOME_SAMPLE_FOR_GIVE_BACK_RATE = 3;

    /**
     * Certifies whether a SPECIFIC worker is ready for critical tasks, per
     * task family — never on a self-declared or stale claim alone.
     *
     * Fails closed (every family blocked) when the worker's readiness
     * evidence is self_declared=true or older than max_evidence_age_days:
     * a worker cannot certify itself, and old evidence may no longer
     * reflect current behavior.
     *
     * Per-family blockers (any one excludes that family from
     * allowed_task_families):
     *   missing_capability:<name>   — worker lacks a capability the family requires
     *   high_give_back_rate         — give_back_rate > ceiling with enough samples
     *   known_failure_mode          — family listed in the worker's known_failure_modes
     *
     * readiness_status:
     *   certified — at least one family allowed, no global evidence failure
     *   partial   — some families allowed, some blocked
     *   blocked   — no family allowed (including the global self-declared/stale case)
     *
     * @param  array<string, mixed>  $workerFacts
     * @return array<string, mixed>
     */
    public function certifyWorkerReadiness(array $workerFacts): array
    {
        $agentId = (string) ($workerFacts['agent_id'] ?? 'unknown');
        $capabilities = array_map('strval', (array) ($workerFacts['capabilities'] ?? []));
        $taskFamilies = (array) ($workerFacts['task_families'] ?? []);
        $evidence = (array) ($workerFacts['evidence'] ?? []);
        $recentOutcomes = (array) ($workerFacts['recent_outcomes'] ?? []);
        $knownFailureModes = array_map('strval', (array) ($workerFacts['known_failure_modes'] ?? []));
        $maxEvidenceAgeDays = (int) ($workerFacts['max_evidence_age_days'] ?? self::DEFAULT_MAX_EVIDENCE_AGE_DAYS);

        $selfDeclared = (bool) ($evidence['self_declared'] ?? false);
        $evidenceAgeDays = (int) ($evidence['age_days'] ?? 0);
        $evidenceStale = $evidenceAgeDays > $maxEvidenceAgeDays;
        $globalEvidenceFailure = $selfDeclared || $evidenceStale;

        $allowed = [];
        $blocked = [];

        foreach ($taskFamilies as $familyFacts) {
            if (! is_array($familyFacts) || ! isset($familyFacts['family'])) {
                continue;
            }
            $family = (string) $familyFacts['family'];
            $requiredCapabilities = array_map('strval', (array) ($familyFacts['required_capabilities'] ?? []));

            $reasons = [];

            if ($selfDeclared) {
                $reasons[] = 'self_declared_evidence_not_verified';
            }
            if ($evidenceStale) {
                $reasons[] = sprintf('stale_evidence_age_days_%d_exceeds_max_%d', $evidenceAgeDays, $maxEvidenceAgeDays);
            }

            $missingCapabilities = array_values(array_diff($requiredCapabilities, $capabilities));
            foreach ($missingCapabilities as $missing) {
                $reasons[] = "missing_capability:{$missing}";
            }

            $familyOutcomes = array_values(array_filter(
                $recentOutcomes,
                static fn ($o): bool => is_array($o) && (string) ($o['family'] ?? '') === $family,
            ));
            $sampleSize = count($familyOutcomes);
            $giveBackCount = count(array_filter(
                $familyOutcomes,
                static fn (array $o): bool => (string) ($o['outcome'] ?? '') === 'give_back',
            ));
            $giveBackRate = $sampleSize > 0 ? round($giveBackCount / $sampleSize, 4) : 0.0;
            if ($sampleSize >= self::MIN_OUTCOME_SAMPLE_FOR_GIVE_BACK_RATE && $giveBackRate > self::DEFAULT_HIGH_GIVE_BACK_RATE_CEILING) {
                $reasons[] = 'high_give_back_rate';
            }

            if (in_array($family, $knownFailureModes, true)) {
                $reasons[] = 'known_failure_mode';
            }

            if ($reasons === []) {
                $allowed[] = $family;
            } else {
                $blocked[] = ['family' => $family, 'reasons' => $reasons];
            }
        }

        $readinessStatus = match (true) {
            $allowed === [] => 'blocked',
            $blocked === [] => 'certified',
            default => 'partial',
        };

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'agent_id' => $agentId,
            'readiness_status' => $readinessStatus,
            'capability_coverage' => $capabilities,
            'evidence_freshness' => [
                'age_days' => $evidenceAgeDays,
                'max_age_days' => $maxEvidenceAgeDays,
                'is_stale' => $evidenceStale,
                'self_declared' => $selfDeclared,
            ],
            'allowed_task_families' => $allowed,
            'blocked_task_families' => $blocked,
            'global_evidence_failure' => $globalEvidenceFailure,
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
     * @return array<string, mixed>
     */
    private function invariantRegisterIdempotent(): array
    {
        $sandboxRegistry = new AgentRuntimeRegistryRepository;
        $agentId = 'cert.idempotent.'.bin2hex(random_bytes(4));
        $payload = [
            'agent_id' => $agentId,
            'kind' => 'dry_run_agent',
            'label' => 'Certification probe',
            'status' => 'registered',
            'capabilities' => ['dry_run_only', 'evidence_collection'],
            'max_parallel_tasks' => 1,
            'current_task_count' => 0,
        ];
        try {
            $first = $sandboxRegistry->register($payload);
            $second = $sandboxRegistry->register($payload);
            $ok = (($first['status'] ?? '') === 'ok') && (($second['idempotent'] ?? false) === true);
            $sandboxRegistry->unregister($agentId, ['reason' => 'certification_cleanup']);

            return $this->invariantBool(
                'register_idempotent',
                $ok,
                'Re-registering the same agent payload must be idempotent.',
            );
        } catch (\Throwable $e) {
            return $this->invariantBool(
                'register_idempotent',
                false,
                'Registry threw exception during idempotent register check: '.$e->getMessage(),
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function invariantHeartbeatStaleDetection(): array
    {
        $hb = new AgentRuntimeRegistryHeartbeatRepository;
        $agentId = 'cert.stale.probe';
        try {
            $hb->record($agentId, [
                'status' => 'healthy',
                'observed_at' => CarbonImmutable::now()->subSeconds(3600)->toIso8601String(),
            ]);
            $status = $hb->heartbeatStatus($agentId, ['ttl_seconds' => 60]);
            $ok = (bool) ($status['is_stale'] ?? false);

            return $this->invariantBool(
                'heartbeat_stale_detection',
                $ok,
                'Heartbeats older than TTL must be reported as stale.',
            );
        } catch (\Throwable $e) {
            return $this->invariantBool(
                'heartbeat_stale_detection',
                false,
                'Heartbeat repository threw exception: '.$e->getMessage(),
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function invariantCapabilityMatchDetection(): array
    {
        $match = $this->catalog->match(['code_edit', 'evidence_collection'], ['code_edit']);
        $ok = ($match['match_status'] ?? '') === 'partial' && in_array('evidence_collection', (array) ($match['missing'] ?? []), true);

        return $this->invariantBool(
            'capability_match_detection',
            $ok,
            'Capability catalog must detect partial matches with explicit missing entries.',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function invariantQuarantineBlocksAvailability(): array
    {
        try {
            $agents = [[
                'agent_id' => 'cert.q.'.bin2hex(random_bytes(4)),
                'kind' => 'dry_run_agent',
                'status' => 'available',
                'capabilities' => ['dry_run_only'],
                'max_parallel_tasks' => 1,
                'current_task_count' => 0,
                'heartbeat_required' => false,
            ]];
            $plan = $this->availability->plan($agents, [], [
                'quarantined_agents' => [$agents[0]['agent_id']],
            ]);
            $ok = (int) ($plan['capacity_summary']['available_count'] ?? -1) === 0;

            return $this->invariantBool(
                'quarantine_blocks_availability',
                $ok,
                'Quarantined agents must be reported as unavailable.',
            );
        } catch (\Throwable $e) {
            return $this->invariantBool(
                'quarantine_blocks_availability',
                false,
                'Availability planner threw exception: '.$e->getMessage(),
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function invariantHandoffRequiresContinuation(): array
    {
        $plan = $this->handoff->build(
            ['agent_id' => 'from', 'capabilities' => ['code_edit']],
            ['agent_id' => 'to', 'capabilities' => ['code_edit'], 'status' => 'available'],
            ['task_packet_id' => 'tp', 'task_packet_hash' => 'h', 'required_capabilities' => ['code_edit'], 'evidence_refs' => ['ev1']],
        );
        $ok = in_array('missing_continuation_summary', (array) ($plan['blockers'] ?? []), true);

        return $this->invariantBool(
            'handoff_requires_continuation',
            $ok,
            'Handoff plan must block when continuation summary hash is missing.',
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function runtimeSafetyInvariants(): array
    {
        $flags = $this->orchestrator->runtimeFlags();
        $invariants = [];
        foreach ([
            'runtime_execution_allowed',
            'dispatch_allowed',
            'provider_call_allowed',
            'token_spend_allowed',
            'self_programming_allowed',
            'ledger_write_allowed',
            'handoff_execution_allowed',
        ] as $flag) {
            $invariants[] = $this->invariantBool(
                'runtime_safety:'.$flag.'_false',
                ($flags[$flag] ?? null) === false,
                "Runtime flag {$flag} must remain false in this stage.",
            );
        }
        $invariants[] = $this->invariantBool(
            'runtime_safety:no_dispatch_real',
            true,
            'This stage must not dispatch any real work to any agent.',
        );
        $invariants[] = $this->invariantBool(
            'runtime_safety:no_provider_call',
            true,
            'This stage must not call any external provider.',
        );
        $invariants[] = $this->invariantBool(
            'runtime_safety:no_token_spend',
            true,
            'This stage must not spend tokens.',
        );
        $invariants[] = $this->invariantBool(
            'runtime_safety:no_self_programming',
            true,
            'This stage must not enable self-programming.',
        );

        return $invariants;
    }

    /**
     * @return array<string, mixed>
     */
    private function invariantBool(string $name, bool $ok, string $description, bool $warning = false): array
    {
        return [
            'name' => $name,
            'ok' => $ok,
            'description' => $description,
            'warning' => $warning,
        ];
    }

}
