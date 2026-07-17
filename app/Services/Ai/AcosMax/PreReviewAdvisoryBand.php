<?php

declare(strict_types=1);

namespace App\Services\Ai\AcosMax;

use App\Services\Ai\Cognitive\PredictiveFailure\CalibrationBandClassifier;
use App\Services\Ai\Support\AiValueNormalizer;

/**
 * MULTN15-08 — pre-review advisory band: "would the operator revert this?"
 *
 * Frontier plan §2768-2772 (M6 · ADVISORY):
 *  - PURE score derived from deterministic features {target class, risk band,
 *    historical revert/override rate for similar items (MAXN-03), confidence band}
 *  - Anotates the auto-apply receipt with `predicted_revert_band`;
 *    **NEVER blocks, NEVER delays** auto-apply — only orders the review-debt
 *    queue in the digest (ELEV-25 is the FIRST honest consumer).
 *  - Reuses CalibrationBandClassifier (pattern ASI-15) → bands {low, sweet, high}.
 *  - n<10 similar historical items ⇒ `insufficient_sample` (NEVER emits a band).
 *  - Death criterion pinned in freeze: after 2 windows, if high-band does NOT
 *    separate from low-band (`lift_high_over_low < DEATH_MIN_LIFT`) with
 *    `n≥DEATH_MIN_N`, family is SUSPENDED (ELEV-28).
 *  - Ex-post calibration (declared band × real revert, denominators cru, NEVER
 *    single scalar — "92" lesson).
 *
 * Charter (§2771 "banda alta NUNCA bloqueia nem atrasa"):
 *   source.blocks_auto_apply=false + source.delays_auto_apply=false +
 *   source.mutates_pipeline=false — cravado no schema.
 */
final class PreReviewAdvisoryBand
{
    public const SCHEMA_VERSION = 'atlas.operator.pre_review_advisory_band.v1';

    public const FORMULA_VERSION = 'atlas.multn15_08.pre_review_band.v1';

    /** Below this floor the band is `insufficient_sample`, NEVER a band. */
    public const MIN_N_FOR_BAND = 10;

    /** Death criterion pinned (ELEV-03) — same window used across ASI-15 family. */
    public const DEATH_MIN_N = 30;

    /** Below this lift (high_revert_rate − low_revert_rate), the family dies. */
    public const DEATH_MIN_LIFT = 0.15;

    /**
     * @param  array<string,mixed>  $features required keys:
     *   target_class: string (e.g. 'migrations', 'ops', 'debug', 'unknown')
     *   risk_band: 'low'|'medium'|'high'|'critical'|null
     *   confidence_band: 'low'|'sweet'|'high'|null (declared self-model band)
     *   similar_revert_rate: float [0..1] (from MAXN-03)
     *   n_similar: int (from MAXN-03)
     *
     * @return array<string,mixed>
     */
    public static function judge(array $features, ?CalibrationBandClassifier $classifier = null): array
    {
        $targetClass = self::normalizeClass($features['target_class'] ?? null);
        $riskBand = self::normalizeRiskBand($features['risk_band'] ?? null);
        $confidenceBand = self::normalizeConfBand($features['confidence_band'] ?? null);
        $rawRate = $features['similar_revert_rate'] ?? null;
        $rate = is_numeric($rawRate) ? AiValueNormalizer::clampUnit((float) $rawRate) : null;
        $nSimilar = max(0, (int) ($features['n_similar'] ?? 0));

        $result = [
            'schema_version' => self::SCHEMA_VERSION,
            'formula_version' => self::FORMULA_VERSION,
            'features' => [
                'target_class' => $targetClass,
                'risk_band' => $riskBand,
                'confidence_band' => $confidenceBand,
                'similar_revert_rate' => $rate,
                'n_similar' => $nSimilar,
            ],
            'predicted_revert_band' => null,
            'probability' => null,
            'basis' => 'insufficient_sample',
            'source' => [
                'blocks_auto_apply' => false,
                'delays_auto_apply' => false,
                'mutates_pipeline' => false,
                'reorders_digest_only' => true,
                'reuses_calibration_band_classifier' => true,
                'min_n_for_band' => self::MIN_N_FOR_BAND,
            ],
        ];

        // Insufficient sample ⇒ NEVER a band (pétreo §2771: "n<10 ⇒ insufficient_sample").
        if ($rate === null || $nSimilar < self::MIN_N_FOR_BAND) {
            return $result;
        }

        // Base probability = historical revert rate, then nudge by risk & confidence.
        $probability = $rate;
        // Risk nudge: higher risk band ⇒ higher predicted-revert probability.
        $probability += match ($riskBand) {
            'critical' => 0.15,
            'high' => 0.08,
            'medium' => 0.02,
            default => 0.0,
        };
        // Confidence nudge: low confidence ⇒ more likely revert; high confidence ⇒ less.
        $probability += match ($confidenceBand) {
            'low' => 0.05,
            'high' => -0.05,
            default => 0.0,
        };
        $probability = AiValueNormalizer::clampUnit($probability);

        $classification = ($classifier ?? new CalibrationBandClassifier)->classify($probability);

        $result['predicted_revert_band'] = (string) $classification['band'];
        $result['probability'] = $probability;
        $result['basis'] = 'measured';

        return $result;
    }

