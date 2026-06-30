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
        ];
    }
}
