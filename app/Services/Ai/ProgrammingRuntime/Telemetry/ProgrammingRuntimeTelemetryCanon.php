<?php

namespace App\Services\Ai\ProgrammingRuntime\Telemetry;

/**
 * Canonical enums for `atlas.programming.runtime_telemetry.*` schemas.
 *
 * Programming Runtime Telemetry captures internal measurements about Atlas
 * Dev + Atlas Forge flows. It is NOT a benchmark, NOT a rival comparison and
 * NEVER carries provider/operator secrets. Every aggregate response surfaces
 * `benchmark_not_run: true` so downstream consumers cannot mistake telemetry
 * for benchmark evidence.
 */
final class ProgrammingRuntimeTelemetryCanon
{
    public const EVENT_SCHEMA_VERSION = 'atlas.programming.runtime_telemetry.event.v1';

    public const AGGREGATE_SCHEMA_VERSION = 'atlas.programming.runtime_telemetry.aggregate.v1';

    public const SELECTED_CORE_DEV = 'atlas_dev';

    public const SELECTED_CORE_FORGE = 'atlas_forge';

    public const SELECTED_CORE_DEV_TO_FORGE = 'atlas_dev_to_forge';

    public const SELECTED_CORES = [
        self::SELECTED_CORE_DEV,
        self::SELECTED_CORE_FORGE,
        self::SELECTED_CORE_DEV_TO_FORGE,
    ];

    public const CANONICAL_EVENT_NAMES = [
        'flow_selected',
        'route_decision_recorded',
        'rag_sufficiency_evaluated',
        'retrieval_sources_observed',
        'execution_outcome_recorded',
        'test_outcome_recorded',
        'repair_attempt_recorded',
        'forge_escalation_recorded',
        'work_packet_status_changed',
        'evidence_completeness_evaluated',
        'certification_status_evaluated',
        'blockers_observed',
        'runtime_record_completed',
    ];

    public const RAG_GATE_STATUSES = ['passed', 'degraded', 'failed_closed', 'skipped'];

    public const EXECUTION_STATUSES = [
        'passed',
        'failed',
        'inconclusive',
        'blocked',
        'needs_review',
        'in_progress',
        'recorded',
        'delegated',
        'ready_for_provider',
        'escalated',
    ];

    public const TEST_STATUSES = ['passed', 'failed', 'flaky', 'skipped', 'not_run'];

    public const CERTIFICATION_STATUSES = ['passed', 'blocked', 'partial', 'failed', 'not_evaluated'];
}
