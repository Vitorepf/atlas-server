<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure cost guard for local subscription clients. Treats a subscription
 * seat as BOUNDED local capacity, never as a blank check: autonomous
 * routing is blocked the moment paid-API usage is required or any quota
 * boundary needed for safe 24/7 operation is unknown.
 *
 * safe_for_manual_use requires only that paid API is not required — a human
 * watching the session can absorb soft-throttle or unknown-quota risk.
 *
 * safe_for_24_7 additionally requires every quota boundary to be known:
 * quota_remaining_known, hard_limit_known, and reset_window_known.
 *
 * Never reads billing data, never makes network calls — evaluate() is a
 * pure function over facts the caller already collected.
 */
final class AtlasExternalBrainLocalClientSubscriptionCostGuard
{
    public const SCHEMA = 'atlas.external_brain.local_client_subscription_cost_guard.v1';

    public const STATUS_SAFE_FOR_24_7 = 'safe_for_24_7';
    public const STATUS_SAFE_FOR_MANUAL_USE_ONLY = 'safe_for_manual_use_only';
    public const STATUS_BLOCKED = 'blocked';

    public const CLIENT_CLASS_LOCAL_SUBSCRIPTION = 'local_subscription';
    public const CLIENT_CLASS_INCLUDED_POOL = 'included_pool';
    public const CLIENT_CLASS_API_METERED = 'api_metered';
    public const CLIENT_CLASS_UNKNOWN_COST = 'unknown_cost';

    /** distinct risk level per client class (AC2). */
    private const RISK_LEVEL_BY_CLIENT_CLASS = [
        self::CLIENT_CLASS_LOCAL_SUBSCRIPTION => 'low',
        self::CLIENT_CLASS_INCLUDED_POOL => 'medium',
        self::CLIENT_CLASS_API_METERED => 'high',
        self::CLIENT_CLASS_UNKNOWN_COST => 'critical',
    ];

    /**
     * @param  array<string,mixed>  $facts
     * @return array<string,mixed>
     */
    public function evaluate(array $facts): array
    {
        $usesExistingSubscription = (bool) ($facts['uses_existing_subscription'] ?? false);
        $paidApiRequired = (bool) ($facts['paid_api_required'] ?? false);
        $quotaRemainingKnown = (bool) ($facts['quota_remaining_known'] ?? false);
        $softThrottleObserved = (bool) ($facts['soft_throttle_observed'] ?? false);
        $hardLimitKnown = (bool) ($facts['hard_limit_known'] ?? false);
        $resetWindowKnown = (bool) ($facts['reset_window_known'] ?? false);
        $fallbackAvailable = (bool) ($facts['fallback_available'] ?? false);
        $includedInPool = (bool) ($facts['included_in_pool'] ?? false);
        $runPolicyForbidsPaidApi = (bool) ($facts['run_policy_forbids_paid_api'] ?? false);

        $blockers = [];
        if ($paidApiRequired) {
            $blockers[] = 'paid_api_required';
        }
        if (! $quotaRemainingKnown) {
            $blockers[] = 'quota_remaining_unknown';
        }
        if (! $hardLimitKnown) {
            $blockers[] = 'hard_limit_unknown';
        }
        if (! $resetWindowKnown) {
            $blockers[] = 'reset_window_unknown';
        }

        $safeForManualUse = ! $paidApiRequired;
        $safeFor247 = $safeForManualUse && $quotaRemainingKnown && $hardLimitKnown && $resetWindowKnown;

        $costGuardStatus = match (true) {
            $paidApiRequired => self::STATUS_BLOCKED,
            $safeFor247 => self::STATUS_SAFE_FOR_24_7,
            $safeForManualUse => self::STATUS_SAFE_FOR_MANUAL_USE_ONLY,
            default => self::STATUS_BLOCKED,
        };

        $fallbackRequired = ! $safeFor247;
        if ($fallbackRequired && ! $fallbackAvailable) {
            $blockers[] = 'no_fallback_available_for_unsafe_24_7_routing';
        }

        // AC2: classify the client so a hidden API-metered client can never masquerade
        // as bounded local capacity — priority: explicit paid-API > known subscription
        // seat > known shared pool > unknown (worst case, treated as highest risk).
        $clientClass = match (true) {
            $paidApiRequired => self::CLIENT_CLASS_API_METERED,
            $usesExistingSubscription => self::CLIENT_CLASS_LOCAL_SUBSCRIPTION,
            $includedInPool => self::CLIENT_CLASS_INCLUDED_POOL,
            default => self::CLIENT_CLASS_UNKNOWN_COST,
        };
        $riskLevel = self::RISK_LEVEL_BY_CLIENT_CLASS[$clientClass];

        // AC3: a run policy that forbids paid-API dependence must also catch the
        // client masquerading as unknown-cost — never just the explicitly-flagged one.
        $runPolicyViolation = $runPolicyForbidsPaidApi
            && in_array($clientClass, [self::CLIENT_CLASS_API_METERED, self::CLIENT_CLASS_UNKNOWN_COST], true);
        if ($runPolicyViolation) {
            $blockers[] = 'run_policy_forbids_paid_api_dependence';
            $costGuardStatus = self::STATUS_BLOCKED;
        }

        // AC4: never silently switch to a paid provider — always name a fallback that
        // preserves task quality (defer/restrict/block), never "use paid API instead".
        $fallbackRecommendation = match (true) {
            $costGuardStatus === self::STATUS_SAFE_FOR_24_7 => 'none_required',
            $clientClass === self::CLIENT_CLASS_API_METERED || $runPolicyViolation
                => 'defer_to_local_subscription_or_pool_client_never_silently_switch_to_paid_api',
            $costGuardStatus === self::STATUS_SAFE_FOR_MANUAL_USE_ONLY
                => 'restrict_to_manual_supervised_session_until_quota_boundaries_known',
            default => 'block_task_until_missing_quota_boundaries_confirmed',
        };

        return [
            'schema_version' => self::SCHEMA,
            'uses_existing_subscription' => $usesExistingSubscription,
            'paid_api_required' => $paidApiRequired,
            'quota_remaining_known' => $quotaRemainingKnown,
            'soft_throttle_observed' => $softThrottleObserved,
            'hard_limit_known' => $hardLimitKnown,
            'reset_window_known' => $resetWindowKnown,
            'fallback_available' => $fallbackAvailable,
            'cost_guard_status' => $costGuardStatus,
            'safe_for_manual_use' => $safeForManualUse,
            'safe_for_24_7' => $safeFor247,
            'fallback_required' => $fallbackRequired,
            'blockers' => array_values(array_unique($blockers)),
            'billing_data_read' => false,
            'network_calls_made' => false,
            'client_class' => $clientClass,
            'risk_level' => $riskLevel,
            'run_policy_violation' => $runPolicyViolation,
            'fallback_recommendation' => $fallbackRecommendation,
        ];
    }
}
