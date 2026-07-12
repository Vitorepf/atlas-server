<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery;

/**
 * Pure trust assessment used by the heavy-work selector.
 *
 * This is deliberately only a deterministic read-side verdict. Persistence,
 * evidence recording and release authority belong to the governance ladder;
 * this class must not grant or perform a merge.
 */
final class AtlasLoopTrustLadder
{
    public const PARK_ONLY = 'park_only';

    public const TRUSTED_REVIEW = 'trusted_review';

    public const AUTONOMOUS_MERGE = 'autonomous_merge';

    private const Z = 1.96;

    private const TRUSTED_LOWER = 0.7;

    private const AUTONOMOUS_LOWER = 0.9;

    private const MIN_SAMPLES_FOR_AUTONOMY = 20;

    /**
     * @param array<string,mixed> $stats
     * @return array{level:string,can_auto_merge:bool,wilson_lower:float,samples:int,reason:string}
     */
    public function assess(array $stats): array
    {
        $successes = max(0, (int) ($stats['successes'] ?? 0));
        $failures = max(0, (int) ($stats['failures'] ?? 0));
        $samples = $successes + $failures;
        $lower = $this->wilsonLowerBound($successes, $samples);

        if ($samples >= self::MIN_SAMPLES_FOR_AUTONOMY && $lower >= self::AUTONOMOUS_LOWER) {
            return $this->verdict(
                self::AUTONOMOUS_MERGE,
                true,
                $lower,
                $samples,
                'proven acceptance: wilson_lower>=0.9 over >=20 obras',
            );
        }

        if ($lower >= self::TRUSTED_LOWER) {
            return $this->verdict(
                self::TRUSTED_REVIEW,
                false,
                $lower,
                $samples,
                'trusted but parked: wilson_lower>=0.7',
            );
        }

        return $this->verdict(
            self::PARK_ONLY,
            false,
            $lower,
            $samples,
            $samples < self::MIN_SAMPLES_FOR_AUTONOMY
                ? 'insufficient evidence (n='.$samples.') or rate not yet proven — parks for operator'
                : 'acceptance rate not yet proven — parks for operator',
        );
    }

    private function wilsonLowerBound(int $successes, int $samples): float
    {
        if ($samples <= 0) {
            return 0.0;
        }

        $zSquared = self::Z * self::Z;
        $rate = $successes / $samples;
        $denominator = 1.0 + $zSquared / $samples;
        $centre = $rate + $zSquared / (2 * $samples);
        $margin = self::Z * sqrt(($rate * (1.0 - $rate) + $zSquared / (4 * $samples)) / $samples);

        return max(0.0, ($centre - $margin) / $denominator);
    }

    /** @return array{level:string,can_auto_merge:bool,wilson_lower:float,samples:int,reason:string} */
    private function verdict(string $level, bool $canAutoMerge, float $lower, int $samples, string $reason): array
    {
        return [
            'level' => $level,
            'can_auto_merge' => $canAutoMerge,
            'wilson_lower' => round($lower, 4),
            'samples' => $samples,
            'reason' => $reason,
        ];
    }
}
