<?php

namespace App\Services\Ai\Programming\Forge;


/**
 * Canonical enums for `atlas.forge.multi_agent_schedule.v1`.
 *
 * The Forge multi-agent scheduler is intentionally a PLANNING layer, not an
 * execution layer. It never spawns agents, never invokes providers, never
 * spends tokens — it produces a stable, hash-bearing contract that
 * downstream layers (Forge Work Packet Execution Cycle, Dev escalation,
 * SDD pipeline, Agent Control Plane) can consume.
 *
 * Why a separate canon from
 * `AgentControlPlaneMultiAgentParallelismPlanner` (SelfConstruction-scoped):
 * the SelfConstruction planner is concerned with terminal worker scope locks
 * and runtime registry leases; this canon is concerned with Forge work
 * packet ownership, role composition (planner/worker/verifier/researcher/
 * debugger/reviewer), and verification topology. The two layers compose
 * but do not duplicate.
 */
final class ForgeMultiAgentScheduleCanon
{
    public const SCHEMA_VERSION = 'atlas.forge.multi_agent_schedule.v1';

    public const ROLE_PLANNER = 'planner';

    public const ROLE_WORKER = 'worker';

    public const ROLE_VERIFIER = 'verifier';

    public const ROLE_RESEARCHER = 'researcher';

    public const ROLE_DEBUGGER = 'debugger';

    public const ROLE_REVIEWER = 'reviewer';

    /** @var array<int,string> */
    public const ROLES = [
        self::ROLE_PLANNER,
        self::ROLE_WORKER,
        self::ROLE_VERIFIER,
        self::ROLE_RESEARCHER,
        self::ROLE_DEBUGGER,
        self::ROLE_REVIEWER,
    ];

    public const STATUS_READY = 'ready';

    public const STATUS_BLOCKED_BY_OWNERSHIP_CONFLICT = 'blocked_by_ownership_conflict';

    public const STATUS_DEGENERATE_NO_PACKETS = 'degenerate_no_packets';

    /** @var array<int,string> */
    public const STATUSES = [
        self::STATUS_READY,
        self::STATUS_BLOCKED_BY_OWNERSHIP_CONFLICT,
        self::STATUS_DEGENERATE_NO_PACKETS,
    ];

    public const INTEGRATION_SINGLE_WORKER = 'single_worker';

    public const INTEGRATION_PARALLEL_NO_OVERLAP = 'parallel_no_overlap';

    public const INTEGRATION_SERIAL_MERGE_ON_OVERLAP = 'serial_merge_on_overlap';

    public const INTEGRATION_BLOCKED = 'blocked';

    /** @var array<int,string> */
    public const INTEGRATIONS = [
        self::INTEGRATION_SINGLE_WORKER,
        self::INTEGRATION_PARALLEL_NO_OVERLAP,
        self::INTEGRATION_SERIAL_MERGE_ON_OVERLAP,
        self::INTEGRATION_BLOCKED,
    ];

    public const RISK_LOW = 'low';

    public const RISK_MEDIUM = 'medium';

    public const RISK_HIGH = 'high';

    public const RISK_CRITICAL = 'critical';

    /** @var array<int,string> */
    public const RISK_BANDS = [
        self::RISK_LOW,
        self::RISK_MEDIUM,
        self::RISK_HIGH,
        self::RISK_CRITICAL,
    ];

    public const CONFLICT_KIND_FILE_OVERLAP = 'file_overlap';

    public const CONFLICT_KIND_DEPENDENCY_CYCLE = 'dependency_cycle';

    public const CONFLICT_KIND_UNSCOPED_PACKET = 'unscoped_packet';

    /** @var array<int,string> */
    public const CONFLICT_KINDS = [
        self::CONFLICT_KIND_FILE_OVERLAP,
        self::CONFLICT_KIND_DEPENDENCY_CYCLE,
        self::CONFLICT_KIND_UNSCOPED_PACKET,
    ];

    /**
     * Risk bands that auto-add a verifier role.
     *
     * @return array<int,string>
     */
    public static function highRiskBands(): array
    {
        return [self::RISK_HIGH, self::RISK_CRITICAL];
    }

    /**
     * Worker count above which a coordinator/planner role is auto-added.
     */
    public const PLANNER_THRESHOLD = 5;
}
