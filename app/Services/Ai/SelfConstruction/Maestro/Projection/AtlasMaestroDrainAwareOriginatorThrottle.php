<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Maestro\Projection;

/**
 * Pure throttle that adjusts originator batch size from real drain facts.
 *
 * - sufficient_depth → smaller batches (not stop — the loop never stops)
 * - dry queue → max replenishment
 * - active drain → normal replenishment
 *
 * Pure: no I/O, no side effects.
 */
final class AtlasMaestroDrainAwareOriginatorThrottle
{
    public const SCHEMA = 'atlas.maestro.drain_aware_originator_throttle.v1';

    public const DEFAULT_MAX_BATCH = 10;
    public const DEFAULT_MIN_BATCH = 1;
    public const DEFAULT_NORMAL_BATCH = 5;

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function throttle(array $input): array
    {
        $claimableDepth = (int) ($input['claimable_depth'] ?? 0);
        $activeWorkers = (int) ($input['active_workers'] ?? 0);
        $serveRatePerMinute = (float) ($input['serve_rate_per_minute'] ?? 0.0);
        $maxBatch = (int) ($input['max_batch'] ?? self::DEFAULT_MAX_BATCH);
        $minBatch = (int) ($input['min_batch'] ?? self::DEFAULT_MIN_BATCH);
        $normalBatch = (int) ($input['normal_batch'] ?? self::DEFAULT_NORMAL_BATCH);

        // Determine drain state.
        $isDry = $claimableDepth === 0;
        $isSufficientDepth = $claimableDepth > 0 && $activeWorkers > 0 && $serveRatePerMinute > 0.0;
        $isActiveDrain = $claimableDepth > 0 && $activeWorkers > 0;

        $batchSize = match (true) {
            $isDry => $maxBatch,
            $isSufficientDepth => max($minBatch, (int) floor($normalBatch / 2)),
            $isActiveDrain => $normalBatch,
            default => $normalBatch,
        };

        $drainState = match (true) {
            $isDry => 'dry_queue',
            $isSufficientDepth => 'sufficient_depth',
            $isActiveDrain => 'active_drain',
            default => 'unknown',
        };

        $reasons = [];
        if ($isDry) {
            $reasons[] = 'dry_queue_max_replenishment';
        }
        if ($isSufficientDepth) {
            $reasons[] = 'sufficient_depth_reduced_batch';
        }
        if ($isActiveDrain && ! $isSufficientDepth) {
            $reasons[] = 'active_drain_normal_replenishment';
        }

        return [
            'schema_version' => self::SCHEMA,
            'batch_size' => $batchSize,
            'drain_state' => $drainState,
            'reasons' => $reasons,
            'claimable_depth' => $claimableDepth,
            'active_workers' => $activeWorkers,
            'serve_rate_per_minute' => $serveRatePerMinute,
            'max_batch' => $maxBatch,
            'min_batch' => $minBatch,
            'stop_loop' => false, // sufficient_depth never stops the loop
        ];
    }
}
