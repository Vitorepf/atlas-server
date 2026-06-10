<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

/**
 * S129 — L9InvariantFormalProofSpecBuilder (block: L9 Sovereign Engineering, phase Q2).
 *
 * Produces a DESIGN-ONLY proof specification for the L9 engineering invariant set.
 * The L9 sovereign-engineering map (Q2) says the arrival criterion is "a set of
 * engineering invariants whose non-violation is PROVED (not just tested) and which
 * the system cannot move". This builder designs the proof BOUNDARY for that goal —
 * the theorems to discharge, the assumptions the boundary rests on, the obligations
 * a future verifier must satisfy and the requirements that verifier itself must meet.
 * It NEVER claims that formal verification already exists.
 *
 * Honesty rules (the operator does not accept false claims):
 *   - `proof_status` is ALWAYS {@see PROOF_STATUS_NOT_VERIFIED}; no input flips it;
 *   - `proven` is ALWAYS false — a generated spec is a design artifact, never a proof;
 *   - `claims_formal_verification` is ALWAYS false — the spec describes work to do,
 *     it does not assert the work is done;
 *   - `delegation_authorized` is ALWAYS false — the proof boundary is designed BEFORE
 *     any delegation expands; delegation stays gated behind a verifier that does not
 *     yet exist (DoD: "Design the proof boundary before any delegation expands");
 *   - an empty invariant set blocks: there is nothing to prove, so the spec is
 *     `blocked` with no theorems and no obligations.
 *
 * Pure builder function: no I/O, DB, Eloquent, facades, HTTP/provider calls,
 * git/Process, filesystem, clock/now() or randomness. `spec_id` is a DETERMINISTIC
 * content digest (sha256 over the sorted distinct invariant slugs), so identical
 * input always yields an identical spec. Every theorem id, obligation and count is
 * COMPUTED from the `$invariants` argument via real rules — feeding a different
 * invariant set yields a correspondingly different spec.
 */
final class L9InvariantFormalProofSpecBuilder
{
    /**
     * Canonical schema. Mirrors the `atlas.loop.*.v1` pattern of the sibling pure
     * builders that live beside it (L7ProofBundleService, AutonomyTierPromotion...).
     */
    private const SCHEMA_VERSION = 'atlas.loop.l9_invariant_formal_proof_spec.v1';

    /** The only proof status a design-only spec may carry. */
    public const PROOF_STATUS_NOT_VERIFIED = 'not_verified';

    public const STATUS_DESIGN_SPEC_READY = 'design_spec_ready';

    public const STATUS_BLOCKED = 'blocked';

    public const BLOCKER_EMPTY_INVARIANT_SET = 'empty_invariant_set';

    /**
     * Assumptions the proof boundary rests on. These are axioms of the boundary
     * itself (true regardless of which invariants are supplied), declared so the
     * design never silently assumes more than it states. The final assumption is
     * the honesty anchor: no formal verifier is attached yet.
     *
     * @var list<string>
     */
    private const BOUNDARY_ASSUMPTIONS = [
        'operator_is_sole_source_of_engineering_ends',
        'engineering_scope_only',
        'invariant_set_is_explicit_and_immovable',
        'no_formal_verifier_attached_yet',
    ];

    /**
     * Requirements a future verifier must satisfy for this spec to be dischargeable.
     * These align with what the downstream proof-result verifier (S130) checks:
     * artifact hash, verifier identity, reproducibility and full theorem coverage.
     *
     * @var list<string>
     */
    private const VERIFIER_REQUIREMENTS = [
        'artifact_hash_required',
        'verifier_identity_required',
        'reproducibility_required',
        'full_theorem_coverage_required',
    ];

