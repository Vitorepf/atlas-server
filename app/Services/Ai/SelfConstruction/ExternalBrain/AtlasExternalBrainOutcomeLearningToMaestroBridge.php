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
 *     has_value_proof: bool     true = real capability delta proven; false = weak-green candidate
 *   }
 *
 * RULES (all applied per row; priority ordering within each output category):
 *
 *   POISON BLOCK:
 *     give_back_rate >= POISON_GIVE_BACK OR quarantine_rate >= POISON_QUARANTINE
 *     → poison_family_blocks + block supply adjustment + replenisher reduce_supply
 *
 *   SUPPLY DECREASE (non-poison):
 *     give_back_rate >= GIVE_BACK_CEILING (but below poison)
 *     → task_family_supply_adjustments decrease + replenisher reduce_supply
 *
 *   SUPPLY INCREASE (requires value_proof):
 *     success_rate >= SUCCESS_FLOOR AND has_value_proof
 *     → task_family_supply_adjustments increase + replenisher boost_supply
 *
 *   WEAK-GREEN QUALITY REVIEW (success without value_proof):
 *     success_rate >= SUCCESS_FLOOR AND NOT has_value_proof
 *     → weak_green_quality_reviews tighten_task_fabric (no supply boost)
 *
 *   WORKER AFFINITY (independent; emitted regardless of supply direction):
 *     success_rate >= SUCCESS_FLOOR AND has_value_proof → confirm current worker_tier
 *     give_back_rate >= GIVE_BACK_CEILING                → recommend cheaper tier
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
 *     weak_green_quality_reviews,
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
        $thresholds       = is_array($options['thresholds'] ?? null) ? $options['thresholds'] : [];
        $minSamples       = (int)   ($thresholds['min_samples']       ?? self::MIN_SAMPLES);
        $successFloor     = (float) ($thresholds['success_floor']     ?? self::SUCCESS_FLOOR);
        $giveBackCeiling  = (float) ($thresholds['give_back_ceiling'] ?? self::GIVE_BACK_CEILING);
        $poisonGiveBack   = (float) ($thresholds['poison_give_back']  ?? self::POISON_GIVE_BACK);
        $poisonQuarantine = (float) ($thresholds['poison_quarantine'] ?? self::POISON_QUARANTINE);

        $affinityUpdates      = [];
        $supplyAdjustments    = [];
        $poisonBlocks         = [];
        $weakGreenReviews     = [];
        $replenisherFeedback  = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $family         = (string) ($row['task_family'] ?? '');
            $tier           = (string) ($row['worker_tier'] ?? 'scaffolded_small_model');
            $successRate    = (float)  ($row['success_rate'] ?? 0.0);
            $giveBackRate   = (float)  ($row['give_back_rate'] ?? 0.0);
            $quarantineRate = (float)  ($row['quarantine_rate'] ?? 0.0);
            $sampleCount    = (int)    ($row['sample_count'] ?? 0);
            $hasValueProof  = (bool)   ($row['has_value_proof'] ?? false);

            if ($sampleCount < $minSamples || $family === '') {
                continue;
            }

            $isPoison = $giveBackRate >= $poisonGiveBack || $quarantineRate >= $poisonQuarantine;

            // ── Poison block (highest priority; skips all other rules) ─────────
            if ($isPoison) {
                $reason = $giveBackRate >= $poisonGiveBack
                    ? sprintf('give_back_rate_%.2f_exceeds_poison_threshold_%.2f', $giveBackRate, $poisonGiveBack)
                    : sprintf('quarantine_rate_%.2f_exceeds_poison_threshold_%.2f', $quarantineRate, $poisonQuarantine);

                $poisonBlocks[]     = ['task_family' => $family, 'reason' => $reason];
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
                $affinityUpdates[] = [
                    'task_family'      => $family,
                    'recommended_tier' => $this->cheaperTier($tier),
                    'reason'           => 'poison_family_downgrade_to_cheaper_tier',
                ];
                continue;
            }

            // ── Non-poison supply decrease ────────────────────────────────────
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

                $affinityUpdates[] = [
                    'task_family'      => $family,
                    'recommended_tier' => $this->cheaperTier($tier),
                    'reason'           => sprintf('give_back_rate_%.2f_suggests_simpler_tier', $giveBackRate),
                ];
            }

            // ── Supply increase OR weak-green review ──────────────────────────
            if ($successRate >= $successFloor) {
                if ($hasValueProof) {
                    // Proven real value: boost supply and confirm tier.
                    $magnitude = round(min(1.0, $successRate), 4);
                    $supplyAdjustments[] = [
                        'task_family' => $family,
                        'adjustment'  => 'increase',
                        'magnitude'   => $magnitude,
                        'reason'      => sprintf('success_rate_%.2f_with_value_proof_above_floor_%.2f', $successRate, $successFloor),
                    ];
                    $supplySentinel = 'boost_supply';

                    $affinityUpdates[] = [
                        'task_family'      => $family,
                        'recommended_tier' => $tier,
                        'reason'           => sprintf('success_rate_%.2f_with_value_proof_confirms_tier_%s', $successRate, $tier),
                    ];
                } else {
                    // Green metrics but no value proof: tighten gates, do not boost.
                    $weakGreenReviews[] = [
                        'task_family' => $family,
                        'action'      => 'tighten_task_fabric',
                        'worker_tier' => $tier,
                        'reason'      => sprintf('success_rate_%.2f_without_value_proof', $successRate),
                    ];
                    // supplySentinel stays 'hold' (no boost for weak-green)
                }
            }

            $replenisherFeedback[] = [
                'task_family' => $family,
                'action'      => $supplySentinel,
                'reason'      => match ($supplySentinel) {
                    'boost_supply'   => 'boost_supply_based_on_outcome_rates',
                    'reduce_supply'  => 'reduce_supply_based_on_outcome_rates',
                    default          => 'no_dominant_supply_signal',
                },
            ];
        }

        return [
            'schema'                          => self::SCHEMA,
            'worker_affinity_updates'         => $affinityUpdates,
            'task_family_supply_adjustments'  => $supplyAdjustments,
            'poison_family_blocks'            => $poisonBlocks,
            'weak_green_quality_reviews'      => $weakGreenReviews,
            'replenisher_feedback'            => $replenisherFeedback,
        ];
    }

    private function cheaperTier(string $tier): string
    {
        $rank   = self::TIER_ORDER[$tier] ?? 1;
        $cheaper = max(0, $rank - 1);
        $flip   = array_flip(self::TIER_ORDER);

        return $flip[$cheaper] ?? 'small_model';
    }
}
