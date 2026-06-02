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
        $level = $this->level($candidate);
        $phase = $this->phase($candidate);
        $candidateId = $this->candidateId($level, $phase, $candidate);
        $l7Certified = $this->isL7Certified($l7Certification);
        $isL8 = $this->isL8Candidate($level);
        $completedPhases = $this->completedPhases($completedL8);
        $completedIds = $this->completedIds($completedL8);
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
        $missingDeps = $this->missingDependencies($candidate, $completedIds);

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

    /**
     * @param  array<string, mixed>  $candidate
     * @param  list<string>  $completedIds
     * @return list<string>
     */
    private function missingDependencies(array $candidate, array $completedIds): array
    {
        $declared = $candidate['depends_on'] ?? $candidate['dependencies'] ?? [];

        if (! is_array($declared)) {
            return [];
        }

        $missing = [];

        foreach ($declared as $dependency) {
            $token = $this->normalizeToken($dependency);

            if ($token === '') {
                continue;
            }

            if (! in_array($token, $completedIds, true)) {
                $missing[] = $token;
            }
        }

        return $missing;
    }

    /**
     * @param  array<string, mixed>  $candidate
     */
    private function level(array $candidate): string
    {
        $explicit = $this->normalizeToken($candidate['level'] ?? null);

        if ($explicit !== '') {
            return $explicit;
        }

        return $this->splitId($candidate)[0];
    }

    /**
     * @param  array<string, mixed>  $candidate
     */
    private function phase(array $candidate): string
    {
        $explicit = $this->normalizeToken($candidate['phase'] ?? null);

        if ($explicit !== '') {
            return $explicit;
        }

        return $this->splitId($candidate)[1];
    }

    /**
     * Split a combined identifier such as "L8-P5" / "l8_p5" into [level, phase].
     *
     * @param  array<string, mixed>  $candidate
     * @return array{0: string, 1: string}
     */
    private function splitId(array $candidate): array
    {
        $raw = $candidate['id'] ?? $candidate['candidate_id'] ?? $candidate['candidate'] ?? null;

        if (! is_string($raw)) {
            return ['', ''];
        }

        $normalized = strtoupper(trim($raw));
        $parts = preg_split('/[^A-Z0-9]+/', $normalized, 2, PREG_SPLIT_NO_EMPTY);

        if ($parts === false || $parts === []) {
            return ['', ''];
        }

        return [
            $parts[0],
            $parts[1] ?? '',
        ];
    }

    /**
     * @param  array<string, mixed>  $candidate
     */
    private function candidateId(string $level, string $phase, array $candidate): string
    {
        if ($level !== '' && $phase !== '') {
            return $level.'-'.$phase;
        }

        if ($level !== '') {
            return $level;
        }

        $raw = $candidate['id'] ?? $candidate['candidate_id'] ?? $candidate['candidate'] ?? null;

        return is_string($raw) && trim($raw) !== '' ? strtoupper(trim($raw)) : 'unknown';
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

    /**
     * Phase tokens (P1..P5) present in the completed-L8 set, derived from either
     * completed phase tokens or completed slice identifiers (e.g. "L8-P5").
     *
     * @param  list<mixed>|array<string, mixed>  $completedL8
     * @return list<string>
     */
    private function completedPhases(array $completedL8): array
    {
        $phases = [];

        foreach ($this->completedIds($completedL8) as $token) {
            $parts = preg_split('/[^A-Z0-9]+/', $token, -1, PREG_SPLIT_NO_EMPTY);

            if ($parts === false) {
                continue;
            }

            foreach ($parts as $part) {
                if (array_key_exists($part, self::PHASE_PREREQUISITES) && ! in_array($part, $phases, true)) {
                    $phases[] = $part;
                }
            }
        }

        return $phases;
    }

    /**
     * Normalized identifier tokens present in the completed-L8 set.
     *
     * @param  list<mixed>|array<string, mixed>  $completedL8
     * @return list<string>
     */
    private function completedIds(array $completedL8): array
    {
        $ids = [];

        foreach ($completedL8 as $entry) {
            $token = $this->normalizeToken($entry);

            if ($token === '' || in_array($token, $ids, true)) {
                continue;
            }

            $ids[] = $token;
        }

        return $ids;
    }

    private function normalizeToken(mixed $value): string
    {
        if (is_int($value)) {
            $value = (string) $value;
        }

        if (! is_string($value)) {
            return '';
        }

        return strtoupper(trim($value));
    }
}
