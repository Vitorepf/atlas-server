<?php

namespace App\Services\Ai\Programming\Forge;

use App\Models\AiForgeIntake;
use App\Models\AiForgeMultiAgentSchedule;
use App\Models\AiForgeWorkPacket;
use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\Support\AiValueNormalizer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Produces `atlas.forge.multi_agent_schedule.v1`: an auditable plan that
 * tells downstream layers HOW MANY agents to run, WHICH role each plays,
 * WHO owns which files, in WHAT order, and what verification gates apply.
 *
 * This service:
 *  - never spawns agents;
 *  - never invokes providers;
 *  - never writes a workspace file;
 *  - never declares "Nx faster than X" — comparison is explicitly out of
 *    scope per brief.
 *
 * It can run with or without persistence ({@see plan()} vs
 * {@see planAndPersist()}). Tests use {@see plan()}; runtime callers use
 * {@see planAndPersist()} so the schedule row is auditable from the
 * control plane.
 *
 * Inputs (see {@see plan()}):
 *  - `task_summary`         — free-form short description (required).
 *  - `work_packets`         — list<AiForgeWorkPacket | array>. Each must
 *    expose: packet_id, objective, expected_files (list<string>),
 *    dependencies (list<string of other packet_ids>), suggested_tests.
 *  - `risk_band`            — low|medium|high|critical (drives verifier).
 *  - `intake`               — optional AiForgeIntake (supplies obra_id,
 *    mission_id, workspace_slug — pure context, never executed).
 *  - `options`              — {allow_serialize, verification_required,
 *    reviewer_required, failure_context, mission_id, work_order_id}.
 *
 * Outputs (canonical schedule contract — see {@see toCanonical()}):
 *  - schedule_uuid, status, recommended_agent_count;
 *  - role_assignments (planner/worker/verifier/researcher/debugger/reviewer);
 *  - ownership_map (packet_id → owned_paths) with conflict detection;
 *  - non_overlap_constraints;
 *  - dependency_order (topological);
 *  - integration_plan (single_worker | parallel_no_overlap |
 *    serial_merge_on_overlap | blocked);
 *  - verification_plan ({verifier_count, watches, gates_required});
 *  - conflict_risks;
 *  - schedule_hash (sha256 over canonical payload).
 *
 * Rules encoded:
 *  - 0/1 packet           → 1 worker, integration=single_worker.
 *  - N ≥ 2 packets without file overlap → N workers, integration=parallel_no_overlap.
 *  - N ≥ 2 packets with file overlap AND options.allow_serialize !== true →
 *    status=blocked_by_ownership_conflict, integration=blocked, count=0.
 *  - N ≥ 2 packets with file overlap AND options.allow_serialize === true →
 *    status=ready, integration=serial_merge_on_overlap, ordering respects
 *    dependency_order; verification still runs.
 *  - risk_band ∈ {high, critical} OR options.verification_required → +verifier.
 *  - worker_count ≥ 5 → +planner (coordination).
 *  - intake.recommended_forge_mode == sdd_intake OR risk_band == critical → +researcher.
 *  - options.failure_context truthy → +debugger.
 *  - options.reviewer_required truthy → +reviewer.
 *
 * Cycle detection in dependency graph throws
 * {@see ForgeMultiAgentSchedulerException::dependencyCycle()}.
 */
