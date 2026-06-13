<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

/**
 * Builds the canonical `atlas.autonomy.promotion_request.v1` payload for an
 * L6 -> L7 (Multi-Department Conductor -> Self-Evolving) promotion WITHOUT ever
 * applying the promotion. This is the request-author half of the L7 closure:
 * it assembles an auditable, signature-slotted request from measured evidence
 * and computes whether that evidence is complete enough to be a valid request.
 *
 * Mirrors the L7 exit criteria the runbook encodes in
 * App\Services\Ai\Autonomy\AtlasAutonomyLadderRuntimeService::LADDER (the L7
 * rung): 10 approved self-construction proposals, 0 broken invariants and a
 * Trust Ledger score >= 0.95. The thresholds and metric keys here are mirrored
 * from that canonical ladder, not reinvented.
 *
 * Pure: no I/O, clock, randomness or state mutation. `from_level`/`to_level`
 * are fixed because this builder is L6->L7 specific; every other field is
 * computed from the supplied $evidence. It never mutates a level — it only
 * returns the request a signer would later approve.
 *
 * @see docs/engineering-knowledge-base/atlas-autonomy-ladder-promotion-runbook.md
 * @see docs/engineering-knowledge-base/atlas-aaeos-l7-convergence-roadmap.md
 */
final class L7PromotionRequestBuilder
{
    private const SCHEMA_VERSION = 'atlas.autonomy.promotion_request.v1';

    private const FROM_LEVEL = 'L6';

    private const TO_LEVEL = 'L7';

    /** L7 exit threshold: approved self-construction proposals must reach this. */
    private const REQUIRED_APPROVED_PROPOSALS = 10;

    /** L7 exit threshold: Trust Ledger score must be at least this. */
    private const TRUST_LEDGER_THRESHOLD = 0.95;

    /** L7 exit threshold: broken invariants must not exceed this. */
    private const MAX_BROKEN_INVARIANTS = 0;

    /** Default rollback window when the evidence does not specify one. */
    private const DEFAULT_ROLLBACK_WINDOW_SECONDS = 86_400;

    /**
     * @param  array<string,mixed>  $evidence
     * @return array<string,mixed>
     */
    public function build(array $evidence): array
    {
        $approvedProposals = $this->intValue($evidence, 'approved_self_construction_proposals');
        $brokenInvariants = $this->intValue($evidence, 'broken_invariant_count');
        $trustLedgerScore = $this->trustLedgerScore($evidence);
        $rollbackWindowSeconds = $this->rollbackWindowSeconds($evidence);

        $blockers = [];

        if ($approvedProposals < self::REQUIRED_APPROVED_PROPOSALS) {
            $blockers[] = 'insufficient_self_construction_proposals';
        }

        if ($trustLedgerScore < self::TRUST_LEDGER_THRESHOLD) {
            $blockers[] = 'trust_ledger_below_threshold';
        }

        if ($brokenInvariants > self::MAX_BROKEN_INVARIANTS || $brokenInvariants < 0) {
            $blockers[] = 'invariant_breached';
        }

        if ($rollbackWindowSeconds <= 0) {
            $blockers[] = 'rollback_window_missing';
        }

        $valid = $blockers === [];

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'from_level' => self::FROM_LEVEL,
            'to_level' => self::TO_LEVEL,
            'valid' => $valid,
            'status' => $valid ? 'ready_for_signature' : 'blocked',
            'evidence' => [
                'approved_self_construction_proposals' => $approvedProposals,
                'broken_invariant_count' => $brokenInvariants,
                'trust_ledger_score' => $trustLedgerScore,
            ],
            'trust_ledger_score' => $trustLedgerScore,
            'operator_signature' => $this->signatureSlot($evidence, 'operator_signature'),
            'architect_signature' => $this->signatureSlot($evidence, 'architect_signature'),
            'rollback_window_seconds' => $rollbackWindowSeconds,
            'blockers' => $blockers,
        ];
    }

    /**
     * @param  array<string,mixed>  $evidence
     */
    private function trustLedgerScore(array $evidence): float
    {
        $value = $evidence['trust_ledger_score'] ?? 0.0;

        if (is_int($value)) {
            return (float) $value;
        }

        // Non-finite floats (NaN, ±INF) are not a real Trust Ledger score. NaN in
        // particular slips past the `< threshold` gate (every NaN comparison is
        // false) and would mark a request valid with a NaN score. Fail closed.
        if (is_float($value)) {
            return is_finite($value) ? $value : 0.0;
        }

        if (is_string($value) && is_numeric($value)) {
            $float = (float) $value;

            return is_finite($float) ? $float : 0.0;
        }

        return 0.0;
    }

    /**
     * A signature slot: the provided signature string when present and non-empty,
     * otherwise null (an empty, unsigned slot — never a fabricated value).
     *
     * @param  array<string,mixed>  $evidence
     */
    private function signatureSlot(array $evidence, string $key): ?string
    {
        $value = $evidence[$key] ?? null;

        if (is_string($value) && trim($value) !== '') {
            return $value;
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $evidence
     */
    private function rollbackWindowSeconds(array $evidence): int
    {
        if (! array_key_exists('rollback_window_seconds', $evidence)) {
            return self::DEFAULT_ROLLBACK_WINDOW_SECONDS;
        }

        return $this->intValue($evidence, 'rollback_window_seconds');
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function intValue(array $payload, string $key): int
    {
        $value = $payload[$key] ?? 0;

        if (is_int($value)) {
            return $value;
        }

        if (is_float($value)) {
            return $this->floatToInt($value);
        }

        if (is_string($value) && is_numeric($value)) {
            return $this->floatToInt((float) $value);
        }

        return 0;
    }

    /**
     * Cast a float to int without the silent overflow-to-negative WRAP (and the
     * runtime Warning) that a direct `(int)` cast performs on values outside the
     * representable int range. A huge `broken_invariant_count` float would
     * otherwise wrap to a negative int, slip past the `> 0` breach gate and mark
     * a request valid on a breach signal (fail-open). Saturate to the int bound by
     * sign; a non-finite value (NaN, ±INF) is treated as the maxed-out positive
     * bound so the breach gate still fails closed.
     */
    private function floatToInt(float $value): int
    {
        if (is_nan($value) || $value >= (float) PHP_INT_MAX) {
            return PHP_INT_MAX;
        }

        if ($value <= (float) PHP_INT_MIN) {
            return PHP_INT_MIN;
        }

        return (int) $value;
    }
}
