<?php

namespace App\Services\Ai\AutomationDomain;

/**
 * Canonical enums for the Atlas Automation / Tool Factory Runtime (Meta 8 · Automation).
 *
 * Atlas Automation NEVER executes a real external action by itself. It plans,
 * scores alternatives, builds blueprints and observes tool lifecycle events.
 * Real execution requires Policy approval (`AiSafetyDecision::decision=allow`)
 * and Tool Runtime invocation (`AiToolInvocation`), which live outside this
 * domain.
 */
final class AutomationDomainCanon
{
    public const DOMAIN_ID = 'automation';

    /* Run kinds */
    public const RUN_BROWSER = 'browser_automation';

    public const RUN_API = 'api_automation';

    public const RUN_TERMINAL = 'terminal_automation';

    public const RUN_TOOL_BUILD = 'tool_build';

    public const RUN_TOOL_EVOLUTION = 'tool_evolution';

    public const RUN_REPO_EVALUATION = 'repo_evaluation';

    public const RUN_KINDS = [
        self::RUN_BROWSER,
        self::RUN_API,
        self::RUN_TERMINAL,
        self::RUN_TOOL_BUILD,
        self::RUN_TOOL_EVOLUTION,
        self::RUN_REPO_EVALUATION,
    ];

    /* Run statuses */
    public const STATUS_OPEN = 'open';

    public const STATUS_PLANNED = 'planned';

    public const STATUS_BLOCKED = 'blocked';

    public const STATUS_CLOSED = 'closed';

    public const STATUS_FAILED = 'failed';

    public const RUN_STATUSES = [
        self::STATUS_OPEN,
        self::STATUS_PLANNED,
        self::STATUS_BLOCKED,
        self::STATUS_CLOSED,
        self::STATUS_FAILED,
    ];

    /* Plan types */
    public const PLAN_BROWSER = 'browser';

    public const PLAN_API = 'api';

    public const PLAN_TERMINAL = 'terminal';

    public const PLAN_TOOL_BUILDER = 'tool_builder';

    public const PLAN_EVOLUTION = 'evolution';

    public const PLAN_TYPES = [
        self::PLAN_BROWSER,
        self::PLAN_API,
        self::PLAN_TERMINAL,
        self::PLAN_TOOL_BUILDER,
        self::PLAN_EVOLUTION,
    ];

    /* Plan statuses */
    public const PLAN_STATUS_PLANNED = 'planned';

    public const PLAN_STATUS_APPROVED = 'approved';

    public const PLAN_STATUS_BLOCKED = 'blocked';

    public const PLAN_STATUS_REJECTED = 'rejected';

    public const PLAN_STATUSES = [
        self::PLAN_STATUS_PLANNED,
        self::PLAN_STATUS_APPROVED,
        self::PLAN_STATUS_BLOCKED,
        self::PLAN_STATUS_REJECTED,
    ];

    /* Tool decision kinds (aligned with Tool Economy doc) */
    public const DECISION_USE_EXISTING = 'use_existing';

    public const DECISION_API_CALL = 'api_call';

    public const DECISION_BUY_OR_SUBSCRIBE = 'buy_or_subscribe';

    public const DECISION_CLONE_REPO = 'clone_repo';

    public const DECISION_ADAPT_OPEN_SOURCE = 'adapt_open_source';

    public const DECISION_BUILD_INTERNAL = 'build_internal';

    public const DECISION_MANUAL_FALLBACK = 'manual_fallback';

    public const DECISION_DO_NOT_USE = 'do_not_use';

    public const DECISION_KINDS = [
        self::DECISION_USE_EXISTING,
        self::DECISION_API_CALL,
        self::DECISION_BUY_OR_SUBSCRIBE,
        self::DECISION_CLONE_REPO,
        self::DECISION_ADAPT_OPEN_SOURCE,
        self::DECISION_BUILD_INTERNAL,
        self::DECISION_MANUAL_FALLBACK,
        self::DECISION_DO_NOT_USE,
    ];

    /* Evolution event kinds */
    public const EVOLUTION_SUCCESS_STREAK = 'success_streak';

    public const EVOLUTION_FAILURE_SPIKE = 'failure_spike';

    public const EVOLUTION_DECAY = 'decay';

    public const EVOLUTION_NEW_VERSION_AVAILABLE = 'new_version_available';

    public const EVOLUTION_RETIRE = 'retire';

    public const EVOLUTION_PROMOTE = 'promote';

    public const EVOLUTION_EVENT_KINDS = [
        self::EVOLUTION_SUCCESS_STREAK,
        self::EVOLUTION_FAILURE_SPIKE,
        self::EVOLUTION_DECAY,
        self::EVOLUTION_NEW_VERSION_AVAILABLE,
        self::EVOLUTION_RETIRE,
        self::EVOLUTION_PROMOTE,
    ];

    public const EVOLUTION_RECOMMENDATION_KEEP = 'keep';

    public const EVOLUTION_RECOMMENDATION_MONITOR = 'monitor';

    public const EVOLUTION_RECOMMENDATION_UPDATE = 'update';

    public const EVOLUTION_RECOMMENDATION_REPLACE = 'replace';

    public const EVOLUTION_RECOMMENDATION_RETIRE = 'retire';

    public const EVOLUTION_RECOMMENDATIONS = [
        self::EVOLUTION_RECOMMENDATION_KEEP,
        self::EVOLUTION_RECOMMENDATION_MONITOR,
        self::EVOLUTION_RECOMMENDATION_UPDATE,
        self::EVOLUTION_RECOMMENDATION_REPLACE,
        self::EVOLUTION_RECOMMENDATION_RETIRE,
    ];

    /* Risk categories that always require policy approval before any execution. */
    public const HIGH_RISK_PLAN_TYPES = [
        self::PLAN_BROWSER,
        self::PLAN_TERMINAL,
        self::PLAN_TOOL_BUILDER,
    ];
}
