<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

/**
 * S148 — L10RecursiveImprovementConvergenceProofSpecBuilder (block: L10 Generative
 * Engineering Guard, phase R3).
 *
 * Produces a DESIGN-ONLY proof specification for the bound under which recursive
 * self-improvement (the loop improving the machinery that improves its machinery)
 * is guaranteed to CONVERGE and stay SAFE, rather than diverge or game its own
 * metric. The L10 generative-engineering map (R3) is explicit: recursion is permitted
 * only UP TO the depth where a convergence bound is PROVED, inherits P5 (immunity to
 * self-deception) and Q2 (proven invariants), and any divergence/gaming signal forces
 * a hard stop. The DoD is "Design the bound before any recursion expands".
 *
 * This builder designs that bound — the theorems to discharge, the depth model the
 * recursion must respect, the convergence metric the bound is stated over, the
 * assumptions the boundary rests on and the obligations a future verifier must
 * satisfy. It NEVER claims a bound has been proved, and — load-bearing — it NEVER
 * authorizes recursion to actually expand.
 *
 * Honesty rules (the operator does not accept false capability claims):
 *   - `proof_status` is ALWAYS {@see PROOF_STATUS_NOT_VERIFIED}; no input flips it;
 *   - `recursion_allowed` is ALWAYS false — the spec designs the bound, it does not
 *     open the gate; recursion stays at proven depth 0 until a real verifier proves
 *     a deeper bound (DoD: "Design the bound before any recursion expands");
 *   - `proven` is ALWAYS false — a generated spec is a design artifact, never a proof;
 *   - `claims_convergence_proof` is ALWAYS false — the spec describes work to do,
 *     it does not assert convergence is already established;
 *   - the proven depth in the depth model is ALWAYS 0 — nothing is proved yet, so no
 *     recursion depth may be relied upon;
 *   - missing P5 evidence blocks (R3 inherits P5; without it the bound cannot rest on
 *     self-deception immunity);
 *   - missing Q2 evidence blocks (R3 inherits Q2; without proven invariants the bound
 *     has no invariant floor to converge above).
 *
 * Pure builder function: no I/O, DB, Eloquent, facades, HTTP/provider calls,
 * git/Process, filesystem, clock/now() or randomness. `spec_id` is a DETERMINISTIC
 * content digest (sha256 over the sorted distinct theorem ids), so identical input
 * always yields an identical spec. Every theorem id, obligation, depth field and
 * metric field is COMPUTED from the `$preconditions` argument via real rules —
 * feeding a different precondition payload yields a correspondingly different spec.
 *
 * P5/Q2 evidence is resolved fail-closed, mirroring the sibling L9 certification
 * services byte-for-byte: a pillar counts as present only on an explicit true (a
 * bool, an array asserting `*_certified`/`certified`/`passed`, or the matching
 * top-level `*_certified` flag). Absent or false => missing => blocked.
 */
final class L10RecursiveImprovementConvergenceProofSpecBuilder
{
    /**
     * Canonical schema. Mirrors the `atlas.loop.*.v1` pattern of the sibling pure
     * builders that live beside it (L9InvariantFormalProofSpecBuilder, the
     * AutonomyTier... evaluator).
     */
    private const SCHEMA_VERSION = 'atlas.loop.l10_recursive_improvement_convergence_proof_spec.v1';

    /** The only proof status a design-only spec may carry. */
    public const PROOF_STATUS_NOT_VERIFIED = 'not_verified';

    public const STATUS_DESIGN_SPEC_READY = 'design_spec_ready';

    public const STATUS_BLOCKED = 'blocked';

    public const BLOCKER_MISSING_P5_EVIDENCE = 'missing_p5_evidence';

    public const BLOCKER_MISSING_Q2_EVIDENCE = 'missing_q2_evidence';

    /**
     * The two load-bearing theorems every convergence bound must discharge,
     * regardless of which recursion classes are supplied. They encode the R3 gate:
     * the recursion converges (does not explode) AND never violates an invariant
     * (does not game/diverge). Emitted first so the bound is always anchored on them.
     *
     * @var list<string>
     */
    private const CORE_THEOREM_SLUGS = [
        'recursion_converges_within_bound',
        'recursion_preserves_invariants',
    ];

    /**
     * Assumptions the convergence bound rests on. These are axioms of the bound
     * itself (true regardless of which recursion classes are supplied), declared so
     * the design never silently assumes more than it states. The final assumption is
     * the honesty anchor: no convergence proof is attached yet.
     *
     * @var list<string>
     */
    private const BOUNDARY_ASSUMPTIONS = [
        'operator_is_sole_source_of_engineering_ends',
        'p5_self_deception_immunity_holds',
        'q2_proven_invariants_hold',
        'recursion_runs_only_up_to_proven_depth',
        'divergence_or_gaming_forces_hard_stop',
        'no_convergence_proof_attached_yet',
    ];

