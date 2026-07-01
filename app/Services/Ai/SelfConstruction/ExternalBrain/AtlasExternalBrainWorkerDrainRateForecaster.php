<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure forecaster that predicts how fast workers drain the queue,
 * accounting for worker count, completion velocity, give_back velocity,
 * and task quality erosion.
 *
 * Tells the brain how aggressively to originate new batches.
 *
 * NO network I/O, NO file I/O, NO provider calls.
 */
final class AtlasExternalBrainWorkerDrainRateForecaster
{
    public const SCHEMA = 'atlas.external_brain.worker_drain_rate_forecaster.v1';

    /**
     * @param  array{
     *   active_workers?:int,
     *   claimable_depth?:int,
     *   recent_completions?:int,
     *   recent_give_backs?:int,
     *   window_seconds?:int,
     *   quality_erosion?:float,
     * }  $facts
     * @return array{
     *   schema:string,
     *   forecasted_drain_per_hour:float,
     *   effective_healthy_supply:int,
     *   originate_batch_size:int,
     *   quality_warning:?string,
     *   recommendation:string,
     * }
     */
    public function forecast(array $facts): array
    {
        $workers = max(0, (int) ($facts['active_workers'] ?? 0));
        $claimable = max(0, (int) ($facts['claimable_depth'] ?? 0));
        $completions = max(0, (int) ($facts['recent_completions'] ?? 0));
        $giveBacks = max(0, (int) ($facts['recent_give_backs'] ?? 0));
        $window = max(1, (int) ($facts['window_seconds'] ?? 3600));
        $qualityErosion = max(0.0, min(1.0, (float) ($facts['quality_erosion'] ?? 0.0)));

        // Completion velocity (per hour per worker)
        $hoursWindow = $window / 3600;
        $completionRatePerWorker = $workers > 0 ? ($completions / $hoursWindow) / $workers : 0.0;

        // Forecasted drain: more workers × per-worker velocity
        $forecastedDrain = round($completionRatePerWorker * $workers, 2);

        // Give-back velocity erodes healthy supply
        $giveBackRate = $workers > 0 ? ($giveBacks / $hoursWindow) : 0.0;
        // Each give-back wastes a worker-cycle; estimate effective erosion as claimable * (1 - give_back_fraction)
        $totalEvents = $completions + $giveBacks;
        $giveBackFraction = $totalEvents > 0 ? $giveBacks / $totalEvents : 0.0;

        // Quality erosion further reduces effective supply
        $effectiveMultiplier = 1.0 - $giveBackFraction - $qualityErosion;
        $effectiveMultiplier = max(0.0, $effectiveMultiplier);
        $effectiveHealthySupply = (int) round($claimable * $effectiveMultiplier);

        // Origination batch size: how many new packets the brain should originate
        // Higher when drain is high and supply is low.
        $drainPerHour = $forecastedDrain;
        $hoursOfSupplyLeft = $drainPerHour > 0 ? $effectiveHealthySupply / $drainPerHour : 999.0;

        $batchSize = 0;
        if ($hoursOfSupplyLeft < 1.0) {
            $batchSize = max(5, (int) ceil($drainPerHour));
        } elseif ($hoursOfSupplyLeft < 2.0) {
            $batchSize = max(2, (int) ceil($drainPerHour / 2));
        }

        // Quality warning
        $qualityWarning = null;
        if ($giveBackFraction > 0.3) {
            $qualityWarning = 'high_give_back_velocity:' . round($giveBackFraction, 2);
        } elseif ($qualityErosion > 0.2) {
            $qualityWarning = 'quality_erosion:' . round($qualityErosion, 2);
        }

        // Recommendation
        $recommendation = match (true) {
            $hoursOfSupplyLeft < 1.0 => 'originate_urgently',
            $hoursOfSupplyLeft < 2.0 => 'originate_soon',
            $hoursOfSupplyLeft < 4.0 => 'originate_steady',
            default => 'originate_comfortable',
        };

        return [
            'schema' => self::SCHEMA,
            'forecasted_drain_per_hour' => $forecastedDrain,
            'effective_healthy_supply' => $effectiveHealthySupply,
            'originate_batch_size' => $batchSize,
            'quality_warning' => $qualityWarning,
            'recommendation' => $recommendation,
        ];
    }
}
