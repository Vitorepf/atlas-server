<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

/**
 * L10-R1 generative-paradigm outcome validator.
 *
 * A generative engineering paradigm candidate (a genuinely new abstraction or
 * method proposed by the system) earns adoption into canon only when THREE
 * independent proofs hold together: the novelty is real (outside known space,
 * not a renamed existing pattern), the claimed improvement is proven by a
 * positive RETAINED outcome (not a transient peak), the candidate preserves
 * engineering invariants (safety proof), and the operator explicitly curates it
 * into canon. Generativity is proven by retained outcome and curated adoption;
 * it is never self-authorised.
 *
 * This validator is read-only and pure: it never admits a paradigm to runtime,
 * never writes state, never calls a provider, clock or randomness, and computes
 * every returned field from its inputs.
 *
 * Validation rules, applied in this fixed order (fail-closed first):
 *   1. novelty_status not 'new'                 -> blocks (unknown / known / absent
 *      novelty cannot be validated as generative).
 *   2. retained_delta <= 0 or non-finite (NaN)   -> blocks (no proven positive
 *      retained improvement means nothing generative was proven; a NaN signal is
 *      undefined, never a proven improvement, and fails closed).
 *   3. invariant_safe is false                   -> blocks (a violated engineering
 *      invariant rejects the paradigm regardless of outcome).
 *   4. operator did not admit to canon           -> blocks (adoption is curated,
 *      never implicit and never system-chosen).
 *
 * A paradigm is admitted to canon (canon_admitted=true) only when it is validated,
 * which requires every gate above to pass.
 */
final class L10GenerativeParadigmOutcomeValidator
{
    private const SCHEMA_VERSION = 'atlas.aaeos.l10.generative_paradigm_outcome_validation.v1';

    private const NOVELTY_STATUS_NEW = 'new';

    private const BLOCKER_NOVELTY_NOT_NEW = 'novelty_not_new';

    private const BLOCKER_NON_POSITIVE_RETAINED_DELTA = 'non_positive_retained_delta';

    private const BLOCKER_INVARIANT_UNSAFE = 'invariant_unsafe';

    private const BLOCKER_NO_OPERATOR_CANON_DECISION = 'no_operator_canon_decision';

