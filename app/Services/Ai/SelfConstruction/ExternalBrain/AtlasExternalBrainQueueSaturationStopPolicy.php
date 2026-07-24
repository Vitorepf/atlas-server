<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

use App\Support\YesNo;

/**
 * Pure policy that decides whether queue saturation should stop origination.
 *
 * Comfortable queue depth NEVER stops high-value origination — it only shifts
 * strategy toward higher selectivity and consolidation.
 *
 * True stop is only when no high-value frontier, no research path, AND no
 * simplification path remain.
 *
 * NO network I/O, NO file I/O, NO provider calls.
 */
final class AtlasExternalBrainQueueSaturationStopPolicy
{
    public const SCHEMA = 'atlas.external_brain.queue_saturation_stop_policy.v1';

    public const ACTION_STOP = 'stop';

    public const ACTION_CONTINUE_SELECTIVE = 'continue_with_higher_selectivity';

    public const ACTION_CONSOLIDATION_REFILL = 'consolidation_refill';

    public const ACTION_CREATE_HIGH_VALUE_BATCH = 'create_high_value_batch';

    public const ACTION_WAIT = 'wait';

    /**
     * @param  array{
     *   claimable_depth?:int,
     *   saturation_threshold?:int,
     *   high_value_frontier_count?:int,
     *   research_path_available?:bool,
     *   simplification_path_available?:bool,
     *   task_quality_erosion?:float,
     *   fresh_non_duplicate_candidates?:int,
     *   duplicate_candidates?:int,
     *   malformed_candidates?:int,
     *   low_value_candidates?:int,
     *   candidates_exhausted?:bool,
     * }  $facts
     * @return array{
     *   schema:string,
     *   action:string,
     *   reasons:list<string>,
     *   padding_throttle_explanation:string,
     * }
     */
    public function evaluate(array $facts): array
    {
        $claimable = max(0, (int) ($facts['claimable_depth'] ?? 0));
        $threshold = max(1, (int) ($facts['saturation_threshold'] ?? 150));
        $highValueFrontier = max(0, (int) ($facts['high_value_frontier_count'] ?? 0));
        $researchPath = (bool) ($facts['research_path_available'] ?? false);
        $simplificationPath = (bool) ($facts['simplification_path_available'] ?? false);
        $qualityErosion = max(0.0, min(1.0, (float) ($facts['task_quality_erosion'] ?? 0.0)));

        $freshCandidates = max(0, (int) ($facts['fresh_non_duplicate_candidates'] ?? 0));
        $duplicateCandidates = max(0, (int) ($facts['duplicate_candidates'] ?? 0));
        $malformedCandidates = max(0, (int) ($facts['malformed_candidates'] ?? 0));
        $lowValueCandidates = max(0, (int) ($facts['low_value_candidates'] ?? 0));
        $candidatesExhausted = (bool) ($facts['candidates_exhausted'] ?? false);

        $isSaturated = $claimable >= $threshold;

        // Fresh non-duplicate high-leverage candidates exist → create_high_value_batch
        // regardless of queue depth or path exhaustion. Queue depth never blocks valuable origination.
        if ($freshCandidates > 0) {
            return $this->envelope(self::ACTION_CREATE_HIGH_VALUE_BATCH, [
                'fresh_non_duplicate_candidates:' . $freshCandidates,
                'queue_depth:' . $claimable,
                'high_value_frontier:' . $highValueFrontier,
                'queue_depth_never_blocks_valuable_origination',
            ]);
        }

        // True stop: no paths remain at all AND no fresh candidates.
        if ($highValueFrontier === 0 && ! $researchPath && ! $simplificationPath) {
            return $this->envelope(self::ACTION_STOP, [
                'stop: no high-value frontier',
                'stop: no research path',
                'stop: no simplification path',
            ]);
        }

        // All remaining candidates are duplicate, malformed, low-value or exhausted → wait
        if ($candidatesExhausted || ($freshCandidates === 0 && ($duplicateCandidates > 0 || $malformedCandidates > 0 || $lowValueCandidates > 0))) {
            return $this->envelope(self::ACTION_WAIT, [
                'candidates_exhausted_or_low_quality',
                'duplicate:' . $duplicateCandidates . ' malformed:' . $malformedCandidates . ' low_value:' . $lowValueCandidates,
                'wait_for_better_candidates_not_queue_depth',
            ]);
        }

        // Saturation with quality erosion → consolidation_refill (don't pad).
        if ($isSaturated && $qualityErosion > 0.2) {
            return $this->envelope(self::ACTION_CONSOLIDATION_REFILL, [
                'saturation:' . $claimable . '>=' . $threshold,
                'quality_erosion:' . round($qualityErosion, 2),
                'prefer_consolidation_over_padding',
            ]);
        }

        // Saturated but paths remain → continue with higher selectivity (NOT stop).
        if ($isSaturated) {
            return $this->envelope(self::ACTION_CONTINUE_SELECTIVE, [
                'saturated:' . $claimable . '>=' . $threshold,
                'paths_remain: frontier=' . $highValueFrontier . ' research=' . (YesNo::format($researchPath)) . ' simplification=' . (YesNo::format($simplificationPath)),
                'shift_to_higher_selectivity',
            ]);
        }

        // Not saturated → continue normally.
        return $this->envelope(self::ACTION_CONTINUE_SELECTIVE, [
            'not_saturated:' . $claimable . '<' . $threshold,
        ]);
    }

    /** @param  list<string>  $reasons */
    private function envelope(string $action, array $reasons): array
    {
        sort($reasons, SORT_STRING);

        return [
            'schema' => self::SCHEMA,
            'action' => $action,
            'reasons' => $reasons,
            'padding_throttle_explanation' => $this->buildPaddingThrottleExplanation($action),
        ];
    }

    /**
     * Explain the difference between throttling padding and blocking valuable origination.
     */
    private function buildPaddingThrottleExplanation(string $action): string
    {
        return match ($action) {
            self::ACTION_CREATE_HIGH_VALUE_BATCH => 'queue_depth_never_blocks_valuable_origination: fresh non-duplicate structural leverage takes priority over saturation',
            self::ACTION_WAIT => 'wait_is_candidate_quality_gate_not_queue_depth: candidates are duplicate/malformed/low-value/exhausted, not blocked by queue depth',
            self::ACTION_STOP => 'stop_only_when_all_paths_exhausted: no frontier, no research, no simplification',
            self::ACTION_CONSOLIDATION_REFILL => 'consolidation_over_padding: quality erosion detected, prefer consolidation to maintain signal-to-noise',
            self::ACTION_CONTINUE_SELECTIVE => 'continue_with_higher_selectivity: saturated queue shifts to higher bar but does not stop origination',
            default => 'unknown_action',
        };
    }
}
