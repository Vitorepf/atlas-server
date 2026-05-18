<?php

namespace App\Services\Ai\RouterRuntime;

/**
 * Canonical enums for the Meta 6 Router/Runtime Dispatch pipeline. Single
 * source of truth shared by services, command, tests and docs.
 */
final class RouterRuntimeCanon
{
    // Intent types
    public const INTENT_CONVERSATION = 'conversation';

    public const INTENT_RESEARCH = 'research';

    public const INTENT_PROGRAMMING = 'programming';

    public const INTENT_DEBUG = 'debug';

    public const INTENT_REVIEW = 'review';

    public const INTENT_EXPLAIN = 'explain';

    public const INTENT_PLAN = 'plan';

    public const INTENT_FINANCE = 'finance';

    public const INTENT_MARKETING = 'marketing';

    public const INTENT_STRATEGY = 'strategy';

    public const INTENT_CYBER = 'cyber';

    public const INTENT_PERSONAL_DEVELOPMENT = 'personal_development';

    public const INTENT_AUTOMATION = 'automation';

    public const INTENT_UNKNOWN = 'unknown';

    public const INTENT_TYPES = [
        self::INTENT_CONVERSATION,
        self::INTENT_RESEARCH,
        self::INTENT_PROGRAMMING,
        self::INTENT_DEBUG,
        self::INTENT_REVIEW,
        self::INTENT_EXPLAIN,
        self::INTENT_PLAN,
        self::INTENT_FINANCE,
        self::INTENT_MARKETING,
        self::INTENT_STRATEGY,
        self::INTENT_CYBER,
        self::INTENT_PERSONAL_DEVELOPMENT,
        self::INTENT_AUTOMATION,
        self::INTENT_UNKNOWN,
    ];

    // Routing modes
    public const MODE_LIGHTWEIGHT = 'lightweight';

    public const MODE_STANDARD = 'standard';

    public const MODE_DEEP = 'deep';

    public const MODE_FORGE = 'forge';

    public const MODE_BLOCKED = 'blocked';

    public const ROUTING_MODES = [
        self::MODE_LIGHTWEIGHT,
        self::MODE_STANDARD,
        self::MODE_DEEP,
        self::MODE_FORGE,
        self::MODE_BLOCKED,
    ];

    // Dispatch statuses
    public const DISPATCH_PLANNED = 'planned';

    public const DISPATCH_DISPATCHED = 'dispatched';

    public const DISPATCH_SIMULATED = 'simulated';

    public const DISPATCH_BLOCKED = 'blocked';

    public const DISPATCH_FAILED = 'failed';

    public const DISPATCH_COMPLETED = 'completed';

    public const DISPATCH_STATUSES = [
        self::DISPATCH_PLANNED,
        self::DISPATCH_DISPATCHED,
        self::DISPATCH_SIMULATED,
        self::DISPATCH_BLOCKED,
        self::DISPATCH_FAILED,
        self::DISPATCH_COMPLETED,
    ];

    // Receipt types
    public const RECEIPT_ROUTER_DECISION = 'router_decision';

    public const RECEIPT_RUNTIME_DISPATCH = 'runtime_dispatch';

    public const RECEIPT_TYPES = [
        self::RECEIPT_ROUTER_DECISION,
        self::RECEIPT_RUNTIME_DISPATCH,
    ];

    /**
     * Mapping from intent type to canonical primary domain. The router uses
     * this when no explicit domain hint is provided.
     */
    public const INTENT_TO_DOMAIN = [
        self::INTENT_CONVERSATION => 'conversation',
        self::INTENT_RESEARCH => 'research',
        self::INTENT_PROGRAMMING => 'programming',
        self::INTENT_DEBUG => 'programming',
        self::INTENT_REVIEW => 'programming',
        self::INTENT_EXPLAIN => 'explain',
        self::INTENT_PLAN => 'strategy',
        self::INTENT_FINANCE => 'finance',
        self::INTENT_MARKETING => 'marketing',
        self::INTENT_STRATEGY => 'strategy',
        self::INTENT_CYBER => 'cyber',
        self::INTENT_PERSONAL_DEVELOPMENT => 'personal_development',
        self::INTENT_AUTOMATION => 'automation',
        self::INTENT_UNKNOWN => 'conversation',
    ];

    /**
     * Canonical flow_id mapping aligned with the Router Runtime Enterprise
     * Upgrade contract: `atlas_dev`, `atlas_research`, `atlas_debug`,
     * `atlas_review`, `atlas_plan`, `atlas_explain`, `atlas_conversation`,
     * `atlas_forge`.
     */
    public const INTENT_TO_FLOW = [
        self::INTENT_CONVERSATION => 'atlas_conversation',
        self::INTENT_RESEARCH => 'atlas_research',
        self::INTENT_PROGRAMMING => 'atlas_dev',
        self::INTENT_DEBUG => 'atlas_debug',
        self::INTENT_REVIEW => 'atlas_review',
        self::INTENT_EXPLAIN => 'atlas_explain',
        self::INTENT_PLAN => 'atlas_plan',
        self::INTENT_FINANCE => 'atlas_plan',
        self::INTENT_MARKETING => 'atlas_plan',
        self::INTENT_STRATEGY => 'atlas_plan',
        self::INTENT_CYBER => 'atlas_plan',
        self::INTENT_PERSONAL_DEVELOPMENT => 'atlas_plan',
        self::INTENT_AUTOMATION => 'atlas_plan',
        self::INTENT_UNKNOWN => 'atlas_conversation',
    ];

    /**
     * High-risk domains that always require policy gate.
     */
    public const HIGH_RISK_DOMAINS = [
        'finance',
        'cyber',
        'automation',
        'marketing',
    ];

    /**
     * Domains that require evidence gate when dispatching a runtime.
     */
    public const EVIDENCE_REQUIRED_DOMAINS = [
        'programming',
        'research',
        'cyber',
        'finance',
    ];

    /**
     * Domains that typically require a tool plan before dispatch.
     */
    public const TOOL_PLAN_REQUIRED_DOMAINS = [
        'automation',
        'cyber',
        'research',
    ];
}