class ForgeMultiAgentSchedulerService
{
    /**
     * @param  array<int,AiForgeWorkPacket|array<string,mixed>>  $workPackets
     * @param  array<string,mixed>  $options
     * @return array<string,mixed> canonical schedule contract
     */
    public function plan(
        string $taskSummary,
        array $workPackets = [],
        string $riskBand = ForgeMultiAgentScheduleCanon::RISK_MEDIUM,
        ?AiForgeIntake $intake = null,
        array $options = [],
    ): array {
        $taskSummary = trim($taskSummary);
        if ($taskSummary === '') {
            throw ForgeMultiAgentSchedulerException::emptyTaskSummary();
        }
        if (! in_array($riskBand, ForgeMultiAgentScheduleCanon::RISK_BANDS, true)) {
            throw ForgeMultiAgentSchedulerException::invalidRiskBand($riskBand);
        }

        $normalisedPackets = $this->normalisePackets($workPackets);
        $ownershipMap = $this->buildOwnershipMap($normalisedPackets);
        $overlaps = $this->detectFileOverlaps($ownershipMap);
        $unscoped = $this->detectUnscoped($ownershipMap);
        $dependencyOrder = $this->topologicalOrder($normalisedPackets);

        $allowSerialize = (bool) ($options['allow_serialize'] ?? false);
        $verificationRequired = (bool) ($options['verification_required'] ?? false);
        $reviewerRequired = (bool) ($options['reviewer_required'] ?? false);
        $failureContext = (bool) ($options['failure_context'] ?? false);
        $missionId = AiValueNormalizer::trimmedStringOrNull($options['mission_id'] ?? null);
        $workOrderId = AiValueNormalizer::trimmedStringOrNull($options['work_order_id'] ?? null);

        $obraId = AiValueNormalizer::trimmedStringOrNull($options['obra_id'] ?? $intake?->id);

        $packetCount = count($normalisedPackets);

        // Determine integration + base worker_count + status.
        $status = ForgeMultiAgentScheduleCanon::STATUS_READY;
        $blockerReason = null;
        $conflictRisks = [];

        if ($packetCount === 0) {
            $integrationPlan = ForgeMultiAgentScheduleCanon::INTEGRATION_SINGLE_WORKER;
            $workerCount = 1;
            $status = ForgeMultiAgentScheduleCanon::STATUS_DEGENERATE_NO_PACKETS;
        } elseif ($packetCount === 1) {
            $integrationPlan = ForgeMultiAgentScheduleCanon::INTEGRATION_SINGLE_WORKER;
            $workerCount = 1;
            if ($unscoped !== []) {
                $conflictRisks[] = [
                    'kind' => ForgeMultiAgentScheduleCanon::CONFLICT_KIND_UNSCOPED_PACKET,
                    'packet_ids' => $unscoped,
                    'paths' => [],
                ];
            }
        } else {
            if ($overlaps !== []) {
                foreach ($overlaps as $overlap) {
                    $conflictRisks[] = [
                        'kind' => ForgeMultiAgentScheduleCanon::CONFLICT_KIND_FILE_OVERLAP,
                        'packet_ids' => $overlap['packet_ids'],
                        'paths' => $overlap['paths'],
                    ];
                }
            }
            if ($unscoped !== []) {
                $conflictRisks[] = [
                    'kind' => ForgeMultiAgentScheduleCanon::CONFLICT_KIND_UNSCOPED_PACKET,
                    'packet_ids' => $unscoped,
                    'paths' => [],
                ];
            }

            $hardConflict = $overlaps !== [] || $unscoped !== [];
            if ($hardConflict && ! $allowSerialize) {
                $status = ForgeMultiAgentScheduleCanon::STATUS_BLOCKED_BY_OWNERSHIP_CONFLICT;
                $integrationPlan = ForgeMultiAgentScheduleCanon::INTEGRATION_BLOCKED;
                $workerCount = 0;
                $blockerReason = $overlaps !== []
                    ? 'file_overlap_without_serialize_consent'
                    : 'unscoped_packets_without_serialize_consent';
            } elseif ($hardConflict && $allowSerialize) {
                $integrationPlan = ForgeMultiAgentScheduleCanon::INTEGRATION_SERIAL_MERGE_ON_OVERLAP;
                $workerCount = $packetCount;
            } else {
                $integrationPlan = ForgeMultiAgentScheduleCanon::INTEGRATION_PARALLEL_NO_OVERLAP;
                $workerCount = $packetCount;
            }
        }

        $non_overlap_constraints = $this->buildNonOverlapConstraints($ownershipMap, $integrationPlan);

        // Compose role assignments.
        $assignments = [];
        if ($workerCount > 0) {
            $assignments[] = [
                'role' => ForgeMultiAgentScheduleCanon::ROLE_WORKER,
                'count' => $workerCount,
                'owns_packet_ids' => array_keys($ownershipMap) ?: [],
                'justification' => $packetCount <= 1
                    ? 'single_packet_or_simple_task'
                    : ($integrationPlan === ForgeMultiAgentScheduleCanon::INTEGRATION_SERIAL_MERGE_ON_OVERLAP
                        ? 'serial_merge_on_overlap_with_explicit_consent'
                        : 'one_worker_per_independent_packet'),
            ];
        }

        $verifierCount = 0;
        if (in_array($riskBand, ForgeMultiAgentScheduleCanon::highRiskBands(), true) || $verificationRequired) {
            $verifierCount = 1;
            $assignments[] = [
                'role' => ForgeMultiAgentScheduleCanon::ROLE_VERIFIER,
                'count' => 1,
                'owns_packet_ids' => [],
                'justification' => $verificationRequired ? 'verification_required_by_caller' : 'risk_band='.$riskBand,
            ];
        }

        if ($workerCount >= ForgeMultiAgentScheduleCanon::PLANNER_THRESHOLD) {
            $assignments[] = [
                'role' => ForgeMultiAgentScheduleCanon::ROLE_PLANNER,
                'count' => 1,
                'owns_packet_ids' => [],
                'justification' => 'worker_count>='.ForgeMultiAgentScheduleCanon::PLANNER_THRESHOLD.'_needs_coordinator',
            ];
        }

        $needsResearcher = $riskBand === ForgeMultiAgentScheduleCanon::RISK_CRITICAL
            || ($intake !== null && (string) $intake->recommended_forge_mode === 'sdd_intake');
        if ($needsResearcher && $workerCount > 0) {
            $assignments[] = [
                'role' => ForgeMultiAgentScheduleCanon::ROLE_RESEARCHER,
                'count' => 1,
                'owns_packet_ids' => [],
                'justification' => $riskBand === ForgeMultiAgentScheduleCanon::RISK_CRITICAL
                    ? 'critical_risk_requires_context_gathering'
                    : 'sdd_intake_mode_requires_research',
            ];
        }

        if ($failureContext && $workerCount > 0) {
            $assignments[] = [
                'role' => ForgeMultiAgentScheduleCanon::ROLE_DEBUGGER,
                'count' => 1,
                'owns_packet_ids' => [],
                'justification' => 'failure_context_flag_set_by_caller',
            ];
        }

        if ($reviewerRequired && $workerCount > 0) {
            $assignments[] = [
                'role' => ForgeMultiAgentScheduleCanon::ROLE_REVIEWER,
                'count' => 1,
                'owns_packet_ids' => [],
                'justification' => 'reviewer_required_by_caller',
            ];
        }

        $recommendedAgentCount = array_sum(array_map(
            static fn (array $a): int => (int) ($a['count'] ?? 0),
            $assignments,
        ));

        $verificationPlan = $this->buildVerificationPlan(
            verifierCount: $verifierCount,
            workerCount: $workerCount,
            packetIds: array_keys($ownershipMap),
            riskBand: $riskBand,
        );

        $rolesSummary = [];
        foreach ($assignments as $a) {
            $rolesSummary[(string) $a['role']] = (int) $a['count'];
        }

        $schedule = [
            'schema' => ForgeMultiAgentScheduleCanon::SCHEMA_VERSION,
            'schedule_uuid' => (string) Str::uuid(),
            'intake_id' => $intake?->id,
            'mission_id' => $missionId,
            'work_order_id' => $workOrderId,
            'obra_id' => $obraId,
            'task_summary' => $taskSummary,
            'risk_band' => $riskBand,
            'recommended_agent_count' => $recommendedAgentCount,
            'roles_summary' => $rolesSummary,
            'role_assignments' => $assignments,
            'ownership_map' => $ownershipMap,
            'non_overlap_constraints' => $non_overlap_constraints,
            'dependency_order' => $dependencyOrder,
            'integration_plan' => $integrationPlan,
            'conflict_risks' => $conflictRisks,
            'verification_plan' => $verificationPlan,
            'evidence_refs' => $this->buildEvidenceRefs($intake, $missionId, $workOrderId),
            'status' => $status,
            'blocker_reason' => $blockerReason,
            'created_at' => Carbon::now()->toISOString(),
        ];
        $schedule['schedule_hash'] = $this->computeScheduleHash($schedule);

        return $schedule;
    }

