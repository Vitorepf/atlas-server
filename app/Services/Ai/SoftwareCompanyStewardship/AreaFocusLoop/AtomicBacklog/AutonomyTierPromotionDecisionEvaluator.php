<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AtomicBacklog;

final class AutonomyTierPromotionDecisionEvaluator
{
    private const SCHEMA_VERSION = 'atlas.loop.autonomy_tier_promotion_decision.v1';

    public function decide(array $receipt, array $areaState, array $runtimeSignals): array
    {
        $currentTier = $this->intValue($areaState, 'active_tier');
        $requestedTier = $this->intValue($receipt, 'requested_tier', $currentTier);
        $maxTier = $this->intValue($areaState, 'max_autonomy_tier', $currentTier);

        $blockers = [];

        foreach ([
            [$areaState, 'active_tier'],
            [$receipt, 'requested_tier'],
            [$areaState, 'max_autonomy_tier'],
        ] as [$payload, $key]) {
            if (! $this->hasValidTierValue($payload, $key)) {
                $blockers[] = 'invalid_' . $key;
            }
        }

        if (! $this->isRegisteredArea($areaState)) {
            $blockers[] = 'area_not_registered';
        }

        if (! $this->hasApprovedSignature($receipt)) {
            $blockers[] = 'operator_receipt_signature_missing';
        }

        if (($runtimeSignals['kill_switch_active'] ?? false) === true) {
            $blockers[] = 'kill_switch_active';
        }

        if ($requestedTier > $maxTier) {
            $blockers[] = 'requested_tier_above_max';
        }

        if (! $this->budgetAllowsExecution($runtimeSignals)) {
            $blockers[] = 'budget_not_available';
        }

        $promotable = $blockers === [] && $requestedTier > $currentTier;

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'decision' => $promotable ? 'promote' : 'block',
            'active_tier' => $promotable ? $requestedTier : $currentTier,
            'blockers' => $blockers,
            'receipt_required' => true,
        ];
    }

    private function isRegisteredArea(array $areaState): bool
    {
        return ($areaState['registered'] ?? false) === true
            || ($areaState['area_registered'] ?? false) === true;
    }

    private function hasApprovedSignature(array $receipt): bool
    {
        return ($receipt['operator_signed'] ?? false) === true
            && ($receipt['approved'] ?? false) === true;
    }

    private function budgetAllowsExecution(array $runtimeSignals): bool
    {
        if (($runtimeSignals['budget_exhausted'] ?? false) === true) {
            return false;
        }

        return ($runtimeSignals['budget_available'] ?? true) === true;
    }

    private function intValue(array $payload, string $key, int $default = 0): int
    {
        $value = $payload[$key] ?? $default;

        return is_int($value) ? $value : (int) $value;
    }

    private function hasValidTierValue(array $payload, string $key): bool
    {
        if (! array_key_exists($key, $payload)) {
            return true;
        }

        $value = $payload[$key];

        return is_int($value) || (is_string($value) && filter_var($value, FILTER_VALIDATE_INT) !== false);
    }
}
