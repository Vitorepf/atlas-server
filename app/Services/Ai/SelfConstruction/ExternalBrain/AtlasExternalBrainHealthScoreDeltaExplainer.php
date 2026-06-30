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

    public const VOLUME_NOISE_WEIGHT = 0.01;

    private const NEXT_ACTION_BY_BUCKET = [
        'volume_noise' => 'reject_count_based_claim_require_runnable_proof_per_task',
        'risk_reduction' => 'consolidate_and_document_the_risk_reduction_mechanism',
        'quality_gain' => 'extend_the_quality_pattern_that_drove_this_gain_to_adjacent_surfaces',
        'autonomy_gain' => 'verify_the_autonomy_gain_with_a_zero_human_steady_state_check',
        'simplification_gain' => 'lock_in_the_simplification_with_a_regression_test',
    ];

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

    /**
     * Decomposes a before/after health snapshot into five named buckets so
     * health movement explains quality, autonomy, and queue-risk change
     * instead of rewarding raw task count or shallow green checks.
     *
     * BUCKETS (each clamped to >= 0):
     *   autonomy_gain       = max(0, after.autonomy_score - before.autonomy_score)
     *   quality_gain        = max(0, after.quality_score - before.quality_score)
     *   risk_reduction       = max(0, before.risk_score - after.risk_score)
     *   simplification_gain = max(0, after.simplification_score - before.simplification_score)
     *   volume_noise         = (raw task_count increase NOT backed by an equal
     *                          increase in proven_task_count) * VOLUME_NOISE_WEIGHT
     *                          i.e. tasks added without runnable proof never
     *                          register as health improvement, only as noise.
     *
     * next_action is derived from whichever bucket has the largest magnitude
     * (ties broken by the fixed priority order below, dominant bucket first):
     *   volume_noise > risk_reduction > quality_gain > autonomy_gain > simplification_gain
     *
     * @param  array<string,mixed>  $before
     * @param  array<string,mixed>  $after
     * @return array{schema_version:string, autonomy_gain:float, quality_gain:float,
     *               risk_reduction:float, simplification_gain:float, volume_noise:float,
     *               dominant_bucket:string, next_action:string}
     */
    public function explainDelta(array $before, array $after): array
    {
        $autonomyGain = max(0.0, (float) ($after['autonomy_score'] ?? 0.0) - (float) ($before['autonomy_score'] ?? 0.0));
        $qualityGain = max(0.0, (float) ($after['quality_score'] ?? 0.0) - (float) ($before['quality_score'] ?? 0.0));
        $riskReduction = max(0.0, (float) ($before['risk_score'] ?? 0.0) - (float) ($after['risk_score'] ?? 0.0));
        $simplificationGain = max(0.0, (float) ($after['simplification_score'] ?? 0.0) - (float) ($before['simplification_score'] ?? 0.0));

        $taskCountDelta = max(0, (int) ($after['task_count'] ?? 0) - (int) ($before['task_count'] ?? 0));
        $provenTaskCountDelta = max(0, (int) ($after['proven_task_count'] ?? 0) - (int) ($before['proven_task_count'] ?? 0));
        $unprovenTaskDelta = max(0, $taskCountDelta - $provenTaskCountDelta);
        $volumeNoise = $unprovenTaskDelta * self::VOLUME_NOISE_WEIGHT;

        $buckets = [
            'volume_noise' => $volumeNoise,
            'risk_reduction' => $riskReduction,
            'quality_gain' => $qualityGain,
            'autonomy_gain' => $autonomyGain,
            'simplification_gain' => $simplificationGain,
        ];

        $dominantBucket = 'autonomy_gain';
        $dominantValue = -1.0;
        foreach ($buckets as $bucket => $value) {
            if ($value > $dominantValue) {
                $dominantValue = $value;
                $dominantBucket = $bucket;
            }
        }

        return [
            'schema_version' => self::SCHEMA,
            'autonomy_gain' => round($autonomyGain, 4),
            'quality_gain' => round($qualityGain, 4),
            'risk_reduction' => round($riskReduction, 4),
            'simplification_gain' => round($simplificationGain, 4),
            'volume_noise' => round($volumeNoise, 4),
            'dominant_bucket' => $dominantBucket,
            'next_action' => self::NEXT_ACTION_BY_BUCKET[$dominantBucket],
        ];
    }
}
