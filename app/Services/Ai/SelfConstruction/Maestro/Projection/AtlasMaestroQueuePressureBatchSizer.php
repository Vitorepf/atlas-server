<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Maestro\Projection;

/**
 * Sizes originator batches from queue pressure, active workers and drain
 * confidence while respecting the 5-12 task round contract.
 *
 * Pure: no I/O, no side effects.
 */
final class AtlasMaestroQueuePressureBatchSizer
{
    public const SCHEMA = 'atlas.maestro.queue_pressure_batch_sizer.v1';

    public const BATCH_MIN = 5;
    public const BATCH_NORMAL_LOW = 8;
    public const BATCH_NORMAL_HIGH = 10;
    public const BATCH_MAX = 12;

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function size(array $input): array
    {
        $claimableDepth = (int) ($input['claimable_depth'] ?? 0);
        $activeWorkers = (int) ($input['active_workers'] ?? 0);
        $serveRatePerMinute = (float) ($input['serve_rate_per_minute'] ?? 0.0);
        $malformedCount = (int) ($input['malformed_count'] ?? 0);
        $drainConfidence = (float) ($input['drain_confidence'] ?? 0.5);
        $roundId = (string) ($input['round_id'] ?? '');
        $originatorId = (string) ($input['originator_id'] ?? '');

        if ($malformedCount > 0) {
            return [
                'schema_version' => self::SCHEMA,
                'originator_id' => $originatorId,
                'round_id' => $roundId,
                'batch_size' => 0,
                'batch_kind' => 'repair_only',
                'reasons' => ["malformed_count={$malformedCount}: repair-first batch"],
                'claimable_depth' => $claimableDepth,
                'active_workers' => $activeWorkers,
                'serve_rate_per_minute' => $serveRatePerMinute,
                'drain_confidence' => $drainConfidence,
            ];
        }

        $isDry = $claimableDepth === 0;
        $isLowPressure = $claimableDepth > 0 && $claimableDepth <= $activeWorkers * 2;
        $isNormalPressure = $claimableDepth > $activeWorkers * 2 && $claimableDepth <= $activeWorkers * 5;

        $batchSize = match (true) {
            $isDry => self::BATCH_MAX,
            $isLowPressure => self::BATCH_MIN,
            $isNormalPressure => $this->normalBatch($drainConfidence),
            default => self::BATCH_NORMAL_LOW,
        };

        $batchKind = match (true) {
            $isDry => 'max_replenishment',
            $isLowPressure => 'low_pressure',
            $isNormalPressure => 'normal_pressure',
            default => 'reduced_pressure',
        };

        $reasons = [];
        if ($isDry) {
            $reasons[] = 'dry_queue_selects_max_batch';
        } elseif ($isLowPressure) {
            $reasons[] = 'low_pressure_selects_min_batch';
        } elseif ($isNormalPressure) {
            $reasons[] = 'normal_pressure_selects_mid_batch';
        } else {
            $reasons[] = 'high_pressure_reduces_batch';
        }

        return [
            'schema_version' => self::SCHEMA,
            'originator_id' => $originatorId,
            'round_id' => $roundId,
            'batch_size' => $batchSize,
            'batch_kind' => $batchKind,
            'reasons' => $reasons,
            'claimable_depth' => $claimableDepth,
            'active_workers' => $activeWorkers,
            'serve_rate_per_minute' => $serveRatePerMinute,
            'drain_confidence' => $drainConfidence,
        ];
    }

    private function normalBatch(float $drainConfidence): int
    {
        return $drainConfidence >= 0.7 ? self::BATCH_NORMAL_HIGH : self::BATCH_NORMAL_LOW;
    }
}