    /**
     * @param  array<int,AiForgeWorkPacket|array<string,mixed>>  $workPackets
     * @param  array<string,mixed>  $options
     */
    public function planAndPersist(
        string $taskSummary,
        array $workPackets = [],
        string $riskBand = ForgeMultiAgentScheduleCanon::RISK_MEDIUM,
        ?AiForgeIntake $intake = null,
        array $options = [],
    ): AiForgeMultiAgentSchedule {
        $contract = $this->plan($taskSummary, $workPackets, $riskBand, $intake, $options);

        return AiForgeMultiAgentSchedule::query()->create([
            'uuid' => (string) $contract['schedule_uuid'],
            'schema_version' => ForgeMultiAgentScheduleCanon::SCHEMA_VERSION,
            'intake_id' => $contract['intake_id'],
            'mission_id' => $contract['mission_id'],
            'work_order_id' => $contract['work_order_id'],
            'obra_id' => $contract['obra_id'],
            'task_summary' => $contract['task_summary'],
            'risk_band' => $contract['risk_band'],
            'recommended_agent_count' => (int) $contract['recommended_agent_count'],
            'roles_summary' => $contract['roles_summary'],
            'role_assignments' => $contract['role_assignments'],
            'ownership_map' => $contract['ownership_map'],
            'non_overlap_constraints' => $contract['non_overlap_constraints'],
            'dependency_order' => $contract['dependency_order'],
            'integration_plan' => $contract['integration_plan'],
            'conflict_risks' => $contract['conflict_risks'],
            'verification_plan' => $contract['verification_plan'],
            'evidence_refs' => $contract['evidence_refs'],
            'status' => $contract['status'],
            'blocker_reason' => $contract['blocker_reason'],
            'schedule_hash' => $contract['schedule_hash'],
        ]);
    }

