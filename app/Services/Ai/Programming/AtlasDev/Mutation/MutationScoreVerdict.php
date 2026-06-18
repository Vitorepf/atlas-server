<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Mutation;

/**
 * Verdict returned by {@see MutationScoreGate::evaluate()}.
 *
 * A pure value object: it carries what the gate decided, never how to apply
 * it. The PipelineRunExecutor reads the verdict and routes through the
 * sanctioned channels (honesty flag for advisory, STATUS_FAILED gate for
 * hard). The verdict itself does NOT mutate any gate result.
 *
 * Fields:
 *   - $tripped:        true iff the gate decided the patch's MSI is below
 *                       the threshold (or unevaluable but the gate ran in a
 *                       non-off mode and must not silently green). False on a
 *                       pass, a skip, or off mode.
 *   - $shouldFailGate: true iff the verdict routes to the sanctioned HARD
 *                       channel (STATUS_FAILED gate). True ONLY in hard mode
 *                       on a tripped verdict; never true in advisory (advisory
 *                       uses the honesty-flag channel only) or off.
 *   - $honestyFlags:   the honesty flags the gate appends in advisory mode.
 *                       Empty when off, on a pass, or on a skip. Carries the
 *                       `mutation_score_below_threshold` flag (advisory) and
 *                       retains it for auditability in hard mode.
 *   - $isNoOp:         true iff the gate did not evaluate a real MSI (off
 *                       mode, or a skipped/empty-scope result). A no-op
 *                       NEVER trips and NEVER fails — VAL-E3-008 / VAL-E3-010.
 *   - $noOpReason:     explicit non-empty reason when $isNoOp is true
 *                       (auditability, VAL-CROSS-016 evidence surface).
 *   - $reason:         human-readable reason when $tripped is true (carried
 *                       into evidence artifacts / receipts for auditability).
 *   - $msi:            the real reported MSI the verdict tracks (echoes the
 *                       MutationTestingResult.msi, the basis of the verdict).
 *                       Null on a skip / failed / off path.
 *   - $threshold:      the threshold the verdict was computed against.
 */
final class MutationScoreVerdict
{
    /**
     * @param  list<string>  $honestyFlags
     */
    public function __construct(
        public readonly bool $tripped,
        public readonly bool $shouldFailGate,
        public readonly array $honestyFlags,
        public readonly bool $isNoOp,
        public readonly string $noOpReason,
        public readonly string $reason,
        public readonly ?float $msi,
        public readonly float $threshold,
    ) {}

    /**
     * A no-op verdict: the gate did not evaluate a real MSI (off mode, or a
     * skipped/empty-scope result). Never trips, never fails (VAL-E3-008 /
     * VAL-E3-010). The reason is carried for auditability.
     */
    public static function noOp(string $reason, float $threshold): self
    {
        return new self(
            tripped: false,
            shouldFailGate: false,
            honestyFlags: [],
            isNoOp: true,
            noOpReason: $reason,
            reason: '',
            msi: null,
            threshold: $threshold,
        );
    }

    /**
     * A pass verdict: a real MSI at/above the threshold in a non-off mode.
     * No flag, no fail (VAL-E3-004: a robust test passes in both modes).
     */
    public static function pass(float $msi, float $threshold): self
    {
        return new self(
            tripped: false,
            shouldFailGate: false,
            honestyFlags: [],
            isNoOp: false,
            noOpReason: '',
            reason: '',
            msi: $msi,
            threshold: $threshold,
        );
    }
}
