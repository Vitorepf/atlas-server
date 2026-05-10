<?php

namespace App\Services\Ai\Cognitive\PredictiveFailure;

class CalibrationBandClassifier
{
    public const SWEET_MIN = 0.70;

    public const SWEET_MAX = 0.85;

    /**
     * @return array<string,mixed>
     */
    public function classify(float $probability): array
    {
        $probability = round(max(0.0, min(1.0, $probability)), 3);

        $band = match (true) {
            $probability < self::SWEET_MIN => 'low',
            $probability > self::SWEET_MAX => 'high',
            default => 'sweet',
        };

        return [
            'schema_version' => 'atlas.cognitive.predictive_failure.calibration_band.v1',
            'probability' => $probability,
            'band' => $band,
            'sweet_min' => self::SWEET_MIN,
            'sweet_max' => self::SWEET_MAX,
            'status' => $band === 'sweet' ? 'passed' : 'outside',
        ];
    }
}
