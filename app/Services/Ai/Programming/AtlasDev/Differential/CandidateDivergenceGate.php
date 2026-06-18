<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Differential;

use App\Services\Ai\Programming\AtlasDev\Support\Elevations\ElevationConfig;

/**
 * E4 -- Candidate-divergence gate.
 *
 * The gate that reads the computed comparison result (produced by
 * {@see DifferentialTestingService::compare()}) and decides the verdict as a
 * PURE function of (result, mode). It routes the verdict through exactly one
 * of the two sanctioned channels (no third way, no silent green):
 *
 *   - ADVISORY (e4.mode=advisory): when candidates diverge, the gate trips
 *      and appends the honesty flag {@see FLAG_CANDIDATE_DIVERGENCE}. The
 *      CompletionStateGate then auto-downgrades PASSED -> needs_review (the
 *      passed-forbids-flags ctor invariant guarantees no green-with-flag).
 *      Advisory NEVER forces STATUS_FAILED for the flag alone.
 *
 *   - HARD (e4.mode=hard): the same trip routes to STATUS_FAILED (the
 *      sanctioned hard gate channel) so completion resolves to failed/
 *      blocked. The flag is retained for auditability. Hard NEVER just
 *      downgrades to needs_review on a real divergence.
 *
 *   - OFF (e4.mode=off): the gate is a no-op -- no flag, no STATUS_FAILED
 *      (byte-identical to pre-E4).
 *
 *   - AGREEMENT or SKIP: never trips, regardless of mode.
 *
 * Canonical: mission architecture.md (Atlas Dev Elevation v2, M5 / E4,
 * e4-differential-candidates feature).
 */
final class CandidateDivergenceGate
{
    /**
     * The honesty-flag name the E4 gate appends in advisory mode when
     * candidate divergence is detected. Shared so the executor and tests
     * reference the canonical string. Follows the established convention.
     */
    public const FLAG_CANDIDATE_DIVERGENCE = 'candidate_divergence';

    public function __construct(
        private readonly ElevationConfig $e4Config,
    ) {}

    /**
     * Compute the verdict for a {@see CandidateDivergenceResult}. Pure: same
     * (result, mode) always yields the same verdict.
     */
    public function evaluate(CandidateDivergenceResult $result): CandidateDivergenceVerdict
    {
        // Off mode => byte-identical no-op. No flag, no fail, no surfacing.
        if ($this->e4Config->isOff()) {
            return CandidateDivergenceVerdict::noOp(
                'e4.mode=off: candidate divergence gate is byte-identical to pre-E4',
            );
        }

        // Skip (fewer than 2 candidates) => no-op, never a false fail.
        if ($result->isSkipped) {
            return CandidateDivergenceVerdict::noOp(
                'e4: '.$result->skipReason,
            );
        }

        // Agreement => no trip. High confidence, no flag.
        if ($result->agreed) {
            return CandidateDivergenceVerdict::noOp(
                'e4: all '.$result->candidateCount.' candidates agreed (high confidence)',
            );
        }

        // VAL-E4-002 / VAL-E4-003: divergence => trip.
        //   - advisory => honesty flag only (drives the downgrade, never
        //     STATUS_FAILED for the flag alone).
        //   - hard     => STATUS_FAILED gate channel (never just downgrades).
        // The flag is retained for auditability in both modes.
        $reason = sprintf(
            'e4: candidate divergence detected across %d candidates',
            $result->candidateCount,
        );

        return new CandidateDivergenceVerdict(
            tripped: true,
            shouldFailGate: $this->e4Config->isHard(),
            honestyFlags: [self::FLAG_CANDIDATE_DIVERGENCE],
            isNoOp: false,
            noOpReason: '',
            reason: $reason,
            divergentDiffs: $result->divergentDiffs,
        );
    }
}
