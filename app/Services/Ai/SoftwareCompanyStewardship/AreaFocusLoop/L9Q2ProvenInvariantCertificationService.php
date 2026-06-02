<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

/**
 * S132 — L9Q2ProvenInvariantCertificationService (block: L9 Sovereign Engineering).
 *
 * Read-only certification of L9-Q2 ("engineering invariants PROVEN, not just
 * tested") by COMPOSING five upstream artefacts produced earlier in the Q2 line:
 *   - sovereignty (S127): the permanent invariant that the operator is the sole
 *     source of engineering values/ends — the system may articulate operator
 *     criteria but never chooses engineering ends (`system_as_value_source` is
 *     forbidden);
 *   - invariant_set (S128): the sacred, immovable engineering invariant ids
 *     (merge truth, scope, sensitive classes, gates, provider-claim truth,
 *     canonical-doc authority);
 *   - proof_specs (S129): design-only proof obligations for those invariants
 *     (proof_status=not_verified) — they scope what must be proven, never assert
 *     that a proof already exists;
 *   - proof_results (S130): verified proof-result envelopes that actually cover
 *     invariants (each carries a `verified` flag and the invariant ids it covers);
 *   - delegation (S131): the delegation requested for Q1 — every requested
 *     decision class is bound to the invariant it relies on.
 *
 * This certifier is honesty-first and fail-closed. It never promotes a level,
 * never mutates state and never hides a blocker. Q2 is the SAFETY PRECONDITION
 * for Q1/Q3 ("a prova e pre-condicao de qualquer ampliacao de delegacao"), so the
 * verdict leads with sovereignty, then proof, then the proof-bounded delegation:
 *
 * Verdict rules (ordered, safety-first):
 *   1. sovereignty must be locked — the operator is the sole value source and the
 *      system is never marked as a value source; otherwise `sovereignty_missing`;
 *   2. at least one sacred engineering invariant must be PROVEN — i.e. covered by
 *      a `verified` proof-result envelope (a design proof_spec alone is NOT a
 *      proof); otherwise `proof_missing`;
 *   3. no requested delegation class may rely on an invariant that is not proven —
 *      delegation is bounded by proof, never by confidence; any over-reach emits
 *      `delegation_beyond_proof`.
 *   q2_certified=true ONLY when none of the three blockers fire.
 *
 * Bounds honoured exactly: `proven_invariant_count` is the number of DISTINCT
 * invariant-set ids that a verified proof covers and is clamped to
 * 0..count(invariant_set) — it can never exceed the declared invariant set, and a
 * verified proof for an id outside the set never inflates it.
 *
 * Pure: every returned field is computed from the method inputs via the rules
 * above. No I/O, DB, Eloquent, facade, provider, git, filesystem, clock or
 * randomness. Identical inputs always yield an identical certification.
 *
 * @see docs/engineering-knowledge-base/atlas-aaeos-l9-sovereign-engineering-map.md
 */
final class L9Q2ProvenInvariantCertificationService
{
    private const SCHEMA_VERSION = 'atlas.aaeos.l9.q2_proven_invariant_certification.v1';

    /** L9 phase this service certifies. */
    public const PHASE = 'L9-Q2';

    public const STATUS_CERTIFIED = 'l9_q2_proven';

    public const STATUS_BLOCKED = 'blocked_not_l9_q2';

    /** Blocker emitted when operator sovereignty is not locked. */
    public const BLOCKER_SOVEREIGNTY_MISSING = 'sovereignty_missing';

    /** Blocker emitted when no sacred invariant is covered by a verified proof. */
    public const BLOCKER_PROOF_MISSING = 'proof_missing';

    /** Blocker emitted when a requested delegation class exceeds the proven set. */
    public const BLOCKER_DELEGATION_BEYOND_PROOF = 'delegation_beyond_proof';

    /**
     * Risk levels, ordered low -> high. The proven delegation boundary's
     * `max_risk_level` is the highest level among the allowed (proof-bounded)
     * classes; with no allowed class the boundary is `none`.
     *
     * @var list<string>
     */
    private const RISK_ORDER = ['none', 'low', 'medium', 'high'];

