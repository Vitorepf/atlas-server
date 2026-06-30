<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure calibration ledger. Aggregates model-run outcomes by tier, scaffold
 * variant, critique depth, and task class, then produces routing recommendations
 * only when enough evidence exists.
 *
 * AC2: aggregates by model_tier, scaffold_variant, critique_depth, task_class,
 *      success rate, give_back rate, and value proof strength.
 *
 * AC3: recommendations are only emitted for segments with ≥ MIN_SAMPLES runs.
 *      Segments below that threshold are listed as under_sampled and inconclusive.
 *
 * AC4: output always includes tier_stats, routing_recommendations,
 *      under_sampled_segments, and evidence_thresholds.
 *
 * Pure: no I/O, no side effects.
 */
final class AtlasExternalBrainModelTierCalibrationLedger
{
    public const SCHEMA = 'atlas.external_brain.model_tier_calibration_ledger.v1';

    public const TIER_SMALL      = 'small_model';
    public const TIER_SCAFFOLDED = 'scaffolded_small_model';
    public const TIER_FRONTIER   = 'frontier_model';

    public const OUTCOME_SUCCESS   = 'success';
    public const OUTCOME_GIVE_BACK = 'give_back';
    public const OUTCOME_LOW_VALUE = 'low_value';

    public const MIN_SAMPLES_FOR_RECOMMENDATION = 5;

    private const RECOMMEND_SUCCESS_FLOOR   = 0.30;
    private const RECOMMEND_SUCCESS_CEILING = 0.80;
    private const RECOMMEND_GIVEBACK_DANGER = 0.50;

    /**
     * @param  array{runs?: list<array<string,mixed>>}  $input
     * @return array{schema:string, tier_stats:array<string,mixed>, routing_recommendations:list<array<string,mixed>>, under_sampled_segments:list<array<string,mixed>>, evidence_thresholds:array<string,mixed>}
     */
    public function calibrate(array $input): array
    {
        $runs = (array) ($input['runs'] ?? []);

        // Aggregate by segment key
        $segments = [];
        $tierRaw  = [];

        foreach ($runs as $run) {
            $tier      = (string) ($run['model_tier']        ?? 'unknown');
            $scaffold  = (string) ($run['scaffold_variant']  ?? 'default');
            $critique  = (string) ($run['critique_depth']    ?? 'none');
            $taskClass = (string) ($run['task_class']        ?? 'general');
            $outcome   = (string) ($run['outcome']           ?? '');
            $valueStr  = max(0.0, min(1.0, (float) ($run['value_proof_strength'] ?? 0.0)));

            $segKey = "{$tier}|{$scaffold}|{$critique}|{$taskClass}";

            if (! isset($segments[$segKey])) {
                $segments[$segKey] = [
                    'model_tier'        => $tier,
                    'scaffold_variant'  => $scaffold,
                    'critique_depth'    => $critique,
                    'task_class'        => $taskClass,
                    'success_count'     => 0,
                    'give_back_count'   => 0,
                    'low_value_count'   => 0,
                    'total'             => 0,
                    'value_strength_sum' => 0.0,
                ];
            }

            $segments[$segKey]['total']++;
            $segments[$segKey]['value_strength_sum'] += $valueStr;

            match ($outcome) {
                self::OUTCOME_SUCCESS   => $segments[$segKey]['success_count']++,
                self::OUTCOME_GIVE_BACK => $segments[$segKey]['give_back_count']++,
                self::OUTCOME_LOW_VALUE => $segments[$segKey]['low_value_count']++,
                default                 => null,
            };

            // Tier-level aggregation
            if (! isset($tierRaw[$tier])) {
                $tierRaw[$tier] = ['success' => 0, 'give_back' => 0, 'low_value' => 0, 'total' => 0, 'vs_sum' => 0.0];
            }
            $tierRaw[$tier]['total']++;
            $tierRaw[$tier]['vs_sum'] += $valueStr;
            match ($outcome) {
                self::OUTCOME_SUCCESS   => $tierRaw[$tier]['success']++,
                self::OUTCOME_GIVE_BACK => $tierRaw[$tier]['give_back']++,
                self::OUTCOME_LOW_VALUE => $tierRaw[$tier]['low_value']++,
                default                 => null,
            };
        }

        // Build tier_stats
        $tierStats = [];
        foreach ($tierRaw as $tier => $agg) {
            $total = $agg['total'];
            $tierStats[$tier] = [
                'sample_count'              => $total,
                'success_rate'              => $total > 0 ? round($agg['success'] / $total, 4) : 0.0,
                'give_back_rate'            => $total > 0 ? round($agg['give_back'] / $total, 4) : 0.0,
                'avg_value_proof_strength'  => $total > 0 ? round($agg['vs_sum'] / $total, 4) : 0.0,
            ];
        }

        // Build routing_recommendations and under_sampled_segments
        $recommendations    = [];
        $underSampled       = [];

        foreach ($segments as $segKey => $seg) {
            $total = $seg['total'];
            $label = "{$seg['model_tier']}+{$seg['scaffold_variant']}+{$seg['critique_depth']}+{$seg['task_class']}";

            if ($total < self::MIN_SAMPLES_FOR_RECOMMENDATION) {
                $underSampled[] = [
                    'segment'      => $label,
                    'model_tier'   => $seg['model_tier'],
                    'task_class'   => $seg['task_class'],
                    'sample_count' => $total,
                    'verdict'      => 'inconclusive',
                ];
                continue;
            }

            $successRate  = $seg['success_count'] / $total;
            $giveBackRate = $seg['give_back_count'] / $total;
            $avgVs        = $seg['value_strength_sum'] / $total;

            $recommendation = $this->recommendation($seg['model_tier'], $successRate, $giveBackRate, $avgVs);

            if ($recommendation !== null) {
                $recommendations[] = [
                    'segment'       => $label,
                    'model_tier'    => $seg['model_tier'],
                    'task_class'    => $seg['task_class'],
                    'success_rate'  => round($successRate, 4),
                    'give_back_rate' => round($giveBackRate, 4),
                    'action'        => $recommendation,
                    'evidence'      => "sample_count={$total}",
                ];
            }
        }

        return [
            'schema'                   => self::SCHEMA,
            'tier_stats'               => $tierStats,
            'routing_recommendations'  => $recommendations,
            'under_sampled_segments'   => $underSampled,
            'evidence_thresholds'      => [
                'min_samples_for_recommendation' => self::MIN_SAMPLES_FOR_RECOMMENDATION,
                'success_rate_floor'             => self::RECOMMEND_SUCCESS_FLOOR,
                'success_rate_ceiling'           => self::RECOMMEND_SUCCESS_CEILING,
                'give_back_danger_threshold'     => self::RECOMMEND_GIVEBACK_DANGER,
            ],
        ];
    }

    private function recommendation(string $tier, float $successRate, float $giveBackRate, float $avgVs): ?string
    {
        if ($giveBackRate > self::RECOMMEND_GIVEBACK_DANGER) {
            return "retire_{$tier}_for_this_task_class:give_back_rate_too_high";
        }

        if ($successRate < self::RECOMMEND_SUCCESS_FLOOR) {
            return "downgrade_or_augment_{$tier}:success_rate_below_floor";
        }

        if ($successRate >= self::RECOMMEND_SUCCESS_CEILING) {
            return "promote_{$tier}:high_success_rate";
        }

        return null; // adequate — no change needed
    }
}
