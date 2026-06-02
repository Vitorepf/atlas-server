<?php

declare(strict_types=1);

namespace App\Services\Ai\Evidence;

/**
 * Classifies whether an operator override carries a sufficient justification.
 *
 * A bare "approve" on a high-risk acceptance is the classic way an audit trail
 * rots: the decision is recorded but the *why* is missing, so the receipt cannot
 * be reconstructed later. This classifier grades the rationale by its trimmed
 * length and whether it cites a verifiable reference hash, and fails closed when
 * a high-risk acceptance ships with an empty rationale.
 *
 * Pure: every returned field is computed from the supplied override payload.
 * Zero constructor dependencies, no I/O, no facades, no clock, no randomness.
 */
final class OverrideJustificationSufficiencyClassifier
{
    /**
     * Minimum trimmed rationale length that can qualify as 'sufficient'
     * (only reached together with a non-empty reference hash). The boundary is
     * inclusive: exactly 32 chars meets the threshold, but without a hash it
     * still lands as 'thin'.
     */
    private const SUFFICIENT_RATIONALE_LENGTH = 32;

    private const REASON_HIGH_RISK_ACCEPT_EMPTY_RATIONALE = 'high_risk_accept_empty_rationale';

    /**
     * Decision tokens that count as an acceptance for the fail-closed rule.
     *
     * @var list<string>
     */
    private const ACCEPTING_DECISIONS = ['approved', 'accept'];

    /**
     * Risk tiers that arm the fail-closed rule.
     *
     * @var list<string>
     */
    private const HIGH_RISK_TIERS = ['high', 'critical'];

    /**
     * Override payload keys consulted, in priority order, for the reference hash.
     *
     * @var list<string>
     */
    private const REFERENCE_HASH_KEYS = ['referenced_hash', 'finding_hash', 'receipt_hash'];

    /**
     * @param  array<string,mixed>  $override
     * @return array{verdict:string, rationale_length:int, has_reference_hash:bool, fail_closed:bool, reasons:list<string>}
     */
    public function classify(array $override): array
    {
        $rationale = $this->stringValue($override, 'rationale');

        if ($rationale === '') {
            $rationale = $this->stringValue($override, 'reason');
        }

        $rationale = trim($rationale);
        $length = strlen($rationale);

        $hasReferenceHash = $this->hasReferenceHash($override);

        $reasons = [];
        $failClosed = false;

        // Length-graded verdict (rules 1-4).
        if ($length >= self::SUFFICIENT_RATIONALE_LENGTH && $hasReferenceHash) {
            $verdict = 'sufficient';
        } elseif ($length >= 1) {
            $verdict = 'thin';
        } else {
            $verdict = 'absent';
        }

        // Rule 5/6: fail-closed on a high-risk acceptance with an empty rationale.
        if ($length === 0 && $this->isAcceptingDecision($override) && $this->isHighRisk($override)) {
            $verdict = 'absent';
            $reasons[] = self::REASON_HIGH_RISK_ACCEPT_EMPTY_RATIONALE;
            $failClosed = true;
        }

        return [
            'verdict' => $verdict,
            'rationale_length' => $length,
            'has_reference_hash' => $hasReferenceHash,
            'fail_closed' => $failClosed,
            'reasons' => $reasons,
        ];
    }

    /**
     * @param  array<string,mixed>  $override
     */
    private function hasReferenceHash(array $override): bool
    {
        foreach (self::REFERENCE_HASH_KEYS as $key) {
            if ($this->stringValue($override, $key) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string,mixed>  $override
     */
    private function isAcceptingDecision(array $override): bool
    {
        return in_array($this->normalizedToken($override, 'decision'), self::ACCEPTING_DECISIONS, true);
    }

    /**
     * @param  array<string,mixed>  $override
     */
    private function isHighRisk(array $override): bool
    {
        $risk = $this->normalizedToken($override, 'target_risk');

        if ($risk === '') {
            $risk = $this->normalizedToken($override, 'risk_tier');
        }

        return in_array($risk, self::HIGH_RISK_TIERS, true);
    }

    /**
     * @param  array<string,mixed>  $override
     */
    private function normalizedToken(array $override, string $key): string
    {
        return strtolower(trim($this->stringValue($override, $key)));
    }

    /**
     * @param  array<string,mixed>  $override
     */
    private function stringValue(array $override, string $key): string
    {
        $value = $override[$key] ?? null;

        return is_string($value) ? $value : '';
    }
}
