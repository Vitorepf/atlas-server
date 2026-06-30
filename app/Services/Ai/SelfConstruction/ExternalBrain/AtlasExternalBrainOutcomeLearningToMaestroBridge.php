<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Translates the muscle outcome learning matrix into Maestro routing recommendations.
 * Pure bridge — emits proposals only, never writes to DB, queue, provider or filesystem.
 *
 * INPUT (per row):
 *   {
 *     task_family:     string
 *     worker_tier:     string   (small_model | scaffolded_small_model | frontier_model)
 *     success_rate:    float    0.0–1.0
 *     give_back_rate:  float    0.0–1.0
 *     quarantine_rate: float    0.0–1.0
 *     sample_count:    int      rows with count < MIN_SAMPLES are skipped
 *   }
 *
 * RULES (all applied per row; first matching rule wins within each output category):
 *
 *   POISON BLOCK:
 *     give_back_rate >= POISON_GIVE_BACK OR quarantine_rate >= POISON_QUARANTINE
 *     → poison_family_blocks entry + supply adjustment block + replenisher reduce_supply
 *
 *   SUPPLY DECREASE (non-poison):
 *     give_back_rate >= GIVE_BACK_CEILING (but below poison)
 *     → task_family_supply_adjustments decrease + replenisher reduce_supply
 *
 *   SUPPLY INCREASE:
 *     success_rate >= SUCCESS_FLOOR
 *     → task_family_supply_adjustments increase + replenisher boost_supply
 *
 *   WORKER AFFINITY (independent; emitted regardless of supply direction):
 *     success_rate >= SUCCESS_FLOOR → recommend current worker_tier (it is working well)
 *     give_back_rate >= GIVE_BACK_CEILING → recommend cheaper or scaffolded tier if not already
 *
 * DEFAULT THRESHOLDS:
 *   MIN_SAMPLES           = 3
 *   SUCCESS_FLOOR         = 0.80
 *   GIVE_BACK_CEILING     = 0.40
 *   POISON_GIVE_BACK      = 0.70
 *   POISON_QUARANTINE     = 0.50
 *
 * OUTPUT:
 *   {
 *     schema,
 *     worker_affinity_updates,
 *     task_family_supply_adjustments,
 *     poison_family_blocks,
 *     replenisher_feedback
 *   }
 *
 * PURE / DETERMINISTIC / NO I/O.
 */
final class AtlasExternalBrainOutcomeLearningToMaestroBridge
{
    public const SCHEMA = 'atlas.external_brain.outcome_learning_to_maestro_bridge.v1';

    private const MIN_SAMPLES       = 3;
    private const SUCCESS_FLOOR     = 0.80;
    private const GIVE_BACK_CEILING = 0.40;
    private const POISON_GIVE_BACK  = 0.70;
    private const POISON_QUARANTINE = 0.50;

    private const TIER_ORDER = [
        'small_model'            => 0,
        'scaffolded_small_model' => 1,
        'frontier_model'         => 2,
    ];

