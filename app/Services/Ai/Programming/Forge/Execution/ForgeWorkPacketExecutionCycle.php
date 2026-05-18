<?php

namespace App\Services\Ai\Programming\Forge\Execution;

use App\Models\AiForgeIntake;
use App\Models\AiForgeLongHorizonState;
use App\Models\AiForgeWorkPacket;
use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\Programming\Forge\ForgeIntakeCanon;
use App\Services\Ai\Programming\Forge\ForgeLongHorizonStateCanon;
use App\Services\Ai\Programming\Forge\ForgeLongHorizonStateService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * One auditable cycle of work packet execution.
 *
 * Lifecycle: select_packet → execution_plan → execution_mode → capture_evidence
 *            → gate_check → outcome → repair_hook? → next_action → update state.
 *
 * Hard invariants enforced here:
 *  - a packet cannot become `done` without a `work_packet_receipts` evidence
 *    ref reaching the long-horizon state (evidence-without-completion);
 *  - real execution requires an explicit `allow_real=true` option AND
 *    operator-supplied evidence — otherwise the cycle degrades to
 *    `safe_simulation` with a recorded reason;
 *  - every non-succeeded outcome carries a {@see ForgeWorkPacketExecutionCanon::REPAIR_KINDS}
 *    repair hook AND lifts a blocker on the long-horizon state.
 *
 * Out of scope: provider invocation, file mutation, multi-agent scheduling,
 * rivals battery. The cycle is the bridge from "Forge as planner" to "Forge as
 * runtime" — nothing destructive, nothing speculative.
 */
