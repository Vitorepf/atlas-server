<?php

namespace App\Services\Ai\OperatorApproval;

/**
 * Atlas AI Operator Approval Gate · canonical enums.
 *
 * Single source of truth for gate_mode / status / operator_decision /
 * action prefixes / risk_level. Mantém paridade com PolicyCanon onde faz
 * sentido reusar (RISK_LEVELS) e adiciona o léxico próprio do Operator Gate
 * (modos canônicos de gate, decisões de operador, prefixos de ação).
 *
 * Esta camada é "operator-facing": decide se Atlas pode agir sozinho ou se
 * precisa parar e perguntar/escalar. NÃO substitui PolicyCanon (que é
 * "policy/safety-facing", para tool/forbidden/budget). É construída acima
 * dela e reusa risk_level + decision concepts.
 */
final class OperatorApprovalCanon
{
    public const SCHEMA_VERSION = 'atlas.ai.operator_approval.v1';

    public const MODE_ALLOW_AUTO = 'allow_auto';

    public const MODE_REQUIRE_CONFIRMATION = 'require_confirmation';

    public const MODE_REQUIRE_REVIEW = 'require_review';

    public const MODE_BLOCK = 'block';

    public const MODE_ESCALATE_TO_FORGE = 'escalate_to_forge';

    public const MODES = [
        self::MODE_ALLOW_AUTO,
        self::MODE_REQUIRE_CONFIRMATION,
        self::MODE_REQUIRE_REVIEW,
        self::MODE_BLOCK,
        self::MODE_ESCALATE_TO_FORGE,
    ];

    public const RISK_LOW = 'low';

    public const RISK_MEDIUM = 'medium';

    public const RISK_HIGH = 'high';

    public const RISK_CRITICAL = 'critical';

    public const RISK_LEVELS = [
        self::RISK_LOW,
        self::RISK_MEDIUM,
        self::RISK_HIGH,
        self::RISK_CRITICAL,
    ];

    public const STATUS_AUTO_APPROVED = 'auto_approved';

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_DENIED = 'denied';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_AUTO_APPROVED,
        self::STATUS_PENDING,
        self::STATUS_APPROVED,
        self::STATUS_DENIED,
        self::STATUS_EXPIRED,
        self::STATUS_CANCELLED,
    ];

    public const DECISION_APPROVE = 'approve';

    public const DECISION_DENY = 'deny';

    public const DECISIONS = [
        self::DECISION_APPROVE,
        self::DECISION_DENY,
    ];

    public const ACTION_MISSION_HANDOFF_DEV = 'mission.handoff_dev';

    public const ACTION_MISSION_HANDOFF_FORGE = 'mission.handoff_forge';

    public const ACTION_MISSION_SIMULATED = 'mission.simulated_dispatch';

    public const ACTION_MISSION_CERTIFY = 'mission.certify';

    public const ACTION_TOOL_DESTRUCTIVE = 'tool.destructive';

    public const ACTION_TOOL_FILE_EDIT_MASS = 'tool.file_edit_mass';

    public const ACTION_FINANCE_TRADE = 'finance.trade';

    public const ACTION_FINANCE_TRANSFER = 'finance.transfer';

    public const ACTION_CYBER_ACTIVE = 'cyber.active_scan';

    public const ACTION_CYBER_EXPLOIT = 'cyber.exploit';

    public const ACTION_FORGE_OBRA = 'forge.obra';

    public const ACTION_EXPLAIN = 'explain';

    public const ACTION_RESEARCH = 'research';

    public const ACTION_CONVERSATION = 'conversation';

    public const DEFAULT_EXPIRY_MINUTES = 60;

    public const DEFAULT_REVIEW_EXPIRY_MINUTES = 240;

    /**
     * Modes that mean Atlas may continue without operator interaction.
     */
    public const PASSTHROUGH_MODES = [
        self::MODE_ALLOW_AUTO,
    ];

    /**
     * Modes that require the operator to make a decision before Atlas continues.
     */
    public const WAITING_MODES = [
        self::MODE_REQUIRE_CONFIRMATION,
        self::MODE_REQUIRE_REVIEW,
        self::MODE_ESCALATE_TO_FORGE,
    ];

    /**
     * Modes that hard-block execution even with approval.
     */
    public const BLOCKING_MODES = [
        self::MODE_BLOCK,
    ];

    /**
     * Verify gate_mode validity.
     */
    public static function isValidMode(string $mode): bool
    {
        return in_array($mode, self::MODES, true);
    }

    /**
     * Verify risk_level validity.
     */
    public static function isValidRisk(string $risk): bool
    {
        return in_array($risk, self::RISK_LEVELS, true);
    }
}
