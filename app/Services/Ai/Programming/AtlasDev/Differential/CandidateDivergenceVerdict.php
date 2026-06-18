<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Differential;

use App\Services\Ai\Programming\AtlasDev\Regression\RegressionVerdict;

/**
 * E4 -- Verdict returned by {@see CandidateDivergenceGate::evaluate()}.
 *
 * A pure value object: it carries what the gate decided, never how to apply
 * it. The PipelineRunExecutor reads the verdict and routes through the
 * sanctioned channels (honesty flag for advisory, STATUS_FAILED gate for
 * hard). The verdict itself does NOT mutate any gate result.
 *
 * Mirrors {@see RegressionVerdict}
 * so the executor applies the E4 verdict through the SAME post-gate block
 * pattern used for E1/E2/E3/E5.
 *
 * Fields:
 *   - $tripped:        true iff the gate decided a divergence exists. False
 *                       on agreement, a skip, or off mode.
 *   - $shouldFailGate: true iff the verdict routes to the sanctioned HARD
 *                       channel (STATUS_FAILED gate). True ONLY in hard mode
 *                       on a tripped verdict; never true in advisory or off.
 *   - $honestyFlags:   the honesty flags the gate appends in advisory mode.
 *                       Empty when off, on agreement, or on a skip.
 *   - $isNoOp:         true iff the gate did not evaluate a real divergence
 *                       (off mode, a skip, or agreement). A no-op NEVER trips.
 *   - $noOpReason:     explicit non-empty reason when $isNoOp is true.
 *   - $reason:         human-readable reason when $tripped is true.
 *   - $divergentDiffs: the divergent candidate diffs (evidence). Echoed from
 *                       the result for auditability and for the best-of-N
 *                       summary.
 *
 * Canonical: mission architecture.md (Atlas Dev Elevation v2, M5 / E4).
 */
final class CandidateDivergenceVerdict
{
    /**
     * @param  list<string>  $honestyFlags
     * @param  list<array{index: int, diff: string}>  $divergentDiffs
     */
    public function __construct(
        public readonly bool $tripped,
        public readonly bool $shouldFailGate,
        public readonly array $honestyFlags,
        public readonly bool $isNoOp,
        public readonly string $noOpReason,
        public readonly string $reason,
        public readonly array $divergentDiffs,
    ) {}

    /**
     * A no-op verdict: the gate did not evaluate a real divergence (off mode,
     * skip, or agreement). Never trips, never fails.
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
            divergentDiffs: [],
        );
    }
}