    /**
     * @param  array<int,AiForgeWorkPacket|array<string,mixed>>  $workPackets
     * @return list<array<string,mixed>>
     */
    private function normalisePackets(array $workPackets): array
    {
        $out = [];
        foreach ($workPackets as $i => $p) {
            if ($p instanceof AiForgeWorkPacket) {
                $out[] = [
                    'packet_id' => (string) $p->packet_id,
                    'objective' => (string) $p->objective,
                    'expected_files' => array_values((array) ($p->expected_files ?? [])),
                    'dependencies' => array_values((array) ($p->dependencies ?? [])),
                    'suggested_tests' => array_values((array) ($p->suggested_tests ?? [])),
                ];

                continue;
            }
            if (! is_array($p)) {
                continue;
            }
            $packetId = (string) ($p['packet_id'] ?? $p['id'] ?? sprintf('wp-%03d', $i + 1));
            $out[] = [
                'packet_id' => $packetId,
                'objective' => (string) ($p['objective'] ?? $p['title'] ?? $packetId),
                'expected_files' => array_values(array_filter(
                    (array) ($p['expected_files'] ?? []),
                    static fn ($x): bool => is_string($x) && $x !== '',
                )),
                'dependencies' => array_values(array_filter(
                    (array) ($p['dependencies'] ?? []),
                    static fn ($x): bool => is_string($x) && $x !== '',
                )),
                'suggested_tests' => array_values(array_filter(
                    (array) ($p['suggested_tests'] ?? []),
                    static fn ($x): bool => is_string($x) && $x !== '',
                )),
            ];
        }

        return $out;
    }

    /**
     * @param  list<array<string,mixed>>  $packets
     * @return array<string,list<string>>
     */
    private function buildOwnershipMap(array $packets): array
    {
        $map = [];
        foreach ($packets as $p) {
            $map[(string) $p['packet_id']] = array_values(array_unique(
                array_values((array) $p['expected_files']),
            ));
        }

        return $map;
    }

    /**
     * @param  array<string,list<string>>  $ownership
     * @return list<array{packet_ids:list<string>,paths:list<string>}>
     */
    private function detectFileOverlaps(array $ownership): array
    {
        $byPath = [];
        foreach ($ownership as $packetId => $paths) {
            foreach ($paths as $path) {
                $byPath[$path] ??= [];
                $byPath[$path][] = (string) $packetId;
            }
        }

        $overlaps = [];
        foreach ($byPath as $path => $packetIds) {
            $unique = array_values(array_unique($packetIds));
            if (count($unique) >= 2) {
                $key = implode('|', $unique);
                $overlaps[$key] ??= [
                    'packet_ids' => $unique,
                    'paths' => [],
                ];
                $overlaps[$key]['paths'][] = $path;
            }
        }

        return array_values(array_map(
            static fn (array $o): array => [
                'packet_ids' => $o['packet_ids'],
                'paths' => array_values(array_unique($o['paths'])),
            ],
            $overlaps,
        ));
    }

    /**
     * @param  array<string,list<string>>  $ownership
     * @return list<string>
     */
    private function detectUnscoped(array $ownership): array
    {
        $unscoped = [];
        foreach ($ownership as $packetId => $paths) {
            if ($paths === []) {
                $unscoped[] = (string) $packetId;
            }
        }

        return $unscoped;
    }