    /**
     * Build the design-only proof specification from an invariant set.
     *
     * `$invariants` is the invariant set: a list whose elements are either a plain
     * string (the invariant id) or an array carrying `invariant_id`/`id` and an
     * optional `statement`/`claim`. Entries with no resolvable invariant id are
     * dropped; distinct invariants are de-duplicated by their normalized slug,
     * preserving first-seen order.
     *
     * @param  array<int|string, mixed>  $invariants
     * @return array{
     *     schema_version: string,
     *     spec_id: string,
     *     invariant_ids: list<string>,
     *     invariant_count: int,
     *     theorem_ids: list<string>,
     *     theorem_count: int,
     *     assumptions: list<string>,
     *     proof_obligations: list<array{obligation_id: string, theorem_id: string, invariant_id: string, statement: string, discharged: bool}>,
     *     proof_obligation_count: int,
     *     verifier_requirements: list<string>,
     *     required_theorem_coverage: int,
     *     proof_status: string,
     *     proven: bool,
     *     claims_formal_verification: bool,
     *     delegation_authorized: bool,
     *     status: string,
     *     blockers: list<string>
     * }
     */
    public function build(array $invariants): array
    {
        // Normalize + de-duplicate the invariant set, keyed by slug (first wins).
        $invariantIds = [];
        $theoremIds = [];
        $obligations = [];

        foreach ($invariants as $entry) {
            $invariantId = $this->invariantId($entry);
            if ($invariantId === '') {
                continue;
            }

            $slug = AreaFocusSlugNormalizer::lowerSnakeToken($invariantId);
            if ($slug === '' || isset($invariantIds[$slug])) {
                continue;
            }

            $theoremId = 'theorem.'.$slug;

            $invariantIds[$slug] = $invariantId;
            $theoremIds[$slug] = $theoremId;
            $obligations[$slug] = [
                'obligation_id' => 'obligation.'.$slug,
                'theorem_id' => $theoremId,
                'invariant_id' => $invariantId,
                'statement' => $this->obligationStatement($invariantId, $this->statement($entry)),
                // A design-time obligation is never discharged: nothing is proven yet.
                'discharged' => false,
            ];
        }

        // array_values collapses any int-coerced slug keys back to a clean
        // sequential list<string>; the stored VALUES are always strings.
        $invariantIdList = array_values($invariantIds);
        $theoremIdList = array_values($theoremIds);
        $obligationList = array_values($obligations);

        $blockers = [];
        if ($invariantIdList === []) {
            // Nothing to prove -> the proof boundary has no surface to design.
            $blockers[] = self::BLOCKER_EMPTY_INVARIANT_SET;
        }

        $status = $blockers === []
            ? self::STATUS_DESIGN_SPEC_READY
            : self::STATUS_BLOCKED;

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'spec_id' => $this->specId($theoremIdList),
            'invariant_ids' => $invariantIdList,
            'invariant_count' => count($invariantIdList),
            'theorem_ids' => $theoremIdList,
            'theorem_count' => count($theoremIdList),
            'assumptions' => self::BOUNDARY_ASSUMPTIONS,
            'proof_obligations' => $obligationList,
            'proof_obligation_count' => count($obligationList),
            'verifier_requirements' => self::VERIFIER_REQUIREMENTS,
            // The verifier must cover every theorem the spec declares.
            'required_theorem_coverage' => count($theoremIdList),
            // Honesty invariants — never moved by any input.
            'proof_status' => self::PROOF_STATUS_NOT_VERIFIED,
            'proven' => false,
            'claims_formal_verification' => false,
            'delegation_authorized' => false,
            'status' => $status,
            'blockers' => $blockers,
        ];
    }

    /**
     * Resolve the invariant id from a list entry. Accepts a plain string id or an
     * array carrying `invariant_id`/`id`. Returns '' when no id is present.
     */
    private function invariantId(mixed $entry): string
    {
        if (is_string($entry)) {
            return trim($entry);
        }

        if (is_array($entry)) {
            foreach (['invariant_id', 'id'] as $key) {
                $value = $entry[$key] ?? null;
                if (is_string($value) && trim($value) !== '') {
                    return trim($value);
                }
            }
        }

        return '';
    }

    /**
     * Resolve the optional invariant statement from an array entry.
     */
    private function statement(mixed $entry): string
    {
        if (is_array($entry)) {
            foreach (['statement', 'claim'] as $key) {
                $value = $entry[$key] ?? null;
                if (is_string($value) && trim($value) !== '') {
                    return trim($value);
                }
            }
        }

        return '';
    }

    /**
     * The proof obligation each invariant induces: prove that no reachable system
     * action violates it. Uses the invariant's own statement when supplied.
     */
    private function obligationStatement(string $invariantId, string $statement): string
    {
        if ($statement !== '') {
            return 'No reachable system action may violate: '.$statement;
        }

        return 'No reachable system action may violate invariant '.$invariantId;
    }

    /**
     * Deterministic content-addressed spec id: sha256 over the sorted distinct
     * theorem ids, so the same invariant set (in any order) maps to one spec id
     * and a different set maps to a different id. No clock, no randomness.
     *
     * @param  list<string>  $theoremIds
     */
    private function specId(array $theoremIds): string
    {
        $sorted = $theoremIds;
        sort($sorted);

        return 'l9proof_'.substr(hash('sha256', implode('|', $sorted)), 0, 16);
    }
}