    /**
     * Certify L9-Q2 by composing sovereignty, the invariant set, proof specs,
     * verified proof results and the requested delegation.
     *
     * Recognised `$inputs`:
     *   - `sovereignty`: bool, or array carrying `operator_is_sole_source` /
     *     `operator_authority` / `locked` (bool, true => operator is the sole value
     *     source) and optionally `system_as_value_source` (bool, true => the system
     *     claims to be a value source, which forces sovereignty unlocked);
     *   - `invariant_set`: list of the sacred engineering invariant ids. Each item
     *     is a non-empty string id, or an array carrying `invariant_id` / `id`.
     *     Blank/duplicate ids are ignored; ordering of first appearance is kept;
     *   - `proof_specs`: list of design proof obligations (proof_status=not_verified)
     *     — scoping only; a spec NEVER proves an invariant on its own;
     *   - `proof_results`: list of proof-result envelopes. A result proves the ids
     *     in its `covered_invariant_ids` / `covered` list ONLY when it is
     *     `verified` (=== true); unverified results prove nothing;
     *   - `delegation`: list of requested decision classes. Each item is a
     *     non-empty string class bound to an invariant of the same id, or an array
     *     carrying `decision_class` / `class` plus the `invariant_id` / `id` it
     *     relies on and an optional `risk_level` (none/low/medium/high).
     *
     * @param  array<string,mixed>  $inputs
     * @return array{
     *     schema_version:string,
     *     phase:string,
     *     q2_certified:bool,
     *     sovereignty_locked:bool,
     *     invariant_count:int,
     *     proven_invariant_count:int,
     *     proven_invariant_ids:list<string>,
     *     delegation_boundary:array{
     *         allowed_decision_classes:list<string>,
     *         blocked_decision_classes:list<string>,
     *         max_risk_level:string,
     *         within_proof:bool,
     *         operator_override_required:bool
     *     },
     *     status:string,
     *     blockers:list<string>
     * }
     */
    public function certify(array $inputs): array
    {
        $sovereigntyLocked = $this->sovereigntyLocked($inputs['sovereignty'] ?? null);

        $invariantIds = $this->invariantIds($inputs['invariant_set'] ?? null);
        $invariantCount = count($invariantIds);

        $verifiedIds = $this->verifiedInvariantIds($inputs['proof_results'] ?? null);

        // An invariant is PROVEN only when it is in the sacred set AND a verified
        // proof covers it. Walking the invariant set (not the proof envelopes)
        // intersects the two, so a verified proof for an out-of-set id can never
        // inflate the count past count(invariant_set).
        $provenIds = [];
        foreach ($invariantIds as $id) {
            if (in_array($id, $verifiedIds, true)) {
                $provenIds[] = $id;
            }
        }
        $provenCount = count($provenIds);

        $boundary = $this->delegationBoundary($inputs['delegation'] ?? null, $provenIds);

        // Verdict, safety-first: sovereignty -> proof -> proof-bounded delegation.
        $blockers = [];

        if (! $sovereigntyLocked) {
            $blockers[] = self::BLOCKER_SOVEREIGNTY_MISSING;
        }

        if ($provenCount === 0) {
            $blockers[] = self::BLOCKER_PROOF_MISSING;
        }

        if (! $boundary['within_proof']) {
            $blockers[] = self::BLOCKER_DELEGATION_BEYOND_PROOF;
        }

        $certified = $blockers === [];

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'phase' => self::PHASE,
            'q2_certified' => $certified,
            'sovereignty_locked' => $sovereigntyLocked,
            'invariant_count' => $invariantCount,
            'proven_invariant_count' => $provenCount,
            'proven_invariant_ids' => $provenIds,
            'delegation_boundary' => $boundary,
            'status' => $certified ? self::STATUS_CERTIFIED : self::STATUS_BLOCKED,
            'blockers' => $blockers,
        ];
    }

    /**
     * Sovereignty is locked only when the operator is asserted as the sole value
     * source AND the system is never marked as a value source. A bool input is the
     * fast path; an array is fail-closed (no positive authority => unlocked).
     */
    private function sovereigntyLocked(mixed $raw): bool
    {
        if (is_bool($raw)) {
            return $raw;
        }

        if (! is_array($raw)) {
            return false;
        }

        // The system claiming to be a value source forfeits sovereignty outright:
        // "no instante em que o sistema escolhe os proprios fins, deixa de ser
        // substrato de soberania".
        if (($raw['system_as_value_source'] ?? false) === true) {
            return false;
        }

        foreach (['operator_is_sole_source', 'operator_authority', 'locked'] as $flag) {
            if (array_key_exists($flag, $raw)) {
                return $raw[$flag] === true;
            }
        }

        return false;
    }

    /**
     * Distinct, order-preserving, non-empty invariant ids from the sacred set.
     * Honours a list<string> contract: ids must be non-empty strings (int keys or
     * non-string values never coerce into the set).
     *
     * @return list<string>
     */
    private function invariantIds(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $ids = [];
        foreach ($raw as $item) {
            $id = $this->invariantIdOf($item);
            if ($id !== '' && ! in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * Resolve a single invariant id from a set item: a bare non-empty string id,
     * or an array carrying `invariant_id` / `id`.
     */
    private function invariantIdOf(mixed $item): string
    {
        if (is_array($item)) {
            return $this->stringId($item['invariant_id'] ?? ($item['id'] ?? null));
        }

        return $this->stringId($item);
    }

    /**
     * Invariant ids covered by at least one VERIFIED proof-result envelope. An
     * unverified envelope proves nothing; a design proof_spec is not a proof.
     *
     * @return list<string>
     */
    private function verifiedInvariantIds(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $ids = [];
        foreach ($raw as $result) {
            if (! is_array($result)) {
                continue;
            }

            if (($result['verified'] ?? false) !== true) {
                continue;
            }

            foreach ($this->coveredIds($result) as $id) {
                if (! in_array($id, $ids, true)) {
                    $ids[] = $id;
                }
            }
        }

        return $ids;
    }

    /**
     * The invariant ids a single proof-result envelope declares it covers.
     *
     * @param  array<string,mixed>  $result
     * @return list<string>
     */
    private function coveredIds(array $result): array
    {
        foreach (['covered_invariant_ids', 'covered', 'invariant_ids'] as $key) {
            $list = $result[$key] ?? null;
            if (is_array($list)) {
                $ids = [];
                foreach ($list as $item) {
                    $id = $this->stringId($item);
                    if ($id !== '' && ! in_array($id, $ids, true)) {
                        $ids[] = $id;
                    }
                }

                return $ids;
            }
        }

        $single = $this->stringId($result['invariant_id'] ?? ($result['id'] ?? null));

        return $single === '' ? [] : [$single];
    }

    /**
     * Compute the proof-bounded delegation boundary. A requested decision class is
     * ALLOWED only when the invariant it relies on is proven; otherwise it is
     * blocked and the boundary is breached (`within_proof=false`). With no
     * requested delegation the boundary is vacuously within proof.
     *
     * @return array{
     *     allowed_decision_classes:list<string>,
     *     blocked_decision_classes:list<string>,
     *     max_risk_level:string,
     *     within_proof:bool,
     *     operator_override_required:bool
     * }
     */
    private function delegationBoundary(mixed $raw, array $provenIds): array
    {
        $allowed = [];
        $blocked = [];
        // Highest risk index seen per ALLOWED class, keyed by class. The max is
        // derived only from classes that SURVIVE the block-dominates poison below,
        // so a blocked twin can never inflate max_risk_level.
        $allowedRiskIndex = [];

        if (is_array($raw)) {
            foreach ($raw as $item) {
                $class = $this->decisionClass($item);
                if ($class === '') {
                    continue;
                }

                $reliesOn = $this->reliesOnInvariantId($item, $class);

                if (in_array($reliesOn, $provenIds, true)) {
                    if (! in_array($class, $allowed, true)) {
                        $allowed[] = $class;
                    }
                    $allowedRiskIndex[$class] = max($allowedRiskIndex[$class] ?? 0, $this->riskIndex($item));
                } elseif (! in_array($class, $blocked, true)) {
                    $blocked[] = $class;
                }
            }
        }

        // Block dominates: delegation is bounded by proof, so a single over-reaching
        // request for a class poisons it. The same class requested both within proof
        // (allowed) and beyond it (blocked) must NOT leak into the allowed set — it
        // stays blocked. Mirror the sibling boundary service's poison invariant.
        if ($blocked !== []) {
            $allowed = array_values(array_filter(
                $allowed,
                static fn (string $class): bool => ! in_array($class, $blocked, true),
            ));
        }

        // max_risk_level is the highest risk among the SURVIVING allowed classes
        // only; floor 'none' when nothing is allowed and never an out-of-range index.
        $maxRiskIndex = 0;
        foreach ($allowed as $class) {
            $maxRiskIndex = max($maxRiskIndex, $allowedRiskIndex[$class] ?? 0);
        }

        return [
            'allowed_decision_classes' => $allowed,
            'blocked_decision_classes' => $blocked,
            // Risk floor 'none' when nothing is allowed; never an out-of-range index.
            'max_risk_level' => $allowed === [] ? 'none' : self::RISK_ORDER[$maxRiskIndex],
            'within_proof' => $blocked === [],
            // Q2 protects Q1: operator override stays required above the boundary.
            'operator_override_required' => true,
        ];
    }

    private function decisionClass(mixed $item): string
    {
        if (is_array($item)) {
            return $this->stringId($item['decision_class'] ?? ($item['class'] ?? null));
        }

        return $this->stringId($item);
    }

    /**
     * The invariant id a requested class relies on. An array item names it
     * explicitly; a bare-string class is taken to rely on the invariant of the
     * same id (so `merge_truth` delegation is bounded by the `merge_truth` proof).
     */
    private function reliesOnInvariantId(mixed $item, string $class): string
    {
        if (is_array($item)) {
            $explicit = $this->stringId($item['invariant_id'] ?? ($item['relies_on'] ?? ($item['id'] ?? null)));
            if ($explicit !== '') {
                return $explicit;
            }
        }

        return $class;
    }

    /**
     * Risk index of a requested class within RISK_ORDER. Unknown or absent risk is
     * the lowest ('none' => 0); it never returns an out-of-range index.
     */
    private function riskIndex(mixed $item): int
    {
        if (! is_array($item)) {
            return 0;
        }

        $risk = $item['risk_level'] ?? ($item['risk'] ?? null);
        if (! is_string($risk)) {
            return 0;
        }

        $index = array_search($risk, self::RISK_ORDER, true);

        return $index === false ? 0 : $index;
    }

    /**
     * Coerce a candidate id into a trimmed non-empty string, or '' when it is not a
     * usable string id. Non-string scalars are rejected (a list<string> contract is
     * never satisfied by an int/float/bool coerced to text).
     */
    private function stringId(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }
}
