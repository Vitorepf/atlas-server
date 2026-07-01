<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Decides when the originator may enqueue, must defer, should consolidate,
 * or must stop based on live queue pressure metrics.
 *
 * Inputs (all optional; missing → treated as zero / safe default):
 *   queue_state.claimable_depth  int    — tasks in the queue eligible to be claimed
 *   queue_state.servable_depth   int    — tasks currently servable
 *   queue_state.active_leases    int    — tasks being actively worked
 *   candidate.leverage_score     float  — aggregate leverage of the candidate spec
 *   candidate.category           string — task category (for blocked-family check)
 *   candidate.task_class         string — 'normal'|'malformed'|'collision'|'lease_leak'
 *   context.blocked_families     string[] — categories temporarily suspended
 *   context.worker_pressure      string — 'low'|'normal'|'high'
 *
 * Decisions:
 *   enqueue_now  — healthy pressure, prerequisites met, enqueue immediately
 *   defer        — pressure elevated or blocked family, wait for digest
 *   consolidate  — medium pressure, batch down to minimum viable work
 *   stop         — critical pressure, stop authoring until queue drains
 *
 * Safety rule (never overridden):
 *   urgent repair classes (malformed, collision, lease_leak) always → enqueue_now
 *   regardless of pressure.
 *
 * Pure / deterministic. No I/O.
 */
final class AtlasExternalBrainQueuePressureGovernor
{
    public const SCHEMA = 'atlas.external_brain.queue_pressure_governor.v1';

    public const DECISION_ENQUEUE_NOW  = 'enqueue_now';
    public const DECISION_DEFER        = 'defer';
    public const DECISION_CONSOLIDATE  = 'consolidate';
    public const DECISION_STOP         = 'stop';

    /** Task classes that bypass all pressure checks — queue health trumps throughput. */
    private const URGENT_REPAIR_CLASSES = ['malformed', 'collision', 'lease_leak'];

    private const HIGH_CLAIMABLE_DEPTH = 30;
    private const HIGH_ACTIVE_LEASES   = 15;

    /** Live ratio: servable work queued per active worker. Catches a deep EFFECTIVE queue (e.g.
     *  100+ servable tasks across a handful of workers) even when raw claimable_depth alone is
     *  below HIGH_CLAIMABLE_DEPTH. */
    private const HIGH_SERVABLE_PER_WORKER_RATIO = 5.0;

    /** Below this, live worker completion throughput is considered slowing. */
    private const LOW_COMPLETION_SLOPE = 0.30;

    /** Servable-per-worker ratio at/above this, combined with a low completion_slope, is deep enough to add pressure. */
    private const SLOWING_SERVABLE_PER_WORKER_RATIO = 2.0;

    private const HIGH_LEVERAGE    = 0.75;
    private const MEDIUM_LEVERAGE  = 0.50;

