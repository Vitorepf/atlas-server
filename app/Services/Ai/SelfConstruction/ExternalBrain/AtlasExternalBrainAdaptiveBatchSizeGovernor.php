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
 *   7. baseline_batch — emitted only if no other reason fired.
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

        $sufficient = $servableNow >= max($activeWorkers * 2, 4);
        $deepBacklog = $queueDepth >= self::DEEP_BACKLOG_QUEUE_DEPTH;
        $saturated = $themeSaturation >= self::SATURATION_THRESHOLD;
        $slowDrain = $drainRatePerHour < $activeWorkers;
        $valueHigh = $candidateValueScore >= self::HIGH_VALUE_THRESHOLD;

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
        if ($highPriorityGapCount > 0) {
            $batch = max($batch, min(self::MAX_BATCH_SIZE, $highPriorityGapCount));
            $reasonCodes[] = 'high_priority_gap_needs_fill';
        }
        if ($valueHigh && $batch === 0) {
            $batch = 2;
            $reasonCodes[] = 'high_value_candidate_override';
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