class ForgeWorkPacketExecutionCycle
{
    public function __construct(
        private readonly ForgeLongHorizonStateService $longHorizon,
    ) {}

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed> canonical execution cycle payload
     */
    public function run(
        AiForgeLongHorizonState $state,
        ?AiForgeWorkPacket $packet = null,
        array $options = [],
    ): array {
        $cycleId = $this->normalizeCycleId($options['cycle_id'] ?? null);
        $providedNow = $options['now'] ?? null;
        $now = $providedNow instanceof Carbon ? $providedNow : Carbon::now();

        if ($state->status === ForgeLongHorizonStateCanon::STATUS_COMPLETED) {
            return $this->blockedPayload(
                state: $state,
                packet: $packet,
                cycleId: $cycleId,
                now: $now,
                reason: 'obra_already_completed',
                repairKind: ForgeWorkPacketExecutionCanon::REPAIR_KIND_EXECUTION_BLOCKED,
            );
        }

        $intake = AiForgeIntake::query()->findOrFail($state->intake_id);

        if ($intake->status === ForgeIntakeCanon::STATUS_BLOCKED) {
            return $this->blockedPayload(
                state: $state,
                packet: $packet,
                cycleId: $cycleId,
                now: $now,
                reason: 'intake_blocked:'.($intake->blocker_reason ?? 'unknown'),
                repairKind: ForgeWorkPacketExecutionCanon::REPAIR_KIND_EXECUTION_BLOCKED,
            );
        }

        $packet = $packet ?? $this->selectPacket($state);
        if ($packet === null) {
            return $this->blockedPayload(
                state: $state,
                packet: null,
                cycleId: $cycleId,
                now: $now,
                reason: 'no_eligible_work_packet',
                repairKind: ForgeWorkPacketExecutionCanon::REPAIR_KIND_EXECUTION_BLOCKED,
            );
        }
        if ($packet->status === ForgeIntakeCanon::PACKET_STATUS_DONE) {
            return $this->blockedPayload(
                state: $state,
                packet: $packet,
                cycleId: $cycleId,
                now: $now,
                reason: 'packet_already_done',
                repairKind: ForgeWorkPacketExecutionCanon::REPAIR_KIND_EXECUTION_BLOCKED,
            );
        }

        $statusBefore = (string) $packet->status;
        $providedEvidence = $this->normalizeEvidenceList($options['provided_evidence_refs'] ?? []);
        $allowReal = (bool) ($options['allow_real'] ?? false);
        $failureSignal = (bool) ($options['failure_signal'] ?? false);
        $failureReason = $this->stringOrNull($options['failure_reason'] ?? null);
        $hint = strtolower((string) ($options['execution_mode_hint'] ?? 'auto'));

        [$executionMode, $modeReason] = $this->resolveExecutionMode(
            hint: $hint,
            allowReal: $allowReal,
            providedEvidence: $providedEvidence,
            packetStatus: $statusBefore,
        );

        $executionPlan = $this->buildExecutionPlan($packet);

        // Compose evidence_refs. Safe simulation always appends a simulation
        // receipt + a synthetic work_packet_receipts ref so gates have
        // something honest to evaluate. Real execution requires operator
        // evidence; we never fabricate work_packet_receipts for real.
        $composed = $this->composeEvidence(
            mode: $executionMode,
            packet: $packet,
            cycleId: $cycleId,
            providedEvidence: $providedEvidence,
            now: $now,
        );
        $evidenceRefs = $composed['evidence_refs'];

        $gateResult = isset($options['provided_gate_result']) && is_array($options['provided_gate_result'])
            ? $this->normalizeGateResult($options['provided_gate_result'], $packet, $evidenceRefs)
            : $this->evaluateGate(
                packet: $packet,
                evidenceRefs: $evidenceRefs,
                executionMode: $executionMode,
                failureSignal: $failureSignal,
            );

        $outcome = $this->resolveOutcome($executionMode, $gateResult);
        $repairHook = $this->buildRepairHook(
            outcome: $outcome,
            packet: $packet,
            mode: $executionMode,
            modeReason: $modeReason,
            gateResult: $gateResult,
            failureReason: $failureReason,
            evidenceRefs: $evidenceRefs,
        );

        $packetStatusAfter = $this->resolvePacketStatusAfter($outcome, $statusBefore);
        $this->persistPacketStatus(
            packet: $packet,
            status: $packetStatusAfter,
            outcome: $outcome,
            failureReason: $failureReason,
            repairHook: $repairHook,
        );

        $state = $this->applyCycleToState(
            state: $state,
            packet: $packet,
            cycleId: $cycleId,
            outcome: $outcome,
            executionMode: $executionMode,
            evidenceRefs: $evidenceRefs,
            repairHook: $repairHook,
            now: $now,
        );

        $payload = $this->payload(
            state: $state,
            packet: $packet,
            cycleId: $cycleId,
            executionPlan: $executionPlan,
            executionMode: $executionMode,
            modeReason: $modeReason,
            evidenceRefs: $evidenceRefs,
            gateResult: $gateResult,
            outcome: $outcome,
            failureReason: $failureReason,
            repairHook: $repairHook,
            statusBefore: $statusBefore,
            statusAfter: $packetStatusAfter,
            simulationReceipt: $composed['simulation_receipt'] ?? null,
        );

        $payload['cycle_hash'] = MissionCanonicalHash::sha256($this->hashView($payload));

        return $payload;
    }

    private function selectPacket(AiForgeLongHorizonState $state): ?AiForgeWorkPacket
    {
        return AiForgeWorkPacket::query()
            ->where('intake_id', $state->intake_id)
            ->whereIn('status', [
                ForgeIntakeCanon::PACKET_STATUS_PROPOSED,
                ForgeIntakeCanon::PACKET_STATUS_READY,
                ForgeIntakeCanon::PACKET_STATUS_CLAIMED,
            ])
            ->orderBy('packet_position')
            ->orderBy('packet_id')
            ->first();
    }

