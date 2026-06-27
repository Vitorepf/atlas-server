<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Brain;

/**
 * HEALTH SCORE — one scalar in [0..100] combining the perception suite into a top-line number.
 * Weights are explicit and transparent (no hidden coefficients):
 *
 *   gates          (40 pts): airtight ⇒ +40; any hole ⇒ 0  (gates are pétreo; airtight is a precondition).
 *   served_ratio   (20 pts): ratio * 0.20                   (50% ratio = 10 pts).
 *   starvation     (20 pts): (100 - starvation_pct) * 0.20  (0% starv = 20 pts).
 *   entropy        (10 pts): normalized * 10                (uniform = 10 pts).
 *   trend          (10 pts): recovering=+10, flat=+5, insufficient=+5, worsening=0.
 *
 * Why a single scalar on top of the rich suite: dashboards/cron love thresholds and trend lines; an
 * operator scanning many cohorts wants ONE number to sort by. The rich suite stays the source of truth;
 * this is a compressor with named weights so disagreement is auditable.
 *
 * Pure + deterministic + read-only. Pétreo: réu never edits the weights (else the score flatters itself).
 */
final class AtlasBrainHealthScore
{
    public const SCHEMA = 'atlas.brain.health_score.v1';

    /**
     * @return array{schema:string, score:int, breakdown:array{gates:int, ratio:int, starvation:int, entropy:int, trend:int}}
     */
    public function compute(bool $gatesAirtight, int $servedRatioPct, int $starvationPct, float $entropyNormalized, string $trendDirection): array
    {
        $gates = $gatesAirtight ? 40 : 0;
        $ratio = (int) round($servedRatioPct * 0.20);
        $starv = (int) round((100 - $starvationPct) * 0.20);
        $ent = (int) round($entropyNormalized * 10);
        $trend = match ($trendDirection) {
            'recovering' => 10,
            'flat', 'insufficient_data' => 5,
            'worsening' => 0,
            default => 5,
        };

        $score = max(0, min(100, $gates + $ratio + $starv + $ent + $trend));

        return [
            'schema' => self::SCHEMA,
            'score' => $score,
            'breakdown' => [
                'gates' => $gates,
                'ratio' => $ratio,
                'starvation' => $starv,
                'entropy' => $ent,
                'trend' => $trend,
            ],
        ];
    }
}
