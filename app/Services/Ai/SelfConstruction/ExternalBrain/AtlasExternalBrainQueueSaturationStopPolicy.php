<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure stop policy. Decides when the external brain should stop adding fresh
 * build tasks and switch to audit, consolidation, or evidence backfill instead.
 *
 * Decision hierarchy (first match wins):
 *   allow_enqueue_exception — queue is saturated but proposed task has exceptional
 *                             value_density (≥ 0.8) OR dependency_unlock_score > 0;
 *                             enqueue is allowed as a high-value exception
 *   consolidate_or_audit    — queue is saturated and proposed task lacks exceptional value;
 *                             stop adding, focus inward
 *   unblock_first           — muscles starved + recoverable_backlog > 0
 *   originate_more          — muscles starved + nothing to unblock
 *   monitor                 — healthy, not saturated (default)
 *
 * Thresholds:
 *   saturation_threshold              default 20   — claimable_depth at saturation
 *   muscle_burn_rate_floor            default 5    — minimum servable_now
 *   exceptional_value_density_floor   default 0.80 — minimum value_density for exception
 *
 * Pure: no I/O, no side effects.
 */
final class AtlasExternalBrainQueueSaturationStopPolicy
{
    public const SCHEMA = 'atlas.external_brain.queue_saturation_stop_policy.v1';

    public const DECISION_ALLOW_ENQUEUE_EXCEPTION = 'allow_enqueue_exception';
    public const DECISION_CONSOLIDATE_OR_AUDIT    = 'consolidate_or_audit';
    public const DECISION_ORIGINATE_MORE          = 'originate_more';
    public const DECISION_UNBLOCK_FIRST           = 'unblock_first';
    public const DECISION_MONITOR                 = 'monitor';

    private const DEFAULT_SATURATION_THRESHOLD            = 20;
    private const DEFAULT_MUSCLE_BURN_RATE_FLOOR          = 5;
    private const DEFAULT_EXCEPTIONAL_VALUE_DENSITY_FLOOR = 0.80;

    /**
     * @param  array{
     *   claimable_depth?: int,
     *   servable_now?: int,
     *   recoverable_backlog?: int,
     *   muscle_burn_rate_floor?: int,
     *   saturation_threshold?: int,
     *   value_density?: float,
     *   dependency_unlock_score?: int,
     *   exceptional_value_density_floor?: float,
     * }  $input
     */
    public function evaluate(array $input): array
    {
        $claimableDepth      = max(0, (int)   ($input['claimable_depth']                ?? 0));
        $servableNow         = max(0, (int)   ($input['servable_now']                   ?? 0));
        $recoverableBacklog  = max(0, (int)   ($input['recoverable_backlog']            ?? 0));
        $burnRateFloor       = max(1, (int)   ($input['muscle_burn_rate_floor']         ?? self::DEFAULT_MUSCLE_BURN_RATE_FLOOR));
        $saturationThreshold = max(1, (int)   ($input['saturation_threshold']           ?? self::DEFAULT_SATURATION_THRESHOLD));
        $valueDensity        = max(0.0, min(1.0, (float) ($input['value_density']       ?? 0.0)));
        $dependencyUnlock    = max(0, (int)   ($input['dependency_unlock_score']        ?? 0));
        $exceptionFloor      = max(0.0, (float) ($input['exceptional_value_density_floor'] ?? self::DEFAULT_EXCEPTIONAL_VALUE_DENSITY_FLOOR));

        $queueSaturated      = $claimableDepth >= $saturationThreshold;
        $musclesStarved      = $servableNow < $burnRateFloor;
        $isExceptionalValue  = $valueDensity >= $exceptionFloor || $dependencyUnlock > 0;

        if ($queueSaturated && $isExceptionalValue) {
            return $this->result(
                self::DECISION_ALLOW_ENQUEUE_EXCEPTION,
                "queue saturated (depth={$claimableDepth}) but task has exceptional value: value_density={$valueDensity}, dependency_unlock_score={$dependencyUnlock}",
                'enqueue_this_task_then_reassess',
                $claimableDepth, $servableNow, $valueDensity, $dependencyUnlock,
            );
        }

        if ($queueSaturated && ! $musclesStarved) {
            return $this->result(
                self::DECISION_CONSOLIDATE_OR_AUDIT,
                "claimable_depth={$claimableDepth} ≥ threshold={$saturationThreshold} and value_density={$valueDensity} below exception floor={$exceptionFloor}; block low-value enqueue",
                'run_audit_or_evidence_backfill_next_cycle',
                $claimableDepth, $servableNow, $valueDensity, $dependencyUnlock,
            );
        }

        if ($musclesStarved && $recoverableBacklog > 0) {
            return $this->result(
                self::DECISION_UNBLOCK_FIRST,
                "servable_now={$servableNow} < floor={$burnRateFloor} but recoverable_backlog={$recoverableBacklog} tasks can be freed",
                'unblock_blocked_tasks_before_originating_new_work',
                $claimableDepth, $servableNow, $valueDensity, $dependencyUnlock,
            );
        }

        if ($musclesStarved && $recoverableBacklog === 0) {
            return $this->result(
                self::DECISION_ORIGINATE_MORE,
                "servable_now={$servableNow} < floor={$burnRateFloor} and recoverable_backlog=0; muscles genuinely starved",
                'originate_fresh_tasks_to_refill_worker_pipeline',
                $claimableDepth, $servableNow, $valueDensity, $dependencyUnlock,
            );
        }

        return $this->result(
            self::DECISION_MONITOR,
            "claimable_depth={$claimableDepth} and servable_now={$servableNow} are both healthy; no intervention needed",
            'continue_normal_origination_cadence',
            $claimableDepth, $servableNow, $valueDensity, $dependencyUnlock,
        );
    }

    private function result(
        string $decision,
        string $reason,
        string $nextCycleHint,
        int $claimableDepth,
        int $servableNow,
        float $valueDensity,
        int $dependencyUnlockScore,
    ): array {
        return [
            'schema'                  => self::SCHEMA,
            'decision'                => $decision,
            'reason'                  => $reason,
            'decision_reason'         => $reason,
            'next_cycle_hint'         => $nextCycleHint,
            'claimable_depth'         => $claimableDepth,
            'queue_depth'             => $claimableDepth,
            'servable_now'            => $servableNow,
            'value_density'           => $valueDensity,
            'dependency_unlock_score' => $dependencyUnlockScore,
        ];
    }
}
