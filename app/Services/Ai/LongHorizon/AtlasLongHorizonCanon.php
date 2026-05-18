<?php

declare(strict_types=1);

namespace App\Services\Ai\LongHorizon;

/**
 * Canonical enums and schema versions for the TEOS-I1 long-horizon
 * persistence layer (Mission 4 / Mission 5 / Mission 8 receipts).
 *
 * Only canonical values declared in `atlas-teos-increment-1-plan.md` and
 * `atlas-long-horizon-intelligence-layer.md` are reflected here. Adding a new
 * scope_type, safe_resume_mode, discarded_reason or loss_risk requires an
 * entry in those canon docs first.
 */
final class AtlasLongHorizonCanon
{
    public const CONTINUATION_PACK_SCHEMA_VERSION = 'atlas.long_horizon.continuation_pack.v2';

    public const COMPACTION_RECEIPT_SCHEMA_VERSION = 'atlas.long_horizon.compaction_receipt.v1';

    public const RECOVERY_PLAN_SCHEMA_VERSION = 'atlas.long_horizon.recovery_plan.v1';

    public const SCOPE_TYPE_MISSION = 'mission';

    public const SCOPE_TYPE_WORK_ORDER = 'work_order';

    public const SCOPE_TYPE_OBRA = 'obra';

    public const SCOPE_TYPE_DEV_RUN = 'dev_run';

    public const SCOPE_TYPE_DEV_SESSION = 'dev_session';

    public const SCOPE_TYPE_DEV_WORKSTREAM = 'dev_workstream';

    public const SCOPE_TYPE_FORGE_RUN = 'forge_run';

    public const SCOPE_TYPE_FORGE_OBRA = 'forge_obra';

    public const SCOPE_TYPE_WORK_PACKET = 'work_packet';

    public const SCOPE_TYPE_THREAD = 'thread';

    /**
     * Umbrella scope used by `AiCompactionService::compactForScope()` when
     * the caller wants a cross-scope long-horizon receipt that is not tied
     * to a single mission/obra/run. Kept inside ALLOWED_SCOPE_TYPES so the
     * canon stays the single source of truth.
     */
    public const SCOPE_TYPE_LONG_HORIZON = 'long_horizon';

    /** @var list<string> */
    public const ALLOWED_SCOPE_TYPES = [
        self::SCOPE_TYPE_MISSION,
        self::SCOPE_TYPE_WORK_ORDER,
        self::SCOPE_TYPE_OBRA,
        self::SCOPE_TYPE_DEV_RUN,
        self::SCOPE_TYPE_DEV_SESSION,
        self::SCOPE_TYPE_DEV_WORKSTREAM,
        self::SCOPE_TYPE_FORGE_RUN,
        self::SCOPE_TYPE_FORGE_OBRA,
        self::SCOPE_TYPE_WORK_PACKET,
        self::SCOPE_TYPE_THREAD,
        self::SCOPE_TYPE_LONG_HORIZON,
    ];

    /**
     * Item kinds that must NEVER be discarded silently during compaction.
     * Used by `AiCompactionService::compactForScope()` to force
     * `loss_risk=high` and `must_keep_coverage<1.0` whenever the caller
     * declares a forced_discard touching one of these kinds.
     *
     * Anti-pattern §24 of TEOS: compactação silenciosa que esconde
     * decisão / blocker / DoD / risco crítico.
     *
     * @var list<string>
     */
    public const CRITICAL_KEEP_KINDS = [
        'decision',
        'blocker',
        'dod',
        'risk_critical',
    ];

    /**
     * Canonical safe_resume_mode taxonomy for TEOS-I1 continuation packs.
     * Order matches severity progression: a pack can always be downgraded
     * but never upgraded silently.
     */
    public const SAFE_RESUME_EXECUTE = 'execute';

    public const SAFE_RESUME_READ_ONLY = 'read_only';

    public const SAFE_RESUME_REPAIR = 'repair';

    public const SAFE_RESUME_REVIEW = 'review';

    public const SAFE_RESUME_ASK_HUMAN = 'ask_human';

    public const SAFE_RESUME_BLOCKED = 'blocked';

    public const SAFE_RESUME_ESCALATE_TO_FORGE = 'escalate_to_forge';

    /** @var list<string> */
    public const ALLOWED_SAFE_RESUME_MODES = [
        self::SAFE_RESUME_EXECUTE,
        self::SAFE_RESUME_READ_ONLY,
        self::SAFE_RESUME_REPAIR,
        self::SAFE_RESUME_REVIEW,
        self::SAFE_RESUME_ASK_HUMAN,
        self::SAFE_RESUME_BLOCKED,
        self::SAFE_RESUME_ESCALATE_TO_FORGE,
    ];

    public const DISCARDED_REASON_EXPIRED = 'expired';

    public const DISCARDED_REASON_LOW_SIGNAL = 'low_signal';

    public const DISCARDED_REASON_BUDGET_PRESSURE = 'budget_pressure';

    public const DISCARDED_REASON_SUPERSEDED = 'superseded';

    /** @var list<string> */
    public const ALLOWED_DISCARDED_REASONS = [
        self::DISCARDED_REASON_EXPIRED,
        self::DISCARDED_REASON_LOW_SIGNAL,
        self::DISCARDED_REASON_BUDGET_PRESSURE,
        self::DISCARDED_REASON_SUPERSEDED,
    ];

    public const LOSS_RISK_LOW = 'low';

    public const LOSS_RISK_MEDIUM = 'medium';

    public const LOSS_RISK_HIGH = 'high';

    /** @var list<string> */
    public const ALLOWED_LOSS_RISKS = [
        self::LOSS_RISK_LOW,
        self::LOSS_RISK_MEDIUM,
        self::LOSS_RISK_HIGH,
    ];
}
