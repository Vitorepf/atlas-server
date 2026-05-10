<?php

namespace App\Services\Ai\Kernel\Gates;

use App\Services\Ai\Cognitive\PredictiveFailure\CalibrationBandClassifier;
use App\Services\Ai\Kernel\Slo\KernelSloProbe;

class PredictiveFailureCalibrationBandGate
{
    public function __construct(
        private readonly CalibrationBandClassifier $classifier,
        private readonly KernelSloProbe $slo,
    ) {}

    /**
     * @param  array<string,mixed>  $insertion
     * @return array<string,mixed>
     */
    public function evaluate(array $insertion): array
    {
        return $this->slo->measure('cognitive.predictive_failure.calibration_gate', function () use ($insertion): array {
            $probability = (float) ($insertion['predicted_failure_probability'] ?? 0.0);
            $band = $this->classifier->classify($probability);

            if (($band['band'] ?? null) === 'low') {
                return $this->result('blocked', 'predictive_failure_too_easy_outside_zone', $band);
            }

            if (($band['band'] ?? null) === 'high') {
                return $this->result('blocked', 'predictive_failure_too_hard_outside_zone', $band);
            }

            return $this->result('passed', 'predictive_failure_calibration_band_sweet', $band);
        }, [
            'domain' => (string) ($insertion['domain'] ?? 'learning'),
        ]);
    }

    /**
     * @param  array<string,mixed>  $band
     * @return array<string,mixed>
     */
    private function result(string $status, string $reason, array $band): array
    {
        return [
            'schema_version' => 'atlas.gate.predictive_failure_calibration_band.v1',
            'gate' => 'predictive_failure_calibration_band',
            'status' => $status,
            'reason' => $reason,
            'band' => $band['band'] ?? 'unknown',
            'probability' => $band['probability'] ?? null,
            'sweet_min' => $band['sweet_min'] ?? CalibrationBandClassifier::SWEET_MIN,
            'sweet_max' => $band['sweet_max'] ?? CalibrationBandClassifier::SWEET_MAX,
        ];
    }
}