    /**
     * @param  list<array<string,mixed>>  $rows
     * @param  array<string,mixed>        $options  { thresholds? }
     * @return array<string,mixed>
     */
    public function bridge(array $rows, array $options = []): array
    {
        $thresholds      = is_array($options['thresholds'] ?? null) ? $options['thresholds'] : [];
        $minSamples      = (int)   ($thresholds['min_samples']       ?? self::MIN_SAMPLES);
        $successFloor    = (float) ($thresholds['success_floor']     ?? self::SUCCESS_FLOOR);
        $giveBackCeiling = (float) ($thresholds['give_back_ceiling'] ?? self::GIVE_BACK_CEILING);
        $poisonGiveBack  = (float) ($thresholds['poison_give_back']  ?? self::POISON_GIVE_BACK);
        $poisonQuarantine = (float) ($thresholds['poison_quarantine'] ?? self::POISON_QUARANTINE);

        $affinityUpdates     = [];
        $supplyAdjustments   = [];
        $poisonBlocks        = [];
        $replenisherFeedback = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $family        = (string) ($row['task_family'] ?? '');
            $tier          = (string) ($row['worker_tier'] ?? 'scaffolded_small_model');
            $successRate   = (float) ($row['success_rate'] ?? 0.0);
            $giveBackRate  = (float) ($row['give_back_rate'] ?? 0.0);
            $quarantineRate = (float) ($row['quarantine_rate'] ?? 0.0);
            $sampleCount   = (int) ($row['sample_count'] ?? 0);

            if ($sampleCount < $minSamples || $family === '') {
                continue;
            }

            $isPoison = $giveBackRate >= $poisonGiveBack || $quarantineRate >= $poisonQuarantine;

            // Poison block.
            if ($isPoison) {
                $reason = $giveBackRate >= $poisonGiveBack
                    ? sprintf('give_back_rate_%.2f_exceeds_poison_threshold_%.2f', $giveBackRate, $poisonGiveBack)
                    : sprintf('quarantine_rate_%.2f_exceeds_poison_threshold_%.2f', $quarantineRate, $poisonQuarantine);

                $poisonBlocks[]      = ['task_family' => $family, 'reason' => $reason];
                $supplyAdjustments[] = [
                    'task_family' => $family,
                    'adjustment'  => 'block',
                    'magnitude'   => 1.0,
                    'reason'      => $reason,
                ];
                $replenisherFeedback[] = [
                    'task_family' => $family,
                    'action'      => 'reduce_supply',
                    'reason'      => 'poison_block:'.$reason,
                ];
                // Affinity: downgrade tier for poison.
                $cheaper = $this->cheaperTier($tier);
                $affinityUpdates[] = [
                    'task_family'      => $family,
                    'recommended_tier' => $cheaper,
                    'reason'           => 'poison_family_downgrade_to_cheaper_tier',
                ];
                continue;
            }

            // Non-poison supply decrease.
            $supplySentinel = 'hold';
            if ($giveBackRate >= $giveBackCeiling) {
                $magnitude = round(min(1.0, $giveBackRate * 2), 4);
                $supplyAdjustments[] = [
                    'task_family' => $family,
                    'adjustment'  => 'decrease',
                    'magnitude'   => $magnitude,
                    'reason'      => sprintf('give_back_rate_%.2f_above_ceiling_%.2f', $giveBackRate, $giveBackCeiling),
                ];
                $supplySentinel = 'reduce_supply';
            }

            // Supply increase.
            if ($successRate >= $successFloor) {
                $magnitude = round(min(1.0, $successRate), 4);
                $supplyAdjustments[] = [
                    'task_family' => $family,
                    'adjustment'  => 'increase',
                    'magnitude'   => $magnitude,
                    'reason'      => sprintf('success_rate_%.2f_above_floor_%.2f', $successRate, $successFloor),
                ];
                $supplySentinel = 'boost_supply';
            }

            $replenisherFeedback[] = [
                'task_family' => $family,
                'action'      => $supplySentinel,
                'reason'      => $supplySentinel === 'hold'
                    ? 'no_dominant_supply_signal'
                    : $supplySentinel.'_based_on_outcome_rates',
            ];

            // Worker affinity.
            if ($successRate >= $successFloor) {
                $affinityUpdates[] = [
                    'task_family'      => $family,
                    'recommended_tier' => $tier,
                    'reason'           => sprintf('success_rate_%.2f_confirms_tier_%s', $successRate, $tier),
                ];
            } elseif ($giveBackRate >= $giveBackCeiling) {
                $cheaper = $this->cheaperTier($tier);
                $affinityUpdates[] = [
                    'task_family'      => $family,
                    'recommended_tier' => $cheaper,
                    'reason'           => sprintf('give_back_rate_%.2f_suggests_simpler_tier', $giveBackRate),
                ];
            }
        }

        return [
            'schema'                        => self::SCHEMA,
            'worker_affinity_updates'       => $affinityUpdates,
            'task_family_supply_adjustments' => $supplyAdjustments,
            'poison_family_blocks'          => $poisonBlocks,
            'replenisher_feedback'          => $replenisherFeedback,
        ];
    }

    private function cheaperTier(string $tier): string
    {
        $rank = self::TIER_ORDER[$tier] ?? 1;
        $cheaper = max(0, $rank - 1);
        $flip = array_flip(self::TIER_ORDER);

        return $flip[$cheaper] ?? 'small_model';
    }
}
