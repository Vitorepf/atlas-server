<?php

namespace App\Services\Ai\Programming\Forge;

use App\Models\AiForgeIntake;
use App\Models\AiForgeLongHorizonState;
use App\Models\AiForgeWorkPacket;
use App\Models\AiForgeWorkPacketExecutionCycle;
use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\Programming\Forge\Intelligence\ForgeFailureIntelligenceService;
use App\Services\Ai\Programming\Forge\Intelligence\ForgeOutcomeMemoryService;
use App\Services\Ai\Programming\Forge\Intelligence\ForgeSpecialistWorkcellRouterService;
use App\Services\Ai\Programming\Forge\Intelligence\ForgeWorkPacketCapabilityOrchestrator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

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
 * Out of scope here (explicitly forbidden by brief):
 *  - provider invocation;
 *  - rivals / benchmark;
 *  - sandboxed real execution mechanics.
 *
 * `safe_simulation` mode is the dry-run path: the cycle records that work
 * was simulated and requires the operator to attach an explicit
 * `simulation_log` evidence ref before completing. This lets the long
 * horizon state advance without burning provider tokens — and without
 * pretending a simulation is the same as a real run.
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
    ) {}

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

        $available = $packets->reject(static fn (AiForgeWorkPacket $p): bool => in_array((string) $p->packet_id, $blockedPacketIds, true));
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
        $status = $mode === ForgeWorkPacketExecutionCycleCanon::MODE_BLOCKED
            ? ForgeWorkPacketExecutionCycleCanon::STATUS_BLOCKED
            : ForgeWorkPacketExecutionCycleCanon::STATUS_RUNNING;

        $now = Carbon::now();
        $row = [
            'schema_version' => ForgeWorkPacketExecutionCycleCanon::SCHEMA_VERSION,
            'uuid' => (string) Str::uuid(),
            'intake_id' => $intake->id,
            'work_packet_id' => $packet->id,
            'work_packet_canonical_id' => $packet->packet_id,
            'long_horizon_state_id' => $state?->id,
            'cycle_position' => $position,
            'execution_mode' => $mode,
            'status' => $status,
            'execution_plan' => (array) $built['plan'],
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

        // For mode=blocked, also register a packet-scope blocker into the
        // long-horizon state so the operator sees it.
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
    }

    /**
     * Terminal `success` transition. Refuses unless:
     *   - mode != blocked;
     *   - evidence_refs is non-empty;
     *   - gate_result is non-null;
     *   - at least one gate inside gate_result.gates has status=passed.
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

        $cleanEvidence = array_values(array_filter(
            $evidenceRefs,
            static fn ($r): bool => is_array($r) && isset($r['kind']) && (string) $r['kind'] !== '',
        ));
        if ($cleanEvidence === []) {
            throw ForgeWorkPacketExecutionCycleException::completionWithoutEvidence($cycle->uuid);
        }
        if (! isset($gateResult['gates']) && ! isset($gateResult['all_passed'])) {
            throw ForgeWorkPacketExecutionCycleException::completionWithoutGateResult($cycle->uuid);
        }
        $anyPassed = false;
        foreach ((array) ($gateResult['gates'] ?? []) as $g) {
            if (is_array($g) && ($g['status'] ?? null) === ForgeLongHorizonStateCanon::GATE_STATUS_PASSED) {
                $anyPassed = true;
                break;
            }
        }
        if (! $anyPassed && (bool) ($gateResult['all_passed'] ?? false) !== true) {
            throw ForgeWorkPacketExecutionCycleException::completionWithoutPassedGate($cycle->uuid);
        }

        $cycle->evidence_refs = $cleanEvidence;
        $cycle->gate_result = $gateResult;
        $cycle->outcome_status = ForgeWorkPacketExecutionCycleCanon::OUTCOME_SUCCESS;
        $cycle->status = ForgeWorkPacketExecutionCycleCanon::STATUS_SUCCESS;
        $cycle->failure_reason = null;
        $cycle->repair_hook = null;
        $cycle->completed_at = Carbon::now();
        $outcomeMemory = $this->outcomeMemory->summarize($cycle);
        $cycle->next_action = array_merge(
            $this->computeNextActionAfterSuccess($cycle, $state),
            ['outcome_memory' => $outcomeMemory],
        );
        $cycle->cycle_hash = $this->computeCycleHash($this->cyclePayload($cycle));
        $cycle->save();
        $cycle = $this->persistOutcomeMemory($cycle);

        AiForgeWorkPacket::query()
            ->where('id', $cycle->work_packet_id)
            ->update(['status' => ForgeIntakeCanon::PACKET_STATUS_DONE]);

        if ($state !== null) {
            $this->longHorizon->recordCycle($state, [
                'cycle_id' => 'wp-cycle-'.$cycle->uuid,
                'completed_work_packets' => [(string) $cycle->work_packet_canonical_id],
                'evidence_refs' => $cleanEvidence,
            ]);
        }

        return $cycle;
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
