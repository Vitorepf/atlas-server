<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery;

/**
 * TRUST LADDER — "more autonomous, less human", earned by DEMONSTRATED success and HONEST about
 * sample size. Autonomy for a work-class rises only as its real acceptance rate proves out; it does
 * NOT jump on a few lucky wins.
 *
 * The math is the WILSON SCORE INTERVAL LOWER BOUND on the success proportion — not the point estimate
 * s/n. The point estimate of 3/3 is 1.0, but we are NOT confident the true rate is 1.0 from 3 samples;
 * the Wilson lower bound at 3/3 is ~0.44, so it stays PARK_ONLY. Only when the lower bound (the rate we
 * are statistically confident the class AT LEAST achieves) clears a threshold AND a minimum sample size
 * is met does the class earn higher autonomy. This is what makes "autonomy rises by acceptance rate"
 * honest rather than gameable by a small lucky streak.
 *
 *   PARK_ONLY        default — every obra parks for operator review (no autonomous merge).
 *   TRUSTED_REVIEW   lower bound >= 0.7 with enough samples — parked, but prioritised / fast-tracked.
 *   AUTONOMOUS_MERGE lower bound >= 0.9 AND samples >= the floor — eligible for the governed auto-merge.
 *
 * AUTONOMOUS_MERGE is necessary-but-not-sufficient: the governed crossing ALSO requires its own
 * fail-closed gates (certification, broader-regression, clean-tree, lock) and the operator's flag. The
 * ladder gates the RIGHT to attempt the crossing by proven track record; it never bypasses a gate.
 * Pure: no DB, no provider, no mutation.
 */
final class AtlasLoopTrustLadder
{
    public const PARK_ONLY = 'park_only';

    public const TRUSTED_REVIEW = 'trusted_review';

    public const AUTONOMOUS_MERGE = 'autonomous_merge';

    /** 95% confidence z-score for the Wilson interval. */
    private const Z = 1.96;

    private const TRUSTED_LOWER = 0.7;

    private const AUTONOMOUS_LOWER = 0.9;

    /** No class earns AUTONOMOUS_MERGE below this many observed obras, however high the rate. */
    private const MIN_SAMPLES_FOR_AUTONOMY = 20;

    /**
     * @param  array<string,mixed>  $stats  {successes:int, failures:int} for a work-class
     * @return array{level:string, can_auto_merge:bool, wilson_lower:float, samples:int, reason:string}
     */
    public function assess(array $stats): array
    {
        $s = max(0, (int) ($stats['successes'] ?? 0));
        $f = max(0, (int) ($stats['failures'] ?? 0));
        $n = $s + $f;
        $lower = $this->wilsonLowerBound($s, $n);

        if ($n >= self::MIN_SAMPLES_FOR_AUTONOMY && $lower >= self::AUTONOMOUS_LOWER) {
            return $this->verdict(self::AUTONOMOUS_MERGE, true, $lower, $n, 'proven acceptance: wilson_lower>=0.9 over >='.self::MIN_SAMPLES_FOR_AUTONOMY.' obras');
        }
        if ($lower >= self::TRUSTED_LOWER) {
            return $this->verdict(self::TRUSTED_REVIEW, false, $lower, $n, 'trusted but parked: wilson_lower>=0.7');
        }

        return $this->verdict(self::PARK_ONLY, false, $lower, $n, $n < self::MIN_SAMPLES_FOR_AUTONOMY
            ? 'insufficient evidence (n='.$n.') or rate not yet proven — parks for operator'
            : 'acceptance rate not yet proven — parks for operator');
    }

    /**
     * Wilson score interval LOWER bound for a binomial proportion. n=0 => 0 (no evidence, no trust).
     * Penalises small samples: a perfect-but-tiny record yields a modest lower bound, not 1.0.
     */
    private function wilsonLowerBound(int $successes, int $n): float
    {
        if ($n <= 0) {
            return 0.0;
        }
        $z = self::Z;
        $phat = $successes / $n;
        $z2 = $z * $z;
        $denom = 1.0 + $z2 / $n;
        $centre = $phat + $z2 / (2 * $n);
        $margin = $z * sqrt(($phat * (1.0 - $phat) + $z2 / (4 * $n)) / $n);

        return max(0.0, ($centre - $margin) / $denom);
    }

    /**
     * @return array{level:string, can_auto_merge:bool, wilson_lower:float, samples:int, reason:string}
     */
    private function verdict(string $level, bool $canAutoMerge, float $lower, int $n, string $reason): array
    {
        return [
            'level' => $level,
            'can_auto_merge' => $canAutoMerge,
            'wilson_lower' => round($lower, 4),
            'samples' => $n,
            'reason' => $reason,
        ];
    }
}
