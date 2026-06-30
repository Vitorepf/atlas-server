<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure quota-boundary ledger. Records what is actually known about a
 * provider pool's usage limits (e.g. a Cursor/Codex subscription seat,
 * a local CLI quota) so Atlas never confuses "subscription exists" with
 * "guaranteed 24/7 autonomous capacity."
 *
 * quota_reliable_for_24_7 is false whenever ANY of the following hold:
 *   - paid_api_required === true (pay-per-call has no bounded ceiling Atlas controls)
 *   - hard_limit_known === false
 *   - reset_window is empty/unknown
 *   - billing_boundary_known === false (no proof of the entitlement boundary)
 *   - no fallback capacity is recorded (fallback_pool_available === false)
 *
 * Never makes network calls, never reads billing secrets, never requires
 * paid API usage, never persists account data — record() is a pure
 * function over facts the caller already collected.
 */
final class AtlasExternalBrainProviderPoolQuotaBoundaryLedger
{
    public const SCHEMA = 'atlas.external_brain.provider_pool_quota_boundary_ledger.v1';

    private const RISK_LEVEL_LOW = 'low';
    private const RISK_LEVEL_MEDIUM = 'medium';
    private const RISK_LEVEL_HIGH = 'high';

    /**
     * @param  array<string,mixed>  $facts
     * @return array<string,mixed>
     */
    public function record(array $facts): array
    {
        $providerId = (string) ($facts['provider_id'] ?? '');
        $planName = (string) ($facts['plan_name'] ?? '');
        $poolKind = (string) ($facts['pool_kind'] ?? '');
        $usesExistingSubscription = (bool) ($facts['uses_existing_subscription'] ?? false);
        $paidApiRequired = (bool) ($facts['paid_api_required'] ?? false);
        $resetWindow = trim((string) ($facts['reset_window'] ?? ''));
        $hardLimitKnown = (bool) ($facts['hard_limit_known'] ?? false);
        $softThrottleKnown = (bool) ($facts['soft_throttle_known'] ?? false);
        $estimatedRemaining = $facts['estimated_remaining'] ?? null;
        $observedExhaustion = (bool) ($facts['observed_exhaustion'] ?? false);
        $billingBoundaryKnown = (bool) ($facts['billing_boundary_known'] ?? false);
        $fallbackPoolAvailable = (bool) ($facts['fallback_pool_available'] ?? false);

        $unreliableReasons = [];
        if ($paidApiRequired) {
            $unreliableReasons[] = 'paid_api_required_has_no_atlas_controlled_ceiling';
        }
        if (! $hardLimitKnown) {
            $unreliableReasons[] = 'hard_limit_unknown';
        }
        if ($resetWindow === '') {
            $unreliableReasons[] = 'reset_window_unknown';
        }
        if (! $billingBoundaryKnown) {
            $unreliableReasons[] = 'billing_boundary_unknown_no_entitlement_proof';
        }
        if (! $fallbackPoolAvailable) {
            $unreliableReasons[] = 'fallback_capacity_unavailable';
        }

        $quotaReliableFor247 = $unreliableReasons === [];

        $fallbackRequiredReasons = $unreliableReasons;
        if ($observedExhaustion) {
            $fallbackRequiredReasons[] = 'exhaustion_already_observed';
        }
        $fallbackRequired = $fallbackRequiredReasons !== [];

        $riskLevel = $this->riskLevel(
            quotaReliableFor247: $quotaReliableFor247,
            observedExhaustion: $observedExhaustion,
            softThrottleKnown: $softThrottleKnown,
        );

        $routingBudgetStatus = match (true) {
            $observedExhaustion => 'exhausted_route_elsewhere',
            ! $quotaReliableFor247 => 'unreliable_treat_as_best_effort_only',
            default => 'reliable_within_known_boundary',
        };

        $entry = [
            'schema_version' => self::SCHEMA,
            'provider_id' => $providerId,
            'plan_name' => $planName,
            'pool_kind' => $poolKind,
            'uses_existing_subscription' => $usesExistingSubscription,
            'paid_api_required' => $paidApiRequired,
            'reset_window' => $resetWindow,
            'hard_limit_known' => $hardLimitKnown,
            'soft_throttle_known' => $softThrottleKnown,
            'estimated_remaining' => $estimatedRemaining,
            'observed_exhaustion' => $observedExhaustion,
            'billing_boundary_known' => $billingBoundaryKnown,
            'fallback_pool_available' => $fallbackPoolAvailable,
            'quota_reliable_for_24_7' => $quotaReliableFor247,
            'unreliable_reasons' => $unreliableReasons,
            'routing_budget_status' => $routingBudgetStatus,
            'risk_level' => $riskLevel,
            'fallback_required' => $fallbackRequired,
            'fallback_required_reasons' => array_values(array_unique($fallbackRequiredReasons)),
            'network_calls_made' => false,
            'billing_secrets_read' => false,
            'paid_api_usage_required_to_record' => false,
            'account_data_persisted' => false,
        ];

        return $entry;
    }

    private function riskLevel(bool $quotaReliableFor247, bool $observedExhaustion, bool $softThrottleKnown): string
    {
        if ($observedExhaustion) {
            return self::RISK_LEVEL_HIGH;
        }
        if (! $quotaReliableFor247) {
            return self::RISK_LEVEL_MEDIUM;
        }

        return $softThrottleKnown ? self::RISK_LEVEL_LOW : self::RISK_LEVEL_MEDIUM;
    }
}