    /**
     * Evaluate queue pressure and return a dispatch decision for the candidate spec.
     *
     * @param  array<string,mixed>  $input
     * @return array{
     *     schema:         string,
     *     decision:       string,
     *     reason:         string,
     *     urgent_override: bool,
     * }
     */
    public function decide(array $input): array
    {
        $queueState     = (array) ($input['queue_state'] ?? []);
        $candidate      = (array) ($input['candidate'] ?? []);
        $context        = (array) ($input['context'] ?? []);

        $claimableDepth = (int) ($queueState['claimable_depth'] ?? 0);
        $activeLeases   = (int) ($queueState['active_leases']   ?? 0);
        $servableDepth  = (int) ($queueState['servable_depth']  ?? 0);

        $leverageScore        = (float) ($candidate['leverage_score'] ?? 0.0);
        $dependencyUnlockScore = (float) ($candidate['dependency_unlock_score'] ?? 0.0);
        $effectiveLeverage     = max($leverageScore, $dependencyUnlockScore);
        $category       = trim((string) ($candidate['category']   ?? ''));
        $taskClass      = strtolower(trim((string) ($candidate['task_class'] ?? 'normal')));

        // Live ratio: how much servable work is queued PER active worker — a deep effective
        // queue can exist even when raw claimable_depth alone looks moderate.
        $servablePerWorker = $activeLeases > 0 ? $servableDepth / $activeLeases : (float) $servableDepth;
        $highServableRatio = $servablePerWorker >= self::HIGH_SERVABLE_PER_WORKER_RATIO;

        $blockedFamilies = array_map('strtolower', (array) ($context['blocked_families'] ?? []));
        $workerPressure  = strtolower(trim((string) ($context['worker_pressure'] ?? 'normal')));

        // --- Urgent repair bypass (unconditional) ---
        if (in_array($taskClass, self::URGENT_REPAIR_CLASSES, true)) {
            return $this->result(self::DECISION_ENQUEUE_NOW, "urgent repair class '{$taskClass}' bypasses all pressure checks", urgentOverride: true);
        }

        // --- Worker starvation bypass: servable work per active worker below the floor and the
        //     candidate replenishes worker capacity → enqueue now with a tight batch budget. ---
        $workerFloor = (float) ($queueState['worker_floor'] ?? $context['worker_floor'] ?? 0.0);
        $replenishesWorkerCapacity = (bool) ($candidate['replenishes_worker_capacity'] ?? false);

        if ($workerFloor > 0.0 && $servablePerWorker < $workerFloor && $replenishesWorkerCapacity) {
            return $this->result(self::DECISION_ENQUEUE_NOW, "worker starvation: servable_per_worker_ratio={$servablePerWorker} below worker_floor={$workerFloor}; candidate replenishes worker capacity", underPressure: true);
        }

        // completion_slope: rate of live worker completions (1.0 = healthy, near 0 = slowing/stalled).
        // Absent → treated as healthy (1.0) so existing callers are unaffected.
        $completionSlope = (float) ($context['completion_slope'] ?? $queueState['completion_slope'] ?? 1.0);
        $slowingCompletions = $completionSlope < self::LOW_COMPLETION_SLOPE
            && $servablePerWorker >= self::SLOWING_SERVABLE_PER_WORKER_RATIO;

        $highClaimable = $claimableDepth >= self::HIGH_CLAIMABLE_DEPTH;
        $highLeases    = $activeLeases   >= self::HIGH_ACTIVE_LEASES;
        $queueDeep     = $highClaimable || $highServableRatio || $slowingCompletions;

        // --- Critical pressure: both dimensions saturated → stop ---
        if ($queueDeep && $highLeases) {
            $ratioNote = $highServableRatio ? " live servable_per_worker_ratio={$servablePerWorker}" : '';

            return $this->result(self::DECISION_STOP, "critical pressure: claimable_depth={$claimableDepth} and active_leases={$activeLeases} both at ceiling{$ratioNote}", liveRatioReason: $highServableRatio ? (string) $servablePerWorker : null);
        }

        // --- High pressure on either dimension (including the live servable-per-worker ratio) ---
        if ($queueDeep || $highLeases) {
            if ($effectiveLeverage >= self::HIGH_LEVERAGE) {
                $bypassReason = $dependencyUnlockScore > $leverageScore
                    ? "high dependency_unlock_score={$dependencyUnlockScore} admitted despite elevated pressure (live servable_per_worker_ratio={$servablePerWorker})"
                    : "high-leverage prerequisite (score={$leverageScore}) admitted despite elevated pressure";

                return $this->result(self::DECISION_ENQUEUE_NOW, $bypassReason, underPressure: true, highLeverageEscape: true);
            }

            $ratioNote = $highServableRatio ? " live servable_per_worker_ratio={$servablePerWorker}" : '';

            return $this->result(self::DECISION_DEFER, "queue pressure elevated (claimable={$claimableDepth}, leases={$activeLeases}){$ratioNote}; saturation_blocked_low_leverage: low-leverage batch deferred", underPressure: true, liveRatioReason: $highServableRatio ? (string) $servablePerWorker : null);
        }

        // --- Blocked family check ---
        if ($category !== '' && in_array(strtolower($category), $blockedFamilies, true)) {
            return $this->result(self::DECISION_DEFER, "category '{$category}' is in blocked_families");
        }

        // --- Worker pressure ---
        if ($workerPressure === 'high') {
            if ($leverageScore >= self::MEDIUM_LEVERAGE) {
                return $this->result(self::DECISION_CONSOLIDATE, "worker pressure high; consolidate to minimum viable batch (leverage={$leverageScore})", underPressure: true);
            }

            return $this->result(self::DECISION_DEFER, "worker pressure high and leverage below threshold (score={$leverageScore})", underPressure: true);
        }

        // --- Healthy ---
        return $this->result(self::DECISION_ENQUEUE_NOW, "queue pressure nominal; leverage={$leverageScore}");
    }

