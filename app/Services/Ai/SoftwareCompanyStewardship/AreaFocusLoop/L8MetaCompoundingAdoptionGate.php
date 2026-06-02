<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

/**
 * L8-P2 meta-compounding adoption gate.
 *
 * Given a proposed new M factor/weight (the candidate) and the measured outcome of
 * trialling it, this gate decides whether the candidate may be ADOPTED into the
 * antifragility equation. It is the measured-or-reverted, anti-Goodhart admission
 * point for self-discovered multiplier factors: a candidate is admitted only when
 *
 *   1. P5 self-deception immunity holds (independent P5 evidence is present and no
 *      P5 divergence / gaming was detected — safety precedes capability), and
 *   2. post-change dm/dt did not go down (the factor actually compounds), and
 *   3. observability did not drop (a factor can never buy lift by hiding signal), and
 *   4. the candidate carries a strictly positive MEASURED contribution.
 *
 * Any failing rule rejects the candidate; the first failing rule (evaluated in the
 * safety-first order above) is returned as rejected_reason. The gate is pure: it
 * computes its verdict from the proposal and outcome alone and never writes config
 * or state, so adoption stays a governed proposal an operator/governor enacts.
 */
final class L8MetaCompoundingAdoptionGate
{
    public const SCHEMA_VERSION = 'atlas.aaeos.l8.meta_compounding.adoption_gate.v1';

    public const REASON_MISSING_P5_EVIDENCE = 'missing_p5_evidence';

    public const REASON_P5_DIVERGENCE = 'p5_divergence';

    public const REASON_DM_DT_DOWN = 'dm_dt_down';

    public const REASON_OBSERVABILITY_DOWN = 'observability_down';

    public const REASON_NO_MEASURED_CONTRIBUTION = 'no_measured_contribution';

