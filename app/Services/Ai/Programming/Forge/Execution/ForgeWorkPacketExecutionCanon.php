<?php

namespace App\Services\Ai\Programming\Forge\Execution;

/**
 * Canonical enums + helpers for `atlas.forge.work_packet_execution_cycle.v1`.
 *
 * A Work Packet Execution Cycle is the smallest auditable unit of Forge runtime
 * progress for an Obra: it picks one work packet, plans the execution, runs it
 * (real, safe_simulation or blocked), captures evidence, runs the gate and
 * either advances the packet to `done` or emits a repair hook.
 *
 * Scope is intentionally narrow:
 *  - NO provider invocation, NO file mutation, NO destructive actions.
 *  - NO multi-agent scheduler concerns.
 *  - NO benchmark / rivals battery.
 *  - NO Dev↔Forge fusion.
 *
 * Evidence-without-completion is the hard invariant: a packet cannot become
 * `done` unless the cycle attached at least one `work_packet_receipts`
 * evidence ref to the long-horizon state.
 */
final class ForgeWorkPacketExecutionCanon
{
    public const SCHEMA_VERSION = 'atlas.forge.work_packet_execution_cycle.v1';

    public const REPAIR_HOOK_SCHEMA_VERSION = 'atlas.forge.work_packet_repair_hook.v1';

    public const SIMULATION_RECEIPT_SCHEMA_VERSION = 'atlas.forge.work_packet_simulation_receipt.v1';

    public const EXECUTION_MODE_REAL = 'real';

    public const EXECUTION_MODE_SAFE_SIMULATION = 'safe_simulation';

    public const EXECUTION_MODE_BLOCKED = 'blocked';

    /** @var array<int,string> */
    public const EXECUTION_MODES = [
        self::EXECUTION_MODE_REAL,
        self::EXECUTION_MODE_SAFE_SIMULATION,
        self::EXECUTION_MODE_BLOCKED,
    ];

    public const OUTCOME_SUCCEEDED = 'succeeded';

    public const OUTCOME_FAILED = 'failed';

    public const OUTCOME_INCONCLUSIVE = 'inconclusive';

    public const OUTCOME_BLOCKED = 'blocked';

    /** @var array<int,string> */
    public const OUTCOMES = [
        self::OUTCOME_SUCCEEDED,
        self::OUTCOME_FAILED,
        self::OUTCOME_INCONCLUSIVE,
        self::OUTCOME_BLOCKED,
    ];

    public const GATE_PASSED = 'passed';

    public const GATE_FAILED = 'failed';

    public const GATE_INCONCLUSIVE = 'inconclusive';

    /** @var array<int,string> */
    public const GATE_STATUSES = [
        self::GATE_PASSED,
        self::GATE_FAILED,
        self::GATE_INCONCLUSIVE,
    ];

    public const REPAIR_KIND_MISSING_EVIDENCE = 'missing_evidence';

    public const REPAIR_KIND_GATE_FAILED = 'gate_failed';

    public const REPAIR_KIND_EXECUTION_BLOCKED = 'execution_blocked';

    public const REPAIR_KIND_INCONCLUSIVE = 'inconclusive_result';

    /** @var array<int,string> */
    public const REPAIR_KINDS = [
        self::REPAIR_KIND_MISSING_EVIDENCE,
        self::REPAIR_KIND_GATE_FAILED,
        self::REPAIR_KIND_EXECUTION_BLOCKED,
        self::REPAIR_KIND_INCONCLUSIVE,
    ];

    public const WORK_PACKET_RECEIPT_EVIDENCE_KIND = 'work_packet_receipts';

    public const SIMULATION_RECEIPT_EVIDENCE_KIND = 'safe_simulation_receipt';
}
