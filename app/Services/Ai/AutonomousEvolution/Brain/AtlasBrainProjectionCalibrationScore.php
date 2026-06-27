<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Brain;

/**
 * PROJECTION CALIBRATION SCORE — simulation-twin organ. Given a sample of past projector
 * predictions paired with the actual next-cycle path picks, computes the hit rate (correct /
 * total) so operators can tell whether the projector is well-calibrated or drifting toward
 * noise. Min sample 5; status: >=0.75 well_calibrated, >=0.50 acceptable, else miscalibrated.
 *
 * Pure decision over already-collected pairs. No IO. Pétreo: réu would inflate the hit rate
 * by relaxing the equality check.
 */
final class AtlasBrainProjectionCalibrationScore
{
    public const SCHEMA = 'atlas.brain.projection_calibration_score.v1';

    private const MIN_SAMPLE = 5;

    /**
     * @param  list<array{predicted_path:string, actual_path:string}>  $pairs
     * @return array{schema:string, total:int, hits:int, hit_rate:float, status:string}
     */
    public function score(array $pairs): array
    {
        $total = 0;
        $hits = 0;
        foreach ($pairs as $p) {
            $pred = (string) ($p['predicted_path'] ?? '');
            $actual = (string) ($p['actual_path'] ?? '');
            if ($pred === '' || $actual === '') {
                continue;
            }
            $total++;
            if ($pred === $actual) {
                $hits++;
            }
        }

        if ($total < self::MIN_SAMPLE) {
            return ['schema' => self::SCHEMA, 'total' => $total, 'hits' => $hits, 'hit_rate' => 0.0, 'status' => 'insufficient_sample'];
        }

        $rate = round($hits / $total, 4);
        $status = $rate >= 0.75 ? 'well_calibrated' : ($rate >= 0.50 ? 'acceptable' : 'miscalibrated');

        return ['schema' => self::SCHEMA, 'total' => $total, 'hits' => $hits, 'hit_rate' => $rate, 'status' => $status];
    }
}
