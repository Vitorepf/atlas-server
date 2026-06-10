<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

/**
 * S126 — L9PostL8AdmissionGate (block: L9 Sovereign Engineering).
 *
 * Admits L9 ("sovereign engineering") work into the loop only after L8 is
 * certified real by S125 (`AaeosL8TranscendenceCertificationService`) and only
 * for engineering-scoped candidates whose L9 phase is dependency-unlocked under
 * the safety-first ordering from the sovereign-engineering map:
 *
 *     sovereignty (invariant, immovable) -> Q2 (proven invariants) -> {Q1, Q3}
 *
 * The map is explicit (sec. "Por que esta ordem"):
 *   - the value-sovereignty invariant is hardened FIRST and never moves;
 *   - Q2 (proven invariants) is the trust foundation and precedes delegation;
 *   - Q1 (operator-judgment amplification) and Q3 (discipline evolution) are the
 *     superlinear pillars and are only safe AFTER Q2 — proposing Q1/Q3 before Q2
 *     is dangerous over-delegation, so this gate blocks it.
 *
 * Pure decision function: no I/O, DB, Eloquent, facade, provider, git,
 * filesystem, clock or randomness. Every returned field is computed from the
 * method inputs via the rules below; identical inputs always yield an identical
 * admission. The gate NEVER starts L9 and NEVER claims an L9 runtime — it only
 * decides whether L9 backlog work is admissible (DoD: "L9 backlog is post-L8
 * only; admission never claims L9 runtime").
 *
 * Mirrors the level/phase token parsing and fail-closed shape of the sibling
 * S101 `L8PostL7AdmissionGate`, on the `atlas.aaeos.l9.*` schema family.
 *
 * @see docs/engineering-knowledge-base/atlas-aaeos-l9-sovereign-engineering-map.md
 * @see docs/engineering-knowledge-base/atlas-aaeos-l7-l10-governed-ladder-backlog.md
 */
final class L9PostL8AdmissionGate
{
    private const SCHEMA_VERSION = 'atlas.aaeos.l9.admission.v1';

    /** The L9 level token this gate governs. */
    private const L9_LEVEL = 'L9';

    /** The slice whose L8 certification is the hard predecessor of all L9 work. */
    private const REQUIRED_PREDECESSOR = 'S125';

    /** The engineering scope L9 is locked to. */
    private const ALLOWED_SCOPE = 'engineering_only';

    /** The lone invariant phase that may enter first once L8 is certified. */
    private const SOVEREIGNTY_PHASE = 'SOVEREIGNTY';

    /**
     * Safety-first L9 phase ordering. Each phase lists the phases that MUST
     * already be in the completed set before it is admissible. The sovereignty
     * invariant has no prerequisite (its only precondition is "L8 real"); Q2
     * needs the invariant; Q1 and Q3 both need Q2.
     *
     * @var array<string, list<string>>
     */
    private const PHASE_PREREQUISITES = [
        'SOVEREIGNTY' => [],
        'Q2' => ['SOVEREIGNTY'],
        'Q1' => ['Q2'],
        'Q3' => ['Q2'],
    ];

    /**
     * Non-engineering scope tokens that are rejected outright. L9 is sovereign
     * software engineering only — never other domains, a domain generator or
     * multi-company operation (sovereign-engineering map, "Escopo (travado)").
     *
     * @var list<string>
     */
    private const NON_ENGINEERING_SCOPES = [
        'MARKETING',
        'FINANCE',
        'CYBER',
        'TRADING',
        'EXTERNAL_COMPANY',
        'MULTI_COMPANY',
        'DOMAIN_GENERATOR',
    ];

