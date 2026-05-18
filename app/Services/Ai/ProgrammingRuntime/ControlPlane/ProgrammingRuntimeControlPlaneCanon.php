<?php

namespace App\Services\Ai\ProgrammingRuntime\ControlPlane;

/**
 * Canonical enums for the Programming Runtime Control Plane read model.
 *
 * The control plane is observation only — it NEVER mutates runtime state,
 * NEVER runs a benchmark and NEVER compares Atlas against rival providers.
 * `benchmark_status.not_run` is always `true` until a human-authorised
 * external battery flips it.
 */
final class ProgrammingRuntimeControlPlaneCanon
{
    public const SCHEMA_VERSION = 'atlas.programming.runtime_control_plane.v1';

    public const STATUS_GREEN = 'green';

    public const STATUS_PARTIAL = 'partial';

    public const STATUS_BLOCKED = 'blocked';

    public const SEVERITY_INFO = 'info';

    public const SEVERITY_WARN = 'warn';

    public const SEVERITY_BLOCKER = 'blocker';

    public const SEVERITY_CRITICAL = 'critical';

    public const DEV_FLOW_IDS = [
        'atlas_dev',
        'atlas_debug',
        'atlas_review',
        'atlas_research',
    ];

    public const FORGE_FLOW_IDS = [
        'atlas_forge',
    ];

    public const ESCALATION_FLOW_IDS = [
        'atlas_dev_to_forge',
    ];

    /**
     * Mission statuses considered "active" for the control plane.
     */
    public const ACTIVE_MISSION_STATUSES = [
        'pending',
        'planning',
        'planned',
        'in_progress',
        'running',
        'blocked',
        'awaiting_review',
        'awaiting_certification',
        'awaiting_evidence',
    ];

    public const RECENT_LIMIT = 5;
}
