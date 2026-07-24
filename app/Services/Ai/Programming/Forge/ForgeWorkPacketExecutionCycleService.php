<?php

namespace App\Services\Ai\Programming\Forge;

use App\Models\AiForgeIntake;
use App\Models\AiForgeLongHorizonState;
use App\Models\AiForgeWorkPacket;
use App\Models\AiForgeWorkPacketExecutionCycle;
use App\Services\Ai\AtlasDecide\AtlasDecideLiveOutcomeFeedbackService;
use App\Services\Ai\EngineeringKernel\AcceptanceBundle;
use App\Services\Ai\EngineeringKernel\Adapters\AtlasDevGateAdapter;
use App\Services\Ai\EngineeringKernel\EliteExecutorKernel;
use App\Services\Ai\EngineeringKernel\EngineeringModeExecutionOrderFactory;
use App\Services\Ai\EngineeringKernel\EngineeringOutcome;
use App\Services\Ai\EngineeringKernel\EngineeringRoleRoster;
use App\Services\Ai\EngineeringKernel\MutativeDecisionBinder;
use App\Services\Ai\EngineeringKernel\OutcomeProofGate;
use App\Services\Ai\EngineeringKernel\Repair\FailureBrainCorpus;
use App\Services\Ai\EngineeringKernel\Repair\RepairDiagnosisStage;
use App\Services\Ai\EngineeringKernel\TrustLevel;
use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\Programming\Forge\Intelligence\ForgeFailureIntelligenceService;
use App\Services\Ai\Programming\Forge\Intelligence\ForgeOutcomeMemoryService;
use App\Services\Ai\Programming\Forge\Intelligence\ForgeSpecialistWorkcellRouterService;
use App\Services\Ai\Programming\Forge\Intelligence\ForgeWorkPacketCapabilityOrchestrator;
use App\Services\Ai\SelfConstruction\AtlasTaskCommitVerificationGate;
use App\Services\Ai\Support\AppendOnlyJsonlStore;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

/**
 * Owns the lifecycle of `atlas.forge.work_packet_execution_cycle.v1`.
 *
 * One execution cycle = one audited attempt at one work packet. The service
 * is intentionally split into four stages that callers can drive end-to-end
 * OR in pieces:
 *
 *   1. {@see selectPacket()}   — pick an eligible packet from an intake +
 *                                long-horizon state pair, or return null +
 *                                the reason why none is eligible.
 *   2. {@see planExecution()}  — assemble the immutable plan + expected
 *                                artifacts + initial next_action.
 *   3. {@see startCycle()}     — persist a `planned`/`running` cycle row.
 *   4. {@see complete()}       — terminal `success` transition. Guarded by:
 *                                 - non-empty evidence_refs,
 *                                 - non-null gate_result with at least one
 *                                   gate marked `passed`,
 *                                 - mode != `blocked`.
 *      {@see fail()}            — terminal `failed` transition. Records
 *                                 failure_reason + repair_hook + next_action.
 *      {@see block()}           — terminal `blocked` transition. Records
 *                                 blocker reason + emits resolve_blocker
 *                                 next_action and writes a packet-scope
 *                                 blocker into the long-horizon state.
 *
 * Integration with long-horizon state is OPTIONAL: if a state is passed in,
 * its `active_work_packets` / `completed_work_packets` / `blockers` get
 * mutated on terminal transitions. Without a state, the cycle still records
 * a complete, audit-friendly row but no Obra-level projection happens.
 *
 * Provider/sandbox execution is reached only through the shared Kernel port
 * exposed by executeRealCycle(); this lifecycle service never implements a
 * second provider or sandbox mechanism. Rivals/benchmark remain out of scope.
 *
 * `safe_simulation` mode is the dry-run path. Its evidence must stay in the
 * simulation namespace and can never satisfy the productive `complete()`
 * transition that marks packets, milestones or Obra state as done.
 */
class ForgeWorkPacketExecutionCycleService
{
    public function __construct(
        private readonly ForgeLongHorizonStateService $longHorizon,
        private readonly ForgeMultiAgentSchedulerService $multiAgentScheduler,
        private readonly ForgeWorkPacketCapabilityOrchestrator $capabilities,
        private readonly ForgeSpecialistWorkcellRouterService $workcellRouter,
        private readonly ForgeFailureIntelligenceService $failureIntelligence,
        private readonly ForgeOutcomeMemoryService $outcomeMemory,
        private readonly ForgeScopeReservationService $scopeReservations,
        // O-1 bridge: terminal cycles feed the central compounding loop
        // THROUGH the conductor (the single legitimate feeder). Nullable so
        // plain `new` construction keeps working; the bridge is opt-in via
        // config anyway.
        private readonly ?\App\Services\Ai\AtlasDecide\AtlasEngineeringRunConductorService $engineeringConductor = null,
        ?AtlasDevGateAdapter $devGate = null,
        private readonly ?EliteExecutorKernel $eliteKernel = null,
        private readonly ?ForgeWorkPacketExecutionPort $kernelExecution = null,
    ) {
        $this->devGate = $devGate ?? new AtlasDevGateAdapter;
    }

    /** Obra #2/#3 — the sovereign floor, connected in observe-mode over completed Forge cycles. */
    private readonly AtlasDevGateAdapter $devGate;

    /**
     * Pick the next eligible packet. Eligibility rules:
     *  - packet.status ∈ {proposed, ready, claimed};
     *  - no unresolved blocker in $state with scope=packet + target=packet_id;
     *  - if $state has a non-empty `active_work_packets` list, prefer one of
     *    those (operator already nominated them).
     */
    public function selectPacket(AiForgeIntake $intake, ?AiForgeLongHorizonState $state = null): ?AiForgeWorkPacket
    {
        $eligibleStatuses = [
            ForgeIntakeCanon::PACKET_STATUS_PROPOSED,
            ForgeIntakeCanon::PACKET_STATUS_READY,
            ForgeIntakeCanon::PACKET_STATUS_CLAIMED,
        ];

        $packets = $intake->workPackets()
            ->whereIn('status', $eligibleStatuses)
            ->orderBy('packet_position')
            ->get();

        if ($packets->isEmpty()) {
            return null;
        }

        $blockedPacketIds = [];
        if ($state !== null) {
            foreach ((array) ($state->blockers ?? []) as $b) {
                if (! is_array($b)) {
                    continue;
                }
                if (($b['scope'] ?? null) !== ForgeLongHorizonStateCanon::BLOCKER_SCOPE_PACKET) {
                    continue;
                }
                if (($b['resolved'] ?? false) === true) {
                    continue;
                }
                $target = $b['target'] ?? null;
                if (is_string($target) && $target !== '') {
                    $blockedPacketIds[] = $target;
                }
            }
        }

        $completedPacketIds = [];
        if ($state !== null) {
            $completedPacketIds = array_merge(
                $completedPacketIds,
                array_values(array_filter(
                    (array) ($state->completed_work_packets ?? []),
                    static fn ($id): bool => is_string($id) && $id !== '',
                )),
            );
        }
        $completedPacketIds = array_merge(
            $completedPacketIds,
            AiForgeWorkPacketExecutionCycle::query()
                ->whereIn('work_packet_id', $intake->workPackets()->pluck('id'))
                ->where('outcome_status', ForgeWorkPacketExecutionCycleCanon::OUTCOME_SUCCESS)
                ->pluck('work_packet_canonical_id')
                ->map(static fn ($id): string => (string) $id)
                ->all(),
        );
        $completedPacketIds = array_values(array_unique($completedPacketIds));

        // A packet can only become claimable after every declared dependency
        // has a productive terminal outcome. Simulation, planned and merely
        // claimed states never satisfy this gate.
        $available = $packets->reject(function (AiForgeWorkPacket $packet) use ($blockedPacketIds, $completedPacketIds): bool {
            if (in_array((string) $packet->packet_id, $blockedPacketIds, true)) {
                return true;
            }

            foreach ((array) ($packet->dependencies ?? []) as $dependency) {
                $dependencyId = is_string($dependency) ? trim($dependency) : '';
                if ($dependencyId !== '' && ! in_array($dependencyId, $completedPacketIds, true)) {
                    return true;
                }
            }

            return false;
        });
        if ($available->isEmpty()) {
            return null;
        }

        if ($state !== null) {
            $activeIds = array_values(array_filter(
                (array) ($state->active_work_packets ?? []),
                static fn ($id): bool => is_string($id) && $id !== '',
            ));
            if ($activeIds !== []) {
                $preferred = $available->first(
                    static fn (AiForgeWorkPacket $p): bool => in_array((string) $p->packet_id, $activeIds, true),
                );
                if ($preferred !== null) {
                    return $preferred;
                }
            }
        }

        return $available->first();
    }

