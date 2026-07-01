<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Maestro\Projection;

/**
 * Estimates how quickly active muscles will drain the claimable task queue, when replenishment
 * must begin, and whether queue depth represents real capacity or dependency-blocked backlog.
 *
 * INPUT:
 *   active_leases:            int   — number of muscles currently holding a lease
 *   claimable_depth:          int   — total tasks available to claim (includes blocked)
 *   servable_now:             int   — tasks claimable right now (no dependency block)
 *   blocked_count:            int   — tasks blocked by unresolved dependencies
 *   recent_completions:       int   — tasks resolved in the last measurement period (default: 1 hour)
 *   burn_rate_floor:          int   — minimum servable_now before replenish_now fires (default 5)
 *   give_back_rate:           float — recent give_back fraction [0,1]; quality signal independent of depth
 *   previous_claimable_depth: int|null — claimable_depth observed at the prior measurement, for trend detection
 *
 * OUTPUT:
 *   { schema, hours_to_dry, burn_rate, capacity_status, replenish_now, reasons,
 *     quality_drain_risk, recommendation }
 *
 *   hours_to_dry:      float   — servable_now ÷ burn_rate; Inf when burn_rate=0
 *   burn_rate:         float   — tasks/hour (recent_completions → active_leases proxy → 1.0)
 *   capacity_status:   'healthy'|'dependency_blocked'|'critically_low'|'empty'
 *   replenish_now:     bool    — true when servable_now < burn_rate_floor, OR claimable depth is
 *                                falling while completion rate is high (draining faster than it looks)
 *   quality_drain_risk: bool   — true when give_back_rate is high, regardless of raw queue depth
 *   recommendation:    'replenish_now'|'address_quality_before_volume'|'maintain'
 *   reasons:           list<string>
 *
 * CAPACITY STATUS (checked in priority order):
 *   empty              — servable_now = 0
 *   critically_low     — servable_now < burn_rate_floor (and > 0)
 *   dependency_blocked — blocked share of claimable >= 50% (capacity is an illusion)
 *   healthy            — otherwise
 *
 * PURE / DETERMINISTIC. No I/O.
 */
final class AtlasMaestroMuscleThroughputContinuityModel
{
    public const SCHEMA = 'atlas.maestro.projection.muscle_throughput_continuity_model.v1';

    public const DEFAULT_BURN_RATE_FLOOR = 5;

    public const STATUS_HEALTHY             = 'healthy';
    public const STATUS_DEPENDENCY_BLOCKED  = 'dependency_blocked';
    public const STATUS_CRITICALLY_LOW      = 'critically_low';
    public const STATUS_EMPTY               = 'empty';

    /** Blocked-fraction threshold above which capacity is considered dependency-blocked. */
    private const BLOCKED_FRACTION_THRESHOLD = 0.5;

    /** give_back_rate at/above this fraction signals quality drain risk regardless of depth. */
    private const QUALITY_DRAIN_GIVE_BACK_THRESHOLD = 0.3;

