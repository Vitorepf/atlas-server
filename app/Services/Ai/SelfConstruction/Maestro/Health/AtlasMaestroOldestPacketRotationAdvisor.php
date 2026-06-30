<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Maestro\Health;

/**
 * Pure, deterministic, read-only advisor that resolves the control-plane ambiguity where a stale p95
 * queue age can trigger MORE origination even though the better action is to drain or reprioritize
 * EXISTING work. The closed action set never includes "originate" — rotate_oldest, surface_oldest_to_muscles,
 * observe, and do_not_rotate are the only possible outcomes, so this advisor can never recommend new work
 * as the fix for stale old work. No queue mutation, dequeue, sleep, worker spawn, provider call,
 * filesystem write, or git command.
 *
 * Input facts shape: {queue_age:{p95:float, ...}, oldest_packet_ids:list<string>, claimable_depth:int,
 *   active_leases:int, serve_rate_per_minute:float|null}
 */
final class AtlasMaestroOldestPacketRotationAdvisor
{
    public const SCHEMA = 'atlas.self_construction.maestro.oldest_packet_rotation_advisor.v1';

    public const ACTION_ROTATE_OLDEST = 'rotate_oldest';

    public const ACTION_SURFACE_OLDEST_TO_MUSCLES = 'surface_oldest_to_muscles';

    public const ACTION_OBSERVE = 'observe';

    public const ACTION_DO_NOT_ROTATE = 'do_not_rotate';

    private const P95_STALE_THRESHOLD_MINUTES = 60.0;

    private const CLAIMABLE_DEPTH_HIGH_THRESHOLD = 20;

    private const LOW_CONSUMPTION_RATE_PER_MINUTE = 1.0;

    /**
     * @param  array<string, mixed>  $facts
     * @return array<string, mixed>
     */
    public function advise(array $facts): array
    {
        $p95 = (float) data_get($facts, 'queue_age.p95', 0.0);
        $claimableDepth = (int) ($facts['claimable_depth'] ?? 0);
        $activeLeases = (int) ($facts['active_leases'] ?? 0);
        $serveRate = $facts['serve_rate_per_minute'] ?? null;
        $oldestIds = array_values(array_map('strval', (array) ($facts['oldest_packet_ids'] ?? [])));

        $isStale = $p95 >= self::P95_STALE_THRESHOLD_MINUTES;
        $isHighDepth = $claimableDepth >= self::CLAIMABLE_DEPTH_HIGH_THRESHOLD;
        $isLowOrUnknownConsumption = $serveRate === null || (float) $serveRate < self::LOW_CONSUMPTION_RATE_PER_MINUTE;

        $reasonCodes = [];
        if ($isStale) {
            $reasonCodes[] = 'p95_age_stale:'.$p95;
        }
        if ($isHighDepth) {
            $reasonCodes[] = 'claimable_depth_high:'.$claimableDepth;
        }
        if ($isLowOrUnknownConsumption) {
            $reasonCodes[] = $serveRate === null ? 'consumption_unknown' : 'consumption_low:'.$serveRate;
        }

        if ($isStale && $isHighDepth && $isLowOrUnknownConsumption) {
            $action = $activeLeases === 0 ? self::ACTION_ROTATE_OLDEST : self::ACTION_SURFACE_OLDEST_TO_MUSCLES;

            return $this->result($action, $reasonCodes, $oldestIds);
        }

        if ($isStale && ! $isHighDepth) {
            $reasonCodes[] = 'depth_not_high_enough_to_warrant_rotation';

            return $this->result(self::ACTION_DO_NOT_ROTATE, $reasonCodes, []);
        }

        return $this->result(self::ACTION_OBSERVE, $reasonCodes, []);
    }

    /**
     * @param  list<string>  $reasonCodes
     * @param  list<string>  $packetIdsToSurface
     * @return array<string, mixed>
     */
    private function result(string $action, array $reasonCodes, array $packetIdsToSurface): array
    {
        return [
            'schema' => self::SCHEMA,
            'action' => $action,
            'reason_codes' => $reasonCodes,
            'packet_ids_to_surface' => $packetIdsToSurface,
        ];
    }
}
