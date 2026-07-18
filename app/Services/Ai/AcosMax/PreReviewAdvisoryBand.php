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
    public const FIELD_FABRICATES_RATE_ON_ZERO_N = 'fabricates_rate_on_zero_n';
    public const FIELD_LIFT_BASIS = 'lift_basis';
    public const SCHEMA_VERSION = 'atlas.operator.pre_review_advisory_band.v1';

    public const FORMULA_VERSION = 'atlas.multn15_08.pre_review_band.v1';

    public const CALIBRATION_SCHEMA = 'atlas.operator.pre_review_advisory_band.calibration.v1';

    /** Below this floor the band is `insufficient_sample`, NEVER a band. */
    public const MIN_N_FOR_BAND = 10;

    /** Death criterion pinned (ELEV-03) — same window used across ASI-15 family. */
    public const DEATH_MIN_N = 30;

    /** Below this lift (high_revert_rate − low_revert_rate), the family dies. */
    public const DEATH_MIN_LIFT = 0.15;

    public const TARGET_CLASS_UNKNOWN = 'unknown';

    public const BASIS_INSUFFICIENT_SAMPLE = 'insufficient_sample';

    public const BASIS_MEASURED = 'measured';

    public const FIELD_SCHEMA_VERSION = 'schema_version';
    public const FIELD_FORMULA_VERSION = 'formula_version';
    public const FIELD_PREDICTED_REVERT_BAND = 'predicted_revert_band';
    public const FIELD_BASIS = 'basis';
    public const FIELD_REALIZED_REVERT_RATE = 'realized_revert_rate';
    public const FIELD_FEATURES = 'features';
    public const FIELD_PROBABILITY = 'probability';
    public const FIELD_SOURCE = 'source';
    public const FIELD_CURVE = 'curve';
    public const FIELD_N = 'n';
    public const FIELD_REVERTS = 'reverts';
    public const FIELD_TARGET_CLASS = 'target_class';
    public const FIELD_RISK_BAND = 'risk_band';
    public const FIELD_CONFIDENCE_BAND = 'confidence_band';
    public const FIELD_SIMILAR_REVERT_RATE = 'similar_revert_rate';
    public const FIELD_N_SIMILAR = 'n_similar';
    public const FIELD_HIGH = 'high';
    public const FIELD_LOW = 'low';
    public const FIELD_REVERTED = 'reverted';
    public const FIELD_BAND = 'band';
    public const FIELD_BLOCKS_AUTO_APPLY = 'blocks_auto_apply';
    public const FIELD_CRITICAL = 'critical';
    public const FIELD_DEATH_CRITERION = 'death_criterion';
    public const FIELD_DELAYS_AUTO_APPLY = 'delays_auto_apply';
    public const FIELD_MUTATES_PIPELINE = 'mutates_pipeline';
    public const FIELD_REORDERS_DIGEST_ONLY = 'reorders_digest_only';
    public const FIELD_REUSES_CALIBRATION_BAND_CLASSIFIER = 'reuses_calibration_band_classifier';
    public const FIELD_LIFT_HIGH_OVER_LOW = 'lift_high_over_low';
    public const FIELD_MEDIUM = 'medium';
    public const FIELD_MIN_LIFT = 'min_lift';
    public const FIELD_MIN_N = 'min_n';
    public const FIELD_MIN_N_FOR_BAND = 'min_n_for_band';
    public const FIELD_SATISFIED_FOR_DEATH = 'satisfied_for_death';
    public const FIELD_SINGLE_SCALAR_FORBIDDEN = 'single_scalar_forbidden';
    public const FIELD_SWEET = 'sweet';
    public const FIELD_OPS = 'ops';
    public const FIELD_UNKNOWN = 'unknown';
    public const FIELD_DEBUG = 'debug';
    public const FLOAT_0_08 = 0.08;
    public const FLOAT_0_15 = 0.15;
    public const FLOAT_0_02 = 0.02;
    public const FLOAT_0_05 = 0.05;
    public const FLOAT_0_0 = 0.0;
    public const FLOAT_NEG_0_05 = -0.05;

    /**
     * @param  array<string,mixed>  $features required keys:
     *   target_class: string (e.g. 'migrations', self::FIELD_OPS, self::FIELD_DEBUG, self::FIELD_UNKNOWN)
     *   risk_band: self::FIELD_LOW|self::FIELD_MEDIUM|self::FIELD_HIGH|'critical'|null
     *   confidence_band: self::FIELD_LOW|self::FIELD_SWEET|self::FIELD_HIGH|null (declared self-model band)
     *   similar_revert_rate: float [0..1] (from MAXN-03)
     *   n_similar: int (from MAXN-03)
     *
     * @return array<string,mixed>
     */
    public static function judge(array $features, ?CalibrationBandClassifier $classifier = null): array
    {
        $targetClass = self::normalizeClass($features[self::FIELD_TARGET_CLASS] ?? null);
        $riskBand = self::normalizeRiskBand($features[self::FIELD_RISK_BAND] ?? null);
        $confidenceBand = self::normalizeConfBand($features[self::FIELD_CONFIDENCE_BAND] ?? null);
        $rate = AiValueNormalizer::finiteFloatOrNull($features[self::FIELD_SIMILAR_REVERT_RATE] ?? null);
        $rate = $rate === null ? null : AiValueNormalizer::clampUnit($rate);
        $nSimilar = max(0, (int) (AiValueNormalizer::finiteFloatOrNull($features[self::FIELD_N_SIMILAR] ?? null) ?? 0));

        $result = [
            self::FIELD_SCHEMA_VERSION => self::SCHEMA_VERSION,
            self::FIELD_FORMULA_VERSION => self::FORMULA_VERSION,
            self::FIELD_FEATURES => [
                self::FIELD_TARGET_CLASS => $targetClass,
                self::FIELD_RISK_BAND => $riskBand,
                self::FIELD_CONFIDENCE_BAND => $confidenceBand,
                self::FIELD_SIMILAR_REVERT_RATE => $rate,
                self::FIELD_N_SIMILAR => $nSimilar,
            ],
            self::FIELD_PREDICTED_REVERT_BAND => null,
            self::FIELD_PROBABILITY => null,
            self::FIELD_BASIS => self::BASIS_INSUFFICIENT_SAMPLE,
            self::FIELD_SOURCE => [
                self::FIELD_BLOCKS_AUTO_APPLY => false,
                self::FIELD_DELAYS_AUTO_APPLY => false,
                self::FIELD_MUTATES_PIPELINE => false,
                self::FIELD_REORDERS_DIGEST_ONLY => true,
                self::FIELD_REUSES_CALIBRATION_BAND_CLASSIFIER => true,
                self::FIELD_MIN_N_FOR_BAND => self::MIN_N_FOR_BAND,
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
            self::FIELD_CRITICAL => self::FLOAT_0_15,
            self::FIELD_HIGH => self::FLOAT_0_08,
            self::FIELD_MEDIUM => self::FLOAT_0_02,
            default => self::FLOAT_0_0,
        };
        // Confidence nudge: low confidence ⇒ more likely revert; high confidence ⇒ less.
        $probability += match ($confidenceBand) {
            self::FIELD_LOW => self::FLOAT_0_05,
            self::FIELD_HIGH => self::FLOAT_NEG_0_05,
            default => self::FLOAT_0_0,
        };
        $probability = AiValueNormalizer::clampUnit($probability);

        $classification = ($classifier ?? new CalibrationBandClassifier)->classify($probability);

        $result[self::FIELD_PREDICTED_REVERT_BAND] = AiValueNormalizer::trimmedStringOrNull($classification[self::FIELD_BAND] ?? null) ?? '';
        $result[self::FIELD_PROBABILITY] = $probability;
        $result[self::FIELD_BASIS] = self::BASIS_MEASURED;

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
        $buckets = [self::FIELD_LOW => [self::FIELD_N => 0, self::FIELD_REVERTED => 0], self::FIELD_SWEET => [self::FIELD_N => 0, self::FIELD_REVERTED => 0], self::FIELD_HIGH => [self::FIELD_N => 0, self::FIELD_REVERTED => 0]];
        foreach ($observations as $obs) {
            $band = AiValueNormalizer::trimmedStringOrNull($obs[self::FIELD_PREDICTED_REVERT_BAND] ?? null);
            if ($band === null || ! isset($buckets[$band])) {
                continue;
            }
            $buckets[$band][self::FIELD_N]++;
            if (($obs[self::FIELD_REVERTED] ?? false) === true) {
                $buckets[$band][self::FIELD_REVERTED]++;
            }
        }
        $curve = [];
        foreach ($buckets as $band => $agg) {
            $curve[$band] = [
                self::FIELD_N => $agg[self::FIELD_N],
                self::FIELD_REVERTS => $agg[self::FIELD_REVERTED],
                self::FIELD_REALIZED_REVERT_RATE => $agg[self::FIELD_N] > 0 ? $agg[self::FIELD_REVERTED] / $agg[self::FIELD_N] : null,
                self::FIELD_BASIS => $agg[self::FIELD_N] > 0 ? self::BASIS_MEASURED : self::BASIS_INSUFFICIENT_SAMPLE,
            ];
        }

        $lift = null;
        $liftBasis = self::BASIS_INSUFFICIENT_SAMPLE;
        if ($curve[self::FIELD_HIGH][self::FIELD_N] >= self::DEATH_MIN_N && $curve[self::FIELD_LOW][self::FIELD_N] >= self::DEATH_MIN_N) {
            $lift = ($curve[self::FIELD_HIGH][self::FIELD_REALIZED_REVERT_RATE] ?? 0.0) - ($curve[self::FIELD_LOW][self::FIELD_REALIZED_REVERT_RATE] ?? 0.0);
            $liftBasis = self::BASIS_MEASURED;
        }

        return [
            self::FIELD_SCHEMA_VERSION => self::CALIBRATION_SCHEMA,
            self::FIELD_FORMULA_VERSION => self::FORMULA_VERSION,
            self::FIELD_CURVE => $curve,
            self::FIELD_LIFT_HIGH_OVER_LOW => $lift,
            self::FIELD_LIFT_BASIS => $liftBasis,
            self::FIELD_DEATH_CRITERION => [
                self::FIELD_MIN_N => self::DEATH_MIN_N,
                self::FIELD_MIN_LIFT => self::DEATH_MIN_LIFT,
                self::FIELD_SATISFIED_FOR_DEATH => $liftBasis === self::BASIS_MEASURED && $lift !== null && $lift < self::DEATH_MIN_LIFT,
            ],
            self::FIELD_SOURCE => [
                self::FIELD_SINGLE_SCALAR_FORBIDDEN => true,
                self::FIELD_FABRICATES_RATE_ON_ZERO_N => false,
            ],
        ];
    }

    private static function normalizeClass(mixed $value): string
    {
        if (AiValueNormalizer::trimmedStringOrNull($value) === null) {
            return self::TARGET_CLASS_UNKNOWN;
        }
        $trim = AiValueNormalizer::lowerTrimmedString($value);

        return mb_substr($trim, 0, 64);
    }

    private static function normalizeRiskBand(mixed $value): ?string
    {
        return self::normalizeAllowlistedBand($value, [self::FIELD_LOW, self::FIELD_MEDIUM, self::FIELD_HIGH, 'critical']);
    }

    private static function normalizeConfBand(mixed $value): ?string
    {
        return self::normalizeAllowlistedBand($value, [self::FIELD_LOW, self::FIELD_SWEET, self::FIELD_HIGH]);
    }

    /**
     * @param  list<string>  $allowed
     */
    private static function normalizeAllowlistedBand(mixed $value, array $allowed): ?string
    {
        if (AiValueNormalizer::trimmedStringOrNull($value) === null) {
            return null;
        }
        $lower = AiValueNormalizer::lowerTrimmedString($value);

        return in_array($lower, $allowed, true) ? $lower : null;
    }
}