    public const ACTION_REQUEST_BOUNDED_BATCH = 'request_bounded_batch';

    public const ACTION_HOLD = 'hold';

    public const ACTION_CONTINUE_SEARCH_FOR_HIGH_LEVERAGE = 'continue_search_for_high_leverage';

    private const WORKER_FLOOR_RATIO = 2.0;

    private const BOUNDED_BATCH_MAX_TASKS = 3;

    /** A comfortable worker buffer is never a reason to go passive — the external brain keeps
     *  searching for high-leverage work, just at a lighter batch ceiling than a starvation batch. */
    private const COMFORTABLE_BATCH_MAX_TASKS = 5;

    /**
     * Thin worker buffer overrides passive wait guidance: when claimable depth
     * per active worker is at or below the floor, AND the queue isn't already
     * choked with malformed packets needing a sweep first, request a small
     * bounded batch instead of waiting — a flood-sized batch would just
     * compound a low-quality backlog, so the request stays capped.
     *
     * A comfortable buffer (no starvation, no malformed backlog) is likewise never a reason to
     * go idle: it returns continue_search_for_high_leverage with a light positive max_tasks
     * ceiling, not a passive hold. Only malformed_count>0 still returns hold, since malformed
     * repair must be swept before generation resumes.
     *
     * @param  array<string,mixed>  $health  { malformed_count?: int,
     *   claimable_per_active_worker?: float }
     * @return array{schema:string, action:string, reason:string, max_tasks:int}
     */
    public function evaluateWorkerFloor(array $health): array
    {
        $malformedCount = max(0, (int) ($health['malformed_count'] ?? 0));
        $claimablePerActiveWorker = $health['claimable_per_active_worker'] ?? null;
        $activeLeases = (int) ($health['active_leases'] ?? 0);
        $replenishSignal = (string) ($health['replenish_action'] ?? $health['replenish_recommendation'] ?? '');

        if ($malformedCount > 0) {
            return [
                'schema' => self::SCHEMA,
                'action' => self::ACTION_HOLD,
                'reason' => "malformed_count={$malformedCount}; sweep malformed packets before requesting more generation",
                'max_tasks' => 0,
            ];
        }

        $thinBuffer = $claimablePerActiveWorker !== null && (float) $claimablePerActiveWorker <= self::WORKER_FLOOR_RATIO;
        $urgentReplenishSignal = $replenishSignal === 'replenish_soon' || $replenishSignal === 'replenish_urgently';

        // Bridge from Maestro replenish-urgency facts: even when serve telemetry is blind (no
        // direct serve_rate to read), active workers near starvation are a non-wait signal on
        // their own — request a bounded batch instead of defaulting to passive wait/hold.
        if ($activeLeases > 0 && ($thinBuffer || $urgentReplenishSignal)) {
            $reason = $thinBuffer
                ? "claimable_per_active_worker={$claimablePerActiveWorker} at or below worker floor={$this->floorAsString()} with active_leases={$activeLeases}; request bounded batch to avoid starvation"
                : "replenish_signal={$replenishSignal} with active_leases={$activeLeases}; request bounded batch ahead of worker starvation";

            return [
                'schema' => self::SCHEMA,
                'action' => self::ACTION_REQUEST_BOUNDED_BATCH,
                'reason' => $reason,
                'max_tasks' => self::BOUNDED_BATCH_MAX_TASKS,
            ];
        }

        return [
            'schema' => self::SCHEMA,
            'action' => self::ACTION_CONTINUE_SEARCH_FOR_HIGH_LEVERAGE,
            'reason' => 'worker buffer comfortable; keep searching for high-leverage work at a light batch ceiling',
            'max_tasks' => self::COMFORTABLE_BATCH_MAX_TASKS,
        ];
    }

