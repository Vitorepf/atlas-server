<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

/**
 * Verifies a proof RESULT envelope against the design-only proof
 * specification produced upstream (L9InvariantFormalProofSpecBuilder).
 *
 * L9-Q2 turns sacred engineering invariants into proofs, not prose. A proof
 * result is trustworthy only when it is a verified envelope: it names a
 * theorem the spec actually declared, carries a concrete artifact hash, is
 * signed by a verifier identity, covers every invariant the spec obliged it
 * to cover, and was independently reproduced. Anything missing one of those
 * is unverifiable and must never pass.
 *
 * Pure and deterministic: every returned field is computed from the two array
 * inputs by explicit rules. No I/O, clock, randomness or collaborators.
 */
final class L9InvariantProofResultVerifier
{
    private const SCHEMA_VERSION = 'atlas.aaeos.l9.invariant_proof_result_verification.v1';

    /**
     * Minimum number of independent re-executions required before a proof
     * result counts as reproducible.
     */
    private const MIN_REPRODUCTIONS = 2;

    private const BLOCKER_ARTIFACT_HASH_MISSING = 'artifact_hash_missing';

    private const BLOCKER_THEOREM_MISMATCH = 'theorem_mismatch';

    private const BLOCKER_VERIFIER_IDENTITY_MISSING = 'verifier_identity_missing';

    private const BLOCKER_NOT_REPRODUCIBLE = 'not_reproducible';

    private const BLOCKER_INVARIANT_COVERAGE_INCOMPLETE = 'invariant_coverage_incomplete';

    /**
     * @param  array<string,mixed>  $result    Proof result envelope under verification.
     * @param  array<string,mixed>  $proofSpec Design-only spec the result claims to satisfy.
     * @return array{
     *     schema_version: string,
     *     verified: bool,
     *     covered_invariant_ids: list<string>,
     *     missing_coverage: list<string>,
     *     reproducible: bool,
     *     blockers: list<string>
     * }
     */
    public function verify(array $result, array $proofSpec): array
    {
        $requiredInvariantIds = $this->requiredInvariantIds($proofSpec);
        $claimedCoverage = $this->stringList($result['covered_invariant_ids'] ?? []);

        $coveredInvariantIds = $this->intersection($requiredInvariantIds, $claimedCoverage);
        $missingCoverage = $this->difference($requiredInvariantIds, $claimedCoverage);

        $reproducible = $this->isReproducible($result);

        $blockers = [];

        if (! $this->hasArtifactHash($result)) {
            $blockers[] = self::BLOCKER_ARTIFACT_HASH_MISSING;
        }

        if (! $this->theoremMatches($result, $proofSpec)) {
            $blockers[] = self::BLOCKER_THEOREM_MISMATCH;
        }

        if (! $this->hasVerifierIdentity($result)) {
            $blockers[] = self::BLOCKER_VERIFIER_IDENTITY_MISSING;
        }

        if (! $reproducible) {
            $blockers[] = self::BLOCKER_NOT_REPRODUCIBLE;
        }

        if ($missingCoverage !== []) {
            $blockers[] = self::BLOCKER_INVARIANT_COVERAGE_INCOMPLETE;
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'verified' => $blockers === [],
            'covered_invariant_ids' => $coveredInvariantIds,
            'missing_coverage' => $missingCoverage,
            'reproducible' => $reproducible,
            'blockers' => $blockers,
        ];
    }

    /**
     * Invariant ids the spec obliges the proof to cover. Sourced from explicit
     * required_invariant_ids and/or each proof obligation's invariant_id, then
     * deduplicated and ordered for a stable list<string> contract.
     *
     * @param  array<string,mixed>  $proofSpec
     * @return list<string>
     */
    private function requiredInvariantIds(array $proofSpec): array
    {
        $ids = $this->stringList($proofSpec['required_invariant_ids'] ?? []);

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
     * The result's theorem must be one the spec actually declared. A spec with
     * no declared theorems can never be matched.
     *
     * @param  array<string,mixed>  $result
     * @param  array<string,mixed>  $proofSpec
     */
    private function theoremMatches(array $result, array $proofSpec): bool
    {
        $theoremId = $this->normaliseId($result['theorem_id'] ?? '');

        if ($theoremId === '') {
            return false;
        }

        $declared = $this->stringList($proofSpec['theorem_ids'] ?? []);

        return in_array($theoremId, $declared, true);
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
        $count = $result['reproduction_count'] ?? 0;
        $count = is_int($count) ? $count : (is_numeric($count) ? (int) $count : 0);

        $digestsMatch = ($result['reproduction_digests_match'] ?? false) === true;

        return $count >= self::MIN_REPRODUCTIONS && $digestsMatch;
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
        // ids ('1','01','1.0') in input order, so the same invariant SET presented
        // in a different order would yield a different sorted list and break the
        // verifier's documented determinism.
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
}
