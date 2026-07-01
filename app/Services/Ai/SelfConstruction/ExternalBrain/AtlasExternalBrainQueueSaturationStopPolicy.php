<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

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

    /**
     * @param  array{
     *   claimable_depth?:int,
     *   saturation_threshold?:int,
     *   high_value_frontier_count?:int,
     *   research_path_available?:bool,
     *   simplification_path_available?:bool,
     *   task_quality_erosion?:float,
     * }  $facts
     * @return array{
     *   schema:string,
     *   action:string,
     *   reasons:list<string>,
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

        $isSaturated = $claimable >= $threshold;

        // True stop: no paths remain at all.
        if ($highValueFrontier === 0 && ! $researchPath && ! $simplificationPath) {
            return $this->envelope(self::ACTION_STOP, [
                'stop: no high-value frontier',
                'stop: no research path',
                'stop: no simplification path',
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
                'paths_remain: frontier=' . $highValueFrontier . ' research=' . ($researchPath ? 'yes' : 'no') . ' simplification=' . ($simplificationPath ? 'yes' : 'no'),
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
        ];
    }
}
