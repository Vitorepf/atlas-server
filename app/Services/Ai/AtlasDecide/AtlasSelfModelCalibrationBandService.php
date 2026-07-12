<?php

declare(strict_types=1);

namespace App\Services\Ai\AtlasDecide;

use App\Services\Ai\Cognitive\PredictiveFailure\CalibrationBandClassifier;

/**
 * ASI-15 — DERIVED confidence band on the Decide's self-model candidate.
 *
 * Reuses the calibration band vocabulary already living in
 * {@see CalibrationBandClassifier} (`low` / `sweet` / `high` — pinned
 * thresholds), and folds a sample-size guard so the anti-Goodhart law of
 * "no calibration on n<10" is enforced by construction: below the floor the
 * band is `insufficient_sample`, never `low`/`sweet`/`high`. THE CALLER
 * CANNOT SET THE BAND — it is a pure function of {proven_rate, n}.
 *
 * Ex-post curve (declared band × outcome real) is computed by
 * {@see calibrationCurve()} — groups outcomes by the band the decision was
 * declared with and reports realized-rate per band + denominator. A band
 * with fewer than MIN_CURVE_SAMPLES realized outcomes remains
 * `insufficient_sample` on the curve.
 *
 * NEVER emits a single scalar score — bands + denominators only (the plan's
 * "92 lesson" from COM-04).
 */
final class AtlasSelfModelCalibrationBandService
{
    public const SCHEMA = 'atlas.decide.self_model_calibration_band.v1';

    public const BAND_INSUFFICIENT = 'insufficient_sample';

    public const MIN_N_FOR_BAND = 10;

    public const MIN_CURVE_SAMPLES = 10;

    /**
     * Derive the confidence band from a self-model candidate. Never called
     * with caller-supplied `band`; always derives it.
     *
     * @param  array{n?:int,proven_rate?:float,route?:string}  $candidate
     * @return array<string,mixed>
     */
    public function classifyCandidate(array $candidate): array
    {
        $n = max(0, (int) ($candidate['n'] ?? 0));
        $rate = (float) ($candidate['proven_rate'] ?? 0.0);

        if ($n < self::MIN_N_FOR_BAND) {
            return [
                'schema' => self::SCHEMA,
                'route' => $candidate['route'] ?? null,
                'n' => $n,
                'proven_rate' => round($rate, 4),
                'band' => self::BAND_INSUFFICIENT,
                'reason' => 'n_below_floor',
                'floor' => self::MIN_N_FOR_BAND,
            ];
        }

        $classified = (new CalibrationBandClassifier)->classify($rate);

        return [
            'schema' => self::SCHEMA,
            'route' => $candidate['route'] ?? null,
            'n' => $n,
            'proven_rate' => round($rate, 4),
            'band' => (string) $classified['band'],
            'sweet_min' => $classified['sweet_min'],
            'sweet_max' => $classified['sweet_max'],
        ];
    }

    /**
     * Ex-post calibration curve: group realized outcomes by declared band and
     * report realized proven-rate per band with denominators exposed.
     *
     * @param  list<array{declared_band?:string,proven_real?:bool}>  $outcomes
     * @return array<string,mixed>
     */
    public function calibrationCurve(array $outcomes): array
    {
        $buckets = [
            'low' => ['declared' => 0, 'realized_proven' => 0],
            'sweet' => ['declared' => 0, 'realized_proven' => 0],
            'high' => ['declared' => 0, 'realized_proven' => 0],
        ];

        foreach ($outcomes as $row) {
            $band = (string) ($row['declared_band'] ?? '');
            if (! isset($buckets[$band])) {
                continue;
            }
            $buckets[$band]['declared']++;
            if (($row['proven_real'] ?? false) === true) {
                $buckets[$band]['realized_proven']++;
            }
        }

        $emit = [];
        foreach ($buckets as $band => $counts) {
            $n = $counts['declared'];
            if ($n < self::MIN_CURVE_SAMPLES) {
                $emit[$band] = [
                    'declared_band' => $band,
                    'n' => $n,
                    'realized_proven' => $counts['realized_proven'],
                    'realized_rate' => null,
                    'status' => self::BAND_INSUFFICIENT,
                ];

                continue;
            }
            $realizedRate = $counts['realized_proven'] / $n;
            $emit[$band] = [
                'declared_band' => $band,
                'n' => $n,
                'realized_proven' => $counts['realized_proven'],
                'realized_rate' => round($realizedRate, 4),
                'status' => 'ok',
            ];
        }

        return [
            'schema' => self::SCHEMA.'.curve',
            'min_curve_samples' => self::MIN_CURVE_SAMPLES,
            'bands' => array_values($emit),
        ];
    }
}
