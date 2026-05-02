<?php

namespace App\Services\Ai\Telemetry\Engine\Dto;

/**
 * Output of GEVSS attribution per anomaly. Persisted to ai_report_findings
 * with stable UUID so Agent 5 (Recommendation Lifecycle) can FK into it.
 */
final readonly class DiagnosticFinding
{
    public function __construct(
        public string $id,
        public string $metric,
        public string $direction,
        public ?float $magnitudePct,
        public array $attributionDimensions,
        public ?float $explainedFraction,
        public int $affectedN,
        public array $evidence,
        public array $signals,
        public float $confidenceScore,
        public string $confidenceBand,
        public ?string $suggestedActionSeed,
    ) {}

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'metric' => $this->metric,
            'direction' => $this->direction,
            'magnitude_pct' => $this->magnitudePct,
            'attribution' => [
                'dimensions' => $this->attributionDimensions,
                'explained_fraction' => $this->explainedFraction,
                'affected_n' => $this->affectedN,
            ],
            'evidence' => $this->evidence,
            'signals' => $this->signals,
            'confidence_score' => $this->confidenceScore,
            'confidence_band' => $this->confidenceBand,
            'suggested_action_seed' => $this->suggestedActionSeed,
        ];
    }
}
