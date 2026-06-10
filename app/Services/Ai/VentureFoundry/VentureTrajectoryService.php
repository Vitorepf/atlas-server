<?php

namespace App\Services\Ai\VentureFoundry;

use App\Models\AiVenture;

/**
 * Deterministic growth-trajectory projection toward the venture's target ARR.
 *
 * No synthetic revenue claims: while no ARR observation exists the projection
 * is BLOCKED and says so. With an observed ARR it answers two questions the
 * strategist needs:
 *  - "se crescermos a X% ao ano, em quantos anos chegamos ao alvo?" (cenários)
 *  - "para chegar em H anos, qual CAGR é exigido?" (horizontes)
 */
class VentureTrajectoryService
{
    public const STATUS_BLOCKED = 'blocked_no_observed_arr';

    public const STATUS_PROJECTED = 'projected';

    public const STATUS_TARGET_REACHED = 'target_reached';

    /**
     * @param  array<string,array{value: float, observed_at: string}>  $latestMetrics
     * @return array<string,mixed>
     */
    public function project(AiVenture $venture, array $latestMetrics): array
    {
        $target = (float) $venture->target_arr_usd;
        $arrObservation = $latestMetrics[VentureGrowthLadderService::METRIC_ARR_USD] ?? null;
        $arr = $arrObservation !== null ? (float) $arrObservation['value'] : null;

        $base = [
            'schema_version' => 'atlas.ai.venture.trajectory.v1',
            'target_arr_usd' => $target,
            'observed_arr_usd' => $arr,
            'observed_at' => $arrObservation['observed_at'] ?? null,
        ];

        if ($arr === null || $arr <= 0.0) {
            return $base + [
                'status' => self::STATUS_BLOCKED,
                'detail' => 'Sem ARR observado; projeção de trajetória exige receita real registrada via metric-record.',
                'scenarios' => [],
                'required_growth' => [],
            ];
        }

        if ($arr >= $target) {
            return $base + [
                'status' => self::STATUS_TARGET_REACHED,
                'detail' => 'ARR observado já alcança o alvo declarado.',
                'multiple_to_target' => 1.0,
                'scenarios' => [],
                'required_growth' => [],
            ];
        }

        $multiple = $target / $arr;

        $scenarios = [];
        foreach ((array) config('atlas_venture_foundry.trajectory_scenarios', []) as $name => $rate) {
            $rate = (float) $rate;
            if ($rate <= 0.0) {
                continue;
            }
            $scenarios[] = [
                'scenario' => (string) $name,
                'annual_growth_rate' => $rate,
                'years_to_target' => round(log($multiple) / log(1.0 + $rate), 1),
            ];
        }

        $requiredGrowth = [];
        foreach ((array) config('atlas_venture_foundry.trajectory_horizons_years', [3, 5, 7, 10]) as $horizon) {
            $horizon = (int) $horizon;
            if ($horizon <= 0) {
                continue;
            }
            $requiredGrowth[] = [
                'horizon_years' => $horizon,
                'required_cagr' => round($multiple ** (1.0 / $horizon) - 1.0, 4),
            ];
        }

        return $base + [
            'status' => self::STATUS_PROJECTED,
            'detail' => null,
            'multiple_to_target' => round($multiple, 1),
            'scenarios' => $scenarios,
            'required_growth' => $requiredGrowth,
        ];
    }
}