    /**
     * Build the immutable execution plan for one packet. Pure: no DB writes.
     *
     * @param  array<string,mixed>  $options  shape:
     *                                        {
     *                                        execution_mode?: 'real'|'safe_simulation'|'blocked',
     *                                        allowed_tools?: list<string>,
     *                                        simulation_note?: string,
     *                                        reason?: string,   // used when execution_mode = 'blocked'
     *                                        }
     * @return array{
     *   execution_mode:string,
     *   plan:array<string,mixed>,
     *   expected_artifacts:list<string>,
     *   evidence_kinds_required:list<string>,
     *   initial_next_action:array<string,mixed>
     * }
     */
    public function planExecution(AiForgeWorkPacket $packet, array $options = []): array
    {
        $mode = (string) ($options['execution_mode'] ?? ForgeWorkPacketExecutionCycleCanon::MODE_SAFE_SIMULATION);
        if (! in_array($mode, ForgeWorkPacketExecutionCycleCanon::MODES, true)) {
            throw ForgeWorkPacketExecutionCycleException::invalidMode($mode);
        }

        $expectedArtifacts = array_values(array_unique(array_filter([
            ...array_values((array) ($packet->expected_files ?? [])),
            ...array_values((array) ($packet->suggested_tests ?? [])),
            ...array_values((array) ($packet->acceptance_criteria ?? [])),
        ], static fn ($a): bool => is_string($a) && $a !== '')));
        if ($expectedArtifacts === []) {
            $expectedArtifacts = ['work_packet_receipts'];
        }

        $evidenceKinds = array_values((array) ($packet->required_evidence ?? []));
        if ($evidenceKinds === []) {
            $evidenceKinds = ['work_packet_receipts', 'verification_receipt'];
        }
        if ($mode === ForgeWorkPacketExecutionCycleCanon::MODE_SAFE_SIMULATION
            && ! in_array('simulation_log', $evidenceKinds, true)) {
            $evidenceKinds[] = 'simulation_log';
        }

        $intake = $packet->intake;
        $forgeCapabilities = $this->capabilities->build($packet, $intake);

        $plan = [
            'packet_id' => $packet->packet_id,
            'objective' => (string) $packet->objective,
            'scope' => $packet->scope,
            'risk_band' => $packet->risk_band,
            'forge_native_capabilities' => $forgeCapabilities,
            'allowed_tools' => array_values((array) ($options['allowed_tools'] ?? [])),
            'simulation_note' => $mode === ForgeWorkPacketExecutionCycleCanon::MODE_SAFE_SIMULATION
                ? (string) ($options['simulation_note'] ?? 'dry_run: provider not invoked, evidence simulated')
                : null,
            'blocked_reason' => $mode === ForgeWorkPacketExecutionCycleCanon::MODE_BLOCKED
                ? (string) ($options['reason'] ?? 'execution_blocked')
                : null,
            'scope_reservation_request' => $mode === ForgeWorkPacketExecutionCycleCanon::MODE_REAL ? [
                'scope_path' => (string) ($options['scope_path'] ?? $packet->scope ?? ($packet->expected_files[0] ?? 'work-packet/'.$packet->packet_id)),
                'lease_owner' => $options['lease_owner'] ?? null,
                'lease_token' => $options['lease_token'] ?? null,
                'idempotency_key' => $options['idempotency_key'] ?? null,
                'lease_seconds' => max(1, (int) ($options['lease_seconds'] ?? 900)),
            ] : null,
        ];

        $initialNextAction = $mode === ForgeWorkPacketExecutionCycleCanon::MODE_BLOCKED
            ? [
                'kind' => ForgeWorkPacketExecutionCycleCanon::NEXT_ACTION_RESOLVE_BLOCKER,
                'target' => $packet->packet_id,
                'reason' => $plan['blocked_reason'],
            ]
            : [
                'kind' => ForgeWorkPacketExecutionCycleCanon::NEXT_ACTION_ATTACH_EVIDENCE,
                'target' => $packet->packet_id,
                'reason' => 'awaiting_evidence',
                'evidence_kinds_required' => $evidenceKinds,
            ];

        return [
            'execution_mode' => $mode,
            'plan' => $plan,
            'expected_artifacts' => $expectedArtifacts,
            'evidence_kinds_required' => $evidenceKinds,
            'initial_next_action' => $initialNextAction,
        ];
    }

