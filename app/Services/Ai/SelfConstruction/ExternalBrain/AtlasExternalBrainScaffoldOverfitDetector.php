<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure detector: identifies scaffold variants that are over-optimized for
 * passing the task gate or benchmark while losing real quality signals.
 *
 * Overfit criteria (a variant is "suspect" when any of the following hold):
 *   A. High gate pass rate (≥ HIGH_GATE_THRESHOLD) combined with at least one
 *      falling real-quality metric (commit_success, value_proof, diversity,
 *      compounding_impact each below their respective thresholds).
 *   B. heldout_gap = benchmark_pass_rate − heldout_pass_rate > HELDOUT_GAP_THRESHOLD
 *      (in-distribution benchmark diverges from held-out challenge set).
 *
 * evidence_trend: per-variant trend classification (stable / borderline / declining).
 * recommended_action: 'reset_scaffold' ≥ 2 suspects; 'increase_heldout_set' high gap;
 *   'monitor' for 1 suspect; 'none' when clean.
 */
final class AtlasExternalBrainScaffoldOverfitDetector
{
    public const SCHEMA = 'atlas.external_brain.scaffold_overfit_detector.v1';

    public const HIGH_GATE_THRESHOLD = 0.80;

    public const REAL_QUALITY_THRESHOLD = 0.70;

    public const DIVERSITY_THRESHOLD = 0.50;

    public const COMPOUNDING_THRESHOLD = 0.50;

    public const HELDOUT_GAP_THRESHOLD = 0.25;

    /**
     * @param  array<string,mixed>  $input  scaffold_metrics list
     * @return array<string,mixed>
     */
    public function detect(array $input): array
    {
        $metrics = is_array($input['scaffold_metrics'] ?? null) ? $input['scaffold_metrics'] : [];

        $suspectScaffolds = [];
        $evidenceTrend = [];
        $anyHeldoutOverfit = false;

        foreach ($metrics as $m) {
            $id = (string) ($m['variant_id'] ?? 'unknown');
            $gateRate = (float) ($m['gate_pass_rate'] ?? 0.0);
            $commitRate = (float) ($m['commit_success_rate'] ?? 0.0);
            $valueRate = (float) ($m['value_proof_rate'] ?? 0.0);
            $diversity = (float) ($m['diversity_score'] ?? 0.0);
            $compounding = (float) ($m['compounding_impact'] ?? 0.0);
            $benchmarkRate = (float) ($m['benchmark_pass_rate'] ?? 0.0);
            $heldoutRate = (float) ($m['heldout_pass_rate'] ?? 0.0);

            $heldoutGap = round($benchmarkRate - $heldoutRate, 4);

            $decliningMetrics = [];
            if ($commitRate < self::REAL_QUALITY_THRESHOLD) {
                $decliningMetrics[] = 'commit_success_rate';
            }
            if ($valueRate < self::REAL_QUALITY_THRESHOLD) {
                $decliningMetrics[] = 'value_proof_rate';
            }
            if ($diversity < self::DIVERSITY_THRESHOLD) {
                $decliningMetrics[] = 'diversity_score';
            }
            if ($compounding < self::COMPOUNDING_THRESHOLD) {
                $decliningMetrics[] = 'compounding_impact';
            }

            $trend = count($decliningMetrics) >= 2 ? 'declining' : (count($decliningMetrics) === 1 ? 'borderline' : 'stable');
            $evidenceTrend[$id] = ['trend' => $trend, 'declining_metrics' => $decliningMetrics];

            $gateHighWithRealDecline = $gateRate >= self::HIGH_GATE_THRESHOLD && count($decliningMetrics) > 0;
            $heldoutOverfit = $heldoutGap > self::HELDOUT_GAP_THRESHOLD;

            if ($gateHighWithRealDecline || $heldoutOverfit) {
                $suspectScaffolds[] = [
                    'variant_id' => $id,
                    'gate_pass_rate' => $gateRate,
                    'heldout_gap' => $heldoutGap,
                    'declining_metrics' => $decliningMetrics,
                    'reasons' => array_filter([
                        $gateHighWithRealDecline ? 'high_gate_low_real_quality' : null,
                        $heldoutOverfit ? 'heldout_gap_exceeds_threshold' : null,
                    ]),
                ];
                if ($heldoutOverfit) {
                    $anyHeldoutOverfit = true;
                }
            }
        }

        $suspectCount = count($suspectScaffolds);
        if ($suspectCount >= 2) {
            $recommendedAction = 'reset_scaffold';
        } elseif ($anyHeldoutOverfit) {
            $recommendedAction = 'increase_heldout_set';
        } elseif ($suspectCount === 1) {
            $recommendedAction = 'monitor';
        } else {
            $recommendedAction = 'none';
        }

        return [
            'schema_version' => self::SCHEMA,
            'overfit_detected' => $suspectCount > 0,
            'suspect_scaffolds' => $suspectScaffolds,
            'evidence_trend' => $evidenceTrend,
            'heldout_gap' => $anyHeldoutOverfit,
            'recommended_action' => $recommendedAction,
        ];
    }
}
