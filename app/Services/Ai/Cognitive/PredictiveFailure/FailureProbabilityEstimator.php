<?php

namespace App\Services\Ai\Cognitive\PredictiveFailure;

class FailureProbabilityEstimator
{
    /**
     * @param  array<string,mixed>  $selection
     * @return array<string,mixed>
     */
    public function estimate(array $selection): array
    {
        $signals = (array) ($selection['signals_used'] ?? []);
        $stage = max(1, min(5, (int) ($signals['dreyfus_stage'] ?? 2)));
        $stageRisk = match ($stage) {
            1 => 0.28,
            2 => 0.22,
            3 => 0.14,
            4 => 0.06,
            default => 0.02,
        };

        $probability = 0.35
            + ((float) ($signals['kg_gap_score'] ?? 0.5) * 0.18)
            + ((float) ($signals['decay_score'] ?? 0.5) * 0.12)
            + ((float) ($signals['failure_history_signal'] ?? 0.3) * 0.14)
            + $stageRisk;

        return [
            'schema_version' => 'atlas.cognitive.predictive_failure.probability_estimate.v1',
            'predicted_failure_probability' => round(max(0.0, min(0.95, $probability)), 3),
            'model' => 'bounded_weighted_bayesian_prior_v0',
            'signals_used' => $signals,
        ];
    }
}
