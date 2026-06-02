<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

/**
 * Verifies a recursive-self-improvement CONVERGENCE proof RESULT envelope
 * against the design-only convergence proof specification produced upstream
 * (the L10 recursive-improvement convergence proof spec builder).
 *
 * L10-R3 only permits recursion up to the depth where a convergence bound is
 * formally PROVEN, and the recursion must run exactly up to that limit and
 * never beyond. A proof result is trustworthy only when it is a verified
 * envelope: it carries a concrete artifact hash, covers every theorem the spec
 * declared, is signed by a verifier identity, was independently reproduced, and
 * never ranges beyond the invariants the spec actually covered. Anything that
 * fails one of those is unverifiable and must never pass; an unverifiable proof
 * collapses the proven recursion depth back to zero.
 *
 * Pure and deterministic: every returned field is computed from the two array
 * inputs by explicit rules. No I/O, clock, randomness or collaborators.
 */
final class L10ConvergenceProofResultVerifier
{
    private const SCHEMA_VERSION = 'atlas.aaeos.l10.convergence_proof_result_verification.v1';

    /**
     * Minimum number of independent re-executions required before a proof
     * result counts as reproducible.
     */
    private const MIN_REPRODUCTIONS = 2;

    private const BLOCKER_ARTIFACT_HASH_MISSING = 'artifact_hash_missing';

    private const BLOCKER_THEOREM_COVERAGE_INCOMPLETE = 'theorem_coverage_incomplete';

    private const BLOCKER_VERIFIER_IDENTITY_MISSING = 'verifier_identity_missing';

    private const BLOCKER_NOT_REPRODUCIBLE = 'not_reproducible';

    private const BLOCKER_PROOF_BEYOND_COVERED_INVARIANTS = 'proof_beyond_covered_invariants';

