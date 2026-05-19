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

    // Canonical flow_id constants for each domain-aligned specialist flow.
    public const FLOW_CONVERSATION = 'atlas_conversation';

    public const FLOW_RESEARCH = 'atlas_research';

    public const FLOW_DEV = 'atlas_dev';

    public const FLOW_DEBUG = 'atlas_debug';

    public const FLOW_REVIEW = 'atlas_review';

    public const FLOW_EXPLAIN = 'atlas_explain';

    public const FLOW_PLAN = 'atlas_plan';

    public const FLOW_FORGE = 'atlas_forge';

    public const FLOW_FINANCE = 'atlas_finance';

    public const FLOW_MARKETING = 'atlas_marketing';

    public const FLOW_STRATEGY = 'atlas_strategy';

    public const FLOW_CYBER = 'atlas_cyber';

    public const FLOW_PERSONAL_DEVELOPMENT = 'atlas_personal_development';

    public const FLOW_AUTOMATION = 'atlas_automation';

    /**
     * Canonical flow_id mapping. Each non-programming domain now has a
     * dedicated specialist flow — finance/marketing/strategy/cyber/personal/
     * automation no longer fall back to `atlas_plan`. The fallback for
     * truly unknown intents stays `atlas_conversation`.
     */
    public const INTENT_TO_FLOW = [
        self::INTENT_CONVERSATION => self::FLOW_CONVERSATION,
        self::INTENT_RESEARCH => self::FLOW_RESEARCH,
        self::INTENT_PROGRAMMING => self::FLOW_DEV,
        self::INTENT_DEBUG => self::FLOW_DEBUG,
        self::INTENT_REVIEW => self::FLOW_REVIEW,
        self::INTENT_EXPLAIN => self::FLOW_EXPLAIN,
        self::INTENT_PLAN => self::FLOW_PLAN,
        self::INTENT_FINANCE => self::FLOW_FINANCE,
        self::INTENT_MARKETING => self::FLOW_MARKETING,
        self::INTENT_STRATEGY => self::FLOW_STRATEGY,
        self::INTENT_CYBER => self::FLOW_CYBER,
        self::INTENT_PERSONAL_DEVELOPMENT => self::FLOW_PERSONAL_DEVELOPMENT,
        self::INTENT_AUTOMATION => self::FLOW_AUTOMATION,
        self::INTENT_UNKNOWN => self::FLOW_CONVERSATION,
    ];

    /**
     * The 14 canonical specialist flow_ids. Used by SpecialistFlows registry
     * and asserted by tests — adding/removing requires updating the test
     * suite + canon doc.
     *
     * @var array<int,string>
     */
    public const ALLOWED_FLOW_IDS = [
        self::FLOW_CONVERSATION,
        self::FLOW_RESEARCH,
        self::FLOW_DEV,
        self::FLOW_DEBUG,
        self::FLOW_REVIEW,
        self::FLOW_EXPLAIN,
        self::FLOW_PLAN,
        self::FLOW_FORGE,
        self::FLOW_FINANCE,
        self::FLOW_MARKETING,
        self::FLOW_STRATEGY,
        self::FLOW_CYBER,
        self::FLOW_PERSONAL_DEVELOPMENT,
        self::FLOW_AUTOMATION,
    ];

    /**
     * Programming-anchored flow_ids. Non-programming specialist handlers
     * MUST refuse to delegate to these flows; otherwise finance/marketing/
     * cyber traffic could silently land inside Atlas Dev runtime.
     *
     * @var array<int,string>
     */
    public const PROGRAMMING_FLOW_IDS = [
        self::FLOW_DEV,
        self::FLOW_FORGE,
        self::FLOW_DEBUG,
        self::FLOW_REVIEW,
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
