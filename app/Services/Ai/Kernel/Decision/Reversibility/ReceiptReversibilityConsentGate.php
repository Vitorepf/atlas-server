<?php

declare(strict_types=1);

namespace App\Services\Ai\Kernel\Decision\Reversibility;

final class ReceiptReversibilityConsentGate
{
    private const SCHEMA_VERSION = 'atlas.decide.receipt_reversibility_consent.v1';

    private const KNOWN_TIERS = ['cheap', 'moderate', 'expensive', 'unrecoverable'];

    /**
     * @return array{
     *     schema_version: string,
     *     verdict: string,
     *     committable: bool,
     *     requires_operator_confirmation: bool,
     *     remaining_hold_seconds: int,
     *     reason: string
     * }
     */
    public function evaluate(
        string $reversalTier,
        int $elapsedHoldSeconds,
        int $requiredHoldSeconds,
        bool $operatorConfirmed
    ): array {
        $tier = $this->normalizeTier($reversalTier);

        $elapsed = max(0, $elapsedHoldSeconds);
        $required = max(0, $requiredHoldSeconds);
        $remaining = max(0, $required - $elapsed);

        $decision = $this->decide($tier, $remaining, $operatorConfirmed);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'verdict' => $decision['verdict'],
            'committable' => $decision['committable'],
            'requires_operator_confirmation' => $decision['requires_operator_confirmation'],
            'remaining_hold_seconds' => $remaining,
            'reason' => $decision['reason'],
        ];
    }

    private function normalizeTier(string $reversalTier): string
    {
        $tier = strtolower(trim($reversalTier));

        return in_array($tier, self::KNOWN_TIERS, true) ? $tier : 'unrecoverable';
    }

    /**
     * @return array{verdict: string, committable: bool, requires_operator_confirmation: bool, reason: string}
     */
    private function decide(string $tier, int $remaining, bool $operatorConfirmed): array
    {
        // R1: unrecoverable & !confirmed -> blocked, consent required.
        if ($tier === 'unrecoverable' && ! $operatorConfirmed) {
            return $this->result('blocked_irreversible_without_consent', false, true, 'unrecoverable_action_requires_explicit_operator_confirmation');
        }

        // R2: unrecoverable & confirmed -> committable with explicit consent.
        if ($tier === 'unrecoverable') {
            return $this->result('committable_with_explicit_consent', true, true, 'unrecoverable_action_authorized_by_operator_consent');
        }

        // R3: expensive & remaining>0 & !confirmed -> holding for cooldown.
        if ($tier === 'expensive' && $remaining > 0 && ! $operatorConfirmed) {
            return $this->result('holding_for_cooldown', false, false, 'expensive_reversal_holding_until_cooldown_elapses');
        }

        // R4: expensive & (remaining=0 OR confirmed) -> committable after hold.
        if ($tier === 'expensive') {
            return $this->result('committable_after_hold', true, false, 'expensive_reversal_hold_satisfied');
        }

        // R5: moderate & remaining>0 & !confirmed -> holding for cooldown.
        if ($tier === 'moderate' && $remaining > 0 && ! $operatorConfirmed) {
            return $this->result('holding_for_cooldown', false, false, 'moderate_reversal_holding_until_cooldown_elapses');
        }

        // R6: moderate -> committable after hold.
        if ($tier === 'moderate') {
            return $this->result('committable_after_hold', true, false, 'moderate_reversal_hold_satisfied');
        }

        // R7: cheap -> committable immediately.
        return $this->result('committable_immediately', true, false, 'cheap_reversal_no_hold_required');
    }

    /**
     * @return array{verdict: string, committable: bool, requires_operator_confirmation: bool, reason: string}
     */
    private function result(string $verdict, bool $committable, bool $requiresOperatorConfirmation, string $reason): array
    {
        return [
            'verdict' => $verdict,
            'committable' => $committable,
            'requires_operator_confirmation' => $requiresOperatorConfirmation,
            'reason' => $reason,
        ];
    }
}
