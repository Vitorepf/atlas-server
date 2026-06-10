<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

/**
 * L9-Q3 engineering-method outcome validator.
 *
 * A system-discovered engineering method candidate (from S139) earns a place in
 * canonical engineering discipline only when its claimed improvement is proven by
 * MEASURED outcomes, not by style or plausibility. This validator reads the
 * candidate together with its post-adoption outcome evidence and decides whether
 * the method is validated (retained) or must be reverted.
 *
 * It is read-only and pure: it never applies a revert, never writes state, never
 * calls a provider, clock or randomness, and computes every field from its inputs.
 *
 * Validation rules, applied in this fixed order (fail-closed first), mirroring the
 * L8 measured-or-reverted lexicon:
 *   1. No outcome evidence (empty evidence_refs) -> not validated -> reverted
 *      (the improvement was never measured, so it cannot be retained).
 *   2. regression_count > 0                       -> not validated -> reverted
 *      (a measured regression rejects the method).
 *   3. retained_delta < 0                          -> not validated -> reverted
 *      (negative retained improvement rejects the method).
 *   4. otherwise (measured, no regression, retained_delta >= 0) -> validated.
 *
 * A non-validated method is always reverted=true (measured-or-reverted): the
 * method does not survive on style, only on retained outcome.
 */
final class L9EngineeringMethodOutcomeValidator
{
    private const SCHEMA_VERSION = 'atlas.aaeos.l9.engineering_method_outcome_validation.v1';

    private const BLOCKER_NO_OUTCOME_EVIDENCE = 'no_outcome_evidence';

    private const BLOCKER_REGRESSION_OBSERVED = 'regression_observed';

    private const BLOCKER_NEGATIVE_RETAINED_DELTA = 'negative_retained_delta';

    /**
     * Validate a system-discovered engineering method candidate by its measured outcome.
     *
     * @param  array<string, mixed>  $candidate
     * @param  array<string, mixed>  $outcomes
     * @return array{
     *     schema_version: string,
     *     validated: bool,
     *     retained_delta: float,
     *     regression_count: int,
     *     reverted: bool,
     *     evidence_refs: list<string>,
     *     blockers: list<string>
     * }
     */
    public function validate(array $candidate, array $outcomes): array
    {
        $evidenceRefs = $this->evidenceRefs($candidate, $outcomes);
        $regressionCount = $this->regressionCount($outcomes);
        $retainedDelta = $this->retainedDelta($outcomes);

        $blockers = [];

        if ($evidenceRefs === []) {
            $blockers[] = self::BLOCKER_NO_OUTCOME_EVIDENCE;
        }

        if ($regressionCount > 0) {
            $blockers[] = self::BLOCKER_REGRESSION_OBSERVED;
        }

        if ($retainedDelta < 0.0) {
            $blockers[] = self::BLOCKER_NEGATIVE_RETAINED_DELTA;
        }

        $validated = $blockers === [];

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'validated' => $validated,
            'retained_delta' => $retainedDelta,
            'regression_count' => $regressionCount,
            'reverted' => ! $validated,
            'evidence_refs' => $evidenceRefs,
            'blockers' => $blockers,
        ];
    }

    /**
     * Normalised string evidence references proving the outcome was measured.
     * References may be carried on the outcomes payload or the candidate; an
     * empty list means the improvement is unproven and the method is reverted.
     *
     * @param  array<string, mixed>  $candidate
     * @param  array<string, mixed>  $outcomes
     * @return list<string>
     */
    private function evidenceRefs(array $candidate, array $outcomes): array
    {
        $refs = AreaFocusStringListNormalizer::preserveStrings($outcomes['evidence_refs'] ?? []);

        if ($refs === []) {
            $refs = AreaFocusStringListNormalizer::preserveStrings($candidate['evidence_refs'] ?? []);
        }

        return $refs;
    }

    /**
     * Measured regression count from the outcome window. Accepts an explicit
     * regression_count or a regression_metrics.regression_count nesting; any
     * negative value is clamped to zero so a missing/garbled signal cannot
     * masquerade as "no regression earns validation".
     *
     * @param  array<string, mixed>  $outcomes
     */
    private function regressionCount(array $outcomes): int
    {
        if (array_key_exists('regression_count', $outcomes)) {
            return max(0, AreaFocusScalarNormalizer::payloadFiniteInt($outcomes, 'regression_count', 0));
        }

        $metrics = $outcomes['regression_metrics'] ?? null;
        if (is_array($metrics)) {
            return max(0, AreaFocusScalarNormalizer::payloadFiniteInt($metrics, 'regression_count', 0));
        }

        return 0;
    }

    /**
     * Retained outcome improvement measured AFTER the revert window: the delta
     * that actually held, not the peak. Accepts an explicit retained_delta or a
     * retained_metrics.retained_delta nesting. Defaults to a negative sentinel so
     * that an absent retained signal fails closed (cannot be retained on style).
     *
     * @param  array<string, mixed>  $outcomes
     */
    private function retainedDelta(array $outcomes): float
    {
        if (array_key_exists('retained_delta', $outcomes)) {
            return AreaFocusScalarNormalizer::payloadFiniteFloat($outcomes, 'retained_delta', -1.0);
        }

        $metrics = $outcomes['retained_metrics'] ?? null;
        if (is_array($metrics)) {
            return AreaFocusScalarNormalizer::payloadFiniteFloat($metrics, 'retained_delta', -1.0);
        }

        return -1.0;
    }
}