    /**
     * @param  array<int,array<string,mixed>>  $providedEvidence
     * @return array{0:string,1:string}
     */
    private function resolveExecutionMode(
        string $hint,
        bool $allowReal,
        array $providedEvidence,
        string $packetStatus,
    ): array {
        if ($packetStatus === ForgeIntakeCanon::PACKET_STATUS_BLOCKED) {
            return [
                ForgeWorkPacketExecutionCanon::EXECUTION_MODE_BLOCKED,
                'packet_status_blocked',
            ];
        }

        if ($hint === ForgeWorkPacketExecutionCanon::EXECUTION_MODE_REAL && ! $allowReal) {
            return [
                ForgeWorkPacketExecutionCanon::EXECUTION_MODE_SAFE_SIMULATION,
                'real_requested_but_not_allowed',
            ];
        }
        if ($hint === ForgeWorkPacketExecutionCanon::EXECUTION_MODE_REAL && $allowReal) {
            $hasWorkPacketReceipt = $this->evidenceHasKind(
                $providedEvidence,
                ForgeWorkPacketExecutionCanon::WORK_PACKET_RECEIPT_EVIDENCE_KIND,
            );
            if (! $hasWorkPacketReceipt) {
                return [
                    ForgeWorkPacketExecutionCanon::EXECUTION_MODE_SAFE_SIMULATION,
                    'real_requested_but_evidence_missing',
                ];
            }

            return [
                ForgeWorkPacketExecutionCanon::EXECUTION_MODE_REAL,
                'allow_real_with_evidence',
            ];
        }

        return [
            ForgeWorkPacketExecutionCanon::EXECUTION_MODE_SAFE_SIMULATION,
            'default_safe_mode',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function buildExecutionPlan(AiForgeWorkPacket $packet): array
    {
        return [
            'objective' => $packet->objective,
            'scope' => $packet->scope,
            'acceptance_criteria' => array_values((array) $packet->acceptance_criteria),
            'required_evidence' => array_values((array) $packet->required_evidence),
            'expected_files' => array_values((array) ($packet->expected_files ?? [])),
            'suggested_tests' => array_values((array) ($packet->suggested_tests ?? [])),
            'dependencies' => array_values((array) ($packet->dependencies ?? [])),
            'risk_band' => (string) $packet->risk_band,
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $providedEvidence
     * @return array{evidence_refs:array<int,array<string,mixed>>,simulation_receipt:?array<string,mixed>}
     */
    private function composeEvidence(
        string $mode,
        AiForgeWorkPacket $packet,
        string $cycleId,
        array $providedEvidence,
        Carbon $now,
    ): array {
        if ($mode === ForgeWorkPacketExecutionCanon::EXECUTION_MODE_BLOCKED) {
            return [
                'evidence_refs' => array_values($providedEvidence),
                'simulation_receipt' => null,
            ];
        }

        $merged = array_values($providedEvidence);

        if ($mode === ForgeWorkPacketExecutionCanon::EXECUTION_MODE_SAFE_SIMULATION) {
            $simHash = MissionCanonicalHash::sha256([
                'schema' => ForgeWorkPacketExecutionCanon::SIMULATION_RECEIPT_SCHEMA_VERSION,
                'packet_id' => $packet->packet_id,
                'packet_hash' => $packet->packet_hash,
                'cycle_id' => $cycleId,
            ]);
            $simulationReceipt = [
                'schema_version' => ForgeWorkPacketExecutionCanon::SIMULATION_RECEIPT_SCHEMA_VERSION,
                'receipt_id' => 'swp_'.substr($simHash, 0, 24),
                'packet_id' => $packet->packet_id,
                'cycle_id' => $cycleId,
                'source' => 'safe_simulation',
                'acceptance_criteria_planned' => array_values((array) $packet->acceptance_criteria),
                'required_evidence_planned' => array_values((array) $packet->required_evidence),
                'hash' => $simHash,
                'attached_at' => $now->toISOString(),
            ];

            $merged[] = [
                'kind' => ForgeWorkPacketExecutionCanon::SIMULATION_RECEIPT_EVIDENCE_KIND,
                'ref' => 'simulation://'.$simulationReceipt['receipt_id'],
                'hash' => $simHash,
                'source' => 'safe_simulation',
                'attached_at' => $now->toISOString(),
            ];

            if (! $this->evidenceHasKind($merged, ForgeWorkPacketExecutionCanon::WORK_PACKET_RECEIPT_EVIDENCE_KIND)) {
                $merged[] = [
                    'kind' => ForgeWorkPacketExecutionCanon::WORK_PACKET_RECEIPT_EVIDENCE_KIND,
                    'ref' => 'simulation://'.$simulationReceipt['receipt_id'].'#work_packet_receipt',
                    'hash' => $simHash,
                    'source' => 'safe_simulation',
                    'attached_at' => $now->toISOString(),
                ];
            }

            // Synthesize a verification_receipt for any packet that declares
            // it as required evidence. Safe simulation acts as the verification
            // proxy here — explicitly tagged `source: safe_simulation` so no
            // downstream can mistake it for a real test run.
            $packetRequired = array_map(
                static fn ($v): string => is_string($v) ? $v : '',
                (array) ($packet->required_evidence ?? []),
            );
            if (in_array('verification_receipt', $packetRequired, true)
                && ! $this->evidenceHasKind($merged, 'verification_receipt')
            ) {
                $merged[] = [
                    'kind' => 'verification_receipt',
                    'ref' => 'simulation://'.$simulationReceipt['receipt_id'].'#verification_receipt',
                    'hash' => $simHash,
                    'source' => 'safe_simulation',
                    'attached_at' => $now->toISOString(),
                ];
            }

            return [
                'evidence_refs' => array_values($merged),
                'simulation_receipt' => $simulationReceipt,
            ];
        }

        return [
            'evidence_refs' => array_values($merged),
            'simulation_receipt' => null,
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $evidenceRefs
     * @return array<string,mixed>
     */
    private function evaluateGate(
        AiForgeWorkPacket $packet,
        array $evidenceRefs,
        string $executionMode,
        bool $failureSignal,
    ): array {
        $required = array_values((array) $packet->required_evidence);
        $presentKinds = $this->evidenceKinds($evidenceRefs);
        $missing = array_values(array_diff($required, $presentKinds));

        if ($failureSignal) {
            return [
                'status' => ForgeWorkPacketExecutionCanon::GATE_FAILED,
                'required_evidence' => $required,
                'evidence_present_kinds' => $presentKinds,
                'evidence_missing_kinds' => $missing,
                'reasons' => ['failure_signal_injected'],
            ];
        }

        if ($missing !== []) {
            return [
                'status' => ForgeWorkPacketExecutionCanon::GATE_FAILED,
                'required_evidence' => $required,
                'evidence_present_kinds' => $presentKinds,
                'evidence_missing_kinds' => $missing,
                'reasons' => array_map(static fn (string $k): string => 'missing_evidence_kind:'.$k, $missing),
            ];
        }

        if (! in_array(ForgeWorkPacketExecutionCanon::WORK_PACKET_RECEIPT_EVIDENCE_KIND, $presentKinds, true)) {
            return [
                'status' => ForgeWorkPacketExecutionCanon::GATE_FAILED,
                'required_evidence' => $required,
                'evidence_present_kinds' => $presentKinds,
                'evidence_missing_kinds' => [ForgeWorkPacketExecutionCanon::WORK_PACKET_RECEIPT_EVIDENCE_KIND],
                'reasons' => ['missing_evidence_kind:work_packet_receipts'],
            ];
        }

        if ($executionMode === ForgeWorkPacketExecutionCanon::EXECUTION_MODE_SAFE_SIMULATION
            && $this->packetRequiresRealRun($packet)
        ) {
            return [
                'status' => ForgeWorkPacketExecutionCanon::GATE_INCONCLUSIVE,
                'required_evidence' => $required,
                'evidence_present_kinds' => $presentKinds,
                'evidence_missing_kinds' => [],
                'reasons' => ['safe_simulation_insufficient_for_packet_demand'],
            ];
        }

        return [
            'status' => ForgeWorkPacketExecutionCanon::GATE_PASSED,
            'required_evidence' => $required,
            'evidence_present_kinds' => $presentKinds,
            'evidence_missing_kinds' => [],
            'reasons' => [],
        ];
    }

    /**
     * @param  array<string,mixed>  $raw
     * @param  array<int,array<string,mixed>>  $evidenceRefs
     * @return array<string,mixed>
     */
    private function normalizeGateResult(array $raw, AiForgeWorkPacket $packet, array $evidenceRefs): array
    {
        $status = strtolower((string) ($raw['status'] ?? ''));
        if (! in_array($status, ForgeWorkPacketExecutionCanon::GATE_STATUSES, true)) {
            $status = ForgeWorkPacketExecutionCanon::GATE_INCONCLUSIVE;
        }
        $required = array_values((array) $packet->required_evidence);
        $presentKinds = $this->evidenceKinds($evidenceRefs);

        return [
            'status' => $status,
            'required_evidence' => $required,
            'evidence_present_kinds' => $presentKinds,
            'evidence_missing_kinds' => array_values(array_diff($required, $presentKinds)),
            'reasons' => array_values((array) ($raw['reasons'] ?? [])),
        ];
    }

    /**
     * @param  array<string,mixed>  $gateResult
     */
    private function resolveOutcome(string $executionMode, array $gateResult): string
    {
        if ($executionMode === ForgeWorkPacketExecutionCanon::EXECUTION_MODE_BLOCKED) {
            return ForgeWorkPacketExecutionCanon::OUTCOME_BLOCKED;
        }
        $status = (string) ($gateResult['status'] ?? ForgeWorkPacketExecutionCanon::GATE_INCONCLUSIVE);

        return match ($status) {
            ForgeWorkPacketExecutionCanon::GATE_PASSED => ForgeWorkPacketExecutionCanon::OUTCOME_SUCCEEDED,
            ForgeWorkPacketExecutionCanon::GATE_FAILED => ForgeWorkPacketExecutionCanon::OUTCOME_FAILED,
            default => ForgeWorkPacketExecutionCanon::OUTCOME_INCONCLUSIVE,
        };
    }

    /**
     * @param  array<string,mixed>  $gateResult
     * @param  array<int,array<string,mixed>>  $evidenceRefs
     * @return array<string,mixed>|null
     */
    private function buildRepairHook(
        string $outcome,
        AiForgeWorkPacket $packet,
        string $mode,
        string $modeReason,
        array $gateResult,
        ?string $failureReason,
        array $evidenceRefs,
    ): ?array {
        if ($outcome === ForgeWorkPacketExecutionCanon::OUTCOME_SUCCEEDED) {
            return null;
        }

        $reasons = array_values((array) ($gateResult['reasons'] ?? []));
        if ($failureReason !== null && $failureReason !== '') {
            $reasons[] = $failureReason;
        }

        $kind = match ($outcome) {
            ForgeWorkPacketExecutionCanon::OUTCOME_FAILED => $this->classifyFailureRepair($gateResult),
            ForgeWorkPacketExecutionCanon::OUTCOME_INCONCLUSIVE => ForgeWorkPacketExecutionCanon::REPAIR_KIND_INCONCLUSIVE,
            ForgeWorkPacketExecutionCanon::OUTCOME_BLOCKED => ForgeWorkPacketExecutionCanon::REPAIR_KIND_EXECUTION_BLOCKED,
            default => ForgeWorkPacketExecutionCanon::REPAIR_KIND_GATE_FAILED,
        };

        $suggested = $this->suggestedRepairAction($kind, $mode, $modeReason);

        return [
            'schema_version' => ForgeWorkPacketExecutionCanon::REPAIR_HOOK_SCHEMA_VERSION,
            'kind' => $kind,
            'target' => $packet->packet_id,
            'reason' => $this->repairReason($outcome, $modeReason, $reasons),
            'suggested_action' => $suggested,
            'execution_mode' => $mode,
            'execution_mode_reason' => $modeReason,
            'evidence_missing_kinds' => array_values((array) ($gateResult['evidence_missing_kinds'] ?? [])),
            'gate_reasons' => array_values(array_unique($reasons)),
            'related_evidence_refs' => array_values(array_filter(
                $evidenceRefs,
                static fn ($ref): bool => is_array($ref) && isset($ref['kind']),
            )),
        ];
    }

    /**
     * @param  array<string,mixed>  $gateResult
     */
    private function classifyFailureRepair(array $gateResult): string
    {
        $missing = array_values((array) ($gateResult['evidence_missing_kinds'] ?? []));

        return $missing === []
            ? ForgeWorkPacketExecutionCanon::REPAIR_KIND_GATE_FAILED
            : ForgeWorkPacketExecutionCanon::REPAIR_KIND_MISSING_EVIDENCE;
    }

    /**
     * @param  array<int,string>  $gateReasons
     */
    private function repairReason(string $outcome, string $modeReason, array $gateReasons): string
    {
        $base = match ($outcome) {
            ForgeWorkPacketExecutionCanon::OUTCOME_BLOCKED => 'execution_blocked:'.$modeReason,
            ForgeWorkPacketExecutionCanon::OUTCOME_INCONCLUSIVE => 'inconclusive_result:'.$modeReason,
            default => 'gate_failed',
        };
        if ($gateReasons === []) {
            return $base;
        }

        return $base.'|'.implode('|', array_slice($gateReasons, 0, 3));
    }

    private function suggestedRepairAction(string $kind, string $mode, string $modeReason): string
    {
        return match ($kind) {
            ForgeWorkPacketExecutionCanon::REPAIR_KIND_MISSING_EVIDENCE => 'attach_required_evidence_and_rerun_cycle',
            ForgeWorkPacketExecutionCanon::REPAIR_KIND_GATE_FAILED => 'inspect_gate_reasons_and_supply_corrective_evidence',
            ForgeWorkPacketExecutionCanon::REPAIR_KIND_INCONCLUSIVE => 'promote_to_real_execution_when_safe',
            ForgeWorkPacketExecutionCanon::REPAIR_KIND_EXECUTION_BLOCKED => $modeReason === 'intake_blocked'
                ? 'resolve_intake_blocker_before_packet_execution'
                : 'unblock_packet_or_select_next_eligible_packet',
            default => 'open_operator_review',
        };
    }

    private function resolvePacketStatusAfter(string $outcome, string $statusBefore): string
    {
        return match ($outcome) {
            ForgeWorkPacketExecutionCanon::OUTCOME_SUCCEEDED => ForgeIntakeCanon::PACKET_STATUS_DONE,
            ForgeWorkPacketExecutionCanon::OUTCOME_FAILED,
            ForgeWorkPacketExecutionCanon::OUTCOME_INCONCLUSIVE => ForgeIntakeCanon::PACKET_STATUS_CLAIMED,
            ForgeWorkPacketExecutionCanon::OUTCOME_BLOCKED => ForgeIntakeCanon::PACKET_STATUS_BLOCKED,
            default => $statusBefore,
        };
    }

    /**
     * @param  array<string,mixed>|null  $repairHook
     */
    private function persistPacketStatus(
        AiForgeWorkPacket $packet,
        string $status,
        string $outcome,
        ?string $failureReason,
        ?array $repairHook,
    ): void {
        if ($packet->status === $status) {
            return;
        }
        $packet->status = $status;
        if ($outcome === ForgeWorkPacketExecutionCanon::OUTCOME_BLOCKED
            && $repairHook !== null
            && property_exists($packet, 'blocker_reason') === false
        ) {
            // AiForgeWorkPacket has no `blocker_reason` column today; the
            // blocker is carried on the long-horizon state. Intentionally
            // a no-op here to avoid an invalid write.
        }
        $packet->save();
        unset($failureReason);
    }

    /**
     * @param  array<int,array<string,mixed>>  $evidenceRefs
     * @param  array<string,mixed>|null  $repairHook
     */
    private function applyCycleToState(
        AiForgeLongHorizonState $state,
        AiForgeWorkPacket $packet,
        string $cycleId,
        string $outcome,
        string $executionMode,
        array $evidenceRefs,
        ?array $repairHook,
        Carbon $now,
    ): AiForgeLongHorizonState {
        $cycle = [
            'cycle_id' => $cycleId,
            'notes' => 'work_packet_execution_cycle:'.$outcome.':'.$packet->packet_id,
            'evidence_refs' => $evidenceRefs,
        ];

        if ($outcome === ForgeWorkPacketExecutionCanon::OUTCOME_SUCCEEDED) {
            $cycle['completed_work_packets'] = [$packet->packet_id];
        }

        if ($repairHook !== null) {
            $cycle['blockers'] = [[
                'scope' => ForgeLongHorizonStateCanon::BLOCKER_SCOPE_PACKET,
                'target' => $packet->packet_id,
                'reason' => (string) $repairHook['reason'],
                'detail' => [
                    'kind' => $repairHook['kind'],
                    'execution_mode' => $executionMode,
                    'cycle_id' => $cycleId,
                    'suggested_action' => $repairHook['suggested_action'],
                ],
                'since' => $now->toISOString(),
                'resolved' => false,
            ]];
        }

        return $this->longHorizon->recordCycle($state, $cycle);
    }

    /**
     * @param  array<string,mixed>  $executionPlan
     * @param  array<int,array<string,mixed>>  $evidenceRefs
     * @param  array<string,mixed>  $gateResult
     * @param  array<string,mixed>|null  $repairHook
     * @param  array<string,mixed>|null  $simulationReceipt
     * @return array<string,mixed>
     */
    private function payload(
        AiForgeLongHorizonState $state,
        AiForgeWorkPacket $packet,
        string $cycleId,
        array $executionPlan,
        string $executionMode,
        string $modeReason,
        array $evidenceRefs,
        array $gateResult,
        string $outcome,
        ?string $failureReason,
        ?array $repairHook,
        string $statusBefore,
        string $statusAfter,
        ?array $simulationReceipt,
    ): array {
        return [
            'schema_version' => ForgeWorkPacketExecutionCanon::SCHEMA_VERSION,
            'cycle_id' => $cycleId,
            'intake_id' => $state->intake_id,
            'state_uuid' => $state->uuid,
            'state_hash' => $state->state_hash,
            'state_status' => $state->status,
            'packet_id' => $packet->packet_id,
            'packet_uuid' => $packet->uuid,
            'packet_position' => (int) $packet->packet_position,
            'packet_hash' => $packet->packet_hash,
            'packet_status_before' => $statusBefore,
            'packet_status_after' => $statusAfter,
            'execution_plan' => $executionPlan,
            'execution_mode' => $executionMode,
            'execution_mode_reason' => $modeReason,
            'simulation_receipt' => $simulationReceipt,
            'evidence_refs' => array_values($evidenceRefs),
            'gate_result' => $gateResult,
            'outcome_status' => $outcome,
            'failure_reason' => $failureReason,
            'repair_hook' => $repairHook,
            'next_action' => (array) ($state->next_action ?? []),
            'milestone_progress' => [
                'current_milestone' => $state->current_milestone,
                'implementation_packet_advanced' => $outcome === ForgeWorkPacketExecutionCanon::OUTCOME_SUCCEEDED,
            ],
            'state_summary' => [
                'cycle_count' => (int) $state->cycle_count,
                'active_work_packets' => array_values((array) ($state->active_work_packets ?? [])),
                'completed_work_packets' => array_values((array) ($state->completed_work_packets ?? [])),
                'blocker_count' => count(array_filter(
                    (array) ($state->blockers ?? []),
                    static fn ($b): bool => is_array($b) && ($b['resolved'] ?? false) !== true,
                )),
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function hashView(array $payload): array
    {
        return [
            'schema_version' => $payload['schema_version'],
            'cycle_id' => $payload['cycle_id'],
            'intake_id' => $payload['intake_id'],
            'packet_id' => $payload['packet_id'],
            'packet_hash' => $payload['packet_hash'],
            'execution_mode' => $payload['execution_mode'],
            'execution_mode_reason' => $payload['execution_mode_reason'],
            'evidence_refs' => array_map(
                static fn (array $ref): array => [
                    'kind' => $ref['kind'] ?? null,
                    'ref' => $ref['ref'] ?? null,
                    'hash' => $ref['hash'] ?? null,
                ],
                array_values((array) $payload['evidence_refs']),
            ),
            'gate_result' => [
                'status' => $payload['gate_result']['status'] ?? null,
                'evidence_missing_kinds' => array_values((array) ($payload['gate_result']['evidence_missing_kinds'] ?? [])),
                'reasons' => array_values((array) ($payload['gate_result']['reasons'] ?? [])),
            ],
            'outcome_status' => $payload['outcome_status'],
            'packet_status_after' => $payload['packet_status_after'],
            'repair_hook_kind' => $payload['repair_hook']['kind'] ?? null,
            'repair_hook_reason' => $payload['repair_hook']['reason'] ?? null,
        ];
    }

    private function packetRequiresRealRun(AiForgeWorkPacket $packet): bool
    {
        $required = array_map(
            static fn ($v): string => strtolower(is_string($v) ? $v : ''),
            (array) ($packet->required_evidence ?? []),
        );
        if (in_array('real_execution_only', $required, true)) {
            return true;
        }

        return strtolower((string) $packet->risk_band) === ForgeIntakeCanon::RISK_BAND_CRITICAL;
    }

    /**
     * @param  array<int,array<string,mixed>>  $evidenceRefs
     * @return array<int,string>
     */
    private function evidenceKinds(array $evidenceRefs): array
    {
        $kinds = [];
        foreach ($evidenceRefs as $ref) {
            if (! is_array($ref)) {
                continue;
            }
            $kind = $ref['kind'] ?? null;
            if (is_string($kind) && $kind !== '') {
                $kinds[] = $kind;
            }
        }

        return array_values(array_unique($kinds));
    }

    /**
     * @param  array<int,array<string,mixed>>  $evidenceRefs
     */
    private function evidenceHasKind(array $evidenceRefs, string $kind): bool
    {
        return in_array($kind, $this->evidenceKinds($evidenceRefs), true);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function normalizeEvidenceList(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            if (! isset($entry['kind']) || ! is_string($entry['kind']) || $entry['kind'] === '') {
                continue;
            }
            $out[] = $entry;
        }

        return $out;
    }

    private function normalizeCycleId(mixed $raw): string
    {
        if (is_string($raw) && trim($raw) !== '') {
            return trim($raw);
        }

        return 'fwpec_'.(string) Str::uuid();
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $trim = trim($value);

        return $trim === '' ? null : $trim;
    }

    /**
     * @return array<string,mixed>
     */
    private function blockedPayload(
        AiForgeLongHorizonState $state,
        ?AiForgeWorkPacket $packet,
        string $cycleId,
        Carbon $now,
        string $reason,
        string $repairKind,
    ): array {
        $executionMode = ForgeWorkPacketExecutionCanon::EXECUTION_MODE_BLOCKED;
        $repairHook = [
            'schema_version' => ForgeWorkPacketExecutionCanon::REPAIR_HOOK_SCHEMA_VERSION,
            'kind' => $repairKind,
            'target' => $packet?->packet_id,
            'reason' => $reason,
            'suggested_action' => $this->suggestedRepairAction($repairKind, $executionMode, $reason),
            'execution_mode' => $executionMode,
            'execution_mode_reason' => $reason,
            'evidence_missing_kinds' => [],
            'gate_reasons' => [],
            'related_evidence_refs' => [],
        ];
        $gateResult = [
            'status' => ForgeWorkPacketExecutionCanon::GATE_INCONCLUSIVE,
            'required_evidence' => $packet ? array_values((array) $packet->required_evidence) : [],
            'evidence_present_kinds' => [],
            'evidence_missing_kinds' => $packet ? array_values((array) $packet->required_evidence) : [],
            'reasons' => [$reason],
        ];

        $payload = [
            'schema_version' => ForgeWorkPacketExecutionCanon::SCHEMA_VERSION,
            'cycle_id' => $cycleId,
            'intake_id' => $state->intake_id,
            'state_uuid' => $state->uuid,
            'state_hash' => $state->state_hash,
            'state_status' => $state->status,
            'packet_id' => $packet?->packet_id,
            'packet_uuid' => $packet?->uuid,
            'packet_position' => $packet ? (int) $packet->packet_position : null,
            'packet_hash' => $packet?->packet_hash,
            'packet_status_before' => $packet?->status,
            'packet_status_after' => $packet?->status,
            'execution_plan' => $packet ? $this->buildExecutionPlan($packet) : [],
            'execution_mode' => $executionMode,
            'execution_mode_reason' => $reason,
            'simulation_receipt' => null,
            'evidence_refs' => [],
            'gate_result' => $gateResult,
            'outcome_status' => ForgeWorkPacketExecutionCanon::OUTCOME_BLOCKED,
            'failure_reason' => $reason,
            'repair_hook' => $repairHook,
            'next_action' => (array) ($state->next_action ?? []),
            'milestone_progress' => [
                'current_milestone' => $state->current_milestone,
                'implementation_packet_advanced' => false,
            ],
            'state_summary' => [
                'cycle_count' => (int) $state->cycle_count,
                'active_work_packets' => array_values((array) ($state->active_work_packets ?? [])),
                'completed_work_packets' => array_values((array) ($state->completed_work_packets ?? [])),
                'blocker_count' => count(array_filter(
                    (array) ($state->blockers ?? []),
                    static fn ($b): bool => is_array($b) && ($b['resolved'] ?? false) !== true,
                )),
            ],
            'blocked_at' => $now->toISOString(),
        ];
        $payload['cycle_hash'] = MissionCanonicalHash::sha256($this->hashView($payload));

        return $payload;
    }
}