    /**
     * Admit (or block) an L9 candidate against the L8 certification.
     *
     * Recognised `$candidate` keys:
     *   - `level` / `phase` (strings) or a combined `id`/`candidate_id`/
     *     `candidate` such as "L9-Q2" / "l9_q2";
     *   - `scope` / `domain` (string) — the candidate's scope; an explicit
     *     non-engineering scope is rejected (engineering / software-engineering
     *     / AAEOS scopes pass);
     *   - `depends_on` / `dependencies` (list) — explicit slice predecessors
     *     that must all be in `$completedL9` for a child phase to be unlocked.
     *
     * Recognised `$l8Certification` keys: `certified` / `l8_certified` (bool).
     *
     * @param  array<string, mixed>  $candidate
     * @param  array<string, mixed>  $l8Certification
     * @param  list<mixed>|array<string, mixed>  $completedL9
     * @return array{
     *     schema_version: string,
     *     candidate: string,
     *     level: string,
     *     phase: string,
     *     required_predecessor: string,
     *     l8_certified: bool,
     *     is_l9_candidate: bool,
     *     scope: string,
     *     scope_in_engineering: bool,
     *     completed_phases: list<string>,
     *     missing_prerequisites: list<string>,
     *     allowed_l9_phase: string,
     *     status: string,
     *     admitted: bool,
     *     blockers: list<string>,
     *     reason: string
     * }
     */
    public function admit(array $candidate, array $l8Certification, array $completedL9 = []): array
    {
        $level = AreaFocusAdmissionCandidateNormalizer::level($candidate);
        $phase = AreaFocusAdmissionCandidateNormalizer::phase($candidate);
        $candidateId = AreaFocusAdmissionCandidateNormalizer::candidateId($level, $phase, $candidate);
        $l8Certified = $this->isL8Certified($l8Certification);
        $isL9 = $level === self::L9_LEVEL;
        $scope = $this->scope($candidate);
        $scopeInEngineering = $this->scopeInEngineering($scope);
        $completedPhases = AreaFocusAdmissionCandidateNormalizer::completedPhases($completedL9, self::PHASE_PREREQUISITES);
        $completedIds = AreaFocusAdmissionCandidateNormalizer::uniqueUpperTokens($completedL9);

        // 1. Candidates outside L9 are not this gate's concern: pass through.
        if (! $isL9) {
            return $this->result(
                $candidateId,
                $level,
                $phase,
                $l8Certified,
                false,
                $scope,
                $scopeInEngineering,
                $completedPhases,
                [],
                $phase === '' ? '' : $phase,
                'not_l9_candidate',
                true,
                [],
                'candidate_outside_l9_not_gated',
            );
        }

        // 2. L8 must be certified real by S125 before ANY L9 work is admissible.
        if (! $l8Certified) {
            return $this->result(
                $candidateId,
                $level,
                $phase,
                false,
                true,
                $scope,
                $scopeInEngineering,
                $completedPhases,
                [],
                '',
                'blocked_l9_pre_l8',
                false,
                ['l8_not_certified'],
                'l9_never_starts_before_certified_l8',
            );
        }

        // 3. L9 is sovereign software engineering only: a non-engineering scope
        //    is rejected before any phase ordering is considered.
        if (! $scopeInEngineering) {
            return $this->result(
                $candidateId,
                $level,
                $phase,
                true,
                true,
                $scope,
                false,
                $completedPhases,
                [],
                '',
                'blocked_non_engineering_scope',
                false,
                ['non_engineering_scope'],
                'l9_is_sovereign_engineering_only',
            );
        }

        // 4. Fail-closed on an L9 candidate whose phase is unknown / malformed.
        if (! array_key_exists($phase, self::PHASE_PREREQUISITES)) {
            return $this->result(
                $candidateId,
                $level,
                $phase,
                true,
                true,
                $scope,
                true,
                $completedPhases,
                [],
                '',
                'blocked_unknown_l9_phase',
                false,
                ['l9_phase_unknown'],
                'unknown_l9_phase_fails_closed',
            );
        }

        // 5. Safety-first ordering: every phase prerequisite must already be in
        //    the completed set. Q1/Q3 before Q2 (or Q2 before the sovereignty
        //    invariant) is blocked — sovereignty/Q2 precede the Q1/Q3 pillars.
        $missingPhases = $this->missingPhasePrerequisites($phase, $completedPhases);

        // 6. Explicit slice dependencies declared on the candidate must all be
        //    completed for it to count as a dependency-unlocked L9 child.
        $missingDeps = AreaFocusAdmissionCandidateNormalizer::missingDependencies($candidate, $completedIds);

        $blockers = [];

        foreach ($missingPhases as $missingPhase) {
            $blockers[] = 'sovereignty_q2_must_precede_'.strtolower($phase).'_missing_'.strtolower($missingPhase);
        }

        foreach ($missingDeps as $missingDep) {
            $blockers[] = 'dependency_unmet_'.strtolower($missingDep);
        }

        if ($blockers !== []) {
            // The earliest unmet prerequisite phase is what must come first.
            $allowedPhase = $missingPhases !== []
                ? $this->lowestRankPhase($missingPhases)
                : $phase;

            return $this->result(
                $candidateId,
                $level,
                $phase,
                true,
                true,
                $scope,
                true,
                $completedPhases,
                $missingPhases,
                $allowedPhase,
                'blocked_safety_ordering',
                false,
                $blockers,
                'l9_child_not_dependency_unlocked',
            );
        }

        $isSovereignty = $phase === self::SOVEREIGNTY_PHASE;

        $reason = $isSovereignty
            ? 'l9_sovereignty_invariant_admitted_after_l8'
            : 'dependency_unlocked_l9_child_admitted';

        $status = $isSovereignty
            ? 'admitted_l9_sovereignty_invariant'
            : 'admitted_dependency_unlocked_l9_child';

        return $this->result(
            $candidateId,
            $level,
            $phase,
            true,
            true,
            $scope,
            true,
            $completedPhases,
            [],
            $phase,
            $status,
            true,
            [],
            $reason,
        );
    }

