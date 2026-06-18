<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Differential\Shadow;

/**
 * E4 -- Verdict returned by {@see ShadowDiffGate::evaluate()}.
 *
 * A pure value object: it carries what the gate decided, never how to apply
 * it. The PipelineRunExecutor reads the verdict and routes through the
 * sanctioned channels (honesty flag for advisory, STATUS_FAILED gate for
 * hard). The verdict itself does NOT mutate any gate result.
 *
 * Mirrors {@see \App\Services\Ai\Programming\AtlasDev\Differential\CandidateDivergenceVerdict}
 * and {@see \App\Services\Ai\Programming\AtlasDev\Regression\RegressionVerdict}
 * so the executor applies the E4 shadow-diff verdict through the SAME
 * post-gate block pattern used for E1/E2/E3/E5.
 *
 * Fields:
 *   - $tripped:        true iff the gate decided a shadow-diff regression
 *                       exists (old != new on at least one pure symbol).
 *                       False on agreement, a skip, or off mode.
 *   - $shouldFailGate: true iff the verdict routes to the sanctioned HARD
 *                       channel (STATUS_FAILED gate). True ONLY in hard mode
 *                       on a tripped verdict; never true in advisory or off.
 *   - $honestyFlags:   the honesty flags the gate appends in advisory mode.
 *                       Empty when off, on agreement, or on a skip. Carries
 *                       the `shadow_diff_regression` flag (advisory) and
 *                       retains it for auditability in hard mode.
 *   - $isNoOp:         true iff the gate did not evaluate a real regression
 *                       (off mode, a skip — no PHP files / all impure / all
 *                       newly-added / harness failure — or agreement). A
 *                       no-op NEVER trips.
 *   - $noOpReason:     explicit non-empty reason when $isNoOp is true
 *                       (auditability, VAL-CROSS-016 evidence surface).
 *   - $reason:         human-readable reason when $tripped is true (carried
 *                       into evidence artifacts / receipts for auditability).
 *   - $divergentSymbols: the divergent symbol records (evidence). Echoed
 *                       from the result so the executor's summary carries
 *                       the symbol + first divergent input + outputs.
 *
 * Canonical: mission architecture.md (Atlas Dev Elevation v2, M5 / E4,
 * e4-shadow-diff-pure-functions feature).
 */
final class ShadowDiffVerdict
{
    /**
     * @param  list<string>  $honestyFlags
     * @param  list<array{symbol: non-empty-string, file: non-empty-string, input: string, oldOutput: string, newOutput: string}>  $divergentSymbols
     */
    public function __construct(
        public readonly bool $tripped,
        public readonly bool $shouldFailGate,
        public readonly array $honestyFlags,
        public readonly bool $isNoOp,
        public readonly string $noOpReason,
        public readonly string $reason,
        public readonly array $divergentSymbols,
    ) {}

    /**
     * A no-op verdict: the gate did not evaluate a real shadow-diff
     * regression (off mode, skip, or agreement). Never trips, never fails.
     * The reason is carried for auditability.
     */
    public static function noOp(string $reason): self
    {
        return new self(
            tripped: false,
            shouldFailGate: false,
            honestyFlags: [],
            isNoOp: true,
            noOpReason: $reason,
            reason: '',
            divergentSymbols: [],
        );
    }
}