    private function floorAsString(): string
    {
        return (string) self::WORKER_FLOOR_RATIO;
    }

    /** @return array{schema:string,decision:string,reason:string,urgent_override:bool,under_pressure:bool,live_ratio_reason:?string,batch_budget:array<string,mixed>,high_leverage_escape:bool} */
    private function result(string $decision, string $reason, bool $urgentOverride = false, bool $underPressure = false, ?string $liveRatioReason = null, bool $highLeverageEscape = false): array
    {
        return [
            'schema'             => self::SCHEMA,
            'decision'           => $decision,
            'reason'             => $reason,
            'urgent_override'    => $urgentOverride,
            'under_pressure'     => $underPressure,
            'live_ratio_reason'  => $liveRatioReason,
            'batch_budget'       => $this->buildBatchBudget($decision, $urgentOverride, $underPressure, $liveRatioReason),
            'high_leverage_escape' => $highLeverageEscape,
        ];
    }

    /** @return array{max_tasks:int, minimum_leverage_score:float, evidence_floor:float, reason:string} */
    private function buildBatchBudget(string $decision, bool $urgentOverride, bool $underPressure, ?string $liveRatioReason = null): array
    {
        if ($urgentOverride) {
            return [
                'max_tasks'              => 1,
                'minimum_leverage_score' => 0.0,
                'evidence_floor'         => 0.0,
                'reason'                 => 'urgent repair bypass: no leverage or evidence floor applies',
            ];
        }

        $ratioSuffix = $liveRatioReason !== null ? " (live servable_per_worker_ratio={$liveRatioReason})" : '';

        return match ($decision) {
            self::DECISION_ENQUEUE_NOW => $underPressure
                ? ['max_tasks' => 3,  'minimum_leverage_score' => 0.75, 'evidence_floor' => 0.50, 'reason' => 'high-leverage bypass under pressure: tight batch of 3']
                : ['max_tasks' => 10, 'minimum_leverage_score' => 0.30, 'evidence_floor' => 0.20, 'reason' => 'healthy queue: standard batch of 10'],
            self::DECISION_CONSOLIDATE => [
                'max_tasks'              => 3,
                'minimum_leverage_score' => 0.50,
                'evidence_floor'         => 0.40,
                'reason'                 => 'worker pressure high: minimum viable batch of 3',
            ],
            self::DECISION_DEFER => [
                'max_tasks'              => 1,
                'minimum_leverage_score' => 0.75,
                'evidence_floor'         => 0.60,
                'reason'                 => 'elevated pressure: at most 1 high-leverage task per digest cycle'.$ratioSuffix,
            ],
            self::DECISION_STOP => [
                'max_tasks'              => 0,
                'minimum_leverage_score' => 1.0,
                'evidence_floor'         => 1.0,
                'reason'                 => 'critical pressure: no new tasks may be enqueued'.$ratioSuffix,
            ],
            default => [
                'max_tasks'              => 1,
                'minimum_leverage_score' => 0.50,
                'evidence_floor'         => 0.30,
                'reason'                 => 'unknown decision: conservative fallback',
            ],
        };
    }
}