    /**
     * Kahn topological sort. Cycle → exception.
     *
     * @param  list<array<string,mixed>>  $packets
     * @return list<string>
     */
    private function topologicalOrder(array $packets): array
    {
        $ids = array_map(static fn (array $p): string => (string) $p['packet_id'], $packets);
        $idSet = array_fill_keys($ids, true);

        $indeg = array_fill_keys($ids, 0);
        $adj = array_fill_keys($ids, []);

        foreach ($packets as $p) {
            $self = (string) $p['packet_id'];
            foreach ((array) $p['dependencies'] as $dep) {
                $depId = (string) $dep;
                if (! isset($idSet[$depId])) {
                    // Dangling dependency: ignore for ordering, treat as soft hint.
                    continue;
                }
                $adj[$depId][] = $self;
                $indeg[$self]++;
            }
        }

        $queue = [];
        foreach ($indeg as $id => $d) {
            if ($d === 0) {
                $queue[] = $id;
            }
        }
        sort($queue);

        $order = [];
        while ($queue !== []) {
            $id = array_shift($queue);
            $order[] = $id;
            foreach ($adj[$id] as $next) {
                $indeg[$next]--;
                if ($indeg[$next] === 0) {
                    $queue[] = $next;
                    sort($queue);
                }
            }
        }

        if (count($order) !== count($ids)) {
            $remaining = array_values(array_diff($ids, $order));
            throw ForgeMultiAgentSchedulerException::dependencyCycle($remaining[0] ?? '?');
        }

        return $order;
    }

    /**
     * @param  array<string,list<string>>  $ownership
     * @return list<array<string,mixed>>
     */
    private function buildNonOverlapConstraints(array $ownership, string $integrationPlan): array
    {
        $packetIds = array_keys($ownership);
        $constraints = [];
        for ($i = 0; $i < count($packetIds); $i++) {
            for ($j = $i + 1; $j < count($packetIds); $j++) {
                $a = (string) $packetIds[$i];
                $b = (string) $packetIds[$j];
                $shared = array_values(array_intersect($ownership[$a], $ownership[$b]));
                if ($shared === []) {
                    continue;
                }
                $constraints[] = [
                    'a' => $a,
                    'b' => $b,
                    'shared_paths' => $shared,
                    'rule' => $integrationPlan === ForgeMultiAgentScheduleCanon::INTEGRATION_SERIAL_MERGE_ON_OVERLAP
                        ? 'run_serially_in_dependency_order'
                        : 'must_not_run_concurrently',
                ];
            }
        }

        return $constraints;
    }

    /**
     * @param  list<string>  $packetIds
     * @return array<string,mixed>
     */
    private function buildVerificationPlan(int $verifierCount, int $workerCount, array $packetIds, string $riskBand): array
    {
        if ($verifierCount === 0) {
            return [
                'verifier_count' => 0,
                'watches' => [],
                'gates_required' => [],
            ];
        }

        // One verifier currently watches every worker. A future evolution can
        // shard verifiers across packet groups.
        $watches = [];
        foreach ($packetIds as $packetId) {
            $watches[] = [
                'verifier_index' => 0,
                'watches_packet_id' => (string) $packetId,
                'gates' => ['scope_guard', 'completion_state_gate', 'evidence_attached'],
            ];
        }

        return [
            'verifier_count' => $verifierCount,
            'watches' => $watches,
            'gates_required' => array_values(array_unique([
                'scope_guard',
                'completion_state_gate',
                'evidence_attached',
                ...(in_array($riskBand, ForgeMultiAgentScheduleCanon::highRiskBands(), true)
                    ? ['risk_review_passed']
                    : []),
            ])),
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function buildEvidenceRefs(?AiForgeIntake $intake, ?string $missionId, ?string $workOrderId): array
    {
        $refs = [];
        if ($intake !== null) {
            $refs[] = [
                'kind' => 'forge_intake',
                'ref' => $intake->id,
                'hash' => $intake->intake_hash,
            ];
        }
        if ($missionId !== null) {
            $refs[] = ['kind' => 'mission', 'ref' => $missionId];
        }
        if ($workOrderId !== null) {
            $refs[] = ['kind' => 'work_order', 'ref' => $workOrderId];
        }

        return $refs;
    }

    /**
     * @param  array<string,mixed>  $schedule
     */
    private function computeScheduleHash(array $schedule): string
    {
        $payload = $schedule;
        unset($payload['schedule_hash'], $payload['created_at'], $payload['schedule_uuid']);

        return MissionCanonicalHash::sha256($payload);
    }

}