    public const THROUGHPUT_MODE_DIRECT = 'direct';
    public const THROUGHPUT_MODE_ESTIMATED = 'estimated';
    public const THROUGHPUT_MODE_BLIND = 'blind';

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function model(array $input): array
    {
        $activeLeases      = max(0, (int) ($input['active_leases'] ?? 0));
        $claimableDepth    = max(0, (int) ($input['claimable_depth'] ?? 0));
        $servableNow       = max(0, (int) ($input['servable_now'] ?? 0));
        $blockedCount      = max(0, (int) ($input['blocked_count'] ?? 0));
        $recentCompletions = max(0, (int) ($input['recent_completions'] ?? 0));
        $burnRateFloor     = max(1, (int) ($input['burn_rate_floor'] ?? self::DEFAULT_BURN_RATE_FLOOR));
        $serveTotal        = max(0, (int) ($input['serve_total'] ?? 0));
        $projectionHistory = is_array($input['projection_history'] ?? null) ? $input['projection_history'] : [];
        $giveBackRate      = max(0.0, min(1.0, (float) ($input['give_back_rate'] ?? 0.0)));
        $previousClaimable = array_key_exists('previous_claimable_depth', $input) && $input['previous_claimable_depth'] !== null
            ? (int) $input['previous_claimable_depth']
            : null;

        $projectionEstimatedBurn = $this->projectionEstimatedBurn($projectionHistory);

        // Burn rate: prefer observed completions, fall back to lease count, then 1.0.
        $burnRate = $recentCompletions > 0
            ? (float) $recentCompletions
            : ($activeLeases > 0 ? (float) $activeLeases : 1.0);

        // Throughput mode labels WHERE the burn-rate signal actually came from, so an originator
        // never reads zero direct telemetry as "zero real work happened" — a projection-derived
        // estimate, or an outright blind spot, must never be silently presented as a hard zero.
        $throughputMode = match (true) {
            $recentCompletions > 0 || $serveTotal > 0 => self::THROUGHPUT_MODE_DIRECT,
            $projectionEstimatedBurn > 0 => self::THROUGHPUT_MODE_ESTIMATED,
            default => self::THROUGHPUT_MODE_BLIND,
        };

        $hoursToDry = $burnRate > 0
            ? round($servableNow / $burnRate, 2)
            : INF;

        $capacityStatus = $this->capacityStatus($servableNow, $burnRateFloor, $blockedCount, $claimableDepth);
        $replenishNow   = $servableNow < $burnRateFloor;

        // AC: falling claimable depth with a high completion rate is draining faster than raw
        // depth suggests — replenish now even though servable_now hasn't crossed the floor yet.
        $depthFalling = $previousClaimable !== null && $claimableDepth < $previousClaimable;
        $depthFallingWithHighCompletionRate = $depthFalling && $recentCompletions >= $burnRateFloor;
        if ($depthFallingWithHighCompletionRate) {
            $replenishNow = true;
        }

        $qualityDrainRisk = $giveBackRate >= self::QUALITY_DRAIN_GIVE_BACK_THRESHOLD;

        $reasons = $this->buildReasons(
            $servableNow, $burnRateFloor, $blockedCount, $claimableDepth,
            $burnRate, $recentCompletions, $activeLeases, $replenishNow,
        );

        if ($depthFallingWithHighCompletionRate) {
            $reasons[] = sprintf('falling_claimable_depth:previous=%d current=%d recent_completions=%d', $previousClaimable, $claimableDepth, $recentCompletions);
        }
        if ($qualityDrainRisk) {
            $reasons[] = sprintf('quality_drain_risk:give_back_rate=%.2f', $giveBackRate);
        }

        if ($throughputMode === self::THROUGHPUT_MODE_BLIND) {
            $reasons[] = 'caution:zero_telemetry_does_not_mean_zero_real_work';
        } elseif ($throughputMode === self::THROUGHPUT_MODE_ESTIMATED) {
            $reasons[] = sprintf('throughput_mode_estimated_from_projection_history:burn=%.2f', $projectionEstimatedBurn);
        }

        $recommendation = match (true) {
            $replenishNow => 'replenish_now',
            $qualityDrainRisk => 'address_quality_before_volume',
            default => 'maintain',
        };

        return [
            'schema'             => self::SCHEMA,
            'hours_to_dry'       => $hoursToDry,
            'burn_rate'          => $burnRate,
            'capacity_status'    => $capacityStatus,
            'replenish_now'      => $replenishNow,
            'quality_drain_risk' => $qualityDrainRisk,
            'recommendation'     => $recommendation,
            'reasons'            => array_values($reasons),
            'throughput_mode'    => $throughputMode,
        ];
    }

    /**
     * Sums completion (or, absent that, claim) deltas across a projection fact history to derive a
     * burn-rate estimate when no direct serve telemetry exists. Never invents a positive estimate
     * from an empty or malformed history.
     *
     * @param  list<array<string,mixed>>  $projectionHistory
     */
    private function projectionEstimatedBurn(array $projectionHistory): float
    {
        $completionSum = 0.0;
        $claimSum = 0.0;
        foreach ($projectionHistory as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $completionSum += max(0, (float) ($entry['completion_delta'] ?? 0));
            $claimSum += max(0, (float) ($entry['claim_delta'] ?? 0));
        }

        return $completionSum > 0 ? $completionSum : $claimSum;
    }

    private function capacityStatus(int $servableNow, int $floor, int $blocked, int $claimable): string
    {
        if ($servableNow === 0) {
            return self::STATUS_EMPTY;
        }
        if ($servableNow < $floor) {
            return self::STATUS_CRITICALLY_LOW;
        }
        $blockedFraction = $claimable > 0 ? $blocked / $claimable : 0.0;
        if ($blockedFraction >= self::BLOCKED_FRACTION_THRESHOLD) {
            return self::STATUS_DEPENDENCY_BLOCKED;
        }

        return self::STATUS_HEALTHY;
    }

    /**
     * @return list<string>
     */
    private function buildReasons(
        int $servableNow,
        int $floor,
        int $blocked,
        int $claimable,
        float $burnRate,
        int $recentCompletions,
        int $activeLeases,
        bool $replenishNow,
    ): array {
        $reasons = [];

        if ($recentCompletions > 0) {
            $reasons[] = sprintf('burn_rate_source:recent_completions=%d', $recentCompletions);
        } elseif ($activeLeases > 0) {
            $reasons[] = sprintf('burn_rate_source:active_leases_proxy=%d', $activeLeases);
        } else {
            $reasons[] = 'burn_rate_source:fallback_1.0';
        }

        $reasons[] = sprintf('servable_now=%d burn_rate_floor=%d', $servableNow, $floor);

        if ($blocked > 0) {
            $blockedFraction = $claimable > 0 ? round($blocked / $claimable, 2) : 1.0;
            $reasons[] = sprintf('blocked_count=%d blocked_fraction=%.2f', $blocked, $blockedFraction);
        }

        if ($replenishNow) {
            $reasons[] = sprintf('replenish_now:servable_now(%d)<floor(%d)', $servableNow, $floor);
        }

        return $reasons;
    }
}
