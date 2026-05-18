<?php

namespace App\Services\Ai\Programming\Console;

/**
 * Canonical enums + envelope shape for the Atlas Programming Console.
 *
 * The console is a thin polish layer over the existing readiness, telemetry
 * and control-plane services. It produces a single canonical JSON envelope so
 * humans and AIs always see the same field names regardless of which
 * sub-action they invoke.
 *
 * Invariants (enforced in {@see ProgrammingConsoleService}):
 *  - read-only sub-actions never write to disk or DB;
 *  - write sub-actions (e.g. forge:intake) only call paths that do NOT invoke
 *    rival providers; benchmark execution is explicitly out of scope;
 *  - every envelope carries `claim_policy.benchmark_not_run = true`.
 */
final class ProgrammingConsoleCanon
{
    public const SCHEMA_VERSION = 'atlas.programming.console.v1';

    public const ACTION_STATUS = 'status';

    public const ACTION_DEV_PLAN = 'dev:plan';

    public const ACTION_DEV_SUMMARY = 'dev:summary';

    public const ACTION_FORGE_INTAKE = 'forge:intake';

    public const ACTION_FORGE_SUMMARY = 'forge:summary';

    public const ACTION_BLOCKERS = 'blockers';

    public const ACTION_NEXT_ACTIONS = 'next-actions';

    public const ACTION_EVIDENCE = 'evidence';

    public const ACTION_CERTIFICATION = 'certification';

    public const ACTION_TELEMETRY = 'telemetry';

    public const ACTION_SMOKE = 'smoke';

    public const ACTION_LONG_HORIZON_STATUS = 'long-horizon:status';

    public const ACTION_LONG_HORIZON_COMPACT = 'long-horizon:compact';

    public const ACTION_LONG_HORIZON_CONTINUE = 'long-horizon:continue';

    public const ACTION_LONG_HORIZON_CERTIFY = 'long-horizon:certify';

    /** @var array<int,string> */
    public const ACTIONS = [
        self::ACTION_STATUS,
        self::ACTION_DEV_PLAN,
        self::ACTION_DEV_SUMMARY,
        self::ACTION_FORGE_INTAKE,
        self::ACTION_FORGE_SUMMARY,
        self::ACTION_BLOCKERS,
        self::ACTION_NEXT_ACTIONS,
        self::ACTION_EVIDENCE,
        self::ACTION_CERTIFICATION,
        self::ACTION_TELEMETRY,
        self::ACTION_SMOKE,
        self::ACTION_LONG_HORIZON_STATUS,
        self::ACTION_LONG_HORIZON_COMPACT,
        self::ACTION_LONG_HORIZON_CONTINUE,
        self::ACTION_LONG_HORIZON_CERTIFY,
    ];

    public const STATUS_GREEN = 'green';

    public const STATUS_PARTIAL = 'partial';

    public const STATUS_BLOCKED = 'blocked';

    /** @var array<int,string> */
    public const STATUSES = [
        self::STATUS_GREEN,
        self::STATUS_PARTIAL,
        self::STATUS_BLOCKED,
    ];

    public const CORE_DEV = 'atlas_dev';

    public const CORE_FORGE = 'atlas_forge';

    public const CORE_DEV_TO_FORGE = 'atlas_dev_to_forge';

    public const CORE_NONE = 'n/a';

    /** @var array<int,string> */
    public const CORES = [
        self::CORE_DEV,
        self::CORE_FORGE,
        self::CORE_DEV_TO_FORGE,
        self::CORE_NONE,
    ];

    public const CERTIFICATION_STATUS_PASSED = 'passed';

    public const CERTIFICATION_STATUS_PARTIAL = 'partial';

    public const CERTIFICATION_STATUS_BLOCKED = 'blocked';

    public const CERTIFICATION_STATUS_FAILED = 'failed';

    public const CERTIFICATION_STATUS_NOT_EVALUATED = 'not_evaluated';

    /** @var array<int,string> */
    public const CERTIFICATION_STATUSES = [
        self::CERTIFICATION_STATUS_PASSED,
        self::CERTIFICATION_STATUS_PARTIAL,
        self::CERTIFICATION_STATUS_BLOCKED,
        self::CERTIFICATION_STATUS_FAILED,
        self::CERTIFICATION_STATUS_NOT_EVALUATED,
    ];

    /**
     * Canonical envelope keys that every action must declare (even if empty)
     * so callers can parse output uniformly.
     *
     * @var array<int,string>
     */
    public const ENVELOPE_KEYS = [
        'schema_version',
        'action',
        'status',
        'ids',
        'selected_core',
        'flow',
        'payload',
        'evidence_refs',
        'blockers',
        'next_actions',
        'certification_status',
        'claim_policy',
        'note',
    ];
}
