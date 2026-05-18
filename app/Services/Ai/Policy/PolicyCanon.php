<?php

namespace App\Services\Ai\Policy;

/**
 * Canonical enums shared by policy services. Single source of truth so tests,
 * services, command and docs agree on allowed values.
 */
final class PolicyCanon
{
    public const SCOPE_GLOBAL = 'global';

    public const SCOPE_DOMAIN = 'domain';

    public const SCOPE_MISSION = 'mission';

    public const SCOPE_WORK_ORDER = 'work_order';

    public const SCOPE_TOOL = 'tool';

    public const SCOPES = [
        self::SCOPE_GLOBAL,
        self::SCOPE_DOMAIN,
        self::SCOPE_MISSION,
        self::SCOPE_WORK_ORDER,
        self::SCOPE_TOOL,
    ];

    public const AUTONOMY_SUGGEST = 'suggest';

    public const AUTONOMY_DRAFT = 'draft';

    public const AUTONOMY_EXECUTE_WITH_APPROVAL = 'execute_with_approval';

    public const AUTONOMY_AUTONOMOUS = 'autonomous';

    public const AUTONOMY_LEVELS = [
        self::AUTONOMY_SUGGEST,
        self::AUTONOMY_DRAFT,
        self::AUTONOMY_EXECUTE_WITH_APPROVAL,
        self::AUTONOMY_AUTONOMOUS,
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

    public const DECISION_ALLOW = 'allow';

    public const DECISION_DENY = 'deny';

    public const DECISION_REQUIRE_APPROVAL = 'require_approval';

    public const DECISION_BLOCKED = 'blocked';

    public const DECISIONS = [
        self::DECISION_ALLOW,
        self::DECISION_DENY,
        self::DECISION_REQUIRE_APPROVAL,
        self::DECISION_BLOCKED,
    ];

    public const APPROVAL_PENDING = 'pending';

    public const APPROVAL_APPROVED = 'approved';

    public const APPROVAL_REJECTED = 'rejected';

    public const APPROVAL_EXPIRED = 'expired';

    public const APPROVAL_CANCELLED = 'cancelled';

    public const APPROVAL_STATUSES = [
        self::APPROVAL_PENDING,
        self::APPROVAL_APPROVED,
        self::APPROVAL_REJECTED,
        self::APPROVAL_EXPIRED,
        self::APPROVAL_CANCELLED,
    ];

    public const RISK_RANK = [
        self::RISK_LOW => 1,
        self::RISK_MEDIUM => 2,
        self::RISK_HIGH => 3,
        self::RISK_CRITICAL => 4,
    ];

    public static function riskExceeds(string $candidate, string $tolerance): bool
    {
        return (self::RISK_RANK[$candidate] ?? 0) > (self::RISK_RANK[$tolerance] ?? 0);
    }
}