    /**
     * @param array{
     *     factor_id?: string,
     *     candidate_id?: string,
     *     evidence_refs?: list<string>
     * } $proposal the candidate new M factor/weight
     * @param array{
     *     p5_evidence_ref?: string,
     *     p5_divergence_detected?: bool,
     *     gaming_detected?: bool,
     *     dm_dt_before?: int|float,
     *     dm_dt_after?: int|float,
     *     observability_before?: int|float,
     *     observability_after?: int|float,
     *     measured_contribution?: int|float,
     *     evidence_refs?: list<string>
     * } $outcome the measured outcome of trialling the candidate
     *
     * @return array{
     *     schema_version: string,
     *     adopted_candidate: string|null,
     *     rejected_reason: string|null,
     *     dm_dt_delta: float,
     *     observability_delta: float,
     *     measured_contribution: float,
     *     p5_passed: bool,
     *     evidence_refs: list<string>
     * }
     */
    public function evaluate(array $proposal, array $outcome): array
    {
        $candidateId = $this->candidateId($proposal);

        $dmDtDelta = $this->finiteDelta(
            $this->floatValue($outcome['dm_dt_after'] ?? 0.0),
            $this->floatValue($outcome['dm_dt_before'] ?? 0.0),
        );
        $observabilityDelta = $this->finiteDelta(
            $this->floatValue($outcome['observability_after'] ?? 0.0),
            $this->floatValue($outcome['observability_before'] ?? 0.0),
        );
        $measuredContribution = $this->floatValue($outcome['measured_contribution'] ?? 0.0);

        $p5Passed = $this->p5Passed($outcome);

        $rejectedReason = $this->firstRejection(
            $outcome,
            $p5Passed,
            $dmDtDelta,
            $observabilityDelta,
            $measuredContribution,
        );

        $adopted = $rejectedReason === null && $candidateId !== null;

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'adopted_candidate' => $adopted ? $candidateId : null,
            'rejected_reason' => $rejectedReason,
            'dm_dt_delta' => $dmDtDelta,
            'observability_delta' => $observabilityDelta,
            'measured_contribution' => $measuredContribution,
            'p5_passed' => $p5Passed,
            'evidence_refs' => $this->evidenceRefs($proposal, $outcome),
        ];
    }

    /**
     * Safety-first rejection ordering: P5 immunity before any measured-lift rule, so a
     * candidate that games the metric can never be admitted on a "good" number.
     *
     * @param array<string, mixed> $outcome
     */
    private function firstRejection(
        array $outcome,
        bool $p5Passed,
        float $dmDtDelta,
        float $observabilityDelta,
        float $measuredContribution,
    ): ?string {
        if (! $this->hasP5Evidence($outcome)) {
            return self::REASON_MISSING_P5_EVIDENCE;
        }

        if (! $p5Passed) {
            return self::REASON_P5_DIVERGENCE;
        }

        if ($dmDtDelta < 0.0) {
            return self::REASON_DM_DT_DOWN;
        }

        if ($observabilityDelta < 0.0) {
            return self::REASON_OBSERVABILITY_DOWN;
        }

        if ($measuredContribution <= 0.0) {
            return self::REASON_NO_MEASURED_CONTRIBUTION;
        }

        return null;
    }

    /**
     * @param array{factor_id?: string, candidate_id?: string} $proposal
     */
    private function candidateId(array $proposal): ?string
    {
        foreach (['factor_id', 'candidate_id'] as $key) {
            $value = $proposal[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $outcome
     */
    private function p5Passed(array $outcome): bool
    {
        // Safety-first / fail-closed: ANY truthy detection signal means divergence or
        // gaming WAS detected, so the candidate cannot pass. A strict `=== true` check
        // would fail open on a non-bool truthy flag (int 1, "1", "true") arriving from a
        // JSON decode, DB tinyint, or a non-strict producer — exactly the kind of "good
        // number hiding a gamed metric" this anti-Goodhart gate exists to veto.
        if ($this->flagSet($outcome, 'p5_divergence_detected')) {
            return false;
        }

        if ($this->flagSet($outcome, 'gaming_detected')) {
            return false;
        }

        return true;
    }

    /**
     * A detection flag counts as set when it is present and truthy. Absent or explicitly
     * falsey ([false]/0/""/"0"/null) leaves the corresponding immunity intact.
     *
     * @param array<string, mixed> $outcome
     */
    private function flagSet(array $outcome, string $key): bool
    {
        return (bool) ($outcome[$key] ?? false);
    }

    /**
     * @param array<string, mixed> $outcome
     */
    private function hasP5Evidence(array $outcome): bool
    {
        $ref = $outcome['p5_evidence_ref'] ?? null;

        return is_string($ref) && trim($ref) !== '';
    }

    /**
     * Merge proposal + outcome evidence into a de-duplicated, re-indexed list<string>.
     * Re-indexing via array_values guarantees a JSON array (never an object) even when
     * numeric-looking string refs would otherwise collapse to integer keys.
     *
     * @param array{evidence_refs?: list<string>} $proposal
     * @param array{p5_evidence_ref?: string, evidence_refs?: list<string>} $outcome
     *
     * @return list<string>
     */
    private function evidenceRefs(array $proposal, array $outcome): array
    {
        $refs = [];

        foreach ([$proposal['evidence_refs'] ?? [], $outcome['evidence_refs'] ?? []] as $bag) {
            if (! is_array($bag)) {
                continue;
            }

            foreach ($bag as $ref) {
                $this->pushRef($refs, $ref);
            }
        }

        $this->pushRef($refs, $outcome['p5_evidence_ref'] ?? null);

        return array_values($refs);
    }

    /**
     * @param array<string, string> $refs
     */
    private function pushRef(array &$refs, mixed $ref): void
    {
        if (! is_string($ref)) {
            return;
        }

        $trimmed = trim($ref);
        if ($trimmed === '') {
            return;
        }

        $refs[$trimmed] = $trimmed;
    }

    /**
     * Subtract two already-finite measurements while keeping the result finite. Two
     * finite operands near ±PHP_FLOAT_MAX can still overflow to ±INF; an INF/NAN delta
     * is not a real movement, so it collapses to 0.0 (no signal). This preserves the
     * declared `float` delta contract and keeps the gate fail-closed: a +INF delta must
     * never read as a real improvement that admits a candidate.
     */
    private function finiteDelta(float $after, float $before): float
    {
        $delta = $after - $before;

        return is_finite($delta) ? $delta : 0.0;
    }

    private function floatValue(mixed $value): float
    {
        if (is_int($value) || is_float($value)) {
            $number = (float) $value;

            // Non-finite (INF/NAN) is not a real measurement. Collapsing it to 0.0 keeps
            // the gate fail-closed: a NaN measured_contribution must read as "no measured
            // contribution" (NAN <= 0.0 is false, so an unguarded NaN would wrongly admit).
            return is_finite($number) ? $number : 0.0;
        }

        if (is_string($value) && is_numeric($value)) {
            $number = (float) $value;

            return is_finite($number) ? $number : 0.0;
        }

        return 0.0;
    }
}
