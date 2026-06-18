<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Differential\Shadow;

use App\Services\Ai\Programming\AtlasDev\Support\Elevations\ElevationConfig;

/**
 * E4 -- Shadow-diff gate for pure functions.
 *
 * The gate that reads the computed shadow-diff result (produced by
 * {@see ShadowDiffService::evaluate()}) and decides the verdict as a PURE
 * function of (result, mode). It routes the verdict through exactly one of
 * the two sanctioned channels (no third way, no silent green):
 *
 *   - ADVISORY (e4.mode=advisory): when at least one pure symbol diverged,
 *      the gate trips and appends the honesty flag
 *      {@see FLAG_SHADOW_DIFF_REGRESSION}. The CompletionStateGate then
 *      auto-downgrades PASSED -> needs_review (the passed-forbids-flags ctor
 *      invariant guarantees no green-with-flag). Advisory NEVER forces
 *      STATUS_FAILED for the flag alone.
 *
 *   - HARD (e4.mode=hard): the same trip routes to STATUS_FAILED (the
 *      sanctioned hard gate channel) so completion resolves to failed/
 *      blocked (VAL-E4-006). The flag is retained for auditability. Hard
 *      NEVER just downgrades to needs_review on a real regression.
 *
 *   - OFF (e4.mode=off): the gate is a no-op -- no flag, no STATUS_FAILED
 *      (byte-identical to pre-E4-shadow-diff). The executor additionally
 *      skips the shadow-diff service entirely so no subprocess runs.
 *
 *   - SKIP (no PHP files, all impure, all newly-added, or harness failure):
 *      a no-op with explicit reason. Never trips. VAL-E4-007 (impure =>
 *      no flag) and VAL-E4-011 (newly-added => skip with reason, no flag,
 *      no exception) are guaranteed here.
 *
 *   - AGREEMENT (behavior-preserving refactor): a no-op. VAL-E4-008
 *      (identical outputs => no flag, stays green on this axis).
 *
 * Canonical: mission architecture.md (Atlas Dev Elevation v2, M5 / E4,
 * e4-shadow-diff-pure-functions feature).
 */
final class ShadowDiffGate
{
    /**
     * The honesty-flag name the E4 shadow-diff gate appends in advisory mode
     * when a pure-function regression is detected. Shared so the executor
     * and tests reference the canonical string. Follows the established
     * condition-naming convention.
     */
    public const FLAG_SHADOW_DIFF_REGRESSION = 'shadow_diff_regression';

    public function __construct(
        private readonly ElevationConfig $e4Config,
    ) {}

    /**
     * Compute the verdict for a {@see ShadowDiffResult}. Pure: same
     * (result, mode) always yields the same verdict.
     */
    public function evaluate(ShadowDiffResult $result): ShadowDiffVerdict
    {
        // Off mode => byte-identical no-op. No flag, no fail, no surfacing.
        if ($this->e4Config->isOff()) {
            return ShadowDiffVerdict::noOp(
                'e4.mode=off: shadow-diff gate is byte-identical to pre-E4',
            );
        }

        // Skip (no symbol could be evaluated) => no-op with reason, never trips.
        // VAL-E4-007 (impure => no flag) and VAL-E4-011 (newly-added => skip).
        if ($result->isSkipped) {
            return ShadowDiffVerdict::noOp('e4 shadow-diff: '.$result->skipReason);
        }

        // Agreement (all evaluated symbols agreed) => no-op. VAL-E4-008.
        if (! $result->diverged) {
            $evaluatedCount = count($result->evaluatedSymbols);
            $skippedCount = count($result->skippedSymbols);

            return ShadowDiffVerdict::noOp(sprintf(
                'e4 shadow-diff: %d symbol(s) agreed across inputs (%d skipped)',
                $evaluatedCount,
                $skippedCount,
            ));
        }

        // VAL-E4-005 / VAL-E4-006: divergence => trip.
        //   - advisory => honesty flag only (drives the downgrade, never
        //     STATUS_FAILED for the flag alone).
        //   - hard     => STATUS_FAILED gate channel (never just downgrades).
        // The flag is retained for auditability in both modes.
        $reason = sprintf(
            'e4 shadow-diff: %d pure symbol(s) diverged (old != new on probed inputs)',
            count($result->divergentSymbols),
        );

        return new ShadowDiffVerdict(
            tripped: true,
            shouldFailGate: $this->e4Config->isHard(),
            honestyFlags: [self::FLAG_SHADOW_DIFF_REGRESSION],
            isNoOp: false,
            noOpReason: '',
            reason: $reason,
            divergentSymbols: $result->divergentSymbols,
        );
    }
}
