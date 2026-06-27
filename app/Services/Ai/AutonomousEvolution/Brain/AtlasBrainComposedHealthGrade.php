<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Brain;

/**
 * COMPOSED HEALTH GRADE — comprehension-deepening organ. Single A-F brain grade fusing three
 * already-computed inputs:
 *   diversity_score (0..1)              — entropy-of-paths
 *   calibration_hit_rate (0..1)         — projector hit-rate
 *   coverage_min_organs (int)           — min organs per portfolio path
 *
 * Weighted 40/40/20. Output letter A (>=85), B (>=70), C (>=55), D (>=40), F otherwise.
 *
 * Pure decision. No IO. Pétreo: réu would re-weight to inflate the grade.
 */
final class AtlasBrainComposedHealthGrade
{
    public const SCHEMA = 'atlas.brain.composed_health_grade.v1';

    /**
     * @return array{schema:string, score:int, letter:string, components:array{diversity:int, calibration:int, coverage:int}}
     */
    public function grade(float $diversityScore, float $calibrationHitRate, int $coverageMinOrgans): array
    {
        $diversity = (int) round(max(0.0, min(1.0, $diversityScore)) * 100);
        $calibration = (int) round(max(0.0, min(1.0, $calibrationHitRate)) * 100);
        // coverage: 0 organs = 0, 6+ organs = 100, linear in between
        $coverage = (int) round(max(0, min(6, $coverageMinOrgans)) * (100 / 6));
        $score = (int) round(0.4 * $diversity + 0.4 * $calibration + 0.2 * $coverage);
        $letter = match (true) {
            $score >= 85 => 'A',
            $score >= 70 => 'B',
            $score >= 55 => 'C',
            $score >= 40 => 'D',
            default => 'F',
        };

        return [
            'schema' => self::SCHEMA,
            'score' => $score,
            'letter' => $letter,
            'components' => ['diversity' => $diversity, 'calibration' => $calibration, 'coverage' => $coverage],
        ];
    }
}