    /**
     * @param  list<string>  $completedPhases
     * @param  list<string>  $missingPrerequisites
     * @param  list<string>  $blockers
     * @return array{
     *     schema_version: string,
     *     candidate: string,
     *     level: string,
     *     phase: string,
     *     required_predecessor: string,
     *     l8_certified: bool,
     *     is_l9_candidate: bool,
     *     scope: string,
     *     scope_in_engineering: bool,
     *     completed_phases: list<string>,
     *     missing_prerequisites: list<string>,
     *     allowed_l9_phase: string,
     *     status: string,
     *     admitted: bool,
     *     blockers: list<string>,
     *     reason: string
     * }
     */
    private function result(
        string $candidateId,
        string $level,
        string $phase,
        bool $l8Certified,
        bool $isL9Candidate,
        string $scope,
        bool $scopeInEngineering,
        array $completedPhases,
        array $missingPrerequisites,
        string $allowedPhase,
        string $status,
        bool $admitted,
        array $blockers,
        string $reason,
    ): array {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'candidate' => $candidateId,
            'level' => $level,
            'phase' => $phase,
            'required_predecessor' => self::REQUIRED_PREDECESSOR,
            'l8_certified' => $l8Certified,
            'is_l9_candidate' => $isL9Candidate,
            'scope' => $scope,
            'scope_in_engineering' => $scopeInEngineering,
            'completed_phases' => array_values($completedPhases),
            'missing_prerequisites' => array_values($missingPrerequisites),
            'allowed_l9_phase' => $allowedPhase,
            'status' => $status,
            'admitted' => $admitted,
            'blockers' => array_values($blockers),
            'reason' => $reason,
        ];
    }

    /**
     * @param  list<string>  $completedPhases
     * @return list<string>
     */
    private function missingPhasePrerequisites(string $phase, array $completedPhases): array
    {
        $missing = [];

        foreach (self::PHASE_PREREQUISITES[$phase] as $required) {
            if (! in_array($required, $completedPhases, true)) {
                $missing[] = $required;
            }
        }

        return $missing;
    }

    /**
     * The lowest-rank (earliest in the ordering) phase among a set, used to
     * report which phase must come first when ordering blocks a candidate.
     *
     * @param  list<string>  $phases
     */
    private function lowestRankPhase(array $phases): string
    {
        $order = array_keys(self::PHASE_PREREQUISITES);
        $best = '';
        $bestRank = PHP_INT_MAX;

        foreach ($phases as $candidatePhase) {
            $rank = array_search($candidatePhase, $order, true);
            $rank = $rank === false ? PHP_INT_MAX : $rank;

            if ($rank < $bestRank) {
                $bestRank = $rank;
                $best = $candidatePhase;
            }
        }

        return $best;
    }

    /**
     * @param  array<string, mixed>  $l8Certification
     */
    private function isL8Certified(array $l8Certification): bool
    {
        return ($l8Certification['certified'] ?? false) === true
            || ($l8Certification['l8_certified'] ?? false) === true;
    }

    /**
     * The candidate's declared scope, normalized. Absent scope defaults to the
     * locked engineering scope (an L9 candidate is engineering unless it
     * explicitly declares otherwise).
     *
     * @param  array<string, mixed>  $candidate
     */
    private function scope(array $candidate): string
    {
        $raw = $candidate['scope'] ?? $candidate['domain'] ?? null;
        $token = AreaFocusAdmissionCandidateNormalizer::upperToken($raw);

        return $token === '' ? strtoupper(self::ALLOWED_SCOPE) : $token;
    }

    /**
     * A scope is in engineering unless it is one of the explicit non-engineering
     * domains (other domains / domain generator / multi-company).
     */
    private function scopeInEngineering(string $scope): bool
    {
        return ! in_array($scope, self::NON_ENGINEERING_SCOPES, true);
    }
}
