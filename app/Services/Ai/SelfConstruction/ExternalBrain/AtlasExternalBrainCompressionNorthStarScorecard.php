<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\Support\AiValueNormalizer;

/**
 * Pure strategic scorecard: compresses fitness gain, autonomy gain, deletion gain, proof
 * health and regression rate into ONE north_star_score plus a thresholded strategic
 * recommendation — so the engine optimizes structural improvement, not task count or line
 * count alone. Never a decorative dashboard summary.
 *
 * north_star_score (0.0-1.0, rounded 4dp):
 *   fitness_gain    × 0.30   (clamped 0..1 — architectural fitness delta this batch)
 *   + autonomy_gain × 0.25   (clamped 0..1 — reduced human/operator touch this batch)
 *   + deletion_gain × 0.20   (clamped 0..1 — net code/complexity removed this batch)
 *   + proof_health  × 0.15   (clamped 0..1 — fraction of changes carrying real proof)
 *   + (1 - regression_rate) × 0.10   (regression_rate clamped 0..1)
 *
 * RECOMMENDATION (score bands, then blockers/regression override downward — never upward):
 *   score >= 0.75 -> accelerate
 *   score >= 0.50 -> steady
 *   score >= 0.25 -> repair
 *   else          -> stop
 *
 *   blockers non-empty            -> recommendation capped at 'repair' (never accelerate/steady)
 *   regression_rate >= HARD_STOP  -> forced 'stop' regardless of score or blockers (safety floor)
 *
 * Pure. No I/O, no provider calls, deterministic.
 */
final class AtlasExternalBrainCompressionNorthStarScorecard
{
    public const SCHEMA = 'atlas.external_brain.compression_north_star_scorecard.v1';

    public const RECOMMENDATION_ACCELERATE = 'accelerate';

    public const RECOMMENDATION_STEADY = 'steady';

    public const RECOMMENDATION_REPAIR = 'repair';

    public const RECOMMENDATION_STOP = 'stop';

    private const BAND_ACCELERATE = 0.75;

    private const BAND_STEADY = 0.50;

    private const BAND_REPAIR = 0.25;

    private const REGRESSION_HARD_STOP = 0.50;

    /** Recommendation severity order, lowest index = least severe (used to cap downward only). */
    private const SEVERITY_ORDER = [
        self::RECOMMENDATION_ACCELERATE,
        self::RECOMMENDATION_STEADY,
        self::RECOMMENDATION_REPAIR,
        self::RECOMMENDATION_STOP,
    ];

    /**
     * @param  array{
     *   fitness_gain?:float, autonomy_gain?:float, deletion_gain?:float,
     *   proof_health?:float, regression_rate?:float, blockers?:list<string>,
     * }  $input
     * @return array<string,mixed>
     */
    public function score(array $input): array
    {
        $fitnessGain = $this->clamp01((float) ($input['fitness_gain'] ?? 0.0));
        $autonomyGain = $this->clamp01((float) ($input['autonomy_gain'] ?? 0.0));
        $deletionGain = $this->clamp01((float) ($input['deletion_gain'] ?? 0.0));
        $proofHealth = $this->clamp01((float) ($input['proof_health'] ?? 0.0));
        $regressionRate = $this->clamp01((float) ($input['regression_rate'] ?? 0.0));
        $blockers = array_values(array_map('strval', (array) ($input['blockers'] ?? [])));

        $northStarScore = round(
            $fitnessGain * 0.30
            + $autonomyGain * 0.25
            + $deletionGain * 0.20
            + $proofHealth * 0.15
            + (1.0 - $regressionRate) * 0.10,
            4,
        );

        $bandRecommendation = match (true) {
            $northStarScore >= self::BAND_ACCELERATE => self::RECOMMENDATION_ACCELERATE,
            $northStarScore >= self::BAND_STEADY => self::RECOMMENDATION_STEADY,
            $northStarScore >= self::BAND_REPAIR => self::RECOMMENDATION_REPAIR,
            default => self::RECOMMENDATION_STOP,
        };

        $recommendation = $bandRecommendation;
        $overrides = [];
        if ($blockers !== [] && $this->severity($recommendation) < $this->severity(self::RECOMMENDATION_REPAIR)) {
            $recommendation = self::RECOMMENDATION_REPAIR;
            $overrides[] = 'blockers_cap_at_repair';
        }
        if ($regressionRate >= self::REGRESSION_HARD_STOP) {
            $recommendation = self::RECOMMENDATION_STOP;
            $overrides[] = 'regression_rate_hard_stop';
        }

        return [
            'schema' => self::SCHEMA,
            'north_star_score' => $northStarScore,
            'band_recommendation' => $bandRecommendation,
            'recommendation' => $recommendation,
            'overrides' => $overrides,
            'blockers' => $blockers,
            'inputs' => [
                'fitness_gain' => $fitnessGain,
                'autonomy_gain' => $autonomyGain,
                'deletion_gain' => $deletionGain,
                'proof_health' => $proofHealth,
                'regression_rate' => $regressionRate,
            ],
        ];
    }

    private function clamp01(float $value): float
    {
        return AiValueNormalizer::clampUnit($value);
    }

    private function severity(string $recommendation): int
    {
        $index = array_search($recommendation, self::SEVERITY_ORDER, true);

        return $index === false ? count(self::SEVERITY_ORDER) - 1 : $index;
    }
}