    /**
     * Ex-post calibration: group by declared band, publish {n, realized_revert_rate}
     * per band. NEVER emits a single scalar; NEVER fabricates rate on n=0.
     *
     * @param  list<array{predicted_revert_band?:string,reverted?:bool}>  $observations
     * @return array<string,mixed>
     */
    public static function calibration(array $observations): array
    {
        $buckets = ['low' => ['n' => 0, 'reverted' => 0], 'sweet' => ['n' => 0, 'reverted' => 0], 'high' => ['n' => 0, 'reverted' => 0]];
        foreach ($observations as $obs) {
            $band = $obs['predicted_revert_band'] ?? null;
            if (! is_string($band) || ! isset($buckets[$band])) {
                continue;
            }
            $buckets[$band]['n']++;
            if (($obs['reverted'] ?? false) === true) {
                $buckets[$band]['reverted']++;
            }
        }
        $curve = [];
        foreach ($buckets as $band => $agg) {
            $curve[$band] = [
                'n' => $agg['n'],
                'reverts' => $agg['reverted'],
                'realized_revert_rate' => $agg['n'] > 0 ? $agg['reverted'] / $agg['n'] : null,
                'basis' => $agg['n'] > 0 ? 'measured' : 'insufficient_sample',
            ];
        }

        $lift = null;
        $liftBasis = 'insufficient_sample';
        if ($curve['high']['n'] >= self::DEATH_MIN_N && $curve['low']['n'] >= self::DEATH_MIN_N) {
            $lift = ($curve['high']['realized_revert_rate'] ?? 0.0) - ($curve['low']['realized_revert_rate'] ?? 0.0);
            $liftBasis = 'measured';
        }

        return [
            'schema_version' => 'atlas.operator.pre_review_advisory_band.calibration.v1',
            'formula_version' => self::FORMULA_VERSION,
            'curve' => $curve,
            'lift_high_over_low' => $lift,
            'lift_basis' => $liftBasis,
            'death_criterion' => [
                'min_n' => self::DEATH_MIN_N,
                'min_lift' => self::DEATH_MIN_LIFT,
                'satisfied_for_death' => $liftBasis === 'measured' && $lift !== null && $lift < self::DEATH_MIN_LIFT,
            ],
            'source' => [
                'single_scalar_forbidden' => true,
                'fabricates_rate_on_zero_n' => false,
            ],
        ];
    }

    private static function normalizeClass(mixed $value): string
    {
        if (! is_string($value) || $value === '') {
            return 'unknown';
        }
        $trim = AiValueNormalizer::lowerTrimmedString($value);

        return mb_substr($trim, 0, 64);
    }

    private static function normalizeRiskBand(mixed $value): ?string
    {
        return self::normalizeAllowlistedBand($value, ['low', 'medium', 'high', 'critical']);
    }

    private static function normalizeConfBand(mixed $value): ?string
    {
        return self::normalizeAllowlistedBand($value, ['low', 'sweet', 'high']);
    }

    /**
     * @param  list<string>  $allowed
     */
    private static function normalizeAllowlistedBand(mixed $value, array $allowed): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $lower = AiValueNormalizer::lowerTrimmedString($value);

        return in_array($lower, $allowed, true) ? $lower : null;
    }
}
