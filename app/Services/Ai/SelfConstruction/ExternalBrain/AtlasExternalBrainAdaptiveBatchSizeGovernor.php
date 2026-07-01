<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Adapts future origination batch size to queue depth, worker drain rate,
 * theme saturation, and expected value instead of always adding a
 * fixed-size batch.
 *
 * DECISION PIPELINE (baseline 6, each rule may reduce/raise/clear it):
 *   1. queue_depth_sufficient   — servable_now already covers active_workers*2
 *                                 (min 4) -> batch starts at 0.
 *   2. deep_backlog_reduces_batch    — queue_depth >= 50 -> halve.
 *   3. theme_saturation_reduces_batch — theme_saturation >= 0.6 -> halve.
 *   4. slow_drain_rate_reduces_batch  — drain_rate_per_hour < active_workers -> halve.
 *   5. high_priority_gap_needs_fill   — high_priority_gap_count > 0 -> raise
 *      batch to at least min(12, high_priority_gap_count).
 *   6. high_value_candidate_override  — candidate_value_score >= 0.7 AND
 *      batch is still 0 -> set batch to 2.
 *   7. high_drain_high_quality_raises_batch — drain_rate_per_hour >= active_workers*2
 *      AND servable_now_delta < 0 (depth falling) AND recent_seed_proof_quality >= 0.7
 *      AND queue not already sufficient -> raise batch to at least baseline+4 (capped at 12).
 *   8. poison_rate_shrinks_batch — give_back_poison_rate >= 0.3 -> caps batch at 2,
 *      overriding every other rule (including quota-pressure fills) so a noisy queue
 *      never gets flooded with more work than it can safely absorb.
 *   9. baseline_batch — emitted only if no other reason fired.
 *
 * recommended_batch_size is always clamped to [0, 12].
 * should_enqueue = recommended_batch_size > 0.
 *
 * INPUT:
 *   queue_depth:              int (default 0)
 *   servable_now:             int (default 0)
 *   active_workers:           int (default 1)
 *   drain_rate_per_hour:      float (default 0.0)
 *   theme_saturation:         float (default 0.0)
 *   candidate_value_score:    float (default 0.0)
 *   high_priority_gap_count:  int (default 0)
 *   servable_now_delta:       float (default 0.0) — negative means servable depth is falling
 *   recent_seed_proof_quality: float (default 0.5) — quality of most recently originated seeds
 *   give_back_poison_rate:    float (default 0.0) — fraction of recent tasks given back as poison
 *
 * OUTPUT:
 *   { schema, recommended_batch_size, reason_codes, should_enqueue }
 *
 * Pure: no I/O, no side effects.
 */
final class AtlasExternalBrainAdaptiveBatchSizeGovernor
{
    public const SCHEMA = 'atlas.external_brain.adaptive_batch_size_governor.v1';

    private const BASELINE_BATCH_SIZE = 6;

    private const MAX_BATCH_SIZE = 12;

    private const DEEP_BACKLOG_QUEUE_DEPTH = 50;

    private const SATURATION_THRESHOLD = 0.6;

    private const HIGH_VALUE_THRESHOLD = 0.7;

    private const HIGH_DRAIN_MULTIPLIER = 2.0;

    private const HIGH_PROOF_QUALITY_THRESHOLD = 0.7;

    private const HIGH_POISON_RATE_THRESHOLD = 0.3;