    /**
     * Requirements a future verifier must satisfy for this spec to be dischargeable.
     * These align with what the downstream convergence-proof-result verifier (S149)
     * checks: artifact hash, verifier identity, reproducibility, full theorem
     * coverage and an explicit proven recursion depth.
     *
     * @var list<string>
     */
    private const VERIFIER_REQUIREMENTS = [
        'artifact_hash_required',
        'verifier_identity_required',
        'reproducibility_required',
        'full_theorem_coverage_required',
        'proven_recursion_depth_required',
    ];

    /**
     * Build the design-only convergence proof specification from the L10
     * preconditions.
     *
     * `$preconditions` carries, at minimum, the P5 and Q2 evidence the R3 bound
     * inherits (keyed `p5`/`q2`, fail-closed). It MAY also carry:
     *   - `recursion_classes`: a list of the self-improvement recursion classes whose
     *     convergence must be proved. Each element is a plain string id or an array
     *     carrying `class_id`/`id` plus an optional `statement`/`description`. Entries
     *     with no resolvable id are dropped; distinct classes are de-duplicated by
     *     normalized slug, preserving first-seen order. Each surviving class induces
     *     its own theorem + obligation on top of the two core theorems.
     *   - `requested_max_depth`: the recursion depth the caller WANTS proved. Echoed
     *     into the depth model as the request; it NEVER becomes the proven depth,
     *     which stays 0 until a real proof exists.
     *
     * @param  array<string, mixed>  $preconditions
     * @return array{
     *     schema_version: string,
     *     spec_id: string,
     *     recursion_class_ids: list<string>,
     *     recursion_class_count: int,
     *     theorem_ids: list<string>,
     *     theorem_count: int,
     *     recursion_depth_model: array{
     *         requested_max_depth: int,
     *         proven_max_depth: int,
     *         allowed_max_depth: int,
     *         model: string,
     *         depth_zero_is_no_op: bool
     *     },
     *     convergence_metric: array{
     *         metric_id: string,
     *         name: string,
     *         direction: string,
     *         convergence_predicate: string,
     *         lower_bound: float,
     *         upper_bound: float,
     *         measured: bool
     *     },
     *     assumptions: list<string>,
     *     proof_obligations: list<array{obligation_id: string, theorem_id: string, target: string, statement: string, discharged: bool}>,
     *     proof_obligation_count: int,
     *     verifier_requirements: list<string>,
     *     required_theorem_coverage: int,
     *     proof_status: string,
     *     recursion_allowed: bool,
     *     proven: bool,
     *     claims_convergence_proof: bool,
     *     status: string,
     *     blockers: list<string>
     * }
     */
    public function build(array $preconditions): array
    {
        $theoremIds = [];
        $obligations = [];

        // Core theorems first — always present, anchoring every convergence bound.
        foreach (self::CORE_THEOREM_SLUGS as $coreSlug) {
            $theoremId = 'theorem.'.$coreSlug;
            $theoremIds[$coreSlug] = $theoremId;
            $obligations[$coreSlug] = [
                'obligation_id' => 'obligation.'.$coreSlug,
                'theorem_id' => $theoremId,
                'target' => $coreSlug,
                'statement' => $this->coreObligationStatement($coreSlug),
                // A design-time obligation is never discharged: nothing is proven yet.
                'discharged' => false,
            ];
        }

        // Per-recursion-class theorems on top, computed from the supplied classes.
        foreach ($this->recursionClasses($preconditions) as $entry) {
            $classId = $this->classId($entry);
            if ($classId === '') {
                continue;
            }

            $slug = AreaFocusSlugNormalizer::lowerSnakeToken($classId);
            if ($slug === '' || isset($theoremIds[$slug])) {
                continue;
            }

            $theoremId = 'theorem.'.$slug;
            $theoremIds[$slug] = $theoremId;
            $obligations[$slug] = [
                'obligation_id' => 'obligation.'.$slug,
                'theorem_id' => $theoremId,
                'target' => $classId,
                'statement' => $this->classObligationStatement($classId, $this->statement($entry)),
                'discharged' => false,
            ];
        }

        // array_values collapses any int-coerced slug keys back to a clean
        // sequential list<string>; the stored VALUES are always strings.
        $theoremIdList = array_values($theoremIds);
        $obligationList = array_values($obligations);
        $classIdList = $this->resolvedClassIds($preconditions);

        $blockers = [];
        if (! $this->pillarPresent($preconditions, 'p5')) {
            // R3 inherits P5: without self-deception immunity the bound is unsafe.
            $blockers[] = self::BLOCKER_MISSING_P5_EVIDENCE;
        }
        if (! $this->pillarPresent($preconditions, 'q2')) {
            // R3 inherits Q2: without proven invariants the bound has no floor.
            $blockers[] = self::BLOCKER_MISSING_Q2_EVIDENCE;
        }

        $status = $blockers === []
            ? self::STATUS_DESIGN_SPEC_READY
            : self::STATUS_BLOCKED;

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'spec_id' => $this->specId($theoremIdList),
            'recursion_class_ids' => $classIdList,
            'recursion_class_count' => count($classIdList),
            'theorem_ids' => $theoremIdList,
            'theorem_count' => count($theoremIdList),
            'recursion_depth_model' => $this->recursionDepthModel($preconditions),
            'convergence_metric' => $this->convergenceMetric(),
            'assumptions' => self::BOUNDARY_ASSUMPTIONS,
            'proof_obligations' => $obligationList,
            'proof_obligation_count' => count($obligationList),
            'verifier_requirements' => self::VERIFIER_REQUIREMENTS,
            // The verifier must cover every theorem the spec declares.
            'required_theorem_coverage' => count($theoremIdList),
            // Honesty invariants — never moved by any input.
            'proof_status' => self::PROOF_STATUS_NOT_VERIFIED,
            'recursion_allowed' => false,
            'proven' => false,
            'claims_convergence_proof' => false,
            'status' => $status,
            'blockers' => $blockers,
        ];
    }

    /**
     * The recursion-depth model the bound is stated over. `requested_max_depth` echoes
     * the caller's ask (clamped to be non-negative); `proven_max_depth` is ALWAYS 0
     * because nothing is proved at design time; `allowed_max_depth` is therefore 0 —
     * recursion may not actually expand. This is the load-bearing safety field:
     * design the bound now, run the recursion only after a verifier proves a depth.
     *
     * @param  array<string, mixed>  $preconditions
     * @return array{requested_max_depth: int, proven_max_depth: int, allowed_max_depth: int, model: string, depth_zero_is_no_op: bool}
     */
    private function recursionDepthModel(array $preconditions): array
    {
        $requested = $this->requestedMaxDepth($preconditions);

        return [
            'requested_max_depth' => $requested,
            // Nothing is proved yet -> the only depth that may be relied on is 0.
            'proven_max_depth' => 0,
            // Allowed never exceeds proven; at design time that is 0.
            'allowed_max_depth' => 0,
            'model' => 'bounded_contraction_over_recursion_depth',
            'depth_zero_is_no_op' => true,
        ];
    }

    /**
     * The convergence metric the bound is stated over: a non-increasing contraction
     * measure across recursion depth, bounded to the unit interval, with the
     * convergence predicate the future proof must establish. `measured` is false —
     * the metric is specified by design, not yet measured by any run.
     *
     * @return array{metric_id: string, name: string, direction: string, convergence_predicate: string, lower_bound: float, upper_bound: float, measured: bool}
     */
    private function convergenceMetric(): array
    {
        return [
            'metric_id' => 'metric.recursive_improvement_contraction_factor',
            'name' => 'recursive_improvement_contraction_factor',
            // The metric must shrink as depth grows for the recursion to converge.
            'direction' => 'non_increasing',
            'convergence_predicate' => 'contraction_factor_below_one_at_every_proven_depth',
            'lower_bound' => 0.0,
            'upper_bound' => 1.0,
            'measured' => false,
        ];
    }

    /**
     * The obligation each core theorem induces.
     */
    private function coreObligationStatement(string $coreSlug): string
    {
        return match ($coreSlug) {
            'recursion_converges_within_bound' => 'Prove the self-improvement recursion converges within a finite proven depth bound',
            'recursion_preserves_invariants' => 'Prove the self-improvement recursion never violates a Q2 invariant at any proven depth',
            default => 'Prove convergence obligation '.$coreSlug,
        };
    }

    /**
     * The proof obligation each recursion class induces: prove its sub-recursion
     * converges and stays safe. Uses the class's own statement when supplied.
     */
    private function classObligationStatement(string $classId, string $statement): string
    {
        if ($statement !== '') {
            return 'Prove convergence and invariant-safety for recursion class: '.$statement;
        }

        return 'Prove convergence and invariant-safety for recursion class '.$classId;
    }

    /**
     * The requested recursion depth, read from `requested_max_depth` (clamped to be
     * non-negative; a finite numeric value floored to int). Defaults to 0 when absent
     * or unusable. It is an INPUT echo only — it never becomes the proven depth.
     *
     * @param  array<string, mixed>  $preconditions
     */
    private function requestedMaxDepth(array $preconditions): int
    {
        $value = $preconditions['requested_max_depth'] ?? null;

        if (is_int($value)) {
            return max($value, 0);
        }

        if (is_float($value) && is_finite($value)) {
            return max((int) $value, 0);
        }

        if (is_string($value) && is_numeric(trim($value))) {
            return max((int) (float) trim($value), 0);
        }

        return 0;
    }

    /**
     * The raw recursion-class entries supplied in the preconditions, or [] when none.
     *
     * @param  array<string, mixed>  $preconditions
     * @return array<int|string, mixed>
     */
    private function recursionClasses(array $preconditions): array
    {
        $classes = $preconditions['recursion_classes'] ?? null;

        return is_array($classes) ? $classes : [];
    }

    /**
     * The distinct, normalized recursion-class ids in first-seen order. Mirrors the
     * de-duplication the theorem loop performs so the reported ids and the theorems
     * stay in lockstep.
     *
     * @param  array<string, mixed>  $preconditions
     * @return list<string>
     */
    private function resolvedClassIds(array $preconditions): array
    {
        // Seed the slugs the theorem loop already anchors on (the core theorems)
        // so a class whose slug collides with a core theorem is dropped here
        // exactly as it is from the theorem list — its convergence is subsumed by
        // the core theorem, so it is not a distinct reported recursion class. This
        // keeps recursion_class_ids and theorem_ids in lockstep
        // (theorem_count === recursion_class_count + count(core theorems)).
        $seen = [];
        foreach (self::CORE_THEOREM_SLUGS as $coreSlug) {
            $seen[$coreSlug] = true;
        }

        $ids = [];

        foreach ($this->recursionClasses($preconditions) as $entry) {
            $classId = $this->classId($entry);
            if ($classId === '') {
                continue;
            }

            $slug = AreaFocusSlugNormalizer::lowerSnakeToken($classId);
            if ($slug === '' || isset($seen[$slug])) {
                continue;
            }

            $seen[$slug] = true;
            $ids[] = $classId;
        }

        return $ids;
    }

    /**
     * Resolve the recursion-class id from a list entry. Accepts a plain string id or
     * an array carrying `class_id`/`id`. Returns '' when no id is present.
     */
    private function classId(mixed $entry): string
    {
        if (is_string($entry)) {
            return trim($entry);
        }

        if (is_array($entry)) {
            foreach (['class_id', 'id'] as $key) {
                $value = $entry[$key] ?? null;
                if (is_string($value) && trim($value) !== '') {
                    return trim($value);
                }
            }
        }

        return '';
    }

    /**
     * Resolve the optional class statement from an array entry.
     */
    private function statement(mixed $entry): string
    {
        if (is_array($entry)) {
            foreach (['statement', 'description'] as $key) {
                $value = $entry[$key] ?? null;
                if (is_string($value) && trim($value) !== '') {
                    return trim($value);
                }
            }
        }

        return '';
    }

    /**
     * Whether a load-bearing pillar (P5 or Q2) is present, resolved fail-closed and
     * mirroring the sibling L9 certification services byte-for-byte: a bool counts
     * directly; an array counts on the first present of `<pillar>_certified` /
     * `certified` / `passed` being === true; otherwise the matching top-level
     * `<pillar>_certified` flag must be === true. Absent => false => missing.
     *
     * @param  array<string, mixed>  $preconditions
     */
    private function pillarPresent(array $preconditions, string $pillar): bool
    {
        $value = $preconditions[$pillar] ?? null;

        if (is_bool($value)) {
            return $value;
        }

        if (is_array($value)) {
            foreach ([$pillar.'_certified', 'certified', 'passed'] as $flag) {
                if (array_key_exists($flag, $value)) {
                    return $value[$flag] === true;
                }
            }
        }

        return ($preconditions[$pillar.'_certified'] ?? null) === true;
    }

    /**
     * Deterministic content-addressed spec id: sha256 over the sorted distinct
     * theorem ids, so the same precondition payload (in any class order) maps to one
     * spec id and a different set of classes maps to a different id. No clock, no
     * randomness.
     *
     * @param  list<string>  $theoremIds
     */
    private function specId(array $theoremIds): string
    {
        $sorted = $theoremIds;
        sort($sorted);

        return 'l10conv_'.substr(hash('sha256', implode('|', $sorted)), 0, 16);
    }
}
