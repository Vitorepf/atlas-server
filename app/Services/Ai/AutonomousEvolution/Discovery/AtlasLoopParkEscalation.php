<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery;

/**
 * PARK-LEDGER ESCALATION + LOOP-HEALTH METRIC (autonomy-theatre detector).
 *
 * Parking work is a LEGITIMATE outcome — the loop should not be forced to act on every candidate.
 * But park-EVERYTHING is autonomy theatre: if a high-EV item is repeatedly parked while almost
 * nothing is actually delivered, the loop is busy looking-busy, not evolving the scope. This unit
 * adds two defenses against that failure mode:
 *
 *   1. escalatePriority() — a parked item that keeps coming back has its priority ESCALATED so it
 *      cannot be starved forever behind a wall of cheaper, freshly-discovered work. Monotonic in the
 *      number of re-attempts, clamped to the unit interval. Zero re-attempts is a no-op (the base
 *      priority is preserved verbatim) so a first-pass park is never artificially inflated.
 *
 *   2. loopHealth() — a window-scoped health read. When high-EV work is being PARKED faster than work
 *      is DELIVERED, the loop is in an autonomy-theatre regression and the flag fires. Parking nothing
 *      (parked == 0) is never a regression regardless of delivery, and delivering at least as fast as
 *      parking is healthy.
 *
 * Pure / deterministic — no I/O, no clock, no provider spend. Same inputs => same outputs.
 */
final class AtlasLoopParkEscalation
{
    /**
     * Priority bump applied per re-attempt before clamping. Small enough that a single re-park is a
     * nudge, large enough that a chronically-parked item climbs out of starvation within a handful of
     * cycles.
     */
    private const REATTEMPT_STEP = 0.05;

    private const PRIORITY_FLOOR = 0.0;

    private const PRIORITY_CEILING = 1.0;

    /**
     * Escalate a parked item's priority by how many times it has bounced back into the queue.
     *
     * Monotonically non-decreasing in $reattempts and clamped to [0,1]. $reattempts == 0 returns the
     * base priority unchanged (also clamped, so a caller passing a slightly out-of-range base still
     * gets a well-formed result).
     *
     * @param float $basePriority the item's intrinsic priority (expected in [0,1])
     * @param int   $reattempts   number of times this item has been re-parked / re-surfaced (>= 0)
     */
    public function escalatePriority(float $basePriority, int $reattempts): float
    {
        $bumps = max(0, $reattempts);

        $escalated = $basePriority + ($bumps * self::REATTEMPT_STEP);

        return $this->clamp($escalated);
    }

    /**
     * Window-scoped loop-health read.
     *
     * @param int $parkedHighEvCount number of high-EV items parked in the window
     * @param int $deliveredCount    number of items actually delivered in the window
     * @param int $windowSeconds     length of the observation window (seconds)
     *
     * @return array{
     *     healthy: bool,
     *     parking_faster_than_delivering: bool,
     *     park_rate: float,
     *     deliver_rate: float,
     *     reason: string
     * }
     */
    public function loopHealth(int $parkedHighEvCount, int $deliveredCount, int $windowSeconds): array
    {
        $parked = max(0, $parkedHighEvCount);
        $delivered = max(0, $deliveredCount);

        // Per-second rates over the window. A non-positive window collapses to 0 rates rather than
        // dividing by zero — the regression flag below is driven by the raw counts, not the rates, so
        // a degenerate window never spuriously clears the autonomy-theatre signal.
        $window = max(0, $windowSeconds);
        $parkRate = $window > 0 ? (float) $parked / $window : 0.0;
        $deliverRate = $window > 0 ? (float) $delivered / $window : 0.0;

        // Autonomy theatre: high-EV work is being parked strictly faster than work is delivered, and
        // at least one such park actually happened (parking nothing is never a regression).
        $parkingFasterThanDelivering = $parked > $delivered && $parked > 0;

        $healthy = ! $parkingFasterThanDelivering;

        $reason = $parkingFasterThanDelivering
            ? sprintf(
                'autonomy-theatre regression: %d high-EV item(s) parked but only %d delivered in %ds window',
                $parked,
                $delivered,
                $window
            )
            : sprintf(
                'healthy: %d high-EV parked, %d delivered in %ds window',
                $parked,
                $delivered,
                $window
            );

        return [
            'healthy' => $healthy,
            'parking_faster_than_delivering' => $parkingFasterThanDelivering,
            'park_rate' => $parkRate,
            'deliver_rate' => $deliverRate,
            'reason' => $reason,
        ];
    }

    private function clamp(float $value): float
    {
        return max(self::PRIORITY_FLOOR, min(self::PRIORITY_CEILING, $value));
    }
}
