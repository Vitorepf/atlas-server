<?php

declare(strict_types=1);

namespace App\Services\Ai\Context\Gates;

final class HallucinationRiskBandClassifier
{
    /**
     * @return array<string,mixed>
     */
    public function classify(float $riskScore, float $elevatedThreshold = 0.05, float $highThreshold = 0.15): array
    {
        $riskScore = round(max(0.0, min(1.0, $riskScore)), 3);

        $band = match (true) {
            $riskScore < $elevatedThreshold => 'low',
            $riskScore < $highThreshold => 'elevated',
            default => 'high',
        };

        return [
            'schema_version' => 'atlas.context.hallucination_risk_band.v1',
            'risk_score' => $riskScore,
            'band' => $band,
            'abstain' => $band === 'high',
            'elevated_threshold' => round($elevatedThreshold, 3),
            'high_threshold' => round($highThreshold, 3),
            'status' => match ($band) {
                'low' => 'passed',
                'elevated' => 'review',
                default => 'abstained',
            },
        ];
    }
}
