<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

/**
 * AAEOS HTTP Path Closure Verifier (S88, L7 Runtime Completion).
 *
 * Pure verifier that proves ONE request traversed the 17 canonical AAEOS
 * phases P0..P16 (intent_capture .. learning) by inspecting the phase
 * envelopes it emitted along the way. It computes which phases were seen,
 * which were skipped (a path that jumps over governance phases is the
 * legacy fallback the runbook warns about), the evidence hashes collected
 * and the share of phases that fell back to the legacy path.
 *
 * The phase vocabulary mirrors the existing canonical sources byte-for-byte:
 *  - phase ids P0..P16 and the P10..P16 post-execution set from
 *    PostExecutionPhaseEmissionPlanBuilder;
 *  - phase names from AaeosPhaseHandoffService::PHASES.
 *
 * It is a verifier only: it never executes phase work, never mutates state
 * and never grants an L3/L4 autonomy claim from a single closed request.
 */
final class AaeosHttpPathClosureVerifier
{
    public const SCHEMA_VERSION = 'atlas.aaeos.http_path_closure_verification.v1';

    /**
     * The 17 canonical phases, ordered P0..P16. Names mirror
     * AaeosPhaseHandoffService::PHASES.
     *
     * @var list<string>
     */
    private const PHASE_NAMES = [
        'intent_capture',   // P0
        'disambiguation',   // P1
        'placement',        // P2
        'classification',   // P3
        'policy_gate',      // P4
        'topology',         // P5
        'routing',          // P6
        'spec',             // P7
        'tasks',            // P8
        'receipt',          // P9
        'execution',        // P10
        'gates',            // P11
        'evidence',         // P12
        'delivery',         // P13
        'human_review',     // P14
        'certification',    // P15
        'learning',         // P16
    ];

    /** Highest canonical phase number (P16). */
    private const LAST_PHASE = 16;

    /** First post-execution phase number (P10). */
    private const FIRST_POST_EXECUTION_PHASE = 10;

