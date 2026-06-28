<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Pattern;

/**
 * KEYSTONE #4 — CAUSAL credit, made rigorous. The {@see AtlasLoopPatternLearningLedger} stats() are
 * CORRELATIONAL (mean_outcome / success_rate) — a pattern can look good purely by being tried on easy
 * objectives. This gate estimates the CAUSAL effect of a pattern vs the pooled baseline of every OTHER
 * pattern, with a normal-approx confidence interval on the difference of means, and answers ONE binary
 * question: is this pattern's compounding PROVEN (its uplift CI excludes zero AND is positive)?
 *
 * Anti-Goodhart by construction: it emits effect + CI as EVIDENCE and a BINARY proven/not-proven gate —
 * NEVER a scalar leaderboard the selector climbs (a noisy high mean is NOT proven; the "fabricated
 * compounding" the spec warns about is exactly a high mean whose CI still straddles zero). author≠judge
 * intact: this only supplies evidence; the ChampionGate/selector remain the deciders. Pure + deterministic
 * (same rows → same verdict), so the math is directly unit-testable. Numeric-safe (guards n, variance, fp).
 */
final class AtlasLoopPatternCausalEffectGate
{
    /** ~95% two-sided normal CI. */
    public const DEFAULT_Z = 1.96;

    /** Below this per-arm sample count, an effect can never be "proven" — there isn't enough evidence. */
    public const MIN_SAMPLES = 3;

    /**
     * Causal effect of $patternId vs the pooled baseline (all other patterns), with a CI on the difference.
     *
     * @param  list<array{pattern_id:string, value:float}>  $pairs
     * @return array{pattern_id:string, effect:?float, ci_low:?float, ci_high:?float, n:int, baseline_n:int, proven:bool, reason:string}
     */
    public static function effectFor(array $pairs, string $patternId, float $z = self::DEFAULT_Z, int $minSamples = self::MIN_SAMPLES): array
    {
        $treat = [];
        $control = [];
        foreach ($pairs as $p) {
            $v = (float) ($p['value'] ?? 0.0);
            if (! is_finite($v)) {
                continue;
            }
            if ((string) ($p['pattern_id'] ?? '') === $patternId) {
                $treat[] = $v;
            } else {
                $control[] = $v;
            }
        }

        $nt = count($treat);
        $nc = count($control);
        $base = ['pattern_id' => $patternId, 'effect' => null, 'ci_low' => null, 'ci_high' => null, 'n' => $nt, 'baseline_n' => $nc];

        $min = max(2, $minSamples); // variance needs >=2; the policy floor is minSamples.
        if ($nt < $min || $nc < $min) {
            return $base + ['proven' => false, 'reason' => 'insufficient_evidence'];
        }

        $effect = self::mean($treat) - self::mean($control);
        $se = sqrt(self::sampleVar($treat) / $nt + self::sampleVar($control) / $nc);
        if (! is_finite($effect) || ! is_finite($se)) {
            return $base + ['proven' => false, 'reason' => 'degenerate'];
        }

        $ciLow = $effect - $z * $se;
        $ciHigh = $effect + $z * $se;
        $proven = $ciLow > 0.0; // CI excludes zero AND the effect is positive.

        return [
            'pattern_id' => $patternId,
            'effect' => $effect,
            'ci_low' => $ciLow,
            'ci_high' => $ciHigh,
            'n' => $nt,
            'baseline_n' => $nc,
            'proven' => $proven,
            'reason' => $proven ? 'compounding_proven' : 'ci_includes_zero_or_negative',
        ];
    }

    /** Binary gate the ChampionGate/selector consult: promote/prioritize a pattern only when this is true. */
    public static function isCompoundingProven(array $pairs, string $patternId, float $z = self::DEFAULT_Z, int $minSamples = self::MIN_SAMPLES): bool
    {
        return self::effectFor($pairs, $patternId, $z, $minSamples)['proven'];
    }

    /**
     * Map ledger rows to {pattern_id, value} pairs. value = measured_outcome when numeric, else the honest
     * binary success signal (result === 'success' ? 1.0 : 0.0) — so a row always contributes a fact.
     *
     * @param  list<array<string,mixed>>  $rows
     * @return list<array{pattern_id:string, value:float}>
     */
    public static function pairsFrom(array $rows): array
    {
        $pairs = [];
        foreach ($rows as $row) {
            $id = trim((string) ($row['pattern_id'] ?? ''));
            if ($id === '') {
                continue;
            }
            $measured = $row['measured_outcome'] ?? null;
            $value = is_numeric($measured)
                ? (float) $measured
                : (((string) ($row['result'] ?? '')) === AtlasLoopPatternLearningLedger::RESULT_SUCCESS ? 1.0 : 0.0);
            $pairs[] = ['pattern_id' => $id, 'value' => $value];
        }

        return $pairs;
    }

    /** @param  list<float>  $xs */
    private static function mean(array $xs): float
    {
        $n = count($xs);

        return $n === 0 ? 0.0 : array_sum($xs) / $n;
    }

    /** Sample variance (n-1). @param  list<float>  $xs */
    private static function sampleVar(array $xs): float
    {
        $n = count($xs);
        if ($n < 2) {
            return 0.0;
        }
        $mean = self::mean($xs);
        $ss = 0.0;
        foreach ($xs as $x) {
            $ss += ($x - $mean) ** 2;
        }

        return max(0.0, $ss / ($n - 1)); // clamp fp negatives.
    }
}