    private const POISON_CAPPED_BATCH_SIZE = 2;

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function govern(array $input): array
    {
        $queueDepth = max(0, (int) ($input['queue_depth'] ?? 0));
        $servableNow = max(0, (int) ($input['servable_now'] ?? 0));
        $activeWorkers = max(1, (int) ($input['active_workers'] ?? 1));
        $drainRatePerHour = (float) ($input['drain_rate_per_hour'] ?? 0.0);
        $themeSaturation = max(0.0, min(1.0, (float) ($input['theme_saturation'] ?? 0.0)));
        $candidateValueScore = max(0.0, min(1.0, (float) ($input['candidate_value_score'] ?? 0.0)));
        $highPriorityGapCount = max(0, (int) ($input['high_priority_gap_count'] ?? 0));
        $minimumClaimablePerWorker = max(1, (int) ($input['minimum_claimable_per_worker'] ?? 2));
        $servableNowDelta = (float) ($input['servable_now_delta'] ?? 0.0);
        $recentSeedProofQuality = max(0.0, min(1.0, (float) ($input['recent_seed_proof_quality'] ?? 0.5)));
        $giveBackPoisonRate = max(0.0, min(1.0, (float) ($input['give_back_poison_rate'] ?? 0.0)));

        $requiredFloor = max($activeWorkers * $minimumClaimablePerWorker, 4);
        $sufficient = $servableNow >= $requiredFloor;
        $deepBacklog = $queueDepth >= self::DEEP_BACKLOG_QUEUE_DEPTH;
        $saturated = $themeSaturation >= self::SATURATION_THRESHOLD;
        $slowDrain = $drainRatePerHour < $activeWorkers;
        $valueHigh = $candidateValueScore >= self::HIGH_VALUE_THRESHOLD;
        $highDrain = $drainRatePerHour >= $activeWorkers * self::HIGH_DRAIN_MULTIPLIER;
        $servableFalling = $servableNowDelta < 0.0;
        $proofQualityHigh = $recentSeedProofQuality >= self::HIGH_PROOF_QUALITY_THRESHOLD;
        $poisonRateHigh = $giveBackPoisonRate >= self::HIGH_POISON_RATE_THRESHOLD;

        $batch = self::BASELINE_BATCH_SIZE;
        $reasonCodes = [];

        if ($sufficient) {
            $batch = 0;
            $reasonCodes[] = 'queue_depth_sufficient';
        }
        if ($deepBacklog) {
            $batch = intdiv($batch, 2);
            $reasonCodes[] = 'deep_backlog_reduces_batch';
        }
        if ($saturated) {
            $batch = intdiv($batch, 2);
            $reasonCodes[] = 'theme_saturation_reduces_batch';
        }
        if ($slowDrain) {
            $batch = intdiv($batch, 2);
            $reasonCodes[] = 'slow_drain_rate_reduces_batch';
        }
        if ($servableNow === 0 && ! $sufficient) {
            $topUp = min(self::MAX_BATCH_SIZE, max(0, $requiredFloor - $servableNow));
            if ($batch < $topUp) {
                $batch = $topUp;
                $reasonCodes[] = 'near_starvation_top_up_batch';
            }
        }
        if ($highPriorityGapCount > 0) {
            $batch = max($batch, min(self::MAX_BATCH_SIZE, $highPriorityGapCount));
            $reasonCodes[] = 'high_priority_gap_needs_fill';
        }
        if ($valueHigh && $batch === 0) {
            $batch = 2;
            $reasonCodes[] = 'high_value_candidate_override';
        }
        if ($highDrain && $servableFalling && $proofQualityHigh && ! $sufficient) {
            $batch = max($batch, min(self::MAX_BATCH_SIZE, self::BASELINE_BATCH_SIZE + 4));
            $reasonCodes[] = 'high_drain_high_quality_raises_batch';
        }
        if ($poisonRateHigh) {
            $batch = min($batch, self::POISON_CAPPED_BATCH_SIZE);
            $reasonCodes[] = 'poison_rate_shrinks_batch';
        }
        if ($reasonCodes === []) {
            $reasonCodes[] = 'baseline_batch';
        }

        $batch = max(0, min(self::MAX_BATCH_SIZE, $batch));

        return [
            'schema' => self::SCHEMA,
            'recommended_batch_size' => $batch,
            'reason_codes' => $reasonCodes,
            'should_enqueue' => $batch > 0,
        ];
    }
}
