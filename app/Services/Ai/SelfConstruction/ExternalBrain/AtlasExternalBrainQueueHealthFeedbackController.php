<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Feeds task queue health, malformed sweep and queued-target facts into the
 * next originator round as concrete batch-size and vein constraints.
 *
 * Pure: no I/O, no side effects.
 */
final class AtlasExternalBrainQueueHealthFeedbackController
{
    public const SCHEMA = 'atlas.external_brain.queue_health_feedback_controller.v1';

    public const CONSTRAINT_REPAIR_FIRST = 'repair_first';
    public const CONSTRAINT_SHRINK_BATCH = 'shrink_batch';
    public const CONSTRAINT_NORMAL_BATCH = 'normal_batch';

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function control(array $input): array
    {
        $health = is_array($input['queue_health'] ?? null) ? $input['queue_health'] : [];
        $sweep = is_array($input['malformed_sweep'] ?? null) ? $input['malformed_sweep'] : [];
        $targets = is_array($input['queued_targets'] ?? null) ? $input['queued_targets'] : [];
        $originatorId = (string) ($input['originator_id'] ?? '');
        $roundId = (string) ($input['round_id'] ?? '');

        $isHealthy = (bool) ($health['healthy'] ?? false);
        $claimableDepth = (int) ($health['claimable_depth'] ?? 0);
        $activeLeases = (int) ($health['active_leases'] ?? 0);
        $malformedCount = (int) ($health['malformed_count'] ?? ($sweep['would_block_count'] ?? 0));
        $wouldBlockCount = (int) ($sweep['would_block_count'] ?? 0);

        $constraints = [];
        $reasons = [];
        $maxBatchSize = 10;

        if ($malformedCount > 0 || $wouldBlockCount > 0) {
            $constraints[] = self::CONSTRAINT_REPAIR_FIRST;
            $reasons[] = 'malformed_blockers_force_repair_first:'.$malformedCount;
            $maxBatchSize = 0;
        }

        if (! $isHealthy && $maxBatchSize > 0) {
            $constraints[] = self::CONSTRAINT_SHRINK_BATCH;
            $reasons[] = 'unhealthy_queue_shrinks_expansion:'.$claimableDepth;
            $maxBatchSize = min($maxBatchSize, 2);
        }

        if ($claimableDepth > 20 && $maxBatchSize > 0) {
            $constraints[] = self::CONSTRAINT_SHRINK_BATCH;
            $reasons[] = 'deep_queue_shrinks_batch:'.$claimableDepth;
            $maxBatchSize = min($maxBatchSize, 3);
        }

        if ($activeLeases > 10 && $maxBatchSize > 0) {
            $constraints[] = self::CONSTRAINT_SHRINK_BATCH;
            $reasons[] = 'high_active_leases_shrinks_batch:'.$activeLeases;
            $maxBatchSize = min($maxBatchSize, 3);
        }

        if ($constraints === []) {
            $constraints[] = self::CONSTRAINT_NORMAL_BATCH;
            $reasons[] = 'clean_health_permits_normal_batch';
        }

        $targetFamilies = array_values(array_unique(array_filter(array_map(
            static fn (mixed $t): string => is_array($t) ? (string) ($t['target_family'] ?? '') : (string) $t,
            $targets
        ))));

        return [
            'schema_version' => self::SCHEMA,
            'originator_id' => $originatorId,
            'round_id' => $roundId,
            'constraints' => $constraints,
            'reasons' => $reasons,
            'max_batch_size' => $maxBatchSize,
            'allows_expansion' => $maxBatchSize > 0,
            'queue_healthy' => $isHealthy,
            'malformed_count' => $malformedCount,
            'would_block_count' => $wouldBlockCount,
            'claimable_depth' => $claimableDepth,
            'active_leases' => $activeLeases,
            'queued_target_families' => $targetFamilies,
        ];
    }
}
