<?php

namespace App\Services\Ai\Programming\Forge;

/**
 * Canonical enums, defaults and gate map for `atlas.forge.long_horizon_state.v1`.
 *
 * Long-horizon state is the persistent record that lets an Atlas Forge Obra
 * survive across cycles, sessions and operators. Without it Forge degrades
 * into a sequence of disconnected prompts — exactly what the Forge OS index
 * (`atlas-forge-operating-system.md`) names as a top failure mode.
 *
 * Scope of THIS canon (intentionally narrow):
 *  - field shapes, status enum, gate→evidence map, next-action kinds, default
 *    DoD for completion;
 *  - NO provider invocation, NO multi-agent scheduler, NO benchmark/rivals
 *    concerns. Those live in their own layers.
 */
final class ForgeLongHorizonStateCanon
{
    public const SCHEMA_VERSION = 'atlas.forge.long_horizon_state.v1';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_BLOCKED = 'blocked';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_SUSPENDED = 'suspended';

    /** @var array<int,string> */
    public const STATUSES = [
        self::STATUS_ACTIVE,
        self::STATUS_BLOCKED,
        self::STATUS_COMPLETED,
        self::STATUS_SUSPENDED,
    ];

    public const NEXT_ACTION_RESOLVE_INTAKE_BLOCKER = 'resolve_intake_blocker';

    public const NEXT_ACTION_ATTACH_EVIDENCE = 'attach_evidence';

    public const NEXT_ACTION_SCOPE_WORK_PACKETS = 'scope_work_packets';

    public const NEXT_ACTION_CLAIM_WORK_PACKET = 'claim_work_packet';

    public const NEXT_ACTION_COMPLETE_WORK_PACKETS = 'complete_work_packets';

    public const NEXT_ACTION_RESOLVE_BLOCKER = 'resolve_blocker';

    public const NEXT_ACTION_ADVANCE_MILESTONE = 'advance_milestone';

    public const NEXT_ACTION_RUN_CERTIFICATION = 'run_certification';

    public const NEXT_ACTION_COMPLETE_OBRA = 'complete_obra';

    public const NEXT_ACTION_OBRA_COMPLETED = 'obra_completed';

    /** @var array<int,string> */
    public const NEXT_ACTION_KINDS = [
        self::NEXT_ACTION_RESOLVE_INTAKE_BLOCKER,
        self::NEXT_ACTION_ATTACH_EVIDENCE,
        self::NEXT_ACTION_SCOPE_WORK_PACKETS,
        self::NEXT_ACTION_CLAIM_WORK_PACKET,
        self::NEXT_ACTION_COMPLETE_WORK_PACKETS,
        self::NEXT_ACTION_RESOLVE_BLOCKER,
        self::NEXT_ACTION_ADVANCE_MILESTONE,
        self::NEXT_ACTION_RUN_CERTIFICATION,
        self::NEXT_ACTION_COMPLETE_OBRA,
        self::NEXT_ACTION_OBRA_COMPLETED,
    ];

    public const GATE_STATUS_PASSED = 'passed';

    public const GATE_STATUS_FAILED = 'failed';

    public const GATE_STATUS_PENDING = 'pending';

    /** @var array<int,string> */
    public const GATE_STATUSES = [
        self::GATE_STATUS_PASSED,
        self::GATE_STATUS_FAILED,
        self::GATE_STATUS_PENDING,
    ];

    public const BLOCKER_SCOPE_INTAKE = 'intake';

    public const BLOCKER_SCOPE_MILESTONE = 'milestone';

    public const BLOCKER_SCOPE_PACKET = 'packet';

    /** @var array<int,string> */
    public const BLOCKER_SCOPES = [
        self::BLOCKER_SCOPE_INTAKE,
        self::BLOCKER_SCOPE_MILESTONE,
        self::BLOCKER_SCOPE_PACKET,
    ];

    public const MILESTONE_STATUS_PENDING = 'pending';

    public const MILESTONE_STATUS_ACTIVE = 'active';

    public const MILESTONE_STATUS_COMPLETED = 'completed';

    public const MILESTONE_STATUS_BLOCKED = 'blocked';

    /** @var array<int,string> */
    public const MILESTONE_STATUSES = [
        self::MILESTONE_STATUS_PENDING,
        self::MILESTONE_STATUS_ACTIVE,
        self::MILESTONE_STATUS_COMPLETED,
        self::MILESTONE_STATUS_BLOCKED,
    ];

    /**
     * Map from canonical gate_id → evidence_kind that satisfies it.
     *
     * Two special gates do NOT map to an evidence kind:
     *  - `work_packets_scoped`        — checked against work packet count.
     *  - `no_unresolved_blockers`     — checked against blockers list.
     *
     * Every other canonical gate from `ForgeMilestonePlanner::blueprint()`
     * passes when its mapped evidence_kind is present in the state's
     * `evidence_refs` (one ref of that kind is enough; we audit count
     * separately if needed).
     *
     * @return array<string,string>
     */
    public static function gateToEvidenceKindMap(): array
    {
        return [
            'spec_anchor_valid' => 'plan',
            'context_sufficiency_passed' => 'context_pack',
            'reservations_valid' => 'reservations',
            'permissions_approved' => 'permissions',
            'verification_receipt_present' => 'verification_receipt',
            'docs_health_passed' => 'evidence_pack',
            'certification_passed_or_blocked' => 'certification',
        ];
    }

    /**
     * Gates that are NOT evidence-driven; they are structural / state-driven.
     *
     * @return array<int,string>
     */
    public static function structuralGates(): array
    {
        return ['work_packets_scoped', 'no_unresolved_blockers'];
    }
}
