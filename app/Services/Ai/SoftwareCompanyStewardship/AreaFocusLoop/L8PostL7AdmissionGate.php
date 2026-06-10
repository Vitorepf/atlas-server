<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

/**
 * S101 — L8PostL7AdmissionGate.
 *
 * Admits L8 ("transcendence") work into the loop only when two independent
 * preconditions hold:
 *
 *   1. L7 is certified real by S100 (`AaeosL7CompletionCertificationService`);
 *      a system that does not yet execute has no evidence of where its own
 *      frame traps, so no L8 pillar is readable as work before L7 is real.
 *   2. The candidate respects the L8 safety-first ordering from the
 *      transcendence map / L8-L10 detailing:
 *
 *          P5 (safety) -> {P1, P2} -> P3 -> P4
 *
 *      P5 (self-deception immunity) is the lone `safety-precondition` whose
 *      only precondition is "L7 real". Every capability phase (P1/P2/P3/P4) is
 *      blocked until its phase prerequisites have been completed. A candidate
 *      may therefore enter either because it *is* L8-P5, or because it is a
 *      dependency-unlocked L8 child whose phase prerequisites and explicit
 *      slice dependencies are all already in the completed set.
 *
 * Pure decision function: no I/O, no DB, no clock, no randomness. Every
 * returned field is computed from the method inputs via real rules. The gate
 * never starts L8; it only decides whether L8 work is admissible.
 *
 * Mirrors the level/phase token parsing of the sibling S99
 * `NorthStarReadinessGate` (e.g. "L8-P5" / "l8_p5"), byte-for-byte on the
 * `atlas.aaeos.l8.*` schema family.
 */
final class L8PostL7AdmissionGate
{
    private const SCHEMA_VERSION = 'atlas.aaeos.l8.admission_gate.v1';

    /**
     * The L8 level token this gate governs.
     */
    private const L8_LEVEL = 'L8';

    /**
     * The lone safety-precondition phase that may enter first once L7 is real.
     */
    private const SAFETY_PHASE = 'P5';

    /**
     * Safety-first phase ordering: each capability phase lists the phases that
     * MUST already be in the completed set before it is admissible. P5 has no
     * phase prerequisite (its only precondition is "L7 real").
     *
     * @var array<string, list<string>>
     */
    private const PHASE_PREREQUISITES = [
        'P5' => [],
        'P1' => ['P5'],
        'P2' => ['P5'],
        'P3' => ['P1', 'P2'],
        'P4' => ['P3'],
    ];

    /**
     * @param  array<string, mixed>  $candidate
     * @param  array<string, mixed>  $l7Certification
     * @param  list<mixed>|array<string, mixed>  $completedL8
     * @return array{
     *     schema_version: string,
     *     candidate: string,
     *     level: string,
     *     phase: string,
     *     l7_certified: bool,
     *     is_l8_candidate: bool,
     *     is_safety_phase: bool,
     *     completed_phases: list<string>,
     *     missing_prerequisites: list<string>,
     *     status: string,
     *     admitted: bool,
     *     blockers: list<string>,
     *     reason: string
     * }
     */
    public function evaluate(array $candidate, array $l7Certification, array $completedL8): array
    {
        $level = AreaFocusAdmissionCandidateNormalizer::level($candidate);
        $phase = AreaFocusAdmissionCandidateNormalizer::phase($candidate);
        $candidateId = AreaFocusAdmissionCandidateNormalizer::candidateId($level, $phase, $candidate);
        $l7Certified = $this->isL7Certified($l7Certification);
        $isL8 = $this->isL8Candidate($level);
        $completedPhases = AreaFocusAdmissionCandidateNormalizer::completedPhases($completedL8, self::PHASE_PREREQUISITES);
        $completedIds = AreaFocusAdmissionCandidateNormalizer::uniqueUpperTokens($completedL8);
        $isSafetyPhase = $isL8 && $phase === self::SAFETY_PHASE;

        // 1. Candidates outside L8 are not this gate's concern: pass through.
        if (! $isL8) {
            return $this->result(
                $candidateId,
                $level,
                $phase,
                $l7Certified,
                false,
                false,
                $completedPhases,
                [],
                'not_l8_candidate',
                true,
                [],
                'candidate_outside_l8_not_gated',
            );
        }

        // 2. L7 must be certified real by S100 before ANY L8 work is admissible.
        if (! $l7Certified) {
            return $this->result(
                $candidateId,
                $level,
                $phase,
                false,
                true,
                $isSafetyPhase,
                $completedPhases,
                [],
                'blocked_l8_pre_l7',
                false,
                ['l7_not_certified'],
                'l8_never_starts_before_real_l7',
            );
        }

        // 3. Fail-closed on an L8 candidate whose phase is unknown / malformed.
        if (! array_key_exists($phase, self::PHASE_PREREQUISITES)) {
            return $this->result(
                $candidateId,
                $level,
                $phase,
                true,
                true,
                false,
                $completedPhases,
                [],
                'blocked_unknown_l8_phase',
                false,
                ['l8_phase_unknown'],
                'unknown_l8_phase_fails_closed',
            );
        }

        // 4. Safety-first phase ordering: every phase prerequisite must already
        //    be in the completed set. P5 has none, so it is admitted directly.
        $missingPhases = $this->missingPhasePrerequisites($phase, $completedPhases);

        // 5. Explicit slice dependencies declared on the candidate must all be
        //    completed for it to count as a dependency-unlocked L8 child.
        $missingDeps = AreaFocusAdmissionCandidateNormalizer::missingDependencies($candidate, $completedIds);

        $blockers = [];

        foreach ($missingPhases as $missingPhase) {
            $blockers[] = 'safety_ordering_requires_'.strtolower($missingPhase).'_first';
        }

        foreach ($missingDeps as $missingDep) {
            $blockers[] = 'dependency_unmet_'.strtolower($missingDep);
        }

        if ($blockers !== []) {
            return $this->result(
                $candidateId,
                $level,
                $phase,
                true,
                true,
                $isSafetyPhase,
                $completedPhases,
                $missingPhases,
                'blocked_safety_ordering',
                false,
                $blockers,
                'l8_child_not_dependency_unlocked',
            );
        }

        $reason = $isSafetyPhase
            ? 'l8_p5_safety_precondition_admitted_after_l7'
            : 'dependency_unlocked_l8_child_admitted';

        $status = $isSafetyPhase
            ? 'admitted_l8_p5_safety_precondition'
            : 'admitted_dependency_unlocked_l8_child';

        return $this->result(
            $candidateId,
            $level,
            $phase,
            true,
            true,
            $isSafetyPhase,
            $completedPhases,
            [],
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
     *     l7_certified: bool,
     *     is_l8_candidate: bool,
     *     is_safety_phase: bool,
     *     completed_phases: list<string>,
     *     missing_prerequisites: list<string>,
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
        bool $l7Certified,
        bool $isL8Candidate,
        bool $isSafetyPhase,
        array $completedPhases,
        array $missingPrerequisites,
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
            'l7_certified' => $l7Certified,
            'is_l8_candidate' => $isL8Candidate,
            'is_safety_phase' => $isSafetyPhase,
            'completed_phases' => array_values($completedPhases),
            'missing_prerequisites' => array_values($missingPrerequisites),
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

    private function isL8Candidate(string $level): bool
    {
        return $level === self::L8_LEVEL;
    }

    /**
     * @param  array<string, mixed>  $l7Certification
     */
    private function isL7Certified(array $l7Certification): bool
    {
        return ($l7Certification['certified'] ?? false) === true
            || ($l7Certification['l7_certified'] ?? false) === true;
    }
}