    /**
     * Validate a generative paradigm candidate by retained outcome, safety proof
     * and operator-curated canon decision.
     *
     * @param  array<string, mixed>  $candidate
     * @param  array<string, mixed>  $novelty
     * @param  array<string, mixed>  $outcomes
     * @param  array<string, mixed>  $operatorDecision
     * @return array{
     *     schema_version: string,
     *     validated: bool,
     *     retained_delta: float,
     *     invariant_safe: bool,
     *     canon_admitted: bool,
     *     blockers: list<string>
     * }
     */
    public function validate(
        array $candidate,
        array $novelty,
        array $outcomes,
        array $operatorDecision,
    ): array {
        $noveltyIsNew = $this->noveltyIsNew($novelty);
        $retainedDelta = $this->retainedDelta($outcomes);
        $invariantSafe = $this->invariantSafe($candidate, $outcomes);
        $operatorAdmitted = $this->operatorAdmittedToCanon($operatorDecision);

        $blockers = [];

        if (! $noveltyIsNew) {
            $blockers[] = self::BLOCKER_NOVELTY_NOT_NEW;
        }

        if ($retainedDelta <= 0.0 || is_nan($retainedDelta)) {
            $blockers[] = self::BLOCKER_NON_POSITIVE_RETAINED_DELTA;
        }

        if (! $invariantSafe) {
            $blockers[] = self::BLOCKER_INVARIANT_UNSAFE;
        }

        if (! $operatorAdmitted) {
            $blockers[] = self::BLOCKER_NO_OPERATOR_CANON_DECISION;
        }

        $validated = $blockers === [];

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'validated' => $validated,
            'retained_delta' => $retainedDelta,
            'invariant_safe' => $invariantSafe,
            'canon_admitted' => $validated,
            'blockers' => $blockers,
        ];
    }

    /**
     * The paradigm is novel only when the upstream novelty discrimination reports
     * an explicit 'new' status. Any other status (unknown, unknown_not_new, known,
     * renamed, absent) fails closed: generative validation cannot proceed on a
     * candidate that is not proven to be outside known space.
     *
     * @param  array<string, mixed>  $novelty
     */
    private function noveltyIsNew(array $novelty): bool
    {
        $status = $novelty['novelty_status'] ?? null;

        return is_string($status) && $status === self::NOVELTY_STATUS_NEW;
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
            return $this->floatValue($outcomes, 'retained_delta', -1.0);
        }

        $metrics = $outcomes['retained_metrics'] ?? null;
        if (is_array($metrics)) {
            return $this->floatValue($metrics, 'retained_delta', -1.0);
        }

        return -1.0;
    }

    /**
     * The candidate is invariant-safe only when a safety proof is present AND no
     * invariant violation is reported. The proof may be carried on the outcomes
     * payload or the candidate. A reported violation always wins over a claimed
     * proof, and an absent proof fails closed (unsafe until proven safe).
     *
     * @param  array<string, mixed>  $candidate
     * @param  array<string, mixed>  $outcomes
     */
    private function invariantSafe(array $candidate, array $outcomes): bool
    {
        $violationCount = max(
            $this->violationCount($outcomes),
            $this->violationCount($candidate),
        );

        if ($violationCount > 0) {
            return false;
        }

        return $this->safetyProofPresent($outcomes) || $this->safetyProofPresent($candidate);
    }

    /**
     * Count of reported engineering invariant violations. Accepts an explicit
     * invariant_violation_count or a safety_proof.invariant_violation_count
     * nesting; any negative value is clamped to zero so a garbled signal cannot
     * masquerade as "no violation".
     *
     * @param  array<string, mixed>  $payload
     */
    private function violationCount(array $payload): int
    {
        if (array_key_exists('invariant_violation_count', $payload)) {
            return max(0, $this->intValue($payload, 'invariant_violation_count', 0));
        }

        $proof = $payload['safety_proof'] ?? null;
        if (is_array($proof) && array_key_exists('invariant_violation_count', $proof)) {
            return max(0, $this->intValue($proof, 'invariant_violation_count', 0));
        }

        return 0;
    }

    /**
     * A safety proof is present when at least one non-empty safety reference is
     * supplied. References may sit on safety_refs directly or inside a
     * safety_proof.safety_refs nesting. An empty set means no proof.
     *
     * @param  array<string, mixed>  $payload
     */
    private function safetyProofPresent(array $payload): bool
    {
        if (AreaFocusStringListNormalizer::preserveStrings($payload['safety_refs'] ?? []) !== []) {
            return true;
        }

        $proof = $payload['safety_proof'] ?? null;
        if (is_array($proof) && AreaFocusStringListNormalizer::preserveStrings($proof['safety_refs'] ?? []) !== []) {
            return true;
        }

        return false;
    }

    /**
     * The operator admitted the paradigm to canon only with an explicit, positive
     * canon decision. Both an admit_to_canon=true flag and a decision='admit'
     * verb are accepted, but anything else (reject, defer, missing, implicit)
     * fails closed: adoption is curated, never assumed.
     *
     * @param  array<string, mixed>  $operatorDecision
     */
    private function operatorAdmittedToCanon(array $operatorDecision): bool
    {
        if (($operatorDecision['admit_to_canon'] ?? false) !== true) {
            return false;
        }

        $decision = $operatorDecision['decision'] ?? 'admit';

        return is_string($decision) && $decision === 'admit';
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function intValue(array $payload, string $key, int $default): int
    {
        $value = $payload[$key] ?? $default;

        if (is_int($value)) {
            return $value;
        }

        if (is_float($value) || (is_string($value) && is_numeric($value))) {
            return (int) $value;
        }

        return $default;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function floatValue(array $payload, string $key, float $default): float
    {
        $value = $payload[$key] ?? $default;

        if (is_int($value)) {
            return (float) $value;
        }

        // A non-finite retained delta (NAN, +/-INF) is not a proven measured
        // outcome. Both NAN (NAN <= 0.0 is false) and +INF (INF <= 0.0 is false)
        // would slip past the non-positive-delta veto and validate a paradigm on
        // a degenerate, undefined signal (and poison JSON). Treat any non-finite
        // value as the absent retained signal -> fail closed via the sentinel.
        if (is_float($value)) {
            return is_finite($value) ? $value : $default;
        }

        if (is_string($value) && is_numeric($value)) {
            $float = (float) $value;

            return is_finite($float) ? $float : $default;
        }

        return $default;
    }
}
