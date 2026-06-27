<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Brain;

/**
 * HORIZON DIVERSITY FORECAST — simulation-twin organ. Given current per-path counts and K
 * additional cycles, runs the greedy-rotation simulator and computes the HHI of the projected
 * distribution. Lets operators answer "if I let the brain rotate K more times under the current
 * policy, what does the concentration look like?". Pure composition over GreedyRotationProjector
 * outputs + Herfindahl math.
 *
 * No IO. Pétreo: réu would clamp the forecast HHI to look healthier than projection implies.
 */
final class AtlasBrainHorizonDiversityForecast
{
    public const SCHEMA = 'atlas.brain.horizon_diversity_forecast.v1';

    public function __construct(private readonly AtlasBrainGreedyRotationProjector $projector) {}

    /**
     * @param  array<string, int>  $currentCounts
     * @return array{schema:string, projected_hhi:float, projected_counts:array<string,int>, k:int}
     */
    public function forecast(array $currentCounts, int $k): array
    {
        $projection = $this->projector->project($currentCounts, $k);
        $counts = $projection['projected_counts'];
        $total = array_sum($counts);
        if ($total <= 0) {
            return ['schema' => self::SCHEMA, 'projected_hhi' => 0.0, 'projected_counts' => $counts, 'k' => $k];
        }
        $hhi = 0.0;
        foreach ($counts as $c) {
            $share = $c / $total;
            $hhi += $share * $share;
        }

        return ['schema' => self::SCHEMA, 'projected_hhi' => round($hhi, 4), 'projected_counts' => $counts, 'k' => $k];
    }
}