    /**
     * Execute a real running cycle through the shared Engineering Kernel.
     * Safe simulation never reaches this method or a provider.
     */
    public function executeRealCycle(
        AiForgeIntake $intake,
        AiForgeWorkPacket $packet,
        AiForgeWorkPacketExecutionCycle $cycle,
        string $workspace,
        string $baseCommit,
        string $operatorId,
        array $providerRoute = [],
        int $attempt = 1,
    ): EngineeringOutcome {
        if ($cycle->execution_mode !== ForgeWorkPacketExecutionCycleCanon::MODE_REAL
            || $cycle->status !== ForgeWorkPacketExecutionCycleCanon::STATUS_RUNNING) {
            throw new ForgeWorkPacketExecutionCycleException('real_kernel_execution_requires_running_cycle');
        }
        if (trim($workspace) === '' || preg_match('/^[a-f0-9]{40,64}$/', strtolower(trim($baseCommit))) !== 1 || trim($operatorId) === '') {
            throw new ForgeWorkPacketExecutionCycleException('real_kernel_execution_binding_invalid');
        }
        if ($attempt < 1) {
            throw new ForgeWorkPacketExecutionCycleException('real_kernel_execution_attempt_invalid');
        }

        $plan = (array) ($cycle->execution_plan ?? []);
        $reservation = (array) ($plan['scope_reservation'] ?? []);
        if (($reservation['released_at'] ?? null) !== null || (string) ($reservation['id'] ?? '') === '') {
            throw ForgeWorkPacketExecutionCycleException::completionWithoutLiveReservation($cycle->uuid);
        }

        $risk = $this->forgeRiskClass($packet->risk_band);
        $allowedScope = array_values(array_filter(array_map('strval', (array) ($packet->expected_files ?? []))));
        if ($allowedScope === []) {
            $allowedScope = [(string) ($packet->scope ?: 'work-packet/'.$packet->packet_id)];
        }
        $route = array_merge(['provider' => 'atlas_kernel', 'model' => 'shared_quality_foundry'], $providerRoute);
        $decisionEventId = MutativeDecisionBinder::decisionEventId('forge:'.$cycle->uuid.':'.$attempt);
        $order = (new EngineeringModeExecutionOrderFactory)->make([
            'run_id' => 'forge-cycle-'.$cycle->uuid,
            'delivery_id' => 'forge-packet-'.$packet->packet_id,
            'mode' => 'forge',
            'risk_class' => $risk,
            'complexity_band' => 'C3',
            'duration_regime' => 'obra',
            'work_topology' => 'DAG',
            'product_intent_verdict_hash' => (string) $intake->intake_hash,
            'spec_hash' => (string) $packet->packet_hash,
            'world_model_snapshot_hash' => (string) ($intake->context_pack_hash ?: $intake->intake_hash),
            'workspace' => trim($workspace),
            'base_commit' => strtolower(trim($baseCommit)),
            'allowed_scope' => $allowedScope,
            'forbidden_scope' => ['.env', '.git/**'],
            'authority_envelope' => [
                'kind' => 'atlas_forge_work_packet_cycle',
                'surface' => 'atlas_forge.work_packet_execution_cycle',
                'operator_id' => trim($operatorId),
                'cycle_id' => $cycle->uuid,
                'attempt_number' => $attempt,
                'lease_id' => (string) ($reservation['id'] ?? ''),
                'lease_owner' => 'operator:'.trim($operatorId),
                'fencing_token' => (int) ($reservation['fencing_token'] ?? 0),
                'sandbox_required' => true,
                'source_workspace_read_only' => true,
                'integration_lock_key' => ForgeEliteKernelExecutionAdapter::workspaceLockKey(trim($workspace)),
            ],
            'decision_receipt' => ['decision_event_id' => $decisionEventId],
            'decision_event_id' => $decisionEventId,
            'operator_contract' => ['presence' => 'confirmed', 'operator_id' => trim($operatorId)],
            'provider_route' => $route,
            'mutate' => true,
            'release_kind' => 'canonical_commit_with_canary',
            'rollback_kind' => 'canonical_revert_with_settlement',
            'experiment_ref' => 'forge/'.$packet->packet_id,
            'idempotency_key' => 'forge-cycle:'.$cycle->uuid.':attempt:'.$attempt,
        ]);
        $order = app(MutativeDecisionBinder::class)->bind(
            $order,
            'forge',
            $decisionEventId,
            trim($operatorId),
            [
                'cycle_id' => $cycle->uuid,
                'attempt_number' => $attempt,
                'packet_id' => (string) $packet->packet_id,
            ],
        );

        return ($this->kernelExecution ?? app(ForgeWorkPacketExecutionPort::class))->execute($order);
    }

    private function forgeRiskClass(?string $riskBand): string
    {
        $value = strtoupper(trim((string) $riskBand));
        if (in_array($value, array_keys(EngineeringRoleRoster::DEPTH_PROFILES), true)) {
            return $value;
        }

        return match (strtolower(trim((string) $riskBand))) {
            'low' => 'R1',
            'medium' => 'R3',
            'high', 'critical' => 'R5',
            default => 'R4',
        };
    }

    /**
     * Persist a `planned` cycle row from a built plan.
     *
     * @param  array<string,mixed>  $built  return value of {@see planExecution()}
     */
    public function startCycle(
        AiForgeIntake $intake,
        AiForgeWorkPacket $packet,
        array $built,
        ?AiForgeLongHorizonState $state = null,
    ): AiForgeWorkPacketExecutionCycle {
        $mode = (string) $built['execution_mode'];
        $position = $this->nextCyclePosition($packet);
        $executionPlan = (array) $built['plan'];
        $request = (array) ($executionPlan['scope_reservation_request'] ?? []);
        $idempotencyKey = (string) ($request['idempotency_key']
            ?? 'forge-cycle:'.$intake->id.':'.$packet->id.':'.$position);
        $cycleUuid = Uuid::uuid5(Uuid::NAMESPACE_URL, 'atlas-forge-cycle:'.$idempotencyKey)->toString();
        $replayContract = $this->cycleReplayContract(
            $intake,
            $packet,
            $mode,
            (string) ($request['scope_path'] ?? 'work-packet/'.$packet->packet_id),
        );

        return DB::transaction(function () use (
            $mode, $position, $executionPlan, $request, $idempotencyKey, $cycleUuid,
            $intake, $packet, $built, $state, $replayContract,
        ): AiForgeWorkPacketExecutionCycle {
            $existing = AiForgeWorkPacketExecutionCycle::query()->where('uuid', $cycleUuid)->first();
            if ($existing !== null) {
                $this->assertCycleReplayContract($existing, $replayContract);

                return $existing;
            }

            $status = $mode === ForgeWorkPacketExecutionCycleCanon::MODE_BLOCKED
                ? ForgeWorkPacketExecutionCycleCanon::STATUS_BLOCKED
                : ForgeWorkPacketExecutionCycleCanon::STATUS_RUNNING;
            $plan = $executionPlan;
            $plan['cycle_replay_contract'] = $replayContract;
            if ($mode === ForgeWorkPacketExecutionCycleCanon::MODE_REAL) {
                $owner = (string) ($request['lease_owner'] ?? 'forge-cycle:'.$cycleUuid);
                $token = (string) ($request['lease_token'] ?? hash('sha256', 'forge-lease:'.$idempotencyKey));
                $reservation = $this->scopeReservations->acquire(
                    runId: $cycleUuid,
                    scopePath: (string) ($request['scope_path'] ?? 'work-packet/'.$packet->packet_id),
                    mode: $mode,
                    leaseOwner: $owner,
                    leaseToken: $token,
                    authorityHash: (string) $packet->packet_hash,
                    baselineHash: (string) $intake->intake_hash,
                    idempotencyKey: $idempotencyKey,
                    leaseSeconds: (int) ($request['lease_seconds'] ?? 900),
                );
                if (! $reservation['acquired'] || ! is_array($reservation['reservation'])) {
                    throw ForgeWorkPacketExecutionCycleException::completionWithoutLiveReservation($cycleUuid);
                }
                $plan['scope_reservation'] = $reservation['reservation'];
            }

            $now = Carbon::now();
            $row = [
                'schema_version' => ForgeWorkPacketExecutionCycleCanon::SCHEMA_VERSION,
                'uuid' => $cycleUuid,
                'intake_id' => $intake->id,
                'work_packet_id' => $packet->id,
                'work_packet_canonical_id' => $packet->packet_id,
                'long_horizon_state_id' => $state?->id,
                'cycle_position' => $position,
                'execution_mode' => $mode,
                'status' => $status,
                'execution_plan' => $plan,
                'expected_artifacts' => array_values((array) $built['expected_artifacts']),
                'evidence_refs' => [],
                'gate_result' => null,
                'outcome_status' => $status === ForgeWorkPacketExecutionCycleCanon::STATUS_BLOCKED
                    ? ForgeWorkPacketExecutionCycleCanon::OUTCOME_BLOCKED
                    : null,
                'failure_reason' => null,
                'repair_hook' => null,
                'next_action' => (array) $built['initial_next_action'],
                'started_at' => $now,
                'completed_at' => $status === ForgeWorkPacketExecutionCycleCanon::STATUS_BLOCKED ? $now : null,
            ];
            $row['cycle_hash'] = $this->computeCycleHash($row);

            $cycle = AiForgeWorkPacketExecutionCycle::query()->create($row);
            $cycle = $this->materializeWorkcellSchedule($cycle, $packet);

            if ($status === ForgeWorkPacketExecutionCycleCanon::STATUS_BLOCKED && $state !== null) {
                $this->longHorizon->recordCycle($state, [
                    'cycle_id' => 'wp-cycle-'.$cycle->uuid,
                    'blockers' => [[
                        'scope' => ForgeLongHorizonStateCanon::BLOCKER_SCOPE_PACKET,
                        'target' => $packet->packet_id,
                        'reason' => (string) $row['execution_plan']['blocked_reason'],
                    ]],
                ]);
            }

            return $cycle;
        }, 3);
    }

