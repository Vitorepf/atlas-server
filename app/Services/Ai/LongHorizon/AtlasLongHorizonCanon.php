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

    public const REPLAY_MANIFEST_SCHEMA_VERSION = 'atlas.long_horizon.replay_manifest.v1';

    public const REPLAY_READER_SCHEMA_VERSION = 'atlas.long_horizon.replay_reader_bundle.v1';

    /**
     * TEOS-I2 · Causal Decision Graph Lite. Read-model (sem tabela) que
     * compõe um grafo causal navegável sobre Mission Foundation +
     * Forge intake + Router Decisions + Evidence/Certifications.
     */
    public const CAUSAL_GRAPH_LITE_SCHEMA_VERSION = 'atlas.long_horizon.causal_graph_lite.v1';

    /**
     * TEOS-I3 · Strategic Forgetting. Read-only receipt over durable memory
     * entries deciding whether to retain, compress, archive, demote, expire,
     * supersede or forget. The service never deletes by default.
     */
    public const STRATEGIC_FORGETTING_RECEIPT_SCHEMA_VERSION = 'atlas.teos.strategic_forgetting_receipt.v1';

    public const OBRA_REVIEW_RECEIPT_SCHEMA_VERSION = 'atlas.teos.obra_review_receipt.v1';

    public const OPERATOR_ATTENTION_QUEUE_SCHEMA_VERSION = 'atlas.teos.operator_attention_queue.v1';

    public const TIME_AWARE_WORLD_MODEL_SCHEMA_VERSION = 'atlas.teos.time_aware_world_model.v1';

    public const ATTENTION_SEVERITY_CRITICAL = 'critical';

    public const ATTENTION_SEVERITY_HIGH = 'high';

    public const ATTENTION_SEVERITY_MEDIUM = 'medium';

    public const ATTENTION_SEVERITY_LOW = 'low';

    /** @var list<string> */
    public const OPERATOR_ATTENTION_SEVERITIES = [
        self::ATTENTION_SEVERITY_CRITICAL,
        self::ATTENTION_SEVERITY_HIGH,
        self::ATTENTION_SEVERITY_MEDIUM,
        self::ATTENTION_SEVERITY_LOW,
    ];

    public const FORGETTING_POLICY_RETAIN = 'retain';

    public const FORGETTING_POLICY_COMPRESS = 'compress';

    public const FORGETTING_POLICY_ARCHIVE = 'archive';

    public const FORGETTING_POLICY_DEMOTE = 'demote';

    public const FORGETTING_POLICY_EXPIRE = 'expire';

    public const FORGETTING_POLICY_SUPERSEDE = 'supersede';

    public const FORGETTING_POLICY_FORGET = 'forget';

    public const OBRA_REVIEW_WEEKLY_SYNTHESIS = 'weekly_synthesis';

    public const OBRA_REVIEW_MONTHLY_ARCHITECTURE = 'monthly_architecture_review';

    public const OBRA_REVIEW_DECISION_CONTINUE = 'continue';

    public const OBRA_REVIEW_DECISION_ADJUST_SCOPE = 'adjust_scope';

    public const OBRA_REVIEW_DECISION_PAUSE = 'pause';

    public const OBRA_REVIEW_DECISION_CLOSE = 'close';

    /** @var list<string> */
    public const OBRA_REVIEW_DECISIONS = [
        self::OBRA_REVIEW_DECISION_CONTINUE,
        self::OBRA_REVIEW_DECISION_ADJUST_SCOPE,
        self::OBRA_REVIEW_DECISION_PAUSE,
        self::OBRA_REVIEW_DECISION_CLOSE,
    ];

    /** @var list<string> */
    public const STRATEGIC_FORGETTING_POLICIES = [
        self::FORGETTING_POLICY_RETAIN,
        self::FORGETTING_POLICY_COMPRESS,
        self::FORGETTING_POLICY_ARCHIVE,
        self::FORGETTING_POLICY_DEMOTE,
        self::FORGETTING_POLICY_EXPIRE,
        self::FORGETTING_POLICY_SUPERSEDE,
        self::FORGETTING_POLICY_FORGET,
    ];

    /* ------------------------------------------------------------ */
    /* Causal Graph Lite · node + edge taxonomy */
    /* ------------------------------------------------------------ */

    public const CAUSAL_NODE_DECISION = 'decision';

    public const CAUSAL_NODE_RECEIPT = 'receipt';

    public const CAUSAL_NODE_WORK_PACKET = 'work_packet';

    public const CAUSAL_NODE_MISSION_STEP = 'mission_step';

    public const CAUSAL_NODE_FILE_REF = 'file_ref';

    public const CAUSAL_NODE_TEST = 'test';

    public const CAUSAL_NODE_REPAIR = 'repair';

    public const CAUSAL_NODE_BLOCKER = 'blocker';

    public const CAUSAL_NODE_CERTIFICATION = 'certification';

    /** @var list<string> */
    public const ALLOWED_CAUSAL_NODE_KINDS = [
        self::CAUSAL_NODE_DECISION,
        self::CAUSAL_NODE_RECEIPT,
        self::CAUSAL_NODE_WORK_PACKET,
        self::CAUSAL_NODE_MISSION_STEP,
        self::CAUSAL_NODE_FILE_REF,
        self::CAUSAL_NODE_TEST,
        self::CAUSAL_NODE_REPAIR,
        self::CAUSAL_NODE_BLOCKER,
        self::CAUSAL_NODE_CERTIFICATION,
    ];

    public const CAUSAL_EDGE_CAUSED = 'caused';

    public const CAUSAL_EDGE_DEPENDS_ON = 'depends_on';

    public const CAUSAL_EDGE_VERIFIED_BY = 'verified_by';

    public const CAUSAL_EDGE_REPAIRED_BY = 'repaired_by';

    public const CAUSAL_EDGE_BLOCKED_BY = 'blocked_by';

    public const CAUSAL_EDGE_SUPERSEDED_BY = 'superseded_by';

    /** @var list<string> */
    public const ALLOWED_CAUSAL_EDGE_KINDS = [
        self::CAUSAL_EDGE_CAUSED,
        self::CAUSAL_EDGE_DEPENDS_ON,
        self::CAUSAL_EDGE_VERIFIED_BY,
        self::CAUSAL_EDGE_REPAIRED_BY,
        self::CAUSAL_EDGE_BLOCKED_BY,
        self::CAUSAL_EDGE_SUPERSEDED_BY,
    ];

    /**
     * Scope types que `LongHorizonCausalDecisionGraphService::build()`
     * aceita hoje. Subset estrito de ALLOWED_SCOPE_TYPES — adicionar
     * outros exige código novo no service + teste.
     *
     * @var list<string>
     */
    public const CAUSAL_GRAPH_LITE_ALLOWED_SCOPES = [
        self::SCOPE_TYPE_MISSION,
        self::SCOPE_TYPE_WORK_ORDER,
        self::SCOPE_TYPE_OBRA,
        self::SCOPE_TYPE_FORGE_OBRA,
    ];

    /**
     * Replay manifest status taxonomy. The status reflects how an arbitrary
     * provider can consume the manifest:
     *   - `ready`            — all required refs available; reader can resume.
     *   - `partial`          — some required refs missing; reader should
     *                          downgrade to read_only/review.
     *   - `blocked`          — required refs missing AND continuation pack
     *                          flagged stale/blocked; reader must ask human.
     *   - `requires_recovery` — recovery_queries from compaction receipt are
     *                          still open; reader should replay them first.
     */
    public const REPLAY_STATUS_READY = 'ready';

    public const REPLAY_STATUS_PARTIAL = 'partial';

    public const REPLAY_STATUS_BLOCKED = 'blocked';

    public const REPLAY_STATUS_REQUIRES_RECOVERY = 'requires_recovery';

    /** @var list<string> */
    public const ALLOWED_REPLAY_STATUSES = [
        self::REPLAY_STATUS_READY,
        self::REPLAY_STATUS_PARTIAL,
        self::REPLAY_STATUS_BLOCKED,
        self::REPLAY_STATUS_REQUIRES_RECOVERY,
    ];

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
