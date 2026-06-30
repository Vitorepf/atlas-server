<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure explainer: converts a health_score breakdown into ranked, actionable gap
 * recommendations so the brain can target exact task families and prove improvement
 * with falsification evidence rather than treating a score as a vague dashboard number.
 *
 * Detected gaps:
 *   gate_holes > 0            → gate_holes gap, lift = gate_holes × LIFT_PER_GATE
 *   entropy == 0.0            → zero_entropy gap, lift = ZERO_ENTROPY_LIFT
 *   trend_data_points < 3     → insufficient_trend_data gap, lift = TREND_MIN_LIFT
 *
 * Recommendations are sorted descending by expected_score_lift.
 * Falsification evidence is required per recommendation so the brain cannot claim
 * improvement without a concrete measurable signal.
 */
final class AtlasExternalBrainHealthScoreDeltaExplainer
{
    public const SCHEMA = 'atlas.external_brain.health_score_delta_explainer.v1';

    public const LIFT_PER_GATE = 5.0;

    public const ZERO_ENTROPY_LIFT = 15.0;

    public const TREND_MIN_LIFT = 10.0;

    public const TREND_MIN_DATA_POINTS = 3;

    /**
     * @param  array<string,mixed>  $breakdown  gate_holes, entropy, trend_data_points
     * @return array{schema_version:string, ranked_recommendations:list<array<string,mixed>>, total_expected_lift:float}
     */
    public function explain(array $breakdown): array
    {
        $gateHoles = max(0, (int) ($breakdown['gate_holes'] ?? 0));
        $entropy = (float) ($breakdown['entropy'] ?? 1.0);
        $trendDataPoints = (int) ($breakdown['trend_data_points'] ?? self::TREND_MIN_DATA_POINTS);

        $recommendations = [];

        if ($gateHoles > 0) {
            $recommendations[] = [
                'gap' => 'gate_holes',
                'expected_score_lift' => round($gateHoles * self::LIFT_PER_GATE, 2),
                'task_families' => ['gate-certification', 'gate-impl', 'gate-wiring'],
                'falsification_evidence' => ['gate_pass_rate_increases', 'malformed_count_drops_to_zero'],
            ];
        }

        if ($entropy == 0.0) {
            $recommendations[] = [
                'gap' => 'zero_entropy',
                'expected_score_lift' => self::ZERO_ENTROPY_LIFT,
                'task_families' => ['discovery', 'exploration', 'origination'],
                'falsification_evidence' => ['discovery_variety_increases', 'new_capability_ids_appear'],
            ];
        }

        if ($trendDataPoints < self::TREND_MIN_DATA_POINTS) {
            $recommendations[] = [
                'gap' => 'insufficient_trend_data',
                'expected_score_lift' => self::TREND_MIN_LIFT,
                'task_families' => ['telemetry-wiring', 'trend-measurement'],
                'falsification_evidence' => ['score_variance_nonzero', 'trend_data_points_reaches_3'],
            ];
        }

        usort($recommendations, static fn (array $a, array $b): int => $b['expected_score_lift'] <=> $a['expected_score_lift']);

        return [
            'schema_version' => self::SCHEMA,
            'ranked_recommendations' => array_values($recommendations),
            'total_expected_lift' => round((float) array_sum(array_column($recommendations, 'expected_score_lift')), 2),
        ];
    }
}
