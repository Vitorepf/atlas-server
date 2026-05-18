<?php

namespace App\Services\Ai\ToolRuntime;

class ToolRuntimeCanon
{
    public const TOOL_TYPES = [
        'internal',
        'cli',
        'api',
        'browser',
        'github',
        'filesystem',
        'database',
        'mcp',
        'external',
        'generated',
    ];

    public const AUTHORITY_GROUPS = [
        'read_only',
        'draft',
        'local_mutation',
        'external_action',
        'financial_action',
        'security_sensitive',
    ];

    public const RISK_LEVELS = ['low', 'medium', 'high', 'critical'];

    public const TOOL_STATUS = ['active', 'disabled', 'deprecated', 'blocked'];

    public const INVOCATION_STATUS = [
        'planned',
        'allowed',
        'denied',
        'running',
        'succeeded',
        'failed',
        'blocked',
    ];

    public const HEALTH_STATUS = ['healthy', 'degraded', 'failed', 'unknown'];

    public const VALIDATION_STATUS = ['passed', 'failed', 'blocked', 'skipped'];

    public const HIGH_RISK_AUTHORITY_GROUPS = [
        'external_action',
        'financial_action',
        'security_sensitive',
    ];

    public static function isHighRiskAuthority(string $authorityGroup): bool
    {
        return in_array($authorityGroup, self::HIGH_RISK_AUTHORITY_GROUPS, true);
    }

    public static function isReadOnlyAuthority(string $authorityGroup): bool
    {
        return $authorityGroup === 'read_only';
    }
}
