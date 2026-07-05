<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Maestro\ClosedLoop;

/**
 * Pure feedback loop that feeds real worker consumption and completion outcomes
 * back into originator batch size and vein selection.
 *
 * Adjustments:
 *   - Low consumption → reduce batch size
 *   - High completion quality → expand the winning vein
 *   - Give_back spikes → force repair-first
 *
 * Pure: no I/O, no side effects.
 */
final class AtlasMaestroOriginatorConsumptionFeedbackLoop
{
    public const SCHEMA = 'atlas.maestro.originator_consumption_feedback_loop.v1';

    private const LOW_CONSUMPTION_THRESHOLD = 0.3;
    private const HIGH_COMPLETION_THRESHOLD = 0.7;
    private const GIVE_BACK_SPIKE_THRESHOLD = 0.4;

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function feedback(array $input): array
    {
        $totalEmitted = max(1, (int) ($input['total_emitted'] ?? 0));
        $totalConsumed = (int) ($input['total_consumed'] ?? 0);
        $completedCount = (int) ($input['completed_count'] ?? 0);
        $giveBackCount = (int) ($input['give_back_count'] ?? 0);
        $currentBatchSize = (int) ($input['current_batch_size'] ?? 5);
        $winningVein = (string) ($input['winning_vein'] ?? '');

        $consumptionRate = $totalConsumed / $totalEmitted;
        $completionRate = $totalConsumed > 0 ? $completedCount / $totalConsumed : 0.0;
        $giveBackRate = $totalConsumed > 0 ? $giveBackCount / $totalConsumed : 0.0;

        $batchAdjustment = 'maintain';
        $veinAdjustment = 'maintain';
        $dispatchHint = 'balanced';
        $reasons = [];

        // Low consumption → reduce batch size.
        if ($consumptionRate < self::LOW_CONSUMPTION_THRESHOLD) {
            $batchAdjustment = 'reduce';
            $currentBatchSize = max(1, (int) floor($currentBatchSize / 2));
            $reasons[] = 'low_consumption_rate:'.round($consumptionRate, 2);
        }

        // High completion quality → expand the winning vein.
        if ($completionRate >= self::HIGH_COMPLETION_THRESHOLD && $giveBackRate < self::GIVE_BACK_SPIKE_THRESHOLD) {
            $veinAdjustment = 'expand';
            $dispatchHint = 'expansion_first';
            $reasons[] = 'high_completion_rate:'.round($completionRate, 2);
        }

        // Give_back spikes → force repair-first.
        if ($giveBackRate >= self::GIVE_BACK_SPIKE_THRESHOLD) {
            $dispatchHint = 'repair_first';
            $veinAdjustment = 'pivot';
            $reasons[] = 'give_back_spike:'.round($giveBackRate, 2);
        }

        if ($reasons === []) {
            $reasons[] = 'no_adjustment_needed';
        }

        return [
            'schema_version' => self::SCHEMA,
            'batch_adjustment' => $batchAdjustment,
            'vein_adjustment' => $veinAdjustment,
            'dispatch_hint' => $dispatchHint,
            'adjusted_batch_size' => $currentBatchSize,
            'winning_vein' => $winningVein,
            'reasons' => $reasons,
            'consumption_rate' => round($consumptionRate, 3),
            'completion_rate' => round($completionRate, 3),
            'give_back_rate' => round($giveBackRate, 3),
            'total_emitted' => $totalEmitted,
            'total_consumed' => $totalConsumed,
            'completed_count' => $completedCount,
            'give_back_count' => $giveBackCount,
        ];
    }
}
