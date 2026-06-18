<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Regression;

use App\Services\Ai\Programming\AtlasDev\Support\Elevations\ElevationConfig;

/**
 * E5 -- Regression-baseline gate.
 *
 * The gate that reads the computed regression set (produced by
 * {@see RegressionBaselineService::computeRegressions()}) and decides the
 * verdict as a PURE function of (regressions, mode). It routes the verdict
 * through exactly one of the two sanctioned channels (no third way, no
 * silent green):
 *
 *   - ADVISORY (e5.mode=advisory): when the regression set is non-empty,
 *      the gate trips and appends the honesty flag
 *      {@see FLAG_REGRESSION_DETECTED}. The CompletionStateGate then
 *      auto-downgrades PASSED -> needs_review (the passed-forbids-flags
 *      ctor invariant guarantees no green-with-flag). Advisory NEVER forces
 *      STATUS_FAILED for the flag alone.
 *
 *   - HARD (e5.mode=hard): the same trip routes to STATUS_FAILED (the
 *      sanctioned hard gate channel) so completion resolves to failed/
 *      blocked (VAL-E5-003). The flag is retained for auditability. Hard
 *      NEVER just downgrades to needs_review on a real regression.
 *
 *   - OFF (e5.mode=off): the gate is a no-op -- no flag, no STATUS_FAILED
 *      (byte-identical to pre-E5). The executor additionally skips the
 *      baseline capture entirely so the run is byte-identical.
 *
 *   - EMPTY regressions: a no-op -- never a false fail. A patch whose
 *      post-patch results contain no regressions (all failures are
 *      pre-existing or all previously-passing tests still pass) does not
 *      trip the gate, regardless of mode.
 *
 * Canonical: mission architecture.md (Atlas Dev Elevation v2, M4 / E5,
 * e5-regression-baseline feature).
 */
final class RegressionBaselineGate
{
    /**
     * The honesty-flag name the E5 gate appends in advisory mode when a
     * regression is detected. Shared so the executor (next feature) and
     * tests reference the canonical string. Follows the
     * `elevation_<eN>_<condition>` convention.
     */
    public const FLAG_REGRESSION_DETECTED = 'regression_detected';

    public function __construct(
        private readonly ElevationConfig $e5Config,
    ) {}

    /**
     * Compute the verdict for a {@see RegressionBaselineResult}. Pure: same
     * (result, mode) always yields the same verdict.
     */
    public function evaluate(RegressionBaselineResult $result): RegressionVerdict
    {
        $baselineHash = $result->baseline->contentHash;

        // Off mode => byte-identical no-op. No flag, no fail, no surfacing.
        // The executor additionally skips the baseline capture entirely so
        // the run is byte-identical to pre-E5.
        if ($this->e5Config->isOff()) {
            return RegressionVerdict::noOp(
                'e5.mode=off: regression baseline gate is byte-identical to pre-E5',
                $baselineHash,
            );
        }

        $regressions = $result->regressions;

        // Empty regression set => no trip. A patch whose post-patch results
        // contain no regressions (all failures pre-existing, or all
        // previously-passing tests still pass, or a net fix) does not trip
        // the gate. This is NOT a false pass: the verification gate itself
        // already catches failing tests; E5 only classifies regressions
        // (passed-before/fails-after) for the elevation verdict.
        if ($regressions === []) {
            // Also a no-op when the baseline was empty (no scoped tests to
            // compare) -- mirrors the E3 VAL-E3-008 skip pattern.
            if ($result->baseline->results === []) {
                return RegressionVerdict::noOp(
                    'e5: empty baseline (no scoped tests captured pre-patch)',
                    $baselineHash,
                );
            }

            return RegressionVerdict::pass($baselineHash);
        }

        // VAL-E5-002 / VAL-E5-003: non-empty regression set => trip.
        //   - advisory => honesty flag only (drives the downgrade, never
        //     STATUS_FAILED for the flag alone).
        //   - hard     => STATUS_FAILED gate channel (never just downgrades).
        // The flag is retained for auditability in both modes.
        $reason = sprintf(
            'e5: %d regression(s) detected (passed-before, fails-after): %s',
            count($regressions),
            implode(', ', $regressions),
        );

        return new RegressionVerdict(
            tripped: true,
            shouldFailGate: $this->e5Config->isHard(),
            honestyFlags: [self::FLAG_REGRESSION_DETECTED],
            isNoOp: false,
            noOpReason: '',
            reason: $reason,
            regressions: $regressions,
            baselineHash: $baselineHash,
        );
    }
}
