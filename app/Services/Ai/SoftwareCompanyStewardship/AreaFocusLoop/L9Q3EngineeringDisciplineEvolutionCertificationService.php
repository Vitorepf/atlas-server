<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

/**
 * S143 — L9Q3EngineeringDisciplineEvolutionCertificationService (block: L9
 * Sovereign Engineering, phase Q3).
 *
 * Read-only certification of the L9-Q3 teto ("auto-evolucao da disciplina de
 * engenharia"). Q3 is real ONLY when the AAEOS has discovered an engineering
 * method by itself, that method was VALIDATED by measured outcome AND RETAINED,
 * and the parallel-lineage exploration raised the compound multiplier versus a
 * single-lineage evolution. The L9 sovereign-engineering map states the arrival
 * criterion exactly (atlas-aaeos-l9-sovereign-engineering-map.md line 158/222):
 *
 *   "o AAEOS adotou ao menos um metodo/padrao de engenharia descoberto por ele
 *    proprio, validado por outcome medido e mantido, e a exploracao em multiplas
 *    linhagens elevou o multiplicador composto vs. a evolucao de linhagem unica."
 *
 * So Q3 is "discipline evolution proven by RETAINED outcome": a method that was
 * validated but then NOT retained does not count — the discipline only evolves by
 * outcomes that actually held. And Q3 stands on Q2 (the proven sandbox): the map's
 * safety gate (line 157/159) makes Q2 a precondition ("Depende de Q2 (sandbox
 * provado) para ser seguro"; "linhagens paralelas sao sandboxed e nunca dao merge
 * sem o gate"), so a missing Q2 boundary blocks the whole certification.
 *
 * This certifier is read-only and fail-closed: it never promotes a level, never
 * mutates state, never executes a method, never reverts, and never hides a
 * blocker. Every returned field is COMPUTED from the supplied evidence (the
 * validated/retained method records, the lineage-multiplier measurement and the
 * Q2 boundary), never canned.
 *
 * Method records mirror the L9 outcome-validator shape (S140): each is validated
 * when `validated === true`, and retained when it was validated AND held — i.e.
 * `retained === true`, or `reverted === false` (measured-or-reverted: a reverted
 * method is not retained). A method that is not validated can never be retained.
 *
 * Verdict rules, applied in the canonical enumerated order (the order the slice
 * row lists them):
 *   1. zero validated methods            -> `no_validated_method`
 *      (nothing was proven, so there is nothing to certify);
 *   2. a validated-but-not-retained method -> `non_retained_method`
 *      (discipline evolution must be proven by RETAINED outcome — a method that
 *      did not hold breaks the criterion);
 *   3. missing Q2 boundary               -> `q2_boundary_missing`
 *      (Q3 has no proven sandbox to stand on without Q2).
 *   q3_certified=true ONLY when none of the three blockers fire.
 *
 * The `q2_boundary_missing` blocker constant and the Q2-boundary detection mirror
 * the sibling L9 parallel-lineage planner (S141,
 * L9ParallelEngineeringLineageSandboxPlanner) byte-for-byte.
 *
 * Pure: every returned field is computed from the method inputs via the rules
 * above. No I/O, DB, Eloquent, facade, provider, git, filesystem, clock or
 * randomness. Identical inputs always yield an identical certification.
 *
 * @see docs/engineering-knowledge-base/atlas-aaeos-l9-sovereign-engineering-map.md
 */
final class L9Q3EngineeringDisciplineEvolutionCertificationService
{
    private const SCHEMA_VERSION = 'atlas.aaeos.l9.q3_engineering_discipline_evolution_certification.v1';

    /** L9 phase this service certifies. */
    public const PHASE = 'L9-Q3';

    public const STATUS_CERTIFIED = 'q3_certified';

    public const STATUS_BLOCKED = 'blocked_not_q3';

    /** Blocker when no system-discovered method was validated by measured outcome. */
    public const BLOCKER_NO_VALIDATED_METHOD = 'no_validated_method';

    /** Blocker when a validated method was not retained (did not hold its outcome). */
    public const BLOCKER_NON_RETAINED_METHOD = 'non_retained_method';

