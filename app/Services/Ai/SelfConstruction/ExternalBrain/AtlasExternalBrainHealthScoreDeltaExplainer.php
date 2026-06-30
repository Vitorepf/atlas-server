<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure explainer: converts a health_score breakdown into ranked, actionable gap
 * recommendations so the brain can target exact task families and prove improvement
 * with falsification evidence rather than treating a score as a vague dashboard number.
 *
 * Detected gaps (first 3 existing, 5 new):
 *   gate_holes            — gate_holes > 0
 *   zero_entropy          — entropy == 0.0
 *   insufficient_trend_data — trend_data_points < TREND_MIN_DATA_POINTS
 *   stale_evidence        — evidence_age_days > STALE_EVIDENCE_THRESHOLD_DAYS
 *   high_give_back_rate   — give_back_rate > HIGH_GIVE_BACK_THRESHOLD
 *   poison_rate           — poison_rate > POISON_RATE_THRESHOLD
 *   queue_collision_risk  — queue_collision_risk > QUEUE_COLLISION_THRESHOLD
 *   low_compounding_rate  — compounding_rate < LOW_COMPOUNDING_THRESHOLD
 *
 * Recommendations are sorted descending by expected_score_lift; ties broken
 * alphabetically by gap name. Each entry includes owner_subsystem, risk_level,
 * and stop_condition so the brain knows who owns the work and when to stop.
 */
final class AtlasExternalBrainHealthScoreDeltaExplainer
{
    public const SCHEMA = 'atlas.external_brain.health_score_delta_explainer.v1';

    public const LIFT_PER_GATE               = 5.0;
    public const ZERO_ENTROPY_LIFT           = 15.0;
    public const TREND_MIN_LIFT              = 10.0;
    public const STALE_EVIDENCE_LIFT         = 12.0;
    public const HIGH_GIVE_BACK_LIFT         =  8.0;
    public const POISON_RATE_LIFT            = 20.0;
    public const QUEUE_COLLISION_LIFT        =  7.0;
    public const LOW_COMPOUNDING_LIFT        = 18.0;

    public const TREND_MIN_DATA_POINTS       =  3;
    public const STALE_EVIDENCE_THRESHOLD_DAYS = 7;
    public const HIGH_GIVE_BACK_THRESHOLD    =  0.25;
    public const POISON_RATE_THRESHOLD       =  0.10;
    public const QUEUE_COLLISION_THRESHOLD   =  0.15;
    public const LOW_COMPOUNDING_THRESHOLD   =  0.30;

