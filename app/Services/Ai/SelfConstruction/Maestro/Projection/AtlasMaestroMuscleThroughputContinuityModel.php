<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Maestro\Projection;

/**
 * Estimates how quickly active muscles will drain the claimable task queue, when replenishment
 * must begin, and whether queue depth represents real capacity or dependency-blocked backlog.
 *
 * INPUT:
 *   active_leases:       int   — number of muscles currently holding a lease
 *   claimable_depth:     int   — total tasks available to claim (includes blocked)
 *   servable_now:        int   — tasks claimable right now (no dependency block)
 *   blocked_count:       int   — tasks blocked by unresolved dependencies
 *   recent_completions:  int   — tasks resolved in the last measurement period (default: 1 hour)
 *   burn_rate_floor:     int   — minimum servable_now before replenish_now fires (default 5)
 *
 * OUTPUT:
 *   { schema, hours_to_dry, burn_rate, capacity_status, replenish_now, reasons }
 *
 *   hours_to_dry:      float   — servable_now ÷ burn_rate; Inf when burn_rate=0
 *   burn_rate:         float   — tasks/hour (recent_completions → active_leases proxy → 1.0)
 *   capacity_status:   'healthy'|'dependency_blocked'|'critically_low'|'empty'
 *   replenish_now:     bool    — true when servable_now < burn_rate_floor
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

        // Burn rate: prefer observed completions, fall back to lease count, then 1.0.
        $burnRate = $recentCompletions > 0
            ? (float) $recentCompletions
            : ($activeLeases > 0 ? (float) $activeLeases : 1.0);

        $hoursToDry = $burnRate > 0
            ? round($servableNow / $burnRate, 2)
            : INF;

        $capacityStatus = $this->capacityStatus($servableNow, $burnRateFloor, $blockedCount, $claimableDepth);
        $replenishNow   = $servableNow < $burnRateFloor;

        $reasons = $this->buildReasons(
            $servableNow, $burnRateFloor, $blockedCount, $claimableDepth,
            $burnRate, $recentCompletions, $activeLeases, $replenishNow,
        );

        return [
            'schema'          => self::SCHEMA,
            'hours_to_dry'    => $hoursToDry,
            'burn_rate'       => $burnRate,
            'capacity_status' => $capacityStatus,
            'replenish_now'   => $replenishNow,
            'reasons'         => array_values($reasons),
        ];
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
