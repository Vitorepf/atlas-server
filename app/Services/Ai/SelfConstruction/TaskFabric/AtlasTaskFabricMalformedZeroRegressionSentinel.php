<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\TaskFabric;

/**
 * Pure sentinel: scores a batch snapshot against zero-regression requirements.
 * A batch is accepted only when every regression counter is zero and the queue is live.
 *
 * Checked signals (each non-zero/unhealthy adds a rejection reason):
 *   health.healthy=false          → health_not_ok
 *   health.malformed_count>0      → malformed_count_nonzero
 *   sweep.would_block_count>0     → would_block_count_nonzero
 *   sweep.malformed_count>0       → sweep_malformed_nonzero
 *   queued_targets.collision_count>0   → collision_count_nonzero
 *   queued_targets.dry_queue=true      → dry_queue
 *   queued_targets.recoverable_count>0 → recoverable_backlog_nonzero
 *
 * rejection_reasons is always sorted for deterministic output.
 */
final class AtlasTaskFabricMalformedZeroRegressionSentinel
{
    public const SCHEMA = 'atlas.task_fabric.malformed_zero_regression_sentinel.v1';

    /**
     * @param  array<string,mixed>  $snapshot  health, sweep, queued_targets sub-arrays
     * @return array{schema_version:string, accepted:bool, rejection_reasons:list<string>}
     */
    public function score(array $snapshot): array
    {
        $health = is_array($snapshot['health'] ?? null) ? $snapshot['health'] : [];
        $sweep = is_array($snapshot['sweep'] ?? null) ? $snapshot['sweep'] : [];
        $queued = is_array($snapshot['queued_targets'] ?? null) ? $snapshot['queued_targets'] : [];

        $healthy = (bool) ($health['healthy'] ?? true);
        $malformedCount = max(0, (int) ($health['malformed_count'] ?? 0));
        $wouldBlockCount = max(0, (int) ($sweep['would_block_count'] ?? 0));
        $sweepMalformed = max(0, (int) ($sweep['malformed_count'] ?? 0));
        $collisionCount = max(0, (int) ($queued['collision_count'] ?? 0));
        $dryQueue = (bool) ($queued['dry_queue'] ?? false);
        $recoverableCount = max(0, (int) ($queued['recoverable_count'] ?? 0));

        $reasons = [];

        if (! $healthy) {
            $reasons[] = 'health_not_ok';
        }
        if ($malformedCount > 0) {
            $reasons[] = 'malformed_count_nonzero';
        }
        if ($wouldBlockCount > 0) {
            $reasons[] = 'would_block_count_nonzero';
        }
        if ($sweepMalformed > 0) {
            $reasons[] = 'sweep_malformed_nonzero';
        }
        if ($collisionCount > 0) {
            $reasons[] = 'collision_count_nonzero';
        }
        if ($dryQueue) {
            $reasons[] = 'dry_queue';
        }
        if ($recoverableCount > 0) {
            $reasons[] = 'recoverable_backlog_nonzero';
        }

        sort($reasons);

        return [
            'schema_version' => self::SCHEMA,
            'accepted' => $reasons === [],
            'rejection_reasons' => $reasons,
        ];
    }
}
