<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Regression;

use App\Services\Ai\Programming\AtlasDev\Mutation\MutationScoreVerdict;

/**
 * E5 -- Verdict returned by {@see RegressionBaselineGate::evaluate()}.
 *
 * A pure value object: it carries what the gate decided, never how to apply
 * it. The PipelineRunExecutor reads the verdict and routes through the
 * sanctioned channels (honesty flag for advisory, STATUS_FAILED gate for
 * hard). The verdict itself does NOT mutate any gate result.
 *
 * Mirrors {@see MutationScoreVerdict}
 * so the executor applies the E5 verdict through the SAME post-gate block
 * pattern used for E1/E2/E3.
 *
 * Fields:
 *   - $tripped:        true iff the gate decided a regression exists (a test
 *                       that passed before and fails after). False on a pass,
 *                       a skip, or off mode.
 *   - $shouldFailGate: true iff the verdict routes to the sanctioned HARD
 *                       channel (STATUS_FAILED gate). True ONLY in hard mode
 *                       on a tripped verdict; never true in advisory (advisory
 *                       uses the honesty-flag channel only) or off.
 *   - $honestyFlags:   the honesty flags the gate appends in advisory mode.
 *                       Empty when off, on a pass, or on a skip. Carries the
 *                       `regression_detected` flag (advisory) and retains it
 *                       for auditability in hard mode.
 *   - $isNoOp:         true iff the gate did not evaluate a real regression
 *                       (off mode, or an empty baseline / empty post-patch).
 *                       A no-op NEVER trips and NEVER fails.
 *   - $noOpReason:     explicit non-empty reason when $isNoOp is true.
 *   - $reason:         human-readable reason when $tripped is true (carried
 *                       into evidence artifacts / receipts for auditability).
 *   - $regressions:    the regression set (commands that passed-before and
 *                       fail-after). Echoed from the result for evidence.
 *   - $baselineHash:   the baseline cache content hash the verdict was
 *                       computed against. Proves the verdict used the
 *                       iteration-0 baseline (VAL-E5-012 evidence surface).
 */
final class RegressionVerdict
{
    /**
     * @param  list<string>  $honestyFlags
     * @param  list<string>  $regressions
     */
    public function __construct(
        public readonly bool $tripped,
        public readonly bool $shouldFailGate,
        public readonly array $honestyFlags,
        public readonly bool $isNoOp,
        public readonly string $noOpReason,
        public readonly string $reason,
        public readonly array $regressions,
        public readonly ?string $baselineHash,
    ) {}

    /**
     * A no-op verdict: the gate did not evaluate a real regression (off mode,
     * or an empty baseline). Never trips, never fails. The reason is carried
     * for auditability.
     */
    public static function noOp(string $reason, ?string $baselineHash): self
    {
        return new self(
            tripped: false,
            shouldFailGate: false,
            honestyFlags: [],
            isNoOp: true,
            noOpReason: $reason,
            reason: '',
            regressions: [],
            baselineHash: $baselineHash,
        );
    }

    /**
     * A pass verdict: the baseline + post-patch diff produced NO regressions
     * in a non-off mode. No flag, no fail.
     */
    public static function pass(?string $baselineHash): self
    {
        return new self(
            tripped: false,
            shouldFailGate: false,
            honestyFlags: [],
            isNoOp: false,
            noOpReason: '',
            reason: '',
            regressions: [],
            baselineHash: $baselineHash,
        );
    }
}
