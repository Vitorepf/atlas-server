<?php

namespace App\Services\Ai\Mission;

use App\Models\AiMissionEvidenceRef;
use App\Models\AiWorkOrder;

/**
 * DTO canônico de um ciclo de Follow-Through.
 *
 * Schema: atlas.ai.mission_follow_through.cycle.v1
 *
 * Cada `runNext()` produz um `MissionFollowThroughResult`. Multi-cycle
 * (`runUntilBlockedOrComplete`) retorna o último, mais o histórico via
 * AiMissionEvent canon na própria mission.
 *
 * Outcomes canônicos (`outcome`):
 *   - `simulated_safe` — non-programming flow executou safely sem provider call
 *   - `handoff_dev` — work_order encaminhado para Atlas Dev
 *   - `handoff_forge` — work_order encaminhado para Atlas Forge
 *   - `no_selectable_step` — mission sem WO selecionáveis (todos terminais ou bloqueados)
 *   - `mission_blocked` — mission transitou para BLOCKED por falha
 *   - `mission_completed_pending_cert` — todos WO terminais, certify foi tentada
 *   - `mission_certified` — certify PASSED, mission COMPLETED selada
 *   - `mission_already_terminal` — mission já está em estado terminal
 *   - `noop_invalid_state` — mission em estado que não permite progresso (cancelled/failed)
 */
final class MissionFollowThroughResult
{
    public const SCHEMA_VERSION = 'atlas.ai.mission_follow_through.cycle.v1';

    public const OUTCOME_SIMULATED_SAFE = 'simulated_safe';

    public const OUTCOME_HANDOFF_DEV = 'handoff_dev';

    public const OUTCOME_HANDOFF_FORGE = 'handoff_forge';

    public const OUTCOME_NO_SELECTABLE_STEP = 'no_selectable_step';

    public const OUTCOME_MISSION_BLOCKED = 'mission_blocked';

    public const OUTCOME_MISSION_COMPLETED_PENDING_CERT = 'mission_completed_pending_cert';

    public const OUTCOME_MISSION_CERTIFIED = 'mission_certified';

    public const OUTCOME_MISSION_ALREADY_TERMINAL = 'mission_already_terminal';

    public const OUTCOME_NOOP_INVALID_STATE = 'noop_invalid_state';

    public const OUTCOME_WAITING_APPROVAL = 'waiting_for_user';

    public const OUTCOME_BLOCKED_BY_APPROVAL = 'blocked_by_approval';

    /**
     * @param  array<int,string>  $blockers
     * @param  array<int,AiMissionEvidenceRef>  $evidenceRefs
     */
    public function __construct(
        public readonly string $cycleId,
        public readonly string $missionUuid,
        public readonly string $statusBefore,
        public readonly string $statusAfter,
        public readonly ?AiWorkOrder $selectedWorkOrder,
        public readonly ?string $selectedFlow,
        public readonly string $actionTaken,
        public readonly string $outcome,
        public readonly array $evidenceRefs,
        public readonly ?string $receiptHash,
        public readonly array $blockers,
        public readonly bool $repairCreated,
        public readonly ?string $certificationStatus,
        public readonly ?string $certificationHash,
        public readonly string $nextAction,
        public readonly string $hash,
    ) {}

    /**
     * Construct a cycle hash from canonical fields. Deterministic — same
     * inputs → same hash. Used to dedupe in CI/replay.
     *
     * @param  array<string,mixed>  $extras
     */
    public static function buildHash(
        string $cycleId,
        string $missionUuid,
        string $statusBefore,
        string $statusAfter,
        ?string $selectedWorkOrderUuid,
        ?string $selectedFlow,
        string $outcome,
        ?string $receiptHash,
        array $extras = [],
    ): string {
        return MissionCanonicalHash::sha256([
            'cycle_id' => $cycleId,
            'mission_uuid' => $missionUuid,
            'status_before' => $statusBefore,
            'status_after' => $statusAfter,
            'selected_work_order_uuid' => $selectedWorkOrderUuid,
            'selected_flow' => $selectedFlow,
            'outcome' => $outcome,
            'receipt_hash' => $receiptHash,
            ...$extras,
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'cycle_id' => $this->cycleId,
            'mission_uuid' => $this->missionUuid,
            'status_before' => $this->statusBefore,
            'status_after' => $this->statusAfter,
            'selected_work_order' => $this->selectedWorkOrder === null
                ? null
                : [
                    'uuid' => $this->selectedWorkOrder->uuid,
                    'id' => $this->selectedWorkOrder->id,
                    'title' => $this->selectedWorkOrder->title,
                    'objective_id' => $this->selectedWorkOrder->objective_id,
                    'status_after_cycle' => $this->selectedWorkOrder->status,
                ],
            'selected_flow' => $this->selectedFlow,
            'action_taken' => $this->actionTaken,
            'outcome' => $this->outcome,
            'evidence_refs' => array_map(
                static fn (AiMissionEvidenceRef $e) => [
                    'id' => $e->id,
                    'uuid' => $e->uuid,
                    'evidence_type' => $e->evidence_type,
                    'evidence_ref' => $e->evidence_ref,
                    'evidence_hash' => $e->evidence_hash,
                ],
                $this->evidenceRefs,
            ),
            'receipt_hash' => $this->receiptHash,
            'blockers' => $this->blockers,
            'repair_created' => $this->repairCreated,
            'certification_status' => $this->certificationStatus,
            'certification_hash' => $this->certificationHash,
            'next_action' => $this->nextAction,
            'hash' => $this->hash,
        ];
    }

    /**
     * Indica que o ciclo de fato avançou a mission (selecionou WO, marcou status ou criou handoff).
     */
    public function advanced(): bool
    {
        return ! in_array($this->outcome, [
            self::OUTCOME_NO_SELECTABLE_STEP,
            self::OUTCOME_MISSION_ALREADY_TERMINAL,
            self::OUTCOME_NOOP_INVALID_STATE,
            self::OUTCOME_WAITING_APPROVAL,
            self::OUTCOME_BLOCKED_BY_APPROVAL,
        ], true);
    }

    /**
     * Indica que mission alcançou estado terminal e o loop deve parar.
     */
    public function terminal(): bool
    {
        return in_array($this->outcome, [
            self::OUTCOME_MISSION_CERTIFIED,
            self::OUTCOME_MISSION_BLOCKED,
            self::OUTCOME_MISSION_ALREADY_TERMINAL,
            self::OUTCOME_NOOP_INVALID_STATE,
            self::OUTCOME_WAITING_APPROVAL,
            self::OUTCOME_BLOCKED_BY_APPROVAL,
        ], true);
    }

    /**
     * Indica que o loop deve continuar (mais ciclos disponíveis).
     */
    public function shouldContinue(): bool
    {
        return ! $this->terminal()
            && $this->outcome !== self::OUTCOME_NO_SELECTABLE_STEP
            && $this->outcome !== self::OUTCOME_MISSION_COMPLETED_PENDING_CERT
            && $this->outcome !== self::OUTCOME_WAITING_APPROVAL
            && $this->outcome !== self::OUTCOME_BLOCKED_BY_APPROVAL;
    }
}
