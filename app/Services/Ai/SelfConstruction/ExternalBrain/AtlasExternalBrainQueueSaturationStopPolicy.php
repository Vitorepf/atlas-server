<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure stop policy. Decides when the external brain should stop adding fresh
 * build tasks and switch to audit, consolidation, or evidence backfill instead.
 *
 * Decision hierarchy (first match wins):
 *   consolidate_or_audit — claimable_depth ≥ saturation_threshold AND servable_now ≥ muscle_burn_rate_floor
 *                          (queue is healthy and deep → stop adding, focus inward)
 *   unblock_first        — servable_now < muscle_burn_rate_floor AND recoverable_backlog > 0
 *                          (muscles are starved but blocked tasks can be freed → unblock beats new origination)
 *   originate_more       — servable_now < muscle_burn_rate_floor AND recoverable_backlog == 0
 *                          (muscles are genuinely starved and nothing to unblock → must originate)
 *   monitor              — healthy but not yet saturated (default)
 *
 * Thresholds (configurable via input, with sensible defaults):
 *   saturation_threshold    default 20 — claimable_depth at which we consider the queue saturated
 *   muscle_burn_rate_floor  default 5  — minimum servable_now for the worker pool to stay busy
 *
 * Pure: no I/O, no side effects.
 */
final class AtlasExternalBrainQueueSaturationStopPolicy
{
    public const SCHEMA = 'atlas.external_brain.queue_saturation_stop_policy.v1';

    public const DECISION_CONSOLIDATE_OR_AUDIT = 'consolidate_or_audit';
    public const DECISION_ORIGINATE_MORE       = 'originate_more';
    public const DECISION_UNBLOCK_FIRST        = 'unblock_first';
    public const DECISION_MONITOR              = 'monitor';

    private const DEFAULT_SATURATION_THRESHOLD   = 20;
    private const DEFAULT_MUSCLE_BURN_RATE_FLOOR = 5;

    /**
     * @param  array{
     *   claimable_depth?: int,
     *   servable_now?: int,
     *   recoverable_backlog?: int,
     *   muscle_burn_rate_floor?: int,
     *   saturation_threshold?: int,
     * }  $input
     * @return array{schema:string, decision:string, reason:string, next_cycle_hint:string, claimable_depth:int, servable_now:int}
     */
    public function evaluate(array $input): array
    {
        $claimableDepth      = max(0, (int) ($input['claimable_depth']        ?? 0));
        $servableNow         = max(0, (int) ($input['servable_now']           ?? 0));
        $recoverableBacklog  = max(0, (int) ($input['recoverable_backlog']    ?? 0));
        $burnRateFloor       = max(1, (int) ($input['muscle_burn_rate_floor'] ?? self::DEFAULT_MUSCLE_BURN_RATE_FLOOR));
        $saturationThreshold = max(1, (int) ($input['saturation_threshold']   ?? self::DEFAULT_SATURATION_THRESHOLD));

        $queueSaturated  = $claimableDepth >= $saturationThreshold;
        $musclesStarved  = $servableNow < $burnRateFloor;

        if ($queueSaturated && ! $musclesStarved) {
            return $this->result(
                self::DECISION_CONSOLIDATE_OR_AUDIT,
                "claimable_depth={$claimableDepth} ≥ threshold={$saturationThreshold} and servable_now={$servableNow} ≥ floor={$burnRateFloor}; queue is healthy and deep",
                'run_audit_or_evidence_backfill_next_cycle',
                $claimableDepth, $servableNow,
            );
        }

        if ($musclesStarved && $recoverableBacklog > 0) {
            return $this->result(
                self::DECISION_UNBLOCK_FIRST,
                "servable_now={$servableNow} < floor={$burnRateFloor} but recoverable_backlog={$recoverableBacklog} tasks can be freed",
                'unblock_blocked_tasks_before_originating_new_work',
                $claimableDepth, $servableNow,
            );
        }

        if ($musclesStarved && $recoverableBacklog === 0) {
            return $this->result(
                self::DECISION_ORIGINATE_MORE,
                "servable_now={$servableNow} < floor={$burnRateFloor} and recoverable_backlog=0; muscles genuinely starved",
                'originate_fresh_tasks_to_refill_worker_pipeline',
                $claimableDepth, $servableNow,
            );
        }

        return $this->result(
            self::DECISION_MONITOR,
            "claimable_depth={$claimableDepth} and servable_now={$servableNow} are both healthy; no intervention needed",
            'continue_normal_origination_cadence',
            $claimableDepth, $servableNow,
        );
    }

    private function result(
        string $decision,
        string $reason,
        string $nextCycleHint,
        int $claimableDepth,
        int $servableNow,
    ): array {
        return [
            'schema'          => self::SCHEMA,
            'decision'        => $decision,
            'reason'          => $reason,
            'next_cycle_hint' => $nextCycleHint,
            'claimable_depth' => $claimableDepth,
            'servable_now'    => $servableNow,
        ];
    }
}
