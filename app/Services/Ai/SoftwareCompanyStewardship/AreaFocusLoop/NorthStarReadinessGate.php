<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

/**
 * S99 — NorthStarReadinessGate.
 *
 * Blocks every future-map (L8 / L9 / L10 / north-star) candidate from entering
 * the roadmap until L7 is certified real (S100). Once L7 is certified, the
 * safety-first ordering of the L8/L9/L10 detailing applies: the only candidate
 * allowed to enter as the next roadmap item is L8-P5 (the lone
 * `safety-precondition` whose only precondition is "L7 real"). Every other
 * future-map candidate stays blocked until its own precursors are real.
 *
 * Pure decision function: no I/O, no clock, no randomness. Every returned field
 * is computed from the method inputs.
 */
final class NorthStarReadinessGate
{
    private const SCHEMA_VERSION = 'atlas.aaeos.north_star_readiness_gate.v1';

    /**
     * Levels whose candidates are governed by this gate (future-map / north-star).
     *
     * @var list<string>
     */
    private const FUTURE_MAP_LEVELS = ['L8', 'L9', 'L10'];

    /**
     * The single candidate permitted to enter as the next roadmap item once L7
     * is certified: safety-first ordering inside L8 requires P5 before P1-P4.
     */
    private const ADMISSIBLE_LEVEL_AFTER_L7 = 'L8';

    private const ADMISSIBLE_PHASE_AFTER_L7 = 'P5';

    /**
     * @param  array<string, mixed>  $candidate
     * @param  array<string, mixed>  $l7
     * @return array{
     *     schema_version: string,
     *     candidate: string,
     *     level: string,
     *     phase: string,
     *     l7_certified: bool,
     *     north_star_candidate: bool,
     *     status: string,
     *     admitted: bool,
     *     blockers: list<string>,
     *     reason: string
     * }
     */
    public function evaluate(array $candidate, array $l7): array
    {
        $level = $this->level($candidate);
        $phase = $this->phase($candidate);
        $candidateId = $this->candidateId($level, $phase, $candidate);
        $l7Certified = $this->isL7Certified($l7);
        $northStarCandidate = $this->isFutureMapCandidate($level, $candidate);

        if (! $northStarCandidate) {
            return $this->result(
                $candidateId,
                $level,
                $phase,
                $l7Certified,
                false,
                'not_north_star_candidate',
                true,
                [],
                'candidate_below_future_map_not_gated',
            );
        }

        if (! $l7Certified) {
            return $this->result(
                $candidateId,
                $level,
                $phase,
                false,
                true,
                'blocked_north_star_pre_l7',
                false,
                ['l7_not_certified'],
                'future_map_escape_blocked_until_l7_real',
            );
        }

        if ($level === self::ADMISSIBLE_LEVEL_AFTER_L7 && $phase === self::ADMISSIBLE_PHASE_AFTER_L7) {
            return $this->result(
                $candidateId,
                $level,
                $phase,
                true,
                true,
                'admitted_l8_p5_only',
                true,
                [],
                'l8_p5_safety_precondition_admitted_after_l7',
            );
        }

        return $this->result(
            $candidateId,
            $level,
            $phase,
            true,
            true,
            'blocked_safety_ordering_requires_l8_p5',
            false,
            ['safety_ordering_requires_l8_p5_first'],
            'only_l8_p5_may_enter_first_after_l7',
        );
    }

    /**
     * @param  list<string>  $blockers
     * @return array{
     *     schema_version: string,
     *     candidate: string,
     *     level: string,
     *     phase: string,
     *     l7_certified: bool,
     *     north_star_candidate: bool,
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
        bool $northStarCandidate,
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
            'north_star_candidate' => $northStarCandidate,
            'status' => $status,
            'admitted' => $admitted,
            'blockers' => array_values($blockers),
            'reason' => $reason,
        ];
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
     * Split a combined identifier such as "L8-P5" / "l10_r1" into [level, phase].
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

        return is_string($raw) ? strtoupper(trim($raw)) : 'unknown';
    }

    /**
     * @param  array<string, mixed>  $candidate
     */
    private function isFutureMapCandidate(string $level, array $candidate): bool
    {
        if (($candidate['north_star'] ?? false) === true) {
            return true;
        }

        return in_array($level, self::FUTURE_MAP_LEVELS, true);
    }

    /**
     * @param  array<string, mixed>  $l7
     */
    private function isL7Certified(array $l7): bool
    {
        return ($l7['l7_certified'] ?? false) === true;
    }

    private function normalizeToken(mixed $value): string
    {
        if (! is_string($value)) {
            return '';
        }

        return strtoupper(trim($value));
    }
}
