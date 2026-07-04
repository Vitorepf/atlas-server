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
 *     quarantined_count?:int, consumption_rate_per_minute?:float,
 *     stale_claimable_count?:int, oldest_claimable_seconds?:int }
 *
 * STALE-BACKLOG TOP-UP (bounded): a high stale_claimable_count means the raw claimable_depth
 * overstates real supply — those packets have sat unclaimed long enough they are unlikely to be
 * pulled by active workers. When workers are active (target_worker_count or active_leases > 0)
 * and effective supply (claimable_depth net of stale_claimable_count) falls below low_water_mark,
 * this allows a small top-up even though raw claimable_depth alone looks healthy. stop_for_safety
 * and repair_first are checked FIRST and always take precedence over this path.
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

    public const OUTCOME_HOLD_OR_CONSOLIDATE = 'hold_or_consolidate';

    public const OUTCOME_TOP_UP_SELECTIVE = 'top_up_selective';

    public const DEFAULT_LOW_WATER_MARK = 25;

    public const DEFAULT_BATCH_CAP = 10;

    public const DEFAULT_MIN_QUALITY_THRESHOLD = 0.5;

    /** Above this drain rate (0..1), the worker pool is shrinking fast enough to justify a
     *  small selective top-up even when the queue looks deep by raw depth alone. */
    private const WORKER_DRAIN_HIGH_THRESHOLD = 0.3;

    private const SELECTIVE_TOP_UP_CAP = 2;

    /** @var array{balanced:bool, lanes:array<string,int>, max_lane:?string, min_lane:?string} */
    private array $laneBalance = ['balanced' => true, 'lanes' => [], 'max_lane' => null, 'min_lane' => null];

    private float $minQualityThreshold = self::DEFAULT_MIN_QUALITY_THRESHOLD;

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

        $this->minQualityThreshold = (float) ($facts['minimum_quality_threshold'] ?? self::DEFAULT_MIN_QUALITY_THRESHOLD);
        $this->laneBalance = $this->computeLaneBalance(is_array($facts['lane_distribution'] ?? null) ? $facts['lane_distribution'] : []);
        $candidateQuality = array_key_exists('candidate_quality_score', $facts) ? (float) $facts['candidate_quality_score'] : null;
        $workerDrainRate = max(0.0, (float) ($facts['worker_drain_rate'] ?? 0.0));

        // New worker-count signals.
        $targetWorkers = (int) ($facts['target_worker_count'] ?? 0);
        $quarantined = (int) ($facts['quarantined_count'] ?? 0);
        $netClaimable = max(0, $claimable - $quarantined);
        $topUpRequired = $targetWorkers > 0 && $netClaimable < $targetWorkers;
        $workerNeed = $targetWorkers > 0 ? max(0, $targetWorkers - $netClaimable) : 0;

        // AC2/AC3: health and high-value signals for nonblocking-health-aware top-up decisions.
        $health = (bool) ($facts['health'] ?? true);
        $highValueClaimable = max(0, (int) ($facts['high_value_claimable_depth'] ?? 0));

        // Worker-floor hysteresis: replenish BEFORE the queue actually reaches
        // no_claimable_task. claimable_per_active_worker <= 2 or an explicit
        // replenish_soon recommendation are both leading indicators — wait for
        // them at face value instead of waiting for claimable_depth to fall
        // below the (much later) low_water_mark.
        $claimablePerActiveWorker = $facts['claimable_per_active_worker'] ?? null;
        $replenishRecommendation = (string) ($facts['replenish_recommendation'] ?? '');
        $workerFloorHysteresisTrigger = ($claimablePerActiveWorker !== null && (float) $claimablePerActiveWorker <= 2.0)
            || $replenishRecommendation === 'replenish_soon';
        if ($workerFloorHysteresisTrigger) {
            $topUpRequired = true;
        }
        $activeLeasesForHysteresis = (int) ($facts['active_leases'] ?? 0);
        // A configurable minimum claimable-per-active-worker BUFFER (not just "one packet per
        // worker"): active_leases - netClaimable is zero whenever the queue is above the worker
        // count, even though it may still be thin relative to a healthy buffer target. When a
        // target is supplied, the real gap is active_leases * target - netClaimable.
        $workerBufferTargetPerWorker = (float) ($facts['worker_buffer_target_per_worker'] ?? 0.0);
        if ($workerFloorHysteresisTrigger && $workerBufferTargetPerWorker > 0.0 && $activeLeasesForHysteresis > 0) {
            $bufferTarget = (int) ceil($activeLeasesForHysteresis * $workerBufferTargetPerWorker);
            $hysteresisNeed = max(0, $bufferTarget - $netClaimable);
        } else {
            $hysteresisNeed = $workerFloorHysteresisTrigger
                ? max(0, $activeLeasesForHysteresis - $netClaimable, $workerNeed)
                : 0;
        }

        // Stale-claimable backlog signal: raw claimable_depth overstates real supply when a
        // chunk of it has sat unclaimed long enough to be effectively dead.
        $activeLeases = (int) ($facts['active_leases'] ?? 0);
        $workersActive = $targetWorkers > 0 || $activeLeases > 0;
        $staleClaimable = min($claimable, max(0, (int) ($facts['stale_claimable_count'] ?? 0)));
        $effectiveClaimable = max(0, $claimable - $staleClaimable);
        $belowEffectiveLowWater = $workersActive && $staleClaimable > 0 && $effectiveClaimable < $lowWater;

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

        // Decide if any top-up is warranted (low-water, worker-count, stale-backlog, or hysteresis path).
        $belowLowWater = $claimable < $lowWater;
        $queueLooksDeep = ! $belowLowWater && ! $topUpRequired && ! $belowEffectiveLowWater;

        // AC2: deep queue with nonblocking health flag — don't pad, just explain why.
        if ($queueLooksDeep && ! $health) {
            return $this->envelope(self::OUTCOME_WAIT, 0, $topUpRequired, 0, ['wait:nonblocking_health_flag_deep_queue_no_padding'],
                'nonblocking_health_flag', 'on_demand');
        }

        // AC3: genuinely low high-value supply or worker drain starvation triggers top-up
        // even when the raw claimable_depth seems adequate.
        $highValueLow = $highValueClaimable > 0 && $highValueClaimable <= $lowWater / 2;

        // High worker drain + high candidate quality: allow a small selective top-up even under
        // a deep queue, since the pool itself is shrinking fast enough to justify fresh supply.
        if ($workerDrainRate > self::WORKER_DRAIN_HIGH_THRESHOLD && $candidateQuality !== null && $candidateQuality >= $this->minQualityThreshold && $accepted > 0) {
            $selectiveCount = max(1, min(self::SELECTIVE_TOP_UP_CAP, $batchCap, $accepted, intdiv($budgetRem, $perPacket)));
            if ($selectiveCount > 0) {
                return $this->envelope(self::OUTCOME_TOP_UP_SELECTIVE, $selectiveCount, $topUpRequired, $selectiveCount, ['top_up_selective:high_worker_drain_high_quality:'.$selectiveCount]);
            }
        }

        // Deep queue + weak candidate quality: don't blindly wait — recommend consolidating the
        // existing backlog instead of adding more low-value supply on top of it.
        if ($queueLooksDeep && $candidateQuality !== null && $candidateQuality < $this->minQualityThreshold) {
            return $this->envelope(self::OUTCOME_HOLD_OR_CONSOLIDATE, 0, $topUpRequired, 0, ['hold_or_consolidate:deep_queue_weak_candidate_quality:'.$candidateQuality]);
        }

        // AC3: even a deep-looking queue needs top-up when high-value supply is critically low.
        if ($queueLooksDeep && $highValueLow) {
            $byBudget = intdiv($budgetRem, $perPacket);
            $highValueNeed = min($highValueClaimable, $lowWater - $highValueClaimable);
            $newCount = max(0, min($batchCap, $accepted, $byBudget, $highValueNeed));
            if ($newCount > 0) {
                return $this->envelope(self::OUTCOME_ALLOW, $newCount, $topUpRequired, $newCount, ['allow:high_value_supply_low:'.$newCount]);
            }
        }

        if ($queueLooksDeep) {
            return $this->envelope(self::OUTCOME_WAIT, 0, $topUpRequired, 0, ['wait:claimable_above_low_water_mark:'.$claimable.'>='.$lowWater]);
        }
        if ($accepted <= 0) {
            return $this->envelope(self::OUTCOME_WAIT, 0, $topUpRequired, 0, ['wait:no_accepted_frontiers']);
        }

        $byBudget = intdiv($budgetRem, $perPacket);
        // Use the largest of the needs (low-water gap, worker-count gap, stale-backlog gap,
        // hysteresis gap, and high-value supply gap).
        $lowWaterNeed = $belowLowWater ? $lowWater - $claimable : 0;
        $staleBacklogNeed = $belowEffectiveLowWater ? $lowWater - $effectiveClaimable : 0;
        $highValueNeed = $highValueLow && $highValueClaimable < $lowWater
            ? $lowWater - $highValueClaimable
            : 0;
        $need = max($lowWaterNeed, $workerNeed, $staleBacklogNeed, $hysteresisNeed, $highValueNeed);
        $newCount = max(0, min($batchCap, $accepted, $byBudget, $need));

        if ($newCount === 0) {
            return $this->envelope(self::OUTCOME_WAIT, 0, $topUpRequired, 0, ['wait:no_room_after_caps']);
        }

        $reason = match (true) {
            $workerFloorHysteresisTrigger && ! $belowLowWater && ! $belowEffectiveLowWater => 'allow:worker_floor_hysteresis:'.$newCount,
            $belowEffectiveLowWater && ! $belowLowWater && ! $topUpRequired => 'allow:stale_backlog_effective_low_water:'.$newCount,
            default => 'allow:topping_up:'.$newCount,
        };

        return $this->envelope(self::OUTCOME_ALLOW, $newCount, $topUpRequired, $newCount, [$reason]);
    }

    /**
     * @param  list<string>  $reasons
     * @return array{schema:string, outcome:string, new_packet_count:int, top_up_required:bool, target_new_packets:int, reasons:list<string>, no_padding_reason:?string, next_allowed_origination_mode:string}
     */
    private function envelope(string $outcome, int $count, bool $topUpRequired, int $targetNewPackets, array $reasons, string $noPaddingReason = '', string $nextMode = 'immediate'): array
    {
        sort($reasons, SORT_STRING);

        return [
            'schema' => self::SCHEMA,
            'outcome' => $outcome,
            'new_packet_count' => $count,
            'top_up_required' => $topUpRequired,
            'target_new_packets' => $targetNewPackets,
            'reasons' => $reasons,
            'lane_balance' => $this->laneBalance,
            'minimum_quality_threshold' => $this->minQualityThreshold,
            'no_padding_reason' => $noPaddingReason !== '' ? $noPaddingReason : null,
            'next_allowed_origination_mode' => $nextMode,
        ];
    }

    /**
     * @param  array<string,mixed>  $laneDistribution
     * @return array{balanced:bool, lanes:array<string,int>, max_lane:?string, min_lane:?string}
     */
    private function computeLaneBalance(array $laneDistribution): array
    {
        if ($laneDistribution === []) {
            return ['balanced' => true, 'lanes' => [], 'max_lane' => null, 'min_lane' => null];
        }

        $counts = array_map('intval', $laneDistribution);
        $max = max($counts);
        $min = min($counts);
        $balanced = $max === 0 || ($min / $max) >= 0.5;

        return [
            'balanced' => $balanced,
            'lanes' => $counts,
            'max_lane' => (string) array_search($max, $counts, true),
            'min_lane' => (string) array_search($min, $counts, true),
        ];
    }
}
