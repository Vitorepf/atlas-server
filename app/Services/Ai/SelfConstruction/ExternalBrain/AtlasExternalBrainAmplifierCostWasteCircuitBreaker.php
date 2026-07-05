<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Stops amplifier routes that spend more effort without improving
 * accepted task quality or downstream success.
 *
 * Read-only: never starts processes, never calls providers.
 */
final class AtlasExternalBrainAmplifierCostWasteCircuitBreaker
{
    public const SCHEMA = 'atlas.self_construction.external_brain_amplifier_cost_waste_circuit_breaker.v1';

    public const COST_WASTE_THRESHOLD = 0.5;

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function evaluate(array $input): array
    {
        $effortSpent = (float) ($input['effort_spent'] ?? 0.0);
        $qualityLift = (float) ($input['accepted_quality_lift'] ?? 0.0);
        $downstreamSuccess = (float) ($input['downstream_success_rate'] ?? 0.0);

        $totalLift = $qualityLift + $downstreamSuccess;
        $costWasteRatio = $effortSpent > 0.0 ? $effortSpent / max($totalLift, 0.01) : 0.0;

        $blocked = $costWasteRatio > self::COST_WASTE_THRESHOLD && $totalLift < $effortSpent;

        return [
            'schema' => self::SCHEMA,
            'blocked' => $blocked,
            'cost_waste_ratio' => round($costWasteRatio, 4),
            'threshold' => self::COST_WASTE_THRESHOLD,
            'effort_spent' => $effortSpent,
            'accepted_quality_lift' => $qualityLift,
            'downstream_success_rate' => $downstreamSuccess,
            'total_lift' => round($totalLift, 4),
            'reason' => $blocked ? 'high_cost_low_lift' : 'acceptable',
        ];
    }
}