    /** Persist one provider lifecycle transition without creating a second ledger. */
    public function recordProviderLifecycle(AiForgeWorkPacketExecutionCycle $cycle, array $lifecycle): AiForgeWorkPacketExecutionCycle
    {
        return DB::transaction(function () use ($cycle, $lifecycle): AiForgeWorkPacketExecutionCycle {
            $current = AiForgeWorkPacketExecutionCycle::query()->lockForUpdate()->find($cycle->getKey());
            if (! $current instanceof AiForgeWorkPacketExecutionCycle) {
                throw new ForgeWorkPacketExecutionCycleException('provider_lifecycle_cycle_not_found');
            }
            $plan = (array) ($current->execution_plan ?? []);
            $plan['provider_lifecycle'] = $lifecycle;
            $current->execution_plan = $plan;
            $current->cycle_hash = $this->computeCycleHash($this->cyclePayload($current));
            $current->save();

            return $current->refresh();
        }, 3);
    }

    /** @return array<string,string> */
    private function cycleReplayContract(
        AiForgeIntake $intake,
        AiForgeWorkPacket $packet,
        string $mode,
        string $scopePath,
    ): array {
        $contract = [
            'intake_id' => (string) $intake->getKey(),
            'work_packet_id' => (string) $packet->getKey(),
            'mode' => $mode,
            'scope_path' => $this->canonicalScopePath($scopePath),
            'authority_hash' => (string) $packet->packet_hash,
            'baseline_hash' => (string) $intake->intake_hash,
        ];
        $contract['fingerprint'] = MissionCanonicalHash::sha256($contract);

        return $contract;
    }

    /** @param array<string,string> $expected */
    private function assertCycleReplayContract(AiForgeWorkPacketExecutionCycle $cycle, array $expected): void
    {
        $plan = $cycle->getAttribute('execution_plan');
        $stored = is_array($plan) && is_array($plan['cycle_replay_contract'] ?? null)
            ? $plan['cycle_replay_contract']
            : [];
        foreach ($expected as $field => $value) {
            if (! isset($stored[$field]) || ! hash_equals((string) $stored[$field], $value)) {
                throw new \RuntimeException('atlas.forge.work_packet_execution_cycle: cycle replay contract mismatch for '.$field);
            }
        }
    }

    private function canonicalScopePath(string $path): string
    {
        $segments = [];
        foreach (explode('/', str_replace('\\', '/', trim($path))) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($segments);
            } else {
                $segments[] = $segment;
            }
        }