    /**
     * @param  array<string,mixed>  $breakdown
     * @return array{schema_version:string, ranked_recommendations:list<array<string,mixed>>, total_expected_lift:float}
     */
    public function explain(array $breakdown): array
    {
        $gateHoles        = max(0, (int)   ($breakdown['gate_holes']          ?? 0));
        $entropy          =        (float)  ($breakdown['entropy']             ?? 1.0);
        $trendPts         =        (int)    ($breakdown['trend_data_points']   ?? self::TREND_MIN_DATA_POINTS);
        $evidenceAgeDays  = max(0, (int)   ($breakdown['evidence_age_days']   ?? 0));
        $giveBackRate     = max(0.0, (float)($breakdown['give_back_rate']      ?? 0.0));
        $poisonRate       = max(0.0, (float)($breakdown['poison_rate']         ?? 0.0));
        $queueCollision   = max(0.0, (float)($breakdown['queue_collision_risk'] ?? 0.0));
        $compoundingRate  = max(0.0, (float)($breakdown['compounding_rate']    ?? 1.0));

        $recs = [];

        if ($gateHoles > 0) {
            $recs[] = [
                'gap'                  => 'gate_holes',
                'expected_score_lift'  => round($gateHoles * self::LIFT_PER_GATE, 2),
                'task_families'        => ['gate-certification', 'gate-impl', 'gate-wiring'],
                'owner_subsystem'      => 'gate_layer',
                'risk_level'           => 'medium',
                'falsification_evidence' => ['gate_pass_rate_increases', 'malformed_count_drops_to_zero'],
                'stop_condition'       => 'gate_pass_rate >= 0.95',
            ];
        }

        if ($entropy == 0.0) {
            $recs[] = [
                'gap'                  => 'zero_entropy',
                'expected_score_lift'  => self::ZERO_ENTROPY_LIFT,
                'task_families'        => ['discovery', 'exploration', 'origination'],
                'owner_subsystem'      => 'discovery_layer',
                'risk_level'           => 'low',
                'falsification_evidence' => ['discovery_variety_increases', 'new_capability_ids_appear'],
                'stop_condition'       => 'entropy > 0.10',
            ];
        }

        if ($trendPts < self::TREND_MIN_DATA_POINTS) {
            $recs[] = [
                'gap'                  => 'insufficient_trend_data',
                'expected_score_lift'  => self::TREND_MIN_LIFT,
                'task_families'        => ['telemetry-wiring', 'trend-measurement'],
                'owner_subsystem'      => 'telemetry_layer',
                'risk_level'           => 'low',
                'falsification_evidence' => ['score_variance_nonzero', 'trend_data_points_reaches_3'],
                'stop_condition'       => 'trend_data_points >= 3',
            ];
        }

        if ($evidenceAgeDays > self::STALE_EVIDENCE_THRESHOLD_DAYS) {
            $recs[] = [
                'gap'                  => 'stale_evidence',
                'expected_score_lift'  => self::STALE_EVIDENCE_LIFT,
                'task_families'        => ['evidence-refresh', 'evidence-collection'],
                'owner_subsystem'      => 'evidence_layer',
                'risk_level'           => 'medium',
                'falsification_evidence' => ['evidence_age_drops_below_7_days', 'fresh_run_result_recorded'],
                'stop_condition'       => 'evidence_age_days <= 7',
            ];
        }

        if ($giveBackRate > self::HIGH_GIVE_BACK_THRESHOLD) {
            $recs[] = [
                'gap'                  => 'high_give_back_rate',
                'expected_score_lift'  => self::HIGH_GIVE_BACK_LIFT,
                'task_families'        => ['task-routing', 'scope-repair', 'packet-hardening'],
                'owner_subsystem'      => 'task_routing_layer',
                'risk_level'           => 'high',
                'falsification_evidence' => ['give_back_rate_drops_below_0.10', 'queue_throughput_increases'],
                'stop_condition'       => 'give_back_rate < 0.10',
            ];
        }

        if ($poisonRate > self::POISON_RATE_THRESHOLD) {
            $recs[] = [
                'gap'                  => 'poison_rate',
                'expected_score_lift'  => self::POISON_RATE_LIFT,
                'task_families'        => ['quality-gate', 'poison-detection', 'certification-hardening'],
                'owner_subsystem'      => 'quality_gate_layer',
                'risk_level'           => 'high',
                'falsification_evidence' => ['poison_rate_drops_below_0.05', 'cert_rejection_count_drops'],
                'stop_condition'       => 'poison_rate < 0.05',
            ];
        }

        if ($queueCollision > self::QUEUE_COLLISION_THRESHOLD) {
            $recs[] = [
                'gap'                  => 'queue_collision_risk',
                'expected_score_lift'  => self::QUEUE_COLLISION_LIFT,
                'task_families'        => ['queue-governance', 'dedup-wiring', 'collision-detection'],
                'owner_subsystem'      => 'queue_governance_layer',
                'risk_level'           => 'medium',
                'falsification_evidence' => ['queue_collision_rate_drops_below_0.05', 'dedup_hit_rate_increases'],
                'stop_condition'       => 'queue_collision_rate < 0.05',
            ];
        }

        if ($compoundingRate < self::LOW_COMPOUNDING_THRESHOLD) {
            $recs[] = [
                'gap'                  => 'low_compounding_rate',
                'expected_score_lift'  => self::LOW_COMPOUNDING_LIFT,
                'task_families'        => ['compounding-wiring', 'impact-measurement', 'flywheel-activation'],
                'owner_subsystem'      => 'compounding_layer',
                'risk_level'           => 'low',
                'falsification_evidence' => ['compounding_rate_reaches_0.30', 'impact_ledger_entries_increase'],
                'stop_condition'       => 'compounding_rate >= 0.30',
            ];
        }

        usort($recs, static fn (array $a, array $b): int =>
            $b['expected_score_lift'] <=> $a['expected_score_lift']
            ?: strcmp($a['gap'], $b['gap'])
        );

        return [
            'schema_version'         => self::SCHEMA,
            'ranked_recommendations' => array_values($recs),
            'total_expected_lift'    => round((float) array_sum(array_column($recs, 'expected_score_lift')), 2),
        ];
    }
}