    /**
     * Blocker when the Q2 proof boundary is missing, so no sandbox is proven safe.
     * Mirrors L9ParallelEngineeringLineageSandboxPlanner::BLOCKER_Q2_BOUNDARY_MISSING.
     */
    public const BLOCKER_Q2_BOUNDARY_MISSING = 'q2_boundary_missing';

    /**
     * Certify L9-Q3 from discipline-evolution evidence.
     *
     * Recognised `$inputs`:
     *   - methods | validated_methods | retained_methods: list of system-discovered
     *     engineering-method records. Each item is an array carrying `validated`
     *     (bool) and a retention signal — `retained` (bool) and/or `reverted` (bool,
     *     where `reverted === false` means retained). A non-array item, or a record
     *     without `validated === true`, is not a validated method. A validated record
     *     is retained when `retained === true`, or (when no `retained` flag is given)
     *     when an explicit `reverted === false` is present; absence of any retention
     *     signal fails closed (the method counts as validated but NOT retained).
     *   - lineage_multiplier_delta: float — the compound-multiplier lift of parallel
     *     lineages over single-lineage evolution. Read directly when finite, else
     *     computed as (`multi_lineage_multiplier`|`parallel_lineage_multiplier`) -
     *     (`single_lineage_multiplier`|`baseline_lineage_multiplier`). Any real
     *     magnitude; the arrival criterion expects it positive, surfaced as evidence.
     *   - q2 | q2_boundary: array<string,mixed>|bool — the L9-Q2 proven-invariant
     *     boundary. A bool is the fast path; an array is present when it asserts
     *     `q2_certified`/`certified`/`present` === true (an explicit false un-certifies
     *     it) or carries a concrete proof anchor. Also accepted at the top level as
     *     `q2_certified`/`q2_boundary_present` (bool). Fail-closed: absent => missing.
     *
     * @param  array<string,mixed>  $inputs
     * @return array{
     *     schema_version:string,
     *     phase:string,
     *     q3_certified:bool,
     *     status:string,
     *     validated_method_count:int,
     *     retained_method_count:int,
     *     lineage_multiplier_delta:float,
     *     lineage_multiplier_positive:bool,
     *     q2_boundary_present:bool,
     *     blockers:list<string>
     * }
     */
    public function certify(array $inputs): array
    {
        $methods = $this->methodRecords($inputs);

        $validatedCount = 0;
        $retainedCount = 0;
        foreach ($methods as $method) {
            if (! $this->isValidated($method)) {
                continue;
            }

            $validatedCount++;

            // A method can only be retained if it was validated first.
            if ($this->isRetained($method)) {
                $retainedCount++;
            }
        }

        $lineageMultiplierDelta = $this->lineageMultiplierDelta($inputs);
        $q2BoundaryPresent = $this->q2BoundaryPresent($inputs);

        // Blocker order follows the slice row's enumerated order: zero validated
        // methods, then a non-retained method, then a missing Q2 boundary. No
        // blocker is ever hidden.
        $blockers = [];

        if ($validatedCount === 0) {
            $blockers[] = self::BLOCKER_NO_VALIDATED_METHOD;
        }

        // A validated method that did not retain breaks "discipline evolution
        // proven by retained outcome". This fires only once a method was actually
        // validated (zero validated is already covered above).
        if ($validatedCount > 0 && $retainedCount < $validatedCount) {
            $blockers[] = self::BLOCKER_NON_RETAINED_METHOD;
        }

        if (! $q2BoundaryPresent) {
            $blockers[] = self::BLOCKER_Q2_BOUNDARY_MISSING;
        }

        $certified = $blockers === [];

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'phase' => self::PHASE,
            'q3_certified' => $certified,
            'status' => $certified ? self::STATUS_CERTIFIED : self::STATUS_BLOCKED,
            'validated_method_count' => $validatedCount,
            'retained_method_count' => $retainedCount,
            'lineage_multiplier_delta' => $lineageMultiplierDelta,
            'lineage_multiplier_positive' => $lineageMultiplierDelta > 0.0,
            'q2_boundary_present' => $q2BoundaryPresent,
            'blockers' => $blockers,
        ];
    }

    /**
     * The system-discovered method records, taken from the first present of
     * `methods` / `validated_methods` / `retained_methods`. Non-array items are
     * dropped downstream.
     *
     * @param  array<string,mixed>  $inputs
     * @return list<mixed>
     */
    private function methodRecords(array $inputs): array
    {
        foreach (['methods', 'validated_methods', 'retained_methods'] as $key) {
            $value = $inputs[$key] ?? null;
            if (is_array($value)) {
                return array_values($value);
            }
        }

        return [];
    }

    /**
     * Whether a method record was validated by measured outcome. Fail-closed: only
     * an array record with `validated === true` counts.
     */
    private function isValidated(mixed $method): bool
    {
        return is_array($method) && ($method['validated'] ?? null) === true;
    }

    /**
     * Whether a validated method record was RETAINED (held its outcome). Retained
     * when `retained === true`; else, when no `retained` flag is given, an explicit
     * `reverted === false` proves retention (measured-or-reverted). Absent retention
     * signal fails closed (validated but not retained).
     */
    private function isRetained(mixed $method): bool
    {
        if (! is_array($method)) {
            return false;
        }

        if (array_key_exists('retained', $method)) {
            return $method['retained'] === true;
        }

        if (array_key_exists('reverted', $method)) {
            return $method['reverted'] === false;
        }

        return false;
    }

    /**
     * Compound-multiplier lift of parallel lineages over single-lineage evolution.
     * Read from `lineage_multiplier_delta` when a finite number, else computed as
     * multi-lineage multiplier minus single-lineage multiplier (each defaulting to
     * 0.0 when absent). Not clamped: a delta may be any real magnitude. Rounded to a
     * stable 4 decimals for deterministic output.
     *
     * @param  array<string,mixed>  $inputs
     */
    private function lineageMultiplierDelta(array $inputs): float
    {
        $explicit = $inputs['lineage_multiplier_delta'] ?? null;
        if (AreaFocusScalarNormalizer::finiteNumber($explicit)) {
            return round((float) $explicit, 4);
        }

        $multi = AreaFocusScalarNormalizer::finiteNumberOrZero(
            $inputs['multi_lineage_multiplier']
                ?? $inputs['parallel_lineage_multiplier']
                ?? null,
        );
        $single = AreaFocusScalarNormalizer::finiteNumberOrZero(
            $inputs['single_lineage_multiplier']
                ?? $inputs['baseline_lineage_multiplier']
                ?? null,
        );

        return round($multi - $single, 4);
    }

    /**
     * Whether the L9-Q2 proof boundary is present. Mirrors the sibling parallel
     * lineage planner (S141): a bool fast path; an array that asserts
     * `q2_certified`/`certified`/`present` === true (an explicit false un-certifies)
     * or carries a concrete proof anchor; or the top-level `q2_certified` /
     * `q2_boundary_present` flag. Fail-closed: absent => not present.
     *
     * @param  array<string,mixed>  $inputs
     */
    private function q2BoundaryPresent(array $inputs): bool
    {
        $boundary = $inputs['q2'] ?? $inputs['q2_boundary'] ?? null;

        if (is_bool($boundary)) {
            return $boundary;
        }

        if (is_array($boundary) && $boundary !== []) {
            if (($boundary['q2_certified'] ?? null) === false
                || ($boundary['certified'] ?? null) === false
                || ($boundary['present'] ?? null) === false) {
                return false;
            }

            if (($boundary['q2_certified'] ?? false) === true
                || ($boundary['certified'] ?? false) === true
                || ($boundary['present'] ?? false) === true) {
                return true;
            }

            // A boundary that carries a concrete proof anchor is present.
            $anchor = AreaFocusScalarNormalizer::payloadString(
                $boundary,
                ['boundary_id', 'proof_boundary_id', 'delegation_boundary', 'evidence_ref'],
                '',
            );
            if ($anchor !== '') {
                return true;
            }
        }

        return ($inputs['q2_certified'] ?? null) === true
            || ($inputs['q2_boundary_present'] ?? null) === true;
    }
}