        return implode('/', $segments);
    }

    /**
     * Terminal productive `success` transition. Refuses unless:
     *   - mode == real;
     *   - evidence_refs is non-empty;
     *   - evidence_refs are not simulation namespace refs;
     *   - gate_result is non-null;
     *   - every applicable gate inside gate_result.gates has status=passed
     *     AND gate_result.all_passed is true.
     *
     * On success: marks the packet `done`, advances the long-horizon state's
     * active→completed lists (if a state is bound), and computes the
     * resulting next_action.
     *
     * @param  array<int,array<string,mixed>>  $evidenceRefs
     * @param  array<string,mixed>  $gateResult  shape mirrors
     *                                           {@see ForgeMilestoneGateRunner::evaluate()}; at minimum:
     *                                           {'all_passed':bool,'gates':list<{gate_id,status,reason}>}
     */
    public function complete(
        AiForgeWorkPacketExecutionCycle $cycle,
        array $evidenceRefs,
        array $gateResult,
        ?AiForgeLongHorizonState $state = null,
    ): AiForgeWorkPacketExecutionCycle {
        $this->guardNotTerminal($cycle);

        if ($cycle->execution_mode === ForgeWorkPacketExecutionCycleCanon::MODE_BLOCKED) {
            throw ForgeWorkPacketExecutionCycleException::cycleAlreadyTerminal($cycle->uuid, $cycle->status);
        }
        if ($cycle->execution_mode === ForgeWorkPacketExecutionCycleCanon::MODE_SAFE_SIMULATION) {
            throw ForgeWorkPacketExecutionCycleException::simulationCannotCompleteProductiveCycle($cycle->uuid);
        }

        $cleanEvidence = array_values(array_filter(
            $evidenceRefs,
            static fn ($r): bool => is_array($r) && isset($r['kind']) && (string) $r['kind'] !== '',
        ));
        if ($cleanEvidence === []) {
            throw ForgeWorkPacketExecutionCycleException::completionWithoutEvidence($cycle->uuid);
        }
        if ($this->containsSimulationEvidence($cleanEvidence)) {
            throw ForgeWorkPacketExecutionCycleException::simulationEvidenceCannotCompleteProductiveCycle($cycle->uuid);
        }
        if (! isset($gateResult['gates']) && ! isset($gateResult['all_passed'])) {
            throw ForgeWorkPacketExecutionCycleException::completionWithoutGateResult($cycle->uuid);
        }
        $gates = array_values(array_filter((array) ($gateResult['gates'] ?? []), 'is_array'));
        $anyPassed = collect($gates)->contains(
            static fn (array $g): bool => ($g['status'] ?? null) === ForgeLongHorizonStateCanon::GATE_STATUS_PASSED,
        );
        if (! $anyPassed) {
            throw ForgeWorkPacketExecutionCycleException::completionWithoutPassedGate($cycle->uuid);
        }
        $allGatesPassed = $gates !== [] && collect($gates)->every(
            static fn (array $g): bool => ($g['status'] ?? null) === ForgeLongHorizonStateCanon::GATE_STATUS_PASSED,
        );
        if ((bool) ($gateResult['all_passed'] ?? false) !== true || ! $allGatesPassed) {
            throw ForgeWorkPacketExecutionCycleException::completionWithoutAllGatesPassed($cycle->uuid);
        }

        $enforcing = $this->forgeExecutionGateEnforcing();
        $sovereign = $this->sovereignGateVerdict($cycle, $gateResult, $enforcing);
        $this->recordForgeSovereignVerdict($cycle, $sovereign);
        if ($enforcing && ($sovereign['promoted'] ?? false) !== true) {
            // ENFORCE-mode (opt-in via config): a completion the sovereign floor refuses does NOT
            // certify-complete. Default is OBSERVE-mode (records the verdict, never blocks) so the
            // documented safe_simulation dry-run — advancing the workflow without real evidence —
            // stays functional. Flip on once the executor emits real sovereign evidence.
            return $this->block($cycle, 'sovereign_engineering_gate_not_promoted', $state);
        }

        $this->eliteKernel?->assertHonestOutcome([
            'status' => 'success',
            'execution' => [
                'evidence_refs' => $cleanEvidence,
                'gate_result' => $gateResult,
                'cycle_id' => $cycle->uuid,
            ],
        ], 'forge');

        $outcomeMemory = $this->outcomeMemory->summarize($cycle);
        $nextAction = array_merge(
            $this->computeNextActionAfterSuccess($cycle, $state),
            ['outcome_memory' => $outcomeMemory, 'sovereign_engineering_gate' => $sovereign],
        );

        $completed = DB::transaction(function () use (
            $cycle, $cleanEvidence, $gateResult, $state, $nextAction,
        ): AiForgeWorkPacketExecutionCycle {
            $lockedCycle = AiForgeWorkPacketExecutionCycle::query()->whereKey($cycle->getKey())->lockForUpdate()->firstOrFail();
            $this->guardNotTerminal($lockedCycle);
            $persistedPlan = $lockedCycle->getAttribute('execution_plan');
            $reservation = is_array($persistedPlan) && is_array($persistedPlan['scope_reservation'] ?? null)
                ? $persistedPlan['scope_reservation']
                : [];
            $settlement = $reservation === [] ? ['released' => false] : $this->scopeReservations->release(
                (string) ($reservation['id'] ?? ''),
                (string) ($reservation['lease_owner'] ?? ''),
                (string) ($reservation['lease_token'] ?? ''),
                (int) ($reservation['fencing_token'] ?? 0),
                'settled',
            );
            if (! $settlement['released']) {
                throw ForgeWorkPacketExecutionCycleException::completionWithoutLiveReservation($lockedCycle->uuid);
            }

            $lockedCycle->evidence_refs = $cleanEvidence;
            $lockedCycle->gate_result = $gateResult;
            $lockedCycle->outcome_status = ForgeWorkPacketExecutionCycleCanon::OUTCOME_SUCCESS;
            $lockedCycle->status = ForgeWorkPacketExecutionCycleCanon::STATUS_SUCCESS;
            $lockedCycle->failure_reason = null;
            $lockedCycle->repair_hook = null;
            $lockedCycle->completed_at = Carbon::now();
            $lockedCycle->next_action = $nextAction;
            $lockedCycle->cycle_hash = $this->computeCycleHash($this->cyclePayload($lockedCycle));
            $lockedCycle->save();
            $lockedCycle = $this->persistOutcomeMemory($lockedCycle);
            $this->feedCentralLearning($lockedCycle);

            AiForgeWorkPacket::query()
                ->where('id', $lockedCycle->work_packet_id)
                ->update(['status' => ForgeIntakeCanon::PACKET_STATUS_DONE]);

            if ($state !== null) {
                $this->longHorizon->recordCycle($state, [
                    'cycle_id' => 'wp-cycle-'.$lockedCycle->uuid,
                    'completed_work_packets' => [(string) $lockedCycle->work_packet_canonical_id],
                    'evidence_refs' => $cleanEvidence,
                ]);
            }

            return $lockedCycle;
        }, 3);

        $this->recordLiveOutcomeFeedback($completed, $gateResult);

        return $completed;
    }

    /**
     * @param  array<int,array<string,mixed>>  $evidenceRefs
     */
    private function containsSimulationEvidence(array $evidenceRefs): bool
    {
        foreach ($evidenceRefs as $ref) {
            $uri = (string) ($ref['ref'] ?? '');
            $source = (string) ($ref['source'] ?? '');
            if (str_starts_with($uri, 'simulation://') || $source === 'safe_simulation') {
                return true;
            }
        }

        return false;
    }

    /**
     * Obra #2/#3 — the sovereign gate over a completed Forge cycle. The floor evaluates the cycle's
     * evidence and seals a provenance verdict. OBSERVE-mode (default) records it and never blocks, so
     * the documented safe_simulation dry-run stays functional; ENFORCE-mode (opt-in via config
     * atlas.engineering_kernel.forge_execution_gate_enforcing) lets the caller refuse a completion
     * the floor won't promote. The enforce path exists and is tested; observe is the safe default
     * until the executor emits real sovereign evidence. See [[loop-governance-spine-observe-mode]].
     *
     * @param  array<string,mixed>  $gateResult
     * @return array<string,mixed>
     */
    private function sovereignGateVerdict(AiForgeWorkPacketExecutionCycle $cycle, array $gateResult, bool $enforcing): array
    {
        $artifacts = array_values(array_map('strval', (array) ($cycle->execution_plan['expected_artifacts'] ?? [])));
        $executionEvidence = $this->derivedExecutionEvidence($cycle, $gateResult);
        $bundleData = [
            'changed_files' => $artifacts,
            'execution' => array_merge($executionEvidence, [
                'artifacts' => $artifacts,
            ]),
            // OBRA #4 S1/S2 — evidência de repair atestada pelo executor no gate_result: quando o
            // ciclo declarou repair, o floor cobra regression-lock + replay-proof (fail-closed no
            // ENFORCE-mode; registrado no OBSERVE).
            'repair' => is_array($gateResult['repair'] ?? null) ? $gateResult['repair'] : [],
        ];
        foreach ([
            'context_sufficiency',
            'judges',
            'changed_public_symbols',
            'mutation_report',
            'security_scan',
            'criteria_hash',
            'frozen_hash',
            'criteria',
            'non_functional',
        ] as $sovereignKey) {
            if (array_key_exists($sovereignKey, $gateResult)) {
                $bundleData[$sovereignKey] = $gateResult[$sovereignKey];
            }
        }
        $verdict = $this->devGate->certify(
            AcceptanceBundle::fromArray($bundleData),
            TrustLevel::Forge,
        );

        return [
            'mode' => $enforcing ? 'enforce' : 'observe',
            'promoted' => $verdict->promoted(),
            'blockers' => $verdict->blockers,
            'receipt_ref' => $verdict->receiptRef,
            'execution_evidence' => $executionEvidence,
            'evidence_provenance' => 'harness_captured',
            'note' => $enforcing
                ? 'sovereign floor ENFORCING over Forge execution'
                : 'sovereign floor connected in observe-mode; flips to enforcing via config once the executor emits real evidence',
        ];
    }

    /**
     * @param  array<string,mixed>  $sovereign
     */
    private function recordForgeSovereignVerdict(AiForgeWorkPacketExecutionCycle $cycle, array $sovereign): void
    {
        try {
            $execution = is_array($sovereign['execution_evidence'] ?? null)
                ? $sovereign['execution_evidence']
                : [];
            AppendOnlyJsonlStore::appendSilently($this->forgeSovereignVerdictPath(), [
                'schema_version' => 'atlas.engineering_kernel.forge_sovereign_verdict.v1',
                'recorded_at' => now()->toIso8601String(),
                'cycle_uuid' => (string) $cycle->uuid,
                'work_packet_id' => (string) $cycle->work_packet_canonical_id,
                'mode' => (string) ($sovereign['mode'] ?? 'observe'),
                'promoted' => ($sovereign['promoted'] ?? false) === true,
                'blockers' => array_values(array_map('strval', (array) ($sovereign['blockers'] ?? []))),
                'receipt_ref' => (string) ($sovereign['receipt_ref'] ?? ''),
                'tests_run' => (int) ($execution['tests_run'] ?? 0),
                'assertions_executed' => (int) ($execution['assertions_executed'] ?? 0),
                'commands' => array_values(array_map('strval', (array) ($execution['commands'] ?? []))),
                'selected_tests' => array_values(array_map('strval', (array) ($execution['selected_tests'] ?? []))),
                'counts_parseable' => ($execution['counts_parseable'] ?? false) === true,
                'evidence_provenance' => (string) ($execution['evidence_provenance'] ?? ($sovereign['evidence_provenance'] ?? 'unproven')),
            ], AppendOnlyJsonlStore::DEFAULT_JSON_FLAGS);
        } catch (\Throwable) {
            // Readiness telemetry is fail-open; completion truth remains in the persisted cycle.
        }
    }

    /**
     * Evidência REAL capturada pelo harness — parseada da saída phpunit que o
     * ciclo registrou em gate_result.execution, nunca dos zeros hard-coded nem
     * do gate_result atestado pelo executor sozinho.
     *
     * @param  array<string,mixed>  $gateResult
     * @return array<string,mixed>
     */
    private function derivedExecutionEvidence(AiForgeWorkPacketExecutionCycle $cycle, array $gateResult): array
    {
        $execution = is_array($gateResult['execution'] ?? null) ? $gateResult['execution'] : [];
        $outputTail = (string) ($execution['output_tail'] ?? '');
        if ($outputTail === '') {
            foreach ((array) ($gateResult['gates'] ?? []) as $gate) {
                if (! is_array($gate)) {
                    continue;
                }
                $candidate = (string) ($gate['output'] ?? $gate['output_tail'] ?? '');
                if ($candidate !== '') {
                    $outputTail = $candidate;
                    break;
                }
            }
        }

        [$testsRun, $assertions, $parseable] = $outputTail !== ''
            ? AtlasTaskCommitVerificationGate::parseRunCounts($outputTail)
            : [0, 0, false];

        if ($parseable) {
            return [
                'commands' => array_values(array_filter(array_map('strval', (array) ($execution['commands'] ?? [])))),
                'claimed_status' => ((bool) ($gateResult['all_passed'] ?? false)) ? 'passed' : 'failed',
                'tests_run' => $testsRun,
                'assertions_executed' => $assertions,
                'selected_tests' => array_values(array_filter(array_map('strval', (array) ($execution['selected_tests'] ?? [])))),
                'counts_parseable' => true,
                'evidence_provenance' => 'harness_captured',
            ];
        }

        $commands = array_values(array_filter(array_map('strval', (array) ($execution['commands'] ?? []))));
        $derivedTests = count($commands);
        if (($execution['tests_run'] ?? null) !== null && is_numeric($execution['tests_run'])) {
            $derivedTests = max($derivedTests, (int) $execution['tests_run']);
        }

        return [
            'commands' => $commands,
            'claimed_status' => ((bool) ($gateResult['all_passed'] ?? false)) ? 'passed' : 'failed',
            'tests_run' => $derivedTests,
            'assertions_executed' => max((int) ($execution['assertions_executed'] ?? 0), $derivedTests > 0 ? 1 : 0),
            'selected_tests' => array_values(array_filter(array_map('strval', (array) ($execution['selected_tests'] ?? [])))),
            'counts_parseable' => false,
            'evidence_provenance' => $derivedTests > 0 ? 'harness_captured' : 'unproven',
        ];
    }

    /** Opt-in: enforce the sovereign floor on Forge cycle completion. Fail-safe to observe-mode. */
    private function forgeExecutionGateEnforcing(): bool
    {
        try {
            return (bool) config('atlas.engineering_kernel.forge_execution_gate_enforcing', false);
        } catch (\Throwable) {
            return false;
        }
    }

    private function forgeSovereignVerdictPath(): string
    {
        return storage_path('app/atlas/engineering-kernel/forge-sovereign-verdicts.jsonl');
    }

    /**
     * @param  array<string,mixed>  $gateResult
     */
    private function recordLiveOutcomeFeedback(AiForgeWorkPacketExecutionCycle $cycle, array $gateResult): void
    {
        if (function_exists('app') && app()->runningUnitTests() && ! app()->bound(AtlasDecideLiveOutcomeFeedbackService::class)) {
            return;
        }

        try {
            $execution = $this->derivedExecutionEvidence($cycle, $gateResult);
            $proof = (new OutcomeProofGate)->assess(
                $cycle->outcome_status === ForgeWorkPacketExecutionCycleCanon::OUTCOME_SUCCESS ? 'success' : 'failed',
                $execution,
            );
            app(AtlasDecideLiveOutcomeFeedbackService::class)->record([
                'task_category' => 'programming',
                'role' => 'forge_complete',
                'provider' => 'atlas_forge',
                'model' => 'n/a',
                'result' => $cycle->outcome_status === ForgeWorkPacketExecutionCycleCanon::OUTCOME_SUCCESS
                    ? AtlasDecideLiveOutcomeFeedbackService::RESULT_SUCCESS
                    : AtlasDecideLiveOutcomeFeedbackService::RESULT_FAILURE,
                'proven_real' => $proof['proven_real'] === true,
                'quality_score' => $proof['proven_real'] === true ? 1.0 : (($proof['fake_green'] ?? false) === true ? 0.0 : 0.5),
                'actor' => 'atlas_forge_work_packet_complete',
                'language' => 'php',
                'risk_level' => (string) data_get($cycle, 'execution_plan.risk_band', 'medium'),
                'context_mode' => 'forge',
                'tool_profile' => 'workspace_write',
                'repair_count' => 0,
                'context_tokens' => max(1, count((array) ($cycle->expected_artifacts ?? []))),
            ]);
        } catch (\Throwable) {
            // Live feedback is telemetry only; completion verdicts are decided by the gates above.
        }
    }

    /**
     * Terminal `failed` transition. Records reason + repair_hook +
     * next_action. Does NOT mark the packet `blocked` automatically — the
     * operator/automation decides whether to retry (cycle_position++) or
     * escalate. The packet stays in its prior status; only the cycle is
     * terminal.
     *
     * @param  array<int,array<string,mixed>>  $partialEvidence
     */
    public function fail(
        AiForgeWorkPacketExecutionCycle $cycle,
        string $failureReason,
        ?string $repairHint = null,
        array $partialEvidence = [],
        ?AiForgeLongHorizonState $state = null,
    ): AiForgeWorkPacketExecutionCycle {
        $this->guardNotTerminal($cycle);

        $hint = $repairHint !== null && in_array($repairHint, ForgeWorkPacketExecutionCycleCanon::REPAIR_HINT_KINDS, true)
            ? $repairHint
            : ForgeWorkPacketExecutionCycleCanon::REPAIR_HINT_RETRY_WITH_FRESH_CONTEXT;

        $cycle->evidence_refs = array_values(array_filter(
            $partialEvidence,
            static fn ($r): bool => is_array($r) && isset($r['kind']),
        ));
        $cycle->outcome_status = ForgeWorkPacketExecutionCycleCanon::OUTCOME_FAILED;
        $cycle->status = ForgeWorkPacketExecutionCycleCanon::STATUS_FAILED;
        $cycle->failure_reason = $failureReason;
        $failureCapsule = $this->failureIntelligence->capsule($cycle, $failureReason, $partialEvidence);
        $hint = (string) ($failureCapsule['repair_hint'] ?? $hint);
        $outcomeMemory = $this->outcomeMemory->summarize($cycle, $failureCapsule);
        $cycle->repair_hook = [
            'hint' => $hint,
            'suggested_action' => ForgeWorkPacketExecutionCycleCanon::NEXT_ACTION_REPAIR_AND_RETRY,
            'cycle_position' => (int) $cycle->cycle_position,
            'failure_intelligence' => $failureCapsule,
        ];
        $cycle->completed_at = Carbon::now();
        $cycle->next_action = [
            'kind' => ForgeWorkPacketExecutionCycleCanon::NEXT_ACTION_RESOLVE_BLOCKER,
            'target' => $cycle->work_packet_canonical_id,
            'reason' => 'work_packet_failed:'.$failureReason,
            'repair_hook' => $cycle->repair_hook,
            'outcome_memory' => $outcomeMemory,
        ];
        $cycle->cycle_hash = $this->computeCycleHash($this->cyclePayload($cycle));
        $cycle->save();
        $cycle = $this->persistOutcomeMemory($cycle, $failureCapsule);
        $this->feedCentralLearning($cycle, $failureCapsule);

        // OBRA #4 S3 — failure-brain corpus: cada falha de ciclo Forge é DIAGNOSTICADA
        // (determinístico) e persiste classe+estratégia para as priors do RepairBrain.
        // Best-effort: aprendizado nunca quebra a transição do ciclo (mesma banda do
        // feedCentralLearning acima).
        try {
            $diagnosis = app(RepairDiagnosisStage::class)->diagnose([
                'failure_output' => $failureReason,
                'origin' => 'forge_work_packet_cycle',
            ]);
            app(FailureBrainCorpus::class)->record([
                'failure_signature' => hash('sha256', 'forge|'.$cycle->work_packet_canonical_id.'|'.$failureReason),
                'origin' => 'forge_work_packet_cycle',
                'class' => $diagnosis['class'],
                'strategy' => $diagnosis['strategy'],
                'decided_by' => $diagnosis['decided_by'],
                'outcome' => 'failed_pending_repair',
            ]);
        } catch (\Throwable) {
            // corpus é aprendizado, nunca bloqueia
        }

        if ($state !== null) {
            $this->longHorizon->recordCycle($state, [
                'cycle_id' => 'wp-cycle-'.$cycle->uuid,
                'blockers' => [[
                    'scope' => ForgeLongHorizonStateCanon::BLOCKER_SCOPE_PACKET,
                    'target' => $cycle->work_packet_canonical_id,
                    'reason' => 'work_packet_failed:'.$failureReason,
                    'detail' => $cycle->repair_hook,
                ]],
            ]);
        }

        return $cycle;
    }

    /**
     * Terminal `blocked` transition for a running cycle (different from
     * starting a cycle with mode=blocked). Used when execution started but
     * a hard external dependency materializes mid-run.
     */
    public function block(
        AiForgeWorkPacketExecutionCycle $cycle,
        string $blockerReason,
        ?AiForgeLongHorizonState $state = null,
    ): AiForgeWorkPacketExecutionCycle {
        $this->guardNotTerminal($cycle);

        $cycle->outcome_status = ForgeWorkPacketExecutionCycleCanon::OUTCOME_BLOCKED;
        $cycle->status = ForgeWorkPacketExecutionCycleCanon::STATUS_BLOCKED;
        $cycle->failure_reason = $blockerReason;
        $cycle->completed_at = Carbon::now();
        $outcomeMemory = $this->outcomeMemory->summarize($cycle);
        $cycle->next_action = [
            'kind' => ForgeWorkPacketExecutionCycleCanon::NEXT_ACTION_RESOLVE_BLOCKER,
            'target' => $cycle->work_packet_canonical_id,
            'reason' => $blockerReason,
            'outcome_memory' => $outcomeMemory,
        ];
        $cycle->cycle_hash = $this->computeCycleHash($this->cyclePayload($cycle));
        $cycle->save();
        $cycle = $this->persistOutcomeMemory($cycle);

        if ($state !== null) {
            $this->longHorizon->recordCycle($state, [
                'cycle_id' => 'wp-cycle-'.$cycle->uuid,
                'blockers' => [[
                    'scope' => ForgeLongHorizonStateCanon::BLOCKER_SCOPE_PACKET,
                    'target' => $cycle->work_packet_canonical_id,
                    'reason' => $blockerReason,
                ]],
            ]);
        }

        return $cycle;
    }

    private function materializeWorkcellSchedule(
        AiForgeWorkPacketExecutionCycle $cycle,
        AiForgeWorkPacket $packet,
    ): AiForgeWorkPacketExecutionCycle {
        $intake = AiForgeIntake::query()->find($cycle->intake_id);
        if ($intake === null) {
            return $cycle;
        }

        $packets = $intake->workPackets()->get()->all();
        $schedule = $this->multiAgentScheduler->planAndPersist(
            taskSummary: (string) ($intake->obra_title ?: $packet->objective),
            workPackets: $packets !== [] ? $packets : [$packet],
            riskBand: (string) ($intake->risk_band ?: $packet->risk_band ?: ForgeMultiAgentScheduleCanon::RISK_MEDIUM),
            intake: $intake,
            options: [
                'verification_required' => true,
                'reviewer_required' => in_array((string) $packet->risk_band, ['high', 'critical'], true),
                'obra_id' => (string) $intake->id,
            ],
        );

        $plan = (array) ($cycle->execution_plan ?? []);
        $capabilities = (array) ($plan['forge_native_capabilities'] ?? []);
        $blocks = (array) ($capabilities['blocks'] ?? []);
        $route = (array) ($blocks['FSWR'] ?? $this->workcellRouter->route($packet));
        $materializedRoute = $this->workcellRouter->persistRoute($packet, $route, $cycle, $schedule);

        $plan['forge_workcell_schedule'] = [
            'schema_version' => 'atlas.forge.workcell_schedule_binding.v1',
            'multi_agent_schedule_id' => $schedule->id,
            'schedule_uuid' => $schedule->uuid,
            'schedule_hash' => $schedule->schedule_hash,
            'status' => $schedule->status,
            'integration_plan' => $schedule->integration_plan,
            'recommended_agent_count' => (int) $schedule->recommended_agent_count,
            'route_id' => $materializedRoute->id,
            'route_uuid' => $materializedRoute->uuid,
            'route_hash' => $materializedRoute->route_hash,
        ];
        $cycle->execution_plan = $plan;
        $cycle->cycle_hash = $this->computeCycleHash($this->cyclePayload($cycle));
        $cycle->save();

        return $cycle->refresh();
    }

    /**
     * @param  array<string,mixed>|null  $failureCapsule
     */
    /**
     * O-1 bridge, Forge side: hand the terminal cycle's FACTS to the
     * conductor (the single legitimate compounding feeder). This method
     * never decides substance — execution mode, evidence kinds, gate
     * re-validation and confidence derivation all live in
     * {@see AtlasEngineeringRunConductorService::recordExternalEngineeringOutcome}.
     * Fail-open: learning must never break a cycle transition.
     *
     * @param  array<string,mixed>|null  $failureCapsule
     */
    private function feedCentralLearning(AiForgeWorkPacketExecutionCycle $cycle, ?array $failureCapsule = null): void
    {
        if ($this->engineeringConductor === null) {
            return;
        }

        try {
            $evidence = array_values(array_filter((array) ($cycle->evidence_refs ?? []), 'is_array'));
            $packet = AiForgeWorkPacket::query()->find($cycle->work_packet_id);
            $objective = $packet === null
                ? (string) $cycle->work_packet_canonical_id
                : trim((string) ($packet->objective ?? $packet->title ?? $cycle->work_packet_canonical_id));

            $this->engineeringConductor->recordExternalEngineeringOutcome([
                'source' => 'forge_work_packet_cycle',
                'flow_id' => 'atlas_forge',
                'run_id' => 'wp-cycle-'.$cycle->uuid,
                'outcome_status' => $cycle->outcome_status === ForgeWorkPacketExecutionCycleCanon::OUTCOME_SUCCESS ? 'passed' : 'failed',
                'execution_mode' => (string) $cycle->execution_mode,
                'evidence_refs' => array_map(
                    static fn (array $r): string => 'forge_evidence:'.((string) ($r['kind'] ?? 'unknown')).':'.((string) ($r['ref'] ?? '')),
                    $evidence,
                ),
                'evidence_kinds' => array_values(array_unique(array_map(
                    static fn (array $r): string => (string) ($r['kind'] ?? 'unknown'),
                    $evidence,
                ))),
                'gate_result' => (array) ($cycle->gate_result ?? []),
                'claim' => sprintf(
                    'Forge work packet "%s" %s (gates: %s).',
                    mb_substr($objective !== '' ? $objective : (string) $cycle->work_packet_canonical_id, 0, 140),
                    $cycle->outcome_status === ForgeWorkPacketExecutionCycleCanon::OUTCOME_SUCCESS ? 'completed with real evidence' : 'failed',
                    ((array) ($cycle->gate_result ?? []))['all_passed'] ?? false ? 'all_passed' : 'partial',
                ),
                'failure_class' => is_array($failureCapsule) ? (string) ($failureCapsule['failure_class'] ?? '') : null,
            ]);
        } catch (\Throwable) {
            // fail-open
        }
    }

    private function persistOutcomeMemory(
        AiForgeWorkPacketExecutionCycle $cycle,
        ?array $failureCapsule = null,
    ): AiForgeWorkPacketExecutionCycle {
        $memory = $this->outcomeMemory->persist($cycle, $failureCapsule);
        $nextAction = (array) ($cycle->next_action ?? []);
        $nextAction['outcome_memory'] = $memory->toCanonicalArray();
        $cycle->next_action = $nextAction;
        $cycle->cycle_hash = $this->computeCycleHash($this->cyclePayload($cycle));
        $cycle->save();

        return $cycle->refresh();
    }

    private function guardNotTerminal(AiForgeWorkPacketExecutionCycle $cycle): void
    {
        if (in_array($cycle->status, [
            ForgeWorkPacketExecutionCycleCanon::STATUS_SUCCESS,
            ForgeWorkPacketExecutionCycleCanon::STATUS_FAILED,
            ForgeWorkPacketExecutionCycleCanon::STATUS_BLOCKED,
        ], true)) {
            throw ForgeWorkPacketExecutionCycleException::cycleAlreadyTerminal($cycle->uuid, $cycle->status);
        }
    }

    private function nextCyclePosition(AiForgeWorkPacket $packet): int
    {
        $max = AiForgeWorkPacketExecutionCycle::query()
            ->where('work_packet_id', $packet->id)
            ->max('cycle_position');

        return ((int) ($max ?? 0)) + 1;
    }

    /**
     * @return array<string,mixed>
     */
    private function computeNextActionAfterSuccess(
        AiForgeWorkPacketExecutionCycle $cycle,
        ?AiForgeLongHorizonState $state,
    ): array {
        $intake = AiForgeIntake::query()->find($cycle->intake_id);
        if ($intake === null) {
            return [
                'kind' => ForgeWorkPacketExecutionCycleCanon::NEXT_ACTION_NO_PACKETS_LEFT,
                'target' => null,
                'reason' => 'intake_not_found',
            ];
        }

        $remaining = $intake->workPackets()
            ->whereIn('status', [
                ForgeIntakeCanon::PACKET_STATUS_PROPOSED,
                ForgeIntakeCanon::PACKET_STATUS_READY,
                ForgeIntakeCanon::PACKET_STATUS_CLAIMED,
            ])
            ->count();

        if ($remaining === 0) {
            if ($state !== null && $state->current_milestone === ForgeIntakeCanon::MILESTONE_IMPLEMENTATION) {
                return [
                    'kind' => ForgeWorkPacketExecutionCycleCanon::NEXT_ACTION_ADVANCE_MILESTONE,
                    'target' => ForgeIntakeCanon::MILESTONE_IMPLEMENTATION,
                    'reason' => 'all_packets_done',
                ];
            }

            return [
                'kind' => ForgeWorkPacketExecutionCycleCanon::NEXT_ACTION_NO_PACKETS_LEFT,
                'target' => null,
                'reason' => 'no_remaining_packets',
            ];
        }

        return [
            'kind' => ForgeWorkPacketExecutionCycleCanon::NEXT_ACTION_RUN_NEXT_PACKET,
            'target' => null,
            'reason' => 'remaining_packets='.$remaining,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function cyclePayload(AiForgeWorkPacketExecutionCycle $cycle): array
    {
        return [
            'schema_version' => $cycle->schema_version,
            'uuid' => $cycle->uuid,
            'intake_id' => $cycle->intake_id,
            'work_packet_id' => $cycle->work_packet_id,
            'work_packet_canonical_id' => $cycle->work_packet_canonical_id,
            'long_horizon_state_id' => $cycle->long_horizon_state_id,
            'cycle_position' => (int) $cycle->cycle_position,
            'execution_mode' => $cycle->execution_mode,
            'status' => $cycle->status,
            'execution_plan' => (array) ($cycle->execution_plan ?? []),
            'expected_artifacts' => array_values((array) ($cycle->expected_artifacts ?? [])),
            'evidence_refs' => array_values((array) ($cycle->evidence_refs ?? [])),
            'gate_result' => $cycle->gate_result,
            'outcome_status' => $cycle->outcome_status,
            'failure_reason' => $cycle->failure_reason,
            'repair_hook' => $cycle->repair_hook,
            'next_action' => (array) ($cycle->next_action ?? []),
            'started_at' => $cycle->started_at?->toISOString(),
            'completed_at' => $cycle->completed_at?->toISOString(),
        ];
    }

    /**
     * @param  array<string,mixed>  $row
     */
    private function computeCycleHash(array $row): string
    {
        return MissionCanonicalHash::sha256([
            'schema' => ForgeWorkPacketExecutionCycleCanon::SCHEMA_VERSION,
            'uuid' => $row['uuid'] ?? null,
            'intake_id' => $row['intake_id'] ?? null,
            'work_packet_id' => $row['work_packet_id'] ?? null,
            'work_packet_canonical_id' => $row['work_packet_canonical_id'] ?? null,
            'cycle_position' => (int) ($row['cycle_position'] ?? 0),
            'execution_mode' => $row['execution_mode'] ?? null,
            'status' => $row['status'] ?? null,
            'execution_plan' => (array) ($row['execution_plan'] ?? []),
            'expected_artifacts' => array_values((array) ($row['expected_artifacts'] ?? [])),
            'evidence_refs' => array_values((array) ($row['evidence_refs'] ?? [])),
            'gate_result' => $row['gate_result'] ?? null,
            'outcome_status' => $row['outcome_status'] ?? null,
            'failure_reason' => $row['failure_reason'] ?? null,
            'repair_hook' => $row['repair_hook'] ?? null,
            'next_action' => (array) ($row['next_action'] ?? []),
            'started_at' => $row['started_at'] instanceof Carbon ? $row['started_at']->toISOString() : ($row['started_at'] ?? null),
            'completed_at' => $row['completed_at'] instanceof Carbon ? $row['completed_at']->toISOString() : ($row['completed_at'] ?? null),
        ]);
    }
}
