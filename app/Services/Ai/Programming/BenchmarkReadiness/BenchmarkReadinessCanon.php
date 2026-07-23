<?php

namespace App\Services\Ai\Programming\BenchmarkReadiness;


/**
 * Canonical enums for the Atlas Programming Benchmark Readiness Harness.
 *
 * The harness PREPARES a benchmark suite (manifest + scoring rubric +
 * readiness checks). It NEVER executes the suite, NEVER calls Claude Code or
 * Codex, NEVER produces a superiority claim. Every output is tagged
 * `status = benchmark_not_run` and carries `human_authorization_required:true`.
 *
 * Wiring a real execution runtime is a future slice (M10 in the programming
 * superiority roadmap). Until then, attempting `run()` is impossible by design.
 */
final class BenchmarkReadinessCanon
{
    public const SUITE_SCHEMA_VERSION = 'atlas.programming.benchmark_suite.readiness.v1';

    public const CASE_SCHEMA_VERSION = 'atlas.programming.benchmark_case.readiness.v1';

    public const RUBRIC_SCHEMA_VERSION = 'atlas.programming.benchmark_scoring_rubric.v1';

    public const STATUS_NOT_RUN = 'benchmark_not_run';

    public const STATUS_READINESS_ONLY = 'readiness_only';

    /** @var array<int,string> */
    public const STATUSES = [
        self::STATUS_NOT_RUN,
        self::STATUS_READINESS_ONLY,
    ];

    public const CASE_TYPE_BUG_FIX = 'bug_fix';

    public const CASE_TYPE_FEATURE = 'feature';

    public const CASE_TYPE_DEBUG = 'debug';

    public const CASE_TYPE_REVIEW = 'review';

    public const CASE_TYPE_RESEARCH = 'research';

    public const CASE_TYPE_FORGE_OBRA = 'forge_obra';

    public const CASE_TYPE_LONG_HORIZON_CONTINUATION = 'long_horizon_continuation';

    public const CASE_TYPE_REPAIR_LOOP = 'repair_loop';

    /** @var array<int,string> */
    public const CASE_TYPES = [
        self::CASE_TYPE_BUG_FIX,
        self::CASE_TYPE_FEATURE,
        self::CASE_TYPE_DEBUG,
        self::CASE_TYPE_REVIEW,
        self::CASE_TYPE_RESEARCH,
        self::CASE_TYPE_FORGE_OBRA,
        self::CASE_TYPE_LONG_HORIZON_CONTINUATION,
        self::CASE_TYPE_REPAIR_LOOP,
    ];

    /**
     * Eight canonical scoring dimensions. Every rubric must weight at least
     * one of these; weights must sum to 1.0 (±0.001 tolerance).
     *
     * @var array<int,string>
     */
    public const SCORING_DIMENSIONS = [
        'correctness',
        'evidence_completeness',
        'context_sufficiency',
        'repair_efficiency',
        'scope_compliance',
        'test_coverage',
        'completion_honesty',
        'safety',
    ];

    /** @var array<int,string> */
    public const REQUIRED_TELEMETRY_SIGNALS = [
        'flow_id',
        'route_decision_id',
        'rag_gate_status',
        'retrieval_receipt_id',
        'patch_verifier_status',
        'test_outcome',
        'repair_loop_status',
        'evidence_completeness',
        'context_sufficiency',
        'certification_status',
        'cycle_hash',
    ];

    /** @var array<int,string> */
    public const REQUIRED_EVIDENCE_KINDS = [
        'plan',
        'context_pack',
        'work_packet_receipts',
        'verification_receipt',
        'evidence_pack',
        'certification',
    ];

    public const PROVIDER_SLOT_ATLAS = 'atlas';

    public const PROVIDER_SLOT_DEV = 'dev';

    public const PROVIDER_SLOT_FORGE = 'forge';

    public const PROVIDER_SLOT_RIVAL_PLACEHOLDER = 'rival_placeholder';

    /**
     * Canonical provider slot ordering. Rival placeholder is intentionally
     * present so a future authorized slice can plug in Claude Code / Codex —
     * but in THIS slice the placeholder is `not_run` and never invoked.
     *
     * @var array<int,string>
     */
    public const PROVIDER_SLOTS = [
        self::PROVIDER_SLOT_ATLAS,
        self::PROVIDER_SLOT_DEV,
        self::PROVIDER_SLOT_FORGE,
        self::PROVIDER_SLOT_RIVAL_PLACEHOLDER,
    ];

    public const RISK_BAND_LOW = 'low';

    public const RISK_BAND_MEDIUM = 'medium';

    public const RISK_BAND_HIGH = 'high';

    public const RISK_BAND_CRITICAL = 'critical';

    /** @var array<int,string> */
    public const RISK_BANDS = [
        self::RISK_BAND_LOW,
        self::RISK_BAND_MEDIUM,
        self::RISK_BAND_HIGH,
        self::RISK_BAND_CRITICAL,
    ];

    /** @var array<int,string> */
    public const DIFFICULTIES = ['L1', 'L2', 'L3', 'L4', 'L5'];

    public const READINESS_PASSED = 'passed';

    public const READINESS_PARTIAL = 'partial';

    public const READINESS_BLOCKED = 'blocked';

    /** @var array<int,string> */
    public const READINESS_STATUSES = [
        self::READINESS_PASSED,
        self::READINESS_PARTIAL,
        self::READINESS_BLOCKED,
    ];

    /**
     * Read-only tool bundles. The harness never declares destructive or
     * provider-invocation tools — execution is out of scope.
     *
     * @return array<string,array<int,string>>
     */
    public static function toolBundles(): array
    {
        $read = ['code.intelligence.read', 'docs.read', 'tests.read', 'git.read'];

        return [
            'read_only' => $read,
            'patch_proposal' => array_merge($read, ['code.patch.propose']),
            'debug_sandbox' => array_merge($read, ['tests.run.sandbox', 'logs.read']),
            'forge_obra' => array_merge($read, ['code.patch.propose', 'work_packet.advance']),
            'long_horizon' => array_merge($read, ['code.patch.propose', 'work_packet.advance', 'state.checkpoint']),
            'repair_loop' => array_merge($read, ['code.patch.propose', 'tests.run.sandbox', 'logs.read']),
            'review' => array_merge($read, ['diff.read']),
            'research' => array_merge($read, ['web.read.read_only']),
        ];
    }

    /**
     * Authorization tokens that any future runtime would require. The harness
     * checks the SHAPE only — it has no way to verify the operator's actual
     * intent in this slice.
     *
     * @var array<int,string>
     */
    public const REQUIRED_AUTHORIZATION_FIELDS = [
        'external_battery_authorized',
        'human_operator_signature',
        'authorization_reason',
        'expires_at',
    ];
}
