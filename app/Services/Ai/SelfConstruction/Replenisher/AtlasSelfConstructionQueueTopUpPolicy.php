<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Replenisher;

/**
 * Pure policy that decides whether the native replenisher should top up the queue.
 *
 * INPUT FACTS:
 *   { queue_health_status:string, claimable_depth:int, servable_depth:int,
 *     malformed_count:int, accepted_frontier_count:int,
 *     risk_budget:{remaining_units:int, required_per_packet:int},
 *     low_water_mark?:int, batch_cap?:int,
 *     target_worker_count?:int, active_leases?:int,
 *     quarantined_count?:int, consumption_rate_per_minute?:float }
 *
 * OUTCOMES:
 *   stop_for_safety  — queue_health_status='red' OR risk_budget exhausted
 *   repair_first     — malformed_count > 0 (clean before adding more)
 *   allow            — claimable depth cannot feed active workers OR below low_water_mark
 *                      (bounded by batch_cap and risk_budget remaining/required_per_packet)
 *   wait             — otherwise
 *
 * RESPONSE FIELDS (always present):
 *   top_up_required   — true when net_claimable < target_worker_count
 *   target_new_packets — how many packets to add (0 when no top-up needed or blocked)
 *
 * INVARIANTS:
 *   - DETERMINISTIC envelope (reasons sorted).
 *   - PURE.
 *   - new_packet_count is BOUNDED by min(batch_cap, accepted_frontier_count, budget_remaining/per_packet).
 */
final class AtlasSelfConstructionQueueTopUpPolicy
{
    public const SCHEMA = 'atlas.replenisher.queue_top_up_policy.v1';

    public const OUTCOME_ALLOW = 'allow';

    public const OUTCOME_WAIT = 'wait';

    public const OUTCOME_REPAIR_FIRST = 'repair_first';

    public const OUTCOME_STOP_SAFETY = 'stop_for_safety';

    public const DEFAULT_LOW_WATER_MARK = 25;

    public const DEFAULT_BATCH_CAP = 10;

    /**
     * @param  array<string,mixed>  $facts
     * @return array{schema:string, outcome:string, new_packet_count:int, top_up_required:bool, target_new_packets:int, reasons:list<string>}
     */
    public function decide(array $facts): array
    {
        $reasons = [];
        $queueStatus = (string) ($facts['queue_health_status'] ?? '');
        $claimable = (int) ($facts['claimable_depth'] ?? 0);
        $malformed = (int) ($facts['malformed_count'] ?? 0);
        $accepted = (int) ($facts['accepted_frontier_count'] ?? 0);
        $budget = is_array($facts['risk_budget'] ?? null) ? $facts['risk_budget'] : [];
        $budgetRem = (int) ($budget['remaining_units'] ?? 0);
        $perPacket = max(1, (int) ($budget['required_per_packet'] ?? 1));
        $lowWater = (int) ($facts['low_water_mark'] ?? self::DEFAULT_LOW_WATER_MARK);
        $batchCap = (int) ($facts['batch_cap'] ?? self::DEFAULT_BATCH_CAP);

        // New worker-count signals.
        $targetWorkers = (int) ($facts['target_worker_count'] ?? 0);
        $quarantined = (int) ($facts['quarantined_count'] ?? 0);
        $netClaimable = max(0, $claimable - $quarantined);
        $topUpRequired = $targetWorkers > 0 && $netClaimable < $targetWorkers;
        $workerNeed = $targetWorkers > 0 ? max(0, $targetWorkers - $netClaimable) : 0;

        if ($queueStatus === 'red') {
            $reasons[] = 'stop:queue_health_red';
        }
        if ($budgetRem <= 0) {
            $reasons[] = 'stop:risk_budget_exhausted';
        }
        if ($reasons !== []) {
            sort($reasons, SORT_STRING);

            return $this->envelope(self::OUTCOME_STOP_SAFETY, 0, $topUpRequired, 0, $reasons);
        }

        if ($malformed > 0) {
            return $this->envelope(self::OUTCOME_REPAIR_FIRST, 0, $topUpRequired, 0, ['repair_first:malformed_count:'.$malformed]);
        }

        // Decide if any top-up is warranted (either low-water or worker-count path).
        $belowLowWater = $claimable < $lowWater;
        if (! $belowLowWater && ! $topUpRequired) {
            return $this->envelope(self::OUTCOME_WAIT, 0, false, 0, ['wait:claimable_above_low_water_mark:'.$claimable.'>='.$lowWater]);
        }
        if ($accepted <= 0) {
            return $this->envelope(self::OUTCOME_WAIT, 0, $topUpRequired, 0, ['wait:no_accepted_frontiers']);
        }

        $byBudget = intdiv($budgetRem, $perPacket);
        // Use the larger of the two needs (low-water gap vs worker-count gap).
        $lowWaterNeed = $belowLowWater ? $lowWater - $claimable : 0;
        $need = max($lowWaterNeed, $workerNeed);
        $newCount = max(0, min($batchCap, $accepted, $byBudget, $need));

        if ($newCount === 0) {
            return $this->envelope(self::OUTCOME_WAIT, 0, $topUpRequired, 0, ['wait:no_room_after_caps']);
        }

        return $this->envelope(self::OUTCOME_ALLOW, $newCount, $topUpRequired, $newCount, ['allow:topping_up:'.$newCount]);
    }

    /**
     * @param  list<string>  $reasons
     * @return array{schema:string, outcome:string, new_packet_count:int, top_up_required:bool, target_new_packets:int, reasons:list<string>}
     */
    private function envelope(string $outcome, int $count, bool $topUpRequired, int $targetNewPackets, array $reasons): array
    {
        sort($reasons, SORT_STRING);

        return [
            'schema' => self::SCHEMA,
            'outcome' => $outcome,
            'new_packet_count' => $count,
            'top_up_required' => $topUpRequired,
            'target_new_packets' => $targetNewPackets,
            'reasons' => $reasons,
        ];
    }
}