    /**
     * Verify the closure of a single HTTP request across P0..P16.
     *
     * @param  list<array<string,mixed>>  $envelopes  one phase envelope per traversed phase
     * @param  array<string,mixed>  $options  e.g. ['post_execution_phase_emit' => true]
     * @return array{
     *     schema_version: string,
     *     phases_seen: list<int>,
     *     skipped_phases: list<int>,
     *     evidence_hashes: list<string>,
     *     legacy_fallback_rate: float,
     *     canonical_skips: list<int>,
     *     blocked_phases: list<int>,
     *     required_phases: list<int>,
     *     post_execution_required: bool,
     *     full_17_phase_path: bool,
     *     closed: bool,
     *     claims_l3_l4_autonomy: bool,
     *     blockers: list<string>
     * }
     */
    public function verify(array $envelopes, array $options = []): array
    {
        $postExecutionRequired = ($options['post_execution_phase_emit'] ?? false) === true;
        $requiredPhases = $this->requiredPhases($postExecutionRequired);

        /** @var array<int,string> $statusByPhase */
        $statusByPhase = [];
        /** @var array<int,bool> $canonicalSkipByPhase */
        $canonicalSkipByPhase = [];
        $evidenceHashes = [];
        $legacyFallbackCount = 0;
        $seenCount = 0;

        foreach ($envelopes as $envelope) {
            if (! is_array($envelope)) {
                continue;
            }

            $phase = $this->phaseNumber($envelope);
            if ($phase === null) {
                continue;
            }

            $expectedName = self::PHASE_NAMES[$phase];
            $declaredName = $envelope['phase_name'] ?? null;
            if (is_string($declaredName) && $declaredName !== '' && $declaredName !== $expectedName) {
                // Envelope claims a phase number whose name does not match the
                // canonical name; treat it as not a trustworthy traversal.
                continue;
            }

            $seenCount++;

            // A phase may be declared more than once in a trace. Status is
            // sticky-worst: once a phase is observed blocked/failed (or skipped
            // without a canonical marker) a later benign envelope must never
            // erase that — otherwise a denied governance phase could be masked
            // back to ok and the request would wrongly close. Merging keeps the
            // most severe status seen and makes the result order-independent.
            $incomingStatus = $this->normalizedStatus($envelope);
            $existingStatus = $statusByPhase[$phase] ?? null;
            $statusByPhase[$phase] = $existingStatus === null
                ? $incomingStatus
                : $this->moreSevereStatus($existingStatus, $incomingStatus);

            // A phase counts as a canonical skip only when every envelope that
            // declares it agrees it was canonical; any unmarked declaration
            // demotes it to a real (blocking) skip.
            $incomingCanonical = ($envelope['canonical_skip'] ?? false) === true;
            $canonicalSkipByPhase[$phase] = array_key_exists($phase, $canonicalSkipByPhase)
                ? ($canonicalSkipByPhase[$phase] && $incomingCanonical)
                : $incomingCanonical;

            if (($envelope['legacy_fallback'] ?? false) === true) {
                $legacyFallbackCount++;
            }

            $hash = $this->evidenceHash($envelope);
            if ($hash !== null) {
                $evidenceHashes[] = $hash;
            }
        }

        $phasesSeen = array_keys($statusByPhase);
        sort($phasesSeen);

        $skippedPhases = [];
        $canonicalSkips = [];
        $blockedPhases = [];

        foreach ($requiredPhases as $phase) {
            $status = $statusByPhase[$phase] ?? null;

            if ($status === null) {
                // Required phase never appeared: the path jumped over it.
                $skippedPhases[] = $phase;

                continue;
            }

            if ($status === 'skipped') {
                if (($canonicalSkipByPhase[$phase] ?? false) === true) {
                    $canonicalSkips[] = $phase;
                } else {
                    $skippedPhases[] = $phase;
                }

                continue;
            }

            if ($status === 'blocked' || $status === 'failed') {
                $blockedPhases[] = $phase;
            }
        }

        sort($skippedPhases);
        sort($canonicalSkips);
        sort($blockedPhases);

        $legacyFallbackRate = $seenCount === 0
            ? 0.0
            : round($legacyFallbackCount / $seenCount, 4);

        $blockers = $this->blockers(
            $skippedPhases,
            $blockedPhases,
            $postExecutionRequired,
            $legacyFallbackRate,
        );

        $fullSeventeenPhasePath = $this->fullSeventeenPhasePath($statusByPhase, $canonicalSkipByPhase);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'phases_seen' => array_values($phasesSeen),
            'skipped_phases' => array_values($skippedPhases),
            'evidence_hashes' => array_values($evidenceHashes),
            'legacy_fallback_rate' => $legacyFallbackRate,
            'canonical_skips' => array_values($canonicalSkips),
            'blocked_phases' => array_values($blockedPhases),
            'required_phases' => array_values($requiredPhases),
            'post_execution_required' => $postExecutionRequired,
            'full_17_phase_path' => $fullSeventeenPhasePath,
            'closed' => $blockers === [],
            // A single closed request never authorizes an L3/L4 autonomy claim.
            'claims_l3_l4_autonomy' => false,
            'blockers' => array_values($blockers),
        ];
    }

    /**
     * Required phase set. The pre/execution governance core P0..P9 is always
     * required for a real (non-legacy) HTTP path; when post-execution emission
     * is on, P10..P16 become required too.
     *
     * @return list<int>
     */
    private function requiredPhases(bool $postExecutionRequired): array
    {
        $last = $postExecutionRequired ? self::LAST_PHASE : self::FIRST_POST_EXECUTION_PHASE - 1;

        return range(0, $last);
    }

    /**
     * @param  array<string,mixed>  $envelope
     */
    private function phaseNumber(array $envelope): ?int
    {
        $number = null;

        $id = $envelope['phase_id'] ?? null;
        if (is_string($id) && preg_match('/^P(\d{1,2})$/', $id, $matches) === 1) {
            $number = (int) $matches[1];
        } elseif (array_key_exists('phase_number', $envelope) && is_int($envelope['phase_number'])) {
            $number = $envelope['phase_number'];
        }

        if ($number === null || $number < 0 || $number > self::LAST_PHASE) {
            return null;
        }

        return $number;
    }

    /**
     * @param  array<string,mixed>  $envelope
     */
    private function normalizedStatus(array $envelope): string
    {
        $status = $envelope['status'] ?? 'ok';
        if (! is_string($status) || $status === '') {
            return 'ok';
        }

        return in_array($status, ['ok', 'skipped', 'blocked', 'failed'], true)
            ? $status
            : 'blocked';
    }

    /**
     * Return the more severe of two normalized phase statuses so that a phase
     * declared multiple times is judged by its worst observation (fail-closed).
     * Severity order (worst to best): blocked/failed > skipped > ok.
     */
    private function moreSevereStatus(string $a, string $b): string
    {
        $severity = ['ok' => 0, 'skipped' => 1, 'blocked' => 2, 'failed' => 2];

        return ($severity[$b] ?? 2) > ($severity[$a] ?? 2) ? $b : $a;
    }

    /**
     * Collect a canonical sha256 evidence hash from the envelope, if present.
     *
     * @param  array<string,mixed>  $envelope
     */
    private function evidenceHash(array $envelope): ?string
    {
        $hash = $envelope['evidence_hash'] ?? null;
        if (is_string($hash) && str_starts_with($hash, 'sha256:') && strlen($hash) > strlen('sha256:')) {
            return $hash;
        }

        return null;
    }

    /**
     * A request closes the full 17-phase path only when every phase P0..P16 is
     * either ok or a recorded canonical skip — no absence, no block, no failure.
     *
     * @param  array<int,string>  $statusByPhase
     * @param  array<int,bool>  $canonicalSkipByPhase
     */
    private function fullSeventeenPhasePath(array $statusByPhase, array $canonicalSkipByPhase): bool
    {
        for ($phase = 0; $phase <= self::LAST_PHASE; $phase++) {
            $status = $statusByPhase[$phase] ?? null;

            if ($status === 'ok') {
                continue;
            }

            if ($status === 'skipped' && ($canonicalSkipByPhase[$phase] ?? false) === true) {
                continue;
            }

            return false;
        }

        return true;
    }

    /**
     * @param  list<int>  $skippedPhases
     * @param  list<int>  $blockedPhases
     * @return list<string>
     */
    private function blockers(
        array $skippedPhases,
        array $blockedPhases,
        bool $postExecutionRequired,
        float $legacyFallbackRate,
    ): array {
        $blockers = [];

        foreach ($skippedPhases as $phase) {
            $blockers[] = 'phase_skipped:P'.$phase;
        }

        foreach ($blockedPhases as $phase) {
            $blockers[] = 'phase_blocked:P'.$phase;
        }

        if ($postExecutionRequired) {
            $postExecutionSkipped = array_filter(
                $skippedPhases,
                static fn (int $phase): bool => $phase >= self::FIRST_POST_EXECUTION_PHASE,
            );
            if ($postExecutionSkipped !== []) {
                $blockers[] = 'post_execution_phases_missing';
            }
        }

        if ($legacyFallbackRate > 0.0) {
            $blockers[] = 'legacy_fallback_path_used';
        }

        return AreaFocusStringListNormalizer::uniqueStringValues($blockers);
    }
}
