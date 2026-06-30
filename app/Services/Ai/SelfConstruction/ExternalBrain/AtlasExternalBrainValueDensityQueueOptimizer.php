<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Decides whether to keep feeding the queue, let muscles drain first, or prioritize top packets
 * by value per worker-minute.
 *
 * DECISION PRIORITY:
 *   drain_first    — queue already has enough high-value work
 *                    triggers: claimable_count >= oversaturation_limit
 *                           OR (claimable_count >= depth_floor AND value_density_score >= value_density_floor)
 *   feed_queue     — depth or value density is too low for the active muscle fleet
 *                    triggers: claimable_count < depth_floor OR value_density_score < value_density_floor
 *   prioritize_top — depth is adequate, density is good, but optimizing selection could squeeze more value
 *
 * VALUE DENSITY PER PACKET = expected_value / max(1, estimated_worker_minutes)
 * VALUE DENSITY SCORE      = mean of per-packet densities (0.0 if no packets)
 *
 * MUSCLE MINUTES CAPACITY  = muscle_count × muscle_minutes_per_task
 *
 * TOP PACKET CLASSES       = packets in top-3 by value density (sorted desc)
 * LOW VALUE TAIL           = packets with per-packet density < value_density_floor
 *
 * INPUT:
 *   packets: list<{
 *     packet_id:                 string
 *     expected_value?:           float  (default 0.0)
 *     estimated_worker_minutes?: float  (default 1.0)
 *   }>
 *   muscle_count?:               int    (default 1)
 *   muscle_minutes_per_task?:    float  (default 30.0)
 *   value_density_floor?:        float  (default 0.50)
 *   depth_floor_multiplier?:     int    (default 2 — depth_floor = muscle_count × multiplier)
 *   oversaturation_multiplier?:  int    (default 5 — limit = muscle_count × multiplier)
 *
 * OUTPUT:
 *   { schema, action, value_density_score, top_packet_classes, low_value_tail, muscle_minutes_capacity }
 *
 * PURE / DETERMINISTIC / NO I/O.
 */
final class AtlasExternalBrainValueDensityQueueOptimizer
{
    public const SCHEMA = 'atlas.external_brain.value_density_queue_optimizer.v1';

    public const ACTION_FEED_QUEUE     = 'feed_queue';
    public const ACTION_DRAIN_FIRST    = 'drain_first';
    public const ACTION_PRIORITIZE_TOP = 'prioritize_top';

    private const DEFAULT_MUSCLE_COUNT             = 1;
    private const DEFAULT_MUSCLE_MINUTES_PER_TASK  = 30.0;
    private const DEFAULT_VALUE_DENSITY_FLOOR      = 0.50;
    private const DEFAULT_DEPTH_FLOOR_MULTIPLIER   = 2;
    private const DEFAULT_OVERSAT_MULTIPLIER        = 5;
    private const TOP_N                            = 3;

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function optimize(array $input): array
    {
        $rawPackets          = is_array($input['packets'] ?? null) ? $input['packets'] : [];
        $muscleCount         = max(1, (int) ($input['muscle_count'] ?? self::DEFAULT_MUSCLE_COUNT));
        $muscleMinutes       = max(1.0, (float) ($input['muscle_minutes_per_task'] ?? self::DEFAULT_MUSCLE_MINUTES_PER_TASK));
        $valueDensityFloor   = (float) ($input['value_density_floor'] ?? self::DEFAULT_VALUE_DENSITY_FLOOR);
        $depthMultiplier     = max(1, (int) ($input['depth_floor_multiplier'] ?? self::DEFAULT_DEPTH_FLOOR_MULTIPLIER));
        $oversatMultiplier   = max(1, (int) ($input['oversaturation_multiplier'] ?? self::DEFAULT_OVERSAT_MULTIPLIER));

        $depthFloor          = $muscleCount * $depthMultiplier;
        $oversatLimit        = $muscleCount * $oversatMultiplier;
        $muscleMinutesCap    = (float) ($muscleCount * $muscleMinutes);

        // Compute per-packet densities.
        $packets = [];
        foreach ($rawPackets as $p) {
            if (! is_array($p) || ! isset($p['packet_id'])) {
                continue;
            }
            $expectedValue   = (float) ($p['expected_value'] ?? 0.0);
            $workerMinutes   = max(1.0, (float) ($p['estimated_worker_minutes'] ?? 1.0));
            $packets[] = [
                'packet_id' => (string) $p['packet_id'],
                'density'   => $expectedValue / $workerMinutes,
            ];
        }

        $claimableCount  = count($packets);
        $densities       = array_column($packets, 'density');
        $valueDensityScore = $claimableCount > 0
            ? array_sum($densities) / $claimableCount
            : 0.0;

        // Top-N by density (descending).
        usort($packets, static fn (array $a, array $b): int =>
            abs($b['density'] - $a['density']) < 0.00001
                ? strcmp($a['packet_id'], $b['packet_id'])
                : ($b['density'] <=> $a['density'])
        );
        $topPacketClasses = array_column(array_slice($packets, 0, self::TOP_N), 'packet_id');

        // Low-value tail.
        $lowValueTail = array_column(
            array_filter($packets, static fn (array $p): bool => $p['density'] < $valueDensityFloor),
            'packet_id',
        );

        // Decision.
        if ($claimableCount >= $oversatLimit
            || ($claimableCount >= $depthFloor && $valueDensityScore >= $valueDensityFloor)
        ) {
            $action = self::ACTION_DRAIN_FIRST;
        } elseif ($claimableCount < $depthFloor || $valueDensityScore < $valueDensityFloor) {
            $action = self::ACTION_FEED_QUEUE;
        } else {
            $action = self::ACTION_PRIORITIZE_TOP;
        }

        return [
            'schema'                 => self::SCHEMA,
            'action'                 => $action,
            'value_density_score'    => round($valueDensityScore, 6),
            'top_packet_classes'     => $topPacketClasses,
            'low_value_tail'         => array_values($lowValueTail),
            'muscle_minutes_capacity' => $muscleMinutesCap,
        ];
    }
}
