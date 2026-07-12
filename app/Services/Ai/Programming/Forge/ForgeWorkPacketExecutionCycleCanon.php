<?php

namespace App\Services\Ai\Programming\Forge;

/**
 * Canonical enums and defaults for `atlas.forge.work_packet_execution_cycle.v1`.
 *
 * One execution cycle is a single, audited attempt to satisfy one work packet's
 * acceptance criteria. It is intentionally narrow:
 *
 *  - it carries the plan (what we intended to do);
 *  - it carries the mode (real / safe_simulation / blocked);
 *  - it carries the outcome (success / failed / blocked + reason);
 *  - it carries the evidence + gate result attached at the moment of
 *    completion — the completion guard refuses to mark `success` without
 *    both;
 *  - it carries a repair_hook on failure so the next cycle can pick up
 *    structured advice rather than free-form chat;
 *  - it carries the resulting next_action so the long-horizon state knows
 *    what to do next.
 *
 * Provider invocation and sandboxing are reached only through the shared
 * Engineering Kernel port after a real cycle has a live reservation; this
 * lifecycle contract never implements a second provider path. Rivals battery,
 * benchmark and scoring remain out of scope.
 */
final class ForgeWorkPacketExecutionCycleCanon
{
    public const SCHEMA_VERSION = 'atlas.forge.work_packet_execution_cycle.v1';

    public const MODE_REAL = 'real';

    public const MODE_SAFE_SIMULATION = 'safe_simulation';

    public const MODE_BLOCKED = 'blocked';

    /** @var array<int,string> */
    public const MODES = [
        self::MODE_REAL,
        self::MODE_SAFE_SIMULATION,
        self::MODE_BLOCKED,
    ];

    public const STATUS_PLANNED = 'planned';

    public const STATUS_RUNNING = 'running';

    public const STATUS_SUCCESS = 'success';

    public const STATUS_FAILED = 'failed';

    public const STATUS_BLOCKED = 'blocked';

    /** @var array<int,string> */
    public const STATUSES = [
        self::STATUS_PLANNED,
        self::STATUS_RUNNING,
        self::STATUS_SUCCESS,
        self::STATUS_FAILED,
        self::STATUS_BLOCKED,
    ];

    public const OUTCOME_SUCCESS = 'success';

    public const OUTCOME_FAILED = 'failed';

    public const OUTCOME_BLOCKED = 'blocked';

    public const OUTCOME_PARTIAL = 'partial';

    /** @var array<int,string> */
    public const OUTCOMES = [
        self::OUTCOME_SUCCESS,
        self::OUTCOME_FAILED,
        self::OUTCOME_BLOCKED,
        self::OUTCOME_PARTIAL,
    ];

    public const NEXT_ACTION_RUN_NEXT_PACKET = 'run_next_packet';

    public const NEXT_ACTION_ATTACH_EVIDENCE = 'attach_evidence';

    public const NEXT_ACTION_RESOLVE_BLOCKER = 'resolve_blocker';

    public const NEXT_ACTION_REPAIR_AND_RETRY = 'repair_and_retry';

    public const NEXT_ACTION_ADVANCE_MILESTONE = 'advance_milestone';

    public const NEXT_ACTION_NO_PACKETS_LEFT = 'no_packets_left';

    /** @var array<int,string> */
    public const NEXT_ACTION_KINDS = [
        self::NEXT_ACTION_RUN_NEXT_PACKET,
        self::NEXT_ACTION_ATTACH_EVIDENCE,
        self::NEXT_ACTION_RESOLVE_BLOCKER,
        self::NEXT_ACTION_REPAIR_AND_RETRY,
        self::NEXT_ACTION_ADVANCE_MILESTONE,
        self::NEXT_ACTION_NO_PACKETS_LEFT,
    ];

    public const REPAIR_HINT_RETRY_WITH_FRESH_CONTEXT = 'retry_with_fresh_context';

    public const REPAIR_HINT_NARROW_SCOPE = 'narrow_scope';

    public const REPAIR_HINT_ADD_TESTS = 'add_tests_first';

    public const REPAIR_HINT_REQUEST_OPERATOR_INPUT = 'request_operator_input';

    public const REPAIR_HINT_ESCALATE_TO_HUMAN = 'escalate_to_human';

    /** @var array<int,string> */
    public const REPAIR_HINT_KINDS = [
        self::REPAIR_HINT_RETRY_WITH_FRESH_CONTEXT,
        self::REPAIR_HINT_NARROW_SCOPE,
        self::REPAIR_HINT_ADD_TESTS,
        self::REPAIR_HINT_REQUEST_OPERATOR_INPUT,
        self::REPAIR_HINT_ESCALATE_TO_HUMAN,
    ];
}
