<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ContinuousRuntime;

/**
 * Decides when a 24/7 self-construction soak run should continue, slow_down, replenish, or pause.
 * Does NOT require a human/operator heartbeat in steady state.
 *
 * ACTION PRIORITY (highest wins):
 *   pause      — concrete safety defect OR repeated_failure_count >= failure_threshold
 *   replenish  — claimable_depth < claimable_floor (not a pause)
 *   slow_down  — queue_saturation >= threshold OR worker_contention_rate >= threshold
 *   continue   — all healthy
 *
 * WORKER PRESSURE:
 *   high   — worker_contention_rate >= worker_contention_threshold
 *   medium — worker_contention_rate >= 0.50
 *   normal — otherwise
 *
 * EVIDENCE FRESHNESS STATE:
 *   stale — evidence_freshness_seconds > evidence_freshness_threshold
 *   fresh — otherwise
 *
 * replenishment_needed is always computed independently (true when claimable_depth < claimable_floor).
 *
 * INPUT:
 *   claimable_depth:              int   (default 0)
 *   claimable_floor?:             int   (default 5)
 *   worker_contention_rate?:      float (default 0.0)
 *   worker_contention_threshold?: float (default 0.70)
 *   queue_saturation?:            float (default 0.0)
 *   queue_saturation_threshold?:  float (default 0.85)
 *   evidence_freshness_seconds?:  int   (default 0)
 *   evidence_freshness_threshold?: int  (default 3600)
 *   repeated_failure_count?:      int   (default 0)
 *   repeated_failure_threshold?:  int   (default 3)
 *   safety_defect_detected?:      bool  (default false)
 *
 * OUTPUT:
 *   { schema, action, safety_reasons, replenishment_needed, worker_pressure, evidence_freshness_state }
 *
 * PURE / DETERMINISTIC / NO I/O.
 */
final class AtlasSelfConstructionAutonomousSoakContinuityPolicy
{
    public const SCHEMA = 'atlas.self_construction.continuous_runtime.autonomous_soak_continuity_policy.v1';

    public const ACTION_CONTINUE   = 'continue';
    public const ACTION_SLOW_DOWN  = 'slow_down';
    public const ACTION_REPLENISH  = 'replenish';
    public const ACTION_PAUSE      = 'pause';

    public const PRESSURE_HIGH   = 'high';
    public const PRESSURE_MEDIUM = 'medium';
    public const PRESSURE_NORMAL = 'normal';

    public const FRESHNESS_FRESH = 'fresh';
    public const FRESHNESS_STALE = 'stale';

    private const DEFAULT_CLAIMABLE_FLOOR              = 5;
    private const DEFAULT_WORKER_CONTENTION_THRESHOLD  = 0.70;
    private const DEFAULT_QUEUE_SATURATION_THRESHOLD   = 0.85;
    private const DEFAULT_EVIDENCE_FRESHNESS_THRESHOLD = 3600;
    private const DEFAULT_REPEATED_FAILURE_THRESHOLD   = 3;
    private const MEDIUM_CONTENTION_LEVEL              = 0.50;

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function evaluate(array $input): array
    {
        $claimableDepth          = max(0, (int) ($input['claimable_depth'] ?? 0));
        $claimableFloor          = max(1, (int) ($input['claimable_floor'] ?? self::DEFAULT_CLAIMABLE_FLOOR));
        $workerContention        = (float) ($input['worker_contention_rate'] ?? 0.0);
        $workerContentionThr     = (float) ($input['worker_contention_threshold'] ?? self::DEFAULT_WORKER_CONTENTION_THRESHOLD);
        $queueSaturation         = (float) ($input['queue_saturation'] ?? 0.0);
        $queueSaturationThr      = (float) ($input['queue_saturation_threshold'] ?? self::DEFAULT_QUEUE_SATURATION_THRESHOLD);
        $evidenceFreshnessSeconds = max(0, (int) ($input['evidence_freshness_seconds'] ?? 0));
        $evidenceFreshnessThr    = max(1, (int) ($input['evidence_freshness_threshold'] ?? self::DEFAULT_EVIDENCE_FRESHNESS_THRESHOLD));
        $repeatedFailureCount    = max(0, (int) ($input['repeated_failure_count'] ?? 0));
        $repeatedFailureThr      = max(1, (int) ($input['repeated_failure_threshold'] ?? self::DEFAULT_REPEATED_FAILURE_THRESHOLD));
        $safetyDefect            = (bool) ($input['safety_defect_detected'] ?? false);

        // Derived signals.
        $replenishmentNeeded = $claimableDepth < $claimableFloor;
        $workerPressure      = $this->workerPressure($workerContention, $workerContentionThr);
        $evidenceFreshness   = $evidenceFreshnessSeconds > $evidenceFreshnessThr
            ? self::FRESHNESS_STALE
            : self::FRESHNESS_FRESH;

        // Priority 1: pause.
        $safetyReasons = [];
        if ($safetyDefect) {
            $safetyReasons[] = 'safety_defect_detected';
        }
        if ($repeatedFailureCount >= $repeatedFailureThr) {
            $safetyReasons[] = sprintf('repeated_failure_count=%d >= threshold=%d', $repeatedFailureCount, $repeatedFailureThr);
        }
        if ($safetyReasons !== []) {
            return $this->result(self::ACTION_PAUSE, $safetyReasons, $replenishmentNeeded, $workerPressure, $evidenceFreshness);
        }

        // Priority 2: replenish.
        if ($replenishmentNeeded) {
            return $this->result(self::ACTION_REPLENISH, [], $replenishmentNeeded, $workerPressure, $evidenceFreshness);
        }

        // Priority 3: slow_down.
        if ($queueSaturation >= $queueSaturationThr || $workerContention >= $workerContentionThr) {
            return $this->result(self::ACTION_SLOW_DOWN, [], $replenishmentNeeded, $workerPressure, $evidenceFreshness);
        }

        // Default: continue.
        return $this->result(self::ACTION_CONTINUE, [], $replenishmentNeeded, $workerPressure, $evidenceFreshness);
    }

    private function workerPressure(float $contention, float $threshold): string
    {
        if ($contention >= $threshold) {
            return self::PRESSURE_HIGH;
        }
        if ($contention >= self::MEDIUM_CONTENTION_LEVEL) {
            return self::PRESSURE_MEDIUM;
        }

        return self::PRESSURE_NORMAL;
    }

    /**
     * @param  list<string>  $safetyReasons
     * @return array<string,mixed>
     */
    private function result(
        string $action,
        array $safetyReasons,
        bool $replenishmentNeeded,
        string $workerPressure,
        string $evidenceFreshnessState,
    ): array {
        return [
            'schema'                  => self::SCHEMA,
            'action'                  => $action,
            'safety_reasons'          => $safetyReasons,
            'replenishment_needed'    => $replenishmentNeeded,
            'worker_pressure'         => $workerPressure,
            'evidence_freshness_state' => $evidenceFreshnessState,
        ];
    }
}
