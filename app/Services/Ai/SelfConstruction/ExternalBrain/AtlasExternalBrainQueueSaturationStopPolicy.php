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

    public const DECISION_ALLOW_ENQUEUE_EXCEPTION        = 'allow_enqueue_exception';
    public const DECISION_CONSOLIDATE_OR_AUDIT           = 'consolidate_or_audit';
    public const DECISION_ORIGINATE_MORE                 = 'originate_more';
    public const DECISION_UNBLOCK_FIRST                  = 'unblock_first';
    public const DECISION_MONITOR                        = 'monitor';
    public const DECISION_PAUSE_CREATION_AND_CONSOLIDATE = 'pause_creation_and_consolidate';
    public const DECISION_CONTINUE_CREATION              = 'continue_creation';
    public const DECISION_SELF_HEAL_BEFORE_MORE_VOLUME   = 'self_heal_before_more_volume';

    private const DEFAULT_SATURATION_THRESHOLD            = 20;
    private const DEFAULT_MUSCLE_BURN_RATE_FLOOR          = 5;
    private const DEFAULT_EXCEPTIONAL_VALUE_DENSITY_FLOOR = 0.80;
    private const DEFAULT_AGE_SATURATION_THRESHOLD        = 14;   // days old = stale queue
    private const DEFAULT_LOW_SERVE_RATE_THRESHOLD        = 0.30; // fraction below = low throughput
    private const DEFAULT_POISON_PRESSURE_THRESHOLD       = 1;    // ≥ this → self-heal
    private const DEFAULT_VALUE_DENSITY_CREATION_FLOOR    = 0.60; // minimum for continue_creation

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

        // New inputs (AC1/AC2/AC3)
        $claimableAgeDays    = max(0, (int)   ($input['claimable_age_days']             ?? 0));
        $serveRate           = max(0.0, min(1.0, (float) ($input['serve_rate']          ?? 1.0)));
        $poisonPacketCount   = max(0, (int)   ($input['poison_packet_count']            ?? 0));
        $malformedPacketRate = max(0.0, min(1.0, (float) ($input['malformed_packet_rate'] ?? 0.0)));
        $ageSatThreshold     = max(1, (int)   ($input['age_saturation_threshold']       ?? self::DEFAULT_AGE_SATURATION_THRESHOLD));
        $lowServeRateFloor   = max(0.0, (float) ($input['low_serve_rate_threshold']     ?? self::DEFAULT_LOW_SERVE_RATE_THRESHOLD));
        $poisonThreshold     = max(1, (int)   ($input['poison_pressure_threshold']      ?? self::DEFAULT_POISON_PRESSURE_THRESHOLD));
        $creationFloor       = max(0.0, (float) ($input['value_density_creation_floor'] ?? self::DEFAULT_VALUE_DENSITY_CREATION_FLOOR));

        $queueSaturated      = $claimableDepth >= $saturationThreshold;
        $musclesStarved      = $servableNow < $burnRateFloor;
        $isExceptionalValue  = $valueDensity >= $exceptionFloor || $dependencyUnlock > 0;
        $poisonPressure      = $poisonPacketCount >= $poisonThreshold || $malformedPacketRate > 0.0;
        $queueStaleByAge     = $claimableAgeDays >= $ageSatThreshold;
        $lowThroughput       = $serveRate < $lowServeRateFloor;

        // AC3: poison/malformed pressure → self-heal before anything else
        if ($poisonPressure) {
            return $this->result(
                self::DECISION_SELF_HEAL_BEFORE_MORE_VOLUME,
                "poison_packet_count={$poisonPacketCount}, malformed_packet_rate={$malformedPacketRate}; queue integrity compromised",
                'self_heal_queue_integrity_before_adding_volume',
                $claimableDepth, $servableNow, $valueDensity, $dependencyUnlock,
            );
        }

        if ($queueSaturated && $isExceptionalValue) {
            return $this->result(
                self::DECISION_ALLOW_ENQUEUE_EXCEPTION,
                "queue saturated (depth={$claimableDepth}) but task has exceptional value: value_density={$valueDensity}, dependency_unlock_score={$dependencyUnlock}",
                'enqueue_this_task_then_reassess',
                $claimableDepth, $servableNow, $valueDensity, $dependencyUnlock,
            );
        }

        // AC1: stale queue (old tasks accumulating) + low serve rate → pause and consolidate
        if ($queueStaleByAge && $lowThroughput) {
            return $this->result(
                self::DECISION_PAUSE_CREATION_AND_CONSOLIDATE,
                "claimable_age_days={$claimableAgeDays} ≥ threshold={$ageSatThreshold} and serve_rate={$serveRate} < floor={$lowServeRateFloor}; tasks pile up faster than consumed",
                'pause_creation_and_consolidate_existing_queue',
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

        // AC2: healthy throughput + fresh queue → continue_creation ONLY when value density stays high
        if (! $queueStaleByAge && ! $lowThroughput && $valueDensity >= $creationFloor) {
            return $this->result(
                self::DECISION_CONTINUE_CREATION,
                "queue fresh (age={$claimableAgeDays}d < {$ageSatThreshold}d), serve_rate={$serveRate} healthy, value_density={$valueDensity} ≥ floor={$creationFloor}",
                'continue_creation_at_current_cadence',
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