    /**
     * @param  array<string,mixed>  $result    Convergence proof result envelope under verification.
     * @param  array<string,mixed>  $proofSpec Design-only convergence spec the result claims to satisfy.
     * @return array{
     *     schema_version: string,
     *     verified: bool,
     *     covered_theorem_ids: list<string>,
     *     max_proven_depth: int,
     *     reproducible: bool,
     *     missing_coverage: list<string>,
     *     blockers: list<string>
     * }
     */
    public function verify(array $result, array $proofSpec): array
    {
        $requiredTheoremIds = $this->stringList($proofSpec['theorem_ids'] ?? []);
        $claimedTheoremIds = $this->stringList($result['covered_theorem_ids'] ?? []);

        $coveredTheoremIds = $this->intersection($requiredTheoremIds, $claimedTheoremIds);
        $missingCoverage = $this->difference($requiredTheoremIds, $claimedTheoremIds);

        $reproducible = $this->isReproducible($result);

        $blockers = [];

        if (! $this->hasArtifactHash($result)) {
            $blockers[] = self::BLOCKER_ARTIFACT_HASH_MISSING;
        }

        if (! $this->theoremCoverageComplete($requiredTheoremIds, $missingCoverage)) {
            $blockers[] = self::BLOCKER_THEOREM_COVERAGE_INCOMPLETE;
        }

        if (! $this->hasVerifierIdentity($result)) {
            $blockers[] = self::BLOCKER_VERIFIER_IDENTITY_MISSING;
        }

        if (! $reproducible) {
            $blockers[] = self::BLOCKER_NOT_REPRODUCIBLE;
        }

        if ($this->provesBeyondCoveredInvariants($result, $proofSpec)) {
            $blockers[] = self::BLOCKER_PROOF_BEYOND_COVERED_INVARIANTS;
        }

        $verified = $blockers === [];

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'verified' => $verified,
            'covered_theorem_ids' => $coveredTheoremIds,
            'max_proven_depth' => $this->maxProvenDepth($result, $proofSpec, $verified),
            'reproducible' => $reproducible,
            'missing_coverage' => $missingCoverage,
            'blockers' => $blockers,
        ];
    }

    /**
     * Theorem coverage is complete only when the spec declared at least one
     * theorem and the result claimed every one of them. A spec with no declared
     * theorems can never be covered, so an empty spec is always incomplete.
     *
     * @param  list<string>  $requiredTheoremIds
     * @param  list<string>  $missingCoverage
     */
    private function theoremCoverageComplete(array $requiredTheoremIds, array $missingCoverage): bool
    {
        return $requiredTheoremIds !== [] && $missingCoverage === [];
    }

    /**
     * @param  array<string,mixed>  $result
     */
    private function hasArtifactHash(array $result): bool
    {
        $hash = $result['artifact_hash'] ?? null;

        if (! is_string($hash)) {
            return false;
        }

        return trim($hash) !== '';
    }

    /**
     * @param  array<string,mixed>  $result
     */
    private function hasVerifierIdentity(array $result): bool
    {
        $identity = $result['verifier_id'] ?? null;

        if (! is_string($identity)) {
            return false;
        }

        return trim($identity) !== '';
    }

    /**
     * Reproducible means independently re-executed and matching: at least
     * MIN_REPRODUCTIONS runs whose artifact digests all agreed.
     *
     * @param  array<string,mixed>  $result
     */
    private function isReproducible(array $result): bool
    {
        $count = $this->intValue($result['reproduction_count'] ?? 0);

        $digestsMatch = ($result['reproduction_digests_match'] ?? false) === true;

        return $count >= self::MIN_REPRODUCTIONS && $digestsMatch;
    }

    /**
     * The proof may only range over the invariants the spec actually covered.
     * The covered set is sourced from the spec's explicit covered_invariant_ids
     * and every proof obligation's invariant_id. If the result asserts a proven
     * invariant outside that set, the proof reaches beyond its covered bound and
     * must reject.
     *
     * @param  array<string,mixed>  $result
     * @param  array<string,mixed>  $proofSpec
     */
    private function provesBeyondCoveredInvariants(array $result, array $proofSpec): bool
    {
        $coveredInvariantIds = $this->coveredInvariantIds($proofSpec);
        $provenInvariantIds = $this->stringList($result['proven_invariant_ids'] ?? []);

        foreach ($provenInvariantIds as $invariantId) {
            if (! in_array($invariantId, $coveredInvariantIds, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Invariant ids the spec covers. Sourced from explicit covered_invariant_ids
     * and/or each proof obligation's invariant_id, then deduplicated and ordered
     * for a stable list<string> contract.
     *
     * @param  array<string,mixed>  $proofSpec
     * @return list<string>
     */
    private function coveredInvariantIds(array $proofSpec): array
    {
        $ids = $this->stringList($proofSpec['covered_invariant_ids'] ?? []);

        $obligations = $proofSpec['proof_obligations'] ?? [];

        if (is_array($obligations)) {
            foreach ($obligations as $obligation) {
                if (is_array($obligation) && isset($obligation['invariant_id'])) {
                    $candidate = $this->normaliseId($obligation['invariant_id']);

                    if ($candidate !== '') {
                        $ids[] = $candidate;
                    }
                }
            }
        }

        return $this->uniqueSorted($ids);
    }

    /**
     * The proven recursion depth the envelope earns. A proof can never prove
     * deeper than the spec's recursion depth model bounds, so the claimed depth
     * is clamped to that model. An unverifiable proof proves nothing, so its
     * depth collapses to zero — the bound must exist before the recursion runs.
     *
     * @param  array<string,mixed>  $result
     * @param  array<string,mixed>  $proofSpec
     */
    private function maxProvenDepth(array $result, array $proofSpec, bool $verified): int
    {
        if (! $verified) {
            return 0;
        }

        $claimedDepth = max(0, $this->intValue($result['proven_depth'] ?? 0));
        $modeledDepth = max(0, $this->modeledMaxDepth($proofSpec));

        return min($claimedDepth, $modeledDepth);
    }

    /**
     * The maximum recursion depth the spec's depth model bounds. Read from the
     * recursion_depth_model.max_depth, defaulting to zero when absent.
     *
     * @param  array<string,mixed>  $proofSpec
     */
    private function modeledMaxDepth(array $proofSpec): int
    {
        $model = $proofSpec['recursion_depth_model'] ?? null;

        if (! is_array($model)) {
            return 0;
        }

        return $this->intValue($model['max_depth'] ?? 0);
    }

    /**
     * Required ids that the claimed coverage includes.
     *
     * @param  list<string>  $required
     * @param  list<string>  $claimed
     * @return list<string>
     */
    private function intersection(array $required, array $claimed): array
    {
        $out = [];

        foreach ($required as $id) {
            if (in_array($id, $claimed, true)) {
                $out[] = $id;
            }
        }

        return $out;
    }

    /**
     * Required ids the claimed coverage omits.
     *
     * @param  list<string>  $required
     * @param  list<string>  $claimed
     * @return list<string>
     */
    private function difference(array $required, array $claimed): array
    {
        $out = [];

        foreach ($required as $id) {
            if (! in_array($id, $claimed, true)) {
                $out[] = $id;
            }
        }

        return $out;
    }

    /**
     * Coerce an arbitrary array into a clean, ordered list<string> of ids,
     * dropping blanks and duplicates. Guards the list<string> contract against
     * integer-key coercion and non-string members.
     *
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $ids = [];

        foreach ($value as $item) {
            $candidate = $this->normaliseId($item);

            if ($candidate !== '') {
                $ids[] = $candidate;
            }
        }

        return $this->uniqueSorted($ids);
    }

    /**
     * @param  list<string>  $ids
     * @return list<string>
     */
    private function uniqueSorted(array $ids): array
    {
        $unique = array_unique($ids);

        // String (lexicographic) sort: these are list<string> id contracts, so
        // "ascending" is well-defined only as a string order. Bare sort()/
        // SORT_REGULAR would order numeric-looking ids ('10','9','100')
        // numerically and — worse — leave numerically-equal-but-textually-distinct
        // ids ('1','01','1.0') in input order, so the same theorem/invariant SET
        // presented in a different order would yield a different sorted list and
        // break the verifier's documented determinism.
        sort($unique, SORT_STRING);

        return array_values($unique);
    }

    private function normaliseId(mixed $value): string
    {
        if (is_string($value)) {
            return trim($value);
        }

        if (is_int($value) || is_float($value)) {
            return trim((string) $value);
        }

        return '';
    }

    private function intValue(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_float($value)) {
            return $this->floatToInt($value);
        }

        if (is_string($value) && is_numeric(trim($value))) {
            return $this->floatToInt((float) trim($value));
        }

        return 0;
    }

    /**
     * Coerce a finite float (or numeric-string-derived float) to a deterministic
     * int without leaking runtime warnings or overflow garbage. A non-finite
     * (NAN/INF) value carries no usable depth and collapses to zero. A magnitude
     * at or beyond 2^63 is not representable as an int: casting it emits a runtime
     * warning and overflows to a platform-dependent (even negative) value, which
     * would break purity/determinism and could mis-clamp the proven depth below
     * the spec model. Saturate such magnitudes to PHP_INT_MIN/MAX so the result
     * stays a deterministic int; the downstream max(0, ...) / min(model) clamps
     * then bound it exactly as for any other large value.
     */
    private function floatToInt(float $value): int
    {
        if (! is_finite($value)) {
            return 0;
        }

        if ($value >= 9223372036854775808.0) {
            return PHP_INT_MAX;
        }

        if ($value < -9223372036854775808.0) {
            return PHP_INT_MIN;
        }

        return (int) $value;
    }
}
